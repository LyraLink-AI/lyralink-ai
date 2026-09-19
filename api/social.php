<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
lyra_session_boot();
api_json_headers();

api_enforce_post_and_origin_for_actions([
    'bootstrap',
    'create_server',
    'create_channel',
    'create_dm',
    'create_group',
    'list_messages',
    'send_message',
    'upload_attachment',
    'set_typing',
    'heartbeat',
    'mark_read',
    'poll',
    'voice_join',
    'voice_leave',
    'voice_ping',
    'voice_signal_send',
    'voice_signal_poll',
]);

if (empty($_SESSION['user_id'])) {
    api_fail('Not logged in', 401);
}

$uid = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'user');

action_bootstrap_env_if_missing();

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

social_ensure_schema($db);
$defaults = social_ensure_default_server($db, $uid);

$action = api_action();
if ($action === '') {
    $action = $_POST['action'] ?? 'bootstrap';
}

if ($action === 'bootstrap') {
    $presenceStatus = social_normalize_presence_status((string)($_POST['presence_status'] ?? 'online'));
    social_presence_heartbeat($db, $uid, $presenceStatus);

    $servers = social_get_servers_for_user($db, $uid);
    $dms = social_get_dm_conversations($db, $uid);
    $groups = social_get_group_conversations($db, $uid);

    $rtc = [
        'stun' => social_csv(api_get_secret('WEBRTC_STUN_URLS', 'stun:stun.l.google.com:19302')),
        'turn' => social_csv(api_get_secret('WEBRTC_TURN_URLS', '')),
        'turn_username' => api_get_secret('WEBRTC_TURN_USERNAME', ''),
        'turn_credential' => api_get_secret('WEBRTC_TURN_CREDENTIAL', ''),
        'ice_transport_policy' => strtolower((string)api_get_secret('WEBRTC_ICE_TRANSPORT_POLICY', 'all')) === 'relay' ? 'relay' : 'all',
    ];
    $rtc['has_turn'] = !empty($rtc['turn']) && $rtc['turn_username'] !== '' && $rtc['turn_credential'] !== '';

    echo json_encode([
        'success' => true,
        'me' => [
            'id' => $uid,
            'username' => $username,
            'presence_status' => $presenceStatus,
        ],
        'default_server_id' => $defaults['server_id'],
        'default_text_channel_id' => $defaults['text_channel_id'],
        'default_text_conversation_id' => $defaults['text_conversation_id'],
        'servers' => $servers,
        'dms' => $dms,
        'groups' => $groups,
        'rtc' => $rtc,
    ]);
    exit;
}

if ($action === 'create_server') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        api_fail('Server name required');
    }
    $name = mb_substr($name, 0, 80);

    $stmt = $db->prepare('INSERT INTO social_servers (name, owner_user_id) VALUES (?, ?)');
    $stmt->bind_param('si', $name, $uid);
    if (!$stmt->execute()) {
        api_fail('Failed to create server', 500);
    }
    $serverId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO social_server_members (server_id, user_id, role, last_seen_at) VALUES (?, ?, 'owner', NOW())");
    $stmt->bind_param('ii', $serverId, $uid);
    $stmt->execute();
    $stmt->close();

    $text = social_create_channel($db, $serverId, 'general', 'text', $uid, 1);
    social_create_channel($db, $serverId, 'voice-lounge', 'voice', $uid, 2);

    echo json_encode([
        'success' => true,
        'server_id' => $serverId,
        'text_conversation_id' => $text['conversation_id'],
    ]);
    exit;
}

if ($action === 'create_channel') {
    $serverId = (int)($_POST['server_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $type = strtolower(trim((string)($_POST['type'] ?? 'text')));
    if (!in_array($type, ['text', 'voice'], true)) {
        api_fail('Invalid channel type');
    }
    if ($serverId <= 0 || $name === '') {
        api_fail('Invalid channel data');
    }

    if (!social_user_can_manage_server($db, $uid, $serverId)) {
        api_fail('Forbidden', 403);
    }

    $name = mb_substr($name, 0, 80);
    $row = social_create_channel($db, $serverId, $name, $type, $uid, 999);

    echo json_encode(['success' => true, 'channel' => $row]);
    exit;
}

if ($action === 'create_dm') {
    $targetUsername = trim((string)($_POST['username'] ?? ''));
    if ($targetUsername === '') {
        api_fail('Target username required');
    }

    $stmt = $db->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $targetUsername);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        api_fail('User not found', 404);
    }

    $targetId = (int)$target['id'];
    if ($targetId === $uid) {
        api_fail('Cannot DM yourself');
    }

    $existingId = social_find_dm_conversation($db, $uid, $targetId);
    if ($existingId > 0) {
        echo json_encode(['success' => true, 'conversation_id' => $existingId]);
        exit;
    }

    $db->begin_transaction();
    try {
        $title = $target['username'];
        $stmt = $db->prepare("INSERT INTO social_conversations (type, title, created_by, updated_at) VALUES ('dm', ?, ?, NOW())");
        $stmt->bind_param('si', $title, $uid);
        $stmt->execute();
        $convId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO social_conversation_members (conversation_id, user_id, is_admin, last_seen_at) VALUES (?, ?, ?, NOW())');
        $isAdmin = 1;
        $stmt->bind_param('iii', $convId, $uid, $isAdmin);
        $stmt->execute();
        $isAdmin = 0;
        $stmt->bind_param('iii', $convId, $targetId, $isAdmin);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        echo json_encode(['success' => true, 'conversation_id' => $convId]);
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        api_fail('Failed to create DM', 500);
    }
}

if ($action === 'create_group') {
    $title = trim((string)($_POST['title'] ?? 'Group Chat'));
    $rawUsers = trim((string)($_POST['usernames'] ?? ''));
    $usernames = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawUsers)))));

    if (count($usernames) < 1) {
        api_fail('Provide at least one username');
    }

    $memberIds = [];
    foreach ($usernames as $u) {
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $memberIds[] = (int)$row['id'];
        }
    }

    $memberIds[] = $uid;
    $memberIds = array_values(array_unique(array_filter($memberIds, fn($id) => $id > 0)));

    if (count($memberIds) < 2) {
        api_fail('Need at least two members');
    }

    $title = mb_substr($title, 0, 100);

    $db->begin_transaction();
    try {
        $stmt = $db->prepare("INSERT INTO social_conversations (type, title, created_by, updated_at) VALUES ('group', ?, ?, NOW())");
        $stmt->bind_param('si', $title, $uid);
        $stmt->execute();
        $convId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO social_conversation_members (conversation_id, user_id, is_admin, last_seen_at) VALUES (?, ?, ?, NOW())');
        foreach ($memberIds as $memberId) {
            $isAdmin = $memberId === $uid ? 1 : 0;
            $stmt->bind_param('iii', $convId, $memberId, $isAdmin);
            $stmt->execute();
        }
        $stmt->close();

        $db->commit();
        echo json_encode(['success' => true, 'conversation_id' => $convId]);
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        api_fail('Failed to create group', 500);
    }
}

if ($action === 'list_messages') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $beforeId = (int)($_POST['before_id'] ?? 0);
    if (!social_user_in_conversation($db, $uid, $convId)) {
        api_fail('Forbidden', 403);
    }

    $messages = social_fetch_messages($db, $uid, $convId, $beforeId, 60);
    social_mark_delivered_visible($db, $uid, $convId);

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'send_message') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    if (!social_user_in_conversation($db, $uid, $convId)) {
        api_fail('Forbidden', 403);
    }

    if ($content === '' && $attachmentId <= 0) {
        api_fail('Message content required');
    }

    $content = mb_substr($content, 0, 4000);

    if ($attachmentId > 0 && !social_attachment_owned_by($db, $attachmentId, $uid)) {
        api_fail('Invalid attachment', 403);
    }

    $db->begin_transaction();
    try {
        $msgType = $attachmentId > 0 ? 'attachment' : 'text';
        $stmt = $db->prepare('INSERT INTO social_messages (conversation_id, sender_user_id, content, message_type, attachment_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iissi', $convId, $uid, $content, $msgType, $attachmentId);
        $stmt->execute();
        $msgId = (int)$stmt->insert_id;
        $stmt->close();

        $recipientIds = social_recipient_user_ids($db, $convId, $uid);

        if ($recipientIds) {
            $stmt = $db->prepare('INSERT IGNORE INTO social_message_receipts (message_id, user_id, delivered_at, read_at) VALUES (?, ?, NULL, NULL)');
            foreach ($recipientIds as $rid) {
                $stmt->bind_param('ii', $msgId, $rid);
                $stmt->execute();
            }
            $stmt->close();
        }

        $stmt = $db->prepare('INSERT IGNORE INTO social_message_receipts (message_id, user_id, delivered_at, read_at) VALUES (?, ?, NOW(), NOW())');
        $stmt->bind_param('ii', $msgId, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('UPDATE social_conversations SET updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $convId);
        $stmt->execute();
        $stmt->close();

        $db->commit();

        $messages = social_fetch_messages($db, $uid, $convId, $msgId - 1, 1);
        $message = $messages[0] ?? null;

        echo json_encode(['success' => true, 'message' => $message, 'message_id' => $msgId]);
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        api_fail('Failed to send message', 500);
    }
}

if ($action === 'upload_attachment') {
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        api_fail('No file uploaded');
    }

    $file = $_FILES['file'];
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        api_fail('Attachment must be between 1 byte and 10MB');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'application/zip',
        'application/x-zip-compressed',
        'application/json',
    ];
    if (!in_array($mime, $allowed, true)) {
        api_fail('Unsupported file type');
    }

    $origName = (string)($file['name'] ?? 'attachment');
    $origName = mb_substr($origName, 0, 180);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if ($ext === '') {
        $ext = 'bin';
    }

    $relDir = 'storage/social_uploads/' . date('Y/m');
    $absDir = dirname(__DIR__) . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
        api_fail('Failed to create upload directory', 500);
    }

    $diskName = bin2hex(random_bytes(12)) . '.' . $ext;
    $absPath = $absDir . '/' . $diskName;
    $relPath = $relDir . '/' . $diskName;

    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        api_fail('Failed to store upload', 500);
    }

    $stmt = $db->prepare('INSERT INTO social_attachments (uploader_user_id, storage_key, disk_path, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
    $storageKey = bin2hex(random_bytes(16));
    $stmt->bind_param('issssi', $uid, $storageKey, $relPath, $origName, $mime, $size);
    if (!$stmt->execute()) {
        @unlink($absPath);
        api_fail('Failed to persist upload', 500);
    }
    $attachmentId = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'attachment' => [
            'id' => $attachmentId,
            'name' => $origName,
            'size_bytes' => $size,
            'mime_type' => $mime,
            'url' => '/api/social_attachment.php?id=' . $attachmentId,
        ],
    ]);
    exit;
}

if ($action === 'set_typing') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $isTyping = (int)($_POST['is_typing'] ?? 0) === 1;
    if (!social_user_in_conversation($db, $uid, $convId)) {
        api_fail('Forbidden', 403);
    }

    if ($isTyping) {
        $stmt = $db->prepare('INSERT INTO social_typing (conversation_id, user_id, expires_at, updated_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 6 SECOND), NOW()) ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), updated_at = NOW()');
        $stmt->bind_param('ii', $convId, $uid);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare('DELETE FROM social_typing WHERE conversation_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $convId, $uid);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'heartbeat') {
    $status = strtolower(trim((string)($_POST['status'] ?? 'online')));
    if (!in_array($status, ['online', 'away', 'offline'], true)) {
        $status = 'online';
    }
    social_presence_heartbeat($db, $uid, $status);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_read') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $messageId = (int)($_POST['message_id'] ?? 0);

    if (!social_user_in_conversation($db, $uid, $convId)) {
        api_fail('Forbidden', 403);
    }

    $stmt = $db->prepare('UPDATE social_message_receipts r INNER JOIN social_messages m ON m.id = r.message_id SET r.read_at = IFNULL(r.read_at, NOW()), r.delivered_at = IFNULL(r.delivered_at, NOW()) WHERE r.user_id = ? AND m.conversation_id = ? AND r.message_id <= ?');
    $stmt->bind_param('iii', $uid, $convId, $messageId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'voice_join') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    social_assert_voice_channel_access($db, $uid, $channelId);

    $stmt = $db->prepare('INSERT INTO social_voice_participants (channel_id, user_id, last_seen_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()');
    $stmt->bind_param('ii', $channelId, $uid);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'members' => social_voice_members($db, $channelId),
    ]);
    exit;
}

if ($action === 'voice_leave') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    if ($channelId > 0) {
        $stmt = $db->prepare('DELETE FROM social_voice_participants WHERE channel_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $channelId, $uid);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'voice_ping') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    social_assert_voice_channel_access($db, $uid, $channelId);

    $stmt = $db->prepare('INSERT INTO social_voice_participants (channel_id, user_id, last_seen_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()');
    $stmt->bind_param('ii', $channelId, $uid);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'members' => social_voice_members($db, $channelId),
    ]);
    exit;
}

if ($action === 'voice_signal_send') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    $toUserId = (int)($_POST['to_user_id'] ?? 0);
    $signalType = trim((string)($_POST['signal_type'] ?? 'signal'));
    $payloadRaw = (string)($_POST['payload'] ?? '{}');

    social_assert_voice_channel_access($db, $uid, $channelId);

    if ($signalType === '') {
        $signalType = 'signal';
    }
    if (strlen($payloadRaw) > 25000) {
        api_fail('Signal payload too large');
    }

    $tmp = json_decode($payloadRaw, true);
    if ($tmp === null && $payloadRaw !== 'null') {
        api_fail('Signal payload must be JSON');
    }

    if ($toUserId <= 0) {
        $toUserId = null;
    }

    $stmt = $db->prepare('INSERT INTO social_voice_signals (channel_id, from_user_id, to_user_id, signal_type, payload_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('iiiss', $channelId, $uid, $toUserId, $signalType, $payloadRaw);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'signal_id' => $id]);
    exit;
}

if ($action === 'voice_signal_poll') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    $lastSignalId = (int)($_POST['last_signal_id'] ?? 0);

    social_assert_voice_channel_access($db, $uid, $channelId);

    $stmt = $db->prepare('SELECT s.id, s.channel_id, s.from_user_id, u.username AS from_username, s.to_user_id, s.signal_type, s.payload_json, s.created_at FROM social_voice_signals s INNER JOIN users u ON u.id = s.from_user_id WHERE s.channel_id = ? AND s.id > ? AND (s.to_user_id IS NULL OR s.to_user_id = ? OR s.from_user_id = ?) ORDER BY s.id ASC LIMIT 250');
    $stmt->bind_param('iiii', $channelId, $lastSignalId, $uid, $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $signals = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['from_user_id'] = (int)$row['from_user_id'];
        $row['to_user_id'] = $row['to_user_id'] !== null ? (int)$row['to_user_id'] : null;
        $row['payload'] = json_decode((string)$row['payload_json'], true);
        unset($row['payload_json']);
        $signals[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'signals' => $signals,
        'members' => social_voice_members($db, $channelId),
    ]);
    exit;
}

if ($action === 'poll') {
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $lastMessageId = (int)($_POST['last_message_id'] ?? 0);
    $includeStructure = (int)($_POST['include_structure'] ?? 0) === 1;
    $presenceStatus = social_normalize_presence_status((string)($_POST['presence_status'] ?? 'online'));

    social_presence_heartbeat($db, $uid, $presenceStatus);

    $messages = [];
    $typing = [];
    $presence = [];

    if ($convId > 0 && social_user_in_conversation($db, $uid, $convId)) {
        $messages = social_fetch_messages_since($db, $uid, $convId, $lastMessageId, 120);
        social_mark_delivered_visible($db, $uid, $convId);
        $typing = social_typing_users($db, $convId, $uid);
        $presence = social_presence_for_conversation($db, $convId);
    }

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'typing' => $typing,
        'presence' => $presence,
        'my_presence_status' => $presenceStatus,
        'server_list' => $includeStructure ? social_get_servers_for_user($db, $uid) : null,
        'dms' => $includeStructure ? social_get_dm_conversations($db, $uid) : null,
        'groups' => $includeStructure ? social_get_group_conversations($db, $uid) : null,
    ]);
    exit;
}

api_fail('Unknown action', 404);

function action_bootstrap_env_if_missing(): void {
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            return $needle === '' || strpos($haystack, $needle) === 0;
        }
    }
}

function social_csv(string $raw): array {
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

function social_ensure_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS social_servers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        owner_user_id INT NOT NULL,
        icon_url VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_owner (owner_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_server_members (
        server_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
        last_seen_at DATETIME NULL,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (server_id, user_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        name VARCHAR(60) NOT NULL,
        color_hex VARCHAR(7) DEFAULT '#64748b',
        permissions_json TEXT DEFAULT NULL,
        position INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_server (server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_member_roles (
        server_id INT NOT NULL,
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (server_id, user_id, role_id),
        KEY idx_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_channels (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        type ENUM('text','voice') NOT NULL DEFAULT 'text',
        topic VARCHAR(255) DEFAULT NULL,
        position INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_server (server_id, type, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_conversations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        type ENUM('dm','group','channel') NOT NULL,
        server_id INT DEFAULT NULL,
        channel_id INT DEFAULT NULL,
        title VARCHAR(120) DEFAULT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_type_updated (type, updated_at),
        KEY idx_server_channel (server_id, channel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_channel_conversations (
        channel_id INT NOT NULL,
        conversation_id INT NOT NULL,
        PRIMARY KEY (channel_id),
        UNIQUE KEY uq_conv (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_conversation_members (
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        last_seen_at DATETIME NULL,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (conversation_id, user_id),
        KEY idx_user (user_id, conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_attachments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uploader_user_id INT NOT NULL,
        storage_key VARCHAR(64) NOT NULL,
        disk_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(180) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_key (storage_key),
        KEY idx_uploader (uploader_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        sender_user_id INT NOT NULL,
        content MEDIUMTEXT,
        message_type ENUM('text','system','attachment') NOT NULL DEFAULT 'text',
        attachment_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_conv_id (conversation_id, id),
        KEY idx_sender (sender_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_message_receipts (
        message_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        delivered_at DATETIME NULL,
        read_at DATETIME NULL,
        PRIMARY KEY (message_id, user_id),
        KEY idx_user_read (user_id, read_at),
        KEY idx_user_delivered (user_id, delivered_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_typing (
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        expires_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (conversation_id, user_id),
        KEY idx_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_presence (
        user_id INT NOT NULL,
        status ENUM('online','away','offline') NOT NULL DEFAULT 'online',
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id),
        KEY idx_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_voice_participants (
        channel_id INT NOT NULL,
        user_id INT NOT NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (channel_id, user_id),
        KEY idx_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS social_voice_signals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel_id INT NOT NULL,
        from_user_id INT NOT NULL,
        to_user_id INT NULL,
        signal_type VARCHAR(30) NOT NULL DEFAULT 'signal',
        payload_json MEDIUMTEXT NOT NULL,
        delivered_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_channel_id (channel_id, id),
        KEY idx_target (to_user_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function social_ensure_default_server(mysqli $db, int $uid): array {
    $serverName = 'Lyralink Community';

    $stmt = $db->prepare('SELECT id FROM social_servers WHERE name = ? ORDER BY id ASC LIMIT 1');
    $stmt->bind_param('s', $serverName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $serverId = (int)$row['id'];
    } else {
        $stmt = $db->prepare('INSERT INTO social_servers (name, owner_user_id) VALUES (?, ?)');
        $stmt->bind_param('si', $serverName, $uid);
        $stmt->execute();
        $serverId = (int)$stmt->insert_id;
        $stmt->close();

        social_create_channel($db, $serverId, 'general', 'text', $uid, 1);
        social_create_channel($db, $serverId, 'voice-lounge', 'voice', $uid, 2);
    }

    $stmt = $db->prepare("INSERT IGNORE INTO social_server_members (server_id, user_id, role, last_seen_at) VALUES (?, ?, 'member', NOW())");
    $stmt->bind_param('ii', $serverId, $uid);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("SELECT c.id AS channel_id, scc.conversation_id FROM social_channels c LEFT JOIN social_channel_conversations scc ON scc.channel_id = c.id WHERE c.server_id = ? AND c.type = 'text' ORDER BY c.position ASC, c.id ASC LIMIT 1");
    $stmt->bind_param('i', $serverId);
    $stmt->execute();
    $first = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'server_id' => $serverId,
        'text_channel_id' => (int)($first['channel_id'] ?? 0),
        'text_conversation_id' => (int)($first['conversation_id'] ?? 0),
    ];
}

function social_create_channel(mysqli $db, int $serverId, string $name, string $type, int $createdBy, int $position): array {
    $stmt = $db->prepare('INSERT INTO social_channels (server_id, name, type, position, created_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issii', $serverId, $name, $type, $position, $createdBy);
    $stmt->execute();
    $channelId = (int)$stmt->insert_id;
    $stmt->close();

    $conversationId = null;
    if ($type === 'text') {
        $title = $name;
        $stmt = $db->prepare("INSERT INTO social_conversations (type, server_id, channel_id, title, created_by, updated_at) VALUES ('channel', ?, ?, ?, ?, NOW())");
        $stmt->bind_param('iisi', $serverId, $channelId, $title, $createdBy);
        $stmt->execute();
        $conversationId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO social_channel_conversations (channel_id, conversation_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $channelId, $conversationId);
        $stmt->execute();
        $stmt->close();
    }

    return [
        'id' => $channelId,
        'server_id' => $serverId,
        'name' => $name,
        'type' => $type,
        'conversation_id' => $conversationId,
    ];
}

function social_user_can_manage_server(mysqli $db, int $uid, int $serverId): bool {
    $stmt = $db->prepare('SELECT role FROM social_server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $serverId, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }
    return in_array((string)$row['role'], ['owner', 'admin'], true);
}

function social_get_servers_for_user(mysqli $db, int $uid): array {
    $stmt = $db->prepare('SELECT s.id, s.name, s.owner_user_id FROM social_servers s INNER JOIN social_server_members m ON m.server_id = s.id WHERE m.user_id = ? ORDER BY s.id ASC');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    $servers = [];
    while ($row = $res->fetch_assoc()) {
        $serverId = (int)$row['id'];
        $channels = [];

        $cStmt = $db->prepare('SELECT c.id, c.name, c.type, c.position, scc.conversation_id FROM social_channels c LEFT JOIN social_channel_conversations scc ON scc.channel_id = c.id WHERE c.server_id = ? ORDER BY c.type ASC, c.position ASC, c.id ASC');
        $cStmt->bind_param('i', $serverId);
        $cStmt->execute();
        $cres = $cStmt->get_result();

        while ($ch = $cres->fetch_assoc()) {
            $channels[] = [
                'id' => (int)$ch['id'],
                'name' => $ch['name'],
                'type' => $ch['type'],
                'position' => (int)$ch['position'],
                'conversation_id' => $ch['conversation_id'] !== null ? (int)$ch['conversation_id'] : null,
            ];
        }
        $cStmt->close();

        $servers[] = [
            'id' => $serverId,
            'name' => $row['name'],
            'owner_user_id' => (int)$row['owner_user_id'],
            'channels' => $channels,
        ];
    }

    $stmt->close();
    return $servers;
}

function social_get_dm_conversations(mysqli $db, int $uid): array {
    $stmt = $db->prepare("SELECT c.id, c.title, c.updated_at FROM social_conversations c INNER JOIN social_conversation_members m ON m.conversation_id = c.id WHERE m.user_id = ? AND c.type = 'dm' ORDER BY c.updated_at DESC LIMIT 100");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $convId = (int)$row['id'];
        $peerStmt = $db->prepare('SELECT u.id, u.username FROM social_conversation_members m INNER JOIN users u ON u.id = m.user_id WHERE m.conversation_id = ? AND m.user_id != ? LIMIT 1');
        $peerStmt->bind_param('ii', $convId, $uid);
        $peerStmt->execute();
        $peer = $peerStmt->get_result()->fetch_assoc();
        $peerStmt->close();

        $items[] = [
            'id' => $convId,
            'title' => $peer['username'] ?? ($row['title'] ?: 'Direct Message'),
            'peer_user_id' => isset($peer['id']) ? (int)$peer['id'] : null,
            'updated_at' => $row['updated_at'],
        ];
    }

    $stmt->close();
    return $items;
}

function social_get_group_conversations(mysqli $db, int $uid): array {
    $stmt = $db->prepare("SELECT c.id, c.title, c.updated_at, (SELECT COUNT(*) FROM social_conversation_members gm WHERE gm.conversation_id = c.id) AS member_count FROM social_conversations c INNER JOIN social_conversation_members m ON m.conversation_id = c.id WHERE m.user_id = ? AND c.type = 'group' ORDER BY c.updated_at DESC LIMIT 100");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'] ?: 'Group Chat',
            'member_count' => (int)$row['member_count'],
            'updated_at' => $row['updated_at'],
        ];
    }

    $stmt->close();
    return $items;
}

function social_find_dm_conversation(mysqli $db, int $u1, int $u2): int {
    $stmt = $db->prepare("SELECT c.id FROM social_conversations c INNER JOIN social_conversation_members m ON m.conversation_id = c.id WHERE c.type = 'dm' AND m.user_id IN (?, ?) GROUP BY c.id HAVING COUNT(*) = 2 ORDER BY c.id DESC LIMIT 1");
    $stmt->bind_param('ii', $u1, $u2);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : 0;
}

function social_get_conversation(mysqli $db, int $convId): ?array {
    if ($convId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM social_conversations WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $convId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function social_user_in_conversation(mysqli $db, int $uid, int $convId): bool {
    $conv = social_get_conversation($db, $convId);
    if (!$conv) {
        return false;
    }

    if (($conv['type'] ?? '') === 'channel') {
        $serverId = (int)($conv['server_id'] ?? 0);
        $stmt = $db->prepare('SELECT 1 FROM social_server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $serverId, $uid);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    }

    $stmt = $db->prepare('SELECT 1 FROM social_conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $convId, $uid);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function social_recipient_user_ids(mysqli $db, int $convId, int $excludeUid): array {
    $conv = social_get_conversation($db, $convId);
    if (!$conv) {
        return [];
    }

    $ids = [];
    if (($conv['type'] ?? '') === 'channel') {
        $serverId = (int)($conv['server_id'] ?? 0);
        $stmt = $db->prepare('SELECT user_id FROM social_server_members WHERE server_id = ?');
        $stmt->bind_param('i', $serverId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['user_id'];
            if ($id !== $excludeUid) {
                $ids[] = $id;
            }
        }
        $stmt->close();
        return array_values(array_unique($ids));
    }

    $stmt = $db->prepare('SELECT user_id FROM social_conversation_members WHERE conversation_id = ?');
    $stmt->bind_param('i', $convId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['user_id'];
        if ($id !== $excludeUid) {
            $ids[] = $id;
        }
    }
    $stmt->close();

    return array_values(array_unique($ids));
}

function social_attachment_owned_by(mysqli $db, int $attachmentId, int $uid): bool {
    $stmt = $db->prepare('SELECT 1 FROM social_attachments WHERE id = ? AND uploader_user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $attachmentId, $uid);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function social_fetch_messages(mysqli $db, int $viewerUid, int $convId, int $beforeId, int $limit): array {
    $limit = max(1, min($limit, 200));
    $whereBefore = $beforeId > 0 ? ' AND m.id < ? ' : '';

    if ($beforeId > 0) {
        $stmt = $db->prepare("SELECT m.id, m.conversation_id, m.sender_user_id, u.username AS sender_username, m.content, m.message_type, m.attachment_id, m.created_at, a.original_name, a.mime_type, a.size_bytes,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.user_id != m.sender_user_id) AS recipient_count,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.delivered_at IS NOT NULL AND r.user_id != m.sender_user_id) AS delivered_count,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.read_at IS NOT NULL AND r.user_id != m.sender_user_id) AS read_count
            FROM social_messages m
            INNER JOIN users u ON u.id = m.sender_user_id
            LEFT JOIN social_attachments a ON a.id = m.attachment_id
            WHERE m.conversation_id = ? {$whereBefore}
            ORDER BY m.id DESC
            LIMIT {$limit}");
        $stmt->bind_param('ii', $convId, $beforeId);
    } else {
        $stmt = $db->prepare("SELECT m.id, m.conversation_id, m.sender_user_id, u.username AS sender_username, m.content, m.message_type, m.attachment_id, m.created_at, a.original_name, a.mime_type, a.size_bytes,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.user_id != m.sender_user_id) AS recipient_count,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.delivered_at IS NOT NULL AND r.user_id != m.sender_user_id) AS delivered_count,
            (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.read_at IS NOT NULL AND r.user_id != m.sender_user_id) AS read_count
            FROM social_messages m
            INNER JOIN users u ON u.id = m.sender_user_id
            LEFT JOIN social_attachments a ON a.id = m.attachment_id
            WHERE m.conversation_id = ?
            ORDER BY m.id DESC
            LIMIT {$limit}");
        $stmt->bind_param('i', $convId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = social_normalize_message_row($row, $viewerUid);
    }
    $stmt->close();

    return array_reverse($rows);
}

function social_fetch_messages_since(mysqli $db, int $viewerUid, int $convId, int $lastMessageId, int $limit): array {
    $limit = max(1, min($limit, 300));

    $stmt = $db->prepare("SELECT m.id, m.conversation_id, m.sender_user_id, u.username AS sender_username, m.content, m.message_type, m.attachment_id, m.created_at, a.original_name, a.mime_type, a.size_bytes,
        (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.user_id != m.sender_user_id) AS recipient_count,
        (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.delivered_at IS NOT NULL AND r.user_id != m.sender_user_id) AS delivered_count,
        (SELECT COUNT(*) FROM social_message_receipts r WHERE r.message_id = m.id AND r.read_at IS NOT NULL AND r.user_id != m.sender_user_id) AS read_count
        FROM social_messages m
        INNER JOIN users u ON u.id = m.sender_user_id
        LEFT JOIN social_attachments a ON a.id = m.attachment_id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT {$limit}");
    $stmt->bind_param('ii', $convId, $lastMessageId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = social_normalize_message_row($row, $viewerUid);
    }
    $stmt->close();

    return $rows;
}

function social_normalize_message_row(array $row, int $viewerUid): array {
    $attachment = null;
    if (!empty($row['attachment_id'])) {
        $attachment = [
            'id' => (int)$row['attachment_id'],
            'name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'url' => '/api/social_attachment.php?id=' . (int)$row['attachment_id'],
        ];
    }

    return [
        'id' => (int)$row['id'],
        'conversation_id' => (int)$row['conversation_id'],
        'sender_user_id' => (int)$row['sender_user_id'],
        'sender_username' => $row['sender_username'],
        'content' => $row['content'] ?? '',
        'message_type' => $row['message_type'] ?? 'text',
        'created_at' => $row['created_at'],
        'attachment' => $attachment,
        'is_mine' => (int)$row['sender_user_id'] === $viewerUid,
        'delivery' => [
            'recipient_count' => (int)($row['recipient_count'] ?? 0),
            'delivered_count' => (int)($row['delivered_count'] ?? 0),
            'read_count' => (int)($row['read_count'] ?? 0),
        ],
    ];
}

function social_mark_delivered_visible(mysqli $db, int $uid, int $convId): void {
    $stmt = $db->prepare('UPDATE social_message_receipts r INNER JOIN social_messages m ON m.id = r.message_id SET r.delivered_at = IFNULL(r.delivered_at, NOW()) WHERE r.user_id = ? AND m.conversation_id = ?');
    $stmt->bind_param('ii', $uid, $convId);
    $stmt->execute();
    $stmt->close();
}

function social_typing_users(mysqli $db, int $convId, int $excludeUid): array {
    $stmt = $db->prepare('SELECT t.user_id, u.username FROM social_typing t INNER JOIN users u ON u.id = t.user_id WHERE t.conversation_id = ? AND t.user_id != ? AND t.expires_at > NOW() ORDER BY t.updated_at DESC LIMIT 20');
    $stmt->bind_param('ii', $convId, $excludeUid);
    $stmt->execute();
    $res = $stmt->get_result();

    $users = [];
    while ($row = $res->fetch_assoc()) {
        $users[] = [
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
        ];
    }

    $stmt->close();
    return $users;
}

function social_presence_heartbeat(mysqli $db, int $uid, string $status): void {
    $stmt = $db->prepare('INSERT INTO social_presence (user_id, status, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), last_seen = NOW()');
    $stmt->bind_param('is', $uid, $status);
    $stmt->execute();
    $stmt->close();
}

function social_normalize_presence_status(string $status): string {
    $status = strtolower(trim($status));
    if (!in_array($status, ['online', 'away', 'offline'], true)) {
        return 'online';
    }
    return $status;
}

function social_presence_for_conversation(mysqli $db, int $convId): array {
    $conv = social_get_conversation($db, $convId);
    if (!$conv) {
        return [];
    }

    $ids = [];
    if (($conv['type'] ?? '') === 'channel') {
        $serverId = (int)$conv['server_id'];
        $stmt = $db->prepare('SELECT user_id FROM social_server_members WHERE server_id = ?');
        $stmt->bind_param('i', $serverId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['user_id'];
        }
        $stmt->close();
    } else {
        $stmt = $db->prepare('SELECT user_id FROM social_conversation_members WHERE conversation_id = ?');
        $stmt->bind_param('i', $convId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['user_id'];
        }
        $stmt->close();
    }

    $ids = array_values(array_unique(array_filter($ids, fn($x) => $x > 0)));
    if (!$ids) {
        return [];
    }

    $in = implode(',', array_map('intval', $ids));
    $sql = "SELECT p.user_id, p.status, p.last_seen, u.username FROM social_presence p INNER JOIN users u ON u.id = p.user_id WHERE p.user_id IN ({$in})";
    $res = $db->query($sql);

    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $seenTs = strtotime((string)$row['last_seen']);
        $rawStatus = strtolower((string)($row['status'] ?? 'offline'));
        $isFresh = $seenTs && $seenTs > (time() - 120);
        if (!$isFresh) {
            $effectiveStatus = 'offline';
        } else {
            $effectiveStatus = in_array($rawStatus, ['online', 'away', 'offline'], true) ? $rawStatus : 'online';
        }
        $out[] = [
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'status' => $effectiveStatus,
            'last_seen' => $row['last_seen'],
        ];
    }

    return $out;
}

function social_assert_voice_channel_access(mysqli $db, int $uid, int $channelId): void {
    if ($channelId <= 0) {
        api_fail('Invalid voice channel');
    }

    $stmt = $db->prepare("SELECT c.id FROM social_channels c INNER JOIN social_server_members m ON m.server_id = c.server_id WHERE c.id = ? AND c.type = 'voice' AND m.user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $channelId, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        api_fail('Voice channel access denied', 403);
    }
}

function social_voice_members(mysqli $db, int $channelId): array {
    $stmt = $db->prepare('SELECT p.user_id, u.username, p.last_seen_at FROM social_voice_participants p INNER JOIN users u ON u.id = p.user_id WHERE p.channel_id = ? AND p.last_seen_at > DATE_SUB(NOW(), INTERVAL 20 SECOND) ORDER BY u.username ASC');
    $stmt->bind_param('i', $channelId);
    $stmt->execute();
    $res = $stmt->get_result();

    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = [
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'last_seen_at' => $row['last_seen_at'],
        ];
    }
    $stmt->close();

    return $members;
}
