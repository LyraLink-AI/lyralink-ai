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

function webbot_ws_secret(): string {
    return api_get_secret('BOT_SECRET_KEY', 'lyralink-webbot-secret') ?? 'lyralink-webbot-secret';
}

function webbot_ws_base64url_decode(string $value): string|false {
    $pad = strlen($value) % 4;
    if ($pad > 0) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function webbot_ws_validate_token(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$encoded, $sig] = $parts;
    $expected = hash_hmac('sha256', $encoded, webbot_ws_secret());
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $decoded = webbot_ws_base64url_decode($encoded);
    if ($decoded === false) {
        return null;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return null;
    }
    if ((int)($payload['exp'] ?? 0) < time()) {
        return null;
    }
    if (empty($payload['uid']) || empty($payload['workspace']) || empty($payload['mode'])) {
        return null;
    }
    return $payload;
}

function webbot_ws_run_process(string $container): array {
    $cmd = 'docker exec -i ' . escapeshellarg($container) . ' sh';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
    if (!is_resource($proc)) {
        return ['proc' => null];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return [
        'proc' => $proc,
        'stdin' => $pipes[0],
        'stdout' => $pipes[1],
        'stderr' => $pipes[2],
    ];
}

function webbot_ws_container_flag(string $container, string $template): string {
    $cmd = 'docker inspect -f ' . escapeshellarg($template) . ' ' . escapeshellarg($container) . ' 2>/dev/null';
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
    if (!is_resource($proc)) {
        return '';
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        return '';
    }
    return strtolower(trim((string)$stdout));
}

function webbot_ws_is_restarting(string $container): bool {
    return webbot_ws_container_flag($container, '{{.State.Restarting}}') === 'true';
}

function webbot_ws_is_running(string $container): bool {
    return webbot_ws_container_flag($container, '{{.State.Running}}') === 'true';
}

function webbot_ws_workspace_snapshot(string $workspace): string {
    if (!is_dir($workspace)) {
        return sha1('missing');
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $rows = [];
    $base = str_replace('\\', '/', $workspace);
    foreach ($rii as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/node_modules/') || str_ends_with($path, '/.lyralink-meta.json')) {
            continue;
        }
        $rel = substr($path, strlen($base) + 1);
        if ($rel === false || $rel === '') {
            continue;
        }
        $rows[] = implode('|', [$rel, $file->isDir() ? 'd' : 'f', (string)$file->getMTime(), (string)($file->isDir() ? 0 : $file->getSize())]);
    }
    sort($rows);
    return sha1(implode("\n", $rows));
}

final class WebbotTerminalServer implements MessageComponentInterface {
    private array $clients = [];

    public function onOpen(ConnectionInterface $conn): void {
        $query = '';
        if (isset($conn->httpRequest)) {
            $query = $conn->httpRequest->getUri()->getQuery();
        }
        parse_str($query, $params);
        $token = (string)($params['token'] ?? '');
        $payload = webbot_ws_validate_token($token);
        if ($payload === null) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired token']));
            $conn->close();
            return;
        }

        $mode = (string)$payload['mode'];
        $workspace = (string)$payload['workspace'];
        $container = (string)($payload['container'] ?? '');
        $resourceId = $conn->resourceId;
        $client = [
            'conn' => $conn,
            'mode' => $mode,
            'workspace' => $workspace,
            'snapshot' => webbot_ws_workspace_snapshot($workspace),
        ];

        if ($mode === 'terminal') {
            if (webbot_ws_is_restarting($container)) {
                $conn->send(json_encode(['type' => 'error', 'message' => 'Container is restarting. Wait until it is running.']));
                $conn->close();
                return;
            }
            if (!webbot_ws_is_running($container)) {
                $conn->send(json_encode(['type' => 'error', 'message' => 'Container is not running. Start the bot first.']));
                $conn->close();
                return;
            }
            $session = webbot_ws_run_process($container);
            if (!is_resource($session['proc'] ?? null)) {
                $conn->send(json_encode(['type' => 'error', 'message' => 'Failed to open terminal process']));
                $conn->close();
                return;
            }
            $client['proc'] = $session['proc'];
            $client['stdin'] = $session['stdin'];
            $client['stdout'] = $session['stdout'];
            $client['stderr'] = $session['stderr'];
        }

        $loop = Loop::get();
        $timer = $loop->addPeriodicTimer(0.25, function () use ($conn, $resourceId): void {
            if (!isset($this->clients[$resourceId])) {
                return;
            }
            $client = $this->clients[$resourceId];
            if (($client['mode'] ?? '') === 'terminal') {
                foreach (['stdout', 'stderr'] as $streamName) {
                    $stream = $client[$streamName] ?? null;
                    if (!is_resource($stream)) {
                        continue;
                    }
                    $data = stream_get_contents($stream);
                    if ($data !== false && $data !== '') {
                        $conn->send(json_encode(['type' => 'output', 'data' => $data]));
                    }
                }
                $status = proc_get_status($client['proc']);
                if (!$status['running']) {
                    $conn->send(json_encode(['type' => 'status', 'message' => 'terminal exited']));
                    $this->cleanup($resourceId);
                    $conn->close();
                    return;
                }
            }

            $snapshot = webbot_ws_workspace_snapshot($client['workspace']);
            if ($snapshot !== ($client['snapshot'] ?? '')) {
                $this->clients[$resourceId]['snapshot'] = $snapshot;
                $conn->send(json_encode(['type' => 'file_sync', 'hash' => $snapshot]));
            }
        });

        $client['timer'] = $timer;
        $this->clients[$resourceId] = $client;

        if ($mode === 'terminal') {
            $conn->send(json_encode(['type' => 'status', 'message' => 'terminal connected']));
            fwrite($this->clients[$resourceId]['stdin'], "export TERM=xterm\n");
        } else {
            $conn->send(json_encode(['type' => 'status', 'message' => 'watch connected']));
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $resourceId = $from->resourceId;
        if (!isset($this->clients[$resourceId])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'No active session']));
            return;
        }
        $client = $this->clients[$resourceId];
        if (($client['mode'] ?? '') !== 'terminal') {
            return;
        }
        $payload = json_decode((string)$msg, true);
        if (!is_array($payload)) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid message payload']));
            return;
        }
        if (($payload['type'] ?? '') !== 'input') {
            return;
        }
        $data = (string)($payload['data'] ?? '');
        if ($data === '') {
            return;
        }
        $stdin = $client['stdin'] ?? null;
        if (is_resource($stdin)) {
            fwrite($stdin, $data);
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->cleanup($conn->resourceId);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        $conn->send(json_encode(['type' => 'error', 'message' => $e->getMessage()]));
        $this->cleanup($conn->resourceId);
        $conn->close();
    }

    private function cleanup(int $resourceId): void {
        if (!isset($this->clients[$resourceId])) {
            return;
        }
        $client = $this->clients[$resourceId];
        if (isset($client['timer'])) {
            Loop::get()->cancelTimer($client['timer']);
        }
        foreach (['stdin', 'stdout', 'stderr'] as $streamName) {
            if (is_resource($client[$streamName] ?? null)) {
                fclose($client[$streamName]);
            }
        }
        if (is_resource($client['proc'] ?? null)) {
            proc_terminate($client['proc']);
            proc_close($client['proc']);
        }
        unset($this->clients[$resourceId]);
    }
}

$loop = Loop::get();
$socket = new SocketServer('127.0.0.1:8091', [], $loop);
$server = new IoServer(new HttpServer(new WsServer(new WebbotTerminalServer())), $socket, $loop);
echo "Webbot terminal server listening on 127.0.0.1:8091\n";
$server->run();
