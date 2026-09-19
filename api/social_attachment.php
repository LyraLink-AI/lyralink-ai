<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
lyra_session_boot();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not logged in';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid attachment id';
    exit;
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DB connection failed';
    exit;
}
$db->set_charset('utf8mb4');

$stmt = $db->prepare('SELECT a.id, a.uploader_user_id, a.disk_path, a.original_name, a.mime_type, a.size_bytes FROM social_attachments a WHERE a.id = ? LIMIT 1');
$stmt->bind_param('i', $attachmentId);
$stmt->execute();
$att = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$att) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Attachment not found';
    exit;
}

$allowed = false;
if ((int)$att['uploader_user_id'] === $uid) {
    $allowed = true;
} else {
    $stmt = $db->prepare('SELECT m.conversation_id, c.type, c.server_id FROM social_messages m INNER JOIN social_conversations c ON c.id = m.conversation_id WHERE m.attachment_id = ? ORDER BY m.id DESC LIMIT 1');
    $stmt->bind_param('i', $attachmentId);
    $stmt->execute();
    $ctx = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ctx) {
        $type = (string)($ctx['type'] ?? '');
        if ($type === 'channel') {
            $serverId = (int)($ctx['server_id'] ?? 0);
            $stmt = $db->prepare('SELECT 1 FROM social_server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
            $stmt->bind_param('ii', $serverId, $uid);
            $stmt->execute();
            $allowed = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
        } else {
            $convId = (int)$ctx['conversation_id'];
            $stmt = $db->prepare('SELECT 1 FROM social_conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1');
            $stmt->bind_param('ii', $convId, $uid);
            $stmt->execute();
            $allowed = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$relPath = (string)$att['disk_path'];
$base = realpath(dirname(__DIR__) . '/storage');
$full = realpath(dirname(__DIR__) . '/' . ltrim($relPath, '/'));
if (!$base || !$full || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing';
    exit;
}

$mime = (string)($att['mime_type'] ?: 'application/octet-stream');
$name = (string)($att['original_name'] ?: ('attachment-' . $attachmentId));
$size = (int)($att['size_bytes'] ?? filesize($full));

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
readfile($full);
exit;
