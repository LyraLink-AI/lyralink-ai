<?php

function llm_default_model(string $provider): string {
    $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
    $openRouterModel = trim(api_get_secret('OPENROUTER_MODEL', 'openclaw/openclaw-7b'));
    $openAiModel = trim(api_get_secret('OPENAI_MODEL', 'gpt-4o-mini'));
    if ($provider === 'local' || $provider === 'hermes') {
        return $localModel !== '' ? $localModel : 'lyralink-auto-canary:latest';
    }
    return match ($provider) {
        'openrouter' => $openRouterModel !== '' ? $openRouterModel : 'openclaw/openclaw-7b',
        'openai'     => $openAiModel !== '' ? $openAiModel : 'gpt-4o-mini',
        default      => 'llama-3.1-8b-instant',
    };
}

function llm_provider_available(string $provider): bool {
    $provider = strtolower($provider);
    if ($provider === 'local' || $provider === 'hermes') {
        return true;
    }
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
    $order = array_values(array_unique(array_merge($preferred, ['local', 'hermes', 'groq', 'openrouter', 'openai'])));
    foreach ($order as $provider) {
        $provider = strtolower(trim((string)$provider));
        if ($provider === 'hermes') {
            $provider = 'local';
        }
        if (llm_provider_available($provider)) {
            return $provider;
        }
    }
    return 'local';
}

function llm_parse_csv(string $raw): array {
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

function llm_provider_models(string $provider): array {
    if (in_array(strtolower($provider), ['local', 'hermes'], true)) {
        $models = llm_parse_csv(api_get_secret('LLM_LOCAL_MODELS', ''));
        $configured = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
        if ($configured !== '') {
            $models[] = $configured;
        }
        $models[] = 'lyralink-auto-canary:latest';
        $models[] = 'lyralink-fast:latest';
        $models[] = 'lyralink-code:latest';
        $models[] = 'lyralink-reasoning:latest';
        $models[] = 'lyralink-creative:latest';
        $models = array_values(array_unique(array_filter(array_map('trim', $models), fn($m) => $m !== '')));
        return $models;
    }
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
    $provider = strtolower(trim($provider));
    if ($provider === 'hermes') {
        $provider = 'local';
    }

    if (!in_array($provider, ['local'], true)) {
        return false;
    }

    if ($provider === 'local') {
        return true;
    }

    $allowedProviders = array_map('strtolower', llm_allowed_providers_for_plan($plan));
    if (!$allowedProviders) {
        return true;
    }

    return in_array($provider, $allowedProviders, true)
        || ($provider === 'local' && in_array('hermes', $allowedProviders, true));
}

function llm_model_allowed_for_plan(string $provider, string $model, string $plan): bool {
    $providerModels = llm_provider_models($provider);
    if ($providerModels && !in_array($model, $providerModels, true)) {
        return false;
    }
    if (in_array(strtolower($provider), ['local', 'hermes'], true)) {
        return true;
    }
    $allowedModels = llm_allowed_models_for_plan($plan);
    if (!$allowedModels) {
        return true;
    }
    return in_array($model, $allowedModels, true);
}

function llm_first_valid_provider_for_plan(string $plan, array $preferred = []): string {
    $order = array_values(array_unique(array_merge($preferred, ['local', 'hermes', 'groq', 'openrouter', 'openai'])));
    foreach ($order as $provider) {
        $provider = strtolower(trim((string)$provider));
        if ($provider === 'hermes') {
            $provider = 'local';
        }
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

function chat_model_router_enabled(): bool {
    return api_get_secret('MODEL_ROUTER_ENABLED', '1') === '1';
}

function chat_model_router_map(): array {
    $defaultModel = trim((string)api_get_secret('MODEL_ROUTER_DEFAULT', api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest')));
    $fallbackModel = trim((string)api_get_secret('MODEL_ROUTER_FALLBACK', 'lyralink-fast:latest'));
    if ($defaultModel === '') {
        $defaultModel = 'lyralink-auto-canary:latest';
    }
    if ($fallbackModel === '') {
        $fallbackModel = 'lyralink-fast:latest';
    }

    return [
        'default' => $defaultModel,
        'fast' => trim((string)api_get_secret('MODEL_ROUTER_FAST', $defaultModel)),
        'code' => trim((string)api_get_secret('MODEL_ROUTER_CODE', $defaultModel)),
        'reasoning' => trim((string)api_get_secret('MODEL_ROUTER_REASONING', $defaultModel)),
        'creative' => trim((string)api_get_secret('MODEL_ROUTER_CREATIVE', $defaultModel)),
        'research' => trim((string)api_get_secret('MODEL_ROUTER_RESEARCH', api_get_secret('MODEL_ROUTER_REASONING', $defaultModel))),
        'fallback' => $fallbackModel,
    ];
}

function chat_detect_context_intent(
    string $latestUserMsg,
    bool $taskMode,
    string $taskFocus,
    bool $reasoningRequested,
    bool $webSearchRequested,
    array $datasetMatches,
    $attachmentMeta = null
): string {
    if (is_array($attachmentMeta)) {
        $attachmentType = strtolower((string)($attachmentMeta['type'] ?? ''));
        $attachmentMime = strtolower((string)($attachmentMeta['mime'] ?? ''));
        if (in_array($attachmentType, ['code', 'text'], true) || str_contains($attachmentMime, 'json') || str_contains($attachmentMime, 'xml')) {
            return 'code';
        }
    }

    $msg = strtolower(trim($latestUserMsg));
    $len = strlen($msg);

    if ($taskMode && in_array($taskFocus, ['build', 'debug'], true)) {
        return 'code';
    }
    if ($taskMode && in_array($taskFocus, ['plan', 'ship'], true)) {
        return 'reasoning';
    }
    if ($reasoningRequested) {
        return 'reasoning';
    }

    $codePattern = '/\b(code|php|javascript|typescript|python|sql|regex|function|class|api|endpoint|stack trace|debug|fix|bug|compile|test|refactor|deploy|docker|kubernetes|query)\b/i';
    if ($msg !== '' && preg_match($codePattern, $msg) === 1) {
        return 'code';
    }

    $creativePattern = '/\b(write|rewrite|reword|poem|lyrics|story|script|marketing copy|brand voice|caption|creative|bio|ad copy|slogan|email draft)\b/i';
    if ($msg !== '' && preg_match($creativePattern, $msg) === 1) {
        return 'creative';
    }

    $deepMultipartPattern = '/\b(?:part\s*(?:[1-9]|1[0-9]|2[0-9])\s*(?:of|out of)\s*(?:[2-9]|1[0-9]|2[0-9])\b|(?:[2-9]|1[0-9]|2[0-9])\s*[- ]parts?\b|\bfull\s*(?:architecture|roadmap|plan|breakdown|analysis|system|guide)\b|\bstep\s*by\s*step\b|\bpart\s*[1-9]\b|\b(?:deep|thorough|detailed)\s*(?:analysis|design|architecture|reasoning|plan)\b)/i';
    if ($msg !== '' && preg_match($deepMultipartPattern, $msg) === 1) {
        return 'reasoning';
    }

    $reasoningPattern = '/\b(compare|tradeoff|strategy|design|architecture|decision|analyze|reason|evaluate|pros and cons|best approach|roadmap|plan)\b/i';
    if ($msg !== '' && preg_match($reasoningPattern, $msg) === 1) {
        return 'reasoning';
    }

    $deterministicReasoningPattern = '/\b(calculate|compute|percentage|percentage point|relative reduction|roas|roi|distance|how far|logic puzzle|three boxes|misconception|false premise|saturn|sun orbits earth|water boils|vitamin c)\b/i';
    if ($msg !== '' && preg_match($deterministicReasoningPattern, $msg) === 1) {
        return 'reasoning';
    }

    if ($webSearchRequested && chat_should_use_web_search($latestUserMsg)) {
        return 'research';
    }
    if (!empty($datasetMatches) && $len > 80) {
        return 'reasoning';
    }

    if ($len <= 42 && !$taskMode && chat_is_fast_casual_prompt($latestUserMsg)) {
        return 'fast';
    }

    return 'default';
}

function chat_select_context_model(string $intent, string $plan, bool $degradedMode, array $routerMap, array &$routeMeta = []): string {
    $fallback = trim((string)($routerMap['fallback'] ?? 'lyralink-fast:latest'));
    if ($fallback === '') {
        $fallback = 'lyralink-fast:latest';
    }
    $default = trim((string)($routerMap['default'] ?? 'lyralink-auto-canary:latest'));
    if ($default === '') {
        $default = 'lyralink-auto-canary:latest';
    }

    $candidate = $default;
    if ($intent !== '' && !empty($routerMap[$intent])) {
        $candidate = trim((string)$routerMap[$intent]);
    }
    if ($candidate === '') {
        $candidate = $default;
    }

    $candidates = [
        $candidate,
        $default,
        $fallback,
        'lyralink-fast:latest',
        'lyralink-auto-canary:latest',
        'lyralink-code:latest',
        'lyralink-reasoning:latest',
        'lyralink-creative:latest',
        'hermes3:3b',
        'hermes3:8b',
    ];
    if ($degradedMode) {
        array_unshift($candidates, 'hermes3:3b', 'hermes3:8b');
    }
    $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates), fn($v) => $v !== '')));

    foreach ($candidates as $model) {
        if (llm_model_allowed_for_plan('local', $model, $plan)) {
            $routeMeta = [
                'intent' => $intent,
                'selected_model' => $model,
                'requested_model' => $candidate,
                'default_model' => $default,
                'fallback_model' => $fallback,
            ];
            return $model;
        }
    }

    $safeModel = llm_first_valid_model_for_plan('local', $plan);
    $routeMeta = [
        'intent' => $intent,
        'selected_model' => $safeModel,
        'requested_model' => $candidate,
        'default_model' => $default,
        'fallback_model' => $fallback,
    ];
    return $safeModel;
}

