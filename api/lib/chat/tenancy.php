<?php
/**
 * Row-level tenancy enforcement for the read-only database executor.
 *
 * The sensitive-table denylist in data_plane.php is damage reduction, not a
 * tenancy boundary: the read-only user can read ALL rows of any non-denylisted
 * table, so `SELECT * FROM conversations` would return every tenant's data.
 *
 * 42 tables in this schema carry a tenant column. This module finds which of them a
 * statement references and requires the statement to constrain each one to the
 * caller's VERIFIED identity. The identity must come from the session, never from
 * the request body: chat.php reads `$input['user_id']`, which a caller controls, so
 * scoping on that value would enforce nothing.
 *
 * Fail-closed by design:
 *   - unauthenticated caller referencing a tenant table  -> refused
 *   - statement references a tenant table without binding -> refused
 *   - parameter placeholders cannot be verified           -> refused
 *   - tenant column unknown to this module                -> refused
 * The developer account may bypass, and every bypass is recorded in the result.
 */

if (!function_exists('chat_tenant_scope_fallback_map')) {
    /**
     * Best-effort map used when information_schema is unreachable. The live map is
     * derived from information_schema so this stays correct as the schema evolves.
     */
    function chat_tenant_scope_fallback_map(): array {
        return [
            'admin_invoices' => 'user_id',
            'ai_os_tasks' => 'user_id',
            'api_keys' => 'user_id',
            'app_store_receipts' => 'user_id',
            'automation_jobs' => 'user_id',
            'automation_runs' => 'user_id',
            'automations' => 'user_id',
            'chat_history' => 'user_id',
            'chat_pending_actions' => 'user_id',
            'conversations' => 'user_id',
            'email_verification_codes' => 'user_id',
            'marketing_campaigns' => 'user_id',
            'marketing_youtube_tokens' => 'user_id',
            'organization_members' => 'user_id',
            'pelican_deployments' => 'user_id',
            'projects' => 'user_id',
            'reseller_applications' => 'user_id',
            'reseller_invite_events' => 'user_id',
            'resellers' => 'user_id',
            'security_log' => 'user_id',
            'social_conversation_members' => 'user_id',
            'social_member_roles' => 'user_id',
            'social_message_receipts' => 'user_id',
            'social_presence' => 'user_id',
            'social_server_members' => 'user_id',
            'social_typing' => 'user_id',
            'social_voice_participants' => 'user_id',
            'support_tickets' => 'user_id',
            'transactions' => 'user_id',
            'user_2fa_recovery_codes' => 'user_id',
            'user_convs' => 'user_id',
            'user_data_deletion_requests' => 'user_id',
            'user_files' => 'user_id',
            'user_mobile_tokens' => 'user_id',
            'user_yubikeys' => 'user_id',
            'automation_job_locks' => 'org_id',
            'dataset' => 'org_id',
            'org_audit_logs' => 'org_id',
            'org_subscriptions' => 'org_id',
            'org_usage_events' => 'org_id',
            'org_webhook_secrets' => 'org_id',
            'saas_idempotency_keys' => 'org_id',
        ];
    }
}

if (!function_exists('chat_tenant_scope_map')) {
    /**
     * table => tenant column, read live from information_schema when a connection is
     * available and cached for the request. When a table carries several candidate
     * columns the most specific is chosen (user_id over org_id) so the scope is the
     * narrowest one.
     */
    function chat_tenant_scope_map(?mysqli $db = null): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        if ($db instanceof mysqli) {
            $priorityOrder = [
                'user_id', 'owner_id', 'account_id', 'workspace_id',
                'team_id', 'tenant_id', 'organization_id', 'org_id',
            ];
            $quoted = [];
            foreach ($priorityOrder as $column) {
                $quoted[] = "'" . $column . "'";
            }
            $sql = 'SELECT table_name, column_name FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND column_name IN (' . implode(',', $quoted) . ')';
            $res = @$db->query($sql);
            if ($res instanceof mysqli_result) {
                $priority = array_flip($priorityOrder);
                $dynamic = [];
                while ($row = $res->fetch_assoc()) {
                    $table = strtolower((string)$row['table_name']);
                    $column = strtolower((string)$row['column_name']);
                    if (!isset($dynamic[$table])) {
                        $dynamic[$table] = $column;
                        continue;
                    }
                    if (($priority[$column] ?? 99) < ($priority[$dynamic[$table]] ?? 99)) {
                        $dynamic[$table] = $column;
                    }
                }
                $res->free();
                if ($dynamic !== []) {
                    $cache = $dynamic;
                    return $cache;
                }
            }
        }

        $cache = chat_tenant_scope_fallback_map();
        return $cache;
    }
}

if (!function_exists('chat_tenant_strip_comments')) {
    /**
     * Remove SQL comments.
     *
     * Used for BOTH table detection and binding detection. Stripping comments in only
     * one of those places was a real bypass: a statement could hide its tenant binding
     * inside a comment and still satisfy the scope check. A unit test caught it, so
     * both scans now share this function.
     *
     * A string literal containing "--" is stripped as well. The resulting refusal is
     * fail-closed rather than a bypass, which is the correct direction to err.
     */
    function chat_tenant_strip_comments(string $sql): string {
        $work = (string)preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $work = (string)preg_replace('/(^|\s)--[^\n]*/', ' ', $work);
        return (string)preg_replace('/(^|\s)#[^\n]*/', ' ', $work);
    }
}

if (!function_exists('chat_tenant_statement_tables')) {
    /**
     * Which tenant-scoped tables does this statement reference?
     * Comments and backticks are stripped first so neither can hide a table name.
     */
    function chat_tenant_statement_tables(string $sql, array $map): array {
        $lower = strtolower(str_replace('`', '', chat_tenant_strip_comments($sql)));

        $found = [];
        foreach (array_keys($map) as $table) {
            if (preg_match('/\b' . preg_quote((string)$table, '/') . '\b/', $lower) === 1) {
                $found[] = (string)$table;
            }
        }
        return $found;
    }
}

if (!function_exists('chat_tenant_user_org_ids')) {
    /**
     * Organisations the given user belongs to, read from organization_members.
     *
     * $_SESSION['org_id'] is never set anywhere in this codebase, so without this
     * lookup every org-scoped table - dataset, org_audit_logs, org_subscriptions,
     * org_usage_events, org_webhook_secrets, saas_idempotency_keys,
     * automation_job_locks - was refused to ordinary users. That is fail-closed and
     * therefore safe, but the control was inert: it denied legitimate access rather
     * than protecting anything.
     *
     * Returns [] on any failure or when the user is not a member of anything, which
     * preserves the fail-closed behaviour in chat_tenant_scope_verify().
     */
    function chat_tenant_user_org_ids(?mysqli $db, string $userId): array {
        static $cache = [];
        $userId = trim($userId);
        if ($userId === '' || !($db instanceof mysqli)) {
            return [];
        }
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $cache[$userId] = [];

        // organization_members.user_id is an int column; anything else cannot match.
        if (ctype_digit($userId) !== true) {
            return $cache[$userId];
        }

        $stmt = @$db->prepare('SELECT org_id FROM organization_members WHERE user_id = ?');
        if (!$stmt) {
            return $cache[$userId];
        }
        $numericId = (int)$userId;
        $stmt->bind_param('i', $numericId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $cache[$userId];
        }
        $res = $stmt->get_result();
        $orgs = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $org = trim((string)($row['org_id'] ?? ''));
            if ($org !== '') {
                $orgs[] = $org;
            }
        }
        $stmt->close();
        $cache[$userId] = $orgs;
        return $orgs;
    }
}

if (!function_exists('chat_tenant_scope_verify')) {
    /**
     * Enforce row-level tenancy for a read-only statement.
     *
     * $identity = [
     *   'authenticated' => bool,   // verified by the server, not asserted by the caller
     *   'user_id'       => string, // verified user id
     *   'org_id'        => string, // verified org id, when known
     *   'is_dev'        => bool,
     * ]
     */
    function chat_tenant_scope_verify(string $sql, array $identity, array $map): array {
        $tables = chat_tenant_statement_tables($sql, $map);
        $scannable = chat_tenant_strip_comments($sql);
        if ($tables === []) {
            return ['ok' => true, 'reason' => 'no_tenant_tables', 'tables' => [], 'unscoped' => []];
        }

        if (!empty($identity['is_dev'])) {
            return [
                'ok' => true,
                'reason' => 'developer_bypass',
                'tables' => $tables,
                'unscoped' => [],
                'bypass' => 'developer_account',
            ];
        }

        $userId = trim((string)($identity['user_id'] ?? ''));

        // Organisation scope is a SET, not a single value: a user may belong to more
        // than one organisation, and narrowing to an arbitrary one would hide data
        // the caller is entitled to. A single org_id is still accepted.
        $orgIds = [];
        $orgId = trim((string)($identity['org_id'] ?? ''));
        if ($orgId !== '') {
            $orgIds[$orgId] = true;
        }
        if (isset($identity['org_ids']) && is_array($identity['org_ids'])) {
            foreach ($identity['org_ids'] as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $orgIds[$candidate] = true;
                }
            }
        }

        if (empty($identity['authenticated']) || $userId === '') {
            return [
                'ok' => false,
                'reason' => 'tenant_scope_requires_authenticated_identity',
                'tables' => $tables,
                'unscoped' => $tables,
            ];
        }

        $unscoped = [];
        foreach ($tables as $table) {
            $column = (string)($map[$table] ?? '');
            if ($column === '') {
                $unscoped[] = $table;
                continue;
            }

            if ($column === 'org_id' || $column === 'organization_id') {
                if ($orgIds === []) {
                    $unscoped[] = $table;
                    continue;
                }
                $allowedValues = array_keys($orgIds);
            } elseif ($column === 'user_id') {
                $allowedValues = [$userId];
            } else {
                // Unknown tenancy semantics: refuse rather than guess a mapping.
                $unscoped[] = $table;
                continue;
            }

            $columnQuoted = preg_quote($column, '/');
            $allowedSet = [];
            foreach ($allowedValues as $value) {
                $allowedSet[(string)$value] = true;
            }

            // Equality form: the compared value must be one of the caller's own.
            $bound = false;
            foreach ($allowedValues as $value) {
                $valueQuoted = preg_quote((string)$value, '/');
                $equalsPattern = '/\b' . $columnQuoted . '\s*(?:=|<=>)\s*[\'"]?' . $valueQuoted . '[\'"]?(?![\w.])/i';
                if (preg_match($equalsPattern, $scannable) === 1) {
                    $bound = true;
                    break;
                }
            }

            /*
             * IN-list form: EVERY element must belong to the caller.
             *
             * Accepting the list because it merely CONTAINS one of the caller's ids
             * was a genuine leak: "WHERE org_id IN ('7','99')" returned org 7 (theirs)
             * alongside org 99 (not theirs), so a caller could read another tenant's
             * rows just by appending their own id to the list. A unit test caught it.
             * An unparseable list is refused rather than trusted.
             */
            $inListAccepted = false;
            if ($bound === false && preg_match_all(
                '/\b' . $columnQuoted . '\s+in\s*\(([^()]*)\)/i',
                $scannable,
                $inMatches,
                PREG_SET_ORDER
            ) > 0) {
                // The LAST matching list wins: it is the most restrictive typical use,
                // and anything unrecognised falls through to refusal below.
                $lastList = (string)end($inMatches)[1];
                $elements = [];
                $parseable = true;
                foreach (explode(',', $lastList) as $element) {
                    $element = trim($element);
                    $element = trim($element, "'\"");
                    $element = trim($element);
                    if ($element === '') {
                        $parseable = false;
                        break;
                    }
                    if (preg_match('/^[\w.\-]+$/', $element) !== 1) {
                        // A subquery, function call or placeholders: cannot verify.
                        $parseable = false;
                        break;
                    }
                    $elements[] = $element;
                }
                if ($parseable && $elements !== []) {
                    $inListAccepted = true;
                    foreach ($elements as $element) {
                        if (!isset($allowedSet[$element])) {
                            $inListAccepted = false;
                            break;
                        }
                    }
                }
            }

            if (!$bound && !$inListAccepted) {
                $unscoped[] = $table;
            }
        }

        if ($unscoped !== []) {
            return [
                'ok' => false,
                'reason' => 'tenant_scope_required',
                'tables' => $tables,
                'unscoped' => array_values(array_unique($unscoped)),
            ];
        }

        return [
            'ok' => true,
            'reason' => 'tenant_scoped',
            'tables' => $tables,
            'unscoped' => [],
            'bound_to' => $userId,
        ];
    }
}
