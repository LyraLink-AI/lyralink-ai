<?php
/**
 * Lyralink OS — Event Bus
 * ---------------------------------------------------------------------------
 * Reference: "Lyralink OS — Master Architecture v0.2" section 33 (Event Bus),
 * plus design principles 12 (failure recovery is first-class), 20 (every
 * autonomous capability must be observable and auditable) and 21 (prefer
 * measurable capabilities over marketing claims).
 *
 * WHY THIS EXISTS
 *   The control plane already persists TASKS (ai_os_tasks) and EXECUTIONS
 *   (ai_os_executions). What it could not express was a single ordered stream
 *   answering "why did this happen": which model was selected, which tool ran,
 *   which permission was refused, whether verification passed. Without that,
 *   "every autonomous capability is auditable" is an assertion rather than a
 *   property of the system.
 *
 * DESIGN CONSTRAINTS (deliberate, each one learned from a real defect)
 *   1. Emitting must NEVER break the request that produced the event. Every
 *      entry point is wrapped in try/catch and returns null on any failure.
 *   2. Ordering is by the AUTO_INCREMENT `id`, never by created_at. A
 *      second-precision DATETIME ties when several events land in the same
 *      second, and an earlier bug in the approval gate showed exactly how that
 *      produces non-deterministic selection.
 *   3. Secrets are redacted before persistence. An audit trail that leaks
 *      credentials is worse than no audit trail at all.
 *   4. Payloads are bounded. An unbounded stdout dump in an event row would
 *      silently bloat the table.
 *   5. MySQL is optional. If the connection fails, the JSONL mirror still
 *      records the event, so the trail degrades but never vanishes.
 *
 * Kill switch: LYRA_OS_EVENTS=0
 */

if (!function_exists('chat_os_event_table')) {
    function chat_os_event_table(): string
    {
        return 'ai_os_events';
    }
}

if (!function_exists('chat_os_events_enabled')) {
    function chat_os_events_enabled(): bool
    {
        $v = '';
        if (function_exists('api_get_secret')) {
            $v = (string) api_get_secret('LYRA_OS_EVENTS', '');
        }
        if (trim($v) === '') {
            $env = getenv('LYRA_OS_EVENTS');
            $v = is_string($env) ? $env : '1';
        }
        return trim($v) !== '0';
    }
}

if (!function_exists('chat_os_event_canonical_types')) {
    /**
     * The event vocabulary from architecture section 33. A type outside this
     * list is still accepted if it is properly namespaced, so the bus does not
     * block new instrumentation, but callers should prefer these names.
     */
    function chat_os_event_canonical_types(): array
    {
        return [
            'task.created', 'task.started', 'task.completed', 'task.failed',
            'agent.started', 'agent.completed', 'agent.failed',
            'model.selected', 'model.started', 'model.completed', 'model.failed',
            'tool.called', 'tool.completed', 'tool.failed',
            'permission.requested', 'permission.granted', 'permission.denied',
            'verification.started', 'verification.passed', 'verification.failed',
            'recovery.started', 'recovery.completed',
            'memory.written', 'scheduler.triggered',
        ];
    }
}

if (!function_exists('chat_os_event_severities')) {
    function chat_os_event_severities(): array
    {
        return ['info', 'warning', 'error', 'critical'];
    }
}

if (!function_exists('chat_os_event_default_severity')) {
    function chat_os_event_default_severity(string $type): string
    {
        if (substr($type, -7) === '.failed') {
            return 'error';
        }
        if (substr($type, -7) === '.denied') {
            return 'warning';
        }
        if (in_array($type, ['recovery.started', 'permission.requested'], true)) {
            return 'warning';
        }
        return 'info';
    }
}

if (!function_exists('chat_os_event_connect')) {
    /**
     * Best-effort shared connection. Returns null rather than throwing so that
     * an event emit can never take down the request that triggered it.
     */
    function chat_os_event_connect(): ?mysqli
    {
        static $cached = null;
        static $attempted = false;

        if ($attempted) {
            return $cached;
        }
        $attempted = true;

        if (!class_exists('mysqli')) {
            return null;
        }

        $cfg = ['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud'];
        if (function_exists('api_db_config')) {
            $provided = api_db_config($cfg);
            if (is_array($provided)) {
                $cfg = array_merge($cfg, $provided);
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $db = @new mysqli((string) $cfg['host'], (string) $cfg['user'], (string) $cfg['pass'], (string) $cfg['name']);
            if (!($db instanceof mysqli) || $db->connect_errno) {
                return null;
            }
            @$db->set_charset('utf8mb4');
            $cached = $db;
        } catch (Throwable $e) {
            return null;
        }

        return $cached;
    }
}

if (!function_exists('chat_os_event_ensure_schema')) {
    function chat_os_event_ensure_schema(mysqli $db): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }

        $table = chat_os_event_table();
        // `id` is the ordering authority (see constraint 2). created_at is for
        // humans and range queries only - never for sequencing.
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            component VARCHAR(64) DEFAULT NULL,
            task_id VARCHAR(64) DEFAULT NULL,
            parent_task_id VARCHAR(64) DEFAULT NULL,
            request_id VARCHAR(64) DEFAULT NULL,
            user_id VARCHAR(120) DEFAULT NULL,
            session_id VARCHAR(191) DEFAULT NULL,
            capability_id VARCHAR(64) DEFAULT NULL,
            model_id VARCHAR(120) DEFAULT NULL,
            tool_name VARCHAR(64) DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event_id (event_id),
            KEY idx_type_id (event_type, id),
            KEY idx_task_id (task_id, id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            if (!@$db->query($sql)) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }

        $done = true;
        return true;
    }
}

if (!function_exists('chat_os_event_redact')) {
    /**
     * Recursively replace values whose KEY looks secret-bearing. Keys are
     * matched, not values, so ordinary text mentioning "password" survives.
     */
    function chat_os_event_redact(array $payload, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['_truncated' => 'max_depth'];
        }

        $pattern = '/(pass(word)?|secret|token|api[_-]?key|authorization|cookie|credential|private[_-]?key|bearer|session[_-]?id)/i';
        // Keys that merely CONTAIN a trigger word but carry no secret. Without this
        // allowlist the pattern above matches 'authorization_state' and redacts the
        // most valuable field in the audit record: whether an action was AUTHORIZED,
        // UNAUTHORIZED or APPROVAL_REQUIRED. Observed live 2026-09-22.
        $notSecret = '/^(authorization_state|authorization_status|auth_state|permission_state|token_budget|token_count)$/i'; // EB_REDACTION_ALLOWLIST_20260922
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key) === 1 && preg_match($notSecret, $key) !== 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = chat_os_event_redact($value, $depth + 1);
                continue;
            }
            if (is_object($value)) {
                $out[$key] = '[object ' . get_class($value) . ']';
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}

if (!function_exists('chat_os_event_fail')) {
    /**
     * A swallowed exception here must still leave a trace. An audit subsystem
     * that fails silently is indistinguishable from one that works, which is
     * exactly the failure mode this module exists to prevent.
     */
    function chat_os_event_fail(string $where, Throwable $e): void
    {
        @error_log('lyra_event_bus[' . $where . ']: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

if (!function_exists('chat_os_event_emit')) {
    /**
     * Record one event. Returns the event id on success, null on any failure.
     * Never throws.
     *
     * @param array $payload Event-specific detail (redacted + bounded).
     * @param array $ctx     Context: task_id, parent_task_id, request_id, user_id,
     *                       session_id, capability_id, model_id, tool_name,
     *                       duration_ms, component, severity, db (optional mysqli).
     */
    function chat_os_event_emit(string $type, array $payload = [], array $ctx = []): ?string
    {
        try {
            if (!chat_os_events_enabled()) {
                return null;
            }

            // Normalise BEFORE validating, deliberately. If "Task.Created" and
            // "task.created" were both storable they would be counted as two
            // different event types and any filter or summary would silently
            // under-report. Normalising keeps the vocabulary closed.
            $type = strtolower(trim($type));
            // Namespaced lower-case dotted name only. Rejects "", "task",
            // "task..created", "task/created" and path-like input.
            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $type) !== 1) {
                return null;
            }

            $eventId = function_exists('chat_os_uuid')
                ? (string) chat_os_uuid()
                : bin2hex(random_bytes(16));

            $severity = strtolower(trim((string) ($ctx['severity'] ?? '')));
            if (!in_array($severity, chat_os_event_severities(), true)) {
                $severity = chat_os_event_default_severity($type);
            }

            $payload = chat_os_event_redact($payload);
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($encoded)) {
                $encoded = '{}';
            }
            $maxBytes = 8000;
            if (strlen($encoded) > $maxBytes) {
                $encoded = json_encode([
                    '_truncated' => true,
                    '_original_bytes' => strlen($encoded),
                    '_preview' => substr($encoded, 0, $maxBytes),
                ], JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    $encoded = '{"_truncated":true}';
                }
            }

            // gmdate, not chat_os_db_datetime(): that helper is an ISO-string
            // CONVERTER (chat_os_db_datetime(?string $iso): ?string), not a clock.
            // Calling it with no argument threw ArgumentCountError, which the
            // surrounding catch then swallowed. os_core.php:913 uses gmdate for
            // "now", so this matches the existing convention (UTC, TZ-independent).
            $now = gmdate('Y-m-d H:i:s');

            $pick = static function (string $k) use ($ctx): ?string {
                $v = $ctx[$k] ?? null;
                if (!is_string($v)) {
                    return null;
                }
                $v = trim($v);
                return $v === '' ? null : mb_substr($v, 0, 120);
            };

            $duration = isset($ctx['duration_ms']) && is_numeric($ctx['duration_ms'])
                ? max(0, (int) $ctx['duration_ms'])
                : null;

            $row = [
                'event_id' => $eventId,
                'event_type' => mb_substr($type, 0, 64),
                'severity' => $severity,
                'component' => $pick('component'),
                'task_id' => $pick('task_id'),
                'parent_task_id' => $pick('parent_task_id'),
                'request_id' => $pick('request_id'),
                'user_id' => $pick('user_id'),
                'session_id' => $pick('session_id'),
                'capability_id' => $pick('capability_id'),
                'model_id' => $pick('model_id'),
                'tool_name' => $pick('tool_name'),
                'duration_ms' => $duration,
                'created_at' => $now,
            ];

            // Mirror first: it has no connection dependency, so a DB outage
            // still leaves a trail (constraint 5).
            if (function_exists('chat_os_append_jsonl') && function_exists('chat_os_runtime_storage_dir')) {
                $mirror = chat_os_runtime_storage_dir() . '/events-' . gmdate('Ymd') . '.jsonl';
                chat_os_append_jsonl($mirror, $row + ['payload' => $payload]);
            }

            // Prefer a handle the caller already holds (chat.php has $db). That avoids
            // opening a second connection per request just to write an audit row.
            $db = $ctx['db'] ?? null;
            if (!($db instanceof mysqli)) {
                $db = chat_os_event_connect();
            }
            if ($db instanceof mysqli && chat_os_event_ensure_schema($db)) {
                $table = chat_os_event_table();
                $sql = "INSERT INTO {$table} (
                    event_id, event_type, severity, component, task_id, parent_task_id,
                    request_id, user_id, session_id, capability_id, model_id, tool_name,
                    duration_ms, payload_json, created_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = @$db->prepare($sql);
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param(
                        'ssssssssssssiss',
                        $row['event_id'], $row['event_type'], $row['severity'], $row['component'],
                        $row['task_id'], $row['parent_task_id'], $row['request_id'], $row['user_id'],
                        $row['session_id'], $row['capability_id'], $row['model_id'], $row['tool_name'],
                        $duration, $encoded, $row['created_at']
                    );
                    @$stmt->execute();
                    @$stmt->close();
                }
            }

            return $eventId;
        } catch (Throwable $e) {
            // Observability must never be able to break the observed system,
            // but it must not fail invisibly either.
            chat_os_event_fail('emit', $e);
            return null;
        }
    }
}

if (!function_exists('chat_os_events_recent')) {
    /**
     * Newest-first slice of the stream. Ordering is `id DESC` (constraint 2).
     * Returns [] if the store is unavailable.
     */
    function chat_os_events_recent(int $limit = 50, array $filters = []): array
    {
        $limit = max(1, min(500, $limit));
        $db = chat_os_event_connect();
        if (!($db instanceof mysqli) || !chat_os_event_ensure_schema($db)) {
            return [];
        }

        $table = chat_os_event_table();
        $where = [];
        $params = [];
        $types = '';

        $eq = static function (string $col, $val) use (&$where, &$params, &$types): void {
            if (is_string($val) && trim($val) !== '') {
                $where[] = "{$col} = ?";
                $params[] = trim($val);
                $types .= 's';
            }
        };
        $eq('event_type', $filters['event_type'] ?? null);
        $eq('severity', $filters['severity'] ?? null);
        $eq('task_id', $filters['task_id'] ?? null);
        $eq('component', $filters['component'] ?? null);

        if (!empty($filters['since_id']) && is_numeric($filters['since_id'])) {
            $where[] = 'id > ?';
            $params[] = (int) $filters['since_id'];
            $types .= 'i';
        }

        $sql = "SELECT id, event_id, event_type, severity, component, task_id, parent_task_id,
                       request_id, user_id, capability_id, model_id, tool_name, duration_ms,
                       payload_json, created_at
                FROM {$table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $out = [];
        try {
            $stmt = @$db->prepare($sql);
            if (!($stmt instanceof mysqli_stmt)) {
                chat_os_event_fail('recent.prepare', new RuntimeException((string) $db->error));
                return [];
            }
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            @$stmt->execute();
            $res = @$stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $decoded = json_decode((string) ($r['payload_json'] ?? '{}'), true);
                    $r['payload'] = is_array($decoded) ? $decoded : [];
                    unset($r['payload_json']);
                    $out[] = $r;
                }
            }
            @$stmt->close();
        } catch (Throwable $e) {
            chat_os_event_fail('recent', $e);
            return [];
        }

        return $out;
    }
}

if (!function_exists('chat_os_events_summary')) {
    /**
     * Counts by type and severity for the control-plane status surface.
     */
    function chat_os_events_summary(int $limit = 500): array
    {
        $events = chat_os_events_recent($limit);
        $byType = [];
        $bySeverity = [];

        foreach ($events as $e) {
            $t = (string) ($e['event_type'] ?? '');
            $s = (string) ($e['severity'] ?? 'info');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
            $bySeverity[$s] = ($bySeverity[$s] ?? 0) + 1;
        }
        arsort($byType);
        arsort($bySeverity);

        return [
            'sampled' => count($events),
            'by_type' => $byType,
            'by_severity' => $bySeverity,
            'latest_id' => $events ? (int) ($events[0]['id'] ?? 0) : 0,
            'available' => true,
        ];
    }
}
