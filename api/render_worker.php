<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/marketing_lib.php';

api_json_headers();

function render_worker_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $suffix = strtolower(substr($value, -1));
    $number = (float)$value;
    $multiplier = 1;
    if ($suffix === 'k') {
        $multiplier = 1024;
    } elseif ($suffix === 'm') {
        $multiplier = 1024 * 1024;
    } elseif ($suffix === 'g') {
        $multiplier = 1024 * 1024 * 1024;
    }

    return (int)round($number * $multiplier);
}

function render_worker_reject_oversized_post(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return;
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = render_worker_ini_bytes((string)ini_get('post_max_size'));
    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
        api_fail('Render worker upload exceeded PHP post_max_size. Increase post_max_size and upload_max_filesize.', 413);
    }
}

function render_worker_input(): array {
    $payload = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    foreach (['POST', 'REQUEST', 'GET'] as $source) {
        $container = $source === 'POST' ? $_POST : ($source === 'REQUEST' ? $_REQUEST : $_GET);
        foreach ($container as $k => $v) {
            if (!array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    if (($payload['action'] ?? '') === '' && !empty($_FILES) && is_array($_FILES)) {
        $payload['action'] = 'submit_result';
    }

    return $payload;
}

function render_worker_require_key(array $input): void {
    $expected = trim((string)api_get_secret('RENDER_WORKER_SHARED_KEY', ''));
    if ($expected === '') {
        api_fail('Render worker API misconfigured: missing RENDER_WORKER_SHARED_KEY', 500);
    }

    $provided = trim((string)(
        api_request_header('X-Render-Worker-Key')
        ?: ($input['render_key'] ?? '')
    ));

    if ($provided === '' || !hash_equals($expected, $provided)) {
        api_fail('Unauthorized render worker', 401);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_fail('Method not allowed', 405);
}

render_worker_reject_oversized_post();
$input = render_worker_input();
render_worker_require_key($input);
$action = trim((string)($input['action'] ?? 'claim_job'));

$dbCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('Database unavailable', 500);
}
$db->set_charset('utf8mb4');
marketing_ensure_schema($db);

if ($action === 'claim_job') {
    $workerName = trim((string)($input['worker_name'] ?? 'gpu-worker'));
    $job = marketing_claim_render_job($db, $workerName);
    echo json_encode([
        'success' => true,
        'job' => $job,
    ]);
    exit;
}

if ($action === 'job_status') {
    $jobId = trim((string)($input['job_id'] ?? ''));
    if ($jobId === '') {
        api_fail('Missing job_id', 400);
    }

    $row = marketing_get_render_job($db, $jobId);
    if (!$row) {
        api_fail('Unknown job_id', 404);
    }

    echo json_encode([
        'success' => true,
        'job' => [
            'job_id' => (string)$row['job_id'],
            'status' => (string)$row['status'],
            'worker_name' => (string)($row['worker_name'] ?? ''),
            'error_detail' => (string)($row['error_detail'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'claimed_at' => (string)($row['claimed_at'] ?? ''),
            'completed_at' => (string)($row['completed_at'] ?? ''),
        ],
    ]);
    exit;
}

if ($action === 'submit_result') {
    if ($method !== 'POST') {
        api_fail('Method not allowed', 405);
    }

    $jobId = trim((string)($input['job_id'] ?? ''));
    $status = trim((string)($input['status'] ?? 'completed'));
    $workerName = trim((string)($input['worker_name'] ?? 'gpu-worker'));

    if ($jobId === '') {
        api_fail('Missing job_id', 400);
    }

    if ($status === 'failed') {
        $detail = trim((string)($input['error_detail'] ?? 'Worker failed render job.'));
        $ok = marketing_fail_render_job($db, $jobId, $detail, $workerName);
        if (!$ok) {
            api_fail('Could not mark job failed', 500);
        }
        echo json_encode(['success' => true, 'status' => 'failed_recorded']);
        exit;
    }

    if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
        api_fail('Missing uploaded video file', 400);
    }

    $upload = $_FILES['video'];
    $tmpPath = (string)($upload['tmp_name'] ?? '');
    $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        api_fail('Render worker upload exceeded PHP upload_max_filesize.', 413);
    }

    if ($errorCode !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        api_fail('Video upload failed', 400);
    }

    $tempTarget = sys_get_temp_dir() . '/lyralink_worker_' . bin2hex(random_bytes(8)) . '.mp4';
    if (!move_uploaded_file($tmpPath, $tempTarget)) {
        api_fail('Could not persist uploaded video', 500);
    }

    error_log('[render_worker] submit_result job_id=' . $jobId . ' worker=' . $workerName . ' tmp=' . $tempTarget . ' size=' . (string)filesize($tempTarget));

    $stored = marketing_store_render_job_result($db, $jobId, $tempTarget, $workerName);
    error_log('[render_worker] submit_result stored=' . json_encode($stored));
    if (!$stored['ok']) {
        api_fail((string)($stored['detail'] ?? 'Could not finalize render job'), 500);
    }

    echo json_encode([
        'success' => true,
        'status' => 'completed_recorded',
        'result_path' => (string)($stored['result_path'] ?? ''),
    ]);
    exit;
}

api_fail('Unknown action', 400);
