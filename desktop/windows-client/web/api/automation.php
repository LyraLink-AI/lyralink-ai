<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/lib/network_policy.php';
session_start();
api_json_headers();

$dbCfg = api_db_config(['host'=>'localhost','user'=>'app_user','pass'=>'','name'=>'aicloud']);
$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) { api_fail('DB error', 500); }
$db->set_charset('utf8mb4');
saas_bootstrap_schema($db);

// ── SCHEMA ──────────────────────────────────────────────────────────────────
$db->query("CREATE TABLE IF NOT EXISTS automations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id      INT UNSIGNED NOT NULL DEFAULT 0,
    user_id     INT NOT NULL,
    created_by_user_id INT DEFAULT NULL,
    name        VARCHAR(200) NOT NULL,
    prompt      TEXT NOT NULL,
    schedule    VARCHAR(50) NOT NULL,
    interval_unit VARCHAR(20) NOT NULL DEFAULT 'day',
    interval_value SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    run_count   INT UNSIGNED NOT NULL DEFAULT 0,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NOT NULL,
    last_result MEDIUMTEXT NULL,
    last_status ENUM('ok','error','pending') DEFAULT 'pending',
    webhook_url VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_org_user (org_id, user_id, enabled),
    KEY idx_next_run (next_run_at, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS automation_runs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id         INT UNSIGNED NOT NULL DEFAULT 0,
    automation_id  INT UNSIGNED NOT NULL,
    user_id        INT NOT NULL,
    status         ENUM('ok','error') NOT NULL,
    result         MEDIUMTEXT NULL,
    error_message  VARCHAR(500) NULL,
    idempotency_key VARCHAR(120) DEFAULT NULL,
    tokens_used    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ran_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_org_automation (org_id, automation_id, ran_at),
    KEY idx_user (user_id, ran_at),
    KEY idx_org_idempotency (org_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("ALTER TABLE automations ADD COLUMN IF NOT EXISTS org_id INT UNSIGNED NOT NULL DEFAULT 0");
$db->query("ALTER TABLE automations ADD COLUMN IF NOT EXISTS created_by_user_id INT DEFAULT NULL");
$db->query("ALTER TABLE automation_runs ADD COLUMN IF NOT EXISTS org_id INT UNSIGNED NOT NULL DEFAULT 0");
$db->query("ALTER TABLE automation_runs ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(120) DEFAULT NULL");
$db->query("ALTER TABLE automation_runs ADD COLUMN IF NOT EXISTS acknowledged_at DATETIME NULL");
$db->query("ALTER TABLE automation_runs ADD COLUMN IF NOT EXISTS acknowledged_by_user_id INT NULL");

// ── AUTH ─────────────────────────────────────────────────────────────────────
$uid = 0;
$username = '';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $username = (string)($_SESSION['username'] ?? '');
} else {
    $mobileUser = api_try_mobile_token_auth($db);
    if ($mobileUser) {
        $uid = (int)$mobileUser['id'];
        $username = (string)($mobileUser['username'] ?? '');
    }
}
if (!$uid) { api_fail('Not logged in', 401); }

$ctx = saas_context($db, $uid, $username);
$orgId = (int)$ctx['org_id'];

// ── PLAN LIMITS ──────────────────────────────────────────────────────────────
$planRow = $db->query("SELECT plan FROM users WHERE id = $uid")->fetch_assoc();
$plan = saas_get_org_plan($db, $orgId, $planRow['plan'] ?? 'free');
$planLimits = ['free'=>2, 'basic'=>10, 'pro'=>50, 'enterprise'=>200];
$maxAutomations = $planLimits[$plan] ?? 2;

$action = api_action();
$automationLibraryMode = defined('LYRALINK_AUTOMATION_LIBRARY_MODE') && LYRALINK_AUTOMATION_LIBRARY_MODE === true;

api_enforce_post_and_origin_for_actions([
    'create', 'update', 'delete', 'toggle', 'run_now', 'operator_ack_run', 'operator_retry',
]);

// ── LIST ─────────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'list') {
    $rows = $db->query(
        "SELECT id, name, prompt, schedule, interval_unit, interval_value,
                enabled, run_count, last_run_at, next_run_at,
                last_status, webhook_url, created_at
         FROM automations WHERE org_id = $orgId AND user_id = $uid ORDER BY created_at DESC LIMIT 200"
    );
    $automations = [];
    while ($r = $rows->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $r['enabled'] = (bool)(int)$r['enabled'];
        $r['run_count'] = (int)$r['run_count'];
        $r['interval_value'] = (int)$r['interval_value'];
        $automations[] = $r;
    }
    echo json_encode(['success'=>true, 'automations'=>$automations, 'max'=>$maxAutomations, 'plan'=>$plan, 'org'=>$ctx]);
    exit;
}

// ── HISTORY ──────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'history') {
    $aid = (int)($_GET['automation_id'] ?? 0);
    if (!$aid) { api_fail('automation_id required'); }
    $stmt = $db->prepare(
        "SELECT r.id, r.status, LEFT(r.result,1000) AS result_preview, r.error_message, r.tokens_used, r.ran_at
         FROM automation_runs r
         INNER JOIN automations a ON a.id = r.automation_id
         WHERE r.automation_id = ? AND r.user_id = ? AND r.org_id = ? AND a.org_id = ?
         ORDER BY ran_at DESC LIMIT 20"
    );
    $stmt->bind_param('iiii', $aid, $uid, $orgId, $orgId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $runs = [];
    while ($r = $rows->fetch_assoc()) { $r['id'] = (int)$r['id']; $runs[] = $r; }
    $stmt->close();
    echo json_encode(['success'=>true, 'runs'=>$runs]);
    exit;
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'create') {
    saas_require_role($ctx, ['owner', 'admin', 'member']);

    // Count existing
    $count = (int)$db->query("SELECT COUNT(*) AS c FROM automations WHERE org_id = $orgId")->fetch_assoc()['c'];
    if ($count >= $maxAutomations) {
        api_fail("Your plan allows up to $maxAutomations automations. Upgrade to add more.");
    }

    $name           = trim((string)($_POST['name'] ?? ''));
    $prompt         = trim((string)($_POST['prompt'] ?? ''));
    $intervalValue  = max(1, (int)($_POST['interval_value'] ?? 1));
    $intervalUnit   = preg_replace('/[^a-z]/', '', strtolower($_POST['interval_unit'] ?? 'day'));
    $webhookUrl     = trim((string)($_POST['webhook_url'] ?? ''));
    $scheduleStr    = trim((string)($_POST['schedule'] ?? ''));

    if (!$name || !$prompt) { api_fail('Name and prompt are required'); }
    if (mb_strlen($name) > 200) { api_fail('Name too long'); }
    if (mb_strlen($prompt) > 4000) { api_fail('Prompt too long (max 4000 chars)'); }
    if (!in_array($intervalUnit, ['minute','hour','day','week','month'], true)) {
        $intervalUnit = 'day';
    }
    if ($webhookUrl !== '') {
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            api_fail('Invalid webhook URL');
        }
        $webhookCheck = netpolicy_validate_outbound_url($webhookUrl, false);
        if (!$webhookCheck['ok']) {
            api_fail('Webhook URL rejected: ' . $webhookCheck['error']);
        }
    }

    $nextRun = automation_next_run($intervalValue, $intervalUnit);
    $schedule = "$intervalValue $intervalUnit";

    $idemKey = trim((string)api_request_header('Idempotency-Key'));
    if ($idemKey !== '' && saas_is_idempotent_request($db, $orgId, 'automation.create', $idemKey, 900)) {
        api_fail('Duplicate create request', 409);
    }

    $stmt = $db->prepare(
        "INSERT INTO automations (org_id, user_id, created_by_user_id, name, prompt, schedule, interval_unit, interval_value, next_run_at, webhook_url)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $nullWebhook = $webhookUrl !== '' ? $webhookUrl : null;
    $stmt->bind_param('iiissssiss', $orgId, $uid, $uid, $name, $prompt, $schedule, $intervalUnit, $intervalValue, $nextRun, $nullWebhook);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    saas_record_audit($db, $orgId, $uid, 'automation.create', 'automation', (string)$id, ['name' => $name]);
    echo json_encode(['success'=>true, 'id'=>$id, 'next_run_at'=>$nextRun]);
    exit;
}

// ── UPDATE ───────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'update') {
    saas_require_role($ctx, ['owner', 'admin', 'member']);

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { api_fail('id required'); }

    $name          = trim((string)($_POST['name'] ?? ''));
    $prompt        = trim((string)($_POST['prompt'] ?? ''));
    $intervalValue = max(1, (int)($_POST['interval_value'] ?? 1));
    $intervalUnit  = preg_replace('/[^a-z]/', '', strtolower($_POST['interval_unit'] ?? 'day'));
    $webhookUrl    = trim((string)($_POST['webhook_url'] ?? ''));

    if (!$name || !$prompt) { api_fail('Name and prompt are required'); }
    if (!in_array($intervalUnit, ['minute','hour','day','week','month'], true)) { $intervalUnit = 'day'; }
    if ($webhookUrl !== '') {
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            api_fail('Invalid webhook URL');
        }
        $webhookCheck = netpolicy_validate_outbound_url($webhookUrl, false);
        if (!$webhookCheck['ok']) {
            api_fail('Webhook URL rejected: ' . $webhookCheck['error']);
        }
    }

    $nextRun = automation_next_run($intervalValue, $intervalUnit);
    $schedule = "$intervalValue $intervalUnit";
    $nullWebhook = $webhookUrl !== '' ? $webhookUrl : null;

    $stmt = $db->prepare(
        "UPDATE automations SET name=?, prompt=?, schedule=?, interval_unit=?, interval_value=?,
         next_run_at=?, webhook_url=? WHERE id=? AND user_id=? AND org_id=?"
    );
    $stmt->bind_param('ssssissiii', $name, $prompt, $schedule, $intervalUnit, $intervalValue, $nextRun, $nullWebhook, $id, $uid, $orgId);
    $stmt->execute();
    $stmt->close();
    saas_record_audit($db, $orgId, $uid, 'automation.update', 'automation', (string)$id, ['name' => $name]);
    echo json_encode(['success'=>true]);
    exit;
}

// ── TOGGLE ───────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'toggle') {
    saas_require_role($ctx, ['owner', 'admin', 'member']);

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { api_fail('id required'); }
    $stmt = $db->prepare(
        "UPDATE automations SET enabled = IF(enabled=1,0,1) WHERE id=? AND user_id=? AND org_id=?"
    );
    $stmt->bind_param('iii', $id, $uid, $orgId);
    $stmt->execute();
    $stmt->close();
    $row = $db->query("SELECT enabled FROM automations WHERE id=$id AND user_id=$uid AND org_id=$orgId")->fetch_assoc();
    saas_record_audit($db, $orgId, $uid, 'automation.toggle', 'automation', (string)$id);
    echo json_encode(['success'=>true, 'enabled'=>(bool)(int)($row['enabled'] ?? 0)]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'delete') {
    saas_require_role($ctx, ['owner', 'admin']);

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { api_fail('id required'); }
    $db->query("DELETE FROM automation_runs WHERE automation_id = $id AND user_id = $uid AND org_id = $orgId");
    $stmt = $db->prepare("DELETE FROM automations WHERE id=? AND user_id=? AND org_id=?");
    $stmt->bind_param('iii', $id, $uid, $orgId);
    $stmt->execute();
    $stmt->close();
    saas_record_audit($db, $orgId, $uid, 'automation.delete', 'automation', (string)$id);
    echo json_encode(['success'=>true]);
    exit;
}

// ── RUN NOW (manual trigger) ─────────────────────────────────────────────────
if (!$automationLibraryMode && $action === 'run_now') {
    saas_require_role($ctx, ['owner', 'admin', 'member']);

    $rl = saas_rate_limit_check($db, 'automation_run_now', $orgId . ':' . $uid, 20, 60, 120);
    if ($rl['blocked']) {
        api_fail('Too many run requests. Try again in ' . (int)$rl['retry_after'] . 's', 429);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { api_fail('id required'); }
    $stmt = $db->prepare("SELECT * FROM automations WHERE id=? AND user_id=? AND org_id=?");
    $stmt->bind_param('iii', $id, $uid, $orgId);
    $stmt->execute();
    $auto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$auto) { api_fail('Not found'); }

    $idemKey = trim((string)api_request_header('Idempotency-Key'));
    if ($idemKey !== '' && saas_is_idempotent_request($db, $orgId, 'automation.run_now', $idemKey, 300)) {
        api_fail('Duplicate run request', 409);
    }

    $result = automation_execute($db, $auto, $idemKey);
    if (!$result['success']) {
        saas_rate_limit_fail($db, 'automation_run_now', $orgId . ':' . $uid, 60, 20, 120);
    }
    echo json_encode($result);
    exit;
}

if (!$automationLibraryMode && $action === 'operator_queue') {
    saas_require_role($ctx, ['owner', 'admin']);

    $runs = [];
    $stmt = $db->prepare(
        "SELECT r.id, r.automation_id, r.error_message, r.ran_at, a.name AS automation_name, u.username
         FROM automation_runs r
         INNER JOIN automations a ON a.id = r.automation_id
         LEFT JOIN users u ON u.id = r.user_id
         WHERE r.org_id = ?
           AND r.status = 'error'
           AND r.acknowledged_at IS NULL
         ORDER BY r.ran_at DESC
         LIMIT 50"
    );
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['automation_id'] = (int)$row['automation_id'];
        $runs[] = $row;
    }
    $stmt->close();

    $summaryStmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN last_status = 'error' THEN 1 ELSE 0 END) AS failed_automations,
            SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled_automations,
            COUNT(*) AS total_automations
         FROM automations
         WHERE org_id = ?"
    );
    $summaryStmt->bind_param('i', $orgId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    echo json_encode([
        'success' => true,
        'summary' => [
            'failed_automations' => (int)($summary['failed_automations'] ?? 0),
            'enabled_automations' => (int)($summary['enabled_automations'] ?? 0),
            'total_automations' => (int)($summary['total_automations'] ?? 0),
            'unacked_failed_runs' => count($runs),
        ],
        'runs' => $runs,
    ]);
    exit;
}

if (!$automationLibraryMode && $action === 'operator_ack_run') {
    saas_require_role($ctx, ['owner', 'admin']);

    $runId = (int)($_POST['run_id'] ?? 0);
    if ($runId <= 0) {
        api_fail('run_id required');
    }

    $stmt = $db->prepare(
        "UPDATE automation_runs
         SET acknowledged_at = NOW(), acknowledged_by_user_id = ?
         WHERE id = ? AND org_id = ?"
    );
    $stmt->bind_param('iii', $uid, $runId, $orgId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        api_fail('Run not found', 404);
    }

    saas_record_audit($db, $orgId, $uid, 'automation.ack_error_run', 'automation_run', (string)$runId);
    echo json_encode(['success' => true]);
    exit;
}

if (!$automationLibraryMode && $action === 'operator_retry') {
    saas_require_role($ctx, ['owner', 'admin']);

    $automationId = (int)($_POST['automation_id'] ?? 0);
    if ($automationId <= 0) {
        api_fail('automation_id required');
    }

    $stmt = $db->prepare("SELECT * FROM automations WHERE id = ? AND org_id = ? LIMIT 1");
    $stmt->bind_param('ii', $automationId, $orgId);
    $stmt->execute();
    $auto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$auto) {
        api_fail('Automation not found', 404);
    }

    $result = automation_execute($db, $auto, 'operator-retry-' . $uid . '-' . time());
    saas_record_audit($db, $orgId, $uid, 'automation.operator_retry', 'automation', (string)$automationId, ['success' => (bool)($result['success'] ?? false)]);
    echo json_encode($result);
    exit;
}

if (!$automationLibraryMode) {
    api_fail('Unknown action');
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function automation_next_run(int $intervalValue, string $intervalUnit): string {
    $map = ['minute'=>'minute','hour'=>'hour','day'=>'day','week'=>'week','month'=>'month'];
    $unit = $map[$intervalUnit] ?? 'day';
    return date('Y-m-d H:i:s', strtotime("+$intervalValue $unit"));
}

function automation_execute(mysqli $db, array $auto, string $idempotencyKey = ''): array {
    $provider = 'local';

    $prompt = $auto['prompt'];
    $autoId = (int)$auto['id'];
    $orgId  = (int)($auto['org_id'] ?? 0);
    $uid    = (int)$auto['user_id'];

    require_once __DIR__ . '/dataset_search.php';
    $datasetMatches = datasetSearch($db, (string)$prompt, '', 3);
    $datasetContext = '';
    if (!empty($datasetMatches)) {
        $ctxLines = [];
        foreach ($datasetMatches as $i => $match) {
            $n = $i + 1;
            $q = trim((string)($match['question'] ?? ''));
            $a = trim((string)($match['answer'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $ctxLines[] = "{$n}. Q: {$q}\nA: {$a}";
        }
        if (!empty($ctxLines)) {
            $datasetContext = "Reference these approved dataset examples when relevant:\n" . implode("\n\n", $ctxLines) . "\n\n";
        }
    }

    try {
        $reply = automation_call_llm($provider, '', '', '', $datasetContext . $prompt);
        $nextRun = automation_next_run((int)$auto['interval_value'], $auto['interval_unit']);

        $stmt = $db->prepare(
            "UPDATE automations SET last_run_at=NOW(), next_run_at=?, last_result=?,
             last_status='ok', run_count=run_count+1 WHERE id=?"
        );
        $r = mb_substr($reply, 0, 8000);
        $stmt->bind_param('ssi', $nextRun, $r, $autoId);
        $stmt->execute(); $stmt->close();

        $stmt2 = $db->prepare(
            "INSERT INTO automation_runs (org_id, automation_id, user_id, status, result, idempotency_key) VALUES (?,?,?,'ok',?,?)"
        );
        $idem = $idempotencyKey !== '' ? $idempotencyKey : null;
        $stmt2->bind_param('iiiss', $orgId, $autoId, $uid, $reply, $idem);
        $stmt2->execute(); $stmt2->close();

        saas_record_usage($db, $orgId, $uid, 'automation_run', 1.0, ['automation_id' => $autoId, 'status' => 'ok']);
        saas_record_audit($db, $orgId, $uid, 'automation.run', 'automation', (string)$autoId, ['status' => 'ok']);

        // Webhook delivery (fire-and-forget)
        if (!empty($auto['webhook_url'])) {
            automation_deliver_webhook($auto['webhook_url'], [
                'automation_id' => $autoId,
                'org_id'        => $orgId,
                'name'          => $auto['name'],
                'result'        => $reply,
                'ran_at'        => date('c'),
            ], $orgId, $db);
        }

        return ['success'=>true, 'result'=>$reply, 'next_run_at'=>$nextRun];

    } catch (Throwable $e) {
        $errMsg = substr($e->getMessage(), 0, 500);
        $stmt = $db->prepare(
            "UPDATE automations SET last_run_at=NOW(), last_status='error', last_result=? WHERE id=?"
        );
        $stmt->bind_param('si', $errMsg, $autoId);
        $stmt->execute(); $stmt->close();

        $stmt2 = $db->prepare(
            "INSERT INTO automation_runs (org_id, automation_id, user_id, status, error_message, idempotency_key) VALUES (?,?,?,'error',?,?)"
        );
        $idem = $idempotencyKey !== '' ? $idempotencyKey : null;
        $stmt2->bind_param('iiiss', $orgId, $autoId, $uid, $errMsg, $idem);
        $stmt2->execute(); $stmt2->close();

        saas_record_usage($db, $orgId, $uid, 'automation_run', 1.0, ['automation_id' => $autoId, 'status' => 'error']);
        saas_record_audit($db, $orgId, $uid, 'automation.run', 'automation', (string)$autoId, ['status' => 'error', 'error' => $errMsg]);

        return ['success'=>false, 'error'=>$errMsg];
    }
}

function automation_call_llm(string $provider, string $groqKey, string $orKey, string $oaKey, string $prompt): string {
    $localBase = rtrim((string)api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRoot = preg_replace('#/v1$#', '', $localBase) ?: $localBase;
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest')) ?: 'lyralink-auto-canary:latest';
    $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
    try {
        return automation_http_llm(
            $localRoot . '/api/chat',
            "Bearer $localApiKey",
            $localModel,
            $prompt
        );
    } catch (Throwable $e) {
        return automation_http_llm(
            $localBase . '/chat/completions',
            "Bearer $localApiKey",
            $localModel,
            $prompt
        );
    }
}

function automation_http_llm(string $url, string $authHeader, string $model, string $prompt): string {
    $body = json_encode([
        'model'    => $model,
        'messages' => [['role'=>'user','content'=>$prompt]],
        'max_tokens' => 1024,
        'stream' => false,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '4'),
        CURLOPT_TIMEOUT        => (int)api_get_secret('LOCAL_LLM_TIMEOUT', '35'),
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => (int)api_get_secret('LOCAL_LLM_LOW_SPEED_TIME', '25'),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: $authHeader",
        ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { throw new RuntimeException("cURL error: $err"); }
    $data = json_decode($raw, true);
    $reply = $data['choices'][0]['message']['content'] ?? ($data['message']['content'] ?? null);
    if ($reply === null) {
        throw new RuntimeException('LLM returned no content: ' . substr($raw, 0, 300));
    }
    return $reply;
}

function automation_deliver_webhook(string $url, array $payload, int $orgId = 0, ?mysqli $db = null): void {
    $isDiscord = stripos($url, 'discord.com/api/webhooks/') !== false
                 || stripos($url, 'discordapp.com/api/webhooks/') !== false;

    if ($isDiscord) {
        $result  = (string)($payload['result'] ?? '');
        $name    = (string)($payload['name'] ?? 'Automation');
        $ranAt   = (string)($payload['ran_at'] ?? date('c'));

        // Discord embeds cap description at 4096 chars
        $description = mb_strlen($result) > 4000
            ? mb_substr($result, 0, 4000) . '…'
            : $result;

        $body = json_encode([
            'username'   => 'Lyralink Automations',
            'avatar_url' => 'https://lyralinkai.com/images/lyralinklogobolt.png',
            'embeds'     => [[
                'title'       => '⚡ ' . $name,
                'description' => $description,
                'color'       => 0x7c3aed,
                'footer'      => ['text' => 'Lyralink Automation'],
                'timestamp'   => $ranAt,
            ]],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $body = json_encode($payload);
    }

    $headers = ['Content-Type: application/json', 'User-Agent: Lyralink-Automation/1.0'];
    if ($db && $orgId > 0) {
        $secret = saas_webhook_signature_secret($db, $orgId);
        if ($secret) {
            $headers[] = 'X-Lyralink-Signature: ' . saas_webhook_signature_header((string)$body, $secret);
            $headers[] = 'X-Lyralink-Delivery-Timestamp: ' . time();
            $headers[] = 'X-Lyralink-Org-Id: ' . $orgId;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
