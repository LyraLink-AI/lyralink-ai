<?php
/**
 * Data-plane executors: read-only database query and local host inspection.
 *
 * Both capabilities are read-only by construction, and each layers its defences
 * instead of trusting a single check.
 *
 * database.query
 *   1. Connects as a dedicated SELECT-only MariaDB user (LYRALINK_DB_RO_*). The
 *      server itself refuses writes, so a bug in the statement inspection below
 *      cannot become a write. The application user holds INSERT/UPDATE/DELETE/
 *      DROP/ALTER/EXECUTE on this schema and must never be used here.
 *   2. Accepts only a single SELECT / WITH / SHOW / DESCRIBE / EXPLAIN statement.
 *   3. Refuses outfile/dumpfile/load-file/load-data and unbounded-work functions.
 *   4. Refuses tables holding credentials, tokens, secrets or other users' data.
 *      The read-only user can read those tables, so this check is load-bearing.
 *   5. Runs inside max_statement_time plus a read-only transaction.
 *   6. Caps rows and bytes handed back to the model.
 *
 * server.inspect
 *   Reads host state through PHP and /proc only - no shell, no subprocess. The
 *   sandbox is deliberately NOT used: it is a `--network none --read-only`
 *   container, so anything it reported would describe the container rather than
 *   the server. Presenting container facts as server state would be a lie, and
 *   this capability exists to support verifiable claims about the host.
 */

if (!function_exists('lyra_db_ro_config')) {
    function lyra_db_ro_config(): array {
        $read = static function (string $key, string $default): string {
            if (function_exists('api_get_secret')) {
                $value = api_get_secret($key, '');
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            $env = getenv($key);
            return (is_string($env) && trim($env) !== '') ? trim($env) : $default;
        };

        return [
            'host' => $read('DB_HOST', 'localhost'),
            'user' => $read('LYRALINK_DB_RO_USER', ''),
            'pass' => $read('LYRALINK_DB_RO_PASS', ''),
            'name' => $read('LYRALINK_DB_RO_NAME', $read('DB_NAME', '')),
            'timeout_sec' => max(1, min(30, (int)$read('LYRALINK_DB_QUERY_TIMEOUT', '8'))),
            'max_rows' => max(1, min(500, (int)$read('LYRALINK_DB_QUERY_MAX_ROWS', '50'))),
            'max_bytes' => max(2000, min(200000, (int)$read('LYRALINK_DB_QUERY_MAX_BYTES', '60000'))),
            // Tables the model must never read: credentials, tokens, secrets and
            // other users' private content. Overridable per deployment.
            'deny_tables' => $read(
                'LYRALINK_DB_DENY_TABLES',
                'users,api_keys,user_2fa_recovery_codes,user_yubikeys,org_webhook_secrets,'
                . 'discord_sync_tokens,marketing_youtube_tokens,user_mobile_tokens,login_attempts,'
                . 'auth_rate_limits,saas_idempotency_keys,user_convs,user_conv_messages,user_files,'
                . 'user_data_deletion_requests,support_config,credit_transfers,transactions,'
                . 'password_resets,sessions,oauth_tokens'
            ),
        ];
    }
}

if (!function_exists('lyra_db_query_available')) {
    /**
     * True only when a usable read-only credential pair exists. Availability is
     * never inferred from the presence of this file.
     */
    function lyra_db_query_available(): bool {
        $config = lyra_db_ro_config();
        return $config['user'] !== ''
            && $config['pass'] !== ''
            && $config['name'] !== ''
            && class_exists('mysqli');
    }
}

if (!function_exists('lyra_db_query_inspect')) {
    /**
     * Static inspection of the statement. Returns ['ok','errors','sql'].
     * This is damage reduction; the authoritative boundary is the read-only user.
     */
    function lyra_db_query_inspect(string $sql, array $config): array {
        $errors = [];

        // Strip comments and backticks first: otherwise a comment or a quoted
        // identifier lets a denied keyword or table name slip past the scan.
        $work = (string)preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $work = (string)preg_replace('/(^|\s)--[^\n]*/', ' ', $work);
        $work = (string)preg_replace('/(^|\s)#[^\n]*/', ' ', $work);
        $stripped = str_replace('`', '', $work);
        $trimmed = trim($stripped);

        if ($trimmed === '') {
            return ['ok' => false, 'errors' => ['empty_statement'], 'sql' => ''];
        }

        // Exactly one statement. A trailing semicolon is tolerated, an interior
        // one is a second statement.
        $single = rtrim($trimmed);
        if (substr($single, -1) === ';') {
            $single = rtrim(substr($single, 0, -1));
        }
        if (strpos($single, ';') !== false) {
            $errors[] = 'multiple_statements';
        }

        $lower = strtolower($single);

        // Only read verbs. ANALYZE is excluded: in MariaDB it updates statistics.
        if (preg_match('/^\s*(select|with|show|describe|desc|explain)\b/', $lower) !== 1) {
            $errors[] = 'statement_not_read_only';
        }

        $forbidden = [
            'into\s+outfile' => 'outfile_write',
            'into\s+dumpfile' => 'dumpfile_write',
            'load_file\s*\(' => 'file_read',
            'load\s+data' => 'load_data',
            'benchmark\s*\(' => 'unbounded_work',
            'sleep\s*\(' => 'time_delay',
            'get_lock\s*\(' => 'lock_taken',
            'for\s+update' => 'locking_read',
            'lock\s+in\s+share\s+mode' => 'locking_read',
            'into\s+@' => 'session_variable_write',
        ];
        foreach ($forbidden as $pattern => $name) {
            if (preg_match('/' . $pattern . '/', $lower) === 1) {
                $errors[] = $name;
            }
        }

        // System schemas are denied explicitly even though the read-only user
        // cannot reach them, so the refusal is intentional and named.
        if (preg_match('/\b(mysql|sys|performance_schema)\s*\./', $lower) === 1) {
            $errors[] = 'system_schema_access';
        }

        // Sensitive tables. Word-boundary match over the whole statement catches
        // subqueries and joins, not just the outer FROM.
        $denied = [];
        foreach (explode(',', (string)$config['deny_tables']) as $table) {
            $table = strtolower(trim($table));
            if ($table === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/', $lower) === 1) {
                $denied[] = $table;
            }
        }
        if ($denied !== []) {
            $errors[] = 'sensitive_table:' . implode('|', array_slice($denied, 0, 5));
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'sql' => $single];
    }
}

if (!function_exists('lyra_db_query_execute')) {
    /**
     * Run one read-only query. Returns ['ok','reason','result','stdout','verified'].
     * Never includes credentials in any returned string.
     */
    function lyra_db_query_execute(string $sql, array $opts = []): array {
        $config = lyra_db_ro_config();

        if (!lyra_db_query_available()) {
            return ['ok' => false, 'reason' => 'read_only_credentials_missing', 'result' => [], 'stdout' => '', 'verified' => false];
        }

        $inspection = lyra_db_query_inspect($sql, $config);
        if (!$inspection['ok']) {
            return [
                'ok' => false,
                'reason' => 'refused:' . implode(',', $inspection['errors']),
                'result' => ['errors' => $inspection['errors']],
                'stdout' => '',
                'verified' => false,
            ];
        }
        $statement = (string)$inspection['sql'];

        // Bound the work a single query can do.
        $maxRows = (int)($opts['max_rows'] ?? $config['max_rows']);
        $maxBytes = (int)($opts['max_bytes'] ?? $config['max_bytes']);
        $timeoutSec = (int)($opts['timeout_sec'] ?? $config['timeout_sec']);

        if (preg_match('/^\s*(select|with)\b/', strtolower($statement)) === 1
            && preg_match('/\blimit\b/', strtolower($statement)) !== 1) {
            $statement .= ' LIMIT ' . $maxRows;
        }

        // Split host:port / host:socket so mysqli receives a bare hostname.
        $host = (string)$config['host'];
        $port = 3306;
        $socket = null;
        if (strpos($host, ':') !== false) {
            [$hostOnly, $rest] = explode(':', $host, 2);
            if (ctype_digit($rest)) {
                $port = (int)$rest;
            } else {
                $socket = $rest;
            }
            $host = $hostOnly;
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $connection = @mysqli_connect(
            $host,
            (string)$config['user'],
            (string)$config['pass'],
            (string)$config['name'],
            $port,
            $socket
        );
        if (!$connection) {
            // Deliberately not surfacing mysqli_connect_error(): it can echo the
            // username back and would end up in the audit trail.
            return ['ok' => false, 'reason' => 'connection_failed', 'result' => [], 'stdout' => '', 'verified' => false];
        }

        // Row-level tenancy. The sensitive-table denylist is damage reduction, not a
        // tenancy boundary: the read-only user can read every row of any table it is
        // allowed to touch. Every tenant-scoped table referenced by the statement
        // must therefore be bound to the caller's verified session identity.
        if (function_exists('chat_tenant_scope_verify')) {
            $tenantCheck = chat_tenant_scope_verify(
                $statement,
                (array)($opts['identity'] ?? []),
                chat_tenant_scope_map($connection)
            );
            if (empty($tenantCheck['ok'])) {
                mysqli_close($connection);
                return [
                    'ok' => false,
                    'reason' => 'refused:' . (string)$tenantCheck['reason'],
                    'result' => $tenantCheck,
                    'stdout' => '',
                    'verified' => false,
                ];
            }
        }

        @mysqli_set_charset($connection, 'utf8mb4');
        // Layer 5a: server-side statement timeout (seconds, MariaDB).
        @mysqli_query($connection, 'SET SESSION max_statement_time = ' . $timeoutSec);
        // Layer 5b: read-only transaction. Writes fail even if inspection missed one.
        @mysqli_query($connection, 'START TRANSACTION READ ONLY');

        $result = @mysqli_query($connection, $statement);

        if ($result === false) {
            $error = (string)mysqli_error($connection);
            $errno = (int)mysqli_errno($connection);
            @mysqli_query($connection, 'ROLLBACK');
            mysqli_close($connection);
            return [
                'ok' => false,
                'reason' => 'query_error',
                'result' => ['error' => $error, 'errno' => $errno],
                'stdout' => '',
                'verified' => false,
            ];
        }

        if ($result === true) {
            // A read capability producing no result set means the statement was
            // not the read we inspected. Refuse rather than report success.
            @mysqli_query($connection, 'ROLLBACK');
            mysqli_close($connection);
            return ['ok' => false, 'reason' => 'no_result_set', 'result' => [], 'stdout' => '', 'verified' => false];
        }

        $columns = [];
        foreach ((array)mysqli_fetch_fields($result) as $field) {
            $columns[] = (string)($field->name ?? '');
        }

        $rows = [];
        $bytes = 0;
        $truncated = false;
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
            $bytes += strlen((string)json_encode($row));
            if (count($rows) >= $maxRows || $bytes >= $maxBytes) {
                $truncated = count($rows) >= $maxRows;
                break;
            }
        }
        $rowCount = (int)mysqli_num_rows($result);
        mysqli_free_result($result);
        @mysqli_query($connection, 'ROLLBACK');
        mysqli_close($connection);

        // Render a compact TSV view for the model; the structured rows are for audit.
        $lines = [implode("\t", $columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $cells[] = $value === null ? 'NULL' : substr((string)$value, 0, 120);
            }
            $lines[] = implode("\t", $cells);
        }

        return [
            'ok' => true,
            'reason' => 'query_completed',
            'result' => [
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => $rowCount,
                'rows_returned' => count($rows),
                'truncated' => $truncated,
                'bytes' => $bytes,
                'read_only' => true,
            ],
            'stdout' => implode("\n", $lines),
            // Verified: the server produced a result set for an inspected read.
            'verified' => $rowCount >= 0 && $columns !== [],
        ];
    }
}

if (!function_exists('lyra_server_inspect_available')) {
    /**
     * Linux-specific, shell-free host inspection. False anywhere /proc is absent
     * so the capability is never advertised on a platform it cannot read.
     */
    function lyra_server_inspect_available(): bool {
        return function_exists('sys_getloadavg') && @is_readable('/proc/uptime');
    }
}

if (!function_exists('lyra_server_inspect')) {
    /**
     * Inspect THIS host. Read-only, no shell. Returns ['ok','reason','result','stdout','verified'].
     *
     * Only local targets are supported. A request for any other target is refused
     * by name rather than silently answered with local data.
     */
    function lyra_server_inspect(string $target, array $opts = []): array {
        if (!lyra_server_inspect_available()) {
            return ['ok' => false, 'reason' => 'inspection_unsupported_platform', 'result' => [], 'stdout' => '', 'verified' => false];
        }

        $target = strtolower(trim($target));
        $localAliases = ['', 'local', 'localhost', 'host', 'this-host', 'this_server', 'this-server', 'server', 'self', 'machine', 'system', 'all'];
        $facets = ['load', 'memory', 'disk', 'php', 'ports', 'processes', 'uptime'];

        if (!in_array($target, $localAliases, true) && !in_array($target, $facets, true)) {
            return [
                'ok' => false,
                'reason' => 'unsupported_target',
                'result' => ['requested' => $target, 'supported' => array_merge(array_filter($localAliases), $facets)],
                'stdout' => '',
                'verified' => false,
            ];
        }

        $wantAll = in_array($target, $localAliases, true) || $target === 'system';
        $read = static function (string $path): ?string {
            if (!@is_readable($path)) {
                return null;
            }
            $contents = @file_get_contents($path, false, null, 0, 65536);
            return is_string($contents) ? $contents : null;
        };

        $result = ['host_target' => 'local_web_host', 'facets' => []];
        $failures = [];
        $lines = [];

        // load average. CPU count comes from /proc/cpuinfo - no shell, and load
        // average is only interpretable per-core.
        if ($wantAll || $target === 'load') {
            $load = @sys_getloadavg();
            $cpuCount = 0;
            $cpuinfo = $read('/proc/cpuinfo');
            if ($cpuinfo !== null) {
                $cpuCount = (int)preg_match_all('/^processor\s*:/m', $cpuinfo);
            }
            if (is_array($load)) {
                $result['facets']['load'] = [
                    'one' => round((float)$load[0], 2),
                    'five' => round((float)$load[1], 2),
                    'fifteen' => round((float)$load[2], 2),
                    'cpu_count' => $cpuCount,
                ];
                $lines[] = 'load average: ' . $result['facets']['load']['one']
                    . ' / ' . $result['facets']['load']['five']
                    . ' / ' . $result['facets']['load']['fifteen']
                    . ($cpuCount > 0 ? ' across ' . $cpuCount . ' cpu(s)' : '');
            } else {
                $failures[] = 'load';
            }
        }

        // uptime + kernel
        if ($wantAll || $target === 'uptime') {
            $uptimeRaw = $read('/proc/uptime');
            if ($uptimeRaw !== null) {
                $seconds = (float)explode(' ', trim($uptimeRaw))[0];
                $result['facets']['uptime'] = [
                    'seconds' => (int)$seconds,
                    'human' => floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm',
                ];
                $lines[] = 'uptime: ' . $result['facets']['uptime']['human'];
            } else {
                $failures[] = 'uptime';
            }
        }

        // memory
        if ($wantAll || $target === 'memory') {
            $meminfo = $read('/proc/meminfo');
            if ($meminfo !== null) {
                $mem = [];
                foreach (explode("\n", $meminfo) as $line) {
                    if (preg_match('/^(MemTotal|MemFree|MemAvailable|SwapTotal|SwapFree):\s+(\d+)\s*kB/', $line, $m) === 1) {
                        $mem[$m[1]] = (int)$m[2] * 1024;
                    }
                }
                if ($mem !== []) {
                    $total = $mem['MemTotal'] ?? 0;
                    $available = $mem['MemAvailable'] ?? $mem['MemFree'] ?? 0;
                    $mem['used_percent'] = $total > 0 ? round((($total - $available) / $total) * 100, 1) : null;
                    $result['facets']['memory'] = $mem;
                    $lines[] = 'memory: ' . round($total / 1048576, 1) . ' MiB total, '
                        . round($available / 1048576, 1) . ' MiB available ('
                        . (string)$mem['used_percent'] . '% used)';
                } else {
                    $failures[] = 'memory';
                }
            } else {
                $failures[] = 'memory';
            }
        }

        // disk
        if ($wantAll || $target === 'disk') {
            $total = @disk_total_space('/');
            $free = @disk_free_space('/');
            if ($total !== false && $free !== false && $total > 0) {
                $result['facets']['disk'] = [
                    'mount' => '/',
                    'total_bytes' => (int)$total,
                    'free_bytes' => (int)$free,
                    'used_percent' => round((($total - $free) / $total) * 100, 1),
                ];
                $lines[] = 'disk /: ' . round($free / 1073741824, 1) . ' GiB free of '
                    . round($total / 1073741824, 1) . ' GiB ('
                    . (string)$result['facets']['disk']['used_percent'] . '% used)';
            } else {
                $failures[] = 'disk';
            }
        }

        // php runtime
        if ($wantAll || $target === 'php') {
            $result['facets']['php'] = [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => (string)ini_get('memory_limit'),
                'max_execution_time' => (int)ini_get('max_execution_time'),
            ];
            $lines[] = 'php: ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
        }

        // listening TCP ports, read from /proc without invoking anything
        if ($wantAll || $target === 'ports') {
            $ports = [];
            foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $procFile) {
                $table = $read($procFile);
                if ($table === null) {
                    continue;
                }
                foreach (array_slice(explode("\n", $table), 1) as $row) {
                    $cols = preg_split('/\s+/', trim($row)) ?: [];
                    if (count($cols) < 4) {
                        continue;
                    }
                    if (strtoupper((string)($cols[3] ?? '')) !== '0A') {
                        continue; // 0A = LISTEN
                    }
                    $port = hexdec(substr((string)$cols[1], -4));
                    if ($port > 0) {
                        $ports[$port] = true;
                    }
                }
            }
            if ($ports !== []) {
                $list = array_keys($ports);
                sort($list);
                $result['facets']['ports'] = ['listening_count' => count($list), 'listening' => $list];
                $lines[] = 'listening tcp ports: ' . implode(', ', $list);
            } else {
                $failures[] = 'ports';
            }
        }

        // presence of key service processes, read from /proc
        if ($wantAll || $target === 'processes') {
            $interesting = ['nginx', 'apache2', 'httpd', 'mariadbd', 'mysqld', 'php-fpm', 'ollama', 'dockerd', 'containerd', 'crond', 'sshd'];
            $found = [];
            $procDirs = @glob('/proc/[0-9]*/comm');
            foreach ((array)$procDirs as $commPath) {
                $comm = $read($commPath);
                if ($comm === null) {
                    continue;
                }
                $comm = trim($comm);
                if (in_array($comm, $interesting, true)) {
                    $found[$comm] = ($found[$comm] ?? 0) + 1;
                }
            }
            if ($procDirs !== false || $found !== []) {
                $result['facets']['processes'] = $found;
                $lines[] = 'services: ' . ($found === []
                    ? 'none of the tracked processes are running'
                    : implode(', ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($found), $found)));
            } else {
                $failures[] = 'processes';
            }
        }

        $result['failures'] = $failures;
        $result['inspected_facets'] = array_keys($result['facets']);

        // Verified only if we actually read measurements from the host.
        $verified = $result['facets'] !== [];

        return [
            'ok' => $verified,
            'reason' => $verified
                ? ($failures === [] ? 'inspected' : 'inspected_with_gaps')
                : 'inspection_unavailable',
            'result' => $result,
            'stdout' => implode("\n", $lines),
            'verified' => $verified,
        ];
    }
}
