<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/security.php';

use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

function support_ws_secret(): string {
    $secret = trim((string)api_get_secret('SUPPORT_WS_SECRET', ''));
    if ($secret !== '') {
        return $secret;
    }
    $fallback = trim((string)api_get_secret('BOT_SECRET_KEY', ''));
    return $fallback !== '' ? $fallback : 'lyralink-support-ws-secret';
}

function support_ws_base64url_decode(string $value): string|false {
    $pad = strlen($value) % 4;
    if ($pad > 0) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function support_ws_validate_token(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encoded, $sig] = $parts;
    $expected = hash_hmac('sha256', $encoded, support_ws_secret());
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $decoded = support_ws_base64url_decode($encoded);
    if ($decoded === false) {
        return null;
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return null;
    }

    $ticketRef = strtoupper(trim((string)($payload['ticket_ref'] ?? '')));
    $role = strtolower(trim((string)($payload['role'] ?? '')));
    $exp = (int)($payload['exp'] ?? 0);

    if ($exp < time()) {
        return null;
    }
    if (!preg_match('/^TKT-[A-Z0-9]{8}$/', $ticketRef)) {
        return null;
    }
    if (!in_array($role, ['user', 'agent'], true)) {
        return null;
    }

    return [
        'ticket_ref' => $ticketRef,
        'role' => $role,
        'exp' => $exp,
    ];
}

final class SupportLiveServer implements MessageComponentInterface {
    private SplObjectStorage $clients;
    private mysqli $db;
    private array $ticketState = [];

    public function __construct(mysqli $db) {
        $this->clients = new SplObjectStorage();
        $this->db = $db;
    }

    public function onOpen(ConnectionInterface $conn): void {
        $query = '';
        if (isset($conn->httpRequest)) {
            $query = $conn->httpRequest->getUri()->getQuery();
        }

        parse_str($query, $params);
        $token = (string)($params['token'] ?? '');
        $payload = support_ws_validate_token($token);
        if ($payload === null) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid token']));
            $conn->close();
            return;
        }

        $this->clients->attach($conn, [
            'ticket_ref' => $payload['ticket_ref'],
            'role' => $payload['role'],
        ]);

        $conn->send(json_encode([
            'type' => 'ready',
            'ticket_ref' => $payload['ticket_ref'],
            'role' => $payload['role'],
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $decoded = json_decode((string)$msg, true);
        if (!is_array($decoded)) {
            return;
        }
        if (($decoded['type'] ?? '') === 'ping') {
            $from->send(json_encode(['type' => 'pong', 'ts' => time()]));
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, Exception $e): void {
        error_log('[support-ws] Connection error: ' . $e->getMessage());
        $conn->close();
    }

    public function pollChanges(): void {
        if (count($this->clients) === 0) {
            return;
        }

        $refs = [];
        foreach ($this->clients as $client) {
            $meta = $this->clients[$client];
            if (!is_array($meta)) {
                continue;
            }
            $ref = (string)($meta['ticket_ref'] ?? '');
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }

        if (!$refs) {
            return;
        }

        $escaped = [];
        foreach (array_keys($refs) as $ref) {
            $escaped[] = "'" . $this->db->real_escape_string($ref) . "'";
        }
        if (!$escaped) {
            return;
        }

        $sql = "SELECT t.ticket_ref, t.status, t.updated_at, COALESCE(MAX(r.id), 0) AS latest_reply_id "
             . "FROM support_tickets t "
             . "LEFT JOIN support_replies r ON r.ticket_id = t.id "
             . "WHERE t.ticket_ref IN (" . implode(',', $escaped) . ") "
             . "GROUP BY t.id";

        $result = $this->db->query($sql);
        if (!$result) {
            error_log('[support-ws] Poll query failed: ' . $this->db->error);
            return;
        }

        while ($row = $result->fetch_assoc()) {
            $ticketRef = (string)$row['ticket_ref'];
            $newState = [
                'status' => (string)($row['status'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'latest_reply_id' => (int)($row['latest_reply_id'] ?? 0),
            ];

            $old = $this->ticketState[$ticketRef] ?? null;
            $this->ticketState[$ticketRef] = $newState;

            $changed = !is_array($old)
                || ((int)($old['latest_reply_id'] ?? -1) !== $newState['latest_reply_id'])
                || ((string)($old['status'] ?? '') !== $newState['status'])
                || ((string)($old['updated_at'] ?? '') !== $newState['updated_at']);

            if (!$changed) {
                continue;
            }

            $this->broadcastTicketUpdate($ticketRef, $newState);
        }

        $result->close();
    }

    private function broadcastTicketUpdate(string $ticketRef, array $state): void {
        $payload = json_encode([
            'type' => 'ticket_update',
            'ticket_ref' => $ticketRef,
            'status' => $state['status'] ?? '',
            'updated_at' => $state['updated_at'] ?? '',
            'latest_reply_id' => (int)($state['latest_reply_id'] ?? 0),
        ]);

        foreach ($this->clients as $client) {
            $meta = $this->clients[$client];
            if (!is_array($meta) || (string)($meta['ticket_ref'] ?? '') !== $ticketRef) {
                continue;
            }
            try {
                $client->send($payload);
            } catch (Exception $e) {
                error_log('[support-ws] Failed to push update: ' . $e->getMessage());
            }
        }
    }
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    fwrite(STDERR, "[support-ws] DB connection failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$bindHost = trim((string)api_get_secret('SUPPORT_WS_BIND', '127.0.0.1')) ?: '127.0.0.1';
$port = (int)api_get_secret('SUPPORT_WS_PORT', '8082');
if ($port <= 0) {
    $port = 8082;
}

$loop = Loop::get();
$server = new SupportLiveServer($db);
$loop->addPeriodicTimer(2.0, function () use ($server): void {
    $server->pollChanges();
});

$socket = new SocketServer($bindHost . ':' . $port, [], $loop);
$http = new HttpServer(new WsServer($server));
new IoServer($http, $socket, $loop);

echo "[support-ws] listening on {$bindHost}:{$port}\n";
$loop->run();
