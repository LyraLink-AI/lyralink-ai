<?php
/**
 * Approval gate for consequential capabilities.
 *
 * os_core already decides when approval is required (authorization state
 * APPROVAL_REQUIRED), but nothing enforced it, so the decision was decorative.
 * This file is the enforcement: a consequential action is parked as a pending
 * action, and only executes once the user confirms it in a later turn.
 *
 * Design constraints:
 *   - Single use. Claiming a pending action is one atomic UPDATE whose affected
 *     row count decides the winner, so a double-submit cannot execute twice.
 *   - Bound to the session and user. A pending action cannot be approved from a
 *     different conversation.
 *   - Expiring. An unanswered approval does not stay executable forever.
 *   - Orderable. Ordering is by `seq`, a monotonic auto-increment. Ordering by
 *     created_at was a real defect: it is second-precision, so two actions
 *     created in the same second (which the tool loop permits, up to three per
 *     turn) tied and the winner was arbitrary. A bare "approve" could then
 *     execute a different action than the one the user was shown.
 *   - Unambiguous. If more than one action is parked, a bare confirmation is
 *     refused and the explicit id is required. Guessing is not acceptable for a
 *     consequential action.
 *   - Auditable. Creation, approval, denial and expiry are all recorded.
 */

if (!function_exists('chat_pending_actions_table_sql')) {
    function chat_pending_actions_table_sql(): string {
        return "CREATE TABLE IF NOT EXISTS chat_pending_actions (
            seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id VARCHAR(40) NOT NULL PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL DEFAULT '',
            task_id VARCHAR(64) NOT NULL DEFAULT '',
            session_id VARCHAR(191) NOT NULL DEFAULT '',
            user_id VARCHAR(120) NOT NULL DEFAULT '',
            capability_id VARCHAR(64) NOT NULL,
            resource_id VARCHAR(64) NOT NULL DEFAULT '',
            args_json LONGTEXT NOT NULL,
            input_summary TEXT NOT NULL,
            risk_level VARCHAR(40) NOT NULL DEFAULT 'HIGH',
            requires_confirmation TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            resolved_by VARCHAR(120) NOT NULL DEFAULT '',
            execution_id VARCHAR(64) NOT NULL DEFAULT '',
            result_json LONGTEXT NULL,
            error_text TEXT NULL,
            UNIQUE KEY uniq_seq (seq),
            KEY idx_session_status (session_id, status),
            KEY idx_status_expires (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
}

if (!function_exists('chat_pending_actions_ensure_table')) {
    function chat_pending_actions_ensure_table($db): bool {
        if (!($db instanceof mysqli)) {
            return false;
        }

        $exists = $db->query("SHOW TABLES LIKE 'chat_pending_actions'");
        $present = ($exists instanceof mysqli_result && $exists->num_rows > 0);
        if ($exists instanceof mysqli_result) {
            $exists->free();
        }

        if (!$present) {
            return (bool)$db->query(chat_pending_actions_table_sql());
        }

        // Migrate tables created before ordering became deterministic.
        $col = $db->query("SHOW COLUMNS FROM chat_pending_actions LIKE 'seq'");
        $hasSeq = ($col instanceof mysqli_result && $col->num_rows > 0);
        if ($col instanceof mysqli_result) {
            $col->free();
        }
        if (!$hasSeq) {
            $db->query(
                "ALTER TABLE chat_pending_actions
                 ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                 ADD UNIQUE KEY uniq_seq (seq)"
            );
        }
        return true;
    }
}

if (!function_exists('chat_pending_action_create')) {
    /**
     * Park a consequential action awaiting confirmation.
     * Returns the public approval id, or null when it could not be recorded.
     */
    function chat_pending_action_create(string $capabilityId, array $args, string $inputSummary, array $context = []): ?string {
        $db = $context['db'] ?? null;
        if (!($db instanceof mysqli)) {
            return null;
        }
        if (!chat_pending_actions_ensure_table($db)) {
            return null;
        }

        $id = 'ap' . bin2hex(random_bytes(8));
        $ttl = (int)($context['approval_ttl_sec'] ?? 900);
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + max(60, $ttl));

        $argsJson = function_exists('chat_os_encode_json') ? chat_os_encode_json($args) : json_encode($args);
        $sessionId = (string)($context['session_id'] ?? '');
        $userId = (string)($context['user_id'] ?? '');
        $requestId = (string)($context['request_id'] ?? '');
        $taskId = (string)($context['task_id'] ?? '');
        $resourceId = function_exists('chat_os_primary_resource_for_capability')
            ? chat_os_primary_resource_for_capability($capabilityId) : '';
        $risk = (string)($context['risk_level'] ?? 'HIGH');

        $stmt = $db->prepare(
            "INSERT INTO chat_pending_actions
             (id, request_id, task_id, session_id, user_id, capability_id, resource_id,
              args_json, input_summary, risk_level, requires_confirmation, status, created_at, expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,1,'pending',?,?)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param(
            'ssssssssssss',
            $id, $requestId, $taskId, $sessionId, $userId, $capabilityId, $resourceId,
            $argsJson, $inputSummary, $risk, $now, $expires
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? $id : null;
    }
}

if (!function_exists('chat_pending_actions_list')) {
    /**
     * All still-valid pending actions for a session, newest first by `seq`.
     * Scoped by session AND user so one account cannot clear another's queue.
     */
    function chat_pending_actions_list($db, string $sessionId, string $userId = ''): array {
        if (!($db instanceof mysqli) || $sessionId === '') {
            return [];
        }
        if (!chat_pending_actions_ensure_table($db)) {
            return [];
        }
        chat_pending_action_expire($db);

        if ($userId !== '') {
            $stmt = $db->prepare(
                "SELECT * FROM chat_pending_actions
                 WHERE session_id = ? AND user_id = ? AND status = 'pending' AND expires_at > UTC_TIMESTAMP()
                 ORDER BY seq DESC"
            );
            if (!$stmt) { return []; }
            $stmt->bind_param('ss', $sessionId, $userId);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM chat_pending_actions
                 WHERE session_id = ? AND status = 'pending' AND expires_at > UTC_TIMESTAMP()
                 ORDER BY seq DESC"
            );
            if (!$stmt) { return []; }
            $stmt->bind_param('s', $sessionId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $row['args'] = json_decode((string)$row['args_json'], true) ?: [];
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('chat_pending_action_latest')) {
    function chat_pending_action_latest($db, string $sessionId, string $userId): ?array {
        $rows = chat_pending_actions_list($db, $sessionId, $userId);
        return $rows === [] ? null : $rows[0];
    }
}

if (!function_exists('chat_pending_action_latest_for_session')) {
    function chat_pending_action_latest_for_session($db, string $sessionId): ?array {
        $rows = chat_pending_actions_list($db, $sessionId, '');
        return $rows === [] ? null : $rows[0];
    }
}

if (!function_exists('chat_pending_action_claim')) {
    /**
     * Atomically claim a pending action for execution.
     *
     * The WHERE clause carries the state transition, so exactly one caller can
     * win even if the approval arrives twice (double-click, retry, replay).
     * Losing the race is reported, never silently ignored.
     */
    function chat_pending_action_claim($db, string $id, string $sessionId, string $userId): array {
        if (!($db instanceof mysqli)) {
            return ['ok' => false, 'reason' => 'no_database'];
        }
        if (!chat_pending_actions_ensure_table($db)) {
            return ['ok' => false, 'reason' => 'table_unavailable'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "UPDATE chat_pending_actions
             SET status = 'executing', resolved_at = ?, resolved_by = ?
             WHERE id = ? AND session_id = ? AND user_id = ?
               AND status = 'pending' AND expires_at > UTC_TIMESTAMP()"
        );
        if (!$stmt) {
            return ['ok' => false, 'reason' => 'prepare_failed'];
        }
        $stmt->bind_param('sssss', $now, $userId, $id, $sessionId, $userId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();

        if (!$claimed) {
            return ['ok' => false, 'reason' => 'not_claimable'];
        }

        $stmt = $db->prepare("SELECT * FROM chat_pending_actions WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'reason' => 'prepare_failed'];
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'reason' => 'vanished'];
        }
        $row['args'] = json_decode((string)$row['args_json'], true) ?: [];
        return ['ok' => true, 'action' => $row];
    }
}

if (!function_exists('chat_pending_action_finish')) {
    function chat_pending_action_finish($db, string $id, string $status, array $result, string $executionId = '', string $error = ''): bool {
        if (!($db instanceof mysqli)) {
            return false;
        }
        $status = in_array($status, ['executed', 'failed', 'denied', 'expired'], true) ? $status : 'failed';
        $now = gmdate('Y-m-d H:i:s');
        $resultJson = function_exists('chat_os_encode_json') ? chat_os_encode_json($result) : json_encode($result);
        $stmt = $db->prepare(
            "UPDATE chat_pending_actions
             SET status = ?, result_json = ?, execution_id = ?, error_text = ?, resolved_at = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssssss', $status, $resultJson, $executionId, $error, $now, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('chat_pending_action_expire')) {
    function chat_pending_action_expire($db): int {
        if (!($db instanceof mysqli)) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "UPDATE chat_pending_actions
             SET status = 'expired', resolved_at = ?
             WHERE status = 'pending' AND expires_at <= UTC_TIMESTAMP()"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return (int)$n;
    }
}

if (!function_exists('chat_approval_parse_user_message')) {
    /**
     * Detect a confirmation in a follow-up user message.
     *
     * Deliberately narrow: it requires a standalone affirmative word, so a
     * message that merely discusses approval ("I approved this last week")
     * does not clear the gate. This is the only place user text can authorise a
     * consequential action, and it is never fed the model's output.
     */
    function chat_approval_parse_user_message(string $message): array {
        $text = strtolower(trim((string)$message));
        if ($text === '' || strlen($text) > 200) {
            return ['intent' => 'none', 'id' => ''];
        }

        $id = '';
        if (preg_match('/\b(ap[0-9a-f]{16})\b/', $text, $m) === 1) {
            $id = $m[1];
        }

        $affirm = '/^(yes|yep|yeah|ok|okay|sure|approve|approved|confirm|confirmed|do it|go ahead|proceed|allowed|allow)\b/';
        $deny = '/^(no|nope|cancel|deny|denied|reject|stop|abort|don\'?t)\b/';

        if (preg_match($affirm, $text) === 1) {
            return ['intent' => 'approve', 'id' => $id];
        }
        if (preg_match($deny, $text) === 1) {
            return ['intent' => 'deny', 'id' => $id];
        }
        return ['intent' => 'none', 'id' => ''];
    }
}

if (!function_exists('chat_approval_resolve_from_message')) {
    /**
     * Resolve a pending action from a user turn, then optionally execute it.
     *
     * Returns null when there is nothing to do. Execution reuses the dispatcher
     * with approval_granted set, so direct and approved runs share one code path.
     */
    function chat_approval_resolve_from_message(string $message, array $context = []): ?array {
        $db = $context['db'] ?? null;
        if (!($db instanceof mysqli)) {
            return null;
        }
        $parsed = chat_approval_parse_user_message($message);
        if ($parsed['intent'] === 'none') {
            return null;
        }

        $sessionId = (string)($context['session_id'] ?? '');
        $userId = (string)($context['user_id'] ?? '');

        if ($parsed['id'] !== '') {
            $claim = chat_pending_action_claim($db, $parsed['id'], $sessionId, $userId);
        } else {
            $pending = chat_pending_actions_list($db, $sessionId, $userId);
            if ($pending === [] && $userId !== '') {
                $pending = chat_pending_actions_list($db, $sessionId, '');
            }
            if ($pending === []) {
                return ['intent' => $parsed['intent'], 'handled' => false, 'reason' => 'no_pending_action'];
            }
            if (count($pending) > 1) {
                // Never guess between consequential actions. Ask for the id.
                $ids = array_map(static fn($row) => (string)$row['id'], array_slice($pending, 0, 5));
                return [
                    'intent' => $parsed['intent'],
                    'handled' => false,
                    'reason' => 'ambiguous_multiple_pending',
                    'reply' => 'There is more than one action waiting on you, so I will not guess. '
                        . 'Reply "approve ' . $ids[0] . '" (or the id you mean). Waiting: '
                        . implode(', ', $ids) . '. Nothing has been executed.',
                ];
            }
            $only = $pending[0];
            $claim = chat_pending_action_claim($db, (string)$only['id'], $sessionId, (string)$only['user_id']);
        }

        if (empty($claim['ok'])) {
            return ['intent' => $parsed['intent'], 'handled' => false, 'reason' => (string)($claim['reason'] ?? 'claim_failed')];
        }

        $action = (array)$claim['action'];
        if ($parsed['intent'] === 'deny') {
            chat_pending_action_finish($db, (string)$action['id'], 'denied', ['denied' => true]);
            return [
                'intent' => 'deny',
                'handled' => true,
                'capability_id' => (string)$action['capability_id'],
                'reply' => 'Cancelled. Nothing was executed.',
            ];
        }

        $execContext = $context;
        $execContext['approval_granted'] = true;
        $execContext['request_id'] = (string)($action['request_id'] ?? ($context['request_id'] ?? ''));
        $execContext['task_id'] = (string)($action['task_id'] ?? ($context['task_id'] ?? ''));

        $dispatch = chat_capability_dispatch(
            (string)$action['capability_id'],
            (array)($action['args'] ?? []),
            $execContext
        );

        $record = (array)($dispatch['record'] ?? []);
        chat_pending_action_finish(
            $db,
            (string)$action['id'],
            !empty($dispatch['ok']) ? 'executed' : 'failed',
            (array)($dispatch['result'] ?? []),
            (string)($record['execution_id'] ?? ''),
            !empty($dispatch['ok']) ? '' : (string)($dispatch['reason'] ?? '')
        );

        return [
            'intent' => 'approve',
            'handled' => true,
            'capability_id' => (string)$action['capability_id'],
            'dispatch' => $dispatch,
            'record' => $record,
            'action_id' => (string)$action['id'],
        ];
    }
}
