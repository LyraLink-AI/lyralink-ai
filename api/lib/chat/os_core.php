<?php

if (!function_exists('chat_os_now_iso')) {
    function chat_os_now_iso(): string {
        return gmdate('c');
    }
}

if (!function_exists('chat_os_uuid')) {
    function chat_os_uuid(string $prefix = 'os'): string {
        try {
            $bytes = random_bytes(8);
            return $prefix . '_' . bin2hex($bytes);
        } catch (Throwable $e) {
            return $prefix . '_' . substr(hash('sha256', $prefix . '|' . microtime(true)), 0, 16);
        }
    }
}

if (!function_exists('chat_os_universal_states')) {
    function chat_os_universal_states(): array {
        return [
            'NOT_PROVIDED',
            'UNAVAILABLE',
            'UNKNOWN',
            'UNAUTHORIZED',
            'AVAILABLE',
            'REQUESTED',
            'QUEUED',
            'EXECUTING',
            'SUCCEEDED',
            'FAILED',
            'TIMEOUT',
            'CANCELLED',
            'VERIFIED',
            'UNVERIFIED',
        ];
    }
}

if (!function_exists('chat_os_execution_states')) {
    function chat_os_execution_states(): array {
        return [
            'NOT_REQUIRED',
            'REQUIRED',
            'AVAILABLE',
            'AUTHORIZED',
            'STARTED',
            'SUCCEEDED',
            'FAILED',
            'TIMED_OUT',
            'RESULT_AVAILABLE',
            'RESULT_VERIFIED',
            'NOT_AVAILABLE',
            'NOT_AUTHORIZED',
        ];
    }
}

if (!function_exists('chat_os_capability_registry')) {
    function chat_os_capability_registry(array $runtime = []): array {
        $base = [
            'web.search' => [
                'capability_id' => 'web.search',
                'name' => 'Web Search',
                'description' => 'Search the public web for fresh candidate sources.',
                'version' => '1.0',
                'provider' => 'duckduckgo+rss',
                'availability' => 'runtime',
                'required_resources' => ['internet_search'],
                'required_permissions' => ['network.read'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['query' => 'string'],
                'output_schema' => ['results' => 'array'],
                'requires_confirmation' => false,
                'execution_method' => 'chat_web_search_query_with_status',
                'verification_method' => 'result_count_and_retrieval_flags',
                'timeout_sec' => 10,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'none'],
                'metadata' => ['research' => true],
            ],
            'web.retrieve' => [
                'capability_id' => 'web.retrieve',
                'name' => 'Web Retrieve',
                'description' => 'Retrieve full page content for source inspection.',
                'version' => '1.0',
                'provider' => 'php_http_fetch',
                'availability' => 'runtime',
                'required_resources' => ['internet_fetch'],
                'required_permissions' => ['network.read'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['url' => 'string'],
                'output_schema' => ['content' => 'string'],
                'requires_confirmation' => false,
                'execution_method' => 'chat_web_fetch_html',
                'verification_method' => 'retrieved_content_hash',
                'timeout_sec' => 8,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'none'],
                'metadata' => ['research' => true],
            ],
            'web.verify_source' => [
                'capability_id' => 'web.verify_source',
                'name' => 'Web Source Verification',
                'description' => 'Verify that a source was actually retrieved and is suitable to cite.',
                'version' => '1.0',
                'provider' => 'verification_pipeline',
                'availability' => 'runtime',
                'required_resources' => ['internet_fetch'],
                'required_permissions' => ['network.read'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['source_candidates' => 'array'],
                'output_schema' => ['verified_sources' => 'array'],
                'requires_confirmation' => false,
                'execution_method' => 'chat_claim_provenance_summary',
                'verification_method' => 'source_content_hash_and_metadata',
                'timeout_sec' => 8,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'none'],
                'metadata' => ['research' => true, 'verification' => true],
            ],
            'database.query' => [
                'capability_id' => 'database.query',
                'name' => 'Database Query',
                'description' => 'Query a connected database resource.',
                'version' => '1.0',
                'provider' => 'mysqli',
                'availability' => 'conditional',
                'required_resources' => ['production_mysql'],
                'required_permissions' => ['database.read'],
                'enabled' => true,
                'risk_level' => 'HIGH',
                'input_schema' => ['query' => 'string'],
                'output_schema' => ['rows' => 'array'],
                'requires_confirmation' => true,
                'execution_method' => 'runtime_connection_required',
                'verification_method' => 'query_result_artifact',
                'timeout_sec' => 15,
                'retry_policy' => ['max_attempts' => 0, 'fallback' => 'none'],
                'metadata' => ['write_capable' => false],
            ],
            'shell.execute' => [
                'capability_id' => 'shell.execute',
                'name' => 'Shell Execute',
                'description' => 'Execute shell commands on a provisioned host.',
                'version' => '1.0',
                'provider' => 'not_available_in_chat_api',
                'availability' => 'disabled',
                'required_resources' => ['server_shell'],
                'required_permissions' => ['shell.execute'],
                'enabled' => false,
                'risk_level' => 'CRITICAL',
                'input_schema' => ['command' => 'string'],
                'output_schema' => ['stdout' => 'string', 'stderr' => 'string'],
                'requires_confirmation' => true,
                'execution_method' => 'unavailable',
                'verification_method' => 'execution_artifact_required',
                'timeout_sec' => 30,
                'retry_policy' => ['max_attempts' => 0, 'fallback' => 'none'],
                'metadata' => ['production_sensitive' => true],
            ],
            'filesystem.read' => [
                'capability_id' => 'filesystem.read',
                'name' => 'Filesystem Read',
                'description' => 'Read approved files from the workspace or runtime.',
                'version' => '1.0',
                'provider' => 'workspace_reader',
                'availability' => 'runtime',
                'required_resources' => ['workspace_filesystem'],
                'required_permissions' => ['filesystem.read'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['path' => 'string'],
                'output_schema' => ['content' => 'string'],
                'requires_confirmation' => false,
                'execution_method' => 'chat_workspace_read_file',
                'verification_method' => 'file_hash',
                'timeout_sec' => 5,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'none'],
            ],
            'filesystem.write' => [
                'capability_id' => 'filesystem.write',
                'name' => 'Filesystem Write',
                'description' => 'Write approved files through controlled edit paths.',
                'version' => '1.0',
                'provider' => 'workspace_writer',
                'availability' => 'conditional',
                'required_resources' => ['workspace_filesystem'],
                'required_permissions' => ['filesystem.write'],
                'enabled' => false,
                'risk_level' => 'HIGH',
                'input_schema' => ['path' => 'string', 'content' => 'string'],
                'output_schema' => ['diff' => 'string'],
                'requires_confirmation' => true,
                'execution_method' => 'not_enabled_in_chat_runtime',
                'verification_method' => 'file_diff+syntax',
                'timeout_sec' => 20,
                'retry_policy' => ['max_attempts' => 0, 'fallback' => 'none'],
            ],
            'server.inspect' => [
                'capability_id' => 'server.inspect',
                'name' => 'Server Inspect',
                'description' => 'Inspect deployment or runtime server state.',
                'version' => '1.0',
                'provider' => 'not_available_in_chat_api',
                'availability' => 'disabled',
                'required_resources' => ['deployment_control_plane'],
                'required_permissions' => ['server.inspect'],
                'enabled' => false,
                'risk_level' => 'HIGH',
                'input_schema' => ['target' => 'string'],
                'output_schema' => ['status' => 'string'],
                'requires_confirmation' => true,
                'execution_method' => 'unavailable',
                'verification_method' => 'deployment_artifact',
                'timeout_sec' => 20,
                'retry_policy' => ['max_attempts' => 0, 'fallback' => 'none'],
                'metadata' => ['production_sensitive' => true],
            ],
            'model.fast' => [
                'capability_id' => 'model.fast',
                'name' => 'Fast Model Response',
                'description' => 'Low-latency response generation for simple requests.',
                'version' => '1.0',
                'provider' => 'llm_router',
                'availability' => 'runtime',
                'required_resources' => ['local_model_runtime'],
                'required_permissions' => ['model.generate'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['messages' => 'array'],
                'output_schema' => ['reply' => 'string'],
                'requires_confirmation' => false,
                'execution_method' => 'callLlm',
                'verification_method' => 'chat_self_verify_summary',
                'timeout_sec' => 18,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'model.reason'],
            ],
            'model.reason' => [
                'capability_id' => 'model.reason',
                'name' => 'Reasoning Model Response',
                'description' => 'Higher-depth reasoning path for multi-step analysis.',
                'version' => '1.0',
                'provider' => 'llm_router',
                'availability' => 'runtime',
                'required_resources' => ['local_model_runtime'],
                'required_permissions' => ['model.generate'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['messages' => 'array'],
                'output_schema' => ['reply' => 'string'],
                'requires_confirmation' => false,
                'execution_method' => 'callLlm',
                'verification_method' => 'chat_self_verify_summary',
                'timeout_sec' => 40,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'model.fast'],
            ],
            'model.code' => [
                'capability_id' => 'model.code',
                'name' => 'Code Model Response',
                'description' => 'Code-focused model path for build and debug requests.',
                'version' => '1.0',
                'provider' => 'llm_router',
                'availability' => 'runtime',
                'required_resources' => ['local_model_runtime'],
                'required_permissions' => ['model.generate'],
                'enabled' => true,
                'risk_level' => 'LOW',
                'input_schema' => ['messages' => 'array'],
                'output_schema' => ['reply' => 'string'],
                'requires_confirmation' => false,
                'execution_method' => 'callLlm',
                'verification_method' => 'chat_self_verify_summary',
                'timeout_sec' => 45,
                'retry_policy' => ['max_attempts' => 1, 'fallback' => 'model.reason'],
            ],
        ];

        foreach ($runtime as $capabilityId => $override) {
            if (!isset($base[$capabilityId]) || !is_array($override)) {
                continue;
            }
            $base[$capabilityId] = array_merge($base[$capabilityId], $override);
        }

        return $base;
    }
}

if (!function_exists('chat_os_resource_states')) {
    function chat_os_resource_states(): array {
        return ['REGISTERED', 'AVAILABLE', 'UNAVAILABLE', 'DISABLED', 'UNKNOWN'];
    }
}

if (!function_exists('chat_os_authorization_states')) {
    function chat_os_authorization_states(): array {
        return ['AUTHORIZED', 'UNAUTHORIZED', 'APPROVAL_REQUIRED', 'NOT_REQUIRED', 'NOT_PROVIDED', 'UNKNOWN'];
    }
}

if (!function_exists('chat_os_route_classes')) {
    function chat_os_route_classes(): array {
        return ['FAST', 'STANDARD', 'DEEP', 'RESEARCH', 'EXECUTION'];
    }
}

if (!function_exists('chat_os_primary_resource_for_capability')) {
    function chat_os_primary_resource_for_capability(string $capabilityId): string {
        return match ($capabilityId) {
            'web.search' => 'internet_search',
            'web.retrieve', 'web.verify_source' => 'internet_fetch',
            'database.query' => 'production_mysql',
            'shell.execute' => 'server_shell',
            'server.inspect' => 'deployment_control_plane',
            'filesystem.read', 'filesystem.write' => 'workspace_filesystem',
            'model.fast', 'model.reason', 'model.code' => 'local_model_runtime',
            default => 'general_runtime',
        };
    }
}

if (!function_exists('chat_os_resource_registry')) {
    function chat_os_resource_registry(array $runtime = []): array {
        $webSearchRequested = (bool)($runtime['web_search_requested'] ?? false);
        $webRuntimeAvailable = array_key_exists('web_runtime_available', $runtime)
            ? (bool)$runtime['web_runtime_available']
            : function_exists('chat_web_search_query_with_status');
        $workspaceAvailable = array_key_exists('workspace_available', $runtime)
            ? (bool)$runtime['workspace_available']
            : true;
        $modelAvailable = array_key_exists('model_runtime_available', $runtime)
            ? (bool)$runtime['model_runtime_available']
            : true;

        return [
            'internet_search' => [
                'resource_id' => 'internet_search',
                'name' => 'Internet Search',
                'state' => !$webRuntimeAvailable ? 'UNAVAILABLE' : ($webSearchRequested ? 'AVAILABLE' : 'DISABLED'),
                'kind' => 'network',
            ],
            'internet_fetch' => [
                'resource_id' => 'internet_fetch',
                'name' => 'Internet Retrieval',
                'state' => !$webRuntimeAvailable ? 'UNAVAILABLE' : ($webSearchRequested ? 'AVAILABLE' : 'DISABLED'),
                'kind' => 'network',
            ],
            'workspace_filesystem' => [
                'resource_id' => 'workspace_filesystem',
                'name' => 'Workspace Filesystem',
                'state' => $workspaceAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
                'kind' => 'filesystem',
            ],
            'local_model_runtime' => [
                'resource_id' => 'local_model_runtime',
                'name' => 'Model Runtime',
                'state' => $modelAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
                'kind' => 'model',
            ],
            'production_mysql' => [
                'resource_id' => 'production_mysql',
                'name' => 'Production MySQL',
                'state' => !empty($runtime['database_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'kind' => 'database',
            ],
            'server_shell' => [
                'resource_id' => 'server_shell',
                'name' => 'Server Shell',
                'state' => !empty($runtime['shell_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'kind' => 'shell',
            ],
            'deployment_control_plane' => [
                'resource_id' => 'deployment_control_plane',
                'name' => 'Deployment Control Plane',
                'state' => !empty($runtime['deployment_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'kind' => 'deployment',
            ],
            'general_runtime' => [
                'resource_id' => 'general_runtime',
                'name' => 'General Runtime',
                'state' => 'AVAILABLE',
                'kind' => 'runtime',
            ],
        ];
    }
}

if (!function_exists('chat_os_capability_access_state')) {
    function chat_os_capability_access_state(array $capability, array $ctx = []): array {
        $exists = !empty($capability['capability_id']);
        if (!$exists) {
            return ['state' => 'UNAVAILABLE', 'reason' => 'NO_CAPABILITY'];
        }

        $enabled = (bool)($capability['enabled'] ?? false);
        if (!$enabled) {
            return ['state' => 'UNAVAILABLE', 'reason' => 'DISABLED'];
        }

        $resourceAvailable = (bool)($ctx['resource_available'] ?? false);
        if (!$resourceAvailable) {
            return ['state' => 'UNAVAILABLE', 'reason' => 'NO_RESOURCE'];
        }

        $authorized = (bool)($ctx['authorized'] ?? false);
        if (!$authorized) {
            return ['state' => 'UNAUTHORIZED', 'reason' => 'NOT_AUTHORIZED'];
        }

        return ['state' => 'AVAILABLE', 'reason' => 'READY'];
    }
}

if (!function_exists('chat_os_build_task')) {
    function chat_os_build_task(string $requestId, array $requestProfile, array $context = []): array {
        $taskId = (string)($context['task_id'] ?? chat_os_uuid('task'));
        $now = chat_os_now_iso();
        $risk = strtoupper((string)($requestProfile['risk_level'] ?? 'LOW'));
        $status = (bool)($context['verified'] ?? false) ? 'VERIFIED' : 'UNVERIFIED';

        return [
            'task_id' => $taskId,
            'request_id' => $requestId,
            'user_id' => (string)($context['user_id'] ?? ''),
            'session_id' => (string)($context['session_id'] ?? ''),
            'parent_task_id' => (string)($context['parent_task_id'] ?? ''),
            'intent_id' => (string)($context['intent_id'] ?? ''),
            'intent' => (string)($context['intent'] ?? ($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')),
            'objective' => (string)($context['objective'] ?? ''),
            'context' => (array)($context['task_context'] ?? []),
            'priority' => (string)($context['priority'] ?? 'normal'),
            'status' => $status,
            'risk_level' => $risk,
            'created_at' => (string)($context['created_at'] ?? $now),
            'started_at' => (string)($context['started_at'] ?? $now),
            'completed_at' => (string)($context['completed_at'] ?? $now),
            'deadline' => $context['deadline'] ?? null,
            'required_capabilities' => (array)($context['required_capabilities'] ?? $context['requested_capabilities'] ?? []),
            'required_resources' => (array)($context['required_resources'] ?? []),
            'authorization_state' => (string)($context['authorization_state'] ?? 'UNKNOWN'),
            'plan_id' => (string)($context['plan_id'] ?? ''),
            'current_action_id' => (string)($context['current_action_id'] ?? ''),
            'result_id' => (string)($context['result_id'] ?? ''),
            'failure_type' => (string)($context['failure_type'] ?? ''),
            'error' => $context['error'] ?? null,
            'evidence_ids' => (array)($context['evidence_ids'] ?? []),
            'memory_write_state' => (string)($context['memory_write_state'] ?? 'NOT_REQUIRED'),
            'requested_capabilities' => (array)($context['requested_capabilities'] ?? []),
            'plan' => (array)($context['plan'] ?? []),
            'actions' => [],
            'result' => (array)($context['result'] ?? []),
            'failure' => $context['failure'] ?? null,
            'verification_status' => $status,
            'memory_references' => (array)($context['memory_references'] ?? []),
            'metadata' => (array)($context['metadata'] ?? []),
        ];
    }
}

if (!function_exists('chat_os_task_requirement_analysis')) {
    function chat_os_task_requirement_analysis(array $requestProfile, array $taskControl, string $latestUserMsg, array $context = []): array {
        $requestClass = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
        $message = strtolower(trim($latestUserMsg));
        $hasKeywords = trim($message) !== '';

        $isExplanation = preg_match('/\b(what is|explain|define|describe|difference between|how does|why does|what does)\b/i', $latestUserMsg) === 1;
        $isGuidance = preg_match('/\b(how should|best practice|safe sequence|safely|guidance|recommend(ed)? steps?|playbook|what should (we|i) do)\b/i', $latestUserMsg) === 1;
        $isInspect = preg_match('/\b(inspect|check|verify|validate|confirm|audit|review|look at|investigate)\b[^.\n]{0,80}\b(repo|repository|file|files|log|logs|database|db|server|system|deployment|production|config|workspace|directory|cluster|container|runtime|state|environment|shell|terminal|command)\b/i', $latestUserMsg) === 1
            || (preg_match('/\btell me what happened\b/i', $latestUserMsg) === 1 && preg_match('/\b(incident|outage|failure|error|production)\b/i', $latestUserMsg) === 1);
        $isExecute = preg_match('/\b(run|execute|deploy|restart|rollback|migrate|fix|patch|delete|write|change|update|apply)\b/i', $latestUserMsg) === 1;
        $explicitRuntimeStatusCheck = preg_match('/\b(?:is|are|whether|status|health|running|deployed|available|up|down|healthy|lagging|backing up|currently)\b/i', $latestUserMsg) === 1
            && preg_match('/\b(?:production|live server|service|deployment|runtime|cluster|database|server|repo|repository|workspace|logs?|config|system|environment)\b/i', $latestUserMsg) === 1;
        $advisoryOperationalQuestion = preg_match('/\b(?:should\s+we|what\s+should\s+(?:we|i)|safe\s+sequence|recommend|first\s+step)\b/i', $latestUserMsg) === 1
            && preg_match('/\b(?:incident|outage|migrat(?:e|ion)|rollback|deploy|production|live)\b/i', $latestUserMsg) === 1
            && !$isInspect
            && !$isExecute;

        $researchRequired = in_array($requestClass, ['RESEARCH', 'SOURCE_REQUIRED'], true)
            || !empty($context['needs_fresh_web'])
            || preg_match('/\b(cite|citation|source|scholarly|whitepaper|official|public source|latest|current|today|prove|verify)\b/i', $latestUserMsg) === 1;

        $executionRequired = !empty($taskControl['requires_execution'])
            || in_array($requestClass, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE', 'SYSTEM_ADMINISTRATION', 'PRODUCTION_OPERATIONS'], true);

        $externalStateRequired = preg_match('/\b(deploy|deployment|live server|service status|status page|health check|production|outage|rollback|restart|migration|currently|running|available|server|database|schema|table|repo|repository|workspace|file system|logs?|shell command)\b/i', $latestUserMsg) === 1;

        $operationalIntent = preg_match('/\b(run|execute|query|read|open|check|list|show|verify|deploy|restart|rollback|migrate|compare|analyze|fix|repair|debug)\b/i', $latestUserMsg) === 1
            || preg_match('/\b(inspect|investigate|review|audit)\b[^.\n]{0,80}\b(repo|repository|file|files|log|logs|database|db|server|system|deployment|production|config|workspace|directory|cluster|container|runtime|state|environment|shell|terminal|command)\b/i', $latestUserMsg) === 1;
        $conceptualIntent = $isExplanation || preg_match('/\b(how do i|what does)\b/i', $latestUserMsg) === 1;
        $guidanceIntent = $isGuidance;

        if (!$executionRequired && $externalStateRequired && ($operationalIntent || $explicitRuntimeStatusCheck) && !$advisoryOperationalQuestion) {
            $executionRequired = true;
        }

        if ($researchRequired) {
            $executionRequired = false;
        }

        // Guidance/explanation about operational topics must not be escalated to execution-only routing
        // unless the user explicitly asks for inspection/verification/execution on their environment.
        if (($isExplanation || $guidanceIntent || $advisoryOperationalQuestion) && !$isInspect && !$isExecute && !$researchRequired) {
            $executionRequired = false;
            $externalStateRequired = false;
        }

        if ($conceptualIntent && !$researchRequired && !$executionRequired && !$operationalIntent) {
            $executionRequired = false;
            $externalStateRequired = false;
        }

        $intent = 'INFORMATION';
        if ($isExecute) {
            $intent = 'EXECUTION';
        } elseif ($isInspect || $explicitRuntimeStatusCheck) {
            $intent = 'INSPECTION';
        } elseif ($researchRequired) {
            $intent = 'RESEARCH';
        } elseif ($guidanceIntent || $isExplanation) {
            $intent = 'EXPLANATION';
        } elseif ($requestClass === 'WRITING') {
            $intent = 'TRANSFORMATION';
        } elseif (in_array($requestClass, ['QUANTITATIVE', 'BASIC_REASONING'], true)) {
            $intent = 'REASONING';
        }

        $desiredOutcome = 'ANSWER';
        if ($intent === 'EXPLANATION' && $guidanceIntent) {
            $desiredOutcome = 'GUIDANCE';
        } elseif ($intent === 'EXPLANATION') {
            $desiredOutcome = 'KNOWLEDGE';
        } elseif ($intent === 'RESEARCH') {
            $desiredOutcome = 'VERIFIED_EXTERNAL_INFORMATION';
        } elseif ($intent === 'INSPECTION') {
            $desiredOutcome = 'VERIFIED_STATE';
        } elseif ($intent === 'EXECUTION') {
            $desiredOutcome = 'STATE_CHANGE';
        } elseif ($intent === 'TRANSFORMATION') {
            $desiredOutcome = 'REWRITE';
        }

        return [
            'intent' => $intent,
            'desired_outcome' => $desiredOutcome,
            'research_required' => $researchRequired,
            'execution_required' => $executionRequired,
            'external_state_required' => $externalStateRequired,
            'operational_intent' => $operationalIntent,
            'conceptual_intent' => $conceptualIntent,
            'guidance_intent' => $guidanceIntent,
            'has_content' => $hasKeywords,
        ];
    }
}

if (!function_exists('chat_os_route_classify')) {
    function chat_os_route_classify(array $requestProfile, array $taskControl, string $latestUserMsg, array $context = []): string {
        $requestClass = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
        $message = trim($latestUserMsg);
        $analysis = chat_os_task_requirement_analysis($requestProfile, $taskControl, $latestUserMsg, $context);

        if (in_array($requestClass, ['RESEARCH', 'SOURCE_REQUIRED'], true) || !empty($context['needs_fresh_web']) || !empty($analysis['research_required'])) {
            return 'RESEARCH';
        }
        if (!empty($analysis['execution_required'])) {
            return 'EXECUTION';
        }
        if (in_array($requestClass, ['WRITING', 'CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true) && strlen($message) <= 260) {
            return 'FAST';
        }
        if (($analysis['intent'] ?? '') === 'EXPLANATION' && ($analysis['desired_outcome'] ?? '') === 'GUIDANCE') {
            return 'STANDARD';
        }
        if (in_array($requestClass, ['QUANTITATIVE', 'BASIC_REASONING', 'SECURITY', 'FALSE_PREMISE'], true)) {
            return 'DEEP';
        }
        return strlen($message) <= 120 ? 'FAST' : 'STANDARD';
    }
}

if (!function_exists('chat_os_capability_for_request')) {
    function chat_os_capability_for_request(array $requestProfile, array $taskControl, string $latestUserMsg, string $routeClass): string {
        $requestClass = strtoupper(trim((string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')));
        $lower = strtolower(trim($latestUserMsg));
        $analysis = chat_os_task_requirement_analysis($requestProfile, $taskControl, $latestUserMsg, []);

        if ($routeClass === 'RESEARCH' || !empty($analysis['research_required'])) {
            return 'web.search';
        }

        $genuineOperation = !empty($analysis['execution_required']) || $routeClass === 'EXECUTION';
        $explicitProductionCheck = (
            preg_match('/\b(deploy|deployment|live server|service status|status page|health check)\b/i', $lower) === 1
            || preg_match('/\b(?:is|whether|status|health|running|deployed|available|up|down|healthy)\b[^.\n]{0,80}\b(?:deployed|running|healthy|available|up|down)\b/i', $lower) === 1
            || ($requestClass === 'PRODUCTION_OPERATIONS' && preg_match('/\b(deployed|production|incident|outage|rollback|restart|migration)\b/i', $lower) === 1)
        );
        $explicitInspectIntent = preg_match('/\b(?:inspect|check|verify|validate|confirm|audit|review|look at|investigate)\b[^.\n]{0,120}\b(?:repo|repository|file|files|log|logs|database|db|server|system|deployment|production|config|workspace|directory|cluster|container|runtime|state|environment|shell|terminal|command)\b/i', $lower) === 1;
        $explicitRuntimeStatusCheck = preg_match('/\b(?:is|are|whether|status|health|running|deployed|available|up|down|healthy|lagging|backing up|currently)\b/i', $lower) === 1
            && preg_match('/\b(?:production|live server|service|deployment|runtime|cluster|database|server|repo|repository|workspace|logs?|config|system|environment)\b/i', $lower) === 1;
        $advisoryOperationalQuestion = preg_match('/\b(?:should\s+we|what\s+should\s+(?:we|i)|safe\s+sequence|recommend|first\s+step)\b/i', $lower) === 1
            && preg_match('/\b(?:incident|outage|migrat(?:e|ion)|rollback|deploy|production|live)\b/i', $lower) === 1
            && !$explicitInspectIntent;

        if ($genuineOperation && $explicitProductionCheck && ($explicitInspectIntent || $explicitRuntimeStatusCheck) && !$advisoryOperationalQuestion) {
            return 'server.inspect';
        }
        // A mention of the word "database" is not a request to read a live
        // database. Asking to build, write or design software that merely
        // involves a database is a code task; classifying it as database.query
        // replaced the entire answer with a tool-unavailable notice. Require an
        // explicit read/query intent against a live system, and never classify a
        // build or authoring request this way.
        $buildOrAuthorIntent = preg_match('/\b(?:make|build|create|write|generate|implement|design|develop|scaffold|author|code|site|website|app|application|page|script|project|add|set\s*up)\b/i', $lower) === 1;
        $explicitDatabaseRead = preg_match('/\b(?:query|queries|select|fetch|retrieve|dump|count|count\s+rows|rows?|records?|indexes?|schema\s+of)\b[^.\n]{0,60}\b(?:database|db|table|schema|mysql|postgres|production)\b/i', $lower) === 1
            || preg_match('/\b(?:database|db|table|schema|mysql|postgres)\b[^.\n]{0,60}\b(?:query|queries|how\s+many\s+rows|row\s+count|size|slow|lagging|replica|index(?:es)?\s+missing)\b/i', $lower) === 1;
        if (
            $genuineOperation
            && !$buildOrAuthorIntent
            && $explicitDatabaseRead
            && preg_match('/\b(what is|explain|define|describe|difference between|how does|why does|how do i|what does)\b/i', $lower) !== 1
        ) {
            return 'database.query';
        }
        if ($genuineOperation && preg_match('/\b(shell|terminal|command|process|systemctl|nginx|apache|docker|kubernetes|ls\s|cat\s|grep\s|find\s|ps\s|whoami|curl\s)\b/i', $lower) === 1) {
            return 'shell.execute';
        }
        if ($genuineOperation && preg_match('/\b(file|repo|repository|codebase|source code|workspace|read the file|inspect the file)\b/i', $lower) === 1) {
            return 'filesystem.read';
        }
        if ($genuineOperation && ($routeClass === 'EXECUTION' || in_array($requestClass, ['SYSTEM_ADMINISTRATION', 'PRODUCTION_OPERATIONS'], true))) {
            return 'model.code';
        }
        return match ($routeClass) {
            'FAST' => 'model.fast',
            'DEEP' => 'model.reason',
            default => 'model.reason',
        };
    }
}

if (!function_exists('chat_os_authorization_decision')) {
    function chat_os_authorization_decision(array $capability, array $resource, array $context = []): array {
        $permission = (array)($capability['required_permissions'] ?? []);
        $requiresConfirmation = (bool)($capability['requires_confirmation'] ?? false);
        $resourceState = strtoupper((string)($resource['state'] ?? 'UNKNOWN'));
        $approved = (bool)($context['approval_granted'] ?? false);
        $isDevUser = (bool)($context['is_dev_user'] ?? false);

        if ($permission === []) {
            return ['state' => 'NOT_REQUIRED', 'approved' => true, 'approval_required' => false, 'permissions' => []];
        }
        if ($resourceState !== 'AVAILABLE') {
            return ['state' => 'NOT_PROVIDED', 'approved' => false, 'approval_required' => $requiresConfirmation, 'permissions' => $permission];
        }
        if ($isDevUser) {
            return ['state' => 'AUTHORIZED', 'approved' => true, 'approval_required' => $requiresConfirmation, 'permissions' => $permission];
        }
        if ($requiresConfirmation && !$approved) {
            return ['state' => 'APPROVAL_REQUIRED', 'approved' => false, 'approval_required' => true, 'permissions' => $permission];
        }

        $granted = (array)($context['granted_permissions'] ?? []);
        if ($granted === []) {
            return ['state' => 'UNAUTHORIZED', 'approved' => $approved, 'approval_required' => $requiresConfirmation, 'permissions' => $permission];
        }

        foreach ($permission as $required) {
            if (!in_array((string)$required, $granted, true)) {
                return ['state' => 'UNAUTHORIZED', 'approved' => $approved, 'approval_required' => $requiresConfirmation, 'permissions' => $permission];
            }
        }

        return ['state' => 'AUTHORIZED', 'approved' => $approved || !$requiresConfirmation, 'approval_required' => $requiresConfirmation, 'permissions' => $permission];
    }
}

if (!function_exists('chat_os_plan_actions')) {
    function chat_os_plan_actions(string $taskId, string $routeClass, string $capabilityId, array $resource, array $authorization, array $context = []): array {
        $steps = [];
        if ($routeClass === 'RESEARCH') {
            $steps = ['Search', 'Retrieve', 'Verify', 'Evidence', 'Claims', 'Citations', 'Answer'];
        } elseif ($routeClass === 'EXECUTION') {
            $steps = ['Classify intent', 'Check resource state', 'Check authorization', 'Assess execution risk', 'Execute if allowed', 'Verify result'];
        } elseif ($routeClass === 'FAST') {
            $steps = ['Classify intent', 'Transform request', 'Draft concise answer', 'Check response contract'];
        } else {
            $steps = ['Classify intent', 'Choose guidance mode', 'Generate response', 'Validate response contract'];
        }

        $planId = chat_os_uuid('plan');
        $actions = [];
        foreach ($steps as $index => $step) {
            $actions[] = chat_os_build_action($taskId, $capabilityId, $step, [
                'action_id' => chat_os_uuid('action'),
                'status' => $index === 0 ? 'REQUESTED' : 'QUEUED',
                'risk_level' => (string)($context['risk_level'] ?? 'LOW'),
                'authorization' => $authorization,
                'input' => ['resource_id' => (string)($resource['resource_id'] ?? ''), 'step' => $step],
            ]);
        }

        return [
            'plan_id' => $planId,
            'route_class' => $routeClass,
            'steps' => $steps,
            'actions' => $actions,
        ];
    }
}

if (!function_exists('chat_os_runtime_storage_dir')) {
    function chat_os_runtime_storage_dir(): string {
        return dirname(__DIR__, 3) . '/storage/ai_os';
    }
}

if (!function_exists('chat_os_append_jsonl')) {
    function chat_os_append_jsonl(string $filePath, array $row): bool {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($row, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return false;
        }
        return @file_put_contents($filePath, $json . "\n", FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('chat_os_db_datetime')) {
    function chat_os_db_datetime(?string $iso): ?string {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('chat_os_encode_json')) {
    function chat_os_encode_json($value): string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('chat_os_ensure_schema')) {
    function chat_os_ensure_schema(?mysqli $db): bool {
        if (!($db instanceof mysqli) || $db->connect_error) {
            return false;
        }

        $schema = [
            "CREATE TABLE IF NOT EXISTS ai_os_tasks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id VARCHAR(64) NOT NULL,
                request_id VARCHAR(64) DEFAULT NULL,
                user_id VARCHAR(120) DEFAULT NULL,
                session_id VARCHAR(191) DEFAULT NULL,
                parent_task_id VARCHAR(64) DEFAULT NULL,
                intent_id VARCHAR(64) DEFAULT NULL,
                intent VARCHAR(120) DEFAULT NULL,
                status VARCHAR(40) NOT NULL,
                priority VARCHAR(40) DEFAULT NULL,
                risk_level VARCHAR(40) DEFAULT NULL,
                required_capabilities_json LONGTEXT DEFAULT NULL,
                required_resources_json LONGTEXT DEFAULT NULL,
                authorization_state VARCHAR(40) DEFAULT NULL,
                plan_id VARCHAR(64) DEFAULT NULL,
                current_action_id VARCHAR(64) DEFAULT NULL,
                result_id VARCHAR(64) DEFAULT NULL,
                failure_type VARCHAR(120) DEFAULT NULL,
                error_text MEDIUMTEXT DEFAULT NULL,
                evidence_ids_json LONGTEXT DEFAULT NULL,
                verification_state VARCHAR(40) DEFAULT NULL,
                memory_write_state VARCHAR(40) DEFAULT NULL,
                objective MEDIUMTEXT DEFAULT NULL,
                context_json LONGTEXT DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                result_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_task_id (task_id),
                KEY idx_request_id (request_id),
                KEY idx_user_id (user_id),
                KEY idx_status_created (status, created_at),
                KEY idx_parent_task (parent_task_id),
                KEY idx_plan_id (plan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ai_os_executions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                execution_id VARCHAR(64) NOT NULL,
                task_id VARCHAR(64) NOT NULL,
                request_id VARCHAR(64) DEFAULT NULL,
                action_id VARCHAR(64) DEFAULT NULL,
                capability_id VARCHAR(120) DEFAULT NULL,
                resource_id VARCHAR(120) DEFAULT NULL,
                status VARCHAR(40) NOT NULL,
                authorization_state VARCHAR(40) DEFAULT NULL,
                input_hash VARCHAR(128) DEFAULT NULL,
                output_hash VARCHAR(128) DEFAULT NULL,
                input_summary MEDIUMTEXT DEFAULT NULL,
                stdout_text MEDIUMTEXT DEFAULT NULL,
                stderr_text MEDIUMTEXT DEFAULT NULL,
                artifact_ids_json LONGTEXT DEFAULT NULL,
                error_type VARCHAR(120) DEFAULT NULL,
                verification_state VARCHAR(40) DEFAULT NULL,
                provenance VARCHAR(80) DEFAULT NULL,
                evidence_level VARCHAR(10) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_execution_id (execution_id),
                KEY idx_task_id (task_id),
                KEY idx_request_id (request_id),
                KEY idx_capability_status (capability_id, status),
                KEY idx_resource_status (resource_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($schema as $sql) {
            if (!$db->query($sql)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('chat_os_persist_task_record_db')) {
    function chat_os_persist_task_record_db(mysqli $db, array $task, array $decision = []): bool {
        if (!chat_os_ensure_schema($db)) {
            return false;
        }

        $sql = "INSERT INTO ai_os_tasks (
            task_id, request_id, user_id, session_id, parent_task_id, intent_id, intent,
            status, priority, risk_level, required_capabilities_json, required_resources_json,
            authorization_state, plan_id, current_action_id, result_id, failure_type,
            error_text, evidence_ids_json, verification_state, memory_write_state,
            objective, context_json, metadata_json, result_json, created_at, started_at, completed_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            request_id = VALUES(request_id),
            user_id = VALUES(user_id),
            session_id = VALUES(session_id),
            parent_task_id = VALUES(parent_task_id),
            intent_id = VALUES(intent_id),
            intent = VALUES(intent),
            status = VALUES(status),
            priority = VALUES(priority),
            risk_level = VALUES(risk_level),
            required_capabilities_json = VALUES(required_capabilities_json),
            required_resources_json = VALUES(required_resources_json),
            authorization_state = VALUES(authorization_state),
            plan_id = VALUES(plan_id),
            current_action_id = VALUES(current_action_id),
            result_id = VALUES(result_id),
            failure_type = VALUES(failure_type),
            error_text = VALUES(error_text),
            evidence_ids_json = VALUES(evidence_ids_json),
            verification_state = VALUES(verification_state),
            memory_write_state = VALUES(memory_write_state),
            objective = VALUES(objective),
            context_json = VALUES(context_json),
            metadata_json = VALUES(metadata_json),
            result_json = VALUES(result_json),
            started_at = VALUES(started_at),
            completed_at = VALUES(completed_at)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $taskId = (string)($task['task_id'] ?? '');
        $requestId = (string)($task['request_id'] ?? '');
        $userId = (string)($task['user_id'] ?? '');
        $sessionId = (string)($task['session_id'] ?? '');
        $parentTaskId = (string)($task['parent_task_id'] ?? '');
        $intentId = (string)($task['intent_id'] ?? '');
        $intent = (string)($task['intent'] ?? '');
        $status = (string)($task['status'] ?? 'UNVERIFIED');
        $priority = (string)($task['priority'] ?? 'normal');
        $riskLevel = (string)($task['risk_level'] ?? 'LOW');
        $requiredCapabilities = chat_os_encode_json($task['required_capabilities'] ?? []);
        $requiredResources = chat_os_encode_json($task['required_resources'] ?? []);
        $authorizationState = (string)($task['authorization_state'] ?? 'UNKNOWN');
        $planId = (string)($task['plan_id'] ?? '');
        $currentActionId = (string)($task['current_action_id'] ?? '');
        $resultId = (string)($task['result_id'] ?? '');
        $failureType = (string)($task['failure_type'] ?? '');
        $errorText = is_scalar($task['error'] ?? null) ? (string)$task['error'] : chat_os_encode_json($task['error'] ?? null);
        $evidenceIds = chat_os_encode_json($task['evidence_ids'] ?? []);
        $verificationState = (string)($task['verification_status'] ?? ($task['status'] ?? 'UNVERIFIED'));
        $memoryWriteState = (string)($task['memory_write_state'] ?? 'NOT_REQUIRED');
        $objective = (string)($task['objective'] ?? '');
        $contextJson = chat_os_encode_json($task['context'] ?? []);
        $metadataJson = chat_os_encode_json(array_merge((array)($task['metadata'] ?? []), ['decision' => $decision]));
        $resultJson = chat_os_encode_json($task['result'] ?? []);
        $createdAt = chat_os_db_datetime((string)($task['created_at'] ?? '')) ?? gmdate('Y-m-d H:i:s');
        $startedAt = chat_os_db_datetime((string)($task['started_at'] ?? ''));
        $completedAt = chat_os_db_datetime((string)($task['completed_at'] ?? ''));

        $stmt->bind_param(
            'ssssssssssssssssssssssssssss',
            $taskId,
            $requestId,
            $userId,
            $sessionId,
            $parentTaskId,
            $intentId,
            $intent,
            $status,
            $priority,
            $riskLevel,
            $requiredCapabilities,
            $requiredResources,
            $authorizationState,
            $planId,
            $currentActionId,
            $resultId,
            $failureType,
            $errorText,
            $evidenceIds,
            $verificationState,
            $memoryWriteState,
            $objective,
            $contextJson,
            $metadataJson,
            $resultJson,
            $createdAt,
            $startedAt,
            $completedAt
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('chat_os_persist_execution_records_db')) {
    function chat_os_persist_execution_records_db(mysqli $db, string $taskId, array $records, string $requestId = ''): bool {
        if (!chat_os_ensure_schema($db)) {
            return false;
        }
        $sql = "INSERT INTO ai_os_executions (
            execution_id, task_id, request_id, action_id, capability_id, resource_id, status,
            authorization_state, input_hash, output_hash, input_summary, stdout_text, stderr_text,
            artifact_ids_json, error_type, verification_state, provenance, evidence_level,
            metadata_json, started_at, completed_at, duration_ms
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            task_id = VALUES(task_id),
            request_id = VALUES(request_id),
            action_id = VALUES(action_id),
            capability_id = VALUES(capability_id),
            resource_id = VALUES(resource_id),
            status = VALUES(status),
            authorization_state = VALUES(authorization_state),
            input_hash = VALUES(input_hash),
            output_hash = VALUES(output_hash),
            input_summary = VALUES(input_summary),
            stdout_text = VALUES(stdout_text),
            stderr_text = VALUES(stderr_text),
            artifact_ids_json = VALUES(artifact_ids_json),
            error_type = VALUES(error_type),
            verification_state = VALUES(verification_state),
            provenance = VALUES(provenance),
            evidence_level = VALUES(evidence_level),
            metadata_json = VALUES(metadata_json),
            started_at = VALUES(started_at),
            completed_at = VALUES(completed_at),
            duration_ms = VALUES(duration_ms)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $ok = true;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $executionId = (string)($record['execution_id'] ?? chat_os_uuid('exec'));
            $taskRef = $taskId !== '' ? $taskId : (string)($record['task_id'] ?? '');
            $requestRef = $requestId !== '' ? $requestId : (string)($record['request_id'] ?? '');
            $actionId = (string)($record['action_id'] ?? '');
            $capabilityId = (string)($record['capability_id'] ?? ($record['tool_name'] ?? 'unknown_tool'));
            $resourceId = (string)($record['resource_id'] ?? chat_os_primary_resource_for_capability($capabilityId));
            $status = (string)($record['status'] ?? 'FAILED');
            $authorizationState = (string)($record['authorization_state'] ?? 'UNKNOWN');
            $inputSummary = (string)($record['input_summary'] ?? '');
            $stdoutText = (string)($record['stdout'] ?? '');
            $stderrText = is_scalar($record['error'] ?? null) ? (string)$record['error'] : '';
            $inputHash = $inputSummary !== '' ? hash('sha256', $inputSummary) : null;
            $outputSeed = $stdoutText !== '' ? $stdoutText : chat_os_encode_json($record);
            $outputHash = $outputSeed !== '' ? hash('sha256', $outputSeed) : null;
            $artifactIds = chat_os_encode_json($record['artifact_ids'] ?? []);
            $errorType = (string)($record['error_type'] ?? ($record['error'] ? 'RUNTIME_ERROR' : ''));
            $verificationState = !empty($record['result_verified']) ? 'VERIFIED' : 'UNVERIFIED';
            $provenance = (string)($record['provenance'] ?? 'TOOL_OBSERVED');
            $evidenceLevel = (string)($record['evidence_level'] ?? chat_evidence_level_for_provenance($provenance, !empty($record['result_verified'])));
            $metadataJson = chat_os_encode_json([
                'tool_name' => $record['tool_name'] ?? null,
                'operation' => $record['operation'] ?? null,
                'success' => $record['success'] ?? null,
                'attempted' => $record['attempted'] ?? null,
                'source' => $record['source'] ?? null,
                'result_available' => $record['result_available'] ?? null,
            ]);
            $startedAt = chat_os_db_datetime((string)($record['started_at'] ?? ''));
            $completedAt = chat_os_db_datetime((string)($record['completed_at'] ?? ''));
            $durationMs = isset($record['duration_ms']) ? (int)$record['duration_ms'] : null;

            $stmt->bind_param(
                'sssssssssssssssssssssi',
                $executionId,
                $taskRef,
                $requestRef,
                $actionId,
                $capabilityId,
                $resourceId,
                $status,
                $authorizationState,
                $inputHash,
                $outputHash,
                $inputSummary,
                $stdoutText,
                $stderrText,
                $artifactIds,
                $errorType,
                $verificationState,
                $provenance,
                $evidenceLevel,
                $metadataJson,
                $startedAt,
                $completedAt,
                $durationMs
            );
            $ok = $stmt->execute() && $ok;
        }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('chat_os_persist_task_record')) {
    function chat_os_persist_task_record(array $task, array $decision = [], ?mysqli $db = null): bool {
        if ($db instanceof mysqli && !$db->connect_error && chat_os_persist_task_record_db($db, $task, $decision)) {
            return true;
        }
        $path = chat_os_runtime_storage_dir() . '/tasks-' . gmdate('Ymd') . '.jsonl';
        return chat_os_append_jsonl($path, [
            'logged_at' => chat_os_now_iso(),
            'task' => $task,
            'decision' => $decision,
        ]);
    }
}

if (!function_exists('chat_os_persist_execution_records')) {
    function chat_os_persist_execution_records(string $taskId, array $records, string $requestId = '', ?mysqli $db = null): bool {
        if ($db instanceof mysqli && !$db->connect_error && chat_os_persist_execution_records_db($db, $taskId, $records, $requestId)) {
            return true;
        }
        $path = chat_os_runtime_storage_dir() . '/executions-' . gmdate('Ymd') . '.jsonl';
        $ok = true;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $ok = chat_os_append_jsonl($path, [
                'logged_at' => chat_os_now_iso(),
                'task_id' => $taskId,
                'request_id' => $requestId,
                'execution' => $record,
            ]) && $ok;
        }
        return $ok;
    }
}

if (!function_exists('chat_os_cron_job_context')) {
    function chat_os_cron_job_context(string $jobName, string $objective, array $context = []): array {
        $requestId = (string)($context['request_id'] ?? chat_os_uuid('cronreq'));
        $requestProfile = [
            'request_class' => 'SYSTEM_ADMINISTRATION',
            'risk_level' => (string)($context['risk_level'] ?? 'MEDIUM'),
            'response_mode' => 'system_administration',
            'tool_required' => true,
            'evidence_required' => true,
        ];
        $taskControl = [
            'requires_execution' => true,
            'risk_level' => strtolower((string)($context['risk_level'] ?? 'medium')),
            'task_domain' => (string)($context['task_domain'] ?? 'operations'),
            'requested_operation' => 'execution',
        ];
        $decision = chat_os_prepare_runtime_decision($objective, $requestProfile, $taskControl, [
            'request_id' => $requestId,
            'user_id' => (string)($context['user_id'] ?? 'system'),
            'session_id' => (string)($context['session_id'] ?? $jobName),
            'is_dev_user' => true,
            'approval_granted' => true,
            'granted_permissions' => (array)($context['granted_permissions'] ?? ['model.generate', 'filesystem.read', 'network.read']),
            'web_search_requested' => (bool)($context['web_search_requested'] ?? false),
            'web_runtime_available' => (bool)($context['web_runtime_available'] ?? false),
            'workspace_available' => (bool)($context['workspace_available'] ?? true),
            'model_runtime_available' => true,
            'database_runtime_available' => (bool)($context['database_runtime_available'] ?? false),
            'shell_runtime_available' => (bool)($context['shell_runtime_available'] ?? false),
            'deployment_runtime_available' => (bool)($context['deployment_runtime_available'] ?? false),
            'needs_fresh_web' => (bool)($context['needs_fresh_web'] ?? false),
            'db' => $context['db'] ?? null,
        ]);
        $decision['job_name'] = $jobName;
        return $decision;
    }
}

if (!function_exists('chat_os_mark_task_result')) {
    function chat_os_mark_task_result(array $task, string $status, array $result = [], ?string $failureType = null, $error = null): array {
        $task['status'] = strtoupper($status);
        $task['verification_status'] = in_array(strtoupper($status), ['VERIFIED', 'SUCCEEDED'], true) ? 'VERIFIED' : 'UNVERIFIED';
        $task['completed_at'] = chat_os_now_iso();
        $task['result'] = $result;
        $task['failure_type'] = $failureType ?? (string)($task['failure_type'] ?? '');
        $task['error'] = $error;
        return $task;
    }
}

if (!function_exists('chat_os_direct_response')) {
    function chat_os_direct_response(array $decision): ?string {
        $routeClass = strtoupper((string)($decision['route_class'] ?? 'STANDARD'));
        $capabilityId = (string)($decision['capability']['capability_id'] ?? 'model.reason');
        $capabilityName = (string)($decision['capability']['name'] ?? $capabilityId);
        $resourceName = (string)($decision['resource']['name'] ?? ($decision['resource']['resource_id'] ?? 'required resource'));
        $resourceState = strtoupper((string)($decision['resource']['state'] ?? 'UNKNOWN'));
        $authState = strtoupper((string)($decision['authorization']['state'] ?? 'UNKNOWN'));
        $riskLevel = strtolower((string)($decision['risk_level'] ?? 'LOW'));

        if (str_starts_with($capabilityId, 'model.')) {
            return null;
        }

        $nextChecks = match ($capabilityId) {
            'server.inspect' => 'Next checks: 1) query the deployment control plane for the latest release and health status, 2) run a health probe against the target endpoint, 3) compare active artifact/version with the expected rollout target.',
            'database.query' => 'Next checks: 1) run read-only schema/version queries, 2) verify migration history table state, 3) compare row counts and key integrity signals before and after the change.',
            'filesystem.read' => 'Next checks: 1) locate the relevant files and commit diff, 2) confirm the exact patch is present, 3) validate the runtime config that loads those files.',
            'shell.execute' => 'Next checks: 1) run read-only inspection commands, 2) capture command output and timestamps, 3) validate service and process status before any state-changing action.',
            'web.search', 'web.retrieve', 'web.verify_source' => 'Next checks: 1) retrieve at least two authoritative sources, 2) extract dated evidence snippets, 3) cite URLs and publication/update dates in the final conclusion.',
            default => 'Next checks: collect objective evidence from the required system, then re-evaluate the conclusion against that evidence before taking action.',
        };

        if ($resourceState === 'UNAVAILABLE' || ($resourceState === 'DISABLED' && $routeClass === 'RESEARCH')) {
            return 'Live runtime evidence from ' . $resourceName . ' is unavailable for ' . $capabilityName . '. This response is unverified and does not claim execution. ' . $nextChecks;
        }
        if ($authState === 'APPROVAL_REQUIRED') {
            return 'This is a ' . $riskLevel . '-risk action and approval is required before execution. This response is unverified and does not claim execution. ' . $nextChecks;
        }
        if ($authState === 'UNAUTHORIZED') {
            return 'Authorization for ' . $capabilityName . ' is missing in this runtime. This response is unverified and does not claim execution. ' . $nextChecks;
        }

        return null;
    }
}

if (!function_exists('chat_os_runtime_prompt')) {
    function chat_os_runtime_prompt(array $decision): string {
        $routeClass = strtoupper((string)($decision['route_class'] ?? 'STANDARD'));
        $capabilityId = (string)($decision['capability']['capability_id'] ?? 'model.reason');
        $resourceState = strtoupper((string)($decision['resource']['state'] ?? 'UNKNOWN'));
        $authState = strtoupper((string)($decision['authorization']['state'] ?? 'UNKNOWN'));
        $riskLevel = strtoupper((string)($decision['risk_level'] ?? 'LOW'));
        $intent = strtoupper((string)($decision['intent'] ?? ''));
        $approvalRequired = !empty($decision['authorization']['approval_required']) ? 'yes' : 'no';

        $objectiveText = strtolower((string)($decision['task']['objective'] ?? ''));
        $isProductionIncident = $intent === 'PRODUCTION_OPERATIONS'
            || preg_match('/\b(incident|outage|degraded|partial\s+migration|sql\s+migration|rollback|production\s+failure)\b/i', $objectiveText) === 1;
        $incidentPolicy = '';
        if ($isProductionIncident) {
            $incidentPolicy = ' Incident priority order is mandatory: 1) stop additional damage, 2) stabilize service, 3) preserve evidence, 4) determine blast radius, 5) establish current state, 6) choose reversible mitigation, 7) recover service, 8) validate recovery, 9) investigate root cause, 10) permanent remediation, 11) post-incident changes. During a live partial migration incident, do not recommend dependency upgrades, broad refactors, or architecture changes in the immediate response path unless concrete evidence explicitly identifies dependency incompatibility as the cause.';
        }

        return "AI-OS control plane is authoritative for this request. Route class: {$routeClass}. Authorized capability: {$capabilityId}. Resource state: {$resourceState}. Authorization state: {$authState}. Risk level: {$riskLevel}. Approval required: {$approvalRequired}. Never claim execution, inspection, retrieval, verification, deployment, or source confirmation unless the runtime created a matching execution record and verified evidence. Always provide a useful answer: when runtime execution or live research is unavailable, clearly label unknowns, avoid fabricated facts, and provide the best evidence-bounded analysis plus concrete next checks the user can run." . $incidentPolicy;
    }
}

if (!function_exists('chat_os_prepare_runtime_decision')) {
    function chat_os_prepare_runtime_decision(string $latestUserMsg, array $requestProfile, array $taskControl, array $context = []): array {
        $requestId = (string)($context['request_id'] ?? '');
        $routeClass = chat_os_route_classify($requestProfile, $taskControl, $latestUserMsg, $context);
        $capabilityId = chat_os_capability_for_request($requestProfile, $taskControl, $latestUserMsg, $routeClass);
        $capabilityRegistry = chat_os_capability_registry((array)($context['capability_overrides'] ?? []));
        $capability = $capabilityRegistry[$capabilityId] ?? ['capability_id' => $capabilityId, 'enabled' => false, 'risk_level' => 'LOW'];
        $resourceRegistry = chat_os_resource_registry([
            'web_search_requested' => (bool)($context['web_search_requested'] ?? false),
            'web_runtime_available' => (bool)($context['web_runtime_available'] ?? false),
            'workspace_available' => (bool)($context['workspace_available'] ?? true),
            'model_runtime_available' => (bool)($context['model_runtime_available'] ?? true),
            'database_runtime_available' => (bool)($context['database_runtime_available'] ?? false),
            'shell_runtime_available' => (bool)($context['shell_runtime_available'] ?? false),
            'deployment_runtime_available' => (bool)($context['deployment_runtime_available'] ?? false),
        ]);
        $resourceId = chat_os_primary_resource_for_capability($capabilityId);
        $resource = $resourceRegistry[$resourceId] ?? ['resource_id' => $resourceId, 'name' => $resourceId, 'state' => 'UNKNOWN'];
        $authorization = chat_os_authorization_decision($capability, $resource, [
            'is_dev_user' => (bool)($context['is_dev_user'] ?? false),
            'approval_granted' => (bool)($context['approval_granted'] ?? false),
            'granted_permissions' => (array)($context['granted_permissions'] ?? []),
        ]);
        $requirementAnalysis = chat_os_task_requirement_analysis($requestProfile, $taskControl, $latestUserMsg, $context);
        $evidenceRequired = !empty($requirementAnalysis['research_required'])
            || !empty($requirementAnalysis['execution_required'])
            || !empty($requirementAnalysis['external_state_required']);
        $riskLevel = strtoupper((string)($requestProfile['risk_level'] ?? ($taskControl['risk_level'] ?? ($capability['risk_level'] ?? 'LOW'))));
        if (!$evidenceRequired && str_starts_with((string)$capabilityId, 'model.')) {
            $riskLevel = 'LOW';
        }
        $requiresResource = !empty($requirementAnalysis['execution_required'])
            || !empty($requirementAnalysis['external_state_required'])
            || !empty($requirementAnalysis['research_required'])
            || !str_starts_with((string)$capabilityId, 'model.');
        $requiredResources = $requiresResource ? [$resourceId] : [];
        $taskId = chat_os_uuid('task');
        $plan = chat_os_plan_actions($taskId, $routeClass, $capabilityId, $resource, $authorization, ['risk_level' => $riskLevel]);

        $task = chat_os_build_task($requestId, $requestProfile, [
            'task_id' => $taskId,
            'user_id' => (string)($context['user_id'] ?? ''),
            'session_id' => (string)($context['session_id'] ?? ''),
            'intent_id' => chat_os_uuid('intent'),
            'intent' => (string)($requirementAnalysis['intent'] ?? ($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')),
            'objective' => $latestUserMsg,
            'required_capabilities' => [$capabilityId],
            'required_resources' => $requiredResources,
            'authorization_state' => (string)($authorization['state'] ?? 'UNKNOWN'),
            'plan_id' => (string)($plan['plan_id'] ?? ''),
            'current_action_id' => (string)($plan['actions'][0]['action_id'] ?? ''),
            'priority' => $routeClass === 'FAST' ? 'high' : 'normal',
            'metadata' => [
                'route_class' => $routeClass,
                'capability_id' => $capabilityId,
                'resource_id' => $resourceId,
                'external_state_required' => !empty($requirementAnalysis['external_state_required']),
                'execution_required' => !empty($requirementAnalysis['execution_required']),
                'research_required' => !empty($requirementAnalysis['research_required']),
                'evidence_required' => $evidenceRequired,
            ],
        ]);

        $actions = [];
        foreach ((array)($plan['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $action['task_id'] = (string)$task['task_id'];
            $action['resource_id'] = $resourceId;
            $actions[] = $action;
        }
        $task['actions'] = $actions;

        $researchRequested = !empty($requirementAnalysis['research_required']) || $routeClass === 'RESEARCH';
        $researchShouldExecute = $researchRequested
            && $capabilityId === 'web.search'
            && (bool)($capability['enabled'] ?? false)
            && (($resource['state'] ?? 'UNKNOWN') === 'AVAILABLE')
            && in_array((string)($authorization['state'] ?? 'UNKNOWN'), ['AUTHORIZED', 'NOT_REQUIRED'], true);
        $executionAllowed = (($resource['state'] ?? 'UNKNOWN') === 'AVAILABLE')
            && in_array((string)($authorization['state'] ?? 'UNKNOWN'), ['AUTHORIZED', 'NOT_REQUIRED'], true)
            && (bool)($capability['enabled'] ?? false);

        $decision = [
            'request_id' => $requestId,
            'intent' => (string)($requirementAnalysis['intent'] ?? ($requestProfile['request_class'] ?? 'GENERAL_INFORMATION')),
            'desired_outcome' => (string)($requirementAnalysis['desired_outcome'] ?? 'ANSWER'),
            'external_state_required' => !empty($requirementAnalysis['external_state_required']),
            'execution_required' => !empty($requirementAnalysis['execution_required']),
            'research_required' => !empty($requirementAnalysis['research_required']),
            'evidence_required' => $evidenceRequired,
            'capabilities_required' => [$capabilityId],
            'resources_required' => $requiredResources,
            'authorization_required' => !empty($capability['required_permissions']) || !empty($authorization['permissions']),
            'routing_class' => $routeClass,
            'route_class' => $routeClass,
            'capability' => $capability,
            'capability_registry' => $capabilityRegistry,
            'resource' => $resource,
            'resource_registry' => $resourceRegistry,
            'authorization' => $authorization,
            'risk_level' => $riskLevel,
            'task' => $task,
            'plan' => $plan,
            'actions' => $actions,
            'research' => [
                'requested' => $researchRequested,
                'should_execute' => $researchShouldExecute,
            ],
            'execution_allowed' => $executionAllowed,
            'direct_response' => null,
        ];
        $decision['direct_response'] = chat_os_direct_response($decision);
        $decision['runtime_prompt'] = chat_os_runtime_prompt($decision);
        $decision['persisted'] = chat_os_persist_task_record($task, [
            'route_class' => $routeClass,
            'capability_id' => $capabilityId,
            'resource_id' => $resourceId,
            'authorization_state' => $authorization['state'] ?? 'UNKNOWN',
            'risk_level' => $riskLevel,
        ], ($context['db'] ?? null) instanceof mysqli ? $context['db'] : null);

        return $decision;
    }
}

if (!function_exists('chat_os_build_action')) {
    function chat_os_build_action(string $taskId, string $capabilityId, string $description, array $context = []): array {
        return [
            'action_id' => (string)($context['action_id'] ?? chat_os_uuid('action')),
            'task_id' => $taskId,
            'parent_action_id' => (string)($context['parent_action_id'] ?? ''),
            'capability_id' => $capabilityId,
            'description' => $description,
            'input' => (array)($context['input'] ?? []),
            'risk_level' => strtoupper((string)($context['risk_level'] ?? 'LOW')),
            'authorization' => (array)($context['authorization'] ?? ['state' => 'UNKNOWN']),
            'status' => strtoupper((string)($context['status'] ?? 'REQUESTED')),
            'execution_id' => (string)($context['execution_id'] ?? ''),
            'verification_requirements' => (array)($context['verification_requirements'] ?? []),
            'dependencies' => (array)($context['dependencies'] ?? []),
            'created_at' => (string)($context['created_at'] ?? chat_os_now_iso()),
        ];
    }
}

if (!function_exists('chat_os_build_evidence_item')) {
    function chat_os_build_evidence_item(string $taskId, string $sourceType, string $source, string $content, array $fields = []): array {
        return [
            'evidence_id' => (string)($fields['evidence_id'] ?? chat_os_uuid('evidence')),
            'task_id' => $taskId,
            'execution_id' => (string)($fields['execution_id'] ?? ''),
            'source_type' => strtoupper($sourceType),
            'source' => $source,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'retrieved_at' => (string)($fields['retrieved_at'] ?? chat_os_now_iso()),
            'created_at' => (string)($fields['created_at'] ?? chat_os_now_iso()),
            'confidence' => (float)($fields['confidence'] ?? 0.5),
            'verification_status' => strtoupper((string)($fields['verification_status'] ?? 'UNVERIFIED')),
            'claims_supported' => (array)($fields['claims_supported'] ?? []),
            'metadata' => (array)($fields['metadata'] ?? []),
        ];
    }
}

if (!function_exists('chat_os_claim_type')) {
    function chat_os_claim_type(string $claim): string {
        $lower = strtolower(trim($claim));
        if ($lower === '') {
            return 'UNKNOWN';
        }
        if (preg_match('/\b(i\s+(ran|executed|queried|checked|inspected|searched|verified|tested|accessed|fetched)|the\s+(logs?|api|server)\s+(show|returned|reports?))\b/i', $claim) === 1) {
            return 'EXECUTION';
        }
        if (preg_match('/\b(according to|source:|citation:|published in|doi|official)\b/i', $claim) === 1) {
            return 'SOURCE';
        }
        if (preg_match('/\b(if|assuming|hypothetical|for example|could)\b/i', $claim) === 1) {
            return 'HYPOTHETICAL';
        }
        if (preg_match('/\d/', $claim) === 1) {
            return 'FACT_NUMERIC';
        }
        return 'FACT';
    }
}

if (!function_exists('chat_os_build_claim_graph')) {
    function chat_os_build_claim_graph(array $claimProvenance, array $evidence): array {
        $claims = [];
        foreach ($claimProvenance as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string)($item['claim'] ?? ''));
            if ($text === '') {
                continue;
            }
            $verified = (bool)($item['verified'] ?? false);
            $evidenceIds = [];
            if ($verified) {
                foreach ($evidence as $ev) {
                    if (!is_array($ev)) {
                        continue;
                    }
                    $evidenceIds[] = (string)($ev['evidence_id'] ?? '');
                }
            }
            $claims[] = [
                'claim_id' => chat_os_uuid('claim'),
                'claim_text' => $text,
                'claim_type' => chat_os_claim_type($text),
                'provenance' => (string)($item['provenance'] ?? 'UNKNOWN'),
                'evidence_ids' => array_values(array_filter($evidenceIds, static fn($id): bool => $id !== '')),
                'support_strength' => (string)($item['evidence_level'] ?? 'E0'),
                'verification_status' => $verified ? 'VERIFIED' : 'UNVERIFIED',
            ];
        }
        return $claims;
    }
}

if (!function_exists('chat_os_invariant_violations')) {
    function chat_os_invariant_violations(array $snapshot): array {
        $issues = [];

        $executions = is_array($snapshot['executions'] ?? null) ? $snapshot['executions'] : [];
        $claims = is_array($snapshot['claims'] ?? null) ? $snapshot['claims'] : [];

        $hasVerifiedExecution = false;
        foreach ($executions as $execution) {
            if (!is_array($execution)) {
                continue;
            }
            if (($execution['status'] ?? '') === 'RESULT_VERIFIED') {
                $hasVerifiedExecution = true;
                break;
            }
        }

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $type = (string)($claim['claim_type'] ?? 'UNKNOWN');
            $status = (string)($claim['verification_status'] ?? 'UNVERIFIED');

            if ($type === 'EXECUTION' && !$hasVerifiedExecution) {
                $issues[] = 'Invariant violation: execution claim exists without verified execution record.';
                break;
            }

            if ($type === 'SOURCE' && $status !== 'VERIFIED') {
                $issues[] = 'Invariant violation: source-attributed claim is unverified.';
                break;
            }
        }

        return array_values(array_unique($issues));
    }
}

if (!function_exists('chat_os_build_control_plane_snapshot')) {
    function chat_os_build_control_plane_snapshot(
        string $latestUserMsg,
        array $requestProfile,
        array $toolState,
        array $claimProvenance,
        array $context = []
    ): array {
        $requestId = (string)($context['request_id'] ?? '');
        $task = chat_os_build_task($requestId, $requestProfile, [
            'intent' => (string)($requestProfile['request_class'] ?? 'GENERAL_INFORMATION'),
            'objective' => $latestUserMsg,
            'verified' => (bool)($context['verified'] ?? false),
            'requested_capabilities' => [(string)($toolState['tool_name'] ?? 'none')],
            'metadata' => [
                'response_mode' => (string)($requestProfile['response_mode'] ?? 'general_information'),
                'claim_risk' => (string)($requestProfile['claim_risk'] ?? 'low'),
                'action_risk' => (string)($requestProfile['action_risk'] ?? 'low'),
                'user_impact' => (string)($requestProfile['user_impact'] ?? 'low'),
            ],
        ]);

        $capabilityRegistry = chat_os_capability_registry();
        $toolName = (string)($toolState['tool_name'] ?? '');
        $capabilityId = $toolName === 'web_search'
            ? 'web.search'
            : ($toolName === 'database' ? 'database.query' : ($toolName === 'shell' ? 'shell.execute' : 'filesystem.read'));
        $capability = $capabilityRegistry[$capabilityId] ?? [];
        $capabilityAccess = chat_os_capability_access_state($capability, [
            'resource_available' => (bool)($toolState['tool_available'] ?? false),
            'authorized' => (bool)($toolState['tool_authorized'] ?? false),
        ]);

        $action = chat_os_build_action(
            (string)$task['task_id'],
            $capabilityId,
            'Resolve user request through validated capability path',
            [
                'risk_level' => (string)($capability['risk_level'] ?? 'LOW'),
                'authorization' => ['state' => $capabilityAccess['state'], 'reason' => $capabilityAccess['reason']],
                'status' => (bool)($toolState['tool_required'] ?? false) ? 'REQUESTED' : 'NOT_REQUIRED',
                'input' => ['message' => $latestUserMsg],
            ]
        );

        $executionRecords = array_values(array_filter($toolState['execution_records'] ?? [], 'is_array'));
        $evidence = [];

        if (!empty($context['webSearchResults']) && is_array($context['webSearchResults'])) {
            $chunks = [];
            foreach (array_slice($context['webSearchResults'], 0, 5) as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $title = trim((string)($result['title'] ?? ''));
                $url = trim((string)($result['url'] ?? ''));
                $excerpt = trim((string)($result['excerpt'] ?? ''));
                if ($title !== '' || $url !== '' || $excerpt !== '') {
                    $chunks[] = $title . ' ' . $url . ' ' . $excerpt;
                }
            }
            if ($chunks !== []) {
                $evidence[] = chat_os_build_evidence_item(
                    (string)$task['task_id'],
                    'WEB_SOURCE',
                    'web_search',
                    implode("\n", $chunks),
                    [
                        'verification_status' => !empty($toolState['tool_result_verified']) ? 'VERIFIED' : 'UNVERIFIED',
                        'confidence' => !empty($toolState['tool_result_verified']) ? 0.9 : 0.4,
                    ]
                );
            }
        }

        if (!empty($executionRecords)) {
            $evidence[] = chat_os_build_evidence_item(
                (string)$task['task_id'],
                'TOOL_EXECUTION',
                'execution_records',
                json_encode($executionRecords, JSON_UNESCAPED_SLASHES) ?: '[]',
                [
                    'verification_status' => 'VERIFIED',
                    'confidence' => 1.0,
                ]
            );
        }

        $claims = chat_os_build_claim_graph($claimProvenance, $evidence);

        $snapshot = [
            'task' => $task,
            'capabilities' => [
                'registry' => $capabilityRegistry,
                'selected_capability' => $capability,
                'access_state' => $capabilityAccess,
            ],
            'actions' => [$action],
            'executions' => $executionRecords,
            'evidence' => $evidence,
            'claims' => $claims,
            'verification' => [
                'status' => !empty($context['verified']) ? 'VERIFIED' : 'UNVERIFIED',
                'request_id' => $requestId,
            ],
        ];

        $snapshot['invariant_violations'] = chat_os_invariant_violations($snapshot);
        return $snapshot;
    }
}
