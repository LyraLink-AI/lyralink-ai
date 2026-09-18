<?php

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

function chat_local_stream_request(array $messages, int $maxTokens, float $temperature, string $model = 'lyralink-auto-canary:latest', ?array &$meta = null, ?float $deadlineTs = null, ?callable $onDelta = null): string|false {
    $localBaseUrl = rtrim(api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRootUrl = preg_replace('#/v1$#', '', $localBaseUrl) ?: $localBaseUrl;
    $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
    $resolvedModel = $model !== '' ? $model : ($localModel !== '' ? $localModel : 'lyralink-auto-canary:latest');
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
        $budgetTimeout = max(12, (int)floor($deadlineTs - microtime(true)));
        $isShortFastRequest = $maxTokens < $balancedTokenThreshold && !str_contains(strtolower($resolvedModel), 'reasoning');

        if ($hasImages) {
            $localTimeout = max($localTimeout, min($budgetTimeout, $imageTimeout));
            $localFallbackTimeout = max(30, min($localFallbackTimeout, max(30, $budgetTimeout - 5)));
        } elseif ($isShortFastRequest) {
            $localTimeout = min($localTimeout, $budgetTimeout);
            $localFallbackTimeout = min($localFallbackTimeout, max(12, $budgetTimeout - 1));
        } else {
            $localTimeout = max($localTimeout, min($budgetTimeout, max(18, (int)floor($localTimeout * 0.8))));
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

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function($ch, string $chunk) use (&$buffer, &$reply, &$finalChunk, $onDelta): int {
        $buffer .= $chunk;
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
        return strlen($chunk);
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
        $meta['error'] = 'Provider returned an empty response';
    }

    return $hasContent ? $reply : false;
}

function chat_provider_stream_request(array $messages, int $maxTokens, float $temperature, string $provider, string $model = '', ?array &$meta = null, ?float $deadlineTs = null, ?callable $onDelta = null): string|false {
    $provider = strtolower(trim($provider));
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    if (in_array($provider, ['local', 'hermes'], true)) {
        return chat_local_stream_request($messages, $maxTokens, $temperature, $model, $meta, $deadlineTs, $onDelta);
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

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function($curlHandle, string $chunk) use (&$buffer, &$reply, &$finalChunk, $onDelta): int {
        $buffer .= $chunk;
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
        return strlen($chunk);
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
        $meta['error'] = 'Provider returned an empty response';
    }

    return $hasContent ? $reply : false;
}

function callLlm(string $provider, array $messages, int $maxTokens = 1024, float $temperature = 0.75, string $model = 'lyralink-auto-canary:latest', ?array &$meta = null, ?float $deadlineTs = null) {
    $provider = strtolower(trim($provider));
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    $requestedProvider = $provider;
    $requestedModel = $model;

    $deadlineRemaining = null;
    if ($deadlineTs !== null) {
        $deadlineRemaining = (int)floor($deadlineTs - microtime(true));
        if ($deadlineRemaining < 2) {
            $deadlineRemaining = 2;
        }
    }

    $doRequest = function (
        string $url,
        array $headers,
        array $data,
        string $effectiveProvider,
        string $modelName,
        int $connectTimeout,
        int $timeout
    ) use (&$meta, $requestedProvider, $requestedModel, $deadlineRemaining): string|false {
        $ch = curl_init($url);
        $effectiveTimeout = max(12, $timeout);
        if ($deadlineRemaining !== null) {
            if ($deadlineRemaining < 12) {
                $effectiveTimeout = max($effectiveTimeout, 12);
            } else {
                $effectiveTimeout = min($effectiveTimeout, $deadlineRemaining);
            }
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

        return is_string($content) && trim($content) !== '' ? $content : false;
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
        $r = $doRequest(
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
        $r = $doRequest(
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
        $r = $doRequest(
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
    $resolvedModel = $model !== '' ? $model : ($localModel !== '' ? $localModel : 'lyralink-auto-canary:latest');
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
    $remoteLoadBalanceEnabled = api_get_secret('REMOTE_LLM_LOAD_BALANCE', '1') === '1';
    $routeShortRequestsToRemote = api_get_secret('REMOTE_LLM_ROUTE_SHORT', '1') === '1';
    $remoteCandidates = llm_remote_brain_candidates();

    if (chat_runtime_is_benchmark_mode()) {
        $remoteLoadBalanceEnabled = false;
        $routeShortRequestsToRemote = false;
    }

    if ($remoteLoadBalanceEnabled && $routeShortRequestsToRemote && !empty($remoteCandidates) && $maxTokens < 600 && !$multipartDeepRequest && !($preferFullReplies && $maxTokens >= 300)) {
        $remoteFastTimeout = max(6, (int)api_get_secret('REMOTE_LLM_FAST_TIMEOUT', '10'));
        foreach ($remoteCandidates as $remoteBaseUrl) {
            $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
            $remoteModel = trim((string)api_get_secret('REMOTE_LLM_MODEL', $resolvedModel));
            $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
            $remoteRequest = $doRequest(
                $remoteRootUrl . '/api/chat',
                ['Content-Type: application/json', 'Authorization: Bearer ' . $remoteApiKey],
                [
                    'model' => $remoteModel !== '' ? $remoteModel : $resolvedModel,
                    'messages' => $messages,
                    'stream' => false,
                    'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                    'options' => [
                        'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 512 : 320)),
                        'temperature' => $temperature,
                    ],
                ],
                'remote',
                $remoteModel !== '' ? $remoteModel : $resolvedModel,
                (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                $deadlineRemaining !== null ? min($remoteFastTimeout, max(6, $deadlineRemaining)) : $remoteFastTimeout
            );
            if ($remoteRequest !== false) {
                return $remoteRequest;
            }
        }
    }

    if ($maxTokens >= $deepTokenThreshold) {
        $localTimeout = max($localTimeout, $deepTimeout);
    } elseif ($maxTokens >= $balancedTokenThreshold) {
        $localTimeout = min($localTimeout, $balancedTimeout);
    } else {
        $localTimeout = min($localTimeout, $fastTimeout);
    }

    if ($deadlineRemaining !== null) {
        $budgetTimeout = max(12, $deadlineRemaining);
        $isShortFastRequest = $maxTokens < $balancedTokenThreshold && !($preferFullReplies || str_contains(strtolower($resolvedModel), 'reasoning'));

        if ($isShortFastRequest) {
            $localTimeout = min($localTimeout, $budgetTimeout);
            $localFallbackTimeout = min($localFallbackTimeout, max(12, $budgetTimeout - 1));
        } else {
            $localTimeout = max($localTimeout, min($budgetTimeout, max(18, (int)floor($localTimeout * 0.8))));
            $localFallbackTimeout = max(12, min($localFallbackTimeout, max(12, (int)floor($localTimeout * 0.7))));
        }
    }
    $localFallbackTimeout = max(12, min($localFallbackTimeout, max(12, (int)floor($localTimeout * 0.7))));

    $runtimeTimeoutOverride = chat_runtime_timeout_override_seconds();
    if ($runtimeTimeoutOverride !== null) {
        $localTimeout = max($localTimeout, $runtimeTimeoutOverride);
        $localFallbackTimeout = max($localFallbackTimeout, max(12, $runtimeTimeoutOverride - 5));
    }

    if ($multipartDeepRequest) {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 2048 : 3072;
    } elseif ($preferFullReplies) {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 640 : 1152;
    } else {
        $localPredictCap = str_contains($resolvedModelLower, '3b') ? 256 : 512;
    }

    $r = $doRequest(
        $localRootUrl . '/api/chat',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $localApiKey],
        [
            'model' => $resolvedModel,
            'messages' => $messages,
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
        $fallbackCandidates = [$resolvedModel, 'hermes3:3b', 'hermes3:8b'];
        foreach (llm_provider_models('local') as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || strtolower($candidate) === strtolower($resolvedModel)) {
                continue;
            }
            $lower = strtolower($candidate);
            if (str_contains($lower, 'fast') || str_contains($lower, 'auto-canary') || str_contains($lower, 'creative') || stripos($lower, '3b') !== false) {
                $fallbackCandidates[] = $candidate;
            }
        }
        foreach (llm_provider_models('local') as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && !in_array($candidate, $fallbackCandidates, true)) {
                $fallbackCandidates[] = $candidate;
            }
        }
        $fallbackCandidates = array_values(array_unique(array_filter(array_map('trim', $fallbackCandidates), fn($v) => $v !== '')));

        $maxFallbacks = $preferFullReplies ? 2 : 1;
        if ($maxTokens >= 512 || $temperature > 0.8) {
            $maxFallbacks = 2;
        }

        foreach (array_slice($fallbackCandidates, 0, max(1, $maxFallbacks)) as $fallbackModel) {
            if (strtolower((string)$fallbackModel) === strtolower((string)$resolvedModel)) {
                continue;
            }
            $fallbackTimeout = max(6, min($localFallbackTimeout, $localTimeout > 0 ? (int)floor($localTimeout * 0.8) : $localFallbackTimeout));
            if ($deadlineRemaining !== null) {
                $fallbackTimeout = min($fallbackTimeout, max(4, $deadlineRemaining));
            }
            $retryMaxTokens = min($maxTokens, $preferFullReplies ? 320 : 220);
            $retryResult = $doRequest(
                $localRootUrl . '/api/chat',
                ['Content-Type: application/json', 'Authorization: Bearer ' . $localApiKey],
                [
                    'model' => $fallbackModel,
                    'messages' => $messages,
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
            $remoteCandidates = llm_remote_brain_candidates();
            $remoteModel = trim((string)api_get_secret('REMOTE_LLM_MODEL', $resolvedModel));
            $remoteApiKey = trim((string)api_get_secret('REMOTE_LLM_API_KEY', 'local-ollama'));
            $remoteTimeout = max(12, (int)api_get_secret('REMOTE_LLM_TIMEOUT', '45'));
            foreach ($remoteCandidates as $remoteBaseUrl) {
                $remoteRootUrl = preg_replace('#/v1$#', '', $remoteBaseUrl) ?: $remoteBaseUrl;
                $remoteResult = $doRequest(
                    $remoteRootUrl . '/api/chat',
                    ['Content-Type: application/json', 'Authorization: Bearer ' . $remoteApiKey],
                    [
                        'model' => $remoteModel !== '' ? $remoteModel : $resolvedModel,
                        'messages' => $messages,
                        'stream' => false,
                        'keep_alive' => trim((string)api_get_secret('REMOTE_LLM_KEEP_ALIVE', '2h')) ?: '2h',
                        'options' => [
                            'num_predict' => max(64, min($maxTokens, $preferFullReplies ? 1024 : 512)),
                            'temperature' => $temperature,
                        ],
                    ],
                    'remote',
                    $remoteModel !== '' ? $remoteModel : $resolvedModel,
                    (int)api_get_secret('REMOTE_LLM_CONNECT_TIMEOUT', '3'),
                    $deadlineRemaining !== null ? min($remoteTimeout, max(12, $deadlineRemaining)) : $remoteTimeout
                );
                if ($remoteResult !== false) {
                    $r = $remoteResult;
                    break;
                }
            }
        }
    }

    return $r === false ? null : $r;
}