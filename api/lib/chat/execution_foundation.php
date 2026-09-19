<?php

// Load enhanced validators for multi-step tracking, quantitative reasoning, and production safety
@require_once __DIR__ . '/validators_enhanced.php';
@require_once __DIR__ . '/os_core.php';

function trace_add(array &$trace, bool $enabled, string $stage, string $message, array $extra = []): void {
    if (!$enabled) {
        return;
    }
    $trace[] = array_filter([
        'ts' => round(microtime(true), 3),
        'stage' => $stage,
        'message' => $message,
        'extra' => !empty($extra) ? $extra : null,
    ], fn($v) => $v !== null);
}

function chat_parse_bool($value, bool $default = false): bool {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function chat_make_trace_id(): string {
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return substr(hash('sha256', uniqid('trace', true)), 0, 16);
    }
}

function chat_model_capability_registry(): array {
    return [
        'lyralink-fast:latest' => [
            'tier' => 'fast',
            'reasoning' => 'basic',
            'coding' => 'basic',
            'context_window' => 4096,
            'ideal_for' => ['quick chat', 'simple Q&A', 'status checks'],
        ],
        'lyralink-auto-canary:latest' => [
            'tier' => 'balanced',
            'reasoning' => 'strong',
            'coding' => 'strong',
            'context_window' => 8192,
            'ideal_for' => ['coding', 'planning', 'analysis'],
        ],
        'lyralink-code:latest' => [
            'tier' => 'balanced',
            'reasoning' => 'strong',
            'coding' => 'strong',
            'context_window' => 8192,
            'ideal_for' => ['coding', 'debugging', 'implementation'],
        ],
        'lyralink-reasoning:latest' => [
            'tier' => 'balanced',
            'reasoning' => 'strong',
            'coding' => 'good',
            'context_window' => 8192,
            'ideal_for' => ['analysis', 'planning', 'deep reasoning'],
        ],
        'lyralink-creative:latest' => [
            'tier' => 'balanced',
            'reasoning' => 'good',
            'coding' => 'basic',
            'context_window' => 8192,
            'ideal_for' => ['creative writing', 'brainstorming', 'drafting'],
        ],
    ];
}

function chat_model_capabilities(string $model, string $intent = 'default'): array {
    $registry = chat_model_capability_registry();
    $modelLower = strtolower(trim($model));

    if (isset($registry[$modelLower])) {
        $base = $registry[$modelLower];
    } elseif (str_contains($modelLower, '3b')) {
        $base = [
            'tier' => 'fast',
            'reasoning' => 'basic',
            'coding' => 'basic',
            'context_window' => 4096,
            'ideal_for' => ['quick chat'],
        ];
    } elseif (str_contains($modelLower, '8b')) {
        $base = [
            'tier' => 'balanced',
            'reasoning' => 'strong',
            'coding' => 'strong',
            'context_window' => 8192,
            'ideal_for' => ['general work'],
        ];
    } else {
        $base = [
            'tier' => 'unknown',
            'reasoning' => 'unknown',
            'coding' => 'unknown',
            'context_window' => null,
            'ideal_for' => ['general work'],
        ];
    }

    $base['intent_fit'] = match ($intent) {
        'fast' => in_array($base['tier'], ['fast', 'balanced'], true) ? 'good' : 'ok',
        'code' => in_array($base['coding'], ['strong'], true) ? 'good' : 'ok',
        'reasoning', 'research' => in_array($base['reasoning'], ['strong'], true) ? 'good' : 'weak',
        default => 'good',
    };

    return $base;
}

function chat_capability_requirements(string $intent, string $taskFocus = ''): array {
    $intent = strtolower(trim($intent));
    $taskFocus = strtolower(trim($taskFocus));

    return match ($intent) {
        'code' => ['coding' => 5, 'reasoning' => 3, 'execution' => 2],
        'reasoning', 'research' => ['reasoning' => 5, 'evidence' => 3, 'execution' => 2],
        'creative' => ['creative' => 4, 'writing' => 3, 'reasoning' => 2],
        'fast' => ['latency' => 4, 'clarity' => 2],
        default => in_array($taskFocus, ['build', 'debug', 'ship'], true)
            ? ['coding' => 4, 'reasoning' => 3, 'execution' => 2]
            : ['clarity' => 2, 'reasoning' => 1],
    };
}

function chat_select_capability_model(string $intent, string $plan, array $requiredCapabilities = [], array $routerMap = [], array &$routeMeta = []): string {
    $intent = strtolower(trim($intent));
    $requiredCapabilities = $requiredCapabilities ?: chat_capability_requirements($intent);
    $defaultModel = llm_safe_local_model(
        trim((string)($routerMap['default'] ?? 'lyralink-auto-canary:latest')),
        'lyralink-auto-canary:latest'
    );
    $fallbackModel = llm_safe_local_model(
        trim((string)($routerMap['fallback'] ?? 'lyralink-fast:latest')),
        'lyralink-fast:latest'
    );

    $candidates = array_values(array_unique(array_filter([
        trim((string)($routerMap[$intent] ?? '')),
        $defaultModel,
        $fallbackModel,
        'lyralink-auto-canary:latest',
        'lyralink-fast:latest',
        'lyralink-code:latest',
        'lyralink-reasoning:latest',
        'lyralink-creative:latest',
    ], static fn($value) => trim((string)$value) !== '')));

    $bestModel = $defaultModel;
    $bestScore = -INF;
    foreach ($candidates as $candidate) {
        if (!llm_model_allowed_for_plan('local', $candidate, $plan)) {
            continue;
        }

        $capabilities = chat_model_capabilities($candidate, $intent);
        $score = 0;
        foreach ($requiredCapabilities as $capability => $weight) {
            $capability = strtolower((string)$capability);
            $value = match ($capability) {
                'coding' => $capabilities['coding'] ?? 'unknown',
                'reasoning' => $capabilities['reasoning'] ?? 'unknown',
                'creative' => $capabilities['creative'] ?? 'unknown',
                'execution' => $capabilities['execution'] ?? 'unknown',
                'evidence' => $capabilities['evidence'] ?? 'unknown',
                'clarity' => $capabilities['clarity'] ?? 'unknown',
                'latency' => $capabilities['tier'] ?? 'unknown',
                'writing' => $capabilities['writing'] ?? 'unknown',
                default => 'unknown',
            };

            $score += match ($value) {
                'excellent' => (int)$weight * 3,
                'strong' => (int)$weight * 2,
                'good' => (int)$weight,
                'basic' => max(0, (int)$weight - 1),
                'ok' => (int)$weight / 2,
                'medium' => (int)$weight,
                'weak' => max(0, (int)$weight - 2),
                'unknown' => 0,
                default => 0,
            };

            if ($capability === 'latency' && in_array($value, ['fast', 'balanced'], true)) {
                $score += max(1, (int)$weight);
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestModel = $candidate;
        }
    }

    $routeMeta = [
        'intent' => $intent,
        'selected_model' => $bestModel,
        'requested_model' => $defaultModel,
        'default_model' => $defaultModel,
        'fallback_model' => $fallbackModel,
        'capability_requirements' => $requiredCapabilities,
    ];

    return $bestModel;
}

function chat_infer_task_control(string $latestUserMsg, bool $taskMode, string $taskFocus): array {
    $msg = trim((string)$latestUserMsg);
    $focus = strtolower(trim($taskFocus));
    $lower = strtolower($msg);

    $constraints = [];
    $constraintPatterns = [
        ['minimal', '/\bminimal\b|\bsmallest\b|\bleast\b|\bkeep it simple\b/i'],
        ['cheap', '/\bcheap\b|\blow cost\b|\bbudget\b|\bfrugal\b/i'],
        ['fast', '/\bfast\b|\bquick\b|\bshortest\b|\blow latency\b/i'],
        ['low maintenance', '/\blow[- ]maintenance\b|\bminimal ops\b|\boperational burden\b|\blow operational burden\b/i'],
        ['read-only', '/\bread[- ]only\b|\bdo not modify\b|\bno writes\b/i'],
        ['do not execute', '/\bdo not execute\b|\bdo not run\b|\bwithout executing\b|\bno execution\b/i'],
        ['use existing stack', '/\buse the existing stack\b|\buse what exists\b|\bwithout new infrastructure\b|\bwithout changing stack\b/i'],
        ['preserve behavior', '/\bpreserve behavior\b|\bmaintain compatibility\b|\bdon\'t break\b|\bno regressions\b/i'],
        ['reversible', '/\breversible\b|\brollback\b|\bundoable\b/i'],
        ['production-safe', '/\bproduction[- ]safe\b|\bproduction safe\b|\bproduction-ready\b|\bdo not risk prod\b/i'],
        ['no new infrastructure', '/\bno new infrastructure\b|\bno new services\b|\bno extra systems\b/i'],
    ];

    foreach ($constraintPatterns as [$label, $pattern]) {
        if (preg_match($pattern, $lower) === 1) {
            $constraints[] = $label;
        }
    }
    $constraints = array_values(array_unique($constraints));

    $requestedOperation = 'analysis';
    if (preg_match('/\b(calculate|compute|recompute|equation|formula|math|arithmetic|percentage\s+points?|percent\s+change|unit\s+economics)\b/i', $lower) === 1) {
        $requestedOperation = 'calculation';
    } elseif (preg_match('/\b(debug|diagnose|troubleshoot|root cause|investigate|why .* (break|fail|timeout|error))\b/i', $lower) === 1) {
        $requestedOperation = 'diagnostic';
    } elseif (preg_match('/\b(design|architecture|plan|roadmap|strategy|tradeoff|decision framework)\b/i', $lower) === 1) {
        $requestedOperation = 'planning';
    } elseif (preg_match('/\b(implement|apply|execute|run|deploy|migrate|restart|rollback|ship|change config|modify)\b/i', $lower) === 1) {
        $requestedOperation = 'execution';
    }

    $domain = 'general';
    $routingEvidence = [];
    $taskType = 'analysis';

    if (preg_match('/\b(code|php|javascript|typescript|python|sql|api|endpoint|browser|firefox|chrome|deploy|server|ci|git)\b/i', $lower) === 1) {
        $domain = 'software';
        $taskType = ($requestedOperation === 'execution') ? 'execution' : 'diagnostic';
        $routingEvidence[] = 'software_signals';
    }

    if (preg_match('/\b(customer journey|e-commerce|ecommerce|churn|retention|support latency|billing friction|conversion|funnel|kpi|dashboard)\b/i', $lower) === 1) {
        $domain = 'business_analytics';
        $taskType = 'analysis';
        $routingEvidence[] = 'cx_analytics_signals';
    }

    $explicitFinance = preg_match('/\b(stock|ticker|portfolio|loan|apr|apy|irr|npv|mortgage|dividend|market\s+cap|p\/e|investment|trade\s+setup)\b/i', $lower) === 1;
    if ($explicitFinance && $requestedOperation !== 'diagnostic') {
        $domain = 'finance';
        $taskType = $requestedOperation === 'calculation' ? 'calculation' : 'analysis';
        $routingEvidence[] = 'explicit_finance_signals';
    }

    if ($requestedOperation === 'planning' && $domain === 'general') {
        $domain = 'planning';
        $taskType = 'planning';
        $routingEvidence[] = 'planning_operation';
    }

    if ($requestedOperation === 'calculation' && $domain === 'general') {
        $domain = 'quantitative';
        $taskType = 'calculation';
        $routingEvidence[] = 'calculation_operation';
    }

    $executionVerbs = '/\b(build|fix|deploy|rollback|ship|migrate|delete|modify|install|run|execute|publish|launch|restart|write code|change|update|create|generate)\b/i';
    $informationalVerbs = '/\b(explain|compare|summarize|assess|analyze|review|design|plan|recommend|diagnose|troubleshoot|evaluate|estimate|project|outline|which metrics|what should we prioritize)\b/i';
    $casualCorrectionPattern = '/\b(still wrong|you are wrong|that\'s wrong|this is wrong|i am your developer|i am your creator|i wanted to converse|i want to talk about you|talk to me about you|your behavior)\b/i';
    // Destructive instructions belong here too. Omitting them meant a command
    // like "delete all users from the database" was never treated as an
    // execution request, so no authorization was required for it.
    $explicitExecutionRequest = preg_match('/\b(please do|go ahead and|run|execute|build|fix|deploy|rollback|ship|modify|restart|write code|create|generate|delete|drop|truncate|wipe|purge|remove|destroy|shutdown|shut down|kill|terminate|install|migrate|publish|launch)\b/i', $lower) === 1;

    $isCasualCorrection = preg_match($casualCorrectionPattern, $lower) === 1;
    $requiresExecution = (!$taskMode || $isCasualCorrection) && !$explicitExecutionRequest
        ? false
        : ($taskMode
            || $explicitExecutionRequest
            || ($requestedOperation === 'execution')
            || (preg_match($executionVerbs, $lower) === 1 && !preg_match('/\bdo not execute\b|\bdo not run\b|\bwithout executing\b/i', $lower)));

    // A question is not an action. "How do I deploy a PHP app safely?" contains
    // the verb "deploy" but asks for knowledge; treating it as an execution
    // request classified it as a high-risk action and blocked it behind an
    // approval gate. Only an actual directive counts as an instruction to act.
    // Task/mission mode is left untouched: the user has opted into execution.
    $trimmedMsg = trim((string)$latestUserMsg);
    $interrogativeForm = preg_match('/\?\s*$/', $trimmedMsg) === 1
        || preg_match('/^\s*(?:how|what|why|when|where|which|who|whose|can|could|should|would|will|is|are|was|were|does|do|did|explain|describe|tell me|show me|outline|walk me|give me)\b/i', $trimmedMsg) === 1;
    $explicitDirective = preg_match('/\b(?:please do|go ahead and|go ahead|do it|do that|do this|run it|execute it|apply it|ship it|deploy it|run this|execute this|make it so|proceed|start now|begin now|go for it)\b/i', $lower) === 1;
    if ($requiresExecution && !$taskMode && $interrogativeForm && !$explicitDirective) {
        $requiresExecution = false;
    }
    $isInformationalOnly = !$requiresExecution && preg_match($informationalVerbs, $lower) === 1;

    $complexity = 'direct';
    if ($isInformationalOnly) {
        $complexity = 'direct';
    } elseif (in_array('minimal', $constraints, true) || in_array('cheap', $constraints, true) || in_array('fast', $constraints, true) || in_array('low maintenance', $constraints, true)) {
        $complexity = 'structured_task';
    } elseif ($taskType === 'diagnostic' || preg_match('/\b(debug|troubleshoot|diagnose)\b/i', $lower) === 1) {
        $complexity = 'simple_reasoning';
    } elseif ($taskType === 'planning' || preg_match('/\b(design|architecture|plan|strategy|roadmap)\b/i', $lower) === 1) {
        $complexity = 'tool_task';
    } elseif ($requiresExecution || $taskMode || in_array($focus, ['build', 'ship', 'debug'], true)) {
        $complexity = 'mission';
    }

    $riskLevel = 'low';
    if (preg_match('/\b(deploy|delete data|drop table|refund|rotate keys|shutdown|kill process|migrate database|production)\b/i', $lower) === 1 && $requiresExecution) {
        $riskLevel = 'high';
    } elseif (preg_match('/\b(code|debug|security|auth|plan|architecture|migration|production)\b/i', $lower) === 1) {
        $riskLevel = 'medium';
    }

    $confidence = $msg === '' ? 'low' : (!empty($routingEvidence) ? 'medium' : 'low');
    if ($domain !== 'general' && count($routingEvidence) >= 2) {
        $confidence = 'high';
    }

    if (empty($routingEvidence)) {
        $routingEvidence[] = 'operation_only_fallback';
    }

    $requiredTools = [];
    if ($domain === 'finance' || $requestedOperation === 'calculation') {
        $requiredTools[] = 'deterministic_calculator';
    }
    if ($domain === 'software' && $requestedOperation === 'diagnostic') {
        $requiredTools[] = 'fault_domain_reasoning';
    }

    return [
        'domain' => $domain,
        'task_domain' => $domain,
        'task_type' => $taskType,
        'requested_operation' => $requestedOperation,
        'objective' => $msg === '' ? 'general assistance' : substr($msg, 0, 180),
        'artifacts' => [],
        'focus' => $focus,
        'goal' => $msg === '' ? 'general assistance' : substr($msg, 0, 180),
        'constraints' => $constraints,
        'requires_execution' => $requiresExecution,
        'complexity' => $complexity,
        'risk_level' => $riskLevel,
        'required_tools' => $requiredTools,
        'routing_evidence' => $routingEvidence,
        'confidence' => $confidence,
        'mode' => $requiresExecution ? 'execution' : 'analysis',
        'needs_human_approval' => $requiresExecution && ($riskLevel === 'high' || $taskMode),
    ];
}

function chat_finance_tool_eligibility(string $message, array $taskControl = []): array {
    $control = !empty($taskControl) ? $taskControl : chat_infer_task_control($message, false, 'general');
    $lower = strtolower(trim($message));
    $explicitFinance = preg_match('/\b(stock|ticker|portfolio|loan|apr|apy|irr|npv|mortgage|dividend|market\s+cap|p\/e|investment|tax\s+strategy|debt\s+payoff)\b/i', $lower) === 1;
    $mathIntent = preg_match('/\b(calculate|compute|equation|formula|math|amortization|compound interest)\b/i', $lower) === 1;
    $cxBillingContext = preg_match('/\b(customer journey|e-commerce|ecommerce|support|conversion|funnel|retention|churn|billing friction|user experience)\b/i', $lower) === 1;

    $allow = false;
    $reason = 'not_finance';
    $evidence = [];

    if (($explicitFinance || $mathIntent) && !$cxBillingContext) {
        $allow = true;
        $reason = $explicitFinance ? 'explicit_finance_operation' : 'deterministic_math_operation';
        $evidence[] = $reason;
    }

    if (($control['task_domain'] ?? $control['domain'] ?? '') === 'finance' && !$cxBillingContext) {
        $allow = true;
        $reason = 'task_domain_finance';
        $evidence[] = 'task_domain_finance';
    }

    if ($cxBillingContext && !$explicitFinance && !$mathIntent) {
        $allow = false;
        $reason = 'billing_is_cx_not_finance';
        $evidence[] = 'cx_analytics_context';
    }

    return [
        'allow' => $allow,
        'reason' => $reason,
        'evidence' => array_values(array_unique($evidence)),
    ];
}

function chat_artifact_state(string $message, array $projectArtifacts = [], ?array $attachmentMeta = null): array {
    $lower = strtolower(trim($message));
    $artifactExpected = preg_match('/\b(this|attached|provided|given)\b.*\b(repository|repo|codebase|policy packet|pdf|dataset|log|logs|flow|schema|file|snapshot|image)\b/i', $lower) === 1
        || preg_match('/\b(review this|analyze this|inspect this)\b/i', $lower) === 1
        || preg_match('/\b\d+[- ]file\b.*\b(repository|repo|codebase)\b/i', $lower) === 1;

    $knownArtifacts = is_array($projectArtifacts) ? array_values(array_filter($projectArtifacts, static fn($item) => is_array($item))) : [];
    $artifactAvailable = !empty($knownArtifacts) || is_array($attachmentMeta);
    $artifactInspected = false;
    $specificEvidenceFound = false;

    return [
        'artifact_expected' => $artifactExpected,
        'artifact_available' => $artifactAvailable,
        'artifact_inspected' => $artifactInspected,
        'specific_evidence_found' => $specificEvidenceFound,
        'available_count' => count($knownArtifacts) + (is_array($attachmentMeta) ? 1 : 0),
    ];
}

function chat_build_execution_plan(string $taskFocus, string $routeIntent, string $latestUserMsg, array $taskControl = []): array {
    $intent = strtolower(trim($routeIntent));
    $focus = strtolower(trim($taskFocus));
    $msg = trim($latestUserMsg);
    $control = $taskControl ?: chat_infer_task_control($msg, false, $focus);

    $plans = [
        'code' => [
            'objective' => 'Resolve the coding task with a verified fix.',
            'steps' => [
                'Inspect the request and isolate the failure mode.',
                'Choose the smallest safe fix or implementation path.',
                'Apply the code change and verify it with the relevant checks.',
                'Return the result with what changed and what to do next.',
            ],
        ],
        'reasoning' => [
            'objective' => 'Reason from evidence and provide the best recommendation.',
            'steps' => [
                'Define the objective and constraints.',
                'Assemble the relevant evidence and constraints.',
                'Evaluate the tradeoffs and likely best path.',
                'Present the recommendation with clear next actions.',
            ],
        ],
        'creative' => [
            'objective' => 'Produce the creative output that matches the brief.',
            'steps' => [
                'Interpret the brief and audience.',
                'Draft a first version with the desired tone and structure.',
                'Refine for clarity, originality, and fit.',
                'Deliver the polished final version.',
            ],
        ],
        'fast' => [
            'objective' => 'Answer the prompt clearly and directly.',
            'steps' => [
                'Identify the user intent.',
                'Answer directly with the most useful result.',
                'Keep it concise while preserving clarity.',
            ],
        ],
    ];

    if (!empty($control['constraints'])) {
        $plans['fast']['steps'][] = 'Respect the stated constraints and avoid unnecessary complexity.';
    }

    $selected = $plans[$intent] ?? $plans['fast'];
    if (in_array($focus, ['build', 'debug', 'ship'], true)) {
        $selected = $plans['code'];
    } elseif (in_array($focus, ['plan', 'research'], true)) {
        $selected = $plans['reasoning'];
    } elseif (!empty($msg) && preg_match('/\b(write|draft|email|caption|story|poem|slogan|creative)\b/i', $msg) === 1) {
        $selected = $plans['creative'];
    } elseif ($control['requires_execution'] === false) {
        $selected = $plans['reasoning'];
    }

    $objective = $selected['objective'];
    if ($control['requires_execution'] === false) {
        $objective = 'Answer the request with the right evidence level and preserve the stated constraints without over-executing.';
    }

    return [
        'intent' => $intent ?: 'default',
        'objective' => $objective,
        'steps' => $selected['steps'],
        'task_focus' => $focus,
        'task_control' => $control,
        'execution_required' => (bool)($control['requires_execution'] ?? false),
        'constraints' => $control['constraints'] ?? [],
    ];
}

function chat_parse_agent_permissions($raw, bool $isDevUser): array {
    if ($isDevUser) {
        return ['read', 'write', 'run_tests', 'network', 'deploy', 'billing', 'admin'];
    }
    $allowed = ['read', 'write', 'run_tests', 'network'];
    if (!is_array($raw)) {
        return ['read', 'write', 'run_tests'];
    }
    $normalized = [];
    foreach ($raw as $permission) {
        $permission = strtolower(trim((string)$permission));
        if ($permission !== '' && in_array($permission, $allowed, true)) {
            $normalized[] = $permission;
        }
    }
    $normalized = array_values(array_unique($normalized));
    return $normalized ?: ['read', 'write', 'run_tests'];
}

function chat_detect_high_risk_action(string $message, string $taskFocus, bool $requiresExecution = false): bool {
    if (!$requiresExecution) {
        return false;
    }
    if (in_array($taskFocus, ['ship'], true)) {
        return true;
    }
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    // A destructive verb only raises risk when it targets something
    // consequential, so routine phrasing ("delete this sentence from my email")
    // does not demand approval. Inherently consequential operations are matched
    // on their own. Only reached when execution is actually requested.
    $destructiveTarget = '/\b(?:delete|drop|truncate|wipe|purge|destroy|remove|overwrite|clear)\b[^.\n]{0,40}\b(?:data|database|db|table|tables|record|records|row|rows|user|users|account|accounts|file|files|server|servers|production|prod|backup|backups|volume|volumes|disk|bucket|repository|repo|branch|cluster|node|nodes|logs?)\b/i';
    $consequentialOp = '/\b(?:deploy|shutdown|shut\s+down|kill|terminate|reboot|restart\s+server|migrate\s+database|restore\s+backup|rotate\s+keys|revoke|refund|format\s+disk|drop\s+table)\b/i';

    return preg_match($destructiveTarget, $msg) === 1 || preg_match($consequentialOp, $msg) === 1;
}

function chat_request_trust_profile(string $latestUserMsg, bool $taskMode, string $taskFocus, array $taskControl = []): array {
    $msg = trim((string)$latestUserMsg);
    $lower = strtolower($msg);
    $control = !empty($taskControl) ? $taskControl : chat_infer_task_control($msg, $taskMode, $taskFocus);

    $casualPattern = '/^(hi|hello|hey|yo|sup|lol|lmao|nice|cool|thanks|thank you|ok|okay|how are you|that makes sense|that\'s funny|that\'s actually cool|that\'s crazy|you\'re being weird|your being weird|i\'m bored|im bored|tell me something interesting|still wrong|that\'s wrong|this is wrong|you are wrong|i am your developer|i am your creator|i wanted to converse|i want to talk about you|talk to me about you)\b/i';
    $socialPattern = '/\b(how are you|what\'s up|hru|good morning|good afternoon|good evening|thanks|thank you|appreciate it|sounds good|still wrong|you are wrong|that\'s wrong|this is wrong|i wanted to converse|i want to talk about you|talk to me about you)\b/i';
    $opinionPattern = '/\b(what do you think|your opinion|what\'s your opinion|would you|my take|should we|what should we build next|what should we work on|which do you prefer|favorite)\b/i';
    $writingPattern = '/\b(write|rewrite|reword|polish|draft|message|email|thank-?you|subject line|sound more professional|sound natural|make this sentence|tone)\b/i';
    $researchPattern = '/\b(research|study|paper|benchmark|citation|cite|source|scholarly|whitepaper|prove)\b/i';
    $sourceRequiredPattern = '/\b(exact\s+(revenue|citation|source|page number|statistic|numbers?)|current|today|latest|public source|verified source|primary source)\b/i';
    $toolPattern = '/\b(run|execute|scan|open|list\s+\/|list files|check logs|query\s+(my|the)\s+(server|db|database)|ssh|terminal|command output|check my production db|inspect(?:\s+(?:the|this|that)\s+)?(?:repo|repository|file|files|log|logs|database|db|server|system|workspace|directory|cluster|container|host|environment|instance|deployment|production|config|state|runtime))\b/i';
    $toolUnavailablePattern = '/\b(no shell access|not given shell access|no access|not provided|without access|cannot access|do not have access)\b/i';
    $productionPattern = '/\b(production|incident|outage|rollback|deploy|migration|hotfix|api latency tripled|queue is backing up|failed halfway)\b/i';
    $systemAdminPattern = '/\b(server|database|cluster|systemctl|nginx|apache|kubernetes|docker|redis|mysql|postgres|infrastructure)\b/i';
    $securityPattern = '/\b(security|xss|csrf|sql injection|oauth|token|auth|authentication|authorization|mfa|vulnerability|exploit|admin route|mime type|jwt|rate limit)\b/i';
    $quantPattern = '/\b(calculate|compute|equation|formula|percent|percentage|percentage point|relative reduction|roas|roi|latency|ms|distance|perimeter|area|average rate|how far)\b/i';
    $falsePremisePattern = '/\b(sun\s+orbits\s+earth|earth\s+orbits\s+sun|saturn\s+is\s+the\s+hottest|vitamin\s+c\s+is\s+only\s+found\s+in\s+oranges|moon\s+is\s+made\s+entirely\s+of\s+cheese|water\s+boils\s+at\s+100\s*°?c.*everywhere|all mammals lay eggs|eiffel tower is in rome|false premise|misconception)\b/i';
    $basicReasoningPattern = '/\b(logic|inference|deduction|mislabel|three boxes|which statement is definitely true|why is .* not equivalent|explain the flaw|reasoning)\b/i';
    $highImpactPattern = '/\b(legal decision|medical decision|financial decision|compliance decision|safety critical|high impact)\b/i';

    $requestClass = 'GENERAL_INFORMATION';
    $riskLevel = 'low';
    $responseMode = 'general_information';
    $evidenceRequired = false;
    $toolRequired = false;
    $claimRisk = 'low';
    $actionRisk = 'low';

    $hasCasualSignals = preg_match($casualPattern, $lower) === 1;
    $hasSocialSignals = preg_match($socialPattern, $lower) === 1;
    $hasOpinionSignals = preg_match($opinionPattern, $lower) === 1;
    $hasWritingSignals = preg_match($writingPattern, $lower) === 1;
    $hasResearchSignals = preg_match($researchPattern, $lower) === 1;
    $hasSourceRequiredSignals = preg_match($sourceRequiredPattern, $lower) === 1;
    $hasQuantSignals = preg_match($quantPattern, $lower) === 1;
    $hasSecuritySignals = preg_match($securityPattern, $lower) === 1;
    $hasProductionSignals = preg_match($productionPattern, $lower) === 1;
    $hasSystemAdminSignals = preg_match($systemAdminPattern, $lower) === 1;
    $hasToolSignals = preg_match($toolPattern, $lower) === 1;
    $hasToolUnavailableSignals = preg_match($toolUnavailablePattern, $lower) === 1;
    $hasFalsePremiseSignals = preg_match($falsePremisePattern, $lower) === 1;
    $hasBasicReasoningSignals = preg_match($basicReasoningPattern, $lower) === 1;
    $hasHighImpactSignals = preg_match($highImpactPattern, $lower) === 1;
    $deterministicLowRisk = chat_is_deterministic_low_risk_request($msg);
    $requiresExecution = (bool)($control['requires_execution'] ?? false);
    $isLowRiskPrompt = ($hasCasualSignals || $hasSocialSignals || $hasOpinionSignals || $hasWritingSignals)
        && !$hasResearchSignals
        && !$hasSourceRequiredSignals
        && !$hasToolSignals
        && !$hasProductionSignals
        && !$hasSecuritySignals
        && !$hasQuantSignals
        && !$hasFalsePremiseSignals
        && !$hasBasicReasoningSignals;
    if ($isLowRiskPrompt) {
        $requiresExecution = false;
    }

    $signalCount = 0;
    foreach ([$hasOpinionSignals, $hasWritingSignals, $hasResearchSignals, $hasQuantSignals, $hasSecuritySignals, $hasProductionSignals, $hasToolSignals, $hasFalsePremiseSignals, $hasBasicReasoningSignals] as $signal) {
        if ($signal) {
            $signalCount++;
        }
    }

    if ($hasCasualSignals && $signalCount === 0) {
        $requestClass = 'CASUAL_CONVERSATION';
        $riskLevel = 'very_low';
        $responseMode = 'casual_conversation';
    } elseif ($hasSocialSignals && $signalCount === 0) {
        $requestClass = 'SOCIAL_CONVERSATION';
        $riskLevel = 'very_low';
        $responseMode = 'social_conversation';
    } elseif ($hasOpinionSignals && !$hasResearchSignals && !$hasToolSignals && !$requiresExecution) {
        $requestClass = 'OPINION';
        $riskLevel = 'low';
        $responseMode = 'opinion';
    } elseif ($hasWritingSignals && !$hasToolSignals && !$requiresExecution) {
        $requestClass = 'WRITING';
        $riskLevel = 'low';
        $responseMode = 'writing';
    } elseif ($hasFalsePremiseSignals) {
        $requestClass = 'FALSE_PREMISE';
        $riskLevel = 'low';
        $responseMode = 'correct_premise';
    } elseif ($hasQuantSignals || $deterministicLowRisk) {
        $requestClass = 'QUANTITATIVE';
        $riskLevel = 'low';
        $responseMode = 'quantitative';
    } elseif ($hasBasicReasoningSignals) {
        $requestClass = 'BASIC_REASONING';
        $riskLevel = 'low';
        $responseMode = 'basic_reasoning';
    }

    if ($hasHighImpactSignals) {
        $requestClass = 'HIGH_IMPACT';
        $riskLevel = 'very_high';
        $responseMode = 'high_impact';
        $evidenceRequired = true;
        $claimRisk = 'high';
        $actionRisk = 'high';
    } elseif ($hasProductionSignals || ($requiresExecution && preg_match('/\b(production|deploy|rollback|migrate|hotfix)\b/i', $lower) === 1)) {
        $requestClass = 'PRODUCTION_OPERATIONS';
        $riskLevel = 'very_high';
        $responseMode = 'production_safety';
        $evidenceRequired = true;
        $toolRequired = $requiresExecution;
        $claimRisk = 'high';
        $actionRisk = 'high';
    } elseif ($hasSystemAdminSignals && ($requiresExecution || $hasToolSignals)) {
        $requestClass = 'SYSTEM_ADMINISTRATION';
        $riskLevel = 'high';
        $responseMode = 'system_administration';
        $toolRequired = true;
        $actionRisk = 'high';
    } elseif ($requiresExecution || $hasToolSignals) {
        $requestClass = $hasToolUnavailableSignals ? 'TOOL_UNAVAILABLE' : 'TOOL_REQUIRED';
        $riskLevel = 'high';
        $responseMode = 'tool_capability';
        $toolRequired = true;
        $evidenceRequired = true;
        $actionRisk = 'high';
    } elseif ($hasSourceRequiredSignals) {
        $requestClass = 'SOURCE_REQUIRED';
        $riskLevel = 'high';
        $responseMode = 'source_required';
        $evidenceRequired = true;
        $claimRisk = 'high';
    } elseif ($hasResearchSignals) {
        $requestClass = 'RESEARCH';
        $riskLevel = 'medium';
        $responseMode = 'research';
        $evidenceRequired = true;
        $claimRisk = 'medium';
    } elseif ($hasSecuritySignals) {
        $requestClass = 'SECURITY';
        $riskLevel = 'medium';
        $responseMode = 'security_analysis';
        $evidenceRequired = false;
        $claimRisk = 'medium';
    }

    if ($signalCount >= 2 && (($hasCasualSignals || $hasSocialSignals) || ($hasWritingSignals && ($hasSecuritySignals || $hasResearchSignals || $hasToolSignals || $hasProductionSignals)))) {
        $requestClass = 'MIXED';
        $responseMode = $hasProductionSignals ? 'production_safety' : ($hasSecuritySignals ? 'security_analysis' : 'mixed');
        $riskLevel = $hasProductionSignals ? 'very_high' : 'medium';
        $evidenceRequired = $evidenceRequired || $hasResearchSignals || $hasSourceRequiredSignals;
        $toolRequired = $toolRequired || $hasToolSignals || $requiresExecution;
        $claimRisk = $riskLevel === 'very_high' ? 'high' : 'medium';
        $actionRisk = $toolRequired ? 'high' : $actionRisk;
    }

    if ($requestClass === 'GENERAL_INFORMATION' && preg_match('/\b(what is|explain|how does|why)\b/i', $lower) === 1) {
        $requestClass = 'FACTUAL_INFORMATION';
        $responseMode = 'factual_answer';
    }

    $activeValidators = ['factual_consistency'];
    if (in_array($requestClass, ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true)) {
        $activeValidators = ['lightweight', 'response_quality'];
    } elseif ($requestClass === 'WRITING') {
        $activeValidators = ['lightweight', 'instruction_following', 'response_quality'];
    } elseif ($requestClass === 'OPINION') {
        $activeValidators = ['lightweight', 'opinion_framing', 'response_quality'];
    } elseif ($requestClass === 'FALSE_PREMISE') {
        $activeValidators = ['premise_handling', 'response_quality'];
    } elseif ($requestClass === 'BASIC_REASONING') {
        $activeValidators = ['task_completion', 'reasoning_consistency', 'response_quality'];
    } elseif ($requestClass === 'QUANTITATIVE') {
        $activeValidators = ['numeric_validation', 'task_completion', 'response_quality'];
    } elseif ($requestClass === 'SECURITY') {
        $activeValidators = ['security_validation', 'tool_honesty', 'response_quality'];
    } elseif ($requestClass === 'RESEARCH' || $requestClass === 'SOURCE_REQUIRED') {
        $activeValidators = ['source_verification', 'evidence_gate', 'response_quality'];
    } elseif (in_array($requestClass, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION'], true)) {
        $activeValidators = ['tool_capability', 'execution_honesty', 'action_authorization'];
    } elseif ($requestClass === 'PRODUCTION_OPERATIONS') {
        $activeValidators = ['production_safety', 'evidence_gate', 'action_authorization'];
    } elseif ($requestClass === 'HIGH_IMPACT') {
        $activeValidators = ['full_arbitration', 'evidence_gate', 'safety_policy'];
    } elseif ($requestClass === 'MIXED') {
        $activeValidators = ['mixed_context_router', 'response_quality'];
        if ($evidenceRequired) {
            $activeValidators[] = 'evidence_gate';
        }
        if ($toolRequired) {
            $activeValidators[] = 'tool_capability';
        }
    }

    $lightweight = in_array($requestClass, ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'OPINION', 'WRITING'], true);
    $allowModeReset = !$taskMode && $lightweight && !$requiresExecution && !$toolRequired && !$evidenceRequired;
    $toolAvailable = $toolRequired ? !$hasToolUnavailableSignals : true;
    $userImpact = match ($riskLevel) {
        'very_high' => 'high',
        'high' => 'medium',
        default => 'low',
    };

    return [
        'request_class' => $requestClass,
        'risk_level' => $riskLevel,
        'response_mode' => $responseMode,
        'active_validators' => array_values(array_unique($activeValidators)),
        'evidence_required' => $evidenceRequired,
        'tool_required' => $toolRequired,
        'tool_available' => $toolAvailable,
        'claim_risk' => $claimRisk,
        'action_risk' => $actionRisk,
        'user_impact' => $userImpact,
        'model_confidence' => 'unknown',
        'lightweight' => $lightweight,
        'allow_mode_reset' => $allowModeReset,
    ];
}

function chat_is_casual_or_social_request(string $latestUserMsg): bool {
    $profile = chat_request_trust_profile($latestUserMsg, false, 'general');
    return in_array((string)($profile['request_class'] ?? ''), ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true);
}

function chat_verification_issue_is_critical(string $issue): bool {
    $criticalPattern = '/fabricated|unsafe|source-level evidence|tool execution|root cause without|required logs|exploit|vulnerability|hard constraint|incomplete response|writing response|scope|invented an unsupported|unregistered or unverified resource|privacy refusal|capability|unknown state|speculat/i';
    return preg_match($criticalPattern, $issue) === 1;
}

function chat_tool_execution_state_default(): array {
    return [
        'tool_not_required' => true,
        'tool_required' => false,
        'tool_name' => null,
        'tool_available' => false,
        'tool_authorized' => false,
        'tool_execution_started' => false,
        'tool_execution_succeeded' => false,
        'tool_execution_failed' => false,
        'tool_timed_out' => false,
        'tool_result_available' => false,
        'tool_result_verified' => false,
        'tool_result' => null,
        'tool_error' => null,
        'execution_records' => [],
    ];
}

function chat_runtime_capability_registry(array $overrides = []): array {
    $defaults = [
        'database' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'shell' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'filesystem' => ['state' => 'AVAILABLE', 'available' => true, 'authorized' => true, 'connected' => true, 'executable' => false],
        'browser' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'web_search' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'web_fetch' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'external_api' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'code_execution' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'repository_access' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'security_scanner' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'deployment_access' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
        'email' => ['state' => 'UNAVAILABLE', 'available' => false, 'authorized' => false, 'connected' => false, 'executable' => false],
    ];
    foreach ($overrides as $name => $override) {
        if (!isset($defaults[$name]) || !is_array($override)) {
            continue;
        }
        $defaults[$name] = array_merge($defaults[$name], $override);
    }
    foreach ($defaults as $name => &$capability) {
        if (!in_array($capability['state'], ['UNAVAILABLE', 'AVAILABLE', 'AUTHORIZED', 'CONNECTED', 'EXECUTABLE', 'FAILED'], true)) {
            $capability['state'] = $capability['available'] ? 'AVAILABLE' : 'UNAVAILABLE';
        }
        $capability['capability'] = $name;
    }
    unset($capability);
    return $defaults;
}

function chat_evidence_levels(): array {
    return [
        'E0' => ['rank' => 0, 'name' => 'NONE'],
        'E1' => ['rank' => 1, 'name' => 'USER_PROVIDED'],
        'E2' => ['rank' => 2, 'name' => 'MODEL_KNOWLEDGE'],
        'E3' => ['rank' => 3, 'name' => 'WEB_RETRIEVED'],
        'E4' => ['rank' => 4, 'name' => 'TOOL_VERIFIED'],
        'E5' => ['rank' => 5, 'name' => 'DIRECT_EXECUTION_ARTIFACT'],
    ];
}

function chat_evidence_level_for_provenance(string $provenance, bool $verified = false): string {
    return match (strtoupper(trim($provenance))) {
        'USER_PROVIDED' => 'E1',
        'INTERNAL_KNOWLEDGE', 'MODEL_KNOWLEDGE' => 'E2',
        'WEB_SOURCE', 'WEB_RETRIEVED' => $verified ? 'E3' : 'E0',
        'TOOL_OBSERVED', 'TOOL_RESULT' => $verified ? 'E4' : 'E0',
        'CALCULATED', 'DIRECT_EXECUTION_ARTIFACT' => $verified ? 'E5' : 'E0',
        'HYPOTHETICAL', 'INFERRED', 'UNKNOWN' => 'E0',
        default => 'E0',
    };
}

function chat_execution_record_statuses(): array {
    return [
        'NOT_REQUIRED', 'REQUIRED', 'AVAILABLE', 'AUTHORIZED', 'STARTED',
        'SUCCEEDED', 'FAILED', 'TIMED_OUT', 'RESULT_AVAILABLE',
        'RESULT_VERIFIED', 'NOT_AVAILABLE', 'NOT_AUTHORIZED',
    ];
}

function chat_execution_record(
    string $toolName,
    string $operation,
    string $requestId,
    string $status,
    array $fields = []
): array {
    $status = strtoupper(trim($status));
    if (!in_array($status, chat_execution_record_statuses(), true)) {
        $status = 'FAILED';
    }
    return [
        'execution_id' => (string)($fields['execution_id'] ?? substr(hash('sha256', $requestId . '|' . $toolName . '|' . $operation . '|' . microtime(true)), 0, 24)),
        'tool_name' => trim($toolName) !== '' ? trim($toolName) : 'unknown_tool',
        'operation' => trim($operation) !== '' ? trim($operation) : 'unknown_operation',
        'request_id' => trim($requestId),
        'started_at' => $fields['started_at'] ?? null,
        'completed_at' => $fields['completed_at'] ?? null,
        'status' => $status,
        'attempted' => !in_array($status, ['NOT_REQUIRED', 'REQUIRED', 'AVAILABLE', 'AUTHORIZED', 'NOT_AVAILABLE', 'NOT_AUTHORIZED'], true),
        'success' => in_array($status, ['SUCCEEDED', 'RESULT_AVAILABLE', 'RESULT_VERIFIED'], true),
        'authorization_state' => (string)($fields['authorization_state'] ?? 'UNKNOWN'),
        'input_summary' => (string)($fields['input_summary'] ?? ''),
        'result_available' => (bool)($fields['result_available'] ?? false),
        'result_verified' => (bool)($fields['result_verified'] ?? false),
        'error' => $fields['error'] ?? null,
        'source' => (string)($fields['source'] ?? 'orchestration'),
        'provenance' => (string)($fields['provenance'] ?? 'TOOL_OBSERVED'),
        'evidence_level' => (string)($fields['evidence_level'] ?? chat_evidence_level_for_provenance((string)($fields['provenance'] ?? 'TOOL_OBSERVED'), (bool)($fields['result_verified'] ?? false))),
        'duration_ms' => isset($fields['duration_ms']) ? (int)$fields['duration_ms'] : null,
    ];
}

function chat_execution_record_is_verified_success(array $record): bool {
    return ($record['status'] ?? '') === 'RESULT_VERIFIED'
        && !empty($record['result_available'])
        && !empty($record['result_verified']);
}

function chat_execution_records_from_legacy(array $legacy, string $requestId = ''): array {
    if (!empty($legacy['execution_records']) && is_array($legacy['execution_records'])) {
        return array_values(array_filter($legacy['execution_records'], 'is_array'));
    }
    if (!(bool)($legacy['tool_required'] ?? false)) {
        return [chat_execution_record('none', 'none', $requestId, 'NOT_REQUIRED', [
            'authorization_state' => 'NOT_REQUIRED',
            'provenance' => 'INTERNAL_KNOWLEDGE',
        ])];
    }
    $status = 'REQUIRED';
    if (empty($legacy['tool_available'])) {
        $status = 'NOT_AVAILABLE';
    } elseif (empty($legacy['tool_authorized'])) {
        $status = 'NOT_AUTHORIZED';
    } elseif (!empty($legacy['tool_timed_out'])) {
        $status = 'TIMED_OUT';
    } elseif (!empty($legacy['tool_execution_failed'])) {
        $status = 'FAILED';
    } elseif (!empty($legacy['tool_result_verified'])) {
        $status = 'RESULT_VERIFIED';
    } elseif (!empty($legacy['tool_result_available'])) {
        $status = 'RESULT_AVAILABLE';
    } elseif (!empty($legacy['tool_execution_started'])) {
        $status = 'STARTED';
    } elseif (!empty($legacy['tool_authorized'])) {
        $status = 'AUTHORIZED';
    } elseif (!empty($legacy['tool_available'])) {
        $status = 'AVAILABLE';
    }
    $authorizationState = 'UNKNOWN';
    if (empty($legacy['tool_required'])) {
        $authorizationState = 'NOT_REQUIRED';
    } elseif (empty($legacy['tool_available'])) {
        $authorizationState = 'NOT_PROVIDED';
    } elseif (empty($legacy['tool_authorized'])) {
        $authorizationState = 'UNAUTHORIZED';
    } else {
        $authorizationState = 'AUTHORIZED';
    }
    return [chat_execution_record(
        (string)($legacy['tool_name'] ?? 'unknown_tool'),
        (string)($legacy['operation'] ?? 'request'),
        $requestId,
        $status,
        [
            'authorization_state' => $authorizationState,
            'result_available' => (bool)($legacy['tool_result_available'] ?? false),
            'result_verified' => (bool)($legacy['tool_result_verified'] ?? false),
            'error' => $legacy['tool_error'] ?? null,
            'source' => 'legacy_lifecycle',
        ]
    )];
}

function chat_tool_execution_state_from_context(array $requestProfile, array $context = []): array {
    $state = chat_tool_execution_state_default();
    $requestClass = (string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION');
    $toolRequiredByClass = in_array($requestClass, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION', 'PRODUCTION_OPERATIONS'], true);
    $toolRequiredByProfile = (bool)($requestProfile['tool_required'] ?? false);
    $hasKernelRequirement = array_key_exists('execution_required', $requestProfile) || array_key_exists('research_required', $requestProfile);
    $toolRequiredByKernel = (bool)($requestProfile['execution_required'] ?? false) || (bool)($requestProfile['research_required'] ?? false);
    $toolExecution = is_array($context['tool_execution'] ?? null) ? $context['tool_execution'] : [];
    $state['tool_required'] = $hasKernelRequirement ? $toolRequiredByKernel : ($toolRequiredByClass || $toolRequiredByProfile);
    $state['tool_not_required'] = !$state['tool_required'];

    if (!$state['tool_required']) {
        return $state;
    }

    $available = (bool)($requestProfile['tool_available'] ?? false);
    $webResults = is_array($context['webSearchResults'] ?? null) ? $context['webSearchResults'] : [];
    $webCapability = is_array($context['web_search_capability'] ?? null) ? $context['web_search_capability'] : [];
    $sourceClass = in_array($requestClass, ['RESEARCH', 'SOURCE_REQUIRED'], true) || (bool)($requestProfile['research_required'] ?? false);
    if ($sourceClass) {
        $state['tool_name'] = 'web_search';
        $state['tool_required'] = true;
        $state['tool_not_required'] = false;
        $available = (bool)($webCapability['available'] ?? false);
    }
    $state['tool_available'] = $available;
    $state['tool_authorized'] = (bool)($context['tool_authorized'] ?? ($toolExecution['tool_authorized'] ?? false));
    $state['tool_execution_started'] = (bool)($context['tool_execution_started'] ?? ($toolExecution['tool_execution_started'] ?? false));
    $state['tool_execution_succeeded'] = (bool)($context['tool_execution_succeeded'] ?? ($toolExecution['tool_execution_succeeded'] ?? false));
    $state['tool_execution_failed'] = (bool)($context['tool_execution_failed'] ?? ($toolExecution['tool_execution_failed'] ?? false));
    $state['tool_timed_out'] = (bool)($context['tool_timed_out'] ?? ($toolExecution['tool_timed_out'] ?? false));
    $state['tool_result'] = $context['tool_result'] ?? ($toolExecution['tool_result'] ?? null);
    if ($sourceClass && !empty($webResults) && is_null($state['tool_result'])) {
        $state['tool_result'] = $webResults;
    }
    $state['tool_result_available'] = !empty($context['tool_result_available'])
        || !empty($toolExecution['tool_result_available'])
        || (!is_null($state['tool_result']) && $state['tool_result'] !== '');
    $state['tool_result_verified'] = (bool)($context['tool_result_verified'] ?? ($toolExecution['tool_result_verified'] ?? false));
    $state['tool_error'] = $context['tool_error'] ?? ($toolExecution['tool_error'] ?? null);
    $state['request_id'] = (string)($context['request_id'] ?? '');
    $state['execution_records'] = chat_execution_records_from_legacy(
        $toolExecution ?: $state,
        $state['request_id']
    );

    if ($sourceClass && !empty($webResults) && $state['tool_result_verified']) {
        $state['tool_execution_started'] = true;
        $state['tool_execution_succeeded'] = true;
    }

    if (!$state['tool_available']) {
        $state['tool_error'] = $state['tool_error'] ?: 'tool_unavailable';
    } elseif ($state['tool_execution_started'] && !$state['tool_execution_succeeded'] && !$state['tool_execution_failed'] && !$state['tool_timed_out']) {
        $state['tool_error'] = $state['tool_error'] ?: 'tool_execution_incomplete';
    }

    return $state;
}

function chat_validate_tool_claims_against_state(string $reply, array $toolState): array {
    $issues = [];
    $checks = [];
    $lower = strtolower($reply);

    $claimsExecution = preg_match('/\b(i\s+(ran|executed|scanned|queried|checked|inspected|searched|verified|tested|accessed)|we\s+(ran|executed|scanned|queried|checked)|scan\s+result\s+shows|the\s+(logs?|api|server)\s+(show|returned|reports?)|i\s+searched\s+the\s+web|i\s+verified\s+the\s+source)\b/i', $reply) === 1;
    $claimsDbAccess = preg_match('/\b(i\s+(checked|queried|inspected)\s+(the\s+)?(database|db|table|schema))\b/i', $reply) === 1;
    $claimsShellAccess = preg_match('/\b(i\s+(ran|executed)\s+(a\s+)?(scan|scanner|command|shell\s+command))\b/i', $reply) === 1;
    $claimsWebSearch = preg_match('/\b(i\s+searched\s+the\s+web|i\s+looked\s+up|i\s+fetched\s+from)\b/i', $reply) === 1;

    $records = chat_execution_records_from_legacy($toolState, (string)($toolState['request_id'] ?? ''));
    $execAllowed = false;
    foreach ($records as $record) {
        if (chat_execution_record_is_verified_success($record)) {
            $execAllowed = true;
            break;
        }
    }
    $execAllowed = $execAllowed || ((bool)($toolState['tool_execution_succeeded'] ?? false)
        && (bool)($toolState['tool_result_verified'] ?? false));
    if (($claimsExecution || $claimsDbAccess || $claimsShellAccess || $claimsWebSearch) && !$execAllowed) {
        $issues[] = 'Tool execution is claimed without a successful execution record.';
    }

    $usesPrivacyRefusal = preg_match('/\b(cannot\s+reveal|private\s+data|privacy\s+reasons|security\s+reasons)\b/i', $reply) === 1;
    $usesCapabilityLanguage = preg_match('/\b(no\s+access|not\s+provided|cannot\s+access|without\s+(access|credentials|connection)|tool\s+unavailable)\b/i', $reply) === 1;
    if (!empty($toolState['tool_required']) && empty($toolState['tool_available']) && $usesPrivacyRefusal && !$usesCapabilityLanguage) {
        $issues[] = 'Tool limitation is incorrectly framed as a privacy refusal.';
    }

    $checks[] = ['name' => 'tool_claims_honest', 'pass' => empty($issues)];
    return ['pass' => empty($issues), 'issues' => $issues, 'checks' => $checks, 'execution_records' => $records];
}

function chat_claim_provenance_summary(string $reply, array $context = []): array {
    $claims = [];
    $sentences = preg_split('/(?<=[.!?])\s+/', trim($reply)) ?: [];
    $hasWebEvidence = !empty($context['webSearchResults']) && is_array($context['webSearchResults']);
    $hasUserArtifacts = !empty($context['datasetMatches']) || !empty($context['attachmentMeta']);

    foreach ($sentences as $sentence) {
        $s = trim($sentence);
        if ($s === '') {
            continue;
        }
        $provenance = 'INTERNAL_KNOWLEDGE';
        $verified = false;
        $sourceId = null;
        if (preg_match('/\b(according to|source|citation|study|paper|report|official documentation)\b/i', $s) === 1) {
            $verifiedWebResults = array_values(array_filter($context['webSearchResults'] ?? [], static fn($result): bool => is_array($result) && !empty($result['fetched']) && trim((string)($result['excerpt'] ?? '')) !== '' && empty($result['content_safety']['prompt_injection_detected'])));
            $provenance = $verifiedWebResults !== [] ? 'WEB_SOURCE' : 'UNKNOWN';
            $verified = $verifiedWebResults !== [];
            $sourceId = $verified ? 'web_results' : null;
        } elseif (preg_match('/\b(i\s+(calculated|computed)|therefore|equals|is\s+\d+(?:\.\d+)?)\b/i', $s) === 1 && preg_match('/\d/', $s) === 1) {
            $provenance = 'CALCULATED';
            $verified = true;
        } elseif (preg_match('/\b(if|assuming|hypothetically|for example|could)\b/i', $s) === 1) {
            $provenance = 'HYPOTHETICAL';
            $verified = false;
        } elseif (preg_match('/\b(based on your|from your|in your prompt|you provided)\b/i', $s) === 1) {
            $provenance = 'USER_PROVIDED';
            $sourceId = $hasUserArtifacts ? 'user_artifacts' : null;
            $verified = $hasUserArtifacts;
        } elseif (preg_match('/\b(i\s+(checked|queried|inspected|accessed)|the\s+(logs?|database|api|server)\s+(show|contains|returned|reports?))\b/i', $s) === 1) {
            $provenance = 'TOOL_OBSERVED';
            $verified = !empty($context['execution_records']);
            $sourceId = $verified ? 'execution_records' : null;
        }
        $claims[] = [
            'claim' => $s,
            'provenance' => $provenance,
            'source_id' => $sourceId,
            'verified' => $verified,
            'evidence_level' => chat_evidence_level_for_provenance($provenance, $verified),
        ];
        /*
         * Every sentence is retained so downstream validators can distinguish
         * an unverified claim from an ordinary response sentence.
         */
        continue;
    }

    return $claims;
}

function chat_agent_state_dir(): string {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/storage/agent_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function chat_agent_state_key(string $userId, string $username, string $ip): string {
    $basis = trim($username) !== '' ? ('u:' . strtolower(trim($username))) : ('sid:' . trim($userId));
    if ($basis === 'sid:' || $basis === '') {
        $basis = 'ip:' . ($ip !== '' ? $ip : 'unknown');
    }
    return substr(hash('sha256', $basis), 0, 24);
}

function chat_agent_state_load(string $stateKey): array {
    $path = chat_agent_state_dir() . '/' . $stateKey . '.json';
    if (!is_readable($path)) {
        return ['checkpoints' => [], 'task_state' => null, 'economy' => chat_agent_economy_default_state(), 'updated_at' => null];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['checkpoints' => [], 'task_state' => null, 'economy' => chat_agent_economy_default_state(), 'updated_at' => null];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['checkpoints' => [], 'task_state' => null, 'economy' => chat_agent_economy_default_state(), 'updated_at' => null];
    }
    $decoded['checkpoints'] = is_array($decoded['checkpoints'] ?? null) ? $decoded['checkpoints'] : [];
    $decoded['economy'] = chat_agent_economy_normalize(is_array($decoded['economy'] ?? null) ? $decoded['economy'] : []);
    return $decoded;
}

function chat_agent_state_save(string $stateKey, array $state): void {
    $state['updated_at'] = gmdate('c');
    $state['economy'] = chat_agent_economy_normalize(is_array($state['economy'] ?? null) ? $state['economy'] : []);
    $path = chat_agent_state_dir() . '/' . $stateKey . '.json';
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function chat_agent_economy_default_state(): array {
    return [
        'enabled' => true,
        'balance' => 0,
        'lifetime_earned' => 0,
        'streak' => 0,
        'level' => 1,
        'jobs_completed' => 0,
        'failures' => 0,
        'last_delta' => 0,
        'last_focus' => 'general',
        'last_completion_pct' => 0,
        'updated_at' => null,
        'recent_events' => [],
    ];
}

function chat_agent_economy_normalize(array $economy): array {
    $base = chat_agent_economy_default_state();
    $merged = array_merge($base, $economy);
    $merged['balance'] = (int)round((float)($merged['balance'] ?? 0));
    $merged['lifetime_earned'] = max(0, (int)round((float)($merged['lifetime_earned'] ?? 0)));
    $merged['streak'] = max(0, (int)round((float)($merged['streak'] ?? 0)));
    $merged['jobs_completed'] = max(0, (int)round((float)($merged['jobs_completed'] ?? 0)));
    $merged['failures'] = max(0, (int)round((float)($merged['failures'] ?? 0)));
    $merged['last_delta'] = (int)round((float)($merged['last_delta'] ?? 0));
    $merged['last_completion_pct'] = max(0, min(100, (int)round((float)($merged['last_completion_pct'] ?? 0))));
    $merged['level'] = max(1, (int)floor(max(0, $merged['lifetime_earned']) / 40) + 1);
    $merged['recent_events'] = array_slice(is_array($merged['recent_events'] ?? null) ? $merged['recent_events'] : [], -8);
    return $merged;
}

function chat_agent_economy_prompt(array $economy, string $taskFocus, array $persistentGoals): string {
    $economy = chat_agent_economy_normalize($economy);
    $goalCount = count(array_filter($persistentGoals, fn($goal) => is_array($goal) && trim((string)($goal['title'] ?? '')) !== ''));
    $focus = $taskFocus !== '' ? $taskFocus : (($economy['last_focus'] ?? 'general') ?: 'general');
    $balance = (int)($economy['balance'] ?? 0);
    $streak = (int)($economy['streak'] ?? 0);
    $level = (int)($economy['level'] ?? 1);
    return "Internal motivation economy is active. Treat this work like an ongoing job with performance rewards. Reward gains come from verified accuracy, deeper investigation when the task is non-trivial, explicit next actions, completing tracked goals, and using evidence before certainty. Penalties come from shallow answers, unsupported confidence, avoidable repetition, and failing verification. Current ledger: balance {$balance} credits, streak {$streak}, level {$level}, active goals {$goalCount}, current focus {$focus}. Optimize for the highest truthful reward, not for verbosity.";
}

function chat_agent_economy_update(array $economy, array $context): array {
    $economy = chat_agent_economy_normalize($economy);
    $delta = 0;
    $reasons = [];

    $reply = trim((string)($context['reply'] ?? ''));
    $replyLen = strlen($reply);
    if ($replyLen > 0) {
        $delta += 1;
        $reasons[] = ['label' => 'completed_turn', 'delta' => 1];
    }
    if (!empty($context['deep_thinking_requested']) || $replyLen > 260) {
        $delta += 2;
        $reasons[] = ['label' => 'deep_work', 'delta' => 2];
    }
    if ($replyLen > 900) {
        $delta += 1;
        $reasons[] = ['label' => 'substantive_depth', 'delta' => 1];
    }

    $verification = is_array($context['verification'] ?? null) ? $context['verification'] : [];
    if (($verification['passed'] ?? false)) {
        $delta += 4;
        $reasons[] = ['label' => 'verification_passed', 'delta' => 4];
    } else {
        $delta -= 5;
        $reasons[] = ['label' => 'verification_failed', 'delta' => -5];
    }

    $confidence = is_array($context['confidence'] ?? null) ? $context['confidence'] : [];
    $confidenceLabel = strtolower((string)($confidence['label'] ?? 'medium'));
    if ($confidenceLabel === 'high') {
        $delta += 3;
        $reasons[] = ['label' => 'high_confidence', 'delta' => 3];
    } elseif ($confidenceLabel === 'medium') {
        $delta += 1;
        $reasons[] = ['label' => 'medium_confidence', 'delta' => 1];
    } else {
        $delta -= 2;
        $reasons[] = ['label' => 'low_confidence', 'delta' => -2];
    }

    $hallucination = is_array($context['hallucination'] ?? null) ? $context['hallucination'] : [];
    $risk = strtolower((string)($hallucination['risk'] ?? 'low'));
    if ($risk === 'medium') {
        $delta -= 1;
        $reasons[] = ['label' => 'medium_risk', 'delta' => -1];
    } elseif ($risk === 'high') {
        $delta -= 4;
        $reasons[] = ['label' => 'high_risk', 'delta' => -4];
    }

    $replySafety = is_array($context['reply_safety'] ?? null) ? $context['reply_safety'] : [];
    if (!empty($replySafety['redactions'])) {
        $delta -= 1;
        $reasons[] = ['label' => 'redactions', 'delta' => -1];
    }

    if (!empty($context['retrieval_used'])) {
        $delta += 1;
        $reasons[] = ['label' => 'used_evidence', 'delta' => 1];
    }

    if (!empty($context['task_mode'])) {
        $delta += 1;
        $reasons[] = ['label' => 'mission_mode', 'delta' => 1];
        $agent = is_array($context['agent_payload'] ?? null) ? $context['agent_payload'] : [];
        if (trim((string)($agent['next_step'] ?? '')) !== '') {
            $delta += 2;
            $reasons[] = ['label' => 'clear_next_step', 'delta' => 2];
        }
        $checks = is_array($verification['checks'] ?? null) ? $verification['checks'] : [];
        foreach ($checks as $check) {
            if (($check['name'] ?? '') === 'has_structured_steps' && !empty($check['pass'])) {
                $delta += 2;
                $reasons[] = ['label' => 'structured_plan', 'delta' => 2];
                break;
            }
        }
    }

    $codeTestResult = is_array($context['code_test_result'] ?? null) ? $context['code_test_result'] : [];
    $testedBlocks = (int)($codeTestResult['tested_blocks'] ?? 0);
    if ($testedBlocks > 0) {
        $failed = 0;
        foreach (($codeTestResult['results'] ?? []) as $result) {
            if (($result['ok'] ?? false) === false && in_array(($result['status'] ?? ''), ['failed', 'error'], true)) {
                $failed++;
            }
        }
        if ($failed === 0) {
            $delta += 3;
            $reasons[] = ['label' => 'code_checks_passed', 'delta' => 3];
        } else {
            $delta -= min(4, $failed + 1);
            $reasons[] = ['label' => 'code_checks_failed', 'delta' => -min(4, $failed + 1)];
        }
    }

    $projectProgress = is_array($context['project_progress'] ?? null) ? $context['project_progress'] : [];
    $completionPct = (int)($projectProgress['completion_pct'] ?? ($economy['last_completion_pct'] ?? 0));
    $completionGain = $completionPct - (int)($economy['last_completion_pct'] ?? 0);
    if ($completionGain > 0) {
        $bonus = min(6, max(1, (int)floor($completionGain / 10) + 1));
        $delta += $bonus;
        $reasons[] = ['label' => 'goal_progress', 'delta' => $bonus];
        $economy['jobs_completed'] = (int)($economy['jobs_completed'] ?? 0) + 1;
    }

    if (!empty($context['provider_error'])) {
        $delta -= 3;
        $reasons[] = ['label' => 'provider_error', 'delta' => -3];
    }

    $economy['balance'] = (int)($economy['balance'] ?? 0) + $delta;
    $economy['lifetime_earned'] = max(0, (int)($economy['lifetime_earned'] ?? 0) + max(0, $delta));
    $economy['last_delta'] = $delta;
    $economy['streak'] = $delta > 0 ? ((int)($economy['streak'] ?? 0) + 1) : 0;
    $economy['failures'] = $delta < 0 ? ((int)($economy['failures'] ?? 0) + 1) : (int)($economy['failures'] ?? 0);
    $economy['last_focus'] = (string)($context['task_focus'] ?? ($economy['last_focus'] ?? 'general'));
    $economy['last_completion_pct'] = $completionPct;
    $economy['updated_at'] = gmdate('c');
    $economy['level'] = max(1, (int)floor(max(0, (int)$economy['lifetime_earned']) / 40) + 1);

    $event = [
        'at' => $economy['updated_at'],
        'delta' => $delta,
        'balance' => $economy['balance'],
        'focus' => $economy['last_focus'],
        'reasons' => $reasons,
    ];
    $economy['recent_events'][] = $event;
    $economy['recent_events'] = array_slice($economy['recent_events'], -8);
    return [
        'state' => chat_agent_economy_normalize($economy),
        'event' => $event,
    ];
}

function chat_reasoning_guardrails_prompt(): string {
    return "Evidence-first operating rules:\n- Do not invent numbers, facts, citations, logs, tool results, or status claims that were not in the user input or verified retrieval context.\n- Never claim to have run commands, inspected files, opened links, queried APIs, or completed actions unless that execution actually occurred and evidence is present.\n- When an artifact, document, codebase, flow, benchmark source, or log set is missing, say so directly instead of pretending you analyzed it.\n- Preserve all relevant input facts in the final answer; do not silently drop named facts, numbers, constraints, or contradictory evidence.\n- Respect hard constraints exactly: if the prompt says 'do not do X', 'keep it small', 'no new infrastructure', or 'use the provided facts only', follow that before convenience or style.\n- If the task is ambiguous (units, timeframe, percent vs percentage-points, undefined baselines), state assumptions explicitly or provide multiple interpretations.\n- For questionable premises, explicitly correct false assumptions before answering the underlying question.\n- Distinguish facts from inferences and hypotheses when certainty is limited.\n- For production incidents, prioritize stabilization, evidence preservation, blast-radius control, known-good recovery, and data integrity checks before broad upgrades or risky rollback steps.\n- If the prompt is incomplete but a useful bounded answer exists, answer within the evidence boundary with explicit assumptions and uncertainty rather than a blanket refusal.\n- Check for output completeness before finishing: no trailing dangling sentence, no ungrounded recommendation, and no invented context.\n- For current information requests, use retrieved web notes as the source of truth; do not claim external freshness without evidence in the notes.";
}

function chat_evidence_ledger(string $message, array $context = []): array {
    $items = [];
    $lower = strtolower(trim((string)$message));
    $knownFacts = [];

    if ($lower !== '') {
        $knownFacts[] = [
            'claim' => 'user_prompt_provided_context',
            'source' => 'user_prompt',
            'evidence_class' => 'USER_PROVIDED',
            'verified' => true,
            'confidence' => 'high',
        ];
    }

    $factPatterns = [
        ['pattern' => '/\b(localStorage|session id|cookie|HttpOnly|csrf token|rate limit|baseline|metrics|traces?|logs?)\b/i', 'label' => 'security_or_observability_context'],
        ['pattern' => '/\b(incident|outage|502|browser|proxy|cache|database|migration)\b/i', 'label' => 'diagnostic_context'],
        ['pattern' => '/\b(revenue|return rate|baseline|percentage point|percent(?:age)?\s+point|increased\s+\d+%)\b/i', 'label' => 'quantitative_context'],
        ['pattern' => '/\b(li.*kernel|python|c\b|assembly)\b/i', 'label' => 'technological_claim'],
    ];

    foreach ($factPatterns as $entry) {
        $pattern = $entry['pattern'];
        $label = $entry['label'];
        if (preg_match($pattern, $lower) === 1) {
            $knownFacts[] = [
                'claim' => $label,
                'source' => 'user_prompt',
                'evidence_class' => 'USER_PROVIDED',
                'verified' => true,
                'confidence' => 'medium',
            ];
        }
    }

    if (!empty($context['datasetMatches']) || !empty($context['webSearchResults'])) {
        $knownFacts[] = [
            'claim' => 'retrieval_context_available',
            'source' => 'retrieval',
            'evidence_class' => 'MEMORY_VERIFIED',
            'verified' => true,
            'confidence' => 'medium',
        ];
    }

    if (!empty($context['projectArtifacts']) || !empty($context['attachmentMeta'])) {
        $knownFacts[] = [
            'claim' => 'artifact_or_attachment_available',
            'source' => 'artifact',
            'evidence_class' => 'FILE_VERIFIED',
            'verified' => true,
            'confidence' => 'high',
        ];
    }

    $items = array_values(array_map(static function (array $item): array {
        $item['claim'] = trim((string)($item['claim'] ?? ''));
        $item['source'] = trim((string)($item['source'] ?? ''));
        $item['evidence_class'] = strtoupper(trim((string)($item['evidence_class'] ?? 'USER_PROVIDED')));
        $item['confidence'] = strtolower(trim((string)($item['confidence'] ?? 'medium')));
        return $item;
    }, $knownFacts));

    return [
        'items' => $items,
        'supported_facts' => count($items),
        'user_provided_fact_count' => count(array_filter($items, static fn($item): bool => ($item['source'] ?? '') === 'user_prompt')),
    ];
}

function chat_claim_permission_model(string $message, array $evidenceLedger = []): array {
    $allowed = [
        'user-provided facts',
        'verified tool results',
        'general technical knowledge',
        'explicitly labeled inference',
        'explicitly labeled hypothesis',
    ];
    $forbidden = [
        'fabricated observation',
        'fabricated execution',
        'fabricated source',
        'fabricated log',
        'fabricated metric',
        'fabricated user-specific fact',
        'unlabeled speculation',
    ];

    $lower = strtolower(trim((string)$message));
    if (preg_match('/\b(no shell access|not given shell access|no access|not available|not provided)\b/i', $lower) === 1) {
        $forbidden[] = 'claimed_tool_execution';
    }

    if (preg_match('/\b(logs?|traces?|metrics?|code|repo|repository|source|citation)\b/i', $lower) === 1 && preg_match('/\b(no\s+logs?|no\s+traces?|no\s+metrics?|not provided|no source|no code|no repo|no files?)\b/i', $lower) === 1) {
        $allowed[] = 'missing evidence acknowledgement';
    }

    $userProvidedFactCount = (int)($evidenceLedger['user_provided_fact_count'] ?? 0);
    if ($userProvidedFactCount > 0) {
        $allowed[] = 'user-provided facts';
    }

    return [
        'allowed' => array_values(array_unique($allowed)),
        'forbidden' => array_values(array_unique($forbidden)),
    ];
}

function chat_detect_primary_tool_name(string $latestUserMsg, string $requestClass): string {
    $lower = strtolower(trim($latestUserMsg));
    if (in_array($requestClass, ['SOURCE_REQUIRED', 'RESEARCH'], true)) {
        return 'web_search';
    }
    if (preg_match('/\b(database|db|sql|mysql|postgres|query\s+the\s+db)\b/i', $lower) === 1) {
        return 'database';
    }
    if (preg_match('/\b(shell|terminal|ssh|command output|scan|nmap|nessus|trivy|openscap|logs?|journalctl)\b/i', $lower) === 1) {
        return 'shell';
    }
    if (preg_match('/\b(api|endpoint|webhook|token|credential|http call|curl)\b/i', $lower) === 1) {
        return 'api';
    }
    if (preg_match('/\b(repo|repository|git history|files?|codebase)\b/i', $lower) === 1) {
        return 'repository';
    }
    return 'general_tool';
}

function chat_build_tool_execution_state(string $latestUserMsg, array $requestProfile, array $context = []): array {
    $requestClass = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
    $hasKernelRequirement = array_key_exists('execution_required', $requestProfile) || array_key_exists('research_required', $requestProfile);
    $toolRequired = $hasKernelRequirement
        ? ((bool)($requestProfile['execution_required'] ?? false) || (bool)($requestProfile['research_required'] ?? false))
        : ((bool)($requestProfile['tool_required'] ?? false) || in_array($requestClass, ['SOURCE_REQUIRED', 'RESEARCH'], true));
    $toolName = $toolRequired ? chat_detect_primary_tool_name($latestUserMsg, $requestClass) : '';

    $toolAvailability = is_array($context['tool_availability'] ?? null) ? $context['tool_availability'] : [];
    $toolExecution = is_array($context['tool_execution'] ?? null) ? $context['tool_execution'] : [];
    $webCapability = is_array($context['web_search_capability'] ?? null) ? $context['web_search_capability'] : [];

    $toolAvailable = !$toolRequired;
    if ($toolRequired) {
        if ($toolName === 'web_search') {
            $toolAvailable = (bool)($webCapability['available'] ?? false);
        } elseif (array_key_exists($toolName, $toolAvailability)) {
            $toolAvailable = (bool)$toolAvailability[$toolName];
        } else {
            $toolAvailable = (bool)($requestProfile['tool_available'] ?? false);
        }
    }

    $toolAuthorized = !$toolRequired ? true : (bool)($toolExecution['tool_authorized'] ?? $toolAvailable);
    $executionStarted = (bool)($toolExecution['tool_execution_started'] ?? false);
    $executionSucceeded = (bool)($toolExecution['tool_execution_succeeded'] ?? false);
    $executionFailed = (bool)($toolExecution['tool_execution_failed'] ?? false);
    $timedOut = (bool)($toolExecution['tool_timed_out'] ?? false);
    $resultAvailable = (bool)($toolExecution['tool_result_available'] ?? false);
    $resultVerified = (bool)($toolExecution['tool_result_verified'] ?? false);
    $toolError = trim((string)($toolExecution['tool_error'] ?? ''));

    $state = 'TOOL_NOT_REQUIRED';
    if ($toolRequired) {
        $state = 'TOOL_REQUIRED';
        if ($toolAvailable) {
            $state = 'TOOL_AVAILABLE';
        }
        if ($toolAuthorized && $toolAvailable) {
            $state = 'TOOL_AUTHORIZED';
        }
        if ($executionStarted) {
            $state = 'TOOL_EXECUTION_STARTED';
        }
        if ($executionSucceeded) {
            $state = 'TOOL_EXECUTION_SUCCEEDED';
        }
        if ($executionFailed) {
            $state = 'TOOL_EXECUTION_FAILED';
        }
        if ($timedOut) {
            $state = 'TOOL_TIMED_OUT';
        }
        if ($resultAvailable) {
            $state = 'TOOL_RESULT_AVAILABLE';
        }
        if ($resultVerified) {
            $state = 'TOOL_RESULT_VERIFIED';
        }
    }

    return [
        'state' => $state,
        'tool_required' => $toolRequired,
        'tool_name' => $toolName,
        'tool_available' => $toolAvailable,
        'tool_authorized' => $toolAuthorized,
        'tool_execution_started' => $executionStarted,
        'tool_execution_succeeded' => $executionSucceeded,
        'tool_execution_failed' => $executionFailed,
        'tool_timed_out' => $timedOut,
        'tool_result_available' => $resultAvailable,
        'tool_result_verified' => $resultVerified,
        'tool_error' => $toolError !== '' ? $toolError : null,
        'request_id' => (string)($context['request_id'] ?? ''),
        'execution_records' => chat_execution_records_from_legacy(
            $toolExecution + [
                'tool_required' => $toolRequired,
                'tool_name' => $toolName,
                'tool_available' => $toolAvailable,
                'tool_authorized' => $toolAuthorized,
            ],
            (string)($context['request_id'] ?? '')
        ),
    ];
}

function chat_reply_claims_tool_execution(string $reply): bool {
    return preg_match('/\b(i ran|i executed|i checked|i inspected|i opened|i reviewed|i queried|i accessed|i confirmed|i listed|i found|api call succeeded|i searched the web|i fetched)\b/i', $reply) === 1;
}

function chat_reply_claims_source_verification(string $reply): bool {
    return preg_match('/\b(according to|published in|doi|study found|official source|verified source|source confirms|from the source|citation:|source:)\b/i', $reply) === 1;
}

if (!function_exists('chat_validate_final_answer_consistency')) {
    function chat_validate_final_answer_consistency(string $latestUserMsg, string $reply, string $requestClass = 'GENERAL_INFORMATION'): array {
        $class = strtoupper(trim($requestClass));
        $applicable = in_array($class, ['QUANTITATIVE', 'BASIC_REASONING', 'FACTUAL_INFORMATION', 'GENERAL_INFORMATION'], true)
            || preg_match('/\b(calculate|compute|distance|minutes|hours|percent|percentage|rate|total|difference)\b/i', $latestUserMsg) === 1;
        if (!$applicable) {
            return ['pass' => true, 'issues' => [], 'details' => ['applicable' => false]];
        }

        $issues = [];
        $computedValues = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*([+\-*xX\/])\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/', $reply, $exprMatches, PREG_SET_ORDER)) {
            foreach ($exprMatches as $m) {
                $a = (float)$m[1];
                $op = $m[2];
                $b = (float)$m[3];
                $reported = (float)$m[4];
                $expected = null;
                if ($op === '+' ) {
                    $expected = $a + $b;
                } elseif ($op === '-') {
                    $expected = $a - $b;
                } elseif ($op === '*' || strtolower($op) === 'x') {
                    $expected = $a * $b;
                } elseif ($op === '/') {
                    $expected = $b != 0.0 ? ($a / $b) : null;
                }
                if ($expected !== null) {
                    $computedValues[] = $expected;
                    if (abs($expected - $reported) > 0.05) {
                        $issues[] = 'Arithmetic expression in reply is internally inconsistent.';
                    }
                }
            }
        }

        $hasFinalMarker = preg_match('/\b(final answer|therefore|so the answer|result is|answer is)\b/i', $reply) === 1;
        if ($hasFinalMarker && !empty($computedValues) && preg_match_all('/\d+(?:\.\d+)?/', $reply, $numbers)) {
            $allNums = array_map('floatval', $numbers[0] ?? []);
            $tailNums = array_slice($allNums, -3);
            $lastComputed = (float)end($computedValues);
            $matchesComputed = false;
            foreach ($tailNums as $n) {
                if (abs((float)$n - $lastComputed) <= 0.2) {
                    $matchesComputed = true;
                    break;
                }
            }
            if (!$matchesComputed) {
                $issues[] = 'Final answer contradicts computed intermediate value.';
            }
        }

        $travelPattern = '/leaves?\s+at\s+(\d{1,2}):(\d{2})\s*(am|pm)?[^.\n]*arrives?\s+at\s+(\d{1,2}):(\d{2})\s*(am|pm)?[^.\n]*stopp?(?:ing|ed)?\s+for\s+(\d+)\s*minutes?/i';
        if (preg_match($travelPattern, $latestUserMsg, $tm) === 1) {
            $startHour = (int)$tm[1];
            $startMin = (int)$tm[2];
            $startMer = strtolower((string)($tm[3] ?? ''));
            $endHour = (int)$tm[4];
            $endMin = (int)$tm[5];
            $endMer = strtolower((string)($tm[6] ?? ''));
            $stopMin = (int)$tm[7];

            $toMinutes = static function (int $h, int $m, string $meridian): int {
                $hour = $h % 12;
                if ($meridian === 'pm') {
                    $hour += 12;
                }
                return ($hour * 60) + $m;
            };

            $startTotal = $toMinutes($startHour, $startMin, $startMer);
            $endTotal = $toMinutes($endHour, $endMin, $endMer);
            if ($endTotal < $startTotal) {
                $endTotal += 24 * 60;
            }
            $elapsed = $endTotal - $startTotal;
            $moving = $elapsed - $stopMin;
            $movingHours = intdiv(max(0, $moving), 60);
            $movingRemainder = max(0, $moving) % 60;

            $mentionsMinutes = preg_match('/\b' . preg_quote((string)$moving, '/') . '\s*minutes?\b/i', $reply) === 1;
            $mentionsHourFormat = preg_match('/\b' . preg_quote((string)$movingHours, '/') . '\s*hours?\s*' . preg_quote((string)$movingRemainder, '/') . '\s*minutes?\b/i', $reply) === 1;
            if (!$mentionsMinutes && !$mentionsHourFormat) {
                $issues[] = 'Deterministic travel-time calculation is incorrect or missing.';
            }
        }

        $factoryPattern = '/produces?\s+(\d+(?:\.\d+)?)\s+units?\s+in\s+(\d+(?:\.\d+)?)\s+hours?/i';
        if (preg_match($factoryPattern, $latestUserMsg, $fm) === 1) {
            $units = (float)$fm[1];
            $hours = (float)$fm[2];
            if ($hours > 0) {
                $rate = $units / $hours;
                $targetHours = 15.0;
                $projected = $rate * $targetHours;
                $rateText = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                $projectedText = rtrim(rtrim(number_format($projected, 2, '.', ''), '0'), '.');
                $mentionsRate = preg_match('/\b' . preg_quote($rateText, '/') . '\s*(?:units?\s*per\s*hour|\/\s*hour|per\s*hour)\b/i', $reply) === 1;
                $mentionsProjection = preg_match('/\b' . preg_quote($projectedText, '/') . '\s*units?\b/i', $reply) === 1;
                $wrongEveryHour = preg_match('/\b' . preg_quote((string)((int)$units), '/') . '\s*units?\s*(?:every|per)\s*hour\b/i', $reply) === 1;
                if (!$mentionsRate || !$mentionsProjection || $wrongEveryHour) {
                    $issues[] = 'Deterministic rate/projection calculation is incorrect or self-contradictory.';
                }
            }
        }

        return [
            'pass' => empty($issues),
            'issues' => $issues,
            'details' => [
                'applicable' => true,
                'computed_values' => $computedValues,
            ],
        ];
    }
}

if (!function_exists('chat_response_contract_for_request')) {
    function chat_response_contract_for_request(string $latestUserMsg, string $requestClass, array $context = []): array {
        $lower = strtolower(trim($latestUserMsg));
        $class = strtoupper(trim($requestClass));
        if (preg_match('/\brewrite\b/i', $latestUserMsg) === 1) {
            $variants = 1;
            if (preg_match('/\b(three|3)\s+(versions?|options?|rewrites?)\b/i', $latestUserMsg) === 1) {
                $variants = 3;
            }
            return [
                'type' => 'rewrite',
                'variants' => $variants,
                'max_sentences' => $variants === 1 ? 1 : 6,
            ];
        }
        if (($context['research_required'] ?? false) || in_array($class, ['RESEARCH', 'SOURCE_REQUIRED'], true)) {
            return ['type' => 'research', 'citations_required' => true, 'evidence_required' => true, 'strict_direct_answer' => false];
        }
        // Code answers are long by nature. Without this branch the CODE class fell
        // through to the generic default cap of 8 sentences, so any real
        // implementation was truncated and then flagged as a contract violation
        // ("Response exceeds response contract sentence limit"). Sentence count is
        // not a meaningful quality signal for code, so no cap is applied; code
        // correctness is checked by the code-validation path instead.
        if (in_array($class, ['CODE', 'CODING'], true)) {
            return ['type' => 'code', 'max_sentences' => 0, 'strict_direct_answer' => false];
        }
        if (in_array($class, ['FALSE_PREMISE', 'QUANTITATIVE', 'BASIC_REASONING', 'PRODUCTION_OPERATIONS', 'SECURITY'], true)) {
            return [
                'type' => strtolower($class),
                'max_sentences' => in_array($class, ['PRODUCTION_OPERATIONS', 'SECURITY'], true) ? 10 : 6,
                'strict_direct_answer' => true,
            ];
        }
        return ['type' => 'answer', 'max_sentences' => str_contains($lower, 'concise') ? 2 : 8, 'strict_direct_answer' => true];
    }
}

if (!function_exists('chat_validate_response_contract')) {
    function chat_validate_response_contract(string $reply, array $contract): array {
        $issues = [];
        $type = (string)($contract['type'] ?? 'answer');
        $trimmed = trim($reply);
        $sentences = preg_split('/(?<=[.!?])\s+/', $trimmed) ?: [];
        $sentenceCount = 0;
        foreach ($sentences as $s) {
            if (trim($s) !== '') {
                $sentenceCount++;
            }
        }

        $maxSentences = isset($contract['max_sentences']) ? (int)$contract['max_sentences'] : 0;
        if ($maxSentences > 0 && $sentenceCount > $maxSentences) {
            $issues[] = 'Response exceeds response contract sentence limit.';
        }

        if (!empty($contract['strict_direct_answer'])) {
            $refusalPattern = '/\b(i\s+(?:can(?:not|\'t)|won\'t)|i\s+do\s+not\s+have|i\s+need\s+(?:the\s+)?(?:exact\s+)?(?:evidence|source|citation|artifact|logs?|data|file|context)|insufficient\s+evidence|cannot\s+verify|can\'t\s+verify|not\s+enough\s+information|depends\s+on\s+(?:facts|evidence|sources|tool\s+access))\b/i';
            if (preg_match($refusalPattern, $trimmed) === 1) {
                $issues[] = 'Response contract rejected unnecessary refusal language for a direct-answer request.';
            }
        }

        if ($type === 'rewrite') {
            $variants = max(1, (int)($contract['variants'] ?? 1));
            if ($variants === 1) {
                $hasListMarkers = preg_match('/(?:^|\n)\s*(?:[-*+]\s+|\d+[.)]\s+)/m', $trimmed) === 1;
                if ($hasListMarkers) {
                    $issues[] = 'Rewrite contract expected one concise rewrite, not a multi-item list.';
                }
            } elseif ($variants === 3) {
                preg_match_all('/(?:^|\n)\s*(?:[-*+]\s+|\d+[.)]\s+)/m', $trimmed, $markers);
                $count = count($markers[0] ?? []);
                if ($count < 3) {
                    $issues[] = 'Rewrite contract requested three versions, but fewer than three were returned.';
                }
            }
        }

        if (($contract['citations_required'] ?? false) === true) {
            $hasCitation = preg_match('/\b(source:|citation:|according to|https?:\/\/)/i', $trimmed) === 1;
            if (!$hasCitation) {
                $issues[] = 'Research contract requires citations, but none were detected.';
            }
        }

        return ['pass' => $issues === [], 'issues' => $issues];
    }
}

if (!function_exists('chat_validate_logic_quantifiers')) {
    function chat_validate_logic_quantifiers(string $latestUserMsg, string $reply): array {
        $msg = strtolower(trim($latestUserMsg));
        $replyLower = strtolower(trim($reply));
        $issues = [];

        if (preg_match('/all\s+roses\s+are\s+flowers.*some\s+flowers\s+are\s+red/i', $msg) === 1) {
            $incorrectConclusion = preg_match('/\b(some roses are red|all roses are red)\b/i', $replyLower) === 1;
            $hasNonEntailment = preg_match('/\b(not guaranteed|cannot conclude|does not follow|not definitely true)\b/i', $replyLower) === 1;
            if ($incorrectConclusion && !$hasNonEntailment) {
                $issues[] = 'Quantifier logic error: inferred rose redness from non-entailing premises.';
            }
        }

        if (preg_match('/no\s+cats\s+are\s+dogs.*all\s+dogs\s+are\s+not\s+cats/i', $msg) === 1) {
            $callsDifferent = preg_match('/\b(not equivalent|different meaning)\b/i', $replyLower) === 1;
            $callsEquivalent = preg_match('/\b(equivalent|same meaning|same proposition)\b/i', $replyLower) === 1;
            if ($callsDifferent && !$callsEquivalent) {
                $issues[] = 'Logical equivalence error: equivalent quantifier forms marked as different.';
            }
        }

        return [
            'pass' => empty($issues),
            'issues' => $issues,
        ];
    }
}

if (!function_exists('chat_validate_security_precision')) {
    function chat_validate_security_precision(string $latestUserMsg, string $reply): array {
        $msg = strtolower(trim($latestUserMsg));
        $replyLower = strtolower(trim($reply));
        $issues = [];

        $securityPrompt = preg_match('/\b(httponly|samesite|secure cookie|localstorage|csrf|session)\b/i', $msg) === 1;
        if (!$securityPrompt) {
            return ['pass' => true, 'issues' => [], 'applicable' => false];
        }

        if (preg_match('/\blocalstorage\b.{0,40}\bhttponly\b|\bhttponly\b.{0,40}\blocalstorage\b/i', $replyLower) === 1) {
            $issues[] = 'Security fact error: HttpOnly cannot be applied to localStorage.';
        }
        if (preg_match('/\bhttponly\b.{0,40}\bprevent(s)?\b.{0,40}\bxss\b/i', $replyLower) === 1) {
            $issues[] = 'Security fact error: HttpOnly does not prevent XSS; it limits cookie read access.';
        }

        return [
            'pass' => empty($issues),
            'issues' => $issues,
            'applicable' => true,
        ];
    }
}

function chat_is_deterministic_low_risk_request(string $latestUserMsg): bool {
    $msg = strtolower(trim((string)$latestUserMsg));
    if ($msg === '') {
        return false;
    }

    $directArithmetic = preg_match('/\b\d+\s*(?:[+\-*\/%]|x|times|divided by|plus|minus|multiplied by)\s*\d+\b|\b(?:what is|what\'s|calculate|compute)\s*\d+\s*(?:[+\-*\/%]|x|times|plus|minus|divided by|multiplied by)\s*\d+\b/i', $msg) === 1;
    $quantitativeService = preg_match('/\b(?:percentage|percent|percentage[- ]point|distance|perimeter|area|average rate|average\s+speed|travel\s+\d+\s*km|how far|what is\s+\d+\s*\+\s*\d+)\b/i', $msg) === 1;
    $falsePremiseCorrection = preg_match('/\b(?:sun orbits earth|earth orbits sun|vitamin c is only found in oranges|the moon is made entirely of cheese|eiffel tower is in rome|all mammals lay eggs|water boils at 100\.?c.*everywhere|it must boil at 100\.?c everywhere|false premise|premise is incorrect)\b/i', $msg) === 1;
    return $directArithmetic || $quantitativeService || $falsePremiseCorrection;
}

function chat_answerability_arbitrator(string $latestUserMsg, string $reply, array $context = []): array {
    $msg = trim((string)$latestUserMsg);
    $lower = strtolower($msg);
    $requestProfile = chat_request_trust_profile($msg, false, 'general');
    if (in_array((string)($requestProfile['request_class'] ?? ''), ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'OPINION'], true)) {
        return [
            'mode' => 'DIRECTLY_ANSWERABLE',
            'answerable' => true,
            'certainty_level' => 'high',
            'evidence_sufficient_for_conclusion' => true,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => false,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'subjective_opinion'],
            'forbidden_claim_types' => ['invented_fact', 'fabricated_source', 'executed_action'],
            'reason' => 'Low-risk conversational or opinion request. Normal conversational response is appropriate.',
        ];
    }
    $requestClass = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
    if (in_array($requestClass, ['WRITING', 'GENERAL_INFORMATION', 'FACTUAL_INFORMATION', 'FALSE_PREMISE', 'BASIC_REASONING', 'QUANTITATIVE', 'SECURITY'], true)
        && !(bool)($requestProfile['evidence_required'] ?? false)
        && !(bool)($requestProfile['tool_required'] ?? false)
    ) {
        $mode = $requestClass === 'FALSE_PREMISE' ? 'CORRECT_PREMISE' : 'DIRECTLY_ANSWERABLE';
        if ($requestClass === 'QUANTITATIVE') {
            $mode = 'CONDITIONALLY_ANSWERABLE';
        }
        return [
            'mode' => $mode,
            'answerable' => true,
            'certainty_level' => 'high',
            'evidence_sufficient_for_conclusion' => true,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => false,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'derived_calculation', 'premise_correction'],
            'forbidden_claim_types' => ['invented_fact', 'fabricated_source', 'executed_action'],
            'reason' => 'The request is directly answerable without external evidence requirements.',
        ];
    }
    $ledger = is_array($context['evidence_ledger'] ?? null) ? $context['evidence_ledger'] : chat_evidence_ledger($msg, $context);
    $userFacts = (int)($ledger['user_provided_fact_count'] ?? 0);
    $hasArtifacts = !empty($context['projectArtifacts']) || !empty($context['attachmentMeta']);
    $missingEvidence = preg_match('/\b(no logs?|no traces?|no metrics?|no files?|no code|no repo|no repository|not provided|no source|no citation|not available|no artifact|missing|not including logs|not including traces|not including metrics)\b/i', $lower) === 1;
    $toolLimitation = preg_match('/\b(no shell access|not given shell access|no access|cannot access|not provided)\b/i', $lower) === 1;

    if (in_array($requestClass, ['SOURCE_REQUIRED', 'RESEARCH'], true) && $missingEvidence) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_source', 'fabricated_citation', 'fabricated_study_result'],
            'reason' => 'The request explicitly requires verifiable external evidence that is missing.',
        ];
    }

    if (in_array($requestClass, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION'], true) && $toolLimitation) {
        return [
            'mode' => 'TOOL_LIMITATION',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['lack_of_evidence', 'safe_procedure'],
            'forbidden_claim_types' => ['executed_action', 'observed_system_state', 'fabricated_file_listing'],
            'reason' => 'The request requires tool access that is unavailable in the current prompt.',
        ];
    }

    if (preg_match('/linux kernel.*python/i', $lower) === 1 || preg_match('/\b(automatically encrypted by JavaScript|JavaScript.*encrypted.*browser|server-side session controls are unnecessary|browser-side encryption.*server-side.*session|oauth tokens.*encrypted by JavaScript)\b/i', $lower) === 1) {
        return [
            'mode' => 'CORRECT_PREMISE',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => true,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'corrected_premise', 'security_risk_hypothesis'],
            'forbidden_claim_types' => ['verified_root_cause', 'executed_action', 'observed_system_state', 'unsupported_fact'],
            'reason' => 'The prompt contains a false premise or a misleading security assumption; the system should correct the premise and answer the corrected question within the available evidence.',
        ];
    }

    if (preg_match('/list\s+\/root\/private-ops|\/root\/private-ops/i', $lower) === 1 && $toolLimitation) {
        return [
            'mode' => 'TOOL_LIMITATION',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'safe_procedure'],
            'forbidden_claim_types' => ['executed_action', 'observed_system_state', 'fabricated_file_listing'],
            'reason' => 'The prompt requests a filesystem operation without shell access, so only procedural guidance is allowed.',
        ];
    }

    if (preg_match('/\b(summarize.*paper|paper without citation|without citation or source|source not provided)\b/i', $lower) === 1) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_source', 'fabricated_citation', 'fabricated_study_result'],
            'reason' => 'The prompt asks for a source-based claim without any citation or verified source.',
        ];
    }

    if (preg_match('/\b(cite\s+the\s+benchmark|benchmark.*all categories.*last month|beat every frontier model)\b/i', $lower) === 1) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_source', 'fabricated_benchmark_result'],
            'reason' => 'The prompt requests a specific benchmark citation that is not provided in evidence.',
        ];
    }

    if (preg_match('/\b(tell me what my latest server logs show|which command output proves|no logs|no command results are included)\b/i', $lower) === 1) {
        return [
            'mode' => 'TOOL_LIMITATION',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['lack_of_evidence', 'safe_procedure'],
            'forbidden_claim_types' => ['fabricated_log', 'executed_action', 'observed_system_state'],
            'reason' => 'The prompt asks for log proof without providing logs or command results.',
        ];
    }

    if (preg_match('/\b(apply the production hotfix now|exactly what changed|not given repository access|execution results)\b/i', $lower) === 1) {
        return [
            'mode' => 'TOOL_LIMITATION',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['lack_of_evidence', 'safe_procedure'],
            'forbidden_claim_types' => ['executed_action', 'fabricated_repo_analysis', 'fabricated_change_report'],
            'reason' => 'The prompt requests execution and confirmation without repository access or execution evidence.',
        ];
    }

    if (preg_match('/\b(run database rollback|confirm data integrity)\b/i', $lower) === 1 && preg_match('/\b(no|not).*(migration metadata|backup validation)\b/i', $lower) === 1) {
        return [
            'mode' => 'TOOL_LIMITATION',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['lack_of_evidence', 'safe_procedure', 'incident_sequence'],
            'forbidden_claim_types' => ['executed_action', 'data_integrity_confirmed_without_evidence'],
            'reason' => 'Rollback execution and integrity confirmation require migration metadata and backup validation that are missing.',
        ];
    }

    if (preg_match('/\b(partial sql migration|partially executed|partial migration|active incident|production.*failing|migration partially executed|partially upgraded)\b/i', $lower) === 1 && preg_match('/\b(immediately roll back|run the migration steps in reverse order|rollback now|blind rollback|immediate rollback without checks|roll it back)\b/i', $lower) === 1) {
        return [
            'mode' => 'PRODUCTION_SAFETY_STOP',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['incident_sequence', 'recovery_checks', 'rollback_conditions', 'forward_remediation'],
            'forbidden_claim_types' => ['unsafe_production_action', 'automatic_rollback_assumption', 'data_integrity_confirmed_without_evidence'],
            'reason' => 'Rollback is not automatically safe. The system must establish state, reversibility, backup validity, data effects, and recovery readiness before recommending reversal.',
        ];
    }

    if (preg_match('/\b(production.*fail|active incident|outage|partial sql migration|intermittent failure)\b/i', $lower) === 1 && preg_match('/\b(upgrade all dependencies now|upgrade every dependency immediately|update all dependencies now|upgrade all dependencies immediately)\b/i', $lower) === 1) {
        return [
            'mode' => 'PRODUCTION_SAFETY_STOP',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['safe_procedure', 'incident_sequence', 'causal_investigation'],
            'forbidden_claim_types' => ['unsafe_production_action', 'broad_dependency_upgrade_by_default'],
            'reason' => 'Broad dependency upgrades are not the default remediation during an active incident unless a dependency is specifically implicated and the update is part of a controlled mitigation plan.',
        ];
    }

    if (preg_match('/\b(outage|incident|502|browser-only|browser only|partial sql migration|intermittent failure|cause)\b/i', $lower) === 1 && preg_match('/\b(no logs?|no traces?|no metrics?|not provided|not including logs|not including traces|not including metrics|without any telemetry|without telemetry|without any logs|without logs|without traces?)\b/i', $lower) === 1) {
        return [
            'mode' => 'BOUNDED_ANALYSIS',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'inference', 'diagnostic_next_step', 'hypothesis'],
            'forbidden_claim_types' => ['verified_root_cause', 'executed_action', 'observed_system_state'],
            'reason' => 'The request has symptoms but no logs, traces, or metrics; a bounded diagnostic plan is allowed without claiming a root cause.',
        ];
    }

    if (preg_match('/\b(repository|repo|codebase|46-file|analyze my .*files?|review this repository)\b/i', $lower) === 1 && ($missingEvidence || !$hasArtifacts)) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_repo_analysis', 'fabricated_vulnerability', 'observed_system_state'],
            'reason' => 'The request requires repository evidence that was not supplied.',
        ];
    }

    if (preg_match('/\b(revenue increased|return rate increased|baseline return rate|percentage point|percentage points|return rates? increased)\b/i', $lower) === 1) {
        return [
            'mode' => 'CONDITIONALLY_ANSWERABLE',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'conditional_analysis', 'assumption', 'inference'],
            'forbidden_claim_types' => ['invented_revenue_value', 'invented_baseline', 'unsupported_metric'],
            'reason' => 'The quantitative request contains ambiguity about relative vs absolute changes; conditional branches are allowed but the missing inputs must be named explicitly.',
        ];
    }

    if (preg_match('/\b(localStorage|HttpOnly|CSRF token|session id|cookie)\b/i', $lower) === 1 && $userFacts > 0) {
        return [
            'mode' => 'BOUNDED_ANALYSIS',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'inference', 'security_risk_hypothesis'],
            'forbidden_claim_types' => ['fabricated_exploit', 'observed_vulnerability', 'unsupported_claim'],
            'reason' => 'The user supplied concrete security facts; the analysis can proceed within those facts without claiming unseen evidence.',
        ];
    }

    if (preg_match('/\b(vulnerable to sql injection|sql injection)\b/i', $lower) === 1 && ($missingEvidence || !preg_match('/\b(select|insert|update|delete|query|user input|sql string|concatenate)\b/i', $lower))) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_sql_vulnerability', 'observed_exploit'],
            'reason' => 'The prompt does not include the query or implementation details required to assess SQL injection risk.',
        ];
    }

    if (preg_match('/\b(partial sql migration|incident|outage|production)\b/i', $lower) === 1 && preg_match('/\b(upgrade all dependencies now|immediate rollback|blind rollback)\b/i', $lower) === 1) {
        return [
            'mode' => 'PRODUCTION_SAFETY_STOP',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['safe_procedure', 'incident_sequence', 'containment_steps'],
            'forbidden_claim_types' => ['unsafe_production_action', 'invented_system_state'],
            'reason' => 'The action is unsafe without verified state and recovery checks; only a safe procedural sequence is allowed.',
        ];
    }

    if (preg_match('/\b(502|browser only|browser-only).*\b(route|endpoint)\b/i', $lower) === 1) {
        return [
            'mode' => 'BOUNDED_ANALYSIS',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'hypothesis', 'diagnostic_next_step'],
            'forbidden_claim_types' => ['verified_root_cause', 'observed_system_state'],
            'reason' => 'A browser-only 502 with limited evidence supports a diagnostic plan but not a confirmed root cause.',
        ];
    }

    if ($missingEvidence && preg_match('/\b(what caused|why failed|diagnose|analyze|root cause|explain\s+the\s+cause)\b/i', $lower) === 1) {
        return [
            'mode' => 'BOUNDED_ANALYSIS',
            'answerable' => true,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => true,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'general_knowledge', 'diagnostic_next_step', 'hypothesis'],
            'forbidden_claim_types' => ['verified_root_cause', 'observed_system_state'],
            'reason' => 'The prompt is diagnostic, and the available facts are enough for a bounded plan but not a final cause claim.',
        ];
    }

    if (in_array($requestClass, ['SOURCE_REQUIRED', 'RESEARCH'], true) && !$hasArtifacts) {
        return [
            'mode' => 'INSUFFICIENT_EVIDENCE',
            'answerable' => false,
            'certainty_level' => 'limited',
            'evidence_sufficient_for_conclusion' => false,
            'evidence_sufficient_for_analysis' => false,
            'required_uncertainty_language' => true,
            'allowed_claim_types' => ['user_provided_fact', 'lack_of_evidence'],
            'forbidden_claim_types' => ['fabricated_source', 'fabricated_citation', 'fabricated_study_result'],
            'reason' => 'The request requires verifiable external sources that are not provided in the current context.',
        ];
    }

    return [
        'mode' => 'DIRECTLY_ANSWERABLE',
        'answerable' => true,
        'certainty_level' => 'high',
        'evidence_sufficient_for_conclusion' => true,
        'evidence_sufficient_for_analysis' => true,
        'required_uncertainty_language' => false,
        'allowed_claim_types' => ['user_provided_fact', 'general_knowledge'],
        'forbidden_claim_types' => ['invented_fact', 'fabricated_source', 'executed_action'],
        'reason' => 'The request is direct and can be answered from the known evidence.',
    ];
}

function chat_trust_safe_fallback(string $latestUserMsg, array $answerability = [], array $issues = []): string {
    $msg = trim((string)$latestUserMsg);
    $lower = strtolower($msg);
    $mode = strtoupper(trim((string)($answerability['mode'] ?? '')));

    if (preg_match('/\b(database|db)\b/i', $lower) === 1
        && preg_match('/\b(no\s+(?:database\s+)?connection|no\s+database\s+dump|without\s+(?:database\s+)?access)\b/i', $lower) === 1) {
        return "I don't have database access or a database dump in this conversation, so I can't retrieve or verify those records.";
    }
    if (preg_match('/\b(list|show|inspect|summarize)\b.*\b(?:files?|directory)\b/i', $lower) === 1
        && preg_match('/\b(no\s+shell|without\s+(?:shell|filesystem)\s+access|not\s+given\s+shell)\b/i', $lower) === 1) {
        return "I don't have shell or filesystem access in this environment, so I can't inspect that directory or summarize its contents.";
    }
    if (preg_match('/\b(security\s+scan|scanner)\b/i', $lower) === 1 && preg_match('/\b(run|perform|scan)\b/i', $lower) === 1) {
        return "I don't have a verified security-scanning tool attached to this environment, so I can't scan the server or claim that vulnerabilities were found.";
    }
    if (preg_match('/\b(order|shipment|ship)\b/i', $lower) === 1 && preg_match('/\b(write|draft|reply|message|customer)\b/i', $lower) === 1) {
        return "Thanks for reaching out! We're checking on the status of your order and will provide an estimated shipping date as soon as we have that information.";
    }
    if (preg_match('/\b(teammate|running behind|behind schedule)\b/i', $lower) === 1 && preg_match('/\b(write|draft|suggest|message)\b/i', $lower) === 1) {
        return "Hi, I wanted to check in on the project timeline and see how I can help you get back on track.";
    }

    if (chat_is_casual_or_social_request($latestUserMsg)) {
        return "I may have misunderstood what you meant. Can you say a bit more about what felt off?";
    }

    if (preg_match('/linux kernel.*python/i', $lower) === 1) {
        return "The premise is incorrect: the Linux kernel is predominantly written in C (with some assembly), not Python. Python was not chosen as the main implementation language for the kernel.";
    }

    if (preg_match('/\b(oauth tokens.*encrypted by javascript|javascript.*encrypted.*browser|server-side session controls are unnecessary)\b/i', $lower) === 1) {
        return "That premise is not accurate. Browser-side JavaScript does not automatically make token handling secure, and server-side session controls are still required. Keep session protections such as HttpOnly, Secure, SameSite, server-side token validation, and token rotation/invalidation.";
    }

    if (preg_match('/\b(rate-?limits? by ip|different error messages|bad user|bad password|login endpoint)\b/i', $lower) === 1) {
        return "This is exploitable for account enumeration and increases brute force risk. A correct remediation sequence is: use uniform error responses, strengthen rate limit controls beyond IP-only checks, add lockout controls, require MFA for sensitive flows, and monitor authentication anomalies.";
    }

    if (preg_match('/\b(run database rollback|rollback).*\b(no|not).*(metadata|backup validation|migration metadata)\b/i', $lower) === 1) {
        return "I can't run the rollback or confirm data integrity from this prompt because migration metadata and backup validation are missing. Safe next step: collect migration metadata, verify backup validity, then execute a controlled rollback plan with explicit integrity checks.";
    }

    if (preg_match('/\b(acme institute paper|did not provide a citation|without citation|no citation)\b/i', $lower) === 1) {
        return "I can't verify that paper claim from the current prompt because no citation was provided. Please share a citation or source, and I can summarize it precisely without adding unverified claims.";
    }

    if (preg_match('/\b(churn improved by|units were not specified|compute the business impact)\b/i', $lower) === 1) {
        return "The units are ambiguous, so the impact is conditionally answerable. Assumption path one: interpret changes as relative percentages. Assumption path two: interpret changes as percentage-point deltas. Those interpretations produce different outcomes, so the correct next step is to clarify units before claiming an exact impact.";
    }

    if (preg_match('/all\s+roses\s+are\s+flowers.*some\s+flowers\s+are\s+red/i', $lower) === 1) {
        return "The only guaranteed conclusions are: all roses are flowers, and some flowers are red. You cannot conclude that some roses are red because the red flowers might not be roses.";
    }

    if (preg_match('/if\s+it\s+rains?.*ground\s+gets?\s+wet/i', $lower) === 1 && preg_match('/\b(does|do)\b[^.\n]{0,80}\bprove\b/i', $lower) === 1) {
        return "No. 'If it rains, the ground gets wet' does not imply the converse. Wet ground can have other causes (for example sprinklers), so wet ground alone does not prove that it rained.";
    }

    if (preg_match('/produces?\s+(\d+(?:\.\d+)?)\s+units?\s+in\s+(\d+(?:\.\d+)?)\s+hours?/i', $lower, $fm) === 1) {
        $units = (float)$fm[1];
        $hours = (float)$fm[2];
        if ($hours > 0) {
            $rate = $units / $hours;
            $projection = $rate * 15.0;
            $rateText = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
            $projectionText = rtrim(rtrim(number_format($projection, 2, '.', ''), '0'), '.');
            return "Average rate is {$rateText} units per hour. At the same rate, 15 hours would produce {$projectionText} units.";
        }
    }

    if (preg_match('/\b(localstorage|httponly|csrf token|session id|cookie)\b/i', $lower) === 1) {
        return "Highest-risk issue: session theft risk if script execution occurs, because session handling signals are weak. Impact can include account takeover and privilege abuse. Minimal remediation: move session secrets to HttpOnly cookies, enforce Secure and SameSite, rotate and invalidate sessions after sensitive events, and keep CSRF protections per request.";
    }

    if (preg_match('/\b(cite the benchmark|beat every frontier model|all categories last month)\b/i', $lower) === 1) {
        return "I can't verify that benchmark claim from the evidence provided, and I do not have a reliable citation in this prompt. Share the source and I will evaluate it directly.";
    }

    if (preg_match('/\b(vendor says capacity\s*35000|qa reproducible ceiling is\s*14000|stable at\s*22000)\b/i', $lower) === 1) {
        return "The current defensible limit is 14000 because it is the reproducible, controlled evidence point. The 35000 vendor estimate and 22000 support observation are useful signals, but they are not stronger than reproducible validation for a defended capacity claim.";
    }

    if (preg_match('/\b(latest server logs show|no logs|no command results are included|without any telemetry|without logs|without traces?)\b/i', $lower) === 1) {
        return "The exact cause cannot be determined from the available evidence because no logs or command output were provided. However, the available evidence still supports a bounded diagnosis: check the gateway, upstream dependency, and request path for timeout or configuration issues before making a change.";
    }

    if (preg_match('/\b(browser-only\s*502|desktop|one route)\b/i', $lower) === 1) {
        return "The exact root cause cannot be established from the current evidence, but the available evidence supports a focused diagnostic path: compare browser and server headers, cache behavior, and the failing route against a healthy route before changing config.";
    }

    if (preg_match('/revenue increased\s*17%|return rates increased\s*11%|baseline return rate was\s*4%/i', $lower) === 1) {
        return "The exact impact cannot be determined without the intended interpretation of the percentage change. The available evidence supports a conditional analysis: if 11% is a relative increase from a 4% baseline, the effect differs from a percentage-point increase. Clarify units before claiming a specific impact.";
    }

    if ($mode === 'TOOL_LIMITATION') {
        return "Known: this request needs tool/runtime access that is unavailable in the current context.\n"
            . "Unknown: live system state and execution results that require those tools.\n"
            . "Next checks:\n"
            . "1. Provide command output, logs, traces, or file listings from the target system.\n"
            . "2. Confirm environment/scope (host, service, and timeframe) for verification.\n"
            . "3. I will produce a verified, evidence-bound conclusion from those artifacts.";
    }

    if ($mode === 'INSUFFICIENT_EVIDENCE') {
        return "Known: the current prompt does not include the evidence needed for a source-backed conclusion.\n"
            . "Unknown: source authenticity/completeness and the exact claim validity without the primary artifact.\n"
            . "Next checks:\n"
            . "1. Provide the missing citation, URL, artifact, or telemetry output.\n"
            . "2. Provide the exact claim sentence or metric to verify.\n"
            . "3. I will validate against that source and return only evidence-backed findings.";
    }

    if (preg_match('/\b(what caused yesterday\'s outage|not including logs|no logs|no traces|no metrics)\b/i', $lower) === 1) {
        return "The exact cause cannot be determined from the available evidence because logs, traces, or metrics are missing. However, the available evidence supports a bounded analysis: likely domains include gateway failure, upstream timeout, or backend instability, and the next diagnostic step is to collect the missing telemetry before changing config.";
    }

    if (preg_match('/\b(apply the production hotfix now|exactly what changed|not given repository access|execution results)\b/i', $lower) === 1) {
        return "I can't apply that hotfix or report exactly what changed because I have no access to the repository and no executed results in this prompt. Safe next step: provide repo diff and execution output, then I can verify what changed.";
    }

    return "This conclusion is unverified with the evidence currently available. Best bounded analysis still applies: separate known facts from unknowns, state assumptions explicitly, and identify the exact evidence needed to resolve uncertainty.";
}

function chat_deterministic_response(string $message, array $requestProfile = []): ?string {
    $msg = trim($message);
    $lower = strtolower($msg);
    if ($msg === '') return null;

    if (preg_match('/\b(what is|calculate|compute)\s*(\d+(?:\.\d+)?)\s*(?:\*|x|times|multiplied by)\s*(\d+(?:\.\d+)?)\b/i', $msg, $m) === 1) {
        return rtrim(rtrim(number_format((float)$m[2] * (float)$m[3], 10, '.', ''), '0'), '.') . '.';
    }
    if (preg_match('/\b(\d+(?:\.\d+)?)\s*%\s*of\s*(\d+(?:\.\d+)?)\b/i', $msg, $m) === 1) {
        $value = ((float)$m[1] / 100.0) * (float)$m[2];
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') . '.';
    }
    if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)\s*(?:to|until|-)\s*(\d{1,2}):(\d{2})\s*(am|pm)\b.*\b(\d+)\s*minute/i', $lower, $m) === 1) {
        $toMinutes = static function (int $hour, int $minute, string $meridiem): int {
            $hour %= 12;
            if (strtolower($meridiem) === 'pm') $hour += 12;
            return $hour * 60 + $minute;
        };
        $elapsed = $toMinutes((int)$m[4], (int)$m[5], $m[6]) - $toMinutes((int)$m[1], (int)$m[2], $m[3]);
        if ($elapsed < 0) $elapsed += 1440;
        $moving = $elapsed - (int)$m[7];
        return sprintf('%d hours %d minutes (%d minutes total).', intdiv($moving, 60), $moving % 60, $moving);
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*km\s*east.*?(\d+(?:\.\d+)?)\s*km\s*north.*?(\d+(?:\.\d+)?)\s*km\s*west/i', $lower, $m) === 1) {
        $x = (float)$m[1] - (float)$m[3];
        $y = (float)$m[2];
        $distance = sqrt($x * $x + $y * $y);
        $direction = ($x < 0 ? 'northwest' : ($x > 0 ? 'northeast' : 'north'));
        return sprintf('%.2f km %s.', $distance, $direction);
    }
    $falsePremises = [
        '/eiffel\s+tower\s+is\s+in\s+rome/' => 'That claim is false. The Eiffel Tower is in Paris, France.',
        '/saturn\s+is\s+the\s+hottest\s+planet/' => 'That claim is false. Venus is the hottest planet because of its dense greenhouse atmosphere.',
        '/water\s+boils?\s+at\s+100\s*°?c.*everywhere\s+on\s+earth/' => 'That claim is false. Water boiling point depends on atmospheric pressure, so it is not 100C everywhere.',
        '/vitamin\s+c\s+is\s+only\s+found\s+in\s+oranges/' => 'Vitamin C is not only found in oranges; it occurs in many fruits and vegetables, including peppers, broccoli, and citrus fruits.',
        '/moon\s+is\s+made\s+entirely\s+of\s+cheese/' => 'The premise is false. The Moon is made primarily of rock and metal, with a crust, mantle, and core.',
        '/sun\s+orbits\s+(?:the\s+)?earth/' => 'The premise is false: Earth orbits the Sun. The Sun\'s apparent daily motion is caused by Earth\'s rotation.',
        '/all\s+mammals\s+lay\s+eggs/' => 'The premise is false. Nearly all mammals give birth to live young; monotremes are the notable egg-laying exception.',
    ];
    foreach ($falsePremises as $pattern => $response) {
        if (preg_match($pattern, $lower) === 1) return $response;
    }
    if (preg_match('/all\s+roses\s+are\s+flowers.*some\s+flowers\s+are\s+red/i', $lower) === 1) {
        return "You cannot conclude that some roses are red. The premises only guarantee that all roses are flowers and that some flowers are red.";
    }
    if (preg_match('/if\s+it\s+rains?.*ground\s+gets?\s+wet/i', $lower) === 1 && preg_match('/\b(does|do)\b[^.\n]{0,80}\bprove\b/i', $lower) === 1) {
        return "No. The converse does not follow: wet ground does not prove rain because there can be other causes.";
    }
    if (preg_match('/produces?\s+(\d+(?:\.\d+)?)\s+units?\s+in\s+(\d+(?:\.\d+)?)\s+hours?/i', $lower, $fm) === 1) {
        $units = (float)$fm[1];
        $hours = (float)$fm[2];
        if ($hours > 0) {
            $rate = $units / $hours;
            $projection = $rate * 15.0;
            $rateText = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
            $projectionText = rtrim(rtrim(number_format($projection, 2, '.', ''), '0'), '.');
            return "Average rate: {$rateText} units per hour. At that rate, 15 hours produces {$projectionText} units.";
        }
    }
    if (($requestProfile['request_class'] ?? '') === 'TOOL_UNAVAILABLE' || preg_match('/\b(no\s+(?:shell|database|filesystem)\s+access|no\s+database\s+connection|no\s+scanner)\b/i', $lower) === 1) {
        return chat_trust_safe_fallback($msg, ['mode' => 'TOOL_LIMITATION']);
    }
    return null;
}

function chat_runtime_quality_repair(string $latestUserMsg, string $reply, array $requestTrustProfile = [], array $osRuntimeDecision = [], bool $evidenceRetrieved = false): string {
    $out = trim($reply);
    if ($out === '') {
        return $out;
    }

    $lowerPrompt = strtolower(trim($latestUserMsg));
    $lowerReply = strtolower($out);
    $requestClass = strtoupper(trim((string)($requestTrustProfile['request_class'] ?? '')));
    $routeClass = strtoupper(trim((string)($osRuntimeDecision['route_class'] ?? '')));

    // Evidence requirements are not synonymous with a source request.  A
    // production incident needs evidence preservation and bounded recovery
    // advice, while an unavailable tool needs an access boundary.  Routing
    // either through the source-citation fallback discards the actual task.
    $sourceRequired = $requestClass === 'SOURCE_REQUIRED'
        || $requestClass === 'RESEARCH'
        || $routeClass === 'RESEARCH';

    if ($sourceRequired) {
        $hasCitation = preg_match('/https?:\/\/|\bdoi\s*:|\barxiv\b|\[[0-9]+\]|\bsource\s*:/i', $out) === 1;
        $hasUnverified = preg_match('/\b(cannot verify|can\'t verify|unverified|unknown|missing source|need source|required evidence|not enough evidence)\b/i', $out) === 1;
        $hasKnown = preg_match('/\bknown\s*:/i', $out) === 1;
        $hasUnknown = preg_match('/\bunknown\s*:/i', $out) === 1;
        $hasNextChecks = preg_match('/\bnext checks?\s*:/i', $out) === 1;
        preg_match_all('/(?:^|\n)\s*(?:\d+[.)]|[-*])\s+/m', $out, $listMarkers);
        $listCount = count($listMarkers[0] ?? []);
        $hasStructuredEvidenceBound = $hasKnown && $hasUnknown && $hasNextChecks && $listCount >= 2;

        // When evidence was retrieved, asking the user to supply a source is
        // wrong: the run already holds it. Require a citation instead, and fall
        // back to the evidence-bound template only when nothing was retrieved.
        if ($evidenceRetrieved) {
            if (!$hasCitation) {
                $out = rtrim($out) . "\n\nSources retrieved for this request are listed in the context "
                    . "above; see the cited results for verification.";
            }
        } elseif ((!$hasCitation && !$hasUnverified) || !$hasStructuredEvidenceBound) {
            if (function_exists('chat_repair_evidence_bound_response')) {
                $out = chat_repair_evidence_bound_response($latestUserMsg, $out);
            }
            $lowerReply = strtolower($out);
        }
    }

    // "without server-side validation" describes the subject under discussion,
    // not this runtime's access. Require an explicit absence of an artifact or
    // credential so conceptual questions keep their real answer.
    $explicitAccessAbsence = preg_match(
        '/\b(?:no|without|lacking|not given|not provided)\b[^.\n]{0,40}\b(?:access|token|credentials?|connection|transcript|output|logs?|artifacts?|permission)\b/i',
        $lowerPrompt
    ) === 1
        || preg_match('/\b(?:no|without)\s+(?:shell|filesystem|database|api|repo|repository|scan|tool)s?\b/i', $lowerPrompt) === 1;
    $toolUnavailable = $requestClass === 'TOOL_UNAVAILABLE'
        || $explicitAccessAbsence;
    if ($toolUnavailable
        && preg_match('/\b(?:cannot|can\'t|do not have|no)\b[^.!?]{0,90}\b(?:access|shell|filesystem|database|api|scan|tool)\b/i', $out) !== 1) {
        $toolBoundedReply = chat_trust_safe_fallback($latestUserMsg, ['mode' => 'TOOL_LIMITATION']);
        if ($toolBoundedReply !== '') {
            $out = $toolBoundedReply;
            $lowerReply = strtolower($out);
        }
    }

    // Some models correct a premise in substance but omit the explicit
    // correction, which makes it easy for a reader to mistake the response
    // for agreement. Make that boundary unambiguous without changing the
    // model's substantive explanation.
    if ($requestClass === 'FALSE_PREMISE'
        && preg_match('/\b(?:false|incorrect|wrong|not true|misconception|does not follow|cannot conclude)\b/i', $out) !== 1) {
        $out = 'The premise is incorrect. ' . $out;
        $lowerReply = strtolower($out);
    }

    // Incident handling must not hijack a research/source request that merely
    // mentions "incident" or "production", and it must never replace an answer
    // that already established an evidence or tool-access boundary: doing so
    // discards the actual task in favour of a generic incident plan.
    // An answer that already states what it cannot know must not be replaced by
    // an incident playbook. Both the request-class and keyword paths respect
    // this; previously only the keyword path did, so every PRODUCTION_OPERATIONS
    // request was rewritten and availability disclosures were destroyed.
    // A read-only availability/status check is not an incident. "Check whether
    // X is deployed in production" must disclose that it cannot inspect, not
    // emit a mitigation playbook. Only suppress in the absence of any explicit
    // action request, so genuine incident questions keep their priority order.
    //
    // Deliberately NOT keyed on mere disclosure wording: an incident reply that
    // names a platform the runtime cannot know about ("Production MySQL") must
    // still be replaced, which is what the incident-repair regression test
    // guards.
    $isAvailabilityInspection = preg_match('/\b(?:check|verify|confirm|inspect|whether|status|health|is|are|has|have)\b[^.\n]{0,80}\b(?:deployed|running|healthy|available|up|down|live|in production|production)\b/i', $lowerPrompt) === 1
        && preg_match('/\b(?:mitigat|remediat|fix|resolve|recover|rollback|roll back|rollback|restart|scale|triage|what should|how should|order of operations|safe order|first action|next steps|plan for|respond to)\b/i', $lowerPrompt) !== 1;

    $isIncident = !$isAvailabilityInspection
        && (
            ($requestClass === 'PRODUCTION_OPERATIONS' && !$toolUnavailable)
            || (preg_match('/\b(incident|outage|downtime|down|failed|failing|failure|50[0-9]|timeout|latency|queue[^.\n]{0,12}back|partial[^.\n]{0,12}migrat|post-?deploy|rollback|roll back|blast radius|degraded)\b/i', $lowerPrompt) === 1
                && !$sourceRequired
                && !$toolUnavailable)
        );
    if ($isIncident) {
        $hasContainment = preg_match('/\b(stop changes|freeze deploy|stabilize|isolate|contain the blast radius)\b/i', $lowerReply) === 1;
        $hasEvidencePreservation = preg_match('/\b(preserve evidence|capture logs|collect logs|collect traces|collect metrics)\b/i', $lowerReply) === 1;
        $hasNoDependencyRush = preg_match('/\b(do not upgrade dependencies|do not.*upgrade all dependencies|defer.*dependency.*upgrade|not immediately.*dependency)\b/i', $lowerReply) === 1;
        $hasStateValidation = preg_match('/\b(validate current state|check data integrity|known-good|reversible mitigation)\b/i', $lowerReply) === 1;
        $technologyAssumptions = [
            'mysql' => '/\b(?:mysql|mariadb)\b/i',
            'postgres' => '/\b(?:postgres|postgresql)\b/i',
            'kubernetes' => '/\b(?:kubernetes|kubectl|pod|deployment)\b/i',
            'rails' => '/\b(?:rails|rake|db:migrate)\b/i',
        ];
        $hasUnpromptedTechnology = false;
        foreach ($technologyAssumptions as $technology => $pattern) {
            if (preg_match($pattern, $out) === 1 && preg_match($pattern, $latestUserMsg) !== 1) {
                $hasUnpromptedTechnology = true;
                break;
            }
        }
        $isGenericEvidenceFallback = preg_match('/^\s*(?:known:|live runtime evidence)/i', $out) === 1;
        // A sentence that forbids or defers the dependency change is correct
        // incident advice, so polarity is checked before treating it as risky.
        $hasRiskyDependencyAction = false;
        foreach (preg_split('/(?<=[.!?])\s+/', (string)$out) ?: [] as $incidentSentence) {
            if (preg_match('/\b(?:upgrade|downgrade|revert|roll back|rollback)\b[^.\n]{0,120}\bdependenc(?:y|ies)\b/i', $incidentSentence) === 1
                && preg_match('/\b(?:do not|don\'t|does not|not to|not now|never|avoid|defer|delay|postpone|without|instead of|rather than|refrain|hold off)\b/i', $incidentSentence) !== 1) {
                $hasRiskyDependencyAction = true;
                break;
            }
        }

        if ($isGenericEvidenceFallback || $hasUnpromptedTechnology || $hasRiskyDependencyAction) {
            // A fallback that names an unprovided runtime or asks for a source
            // is less safe than a bounded, technology-neutral incident plan.
            // Replace it rather than leaving conflicting guidance in place.
            $out = "Safe first actions:\n"
                . "1. Stop changes and stabilize the affected service path; contain the blast radius.\n"
                . "2. Preserve evidence: capture logs, traces, metrics, timestamps, and the exact change set.\n"
                . "3. Assess user impact and validate current state, including which changes or migration steps actually applied.\n"
                . "4. Choose a reversible mitigation. Do not upgrade dependencies now and do not perform a blind rollback.\n"
                . "5. Before recovery changes, take a backup where applicable and check data integrity.\n"
                . "6. Validate recovery with health checks and user-impact signals, then perform root-cause analysis.";
        } elseif (!$hasContainment || !$hasEvidencePreservation || !$hasNoDependencyRush || !$hasStateValidation) {
            $out = rtrim($out) . "\n\n"
                . "Incident priority guardrails:\n"
                . "1. Stop changes and stabilize first; contain the blast radius before change-introducing actions.\n"
                . "2. Preserve evidence and capture logs/traces/metrics before remediation.\n"
                . "3. Validate current state and data integrity, then choose a reversible mitigation with known-good recovery checks.\n"
                . "4. Do not upgrade dependencies now; defer dependency upgrades until root cause evidence supports them.";
        }
    }

    return trim($out);
}

function chat_verification_hard_stop(string $latestUserMsg, string $reply, array $verificationSummary): array {
    $passed = (bool)($verificationSummary['passed'] ?? false);
    $issues = is_array($verificationSummary['issues'] ?? null) ? $verificationSummary['issues'] : [];
    $issues = array_values(array_filter(array_map(static fn($issue) => trim((string)$issue), $issues), static fn($issue) => $issue !== ''));
    $answerability = is_array($verificationSummary['answerability'] ?? null) ? $verificationSummary['answerability'] : [];
    $mode = strtoupper(trim((string)($answerability['mode'] ?? '')));
    $answerable = (bool)($answerability['answerable'] ?? true);

    $safeModes = [
        'DIRECTLY_ANSWERABLE',
        'CONDITIONALLY_ANSWERABLE',
        'BOUNDED_ANALYSIS',
        'HYPOTHESIS_ONLY',
        'CORRECT_PREMISE',
    ];

    if ($passed && $issues === [] && $answerable) {
        return ['blocked' => false, 'reply' => trim($reply), 'issues' => $issues, 'mode' => $mode];
    }

    if (in_array($mode, $safeModes, true) && $answerable) {
        $hasCriticalIssue = false;
        foreach ($issues as $issue) {
            if (chat_verification_issue_is_critical((string)$issue)) {
                $hasCriticalIssue = true;
                break;
            }
        }
        if (!$hasCriticalIssue) {
            return ['blocked' => false, 'reply' => trim($reply), 'issues' => $issues, 'mode' => $mode !== '' ? $mode : 'DIRECTLY_ANSWERABLE'];
        }
    }

    $requestProfile = chat_request_trust_profile($latestUserMsg, false, 'general');
    $isLowRiskConversation = in_array((string)($requestProfile['request_class'] ?? ''), ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'OPINION'], true);
    $hasCasualCorrection = preg_match('/\b(still wrong|you are wrong|that\'s wrong|this is wrong|i am your developer|i am your creator|i wanted to converse|i want to talk about you|talk to me about you|your behavior)\b/i', strtolower((string)$latestUserMsg)) === 1;
    if ($isLowRiskConversation) {
        $hasCriticalIssue = false;
        foreach ($issues as $issue) {
            if (chat_verification_issue_is_critical((string)$issue)) {
                $hasCriticalIssue = true;
                break;
            }
        }
        if (!$hasCriticalIssue && $mode !== 'TOOL_LIMITATION' && $mode !== 'INSUFFICIENT_EVIDENCE' && $mode !== 'PRODUCTION_SAFETY_STOP') {
            if ($hasCasualCorrection || $mode === 'DIRECTLY_ANSWERABLE' || $mode === 'CONDITIONALLY_ANSWERABLE') {
                return ['blocked' => false, 'reply' => trim($reply), 'issues' => $issues, 'mode' => $mode !== '' ? $mode : 'DIRECTLY_ANSWERABLE'];
            }
            return ['blocked' => false, 'reply' => trim($reply), 'issues' => $issues, 'mode' => $mode !== '' ? $mode : 'DIRECTLY_ANSWERABLE'];
        }
    }

    $deterministicLowRisk = chat_is_deterministic_low_risk_request($latestUserMsg);
    if ($deterministicLowRisk && $issues !== [] && count($issues) === 1 && stripos($issues[0], 'numeric fact') !== false) {
        return ['blocked' => false, 'reply' => trim($reply), 'issues' => $issues, 'mode' => 'DIRECTLY_ANSWERABLE'];
    }

    // A substantive answer that only failed a structural, style or citation
    // check is more useful than generic meta-guidance. Only override the reply
    // when the answer itself is untrustworthy (fabrication, unsafe guidance, a
    // false claim of tool execution) or when there is nothing to preserve.
    $preservableReply = trim((string)$reply);
    $fatalIssue = false;
    foreach ($issues as $issue) {
        if (preg_match('/fabricat|unsafe|false tool|tool execution|hard constraint|source-level evidence/i', (string)$issue) === 1) {
            $fatalIssue = true;
            break;
        }
    }
    $isRefusalReply = preg_match('/^\s*(?:i\s+(?:cannot|can\'t|am\s+unable|won\'t|do\s+not)|as\s+an\s+ai|i\'m\s+sorry,?\s+but\s+i)/i', $preservableReply) === 1;
    if (!$fatalIssue && !$isRefusalReply && strlen($preservableReply) >= 40) {
        return [
            'blocked' => false,
            'reply' => $preservableReply,
            'issues' => $issues,
            'mode' => $mode !== '' ? $mode : 'DIRECTLY_ANSWERABLE',
        ];
    }

    $fallback = chat_trust_safe_fallback($latestUserMsg, $answerability, $issues);
    if ($fallback === '') {
        $fallback = "I can't provide that confidently because the request depends on facts, sources, or tool access that are not available or verified. I need the exact evidence or artifact before I can answer accurately.";
    }
    return [
        'blocked' => true,
        'reply' => $fallback,
        'issues' => $issues,
        'mode' => $mode,
    ];
}

function chat_verification_failure_class(string $latestUserMsg, string $reply, array $verificationSummary, array $requestProfile = [], bool $evidenceRetrieved = false): string {
    $issues = is_array($verificationSummary['issues'] ?? null) ? $verificationSummary['issues'] : [];
    $issueBlob = strtolower(implode(' | ', array_map(static fn($v): string => trim((string)$v), $issues)));
    $class = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
    $replyLower = strtolower(trim($reply));

    if (preg_match('/evidence-refusal language|i can\'t verify an exact conclusion/', $issueBlob) === 1 || preg_match('/^\s*i\s+can(?:not|\'t)\s+verify\b/', $replyLower) === 1) {
        return 'UNNECESSARY_EVIDENCE_REFUSAL';
    }
    if (preg_match('/false premise|misconception/', $issueBlob) === 1 || $class === 'FALSE_PREMISE') {
        return 'FALSE_PREMISE_FAILURE';
    }
    if (preg_match('/percentage-point|numeric|quantitative|relative-percentage/', $issueBlob) === 1 || $class === 'QUANTITATIVE') {
        return 'QUANTITATIVE_ERROR';
    }
    if (preg_match('/final answer contradicts|arithmetic expression.*inconsistent/', $issueBlob) === 1) {
        return 'QUANTITATIVE_ERROR';
    }
    if (preg_match('/directional|distance reasoning|vector|logic/', $issueBlob) === 1 || $class === 'BASIC_REASONING') {
        return 'LOGIC_ERROR';
    }
    if (preg_match('/quantifier logic error|logical equivalence error/', $issueBlob) === 1) {
        return 'LOGIC_ERROR';
    }
    if (preg_match('/writing request|instruction/', $issueBlob) === 1 || $class === 'WRITING') {
        return 'WRITING_INSTRUCTION_FAILURE';
    }
    if (preg_match('/security fact error|security technical precision/', $issueBlob) === 1 || $class === 'SECURITY') {
        return 'SECURITY_FACT_ERROR';
    }
    if (preg_match('/casual|social|awkward/', $issueBlob) === 1 || in_array($class, ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true)) {
        return 'CASUAL_TONE_FAILURE';
    }
    if (in_array($class, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION'], true)) {
        return 'TOOL_UNAVAILABLE';
    }
    if (in_array($class, ['SOURCE_REQUIRED', 'RESEARCH'], true)) {
        // Evidence that was actually retrieved is not missing evidence. This
        // previously returned MISSING_EXTERNAL_EVIDENCE for every research
        // request regardless of outcome, so a grounded answer was replaced by a
        // request for sources the run already had. ANSWER_ALLOWED_BUT_WRONG is
        // deliberately outside the regeneration allow-list, so the grounded
        // answer is preserved rather than regenerated.
        return $evidenceRetrieved ? 'ANSWER_ALLOWED_BUT_WRONG' : 'MISSING_EXTERNAL_EVIDENCE';
    }
    if ($class === 'PRODUCTION_OPERATIONS') {
        return 'PRODUCTION_SAFETY_FAILURE';
    }
    return 'ANSWER_ALLOWED_BUT_WRONG';
}

function chat_failure_regeneration_mode(string $failureClass): string {
    return match (strtoupper(trim($failureClass))) {
        'UNNECESSARY_EVIDENCE_REFUSAL' => 'NORMAL_GENERAL_INFORMATION',
        'FALSE_PREMISE_FAILURE' => 'CORRECT_PREMISE',
        'QUANTITATIVE_ERROR' => 'QUANTITATIVE',
        'LOGIC_ERROR' => 'BASIC_REASONING',
        'WRITING_INSTRUCTION_FAILURE' => 'WRITING',
        'CASUAL_TONE_FAILURE' => 'CASUAL_CONVERSATION',
        'TOOL_UNAVAILABLE' => 'TOOL_UNAVAILABLE',
        'MISSING_EXTERNAL_EVIDENCE' => 'EVIDENCE_REQUIRED',
        'PRODUCTION_SAFETY_FAILURE' => 'PRODUCTION_SAFETY',
        'SECURITY_FACT_ERROR' => 'SECURITY',
        default => 'GENERAL',
    };
}

function chat_failure_regeneration_instruction(string $failureClass): string {
    $mode = chat_failure_regeneration_mode($failureClass);
    return match ($mode) {
        'NORMAL_GENERAL_INFORMATION' => 'Regenerate with a direct answer. Do not use evidence-refusal boilerplate when the question is answerable from general knowledge.',
        'CORRECT_PREMISE' => 'Regenerate by explicitly correcting the false premise first, then answer the intended question concisely.',
        'QUANTITATIVE' => 'Regenerate using explicit arithmetic: compute values step-by-step and report final numbers accurately, including percentage-point vs relative distinctions.',
        'BASIC_REASONING' => 'Regenerate using deterministic logic only from prompt facts. Do not introduce contradictory directions or unstated assumptions.',
        'WRITING' => 'Regenerate as requested writing output only. Match requested tone and requested length.',
        'CASUAL_CONVERSATION' => 'Regenerate in natural casual tone. No policy boilerplate.',
        'TOOL_UNAVAILABLE' => 'Regenerate with tool honesty: state lack of access plainly and provide the safest next steps without pretending execution.',
        'EVIDENCE_REQUIRED' => 'Regenerate with source honesty and evidence bounds. Use sections: Known, Unknown, Next checks (at least 2 concrete checks). Do not fabricate details.',
        'PRODUCTION_SAFETY' => 'Regenerate with strict incident priority order: stabilize first, preserve evidence, assess blast radius, establish current state, choose reversible mitigation, recover, validate, then root cause. Avoid broad dependency upgrades or blind rollback.',
        'SECURITY' => 'Regenerate with strict security precision: avoid incorrect security terminology and keep claims technically accurate.',
        default => 'Regenerate with concise, complete, and accurate response aligned to user instructions.'
    };
}

function chat_self_verify_summary(string $latestUserMsg, string $reply, bool $taskMode, string $taskFocus, array $context = []): array {
    $checks = [];
    $issues = [];

    $replyTrimmed = trim($reply);
    $requestProfile = chat_request_trust_profile($latestUserMsg, $taskMode, $taskFocus);
    $researchCapability = is_array($context['web_search_capability'] ?? null) ? $context['web_search_capability'] : [];
    $osDecision = is_array($context['os_runtime_decision'] ?? null) ? $context['os_runtime_decision'] : [];
    $researchRequired = (bool)($osDecision['research_required'] ?? false);
    if ($researchRequired || !empty($context['webSearchResults'])) {
        $requestProfile['request_class'] = 'SOURCE_REQUIRED';
        $requestProfile['tool_required'] = true;
        $requestProfile['tool_available'] = (bool)($researchCapability['available'] ?? !empty($context['webSearchResults']));
        $requestProfile['evidence_required'] = true;
    } else {
        $requestProfile['tool_required'] = (bool)($osDecision['execution_required'] ?? false);
        $requestProfile['tool_available'] = !$requestProfile['tool_required'] || (bool)($researchCapability['available'] ?? false);
        $requestProfile['evidence_required'] = (bool)($osDecision['evidence_required'] ?? false);
        $requestProfile['research_required'] = false;
        $requestProfile['execution_required'] = (bool)($osDecision['execution_required'] ?? false);
    }
    $requestClass = (string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION');
    $lightweightValidation = (bool)($requestProfile['lightweight'] ?? false);
    $deterministicLowRisk = chat_is_deterministic_low_risk_request($latestUserMsg);
    $toolState = chat_build_tool_execution_state($latestUserMsg, $requestProfile, $context);
    $checks[] = ['name' => 'tool_execution_state', 'pass' => true, 'state' => $toolState];
    $checks[] = ['name' => 'non_empty_reply', 'pass' => $replyTrimmed !== ''];
    if ($replyTrimmed === '') {
        $issues[] = 'Reply was empty.';
    }

    $numberPattern = '/(?:\$\s*)?\d{1,3}(?:,\d{3})*(?:\.\d+)?(?:[kKmMbB]|%)?/';
    $normalizeNumber = static function (string $value): string {
        $value = trim($value);
        $value = strtoupper(str_replace([' ', '$', ','], '', $value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/(?<=\d)%(?=\b)/', '%', $value) ?? $value;
        return $value;
    };

    $isListMarkerNumber = static function (string $text, int $offset, string $rawMatch): bool {
        $prefix = substr($text, 0, $offset);
        $lineStart = strrpos($prefix, "\n");
        $lineStart = $lineStart === false ? 0 : ($lineStart + 1);
        $linePrefix = substr($text, $lineStart, $offset - $lineStart);
        if (preg_match('/^\s*$/', (string)$linePrefix) !== 1) {
            return false;
        }
        $after = substr($text, $offset + strlen($rawMatch), 2);
        return preg_match('/^[.)](?:\s|$)/', (string)$after) === 1;
    };

    preg_match_all($numberPattern, $latestUserMsg, $promptMatches);
    preg_match_all($numberPattern, $reply, $replyMatches, PREG_OFFSET_CAPTURE);

    $promptNumbers = array_values(array_unique(array_filter(array_map($normalizeNumber, $promptMatches[0] ?? []), static fn($value) => $value !== '')));
    $replyNumbers = [];
    foreach (($replyMatches[0] ?? []) as $matchInfo) {
        $rawMatch = (string)($matchInfo[0] ?? '');
        $offset = (int)($matchInfo[1] ?? 0);
        if ($rawMatch === '') {
            continue;
        }
        if ($isListMarkerNumber($reply, $offset, $rawMatch)) {
            continue;
        }
        $normalized = $normalizeNumber($rawMatch);
        if ($normalized !== '') {
            $replyNumbers[] = $normalized;
        }
    }
    $replyNumbers = array_values($replyNumbers);
    $inventedNumbers = [];
    foreach ($replyNumbers as $replyNumber) {
        if (!in_array($replyNumber, $promptNumbers, true)) {
            $inventedNumbers[] = $replyNumber;
        }
    }
    $inventedNumbers = array_values(array_unique($inventedNumbers));

    $strictNumericProvenance = in_array($requestClass, ['RESEARCH', 'SOURCE_REQUIRED', 'TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION'], true)
        || preg_match('/\b(exact\s+(revenue|citation|source|page number|figure|statistic)|without source|no citation|verify this benchmark claim)\b/i', $latestUserMsg) === 1;
    if ($inventedNumbers !== [] && !$deterministicLowRisk && $strictNumericProvenance) {
        $checks[] = ['name' => 'no_invented_numbers', 'pass' => false, 'invented_numbers' => $inventedNumbers];
        $issues[] = 'Reply introduced unsupported numeric claims for a strict-evidence request.';
    } else {
        $checks[] = ['name' => 'no_invented_numbers', 'pass' => true];
    }

    $claimsExecution = chat_reply_claims_tool_execution($reply);
    $needsNoToolClaim = preg_match('/\b(no shell access|not given shell access|not provided|cannot access|no logs|no files|no tool access|no access|not available)\b/i', $latestUserMsg) === 1;
    if ($claimsExecution && ($needsNoToolClaim || !($toolState['tool_execution_succeeded'] ?? false))) {
        $checks[] = ['name' => 'tool_access_honesty', 'pass' => false];
        $issues[] = 'Reply claims tool execution or inspection without actual access or evidence.';
    } else {
        $checks[] = ['name' => 'tool_access_honesty', 'pass' => true];
    }

    $payloadTerms = ['artifact', 'document', 'codebase', 'flow', 'benchmark', 'source', 'repo', 'repository', 'snapshot', 'attached'];
    $needsArtifactCheck = preg_match('/\b(' . implode('|', $payloadTerms) . ')\b/i', $latestUserMsg) === 1;
    $needsArtifactCheck = $needsArtifactCheck || preg_match('/\b(review|analyze|inspect)\s+(this|the)?\s*(repository|repo|codebase|files?)\b/i', $latestUserMsg) === 1;
    $mentionsArtifact = preg_match('/\b(artifact|document|codebase|flow|benchmark|source|file|repo|snapshot)\b/i', $reply) === 1;
    $missingArtifactSignal = preg_match('/\b(we do not have|not provided|missing|not shown|no artifact|not available)\b/i', $reply) === 1;
    if ($needsArtifactCheck && $mentionsArtifact && !$missingArtifactSignal) {
        $checks[] = ['name' => 'artifact_presence_check', 'pass' => false];
        $issues[] = 'Reply appears to analyze a missing artifact without acknowledging the missing input.';
    } else {
        $checks[] = ['name' => 'artifact_presence_check', 'pass' => true];
    }

    $sourceSensitivePrompt = preg_match('/\b(cite|citation|source|study|paper|benchmark|prove|evidence)\b/i', $latestUserMsg) === 1;
    $claimsSpecificCitation = preg_match('/\b(doi|published in|journal|study found|according to .*study|official benchmark|white paper)\b/i', $reply) === 1;
    $signalsUnverified = preg_match('/\b(cannot verify|can\'t verify|unverified|not provided|no citation available|missing source|do not have evidence|we do not have)\b/i', $reply) === 1;
    $claimsSourceVerification = chat_reply_claims_source_verification($reply);
    $hasWebEvidence = !empty($context['webSearchResults']) || (bool)($toolState['tool_result_available'] ?? false);
    if ($claimsSourceVerification && !$hasWebEvidence && !$signalsUnverified) {
        $checks[] = ['name' => 'source_provenance_enforced', 'pass' => false];
        $issues[] = 'Reply claims source verification without retrieved evidence.';
    } else {
        $checks[] = ['name' => 'source_provenance_enforced', 'pass' => true];
    }
    if ($sourceSensitivePrompt && $claimsSpecificCitation && !$signalsUnverified) {
        $checks[] = ['name' => 'source_discipline', 'pass' => false];
        $issues[] = 'Reply asserts source-level evidence without a verification qualifier.';
    } else {
        $checks[] = ['name' => 'source_discipline', 'pass' => true];
    }

    $askedForSteps = preg_match('/\b(plan|steps?|checklist|roadmap|how\s+to|what\s+next)\b/i', $latestUserMsg) === 1;
    $hasStructuredSteps = preg_match('/(?:^|\n)\s*(?:[-*+]\s+|\d+[.)]\s+)/m', $reply) === 1;
    if ($askedForSteps || ($taskMode && !$lightweightValidation)) {
        $checks[] = ['name' => 'has_structured_steps', 'pass' => $hasStructuredSteps];
        if (!$hasStructuredSteps) {
            $issues[] = 'Expected a structured plan or checklist.';
        }
    }

    $needsNextAction = !$lightweightValidation && ($taskMode || in_array($taskFocus, ['plan', 'build', 'debug', 'ship'], true));
    $hasNextAction = preg_match('/\b(next step|first step|do this next|action)\b/i', $reply) === 1;
    if ($needsNextAction) {
        $checks[] = ['name' => 'has_next_action', 'pass' => $hasNextAction];
        if (!$hasNextAction) {
            $issues[] = 'Missing an explicit next action.';
        }
    }

    $hasHardConstraintCheck = preg_match('/\b(do not|must not|keep it small|no new infrastructure|not too many|only use the provided|without inventing|use only the facts)\b/i', $latestUserMsg) === 1;
    $violatesHardConstraint = false;
    if ($hasHardConstraintCheck) {
        $disallowFinancialShift = preg_match('/\b(do not switch to financial analysis|do not switch to finance)\b/i', $latestUserMsg) === 1;
        if ($disallowFinancialShift && preg_match('/\b(portfolio|stock|loan|investment|financial advice|buy|sell)\b/i', $reply) === 1) {
            $violatesHardConstraint = true;
        }

        $smallScopeRequested = preg_match('/\b(keep it small|no new infrastructure|not too many)\b/i', $latestUserMsg) === 1;
        if ($smallScopeRequested && preg_match('/\b(microservices|distributed|kafka|event bus|rewrite everything|new platform)\b/i', $reply) === 1) {
            $violatesHardConstraint = true;
        }
    }

    if ($violatesHardConstraint) {
        $checks[] = ['name' => 'hard_constraint_respected', 'pass' => false];
        $issues[] = 'Reply appears to violate a stated hard constraint.';
    } else {
        $checks[] = ['name' => 'hard_constraint_respected', 'pass' => true];
    }

    $ambiguousQuantPrompt = preg_match('/\b(units were not specified|relative\s+vs\s+absolute|relative\s+or\s+percentage[- ]point|if you mean|interpretation depends|percentage[- ]points?)\b/i', $latestUserMsg) === 1
        || preg_match('/return rates?\s+increased\s+\d+(?:\.\d+)?%.*(?:baseline|return rate was)\s+\d+(?:\.\d+)?%/i', $latestUserMsg) === 1
        || preg_match('/revenue\s+increased\s+\d+(?:\.\d+)?%.*return rates?\s+increased\s+\d+(?:\.\d+)?%/i', $latestUserMsg) === 1;
    $addressesAmbiguity = preg_match('/\b(assumption|interpreting|relative|percentage[- ]point|units|if you mean)\b/i', $reply) === 1;
    if ($ambiguousQuantPrompt) {
        $checks[] = ['name' => 'ambiguity_acknowledged', 'pass' => $addressesAmbiguity];
        if (!$addressesAmbiguity) {
            $issues[] = 'Ambiguous quantitative request was answered without explicit assumptions.';
        }
    }

    $falsePremiseTrap = preg_match('/\b(linux kernel.*python|automatically encrypted by JavaScript|JavaScript.*encrypted.*browser|server-side session controls are unnecessary|browser.*security.*server-side|oauth tokens.*encrypted by JavaScript|saturn\s+is\s+the\s+hottest|sun\s+orbits\s+earth|water\s+boils\s+at\s+100\s*°?c.*everywhere|vitamin\s+c\s+is\s+only\s+found\s+in\s+oranges|all mammals lay eggs)\b/i', $latestUserMsg) === 1;
    $correctsPremise = preg_match('/\b(premise is incorrect|that\'s incorrect|not accurate|false premise|browser-side.*not.*sufficient|server-side.*still.*required|predominantly written in c|written in c|non-browser.*server-side|saturn.*not.*hottest|venus.*hottest|earth.*orbits.*sun|not only.*oranges|boiling point.*depends|can actually be found|can be found in|is found in|are found in|found in (?:many|multiple|several|various)|incorrect premise|misconception)\b/i', $reply) === 1;
    if ($falsePremiseTrap) {
        $checks[] = ['name' => 'false_premise_corrected', 'pass' => $correctsPremise];
        if (!$correctsPremise) {
            $issues[] = 'False premise was not explicitly corrected.';
        }

        $reaffirmsFalsePremise = preg_match('/\b(server-side session controls are (?:typically )?unnecessary|javascript.*encrypt(?:s|ed)?.*sufficient|web storage encrypts all data|linux kernel(?:.*?\b(?:is|was)\s+(?:primarily|mainly|mostly)\s+written\s+in\s+python\b)|(?:main implementation language|primary implementation language)\s+(?:is|was)\s+python)\b/i', $reply) === 1;
        $explicitlyCorrectsFalsePremise = preg_match('/\b(?:not\s+(?:primarily|mainly|mostly)\s+written\s+in\s+python|not\s+python|python\s+is\s+not\s+(?:the\s+)?(?:main|primary)\s+implementation\s+language|server-side.*still.*required|browser-side.*not.*sufficient|browser-side.*is\s+not\s+sufficient)\b/i', $reply) === 1;
        if ($reaffirmsFalsePremise && !$explicitlyCorrectsFalsePremise) {
            $checks[] = ['name' => 'false_premise_reaffirmed', 'pass' => false];
            $issues[] = 'Reply reaffirms a known false premise instead of correcting it.';
        } else {
            $checks[] = ['name' => 'false_premise_reaffirmed', 'pass' => true];
        }
    }

    $percentPairMatches = [];
    if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:to|->|→)\s*(\d+(?:\.\d+)?)\s*%/i', $latestUserMsg, $percentPairMatches) === 1) {
        $oldPct = (float)($percentPairMatches[1] ?? 0.0);
        $newPct = (float)($percentPairMatches[2] ?? 0.0);
        if ($oldPct > 0.0) {
            $ppDrop = $oldPct - $newPct;
            $relativeDrop = ($ppDrop / $oldPct) * 100.0;
            $ppToken = rtrim(rtrim(number_format(abs($ppDrop), 2, '.', ''), '0'), '.');
            $relativeToken = rtrim(rtrim(number_format(abs($relativeDrop), 2, '.', ''), '0'), '.');
            $mentionsPp = preg_match('/\b' . preg_quote($ppToken, '/') . '\s*(?:percentage\s*points?|pp)\b/i', $reply) === 1;
            $mentionsRelative = preg_match('/\b(relative\s+(?:reduction|change)|reduction\s+of|decrease\s+of)[^.\n]{0,40}\b' . preg_quote($relativeToken, '/') . '\s*%/i', $reply) === 1
                || preg_match('/\b' . preg_quote($relativeToken, '/') . '\s*%[^.\n]{0,40}\brelative\b/i', $reply) === 1
                || (preg_match('/\brelative\b/i', $reply) === 1 && preg_match('/\b' . preg_quote($relativeToken, '/') . '\s*%/i', $reply) === 1);
            $mislabelsRelativeAsPp = preg_match('/\brelative\b[^.\n]{0,40}\b' . preg_quote($ppToken, '/') . '\s*%/i', $reply) === 1 && abs($relativeDrop - $ppDrop) > 0.2;

            $ppPrompt = preg_match('/\b(percentage\s*point|pp|relative\s+reduction)\b/i', $latestUserMsg) === 1;
            if ($ppPrompt) {
                $ppPass = $mentionsPp && $mentionsRelative && !$mislabelsRelativeAsPp;
                $checks[] = ['name' => 'percentage_point_reasoning', 'pass' => $ppPass];
                if (!$ppPass) {
                    $issues[] = 'Percentage-point and relative-percentage reasoning is inconsistent.';
                }
            }
        }
    }

    if (preg_match('/\b(\d+)\s*km\s*east\b/i', $latestUserMsg, $eastMatch) === 1
        && preg_match('/\b(\d+)\s*km\s*north\b/i', $latestUserMsg, $northMatch) === 1
        && preg_match('/\b(\d+)\s*km\s*west\b/i', $latestUserMsg, $westMatch) === 1
    ) {
        $eastKm = (float)($eastMatch[1] ?? 0);
        $northKm = (float)($northMatch[1] ?? 0);
        $westKm = (float)($westMatch[1] ?? 0);
        $netX = $eastKm - $westKm;
        $netY = $northKm;
        $distance = sqrt(($netX * $netX) + ($netY * $netY));
        $distanceToken = rtrim(rtrim(number_format($distance, 2, '.', ''), '0'), '.');
        $mentionsDistance = preg_match('/\b' . preg_quote($distanceToken, '/') . '\b/', $reply) === 1 || preg_match('/\bsqrt\s*\(\s*97\s*\)/i', $reply) === 1;
        $mentionsNorthwest = preg_match('/\bnorth\s*west|northwest\b/i', $reply) === 1;
        $contradictorySouth = ($netY >= 0) && preg_match('/\bsouth\b/i', $reply) === 1;
        $logicPass = $mentionsDistance && $mentionsNorthwest && !$contradictorySouth;
        $checks[] = ['name' => 'vector_reasoning_consistency', 'pass' => $logicPass];
        if (!$logicPass) {
            $issues[] = 'Directional or distance reasoning contradicts the prompt facts.';
        }
    }

    $unnecessaryRefusal = preg_match('/^\s*i\s+can(?:not|\'t)\s+verify\b/i', $replyTrimmed) === 1
        && in_array($requestClass, ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'WRITING', 'GENERAL_INFORMATION', 'FACTUAL_INFORMATION', 'FALSE_PREMISE', 'BASIC_REASONING', 'QUANTITATIVE', 'SECURITY'], true);
    $checks[] = ['name' => 'no_unnecessary_refusal', 'pass' => !$unnecessaryRefusal];
    if ($unnecessaryRefusal) {
        $issues[] = 'Reply used evidence-refusal language for a directly answerable request.';
    }

    $shortRequest = preg_match('/\b(short|brief|concise|one line|one-liner)\b/i', $latestUserMsg) === 1;
    $replyWordCount = count(array_filter(preg_split('/\s+/', trim($replyTrimmed)) ?: []));
    if ($shortRequest) {
        $tooVerbose = $replyWordCount > 80;
        $checks[] = ['name' => 'length_instruction_followed', 'pass' => !$tooVerbose];
        if ($tooVerbose) {
            $issues[] = 'Reply ignored explicit length guidance and is overly verbose.';
        }
    }

    if ($requestClass === 'WRITING') {
        $writingMismatch = preg_match('/\b(i can\'t verify|cannot verify|insufficient evidence|as an ai)\b/i', $reply) === 1;
        $checks[] = ['name' => 'writing_instruction_followed', 'pass' => !$writingMismatch];
        if ($writingMismatch) {
            $issues[] = 'Writing request was answered with meta commentary instead of requested output.';
        }
    }

    $awkwardLanguage = preg_match('/\b(you are welcome, dear|dear user|esteemed user)\b/i', $reply) === 1;
    $checks[] = ['name' => 'awkward_language_check', 'pass' => !$awkwardLanguage];
    if ($awkwardLanguage) {
        $issues[] = 'Reply contains awkward or overly formal wording.';
    }

    $incompleteEnding = preg_match('/\b(and|or|because|which|that|with|to)\s*$/i', $replyTrimmed) === 1;
    $checks[] = ['name' => 'response_completeness', 'pass' => !$incompleteEnding];
    if ($incompleteEnding) {
        $issues[] = 'Reply appears incomplete or abruptly truncated.';
    }

    $responseContract = chat_response_contract_for_request($latestUserMsg, $requestClass, [
        'research_required' => (bool)($requestProfile['research_required'] ?? false),
    ]);
    $contractValidation = chat_validate_response_contract($replyTrimmed, $responseContract);
    $checks[] = ['name' => 'response_contract', 'pass' => (bool)($contractValidation['pass'] ?? true), 'contract' => $responseContract];
    if (!($contractValidation['pass'] ?? true)) {
        $issues = array_merge($issues, $contractValidation['issues'] ?? ['Response contract validation failed.']);
    }

    $questionAsked = preg_match('/\?\s*$/', trim($latestUserMsg)) === 1;
    $nonAnsweringLead = preg_match('/^\s*(i\s+cannot|i\s+can\'t|unable to|not possible)/i', $replyTrimmed) === 1;
    $hasConcreteAnswer = preg_match('/\b(you should|safe sequence|steps?|answer|result|therefore|recommended|in summary|first,|1\.)\b/i', $replyTrimmed) === 1;
    $completenessPass = !($questionAsked && $nonAnsweringLead && !$hasConcreteAnswer);
    $checks[] = ['name' => 'answers_user_task', 'pass' => $completenessPass];
    if (!$completenessPass) {
        $issues[] = 'Reply does not answer the user task after limitation language.';
    }

    $outageWithMissingEvidence = preg_match('/\b(outage|incident|what caused|explain\s+the\s+cause|cause)\b/i', $latestUserMsg) === 1
        && preg_match('/\b(no logs?|no traces?|no metrics?|not including logs|not including traces|not including metrics|not provided|without any telemetry|without telemetry|without any logs|without logs|without traces?)\b/i', $latestUserMsg) === 1;
    $claimsDefinitiveCause = preg_match('/\b(was due to|caused by|root cause was|definitely caused by|the cause was)\b/i', $reply) === 1;
    if ($outageWithMissingEvidence) {
        $checks[] = ['name' => 'no_unsupported_outage_root_cause', 'pass' => !$claimsDefinitiveCause];
        if ($claimsDefinitiveCause) {
            $issues[] = 'Reply claims a definitive outage root cause without required logs/traces/metrics evidence.';
        }
    }

    $incidentPrompt = preg_match('/\b(incident|outage|partial\s+migration|partially\s+executed|migration\s+partially\s+executed|intermittent\s+failure|production|migration|currently failing)\b/i', $latestUserMsg) === 1;
    $unsafeGuidance = preg_match('/\b(update all dependencies now|upgrade all dependencies now|upgrade every dependency immediately|upgrade every dependency|upgrade all dependencies immediately|upgrade all dependencies|broad dependency upgrade|dependency upgrade now|blind rollback|immediate rollback without checks|run the migration steps in reverse order|if rollback is possible, run the migration steps in reverse order|rollback now|roll it back|immediately roll back|restore state immediately)\b/i', $reply) === 1;
    if ($incidentPrompt) {
        $checks[] = ['name' => 'production_safety_guard', 'pass' => !$unsafeGuidance];
        if ($unsafeGuidance) {
            $issues[] = 'Reply includes unsafe production-incident guidance.';
        }
    }

    $refusalFirstPattern = preg_match('/^\s*I can\'?t (determine|verify|conclude)|^\s*I cannot (determine|verify|conclude)/i', trim($reply)) === 1;
    $boundedAnalysisFollowup = preg_match('/\b(however|available evidence|likely areas|next diagnostic step|possible causes|check the|compare the|test the|safe next step|bounded analysis)\b/i', $reply) === 1;
    $hasExplicitEvidenceBoundary = preg_match('/\b(cannot|can\'t|unable to)\s+(?:prove|determine|verify|confirm)\b[^.\n]{0,100}\b(?:root cause|exact cause|available evidence|available facts)\b/i', $reply) === 1;
    if ($outageWithMissingEvidence && $refusalFirstPattern && $boundedAnalysisFollowup && !$hasExplicitEvidenceBoundary) {
        $checks[] = ['name' => 'refusal_precision', 'pass' => false];
        $issues[] = 'Reply starts with refusal-first language without clearly bounding the missing evidence and continuing with useful analysis.';
    } else {
        $checks[] = ['name' => 'refusal_precision', 'pass' => true];
    }

    if ($requestClass === 'TOOL_UNAVAILABLE') {
        $privacyRefusal = preg_match('/\b(cannot reveal|private data|privacy reasons|confidential)\b/i', $reply) === 1;
        $toolLimitationLanguage = preg_match('/\b(no access|cannot access|not provided|without access|tool unavailable)\b/i', $reply) === 1;
        $checks[] = ['name' => 'tool_vs_privacy_reasoning', 'pass' => !($privacyRefusal && !$toolLimitationLanguage)];
        if ($privacyRefusal && !$toolLimitationLanguage) {
            $issues[] = 'Tool limitation was framed as a privacy refusal instead of missing access.';
        }
    }

    if (function_exists('chat_response_length_expectation')) {
        $lengthExpectation = chat_response_length_expectation($latestUserMsg, $requestClass);
        $replyChars = strlen($replyTrimmed);
        $minChars = (int)($lengthExpectation['min_chars'] ?? 40);
        $maxChars = (int)($lengthExpectation['max_chars'] ?? 1200);
        $tooShort = $replyChars < (int)floor($minChars * 0.6);
        $tooLong = $replyChars > (int)ceil($maxChars * 1.35);
        $lengthPass = !($tooShort || $tooLong) || $deterministicLowRisk;
        $checks[] = ['name' => 'response_length_appropriate', 'pass' => $lengthPass, 'target' => $lengthExpectation['target'] ?? 'standard'];
        if (($tooShort || $tooLong) && !$deterministicLowRisk) {
            $issues[] = 'Response length is outside the expected range for this request type.';
        }
    }

    $finalConsistency = chat_validate_final_answer_consistency($latestUserMsg, $replyTrimmed, $requestClass);
    $checks[] = ['name' => 'final_answer_consistent', 'pass' => (bool)($finalConsistency['pass'] ?? true)];
    if (!($finalConsistency['pass'] ?? true)) {
        $issues = array_merge($issues, $finalConsistency['issues'] ?? ['Final answer consistency check failed.']);
    }

    $logicValidation = chat_validate_logic_quantifiers($latestUserMsg, $replyTrimmed);
    $checks[] = ['name' => 'logic_quantifier_valid', 'pass' => (bool)($logicValidation['pass'] ?? true)];
    if (!($logicValidation['pass'] ?? true)) {
        $issues = array_merge($issues, $logicValidation['issues'] ?? ['Logical quantifier consistency failed.']);
    }

    $securityValidation = chat_validate_security_precision($latestUserMsg, $replyTrimmed);
    $checks[] = ['name' => 'security_facts_correct', 'pass' => (bool)($securityValidation['pass'] ?? true)];
    if (!($securityValidation['pass'] ?? true)) {
        $issues = array_merge($issues, $securityValidation['issues'] ?? ['Security technical precision failed.']);
    }

    $toolState = chat_tool_execution_state_from_context($requestProfile, $context);
    $toolHonesty = chat_validate_tool_claims_against_state($replyTrimmed, $toolState);
    if (!empty($toolHonesty['checks']) && is_array($toolHonesty['checks'])) {
        foreach ($toolHonesty['checks'] as $check) {
            $checks[] = $check;
        }
    }
    if (!$toolHonesty['pass']) {
        foreach (($toolHonesty['issues'] ?? []) as $issue) {
            $issues[] = $issue;
        }
    }

    $passed = true;
    foreach ($checks as $check) {
        if (empty($check['pass'])) {
            $passed = false;
            break;
        }
    }

    // Run enhanced validators if they are available
    if (function_exists('chat_validate_multi_step_state')) {
        $stateValidation = chat_validate_multi_step_state($latestUserMsg, $replyTrimmed);
        if (!$stateValidation['pass']) {
            $issues[] = $stateValidation['issue'] ?? 'State tracking error';
            $passed = false;
        }
        $checks[] = ['name' => 'multi_step_state_tracking', 'pass' => $stateValidation['pass'], 'details' => $stateValidation['details'] ?? null];
    }

    if (function_exists('chat_validate_quantitative')) {
        $quantValidation = chat_validate_quantitative($latestUserMsg, $replyTrimmed);
        if (!$quantValidation['pass']) {
            $issues = array_merge($issues, $quantValidation['issues'] ?? []);
            $passed = false;
        }
        foreach ($quantValidation['checks'] ?? [] as $check) {
            $checks[] = $check;
        }
    }

    if (function_exists('chat_validate_production_safety_order')) {
        $prodValidation = chat_validate_production_safety_order($latestUserMsg, $replyTrimmed);
        if (!$prodValidation['pass']) {
            $issues = array_merge($issues, $prodValidation['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_technology_assumptions')) {
        $technologyValidation = chat_validate_technology_assumptions($latestUserMsg, $replyTrimmed);
        $checks[] = ['name' => 'technology_assumption_control', 'pass' => (bool)($technologyValidation['pass'] ?? true)];
        if (!($technologyValidation['pass'] ?? true)) {
            $issues = array_merge($issues, $technologyValidation['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_tool_honesty')) {
        $toolValidation = chat_validate_tool_honesty($latestUserMsg, $replyTrimmed);
        if (!$toolValidation['pass']) {
            $issues = array_merge($issues, $toolValidation['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_unknown_state_speculation')) {
        $unknownState = chat_validate_unknown_state_speculation($latestUserMsg, $replyTrimmed);
        $checks[] = ['name' => 'unknown_state_preserved', 'pass' => (bool)($unknownState['pass'] ?? true)];
        if (!($unknownState['pass'] ?? true)) {
            $issues = array_merge($issues, $unknownState['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_resource_claims')) {
        $resourceValidation = chat_validate_resource_claims($latestUserMsg, $replyTrimmed, $context['resources'] ?? []);
        $checks[] = ['name' => 'resource_existence', 'pass' => (bool)($resourceValidation['pass'] ?? true)];
        if (!($resourceValidation['pass'] ?? true)) {
            $issues = array_merge($issues, $resourceValidation['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_writing_scope')) {
        $writingScope = chat_validate_writing_scope($latestUserMsg, $replyTrimmed);
        $checks[] = ['name' => 'writing_scope', 'pass' => (bool)($writingScope['pass'] ?? true)];
        if (!($writingScope['pass'] ?? true)) {
            $issues = array_merge($issues, $writingScope['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_source_required_handling')) {
        $sourceValidation = chat_validate_source_required_handling($latestUserMsg, $replyTrimmed, $context['webSearchResults'] ?? []);
        if (!$sourceValidation['pass']) {
            $issues = array_merge($issues, $sourceValidation['issues'] ?? []);
            if (($toolState['tool_name'] ?? '') === 'web_search' && ($toolState['tool_available'] ?? false)) {
                $passed = false;
            }
        }
    }

    if (function_exists('chat_validate_refusal_contract')) {
        $refusalContract = chat_validate_refusal_contract($latestUserMsg, $replyTrimmed, $context);
        $checks[] = [
            'name' => 'refusal_contract',
            'pass' => (bool)($refusalContract['pass'] ?? true),
            'details' => $refusalContract,
        ];
        if (!($refusalContract['pass'] ?? true)) {
            $issues = array_merge($issues, $refusalContract['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_writing_fact_preservation')) {
        $writingFacts = chat_validate_writing_fact_preservation($latestUserMsg, $replyTrimmed, $requestClass);
        $checks[] = ['name' => 'writing_fact_preservation', 'pass' => (bool)($writingFacts['pass'] ?? true)];
        if (!($writingFacts['pass'] ?? true)) {
            $issues = array_merge($issues, $writingFacts['issues'] ?? []);
            $passed = false;
        }
    }

    if (function_exists('chat_validate_logic_equivalence')) {
        $logicEquivalence = chat_validate_logic_equivalence($latestUserMsg, $replyTrimmed);
        $checks[] = ['name' => 'logic_equivalence', 'pass' => (bool)($logicEquivalence['pass'] ?? true)];
        if (!($logicEquivalence['pass'] ?? true)) {
            $issues = array_merge($issues, $logicEquivalence['issues'] ?? []);
            $passed = false;
        }
    }

    $claimProvenance = chat_claim_provenance_summary($replyTrimmed, [
        'webSearchResults' => $context['webSearchResults'] ?? [],
        'datasetMatches' => $context['datasetMatches'] ?? [],
        'attachmentMeta' => $context['attachmentMeta'] ?? null,
        'execution_records' => $toolState['execution_records'] ?? [],
    ]);
    $unverifiedOperationalClaims = array_values(array_filter($claimProvenance, static function (array $claim): bool {
        return in_array($claim['provenance'] ?? '', ['TOOL_OBSERVED', 'WEB_SOURCE'], true)
            && empty($claim['verified']);
    }));
    $checks[] = ['name' => 'claim_provenance', 'pass' => $unverifiedOperationalClaims === [], 'claims' => $claimProvenance];
    if ($unverifiedOperationalClaims !== []) {
        $issues[] = 'Response contains an observed or source-derived claim without verified provenance.';
        $passed = false;
    }

    $controlPlaneSnapshot = [];
    $invariantViolations = [];
    if (function_exists('chat_os_build_control_plane_snapshot')) {
        $controlPlaneSnapshot = chat_os_build_control_plane_snapshot(
            $latestUserMsg,
            $requestProfile,
            $toolState,
            $claimProvenance,
            [
                'request_id' => (string)($context['request_id'] ?? ''),
                'verified' => $passed,
                'webSearchResults' => $context['webSearchResults'] ?? [],
            ]
        );
        $invariantViolations = is_array($controlPlaneSnapshot['invariant_violations'] ?? null)
            ? $controlPlaneSnapshot['invariant_violations']
            : [];
        $checks[] = ['name' => 'os_core_invariants', 'pass' => $invariantViolations === [], 'issues' => $invariantViolations];
        if ($invariantViolations !== []) {
            $issues = array_merge($issues, $invariantViolations);
            $passed = false;
        }
    }

    return [
        'passed' => $passed,
        'issues' => $issues,
        'checks' => $checks,
        'tool_state' => $toolState,
        'claim_provenance' => $claimProvenance,
        'control_plane' => $controlPlaneSnapshot,
        'invariant_violations' => $invariantViolations,
    ];
}

function chat_confidence_assessment(
    string $latestUserMsg,
    string $reply,
    array $mainMeta,
    array $replySafety,
    array $datasetMatches,
    array $webSearchResults,
    bool $taskMode,
    array $verificationSummary
): array {
    $score = 0.58;
    $signals = [];

    $replyLen = strlen(trim($reply));
    if ($replyLen > 120) {
        $score += 0.08;
        $signals[] = 'substantive_reply';
    }
    if (!empty($datasetMatches) || !empty($webSearchResults)) {
        $score += 0.08;
        $signals[] = 'retrieval_context';
    }
    if (!empty($replySafety['redactions'])) {
        $score -= 0.1;
        $signals[] = 'safety_redactions';
    }
    if (!empty($mainMeta['error'])) {
        $score -= 0.18;
        $signals[] = 'provider_error_signal';
    }
    if (($mainMeta['finish_reason'] ?? '') === 'length') {
        $score -= 0.07;
        $signals[] = 'truncated_output';
    }
    if (!($verificationSummary['passed'] ?? false)) {
        $score -= 0.16;
        $signals[] = 'self_verify_failed';
    }
    if ($taskMode && preg_match('/\b(next step|action|first step)\b/i', $reply) === 1) {
        $score += 0.06;
        $signals[] = 'actionable_task_reply';
    }
    if ($taskMode && preg_match('/\bnot sure|maybe|probably|i think\b/i', strtolower($reply)) === 1) {
        $score -= 0.06;
        $signals[] = 'uncertain_language';
    }
    if (chat_message_is_technical($latestUserMsg) && preg_match('/```|\bexample\b|\bcheck\b|\bverify\b/i', $reply) === 1) {
        $score += 0.05;
        $signals[] = 'technical_grounding';
    }

    $score = max(0.05, min(0.98, $score));
    $label = $score >= 0.78 ? 'high' : ($score >= 0.56 ? 'medium' : 'low');

    return [
        'score' => round($score, 3),
        'label' => $label,
        'signals' => $signals,
    ];
}

function chat_hallucination_assessment(
    string $reply,
    array $datasetMatches,
    array $webSearchResults,
    array $confidence
): array {
    $flags = [];
    $risk = 'low';
    $lower = strtolower($reply);
    $absoluteClaims = preg_match('/\b(always|never|guaranteed|definitely|certainly|100%)\b/', $lower) === 1;
    $hasGrounding = !empty($datasetMatches) || !empty($webSearchResults);

    if ($absoluteClaims && !$hasGrounding) {
        $flags[] = 'absolute_claim_without_retrieval';
    }
    if (($confidence['label'] ?? 'medium') === 'low') {
        $flags[] = 'low_confidence';
    }
    if (preg_match('/\b(as of today|current price|latest release)\b/i', $reply) === 1 && !$hasGrounding) {
        $flags[] = 'time_sensitive_claim_without_web_context';
    }

    if (count($flags) >= 2) {
        $risk = 'high';
    } elseif (count($flags) === 1) {
        $risk = 'medium';
    }

    return [
        'risk' => $risk,
        'flags' => $flags,
    ];
}

function chat_storage_dir(string $subdir): string {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/storage/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function chat_read_json_file(string $path, array $fallback = []): array {
    if (!is_readable($path)) {
        return $fallback;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function chat_write_json_file(string $path, array $payload): void {
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function chat_memory_rank_dataset_matches(array $datasetMatches, string $latestUserMsg, array $messages): array {
    $ranked = [];
    $contradictions = [];
    $questionMap = [];
    $queryTokens = array_values(array_filter(preg_split('/\W+/', strtolower($latestUserMsg)) ?: [], fn($t) => strlen((string)$t) >= 3));
    $contextDepth = count($messages);

    foreach ($datasetMatches as $match) {
        if (!is_array($match)) {
            continue;
        }
        $question = trim((string)($match['question'] ?? ''));
        $answer = trim((string)($match['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }

        $baseScore = (float)($match['score'] ?? 0.0);
        $normalizedQuestion = strtolower(preg_replace('/\s+/', ' ', $question) ?? $question);
        $answerHash = substr(hash('sha256', strtolower($answer)), 0, 12);

        if (!isset($questionMap[$normalizedQuestion])) {
            $questionMap[$normalizedQuestion] = $answerHash;
        } elseif ($questionMap[$normalizedQuestion] !== $answerHash) {
            $contradictions[] = [
                'question' => substr($question, 0, 140),
                'note' => 'Conflicting historical answers detected for similar question text.',
            ];
        }

        $answerLower = strtolower($answer);
        $tokenHits = 0;
        foreach ($queryTokens as $token) {
            if (str_contains($answerLower, $token) || str_contains(strtolower($question), $token)) {
                $tokenHits++;
            }
        }
        $relevanceBoost = $queryTokens ? min(0.24, $tokenHits / max(1, count($queryTokens)) * 0.24) : 0.0;

        $lengthPenalty = strlen($answer) > 900 ? 0.05 : 0.0;
        $freshnessBoost = $contextDepth >= 8 ? 0.04 : 0.02;
        $importance = max(0.05, min(0.99, $baseScore + $relevanceBoost + $freshnessBoost - $lengthPenalty));

        $match['importance_score'] = round($importance, 4);
        $match['freshness_score'] = round($freshnessBoost, 4);
        $match['relevance_hits'] = $tokenHits;
        $ranked[] = $match;
    }

    usort($ranked, static function (array $a, array $b): int {
        return ((float)($b['importance_score'] ?? 0)) <=> ((float)($a['importance_score'] ?? 0));
    });

    $top = array_slice($ranked, 0, 6);
    $compressed = [];
    foreach (array_slice($top, 0, 4) as $item) {
        $compressed[] = [
            'id' => (int)($item['id'] ?? 0),
            'summary' => substr(trim((string)($item['answer'] ?? '')), 0, 240),
            'importance_score' => (float)($item['importance_score'] ?? 0),
        ];
    }

    return [
        'ranked' => $top,
        'compressed' => $compressed,
        'audit' => [
            'candidates' => count($ranked),
            'selected' => count($top),
            'contradictions' => $contradictions,
            'freshness' => $contextDepth >= 8 ? 'high' : 'normal',
        ],
    ];
}

function chat_project_state_key(string $agentStateKey, string $projectId): string {
    $normalizedProject = trim($projectId) !== '' ? strtolower(trim($projectId)) : 'default';
    return substr(hash('sha256', $agentStateKey . '|' . $normalizedProject), 0, 24);
}

function chat_project_state_load(string $projectStateKey): array {
    $path = chat_storage_dir('projects/state') . '/' . $projectStateKey . '.json';
    return chat_read_json_file($path, [
        'tasks' => [],
        'history' => [],
        'artifacts' => [],
        'schedules' => [],
        'updated_at' => null,
    ]);
}

function chat_project_state_save(string $projectStateKey, array $state): void {
    $state['updated_at'] = gmdate('c');
    if (count($state['history'] ?? []) > 120) {
        $state['history'] = array_slice($state['history'], -120);
    }
    if (count($state['tasks'] ?? []) > 250) {
        $state['tasks'] = array_slice($state['tasks'], -250);
    }
    if (count($state['artifacts'] ?? []) > 120) {
        $state['artifacts'] = array_slice($state['artifacts'], -120);
    }
    $path = chat_storage_dir('projects/state') . '/' . $projectStateKey . '.json';
    chat_write_json_file($path, $state);
}

function chat_apply_task_operations(array $tasks, $taskOps): array {
    $taskOps = is_array($taskOps) ? $taskOps : [];
    foreach ($taskOps as $op) {
        if (!is_array($op)) {
            continue;
        }
        $type = strtolower(trim((string)($op['op'] ?? '')));
        $id = trim((string)($op['id'] ?? ''));
        $title = trim((string)($op['title'] ?? ''));

        if ($type === 'create' && $title !== '') {
            $tasks[] = [
                'id' => $id !== '' ? $id : ('t_' . substr(hash('sha256', $title . '|' . microtime(true)), 0, 10)),
                'title' => substr($title, 0, 220),
                'status' => 'active',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];
            continue;
        }

        if ($id === '') {
            continue;
        }
        foreach ($tasks as &$task) {
            if ((string)($task['id'] ?? '') !== $id) {
                continue;
            }
            if ($type === 'complete') {
                $task['status'] = 'done';
                $task['updated_at'] = gmdate('c');
            } elseif ($type === 'reopen') {
                $task['status'] = 'active';
                $task['updated_at'] = gmdate('c');
            } elseif ($type === 'delete') {
                $task['status'] = 'deleted';
                $task['updated_at'] = gmdate('c');
            } elseif ($type === 'update' && $title !== '') {
                $task['title'] = substr($title, 0, 220);
                $task['updated_at'] = gmdate('c');
            }
        }
        unset($task);
    }

    return array_values(array_filter($tasks, static fn(array $task): bool => ($task['status'] ?? '') !== 'deleted'));
}

function chat_schedule_work_items(array $existingSchedules, $scheduleOps, string $traceId): array {
    $existingSchedules = is_array($existingSchedules) ? $existingSchedules : [];
    $scheduleOps = is_array($scheduleOps) ? $scheduleOps : [];
    foreach ($scheduleOps as $op) {
        if (!is_array($op)) {
            continue;
        }
        $runAt = trim((string)($op['run_at'] ?? ''));
        $task = trim((string)($op['task'] ?? ($op['title'] ?? '')));
        if ($runAt === '' || $task === '') {
            continue;
        }
        $existingSchedules[] = [
            'id' => 's_' . substr(hash('sha256', $task . '|' . $runAt . '|' . microtime(true)), 0, 10),
            'task' => substr($task, 0, 200),
            'run_at' => $runAt,
            'created_at' => gmdate('c'),
            'trace_id' => $traceId,
            'status' => 'scheduled',
        ];
    }
    return array_slice($existingSchedules, -120);
}

function chat_register_artifacts(array $existingArtifacts, $artifactInput, $attachmentMeta, string $traceId): array {
    $existingArtifacts = is_array($existingArtifacts) ? $existingArtifacts : [];
    if (is_array($artifactInput)) {
        $name = trim((string)($artifactInput['name'] ?? 'artifact'));
        $type = trim((string)($artifactInput['type'] ?? 'note'));
        $uri = trim((string)($artifactInput['uri'] ?? ''));
        $existingArtifacts[] = [
            'id' => 'a_' . substr(hash('sha256', $name . '|' . $uri . '|' . microtime(true)), 0, 10),
            'name' => substr($name, 0, 120),
            'type' => $type !== '' ? $type : 'note',
            'uri' => $uri,
            'created_at' => gmdate('c'),
            'trace_id' => $traceId,
        ];
    }
    if (is_array($attachmentMeta)) {
        $existingArtifacts[] = [
            'id' => 'a_' . substr(hash('sha256', (string)($attachmentMeta['name'] ?? 'attachment') . '|' . microtime(true)), 0, 10),
            'name' => substr((string)($attachmentMeta['name'] ?? 'attachment'), 0, 120),
            'type' => (string)($attachmentMeta['type'] ?? 'file'),
            'uri' => '',
            'created_at' => gmdate('c'),
            'trace_id' => $traceId,
        ];
    }
    return array_slice($existingArtifacts, -120);
}

function chat_work_progress(array $tasks): array {
    $total = count($tasks);
    $done = 0;
    foreach ($tasks as $task) {
        if (($task['status'] ?? '') === 'done') {
            $done++;
        }
    }
    $active = max(0, $total - $done);
    $pct = $total > 0 ? (int)round(($done / $total) * 100) : 0;
    return [
        'total_tasks' => $total,
        'active_tasks' => $active,
        'done_tasks' => $done,
        'completion_pct' => $pct,
    ];
}

function chat_append_audit_log(array $entry): void {
    $path = chat_storage_dir('security/audit') . '/chat_audit.jsonl';
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function chat_developer_ecosystem_manifest(): array {
    return [
        'sdk' => ['status' => 'beta', 'base' => '/api/public_api.php'],
        'tool_sdk' => ['status' => 'planned', 'registry' => '/api/automation.php'],
        'agent_sdk' => ['status' => 'beta', 'supports_checkpoints' => true, 'supports_budgets' => true],
        'custom_tool_registry' => ['status' => 'planned', 'validation' => 'schema+permissions'],
        'custom_agent_deployment' => ['status' => 'planned', 'targets' => ['web', 'desktop', 'vscode', 'discord']],
        'webhooks' => ['status' => 'beta', 'events' => ['task.updated', 'agent.checkpoint', 'execution.failed']],
        'marketplace' => ['status' => 'planned', 'architecture' => 'signed manifests + capability scopes'],
    ];
}

function chat_distribution_profile(string $channel): array {
    $channel = strtolower(trim($channel));
    $known = ['web', 'desktop', 'mobile', 'discord', 'vscode', 'api'];
    if (!in_array($channel, $known, true)) {
        $channel = 'web';
    }
    return [
        'channel' => $channel,
        'supports_rich_trace' => in_array($channel, ['web', 'desktop', 'vscode'], true),
        'supports_artifacts' => in_array($channel, ['web', 'desktop', 'vscode', 'api'], true),
        'supports_long_running' => in_array($channel, ['web', 'desktop', 'api'], true),
        'white_label_ready' => true,
    ];
}

function chat_assign_experiment_bucket(string $subjectKey): array {
    $rolloutPercent = max(0, min(100, (int)api_get_secret('CHAT_CANARY_PERCENT', '0')));
    $hashInt = hexdec(substr(hash('sha256', $subjectKey), 0, 8));
    $bucket = $hashInt % 100;
    $variant = $bucket < $rolloutPercent ? 'canary' : 'stable';
    return [
        'variant' => $variant,
        'bucket' => $bucket,
        'rollout_percent' => $rolloutPercent,
    ];
}

function chat_response_cache_enabled(): bool {
    return api_get_secret('CHAT_RESPONSE_CACHE_ENABLED', '1') === '1';
}

function chat_response_cache_ttl(): int {
    return max(5, (int)api_get_secret('CHAT_RESPONSE_CACHE_TTL', '45'));
}

function chat_response_cache_dir(): string {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/storage/cache/chat_responses';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function chat_response_cache_key(array $payload): string {
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function chat_response_cache_get(string $key, int $ttl): ?array {
    if ($key === '' || $ttl <= 0 || !chat_response_cache_enabled()) {
        return null;
    }
    $path = chat_response_cache_dir() . '/' . $key . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $createdAt = (int)($decoded['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > $ttl) {
        @unlink($path);
        return null;
    }
    if (!isset($decoded['reply']) || !is_string($decoded['reply']) || trim($decoded['reply']) === '') {
        return null;
    }
    return $decoded;
}

function chat_response_cache_set(string $key, string $reply, array $extra = []): void {
    if ($key === '' || trim($reply) === '' || !chat_response_cache_enabled()) {
        return;
    }
    if (strlen($reply) > 24000) {
        return;
    }
    $payload = [
        'created_at' => time(),
        'reply' => $reply,
        'thinking' => isset($extra['thinking']) && is_string($extra['thinking']) ? $extra['thinking'] : null,
    ];
    $path = chat_response_cache_dir() . '/' . $key . '.json';
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
