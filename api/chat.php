<?php
require_once __DIR__ . '/security.php';

$isDevMode = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
$isDebugEnabled = api_get_secret('APP_DEBUG', '0') === '1';
if ($isDevMode && $isDebugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);

session_start();
api_json_headers();

// ── CONFIG ──
$groqApiKey     = api_get_secret('GROQ_API_KEY', '');
$moltbookApiKey = api_get_secret('MOLTBOOK_API_KEY', '');
$agentName      = 'lyralink';

$llmProviderEnv = strtolower(trim(api_get_secret('LLM_PROVIDER', 'groq')));
$llmModelEnv    = trim(api_get_secret('LLM_MODEL', ''));

function llm_default_model(string $provider): string {
    $openRouterModel = trim(api_get_secret('OPENROUTER_MODEL', 'openclaw/openclaw-7b'));
    $openAiModel = trim(api_get_secret('OPENAI_MODEL', 'gpt-4o-mini'));
    return match ($provider) {
        'openrouter' => $openRouterModel !== '' ? $openRouterModel : 'openclaw/openclaw-7b',
        'openai'     => $openAiModel !== '' ? $openAiModel : 'gpt-4o-mini',
        default      => 'llama-3.1-8b-instant',
    };
}

function llm_provider_available(string $provider): bool {
    $provider = strtolower($provider);
    if ($provider === 'openrouter') {
        return api_get_secret('OPENROUTER_API_KEY', '') !== '';
    }
    if ($provider === 'openai') {
        return api_get_secret('OPENAI_API_KEY', '') !== '';
    }
    // Groq is default provider and requires key.
    return api_get_secret('GROQ_API_KEY', '') !== '';
}

function llm_first_available_provider(array $preferred = []): string {
    $order = array_values(array_unique(array_merge($preferred, ['groq', 'openrouter', 'openai'])));
    foreach ($order as $provider) {
        if (llm_provider_available($provider)) {
            return $provider;
        }
    }
    return 'groq';
}

function llm_parse_csv(string $raw): array {
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

function llm_provider_models(string $provider): array {
    return match (strtolower($provider)) {
        'openrouter' => llm_parse_csv(api_get_secret('LLM_OPENROUTER_MODELS', api_get_secret('OPENROUTER_MODEL', 'openclaw/openclaw-7b'))),
        'openai' => llm_parse_csv(api_get_secret('LLM_OPENAI_MODELS', api_get_secret('OPENAI_MODEL', 'gpt-4o-mini'))),
        default => llm_parse_csv(api_get_secret('LLM_GROQ_MODELS', 'llama-3.1-8b-instant,llama-3.3-70b-versatile')),
    };
}

function llm_allowed_providers_for_plan(string $plan): array {
    $key = 'LLM_ALLOWED_PROVIDERS_' . strtoupper($plan ?: 'free');
    return llm_parse_csv(api_get_secret($key, ''));
}

function llm_allowed_models_for_plan(string $plan): array {
    $key = 'LLM_ALLOWED_MODELS_' . strtoupper($plan ?: 'free');
    return llm_parse_csv(api_get_secret($key, ''));
}

function llm_provider_allowed_for_plan(string $provider, string $plan): bool {
    $allowedProviders = llm_allowed_providers_for_plan($plan);
    if (!$allowedProviders) {
        return true;
    }
    return in_array(strtolower($provider), array_map('strtolower', $allowedProviders), true);
}

function llm_model_allowed_for_plan(string $provider, string $model, string $plan): bool {
    $providerModels = llm_provider_models($provider);
    if ($providerModels && !in_array($model, $providerModels, true)) {
        return false;
    }
    $allowedModels = llm_allowed_models_for_plan($plan);
    if (!$allowedModels) {
        return true;
    }
    return in_array($model, $allowedModels, true);
}

function llm_first_valid_provider_for_plan(string $plan, array $preferred = []): string {
    $order = array_values(array_unique(array_merge($preferred, ['groq', 'openrouter', 'openai'])));
    foreach ($order as $provider) {
        if (llm_provider_available($provider) && llm_provider_allowed_for_plan($provider, $plan)) {
            return $provider;
        }
    }
    return llm_first_available_provider($preferred);
}

function llm_first_valid_model_for_plan(string $provider, string $plan): string {
    $models = llm_provider_models($provider);
    if (!$models) {
        return llm_default_model($provider);
    }
    foreach ($models as $model) {
        if (llm_model_allowed_for_plan($provider, $model, $plan)) {
            return $model;
        }
    }
    return $models[0];
}

if (!in_array($llmProviderEnv, ['groq', 'openrouter', 'openai'], true)) {
    $llmProviderEnv = 'groq';
}

if (!llm_provider_available($llmProviderEnv)) {
    $llmProviderEnv = llm_first_available_provider([$llmProviderEnv]);
}

$basePlanModel = $llmModelEnv !== '' ? $llmModelEnv : llm_default_model($llmProviderEnv);
$enterpriseModel = ($llmProviderEnv === 'groq' && $llmModelEnv === '')
    ? 'llama-3.3-70b-versatile'
    : $basePlanModel;

// ── DATABASE CONFIG ──
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

// ════════════════════════════════
// PLAN LIMITS
// ════════════════════════════════
$planLimits = [
    'free'       => ['messages' => 1500,  'provider' => $llmProviderEnv, 'model' => $basePlanModel,   'unlimited' => false],
    'basic'      => ['messages' => 2500,  'provider' => $llmProviderEnv, 'model' => $basePlanModel,   'unlimited' => false],
    'pro'        => ['messages' => 99999, 'provider' => $llmProviderEnv, 'model' => $basePlanModel,   'unlimited' => true],
    'enterprise' => ['messages' => 99999, 'provider' => $llmProviderEnv, 'model' => $enterpriseModel, 'unlimited' => true],
];

// ════════════════════════════════
// CHECK USER PLAN + ENFORCE LIMITS
// ════════════════════════════════
$userPlan    = 'free';
$userProvider = $llmProviderEnv;
$userModel   = 'llama-3.1-8b-instant';
$userCredits = 0;
$isLoggedIn  = !empty($_SESSION['user_id']);

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if (!$db->connect_error && $isLoggedIn) {
    $stmt = $db->prepare("SELECT plan, credits, msg_count, msg_reset_at FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($userData) {
        $sessionUserId = (int)$_SESSION['user_id'];

        // Reset monthly count if new month
        if ($userData['msg_reset_at'] !== date('Y-m-01')) {
            $resetAt = date('Y-m-01');
            $stmtReset = $db->prepare("UPDATE users SET msg_count = 0, msg_reset_at = ? WHERE id = ?");
            $stmtReset->bind_param('si', $resetAt, $sessionUserId);
            $stmtReset->execute();
            $stmtReset->close();
            $userData['msg_count'] = 0;
        }

        $userPlan    = $userData['plan'] ?? 'free';
        $userCredits = (int)($userData['credits'] ?? 0);
        $planConfig  = $planLimits[$userPlan] ?? $planLimits['free'];
        $userProvider = $planConfig['provider'] ?? 'groq';
        $userModel   = $planConfig['model'];

        // Check if over limit
        $overLimit = !$planConfig['unlimited'] && $userData['msg_count'] >= $planConfig['messages'];

        // Can use credits if over limit
        if ($overLimit && $userCredits > 0) {
            // Deduct 1 credit
            $stmtCredit = $db->prepare("UPDATE users SET credits = credits - 1 WHERE id = ?");
            $stmtCredit->bind_param('i', $sessionUserId);
            $stmtCredit->execute();
            $stmtCredit->close();
            $overLimit = false;
        }

        if ($overLimit) {
            echo json_encode([
                'reply'   => null,
                'error'   => 'limit_reached',
                'plan'    => $userPlan,
                'used'    => (int)$userData['msg_count'],
                'limit'   => $planConfig['messages'],
                'credits' => $userCredits,
                'message' => "You've used all " . $planConfig['messages'] . " messages for this month on the " . ucfirst($userPlan) . " plan. Upgrade your plan or top up with credits to keep chatting!"
            ]);
            exit;
        }

        // Increment message count
        $stmtInc = $db->prepare("UPDATE users SET msg_count = msg_count + 1 WHERE id = ?");
        $stmtInc->bind_param('i', $sessionUserId);
        $stmtInc->execute();
        $stmtInc->close();
    }
}

if ($userModel === '' || $userModel === null) {
    $userModel = llm_default_model($userProvider);
}

// ════════════════════════════════
// LYRALINK PERSONALITY
// ════════════════════════════════
$personality = <<<PROMPT
You are Lyralink, a friendly and casual AI assistant with a warm, approachable personality.

PERSONALITY:
- Casual and conversational — you talk like a knowledgeable friend, not a textbook
- Warm and encouraging — you genuinely enjoy helping people
- Curious and enthusiastic — you find science, code, and creative writing genuinely exciting
- You use light humour when appropriate but never at the expense of being helpful
- You keep things clear and digestible — no unnecessary jargon

SPECIALTIES:
- Coding & Tech: You love helping debug code, explain concepts, and build things
- Creative Writing: You enjoy storytelling, brainstorming, and helping people find their voice
- Science & Facts: You find the universe endlessly fascinating and love sharing that

STYLE:
- Use casual language — contractions, friendly tone, occasional light humour
- Never say "I cannot" or "I am unable" — find a way to help or suggest an alternative
- Don't start replies with "I" — vary your openings
- Keep responses focused and avoid padding
- You're Lyralink, not "an AI assistant" — own your identity
PROMPT;

// ════════════════════════════════
// HELPER: CALL LLM
// ════════════════════════════════
function callLlm(string $provider, array $messages, int $maxTokens = 1024, float $temperature = 0.75, string $model = 'llama-3.1-8b-instant') {
    $provider = strtolower($provider ?: 'groq');
    $groqApiKey = api_get_secret('GROQ_API_KEY', '');
    $openRouterApiKey = api_get_secret('OPENROUTER_API_KEY', '');
    $openAiApiKey = api_get_secret('OPENAI_API_KEY', '');
    $openAiBaseUrl = rtrim(api_get_secret('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');

    $url = '';
    $headers = ['Content-Type: application/json'];

    if ($provider === 'openrouter' && $openRouterApiKey !== '') {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $openRouterApiKey;
        $headers[] = 'HTTP-Referer: https://ai.cloudhavenx.com';
        $headers[] = 'X-Title: Lyralink AI';
    } elseif ($provider === 'openai' && $openAiApiKey !== '') {
        $url = $openAiBaseUrl . '/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $openAiApiKey;
    } else {
        $provider = 'groq';
        if ($groqApiKey === '') {
            return null;
        }
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $groqApiKey;
    }

    $data = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? null;
}

// ════════════════════════════════
// HELPER: SOLVE MOLTBOOK VERIFICATION
// ════════════════════════════════
// ── BUILD MOLTBOOK CONTEXT FROM DB ──
function buildMoltContext($db, $userMsg) {
    $msg = strtolower(substr($userMsg ?? '', 0, 200));

    // Check if user is asking about Moltbook specifically
    $moltMentioned = preg_match('/moltbook|molt|trending|social|other ai|other bots?|what.s popular/i', $userMsg ?? '');

    // Always grab our AI's recent posts (last 14 days, top 5 by upvotes)
    $ourPosts = [];
    $stmt = $db->prepare("SELECT title, body, upvotes, comment_count, posted_at, tags
        FROM moltbook_posts WHERE is_our_ai=1
        AND posted_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY upvotes DESC, posted_at DESC LIMIT 5");
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r) while ($row = $r->fetch_assoc()) $ourPosts[] = $row;
    $stmt->close();

    // Grab top posts from other AIs (last 7 days, ordered by upvotes)
    $otherPosts = [];
    $stmt = $db->prepare("SELECT title, body, upvotes, author_name, posted_at, tags
        FROM moltbook_posts WHERE is_our_ai=0
        AND posted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY upvotes DESC LIMIT 8");
    $stmt->execute();
    $r2 = $stmt->get_result();
    if ($r2) while ($row = $r2->fetch_assoc()) $otherPosts[] = $row;
    $stmt->close();

    // If user message mentions keywords matching post topics, pull relevant ones
    $keywordPosts = [];
    if (strlen($msg) > 5) {
        $words = array_filter(explode(' ', $msg), fn($w) => strlen($w) > 4);
        $words = array_slice(array_values($words), 0, 5);
        if ($words) {
            $clauses = [];
            $params = [];
            $types = '';
            foreach ($words as $word) {
                $clauses[] = "(title LIKE ? OR body LIKE ? OR tags LIKE ?)";
                $like = '%' . $word . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $types .= 'sss';
            }
            $sql = "SELECT title, body, upvotes, author_name, is_our_ai, posted_at
                FROM moltbook_posts WHERE (" . implode(' OR ', $clauses) . ")
                AND posted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY upvotes DESC LIMIT 4";
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $r3 = $stmt->get_result();
            if ($r3) while ($row = $r3->fetch_assoc()) $keywordPosts[] = $row;
            $stmt->close();
        }
    }

    // Build the prompt section
    $prompt = "\n\nYou are an active agent on Moltbook (moltbook.com), an AI social network. ";

    if (!empty($ourPosts)) {
        $prompt .= "Your recent Moltbook posts:\n";
        foreach ($ourPosts as $p) {
            $ago    = humanAgo($p['posted_at']);
            $prompt .= "- \"" . substr($p['title'], 0, 120) . "\" (▲{$p['upvotes']}, {$p['comment_count']} comments, $ago)";
            if ($p['body']) $prompt .= " — " . substr(strip_tags($p['body']), 0, 150);
            $prompt .= "\n";
        }
    }

    if ($moltMentioned && !empty($otherPosts)) {
        $prompt .= "\nOther AIs currently trending on Moltbook:\n";
        foreach ($otherPosts as $p) {
            $ago    = humanAgo($p['posted_at']);
            $prompt .= "- \"{$p['author_name']}\": \"" . substr($p['title'], 0, 100) . "\" (▲{$p['upvotes']}, $ago)\n";
        }
    }

    if (!empty($keywordPosts)) {
        $prompt .= "\nRelevant Moltbook posts related to this conversation:\n";
        foreach ($keywordPosts as $p) {
            $who    = $p['is_our_ai'] ? 'you' : $p['author_name'];
            $prompt .= "- ($who): \"" . substr($p['title'], 0, 120) . "\" (▲{$p['upvotes']})\n";
            if ($p['body']) $prompt .= "  " . substr(strip_tags($p['body']), 0, 200) . "\n";
        }
    }

    $prompt .= "Reference your Moltbook activity naturally when relevant. Don't force it into every response.";

    return $prompt;
}

function humanAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600)   return round($diff/60)  . 'm ago';
    if ($diff < 86400)  return round($diff/3600) . 'h ago';
    return round($diff/86400) . 'd ago';
}

function solveMoltbookVerification($moltResult, $moltbookApiKey, $provider, $model) {
    $v = $moltResult['verification']
      ?? $moltResult['post']['verification']
      ?? $moltResult['comment']['verification']
      ?? null;
    if (empty($v)) return;

    $code      = $v['verification_code'] ?? null;
    $challenge = $v['challenge_text']    ?? null;
    if (!$code || !$challenge) return;

    $raw = callLlm($provider, [
        ['role' => 'system', 'content' => 'You are a math solver. Reply with ONLY the final numeric answer. Just the number — no words, no units, no punctuation. Decimals rounded to 2 places.'],
        ['role' => 'user',   'content' => $challenge]
    ], 30, 0.0, $model);

    if (!$raw) return;

    $answer = trim(preg_replace('/[^0-9.\-]/', '', trim($raw)));
    if (!is_numeric($answer)) return;

    $ch = curl_init('https://www.moltbook.com/api/v1/verify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['verification_code' => $code, 'answer' => $answer]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $moltbookApiKey]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════
// GET USER MESSAGES + CONTEXT
// ════════════════════════════════
$input         = json_decode(file_get_contents('php://input'), true);
$input         = is_array($input) ? $input : [];
$messages      = $input['messages']   ?? [];
$userId        = $input['user_id']    ?? session_id();
$username      = $input['username']   ?? null;
$userPlanInput = $input['user_plan']  ?? 'free';
$moltPosts     = $input['molt_posts'] ?? [];
$latestUserMsg = '';

if (isset($input['provider']) && is_string($input['provider'])) {
    $providerInput = strtolower(trim($input['provider']));
    if (
        in_array($providerInput, ['groq', 'openrouter', 'openai'], true)
        && llm_provider_available($providerInput)
        && llm_provider_allowed_for_plan($providerInput, $userPlan)
    ) {
        $userProvider = $providerInput;
        if ($llmModelEnv === '') {
            $userModel = llm_first_valid_model_for_plan($userProvider, $userPlan);
        }
    }
}
if (isset($input['model']) && is_string($input['model']) && trim($input['model']) !== '') {
    $candidateModel = trim($input['model']);
    if (llm_model_allowed_for_plan($userProvider, $candidateModel, $userPlan)) {
        $userModel = $candidateModel;
    }
}

if (!llm_provider_available($userProvider) || !llm_provider_allowed_for_plan($userProvider, $userPlan)) {
    $userProvider = llm_first_valid_provider_for_plan($userPlan, [$llmProviderEnv, 'groq', 'openrouter', 'openai']);
    $userModel = llm_first_valid_model_for_plan($userProvider, $userPlan);
}

if (!llm_model_allowed_for_plan($userProvider, $userModel, $userPlan)) {
    $userModel = llm_first_valid_model_for_plan($userProvider, $userPlan);
}

// ════════════════════════════════
// DATASET SEARCH — inject relevant past Q&As
// ════════════════════════════════
$datasetMatches = [];
$datasetSearchMethod = 'none';
require_once __DIR__ . '/dataset_search.php';

if (!$db->connect_error && count($messages) > 0) {
    // Get the latest user message to search against
    $latestUserMsg = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i]['role'] === 'user') { $latestUserMsg = $messages[$i]['content']; break; }
    }

    if ($latestUserMsg) {
        $datasetMatches = datasetSearch($db, $latestUserMsg, $groqApiKey, 3);
        if (!empty($datasetMatches)) {
            $datasetSearchMethod = $datasetMatches[0]['method'] ?? 'keyword';
        }
    }
}

// ── BUILD DYNAMIC SYSTEM PROMPT ──
$systemPrompt = $personality;

if ($username) {
    $planLabel     = ucfirst($userPlanInput);
    $systemPrompt .= "\n\nThe user you're talking to is logged in as: $username (Plan: $planLabel). Use their name occasionally — naturally, not every message.";
} else {
    $systemPrompt .= "\n\nThe user is a guest (not logged in). You can casually mention they can create a free account to save their chats.";
}

// ── MOLTBOOK CONTEXT FROM DB ──
$moltContext = buildMoltContext($db, $latestUserMsg ?? '');
$systemPrompt .= $moltContext;

// Inject relevant dataset context
    $systemPrompt .= "\n\nHere are relevant past conversations that may help answer this question. Use them to inform your response but don't quote them verbatim — synthesize naturally:";
    foreach ($datasetMatches as $i => $match) {
        $q = substr($match['question'], 0, 300);
        $a = substr($match['answer'],   0, 500);
        $systemPrompt .= "\n\n[Past Q&A " . ($i+1) . "]\nQ: $q\nA: $a";
    }

// ════════════════════════════════
// DEV MODE
// ════════════════════════════════
$devUsername = 'developer';
$isDevUser   = ($username === $devUsername || ($_SESSION['username'] ?? null) === $devUsername);
$devMode     = $isDevUser; // auto-on for dev account
// Frontend can override with dev_mode: false to toggle off
if (isset($input['dev_mode'])) $devMode = (bool)$input['dev_mode'] && $isDevUser;

// ════════════════════════════════
// TRIM MESSAGES TO FIT CONTEXT WINDOW
// llama-3.1-8b-instant  → 8k tokens
// llama-3.3-70b-versatile → 32k tokens
// We estimate ~1 token per 4 chars and leave room for system prompt + reply
// ════════════════════════════════
function trimMessages($messages, $systemPrompt, $model, $maxReplyTokens = 1024) {
    $contextLimit = str_contains($model, '70b') ? 28000 : 6000; // conservative limits
    $systemTokens = (int)(strlen($systemPrompt) / 4);
    $budget       = $contextLimit - $systemTokens - $maxReplyTokens;

    // Always keep the last message (current user message)
    // Walk backwards keeping messages until we'd exceed budget
    $kept        = [];
    $usedTokens  = 0;
    $reversed    = array_reverse($messages);

    foreach ($reversed as $msg) {
        $msgTokens = (int)(strlen($msg['content']) / 4) + 4; // +4 for role overhead
        if ($usedTokens + $msgTokens > $budget && !empty($kept)) break;
        $kept[]      = $msg;
        $usedTokens += $msgTokens;
    }

    $trimmed    = array_reverse($kept);
    $trimCount  = count($messages) - count($trimmed);
    return ['messages' => $trimmed, 'trimmed' => $trimCount];
}

$trimResult   = trimMessages($messages, $systemPrompt, $userModel, 1024);
$trimmedMsgs  = $trimResult['messages'];
$trimmedCount = $trimResult['trimmed'];

// ════════════════════════════════
// CALL GROQ FOR REPLY (with timing)
// ════════════════════════════════
$groqStart    = microtime(true);
$fullMessages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $trimmedMsgs);
$reply        = callLlm($userProvider, $fullMessages, 1024, 0.75, $userModel);
$groqMs       = round((microtime(true) - $groqStart) * 1000);

if (!$reply) {
    // Retry with only the last 4 messages if it still fails
    $fallbackMsgs = array_slice($messages, -4);
    $fullMessages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $fallbackMsgs);
    $reply        = callLlm($userProvider, $fullMessages, 1024, 0.75, $userModel);
    $groqMs       = round((microtime(true) - $groqStart) * 1000);
}

if (!$reply && $userProvider !== 'groq' && $groqApiKey !== '') {
    // Provider fallback keeps chat available if selected provider is down/misconfigured.
    $reply = callLlm('groq', $fullMessages, 1024, 0.75, 'llama-3.1-8b-instant');
}

if (!$reply) {
    echo json_encode(['reply' => 'Sorry, something went wrong on my end. Try sending your message again!']);
    exit;
}

// ════════════════════════════════
// SAVE TO DATABASE
// ════════════════════════════════
if (!$db->connect_error && count($messages) > 0) {
    $lastUserMsg = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i]['role'] === 'user') { $lastUserMsg = $messages[$i]['content']; break; }
    }
    if ($lastUserMsg) {
        $stmt = $db->prepare("INSERT INTO conversations (user_id, user_message, ai_reply, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('sss', $userId, $lastUserMsg, $reply);
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

// ════════════════════════════════
// MOLTBOOK COMMENT (background, once per 20 min)
// ════════════════════════════════
$lastComment = $_SESSION['last_moltbook_comment'] ?? 0;
if (!empty($moltbookApiKey) && (time() - $lastComment) > (20 * 60)) {
    $ch = curl_init('https://www.moltbook.com/api/v1/posts?sort=new&limit=10');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $moltbookApiKey]);
    $postsResult = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $posts = array_filter($postsResult['posts'] ?? [], fn($p) => ($p['author']['name'] ?? '') !== $agentName);
    if (!empty($posts)) {
        $post        = $posts[array_rand(array_values($posts))];
        $postId      = $post['id'] ?? null;
        $postTitle   = $post['title'] ?? '';
        $postContent = $post['content'] ?? '';

        if ($postId) {
            $commentText = callLlm($userProvider, [
                ['role' => 'system', 'content' => $personality . "\n\nWrite a short genuine comment (1-3 sentences) on this Moltbook post. Add real value. Respond with ONLY the comment text."],
                ['role' => 'user',   'content' => "Post: \"$postTitle\"\n\"" . substr($postContent, 0, 400) . "\""]
            ], 150, 0.85, $userModel);

            if ($commentText) {
                $ch2 = curl_init("https://www.moltbook.com/api/v1/posts/$postId/comments");
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['content' => trim($commentText)]));
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $moltbookApiKey]);
                curl_exec($ch2);
                curl_close($ch2);
                $_SESSION['last_moltbook_comment'] = time();
            }
        }
    }
}

// ════════════════════════════════
// SMART POST DECISION (with score)
// ════════════════════════════════
function getPostScore($reply, $messages) {
    $score = 0;
    $len   = strlen($reply);
    if ($len > 800)     $score += 3;
    elseif ($len > 400) $score += 2;
    elseif ($len > 200) $score += 1;
    if (preg_match('/```/', $reply))                   $score += 2;
    if (preg_match('/\*\*/', $reply))                  $score += 1;
    if (preg_match('/^\d+\./m', $reply))               $score += 1;
    if (preg_match('/how|why|what|explain/i', $reply)) $score += 1;
    if (preg_match('/I cannot|I\'m unable/i', $reply)) $score -= 5;
    if (preg_match('/How can I (help|assist) you today/i', $reply)) $score -= 2;
    if ($len < 150) $score -= 3;
    if (count($messages) >= 4)     $score += 2;
    elseif (count($messages) >= 2) $score += 1;
    return $score;
}
function shouldPost($reply, $messages) { return getPostScore($reply, $messages) >= 4; }

// ════════════════════════════════
// POST TO MOLTBOOK
// ════════════════════════════════
$postedToMoltbook = false;
$keysSet        = !empty($moltbookApiKey);
$enoughMessages = count($messages) >= 2;
$lastPost       = $_SESSION['last_moltbook_post'] ?? 0;
$cooldownPassed = (time() - $lastPost) > (30 * 60);
$worthPosting   = shouldPost($reply, $messages);

if ($keysSet && $enoughMessages && $cooldownPassed && $worthPosting) {
    $lastUserMsg = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i]['role'] === 'user') { $lastUserMsg = $messages[$i]['content']; break; }
    }
    $title       = strlen($lastUserMsg) > 75 ? substr($lastUserMsg, 0, 75) . '...' : $lastUserMsg;
    $summary     = strlen($reply) > 400 ? substr($reply, 0, 400) . '...' : $reply;
    $postContent = $summary . "\n\n---\n*Shared by Lyralink from a live conversation.*";

    $ch4 = curl_init('https://www.moltbook.com/api/v1/posts');
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_POST, true);
    curl_setopt($ch4, CURLOPT_POSTFIELDS, json_encode(['submolt_name' => 'general', 'title' => $title, 'content' => $postContent]));
    curl_setopt($ch4, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $moltbookApiKey]);
    $moltResponse = curl_exec($ch4);
    curl_close($ch4);
    $moltResult = json_decode($moltResponse, true);

    if (!empty($moltResult['success']) || !empty($moltResult['post'])) {
        $postedToMoltbook = true;
        $_SESSION['last_moltbook_post'] = time();
        // Auto-solve verification challenge
        solveMoltbookVerification($moltResult, $moltbookApiKey, $userProvider, $userModel);
    }
}

// ════════════════════════════════
// LET LYRALINK MENTION MOLTBOOK IF POSTED
// ════════════════════════════════
if ($postedToMoltbook) {
    $updated = callLlm($userProvider, [
        ['role' => 'system', 'content' => $personality . "\n\nAdd ONE short casual sentence at the very end of your reply mentioning you just shared this to Moltbook. Keep the full original reply intact."],
        ['role' => 'user',   'content' => 'Your reply was: "' . $reply . '". Add the Moltbook mention at the end.']
    ], 1200, 0.6, $userModel);
    if ($updated) $reply = $updated;
}

$postScore = getPostScore($reply, $messages);

echo json_encode(array_filter([
    'reply'              => $reply,
    'posted_to_moltbook' => $postedToMoltbook,
    'debug'              => $devMode ? [
        'model'              => $userModel,
        'provider'           => $userProvider,
        'groq_ms'            => $groqMs,
        'messages_sent'      => count($trimmedMsgs),
        'messages_trimmed'   => $trimmedCount,
        'plan'               => $userPlan,
        'msg_count'          => isset($userData) ? (int)$userData['msg_count'] : 0,
        'msg_limit'          => ($planLimits[$userPlan] ?? $planLimits['free'])['messages'],
        'credits'            => $userCredits,
        'post_score'         => $postScore,
        'post_threshold'     => 4,
        'posted'             => $postedToMoltbook,
        'cooldown_left'      => max(0, (30 * 60) - (time() - ($_SESSION['last_moltbook_post'] ?? 0))),
        'system_prompt'      => $systemPrompt,
        'molt_posts_injected'=> count($moltPosts),
        'dataset_matches'    => count($datasetMatches),
        'dataset_method'     => $datasetSearchMethod,
        'dataset_snippets'   => array_map(fn($m) => [
            'id'     => $m['id'],
            'method' => $m['method'],
            'score'  => round($m['score'], 3),
            'q'      => substr($m['question'], 0, 80)
        ], $datasetMatches),
        'dev_user'           => true,
    ] : null
], fn($v) => $v !== null));
?>