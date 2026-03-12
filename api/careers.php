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
if ($db->connect_error) { echo json_encode(['success'=>false,'error'=>'DB error']); exit; }
$db->set_charset('utf8mb4');

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'apply',
    'save_job',
    'delete_job',
    'update_application',
]);

function requireAdmin($db) {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $id = (int)$_SESSION['agent_id'];
    $stmt = $db->prepare("SELECT role FROM support_agents WHERE id = ? AND active = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $a = $r ? $r->fetch_assoc() : null;
    if (!$a || !in_array($a['role'], ['admin','senior_agent'])) { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
    return $a;
}

// ── PUBLIC: LIST JOBS ──
if ($action === 'list_jobs') {
    $dept = trim($_GET['dept'] ?? '');
    $type = trim($_GET['type'] ?? '');
    if ($dept !== '' && $type !== '') {
        $stmt = $db->prepare("SELECT id, title, department, location, type, salary_min, salary_max, salary_currency, created_at
            FROM careers_jobs WHERE active = 1 AND department = ? AND type = ? ORDER BY sort_order ASC, created_at DESC");
        $stmt->bind_param('ss', $dept, $type);
    } elseif ($dept !== '') {
        $stmt = $db->prepare("SELECT id, title, department, location, type, salary_min, salary_max, salary_currency, created_at
            FROM careers_jobs WHERE active = 1 AND department = ? ORDER BY sort_order ASC, created_at DESC");
        $stmt->bind_param('s', $dept);
    } elseif ($type !== '') {
        $stmt = $db->prepare("SELECT id, title, department, location, type, salary_min, salary_max, salary_currency, created_at
            FROM careers_jobs WHERE active = 1 AND type = ? ORDER BY sort_order ASC, created_at DESC");
        $stmt->bind_param('s', $type);
    } else {
        $stmt = $db->prepare("SELECT id, title, department, location, type, salary_min, salary_max, salary_currency, created_at
            FROM careers_jobs WHERE active = 1 ORDER BY sort_order ASC, created_at DESC");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($r = $result->fetch_assoc()) $jobs[] = $r;
    $stmt->close();
    // Departments for filter
    $depts = [];
    $dr = $db->prepare("SELECT DISTINCT department FROM careers_jobs WHERE active = 1 ORDER BY department");
    $dr->execute();
    $dr = $dr->get_result();
    while ($r = $dr->fetch_assoc()) $depts[] = $r['department'];
    echo json_encode(['success'=>true,'jobs'=>$jobs,'departments'=>$depts]);
    exit;
}

// ── PUBLIC: GET SINGLE JOB ──
if ($action === 'get_job') {
    $id  = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM careers_jobs WHERE id = ? AND active = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Job not found']); exit; }
    echo json_encode(['success'=>true,'job'=>$row]);
    exit;
}

// ── PUBLIC: APPLY ──
if ($action === 'apply') {
    $jobId      = (int)($_POST['job_id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $emailRaw   = trim($_POST['email'] ?? '');
    $email      = str_replace('\\', '', $emailRaw);
    $phone      = trim($_POST['phone'] ?? '');
    $location   = trim($_POST['location'] ?? '');
    $linkedin   = trim($_POST['linkedin'] ?? '');
    $portfolio  = trim($_POST['portfolio'] ?? '');
    $coverRaw   = trim($_POST['cover_letter'] ?? '');
    $cover      = str_replace('\\', '', $coverRaw);
    $experience = trim($_POST['experience'] ?? '');

    if (!$jobId || !$name || !$email || !$cover) {
        echo json_encode(['success'=>false,'error'=>'Name, email, and cover letter are required.']); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success'=>false,'error'=>'Invalid email address.']); exit;
    }
    if (strlen($cover) < 50) {
        echo json_encode(['success'=>false,'error'=>'Cover letter must be at least 50 characters.']); exit;
    }

    // Check job exists
    $stmt = $db->prepare("SELECT id, title FROM careers_jobs WHERE id = ? AND active = 1");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    if (!$job) { echo json_encode(['success'=>false,'error'=>'This position is no longer available.']); exit; }

    // Check duplicate (same email + job within 30 days)
    $stmt = $db->prepare("SELECT id FROM careers_applications WHERE job_id = ? AND email = ? AND applied_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->bind_param('is', $jobId, $email);
    $stmt->execute();
    $dupe = $stmt->get_result()->fetch_assoc();
    if ($dupe) { echo json_encode(['success'=>false,'error'=>'You have already applied for this position recently.']); exit; }

    // Handle resume upload
    $resumeName = null; $resumeData = null;
    if (!empty($_FILES['resume']['tmp_name'])) {
        $allowed = ['pdf','doc','docx'];
        $ext     = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) { echo json_encode(['success'=>false,'error'=>'Resume must be PDF, DOC, or DOCX.']); exit; }
        if ($_FILES['resume']['size'] > 5 * 1024 * 1024) { echo json_encode(['success'=>false,'error'=>'Resume must be under 5MB.']); exit; }
        $resumeName = basename($_FILES['resume']['name']);
        $resumeData = file_get_contents($_FILES['resume']['tmp_name']);
    }

    $stmt = $db->prepare("INSERT INTO careers_applications
        (job_id, name, email, phone, location, linkedin, portfolio, cover_letter, experience, resume_name, resume_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issssssssss', $jobId, $name, $email, $phone, $location, $linkedin, $portfolio, $cover, $experience, $resumeName, $resumeData);
    $stmt->execute();

    $appId = $db->insert_id;
    echo json_encode(['success'=>true,'application_id'=>$appId,'job_title'=>$job['title']]);
    exit;
}

// ── ADMIN: LIST ALL JOBS (including inactive) ──
if ($action === 'admin_list_jobs') {
    requireAdmin($db);
    $stmt = $db->prepare("SELECT j.*, (SELECT COUNT(*) FROM careers_applications a WHERE a.job_id = j.id) as app_count
        FROM careers_jobs j ORDER BY sort_order ASC, created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $jobs = [];
    while ($r = $result->fetch_assoc()) $jobs[] = $r;
    $stmt->close();
    echo json_encode(['success'=>true,'jobs'=>$jobs]);
    exit;
}

// ── ADMIN: CREATE/UPDATE JOB ──
if ($action === 'save_job') {
    requireAdmin($db);
    $id          = (int)($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $department  = trim($_POST['department'] ?? 'General');
    $location    = trim($_POST['location'] ?? 'Remote');
    $type        = trim($_POST['type'] ?? 'full_time');
    $salMin      = isset($_POST['salary_min']) && $_POST['salary_min'] !== '' ? (int)$_POST['salary_min'] : null;
    $salMax      = isset($_POST['salary_max']) && $_POST['salary_max'] !== '' ? (int)$_POST['salary_max'] : null;
    $salCur      = trim($_POST['salary_currency'] ?? 'USD');
    $description = trim($_POST['description'] ?? '');
    $requirements= trim($_POST['requirements'] ?? '');
    $perks       = trim($_POST['perks'] ?? '');
    $active      = (int)($_POST['active'] ?? 1);
    $sort        = (int)($_POST['sort_order'] ?? 0);

    if (!$title || !$description || !$requirements) {
        echo json_encode(['success'=>false,'error'=>'Title, description, and requirements are required.']); exit;
    }

    if ($id) {
        $stmt = $db->prepare("UPDATE careers_jobs SET title = ?, department = ?, location = ?,
            type = ?, salary_min = ?, salary_max = ?, salary_currency = ?,
            description = ?, requirements = ?, perks = ?,
            active = ?, sort_order = ? WHERE id = ?");
        $stmt->bind_param('ssssiissssiii', $title, $department, $location, $type, $salMin, $salMax, $salCur, $description, $requirements, $perks, $active, $sort, $id);
        $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$id]);
    } else {
        $stmt = $db->prepare("INSERT INTO careers_jobs (title, department, location, type, salary_min, salary_max, salary_currency, description, requirements, perks, active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssiissssii', $title, $department, $location, $type, $salMin, $salMax, $salCur, $description, $requirements, $perks, $active, $sort);
        $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$db->insert_id]);
    }
    exit;
}

// ── ADMIN: DELETE JOB ──
if ($action === 'delete_job') {
    requireAdmin($db);
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE careers_jobs SET active = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success'=>true]);
    exit;
}

// ── ADMIN: LIST APPLICATIONS ──
if ($action === 'list_applications') {
    requireAdmin($db);
    $jobId  = (int)($_GET['job_id'] ?? 0);
    $status = trim($_GET['status'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT a.id, a.job_id, a.name, a.email, a.phone, a.location, a.experience,
        a.linkedin, a.portfolio, a.status, a.applied_at, a.resume_name, j.title as job_title
        FROM careers_applications a
        LEFT JOIN careers_jobs j ON j.id = a.job_id
        WHERE 1 = 1";
    $types = '';
    $params = [];
    if ($jobId > 0) {
        $sql .= " AND a.job_id = ?";
        $types .= 'i';
        $params[] = $jobId;
    }
    if ($status !== '') {
        $sql .= " AND a.status = ?";
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= " AND (a.name LIKE ? OR a.email LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY a.applied_at DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $apps = [];
    while ($r = $result->fetch_assoc()) $apps[] = $r;
    echo json_encode(['success'=>true,'applications'=>$apps]);
    exit;
}

// ── ADMIN: GET APPLICATION DETAIL ──
if ($action === 'get_application') {
    requireAdmin($db);
    $id  = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT a.*, j.title as job_title FROM careers_applications a
        LEFT JOIN careers_jobs j ON j.id = a.job_id WHERE a.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
    unset($row['resume_data']); // don't send binary in JSON
    echo json_encode(['success'=>true,'application'=>$row]);
    exit;
}

// ── ADMIN: UPDATE APPLICATION STATUS ──
if ($action === 'update_application') {
    requireAdmin($db);
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');
    $valid  = ['new','reviewing','interview','offer','rejected','withdrawn'];
    if (!in_array($status, $valid)) { echo json_encode(['success'=>false,'error'=>'Invalid status']); exit; }
    $stmt = $db->prepare("UPDATE careers_applications SET status = ?, notes = ? WHERE id = ?");
    $stmt->bind_param('ssi', $status, $notes, $id);
    $stmt->execute();
    echo json_encode(['success'=>true]);
    exit;
}

// ── ADMIN: DOWNLOAD RESUME ──
if ($action === 'download_resume') {
    requireAdmin($db);
    $id  = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT resume_name, resume_data FROM careers_applications WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !$row['resume_data']) { http_response_code(404); echo 'Not found'; exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $row['resume_name'] . '"');
    header('Content-Type: application/pdf');
    echo $row['resume_data'];
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);
?>