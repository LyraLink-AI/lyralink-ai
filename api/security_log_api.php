<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    lyra_session_boot();
}

api_json_headers();

/* Fork mode from configuration only; the Host header is caller-controlled. */
$isForkMode = lyra_is_fork_mode();

if (!lyra_admin_gate_ok()) {
    api_fail('Forbidden', 403);
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('DB connection failed', 500);
}
$db->set_charset('utf8mb4');

$existsStmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_log' LIMIT 1");
if (!$existsStmt) {
    echo json_encode(['success' => true, 'events' => []]);
    exit;
}
$existsStmt->execute();
$exists = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if (!$exists) {
    echo json_encode(['success' => true, 'events' => []]);
    exit;
}

$rows = [];
$res = $db->query("SELECT id, user_id, event_type, ip, detail, created_at FROM security_log ORDER BY id DESC LIMIT 1000");
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'event_type' => (string)($row['event_type'] ?? ''),
            'ip' => (string)($row['ip'] ?? ''),
            'detail' => (string)($row['detail'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
    $res->free();
}

$stats = [
    'ai_policy_blocks_24h' => 0,
    'ai_image_blocks_24h' => 0,
    'ai_rate_limited_24h' => 0,
];

$statsRes = $db->query("SELECT
    COALESCE(SUM(CASE WHEN event_type IN ('ai_policy_block', 'ai_public_policy_block') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS ai_policy_blocks_24h,
    COALESCE(SUM(CASE WHEN event_type = 'ai_image_block' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS ai_image_blocks_24h,
    COALESCE(SUM(CASE WHEN event_type = 'ai_rate_limited' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS ai_rate_limited_24h
    FROM security_log");
if ($statsRes instanceof mysqli_result) {
    $stats = array_merge($stats, $statsRes->fetch_assoc() ?: []);
    $statsRes->free();
}

echo json_encode(['success' => true, 'events' => $rows, 'stats' => $stats]);
