<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['ai.cloudhavenx.com', 'www.ai.cloudhavenx.com'], true);
$forkModeEnv = api_get_secret('FORK_MODE', '');
$isForkMode = ($forkModeEnv === '1') || ($host !== '' && !$isPrimaryHost);

// ── AUTH CHECK — dev only ──
$dbHost = 'localhost';
$dbUser = 'app_user';
$dbPass = '';
$dbName = 'aicloud';
$devUsername = api_get_secret('ADMIN_DEV_USERNAME', 'developer');

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

if (!$isForkMode && (empty($_SESSION['username']) || $_SESSION['username'] !== $devUsername)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'toggle_maintenance',
    'bot_restart',
    'bot_stop',
]);

if ($isForkMode && in_array($action, ['toggle_maintenance', 'bot_restart', 'bot_stop'], true)) {
    echo json_encode(['success' => false, 'error' => 'Disabled in fork preview mode']);
    exit;
}

$flagFile = __DIR__ . '/../maintenance.flag';
$infoFile = __DIR__ . '/../maintenance.info';

// ── TOGGLE MAINTENANCE ──
if ($action === 'toggle_maintenance') {
    $eta = trim($_POST['eta'] ?? '');
    if (file_exists($flagFile)) {
        unlink($flagFile);
        @unlink($infoFile);
        echo json_encode(['success' => true, 'maintenance' => false]);
    } else {
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
        file_put_contents($infoFile, $eta);
        echo json_encode(['success' => true, 'maintenance' => true]);
    }
    exit;
}

// ── GET STATUS ──
if ($action === 'status') {
    $maintenance = file_exists($flagFile);
    $eta         = $maintenance && file_exists($infoFile) ? file_get_contents($infoFile) : '';

    // Site stats
    $usersStmt = $db->prepare("SELECT COUNT(*) c FROM users");
    $usersStmt->execute();
    $users = $usersStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $usersStmt->close();

    $convsStmt = $db->prepare("SELECT COUNT(*) c FROM user_convs");
    $convsStmt->execute();
    $convs = $convsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $convsStmt->close();

    $msgsStmt = $db->prepare("SELECT COUNT(*) c FROM user_conv_messages");
    $msgsStmt->execute();
    $msgs = $msgsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $msgsStmt->close();

    $datasetStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1");
    $datasetStmt->execute();
    $dataset = $datasetStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $datasetStmt->close();

    $apiKeysStmt = $db->prepare("SELECT COUNT(*) c FROM api_keys WHERE active = 1");
    $apiKeysStmt->execute();
    $apiKeys = $apiKeysStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $apiKeysStmt->close();

    $pendingStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 0");
    $pendingStmt->execute();
    $pending = $pendingStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $pendingStmt->close();

    // Bot status via pgrep
    $botRunning = false;
    $botUptime  = null;
    if (function_exists('shell_exec')) {
        $pid = trim(shell_exec("pgrep -f 'node.*index.js' 2>/dev/null") ?? '');
        $botRunning = !empty($pid);
        if ($botRunning) {
            $etimeRaw = trim(shell_exec("ps -o etimes= -p $pid 2>/dev/null") ?? '');
            if (is_numeric($etimeRaw)) {
                $s = (int)$etimeRaw;
                $h = floor($s / 3600); $m = floor(($s % 3600) / 60); $s = $s % 60;
                $botUptime = ($h > 0 ? "{$h}h " : '') . ($m > 0 ? "{$m}m " : '') . "{$s}s";
            }
        }
    }

    echo json_encode([
        'success'     => true,
        'maintenance' => $maintenance,
        'eta'         => $eta,
        'stats'       => compact('users', 'convs', 'msgs', 'dataset', 'apiKeys', 'pending'),
        'bot'         => ['running' => $botRunning, 'uptime' => $botUptime],
    ]);
    exit;
}

// ── BOT CONTROL ──
if ($action === 'bot_restart') {
    if (function_exists('shell_exec')) {
        shell_exec('pm2 restart lyralink-bot > /dev/null 2>&1 &');
        echo json_encode(['success' => true, 'message' => 'Restart signal sent']);
    } else {
        echo json_encode(['success' => false, 'error' => 'shell_exec not available']);
    }
    exit;
}

if ($action === 'bot_stop') {
    if (function_exists('shell_exec')) {
        shell_exec('pm2 stop lyralink-bot > /dev/null 2>&1 &');
        echo json_encode(['success' => true, 'message' => 'Stop signal sent']);
    } else {
        echo json_encode(['success' => false, 'error' => 'shell_exec not available']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>