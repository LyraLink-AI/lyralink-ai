<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lib/network_policy.php';
require_once __DIR__ . '/lib/ai_safeguards.php';
require_once __DIR__ . '/../benchmark/_storage.php';
// ════════════════════════════════
// Lyralink Public Dataset API
// GET /api/public_api.php?q=your+query (authenticate with X-API-Key or Authorization: Bearer)
// ════════════════════════════════
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    error('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

// ── CONFIG ──
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

// Rate limits per plan (requests per day)
$rateLimits = [
    'free'       => 100,
    'basic'      => 500,
    'pro'        => 2000,
    'enterprise' => 10000,
];

$groqApiKey = api_get_secret('GROQ_API_KEY', '');

// ── HELPERS ──
function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function public_api_http_llm($url, $apiKey, array $payload, $connectTimeout, $timeout) {
    $urlCheck = netpolicy_validate_outbound_url((string)$url, true);
    if (!$urlCheck['ok']) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => (int)$connectTimeout,
        CURLOPT_TIMEOUT => (int)$timeout,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => (int)api_get_secret('LOCAL_LLM_LOW_SPEED_TIME', '25'),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? ($data['message']['content'] ?? null);
}

function public_api_chat_orchestrator_url(): string {
    $override = trim((string)api_get_secret('PUBLIC_API_CHAT_ORCHESTRATOR_URL', ''));
    if ($override !== '') {
        return $override;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off' && $https !== '0') ? 'https' : 'http';
    if ($host !== '') {
        return $scheme . '://' . $host . '/api/chat.php';
    }

    return 'http://127.0.0.1/api/chat.php';
}

function public_api_call_chat_orchestrator(array $payload): array {
    $url = public_api_chat_orchestrator_url();
    $urlCheck = netpolicy_validate_outbound_url((string)$url, true);
    if (!$urlCheck['ok']) {
        return ['ok' => false, 'error' => 'orchestrator_url_blocked'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => (int)api_get_secret('PUBLIC_API_CHAT_CONNECT_TIMEOUT', '3'),
        CURLOPT_TIMEOUT => (int)api_get_secret('PUBLIC_API_CHAT_TIMEOUT', '90'),
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => (int)api_get_secret('LOCAL_LLM_LOW_SPEED_TIME', '25'),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $res = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($res) || $res === '') {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'empty_orchestrator_response', 'http_code' => $httpCode];
    }

    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_orchestrator_json', 'http_code' => $httpCode];
    }

    $reply = trim((string)($decoded['reply'] ?? ''));
    if ($reply === '') {
        return ['ok' => false, 'error' => 'orchestrator_missing_reply', 'http_code' => $httpCode, 'response' => $decoded];
    }

    return [
        'ok' => ($httpCode >= 200 && $httpCode < 300),
        'reply' => $reply,
        'http_code' => $httpCode,
        'response' => $decoded,
        'error' => null,
    ];
}

function public_api_prompt_response_from_chat(array $chatResponse, array $key, int $limit, int $remaining, array $orchestrationMeta): array {
    $reply = trim((string)($chatResponse['reply'] ?? ''));
    $safety = is_array($chatResponse['safety'] ?? null) ? $chatResponse['safety'] : [];

    return array_filter([
        'success' => true,
        'response' => $reply,
        'reply' => $reply,
        'thinking' => $chatResponse['thinking'] ?? null,
        'reasoning' => $chatResponse['reasoning'] ?? null,
        'agent' => $chatResponse['agent'] ?? null,
        'execution' => $chatResponse['execution'] ?? null,
        'agent_economy' => $chatResponse['agent_economy'] ?? null,
        'intelligence' => $chatResponse['intelligence'] ?? null,
        'project' => $chatResponse['project'] ?? null,
        'work' => $chatResponse['work'] ?? null,
        'memory' => $chatResponse['memory'] ?? null,
        'developer_ecosystem' => $chatResponse['developer_ecosystem'] ?? null,
        'trust' => $chatResponse['trust'] ?? null,
        'distribution' => $chatResponse['distribution'] ?? null,
        'confidence' => $chatResponse['confidence'] ?? null,
        'verification' => $chatResponse['verification'] ?? null,
        'telemetry' => $chatResponse['telemetry'] ?? null,
        'answerability' => $chatResponse['answerability'] ?? null,
        'hallucination' => $chatResponse['hallucination'] ?? null,
        'safety' => [
            'blocked' => (bool)($safety['blocked'] ?? false),
            'flags' => is_array($safety['flags'] ?? null) ? $safety['flags'] : [],
            'redactions' => is_array($safety['redactions'] ?? null) ? $safety['redactions'] : [],
        ],
        'trace_id' => $chatResponse['trace_id'] ?? null,
        'chat' => $chatResponse,
        'meta' => [
            'plan' => $key['plan'],
            'requests_today' => $key['requests_today'] + 1,
            'requests_limit' => $limit,
            'requests_remaining' => max(0, $remaining),
            'orchestration' => $orchestrationMeta,
        ],
    ], static fn($value) => $value !== null);
}

// Local Lyra-first runtime.
function public_api_call_llm($messages, $maxTokens, $temperature, $groqApiKey) {
    $localBase = rtrim((string)api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRoot = preg_replace('#/v1$#', '', $localBase) ?: $localBase;
    $localKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest')) ?: 'lyralink-auto-canary:latest';
    $preferFullReplies = api_get_secret('LOCAL_LLM_FULL_REPLY_MODE', '1') === '1';
    $modelLower = strtolower($localModel);
    $defaultLocalTimeout = 35;
    if ($preferFullReplies) {
        $defaultLocalTimeout = str_contains($modelLower, '3b') ? 90 : 120;
    } elseif (str_contains($modelLower, '8b')) {
        $defaultLocalTimeout = 45;
    }
    $localTimeout = (int)api_get_secret('LOCAL_LLM_TIMEOUT', (string)$defaultLocalTimeout);
    $localFallbackTimeout = (int)api_get_secret('LOCAL_LLM_FALLBACK_TIMEOUT', $preferFullReplies ? '25' : '10');
    $localPredictCap = $preferFullReplies
        ? (str_contains($modelLower, '3b') ? 640 : 1152)
        : (str_contains($modelLower, '3b') ? 256 : 512);
    $numPredict = max(64, min((int)$maxTokens, (int)$localPredictCap));

    $chatPayload = [
        'model' => $localModel,
        'messages' => $messages,
        'stream' => false,
        'keep_alive' => trim((string)api_get_secret('LOCAL_LLM_KEEP_ALIVE', '2h')) ?: '2h',
        'options' => [
            'num_predict' => $numPredict,
            'temperature' => $temperature,
        ],
    ];
    $reply = public_api_http_llm(
        $localRoot . '/api/chat',
        $localKey,
        $chatPayload,
        (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
        $localTimeout
    );
    if ($reply !== null) {
        return $reply;
    }

    $completionPayload = [
        'model' => $localModel,
        'messages' => $messages,
        'max_tokens' => $numPredict,
        'temperature' => $temperature,
        'stream' => false,
    ];
    $reply = public_api_http_llm(
        $localBase . '/chat/completions',
        $localKey,
        $completionPayload,
        (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
        $localFallbackTimeout
    );
    if ($reply !== null) {
        return $reply;
    }

    if (stripos($localModel, '8b') !== false || stripos($localModel, '70b') !== false) {
        $fallbackModel = trim((string)api_get_secret('LOCAL_LLM_PUBLIC_FALLBACK_MODEL', 'lyralink-fast:latest'));
        if ($fallbackModel !== '' && strcasecmp($fallbackModel, $localModel) !== 0) {
            $fallbackPayload = $chatPayload;
            $fallbackPayload['model'] = $fallbackModel;
            $fallbackPayload['options']['num_predict'] = max(64, min((int)$numPredict, $preferFullReplies ? 640 : 256));
            $reply = public_api_http_llm(
                $localRoot . '/api/chat',
                $localKey,
                $fallbackPayload,
                (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
                $localFallbackTimeout
            );
            if ($reply !== null) {
                return $reply;
            }
        }
    }

    if (!empty($groqApiKey)) {
        $groqPayload = [
            'model' => trim((string)api_get_secret('GROQ_MODEL', 'llama-3.1-8b-instant')) ?: 'llama-3.1-8b-instant',
            'messages' => $messages,
            'max_tokens' => max(64, min((int)$maxTokens, 1024)),
            'temperature' => $temperature,
            'stream' => false,
        ];
        $reply = public_api_http_llm(
            'https://api.groq.com/openai/v1/chat/completions',
            $groqApiKey,
            $groqPayload,
            4,
            35
        );
        if ($reply !== null) {
            return $reply;
        }
    }

    return null;
}

function error($message, $code = 400, $status = 400) {
    respond(['error' => ['code' => $code, 'message' => $message]], $status);
}

function public_api_reserve_quota(mysqli $db, int $keyId, int $limit): array {
    $stmt = $db->prepare("UPDATE api_keys SET requests_today = requests_today + 1, requests_total = requests_total + 1, last_used_at = NOW() WHERE id = ? AND active = 1 AND requests_today < ?");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'quota_prepare_failed'];
    }
    $stmt->bind_param('ii', $keyId, $limit);
    $stmt->execute();
    $claimed = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$claimed) {
        return ['ok' => false, 'rate_limited' => true];
    }

    $current = 0;
    $check = $db->prepare("SELECT requests_today FROM api_keys WHERE id = ? LIMIT 1");
    if ($check) {
        $check->bind_param('i', $keyId);
        $check->execute();
        $check->bind_result($current);
        $check->fetch();
        $check->close();
    }

    return [
        'ok' => true,
        'requests_today' => max(0, (int)$current),
        'requests_remaining' => max(0, $limit - max(0, (int)$current)),
    ];
}

function public_api_bench_json(string $relative): array {
    $path = benchmark_storage_path($relative);
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function public_api_bench_text(string $relative): ?string {
    $path = benchmark_storage_path($relative);
    if (!is_readable($path)) {
        return null;
    }
    return (string)file_get_contents($path);
}

function public_api_bench_clamp_int($value, int $min, int $max, int $default): int {
    $n = filter_var($value, FILTER_VALIDATE_INT);
    if ($n === false) {
        return $default;
    }
    if ($n < $min) {
        return $min;
    }
    if ($n > $max) {
        return $max;
    }
    return $n;
}

function public_api_bench_bool($value, bool $default = false): bool {
    if ($value === null || $value === '') {
        return $default;
    }
    $raw = strtolower(trim((string)$value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function public_api_bench_allowed_path(string $relative): bool {
    $normalized = str_replace('\\', '/', trim($relative));
    if ($normalized === '' || str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
        return false;
    }
    foreach (['tasks/', 'lyralink/', 'scoring/', 'external/'] as $root) {
        if (str_starts_with($normalized, $root)) {
            return true;
        }
    }
    return false;
}

function public_api_bench_task_record(array $entry, bool $includeArtifacts = false): array {
    $taskId = (string)($entry['task_id'] ?? '');
    $taskFile = (string)($entry['task_file'] ?? ('tasks/' . $taskId . '.json'));
    $lyraFile = (string)($entry['lyralink_file'] ?? ('lyralink/' . $taskId . '.json'));
    $scoringFile = (string)($entry['scoring_file'] ?? ('scoring/' . $taskId . '.json'));
    $externalTemplate = (string)($entry['external_template'] ?? ('external/' . $taskId . '.txt'));

    $task = public_api_bench_json($taskFile);
    $lyra = public_api_bench_json($lyraFile);
    $scoring = public_api_bench_json($scoringFile);

    $record = [
        'task_id' => $taskId,
        'category' => (string)($task['category'] ?? ''),
        'prompt_hash' => (string)($task['prompt_hash'] ?? ($entry['prompt_hash'] ?? '')),
        'run_status' => (string)($entry['run_status'] ?? ''),
        'output_status' => (string)($entry['output_status'] ?? ''),
        'score_status' => (string)($entry['score_status'] ?? ''),
        'failure_class' => (string)($entry['failure_class'] ?? ''),
        'latency_ms' => isset($entry['latency_ms']) ? (int)$entry['latency_ms'] : null,
        'lyralink_score' => $scoring['lyralink_score'] ?? null,
        'external_score' => $scoring['external_score'] ?? null,
        'winner' => $scoring['winner'] ?? null,
        'links' => [
            'task' => '/api/public_api.php?action=benchmark_file&path=' . rawurlencode($taskFile),
            'lyralink' => '/api/public_api.php?action=benchmark_file&path=' . rawurlencode($lyraFile),
            'scoring' => '/api/public_api.php?action=benchmark_file&path=' . rawurlencode($scoringFile),
            'external_template' => '/api/public_api.php?action=benchmark_file&path=' . rawurlencode($externalTemplate),
        ],
    ];

    if ($includeArtifacts) {
        $record['artifacts'] = [
            'task' => $task,
            'lyralink' => $lyra,
            'scoring' => $scoring,
            'external_template' => public_api_bench_text($externalTemplate),
        ];
    }

    return $record;
}

function public_api_benchmark_dispatch(string $action): void {
    $manifest = public_api_bench_json('benchmark_manifest.json');
    $summary = public_api_bench_json('scoring/BENCHMARK_SUMMARY_V2.json');
    $tasks = is_array($manifest['tasks'] ?? null) ? $manifest['tasks'] : [];

    $limit = public_api_bench_clamp_int($_GET['limit'] ?? null, 1, 100, 25);
    $offset = public_api_bench_clamp_int($_GET['offset'] ?? null, 0, 1000000, 0);
    $includeArtifacts = public_api_bench_bool($_GET['include_artifacts'] ?? null, false);

    $response = [
        'ok' => true,
        'action' => $action,
        'generated_at' => gmdate('c'),
        'benchmark' => [
            'name' => (string)($summary['benchmark_name'] ?? $manifest['benchmark_name'] ?? 'lyralink-blind-capability-benchmark-v2'),
            'run_id' => (string)($summary['run_id'] ?? $manifest['run_id'] ?? ''),
            'run_status' => (string)($summary['run_status'] ?? $manifest['run_status'] ?? ''),
            'benchmark_version' => (string)($summary['benchmark_version'] ?? $manifest['benchmark_version'] ?? ''),
            'generated_at' => (string)($summary['generated_at'] ?? $manifest['generated_at'] ?? ''),
            'updated_at' => (string)($manifest['updated_at'] ?? ''),
            'total_tasks' => count($tasks),
            'scored_tasks' => (int)($summary['lyralink_scored'] ?? $manifest['scored_tasks'] ?? 0),
            'external_outputs_available' => (int)($summary['external_outputs_available'] ?? $manifest['external_outputs_available'] ?? 0),
            'weighted_total' => $summary['weighted_total'] ?? null,
            'lyralink_average' => $summary['lyralink_average'] ?? null,
            'critical_failures' => $summary['critical_failures'] ?? null,
            'category_breakdown' => is_array($summary['category_breakdown'] ?? null) ? $summary['category_breakdown'] : [],
        ],
    ];

    if ($action === 'benchmark_overview') {
        $response['api'] = [
            'benchmark_overview' => '/api/public_api.php?action=benchmark_overview',
            'benchmark_tasks' => '/api/public_api.php?action=benchmark_tasks&limit=25&offset=0',
            'benchmark_task' => '/api/public_api.php?action=benchmark_task&task_id=T001',
            'benchmark_all' => '/api/public_api.php?action=benchmark_all&limit=10&offset=0&include_artifacts=1',
            'benchmark_file' => '/api/public_api.php?action=benchmark_file&path=scoring/T001.json',
        ];
        respond($response, 200);
    }

    if ($action === 'benchmark_file') {
        $path = trim((string)($_GET['path'] ?? ''));
        if (!public_api_bench_allowed_path($path)) {
            respond(['ok' => false, 'error' => 'invalid_path'], 400);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $payload = public_api_bench_json($path);
            if ($payload === []) {
                respond(['ok' => false, 'error' => 'not_found'], 404);
            }
            respond(['ok' => true, 'path' => $path, 'content_type' => 'application/json', 'content' => $payload], 200);
        }
        $content = public_api_bench_text($path);
        if ($content === null) {
            respond(['ok' => false, 'error' => 'not_found'], 404);
        }
        respond(['ok' => true, 'path' => $path, 'content_type' => 'text/plain', 'content' => $content], 200);
    }

    if ($action === 'benchmark_task') {
        $taskId = strtoupper(trim((string)($_GET['task_id'] ?? '')));
        if ($taskId === '') {
            respond(['ok' => false, 'error' => 'missing_task_id'], 400);
        }
        $found = null;
        foreach ($tasks as $entry) {
            if (is_array($entry) && strtoupper((string)($entry['task_id'] ?? '')) === $taskId) {
                $found = $entry;
                break;
            }
        }
        if ($found === null) {
            respond(['ok' => false, 'error' => 'task_not_found'], 404);
        }
        $response['task'] = public_api_bench_task_record($found, true);
        respond($response, 200);
    }

    if ($action === 'benchmark_tasks' || $action === 'benchmark_all') {
        $slice = array_slice($tasks, $offset, $limit);
        $items = [];
        foreach ($slice as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $items[] = public_api_bench_task_record($entry, $action === 'benchmark_all' ? $includeArtifacts : false);
        }
        $response['paging'] = [
            'limit' => $limit,
            'offset' => $offset,
            'returned' => count($items),
            'total' => count($tasks),
            'next_offset' => ($offset + $limit) < count($tasks) ? ($offset + $limit) : null,
        ];
        $response['tasks'] = $items;
        respond($response, 200);
    }

    respond(['ok' => false, 'error' => 'unknown_benchmark_action'], 400);
}

// ── EXTRACT API KEY ──
// Prefer header-based authentication to avoid API-key leakage via URLs, logs, and referrers.
$publicAction = strtolower(trim((string)($_GET['action'] ?? 'search')));
$publicBenchmarkActions = ['benchmark_overview', 'benchmark_tasks', 'benchmark_task', 'benchmark_all', 'benchmark_file'];
if (in_array($publicAction, $publicBenchmarkActions, true)) {
    public_api_benchmark_dispatch($publicAction);
}

$allowQueryKey = api_get_secret('PUBLIC_API_ALLOW_QUERY_KEY', '0') === '1';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

// Some server stacks expose Authorization under alternate keys.
$authHeader = '';
foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization', 'HTTP_X_ORIGINAL_AUTHORIZATION'] as $k) {
    if (!empty($_SERVER[$k]) && is_string($_SERVER[$k])) {
        $authHeader = $_SERVER[$k];
        break;
    }
}
if ($authHeader === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach (['Authorization', 'authorization'] as $h) {
            if (!empty($headers[$h]) && is_string($headers[$h])) {
                $authHeader = $headers[$h];
                break;
            }
        }
    }
}

if (!$apiKey) {
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) $apiKey = $m[1];
}

if (!$apiKey && $allowQueryKey) {
    $apiKey = $_GET['key'] ?? null;
}

$apiKey = is_string($apiKey) ? trim($apiKey) : '';

if (!$allowQueryKey && isset($_GET['key'])) {
    error('Query-string API keys are disabled. Use X-API-Key or Authorization: Bearer.', 'INSECURE_KEY_TRANSPORT', 400);
}

if (!$apiKey) {
    error('API key required. Use X-API-Key or Authorization: Bearer.', 'MISSING_KEY', 401);
}

// ── DB ──
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) error('Service temporarily unavailable.', 'DB_ERROR', 503);

// ── VALIDATE KEY + GET USER PLAN ──
$stmt = $db->prepare("
    SELECT k.id, k.user_id, k.requests_today, k.requests_total, k.reset_at, k.active,
           u.plan
    FROM api_keys k
    JOIN users u ON u.id = k.user_id
    WHERE k.api_key = ?
");
$stmt->bind_param('s', $apiKey);
$stmt->execute();
$key = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$key) error('Invalid API key.', 'INVALID_KEY', 401);
if (!$key['active']) error('This API key has been disabled.', 'KEY_DISABLED', 403);

// ── RATE LIMIT RESET ──
if ($key['reset_at'] !== date('Y-m-d')) {
    $resetAt = date('Y-m-d');
    $keyId = (int)$key['id'];
    $stmt = $db->prepare("UPDATE api_keys SET requests_today = 0, reset_at = ? WHERE id = ?");
    $stmt->bind_param('si', $resetAt, $keyId);
    $stmt->execute();
    $stmt->close();
    $key['requests_today'] = 0;
}

$limit = $rateLimits[$key['plan']] ?? $rateLimits['free'];
if ($key['requests_today'] >= $limit) {
    header('X-RateLimit-Limit: '     . $limit);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: '     . strtotime('tomorrow'));
    error("Rate limit reached. Your plan allows $limit requests/day. Resets at midnight UTC.", 'RATE_LIMITED', 429);
}

// ── ROUTE: PROMPT (POST) ──
// VS Code extension and other API consumers send prompts here
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $input['action'] ?? 'search';

if ($action === 'prompt' || (!empty($input['prompt']) && $method === 'POST')) {
    if ($method !== 'POST') {
        error('prompt action requires POST.', 'METHOD_NOT_ALLOWED', 405);
    }
    $prompt = trim($input['prompt'] ?? '');
    if (!$prompt) error('prompt field is required.', 'MISSING_PROMPT');
    if (strlen($prompt) > 8000) error('Prompt must be under 8000 characters.', 'PROMPT_TOO_LONG');

    $keyId = (int)$key['id'];
    $quota = public_api_reserve_quota($db, $keyId, $limit);
    if (!empty($quota['rate_limited'])) {
        header('X-RateLimit-Limit: '     . $limit);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: '     . strtotime('tomorrow'));
        error("Rate limit reached. Your plan allows $limit requests/day. Resets at midnight UTC.", 'RATE_LIMITED', 429);
    }
    if (empty($quota['ok'])) {
        error('Rate limit service error.', 'RATE_LIMIT_SERVICE_ERROR', 503);
    }

    $requestsToday = (int)($quota['requests_today'] ?? ($key['requests_today'] + 1));
    $remaining = (int)($quota['requests_remaining'] ?? max(0, $limit - $requestsToday));
    header('X-RateLimit-Limit: '     . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $remaining));
    header('X-RateLimit-Reset: '     . strtotime('tomorrow'));

    $publicMaxReplyTokens = (int)api_get_secret('PUBLIC_API_MAX_REPLY_TOKENS', '512');
    if ($publicMaxReplyTokens < 64) {
        $publicMaxReplyTokens = 64;
    }
    if ($publicMaxReplyTokens > 1536) {
        $publicMaxReplyTokens = 1536;
    }

    $providedMessages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
    $chatMessages = [];
    if (!empty($providedMessages)) {
        foreach ($providedMessages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = strtolower(trim((string)($msg['role'] ?? 'user')));
            $content = trim((string)($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $chatMessages[] = ['role' => $role, 'content' => $content];
        }
    }
    if (empty($chatMessages)) {
        $chatMessages = [['role' => 'user', 'content' => $prompt]];
    }

    $orchestratorPayload = [
        'messages' => $chatMessages,
        'max_tokens' => $publicMaxReplyTokens,
        'user_plan' => (string)($key['plan'] ?? 'free'),
        'client_channel' => 'public_api',
        'sdk_intent' => true,
        'benchmark_mode' => false,
    ];

    $useChatOrchestration = true;
    $allowLegacyFallback = api_get_secret('PUBLIC_API_ORCHESTRATION_FALLBACK', '0') === '1';

    $reply = null;
    $replySafety = ['flags' => [], 'redactions' => []];
    $orchestrationMeta = ['mode' => 'chat_orchestrator', 'fallback_used' => false, 'trace_id' => null];

    if ($useChatOrchestration) {
        $orchestrated = public_api_call_chat_orchestrator($orchestratorPayload);
        if (!empty($orchestrated['ok'])) {
            $reply = trim((string)($orchestrated['reply'] ?? ''));
            $orchestrationMeta['trace_id'] = (string)(($orchestrated['response']['trace_id'] ?? null) ?: '');
            $chatSafety = $orchestrated['response']['safety'] ?? [];
            if (is_array($chatSafety)) {
                $replySafety['flags'] = is_array($chatSafety['flags'] ?? null) ? $chatSafety['flags'] : [];
                $replySafety['redactions'] = is_array($chatSafety['redactions'] ?? null) ? $chatSafety['redactions'] : [];
            }
        } elseif ($allowLegacyFallback) {
            $orchestrationMeta['mode'] = 'legacy_llm_fallback';
            $orchestrationMeta['fallback_used'] = true;
            $fallbackMessages = [['role' => 'user', 'content' => $prompt]];
            $reply = public_api_call_llm($fallbackMessages, $publicMaxReplyTokens, 0.2, $groqApiKey);
        } else {
            error('AI orchestration service error. Please try again.', 'AI_ORCHESTRATION_ERROR', 503);
        }
    } else {
        $orchestrationMeta['mode'] = 'legacy_llm';
        $fallbackMessages = [['role' => 'user', 'content' => $prompt]];
        $reply = public_api_call_llm($fallbackMessages, $publicMaxReplyTokens, 0.2, $groqApiKey);
    }

    if (!$reply) error('AI service error. Please try again.', 'AI_ERROR', 503);

    if (!empty($orchestrated['ok']) && is_array($orchestrated['response'] ?? null)) {
        respond(public_api_prompt_response_from_chat($orchestrated['response'], $key, $limit, $remaining, $orchestrationMeta));
    }

    respond([
        'success'  => true,
        'response' => $reply,
        'reply' => $reply,
        'safety'   => [
            'blocked' => false,
            'flags' => $replySafety['flags'] ?? [],
            'redactions' => $replySafety['redactions'] ?? [],
        ],
        'meta'     => [
            'plan'               => $key['plan'],
            'requests_today'     => $requestsToday,
            'requests_limit'     => $limit,
            'requests_remaining' => max(0, $remaining),
            'orchestration' => $orchestrationMeta,
        ],
    ]);
}

// ── GET QUERY (dataset search) ──
$query = trim($_GET['q'] ?? '');
if (!$query) error('Query parameter ?q= is required, or POST with {prompt} for AI completions.', 'MISSING_QUERY');
if (strlen($query) > 500) error('Query must be under 500 characters.', 'QUERY_TOO_LONG');

$limit_results = min(10, max(1, (int)($_GET['limit'] ?? 3)));

// ── SEARCH DATASET ──
require_once __DIR__ . '/dataset_search.php';
$keyId = (int)$key['id'];
$quota = public_api_reserve_quota($db, $keyId, $limit);
if (!empty($quota['rate_limited'])) {
    header('X-RateLimit-Limit: '     . $limit);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: '     . strtotime('tomorrow'));
    error("Rate limit reached. Your plan allows $limit requests/day. Resets at midnight UTC.", 'RATE_LIMITED', 429);
}
if (empty($quota['ok'])) {
    error('Rate limit service error.', 'RATE_LIMIT_SERVICE_ERROR', 503);
}

$requestsToday = (int)($quota['requests_today'] ?? ($key['requests_today'] + 1));
$remaining = (int)($quota['requests_remaining'] ?? max(0, $limit - $requestsToday));
$results = datasetSearch($db, $query, $groqApiKey, $limit_results);
header('X-RateLimit-Limit: '     . $limit);
header('X-RateLimit-Remaining: ' . max(0, $remaining));
header('X-RateLimit-Reset: '     . strtotime('tomorrow'));

// ── RESPOND ──
respond([
    'object'  => 'search_results',
    'query'   => $query,
    'count'   => count($results),
    'results' => array_map(fn($r) => [
        'id'       => (int)$r['id'],
        'question' => $r['question'],
        'answer'   => $r['answer'],
        'score'    => round((float)$r['score'], 4),
        'method'   => $r['method'],
    ], $results),
    'meta' => [
        'plan'            => $key['plan'],
        'requests_today'  => $requestsToday,
        'requests_limit'  => $limit,
        'requests_remaining' => max(0, $remaining),
    ]
]);
?>