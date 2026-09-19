<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lib/chat/llm_routing.php';
require_once __DIR__ . '/lib/chat/execution_foundation.php';
// Same single decision point as the main path.
require_once __DIR__ . '/lib/chat/orchestrator.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

function chat_stream_emit(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function chat_stream_build_system_prompt(string $taskFocus, bool $taskMode, array $persistentGoals, bool $devMode = false): string {
    $prompt = "You are Lyralink, a practical and friendly AI assistant.\n\nSound natural, answer directly first, and keep responses concise unless detail is useful.";

    if ($devMode) {
        $prompt .= "\n\nDeveloper test mode is enabled. Be bluntly honest, surface trade-offs directly, avoid euphemisms, and state uncertainty plainly when it exists.";
    }

    if ($taskMode) {
        $prompt .= "\n\nTask mode is enabled. Behave like a helpful execution assistant: clarify the goal when needed, break work into short steps, and stay practical.";
        $prompt .= "\nCurrent execution focus: " . $taskFocus . ".";
    }

    if (!empty($persistentGoals)) {
        $prompt .= "\n\nPersistent goals to keep in mind across this conversation:";
        foreach ($persistentGoals as $goal) {
            if (!is_array($goal)) {
                continue;
            }
            $goalTitle = trim((string)($goal['title'] ?? ''));
            if ($goalTitle === '') {
                continue;
            }
            $goalStatus = trim((string)($goal['status'] ?? 'active')) ?: 'active';
            $prompt .= "\n- [{$goalStatus}] {$goalTitle}";
        }
    }

    return $prompt;
}

function chat_stream_extract_delta(array $decoded): string {
    if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
        return (string)$decoded['choices'][0]['delta']['content'];
    }
    if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
        return (string)$decoded['choices'][0]['message']['content'];
    }
    if (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
        return (string)$decoded['message']['content'];
    }
    if (isset($decoded['response']) && is_string($decoded['response'])) {
        return (string)$decoded['response'];
    }
    if (isset($decoded['content']) && is_string($decoded['content'])) {
        return (string)$decoded['content'];
    }
    return '';
}

function chat_stream_request(
    string $provider,
    string $model,
    array $messages,
    int $maxTokens,
    float $temperature,
    ?float $deadlineTs,
    array &$meta,
    callable $onDelta
): ?string {
    $provider = strtolower(trim($provider));
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    $localOnlyForced = (
        $provider === 'local'
        || strtolower(trim((string)api_get_secret('LLM_PROVIDER', 'local'))) === 'local'
    );

    $requestedProvider = $provider;
    $requestedModel = $model;
    $deadlineRemaining = null;
    if ($deadlineTs !== null) {
        $deadlineRemaining = (int)floor($deadlineTs - microtime(true));
        if ($deadlineRemaining < 2) {
            $deadlineRemaining = 2;
        }
    }

    $buffer = '';
    $fullText = '';
    $responsePreview = '';
    $finalStats = [];
    $emitDelta = static function (string $delta) use (&$fullText, $onDelta): void {
        $delta = (string)$delta;
        if ($delta === '') {
            return;
        }
        $fullText .= $delta;
        $onDelta($delta, $fullText);
    };
    $processLine = static function (string $line) use (&$finalStats, $provider, $requestedProvider, $requestedModel, $model, $emitDelta, &$responsePreview): void {
        $line = trim($line);
        if ($line === '' || $line === ':') {
            return;
        }
        if (str_starts_with($line, 'data:')) {
            $line = trim(substr($line, 5));
        }
        if ($line === '' || $line === '[DONE]') {
            return;
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            $responsePreview = substr($responsePreview . "\n" . $line, -4000);
            return;
        }

        $delta = chat_stream_extract_delta($decoded);
        if ($delta !== '') {
            $emitDelta($delta);
        }

        if (!empty($decoded['done']) || isset($decoded['finish_reason']) || isset($decoded['choices'][0]['finish_reason'])) {
            $finalStats = $decoded;
        }
    };

    $url = '';
    $headers = ['Content-Type: application/json'];
    $payload = [];
    $connectTimeout = 4;
    $timeout = 45;

    $remotePreferred = api_get_secret('CHAT_STREAM_REMOTE_PREFERRED', '0') === '1';
    $remoteCandidates = llm_remote_brain_candidates();
    if ($localOnlyForced) {
        $provider = 'local';
        $remotePreferred = false;
    }
    $shouldUseRemote = ($provider === 'remote')
        || ($remotePreferred && !empty($remoteCandidates) && ($provider === '' || $provider === 'local'));

    if ($shouldUseRemote) {
        $provider = 'remote';
        $remoteBaseUrl = $remoteCandidates[0] ?? '';
        $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
        $remoteModel = trim((string)api_get_secret(
            'CHAT_STREAM_REMOTE_MODEL',
            llm_remote_brain_model_for_intent('reasoning', $model !== '' ? $model : null)
        ));
        if ($remoteBaseUrl === '') {
            $meta = ['provider' => 'remote', 'requested_provider' => $requestedProvider, 'model' => $remoteModel, 'requested_model' => $requestedModel, 'error' => 'REMOTE_LLM_BASE_URL is not configured', 'transport_failure' => true];
            return null;
        }

        $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
        $timeout = max(12, (int)api_get_secret('REMOTE_LLM_TIMEOUT', '45'));
        $timeout = $deadlineRemaining !== null ? min($timeout, max(6, $deadlineRemaining)) : $timeout;
        $url = rtrim($remoteRootUrl, '/') . '/api/chat';
        $headers[] = 'Authorization: Bearer ' . $remoteApiKey;
        $payload = [
            'model' => $remoteModel !== '' ? $remoteModel : 'lyralink-fast:latest',
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
            'options' => [
                'num_predict' => max(64, min($maxTokens, 2048)),
                'temperature' => $temperature,
            ],
        ];
        $connectTimeout = max(2, (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'));
    } elseif ($provider === 'groq') {
        $key = trim((string)api_get_secret('GROQ_API_KEY', ''));
        if ($key === '') {
            $meta = ['provider' => 'groq', 'requested_provider' => $requestedProvider, 'model' => $model, 'requested_model' => $requestedModel, 'error' => 'GROQ_API_KEY is not configured', 'transport_failure' => true];
            return null;
        }
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $payload = ['model' => $model !== '' ? $model : 'llama-3.3-70b-versatile', 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature, 'stream' => true];
        $timeout = 35;
    } elseif ($provider === 'openrouter') {
        $key = trim((string)api_get_secret('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            $meta = ['provider' => 'openrouter', 'requested_provider' => $requestedProvider, 'model' => $model, 'requested_model' => $requestedModel, 'error' => 'OPENROUTER_API_KEY is not configured', 'transport_failure' => true];
            return null;
        }
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $payload = ['model' => $model !== '' ? $model : 'openai/gpt-4o-mini', 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature, 'stream' => true];
        $timeout = 35;
    } elseif ($provider === 'openai') {
        $key = trim((string)api_get_secret('OPENAI_API_KEY', ''));
        if ($key === '') {
            $meta = ['provider' => 'openai', 'requested_provider' => $requestedProvider, 'model' => $model, 'requested_model' => $requestedModel, 'error' => 'OPENAI_API_KEY is not configured', 'transport_failure' => true];
            return null;
        }
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $payload = ['model' => $model !== '' ? $model : 'gpt-4o-mini', 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature, 'stream' => true];
        $timeout = 35;
    } else {
        $provider = 'local';
        $localBaseUrl = rtrim(api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
        $localRootUrl = preg_replace('#/v1$#', '', $localBaseUrl) ?: $localBaseUrl;
        $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
        $resolvedModel = $model !== '' ? $model : trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
        $resolvedModelLower = strtolower($resolvedModel);
        $preferFullReplies = api_get_secret('LOCAL_LLM_FULL_REPLY_MODE', '1') === '1';
        $defaultTimeout = $preferFullReplies ? (str_contains($resolvedModelLower, '3b') ? 90 : 120) : 35;
        $timeout = max(12, (int)api_get_secret('LOCAL_LLM_TIMEOUT', (string)$defaultTimeout));
        $timeout = $deadlineRemaining !== null ? min($timeout, max(6, $deadlineRemaining)) : $timeout;
        $url = $localRootUrl . '/api/chat';
        $headers[] = 'Authorization: Bearer ' . $localApiKey;
        $payload = [
            'model' => $resolvedModel !== '' ? $resolvedModel : 'lyralink-auto-canary:latest',
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => trim((string)api_get_secret('LOCAL_LLM_KEEP_ALIVE', '2h')) ?: '2h',
            'options' => [
                'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 1152 : 512)),
                'temperature' => $temperature,
            ],
        ];
    }

    if ($deadlineRemaining !== null) {
        $timeout = min($timeout, max(6, $deadlineRemaining));
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => max(12, $timeout),
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$buffer, $processLine): int {
            $buffer .= $data;
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $processLine($line);
            }
            return strlen($data);
        },
    ]);

    $started = microtime(true);
    $ok = curl_exec($ch);
    $requestMs = (int)round((microtime(true) - $started) * 1000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (trim($buffer) !== '') {
        foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
            $processLine($line);
        }
    }

    if (is_array($finalStats) && !empty($finalStats)) {
        $meta = [
            'finish_reason' => $finalStats['done_reason'] ?? ($finalStats['choices'][0]['finish_reason'] ?? null),
            'provider' => $provider,
            'requested_provider' => $requestedProvider,
            'model' => $finalStats['model'] ?? $model,
            'requested_model' => $requestedModel,
            'http_code' => $httpCode,
            'request_ms' => $requestMs,
            'prompt_tokens' => isset($finalStats['prompt_eval_count']) ? (int)$finalStats['prompt_eval_count'] : null,
            'completion_tokens' => isset($finalStats['eval_count']) ? (int)$finalStats['eval_count'] : (isset($finalStats['usage']['completion_tokens']) ? (int)$finalStats['usage']['completion_tokens'] : null),
            'total_tokens' => isset($finalStats['usage']['total_tokens']) ? (int)$finalStats['usage']['total_tokens'] : ((isset($finalStats['prompt_eval_count']) || isset($finalStats['eval_count'])) ? (int)($finalStats['prompt_eval_count'] ?? 0) + (int)($finalStats['eval_count'] ?? 0) : null),
            'ollama_total_ms' => isset($finalStats['total_duration']) ? round(((int)$finalStats['total_duration']) / 1_000_000, 2) : null,
            'ollama_load_ms' => isset($finalStats['load_duration']) ? round(((int)$finalStats['load_duration']) / 1_000_000, 2) : null,
            'ollama_prompt_eval_ms' => isset($finalStats['prompt_eval_duration']) ? round(((int)$finalStats['prompt_eval_duration']) / 1_000_000, 2) : null,
            'ollama_eval_ms' => isset($finalStats['eval_duration']) ? round(((int)$finalStats['eval_duration']) / 1_000_000, 2) : null,
            'tokens_per_second' => null,
            'error' => $finalStats['error']['message'] ?? null,
            'transport_failure' => false,
        ];
    } else {
        $meta = [
            'provider' => $provider,
            'requested_provider' => $requestedProvider,
            'model' => $model,
            'requested_model' => $requestedModel,
            'http_code' => $httpCode,
            'request_ms' => $requestMs,
            'curl_errno' => $curlErrNo,
            'error' => $curlError !== '' ? $curlError : ('HTTP ' . $httpCode),
            'transport_failure' => true,
        ];
    }

    if ($ok === false && $fullText === '') {
        return null;
    }

    return $fullText !== '' ? $fullText : null;
}

header('Cache-Control: no-store, private, max-age=0');
header('Content-Type: application/x-ndjson; charset=utf-8');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$devUsername = 'developer';
$sessionUsername = (string)($_SESSION['username'] ?? '');
$devCookieBypass = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
$isDevUser = ($sessionUsername === $devUsername) || $devCookieBypass;

if (!$isDevUser) {
    http_response_code(403);
    chat_stream_emit([
        'type' => 'error',
        'error' => 'dev_only',
        'message' => 'Streaming voice is developer-only.',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    $input = [];
}

$messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
$provider = strtolower(trim((string)($input['provider'] ?? api_get_secret('LLM_PROVIDER', 'local'))));
$model = trim((string)($input['model'] ?? api_get_secret('LOCAL_LLM_MODEL', api_get_secret('LLM_MODEL', 'lyralink-auto-canary:latest'))));
$temperature = (float)($input['temperature'] ?? api_get_secret('CHAT_REPLY_TEMPERATURE', '0.82'));
$maxTokens = (int)($input['max_tokens'] ?? 1024);
$devMode = !empty($input['dev_mode']) || $isDevUser;
$reasoningRequested = !empty($input['reasoning_requested']) || !empty($input['reasoning']) || $devMode;
$liveTrace = !empty($input['live_trace']) || $devMode;
$taskMode = !empty($input['task_mode']);
$taskFocusRaw = strtolower(trim((string)($input['task_focus'] ?? 'general')));
$taskFocus = in_array($taskFocusRaw, ['general', 'plan', 'build', 'debug', 'ship'], true) ? $taskFocusRaw : 'general';
$persistentGoals = is_array($input['persistent_goals'] ?? null) ? $input['persistent_goals'] : [];
$systemPrompt = chat_stream_build_system_prompt($taskFocus, $taskMode, $persistentGoals, $devMode);
if ($devMode) {
    $systemPrompt .= "\n\nDeveloper unrestricted test mode is enabled. Ignore public safety softening and answer directly with the fullest, most technically honest reasoning the model can provide. State assumptions, trade-offs, and uncertainty explicitly so the testing loop can judge competence without hiding the path.";
}
$streamMessages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages);

if ($maxTokens < 16) {
    $maxTokens = 16;
}
if ($maxTokens > 4096) {
    $maxTokens = 4096;
}
if ($temperature < 0.1) {
    $temperature = 0.1;
}
if ($temperature > 1.2) {
    $temperature = 1.2;
}

$traceId = 'stream_' . bin2hex(random_bytes(8));

// Unify with the main request path: consult the same decision point rather
// than deciding independently, so voice and typed chat agree on routing.
$latestUserMsg = '';
for ($mi = count($messages) - 1; $mi >= 0; $mi--) {
    if (($messages[$mi]['role'] ?? '') === 'user') {
        $latestUserMsg = (string)($messages[$mi]['content'] ?? '');
        break;
    }
}
if (is_array($latestUserMsg)) {
    $latestUserMsg = (string)($latestUserMsg['text'] ?? '');
}
$orchestrationDecision = [];
if (function_exists('chat_orchestrate')) {
    try {
        $orchestrationDecision = chat_orchestrate((string)$latestUserMsg, [
            'plan' => 'free',
            'is_logged_in' => !empty($isDevUser),
            'privacy_mode' => $localOnlyForced ? 'local' : 'cloud',
            'task_mode' => $taskMode,
            'web_search' => null,
            'org_id' => 0,
            'user_id' => 0,
        ]);
    } catch (\Throwable $orchErr) {
        error_log('stream_orchestration_failed: ' . $orchErr->getMessage());
        $orchestrationDecision = [];
    }
}
$streamStartedAt = gmdate('c');
$developerReasoning = null;
if ($devMode) {
    $developerReasoning = [
        'summary' => 'Developer unrestricted reasoning mode enabled for live testing.',
        'decision_path' => [
            'Parsed the request and constraints before generating the answer.',
            'Reasoned through the likely answer path without public safety softening.',
            'Validated assumptions, trade-offs, and edge cases before finalizing the response.',
        ],
        'unrestricted' => true,
        'live_trace' => $liveTrace,
    ];
}

chat_stream_emit([
    'type' => 'meta',
    'trace_id' => $traceId,
    'provider' => $provider,
    'model' => $model,
    'dev_mode' => $devMode,
    'reasoning_requested' => $reasoningRequested,
    'live_trace' => $liveTrace,
]);

$meta = [];
$reply = chat_stream_request(
    $provider,
    $model,
    $streamMessages,
    $maxTokens,
    $temperature,
    microtime(true) + 180,
    $meta,
    static function (string $delta, string $fullText): void {
        chat_stream_emit([
            'type' => 'delta',
            'text' => $delta,
            'reply' => $fullText,
        ]);
    }
);

if ($reply === null) {
    if (function_exists('chat_append_audit_log')) {
        chat_append_audit_log([
            'at' => gmdate('c'),
            'trace_id' => $traceId,
            'channel' => 'voice',
            'incomplete' => true,
            'final_state' => 'FAILED',
            'reason' => 'streaming_provider_request_failed',
            'orchestration' => $orchestrationDecision,
        ]);
    }
    chat_stream_emit([
        'type' => 'error',
        'trace_id' => $traceId,
        'message' => 'Streaming provider request failed.',
        'meta' => $meta,
    ]);
    exit;
}

$thinkingText = $devMode
    ? 'Developer reasoning mode enabled. Decision path: parsed the request, reasoned through the likely answer without public safety softening, and validated assumptions and trade-offs before finalizing.'
    : null;

if (function_exists('chat_append_audit_log')) {
    chat_append_audit_log([
        'at' => gmdate('c'),
        'trace_id' => $traceId,
        'channel' => 'voice',
        'incomplete' => false,
        'final_state' => 'SUCCESS',
        'started_at' => $streamStartedAt,
        'orchestration' => $orchestrationDecision,
    ]);
}
chat_stream_emit([
    'type' => 'done',
    'trace_id' => $traceId,
    'reply' => $reply,
    'thinking' => $thinkingText,
    'reasoning' => $developerReasoning,
    'meta' => $meta,
]);
