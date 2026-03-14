<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

api_enforce_post_and_origin_for_actions([
    'create',
    'start',
    'stop',
    'restart',
    'save_file',
    'console_exec',
    'create_entry',
    'delete_entry',
    'rename_entry',
    'sftp_enable',
    'sftp_disable',
    'admin_delete_instance',
    'admin_start_instance',
    'admin_stop_instance',
    'admin_restart_instance',
    'admin_sftp_enable',
    'admin_sftp_disable',
]);

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('DB connection failed', 500);
}
$db->set_charset('utf8mb4');

function webbot_enabled(): bool {
    return api_get_secret('ENABLE_WEB_BOT_PANEL', '0') === '1';
}

function webbot_dev_admin_username(): string {
    return api_get_secret('ADMIN_DEV_USERNAME', 'developer') ?? 'developer';
}

function require_user(mysqli $db): array {
    if (empty($_SESSION['user_id'])) {
        api_fail('Login required', 401);
    }
    $userId = (int)$_SESSION['user_id'];
    $stmt = $db->prepare('SELECT id, username, email, plan FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        api_fail('Failed to prepare user lookup', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$user) {
        api_fail('User not found', 401);
    }
    return $user;
}

function require_admin(mysqli $db): array {
    $user = require_user($db);
    $username = (string)($_SESSION['username'] ?? $user['username'] ?? '');
    if (!empty($_SESSION['is_admin']) || $username === webbot_dev_admin_username()) {
        return $user;
    }
    api_fail('Forbidden', 403);
}

function webbot_root_dir(): string {
    return dirname(__DIR__) . '/storage/webbots';
}

function webbot_user_dir(int $userId): string {
    return webbot_root_dir() . '/u' . $userId;
}

function webbot_container_name(int $userId): string {
    return 'lyralink_webbot_u' . $userId;
}

function webbot_sftp_container_name(int $userId): string {
    return 'lyralink_webbot_sftp_u' . $userId;
}

function webbot_meta_path(int $userId): string {
    return webbot_user_dir($userId) . '/.lyralink-meta.json';
}

function webbot_run(string $command): array {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
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

function webbot_docker_available(): bool {
    $r = webbot_run('command -v docker');
    return $r['ok'] && $r['out'] !== '';
}

function webbot_ensure_dir(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    return mkdir($path, 0755, true);
}

function webbot_meta_read(int $userId): array {
    $path = webbot_meta_path($userId);
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

function webbot_meta_write(int $userId, array $meta): bool {
    $dir = webbot_user_dir($userId);
    if (!webbot_ensure_dir($dir)) {
        return false;
    }
    $path = webbot_meta_path($userId);
    return file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function webbot_random_secret(int $bytes = 12): string {
    return bin2hex(random_bytes($bytes));
}

function webbot_find_free_port(int $min = 22000, int $max = 22999): ?int {
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

function webbot_seed_files(string $userDir): void {
    $envPath = $userDir . '/.env';
    if (!is_file($envPath)) {
        file_put_contents($envPath, "DISCORD_TOKEN=\nCLIENT_ID=\nGUILD_ID=\n");
    }

    $pkgPath = $userDir . '/package.json';
    if (!is_file($pkgPath)) {
        $pkg = [
            'name' => 'lyralink-web-discord-bot',
            'version' => '1.0.0',
            'description' => 'Web-managed Discord bot instance',
            'main' => 'index.js',
            'type' => 'commonjs',
            'scripts' => [
                'start' => 'node index.js',
            ],
            'dependencies' => [
                'discord.js' => '^14.16.3',
                'dotenv' => '^16.4.7',
            ],
        ];
        file_put_contents($pkgPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $idxPath = $userDir . '/index.js';
    if (!is_file($idxPath)) {
        $indexJs = <<<'JS'
const { Client, GatewayIntentBits } = require('discord.js');
require('dotenv').config();

const token = process.env.DISCORD_TOKEN;
if (!token) {
  console.error('Missing DISCORD_TOKEN in .env');
  process.exit(1);
}

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.MessageContent,
  ],
});

client.once('ready', () => {
  console.log(`Bot ready: ${client.user.tag}`);
});

client.on('messageCreate', async (msg) => {
  if (msg.author.bot) return;
  if (msg.content === '!ping') {
    await msg.reply('pong');
  }
});

client.login(token);
JS;
        file_put_contents($idxPath, $indexJs . "\n");
    }

    $readmePath = $userDir . '/README.md';
    if (!is_file($readmePath)) {
        file_put_contents($readmePath, "# Lyralink Web Bot\n\n- Set credentials in .env\n- Edit index.js in the web editor\n- Start from the panel\n- Use SFTP with the credentials from the panel\n");
    }
}

function webbot_exists(string $containerName): bool {
    $cmd = 'docker ps -a --filter name=^/' . $containerName . '$ --format {{.Names}}';
    $r = webbot_run($cmd);
    return $r['ok'] && trim($r['out']) === $containerName;
}

function webbot_is_running(string $containerName): bool {
    $cmd = 'docker inspect -f {{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null';
    $r = webbot_run($cmd);
    return $r['ok'] && strtolower(trim($r['out'])) === 'true';
}

function webbot_safe_rel_path(string $path): ?string {
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    if ($path === '' || strlen($path) > 240) {
        return null;
    }
    if (str_contains($path, '..')) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_\-.\/]+$/', $path)) {
        return null;
    }
    return $path;
}

function webbot_list_entries(string $userDir): array {
    if (!is_dir($userDir)) {
        return [];
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
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
        $out[] = [
            'path' => $rel,
            'type' => $file->isDir() ? 'dir' : 'file',
            'size' => $file->isDir() ? 0 : $file->getSize(),
            'mtime' => $file->getMTime(),
        ];
    }
    usort($out, static function ($a, $b): int {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }
        return strcmp($a['path'], $b['path']);
    });
    return $out;
}

function webbot_delete_recursive(string $path): bool {
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
        if (!webbot_delete_recursive($path . '/' . $item)) {
            return false;
        }
    }
    return rmdir($path);
}

function webbot_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function webbot_public_ws_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'ai.cloudhavenx.com';
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return ($https ? 'wss://' : 'ws://') . $host . '/ws/webbot';
}

function webbot_secret(): string {
    return api_get_secret('BOT_SECRET_KEY', 'lyralink-webbot-secret') ?? 'lyralink-webbot-secret';
}

function webbot_ws_token(array $user, int $userId, string $mode): string {
    $payload = [
        'uid' => $userId,
        'usr' => (string)$user['username'],
        'container' => webbot_container_name($userId),
        'workspace' => webbot_user_dir($userId),
        'mode' => $mode,
        'exp' => time() + 900,
    ];
    $encoded = webbot_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $encoded, webbot_secret());
    return $encoded . '.' . $sig;
}

function webbot_sftp_info(int $userId): array {
    $meta = webbot_meta_read($userId);
    $container = webbot_sftp_container_name($userId);
    return [
        'enabled' => !empty($meta['sftp']['enabled']),
        'host' => $_SERVER['HTTP_HOST'] ?? 'ai.cloudhavenx.com',
        'port' => (int)($meta['sftp']['port'] ?? 0),
        'username' => (string)($meta['sftp']['username'] ?? ''),
        'password' => (string)($meta['sftp']['password'] ?? ''),
        'container_exists' => webbot_exists($container),
        'running' => webbot_is_running($container),
    ];
}

function webbot_enable_sftp(int $userId): array {
    $userDir = webbot_user_dir($userId);
    if (!is_dir($userDir)) {
        return ['success' => false, 'error' => 'Create workspace first'];
    }

    $meta = webbot_meta_read($userId);
    $username = $meta['sftp']['username'] ?? ('wb' . $userId);
    $password = $meta['sftp']['password'] ?? webbot_random_secret(8);
    $port = (int)($meta['sftp']['port'] ?? 0);
    if ($port <= 0) {
        $found = webbot_find_free_port();
        if ($found === null) {
            return ['success' => false, 'error' => 'No free SFTP port available'];
        }
        $port = $found;
    }

    $container = webbot_sftp_container_name($userId);
    webbot_run('docker rm -f ' . escapeshellarg($container) . ' >/dev/null 2>&1');

    $cmd = 'docker run -d --name ' . escapeshellarg($container)
        . ' --restart unless-stopped -p ' . escapeshellarg((string)$port . ':22')
        . ' -v ' . escapeshellarg($userDir . ':/home/' . $username . '/work')
        . ' atmoz/sftp ' . escapeshellarg($username . ':' . $password . ':1001:1001:work');

    $r = webbot_run($cmd);
    if (!$r['ok']) {
        return ['success' => false, 'error' => 'Failed to start SFTP container: ' . ($r['err'] ?: $r['out'])];
    }

    $meta['created_at'] = $meta['created_at'] ?? date(DATE_ATOM);
    $meta['sftp'] = [
        'enabled' => true,
        'username' => $username,
        'password' => $password,
        'port' => $port,
        'updated_at' => date(DATE_ATOM),
    ];
    webbot_meta_write($userId, $meta);

    return ['success' => true, 'sftp' => webbot_sftp_info($userId)];
}

function webbot_disable_sftp(int $userId): array {
    $container = webbot_sftp_container_name($userId);
    webbot_run('docker rm -f ' . escapeshellarg($container) . ' >/dev/null 2>&1');
    $meta = webbot_meta_read($userId);
    if (!empty($meta['sftp'])) {
        $meta['sftp']['enabled'] = false;
        $meta['sftp']['updated_at'] = date(DATE_ATOM);
        webbot_meta_write($userId, $meta);
    }
    return ['success' => true, 'sftp' => webbot_sftp_info($userId)];
}

function webbot_destroy_instance(int $userId): array {
    webbot_run('docker rm -f ' . escapeshellarg(webbot_container_name($userId)) . ' >/dev/null 2>&1');
    webbot_run('docker rm -f ' . escapeshellarg(webbot_sftp_container_name($userId)) . ' >/dev/null 2>&1');
    $dir = webbot_user_dir($userId);
    if (is_dir($dir) && !webbot_delete_recursive($dir)) {
        return ['success' => false, 'error' => 'Failed to delete workspace'];
    }
    return ['success' => true, 'message' => 'Instance deleted'];
}

function webbot_user_by_id(mysqli $db, int $userId): ?array {
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

function webbot_all_instances(mysqli $db): array {
    $root = webbot_root_dir();
    if (!is_dir($root)) {
        return [];
    }
    $entries = scandir($root);
    if ($entries === false) {
        return [];
    }
    $instances = [];
    foreach ($entries as $entry) {
        if (!preg_match('/^u(\d+)$/', $entry, $m)) {
            continue;
        }
        $userId = (int)$m[1];
        $user = webbot_user_by_id($db, $userId);
        $dir = webbot_user_dir($userId);
        $main = webbot_container_name($userId);
        $sftp = webbot_sftp_info($userId);
        $files = webbot_list_entries($dir);
        $instances[] = [
            'user_id' => $userId,
            'username' => $user['username'] ?? ('user-' . $userId),
            'email' => $user['email'] ?? '',
            'plan' => $user['plan'] ?? '',
            'workspace_exists' => is_dir($dir),
            'workspace' => basename($dir),
            'file_count' => count($files),
            'container_exists' => webbot_exists($main),
            'running' => webbot_is_running($main),
            'container_name' => $main,
            'sftp' => $sftp,
        ];
    }
    usort($instances, static fn($a, $b) => strcmp($a['username'], $b['username']));
    return $instances;
}

if (!webbot_enabled()) {
    echo json_encode(['success' => false, 'error' => 'Web bot panel is disabled by server config', 'disabled' => true]);
    exit;
}

$user = require_user($db);
$userId = (int)$user['id'];
$userDir = webbot_user_dir($userId);
$container = webbot_container_name($userId);
$action = api_action();

if ($action === 'status') {
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'docker_available' => webbot_docker_available(),
        'workspace_exists' => is_dir($userDir),
        'container_exists' => webbot_exists($container),
        'running' => webbot_is_running($container),
        'container_name' => $container,
        'websocket_url' => webbot_public_ws_url(),
        'sftp' => webbot_sftp_info($userId),
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'plan' => $user['plan'],
        ],
    ]);
    exit;
}

if ($action === 'ws_auth') {
    if (!webbot_is_running($container)) {
        api_fail('Container is not running', 400);
    }
    echo json_encode(['success' => true, 'url' => webbot_public_ws_url(), 'token' => webbot_ws_token($user, $userId, 'terminal'), 'expires_in' => 900]);
    exit;
}

if ($action === 'watch_auth') {
    if (!is_dir($userDir)) {
        api_fail('Workspace not created', 400);
    }
    echo json_encode(['success' => true, 'url' => webbot_public_ws_url(), 'token' => webbot_ws_token($user, $userId, 'watch'), 'expires_in' => 900]);
    exit;
}

if ($action === 'sftp_status') {
    echo json_encode(['success' => true, 'sftp' => webbot_sftp_info($userId)]);
    exit;
}

if ($action === 'admin_list_instances') {
    require_admin($db);
    echo json_encode(['success' => true, 'instances' => webbot_all_instances($db)]);
    exit;
}

if (!webbot_docker_available()) {
    api_fail('Docker is not installed or not available to PHP user', 500);
}

if ($action === 'create') {
    if (!webbot_ensure_dir($userDir)) {
        api_fail('Failed to create workspace', 500);
    }
    webbot_seed_files($userDir);
    $meta = webbot_meta_read($userId);
    $meta['created_at'] = $meta['created_at'] ?? date(DATE_ATOM);
    webbot_meta_write($userId, $meta);
    echo json_encode(['success' => true, 'message' => 'Workspace created', 'workspace' => basename($userDir)]);
    exit;
}

if ($action === 'start') {
    if (!is_dir($userDir)) {
        api_fail('Create workspace first', 400);
    }
    $runInstall = webbot_run('docker run --rm -v ' . escapeshellarg($userDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('npm install --silent'));
    if (!$runInstall['ok']) {
        api_fail('Dependency install failed: ' . ($runInstall['err'] ?: $runInstall['out']), 500);
    }
    webbot_run('docker rm -f ' . escapeshellarg($container) . ' >/dev/null 2>&1');
    $runCmd = 'docker run -d --name ' . escapeshellarg($container) . ' --restart unless-stopped -v ' . escapeshellarg($userDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('node index.js');
    $run = webbot_run($runCmd);
    if (!$run['ok']) {
        api_fail('Failed to start container: ' . ($run['err'] ?: $run['out']), 500);
    }
    echo json_encode(['success' => true, 'message' => 'Container started']);
    exit;
}

if ($action === 'stop') {
    $r = webbot_run('docker stop ' . escapeshellarg($container));
    if (!$r['ok']) {
        api_fail('Failed to stop container: ' . ($r['err'] ?: $r['out']), 500);
    }
    echo json_encode(['success' => true, 'message' => 'Container stopped']);
    exit;
}

if ($action === 'restart') {
    $r = webbot_run('docker restart ' . escapeshellarg($container));
    if (!$r['ok']) {
        api_fail('Failed to restart container: ' . ($r['err'] ?: $r['out']), 500);
    }
    echo json_encode(['success' => true, 'message' => 'Container restarted']);
    exit;
}

if ($action === 'logs') {
    $tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 150;
    $tail = max(10, min(500, $tail));
    $r = webbot_run('docker logs --tail ' . $tail . ' ' . escapeshellarg($container) . ' 2>&1');
    if (!$r['ok'] && !webbot_exists($container)) {
        api_fail('Container does not exist', 400);
    }
    echo json_encode(['success' => true, 'logs' => $r['out'] !== '' ? $r['out'] : $r['err'], 'running' => webbot_is_running($container)]);
    exit;
}

if ($action === 'console_exec') {
    $cmd = trim((string)($_POST['command'] ?? ''));
    if ($cmd === '') {
        api_fail('Command is required');
    }
    if (strlen($cmd) > 300) {
        api_fail('Command too long');
    }
    if (!webbot_is_running($container)) {
        api_fail('Container is not running', 400);
    }
    $r = webbot_run('docker exec -i ' . escapeshellarg($container) . ' sh -lc ' . escapeshellarg($cmd));
    echo json_encode(['success' => $r['ok'], 'exit_code' => $r['code'], 'stdout' => $r['out'], 'stderr' => $r['err']]);
    exit;
}

if ($action === 'list_files') {
    echo json_encode(['success' => true, 'files' => webbot_list_entries($userDir)]);
    exit;
}

if ($action === 'read_file') {
    $path = webbot_safe_rel_path((string)($_GET['path'] ?? ''));
    if ($path === null) {
        api_fail('Invalid file path');
    }
    $full = $userDir . '/' . $path;
    if (!is_file($full)) {
        api_fail('File not found', 404);
    }
    $content = file_get_contents($full);
    if ($content === false) {
        api_fail('Failed to read file', 500);
    }
    echo json_encode(['success' => true, 'path' => $path, 'content' => $content, 'mtime' => filemtime($full)]);
    exit;
}

if ($action === 'save_file') {
    $path = webbot_safe_rel_path((string)($_POST['path'] ?? ''));
    if ($path === null) {
        api_fail('Invalid file path');
    }
    $content = (string)($_POST['content'] ?? '');
    if (strlen($content) > 300000) {
        api_fail('File too large');
    }
    if (!is_dir($userDir) && !webbot_ensure_dir($userDir)) {
        api_fail('Failed to create workspace directory', 500);
    }
    $target = $userDir . '/' . $path;
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        api_fail('Failed to create folder for file', 500);
    }
    $ok = file_put_contents($target, $content);
    if ($ok === false) {
        api_fail('Failed to save file', 500);
    }
    echo json_encode(['success' => true, 'message' => 'File saved', 'bytes' => strlen($content), 'mtime' => filemtime($target)]);
    exit;
}

if ($action === 'create_entry') {
    $path = webbot_safe_rel_path((string)($_POST['path'] ?? ''));
    $kind = (string)($_POST['kind'] ?? 'file');
    if ($path === null) {
        api_fail('Invalid path');
    }
    if (!in_array($kind, ['file', 'dir'], true)) {
        api_fail('Invalid entry type');
    }
    if (!is_dir($userDir) && !webbot_ensure_dir($userDir)) {
        api_fail('Failed to create workspace directory', 500);
    }
    $target = $userDir . '/' . $path;
    if (file_exists($target)) {
        api_fail('Target already exists');
    }
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
        api_fail('Failed to create parent directory', 500);
    }
    $ok = $kind === 'dir' ? mkdir($target, 0755, true) : file_put_contents($target, '') !== false;
    if (!$ok) {
        api_fail('Failed to create entry', 500);
    }
    echo json_encode(['success' => true, 'message' => ucfirst($kind) . ' created']);
    exit;
}

if ($action === 'rename_entry') {
    $oldPath = webbot_safe_rel_path((string)($_POST['old_path'] ?? ''));
    $newPath = webbot_safe_rel_path((string)($_POST['new_path'] ?? ''));
    if ($oldPath === null || $newPath === null) {
        api_fail('Invalid path');
    }
    $oldFull = $userDir . '/' . $oldPath;
    $newFull = $userDir . '/' . $newPath;
    if (!file_exists($oldFull)) {
        api_fail('Source does not exist', 404);
    }
    if (file_exists($newFull)) {
        api_fail('Destination already exists');
    }
    $parent = dirname($newFull);
    if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
        api_fail('Failed to create destination directory', 500);
    }
    if (!rename($oldFull, $newFull)) {
        api_fail('Failed to rename entry', 500);
    }
    echo json_encode(['success' => true, 'message' => 'Entry renamed']);
    exit;
}

if ($action === 'delete_entry') {
    $path = webbot_safe_rel_path((string)($_POST['path'] ?? ''));
    if ($path === null) {
        api_fail('Invalid path');
    }
    $target = $userDir . '/' . $path;
    if (!file_exists($target)) {
        api_fail('Entry not found', 404);
    }
    if (!webbot_delete_recursive($target)) {
        api_fail('Failed to delete entry', 500);
    }
    echo json_encode(['success' => true, 'message' => 'Entry deleted']);
    exit;
}

if ($action === 'sftp_enable') {
    $result = webbot_enable_sftp($userId);
    if (!$result['success']) {
        api_fail($result['error'] ?? 'Failed to enable SFTP', 500);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'sftp_disable') {
    echo json_encode(webbot_disable_sftp($userId));
    exit;
}

if (str_starts_with($action, 'admin_')) {
    require_admin($db);
    $targetUserId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        api_fail('Invalid user_id');
    }

    if ($action === 'admin_delete_instance') {
        echo json_encode(webbot_destroy_instance($targetUserId));
        exit;
    }
    if ($action === 'admin_start_instance') {
        $targetDir = webbot_user_dir($targetUserId);
        if (!is_dir($targetDir)) {
            api_fail('Workspace not found', 404);
        }
        $runInstall = webbot_run('docker run --rm -v ' . escapeshellarg($targetDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('npm install --silent'));
        if (!$runInstall['ok']) {
            api_fail('Dependency install failed: ' . ($runInstall['err'] ?: $runInstall['out']), 500);
        }
        $mainContainer = webbot_container_name($targetUserId);
        webbot_run('docker rm -f ' . escapeshellarg($mainContainer) . ' >/dev/null 2>&1');
        $run = webbot_run('docker run -d --name ' . escapeshellarg($mainContainer) . ' --restart unless-stopped -v ' . escapeshellarg($targetDir . ':/app') . ' -w /app node:20-alpine sh -lc ' . escapeshellarg('node index.js'));
        if (!$run['ok']) {
            api_fail('Failed to start instance: ' . ($run['err'] ?: $run['out']), 500);
        }
        echo json_encode(['success' => true, 'message' => 'Instance started']);
        exit;
    }
    if ($action === 'admin_stop_instance') {
        $stop = webbot_run('docker stop ' . escapeshellarg(webbot_container_name($targetUserId)));
        if (!$stop['ok']) {
            api_fail('Failed to stop instance: ' . ($stop['err'] ?: $stop['out']), 500);
        }
        echo json_encode(['success' => true, 'message' => 'Instance stopped']);
        exit;
    }
    if ($action === 'admin_restart_instance') {
        $restart = webbot_run('docker restart ' . escapeshellarg(webbot_container_name($targetUserId)));
        if (!$restart['ok']) {
            api_fail('Failed to restart instance: ' . ($restart['err'] ?: $restart['out']), 500);
        }
        echo json_encode(['success' => true, 'message' => 'Instance restarted']);
        exit;
    }
    if ($action === 'admin_sftp_enable') {
        $result = webbot_enable_sftp($targetUserId);
        if (!$result['success']) {
            api_fail($result['error'] ?? 'Failed to enable SFTP', 500);
        }
        echo json_encode($result);
        exit;
    }
    if ($action === 'admin_sftp_disable') {
        echo json_encode(webbot_disable_sftp($targetUserId));
        exit;
    }
}

api_fail('Unknown action');
