<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['lyralinkai.com', 'www.lyralinkai.com'], true);
$forkModeEnv = api_get_secret('FORK_MODE', '');
$isForkMode = ($forkModeEnv === '1') || ($host !== '' && !$isPrimaryHost);
$allowUnauthForkAdmin = api_get_secret('ALLOW_UNAUTH_FORK_ADMIN', '0') === '1';

// ── AUTH CHECK — dev only ──
$dbHost = 'localhost';
$dbUser = 'app_user';
$dbPass = '';
$dbName = 'aicloud';
$devUsername = api_get_secret('ADMIN_DEV_USERNAME', 'developer');

$dbCfg = api_db_config([
    'host' => $dbHost,
    'user' => $dbUser,
    'pass' => $dbPass,
    'name' => $dbName,
]);
$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];

if ((empty($_SESSION['username']) || $_SESSION['username'] !== $devUsername) && !($isForkMode && $allowUnauthForkAdmin)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

function admin_table_exists(mysqli $db, string $table): bool {
    $tableEsc = $db->real_escape_string($table);
    $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tableEsc}' LIMIT 1");
    return $res ? (bool)$res->fetch_row() : false;
}

function admin_column_exists(mysqli $db, string $table, string $column): bool {
    $tableEsc = $db->real_escape_string($table);
    $columnEsc = $db->real_escape_string($column);
    $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$tableEsc}' AND column_name = '{$columnEsc}' LIMIT 1");
    return $res ? (bool)$res->fetch_row() : false;
}

function admin_billing_plan_prices(): array {
    return [
        'free' => 0.0,
        'basic' => 5.0,
        'pro' => 15.0,
        'enterprise' => 30.0,
    ];
}

function admin_billing_invoice_no(): string {
    return 'INV-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
}

function admin_build_invoice_note(array $customer): string {
    $plan = strtoupper((string)($customer['plan'] ?? 'free'));
    $provider = (string)($customer['provider'] ?? 'none');
    return "Subscription billing for {$plan} plan ({$provider})";
}

function admin_billing_audit(mysqli $db): array {
    $summary = [
        'webhook_24h_total' => 0,
        'webhook_24h_failed' => 0,
        'webhook_7d_total' => 0,
        'last_event_at' => null,
        'verification_enabled' => api_get_secret('PAYPAL_WEBHOOK_VERIFY', '1') === '1',
        'drift_candidates' => 0,
    ];
    $webhookRecent = [];
    $webhookFailures = [];
    $drift = [];

    if (admin_table_exists($db, 'paypal_webhook_events')) {
        $summaryStmt = $db->prepare("SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS total_24h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_24h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS total_7d,
            MAX(created_at) AS last_event_at
            FROM paypal_webhook_events");
        if ($summaryStmt) {
            $summaryStmt->execute();
            $row = $summaryStmt->get_result()->fetch_assoc();
            $summaryStmt->close();
            if ($row) {
                $summary['webhook_24h_total'] = (int)($row['total_24h'] ?? 0);
                $summary['webhook_24h_failed'] = (int)($row['failed_24h'] ?? 0);
                $summary['webhook_7d_total'] = (int)($row['total_7d'] ?? 0);
                $summary['last_event_at'] = (string)($row['last_event_at'] ?? '');
            }
        }

        $recentStmt = $db->prepare("SELECT event_id, event_type, status, message, resource_id, created_at
            FROM paypal_webhook_events
            ORDER BY id DESC
            LIMIT 20");
        if ($recentStmt) {
            $recentStmt->execute();
            $res = $recentStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $webhookRecent[] = [
                    'event_id' => (string)($r['event_id'] ?? ''),
                    'event_type' => (string)($r['event_type'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                    'message' => (string)($r['message'] ?? ''),
                    'resource_id' => (string)($r['resource_id'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }
            $recentStmt->close();
        }

        $failedStmt = $db->prepare("SELECT event_id, event_type, status, message, created_at
            FROM paypal_webhook_events
            WHERE status IN ('failed', 'ignored')
            ORDER BY id DESC
            LIMIT 20");
        if ($failedStmt) {
            $failedStmt->execute();
            $res = $failedStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $webhookFailures[] = [
                    'event_id' => (string)($r['event_id'] ?? ''),
                    'event_type' => (string)($r['event_type'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                    'message' => (string)($r['message'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }
            $failedStmt->close();
        }
    }

    if (admin_table_exists($db, 'users')) {
        $driftSql = "SELECT id, username, email, plan, paypal_sub_id, apple_product_id, apple_subscription_status
            FROM users
            WHERE
                (LOWER(TRIM(COALESCE(plan, 'free'))) <> 'free' AND COALESCE(paypal_sub_id, '') = '' AND (COALESCE(apple_product_id, '') = '' OR LOWER(TRIM(COALESCE(apple_subscription_status, ''))) NOT IN ('active','trial','grace_period')))
                OR (LOWER(TRIM(COALESCE(plan, 'free'))) = 'free' AND COALESCE(paypal_sub_id, '') <> '')
                OR (LOWER(TRIM(COALESCE(plan, 'free'))) = 'free' AND COALESCE(apple_product_id, '') <> '' AND LOWER(TRIM(COALESCE(apple_subscription_status, ''))) IN ('active','trial','grace_period'))
            ORDER BY id DESC
            LIMIT 30";
        $driftRes = $db->query($driftSql);
        if ($driftRes) {
            while ($u = $driftRes->fetch_assoc()) {
                $plan = strtolower(trim((string)($u['plan'] ?? 'free')));
                $paypal = trim((string)($u['paypal_sub_id'] ?? ''));
                $appleProduct = trim((string)($u['apple_product_id'] ?? ''));
                $appleStatus = strtolower(trim((string)($u['apple_subscription_status'] ?? '')));
                $reason = 'unknown';
                if ($plan !== 'free' && $paypal === '' && ($appleProduct === '' || !in_array($appleStatus, ['active', 'trial', 'grace_period'], true))) {
                    $reason = 'paid_plan_without_billing_link';
                } elseif ($plan === 'free' && $paypal !== '') {
                    $reason = 'free_plan_with_paypal_subscription';
                } elseif ($plan === 'free' && $appleProduct !== '' && in_array($appleStatus, ['active', 'trial', 'grace_period'], true)) {
                    $reason = 'free_plan_with_active_apple_subscription';
                }

                $drift[] = [
                    'user_id' => (int)($u['id'] ?? 0),
                    'username' => (string)($u['username'] ?? ''),
                    'email' => (string)($u['email'] ?? ''),
                    'plan' => $plan,
                    'paypal_sub_id' => $paypal,
                    'apple_product_id' => $appleProduct,
                    'apple_subscription_status' => $appleStatus,
                    'reason' => $reason,
                ];
            }
        }
    }

    $summary['drift_candidates'] = count($drift);

    return [
        'summary' => $summary,
        'webhook_recent' => $webhookRecent,
        'webhook_failures' => $webhookFailures,
        'drift' => $drift,
    ];
}

function admin_billing_snapshot(mysqli $db): array {
    $summary = [
        'active_subscriptions' => 0,
        'mrr' => 0.0,
        'at_risk' => 0,
        'new_30d' => 0,
        'credit_transfers_30d' => 0,
    ];
    $methods = ['paypal' => 0, 'apple' => 0, 'none' => 0];
    $customers = [];
    $invoices = [];

    if (!admin_table_exists($db, 'users')) {
        return compact('summary', 'methods', 'customers', 'invoices');
    }

    $possibleCols = ['id', 'username', 'email', 'plan', 'created_at', 'paypal_sub_id', 'apple_product_id', 'apple_subscription_status', 'apple_subscription_expires_at', 'credits'];
    $selectCols = [];
    foreach ($possibleCols as $col) {
        if (admin_column_exists($db, 'users', $col)) {
            $selectCols[] = $col;
        }
    }
    if (!in_array('id', $selectCols, true)) {
        return compact('summary', 'methods', 'customers', 'invoices');
    }

    $prices = admin_billing_plan_prices();
    $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM users ORDER BY id DESC LIMIT 300';
    $rows = $db->query($sql);
    $atRiskStatuses = ['expired', 'billing_retry', 'past_due', 'grace_period'];
    $activeStatuses = ['active', 'trial', 'grace_period'];
    $nowTs = time();

    if ($rows) {
        while ($u = $rows->fetch_assoc()) {
            $plan = strtolower(trim((string)($u['plan'] ?? 'free')));
            if ($plan === '') $plan = 'free';
            $paypal = trim((string)($u['paypal_sub_id'] ?? ''));
            $apple = trim((string)($u['apple_product_id'] ?? ''));
            $appleStatus = strtolower(trim((string)($u['apple_subscription_status'] ?? '')));
            $email = (string)($u['email'] ?? '');
            $username = (string)($u['username'] ?? ('user_' . (int)$u['id']));
            $createdAt = (string)($u['created_at'] ?? '');
            $nextBilling = (string)($u['apple_subscription_expires_at'] ?? '');

            $provider = 'none';
            if ($apple !== '') $provider = 'apple';
            elseif ($paypal !== '') $provider = 'paypal';
            $methods[$provider] = ($methods[$provider] ?? 0) + 1;

            $planPrice = (float)($prices[$plan] ?? 0.0);
            $hasBillingLink = ($paypal !== '' || $apple !== '');
            $isActive = $plan !== 'free' && ($hasBillingLink || in_array($appleStatus, $activeStatuses, true));
            $isAtRisk = in_array($appleStatus, $atRiskStatuses, true);

            if ($isActive) {
                $summary['active_subscriptions']++;
                $summary['mrr'] += $planPrice;
            }
            if ($isAtRisk) {
                $summary['at_risk']++;
            }

            if ($createdAt !== '' && strtotime($createdAt) >= strtotime('-30 days', $nowTs)) {
                $summary['new_30d']++;
            }

            if ($plan !== 'free' || $hasBillingLink) {
                $customers[] = [
                    'user_id' => (int)($u['id'] ?? 0),
                    'username' => $username,
                    'email' => $email,
                    'plan' => $plan,
                    'provider' => $provider,
                    'status' => $isAtRisk ? 'at_risk' : ($isActive ? 'active' : 'inactive'),
                    'mrr' => $planPrice,
                    'next_billing' => $nextBilling,
                    'credits' => (int)($u['credits'] ?? 0),
                ];

            }
        }
    }

    if (admin_table_exists($db, 'admin_invoices')) {
        $countRes = $db->query("SELECT COUNT(*) AS c FROM admin_invoices");
        $invoiceCount = $countRes ? (int)($countRes->fetch_assoc()['c'] ?? 0) : 0;
        if ($invoiceCount === 0 && $customers) {
            $seed = $db->prepare("INSERT INTO admin_invoices (invoice_no, user_id, customer_name, customer_email, amount, currency, status, due_date, source, note, created_at) VALUES (?, ?, ?, ?, ?, 'USD', ?, ?, 'subscription', ?, NOW())");
            if ($seed) {
                foreach (array_slice($customers, 0, 20) as $c) {
                    $invoiceNo = admin_billing_invoice_no();
                    $status = ($c['status'] ?? 'inactive') === 'at_risk' ? 'overdue' : ((($c['status'] ?? 'inactive') === 'active') ? 'paid' : 'draft');
                    $dueDate = !empty($c['next_billing']) ? (string)$c['next_billing'] : date('Y-m-d H:i:s', strtotime('+7 days'));
                    $note = admin_build_invoice_note($c);
                    $amount = (float)($c['mrr'] ?? 0);
                    $seed->bind_param('sissdsss', $invoiceNo, $c['user_id'], $c['username'], $c['email'], $amount, $status, $dueDate, $note);
                    $seed->execute();
                }
                $seed->close();
            }
        }

        $invRows = $db->query("SELECT id, invoice_no, user_id, customer_name, customer_email, amount, currency, status, due_date, source, emailed_at, paid_at, created_at FROM admin_invoices ORDER BY created_at DESC LIMIT 30");
        if ($invRows) {
            while ($r = $invRows->fetch_assoc()) {
                $invoices[] = [
                    'id' => (int)($r['id'] ?? 0),
                    'invoice_id' => (string)($r['invoice_no'] ?? ''),
                    'user_id' => (int)($r['user_id'] ?? 0),
                    'customer' => (string)($r['customer_name'] ?? ''),
                    'email' => (string)($r['customer_email'] ?? ''),
                    'amount' => (float)($r['amount'] ?? 0),
                    'currency' => (string)($r['currency'] ?? 'USD'),
                    'status' => (string)($r['status'] ?? 'draft'),
                    'due_date' => (string)($r['due_date'] ?? ''),
                    'source' => (string)($r['source'] ?? 'subscription'),
                    'emailed_at' => (string)($r['emailed_at'] ?? ''),
                    'paid_at' => (string)($r['paid_at'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }
        }
    }

    if (admin_table_exists($db, 'credit_transfers')) {
        $cr = $db->query("SELECT COUNT(*) AS c FROM credit_transfers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($cr) {
            $summary['credit_transfers_30d'] = (int)($cr->fetch_assoc()['c'] ?? 0);
        }
    }

    usort($customers, static function (array $a, array $b): int {
        return ($b['mrr'] <=> $a['mrr']) ?: ($b['user_id'] <=> $a['user_id']);
    });
    $customers = array_slice($customers, 0, 30);

    usort($invoices, static function (array $a, array $b): int {
        return strcmp((string)($b['due_date'] ?? ''), (string)($a['due_date'] ?? ''));
    });
    $invoices = array_slice($invoices, 0, 30);

    $audit = admin_billing_audit($db);
    return compact('summary', 'methods', 'customers', 'invoices', 'audit');
}

$db->query("CREATE TABLE IF NOT EXISTS desktop_download_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    version_tag VARCHAR(32) DEFAULT NULL,
    source VARCHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    referer VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_channel_created (channel, created_at),
    INDEX idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS admin_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(32) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('draft','paid','overdue','void') NOT NULL DEFAULT 'draft',
    due_date DATETIME NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'subscription',
    note VARCHAR(255) DEFAULT NULL,
    emailed_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_status_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'toggle_maintenance',
    'bot_restart',
    'bot_stop',
    'invoice_mark_paid',
    'invoice_void',
    'invoice_resend',
    'billing_customer_detail',
    'billing_audit',
]);

if ($isForkMode && in_array($action, ['toggle_maintenance', 'bot_restart', 'bot_stop'], true)) {
    echo json_encode(['success' => false, 'error' => 'Disabled in fork preview mode']);
    exit;
}

if ($isForkMode && in_array($action, ['invoice_mark_paid', 'invoice_void', 'invoice_resend'], true)) {
    echo json_encode(['success' => false, 'error' => 'Disabled in fork preview mode']);
    exit;
}

$flagFile = __DIR__ . '/../maintenance.flag';
$infoFile = __DIR__ . '/../maintenance.info';

// ── TOGGLE MAINTENANCE ──
if ($action === 'toggle_maintenance') {
    $eta = trim($_POST['eta'] ?? '');
    if (file_exists($flagFile)) {
        unlink($flagFile);
        @unlink($infoFile);
        echo json_encode(['success' => true, 'maintenance' => false]);
    } else {
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
        file_put_contents($infoFile, $eta);
        echo json_encode(['success' => true, 'maintenance' => true]);
    }
    exit;
}

// ── GET STATUS ──
if ($action === 'status') {
    $maintenance = file_exists($flagFile);
    $eta         = $maintenance && file_exists($infoFile) ? file_get_contents($infoFile) : '';

    // Site stats
    $usersStmt = $db->prepare("SELECT COUNT(*) c FROM users");
    $usersStmt->execute();
    $users = $usersStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $usersStmt->close();

    $convsStmt = $db->prepare("SELECT COUNT(*) c FROM user_convs");
    $convsStmt->execute();
    $convs = $convsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $convsStmt->close();

    $msgsStmt = $db->prepare("SELECT COUNT(*) c FROM user_conv_messages");
    $msgsStmt->execute();
    $msgs = $msgsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $msgsStmt->close();

    $datasetStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1");
    $datasetStmt->execute();
    $dataset = $datasetStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $datasetStmt->close();

    $apiKeysStmt = $db->prepare("SELECT COUNT(*) c FROM api_keys WHERE active = 1");
    $apiKeysStmt->execute();
    $apiKeys = $apiKeysStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $apiKeysStmt->close();

    $pendingStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 0");
    $pendingStmt->execute();
    $pending = $pendingStmt->get_result()->fetch_assoc()['c'] ?? 0;
    $pendingStmt->close();

    $downloadSummary = [
        'total' => 0,
        'downloads_24h' => 0,
        'downloads_7d' => 0,
        'unique_ips_24h' => 0,
    ];
    $summaryStmt = $db->prepare("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS downloads_24h,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS downloads_7d,
        COALESCE(COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN ip_address ELSE NULL END), 0) AS unique_ips_24h
        FROM desktop_download_events");
    if ($summaryStmt) {
        $summaryStmt->execute();
        $row = $summaryStmt->get_result()->fetch_assoc();
        if ($row) {
            $downloadSummary = [
                'total' => (int)$row['total'],
                'downloads_24h' => (int)$row['downloads_24h'],
                'downloads_7d' => (int)$row['downloads_7d'],
                'unique_ips_24h' => (int)$row['unique_ips_24h'],
            ];
        }
        $summaryStmt->close();
    }

    $downloadRecent = [];
    $recentStmt = $db->prepare("SELECT channel, source, version_tag, ip_address, user_agent, created_at
        FROM desktop_download_events
        ORDER BY id DESC
        LIMIT 20");
    if ($recentStmt) {
        $recentStmt->execute();
        $res = $recentStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $downloadRecent[] = [
                'channel' => (string)($row['channel'] ?? 'stable'),
                'source' => (string)($row['source'] ?? ''),
                'version' => (string)($row['version_tag'] ?? ''),
                'ip' => (string)($row['ip_address'] ?? ''),
                'ua' => (string)($row['user_agent'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
        $recentStmt->close();
    }

    // Bot status via pgrep
    $botRunning = false;
    $botUptime  = null;
    if (function_exists('shell_exec')) {
        $pid = trim(shell_exec("pgrep -f 'node.*index.js' 2>/dev/null") ?? '');
        $botRunning = !empty($pid);
        if ($botRunning) {
            $etimeRaw = trim(shell_exec("ps -o etimes= -p $pid 2>/dev/null") ?? '');
            if (is_numeric($etimeRaw)) {
                $s = (int)$etimeRaw;
                $h = floor($s / 3600); $m = floor(($s % 3600) / 60); $s = $s % 60;
                $botUptime = ($h > 0 ? "{$h}h " : '') . ($m > 0 ? "{$m}m " : '') . "{$s}s";
            }
        }
    }

    echo json_encode([
        'success'     => true,
        'maintenance' => $maintenance,
        'eta'         => $eta,
        'stats'       => compact('users', 'convs', 'msgs', 'dataset', 'apiKeys', 'pending'),
        'billing'     => admin_billing_snapshot($db),
        'downloads'   => ['summary' => $downloadSummary, 'recent' => $downloadRecent],
        'bot'         => ['running' => $botRunning, 'uptime' => $botUptime],
    ]);
    exit;
}

if ($action === 'billing_customer_detail') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid user']);
        exit;
    }

    $user = null;
    $stmt = $db->prepare("SELECT id, username, email, plan, credits, created_at, paypal_sub_id, apple_product_id, apple_subscription_status, apple_subscription_expires_at FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $invoices = [];
    $inv = $db->prepare("SELECT id, invoice_no, amount, currency, status, due_date, source, emailed_at, paid_at, created_at, note FROM admin_invoices WHERE user_id = ? ORDER BY created_at DESC LIMIT 25");
    if ($inv) {
        $inv->bind_param('i', $userId);
        $inv->execute();
        $res = $inv->get_result();
        while ($r = $res->fetch_assoc()) {
            $invoices[] = $r;
        }
        $inv->close();
    }

    $ledger = [];
    if (admin_table_exists($db, 'credit_transfers')) {
        $lg = $db->prepare("SELECT transfer_ref, transfer_type, amount, status, note, created_at FROM credit_transfers WHERE to_user_id = ? OR from_user_id = ? ORDER BY id DESC LIMIT 20");
        if ($lg) {
            $lg->bind_param('ii', $userId, $userId);
            $lg->execute();
            $res = $lg->get_result();
            while ($r = $res->fetch_assoc()) {
                $ledger[] = $r;
            }
            $lg->close();
        }
    }

    echo json_encode(['success' => true, 'customer' => $user, 'invoices' => $invoices, 'ledger' => $ledger]);
    exit;
}

if ($action === 'billing_audit') {
    echo json_encode([
        'success' => true,
        'audit' => admin_billing_audit($db),
    ]);
    exit;
}

if (in_array($action, ['invoice_mark_paid', 'invoice_void', 'invoice_resend'], true)) {
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid invoice']);
        exit;
    }

    if ($action === 'invoice_mark_paid') {
        $stmt = $db->prepare("UPDATE admin_invoices SET status = 'paid', paid_at = NOW() WHERE id = ? LIMIT 1");
    } elseif ($action === 'invoice_void') {
        $stmt = $db->prepare("UPDATE admin_invoices SET status = 'void' WHERE id = ? LIMIT 1");
    } else {
        $stmt = $db->prepare("UPDATE admin_invoices SET emailed_at = NOW() WHERE id = ? LIMIT 1");
    }
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Action failed']);
        exit;
    }
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode(['success' => false, 'error' => 'Invoice not updated']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── BOT CONTROL ──
if ($action === 'bot_restart') {
    if (function_exists('shell_exec')) {
        shell_exec('pm2 restart lyralink-bot > /dev/null 2>&1 &');
        echo json_encode(['success' => true, 'message' => 'Restart signal sent']);
    } else {
        echo json_encode(['success' => false, 'error' => 'shell_exec not available']);
    }
    exit;
}

if ($action === 'bot_stop') {
    if (function_exists('shell_exec')) {
        shell_exec('pm2 stop lyralink-bot > /dev/null 2>&1 &');
        echo json_encode(['success' => true, 'message' => 'Stop signal sent']);
    } else {
        echo json_encode(['success' => false, 'error' => 'shell_exec not available']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>