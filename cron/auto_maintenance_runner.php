<?php
/**
 * Lyralink Auto Maintenance Runner
 * Intended schedule: every 4 hours via cron.
 *
 * What it does:
 * - Collects system and app health metrics
 * - Runs checks (DB, PHP lint sample, endpoint latency)
 * - Applies safe automatic remediations
 * - Uses local AI for additional remediation proposals
 * - Executes only whitelisted AI actions (no approval required)
 * - Sends a detailed Discord bot-log report
 */

require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lib/chat/os_core.php';

date_default_timezone_set('UTC');

const MAINTENANCE_DISCORD_CHANNEL_ID = '1475657872862875727';
const MAINTENANCE_LOCK_FILE = '/tmp/lyralink-auto-maintenance.lock';
const MAINTENANCE_RUN_LOG = '/tmp/lyralink-auto-maintenance.log';

$startedAt = microtime(true);
$workspaceRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$lockHandle = @fopen(MAINTENANCE_LOCK_FILE, 'c+');
if (!$lockHandle) {
    fwrite(STDERR, "[auto_maintenance] could not open lock file\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[auto_maintenance] already running, skipping\n");
    exit(0);
}

register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
$dbOk = !$db->connect_error;
if ($dbOk) {
    $db->set_charset('utf8mb4');
}

$maintenanceOsDecision = chat_os_cron_job_context(
    'auto_maintenance_runner',
    'Run scheduled maintenance health checks, safe remediation, and reporting.',
    [
        'db' => $dbOk ? $db : null,
        'risk_level' => 'MEDIUM',
        'task_domain' => 'operations',
        'workspace_available' => true,
        'database_runtime_available' => $dbOk,
        'shell_runtime_available' => true,
        'granted_permissions' => ['model.generate', 'filesystem.read', 'network.read'],
    ]
);
$maintenanceTask = is_array($maintenanceOsDecision['task'] ?? null) ? $maintenanceOsDecision['task'] : [];

$report = [
    'started_at' => gmdate('c'),
    'workspace_root' => $workspaceRoot,
    'checks' => [],
    'findings' => [],
    'actions' => [],
    'incidents' => [
        'opened' => [],
        'updated' => [],
        'resolved' => [],
        'service_states' => [],
    ],
    'ai' => [
        'enabled' => true,
        'recommendations' => null,
        'raw_error' => null,
    ],
    'summary' => [
        'ok_checks' => 0,
        'warn_checks' => 0,
        'failed_checks' => 0,
        'actions_succeeded' => 0,
        'actions_failed' => 0,
    ],
];
if ($maintenanceTask !== []) {
    $report['ai_os'] = [
        'task_id' => $maintenanceTask['task_id'] ?? null,
        'request_id' => $maintenanceOsDecision['request_id'] ?? null,
        'route_class' => $maintenanceOsDecision['route_class'] ?? null,
        'capability_id' => $maintenanceOsDecision['capability']['capability_id'] ?? null,
        'resource_state' => $maintenanceOsDecision['resource']['state'] ?? null,
        'authorization_state' => $maintenanceOsDecision['authorization']['state'] ?? null,
    ];
}

function maintenance_log(string $message): void {
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents(MAINTENANCE_RUN_LOG, $line, FILE_APPEND);
    // Cron redirects stdout into MAINTENANCE_RUN_LOG as well, and this function
    // already writes that file, so echoing unconditionally duplicated every
    // line. Echo only when a human is watching a terminal.
    if (function_exists('stream_isatty') && @stream_isatty(STDOUT)) {
        echo $line;
    }
}

function maintenance_bytes_human(?int $bytes): string {
    if ($bytes === null || $bytes < 0) {
        return 'n/a';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $v = (float)$bytes;
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
}

function maintenance_exec(string $cmd, int $timeoutSec = 60): array {
    $wrapped = 'timeout ' . max(1, $timeoutSec) . 's bash --noprofile --norc -lc ' . escapeshellarg($cmd) . ' 2>&1';
    $output = [];
    $code = 0;
    @exec($wrapped, $output, $code);
    return [
        'command' => $cmd,
        'exit_code' => $code,
        'output' => trim(implode("\n", $output)),
    ];
}

function maintenance_search_fix_hints(string $query, string $root): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $safeQuery = preg_replace('/[^a-zA-Z0-9_:\/. -]/', ' ', $query) ?? '';
    $safeQuery = trim(preg_replace('/\s+/', ' ', $safeQuery) ?? '');
    if ($safeQuery === '') {
        return [];
    }

    $searchRoot = escapeshellarg($root);
    $cmd = 'cd ' . $searchRoot . ' && rg -n -S --max-count 3 ' . escapeshellarg($safeQuery) . ' wiki README.md scripts api cron 2>/dev/null | head -n 8';
    $res = maintenance_exec($cmd, 20);
    if (($res['exit_code'] ?? 1) !== 0 || trim((string)($res['output'] ?? '')) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", (string)$res['output']))));
}

function maintenance_add_check(array &$report, string $name, string $status, array $details = []): void {
    $report['checks'][] = [
        'name' => $name,
        'status' => $status,
        'details' => $details,
    ];
    if ($status === 'ok') {
        $report['summary']['ok_checks']++;
    } elseif ($status === 'warn') {
        $report['summary']['warn_checks']++;
    } else {
        $report['summary']['failed_checks']++;
    }
}

function maintenance_add_finding(array &$report, string $severity, string $title, string $detail): void {
    $report['findings'][] = [
        'severity' => $severity,
        'title' => $title,
        'detail' => $detail,
    ];
}

function maintenance_add_action(array &$report, string $title, bool $ok, string $detail, array $extra = []): void {
    $report['actions'][] = [
        'title' => $title,
        'ok' => $ok,
        'detail' => $detail,
        'extra' => $extra,
    ];
    if ($ok) {
        $report['summary']['actions_succeeded']++;
    } else {
        $report['summary']['actions_failed']++;
    }
}

function maintenance_read_meminfo(): array {
    $out = [
        'mem_total' => null,
        'mem_available' => null,
        'swap_total' => null,
        'swap_free' => null,
    ];
    if (!is_readable('/proc/meminfo')) {
        return $out;
    }
    $raw = @file_get_contents('/proc/meminfo');
    if (!is_string($raw) || $raw === '') {
        return $out;
    }
    if (preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $raw, $m)) {
        $out['mem_total'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/im', $raw, $m)) {
        $out['mem_available'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^SwapTotal:\s+(\d+)\s+kB/im', $raw, $m)) {
        $out['swap_total'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^SwapFree:\s+(\d+)\s+kB/im', $raw, $m)) {
        $out['swap_free'] = (int)$m[1] * 1024;
    }
    return $out;
}

function maintenance_probe_endpoint(string $url, int $timeoutSec = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'LyralinkAutoMaintenance/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return [
        'url' => $url,
        'ok' => $body !== false && $code >= 200 && $code < 500,
        'http_code' => $code,
        'time_ms' => (int)round($total * 1000),
        'curl_error' => $err,
    ];
}

function maintenance_probe_derive_status(array $probe): string {
    if (!($probe['ok'] ?? false) || (int)($probe['http_code'] ?? 0) >= 500) {
        return 'major_outage';
    }
    if ((int)($probe['time_ms'] ?? 0) >= (int)($probe['degraded_ms'] ?? 2500)) {
        return 'degraded';
    }
    return 'operational';
}

function maintenance_probe_copy_metadata(array $baseProbe, array $freshProbe): array {
    $merged = array_merge($baseProbe, $freshProbe);
    $merged['slug'] = (string)($baseProbe['slug'] ?? '');
    $merged['name'] = (string)($baseProbe['name'] ?? '');
    $merged['description'] = (string)($baseProbe['description'] ?? '');
    $merged['category'] = (string)($baseProbe['category'] ?? 'Core Services');
    $merged['sort_order'] = (int)($baseProbe['sort_order'] ?? 99);
    $merged['degraded_ms'] = (int)($baseProbe['degraded_ms'] ?? 2500);
    $merged['derived_status'] = maintenance_probe_derive_status($merged);
    return $merged;
}

function maintenance_attempt_probe_remediation(array $probe, string $root): array {
    $out = [
        'probe' => $probe,
        'actions' => [],
    ];

    if ((string)($probe['slug'] ?? '') !== 'ai-chat-api') {
        return $out;
    }

    if ((string)($probe['derived_status'] ?? 'operational') === 'operational') {
        return $out;
    }

    $warmup = maintenance_execute_ai_action(['action' => 'warmup_local_llm', 'args' => []], $root);
    $out['actions'][] = [
        'title' => 'Pre-incident remediation: AI Chat API warmup',
        'ok' => (bool)($warmup['ok'] ?? false),
        'detail' => (string)($warmup['detail'] ?? 'Warmup attempted.'),
        'extra' => $warmup['extra'] ?? [],
    ];

    if (!($warmup['ok'] ?? false)) {
        return $out;
    }

    $reprobe = maintenance_probe_endpoint((string)($probe['url'] ?? ''));
    $updatedProbe = maintenance_probe_copy_metadata($probe, $reprobe);
    $updatedProbe['remediation_attempted'] = true;
    $updatedProbe['remediation_action'] = 'warmup_local_llm';
    $updatedProbe['pre_remediation_status'] = (string)($probe['derived_status'] ?? 'operational');
    $updatedProbe['pre_remediation_time_ms'] = (int)($probe['time_ms'] ?? 0);

    if ((string)($updatedProbe['derived_status'] ?? 'operational') !== 'operational') {
        $restart = maintenance_execute_ai_action(['action' => 'restart_local_llm_service', 'args' => []], $root);
        $out['actions'][] = [
            'title' => 'Pre-incident remediation: restart local LLM service',
            'ok' => (bool)($restart['ok'] ?? false),
            'detail' => (string)($restart['detail'] ?? 'Restart attempted.'),
            'extra' => $restart['extra'] ?? [],
        ];

        if ($restart['ok'] ?? false) {
            $reprobeAfterRestart = maintenance_probe_endpoint((string)($probe['url'] ?? ''));
            $updatedProbe = maintenance_probe_copy_metadata($updatedProbe, $reprobeAfterRestart);
            $updatedProbe['remediation_attempted'] = true;
            $updatedProbe['remediation_action'] = 'restart_local_llm_service';
        }
    }

    $out['probe'] = $updatedProbe;
    return $out;
}

function maintenance_table_has_column(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
}

function maintenance_add_column_if_missing(mysqli $db, string $table, string $column, string $definition): void {
    if (maintenance_table_has_column($db, $table, $column)) {
        return;
    }
    $db->query('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function maintenance_probe_state_apply(mysqli $db, array $probe, int $slowThreshold = 2): array {
    $slug = (string)($probe['slug'] ?? '');
    if ($slug === '') {
        $probe['effective_status'] = (string)($probe['derived_status'] ?? 'operational');
        $probe['slow_streak'] = 0;
        $probe['cooldown_active'] = false;
        return $probe;
    }

    $sel = $db->prepare("SELECT slow_streak FROM maintenance_probe_state WHERE service_slug = ? LIMIT 1");
    $previousStreak = 0;
    if ($sel) {
        $sel->bind_param('s', $slug);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        $previousStreak = (int)($row['slow_streak'] ?? 0);
    }

    $rawStatus = (string)($probe['derived_status'] ?? 'operational');
    $isLatencyOnlyDegraded = ($rawStatus === 'degraded')
        && (bool)($probe['ok'] ?? false)
        && (int)($probe['http_code'] ?? 0) >= 200
        && (int)($probe['http_code'] ?? 0) < 500;

    $slowStreak = $isLatencyOnlyDegraded ? ($previousStreak + 1) : 0;
    $effectiveStatus = $rawStatus;
    $cooldownActive = false;
    if ($isLatencyOnlyDegraded && $slowStreak < $slowThreshold) {
        $effectiveStatus = 'operational';
        $cooldownActive = true;
    }

    $latency = (int)($probe['time_ms'] ?? 0);
    $httpCode = (int)($probe['http_code'] ?? 0);
    $err = trim((string)($probe['curl_error'] ?? ''));
    if (strlen($err) > 500) {
        $err = substr($err, 0, 500);
    }

    $up = $db->prepare("INSERT INTO maintenance_probe_state
        (service_slug, slow_streak, last_raw_status, last_effective_status, last_latency_ms, last_http_code, last_error)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            slow_streak = VALUES(slow_streak),
            last_raw_status = VALUES(last_raw_status),
            last_effective_status = VALUES(last_effective_status),
            last_latency_ms = VALUES(last_latency_ms),
            last_http_code = VALUES(last_http_code),
            last_error = VALUES(last_error),
            updated_at = CURRENT_TIMESTAMP");
    if ($up) {
        $up->bind_param('sissiis', $slug, $slowStreak, $rawStatus, $effectiveStatus, $latency, $httpCode, $err);
        $up->execute();
        $up->close();
    }

    $probe['effective_status'] = $effectiveStatus;
    $probe['slow_streak'] = $slowStreak;
    $probe['cooldown_active'] = $cooldownActive;
    return $probe;
}

function maintenance_status_ensure_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS status_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(300) DEFAULT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'Core Services',
        status ENUM('operational','degraded','partial_outage','major_outage','maintenance') NOT NULL DEFAULT 'operational',
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Compatibility migration for older status_services layouts.
    maintenance_add_column_if_missing($db, 'status_services', 'description', "VARCHAR(300) DEFAULT NULL AFTER slug");
    maintenance_add_column_if_missing($db, 'status_services', 'category', "VARCHAR(100) NOT NULL DEFAULT 'Core Services' AFTER description");
    maintenance_add_column_if_missing($db, 'status_services', 'sort_order', "INT NOT NULL DEFAULT 0 AFTER status");
    maintenance_add_column_if_missing($db, 'status_services', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER sort_order");

    $db->query("CREATE TABLE IF NOT EXISTS status_incidents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(300) NOT NULL,
        status ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
        impact ENUM('none','minor','major','critical') NOT NULL DEFAULT 'minor',
        affected_services VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL
    )");

    // Compatibility migration for older status_incidents layouts.
    maintenance_add_column_if_missing($db, 'status_incidents', 'status', "ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating' AFTER title");
    maintenance_add_column_if_missing($db, 'status_incidents', 'impact', "ENUM('none','minor','major','critical') NOT NULL DEFAULT 'minor' AFTER status");
    maintenance_add_column_if_missing($db, 'status_incidents', 'affected_services', "VARCHAR(500) DEFAULT NULL AFTER impact");
    maintenance_add_column_if_missing($db, 'status_incidents', 'resolved_at', "DATETIME DEFAULT NULL AFTER created_at");

    $db->query("CREATE TABLE IF NOT EXISTS status_incident_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        message TEXT NOT NULL,
        status ENUM('investigating','identified','monitoring','resolved') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_incident (incident_id)
    )");

    maintenance_add_column_if_missing($db, 'status_incident_updates', 'status', "ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating' AFTER message");

    $db->query("CREATE TABLE IF NOT EXISTS maintenance_probe_state (
        service_slug VARCHAR(120) PRIMARY KEY,
        slow_streak INT NOT NULL DEFAULT 0,
        last_raw_status VARCHAR(32) NOT NULL DEFAULT 'operational',
        last_effective_status VARCHAR(32) NOT NULL DEFAULT 'operational',
        last_latency_ms INT NOT NULL DEFAULT 0,
        last_http_code INT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function maintenance_status_ensure_service(mysqli $db, string $name, string $slug, string $description, string $category, int $sortOrder): ?array {
    $ins = $db->prepare("INSERT IGNORE INTO status_services (name, slug, description, category, status, sort_order) VALUES (?, ?, ?, ?, 'operational', ?)");
    if ($ins) {
        $ins->bind_param('ssssi', $name, $slug, $description, $category, $sortOrder);
        $ins->execute();
        $ins->close();
    }

    $sel = $db->prepare("SELECT id, name, slug, status FROM status_services WHERE slug = ? LIMIT 1");
    if (!$sel) {
        return null;
    }
    $sel->bind_param('s', $slug);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc() ?: null;
    $sel->close();
    return $row;
}

function maintenance_incident_update_is_due(mysqli $db, int $incidentId, int $minIntervalSeconds): bool {
    if ($minIntervalSeconds <= 0) {
        return true;
    }

    $sql = "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
        FROM status_incident_updates
        WHERE incident_id = ? AND status <> 'resolved'
        ORDER BY created_at DESC
        LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $incidentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row || !isset($row['age_seconds'])) {
        return true;
    }

    return (int)$row['age_seconds'] >= $minIntervalSeconds;
}

function maintenance_incident_open_for_service(mysqli $db, int $serviceId, string $serviceName, string $newStatus, string $detail, int $minUpdateIntervalSeconds = 7200): ?array {
    $serviceToken = 'service:' . $serviceId;
    $like = '%' . $serviceToken . '%';
    $check = $db->prepare("SELECT id, status FROM status_incidents WHERE resolved_at IS NULL AND affected_services LIKE ? ORDER BY created_at DESC LIMIT 1");
    if (!$check) {
        return null;
    }
    $check->bind_param('s', $like);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        $incidentId = (int)($existing['id'] ?? 0);
        if ($incidentId > 0) {
            if (!maintenance_incident_update_is_due($db, $incidentId, $minUpdateIntervalSeconds)) {
                return [
                    'incident_id' => $incidentId,
                    'opened' => false,
                    'updated' => false,
                    'suppressed' => true,
                ];
            }

            $statusForUpdate = ((string)($existing['status'] ?? 'investigating') === 'investigating') ? 'investigating' : 'identified';
            $msg = "Automated monitor still detects {$serviceName} status={$newStatus}. " . trim($detail);
            $up = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
            if ($up) {
                $up->bind_param('iss', $incidentId, $msg, $statusForUpdate);
                $up->execute();
                $up->close();
            }

            $upd = $db->prepare("UPDATE status_incidents SET status = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('si', $statusForUpdate, $incidentId);
                $upd->execute();
                $upd->close();
            }

            return [
                'incident_id' => $incidentId,
                'opened' => false,
                'updated' => true,
            ];
        }
        return null;
    }

    $impact = match ($newStatus) {
        'major_outage' => 'critical',
        'partial_outage' => 'major',
        'degraded' => 'minor',
        default => 'minor',
    };
    $incidentStatus = 'investigating';
    $title = $serviceName . ' ' . str_replace('_', ' ', $newStatus);
    $affectedServices = $serviceToken . ',name:' . $serviceName;

    $stmt = $db->prepare("INSERT INTO status_incidents (title, status, impact, affected_services) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ssss', $title, $incidentStatus, $impact, $affectedServices);
    $stmt->execute();
    $incidentId = (int)$db->insert_id;
    $stmt->close();

    if ($incidentId <= 0) {
        return null;
    }

    $msg = "Automated monitor detected {$serviceName} status={$newStatus}. " . trim($detail);
    $up = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
    if ($up) {
        $up->bind_param('iss', $incidentId, $msg, $incidentStatus);
        $up->execute();
        $up->close();
    }

    return [
        'incident_id' => $incidentId,
        'opened' => true,
        'updated' => false,
    ];
}

function maintenance_incident_resolve_for_service(mysqli $db, int $serviceId, string $serviceName): ?int {
    $serviceToken = 'service:' . $serviceId;
    $like = '%' . $serviceToken . '%';
    $check = $db->prepare("SELECT id FROM status_incidents WHERE resolved_at IS NULL AND affected_services LIKE ? ORDER BY created_at DESC LIMIT 1");
    if (!$check) {
        return null;
    }
    $check->bind_param('s', $like);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$existing) {
        return null;
    }

    $incidentId = (int)$existing['id'];
    $resolvedStatus = 'resolved';
    $message = 'Automated monitor confirms ' . $serviceName . ' has recovered and is operational.';

    $up = $db->prepare("INSERT INTO status_incident_updates (incident_id, message, status) VALUES (?, ?, ?)");
    if ($up) {
        $up->bind_param('iss', $incidentId, $message, $resolvedStatus);
        $up->execute();
        $up->close();
    }

    $upd = $db->prepare("UPDATE status_incidents SET status = 'resolved', resolved_at = NOW() WHERE id = ?");
    if ($upd) {
        $upd->bind_param('i', $incidentId);
        $upd->execute();
        $upd->close();
    }

    return $incidentId;
}

function maintenance_php_lint_sample(string $root, int $maxFiles = 120): array {
    $errors = [];
    $checked = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        if (substr($path, -4) !== '.php') {
            continue;
        }
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (str_contains($path, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $checked++;
        $res = maintenance_exec('php -l ' . escapeshellarg($path), 8);
        if (($res['exit_code'] ?? 1) !== 0) {
            $errors[] = [
                'file' => $path,
                'output' => substr((string)($res['output'] ?? ''), 0, 800),
            ];
        }
        if ($checked >= $maxFiles) {
            break;
        }
    }

    return [
        'checked' => $checked,
        'errors' => $errors,
    ];
}

function maintenance_extract_json(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```json\s*(\{[\s\S]*\})\s*```/i', $raw, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (preg_match('/(\{[\s\S]*\})/m', $raw, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function maintenance_ai_plan(array $report): array {
    $baseUrl = rtrim((string)api_get_secret('OPENAI_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $apiKey = (string)api_get_secret('OPENAI_API_KEY', 'local-ollama');
    $model = (string)api_get_secret('LOCAL_LLM_MODEL', api_get_secret('LLM_MODEL', 'lyralink-auto-canary:latest'));
    if ($model === '') {
        $model = 'lyralink-auto-canary:latest';
    }

    $allowedActions = [
        'clear_temp_logs',
        'warmup_local_llm',
        'restart_local_llm_service',
        'guard_local_llm_latency',
        'run_intrusion_monitor',
        // 'run_dataset_auto_learn',        // disabled 2026-09-22: auto learning off
        // 'run_continuous_model_learning', // disabled 2026-09-22: auto learning off
        'composer_dump_autoload',
        'php_lint_target',
        'apply_text_patch',
    ];

    $prompt = [
        'role' => 'system',
        'content' => 'You are an SRE optimization planner. Return strict JSON only. No markdown. Schema: {"analysis":"...","actions":[{"action":"one_of_allowed","reason":"...","args":{}}],"notes":["..."]}. Allowed actions: ' . implode(', ', $allowedActions) . '. Keep actions safe and minimal.',
    ];

    $userMsg = [
        'role' => 'user',
        'content' => 'Maintenance report context: ' . json_encode([
            'checks' => $report['checks'],
            'findings' => $report['findings'],
            'summary' => $report['summary'],
        ], JSON_UNESCAPED_SLASHES),
    ];

    $payload = [
        'model' => $model,
        'messages' => [$prompt, $userMsg],
        'temperature' => 0.2,
        'max_tokens' => 700,
        'stream' => false,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        return [
            'ok' => false,
            'error' => 'ai_call_failed code=' . $code . ' err=' . $err,
        ];
    }

    $decoded = json_decode((string)$resp, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $plan = maintenance_extract_json((string)$content);
    if (!is_array($plan)) {
        return [
            'ok' => false,
            'error' => 'ai_json_parse_failed',
            'raw' => substr((string)$content, 0, 1200),
        ];
    }

    return [
        'ok' => true,
        'plan' => $plan,
    ];
}

function maintenance_execute_ai_action(array $step, string $root): array {
    $action = strtolower(trim((string)($step['action'] ?? '')));
    $args = is_array($step['args'] ?? null) ? $step['args'] : [];

    if ($action === 'clear_temp_logs') {
        $days = (int)($args['days'] ?? 2);
        $days = max(1, min($days, 14));
        $res = maintenance_exec('find /tmp -maxdepth 1 -type f -name ' . escapeshellarg('lyralink-*.log') . ' -mtime +' . $days . ' -delete && echo cleanup_done', 20);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Cleared /tmp/lyralink-*.log older than ' . $days . ' day(s).', 'extra' => $res];
    }

    if ($action === 'warmup_local_llm') {
        $script = $root . '/scripts/lyralink-hermes-warmup.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'detail' => 'Warmup script missing.', 'extra' => []];
        }
        $res = maintenance_exec('bash ' . escapeshellarg($script), 120);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Executed local LLM warmup script.', 'extra' => $res];
    }

    if ($action === 'restart_local_llm_service') {
        $res = maintenance_exec('command -v systemctl >/dev/null 2>&1 && systemctl restart ollama && bash ' . escapeshellarg($root . '/scripts/lyralink-hermes-warmup.sh'), 180);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Restarted Ollama service and reran warmup.', 'extra' => $res];
    }

    if ($action === 'guard_local_llm_latency') {
        $script = $root . '/scripts/auto_finetune_latency_guard.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'detail' => 'Latency guard script missing.', 'extra' => []];
        }
        $res = maintenance_exec('bash ' . escapeshellarg($script), 80);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran local LLM latency rollback guard.', 'extra' => $res];
    }

    if ($action === 'run_intrusion_monitor') {
        $script = $root . '/cron/security_intrusion_monitor.php';
        $res = maintenance_exec('php ' . escapeshellarg($script), 40);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran intrusion monitor job.', 'extra' => $res];
    }

    if ($action === 'run_dataset_auto_learn') {
        // Automated learning disabled 2026-09-22 - see MAINTENANCE_* gates.
        if (api_get_secret('MAINTENANCE_AUTO_LEARNING_ENABLED', '0') !== '1') {
            return ['ok' => true, 'detail' => 'Dataset auto-learn disabled.', 'extra' => ['skipped' => true]];
        }
        $script = $root . '/cron/dataset_auto_learn.php';
        $res = maintenance_exec('php ' . escapeshellarg($script), 70);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran dataset auto-learn job.', 'extra' => $res];
    }

    if ($action === 'run_public_web_seed') {
        $script = $root . '/cron/public_web_seed.php';
        $res = maintenance_exec('php ' . escapeshellarg($script) . ' --quiet', 180);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran public web dataset seed job.', 'extra' => $res];
    }

    if ($action === 'run_continuous_model_learning') {
        // Automated learning disabled 2026-09-22 - see MAINTENANCE_* gates.
        if (api_get_secret('MAINTENANCE_AUTO_LEARNING_ENABLED', '0') !== '1') {
            return ['ok' => true, 'detail' => 'Continuous model learning disabled.', 'extra' => ['skipped' => true]];
        }
        $script = $root . '/cron/continuous_model_learning.php';
        $res = maintenance_exec('php ' . escapeshellarg($script), 600);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran continuous model learning job.', 'extra' => $res];
    }

    if ($action === 'composer_dump_autoload') {
        $composer = $root . '/composer.json';
        if (!is_file($composer)) {
            return ['ok' => false, 'detail' => 'composer.json missing.', 'extra' => []];
        }
        $res = maintenance_exec('cd ' . escapeshellarg($root) . ' && composer dump-autoload -o', 120);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Ran composer dump-autoload -o.', 'extra' => $res];
    }

    if ($action === 'php_lint_target') {
        $target = (string)($args['file'] ?? '');
        if ($target === '') {
            return ['ok' => false, 'detail' => 'Missing file argument for php_lint_target.', 'extra' => []];
        }
        $abs = realpath($target);
        if ($abs === false) {
            $abs = realpath($root . '/' . ltrim($target, '/')) ?: '';
        }
        if ($abs === '' || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || substr($abs, -4) !== '.php') {
            return ['ok' => false, 'detail' => 'Target is outside workspace or invalid.', 'extra' => ['target' => $target]];
        }
        $res = maintenance_exec('php -l ' . escapeshellarg($abs), 15);
        return ['ok' => ($res['exit_code'] ?? 1) === 0, 'detail' => 'Linted requested PHP target: ' . $abs, 'extra' => $res];
    }

    if ($action === 'apply_text_patch') {
        $allowPatch = api_get_secret('MAINTENANCE_ALLOW_AI_PATCH', '0') === '1';
        if (!$allowPatch) {
            return ['ok' => false, 'detail' => 'AI patch action disabled by MAINTENANCE_ALLOW_AI_PATCH.', 'extra' => []];
        }

        $target = (string)($args['file'] ?? '');
        $find = (string)($args['find'] ?? '');
        $replace = (string)($args['replace'] ?? '');
        if ($target === '' || $find === '') {
            return ['ok' => false, 'detail' => 'apply_text_patch requires file and find args.', 'extra' => []];
        }

        $candidate = $target;
        if (!str_starts_with($candidate, '/')) {
            $candidate = $root . '/' . ltrim($candidate, '/');
        }
        $abs = realpath($candidate);
        if ($abs === false || !is_file($abs)) {
            return ['ok' => false, 'detail' => 'Patch target file not found: ' . $target, 'extra' => []];
        }
        if (!str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'detail' => 'Patch target outside workspace.', 'extra' => []];
        }
        if (str_contains($abs, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'detail' => 'Patching vendor files is blocked.', 'extra' => []];
        }

        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $allowedExt = ['php', 'js', 'css', 'md', 'txt', 'sh'];
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'detail' => 'File extension not allowed for patch: ' . $ext, 'extra' => []];
        }

        $original = @file_get_contents($abs);
        if (!is_string($original)) {
            return ['ok' => false, 'detail' => 'Unable to read patch target file.', 'extra' => []];
        }

        $occurrences = substr_count($original, $find);
        if ($occurrences !== 1) {
            return ['ok' => false, 'detail' => 'Patch find text must match exactly once. Matches=' . $occurrences, 'extra' => []];
        }

        $updated = str_replace($find, $replace, $original);
        if ($updated === $original) {
            return ['ok' => false, 'detail' => 'Patch produced no change.', 'extra' => []];
        }

        $writeOk = @file_put_contents($abs, $updated);
        if ($writeOk === false) {
            return ['ok' => false, 'detail' => 'Failed to write patched content.', 'extra' => []];
        }

        if ($ext === 'php') {
            $lint = maintenance_exec('php -l ' . escapeshellarg($abs), 15);
            if (($lint['exit_code'] ?? 1) !== 0) {
                @file_put_contents($abs, $original);
                return ['ok' => false, 'detail' => 'Patch reverted: PHP lint failed after patch.', 'extra' => $lint];
            }
        }

        return [
            'ok' => true,
            'detail' => 'Applied patch to ' . $abs,
            'extra' => [
                'file' => $abs,
                'ext' => $ext,
                'bytes_written' => (int)$writeOk,
            ],
        ];
    }

    return ['ok' => false, 'detail' => 'Unsupported AI action: ' . $action, 'extra' => []];
}

function maintenance_send_discord_report(array $report): bool {
    $botToken = trim((string)api_get_secret('BOT_SECRET_KEY', ''));
    if ($botToken === '') {
        return false;
    }

    $durationSec = (int)round((float)($report['duration_seconds'] ?? 0));
    $summary = $report['summary'] ?? [];
    $title = 'Bot Logs: Auto Maintenance Report';

    $findingsText = 'None';
    if (!empty($report['findings'])) {
        $lines = [];
        foreach (array_slice($report['findings'], 0, 10) as $f) {
            $lines[] = '[' . strtoupper((string)$f['severity']) . '] ' . $f['title'] . ' - ' . $f['detail'];
        }
        $findingsText = implode("\n", $lines);
    }

    $actionsText = 'None';
    if (!empty($report['actions'])) {
        $lines = [];
        foreach (array_slice($report['actions'], 0, 12) as $a) {
            $lines[] = (($a['ok'] ?? false) ? 'OK' : 'FAIL') . ' - ' . $a['title'] . ': ' . $a['detail'];
        }
        $actionsText = implode("\n", $lines);
    }

    $checksText = 'None';
    if (!empty($report['checks'])) {
        $lines = [];
        foreach (array_slice($report['checks'], 0, 12) as $c) {
            $lines[] = strtoupper((string)$c['status']) . ' - ' . (string)$c['name'];
        }
        $checksText = implode("\n", $lines);
    }

    $embed = [
        'title' => $title,
        'description' => 'Scheduled 4-hour maintenance run completed.',
        'color' => (($summary['failed_checks'] ?? 0) > 0 || ($summary['actions_failed'] ?? 0) > 0) ? 0xF59E0B : 0x22C55E,
        'fields' => [
            ['name' => 'Started (UTC)', 'value' => (string)($report['started_at'] ?? gmdate('c')), 'inline' => true],
            ['name' => 'Duration', 'value' => $durationSec . 's', 'inline' => true],
            ['name' => 'Workspace', 'value' => substr((string)($report['workspace_root'] ?? ''), 0, 900), 'inline' => false],
            ['name' => 'Checks', 'value' => "ok=" . (int)($summary['ok_checks'] ?? 0) . ", warn=" . (int)($summary['warn_checks'] ?? 0) . ", failed=" . (int)($summary['failed_checks'] ?? 0), 'inline' => true],
            ['name' => 'Actions', 'value' => "ok=" . (int)($summary['actions_succeeded'] ?? 0) . ", failed=" . (int)($summary['actions_failed'] ?? 0), 'inline' => true],
            ['name' => 'Findings', 'value' => substr($findingsText, 0, 1024), 'inline' => false],
            ['name' => 'Applied Actions', 'value' => substr($actionsText, 0, 1024), 'inline' => false],
            ['name' => 'Check Matrix', 'value' => substr($checksText, 0, 1024), 'inline' => false],
        ],
        'timestamp' => gmdate('c'),
        'footer' => [
            'text' => 'Lyralink autonomous maintenance',
        ],
    ];

    $payload = ['embeds' => [$embed]];

    $endpoint = 'https://discord.com/api/v10/channels/' . rawurlencode(MAINTENANCE_DISCORD_CHANNEL_ID) . '/messages';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        maintenance_log('[auto_maintenance] discord report failed code=' . $code . ' err=' . $err);
        return false;
    }

    return true;
}

maintenance_log('[auto_maintenance] starting maintenance run');

$mem = maintenance_read_meminfo();
$diskTotal = @disk_total_space($workspaceRoot);
$diskFree = @disk_free_space($workspaceRoot);
$diskUsedPct = (is_numeric($diskTotal) && $diskTotal > 0 && is_numeric($diskFree))
    ? round((1 - ((float)$diskFree / (float)$diskTotal)) * 100, 2)
    : null;

maintenance_add_check($report, 'db_connectivity', $dbOk ? 'ok' : 'fail', [
    'connected' => $dbOk,
    'error' => $dbOk ? null : $db->connect_error,
]);
if (!$dbOk) {
    maintenance_add_finding($report, 'critical', 'Database connectivity failed', (string)$db->connect_error);
}

maintenance_add_check($report, 'system_resources', 'ok', [
    'mem_available' => maintenance_bytes_human($mem['mem_available']),
    'mem_total' => maintenance_bytes_human($mem['mem_total']),
    'disk_free' => is_numeric($diskFree) ? maintenance_bytes_human((int)$diskFree) : 'n/a',
    'disk_total' => is_numeric($diskTotal) ? maintenance_bytes_human((int)$diskTotal) : 'n/a',
    'disk_used_percent' => $diskUsedPct,
]);

if (is_numeric($diskUsedPct) && $diskUsedPct >= 85) {
    maintenance_add_finding($report, 'high', 'High disk usage', 'Disk usage is at ' . $diskUsedPct . '%.');
    $cleanup = maintenance_execute_ai_action(['action' => 'clear_temp_logs', 'args' => ['days' => 2]], $workspaceRoot);
    maintenance_add_action($report, 'Auto cleanup temporary logs', (bool)$cleanup['ok'], (string)$cleanup['detail'], $cleanup['extra'] ?? []);
}

if (!empty($mem['mem_total']) && !empty($mem['mem_available'])) {
    $availPct = ((float)$mem['mem_available'] / (float)$mem['mem_total']) * 100;
    if ($availPct < 10) {
        maintenance_add_finding($report, 'high', 'Low memory availability', 'Available RAM is ' . round($availPct, 2) . '%.');
    }
}

$lint = maintenance_php_lint_sample($workspaceRoot, 140);
$lintErrors = $lint['errors'] ?? [];
$lintStatus = empty($lintErrors) ? 'ok' : 'fail';
maintenance_add_check($report, 'php_lint_sample', $lintStatus, [
    'checked_files' => $lint['checked'] ?? 0,
    'error_count' => count($lintErrors),
]);
if (!empty($lintErrors)) {
    foreach (array_slice($lintErrors, 0, 5) as $err) {
        maintenance_add_finding($report, 'high', 'PHP lint error', basename((string)$err['file']) . ': ' . preg_replace('/\s+/', ' ', (string)$err['output']));
    }

    $hintLines = maintenance_search_fix_hints((string)($lintErrors[0]['output'] ?? 'php parse error'), $workspaceRoot);
    if (!empty($hintLines)) {
        maintenance_add_finding($report, 'medium', 'Local fix hints found', implode(' | ', $hintLines));
    }
}

$baseUrl = rtrim((string)api_get_secret('APP_BASE_URL', 'https://lyralinkai.com'), '/');
$probeTargets = [
    ['slug' => 'web-app', 'name' => 'Web Application', 'description' => 'Main Lyralink web interface', 'category' => 'Core Services', 'sort_order' => 1, 'url' => $baseUrl . '/', 'degraded_ms' => 2600],
    ['slug' => 'ai-chat-api', 'name' => 'AI Chat API', 'description' => 'Core conversational AI endpoint', 'category' => 'Core Services', 'sort_order' => 0, 'url' => $baseUrl . '/api/chat.php?health=1', 'degraded_ms' => 1800],
    ['slug' => 'status-api', 'name' => 'Status API', 'description' => 'Status endpoint and incident feed', 'category' => 'Core Services', 'sort_order' => 2, 'url' => $baseUrl . '/api/status.php', 'degraded_ms' => 2200],
    ['slug' => 'cdn-edge', 'name' => 'CDN / Static Assets', 'description' => 'External CDN dependency availability', 'category' => 'Infrastructure', 'sort_order' => 9, 'url' => 'https://cdnjs.cloudflare.com/ajax/libs/marked/9.1.6/marked.min.js', 'degraded_ms' => 3000],
];

$probes = [];
foreach ($probeTargets as $target) {
    $probe = maintenance_probe_endpoint((string)$target['url']);
    $probe['slug'] = (string)$target['slug'];
    $probe['name'] = (string)$target['name'];
    $probe['description'] = (string)$target['description'];
    $probe['category'] = (string)$target['category'];
    $probe['sort_order'] = (int)$target['sort_order'];
    $probe['degraded_ms'] = (int)$target['degraded_ms'];
    $probe['derived_status'] = maintenance_probe_derive_status($probe);

    $remediation = maintenance_attempt_probe_remediation($probe, $workspaceRoot);
    foreach (($remediation['actions'] ?? []) as $action) {
        maintenance_add_action(
            $report,
            (string)($action['title'] ?? 'Automatic remediation'),
            (bool)($action['ok'] ?? false),
            (string)($action['detail'] ?? ''),
            is_array($action['extra'] ?? null) ? $action['extra'] : []
        );
    }
    $probe = is_array($remediation['probe'] ?? null) ? $remediation['probe'] : $probe;

    if (!empty($probe['remediation_attempted'])) {
        $beforeStatus = (string)($probe['pre_remediation_status'] ?? 'unknown');
        $afterStatus = (string)($probe['derived_status'] ?? 'unknown');
        $beforeLatency = (int)($probe['pre_remediation_time_ms'] ?? 0);
        $afterLatency = (int)($probe['time_ms'] ?? 0);
        if ($afterStatus === 'operational' && $beforeStatus !== 'operational') {
            maintenance_add_finding($report, 'medium', 'Automatic remediation recovered service', (string)($probe['name'] ?? 'Service') . ' recovered after warmup (' . $beforeLatency . 'ms -> ' . $afterLatency . 'ms).');
        } elseif ($afterStatus !== $beforeStatus || $afterLatency !== $beforeLatency) {
            maintenance_add_finding($report, 'medium', 'Automatic remediation changed service state', (string)($probe['name'] ?? 'Service') . ' changed from ' . $beforeStatus . ' to ' . $afterStatus . ' (' . $beforeLatency . 'ms -> ' . $afterLatency . 'ms).');
        }
    }

    $probes[] = $probe;
}

$slowCount = 0;
foreach ($probes as $probe) {
    if (($probe['time_ms'] ?? 0) > 2500) {
        $slowCount++;
        maintenance_add_finding($report, 'medium', 'Slow endpoint', $probe['url'] . ' responded in ' . $probe['time_ms'] . ' ms');
    }
    if (!($probe['ok'] ?? false)) {
        maintenance_add_finding($report, 'high', 'Endpoint probe failed', $probe['url'] . ' http=' . ($probe['http_code'] ?? 0) . ' err=' . ($probe['curl_error'] ?? 'n/a'));

        $hintLines = maintenance_search_fix_hints('endpoint probe failed ' . (string)($probe['url'] ?? ''), $workspaceRoot);
        if (!empty($hintLines)) {
            maintenance_add_finding($report, 'medium', 'Endpoint fix hints found', implode(' | ', $hintLines));
        }
    }
}
$probeStatus = $slowCount > 0 ? 'warn' : 'ok';
if (count(array_filter($probes, static fn($p) => !($p['ok'] ?? false))) > 0) {
    $probeStatus = 'fail';
}
maintenance_add_check($report, 'endpoint_latency', $probeStatus, ['probes' => $probes]);

if ($dbOk) {
    maintenance_status_ensure_schema($db);

    foreach ($probes as $idx => $probe) {
        $probes[$idx] = maintenance_probe_state_apply($db, $probe, 2);
        if (($probes[$idx]['cooldown_active'] ?? false) === true) {
            maintenance_add_finding(
                $report,
                'medium',
                'Latency cooldown active',
                (string)($probes[$idx]['name'] ?? $probes[$idx]['slug'] ?? 'service')
                . ' slow streak=' . (int)($probes[$idx]['slow_streak'] ?? 0)
                . ' (threshold=2), incident deferred this run.'
            );
        }
    }

    $opened = 0;
    $updated = 0;
    $resolved = 0;

    foreach ($probes as $probe) {
        $service = maintenance_status_ensure_service(
            $db,
            (string)($probe['name'] ?? 'Unknown Service'),
            (string)($probe['slug'] ?? 'unknown-service'),
            (string)($probe['description'] ?? ''),
            (string)($probe['category'] ?? 'Core Services'),
            (int)($probe['sort_order'] ?? 99)
        );
        if (!$service) {
            continue;
        }

        $serviceId = (int)($service['id'] ?? 0);
        $serviceName = (string)($service['name'] ?? ($probe['name'] ?? 'Unknown Service'));
        $previousStatus = (string)($service['status'] ?? 'operational');
        $rawStatus = (string)($probe['derived_status'] ?? 'operational');
        $newStatus = (string)($probe['effective_status'] ?? $rawStatus);

        if (($probe['cooldown_active'] ?? false) === true && $rawStatus === 'degraded' && $previousStatus !== 'operational') {
            // Keep existing degraded/outage state until degradation is confirmed or cleared.
            $newStatus = $previousStatus;
        }

        $report['incidents']['service_states'][] = [
            'service_id' => $serviceId,
            'slug' => (string)($probe['slug'] ?? ''),
            'name' => $serviceName,
            'previous_status' => $previousStatus,
            'raw_status' => $rawStatus,
            'new_status' => $newStatus,
            'cooldown_active' => (bool)($probe['cooldown_active'] ?? false),
            'slow_streak' => (int)($probe['slow_streak'] ?? 0),
            'url' => (string)($probe['url'] ?? ''),
            'time_ms' => (int)($probe['time_ms'] ?? 0),
            'http_code' => (int)($probe['http_code'] ?? 0),
        ];

        if ($previousStatus !== $newStatus) {
            $upd = $db->prepare("UPDATE status_services SET status = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('si', $newStatus, $serviceId);
                $upd->execute();
                $upd->close();
            }
            maintenance_add_action($report, 'Update service status: ' . $serviceName, true, $previousStatus . ' -> ' . $newStatus, [
                'service_id' => $serviceId,
                'slug' => $probe['slug'] ?? '',
            ]);
        }

        if ($newStatus !== 'operational') {
            $detail = 'endpoint=' . (string)($probe['url'] ?? '')
                . '; http=' . (int)($probe['http_code'] ?? 0)
                . '; latency_ms=' . (int)($probe['time_ms'] ?? 0)
                . '; error=' . trim((string)($probe['curl_error'] ?? ''));
            $incidentResult = maintenance_incident_open_for_service($db, $serviceId, $serviceName, $newStatus, $detail);
            if ($incidentResult !== null && (int)($incidentResult['incident_id'] ?? 0) > 0) {
                $incidentId = (int)$incidentResult['incident_id'];
                if (!empty($incidentResult['opened'])) {
                    $opened++;
                    $report['incidents']['opened'][] = [
                        'incident_id' => $incidentId,
                        'service_id' => $serviceId,
                        'slug' => (string)($probe['slug'] ?? ''),
                        'status' => $newStatus,
                    ];
                    maintenance_add_finding($report, 'high', 'Active incident auto-created', $serviceName . ' -> incident #' . $incidentId . ' (' . $newStatus . ')');
                } elseif (!empty($incidentResult['updated'])) {
                    $updated++;
                    $report['incidents']['updated'][] = [
                        'incident_id' => $incidentId,
                        'service_id' => $serviceId,
                        'slug' => (string)($probe['slug'] ?? ''),
                        'status' => $newStatus,
                    ];
                    maintenance_add_action($report, 'Update active incident', true, $serviceName . ' incident #' . $incidentId . ' updated with ongoing degradation.', [
                        'service_id' => $serviceId,
                        'slug' => $probe['slug'] ?? '',
                    ]);
                } elseif (!empty($incidentResult['suppressed'])) {
                    maintenance_add_action($report, 'Suppress duplicate incident update', true, $serviceName . ' incident #' . $incidentId . ' update skipped due to minimum interval.', [
                        'service_id' => $serviceId,
                        'slug' => $probe['slug'] ?? '',
                    ]);
                }
            }
        } else {
            $resolvedId = maintenance_incident_resolve_for_service($db, $serviceId, $serviceName);
            if ($resolvedId !== null) {
                $resolved++;
                $report['incidents']['resolved'][] = [
                    'incident_id' => $resolvedId,
                    'service_id' => $serviceId,
                    'slug' => (string)($probe['slug'] ?? ''),
                    'status' => 'resolved',
                ];
                maintenance_add_action($report, 'Resolve incident for recovered service', true, $serviceName . ' incident #' . $resolvedId . ' resolved.', [
                    'service_id' => $serviceId,
                    'slug' => $probe['slug'] ?? '',
                ]);
            }
        }
    }

    $incidentCheckStatus = ($opened > 0 || $updated > 0) ? 'warn' : 'ok';
    maintenance_add_check($report, 'status_incident_automation', $incidentCheckStatus, [
        'opened_incidents' => $opened,
        'updated_incidents' => $updated,
        'resolved_incidents' => $resolved,
        'services_evaluated' => count($probes),
    ]);
}

 $aiProbeRemediated = count(array_filter($probes, static fn(array $probe): bool =>
    (($probe['slug'] ?? '') === 'ai-chat-api') && !empty($probe['remediation_attempted'])
)) > 0;

if ($slowCount > 0) {
    if (!$aiProbeRemediated) {
        $warmup = maintenance_execute_ai_action(['action' => 'warmup_local_llm', 'args' => []], $workspaceRoot);
        maintenance_add_action($report, 'Warmup local LLM for latency recovery', (bool)$warmup['ok'], (string)$warmup['detail'], $warmup['extra'] ?? []);
    }
}

$intrusionRun = maintenance_execute_ai_action(['action' => 'run_intrusion_monitor', 'args' => []], $workspaceRoot);
maintenance_add_action($report, 'Run intrusion monitor maintenance', (bool)$intrusionRun['ok'], (string)$intrusionRun['detail'], $intrusionRun['extra'] ?? []);

// -- Automated learning: DISABLED 2026-09-22 (owner decision) --------------
// Dataset promotion and continuous model learning no longer run unattended.
// continuous_model_learning rebuilds the lyralink-* models, which drops the
// resident copy and forces a cold load on the next chat request. Both are
// treated as 'the system teaching itself' and stay off until re-enabled.
//
// Re-enable in .env:
//   MAINTENANCE_AUTO_LEARNING_ENABLED=1         (master switch)
//   MAINTENANCE_DATASET_AUTO_LEARN_ENABLED=1    (dataset promotion only)
//   MAINTENANCE_CONTINUOUS_LEARNING_ENABLED=1   (model learning only)
//
// Health checks, local-LLM warmup and service auto-restart are NOT gated.
$autoLearningEnabled = api_get_secret('MAINTENANCE_AUTO_LEARNING_ENABLED', '0') === '1';
$datasetLearnEnabled = $autoLearningEnabled
    && api_get_secret('MAINTENANCE_DATASET_AUTO_LEARN_ENABLED', '0') === '1';
$modelLearnEnabled = $autoLearningEnabled
    && api_get_secret('MAINTENANCE_CONTINUOUS_LEARNING_ENABLED', '0') === '1';

if ($datasetLearnEnabled) {
    $datasetRun = maintenance_execute_ai_action(['action' => 'run_dataset_auto_learn', 'args' => []], $workspaceRoot);
    maintenance_add_action($report, 'Run dataset auto-learn maintenance', (bool)$datasetRun['ok'], (string)$datasetRun['detail'], $datasetRun['extra'] ?? []);
} else {
    maintenance_add_action($report, 'Run dataset auto-learn maintenance', true, 'skipped: automated learning disabled', ['skipped' => true]);
}

if ($modelLearnEnabled) {
    $learningRun = maintenance_execute_ai_action(['action' => 'run_continuous_model_learning', 'args' => []], $workspaceRoot);
    maintenance_add_action($report, 'Run continuous model learning maintenance', (bool)$learningRun['ok'], (string)$learningRun['detail'], $learningRun['extra'] ?? []);
} else {
    maintenance_add_action($report, 'Run continuous model learning maintenance', true, 'skipped: automated learning disabled', ['skipped' => true]);
}

$latencyGuardRun = maintenance_execute_ai_action(['action' => 'guard_local_llm_latency', 'args' => []], $workspaceRoot);
maintenance_add_action($report, 'Run local LLM latency rollback guard', (bool)$latencyGuardRun['ok'], (string)$latencyGuardRun['detail'], $latencyGuardRun['extra'] ?? []);

$autoloadRun = maintenance_execute_ai_action(['action' => 'composer_dump_autoload', 'args' => []], $workspaceRoot);
maintenance_add_action($report, 'Optimize composer autoload map', (bool)$autoloadRun['ok'], (string)$autoloadRun['detail'], $autoloadRun['extra'] ?? []);

$aiPlanResult = maintenance_ai_plan($report);
if (!($aiPlanResult['ok'] ?? false)) {
    $report['ai']['raw_error'] = (string)($aiPlanResult['error'] ?? 'unknown');
    if (!empty($aiPlanResult['raw'])) {
        $report['ai']['raw_error'] .= ' raw=' . (string)$aiPlanResult['raw'];
    }
    maintenance_add_finding($report, 'medium', 'AI remediation unavailable', $report['ai']['raw_error']);
} else {
    $plan = is_array($aiPlanResult['plan'] ?? null) ? $aiPlanResult['plan'] : [];
    $report['ai']['recommendations'] = $plan;

    $actions = is_array($plan['actions'] ?? null) ? $plan['actions'] : [];
    foreach (array_slice($actions, 0, 6) as $step) {
        $title = 'AI action: ' . (string)($step['action'] ?? 'unknown');
        $result = maintenance_execute_ai_action($step, $workspaceRoot);
        maintenance_add_action($report, $title, (bool)$result['ok'], (string)$result['detail'], $result['extra'] ?? []);

        if (!($result['ok'] ?? false)) {
            $hint = '';
            if (isset($result['extra']['output']) && is_string($result['extra']['output'])) {
                $hint = substr((string)$result['extra']['output'], 0, 300);
            }
            maintenance_add_finding($report, 'medium', 'AI action failure', $title . ($hint !== '' ? ' -> ' . $hint : ''));
        }
    }
}

$report['finished_at'] = gmdate('c');
$report['duration_seconds'] = round(microtime(true) - $startedAt, 3);
$maintenanceStatus = (($report['summary']['failed_checks'] ?? 0) > 0 || ($report['summary']['actions_failed'] ?? 0) > 0) ? 'FAILED' : 'SUCCEEDED';

$discordSent = maintenance_send_discord_report($report);
$report['discord_report_sent'] = $discordSent;

if ($maintenanceTask !== []) {
    $maintenanceTask = chat_os_mark_task_result(
        $maintenanceTask,
        $maintenanceStatus,
        ['report' => $report],
        $maintenanceStatus === 'FAILED' ? 'MAINTENANCE_CHECK_FAILED' : null,
        $maintenanceStatus === 'FAILED' ? 'One or more maintenance checks or actions failed.' : null
    );
    chat_os_persist_task_record($maintenanceTask, ['job_name' => 'auto_maintenance_runner'], $dbOk ? $db : null);
}

if ($dbOk) {
    $db->query("CREATE TABLE IF NOT EXISTS maintenance_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NOT NULL,
        duration_seconds DECIMAL(10,3) NOT NULL,
        report_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_started_at (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $db->prepare("INSERT INTO maintenance_runs (started_at, finished_at, duration_seconds, report_json) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $started = gmdate('Y-m-d H:i:s', (int)$startedAt);
        $finished = gmdate('Y-m-d H:i:s');
        $duration = (float)$report['duration_seconds'];
        $json = json_encode($report, JSON_UNESCAPED_SLASHES);
        $stmt->bind_param('ssds', $started, $finished, $duration, $json);
        $stmt->execute();
        $stmt->close();
    }

    $db->close();
}

maintenance_log('[auto_maintenance] finished. discord_sent=' . ($discordSent ? 'yes' : 'no'));
exit(0);
