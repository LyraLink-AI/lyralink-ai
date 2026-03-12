<?php
require_once __DIR__ . '/security.php';
// ════════════════════════════════
// Lyralink Public Dataset API
// GET /api/public_api.php?q=your+query&key=YOUR_API_KEY
// ════════════════════════════════
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, X-API-Key');

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

function error($message, $code = 400, $status = 400) {
    respond(['error' => ['code' => $code, 'message' => $message]], $status);
}

// ── EXTRACT API KEY ──
// Accept via ?key=, header X-API-Key, or Authorization: Bearer
$apiKey = $_GET['key']
    ?? $_SERVER['HTTP_X_API_KEY']
    ?? null;

if (!$apiKey) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $apiKey = $m[1];
}

if (!$apiKey) {
    error('API key required. Pass ?key=YOUR_KEY or X-API-Key header.', 'MISSING_KEY', 401);
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

if ($action === 'prompt' || (!empty($input['prompt']) && $_SERVER['REQUEST_METHOD'] === 'POST')) {
    $prompt = trim($input['prompt'] ?? '');
    if (!$prompt) error('prompt field is required.', 'MISSING_PROMPT');
    if (strlen($prompt) > 8000) error('Prompt must be under 8000 characters.', 'PROMPT_TOO_LONG');

    // Rate limit (same counter)
    $keyId = (int)$key['id'];
    $stmt = $db->prepare("UPDATE api_keys SET requests_today = requests_today + 1, requests_total = requests_total + 1, last_used_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $keyId);
    $stmt->execute();
    $stmt->close();

    // Call Groq
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $groqApiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'    => 'llama-3.1-8b-instant',
            'messages' => [
                ['role' => 'system', 'content' => 'You are Lyralink AI, a helpful coding assistant. When asked to fix code, return ONLY the corrected code with no explanation or markdown fences unless specifically asked.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'max_tokens'  => 4096,
            'temperature' => 0.2,
        ]),
    ]);
    $groqRes  = curl_exec($ch);
    $groqCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($groqCode !== 200) {
        error('AI service error. Please try again.', 'AI_ERROR', 503);
    }

    $groqData = json_decode($groqRes, true);
    $reply    = $groqData['choices'][0]['message']['content'] ?? null;

    if (!$reply) error('AI returned empty response.', 'AI_EMPTY', 503);

    $remaining = $limit - $key['requests_today'] - 1;
    header('X-RateLimit-Limit: '     . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $remaining));
    header('X-RateLimit-Reset: '     . strtotime('tomorrow'));

    respond([
        'success'  => true,
        'response' => $reply,
        'meta'     => [
            'plan'               => $key['plan'],
            'requests_today'     => $key['requests_today'] + 1,
            'requests_limit'     => $limit,
            'requests_remaining' => max(0, $remaining),
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
$results = datasetSearch($db, $query, $groqApiKey, $limit_results);

// ── INCREMENT USAGE ──
$keyId = (int)$key['id'];
$stmt = $db->prepare("UPDATE api_keys SET requests_today = requests_today + 1, requests_total = requests_total + 1, last_used_at = NOW() WHERE id = ?");
$stmt->bind_param('i', $keyId);
$stmt->execute();
$stmt->close();

$remaining = $limit - $key['requests_today'] - 1;
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
        'requests_today'  => $key['requests_today'] + 1,
        'requests_limit'  => $limit,
        'requests_remaining' => max(0, $remaining),
    ]
]);
?>