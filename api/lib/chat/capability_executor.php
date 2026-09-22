<?php
// LYRA_EVENT_BUS_WIRED
// Architecture section 33: tool.called / tool.completed / tool.failed /
// permission.denied are emitted from this file. require_once is safe to repeat
// because event_bus.php only declares function_exists-guarded functions.
require_once __DIR__ . '/event_bus.php';

/**
 * Capability dispatch.
 *
 * os_core.php is a POLICY layer: it decides what *should* happen and returns
 * states such as APPROVAL_REQUIRED. Nothing consumed those decisions, so the
 * registry described capabilities the runtime could not actually perform. This
 * file is the missing executor half: it turns an authorization decision into an
 * executed action, or into a refused/pending outcome, and always produces an
 * execution record in the shape chat_os_persist_execution_records() expects.
 *
 * Rules this file exists to enforce:
 *   1. A capability that is not enabled is never executed, and the refusal is
 *      reported as a failure rather than silently skipped.
 *   2. APPROVAL_REQUIRED is binding. It creates a pending action and stops.
 *   3. An execution record is only marked verified when verification actually
 *      ran, so provenance cannot be claimed without evidence.
 */

if (!function_exists('chat_capability_registry_lookup')) {
    function chat_capability_registry_lookup(string $capabilityId, array $runtime = []): ?array {
        if (!function_exists('chat_os_capability_registry')) {
            return null;
        }
        $registry = chat_os_capability_registry($runtime);
        $capability = $registry[$capabilityId] ?? null;
        return is_array($capability) ? $capability : null;
    }
}

if (!function_exists('chat_capability_resource_state')) {
    /**
     * Resolve a capability's backing resource to one of the vocabulary states
     * os_core already uses (AVAILABLE / UNAVAILABLE / DISABLED).
     */
    function chat_capability_resource_state(string $capabilityId, array $context = []): string {
        switch ($capabilityId) {
            case 'filesystem.read':
            case 'filesystem.write':
                return !empty($context['workspace_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            case 'shell.execute':
                return !empty($context['shell_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            case 'database.query':
                return !empty($context['database_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            case 'web.search':
            case 'web.retrieve':
            case 'web.verify_source':
                return !empty($context['web_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            case 'server.inspect':
                return !empty($context['deployment_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            case 'model.fast':
            case 'model.reason':
            case 'model.code':
                return !empty($context['model_runtime_available']) ? 'AVAILABLE' : 'UNAVAILABLE';
            default:
                return 'UNKNOWN';
        }
    }
}

if (!function_exists('chat_capability_validate_args')) {
    /**
     * Validate arguments against the capability's declared input_schema.
     *
     * The schema is a shallow map of name => type. Types gate the executor from
     * receiving something it would otherwise coerce, which is how a string
     * "content" turns into a shell argument by accident.
     */
    function chat_capability_validate_args(array $capability, array $args): array {
        $schema = (array)($capability['input_schema'] ?? []);
        $errors = [];
        $clean = [];

        foreach ($schema as $name => $type) {
            $type = (string)$type;
            if (!array_key_exists($name, $args)) {
                // Only 'command' and 'path'/'content' pairs are truly required;
                // optional keys are declared by suffixing '?'.
                if (substr($type, -1) === '?') {
                    continue;
                }
                $errors[] = 'missing:' . $name;
                continue;
            }
            $value = $args[$name];
            $expected = rtrim($type, '?');

            if ($expected === 'object_or_string') {
                if (!is_array($value) && !is_string($value)) {
                    $errors[] = 'type:' . $name;
                    continue;
                }
                $clean[$name] = $value;
                continue;
            }

            if ($expected === 'array') {
                if (!is_array($value)) {
                    $errors[] = 'type:' . $name;
                    continue;
                }
                $clean[$name] = $value;
                continue;
            }

            if (!is_scalar($value)) {
                $errors[] = 'type:' . $name;
                continue;
            }
            if ($expected === 'string') {
                $value = (string)$value;
                if ($value === '') {
                    $errors[] = 'empty:' . $name;
                    continue;
                }
            }
            $clean[$name] = $value;
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'args' => $clean];
    }
}

if (!function_exists('chat_capability_input_summary')) {
    /**
     * A short, non-secret summary of the call, safe for logs and audit rows.
     * Content bodies are measured, never stored: an arbitrary file write could
     * otherwise place credentials into the audit trail.
     */
    function chat_capability_input_summary(string $capabilityId, array $args): string {
        switch ($capabilityId) {
            case 'filesystem.write':
                return 'path=' . (string)($args['path'] ?? '') .
                    ' bytes=' . strlen((string)($args['content'] ?? ''));
            case 'filesystem.read':
                return 'path=' . (string)($args['path'] ?? '');
            case 'shell.execute':
                return 'command=' . substr((string)($args['command'] ?? ''), 0, 300);
            default:
                $json = function_exists('chat_os_encode_json')
                    ? chat_os_encode_json($args)
                    : json_encode($args);
                return substr((string)$json, 0, 300);
        }
    }
}

if (!function_exists('chat_capability_execution_record')) {
    /**
     * Build a record matching the contract chat_os_persist_execution_records()
     * reads. Kept in one place so every capability reports identically.
     */
    function chat_capability_execution_record(
        string $capabilityId,
        string $status,
        string $authorizationState,
        bool $attempted,
        bool $success,
        string $inputSummary,
        string $stdout = '',
        ?string $error = null,
        bool $resultVerified = false,
        int $durationMs = 0,
        array $context = [],
        array $extra = []
    ): array {
        $nowIso = function_exists('chat_os_now_iso') ? chat_os_now_iso() : gmdate('c');
        $execId = function_exists('chat_os_uuid') ? chat_os_uuid('exec') : uniqid('exec', true);

        $record = array_merge([
            'execution_id' => $execId,
            'task_id' => (string)($context['task_id'] ?? ''),
            'request_id' => (string)($context['request_id'] ?? ''),
            'action_id' => (string)($context['action_id'] ?? ''),
            'capability_id' => $capabilityId,
            'tool_name' => $capabilityId,
            'operation' => 'execute',
            'resource_id' => function_exists('chat_os_primary_resource_for_capability')
                ? chat_os_primary_resource_for_capability($capabilityId)
                : '',
            'status' => $status,
            'authorization_state' => $authorizationState,
            'attempted' => $attempted,
            'success' => $success,
            'input_summary' => $inputSummary,
            'stdout' => $stdout,
            'error' => $error,
            'error_type' => $error !== null && $error !== '' ? 'RUNTIME_ERROR' : '',
            'result_available' => $stdout !== '' || $success,
            'result_verified' => $resultVerified,
            'source' => 'capability_dispatcher',
            'provenance' => $success ? 'TOOL_OBSERVED' : 'INTERNAL_KNOWLEDGE',
            'evidence_level' => $success && $resultVerified ? 'E4' : '',
            'duration_ms' => $durationMs,
            'started_at' => $nowIso,
            'completed_at' => $nowIso,
        ], $extra);

        // Audit: exactly one outcome event per capability execution record.
        // $success means the runtime observed a result; $attempted means the
        // capability actually ran. A capability blocked before execution is a
        // POLICY outcome (permission.denied), not a tool error - conflating the
        // two would make an authority refusal look like a broken tool.
        if (function_exists('chat_os_event_emit')) {
            $eventType = 'tool.failed';
            $severity = 'error';
            if ($success) {
                $eventType = 'tool.completed';
                $severity = 'info';
            } elseif (!$attempted) {
                // The capability did not run. If it was also not AUTHORIZED then the
                // authority/policy layer is what stopped it, which is permission.denied
                // and not a tool error. The first version enumerated authorization
                // strings and missed 'UNAUTHORIZED' - the value the permission gate
                // actually produces - so a blocked action was recorded as a tool
                // failure. Inverting the test means an unrecognised future state still
                // classifies as an authority outcome instead of quietly degrading.
                // PERMISSION_DENIED_INVERTED_20260922
                $severity = 'warning';
                if (strtoupper($authorizationState) !== 'AUTHORIZED') {
                    $eventType = 'permission.denied';
                }
            }
            chat_os_event_emit($eventType, [
                'status' => $status,
                'authorization_state' => $authorizationState,
                'attempted' => $attempted,
                'success' => $success,
                'verified' => $resultVerified,
                'error' => (string)($error ?? ''),
                'input_summary' => $inputSummary,
            ], [
                'component' => 'capability_executor',
                'severity' => $severity,
                'capability_id' => $capabilityId,
                'tool_name' => $capabilityId,
                'duration_ms' => $durationMs,
                'task_id' => (string)($context['task_id'] ?? ''),
                'request_id' => (string)($context['request_id'] ?? ''),
                'db' => ($context['db'] ?? null),
            ]);
        }

        return $record;
    }
}

if (!function_exists('chat_capability_execute')) {
    /**
     * Run a whitelisted capability. Returns ['ok','reason','result','stdout','verified'].
     * Every branch is closed: an unknown capability id cannot reach an executor.
     */
    function chat_capability_execute(string $capabilityId, array $args, array $context = []): array {
        switch ($capabilityId) {
            case 'filesystem.read':
                if (!function_exists('chat_workspace_read_file')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $read = chat_workspace_read_file((string)($args['path'] ?? ''), 20000);
                if ($read === null) {
                    return ['ok' => false, 'reason' => 'read_denied_or_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                return [
                    'ok' => true,
                    'reason' => 'read',
                    'result' => [
                        'relative_path' => $read['relative_path'] ?? '',
                        'bytes' => $read['bytes'] ?? 0,
                        'truncated' => $read['truncated'] ?? false,
                    ],
                    'stdout' => (string)($read['content'] ?? ''),
                    'verified' => true,
                ];

            case 'filesystem.write':
                if (!function_exists('chat_workspace_write_file')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $write = chat_workspace_write_file(
                    (string)($args['path'] ?? ''),
                    (string)($args['content'] ?? '')
                );
                $verified = !empty($write['ok']);
                // Independent verification: the artifact must exist and its hash
                // must match what we intended to write.
                if ($verified && !empty($write['path']) && is_file((string)$write['path'])) {
                    $actual = hash_file('sha256', (string)$write['path']);
                    $expected = hash('sha256', (string)($args['content'] ?? ''));
                    $verified = ($actual === $expected);
                    $write['sha256'] = $actual;
                } else {
                    $verified = false;
                }
                return [
                    'ok' => !empty($write['ok']),
                    'reason' => (string)($write['reason'] ?? 'unknown'),
                    'result' => $write,
                    'stdout' => !empty($write['ok'])
                        ? ('wrote ' . (int)($write['bytes_written'] ?? 0) . ' bytes to ' . (string)($write['relative_path'] ?? ''))
                        : '',
                    'verified' => $verified,
                ];

            case 'shell.execute':
                if (!function_exists('lyra_sandbox_execute')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $run = lyra_sandbox_execute((string)($args['command'] ?? ''), [
                    'timeout_sec' => (int)($context['timeout_sec'] ?? 20),
                ]);
                return [
                    'ok' => !empty($run['ok']),
                    'reason' => (string)($run['reason'] ?? 'unknown'),
                    'result' => $run,
                    'stdout' => (string)($run['stdout'] ?? ''),
                    'verified' => !empty($run['ok']) && ($run['exit_code'] ?? null) === 0,
                ];

            case 'web.search':
                if (!function_exists('chat_web_search_query_with_status')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $webQuery = trim((string)($args['query'] ?? ''));
                if ($webQuery === '') {
                    return ['ok' => false, 'reason' => 'missing_query', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $search = chat_web_search_query_with_status($webQuery, !empty($context['degraded_mode']));
                $searchResults = (array)($search['results'] ?? []);
                $searchStatus = (array)($search['status'] ?? []);
                $searchLines = [];
                foreach ($searchResults as $row) {
                    if (!is_array($row)) { continue; }
                    $searchLines[] = trim((string)($row['title'] ?? '')) . ' | ' . (string)($row['url'] ?? '');
                    if (count($searchLines) >= 8) { break; }
                }
                return [
                    'ok' => true,
                    'reason' => (string)($searchStatus['search_state'] ?? 'SEARCH_ATTEMPTED'),
                    'result' => [
                        'query' => $webQuery,
                        'result_count' => count($searchResults),
                        'status' => $searchStatus,
                    ],
                    'stdout' => implode("\n", $searchLines),
                    // verification_method: result_count_and_retrieval_flags. A search
                    // that returned nothing is an honest failure, not a success.
                    'verified' => !empty($searchStatus['result_verified']),
                ];

            case 'web.retrieve':
                if (!function_exists('chat_web_fetch_html')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $webUrl = trim((string)($args['url'] ?? ''));
                if ($webUrl === '') {
                    return ['ok' => false, 'reason' => 'missing_url', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $fetchTimeout = max(3, min(20, (int)($context['timeout_sec'] ?? 8)));
                $page = chat_web_fetch_html($webUrl, $fetchTimeout);
                if ($page === null) {
                    return ['ok' => false, 'reason' => 'fetch_denied_or_failed', 'result' => ['url' => $webUrl], 'stdout' => '', 'verified' => false];
                }
                $pageBody = (string)($page['body'] ?? '');
                return [
                    'ok' => true,
                    'reason' => 'retrieved',
                    'result' => [
                        'url' => (string)($page['url'] ?? $webUrl),
                        'http_code' => (int)($page['http_code'] ?? 0),
                        'bytes' => strlen($pageBody),
                        'sha256' => $pageBody === '' ? '' : hash('sha256', $pageBody),
                    ],
                    'stdout' => substr($pageBody, 0, 20000),
                    // verification_method: retrieved_content_hash. Only a body we
                    // actually received can be verified.
                    'verified' => $pageBody !== '',
                ];

            case 'web.verify_source':
                $candidates = $args['source_candidates'] ?? null;
                if (!is_array($candidates) || $candidates === []) {
                    return ['ok' => false, 'reason' => 'missing_source_candidates', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                // Ground the check in sources this run actually retrieved. Without
                // that, nothing can be honestly called verified.
                $retrievedSources = [];
                foreach ((array)($context['webSearchResults'] ?? []) as $row) {
                    if (!is_array($row)) { continue; }
                    $rowUrl = trim((string)($row['url'] ?? $row['link'] ?? ''));
                    if ($rowUrl !== '') { $retrievedSources[$rowUrl] = true; }
                }
                $checkedCount = 0;
                $citableCount = 0;
                $unretrieved = [];
                foreach ($candidates as $candidate) {
                    $candidateUrl = is_array($candidate)
                        ? trim((string)($candidate['url'] ?? $candidate['link'] ?? ''))
                        : trim((string)$candidate);
                    if ($candidateUrl === '') { continue; }
                    $checkedCount++;
                    if (isset($retrievedSources[$candidateUrl])) {
                        $citableCount++;
                    } else {
                        $unretrieved[] = $candidateUrl;
                    }
                }
                return [
                    'ok' => true,
                    'reason' => $checkedCount === 0 ? 'no_usable_candidates' : 'candidates_checked',
                    'result' => [
                        'checked' => $checkedCount,
                        'retrieved_and_citable' => $citableCount,
                        'unretrieved' => $unretrieved,
                        'retrieved_source_count' => count($retrievedSources),
                    ],
                    'stdout' => $citableCount . '/' . $checkedCount . ' candidate source(s) were actually retrieved this run.',
                    // Verified only when every candidate is backed by a retrieved
                    // source. Anything weaker would be a false citation guarantee.
                    'verified' => ($checkedCount > 0 && $citableCount === $checkedCount),
                ];

            case 'database.query':
                if (!function_exists('lyra_db_query_execute')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $queryRun = lyra_db_query_execute((string)($args['query'] ?? ''), [
                    'timeout_sec' => (int)($context['timeout_sec'] ?? 8),
                    'identity' => (array)($context['tenant_identity'] ?? []),
                ]);
                return [
                    'ok' => !empty($queryRun['ok']),
                    'reason' => (string)($queryRun['reason'] ?? 'unknown'),
                    'result' => (array)($queryRun['result'] ?? []),
                    'stdout' => (string)($queryRun['stdout'] ?? ''),
                    'verified' => !empty($queryRun['verified']),
                ];

            case 'server.inspect':
                if (!function_exists('lyra_server_inspect')) {
                    return ['ok' => false, 'reason' => 'executor_missing', 'result' => [], 'stdout' => '', 'verified' => false];
                }
                $inspectRun = lyra_server_inspect((string)($args['target'] ?? ''));
                return [
                    'ok' => !empty($inspectRun['ok']),
                    'reason' => (string)($inspectRun['reason'] ?? 'unknown'),
                    'result' => (array)($inspectRun['result'] ?? []),
                    'stdout' => (string)($inspectRun['stdout'] ?? ''),
                    'verified' => !empty($inspectRun['verified']),
                ];

            default:
                return ['ok' => false, 'reason' => 'no_executor_for_capability', 'result' => [], 'stdout' => '', 'verified' => false];
        }
    }
}

if (!function_exists('chat_capability_has_executor')) {
    /**
     * Whether an executor branch exists for this capability id.
     *
     * Kept next to chat_capability_execute so the list cannot drift away from the
     * switch it describes. The manifest refuses to advertise anything absent from
     * here: a tool the model is told it has, but which can only fail, teaches the
     * model to narrate capability it does not possess.
     */
    function chat_capability_has_executor(string $capabilityId): bool {
        static $executable = null;
        if ($executable === null) {
            $executable = array_fill_keys([
                'filesystem.read',
                'filesystem.write',
                'shell.execute',
                'web.search',
                'web.retrieve',
                'web.verify_source',
                'database.query',
                'server.inspect',
            ], true);
        }
        return isset($executable[$capabilityId]);
    }
}

if (!function_exists('chat_capability_dispatch')) {

    /**
     * Authorize, possibly defer for approval, and execute.
     *
     * Returns a decision-shaped result:
     *   status: SUCCEEDED | FAILED | REFUSED | PENDING_CONFIRMATION
     *   executed: whether anything actually ran
     *   record: execution record for persistence (always present)
     */
    function chat_capability_dispatch(string $capabilityId, array $args, array $context = []): array {
        $started = microtime(true);

        // Audit: the runtime is about to attempt this capability. Emitted BEFORE
        // the gates so that a refusal is still visible as an attempt; the outcome
        // event is emitted by chat_capability_execution_record() below, which every
        // dispatch path calls.
        if (function_exists('chat_os_event_emit')) {
            chat_os_event_emit('tool.called', [
                'input_summary' => function_exists('chat_capability_input_summary')
                    ? chat_capability_input_summary($capabilityId, $args)
                    : '',
            ], [
                'component' => 'capability_executor',
                'capability_id' => $capabilityId,
                'tool_name' => $capabilityId,
                'task_id' => (string)($context['task_id'] ?? ''),
                'request_id' => (string)($context['request_id'] ?? ''),
                'db' => ($context['db'] ?? null),
            ]);
        }

        $runtime = (array)($context['runtime'] ?? []);
        $capability = chat_capability_registry_lookup($capabilityId, $runtime);

        $base = [
            'capability_id' => $capabilityId,
            'status' => 'REFUSED',
            'ok' => false,
            'executed' => false,
            'reason' => '',
            'result' => [],
            'stdout' => '',
            'pending_action_id' => null,
            'record' => [],
        ];

        if ($capability === null) {
            $base['reason'] = 'unknown_capability';
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'NOT_EXECUTED', 'UNAVAILABLE', false, false,
                chat_capability_input_summary($capabilityId, $args),
                '', 'unknown capability', false, 0, $context
            );
            return $base;
        }

        // 1. enabled gate. A disabled capability is never attempted.
        if (empty($capability['enabled'])) {
            $base['reason'] = 'capability_disabled';
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'NOT_EXECUTED', 'UNAVAILABLE', false, false,
                chat_capability_input_summary($capabilityId, $args),
                '', 'capability is disabled in the registry', false, 0, $context,
                ['disabled_reason' => 'registry_enabled_false']
            );
            return $base;
        }

        // 2. resource gate.
        $resourceState = chat_capability_resource_state($capabilityId, $context);
        $resource = ['state' => $resourceState, 'resource_id' => function_exists('chat_os_primary_resource_for_capability')
            ? chat_os_primary_resource_for_capability($capabilityId) : ''];
        if ($resourceState !== 'AVAILABLE') {
            $base['reason'] = 'resource_unavailable';
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'NOT_EXECUTED', 'NOT_PROVIDED', false, false,
                chat_capability_input_summary($capabilityId, $args),
                '', 'resource state ' . $resourceState, false, 0, $context
            );
            return $base;
        }

        // 3. argument validation.
        $validation = chat_capability_validate_args($capability, $args);
        if (!$validation['valid']) {
            $base['reason'] = 'invalid_arguments:' . implode(',', $validation['errors']);
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'FAILED', 'AUTHORIZED', true, false,
                chat_capability_input_summary($capabilityId, $args),
                '', 'invalid arguments', false, 0, $context
            );
            return $base;
        }
        $args = $validation['args'];
        $summary = chat_capability_input_summary($capabilityId, $args);

        // 4. authorization. APPROVAL_REQUIRED is binding, not advisory.
        $authorization = ['state' => 'AUTHORIZED', 'approved' => true, 'approval_required' => false];
        if (function_exists('chat_os_authorization_decision')) {
            $authorization = chat_os_authorization_decision($capability, $resource, $context);
        }
        $authState = (string)($authorization['state'] ?? 'AUTHORIZED');

        // Approval floor. chat_os_authorization_decision() returns
        // approved=true for a dev session *before* it evaluates
        // requires_confirmation, so a developer session would otherwise run a
        // CRITICAL capability silently. Holding a developer session should not
        // be sufficient authorisation to execute arbitrary commands, so that
        // class always confirms. HIGH-risk capabilities still follow policy,
        // which keeps ordinary file writes frictionless for the owner.
        $riskLevel = strtoupper((string)($capability['risk_level'] ?? 'LOW'));
        $criticalFloor = ($riskLevel === 'CRITICAL') && empty($context['approval_granted']);

        if ($criticalFloor
            || $authState === 'APPROVAL_REQUIRED'
            || (!empty($authorization['approval_required']) && empty($authorization['approved']))) {
            $pendingId = null;
            if (function_exists('chat_pending_action_create')) {
                $pendingId = chat_pending_action_create($capabilityId, $args, $summary, $context);
            }
            $base['status'] = 'PENDING_CONFIRMATION';
            $base['reason'] = 'approval_required';
            $base['pending_action_id'] = $pendingId;
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'NOT_EXECUTED', 'APPROVAL_REQUIRED', false, false,
                $summary, '', 'awaiting user confirmation', false, 0, $context,
                ['pending_action_id' => $pendingId]
            );
            return $base;
        }

        if ($authState !== 'AUTHORIZED' && empty($authorization['approved'])) {
            $base['reason'] = 'not_authorized:' . $authState;
            $base['record'] = chat_capability_execution_record(
                $capabilityId, 'NOT_EXECUTED', $authState, false, false,
                $summary, '', 'authorization state ' . $authState, false, 0, $context
            );
            return $base;
        }

        // 5. execute.
        $outcome = chat_capability_execute($capabilityId, $args, $context);
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $ok = !empty($outcome['ok']);

        $base['status'] = $ok ? 'SUCCEEDED' : 'FAILED';
        $base['ok'] = $ok;
        $base['executed'] = true;
        $base['reason'] = (string)($outcome['reason'] ?? '');
        $base['result'] = (array)($outcome['result'] ?? []);
        $base['stdout'] = (string)($outcome['stdout'] ?? '');
        $base['record'] = chat_capability_execution_record(
            $capabilityId,
            $ok ? 'SUCCEEDED' : 'FAILED',
            'AUTHORIZED',
            true,
            $ok,
            $summary,
            $base['stdout'],
            $ok ? null : (string)($outcome['reason'] ?? 'execution failed'),
            !empty($outcome['verified']),
            $durationMs,
            $context
        );
        return $base;
    }
}

if (!function_exists('chat_capability_tool_manifest')) {
    /**
     * The capabilities a model may legitimately ask for right now.
     *
     * Only enabled + resource-available + permitted capabilities are listed.
     * Advertising anything else would invite the model to claim a tool it cannot
     * use, which is exactly the dishonesty the verification layer penalises.
     */
    function chat_capability_tool_manifest(array $context = []): array {
        $runtime = (array)($context['runtime'] ?? []);
        if (!function_exists('chat_os_capability_registry')) {
            return [];
        }
        $registry = chat_os_capability_registry($runtime);
        $granted = (array)($context['granted_permissions'] ?? []);
        $isDev = !empty($context['is_dev_user']);
        $manifest = [];

        foreach ($registry as $id => $capability) {
            if (empty($capability['enabled'])) {
                continue;
            }
            if (chat_capability_resource_state((string)$id, $context) !== 'AVAILABLE') {
                continue;
            }
            $required = (array)($capability['required_permissions'] ?? []);
            if ($required !== [] && !$isDev) {
                $missing = array_diff($required, $granted);
                if ($missing !== []) {
                    continue;
                }
            }
            // model.* capabilities are internal routing, not user-callable tools.
            if (strpos((string)$id, 'model.') === 0) {
                continue;
            }
            // Never advertise a capability that has no executor branch.
            if (!chat_capability_has_executor((string)$id)) {
                continue;
            }
            $manifest[] = [
                'capability_id' => (string)$id,
                'name' => (string)($capability['name'] ?? $id),
                'description' => (string)($capability['description'] ?? ''),
                'risk_level' => (string)($capability['risk_level'] ?? 'LOW'),
                'requires_confirmation' => !empty($capability['requires_confirmation']),
                'input_schema' => (array)($capability['input_schema'] ?? []),
            ];
        }
        return $manifest;
    }
}
