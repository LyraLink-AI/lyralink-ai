<?php

require_once __DIR__ . '/llm_capacity.php';

function chat_db_table_exists(mysqli $db, string $table): bool {
    static $cache = [];
    if ($db->connect_error) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) {
        $cache[$table] = false;
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cache[$table] = ((int)($row['c'] ?? 0)) > 0;
    return $cache[$table];
}

function chat_service_runtime_state(mysqli $db, string $slug): array {
    $state = [
        'slug' => $slug,
        'service_status' => 'operational',
        'slow_streak' => 0,
        'probe_status' => 'operational',
        'last_latency_ms' => null,
        'last_http_code' => null,
        'degraded_mode' => false,
    ];

    if ($db->connect_error) {
        return $state;
    }

    if (chat_db_table_exists($db, 'status_services')) {
        $stmt = $db->prepare("SELECT status FROM status_services WHERE slug = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['status'])) {
                $state['service_status'] = (string)$row['status'];
            }
        }
    }

    if (chat_db_table_exists($db, 'maintenance_probe_state')) {
        $stmt = $db->prepare("SELECT slow_streak, last_effective_status, last_latency_ms, last_http_code FROM maintenance_probe_state WHERE service_slug = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $state['slow_streak'] = (int)($row['slow_streak'] ?? 0);
                $state['probe_status'] = (string)($row['last_effective_status'] ?? 'operational');
                $state['last_latency_ms'] = isset($row['last_latency_ms']) ? (int)$row['last_latency_ms'] : null;
                $state['last_http_code'] = isset($row['last_http_code']) ? (int)$row['last_http_code'] : null;
            }
        }
    }

    $state['degraded_mode'] = in_array($state['service_status'], ['degraded', 'partial_outage', 'major_outage', 'maintenance'], true)
        || in_array($state['probe_status'], ['partial_outage', 'major_outage'], true)
        || ($state['probe_status'] === 'degraded' && (int)$state['slow_streak'] >= 2);

    return $state;
}

function chat_local_runtime_health(): array {
    $localBaseUrl = rtrim(api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRootUrl = preg_replace('#/v1$#', '', $localBaseUrl) ?: $localBaseUrl;
    $configuredModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest')) ?: 'lyralink-auto-canary:latest';

    $ch = curl_init($localRootUrl . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPGET => true,
    ]);
    $started = microtime(true);
    $body = curl_exec($ch);
    $requestMs = (int)round((microtime(true) - $started) * 1000);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $models = [];
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded['models'] ?? null)) {
            $models = array_values(array_filter(array_map(
                static fn(array $model): string => (string)($model['name'] ?? ''),
                $decoded['models']
            )));
        }
    }

    return [
        'ok' => $body !== false && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'request_ms' => $requestMs,
        'error' => $err !== '' ? $err : null,
        'configured_model' => $configuredModel,
        'model_available' => in_array($configuredModel, $models, true),
        'loaded_models' => $models,
    ];
}

/**
 * Probe the remote (GPU) runtime and cache the verdict briefly.
 *
 * The local equivalent, chat_local_runtime_health(), already existed; remote
 * had nothing. Without it the capacity gate could only see how many remote
 * slots were BUSY, which means a dead GPU looked idle and was still chosen.
 * Every request routed to it then paid a full connect timeout before falling
 * back, which is the exact wasted-timeout cost the gate exists to remove.
 *
 * This matters more than usual here because the GPU is a spot instance reached
 * over a hand-established SSH tunnel, so it can disappear without warning in
 * ways that leave the local port looking fine.
 *
 * Caching rationale: probing on every request would add a round trip to the
 * happy path, and cost the entire timeout on every request during an outage.
 * So a healthy verdict is cached briefly (okTtl) and an unhealthy one for
 * longer (failTtl), keeping both the steady state and an outage cheap.
 *
 * @return array{ok:bool,url:?string,http_code:int,request_ms:int,error:?string,cached:bool}
 */
function chat_remote_runtime_health(
    array $candidates,
    int $connectTimeout = 2,
    int $totalTimeout = 4,
    int $okTtl = 5,
    int $failTtl = 20
): array {
    $unhealthy = [
        'ok' => false, 'url' => null, 'http_code' => 0,
        'request_ms' => 0, 'error' => null, 'cached' => false,
    ];

    if (empty($candidates)) {
        $unhealthy['error'] = 'no remote candidates configured';
        return $unhealthy;
    }

    if (llm_capacity_secret('REMOTE_LLM_HEALTH_PROBE', '1') !== '1') {
        /* Probe disabled: report healthy so routing is unchanged. Failing open
         * is the correct default, since refusing here would remove the paid
         * path entirely if this one setting were misconfigured. */
        return ['ok' => true, 'url' => null, 'http_code' => 0,
                'request_ms' => 0, 'error' => null, 'cached' => false];
    }

    $cacheFile = llm_capacity_state_dir() . '/remote_health.json';
    $now = microtime(true);

    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($cached) && isset($cached['ts'], $cached['ok'])) {
            $ttl = $cached['ok'] ? $okTtl : $failTtl;
            if (($now - (float)$cached['ts']) < $ttl) {
                $cached['cached'] = true;
                return $cached;
            }
        }
    }

    $result = $unhealthy;
    $timeout = max(1, min($totalTimeout, (int)llm_capacity_secret('REMOTE_LLM_HEALTH_TIMEOUT', (string)$totalTimeout)));

    foreach ($candidates as $candidate) {
        $root = preg_replace('#/v1$#', '', (string)$candidate) ?: (string)$candidate;
        $ch = curl_init(rtrim($root, '/') . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_HTTPGET => true,
        ]);
        $started = microtime(true);
        $body = curl_exec($ch);
        $requestMs = (int)round((microtime(true) - $started) * 1000);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
            $result = [
                'ok' => true, 'url' => $root, 'http_code' => $httpCode,
                'request_ms' => $requestMs, 'error' => null, 'cached' => false,
            ];
            break;
        }

        /* Record the last failure so the log says why, then try the next. */
        $result = [
            'ok' => false, 'url' => $root, 'http_code' => $httpCode,
            'request_ms' => $requestMs,
            'error' => $err !== '' ? $err : ('http ' . $httpCode),
            'cached' => false,
        ];
    }

    $result['ts'] = $now;
    $dir = llm_capacity_state_dir();
    if (llm_capacity_ensure_dir($dir)) {
        @file_put_contents(
            $cacheFile,
            json_encode($result, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    return $result;
}

function chat_transform_messages_for_ollama(array $messages): array {
    $normalized = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = (string)($message['role'] ?? 'user');
        $content = $message['content'] ?? '';
        if (!is_array($content)) {
            $normalized[] = [
                'role' => $role,
                'content' => is_string($content) ? $content : '',
            ];
            continue;
        }

        $textParts = [];
        $images = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = strtolower((string)($part['type'] ?? ''));
            if ($type === 'text') {
                $textParts[] = (string)($part['text'] ?? '');
                continue;
            }
            if ($type === 'image_url') {
                $url = (string)($part['image_url']['url'] ?? '');
                if (preg_match('#^data:[^;]+;base64,(.+)$#', $url, $matches) === 1 && !empty($matches[1])) {
                    $images[] = $matches[1];
                }
            }
        }

        $entry = [
            'role' => $role,
            'content' => trim(implode("\n\n", array_filter($textParts, static fn($value) => trim((string)$value) !== ''))),
        ];
        if (!empty($images)) {
            $entry['images'] = $images;
        }
        $normalized[] = $entry;
    }
    return $normalized;
}

function chat_ollama_messages_include_images(array $messages): bool {
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        if (!empty($message['images']) && is_array($message['images'])) {
            return true;
        }
    }
    return false;
}

function chat_chunk_stream_delta(string $delta): array {
    $text = (string)$delta;
    if ($text === '' || trim($text) === '') {
        return [];
    }
    // Preserve model token spacing exactly as emitted; any normalization can
    // merge words across token boundaries in certain model/runtime paths.
    return [$text];
}

function chat_local_runtime_probe_ms(string $localRootUrl, int $timeoutSeconds = 2): ?int {
    $ch = curl_init(rtrim($localRootUrl, '/') . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => max(1, min(2, $timeoutSeconds)),
        CURLOPT_HTTPGET => true,
    ]);
    $started = microtime(true);
    $body = curl_exec($ch);
    $requestMs = (int)round((microtime(true) - $started) * 1000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }
    return $requestMs;
}

/**
 * Load a local model into memory before the runtime is measured.
 *
 * The hourly continuous-learning rebuild replaces the model files, which drops
 * the resident copy, so the next request pays a full cold load. A cold load
 * also makes /api/tags slow, so the routing probe read it as "this box is
 * busy" and sent short requests to the PAID remote provider. That is backwards:
 * a cold model cost money to avoid. Loading first means the probe measures
 * steady state instead of a load in progress.
 *
 * Mirrors the /api/chat shape used for real inference so the runtime treats it
 * as an ordinary load request. Returns true only when the runtime answered.
 */
function chat_local_model_warmup(string $localRootUrl, string $model, int $timeoutSeconds = 20): bool {
    $model = trim($model);
    if ($model === "") {
        return false;
    }

    $ch = curl_init(rtrim($localRootUrl, "/") . "/api/chat");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => max(1, min(5, $timeoutSeconds)),
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode([
            "model" => $model,
            "messages" => [["role" => "user", "content" => "hi"]],
            "stream" => false,
            "keep_alive" => trim((string)api_get_secret("LOCAL_LLM_KEEP_ALIVE", "2h")) ?: "2h",
            "options" => ["num_predict" => 1, "temperature" => 0],
        ]),
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) && !isset($decoded["error"]);
}

function chat_runtime_is_benchmark_mode(): bool {
    return !empty($GLOBALS['chat_benchmark_mode']);
}

function chat_runtime_timeout_override_seconds(): ?int {
    if (!isset($GLOBALS['chat_runtime_timeout_override'])) {
        return null;
    }
    $value = (int)$GLOBALS['chat_runtime_timeout_override'];
    if ($value <= 0) {
        return null;
    }
    return max(12, min(240, $value));
}

if (!function_exists('chat_estimate_prompt_tokens')) {
    /**
     * Conservative (over-estimating) token count for a message array, so a
     * size-driven timeout errs toward waiting rather than aborting.
     */
    function chat_estimate_prompt_tokens(array $messages): int {
        $chars = 0;
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $content = $m['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $chars += strlen((string)$content);
        }
        return max(1, (int)ceil($chars / 4));
    }
}

if (!function_exists('chat_adaptive_local_timeout')) {
    /**
     * Size a local inference timeout to the prompt instead of a fixed floor.
     *
     * A large prompt must finish prefill before the first token is emitted. If
     * the timeout is below that prefill time the request is guaranteed to
     * abort, and the caller then retries with conversation history stripped.
     *
     * Returns null when disabled or misconfigured, so the caller keeps the
     * historical fixed behaviour exactly.
     */
    function chat_adaptive_local_timeout(array $messages, int $maxTokens, int $currentTimeout): ?int {
        if ((string)api_get_secret('LLM_ADAPTIVE_TIMEOUT', '0') !== '1') {
            return null;
        }
        $prefillTokS = (float)api_get_secret('LLM_PREFILL_TOK_S', '75');
        $genTokS     = (float)api_get_secret('LLM_GEN_TOK_S', '29');
        $overhead    = (float)api_get_secret('LLM_ADAPTIVE_OVERHEAD_SECONDS', '6');
        $cap         = (int)api_get_secret('LLM_ADAPTIVE_MAX_SECONDS', '120');
        if ($prefillTokS <= 0.0 || $genTokS <= 0.0 || $cap < 10) {
            return null;
        }
        $promptTokens = chat_estimate_prompt_tokens($messages);
        $needed = (int)ceil(($promptTokens / $prefillTokS) + ($maxTokens / $genTokS) + $overhead);
        return max(1, min($cap, max($currentTimeout, $needed)));
    }
}

function chat_local_stream_request(array $messages, int $maxTokens, float $temperature, string $model = 'lyralink-auto-canary:latest', ?array &$meta = null, ?float $deadlineTs = null, ?callable $onDelta = null): string|false {
    $localBaseUrl = rtrim(api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRootUrl = preg_replace('#/v1$#', '', $localBaseUrl) ?: $localBaseUrl;
    $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
    $resolvedModel = llm_safe_local_model(
        $model !== '' ? $model : ($localModel !== '' ? $localModel : 'lyralink-auto-canary:latest'),
        llm_default_model('local')
    );
    $ollamaMessages = chat_transform_messages_for_ollama($messages);
    $hasImages = chat_ollama_messages_include_images($ollamaMessages);
    $preferFullReplies = api_get_secret('LOCAL_LLM_FULL_REPLY_MODE', '1') === '1';
    $resolvedModelLower = strtolower($resolvedModel);
    $streamUltraFastTransport = api_get_secret('CHAT_STREAM_ULTRA_FAST', '1') === '1'
        && !$hasImages
        && !str_contains($resolvedModelLower, 'reasoning');
    $minimumStreamTimeout = $streamUltraFastTransport ? 6 : 12;
    $defaultLocalTimeout = 35;
    if ($preferFullReplies) {
        $defaultLocalTimeout = str_contains($resolvedModelLower, '3b') ? 90 : 120;
    } elseif (str_contains($resolvedModelLower, '8b')) {
        $defaultLocalTimeout = 45;
    }
    $localTimeout = max($minimumStreamTimeout, (int)api_get_secret('LOCAL_LLM_TIMEOUT', (string)$defaultLocalTimeout));
    $localFallbackTimeout = max($minimumStreamTimeout, (int)api_get_secret('LOCAL_LLM_FALLBACK_TIMEOUT', $preferFullReplies ? '25' : '10'));
    $deepTokenThreshold = max(128, (int)api_get_secret('LOCAL_LLM_DEEP_TOKEN_THRESHOLD', '420'));
    $balancedTokenThreshold = max(80, (int)api_get_secret('LOCAL_LLM_BALANCED_TOKEN_THRESHOLD', '220'));
    $fastTimeout = max($minimumStreamTimeout, (int)api_get_secret('LOCAL_LLM_FAST_TIMEOUT', '16'));
    $balancedTimeout = max($fastTimeout, (int)api_get_secret('LOCAL_LLM_BALANCED_TIMEOUT', '24'));
    $deepTimeout = max($balancedTimeout, (int)api_get_secret('LOCAL_LLM_DEEP_TIMEOUT', (string)$localTimeout));
    $imageTimeout = max($deepTimeout, (int)api_get_secret('LOCAL_LLM_IMAGE_TIMEOUT', '120'));
    $imageFallbackTimeout = max(30, min($imageTimeout, (int)api_get_secret('LOCAL_LLM_IMAGE_FALLBACK_TIMEOUT', '75')));

    if ($hasImages) {
        $localTimeout = max($localTimeout, $imageTimeout);
        $localFallbackTimeout = max($localFallbackTimeout, $imageFallbackTimeout);
    } elseif ($maxTokens >= $deepTokenThreshold) {
        $localTimeout = max($localTimeout, $deepTimeout);
    } elseif ($maxTokens >= $balancedTokenThreshold) {
        $localTimeout = min($localTimeout, $balancedTimeout);
    } else {
        $localTimeout = min($localTimeout, $fastTimeout);
    }

    if ($deadlineTs !== null) {
        $budgetTimeout = max($minimumStreamTimeout, (int)floor($deadlineTs - microtime(true)));
        $isShortFastRequest = $maxTokens < $balancedTokenThreshold && !str_contains(strtolower($resolvedModel), 'reasoning');

        if ($hasImages) {
            $localTimeout = max($localTimeout, min($budgetTimeout, $imageTimeout));
            $localFallbackTimeout = max(30, min($localFallbackTimeout, max(30, $budgetTimeout - 5)));
        } elseif ($isShortFastRequest) {
            $localTimeout = min($localTimeout, $budgetTimeout);
            $localFallbackTimeout = min($localFallbackTimeout, max($minimumStreamTimeout, $budgetTimeout - 1));
        } else {
            $sizeFloor = chat_adaptive_local_timeout($ollamaMessages, $maxTokens, $localTimeout) ?? 18;
            $localTimeout = max($localTimeout, min($budgetTimeout, max($sizeFloor, (int)floor($localTimeout * 0.8))));
            $localFallbackTimeout = max($minimumStreamTimeout, min($localFallbackTimeout, max($minimumStreamTimeout, (int)floor($localTimeout * 0.7))));
        }
    }

    if ($streamUltraFastTransport) {
        $localTimeout = min($localTimeout, max($minimumStreamTimeout, (int)api_get_secret('CHAT_STREAM_FAST_TIMEOUT', '8')));
        $localFallbackTimeout = min($localFallbackTimeout, max($minimumStreamTimeout, (int)api_get_secret('CHAT_STREAM_FAST_FALLBACK_TIMEOUT', '6')));
    }

    $runtimeTimeoutOverride = chat_runtime_timeout_override_seconds();
    if ($runtimeTimeoutOverride !== null) {
        $localTimeout = max($localTimeout, $runtimeTimeoutOverride);
        $localFallbackTimeout = max($localFallbackTimeout, max($minimumStreamTimeout, $runtimeTimeoutOverride - 5));
    }

    $localFallbackTimeout = $hasImages
        ? max(30, min($localFallbackTimeout, max(30, (int)floor($localTimeout * 0.8))))
        : max($minimumStreamTimeout, min($localFallbackTimeout, max($minimumStreamTimeout, (int)floor($localTimeout * 0.7))));

    $payload = [
        'model' => $resolvedModel,
        'messages' => $ollamaMessages,
        'stream' => true,
        'keep_alive' => trim((string)api_get_secret('LOCAL_LLM_KEEP_ALIVE', '2h')) ?: '2h',
        'options' => [
            'num_predict' => max(64, min($maxTokens, $hasImages ? max(192, min($maxTokens, (int)api_get_secret('LOCAL_LLM_IMAGE_MAX_TOKENS', '900'))) : (str_contains($resolvedModelLower, '3b') ? 256 : ($preferFullReplies ? 1152 : 512)))),
            'temperature' => $temperature,
        ],
    ];

    $reply = '';
    $buffer = '';
    $finalChunk = [];
    $ch = curl_init($localRootUrl . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => chat_ollama_headers($localApiKey),
        CURLOPT_CONNECTTIMEOUT => (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
        CURLOPT_TIMEOUT => $localTimeout,
    ]);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function($ch, string $rawChunk) use (&$buffer, &$reply, &$finalChunk, $onDelta): int {
        $buffer .= $rawChunk;
        while (($newlinePos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $newlinePos));
            $buffer = substr($buffer, $newlinePos + 1);
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line === '' || $line === '[DONE]') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $delta = '';
            if (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
                $delta = $decoded['message']['content'];
            } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
                $delta = $decoded['response'];
            }
            if ($delta !== '') {
                foreach (chat_chunk_stream_delta($delta) as $deltaChunk) {
                    $reply .= $deltaChunk;
                    if (is_callable($onDelta)) {
                        $onDelta($deltaChunk);
                    }
                }
            }
            if (!empty($decoded['done'])) {
                $finalChunk = $decoded;
            }
        }
        return strlen($rawChunk);
    });

    $started = microtime(true);
    $response = curl_exec($ch);
    $requestMs = (int)round((microtime(true) - $started) * 1000);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($buffer !== '') {
        $line = trim($buffer);
        if ($line !== '' && !str_starts_with($line, ':')) {
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line !== '' && $line !== '[DONE]') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $delta = '';
                    if (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
                        $delta = $decoded['message']['content'];
                    } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
                        $delta = $decoded['response'];
                    }
                    if ($delta !== '') {
                        foreach (chat_chunk_stream_delta($delta) as $chunk) {
                            $reply .= $chunk;
                            if (is_callable($onDelta)) {
                                $onDelta($chunk);
                            }
                        }
                    }
                    if (!empty($decoded['done'])) {
                        $finalChunk = $decoded;
                    }
                }
            }
        }
    }

    if ($response === false || $curlErrNo !== 0 || $httpCode < 200 || $httpCode >= 300) {
        if ($meta !== null) {
            $timedOut = $curlErrNo === CURLE_OPERATION_TIMEDOUT;
            $meta = [
                'provider' => 'local',
                'requested_provider' => 'local',
                'model' => $resolvedModel,
                'requested_model' => $model,
                'http_code' => $httpCode,
                'request_ms' => $requestMs,
                'curl_errno' => $curlErrNo,
                'error' => $curlError !== '' ? $curlError : ('HTTP ' . $httpCode),
                'transport_failure' => true,
                'timed_out' => $timedOut,
                'failure_status' => $timedOut ? 'TIMED_OUT' : 'FAILED',
            ];
        }
        return false;
    }

    $promptTokens = $finalChunk['prompt_eval_count'] ?? ($finalChunk['prompt_tokens'] ?? null);
    $completionTokens = $finalChunk['eval_count'] ?? ($finalChunk['completion_tokens'] ?? null);
    $totalTokens = $finalChunk['total_tokens'] ?? null;
    if ($totalTokens === null && ($promptTokens !== null || $completionTokens !== null)) {
        $totalTokens = (int)($promptTokens ?? 0) + (int)$completionTokens;
    }
    $evalNs = isset($finalChunk['eval_duration']) ? (int)$finalChunk['eval_duration'] : null;
    $tokensPerSecond = null;
    if ($evalNs && $completionTokens !== null && $evalNs > 0) {
        $tokensPerSecond = round((int)$completionTokens / ($evalNs / 1_000_000_000), 2);
    }

    if ($meta !== null) {
        $meta = [
            'finish_reason' => $finalChunk['done_reason'] ?? null,
            'provider' => 'local',
            'requested_provider' => 'local',
            'model' => $finalChunk['model'] ?? $resolvedModel,
            'requested_model' => $model,
            'http_code' => $httpCode,
            'request_ms' => $requestMs,
            'prompt_tokens' => $promptTokens !== null ? (int)$promptTokens : null,
            'completion_tokens' => $completionTokens !== null ? (int)$completionTokens : null,
            'total_tokens' => $totalTokens !== null ? (int)$totalTokens : null,
            'ollama_total_ms' => isset($finalChunk['total_duration']) ? round(((int)$finalChunk['total_duration']) / 1_000_000, 2) : null,
            'ollama_load_ms' => isset($finalChunk['load_duration']) ? round(((int)$finalChunk['load_duration']) / 1_000_000, 2) : null,
            'ollama_prompt_eval_ms' => isset($finalChunk['prompt_eval_duration']) ? round(((int)$finalChunk['prompt_eval_duration']) / 1_000_000, 2) : null,
            'ollama_eval_ms' => $evalNs !== null ? round($evalNs / 1_000_000, 2) : null,
            'tokens_per_second' => $tokensPerSecond,
            'error' => $finalChunk['error']['message'] ?? null,
            'transport_failure' => false,
        ];
    }

    $hasContent = trim($reply) !== '';
    if (!$hasContent && $meta !== null) {
        $meta['empty_output'] = true;
        $meta['failure_status'] = 'EMPTY_OUTPUT';
        // Never replace a specific upstream cause with the generic sentence.
        // An HTTP 200 carrying an error body (model not loaded, out of
        // memory, generation aborted) is an upstream fault, not the model
        // electing to return nothing, and it must stay legible in telemetry.
        $specificUpstreamError = trim((string)($meta['error'] ?? ''));
        if ($specificUpstreamError !== '') {
            $meta['failure_status'] = 'UPSTREAM_ERROR';
        } else {
            $meta['error'] = 'Provider returned an empty response';
        }
    }

    return $hasContent ? $reply : false;
}

function chat_provider_stream_request(array $messages, int $maxTokens, float $temperature, string $provider, string $model = '', ?array &$meta = null, ?float $deadlineTs = null, ?callable $onDelta = null): string|false {
    $provider = strtolower(trim($provider));
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    if (in_array($provider, ['local', 'hermes'], true)) {
        /* Local streaming bypasses $doRequest, so take the local slot here
         * or local load would be undercounted and the routing gate above
         * would believe the CPU was idle while a long generation ran. */
        $capacityLocalSlot = llm_capacity_acquire('local');
        try {
            return chat_local_stream_request($messages, $maxTokens, $temperature, $model, $meta, $deadlineTs, $onDelta);
        } finally {
            llm_capacity_release($capacityLocalSlot['handle'] ?? null);
        }
    }

    $resolvedModel = trim((string)$model);
    $key = '';
    $url = '';
    $headers = ['Content-Type: application/json'];
    $requestTimeout = 35;
    if ($deadlineTs !== null) {
        $requestTimeout = min($requestTimeout, max(4, (int)floor($deadlineTs - microtime(true))));
    }

    if ($provider === 'groq') {
        $key = trim((string)api_get_secret('GROQ_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider' => 'groq', 'requested_provider' => $provider, 'model' => $resolvedModel, 'requested_model' => $model, 'error' => 'GROQ_API_KEY is not configured', 'transport_failure' => true];
            }
            return false;
        }
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $resolvedModel = $resolvedModel !== '' ? $resolvedModel : 'llama-3.3-70b-versatile';
    } elseif ($provider === 'openrouter') {
        $key = trim((string)api_get_secret('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider' => 'openrouter', 'requested_provider' => $provider, 'model' => $resolvedModel, 'requested_model' => $model, 'error' => 'OPENROUTER_API_KEY is not configured', 'transport_failure' => true];
            }
            return false;
        }
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $resolvedModel = $resolvedModel !== '' ? $resolvedModel : 'openai/gpt-4o-mini';
    } elseif ($provider === 'openai') {
        $key = trim((string)api_get_secret('OPENAI_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider' => 'openai', 'requested_provider' => $provider, 'model' => $resolvedModel, 'requested_model' => $model, 'error' => 'OPENAI_API_KEY is not configured', 'transport_failure' => true];
            }
            return false;
        }
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $key;
        $resolvedModel = $resolvedModel !== '' ? $resolvedModel : 'gpt-4o-mini';
    } else {
        $remoteBaseUrl = llm_remote_brain_candidates()[0] ?? '';
        $remoteRootUrl = $remoteBaseUrl !== '' ? preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl : '';
        if ($remoteRootUrl === '') {
            if ($meta !== null) {
                $meta = ['provider' => 'remote', 'requested_provider' => $provider, 'model' => $resolvedModel, 'requested_model' => $model, 'error' => 'REMOTE_LLM_BASE_URL is not configured', 'transport_failure' => true];
            }
            return false;
        }
        $url = rtrim($remoteRootUrl, '/') . '/api/chat';
        $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
        $headers[] = 'Authorization: Bearer ' . $remoteApiKey;
        $resolvedModel = $resolvedModel !== '' ? $resolvedModel : (llm_remote_brain_model_for_intent('reasoning', $model) ?: 'lyralink-fast:latest');
    }

    $payload = [
        'model' => $resolvedModel,
        'messages' => $messages,
        'stream' => true,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    ];
    if ($provider === 'remote' || $provider === 'local') {
        $payload['keep_alive'] = trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h';
        $payload['options'] = [
            'num_predict' => max(64, min($maxTokens, 2048)),
            'temperature' => $temperature,
        ];
    }

    $buffer = '';
    $reply = '';
    $finalChunk = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => max(2, (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3')),
        CURLOPT_TIMEOUT => max(12, $requestTimeout),
    ]);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function($curlHandle, string $rawChunk) use (&$buffer, &$reply, &$finalChunk, $onDelta): int {
        $buffer .= $rawChunk;
        while (($newlinePos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $newlinePos));
            $buffer = substr($buffer, $newlinePos + 1);
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line === '' || $line === '[DONE]') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $delta = '';
            if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
                $delta = $decoded['choices'][0]['delta']['content'];
            } elseif (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
                $delta = $decoded['choices'][0]['message']['content'];
            } elseif (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
                $delta = $decoded['message']['content'];
            } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
                $delta = $decoded['response'];
            } elseif (isset($decoded['content']) && is_string($decoded['content'])) {
                $delta = $decoded['content'];
            }
            if ($delta !== '') {
                foreach (chat_chunk_stream_delta($delta) as $deltaChunk) {
                    $reply .= $deltaChunk;
                    if (is_callable($onDelta)) {
                        $onDelta($deltaChunk);
                    }
                }
            }
            if (!empty($decoded['done']) || isset($decoded['finish_reason']) || isset($decoded['choices'][0]['finish_reason'])) {
                $finalChunk = $decoded;
            }
        }
        return strlen($rawChunk);
    });

    $started = microtime(true);
    $response = curl_exec($ch);
    $requestMs = (int)round((microtime(true) - $started) * 1000);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($buffer !== '') {
        $line = trim($buffer);
        if ($line !== '' && !str_starts_with($line, ':')) {
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            if ($line !== '' && $line !== '[DONE]') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $delta = '';
                    if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
                        $delta = $decoded['choices'][0]['delta']['content'];
                    } elseif (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
                        $delta = $decoded['choices'][0]['message']['content'];
                    } elseif (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
                        $delta = $decoded['message']['content'];
                    } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
                        $delta = $decoded['response'];
                    } elseif (isset($decoded['content']) && is_string($decoded['content'])) {
                        $delta = $decoded['content'];
                    }
                    if ($delta !== '') {
                        foreach (chat_chunk_stream_delta($delta) as $chunk) {
                            $reply .= $chunk;
                            if (is_callable($onDelta)) {
                                $onDelta($chunk);
                            }
                        }
                    }
                    if (!empty($decoded['done']) || isset($decoded['finish_reason']) || isset($decoded['choices'][0]['finish_reason'])) {
                        $finalChunk = $decoded;
                    }
                }
            }
        }
    }

    if ($response === false || $curlErrNo !== 0 || $httpCode < 200 || $httpCode >= 300) {
        if ($meta !== null) {
            $timedOut = $curlErrNo === CURLE_OPERATION_TIMEDOUT;
            $meta = [
                'provider' => $provider,
                'requested_provider' => $provider,
                'model' => $resolvedModel,
                'requested_model' => $model,
                'http_code' => $httpCode,
                'request_ms' => $requestMs,
                'curl_errno' => $curlErrNo,
                'error' => $curlError !== '' ? $curlError : ('HTTP ' . $httpCode),
                'transport_failure' => true,
                'timed_out' => $timedOut,
                'failure_status' => $timedOut ? 'TIMED_OUT' : 'FAILED',
            ];
        }
        return false;
    }

    $promptTokens = $finalChunk['usage']['prompt_tokens'] ?? ($finalChunk['prompt_tokens'] ?? ($finalChunk['prompt_eval_count'] ?? null));
    $completionTokens = $finalChunk['usage']['completion_tokens'] ?? ($finalChunk['completion_tokens'] ?? ($finalChunk['eval_count'] ?? null));
    $totalTokens = $finalChunk['usage']['total_tokens'] ?? ($finalChunk['total_tokens'] ?? null);
    if ($totalTokens === null && ($promptTokens !== null || $completionTokens !== null)) {
        $totalTokens = (int)($promptTokens ?? 0) + (int)($completionTokens ?? 0);
    }

    if ($meta !== null) {
        $meta = [
            'finish_reason' => $finalChunk['choices'][0]['finish_reason'] ?? ($finalChunk['finish_reason'] ?? null),
            'provider' => $provider,
            'requested_provider' => $provider,
            'model' => $resolvedModel,
            'requested_model' => $model,
            'http_code' => $httpCode,
            'request_ms' => $requestMs,
            'prompt_tokens' => $promptTokens !== null ? (int)$promptTokens : null,
            'completion_tokens' => $completionTokens !== null ? (int)$completionTokens : null,
            'total_tokens' => $totalTokens !== null ? (int)$totalTokens : null,
            'transport_failure' => false,
            'error' => $finalChunk['error']['message'] ?? null,
        ];
    }

    $hasContent = trim($reply) !== '';
    if (!$hasContent && $meta !== null) {
        $meta['empty_output'] = true;
        $meta['failure_status'] = 'EMPTY_OUTPUT';
        // Never replace a specific upstream cause with the generic sentence.
        // An HTTP 200 carrying an error body (model not loaded, out of
        // memory, generation aborted) is an upstream fault, not the model
        // electing to return nothing, and it must stay legible in telemetry.
        $specificUpstreamError = trim((string)($meta['error'] ?? ''));
        if ($specificUpstreamError !== '') {
            $meta['failure_status'] = 'UPSTREAM_ERROR';
        } else {
            $meta['error'] = 'Provider returned an empty response';
        }
    }

    return $hasContent ? $reply : false;
}

function chat_remote_model_candidates(string $primary, string $fallback = '', array $extras = []): array {
    $candidates = [];
    if (trim($primary) !== '') {
        $candidates[] = trim($primary);
    }
    if (trim($fallback) !== '') {
        $candidates[] = trim($fallback);
    }
    foreach (explode(',', (string)api_get_secret('REMOTE_LLM_MODELS', '')) as $model) {
        $model = trim((string)$model);
        if ($model !== '') {
            $candidates[] = $model;
        }
    }
    foreach ($extras as $model) {
        $model = trim((string)$model);
        if ($model !== '') {
            $candidates[] = $model;
        }
    }
    $candidates[] = 'lyralink-fast:latest';

    return array_values(array_unique(array_filter($candidates, static fn($value) => trim((string)$value) !== '')));
}

function chat_remote_image_model_candidates(string $primary, string $fallback = ''): array {
    $candidates = [];
    if (trim($primary) !== '') {
        $candidates[] = trim($primary);
    }
    if (trim($fallback) !== '') {
        $candidates[] = trim($fallback);
    }
    $candidates[] = 'llava:7b';

    return array_values(array_unique(array_filter($candidates, static fn($value) => trim((string)$value) !== '')));
}

function chat_compact_messages_for_remote_image(array $messages): array {
    $systemMessage = null;
    $imageUserMessage = null;

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        if (($message['role'] ?? '') === 'system' && $systemMessage === null) {
            $content = trim((string)($message['content'] ?? ''));
            if ($content !== '') {
                if (strlen($content) > 1200) {
                    $content = substr($content, 0, 1200);
                }
                $systemMessage = ['role' => 'system', 'content' => $content];
            }
        }
    }

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $message = $messages[$i] ?? null;
        if (!is_array($message)) {
            continue;
        }
        if (($message['role'] ?? '') !== 'user') {
            continue;
        }
        if (!empty($message['images']) && is_array($message['images'])) {
            $imageUserMessage = $message;
            break;
        }
    }

    if ($imageUserMessage === null) {
        return $messages;
    }

    $userContent = trim((string)($imageUserMessage['content'] ?? ''));
    if (strlen($userContent) > 1400) {
        $userContent = substr($userContent, 0, 1400);
    }
    $imageUserMessage['content'] = $userContent;

    $compact = [];
    if ($systemMessage !== null) {
        $compact[] = $systemMessage;
    }
    $compact[] = $imageUserMessage;
    return $compact;
}

function chat_ollama_headers(string $apiKey): array {
    $headers = ['Content-Type: application/json'];
    $token = trim($apiKey);
    if ($token !== '' && strtolower($token) !== 'local-ollama') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return $headers;
}

function callLlm(string $provider, array $messages, int $maxTokens = 1024, float $temperature = 0.75, string $model = 'lyralink-auto-canary:latest', ?array &$meta = null, ?float $deadlineTs = null) {
    $provider = strtolower(trim($provider));
    $multipartDeepRequest = false;
    $routeIntent = 'default';
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    $disableFallback = api_get_secret('CHAT_DISABLE_FALLBACK', '1') === '1';
    $localOnlyForced = (
        $provider === 'local'
        || strtolower(trim((string)api_get_secret('LLM_PROVIDER', 'local'))) === 'local'
        || strtolower(trim((string)api_get_secret('LLM_PROVIDER', 'local'))) === 'hermes'
    );

    $requestedProvider = $provider;
    $requestedModel = $model;

    $deadlineRemaining = $deadlineTs !== null
        ? max(1, (int)floor($deadlineTs - microtime(true)))
        : null;

    $doRequest = function (
        string $url,
        array $headers,
        array $data,
        string $effectiveProvider,
        string $modelName,
        int $connectTimeout,
        int $timeout
    ) use (&$meta, $requestedProvider, $requestedModel, $deadlineTs): string|false {
        $ch = curl_init($url);
        $effectiveTimeout = max(1, $timeout);
        $remainingSeconds = null;
        if ($deadlineTs !== null) {
            $remainingSeconds = (int)floor($deadlineTs - microtime(true));
            if ($remainingSeconds < 1) {
                if ($meta !== null) {
                    $meta = [
                        'provider' => $effectiveProvider,
                        'model' => $modelName,
                        'error' => 'Shared request deadline exhausted before transport attempt',
                        'timed_out' => true,
                        'failure_status' => 'TIMED_OUT',
                        'transport_failure' => true,
                    ];
                }
                return false;
            }
            $effectiveTimeout = min($effectiveTimeout, $remainingSeconds);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $effectiveTimeout);

        $started = microtime(true);
        $response = curl_exec($ch);
        $requestMs = (int)round((microtime(true) - $started) * 1000);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            if ($meta !== null) {
                $timedOut = $curlErrNo === CURLE_OPERATION_TIMEDOUT;
                $meta = [
                    'provider' => $effectiveProvider,
                    'requested_provider' => $requestedProvider,
                    'model' => $modelName,
                    'requested_model' => $requestedModel,
                    'http_code' => $httpCode,
                    'request_ms' => $requestMs,
                    'curl_errno' => $curlErrNo,
                    'error' => $curlError !== '' ? $curlError : ('HTTP ' . $httpCode),
                    'transport_failure' => true,
                    'timed_out' => $timedOut,
                    'failure_status' => $timedOut ? 'TIMED_OUT' : 'FAILED',
                ];
            }
            return false;
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            if ($meta !== null) {
                $meta = [
                    'provider' => $effectiveProvider,
                    'requested_provider' => $requestedProvider,
                    'model' => $modelName,
                    'requested_model' => $requestedModel,
                    'http_code' => $httpCode,
                    'request_ms' => $requestMs,
                    'error' => 'Provider returned invalid JSON',
                    'transport_failure' => false,
                    'empty_output' => false,
                    'failure_status' => 'FAILED',
                ];
            }
            return false;
        }

        $choice = $result['choices'][0] ?? [];
        $content = $choice['message']['content'] ?? ($result['message']['content'] ?? null);

        $promptTokens = $result['prompt_eval_count'] ?? ($result['usage']['prompt_tokens'] ?? null);
        $completionTokens = $result['eval_count'] ?? ($result['usage']['completion_tokens'] ?? null);
        $totalTokens = $result['usage']['total_tokens'] ?? null;
        if ($totalTokens === null && ($promptTokens !== null || $completionTokens !== null)) {
            $totalTokens = (int)($promptTokens ?? 0) + (int)($completionTokens ?? 0);
        }

        $evalNs = isset($result['eval_duration']) ? (int)$result['eval_duration'] : null;
        $tokensPerSecond = null;
        if ($evalNs && $completionTokens !== null && $evalNs > 0) {
            $tokensPerSecond = round((int)$completionTokens / ($evalNs / 1_000_000_000), 2);
        }

        if ($meta !== null) {
            $meta = [
                'finish_reason' => $choice['finish_reason'] ?? ($result['done_reason'] ?? null),
                'provider' => $effectiveProvider,
                'requested_provider' => $requestedProvider,
                'model' => $result['model'] ?? $modelName,
                'requested_model' => $requestedModel,
                'http_code' => $httpCode,
                'request_ms' => $requestMs,
                'prompt_tokens' => $promptTokens !== null ? (int)$promptTokens : null,
                'completion_tokens' => $completionTokens !== null ? (int)$completionTokens : null,
                'total_tokens' => $totalTokens !== null ? (int)$totalTokens : null,
                'ollama_total_ms' => isset($result['total_duration']) ? round(((int)$result['total_duration']) / 1_000_000, 2) : null,
                'ollama_load_ms' => isset($result['load_duration']) ? round(((int)$result['load_duration']) / 1_000_000, 2) : null,
                'ollama_prompt_eval_ms' => isset($result['prompt_eval_duration']) ? round(((int)$result['prompt_eval_duration']) / 1_000_000, 2) : null,
                'ollama_eval_ms' => $evalNs !== null ? round($evalNs / 1_000_000, 2) : null,
                'tokens_per_second' => $tokensPerSecond,
                'error' => $result['error']['message'] ?? null,
                'transport_failure' => false,
            ];
        }

        $hasContent = is_string($content) && trim($content) !== '';
        if (!$hasContent && $meta !== null) {
            $meta['empty_output'] = true;
            $meta['failure_status'] = 'EMPTY_OUTPUT';
            // Never replace a specific upstream cause with the generic sentence.
            // An HTTP 200 carrying an error body (model not loaded, out of
            // memory, generation aborted) is an upstream fault, not the model
            // electing to return nothing, and it must stay legible in telemetry.
            $specificUpstreamError = trim((string)($meta['error'] ?? ''));
            if ($specificUpstreamError !== '') {
                $meta['failure_status'] = 'UPSTREAM_ERROR';
            } else {
                $meta['error'] = 'Provider returned an empty response';
            }
        }
        return $hasContent ? $content : false;
    };

    /* Pool-aware wrapper around $doRequest.
     *
     * Every LLM transport call in this file goes through $doRequest, so this is
     * the single place where a pool slot can be taken and reliably returned.
     * try/finally guarantees the release even if the transport throws, so a
     * slot cannot leak on an exception path. Combined with the fact that the
     * kernel drops flock when a process dies, a fatal or an OOM-killed worker
     * cannot leave a slot permanently held.
     *
     * Remote is enforced: with no free GPU slot this returns false at once, so
     * the existing fallback chain moves the request to local immediately
     * instead of first burning a 10-35 s remote timeout.
     *
     * Local is tracked but never refused. Returning false there would surface
     * an error to the user, and local is the last resort, so it must stay a
     * best-effort path. Its slot count is used only as a routing signal.
     */
    $doRequestGated = function (
        string $url,
        array $headers,
        array $data,
        string $effectiveProvider,
        string $modelName,
        int $connectTimeout,
        int $timeout
    ) use ($doRequest, &$meta): string|false {
        $capacityPool = null;
        if ($effectiveProvider === 'remote') {
            $capacityPool = 'remote';
        } elseif ($effectiveProvider === 'local') {
            $capacityPool = 'local';
        }

        /* Benchmarks bypass capacity control entirely so a benchmark run
         * takes no slots and logs no events, keeping series comparable
         * with the existing local-only benchmark convention. */
        if ($capacityPool === null
            || !llm_capacity_enabled()
            || chat_runtime_is_benchmark_mode()
        ) {
            return $doRequest($url, $headers, $data, $effectiveProvider, $modelName, $connectTimeout, $timeout);
        }

        $capacitySlot = llm_capacity_acquire($capacityPool);

        if ($capacityPool === 'remote' && $capacitySlot['state'] !== 'acquired') {
            if ($meta !== null) {
                $meta = [
                    'provider' => $effectiveProvider,
                    'model' => $modelName,
                    'error' => 'remote pool saturated; deferred to local',
                    'capacity_shed' => true,
                    'transport_failure' => true,
                ];
            }
            llm_capacity_note('remote_dispatch_refused', [
                'model' => $modelName,
                'remote_capacity' => llm_capacity_pool_capacity('remote'),
            ]);

            return false;
        }

        try {
            return $doRequest($url, $headers, $data, $effectiveProvider, $modelName, $connectTimeout, $timeout);
        } finally {
            llm_capacity_release($capacitySlot['handle'] ?? null);
        }
    };

    if ($provider === 'groq') {
        $key = trim((string)api_get_secret('GROQ_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider'=>'groq','requested_provider'=>$requestedProvider,'model'=>$model,'requested_model'=>$requestedModel,'error'=>'GROQ_API_KEY is not configured','transport_failure'=>true];
            }
            return null;
        }
        $resolvedModel = $model !== '' ? $model : llm_default_model('groq');
        $requestTimeout = 30;
        if ($deadlineRemaining !== null) {
            $requestTimeout = min($requestTimeout, max(4, $deadlineRemaining));
        }
        $r = $doRequestGated(
            'https://api.groq.com/openai/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            ['model'=>$resolvedModel,'messages'=>$messages,'max_tokens'=>$maxTokens,'temperature'=>$temperature,'stream'=>false],
            'groq', $resolvedModel, 4, $requestTimeout
        );
        return $r === false ? null : $r;
    }

    if ($provider === 'openrouter') {
        $key = trim((string)api_get_secret('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider'=>'openrouter','requested_provider'=>$requestedProvider,'model'=>$model,'requested_model'=>$requestedModel,'error'=>'OPENROUTER_API_KEY is not configured','transport_failure'=>true];
            }
            return null;
        }
        $resolvedModel = $model !== '' ? $model : llm_default_model('openrouter');
        $requestTimeout = 35;
        if ($deadlineRemaining !== null) {
            $requestTimeout = min($requestTimeout, max(4, $deadlineRemaining));
        }
        $r = $doRequestGated(
            'https://openrouter.ai/api/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            ['model'=>$resolvedModel,'messages'=>$messages,'max_tokens'=>$maxTokens,'temperature'=>$temperature,'stream'=>false],
            'openrouter', $resolvedModel, 4, $requestTimeout
        );
        return $r === false ? null : $r;
    }

    if ($provider === 'openai') {
        $key = trim((string)api_get_secret('OPENAI_API_KEY', ''));
        if ($key === '') {
            if ($meta !== null) {
                $meta = ['provider'=>'openai','requested_provider'=>$requestedProvider,'model'=>$model,'requested_model'=>$requestedModel,'error'=>'OPENAI_API_KEY is not configured','transport_failure'=>true];
            }
            return null;
        }
        $resolvedModel = $model !== '' ? $model : llm_default_model('openai');
        $requestTimeout = 35;
        if ($deadlineRemaining !== null) {
            $requestTimeout = min($requestTimeout, max(4, $deadlineRemaining));
        }
        $r = $doRequestGated(
            'https://api.openai.com/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            ['model'=>$resolvedModel,'messages'=>$messages,'max_tokens'=>$maxTokens,'temperature'=>$temperature,'stream'=>false],
            'openai', $resolvedModel, 4, $requestTimeout
        );
        return $r === false ? null : $r;
    }

    $localBaseUrl = rtrim(api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRootUrl = preg_replace('#/v1$#', '', $localBaseUrl) ?: $localBaseUrl;
    $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
    $resolvedModel = llm_safe_local_model(
        $model !== '' ? $model : ($localModel !== '' ? $localModel : 'lyralink-auto-canary:latest'),
        llm_default_model('local')
    );
    $ollamaMessages = chat_transform_messages_for_ollama($messages);
    $hasImages = chat_ollama_messages_include_images($ollamaMessages);
    $preferFullReplies = api_get_secret('LOCAL_LLM_FULL_REPLY_MODE', '1') === '1';
    $resolvedModelLower = strtolower($resolvedModel);
    $defaultLocalTimeout = 35;
    if ($preferFullReplies) {
        $defaultLocalTimeout = str_contains($resolvedModelLower, '3b') ? 90 : 120;
    } elseif (str_contains($resolvedModelLower, '8b')) {
        $defaultLocalTimeout = 45;
    }
    $localTimeout = max(12, (int)api_get_secret('LOCAL_LLM_TIMEOUT', (string)$defaultLocalTimeout));
    $localFallbackTimeout = max(12, (int)api_get_secret('LOCAL_LLM_FALLBACK_TIMEOUT', $preferFullReplies ? '25' : '10'));
    $deepTokenThreshold = max(128, (int)api_get_secret('LOCAL_LLM_DEEP_TOKEN_THRESHOLD', '420'));
    $balancedTokenThreshold = max(80, (int)api_get_secret('LOCAL_LLM_BALANCED_TOKEN_THRESHOLD', '220'));
    $fastTimeout = max(12, (int)api_get_secret('LOCAL_LLM_FAST_TIMEOUT', '16'));
    $balancedTimeout = max($fastTimeout, (int)api_get_secret('LOCAL_LLM_BALANCED_TIMEOUT', '24'));
    $deepTimeout = max($balancedTimeout, (int)api_get_secret('LOCAL_LLM_DEEP_TIMEOUT', (string)$localTimeout));
    $imageTimeout = max($deepTimeout, (int)api_get_secret('LOCAL_LLM_IMAGE_TIMEOUT', '120'));
    $imageFallbackTimeout = max(30, min($imageTimeout, (int)api_get_secret('LOCAL_LLM_IMAGE_FALLBACK_TIMEOUT', '75')));
    $remoteLoadBalanceEnabled = api_get_secret('REMOTE_LLM_LOAD_BALANCE', '1') === '1';
    $routeShortRequestsToRemote = api_get_secret('REMOTE_LLM_ROUTE_SHORT', '0') === '1';
    $routeOnLocalSlow = api_get_secret('REMOTE_LLM_ROUTE_ON_LOCAL_SLOW', '1') === '1';
    $localSlowThresholdMs = max(600, (int)api_get_secret('REMOTE_LLM_LOCAL_SLOW_MS', '2600'));
    $localProbeTimeout = max(1, min(4, (int)api_get_secret('REMOTE_LLM_LOCAL_PROBE_TIMEOUT', '2')));
    $remoteCandidates = llm_remote_brain_candidates();
    $preferRemoteForDeep = api_get_secret('REMOTE_LLM_PREFER_DEEP', '1') === '1'
        && !$hasImages
        && !empty($remoteCandidates)
        && ($multipartDeepRequest || $maxTokens >= min(160, $balancedTokenThreshold) || $preferFullReplies);
    $imageOffloadFailed = false;
    $remoteImageOffloadEnabled = $hasImages
        && api_get_secret('REMOTE_LLM_IMAGE_OFFLOAD', '1') === '1'
        && !empty($remoteCandidates);

    if ($localOnlyForced) {
        $remoteLoadBalanceEnabled = false;
        $routeShortRequestsToRemote = false;
        $routeOnLocalSlow = false;
        $preferRemoteForDeep = false;
        $remoteImageOffloadEnabled = false;
    }

    if (chat_runtime_is_benchmark_mode()) {
        $remoteLoadBalanceEnabled = false;
        $routeShortRequestsToRemote = false;
        $routeOnLocalSlow = false;
        $preferRemoteForDeep = false;
        $remoteImageOffloadEnabled = false;
    }

    /* ---- Lyralink LLM capacity gate (see lib/chat/llm_capacity.php) -------
     *
     * Measured 2026-09-23 over a 183-request ramp: the remote GPU serves 2
     * parallel slots and the local box 1 (OLLAMA_NUM_PARALLEL=1 is deliberate
     * there). Past those counts aggregate throughput stops rising and then
     * falls, while first-token latency reaches 127 s at 30 concurrent.
     * Ignoring that causes two concrete harms:
     *
     *   1. A request dispatched to a saturated remote pool waits out its whole
     *      10-35 s remote timeout and then falls back to local anyway, while
     *      the GPU keeps processing the abandoned request and grows backlog.
     *   2. Work pushed onto local CPU competes with PHP-FPM and MySQL on the
     *      same 6-core production box, degrading the site for everyone.
     *
     * Policy, covering overflow in both directions:
     *   - remote saturated -> stop offering remote so the request takes the
     *     local path immediately rather than burning a timeout first.
     *   - local saturated and remote has room -> offer remote for eligible
     *     short requests, moving work off the CPU.
     *
     * Advisory and fail-open: this only ever removes remote from consideration
     * or adds it back. It never blocks a request that would otherwise proceed,
     * and an unusable state directory degrades to "untracked" rather than an
     * error. Skipped in benchmark mode so benchmark series stay comparable,
     * matching the existing local-only convention.
     */
    if (llm_capacity_enabled() && !chat_runtime_is_benchmark_mode()) {
        $capacitySnapshot = llm_capacity_snapshot();
        $capacityRemote = $capacitySnapshot['remote'] ?? ['capacity' => 0, 'active' => 0, 'free' => 1];
        $capacityLocal = $capacitySnapshot['local'] ?? ['capacity' => 0, 'active' => 0, 'free' => 1];
        $capacityShortEligible = !$hasImages
            && !empty($remoteCandidates)
            && $maxTokens < 600
            && !$multipartDeepRequest
            && !($preferFullReplies && $maxTokens >= 300);

        /* Health before occupancy. A dead GPU has zero busy slots, so an
         * occupancy-only gate reads it as idle and dispatches to it, burning
         * a full connect timeout per request before the local fallback. */
        $capacityRemoteHealth = chat_remote_runtime_health($remoteCandidates);
        if (!empty($remoteCandidates) && !$capacityRemoteHealth['ok']) {
            /* Only log a FRESH detection. The verdict is cached for failTtl,
             * and logging every request during a long outage wrote thousands
             * of identical lines, burying the signal. Keying off 'cached'
             * rate-limits this to one line per probe cycle with no extra state. */
            if (empty($capacityRemoteHealth['cached'])) {
                llm_capacity_note('remote_unhealthy_stand_down', [
                    'remote_url' => $capacityRemoteHealth['url'],
                    'http_code' => $capacityRemoteHealth['http_code'],
                    'request_ms' => $capacityRemoteHealth['request_ms'],
                    'error' => $capacityRemoteHealth['error'],
                ]);
            }
            $remoteLoadBalanceEnabled = false;
            $routeShortRequestsToRemote = false;
            $routeOnLocalSlow = false;
            $preferRemoteForDeep = false;
            $remoteImageOffloadEnabled = false;
        } elseif ($capacityRemote['free'] <= 0) {
            llm_capacity_note('remote_saturated_stand_down', [
                'remote_active' => $capacityRemote['active'],
                'remote_capacity' => $capacityRemote['capacity'],
                'local_active' => $capacityLocal['active'],
            ]);
            $remoteLoadBalanceEnabled = false;
            $routeShortRequestsToRemote = false;
            $routeOnLocalSlow = false;
            $preferRemoteForDeep = false;
            $remoteImageOffloadEnabled = false;
        } elseif ($capacityLocal['free'] <= 0 && $capacityShortEligible) {
            llm_capacity_note('local_saturated_overflow_to_remote', [
                'local_active' => $capacityLocal['active'],
                'local_capacity' => $capacityLocal['capacity'],
                'remote_free' => $capacityRemote['free'],
                'max_tokens' => $maxTokens,
            ]);
            $remoteLoadBalanceEnabled = true;
            $routeShortRequestsToRemote = true;
        }
    }

    if ($remoteImageOffloadEnabled) {
        $remoteImageTimeout = max(15, min(35, (int)api_get_secret('REMOTE_LLM_IMAGE_TIMEOUT', '30')));
        $remoteImagePredictCap = max(192, min(384, (int)api_get_secret('REMOTE_LLM_IMAGE_MAX_TOKENS', '256')));
        $remoteImageApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
        $remoteImageModelPreferred = trim((string)api_get_secret('REMOTE_LLM_IMAGE_MODEL', ''));
        $remoteImageKeepAlive = trim((string)api_get_secret('REMOTE_LLM_IMAGE_KEEP_ALIVE', api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h'))) ?: '2h';
        $remoteImageMessages = chat_compact_messages_for_remote_image($ollamaMessages);

        foreach ($remoteCandidates as $remoteBaseUrl) {
            $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
            $remoteImageModelCandidates = chat_remote_image_model_candidates(
                $remoteImageModelPreferred,
                ''
            );
            $remoteRequestTimeout = $deadlineRemaining !== null
                ? min($remoteImageTimeout, max(18, $deadlineRemaining))
                : $remoteImageTimeout;

            foreach ($remoteImageModelCandidates as $remoteImageModel) {
                $remoteImageReply = $doRequestGated(
                    $remoteRootUrl . '/api/chat',
                    chat_ollama_headers($remoteImageApiKey),
                    [
                        'model' => $remoteImageModel,
                        'messages' => $remoteImageMessages,
                        'stream' => false,
                        'keep_alive' => $remoteImageKeepAlive,
                        'options' => [
                            'num_predict' => max(256, min($maxTokens, $remoteImagePredictCap)),
                            'temperature' => $temperature,
                        ],
                    ],
                    'remote',
                    $remoteImageModel,
                    (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                    $remoteRequestTimeout
                );
                if ($remoteImageReply !== false) {
                    return $remoteImageReply;
                }
            }
        }

        if (api_get_secret('REMOTE_LLM_IMAGE_SKIP_LOCAL_FALLBACK', '1') === '1') {
            return null;
        }

        $imageOffloadFailed = true;
    }

    $shortRequestEligible = !$hasImages && !empty($remoteCandidates) && $maxTokens < 600 && !$multipartDeepRequest && !($preferFullReplies && $maxTokens >= 300);
    $routeShortByLoad = false;
    if ($shortRequestEligible && $routeOnLocalSlow) {
        $localProbeMs = chat_local_runtime_probe_ms($localRootUrl, $localProbeTimeout);
        if ($localProbeMs === null && api_get_secret("LOCAL_LLM_WARMUP_ON_PROBE_FAIL", "1") === "1") {
            /* A failed probe is ambiguous: the runtime is either down or
             * mid-load, and mid-load is the common case right after the
             * hourly model rebuild. Reading it as "local is slow" sent
             * short requests to the paid remote provider on every cold
             * start. Load the model and measure again; if it was only
             * cold, the re-probe is fast and the request stays local. */
            chat_local_model_warmup(
                $localRootUrl,
                $resolvedModel,
                max(1, (int)api_get_secret("LOCAL_LLM_WARMUP_TIMEOUT", "20"))
            );
            $localProbeMs = chat_local_runtime_probe_ms($localRootUrl, $localProbeTimeout);
        }
        $routeShortByLoad = ($localProbeMs === null || $localProbeMs >= $localSlowThresholdMs);
    }
    $useRemoteForShort = $routeShortRequestsToRemote || $routeShortByLoad;

    if ($remoteLoadBalanceEnabled && $useRemoteForShort && $shortRequestEligible) {
        $remoteFastTimeout = max(6, (int)api_get_secret('REMOTE_LLM_FAST_TIMEOUT', '10'));
        foreach ($remoteCandidates as $remoteBaseUrl) {
            $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
            $remoteIntent = ($maxTokens <= 160)
                ? 'fast'
                : (in_array($routeIntent, ['code', 'research', 'reasoning'], true) ? $routeIntent : 'reasoning');
            $remoteModelCandidates = [llm_remote_brain_model_for_intent($remoteIntent, $resolvedModel)];
            $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));

            foreach ($remoteModelCandidates as $remoteModel) {
                $remoteRequest = $doRequestGated(
                    $remoteRootUrl . '/api/chat',
                    chat_ollama_headers($remoteApiKey),
                    [
                        'model' => $remoteModel,
                        'messages' => $ollamaMessages,
                        'stream' => false,
                        'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                        'options' => [
                            'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 512 : 320)),
                            'temperature' => $temperature,
                        ],
                    ],
                    'remote',
                    $remoteModel,
                    (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                    $deadlineRemaining !== null ? min($remoteFastTimeout, max(6, $deadlineRemaining)) : $remoteFastTimeout
                );
                if ($remoteRequest !== false) {
                    return $remoteRequest;
                }
            }
        }
    }

    if ($hasImages) {
        $localTimeout = max($localTimeout, $imageTimeout);
        $localFallbackTimeout = max($localFallbackTimeout, $imageFallbackTimeout);
        if ($imageOffloadFailed) {
            $localTimeout = min($localTimeout, 20);
            $localFallbackTimeout = min($localFallbackTimeout, 15);
        }
    } elseif ($maxTokens >= $deepTokenThreshold) {
        $localTimeout = max($localTimeout, $deepTimeout);
    } elseif ($maxTokens >= $balancedTokenThreshold) {
        $localTimeout = min($localTimeout, $balancedTimeout);
    } else {
        $localTimeout = min($localTimeout, $fastTimeout);
    }

    if ($deadlineRemaining !== null) {
        $budgetTimeout = max(12, $deadlineRemaining);
        $isShortFastRequest = $maxTokens < $balancedTokenThreshold && !($preferFullReplies || str_contains(strtolower($resolvedModel), 'reasoning'));

        if ($hasImages) {
            $localTimeout = max($localTimeout, min($budgetTimeout, $imageTimeout));
            $localFallbackTimeout = max(30, min($localFallbackTimeout, max(30, $budgetTimeout - 5)));
        } elseif ($isShortFastRequest) {
            $localTimeout = min($localTimeout, $budgetTimeout);
            $localFallbackTimeout = min($localFallbackTimeout, max(12, $budgetTimeout - 1));
        } else {
            $sizeFloor = chat_adaptive_local_timeout($ollamaMessages, $maxTokens, $localTimeout) ?? 18;
            $localTimeout = max($localTimeout, min($budgetTimeout, max($sizeFloor, (int)floor($localTimeout * 0.8))));
            $localFallbackTimeout = max(12, min($localFallbackTimeout, max(12, (int)floor($localTimeout * 0.7))));
        }
    }
    $localFallbackTimeout = $hasImages
        ? max(30, min($localFallbackTimeout, max(30, (int)floor($localTimeout * 0.8))))
        : max(12, min($localFallbackTimeout, max(12, (int)floor($localTimeout * 0.7))));

    $runtimeTimeoutOverride = chat_runtime_timeout_override_seconds();
    if ($runtimeTimeoutOverride !== null) {
        $localTimeout = max($localTimeout, $runtimeTimeoutOverride);
        $localFallbackTimeout = max($localFallbackTimeout, max(12, $runtimeTimeoutOverride - 5));
    }

    if ($preferRemoteForDeep) {
        $deadlineRemaining = $deadlineTs !== null ? max(1, (int)floor($deadlineTs - microtime(true))) : null;
        $remoteDeepTimeout = max(12, (int)api_get_secret('REMOTE_LLM_DEEP_TIMEOUT', '35'));
        $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
        foreach ($remoteCandidates as $remoteBaseUrl) {
            $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
            $remoteIntent = $maxTokens <= 160
                ? 'fast'
                : (in_array($routeIntent, ['code', 'research', 'reasoning'], true) ? $routeIntent : 'reasoning');
            $remoteModelCandidates = [llm_remote_brain_model_for_intent($remoteIntent, $resolvedModel)];
            foreach ($remoteModelCandidates as $remoteModel) {
                $remoteRequest = $doRequestGated(
                    $remoteRootUrl . '/api/chat',
                    chat_ollama_headers($remoteApiKey),
                    [
                        'model' => $remoteModel,
                        'messages' => $ollamaMessages,
                        'stream' => false,
                        'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                        'options' => [
                            'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 768 : 512)),
                            'temperature' => $temperature,
                        ],
                    ],
                    'remote',
                    $remoteModel,
                    (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                    $deadlineRemaining !== null ? min($remoteDeepTimeout, max(12, $deadlineRemaining)) : $remoteDeepTimeout
                );
                if ($remoteRequest !== false) {
                    if ($meta !== null) {
                        $meta['routing_strategy'] = 'remote_primary_local_fallback';
                    }
                    return $remoteRequest;
                }
            }
        }
    }

    if ($hasImages) {
        $imagePredictCap = (int)api_get_secret('LOCAL_LLM_IMAGE_MAX_TOKENS', '900');
        if ($imageOffloadFailed) {
            $imagePredictCap = min($imagePredictCap, 256);
        }
        $localPredictCap = max(192, min($maxTokens, $imagePredictCap));
    } elseif ($multipartDeepRequest) {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 2048 : 3072;
    } elseif ($preferFullReplies) {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 640 : 1152;
    } else {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 256 : 512;
    }

    $deadlineRemaining = $deadlineTs !== null ? max(1, (int)floor($deadlineTs - microtime(true))) : null;
    $r = $doRequestGated(
        $localRootUrl . '/api/chat',
        chat_ollama_headers($localApiKey),
        [
            'model' => $resolvedModel,
            'messages' => $ollamaMessages,
            'stream' => false,
            'keep_alive' => trim((string)api_get_secret('LOCAL_LLM_KEEP_ALIVE', '2h')) ?: '2h',
            'options' => [
                'num_predict' => max(64, min($maxTokens, $localPredictCap)),
                'temperature' => $temperature,
            ],
        ],
        'local',
        $resolvedModel,
        (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
        $localTimeout
    );

    if ($r === false) {
        if ($disableFallback) {
            return null;
        }
        $fallbackCandidates = [
            $resolvedModel,
            'lyralink-fast:latest',
            'lyralink-auto-canary:latest',
            'lyralink-reasoning:latest',
            'lyralink-code:latest',
            'lyralink-creative:latest',
        ];
        if ($hasImages) {
            $fallbackCandidates = array_values(array_unique(array_filter([
                $resolvedModel,
                trim((string)api_get_secret('LOCAL_LLM_IMAGE_MODEL', '')),
                'llava:7b',
            ], static fn($value) => trim((string)$value) !== '')));
        }
        foreach (llm_provider_models('local') as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || strtolower($candidate) === strtolower($resolvedModel)) {
                continue;
            }
            if ($hasImages) {
                continue;
            }
            $lower = strtolower($candidate);
            if (str_contains($lower, 'fast') || str_contains($lower, 'auto-canary') || str_contains($lower, 'creative') || stripos($lower, '3b') !== false) {
                $fallbackCandidates[] = $candidate;
            }
        }
        foreach (llm_provider_models('local') as $candidate) {
            $candidate = trim((string)$candidate);
            if ($hasImages) {
                continue;
            }
            if ($candidate !== '' && !in_array($candidate, $fallbackCandidates, true)) {
                $fallbackCandidates[] = $candidate;
            }
        }
        $fallbackCandidates = array_values(array_unique(array_filter(array_map('trim', $fallbackCandidates), fn($v) => $v !== '')));

        $maxFallbacks = $hasImages ? 1 : ($preferFullReplies ? 2 : 1);
        if (!$hasImages && ($maxTokens >= 512 || $temperature > 0.8)) {
            $maxFallbacks = 2;
        }

        foreach (array_slice($fallbackCandidates, 0, max(1, $maxFallbacks)) as $fallbackModel) {
            if (strtolower((string)$fallbackModel) === strtolower((string)$resolvedModel)) {
                continue;
            }
            $deadlineRemaining = $deadlineTs !== null ? max(1, (int)floor($deadlineTs - microtime(true))) : null;
            $fallbackTimeout = max(6, min($localFallbackTimeout, $localTimeout > 0 ? (int)floor($localTimeout * 0.8) : $localFallbackTimeout));
            if ($deadlineRemaining !== null) {
                $fallbackTimeout = min($fallbackTimeout, max(4, $deadlineRemaining));
            }
            $retryMaxTokens = $hasImages ? min($maxTokens, 192) : min($maxTokens, $preferFullReplies ? 320 : 220);
            $retryResult = $doRequestGated(
                $localRootUrl . '/api/chat',
                chat_ollama_headers($localApiKey),
                [
                    'model' => $fallbackModel,
                    'messages' => $ollamaMessages,
                    'stream' => false,
                    'keep_alive' => trim((string)api_get_secret('LOCAL_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                    'options' => [
                        'num_predict' => max(64, $retryMaxTokens),
                        'temperature' => min($temperature, 0.9),
                    ],
                ],
                'local',
                $fallbackModel,
                (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'),
                $fallbackTimeout
            );
            if ($retryResult !== false) {
                $r = $retryResult;
                break;
            }
        }

        if ($r === false) {
            if ($localOnlyForced) {
                return null;
            }
            $remoteCandidates = llm_remote_brain_candidates();
            $remoteModelCandidates = chat_remote_model_candidates($resolvedModel, llm_remote_brain_model_for_intent($routeIntent, $resolvedModel));
            $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
            $remoteTimeout = max(12, (int)api_get_secret('REMOTE_LLM_TIMEOUT', '45'));
            foreach ($remoteCandidates as $remoteBaseUrl) {
                $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;

                foreach ($remoteModelCandidates as $remoteModel) {
                    $remoteResult = $doRequestGated(
                        $remoteRootUrl . '/api/chat',
                        chat_ollama_headers($remoteApiKey),
                        [
                            'model' => $remoteModel,
                            'messages' => $ollamaMessages,
                            'stream' => false,
                            'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                            'options' => [
                                'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 1024 : 512)),
                                'temperature' => $temperature,
                            ],
                        ],
                        'remote',
                        $remoteModel,
                        (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                        $deadlineRemaining !== null ? min($remoteTimeout, max(12, $deadlineRemaining)) : $remoteTimeout
                    );
                    if ($remoteResult !== false) {
                        $r = $remoteResult;
                        break 2;
                    }
                }
            }
        }
    }

    return $r === false ? null : $r;
}