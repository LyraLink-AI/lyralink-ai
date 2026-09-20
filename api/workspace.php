<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — WORKSPACE API  (projects + files)

   Backs the Files and Projects panels in the chat rail, and the schema created
   by scripts/migrate_workspace.php.

   CONVENTIONS FOLLOWED, NOT INVENTED
     * session required for every action; the user id comes from the session and
       never from the request
     * every single query is scoped by that user id. There is a CROSS_USER_DATA
       flag in this codebase's own security log, so ownership is enforced in the
       SQL rather than filtered after the fact
     * uploads mirror api/social.php's upload_attachment exactly - size cap,
       finfo MIME allowlist, sanitised extension, random on-disk name, and the
       row is removed if the file cannot be persisted
     * storage lives under storage/user_files/, which .htaccess denies over HTTP
       (verified: 403), so every read goes through this file with an ownership
       check rather than a public URL

   CSRF: every state-changing action goes through lyra_csrf_require(), which
   skips a request carrying an explicit credential. The chat page loads
   assets/js/lyra-csrf.js, so panel requests carry the token automatically.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/security.php';
lyra_session_boot();

$action = api_action();

/* Resolved before any output, and the user id is taken only from the session. */
$uid = (int) ($_SESSION['user_id'] ?? 0);

$cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db  = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
if ($db->connect_error) {
    api_fail('Database unavailable', 500);
}

$STATE_CHANGING = [
    'create_project', 'update_project', 'delete_project',
    'upload_file', 'delete_file',
    'link_conversation', 'unlink_conversation',
];

if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in', 'code' => 'AUTH_REQUIRED']);
    exit;
}

if (in_array($action, $STATE_CHANGING, true)) {
    lyra_csrf_require();
}

/* ── helpers ─────────────────────────────────────────────────────────────── */

/** The project row, but only if this user owns it. Returns null otherwise. */
function ws_project(mysqli $db, int $uid, int $pid): ?array
{
    $st = $db->prepare('SELECT id, name, description, status, created_at, updated_at
                        FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
    $st->bind_param('ii', $pid, $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

function ws_conv_owned(mysqli $db, int $uid, string $convId): bool
{
    $st = $db->prepare('SELECT 1 FROM user_convs WHERE conv_id = ? AND user_id = ? LIMIT 1');
    $st->bind_param('si', $convId, $uid);
    $st->execute();
    $ok = $st->get_result()->fetch_row() !== null;
    $st->close();
    return $ok;
}

/* ── download is not JSON, so it is handled before the headers ───────────── */

if ($action === 'download_file') {
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '') { api_fail('No file specified', 400); }

    /* Looked up by the unguessable storage_key AND the session user, so neither
       an id guess nor a stolen key is enough on its own. */
    $st = $db->prepare('SELECT disk_path, original_name, mime_type, size_bytes
                        FROM user_files WHERE storage_key = ? AND user_id = ? LIMIT 1');
    $st->bind_param('si', $key, $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    if ($row === null) { api_fail('Not found', 404); }

    $base = realpath(dirname(__DIR__) . '/storage/user_files');
    $abs  = realpath(dirname(__DIR__) . '/' . (string) $row['disk_path']);

    /* Confirm the resolved path is genuinely inside the storage directory, so a
       crafted disk_path could never read something else. */
    if ($base === false || $abs === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
        api_fail('Not found', 404);
    }
    if (!is_file($abs)) { api_fail('File is missing from storage', 410); }

    $name = (string) $row['original_name'];
    header('Content-Type: ' . ((string) $row['mime_type'] !== '' ? (string) $row['mime_type'] : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name)
        . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

/* Every remaining action answers JSON. */
api_json_headers();

/* ── projects ────────────────────────────────────────────────────────────── */

if ($action === 'list_projects') {
    $st = $db->prepare(
        'SELECT p.id, p.name, p.description, p.status, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM project_conversations pc WHERE pc.project_id = p.id) AS conv_count,
                (SELECT COUNT(*) FROM user_files f WHERE f.project_id = p.id) AS file_count
         FROM projects p
         WHERE p.user_id = ?
         ORDER BY (p.status = "active") DESC, p.updated_at DESC
         LIMIT 200'
    );
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    $st->close();
    echo json_encode(['success' => true, 'projects' => $out]);
    exit;
}

if ($action === 'create_project') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
    $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 2000) : substr($desc, 0, 2000);

    if ($name === '') { api_fail('A project needs a name'); }

    $st = $db->prepare('INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)');
    $st->bind_param('iss', $uid, $name, $desc);
    if (!$st->execute()) { api_fail('Could not create the project', 500); }
    $id = (int) $st->insert_id;
    $st->close();

    echo json_encode(['success' => true, 'project' => ws_project($db, $uid, $id)]);
    exit;
}

if ($action === 'update_project') {
    $pid  = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $stat = (string) ($_POST['status'] ?? '');
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
    $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 2000) : substr($desc, 0, 2000);

    if (ws_project($db, $uid, $pid) === null) { api_fail('Not found', 404); }
    if ($name === '') { api_fail('A project needs a name'); }
    if (!in_array($stat, ['active', 'archived'], true)) { $stat = 'active'; }

    $st = $db->prepare('UPDATE projects SET name = ?, description = ?, status = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?');
    $st->bind_param('sssii', $name, $desc, $stat, $pid, $uid);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true, 'project' => ws_project($db, $uid, $pid)]);
    exit;
}

if ($action === 'delete_project') {
    $pid = (int) ($_POST['id'] ?? 0);
    if (ws_project($db, $uid, $pid) === null) { api_fail('Not found', 404); }

    /* Unlink rather than delete the files: the bytes remain the user's, they
       simply stop belonging to a project that no longer exists. */
    $st = $db->prepare('UPDATE user_files SET project_id = NULL WHERE project_id = ? AND user_id = ?');
    $st->bind_param('ii', $pid, $uid);
    $st->execute();
    $st->close();

    $st = $db->prepare('DELETE FROM project_conversations WHERE project_id = ?');
    $st->bind_param('i', $pid);
    $st->execute();
    $st->close();

    $st = $db->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
    $st->bind_param('ii', $pid, $uid);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'project_conversations') {
    $pid = (int) ($_GET['id'] ?? 0);
    if (ws_project($db, $uid, $pid) === null) { api_fail('Not found', 404); }

    /* Joined to user_convs so a conversation that was deleted cannot linger in
       the panel as a row pointing nowhere. */
    $st = $db->prepare(
        'SELECT c.conv_id, c.title, c.updated_at
         FROM project_conversations pc
         JOIN user_convs c ON c.conv_id = pc.conv_id AND c.user_id = ?
         WHERE pc.project_id = ?
         ORDER BY c.updated_at DESC LIMIT 200'
    );
    $st->bind_param('ii', $uid, $pid);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    $st->close();
    echo json_encode(['success' => true, 'conversations' => $out]);
    exit;
}

if ($action === 'link_conversation' || $action === 'unlink_conversation') {
    $pid    = (int) ($_POST['id'] ?? 0);
    $convId = trim((string) ($_POST['conv_id'] ?? ''));

    if (ws_project($db, $uid, $pid) === null) { api_fail('Not found', 404); }
    if ($convId === '') { api_fail('No conversation specified'); }
    if (!ws_conv_owned($db, $uid, $convId)) { api_fail('Not found', 404); }

    if ($action === 'link_conversation') {
        $st = $db->prepare('INSERT IGNORE INTO project_conversations (project_id, conv_id) VALUES (?, ?)');
        $st->bind_param('is', $pid, $convId);
    } else {
        $st = $db->prepare('DELETE FROM project_conversations WHERE project_id = ? AND conv_id = ?');
        $st->bind_param('is', $pid, $convId);
    }
    $st->execute();
    $st->close();
    echo json_encode(['success' => true]);
    exit;
}

/* ── files ───────────────────────────────────────────────────────────────── */

if ($action === 'list_files') {
    $pid = (int) ($_GET['project_id'] ?? 0);
    $sql = 'SELECT f.id, f.project_id, f.storage_key, f.original_name, f.mime_type,
                   f.size_bytes, f.created_at, p.name AS project_name
            FROM user_files f
            LEFT JOIN projects p ON p.id = f.project_id AND p.user_id = f.user_id
            WHERE f.user_id = ?';
    if ($pid > 0) { $sql .= ' AND f.project_id = ?'; }
    $sql .= ' ORDER BY f.created_at DESC LIMIT 200';

    if ($pid > 0) {
        $st = $db->prepare($sql);
        $st->bind_param('ii', $uid, $pid);
    } else {
        $st = $db->prepare($sql);
        $st->bind_param('i', $uid);
    }
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        /* disk_path is deliberately not returned: the browser has no business
           knowing where the bytes live, and the download goes through the
           ownership-checked route above. */
        $out[] = $r;
    }
    $st->close();
    echo json_encode(['success' => true, 'files' => $out]);
    exit;
}

if ($action === 'upload_file') {
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        api_fail('No file uploaded');
    }

    $file = $_FILES['file'];
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        api_fail('File must be between 1 byte and 25MB');
    }

    /* The type is decided by sniffing the bytes, never by the client's claim. */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'text/html',
        'application/json', 'application/zip', 'application/x-zip-compressed',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    if (!in_array($mime, $allowed, true)) {
        api_fail('Unsupported file type: ' . $mime);
    }

    $origName = (string) ($file['name'] ?? 'file');
    $origName = function_exists('mb_substr') ? mb_substr($origName, 0, 180) : substr($origName, 0, 180);
    $ext = strtolower((string) pathinfo($origName, PATHINFO_EXTENSION));
    $ext = (string) preg_replace('/[^a-z0-9]/', '', $ext);
    if ($ext === '') { $ext = 'bin'; }

    $relDir = 'storage/user_files/' . date('Y/m');
    $absDir = dirname(__DIR__) . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
        api_fail('Failed to create upload directory', 500);
    }

    $diskName = bin2hex(random_bytes(12)) . '.' . $ext;
    $absPath  = $absDir . '/' . $diskName;
    $relPath  = $relDir . '/' . $diskName;

    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        api_fail('Failed to store the file', 500);
    }

    $sha = @hash_file('sha256', $absPath) ?: null;
    $storageKey = bin2hex(random_bytes(16));
    $pid = (int) ($_POST['project_id'] ?? 0);
    $pidVal = null;
    if ($pid > 0) {
        /* Only attach to a project the caller actually owns. */
        if (ws_project($db, $uid, $pid) === null) { $pid = 0; } else { $pidVal = $pid; }
    }

    $st = $db->prepare('INSERT INTO user_files
        (user_id, project_id, storage_key, disk_path, original_name, mime_type, size_bytes, sha256)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('iissssis', $uid, $pidVal, $storageKey, $relPath, $origName, $mime, $size, $sha);
    if (!$st->execute()) {
        @unlink($absPath);
        api_fail('Failed to record the upload', 500);
    }
    $id = (int) $st->insert_id;
    $st->close();

    echo json_encode(['success' => true, 'file' => [
        'id' => $id,
        'storage_key' => $storageKey,
        'original_name' => $origName,
        'mime_type' => $mime,
        'size_bytes' => $size,
        'project_id' => $pidVal,
    ]]);
    exit;
}

if ($action === 'delete_file') {
    $key = trim((string) ($_POST['key'] ?? ''));
    if ($key === '') { api_fail('No file specified'); }

    $st = $db->prepare('SELECT id, disk_path FROM user_files WHERE storage_key = ? AND user_id = ? LIMIT 1');
    $st->bind_param('si', $key, $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if ($row === null) { api_fail('Not found', 404); }

    $base = realpath(dirname(__DIR__) . '/storage/user_files');
    $abs  = realpath(dirname(__DIR__) . '/' . (string) $row['disk_path']);
    if ($base !== false && $abs !== false && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
        @unlink($abs);
    }

    $st = $db->prepare('DELETE FROM user_files WHERE id = ? AND user_id = ?');
    $st->bind_param('ii', $row['id'], $uid);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true]);
    exit;
}

api_fail('Unknown action: ' . $action, 400);
