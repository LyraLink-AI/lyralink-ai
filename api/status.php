<?php
require_once __DIR__ . '/security.php';
api_json_headers();
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$action = api_action() ?: 'get_status';
$sessionRequiredActions = ['update_service', 'create_incident', 'update_incident'];
if (in_array($action, $sessionRequiredActions, true)) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function status_live_uptime_pct(string $status): float {
    return match ($status) {
        'major_outage'   => 25.00,
        'partial_outage' => 72.50,
        'degraded'       => 97.00,
        'maintenance'    => 99.00,
        default          => 100.00,
    };
}

function status_sync_today_uptime(mysqli $db): void {
    $today = date('Y-m-d');
    $existing = [];
    $todayEsc = $db->real_escape_string($today);
    $current = $db->query("SELECT service_id, uptime_pct FROM status_uptime WHERE date = '{$todayEsc}'");
    if ($current) {
        while ($row = $current->fetch_assoc()) {
            $existing[(int)($row['service_id'] ?? 0)] = (float)($row['uptime_pct'] ?? 100.00);
        }
    }

    $rows = $db->query("SELECT id, status FROM status_services ORDER BY id ASC");
    if (!$rows) {
        return;
    }
    $stmt = $db->prepare("INSERT INTO status_uptime (service_id, date, uptime_pct) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE uptime_pct = VALUES(uptime_pct)");
    if (!$stmt) {
        return;
    }
    while ($row = $rows->fetch_assoc()) {
        $serviceId = (int)($row['id'] ?? 0);
        $livePct = status_live_uptime_pct((string)($row['status'] ?? 'operational'));
        $pct = array_key_exists($serviceId, $existing)
            ? min($existing[$serviceId], $livePct)
            : $livePct;
        $stmt->bind_param('isd', $serviceId, $today, $pct);
        $stmt->execute();
    }
    $stmt->close();
}

function status_backfill_uptime_history(mysqli $db, int $days = 90): void {
    $days = max(1, min($days, 365));
    $rows = $db->query("SELECT id, status FROM status_services ORDER BY id ASC");
    if (!$rows) {
        return;
    }
    $services = [];
    while ($row = $rows->fetch_assoc()) {
        $services[] = [
            'id' => (int)($row['id'] ?? 0),
            'status' => (string)($row['status'] ?? 'operational'),
        ];
    }
    if (!$services) {
        return;
    }

    $stmt = $db->prepare("INSERT IGNORE INTO status_uptime (service_id, date, uptime_pct) VALUES (?, ?, ?)");
    if (!$stmt) {
        return;
    }
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        foreach ($services as $svc) {
            $pct = $i === 0 ? status_live_uptime_pct($svc['status']) : 100.00;
            $serviceId = (int)$svc['id'];
            $stmt->bind_param('isd', $serviceId, $date, $pct);
            $stmt->execute();
        }
    }
    $stmt->close();
}

function status_is_outage(string $status): bool {
    return in_array($status, ['partial_outage', 'major_outage'], true);
}

function status_ai_model(): string {
    $configured = trim((string)api_get_secret('STATUS_AI_MODEL', ''));
    if ($configured !== '') {
        return $configured;
    }
    return trim((string)api_get_secret('LLM_MODEL', 'llama-3.3-70b-versatile')) ?: 'llama-3.3-70b-versatile';
}

function status_ai_call(array $messages, int $maxTokens = 700, float $temperature = 0.2): ?string {
    $apiKey = trim((string)api_get_secret('GROQ_API_KEY', ''));
    if ($apiKey === '') {
        return null;
    }

    $payload = [
        'model' => status_ai_model(),
        'messages' => $messages,
        'max_tokens' => max(256, min(1400, $maxTokens)),
        'temperature' => $temperature,
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded['choices'][0]['message']['content'] ?? null;
}

function status_incident_fallback(array $service, string $status): array {
    $svcName = (string)($service['name'] ?? 'A service');
    $statusLabel = str_replace('_', ' ', $status);
    $eta = $status === 'major_outage' ? '2-4 hours' : '30-90 minutes';
    return [
        'title' => $svcName . ' experiencing ' . $statusLabel,
        'status' => 'investigating',
        'impact' => $status === 'major_outage' ? 'critical' : 'major',
        'eta' => $eta,
        'message' => 'We are investigating an issue affecting ' . $svcName . '. Current service state is ' . $statusLabel . '.\n\nEstimated time to restore: ' . $eta . '.',
    ];
}

function status_generate_incident_copy(array $service, string $previousStatus, string $newStatus): array {
    $fallback = status_incident_fallback($service, $newStatus);

    $prompt = [
        ['role' => 'system', 'content' => 'You write concise status-page incident updates. Return strict JSON only with keys: title, message, impact, eta.'],
        ['role' => 'user', 'content' => json_encode([
            'service' => (string)($service['name'] ?? 'Unknown service'),
            'description' => (string)($service['description'] ?? ''),
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'requirements' => [
                'Title under 110 chars',
                'Message must explain likely cause clearly and calmly for customers',
                'Message must include an estimated time for a fix',
                'Impact must be one of: minor, major, critical',
                'ETA should be a short phrase like 30-60 minutes',
            ],
        ], JSON_UNESCAPED_SLASHES)],
    ];

    $raw = status_ai_call($prompt, 650, 0.2);
    if (!is_string($raw) || trim($raw) === '') {
        return $fallback;
    }

    $candidate = trim($raw);
    if (preg_match('/\{[\s\S]*\}/', $candidate, $m)) {
        $candidate = $m[0];
    }
    $parsed = json_decode($candidate, true);
    if (!is_array($parsed)) {
        return $fallback;
    }

    $title = trim((string)($parsed['title'] ?? ''));
    $message = trim((string)($parsed['message'] ?? ''));
    $impact = trim((string)($parsed['impact'] ?? 'major'));
    $eta = trim((string)($parsed['eta'] ?? ''));

    if ($title === '' || $message === '') {
        return $fallback;
    }
    if (!in_array($impact, ['minor', 'major', 'critical'], true)) {
        $impact = $fallback['impact'];
    }
    if ($eta === '') {
        $eta = $fallback['eta'];
    }
    if (stripos($message, 'estimated time') === false && stripos($message, 'eta') === false) {
        $message .= "\n\nEstimated time to restore: {$eta}.";
    }

    return [
        'title' => substr($title, 0, 280),
        'status' => 'investigating',
        'impact' => $impact,
        'eta' => $eta,
        'message' => $message,
    ];
}

function status_create_auto_incident(mysqli $db, array $service, string $previousStatus, string $newStatus): ?int {
    $serviceToken = 'service:' . (int)($service['id'] ?? 0);
    $like = '%' . $serviceToken . '%';
    $check = $db->prepare("SELECT id FROM status_incidents WHERE resolved_at IS NULL AND affected_services LIKE ? ORDER BY created_at DESC LIMIT 1");
    if ($check) {
        $check->bind_param('s', $like);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            return null;
        }
    }

    $copy = status_generate_incident_copy($service, $previousStatus, $newStatus);

    $stmt = $db->prepare("INSERT INTO status_incidents (title, status, impact, affected_services) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return null;
    }
    $title = (string)$copy['title'];
    $incidentStatus = (string)$copy['status'];
    $impact = (string)$copy['impact'];
    $affectedServices = $serviceToken . ',name:' . (string)($service['name'] ?? '');
    $stmt->bind_param('ssss', $title, $incidentStatus, $impact, $affectedServices);
    $stmt->execute();
    $incidentId = $db->insert_id;
    $stmt->close();

    if ($incidentId <= 0) {
        return null;
    }

    $message = (string)$copy['message'];
    $up = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
    if ($up) {
        $up->bind_param('iss', $incidentId, $message, $incidentStatus);
        $up->execute();
        $up->close();
    }

    return $incidentId;
}

// ── DB CONFIG — fill these in ──
$dbHost = 'localhost';
$dbUser = 'app_user';
$dbPass = '';
$dbName = 'aicloud';

$dbCfg = api_db_config([
    'host' => $dbHost,
    'user' => $dbUser,
    'pass' => $dbPass,
    'name' => $dbName,
]);
$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connect failed']);
    exit;
}
$db->set_charset('utf8mb4');

// ── Create tables if they don't exist ──
$db->query("CREATE TABLE IF NOT EXISTS status_services (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(300) DEFAULT NULL,
    category    VARCHAR(100) NOT NULL DEFAULT 'Core Services',
    status      ENUM('operational','degraded','partial_outage','major_outage','maintenance') NOT NULL DEFAULT 'operational',
    sort_order  INT NOT NULL DEFAULT 0,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$db->query("CREATE TABLE IF NOT EXISTS status_incidents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(300) NOT NULL,
    status      ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
    impact      ENUM('none','minor','major','critical') NOT NULL DEFAULT 'minor',
    affected_services VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL
)");

$db->query("CREATE TABLE IF NOT EXISTS status_incident_updates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    message     TEXT NOT NULL,
    status      ENUM('investigating','identified','monitoring','resolved') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_incident (incident_id)
)");

$db->query("CREATE TABLE IF NOT EXISTS status_uptime (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    service_id  INT NOT NULL,
    date        DATE NOT NULL,
    uptime_pct  DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    UNIQUE KEY uq_svc_date (service_id, date)
)");

// ── Seed services if empty ──
$cr = $db->prepare("SELECT COUNT(*) AS c FROM status_services");
$cr->execute();
$svcCount = (int)($cr->get_result()->fetch_assoc()['c'] ?? 0);
$cr->close();

if ($svcCount === 0) {
    $seeds = [
        ['AI Chat API',          'ai-chat-api',   'Core conversational AI endpoint',        'Core Services',   'operational', 0],
        ['Web Application',      'web-app',        'Main Lyralink web interface',            'Core Services',   'operational', 1],
        ['Authentication',       'auth',           'Login and session management',           'Core Services',   'operational', 2],
        ['Moltbook Integration', 'moltbook',       'Social engagement automation',           'Integrations',    'operational', 3],
        ['Billing & Payments',   'billing',        'Subscription and payment processing',    'Integrations',    'operational', 4],
        ['Discord Bot',          'discord-bot',    'Discord integration and commands',       'Integrations',    'operational', 5],
        ['Dataset API',          'dataset-api',    'Public dataset and RAG pipeline',        'Developer Tools', 'operational', 6],
        ['File Storage',         'file-storage',   'Upload and asset delivery',              'Infrastructure',  'operational', 7],
        ['Database',             'database',       'Primary database cluster',               'Infrastructure',  'operational', 8],
    ];
    $ins = $db->prepare("INSERT IGNORE INTO status_services (name, slug, description, category, status, sort_order) VALUES (?,?,?,?,?,?)");
    if ($ins) {
        foreach ($seeds as $s) {
            $ins->bind_param('sssssi', $s[0], $s[1], $s[2], $s[3], $s[4], $s[5]);
            $ins->execute();
        }
        $ins->close();
    }
    $idRes = $db->prepare("SELECT id FROM status_services");
    $idRes->execute();
    $idRes = $idRes->get_result();
    $ids = [];
    if ($idRes) { while ($r = $idRes->fetch_assoc()) $ids[] = (int)$r['id']; }
    $ins2 = $db->prepare("INSERT IGNORE INTO status_uptime (service_id, date, uptime_pct) VALUES (?,?,100.00)");
    if ($ins2 && $ids) {
        for ($i = 89; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            foreach ($ids as $sid) {
                $ins2->bind_param('is', $sid, $date);
                $ins2->execute();
            }
        }
        $ins2->close();
    }
}

api_enforce_post_and_origin_for_actions([
    'update_service',
    'create_incident',
    'update_incident',
]);

if ($action === 'get_status') {
    status_backfill_uptime_history($db, 90);
    status_sync_today_uptime($db);
    $services = [];
    $sr = $db->prepare("SELECT * FROM status_services ORDER BY category, sort_order");
    $sr->execute();
    $sr = $sr->get_result();
    if ($sr) { while ($r = $sr->fetch_assoc()) $services[] = $r; }

    $incidents = [];
    $ir = $db->prepare("SELECT i.*,
        GROUP_CONCAT(u.message    ORDER BY u.created_at DESC SEPARATOR '|||') AS update_messages,
        GROUP_CONCAT(u.status     ORDER BY u.created_at DESC SEPARATOR '|||') AS update_statuses,
        GROUP_CONCAT(u.created_at ORDER BY u.created_at DESC SEPARATOR '|||') AS update_times
        FROM status_incidents i
        LEFT JOIN status_incident_updates u ON u.incident_id = i.id
        WHERE i.resolved_at IS NULL
        GROUP BY i.id ORDER BY i.created_at DESC LIMIT 10");
    $ir->execute();
    $ir = $ir->get_result();
    if ($ir) {
        while ($r = $ir->fetch_assoc()) {
            $r['update_messages'] = array_values(array_filter(explode('|||', $r['update_messages'] ?? '')));
            $r['update_statuses'] = array_values(array_filter(explode('|||', $r['update_statuses'] ?? '')));
            $r['update_times']    = array_values(array_filter(explode('|||', $r['update_times']    ?? '')));
            $incidents[] = $r;
        }
    }

    $resolved = [];
    $rr = $db->prepare("SELECT * FROM status_incidents WHERE resolved_at IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 10");
    $rr->execute();
    $rr = $rr->get_result();
    if ($rr) { while ($r = $rr->fetch_assoc()) $resolved[] = $r; }

    $uptime = [];
    $ur = $db->prepare("SELECT service_id, date, uptime_pct FROM status_uptime WHERE date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) ORDER BY date ASC");
    $ur->execute();
    $ur = $ur->get_result();
    if ($ur) {
        while ($r = $ur->fetch_assoc()) {
            // Use string key so JSON encodes as object not array
            $uptime[strval($r['service_id'])][$r['date']] = (float)$r['uptime_pct'];
        }
    }

    $statuses = array_column($services, 'status');
    $overall  = 'operational';
    if      (in_array('major_outage',   $statuses)) $overall = 'major_outage';
    elseif  (in_array('partial_outage', $statuses)) $overall = 'partial_outage';
    elseif  (in_array('degraded',       $statuses)) $overall = 'degraded';
    elseif  (in_array('maintenance',    $statuses)) $overall = 'maintenance';

    echo json_encode(['success'=>true,'overall'=>$overall,'services'=>$services,'incidents'=>$incidents,'resolved'=>$resolved,'uptime'=>$uptime,'generated'=>date('c')]);
    exit;
}

if ($action === 'update_service') {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if (!in_array($status, ['operational','degraded','partial_outage','major_outage','maintenance'])) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }

    $get = $db->prepare("SELECT id, name, description, status FROM status_services WHERE id = ? LIMIT 1");
    $get->bind_param('i', $id);
    $get->execute();
    $service = $get->get_result()->fetch_assoc();
    $get->close();
    if (!$service) { echo json_encode(['success'=>false,'error'=>'Service not found']); exit; }

    $previousStatus = (string)($service['status'] ?? 'operational');

    $stmt = $db->prepare("UPDATE status_services SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();

    $autoIncidentId = null;
    if (status_is_outage($status) && !status_is_outage($previousStatus)) {
        $service['status'] = $status;
        $autoIncidentId = status_create_auto_incident($db, $service, $previousStatus, $status);
    }

    status_sync_today_uptime($db);
    echo json_encode(['success'=>true, 'auto_incident_created'=>($autoIncidentId !== null), 'incident_id'=>$autoIncidentId]);
    exit;
}

if ($action === 'create_incident') {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $title   = trim($_POST['title'] ?? '');
    $status  = trim($_POST['status'] ?? 'investigating');
    $impact  = trim($_POST['impact'] ?? 'minor');
    $message = trim($_POST['message'] ?? '');
    if (!$title || !$message) { echo json_encode(['success'=>false,'error'=>'Title and message required']); exit; }

    $stmt = $db->prepare("INSERT INTO status_incidents (title, status, impact) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $title, $status, $impact);
    $stmt->execute();
    $incId = $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $incId, $message, $status);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>true,'incident_id'=>$incId]);
    exit;
}

if ($action === 'update_incident') {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $incId   = (int)($_POST['incident_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $status  = trim($_POST['status'] ?? 'monitoring');
    if (!$message) { echo json_encode(['success'=>false,'error'=>'Message required']); exit; }
    $stmt = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $incId, $message, $status);
    $stmt->execute();
    $stmt->close();

    if ($status === 'resolved') {
        $stmt = $db->prepare("UPDATE status_incidents SET status = ?, resolved_at = NOW() WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE status_incidents SET status = ? WHERE id = ?");
    }
    $stmt->bind_param('si', $status, $incId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>true]);
    exit;
}

// ── list open incidents (for admin dropdown) ──
if ($action === 'list_incidents') {
    $incidents = [];
    $ir = $db->prepare("SELECT id, title, status, impact, created_at FROM status_incidents WHERE resolved_at IS NULL ORDER BY created_at DESC LIMIT 50");
    $ir->execute();
    $ir = $ir->get_result();
    if ($ir) { while ($r = $ir->fetch_assoc()) $incidents[] = $r; }
    echo json_encode(['success' => true, 'incidents' => $incidents]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);