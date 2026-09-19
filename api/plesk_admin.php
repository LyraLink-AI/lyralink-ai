<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
lyra_session_boot();
api_json_headers();

function plesk_admin_dev_username(): string {
    return (string)(api_get_secret('ADMIN_DEV_USERNAME', 'developer') ?? 'developer');
}

function plesk_admin_require_dev(): void {
    $username = (string)($_SESSION['username'] ?? '');
    if ($username === '' || $username !== plesk_admin_dev_username()) {
        api_fail('Forbidden', 403);
    }
}

function plesk_admin_runtime_identity(): array {
    $uid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
    $name = 'unknown';
    if ($uid >= 0 && function_exists('posix_getpwuid')) {
        $row = @posix_getpwuid($uid);
        if (is_array($row) && !empty($row['name'])) {
            $name = (string)$row['name'];
        }
    }
    return ['uid' => $uid, 'user' => $name];
}

function plesk_admin_run(string $command): array {
    $proc = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => 1, 'out' => '', 'err' => 'Failed to start process'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [
        'ok' => $code === 0,
        'code' => $code,
        'out' => trim((string)$stdout),
        'err' => trim((string)$stderr),
    ];
}

function plesk_admin_run_privileged(string $command): array {
    $id = plesk_admin_runtime_identity();
    if (($id['uid'] ?? -1) === 0) {
        return plesk_admin_run($command);
    }

    $allowSudo = api_get_secret('PLESK_ADMIN_USE_SUDO', '1') === '1';
    if ($allowSudo) {
        $sudoResult = plesk_admin_run('sudo -n ' . $command);
        if ($sudoResult['ok']) {
            return $sudoResult;
        }

        $err = strtolower(trim((string)($sudoResult['err'] !== '' ? $sudoResult['err'] : $sudoResult['out'])));
        $sudoAuthFailure = str_contains($err, 'a password is required')
            || str_contains($err, 'is not in the sudoers file')
            || str_contains($err, 'no tty present')
            || str_contains($err, 'a terminal is required');

        if (!$sudoAuthFailure) {
            return $sudoResult;
        }

        return [
            'ok' => false,
            'code' => 1,
            'out' => '',
            'err' => 'Plesk commands require root or passwordless sudo for runtime user "' . ($id['user'] ?? 'unknown') . '" (uid ' . (int)($id['uid'] ?? -1) . ').',
        ];
    }

    return [
        'ok' => false,
        'code' => 1,
        'out' => '',
        'err' => 'Plesk commands require root privileges. Set PLESK_ADMIN_USE_SUDO=1 and allow sudo -n for /usr/sbin/plesk, /usr/local/psa/bin/php_handler, /usr/local/psa/bin/scheduler.',
    ];
}

function plesk_admin_collect_plesk(string $subscription): array {
    $sub = escapeshellarg($subscription);

    $version = plesk_admin_run_privileged('/usr/sbin/plesk version');
    $handlers = plesk_admin_run_privileged('/usr/local/psa/bin/php_handler --list');
    $tasks = plesk_admin_run_privileged('/usr/local/psa/bin/scheduler --list -long -subscription ' . $sub);

    return [
        'subscription' => $subscription,
        'version' => [
            'ok' => (bool)$version['ok'],
            'output' => (string)($version['out'] !== '' ? $version['out'] : $version['err']),
        ],
        'php_handlers' => [
            'ok' => (bool)$handlers['ok'],
            'output' => (string)($handlers['out'] !== '' ? $handlers['out'] : $handlers['err']),
        ],
        'scheduled_tasks' => [
            'ok' => (bool)$tasks['ok'],
            'output' => (string)($tasks['out'] !== '' ? $tasks['out'] : $tasks['err']),
        ],
    ];
}

function plesk_admin_collect_mysql(mysqli $db, string $dbName): array {
    $meta = [
        'server_version' => 'unknown',
        'database' => $dbName,
        'table_count' => 0,
        'row_estimate' => 0,
        'data_mb' => 0.0,
        'index_mb' => 0.0,
        'total_mb' => 0.0,
    ];
    $tables = [];
    $top = [];

    $versionRes = $db->query('SELECT VERSION() AS v');
    if ($versionRes) {
        $meta['server_version'] = (string)($versionRes->fetch_assoc()['v'] ?? 'unknown');
    }

    $statusSql = 'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME, UPDATE_TIME '
        . 'FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() '
        . 'ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC';
    $statusRes = $db->query($statusSql);
    if ($statusRes) {
        while ($row = $statusRes->fetch_assoc()) {
            $dataBytes = (float)($row['DATA_LENGTH'] ?? 0);
            $indexBytes = (float)($row['INDEX_LENGTH'] ?? 0);
            $totalBytes = $dataBytes + $indexBytes;
            $entry = [
                'name' => (string)($row['TABLE_NAME'] ?? ''),
                'engine' => (string)($row['ENGINE'] ?? 'unknown'),
                'rows' => (int)($row['TABLE_ROWS'] ?? 0),
                'data_mb' => round($dataBytes / 1048576, 3),
                'index_mb' => round($indexBytes / 1048576, 3),
                'total_mb' => round($totalBytes / 1048576, 3),
                'updated_at' => (string)($row['UPDATE_TIME'] ?? ''),
                'created_at' => (string)($row['CREATE_TIME'] ?? ''),
            ];
            $tables[] = $entry;

            $meta['table_count']++;
            $meta['row_estimate'] += $entry['rows'];
            $meta['data_mb'] += $entry['data_mb'];
            $meta['index_mb'] += $entry['index_mb'];
            $meta['total_mb'] += $entry['total_mb'];
        }
    }

    $meta['data_mb'] = round((float)$meta['data_mb'], 3);
    $meta['index_mb'] = round((float)$meta['index_mb'], 3);
    $meta['total_mb'] = round((float)$meta['total_mb'], 3);
    $top = array_slice($tables, 0, 12);

    return [
        'meta' => $meta,
        'top_tables' => $top,
        'tables' => $tables,
    ];
}

plesk_admin_require_dev();

$action = api_action();
if ($action !== 'snapshot') {
    api_fail('Unknown action', 404);
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('DB connection failed', 500);
}
$db->set_charset('utf8mb4');

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'lyralinkai.com'));
$subscription = preg_replace('/^www\./', '', $host);
if ($subscription === '') {
    $subscription = 'lyralinkai.com';
}

echo json_encode([
    'success' => true,
    'captured_at' => gmdate('c'),
    'runtime' => plesk_admin_runtime_identity(),
    'plesk' => plesk_admin_collect_plesk($subscription),
    'mysql' => plesk_admin_collect_mysql($db, (string)$dbCfg['name']),
], JSON_UNESCAPED_SLASHES);
