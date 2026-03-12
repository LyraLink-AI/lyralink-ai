<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();
header('Access-Control-Allow-Origin: *');

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

$action = api_action() ?: 'get_status';

api_enforce_post_and_origin_for_actions([
    'update_service',
    'create_incident',
    'update_incident',
]);

if ($action === 'get_status') {
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
    $stmt = $db->prepare("UPDATE status_services SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>true]);
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