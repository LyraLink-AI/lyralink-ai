<?php

require_once __DIR__ . '/../api/security.php';

const INTRUSION_DISCORD_CHANNEL_ID = '1475657872862875727';
const ALERT_DEDUPE_MINUTES = 30;

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    exit(1);
}
$db->set_charset('utf8mb4');

$db->query("CREATE TABLE IF NOT EXISTS security_intrusion_alert_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint VARCHAR(190) NOT NULL UNIQUE,
    alert_type VARCHAR(64) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    detail VARCHAR(255) DEFAULT NULL,
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS security_daily_report_state (
    report_date DATE NOT NULL PRIMARY KEY,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("DELETE FROM security_intrusion_alert_cache WHERE sent_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
$db->query("DELETE FROM security_daily_report_state WHERE sent_at < DATE_SUB(NOW(), INTERVAL 10 DAY)");

function intrusion_table_exists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function intrusion_should_send(mysqli $db, string $fingerprint): bool {
    $stmt = $db->prepare("SELECT 1 FROM security_intrusion_alert_cache WHERE fingerprint = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $minutes = ALERT_DEDUPE_MINUTES;
    $stmt->bind_param('si', $fingerprint, $minutes);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return !$exists;
}

function intrusion_mark_sent(mysqli $db, string $fingerprint, string $type, string $detail): void {
    $stmt = $db->prepare("INSERT INTO security_intrusion_alert_cache (fingerprint, alert_type, detail, sent_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE alert_type = VALUES(alert_type), detail = VALUES(detail), sent_at = VALUES(sent_at)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sss', $fingerprint, $type, $detail);
    $stmt->execute();
    $stmt->close();
}

function intrusion_send_discord_alert(string $botToken, string $title, int $color, array $fields): bool {
    if ($botToken === '') {
        return false;
    }

    $payload = [
        'embeds' => [[
            'title' => $title,
            'description' => 'Automated intrusion monitor detected suspicious activity.',
            'color' => $color,
            'fields' => $fields,
            'timestamp' => gmdate('c'),
        ]],
    ];

    $endpoint = 'https://discord.com/api/v10/channels/' . rawurlencode(INTRUSION_DISCORD_CHANNEL_ID) . '/messages';

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $botToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code < 200 || $code >= 300) {
        error_log('[intrusion-monitor] discord send failed code=' . $code . ' err=' . $err);
        return false;
    }

    return true;
}

function intrusion_add_field(array &$fields, string $name, string $value, bool $inline = true): void {
    $fields[] = [
        'name' => $name,
        'value' => intrusion_limit_text($value, 1024),
        'inline' => $inline,
    ];
}

function intrusion_limit_text(string $value, int $limit): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
}

function intrusion_daily_report_sent(mysqli $db, string $reportDate): bool {
    $stmt = $db->prepare("SELECT 1 FROM security_daily_report_state WHERE report_date = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $reportDate);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function intrusion_mark_daily_report_sent(mysqli $db, string $reportDate): void {
    $stmt = $db->prepare("INSERT INTO security_daily_report_state (report_date, sent_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $reportDate);
    $stmt->execute();
    $stmt->close();
}

$botToken = trim((string)api_get_secret('BOT_SECRET_KEY', ''));
if ($botToken === '') {
    exit(0);
}

$alerts = [];

if (intrusion_table_exists($db, 'security_log')) {
    $failBurst = $db->query("SELECT ip, COUNT(*) AS c
        FROM security_log
        WHERE event_type = 'login_fail'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        GROUP BY ip
        HAVING c >= 5
        ORDER BY c DESC
        LIMIT 10");

    if ($failBurst instanceof mysqli_result) {
        while ($row = $failBurst->fetch_assoc()) {
            $ip = (string)($row['ip'] ?? 'unknown');
            $count = (int)($row['c'] ?? 0);
            $fp = 'login_fail_burst|' . $ip;
            if (!intrusion_should_send($db, $fp)) {
                continue;
            }

            $fields = [];
            intrusion_add_field($fields, 'Type', 'Brute Force / Login Fail Burst');
            intrusion_add_field($fields, 'IP', $ip);
            intrusion_add_field($fields, 'Failures (15m)', (string)$count);
            intrusion_add_field($fields, 'Action', 'Investigate source IP and consider edge firewall block.', false);

            $alerts[] = [
                'fingerprint' => $fp,
                'type' => 'login_fail_burst',
                'detail' => 'ip=' . $ip . ',count=' . $count,
                'title' => 'Intrusion Alert: Login Failure Burst',
                'color' => 0xEF4444,
                'fields' => $fields,
            ];
        }
        $failBurst->free();
    }

    $adminProbe = $db->query("SELECT ip, COUNT(*) AS c
        FROM security_log
        WHERE event_type = 'admin_access_denied'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
        GROUP BY ip
        HAVING c >= 3
        ORDER BY c DESC
        LIMIT 10");

    if ($adminProbe instanceof mysqli_result) {
        while ($row = $adminProbe->fetch_assoc()) {
            $ip = (string)($row['ip'] ?? 'unknown');
            $count = (int)($row['c'] ?? 0);
            $fp = 'admin_access_denied_burst|' . $ip;
            if (!intrusion_should_send($db, $fp)) {
                continue;
            }

            $fields = [];
            intrusion_add_field($fields, 'Type', 'Admin Endpoint Probing');
            intrusion_add_field($fields, 'IP', $ip);
            intrusion_add_field($fields, 'Denied Events (20m)', (string)$count);
            intrusion_add_field($fields, 'Action', 'Review request traces and block if malicious.', false);

            $alerts[] = [
                'fingerprint' => $fp,
                'type' => 'admin_probe',
                'detail' => 'ip=' . $ip . ',count=' . $count,
                'title' => 'Intrusion Alert: Admin Probe Pattern',
                'color' => 0xF97316,
                'fields' => $fields,
            ];
        }
        $adminProbe->free();
    }
}

if (intrusion_table_exists($db, 'auth_rate_limits')) {
    $blocked = $db->query("SELECT bucket, identifier, attempts, blocked_until
        FROM auth_rate_limits
        WHERE blocked_until IS NOT NULL
          AND blocked_until > NOW()
          AND attempts >= 8
        ORDER BY blocked_until DESC
        LIMIT 20");

    if ($blocked instanceof mysqli_result) {
        while ($row = $blocked->fetch_assoc()) {
            $bucket = (string)($row['bucket'] ?? 'auth');
            $identifier = (string)($row['identifier'] ?? 'unknown');
            $attempts = (int)($row['attempts'] ?? 0);
            $blockedUntil = (string)($row['blocked_until'] ?? '');
            $fp = 'rate_limit_block|' . $bucket . '|' . $identifier;
            if (!intrusion_should_send($db, $fp)) {
                continue;
            }

            $fields = [];
            intrusion_add_field($fields, 'Type', 'Rate Limit Lockout Triggered');
            intrusion_add_field($fields, 'Bucket', $bucket);
            intrusion_add_field($fields, 'Identifier', $identifier);
            intrusion_add_field($fields, 'Attempts', (string)$attempts);
            intrusion_add_field($fields, 'Blocked Until', $blockedUntil, false);

            $alerts[] = [
                'fingerprint' => $fp,
                'type' => 'rate_limit_lockout',
                'detail' => 'bucket=' . $bucket . ',identifier=' . $identifier . ',attempts=' . $attempts,
                'title' => 'Intrusion Alert: Rate Limit Lockout',
                'color' => 0xF59E0B,
                'fields' => $fields,
            ];
        }
        $blocked->free();
    }
}

foreach ($alerts as $alert) {
    $sent = intrusion_send_discord_alert($botToken, $alert['title'], $alert['color'], $alert['fields']);
    if ($sent) {
        intrusion_mark_sent($db, $alert['fingerprint'], $alert['type'], $alert['detail']);
    }
}

function intrusion_send_daily_security_report(mysqli $db, string $botToken): bool {
    $reportDate = date('Y-m-d');
    if (intrusion_daily_report_sent($db, $reportDate)) {
        return false;
    }

    $loginFailures = 0;
    $adminDenials = 0;
    $distinctIps = 0;
    $topIp = 'n/a';
    $topIpCount = 0;
    $activeLockouts = 0;
    $activeLockoutIdentifiers = 0;
    $topEventsText = 'n/a';

    if (intrusion_table_exists($db, 'security_log')) {
        $summary = $db->query("SELECT
                COALESCE(SUM(CASE WHEN event_type = 'login_fail' THEN 1 ELSE 0 END), 0) AS login_failures,
                COALESCE(SUM(CASE WHEN event_type = 'admin_access_denied' THEN 1 ELSE 0 END), 0) AS admin_denials,
                COALESCE(COUNT(DISTINCT CASE WHEN event_type IN ('login_fail', 'admin_access_denied') THEN ip END), 0) AS distinct_ips
            FROM security_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
              AND event_type IN ('login_fail', 'admin_access_denied')");

        if ($summary instanceof mysqli_result && ($row = $summary->fetch_assoc())) {
            $loginFailures = (int)($row['login_failures'] ?? 0);
            $adminDenials = (int)($row['admin_denials'] ?? 0);
            $distinctIps = (int)($row['distinct_ips'] ?? 0);
        }
        if ($summary instanceof mysqli_result) {
            $summary->free();
        }

        $events = $db->query("SELECT event_type, COUNT(*) AS c
            FROM security_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY event_type
            ORDER BY c DESC, event_type ASC
            LIMIT 3");

        if ($events instanceof mysqli_result) {
            $rows = [];
            while ($row = $events->fetch_assoc()) {
                $label = (string)($row['event_type'] ?? 'unknown');
                $count = (int)($row['c'] ?? 0);
                $rows[] = $label . ': ' . $count;
            }
            if ($rows) {
                $topEventsText = implode("\n", $rows);
            }
            $events->free();
        }

        $top = $db->query("SELECT ip, COUNT(*) AS c
            FROM security_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
              AND event_type IN ('login_fail', 'admin_access_denied')
              AND ip IS NOT NULL AND ip <> ''
            GROUP BY ip
            ORDER BY c DESC, ip ASC
            LIMIT 1");

        if ($top instanceof mysqli_result && ($row = $top->fetch_assoc())) {
            $topIp = (string)($row['ip'] ?? 'n/a');
            $topIpCount = (int)($row['c'] ?? 0);
        }
        if ($top instanceof mysqli_result) {
            $top->free();
        }
    }

    if (intrusion_table_exists($db, 'auth_rate_limits')) {
        $lockouts = $db->query("SELECT
                COALESCE(COUNT(*), 0) AS active_lockouts,
                COALESCE(COUNT(DISTINCT identifier), 0) AS active_identifiers
            FROM auth_rate_limits
            WHERE blocked_until IS NOT NULL
              AND blocked_until > NOW()
              AND attempts >= 8");

        if ($lockouts instanceof mysqli_result && ($row = $lockouts->fetch_assoc())) {
            $activeLockouts = (int)($row['active_lockouts'] ?? 0);
            $activeLockoutIdentifiers = (int)($row['active_identifiers'] ?? 0);
        }
        if ($lockouts instanceof mysqli_result) {
            $lockouts->free();
        }
    }

    $fields = [];
    intrusion_add_field($fields, 'Report Date', $reportDate);
    intrusion_add_field($fields, 'Window', 'Last 24 hours');
    intrusion_add_field($fields, 'Login Failures', (string)$loginFailures);
    intrusion_add_field($fields, 'Admin Denials', (string)$adminDenials);
    intrusion_add_field($fields, 'Distinct IPs', (string)$distinctIps);
    intrusion_add_field($fields, 'Top Events', $topEventsText, false);
    intrusion_add_field($fields, 'Top IP', $topIp . ' (' . $topIpCount . ')');
    intrusion_add_field($fields, 'Active Lockouts', (string)$activeLockouts, true);
    intrusion_add_field($fields, 'Locked Identifiers', (string)$activeLockoutIdentifiers, true);

    $payload = [
        'embeds' => [[
            'title' => 'Bot Logs: Daily Security Report',
            'description' => 'Automated security summary for the last 24 hours.',
            'color' => 0x2563EB,
            'fields' => $fields,
            'timestamp' => gmdate('c'),
            'footer' => [
                'text' => 'Lyralink security monitor · Discord bot logs',
            ],
        ]],
    ];

    $endpoint = 'https://discord.com/api/v10/channels/' . rawurlencode(INTRUSION_DISCORD_CHANNEL_ID) . '/messages';
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $botToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code < 200 || $code >= 300) {
        error_log('[intrusion-monitor] daily security report failed code=' . $code . ' err=' . $err);
        return false;
    }

    intrusion_mark_daily_report_sent($db, $reportDate);
    return true;
}

intrusion_send_daily_security_report($db, $botToken);
