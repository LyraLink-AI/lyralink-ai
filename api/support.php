<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'create_ticket',
    'user_reply',
    'agent_login',
    'agent_logout',
    'heartbeat',
    'agent_reply',
    'update_ticket',
    'create_agent',
    'update_agent',
    'set_discord_roles',
    'set_smtp',
    'delete_ticket',
    'retry_notification_job',
    'webbot_instance_action',
]);

function ensureSupportQueueTable($db) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db->query("CREATE TABLE IF NOT EXISTS support_notification_queue (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(32) NOT NULL,
        payload LONGTEXT NOT NULL,
        status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status_available (status, available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function ensureDiscordTranscriptTable($db) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db->query("CREATE TABLE IF NOT EXISTS discord_ticket_transcripts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ticket_ref VARCHAR(40) NOT NULL,
        channel_id VARCHAR(64) NOT NULL,
        channel_name VARCHAR(120) DEFAULT NULL,
        guild_id VARCHAR(64) DEFAULT NULL,
        opened_by VARCHAR(120) DEFAULT NULL,
        opened_by_id VARCHAR(64) DEFAULT NULL,
        category VARCHAR(64) DEFAULT NULL,
        closed_by VARCHAR(120) DEFAULT NULL,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        transcript LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ticket_ref (ticket_ref),
        KEY idx_channel_id (channel_id),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

// ── HELPERS ──
function genTicketRef() {
    return 'TKT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

function getConfig($db, $key) {
    $stmt = $db->prepare("SELECT `value` FROM support_config WHERE `key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    return $r ? ($r->fetch_assoc()['value'] ?? '') : '';
}

function finishJson(array $payload) {
    echo json_encode($payload);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) {
            @ob_end_flush();
        }
        flush();
    }
}

function queueNotification($db, $channel, array $payload) {
    ensureSupportQueueTable($db);

    $json = json_encode($payload);
    if ($json === false) {
        return false;
    }

    $stmt = $db->prepare("INSERT INTO support_notification_queue (channel, payload) VALUES (?, ?)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $channel, $json);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function triggerNotificationWorker() {
    static $triggered = false;
    if ($triggered) {
        return;
    }

    $script = escapeshellarg(__DIR__ . '/../cron/support_notifications.php');
    $candidateBins = [];
    if (defined('PHP_BINDIR')) {
        $candidateBins[] = PHP_BINDIR . '/php';
    }
    if (PHP_BINARY) {
        $candidateBins[] = PHP_BINARY;
    }
    $candidateBins[] = '/usr/bin/php';
    $candidateBins[] = 'php';

    $phpBin = 'php';
    foreach ($candidateBins as $candidate) {
        if ($candidate && @is_executable($candidate) && stripos(basename($candidate), 'php-fpm') === false) {
            $phpBin = $candidate;
            break;
        }
    }

    $phpBin = escapeshellcmd($phpBin);
    @exec("$phpBin $script > /dev/null 2>&1 &");
    $triggered = true;
}

function sendEmail($db, $to, $subject, $htmlBody) {
    $smtpHost = getConfig($db, 'smtp_host');
    $smtpPort = (int)getConfig($db, 'smtp_port');
    $smtpUser = getConfig($db, 'smtp_user');
    $smtpPass = getConfig($db, 'smtp_pass');
    $fromAddr = getConfig($db, 'smtp_from');

    if (!$smtpUser || !$smtpPass) {
        // Fallback to PHP mail()
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: Lyralink Support <$fromAddr>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit";
        return mail($to, $subject, $htmlBody, $headers);
    }

    // PHPMailer via composer — if available
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->Timeout    = 5;

        if ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpPort === 587) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddr, 'Lyralink Support');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

function sendDiscordWebhook($db, $ticket, $agentName = null) {
    $webhookUrl = getConfig($db, 'discord_webhook_url');
    if (!$webhookUrl) return;

    $priorityColors = ['low' => 3066993, 'medium' => 16776960, 'high' => 15105570, 'critical' => 15158332];
    $priorityEmoji  = ['low' => '🟢', 'medium' => '🟡', 'high' => '🟠', 'critical' => '🔴'];
    $categoryLabels = ['general' => 'General Support', 'billing' => 'Billing', 'bug_report' => 'Bug Report', 'account' => 'Account Issue', 'live_chat' => 'Live Chat'];

    $priority = $ticket['user_priority'];
    $color    = $priorityColors[$priority] ?? 3447003;
    $emoji    = $priorityEmoji[$priority]  ?? '⚪';
    $cat      = $categoryLabels[$ticket['category']] ?? $ticket['category'];

    // Get Discord role ping for this priority
    $stmt = $db->prepare("SELECT role_id FROM support_discord_roles WHERE priority = ?");
    $stmt->bind_param('s', $priority);
    $stmt->execute();
    $pRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ping = $pRow ? "<@&{$pRow['role_id']}>" : '';

    $payload = [
        'content' => $ping ? "$ping — New {$emoji} **" . strtoupper($priority) . "** ticket" : null,
        'embeds'  => [[
            'title'       => "🎫 [{$ticket['ticket_ref']}] " . substr($ticket['subject'], 0, 80),
            'description' => substr($ticket['body'], 0, 300) . (strlen($ticket['body']) > 300 ? '...' : ''),
            'color'       => $color,
            'fields'      => [
                ['name' => 'Category', 'value' => $cat,       'inline' => true],
                ['name' => 'Priority', 'value' => "$emoji " . ucfirst($priority), 'inline' => true],
                ['name' => 'Status',   'value' => '🟣 Open',  'inline' => true],
                ['name' => 'From',     'value' => $ticket['guest_name'] ?? $ticket['username'] ?? 'Guest', 'inline' => true],
            ],
            'footer'      => ['text' => 'Lyralink Support · ' . date('M j, Y g:i A')],
            'url'         => 'https://ai.cloudhavenx.com/pages/support_admin/',
        ]],
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function emailTemplate($title, $body, $ticketRef = null) {
    $ref = $ticketRef ? "<p style='color:#a78bfa;font-size:12px'>Ticket: $ticketRef</p>" : '';
    return "
    <div style='font-family:monospace;background:#0a0a0f;color:#e2e8f0;padding:32px;max-width:560px;margin:0 auto;border-radius:16px;border:1px solid #1e1e2e'>
        <div style='margin-bottom:24px'>
            <h2 style='font-family:sans-serif;color:#a78bfa;margin:0 0 4px'>Lyralink Support</h2>
            $ref
        </div>
        <h3 style='color:#e2e8f0;margin:0 0 16px'>$title</h3>
        <div style='color:#94a3b8;line-height:1.7;font-size:13px'>$body</div>
        <hr style='border:none;border-top:1px solid #1e1e2e;margin:24px 0'>
        <p style='color:#334155;font-size:11px'>Lyralink Support Team <a href='https://ai.cloudhavenx.com/pages/support/' style='color:#7c3aed'>View Ticket</a></p>
    </div>";
}

// ── AGENT AUTH CHECK ──
function requireAgent($db) {
    if (empty($_SESSION['agent_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated as agent']); exit;
    }
    $id = (int)$_SESSION['agent_id'];
    $stmt = $db->prepare("SELECT * FROM support_agents WHERE id = ? AND active = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $agent = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    if (!$agent) { echo json_encode(['success' => false, 'error' => 'Agent not found']); exit; }
    return $agent;
}

function requireRole($agent, $minRole) {
    $roles = ['trial_agent' => 0, 'agent' => 1, 'senior_agent' => 2, 'admin' => 3];
    $agentLevel = $roles[$agent['role']] ?? 0;
    $minLevel   = $roles[$minRole]       ?? 0;
    if ($agentLevel < $minLevel) {
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']); exit;
    }
}

function support_webbot_root_dir(): string {
    return dirname(__DIR__) . '/storage/webbots';
}

function support_webbot_user_dir(int $userId): string {
    return support_webbot_root_dir() . '/u' . $userId;
}

function support_webbot_container_name(int $userId): string {
    return 'lyralink_webbot_u' . $userId;
}

function support_webbot_sftp_container_name(int $userId): string {
    return 'lyralink_webbot_sftp_u' . $userId;
}

function support_webbot_meta_path(int $userId): string {
    return support_webbot_user_dir($userId) . '/.lyralink-meta.json';
}

function support_webbot_run(string $command): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => 1, 'out' => '', 'err' => 'Failed to start process'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['ok' => $code === 0, 'code' => $code, 'out' => trim((string)$stdout), 'err' => trim((string)$stderr)];
}

function support_webbot_ensure_dir(string $path): bool {
    return is_dir($path) || mkdir($path, 0755, true);
}

function support_webbot_exists(string $containerName): bool {
    $cmd = 'docker ps -a --filter name=^/' . $containerName . '$ --format {{.Names}}';
    $r = support_webbot_run($cmd);
    return $r['ok'] && trim($r['out']) === $containerName;
}

function support_webbot_is_running(string $containerName): bool {
    $cmd = 'docker inspect -f {{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null';
    $r = support_webbot_run($cmd);
    return $r['ok'] && strtolower(trim($r['out'])) === 'true';
}

function support_webbot_is_restarting(string $containerName): bool {
    $cmd = 'docker inspect -f {{.State.Restarting}} ' . escapeshellarg($containerName) . ' 2>/dev/null';
    $r = support_webbot_run($cmd);
    return $r['ok'] && strtolower(trim($r['out'])) === 'true';
}

function support_webbot_meta_read(int $userId): array {
    $path = support_webbot_meta_path($userId);
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function support_webbot_meta_write(int $userId, array $meta): bool {
    $dir = support_webbot_user_dir($userId);
    if (!support_webbot_ensure_dir($dir)) {
        return false;
    }
    return file_put_contents(support_webbot_meta_path($userId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function support_webbot_random_secret(int $bytes = 12): string {
    return bin2hex(random_bytes($bytes));
}

function support_webbot_find_free_port(int $min = 22000, int $max = 22999): ?int {
    for ($port = $min; $port <= $max; $port++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
        if (is_resource($sock)) {
            fclose($sock);
            continue;
        }
        return $port;
    }
    return null;
}

function support_webbot_list_entries(string $userDir): array {
    if (!is_dir($userDir)) {
        return [];
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $out = [];
    $base = str_replace('\\', '/', $userDir);
    foreach ($rii as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/node_modules/') || str_ends_with($path, '/.lyralink-meta.json')) {
            continue;
        }
        $rel = substr($path, strlen($base) + 1);
        if ($rel === false || $rel === '') {
            continue;
        }
        $out[] = ['path' => $rel, 'type' => $file->isDir() ? 'dir' : 'file'];
    }
    return $out;
}

function support_webbot_delete_recursive(string $path): bool {
    if (!file_exists($path)) {
        return true;
    }
    if (is_file($path) || is_link($path)) {
        return unlink($path);
    }
    if (!is_dir($path)) {
        return false;
    }
    $items = scandir($path);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!support_webbot_delete_recursive($path . '/' . $item)) {
            return false;
        }
    }
    return rmdir($path);
}

function support_webbot_force_remove_workspace(int $userId): bool {
    $dir = support_webbot_user_dir($userId);
    if (!is_dir($dir)) {
        return true;
    }
    if (support_webbot_delete_recursive($dir)) {
        return true;
    }
    $root = support_webbot_root_dir();
    $entry = 'u' . $userId;
    $cmd = 'docker run --rm -v ' . escapeshellarg($root . ':/webbots') . ' node:20-alpine sh -lc ' . escapeshellarg('rm -rf -- /webbots/' . $entry);
    support_webbot_run($cmd);
    return !is_dir($dir) || support_webbot_delete_recursive($dir);
}

function support_webbot_container_diagnostics(string $containerName): array {
    if (!support_webbot_exists($containerName)) {
        return ['status' => 'missing', 'running' => false, 'restarting' => false, 'stable' => true, 'error' => '', 'exit_code' => 0, 'restart_count' => 0, 'logs_tail' => ''];
    }
    $inspect = support_webbot_run('docker inspect ' . escapeshellarg($containerName) . ' 2>/dev/null');
    if (!$inspect['ok']) {
        return ['status' => 'unknown', 'running' => false, 'restarting' => false, 'stable' => false, 'error' => 'Failed to inspect container', 'exit_code' => 0, 'restart_count' => 0, 'logs_tail' => ''];
    }
    $decoded = json_decode($inspect['out'], true);
    $state = is_array($decoded) && isset($decoded[0]['State']) && is_array($decoded[0]['State']) ? $decoded[0]['State'] : [];
    $status = strtolower((string)($state['Status'] ?? 'unknown'));
    $running = (bool)($state['Running'] ?? false);
    $restarting = (bool)($state['Restarting'] ?? false);
    $exitCode = (int)($state['ExitCode'] ?? 0);
    $restartCount = (int)($state['RestartCount'] ?? 0);
    $stateError = trim((string)($state['Error'] ?? ''));
    $oomKilled = !empty($state['OOMKilled']);
    $reason = '';
    if ($stateError !== '') {
        $reason = $stateError;
    } elseif ($restarting) {
        $reason = 'Container is restarting repeatedly';
    } elseif ($oomKilled) {
        $reason = 'Container was killed due to out-of-memory';
    } elseif (!$running && $exitCode !== 0) {
        $reason = 'Container exited with code ' . $exitCode;
    }
    $logsTail = '';
    if (!$running || $restarting || $reason !== '') {
        $logs = support_webbot_run('docker logs --tail 40 ' . escapeshellarg($containerName) . ' 2>&1');
        if ($logs['ok'] || $logs['out'] !== '' || $logs['err'] !== '') {
            $logsTail = trim($logs['out'] !== '' ? $logs['out'] : $logs['err']);
        }
    }
    return ['status' => $status, 'running' => $running, 'restarting' => $restarting, 'stable' => $running && !$restarting && $reason === '', 'error' => $reason, 'exit_code' => $exitCode, 'restart_count' => $restartCount, 'logs_tail' => $logsTail];
}

function support_webbot_sftp_info(int $userId): array {
    $meta = support_webbot_meta_read($userId);
    $container = support_webbot_sftp_container_name($userId);
    return [
        'enabled' => !empty($meta['sftp']['enabled']),
        'host' => $_SERVER['HTTP_HOST'] ?? 'ai.cloudhavenx.com',
        'port' => (int)($meta['sftp']['port'] ?? 0),
        'username' => (string)($meta['sftp']['username'] ?? ''),
        'password' => (string)($meta['sftp']['password'] ?? ''),
        'container_exists' => support_webbot_exists($container),
        'running' => support_webbot_is_running($container),
    ];
}

function support_webbot_enable_sftp(int $userId): array {
    $userDir = support_webbot_user_dir($userId);
    if (!is_dir($userDir)) {
        return ['success' => false, 'error' => 'Workspace not found'];
    }
    $meta = support_webbot_meta_read($userId);
    $username = $meta['sftp']['username'] ?? ('wb' . $userId);
    $password = $meta['sftp']['password'] ?? support_webbot_random_secret(8);
    $port = (int)($meta['sftp']['port'] ?? 0);
    if ($port <= 0) {
        $port = support_webbot_find_free_port() ?? 0;
        if ($port <= 0) {
            return ['success' => false, 'error' => 'No free SFTP port available'];
        }
    }
    $container = support_webbot_sftp_container_name($userId);
    support_webbot_run('docker rm -f ' . escapeshellarg($container) . ' >/dev/null 2>&1');
    $cmd = 'docker run -d --name ' . escapeshellarg($container)
        . ' --restart unless-stopped -p ' . escapeshellarg((string)$port . ':22')
        . ' -v ' . escapeshellarg($userDir . ':/home/' . $username . '/work')
        . ' atmoz/sftp ' . escapeshellarg($username . ':' . $password . ':1001:1001:work');
    $r = support_webbot_run($cmd);
    if (!$r['ok']) {
        return ['success' => false, 'error' => 'Failed to start SFTP container: ' . ($r['err'] ?: $r['out'])];
    }
    $meta['created_at'] = $meta['created_at'] ?? date(DATE_ATOM);
    $meta['sftp'] = ['enabled' => true, 'username' => $username, 'password' => $password, 'port' => $port, 'updated_at' => date(DATE_ATOM)];
    support_webbot_meta_write($userId, $meta);
    return ['success' => true, 'sftp' => support_webbot_sftp_info($userId)];
}

function support_webbot_disable_sftp(int $userId): array {
    support_webbot_run('docker rm -f ' . escapeshellarg(support_webbot_sftp_container_name($userId)) . ' >/dev/null 2>&1');
    $meta = support_webbot_meta_read($userId);
    if (!empty($meta['sftp'])) {
        $meta['sftp']['enabled'] = false;
        $meta['sftp']['updated_at'] = date(DATE_ATOM);
        support_webbot_meta_write($userId, $meta);
    }
    return ['success' => true, 'sftp' => support_webbot_sftp_info($userId)];
}

function support_webbot_start_main_container(int $userId): array {
    $userDir = support_webbot_user_dir($userId);
    $container = support_webbot_container_name($userId);
    if (!is_dir($userDir)) {
        return ['success' => false, 'error' => 'Workspace not found'];
    }
    $runInstall = support_webbot_run('docker run --rm -v ' . escapeshellarg($userDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('npm install --silent'));
    if (!$runInstall['ok']) {
        return ['success' => false, 'error' => 'Dependency install failed: ' . ($runInstall['err'] ?: $runInstall['out'])];
    }
    support_webbot_run('docker rm -f ' . escapeshellarg($container) . ' >/dev/null 2>&1');
    $run = support_webbot_run('docker run -d --name ' . escapeshellarg($container) . ' --restart unless-stopped -v ' . escapeshellarg($userDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('node index.js'));
    if (!$run['ok']) {
        return ['success' => false, 'error' => 'Failed to start instance: ' . ($run['err'] ?: $run['out'])];
    }
    return ['success' => true, 'message' => 'Instance started'];
}

function support_webbot_destroy_instance(int $userId): array {
    support_webbot_run('docker rm -f ' . escapeshellarg(support_webbot_container_name($userId)) . ' >/dev/null 2>&1');
    support_webbot_run('docker rm -f ' . escapeshellarg(support_webbot_sftp_container_name($userId)) . ' >/dev/null 2>&1');
    if (!support_webbot_force_remove_workspace($userId)) {
        return ['success' => false, 'error' => 'Failed to delete workspace files for u' . $userId];
    }
    return ['success' => true, 'message' => 'Instance deleted'];
}

function support_webbot_container_user_ids(): array {
    $r = support_webbot_run('docker ps -a --format {{.Names}}');
    if (!$r['ok'] || trim($r['out']) === '') {
        return [];
    }
    $ids = [];
    foreach (preg_split('/\r?\n/', trim($r['out'])) as $name) {
        if (preg_match('/^lyralink_webbot_u(\d+)$/', trim($name), $m) || preg_match('/^lyralink_webbot_sftp_u(\d+)$/', trim($name), $m)) {
            $ids[(int)$m[1]] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}

function support_webbot_user_by_id(mysqli $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT id, username, email, plan FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $user ?: null;
}

function support_webbot_all_instances(mysqli $db): array {
    $userIds = [];
    $root = support_webbot_root_dir();
    if (is_dir($root)) {
        $entries = scandir($root);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if (preg_match('/^u(\d+)$/', $entry, $m)) {
                    $userIds[(int)$m[1]] = true;
                }
            }
        }
    }
    foreach (support_webbot_container_user_ids() as $id) {
        $userIds[$id] = true;
    }
    $instances = [];
    foreach (array_keys($userIds) as $userId) {
        $user = support_webbot_user_by_id($db, $userId);
        $dir = support_webbot_user_dir($userId);
        $main = support_webbot_container_name($userId);
        $diag = support_webbot_container_diagnostics($main);
        $instances[] = [
            'user_id' => $userId,
            'username' => $user['username'] ?? ('user-' . $userId),
            'email' => $user['email'] ?? '',
            'plan' => $user['plan'] ?? '',
            'workspace_exists' => is_dir($dir),
            'workspace' => basename($dir),
            'file_count' => count(support_webbot_list_entries($dir)),
            'container_exists' => support_webbot_exists($main),
            'running' => support_webbot_is_running($main),
            'restarting' => support_webbot_is_restarting($main),
            'container_name' => $main,
            'sftp' => support_webbot_sftp_info($userId),
            'stability' => $diag,
        ];
    }
    usort($instances, static fn($a, $b) => strcmp($a['username'], $b['username']));
    return $instances;
}

// ════════════════════════════════
// PUBLIC ACTIONS
// ════════════════════════════════

// ── CREATE TICKET ──
if ($action === 'create_ticket') {
    $subject  = trim($_POST['subject']   ?? '');
    $body     = trim($_POST['body']      ?? '');
    $category = $_POST['category']       ?? 'general';
    $priority = $_POST['priority']       ?? 'medium';
    $name     = trim($_POST['name']      ?? '');
    $email    = trim($_POST['email']     ?? '');

    $validCats  = ['general','billing','bug_report','account','live_chat'];
    $validPris  = ['low','medium','high','critical'];
    if (!$subject || !$body)                  { echo json_encode(['success' => false, 'error' => 'Subject and message are required']); exit; }
    if (!in_array($category, $validCats))     { echo json_encode(['success' => false, 'error' => 'Invalid category']); exit; }
    if (!in_array($priority, $validPris))     { echo json_encode(['success' => false, 'error' => 'Invalid priority']); exit; }

    $userId    = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $guestName = $userId ? null : ($name ?: 'Guest');
    $guestEmail= $userId ? null : ($email ?: null);

    if (!$userId && !$guestEmail) { echo json_encode(['success' => false, 'error' => 'Email required for guest tickets']); exit; }

    // Get user email if logged in
    $userEmail = null;
    if ($userId) {
        $stmt = $db->prepare("SELECT email, username FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ud = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $userEmail = $ud['email'] ?? null;
    }

    $ref = genTicketRef();
    $stmt = $db->prepare("SELECT id FROM support_tickets WHERE ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $refResult = $stmt->get_result();
    while ($refResult->num_rows > 0) {
        $ref = genTicketRef();
        $stmt->close();
        $stmt = $db->prepare("SELECT id FROM support_tickets WHERE ticket_ref = ?");
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $refResult = $stmt->get_result();
    }
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO support_tickets (ticket_ref, user_id, guest_email, guest_name, category, subject, body, user_priority) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('siisssss', $ref, $userId, $guestEmail, $guestName, $category, $subject, $body, $priority);
    $stmt->execute();
    $ticketId = $db->insert_id;
    $stmt->close();

    // Fetch full ticket for notifications
    $stmt = $db->prepare("SELECT t.*, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    finishJson(['success' => true, 'ticket_ref' => $ref, 'ticket_id' => $ticketId]);

    // Queue support email
    $supportEmail = getConfig($db, 'support_email');
    $fromName     = $ticket['guest_name'] ?? $ticket['username'] ?? 'Logged-in User';
    $fromEmail    = $guestEmail ?? $userEmail ?? 'unknown';
    queueNotification($db, 'email', [
        'to' => $supportEmail,
        'subject' => "[{$ref}] New " . strtoupper($priority) . " ticket - $subject",
        'html' => emailTemplate("New Support Ticket", "
            <p><strong>Ref:</strong> $ref</p>
            <p><strong>From:</strong> $fromName ($fromEmail)</p>
            <p><strong>Category:</strong> $category</p>
            <p><strong>Priority:</strong> $priority</p>
            <p><strong>Message:</strong></p>
            <blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>$body</blockquote>
            <p><a href='https://ai.cloudhavenx.com/pages/support_admin/' style='color:#a78bfa'>View in Support Dashboard →</a></p>
        ", $ref),
    ]);

    // Queue user confirmation email
    $confirmTo = $guestEmail ?? $userEmail;
    if ($confirmTo) {
        queueNotification($db, 'email', [
            'to' => $confirmTo,
            'subject' => "[$ref] We received your ticket - $subject",
            'html' => emailTemplate("We've got your ticket!", "
                <p>Thanks for reaching out. Your ticket has been created and our team will respond shortly.</p>
                <p><strong>Ticket ID:</strong> $ref</p>
                <p><strong>Subject:</strong> $subject</p>
                <p><strong>Priority:</strong> $priority</p>
                <p>You can track your ticket at <a href='https://ai.cloudhavenx.com/pages/support/' style='color:#a78bfa'>ai.cloudhavenx.com/pages/support/</a></p>
            ", $ref),
        ]);
    }

    queueNotification($db, 'discord', ['ticket' => $ticket]);
    triggerNotificationWorker();
    exit;
}

// ── GET USER'S TICKETS ──
if ($action === 'my_tickets') {
    $userId = $_SESSION['user_id'] ?? null;
    $email  = trim($_POST['email'] ?? '');

    if (!$userId && !$email) { echo json_encode(['success' => false, 'error' => 'Not identified']); exit; }

    if ($userId) {
        $stmt = $db->prepare("SELECT id, ticket_ref, category, subject, status, user_priority, agent_priority, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $db->prepare("SELECT id, ticket_ref, category, subject, status, user_priority, agent_priority, created_at, updated_at FROM support_tickets WHERE guest_email = ? ORDER BY updated_at DESC LIMIT 20");
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $tickets = [];
    while ($r = $result->fetch_assoc()) $tickets[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'tickets' => $tickets]);
    exit;
}

// ── GET SINGLE TICKET (user view) ──
if ($action === 'get_ticket') {
    $ref    = trim($_POST['ref'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$ref) { echo json_encode(['success' => false, 'error' => 'Ticket reference required']); exit; }

    $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN support_agents a ON a.id = t.assigned_to WHERE t.ticket_ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $owned = ($userId && (int)$ticket['user_id'] === (int)$userId) || ($email && strcasecmp((string)$ticket['guest_email'], $email) === 0);
    if (!$owned) { echo json_encode(['success' => false, 'error' => 'Access denied']); exit; }

    $replies = [];
    $replyStmt = $db->prepare("SELECT r.*, a.username as agent_name FROM support_ticket_replies r LEFT JOIN support_agents a ON a.id = r.agent_id WHERE r.ticket_id = ? AND (r.internal = 0 OR r.internal IS NULL) ORDER BY r.created_at ASC");
    $ticketId = (int)$ticket['id'];
    $replyStmt->bind_param('i', $ticketId);
    $replyStmt->execute();
    $rResult = $replyStmt->get_result();
    while ($row = $rResult->fetch_assoc()) {
        $replies[] = $row;
    }
    $replyStmt->close();

    echo json_encode(['success' => true, 'ticket' => $ticket, 'replies' => $replies]);
    exit;
}

// ── USER REPLY ──
if ($action === 'user_reply') {
    $ref     = trim($_POST['ref'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $userId  = $_SESSION['user_id']   ?? null;
    $email   = trim($_POST['email']   ?? '');

    if (!$message) { echo json_encode(['success' => false, 'error' => 'Message required']); exit; }

    $stmt = $db->prepare("SELECT * FROM support_tickets WHERE ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $owned = ($userId && $ticket['user_id'] == $userId) || ($email && $ticket['guest_email'] === $email);
    if (!$owned) { echo json_encode(['success' => false, 'error' => 'Access denied']); exit; }
    if (in_array($ticket['status'], ['resolved','closed'])) { echo json_encode(['success' => false, 'error' => 'Ticket is closed']); exit; }

    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message) VALUES (?, 'user', ?)");
    $stmt->bind_param('is', $ticket['id'], $message);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE support_tickets SET status = 'open', updated_at = NOW() WHERE id = ?");
    $ticketId = (int)$ticket['id'];
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $stmt->close();

    finishJson(['success' => true]);

    // Queue support reply notification
    $supportEmail = getConfig($db, 'support_email');
    queueNotification($db, 'email', [
        'to' => $supportEmail,
        'subject' => "[{$ref}] User replied - {$ticket['subject']}",
        'html' => emailTemplate("User Reply on Ticket", "
            <p><strong>Ticket:</strong> {$ref}</p>
            <blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>$message</blockquote>
            <p><a href='https://ai.cloudhavenx.com/pages/support_admin/' style='color:#a78bfa'>View Ticket →</a></p>
        ", $ref),
    ]);

    triggerNotificationWorker();

    exit;
}

// ── AGENT ONLINE COUNT ──
if ($action === 'agent_count') {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_agents WHERE last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1");
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
    $stmt->close();
    echo json_encode(['success' => true, 'count' => (int)$count]);
    exit;
}

// ════════════════════════════════
// AGENT ACTIONS
// ════════════════════════════════

// ── AGENT LOGIN ──
if ($action === 'agent_login') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $stmt = $db->prepare("SELECT * FROM support_agents WHERE email = ? AND active = 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $agent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($agent && password_verify($password, $agent['password_hash'])) {
        $_SESSION['agent_id']   = $agent['id'];
        $_SESSION['agent_role'] = $agent['role'];
        $updateStmt = $db->prepare("UPDATE support_agents SET last_seen = NOW() WHERE id = ?");
        $agentId = (int)$agent['id'];
        $updateStmt->bind_param('i', $agentId);
        $updateStmt->execute();
        $updateStmt->close();
        echo json_encode(['success' => true, 'role' => $agent['role'], 'username' => $agent['username']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

// ── AGENT LOGOUT ──
if ($action === 'agent_logout') {
    unset($_SESSION['agent_id'], $_SESSION['agent_role']);
    echo json_encode(['success' => true]);
    exit;
}

// ── AGENT HEARTBEAT ──
if ($action === 'heartbeat') {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success' => false]); exit; }
    $id = (int)$_SESSION['agent_id'];
    $stmt = $db->prepare("UPDATE support_agents SET last_seen = NOW() WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── GET TICKET LIST (agents) ──
if ($action === 'ticket_list') {
    $agent  = requireAgent($db);
    $status = trim($_GET['status'] ?? 'open');
    $cat    = trim($_GET['category'] ?? '');
    $pri    = trim($_GET['priority'] ?? '');
    $search = trim($_GET['search'] ?? '');

    $sql = "
        SELECT t.*, u.username, a.username as agent_name,
               (SELECT COUNT(*) FROM support_ticket_replies WHERE ticket_id = t.id) as reply_count
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN support_agents a ON a.id = t.assigned_to
        WHERE 1 = 1";
    $types = '';
    $params = [];
    if ($status !== 'all') {
        $sql .= " AND t.status = ?";
        $types .= 's';
        $params[] = $status;
    }
    if ($cat !== '') {
        $sql .= " AND t.category = ?";
        $types .= 's';
        $params[] = $cat;
    }
    if ($pri !== '') {
        $sql .= " AND COALESCE(t.agent_priority, t.user_priority) = ?";
        $types .= 's';
        $params[] = $pri;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (t.ticket_ref LIKE ? OR t.subject LIKE ?)";
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
    if ($agent['role'] === 'trial_agent' || $agent['role'] === 'agent') {
        $agentId = (int)$agent['id'];
        $sql .= " AND (t.assigned_to = ? OR t.assigned_to IS NULL)";
        $types .= 'i';
        $params[] = $agentId;
    }
    $sql .= "
        ORDER BY
            FIELD(COALESCE(t.agent_priority, t.user_priority), 'critical','high','medium','low'),
            t.updated_at DESC
        LIMIT 100";

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $tickets = [];
    while ($r = $result->fetch_assoc()) $tickets[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'tickets' => $tickets, 'agent' => ['role' => $agent['role'], 'username' => $agent['username']]]);
    exit;
}

// ── GET SINGLE TICKET (agent view) ──
if ($action === 'get_ticket_admin') {
    $agent = requireAgent($db);
    $ref   = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
    $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN support_agents a ON a.id = t.assigned_to WHERE t.ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

    $replies = [];
    $stmt = $db->prepare("SELECT r.*, a.username as agent_name FROM support_ticket_replies r LEFT JOIN support_agents a ON a.id = r.agent_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC");
    $ticketId = (int)$ticket['id'];
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $rResult = $stmt->get_result();
    while ($row = $rResult->fetch_assoc()) $replies[] = $row;
    $stmt->close();

    $agents = [];
    $aStmt = $db->prepare("SELECT id, username, role FROM support_agents WHERE active = 1 ORDER BY role, username");
    $aStmt->execute();
    $aResult = $aStmt->get_result();
    while ($row = $aResult->fetch_assoc()) $agents[] = $row;
    $aStmt->close();

    echo json_encode(['success' => true, 'ticket' => $ticket, 'replies' => $replies, 'agents' => $agents, 'viewer' => ['role' => $agent['role'], 'id' => $agent['id'], 'username' => $agent['username']]]);
    exit;
}

// ── AGENT REPLY ──
if ($action === 'agent_reply') {
    $agent    = requireAgent($db);
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim($_POST['message']    ?? '');
    $internal = (int)($_POST['internal']  ?? 0);

    if (!$message) { echo json_encode(['success' => false, 'error' => 'Message required']); exit; }

    $stmt = $db->prepare("SELECT t.*, u.email as user_email, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, agent_id, message, internal) VALUES (?, 'agent', ?, ?, ?)");
    $stmt->bind_param('iisi', $ticketId, $agent['id'], $message, $internal);
    $stmt->execute();
    $stmt->close();

    if (!$internal) {
        $updateStmt = $db->prepare("UPDATE support_tickets SET status = 'waiting', updated_at = NOW() WHERE id = ?");
        $updateStmt->bind_param('i', $ticketId);
        $updateStmt->execute();
        $updateStmt->close();

        finishJson(['success' => true]);

        // Queue user notification email
        $toEmail = $ticket['guest_email'] ?? $ticket['user_email'];
        if ($toEmail) {
            queueNotification($db, 'email', [
                'to' => $toEmail,
                'subject' => "[{$ticket['ticket_ref']}] Support reply - {$ticket['subject']}",
                'html' => emailTemplate("New reply from support", "
                    <p><strong>{$agent['username']}</strong> replied to your ticket:</p>
                    <blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>$message</blockquote>
                    <p><a href='https://ai.cloudhavenx.com/pages/support/' style='color:#a78bfa'>View & Reply →</a></p>
                ", $ticket['ticket_ref']),
            ]);
        }

        triggerNotificationWorker();
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

ensureSupportQueueTable($db);

// ── UPDATE TICKET (status, priority, assign) ──
if ($action === 'update_ticket') {
    $agent    = requireAgent($db);
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $field    = $_POST['field']  ?? '';
    $value    = $db->real_escape_string($_POST['value'] ?? '');

    $allowed = [];
    switch ($field) {
        case 'status':
            $allowed = ['open','in_progress','waiting','resolved','closed'];
            requireRole($agent, 'agent');
            break;
        case 'agent_priority':
            $allowed = ['low','medium','high','critical'];
            requireRole($agent, 'agent');
            break;
        case 'assigned_to':
            requireRole($agent, 'senior_agent');
            $allowed = null; // any agent id
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid field']); exit;
    }

    if ($allowed !== null && !in_array($value, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid value']); exit;
    }

    if ($field === 'assigned_to') {
        $assignedTo = $value === '' ? null : (int)$value;
        if ($assignedTo !== null) {
            $stmt = $db->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $assignedTo, $ticketId);
        } else {
            $stmt = $db->prepare("UPDATE support_tickets SET assigned_to = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $ticketId);
        }
    } elseif ($field === 'status' && in_array($value, ['resolved', 'closed'], true)) {
        $stmt = $db->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW(), closed_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $value, $ticketId);
    } else {
        $stmt = $db->prepare("UPDATE support_tickets SET `$field` = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $value, $ticketId);
    }
    $stmt->execute();
    $stmt->close();

    // Add system note
    $note = "Status changed to $value by {$agent['username']}";
    if ($field === 'agent_priority') $note = "Priority set to $value by {$agent['username']}";
    if ($field === 'assigned_to') {
        $assignedTo = (int)$value;
        $stmt = $db->prepare("SELECT username FROM support_agents WHERE id = ?");
        $stmt->bind_param('i', $assignedTo);
        $stmt->execute();
        $aRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $note = "Assigned to " . ($aRow['username'] ?? 'agent') . " by {$agent['username']}";
    }
    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
    $stmt->bind_param('is', $ticketId, $note);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ticket id']);
        exit;
    }

    $lookupStmt = $db->prepare("SELECT id, ticket_ref FROM support_tickets WHERE id = ?");
    $lookupStmt->bind_param('i', $ticketId);
    $lookupStmt->execute();
    $ticket = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $deleteReplies = $db->prepare("DELETE FROM support_ticket_replies WHERE ticket_id = ?");
    $deleteReplies->bind_param('i', $ticketId);
    $deleteReplies->execute();
    $deleteReplies->close();

    $deleteTicket = $db->prepare("DELETE FROM support_tickets WHERE id = ?");
    $deleteTicket->bind_param('i', $ticketId);
    $deleteTicket->execute();
    $ok = $deleteTicket->affected_rows > 0;
    $deleteTicket->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete ticket']);
        exit;
    }

    echo json_encode(['success' => true, 'ticket_ref' => $ticket['ticket_ref']]);
    exit;
}

// ── AGENT MANAGEMENT (admin only) ──
if ($action === 'list_agents') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $stmt = $db->prepare("SELECT id, username, email, role, discord_tag, last_seen, active, created_at FROM support_agents ORDER BY role, username");
    $stmt->execute();
    $result = $stmt->get_result();
    $agents = [];
    while ($r = $result->fetch_assoc()) $agents[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'agents' => $agents]);
    exit;
}

if ($action === 'create_agent') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $role     = $_POST['role']          ?? 'trial_agent';
    $validRoles = ['admin','senior_agent','agent','trial_agent'];
    if (!$username || !$email || strlen($password) < 6 || !in_array($role, $validRoles)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']); exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO support_agents (username, email, password_hash, role) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $username, $email, $hash, $role);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
    }
    $stmt->close();
    exit;
}

if ($action === 'update_agent') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $targetId   = (int)($_POST['agent_id'] ?? 0);
    $role       = trim($_POST['role'] ?? '');
    $active     = (int)($_POST['active'] ?? 1);
    $discordId  = trim($_POST['discord_id'] ?? '');
    $discordTag = trim($_POST['discord_tag'] ?? '');
    $discordIdParam = $discordId !== '' ? $discordId : null;
    $discordTagParam = $discordTag !== '' ? $discordTag : null;
    $stmt = $db->prepare("UPDATE support_agents SET role = ?, active = ?, discord_id = ?, discord_tag = ? WHERE id = ?");
    $stmt->bind_param('sissi', $role, $active, $discordIdParam, $discordTagParam, $targetId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── DISCORD ROLE CONFIG (admin) ──
if ($action === 'set_discord_roles') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $priorities = ['low','medium','high','critical'];
    foreach ($priorities as $p) {
        $roleId   = trim($_POST[$p . '_role_id'] ?? '');
        $roleName = trim($_POST[$p . '_role_name'] ?? '');
        if ($roleId) {
            $stmt = $db->prepare("INSERT INTO support_discord_roles (priority, role_id, role_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), role_name = VALUES(role_name)");
            $stmt->bind_param('sss', $p, $roleId, $roleName);
            $stmt->execute();
            $stmt->close();
        }
    }
    $webhook = trim($_POST['webhook_url'] ?? '');
    if ($webhook) {
        $stmt = $db->prepare("UPDATE support_config SET `value` = ? WHERE `key` = 'discord_webhook_url'");
        $stmt->bind_param('s', $webhook);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── SMTP CONFIG (admin) ──
if ($action === 'set_smtp') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $fields = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','support_email'];
    $stmt = $db->prepare("UPDATE support_config SET `value` = ? WHERE `key` = ?");
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $stmt->bind_param('ss', $v, $f);
        $stmt->execute();
    }
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'notification_queue_status') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    ensureSupportQueueTable($db);

    $summary = [
        'pending' => 0,
        'processing' => 0,
        'failed' => 0,
        'sent_24h' => 0,
    ];

    $countsStmt = $db->prepare("SELECT status, COUNT(*) AS total FROM support_notification_queue GROUP BY status");
    $countsStmt->execute();
    $counts = $countsStmt->get_result();
    while ($row = $counts->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['total'];
    }
    $countsStmt->close();

    $sentStmt = $db->prepare("SELECT COUNT(*) AS total FROM support_notification_queue WHERE status = 'sent' AND processed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $sentStmt->execute();
    $sent24 = $sentStmt->get_result()->fetch_assoc();
    $sentStmt->close();
    $summary['sent_24h'] = (int)($sent24['total'] ?? 0);

    $jobs = [];
    $stmt = $db->prepare("SELECT id, channel, status, attempts, last_error, available_at, created_at, processed_at, payload FROM support_notification_queue WHERE status IN ('failed','pending','processing') ORDER BY FIELD(status,'failed','processing','pending'), created_at DESC LIMIT 50");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payload = json_decode($row['payload'], true);
        $target = '';
        if ($row['channel'] === 'email') {
            $target = $payload['to'] ?? '';
        } elseif ($row['channel'] === 'discord') {
            $target = $payload['ticket']['ticket_ref'] ?? 'Discord webhook';
        }
        unset($row['payload']);
        $row['target'] = $target;
        $jobs[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'summary' => $summary, 'jobs' => $jobs]);
    exit;
}

if ($action === 'retry_notification_job') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    ensureSupportQueueTable($db);

    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid job']);
        exit;
    }

    $stmt = $db->prepare("UPDATE support_notification_queue SET status = 'pending', attempts = 0, last_error = NULL, available_at = NOW(), processed_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();
    triggerNotificationWorker();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'webbot_list_instances') {
    $agent = requireAgent($db);
    requireRole($agent, 'senior_agent');
    echo json_encode(['success' => true, 'instances' => support_webbot_all_instances($db)]);
    exit;
}

if ($action === 'webbot_instance_action') {
    $agent = requireAgent($db);
    requireRole($agent, 'senior_agent');

    $userId = (int)($_POST['user_id'] ?? 0);
    $instanceAction = trim((string)($_POST['instance_action'] ?? ''));
    if ($userId <= 0 || $instanceAction === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid instance request']);
        exit;
    }

    $result = null;
    if ($instanceAction === 'start') {
        $result = support_webbot_start_main_container($userId);
    } elseif ($instanceAction === 'manual_restart') {
        $result = support_webbot_start_main_container($userId);
        if (!empty($result['success'])) {
            $result['message'] = 'Manual restart complete';
        }
    } elseif ($instanceAction === 'restart') {
        $restart = support_webbot_run('docker restart ' . escapeshellarg(support_webbot_container_name($userId)));
        $result = $restart['ok'] ? ['success' => true, 'message' => 'Instance restarted'] : ['success' => false, 'error' => 'Failed to restart instance: ' . ($restart['err'] ?: $restart['out'])];
    } elseif ($instanceAction === 'stop') {
        $stop = support_webbot_run('docker stop ' . escapeshellarg(support_webbot_container_name($userId)));
        $result = $stop['ok'] ? ['success' => true, 'message' => 'Instance stopped'] : ['success' => false, 'error' => 'Failed to stop instance: ' . ($stop['err'] ?: $stop['out'])];
    } elseif ($instanceAction === 'delete') {
        $result = support_webbot_destroy_instance($userId);
    } elseif ($instanceAction === 'sftp_enable') {
        $result = support_webbot_enable_sftp($userId);
        if (!empty($result['success'])) {
            $result['message'] = 'SFTP enabled';
        }
    } elseif ($instanceAction === 'sftp_disable') {
        $result = support_webbot_disable_sftp($userId);
        if (!empty($result['success'])) {
            $result['message'] = 'SFTP disabled';
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown instance action']);
        exit;
    }

    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'error' => (string)($result['error'] ?? 'Instance action failed')]);
        exit;
    }

    $instances = support_webbot_all_instances($db);
    $updated = null;
    foreach ($instances as $instance) {
        if ((int)$instance['user_id'] === $userId) {
            $updated = $instance;
            break;
        }
    }

    echo json_encode(['success' => true, 'message' => $result['message'] ?? 'Updated', 'instance' => $updated, 'sftp' => $result['sftp'] ?? null]);
    exit;
}

// ── AGENT STATS ──
if ($action === 'agent_stats') {
    $agent  = requireAgent($db);
    $agentId = $agent['id'];
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE status NOT IN ('resolved','closed')");
    $stmt->execute();
    $open = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE COALESCE(agent_priority, user_priority) = 'critical' AND status NOT IN ('resolved','closed')");
    $stmt->execute();
    $critical = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE assigned_to = ? AND status NOT IN ('resolved','closed')");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $mine = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_agents WHERE last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1");
    $stmt->execute();
    $online = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['success' => true, 'stats' => compact('open','critical','mine','online')]);
    exit;
}

// ── DISCORD TRANSCRIPT — SAVE (called by bot) ──
if ($action === 'save_transcript') {
    ensureDiscordTranscriptTable($db);

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode($rawBody, true);
    if (!is_array($jsonBody)) {
        $jsonBody = [];
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $headerToken = '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $headerToken = trim($m[1]);
    }

    $botKey = $_POST['bot_key'] ?? ($jsonBody['bot_key'] ?? $headerToken);
    $expectedBotKey = (string)getenv('BOT_SECRET_KEY');
    if ($expectedBotKey === '') {
        $expectedBotKey = getConfig($db, 'bot_secret_key');
    }
    if ($expectedBotKey === '') {
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: BOT_SECRET_KEY missing']); exit;
    }
    if (!hash_equals($expectedBotKey, (string)$botKey)) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }

    $ticketRef   = trim((string)($_POST['ticket_ref'] ?? ($jsonBody['ticket_ref'] ?? '')));
    $channelId   = trim((string)($_POST['channel_id'] ?? ($jsonBody['channel_id'] ?? ($jsonBody['channelId'] ?? ''))));
    $channelName = trim((string)($_POST['channel_name'] ?? ($jsonBody['channel_name'] ?? ($jsonBody['channelName'] ?? ''))));
    $guildId     = trim((string)($_POST['guild_id'] ?? ($jsonBody['guild_id'] ?? ($jsonBody['guildId'] ?? ''))));
    $openedBy    = trim((string)($_POST['opened_by'] ?? ($jsonBody['opened_by'] ?? ($jsonBody['openedBy'] ?? ''))));
    $openedById  = trim((string)($_POST['opened_by_id'] ?? ($jsonBody['opened_by_id'] ?? ($jsonBody['openedById'] ?? ''))));
    $category    = trim((string)($_POST['category'] ?? ($jsonBody['category'] ?? '')));
    $closedBy    = trim((string)($_POST['closed_by'] ?? ($jsonBody['closed_by'] ?? ($jsonBody['closedBy'] ?? ''))));
    $msgCount    = (int)($_POST['message_count'] ?? ($jsonBody['message_count'] ?? ($jsonBody['messageCount'] ?? 0)));
    $transcript  = (string)($_POST['transcript'] ?? ($jsonBody['transcript'] ?? ($jsonBody['web_view'] ?? ($jsonBody['webView'] ?? ''))));

    if ($ticketRef === '') {
        $ticketRef = 'DISCORD-' . strtoupper(substr(md5(($channelId ?: uniqid('', true)) . microtime(true)), 0, 8));
    }
    if ($channelId === '') {
        $channelId = 'unknown';
    }
    if ($transcript === '') {
        echo json_encode(['success' => false, 'error' => 'Transcript payload missing']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO discord_ticket_transcripts
        (ticket_ref, channel_id, channel_name, guild_id, opened_by, opened_by_id, category, closed_by, message_count, transcript)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'DB prepare failed']);
        exit;
    }
    $stmt->bind_param('ssssssssis', $ticketRef, $channelId, $channelName, $guildId, $openedBy, $openedById, $category, $closedBy, $msgCount, $transcript);
    $ok = $stmt->execute();
    $insertId = (int)$db->insert_id;
    $stmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Insert failed']);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $insertId]);
    exit;
}

// ── DISCORD TRANSCRIPT — LIST (agent view) ──
if ($action === 'list_transcripts') {
    $agent  = requireAgent($db);
    ensureDiscordTranscriptTable($db);
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $db->prepare("SELECT id, ticket_ref, channel_name, opened_by, category, closed_by, message_count, created_at FROM discord_ticket_transcripts WHERE opened_by LIKE ? OR ticket_ref LIKE ? OR channel_name LIKE ? ORDER BY created_at DESC LIMIT 100");
        $stmt->bind_param('sss', $like, $like, $like);
    } else {
        $stmt = $db->prepare("SELECT id, ticket_ref, channel_name, opened_by, category, closed_by, message_count, created_at FROM discord_ticket_transcripts ORDER BY created_at DESC LIMIT 100");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'transcripts' => $rows]);
    exit;
}

// ── DISCORD TRANSCRIPT — GET SINGLE ──
if ($action === 'get_transcript') {
    $agent = requireAgent($db);
    ensureDiscordTranscriptTable($db);
    $id    = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM discord_ticket_transcripts WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
    echo json_encode(['success' => true, 'transcript' => $row]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>