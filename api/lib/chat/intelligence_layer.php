<?php

function chat_intelligence_enabled(): bool {
    return api_get_secret('CHAT_INTELLIGENCE_LAYER_ENABLED', '1') === '1';
}

function chat_intelligence_state_dir(): string {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/storage/intelligence_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function chat_intelligence_state_path(string $projectStateKey): string {
    return chat_intelligence_state_dir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $projectStateKey) . '.json';
}

function chat_intelligence_default_state(string $projectId): array {
    return [
        'project_id' => $projectId,
        'world' => [
            'users' => [],
            'projects' => [],
            'services' => [],
            'goals' => [],
            'tasks' => [],
            'resources' => [],
            'models' => [],
            'experiments' => [],
            'knowledge_domains' => [],
        ],
        'timeline' => [],
        'last_plan' => null,
        'last_improvements' => [],
        'updated_at' => null,
    ];
}

function chat_intelligence_load(string $projectStateKey, string $projectId): array {
    $path = chat_intelligence_state_path($projectStateKey);
    if (!is_readable($path)) {
        return chat_intelligence_default_state($projectId);
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return chat_intelligence_default_state($projectId);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return chat_intelligence_default_state($projectId);
    }
    $state = array_merge(chat_intelligence_default_state($projectId), $decoded);
    $state['world'] = is_array($state['world'] ?? null) ? $state['world'] : chat_intelligence_default_state($projectId)['world'];
    $state['timeline'] = array_values(array_slice(is_array($state['timeline'] ?? null) ? $state['timeline'] : [], -20));
    $state['last_improvements'] = array_values(array_slice(is_array($state['last_improvements'] ?? null) ? $state['last_improvements'] : [], -8));
    return $state;
}

function chat_intelligence_save(string $projectStateKey, array $state): void {
    $path = chat_intelligence_state_path($projectStateKey);
    $state['updated_at'] = gmdate('c');
    $state['timeline'] = array_values(array_slice(is_array($state['timeline'] ?? null) ? $state['timeline'] : [], -20));
    $state['last_improvements'] = array_values(array_slice(is_array($state['last_improvements'] ?? null) ? $state['last_improvements'] : [], -8));
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function chat_intelligence_extract_terms(string $text, int $limit = 8): array {
    $tokens = preg_split('/[^a-zA-Z0-9_\-]+/', strtolower(trim($text))) ?: [];
    $out = [];
    foreach ($tokens as $token) {
        $token = trim((string)$token);
        if ($token === '' || strlen($token) < 4) {
            continue;
        }
        if (in_array($token, ['that', 'this', 'with', 'from', 'have', 'what', 'when', 'where', 'will', 'your', 'about', 'into'], true)) {
            continue;
        }
        $out[$token] = true;
        if (count($out) >= $limit) {
            break;
        }
    }
    return array_keys($out);
}

function chat_intelligence_merge_named_items(array $existing, array $items, string $nameKey = 'name'): array {
    $index = [];
    foreach ($existing as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtolower(trim((string)($item[$nameKey] ?? '')));
        if ($name !== '') {
            $index[$name] = $item;
        }
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtolower(trim((string)($item[$nameKey] ?? '')));
        if ($name === '') {
            continue;
        }
        $base = $index[$name] ?? [];
        $index[$name] = array_merge($base, $item, ['updated_at' => gmdate('c')]);
    }
    return array_values($index);
}

function chat_intelligence_capability_plan(array $context): array {
    $message = strtolower(trim((string)($context['latest_user_message'] ?? '')));
    $taskMode = (bool)($context['task_mode'] ?? false);
    $taskFocus = strtolower(trim((string)($context['task_focus'] ?? 'general')));
    $attachmentType = strtolower(trim((string)($context['attachment_type'] ?? '')));
    $needsFresh = (bool)($context['needs_fresh_web'] ?? false);
    $plan = [];

    $plan[] = [
        'capability' => 'brain',
        'priority' => 'core',
        'reason' => $taskMode ? 'Mission mode requires coordinated reasoning.' : 'Base reasoning is required for every response.',
    ];

    if ($needsFresh || preg_match('/\b(news|headline|latest|today|current|right now|research)\b/i', $message) === 1) {
        $plan[] = ['capability' => 'web', 'priority' => 'high', 'reason' => 'The request depends on fresh external information.'];
    }
    if (!empty($context['dataset_matches'])) {
        $plan[] = ['capability' => 'memory', 'priority' => 'high', 'reason' => 'Relevant internal memory was found for this request.'];
    }
    if (preg_match('/\b(code|php|javascript|python|bug|debug|fix|refactor|api|endpoint|deploy|server|linux|sql)\b/i', $message) === 1 || in_array($taskFocus, ['build', 'debug', 'ship'], true)) {
        $plan[] = ['capability' => 'code', 'priority' => 'high', 'reason' => 'The request involves technical execution or debugging.'];
    }
    if (preg_match('/\b(finance|market|stock|revenue|pricing|financial)\b/i', $message) === 1) {
        $plan[] = ['capability' => 'finance', 'priority' => 'medium', 'reason' => 'Financial reasoning or tooling may be relevant.'];
    }
    if ($attachmentType === 'image' || preg_match('/\b(image|photo|screenshot|vision)\b/i', $message) === 1) {
        $plan[] = ['capability' => 'vision', 'priority' => 'medium', 'reason' => 'Visual understanding is relevant to the request.'];
    }
    if (preg_match('/\b(voice|speak|microphone|audio|tts|stt)\b/i', $message) === 1) {
        $plan[] = ['capability' => 'voice', 'priority' => 'medium', 'reason' => 'Voice I/O is relevant to the interaction.'];
    }
    if (!empty($context['training_enabled']) || preg_match('/\b(train|fine-?tune|lora|benchmark|evaluate model|weights?)\b/i', $message) === 1) {
        $plan[] = ['capability' => 'ml_lab', 'priority' => 'medium', 'reason' => 'Model improvement infrastructure is directly relevant.'];
    }

    $plan[] = [
        'capability' => 'orchestrator',
        'priority' => $taskMode ? 'high' : 'medium',
        'reason' => 'Multiple subsystems may need coordination for the best result.',
    ];

    return array_values($plan);
}

function chat_intelligence_scientific_loop(array $context): array {
    $taskMode = (bool)($context['task_mode'] ?? false);
    $verificationPassed = (bool)($context['verification_passed'] ?? false);
    $reply = trim((string)($context['reply'] ?? ''));
    $hypothesis = 'Clarify the environment, pick the right capabilities, then verify the result.';
    if ($taskMode) {
        $hypothesis = 'Solve the task by composing planning, execution, and verification instead of only answering.';
    }
    return [
        'observe' => [
            'message' => trim((string)($context['latest_user_message'] ?? '')),
            'freshness_sensitive' => (bool)($context['needs_fresh_web'] ?? false),
            'task_focus' => strtolower(trim((string)($context['task_focus'] ?? 'general'))),
        ],
        'hypothesize' => $hypothesis,
        'plan' => array_map(static fn($item) => (string)($item['capability'] ?? ''), is_array($context['capability_plan'] ?? null) ? $context['capability_plan'] : []),
        'measure' => [
            'verification_passed' => $verificationPassed,
            'reply_present' => $reply !== '',
            'confidence' => $context['confidence_label'] ?? 'unknown',
        ],
        'verify' => $verificationPassed ? 'verified' : 'needs_followup',
    ];
}

function chat_intelligence_improvement_signals(array $context): array {
    $signals = [];
    $confidenceScore = (float)($context['confidence_score'] ?? 0.0);
    $requestMs = (int)($context['request_ms'] ?? 0);
    $verificationPassed = (bool)($context['verification_passed'] ?? false);
    $needsFresh = (bool)($context['needs_fresh_web'] ?? false);
    $webResults = (int)($context['web_results'] ?? 0);
    $taskCount = (int)($context['active_task_count'] ?? 0);

    if ($confidenceScore < 0.6) {
        $signals[] = ['title' => 'Low confidence slice', 'action' => 'Route similar requests to stronger reasoning or add better evidence.', 'severity' => 'warn'];
    }
    if (!$verificationPassed) {
        $signals[] = ['title' => 'Verification gap', 'action' => 'Add a verification step or narrower tests before finalizing answers.', 'severity' => 'high'];
    }
    if ($needsFresh && $webResults < 2) {
        $signals[] = ['title' => 'Weak fresh retrieval', 'action' => 'Expand curated live sources for freshness-sensitive topics.', 'severity' => 'high'];
    }
    if ($requestMs >= 12000) {
        $signals[] = ['title' => 'Latency hotspot', 'action' => 'Prefer faster models or trim retrieval for similar requests.', 'severity' => 'warn'];
    }
    if ($taskCount > 0) {
        $signals[] = ['title' => 'Long-horizon work detected', 'action' => 'Preserve state and keep task dependencies explicit across runs.', 'severity' => 'info'];
    }
    return $signals;
}

function chat_intelligence_update_state(array $state, array $context): array {
    $world = is_array($state['world'] ?? null) ? $state['world'] : chat_intelligence_default_state((string)($state['project_id'] ?? 'default'))['world'];
    $projectId = trim((string)($context['project_id'] ?? 'default')) ?: 'default';
    $latestUserMsg = trim((string)($context['latest_user_message'] ?? ''));
    $taskFocus = trim((string)($context['task_focus'] ?? 'general')) ?: 'general';
    $provider = trim((string)($context['provider'] ?? ''));
    $model = trim((string)($context['model'] ?? ''));
    $projectProgress = is_array($context['project_progress'] ?? null) ? $context['project_progress'] : [];
    $persistentGoals = is_array($context['persistent_goals'] ?? null) ? $context['persistent_goals'] : [];
    $capabilityPlan = is_array($context['capability_plan'] ?? null) ? $context['capability_plan'] : [];

    $world['projects'] = chat_intelligence_merge_named_items(is_array($world['projects'] ?? null) ? $world['projects'] : [], [[
        'name' => $projectId,
        'status' => (($projectProgress['completion_pct'] ?? 0) >= 100) ? 'complete' : 'active',
        'completion_pct' => (int)($projectProgress['completion_pct'] ?? 0),
        'task_focus' => $taskFocus,
    ]]);

    $goalRows = [];
    foreach ($persistentGoals as $goal) {
        if (!is_array($goal)) {
            continue;
        }
        $title = trim((string)($goal['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $goalRows[] = [
            'name' => $title,
            'status' => trim((string)($goal['status'] ?? 'active')) ?: 'active',
        ];
    }
    $world['goals'] = chat_intelligence_merge_named_items(is_array($world['goals'] ?? null) ? $world['goals'] : [], $goalRows);

    $world['models'] = chat_intelligence_merge_named_items(is_array($world['models'] ?? null) ? $world['models'] : [], [[
        'name' => $model !== '' ? $model : 'unknown',
        'provider' => $provider !== '' ? $provider : 'unknown',
        'task_focus' => $taskFocus,
    ]]);

    $capRows = array_map(static fn($item) => [
        'name' => (string)($item['capability'] ?? ''),
        'priority' => (string)($item['priority'] ?? 'medium'),
        'reason' => (string)($item['reason'] ?? ''),
    ], $capabilityPlan);
    $world['services'] = chat_intelligence_merge_named_items(is_array($world['services'] ?? null) ? $world['services'] : [], $capRows);

    $terms = chat_intelligence_extract_terms($latestUserMsg, 8);
    $domainRows = array_map(static fn($term) => ['name' => $term], $terms);
    $world['knowledge_domains'] = chat_intelligence_merge_named_items(is_array($world['knowledge_domains'] ?? null) ? $world['knowledge_domains'] : [], $domainRows);

    $state['world'] = $world;
    $state['timeline'][] = [
        'at' => gmdate('c'),
        'message_excerpt' => substr($latestUserMsg, 0, 180),
        'task_focus' => $taskFocus,
        'capabilities' => array_values(array_filter(array_map(static fn($item) => (string)($item['capability'] ?? ''), $capabilityPlan))),
        'verification_passed' => (bool)($context['verification_passed'] ?? false),
    ];
    $state['last_plan'] = $capabilityPlan;
    $state['last_improvements'] = chat_intelligence_improvement_signals($context);
    return $state;
}

function chat_intelligence_prompt(array $state, array $capabilityPlan, array $scientificLoop): string {
    $world = is_array($state['world'] ?? null) ? $state['world'] : [];
    $projectCount = count(is_array($world['projects'] ?? null) ? $world['projects'] : []);
    $goalCount = count(is_array($world['goals'] ?? null) ? $world['goals'] : []);
    $serviceCount = count(is_array($world['services'] ?? null) ? $world['services'] : []);
    $capabilityNames = array_values(array_filter(array_map(static fn($item) => (string)($item['capability'] ?? ''), $capabilityPlan)));
    $capabilityText = !empty($capabilityNames) ? implode(', ', $capabilityNames) : 'brain';
    $verifyState = (string)($scientificLoop['verify'] ?? 'needs_followup');

    return "Intelligence operating layer is active. Maintain an internal world model of the user, project, active goals, services, models, and recent system observations. Choose the smallest effective combination of capabilities instead of answering from one mode by default. Current world state: {$projectCount} projects, {$goalCount} goals, {$serviceCount} known capabilities/services. Planned capabilities for this request: {$capabilityText}. Follow an internal loop of observe, hypothesize, plan, measure, and verify. Current verification state: {$verifyState}. Keep answers grounded in evidence, expose uncertainty when support is thin, and prefer coordinated action over generic explanation.";
}

function chat_intelligence_payload(array $state, array $capabilityPlan, array $scientificLoop, array $improvements): array {
    $world = is_array($state['world'] ?? null) ? $state['world'] : [];
    return [
        'enabled' => true,
        'world_state' => [
            'projects' => count(is_array($world['projects'] ?? null) ? $world['projects'] : []),
            'goals' => count(is_array($world['goals'] ?? null) ? $world['goals'] : []),
            'services' => count(is_array($world['services'] ?? null) ? $world['services'] : []),
            'models' => count(is_array($world['models'] ?? null) ? $world['models'] : []),
            'domains' => array_slice(array_map(static fn($item) => (string)($item['name'] ?? ''), is_array($world['knowledge_domains'] ?? null) ? $world['knowledge_domains'] : []), -6),
        ],
        'capability_plan' => $capabilityPlan,
        'scientific_loop' => $scientificLoop,
        'improvement_signals' => $improvements,
        'timeline' => array_slice(is_array($state['timeline'] ?? null) ? $state['timeline'] : [], -5),
    ];
}
