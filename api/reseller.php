<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';
lyra_session_boot();
api_json_headers();

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }
$db->set_charset('utf8mb4');

// ════════════════════════════════
// SCHEMA BOOTSTRAP
// ════════════════════════════════
$db->query("CREATE TABLE IF NOT EXISTS reseller_applications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    company VARCHAR(200) NOT NULL,
    website VARCHAR(255) DEFAULT NULL,
    use_case TEXT NOT NULL,
    client_count VARCHAR(50) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(500) DEFAULT NULL,
    reviewed_by VARCHAR(100) DEFAULT NULL,
    reviewed_at DATETIME NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_created (status, created_at),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS resellers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    application_id INT UNSIGNED NULL,
    company_name VARCHAR(200) NOT NULL,
    custom_domain VARCHAR(255) DEFAULT NULL,
    logo_url VARCHAR(500) DEFAULT NULL,
    accent_color VARCHAR(10) DEFAULT '#7c3aed',
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    invite_token VARCHAR(64) NOT NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_paid_out DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user (user_id),
    UNIQUE KEY uq_invite_token (invite_token),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("ALTER TABLE resellers MODIFY COLUMN status ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active'");

$db->query("CREATE TABLE IF NOT EXISTS reseller_clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reseller_id INT UNSIGNED NOT NULL,
    client_user_id INT NOT NULL,
    added_via ENUM('invite','manual','application') NOT NULL DEFAULT 'invite',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client (client_user_id),
    KEY idx_reseller (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS reseller_earnings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reseller_id INT UNSIGNED NOT NULL,
    client_user_id INT NOT NULL,
    transaction_ref VARCHAR(128) NOT NULL,
    plan VARCHAR(50) NOT NULL,
    gross_amount DECIMAL(10,2) NOT NULL,
    commission_rate DECIMAL(5,2) NOT NULL,
    earned_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','paid_out') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tx_ref (transaction_ref),
    KEY idx_reseller_status (reseller_id, status, created_at),
    KEY idx_client (client_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS reseller_invite_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reseller_id INT UNSIGNED NOT NULL,
    event_type ENUM('invite_resolve','client_linked') NOT NULL DEFAULT 'invite_resolve',
    token VARCHAR(64) NOT NULL,
    user_id INT NULL,
    utm_source VARCHAR(120) DEFAULT NULL,
    utm_medium VARCHAR(120) DEFAULT NULL,
    utm_campaign VARCHAR(160) DEFAULT NULL,
    landing_path VARCHAR(255) DEFAULT NULL,
    referrer_host VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reseller_event_time (reseller_id, event_type, created_at),
    KEY idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("ALTER TABLE resellers ADD COLUMN IF NOT EXISTS onboarding_mode VARCHAR(20) NOT NULL DEFAULT 'guided'");
$db->query("ALTER TABLE resellers ADD COLUMN IF NOT EXISTS growth_goal_clients_30d INT NOT NULL DEFAULT 5");
$db->query("ALTER TABLE resellers ADD COLUMN IF NOT EXISTS alert_webhook_url VARCHAR(500) DEFAULT NULL");

// Mark invite token column on users (for new registrations via reseller link)
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS reseller_ref VARCHAR(64) DEFAULT NULL");

$action = api_action();

$operatorDefaultMode = strtolower(trim((string)api_get_secret('OPERATOR_ONBOARDING_DEFAULT_MODE', 'guided')));
if (!in_array($operatorDefaultMode, ['guided', 'self_serve', 'hybrid'], true)) {
    $operatorDefaultMode = 'guided';
}
$operatorDefaultGrowthGoal = max(1, min(200, (int)api_get_secret('OPERATOR_GROWTH_GOAL_CLIENTS_30D', '5')));

api_enforce_post_and_origin_for_actions([
    'apply',
    'update_branding',
    'add_client_by_email',
    'remove_client',
    'admin_start_impersonation',
    'admin_stop_impersonation',
    'admin_approve',
    'admin_reject',
    'admin_set_commission',
    'admin_toggle_status',
    'admin_mark_paid_out',
    'admin_remove_reseller',
    'set_operator_integrations',
    'set_operator_goals',
    'send_operator_test_alert',
]);

// ── helpers ──────────────────────────────────────────
function reseller_require_auth(): int {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
    }
    return (int)$_SESSION['user_id'];
}

function reseller_support_email(): string {
    return trim((string)api_get_secret('SUPPORT_EMAIL', 'support@lyralinkai.com'));
}

function reseller_require_reseller(mysqli $db): array {
    $uid = reseller_require_auth();
    $stmt = $db->prepare("SELECT * FROM resellers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Not an active reseller', 'code' => 'not_reseller']);
        exit;
    }

    $status = strtolower(trim((string)($row['status'] ?? 'active')));
    if ($status !== 'active') {
        $supportEmail = reseller_support_email();
        $defaultError = $status === 'terminated'
            ? 'Your operator account has been terminated. Contact support for review.'
            : 'Your operator account is suspended. Contact support to restore access.';
        echo json_encode([
            'success' => false,
            'error' => $defaultError,
            'code' => $status === 'terminated' ? 'reseller_terminated' : 'reseller_suspended',
            'reseller_status' => $status,
            'admin_note' => (string)($row['notes'] ?? ''),
            'support_email' => $supportEmail,
            'support_url' => '/pages/support.php',
        ]);
        exit;
    }

    return $row;
}

function reseller_require_admin(): void {
    $dev = api_get_secret('ADMIN_DEV_USERNAME', 'developer');
    if (empty($_SESSION['username']) || $_SESSION['username'] !== $dev) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }
}

function reseller_generate_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function reseller_user_by_id(mysqli $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT id, username, email, plan FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function reseller_is_impersonating(): bool {
    return !empty($_SESSION['admin_impersonation']) && is_array($_SESSION['admin_impersonation']);
}

function reseller_trim_text(?string $value, int $max): ?string {
    if ($value === null) return null;
    $v = trim($value);
    if ($v === '') return null;
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function reseller_referrer_host(): ?string {
    $raw = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($raw === '') return null;
    $host = parse_url($raw, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return null;
    return mb_substr(strtolower($host), 0, 255);
}

function reseller_record_invite_event(mysqli $db, int $rid, string $eventType, string $token, ?int $userId = null): void {
    $utmSource = reseller_trim_text((string)($_REQUEST['utm_source'] ?? ''), 120);
    $utmMedium = reseller_trim_text((string)($_REQUEST['utm_medium'] ?? ''), 120);
    $utmCampaign = reseller_trim_text((string)($_REQUEST['utm_campaign'] ?? ''), 160);
    $landingPath = reseller_trim_text((string)($_REQUEST['path'] ?? $_REQUEST['landing_path'] ?? ''), 255);
    $refHost = reseller_referrer_host();
    $ip = reseller_trim_text((string)($_SERVER['REMOTE_ADDR'] ?? ''), 45);
    $ua = reseller_trim_text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    $uid = $userId ?: null;

    $stmt = $db->prepare("INSERT INTO reseller_invite_events
        (reseller_id, event_type, token, user_id, utm_source, utm_medium, utm_campaign, landing_path, referrer_host, ip_address, user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) return;
    $stmt->bind_param('ississsssss', $rid, $eventType, $token, $uid, $utmSource, $utmMedium, $utmCampaign, $landingPath, $refHost, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

function reseller_http_post_json(string $url, array $payload): bool {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function reseller_send_operator_alert(array $reseller, string $event, array $data = []): bool {
    $url = trim((string)($reseller['alert_webhook_url'] ?? ''));
    if ($url === '') {
        $url = trim((string)api_get_secret('OPERATOR_ALERT_WEBHOOK_URL', ''));
    }
    if ($url === '') return false;
    $payload = [
        'source' => 'lyralink_operator',
        'event' => $event,
        'timestamp' => gmdate('c'),
        'operator' => [
            'id' => (int)($reseller['id'] ?? 0),
            'user_id' => (int)($reseller['user_id'] ?? 0),
            'company_name' => (string)($reseller['company_name'] ?? ''),
        ],
        'data' => $data,
    ];
    return reseller_http_post_json($url, $payload);
}

function reseller_plan_price_map(): array {
    return [
        'free' => 0.0,
        'basic' => 5.0,
        'pro' => 15.0,
        'enterprise' => 30.0,
    ];
}

function reseller_operator_playbook(array $ctx): array {
    $clients = (int)($ctx['total_clients'] ?? 0);
    $new30 = (int)($ctx['new_clients_30d'] ?? 0);
    $pending = (float)($ctx['pending_payout'] ?? 0.0);
    $monthly = (float)($ctx['mrr_estimate'] ?? 0.0);
    $commission = (float)($ctx['commission_rate'] ?? 0.0);
    $shareInvite = (float)($ctx['invite_share_pct'] ?? 0.0);

    $actions = [];

    if ($clients < 5) {
        $actions[] = [
            'priority' => 'high',
            'title' => 'Activate first five managed clients',
            'why' => 'Early client density is the strongest predictor of payout consistency.',
            'tasks' => [
                'Send your acquisition link to warm leads with a time-boxed onboarding offer.',
                'Use API docs + embed snippet to reduce setup friction for non-technical clients.',
                'Convert one manual client via email attach each day this week.',
            ],
        ];
    }

    if ($new30 <= 1 && $clients >= 5) {
        $actions[] = [
            'priority' => 'high',
            'title' => 'Rebuild acquisition velocity',
            'why' => 'Low 30-day additions indicate funnel slowdown.',
            'tasks' => [
                'Regenerate invite token and launch a fresh campaign link in your channels.',
                'Create a short onboarding guide mapped to your primary client persona.',
                'Track weekly additions target: +3 net clients every 30 days.',
            ],
        ];
    }

    if ($pending > max(50.0, $monthly * 0.6)) {
        $actions[] = [
            'priority' => 'medium',
            'title' => 'Reduce payout backlog risk',
            'why' => 'Pending earnings are accumulating faster than settled flow.',
            'tasks' => [
                'Review pending payout age and close settlement cycles weekly.',
                'Prioritize clients with active subscription status checks.',
                'Use admin visibility for failed billing follow-up workflows.',
            ],
        ];
    }

    if ($shareInvite < 50.0 && $clients >= 8) {
        $actions[] = [
            'priority' => 'medium',
            'title' => 'Increase attributed link conversion',
            'why' => 'Manual account linking is scaling overhead; invite flow should dominate.',
            'tasks' => [
                'Place your acquisition link in every onboarding message and landing CTA.',
                'Deploy widget on high-intent pages using your operator token.',
                'Promote one canonical signup path to reduce attribution loss.',
            ],
        ];
    }

    if ($commission < 15.0) {
        $actions[] = [
            'priority' => 'low',
            'title' => 'Review commission-to-support balance',
            'why' => 'Low commission can squeeze support capacity as volume grows.',
            'tasks' => [
                'Benchmark average support time per active client.',
                'Ensure offer pricing keeps healthy spread after usage charges.',
                'Escalate commission review once monthly client growth is stable.',
            ],
        ];
    }

    if (empty($actions)) {
        $actions[] = [
            'priority' => 'low',
            'title' => 'Maintain operator momentum',
            'why' => 'Core growth indicators are healthy.',
            'tasks' => [
                'Expand to a second niche with a tailored onboarding flow.',
                'Add one automation for billing and one for support escalation.',
                'Review pricing and model routing multipliers monthly.',
            ],
        ];
    }

    return $actions;
}

// ════════════════════════════════
// ADMIN — start impersonation
// ════════════════════════════════
if ($action === 'admin_start_impersonation') {
    reseller_require_admin();

    if (reseller_is_impersonating()) {
        $imp = $_SESSION['admin_impersonation'];
        $activeTarget = (int)($imp['target_user_id'] ?? 0);
        $requestedTarget = (int)($_POST['user_id'] ?? 0);
        if ($activeTarget > 0 && $requestedTarget > 0 && $activeTarget === $requestedTarget) {
            echo json_encode(['success' => true, 'redirect' => '/chat.php', 'already_active' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Impersonation already active. Return to admin first.']);
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $redirect = trim((string)($_POST['redirect'] ?? '/chat.php'));
    if ($targetUserId <= 0) {
        echo json_encode(['success' => false, 'error' => 'user_id required']);
        exit;
    }

    $target = reseller_user_by_id($db, $targetUserId);
    if (!$target) {
        echo json_encode(['success' => false, 'error' => 'Target user not found']);
        exit;
    }

    $currentAdmin = [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => (string)($_SESSION['username'] ?? ''),
        'email' => (string)($_SESSION['user_email'] ?? ''),
        'plan' => (string)($_SESSION['plan'] ?? 'free'),
        'started_at' => gmdate('c'),
        'target_user_id' => (int)$target['id'],
        'target_username' => (string)$target['username'],
    ];

    $_SESSION['admin_impersonation'] = $currentAdmin;
    $_SESSION['user_id'] = (int)$target['id'];
    $_SESSION['username'] = (string)$target['username'];
    $_SESSION['user_email'] = (string)($target['email'] ?? '');
    $_SESSION['plan'] = (string)($target['plan'] ?? 'free');
    session_regenerate_id(true);

    if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, '\\') || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
        $redirect = '/chat.php';
    }

    echo json_encode([
        'success' => true,
        'redirect' => $redirect,
        'impersonating' => [
            'user_id' => (int)$target['id'],
            'username' => (string)$target['username'],
        ],
    ]);
    exit;
}

// ════════════════════════════════
// ADMIN — stop impersonation
// ════════════════════════════════
if ($action === 'admin_stop_impersonation') {
    if (!reseller_is_impersonating()) {
        echo json_encode(['success' => true, 'redirect' => '/pages/reseller_admin.php', 'already_stopped' => true]);
        exit;
    }

    $imp = $_SESSION['admin_impersonation'];

    $adminUserId = (int)($imp['user_id'] ?? 0);
    $adminUsername = (string)($imp['username'] ?? '');
    $adminEmail = (string)($imp['email'] ?? '');
    $adminPlan = (string)($imp['plan'] ?? 'free');

    if ($adminUserId > 0) {
        $_SESSION['user_id'] = $adminUserId;
    } else {
        unset($_SESSION['user_id']);
    }
    if ($adminUsername !== '') {
        $_SESSION['username'] = $adminUsername;
    }
    if ($adminEmail !== '') {
        $_SESSION['user_email'] = $adminEmail;
    } else {
        unset($_SESSION['user_email']);
    }
    $_SESSION['plan'] = $adminPlan;
    unset($_SESSION['admin_impersonation']);
    session_regenerate_id(true);

    echo json_encode(['success' => true, 'redirect' => '/pages/reseller_admin.php']);
    exit;
}

// ════════════════════════════════
// APPLY — submit reseller application
// ════════════════════════════════
if ($action === 'apply') {
    $userId  = empty($_SESSION['user_id']) ? null : (int)$_SESSION['user_id'];
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $useCase = trim($_POST['use_case'] ?? '');
    $clientCount = trim($_POST['client_count'] ?? '');

    if (!$name || !$email || !$company || !$useCase) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email']); exit;
    }
    if (mb_strlen($useCase) < 30) {
        echo json_encode(['success' => false, 'error' => 'Please describe your use case in more detail']); exit;
    }

    // Check for duplicate pending application by email
    $stmt = $db->prepare("SELECT id FROM reseller_applications WHERE email = ? AND status = 'pending'");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) {
        echo json_encode(['success' => false, 'error' => 'An application with this email is already pending']); exit;
    }

    // Check if already a reseller
    if ($userId) {
        $stmt = $db->prepare("SELECT id FROM resellers WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $alreadyReseller = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($alreadyReseller) {
            echo json_encode(['success' => false, 'error' => 'You are already an active reseller']); exit;
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare("INSERT INTO reseller_applications (user_id, name, email, company, website, use_case, client_count, ip_address) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('isssssss', $userId, $name, $email, $company, $website, $useCase, $clientCount, $ip);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Application submitted. We will review it within 1-2 business days.']);
    exit;
}

// ════════════════════════════════
// GET DASHBOARD — reseller stats
// ════════════════════════════════
if ($action === 'get_dashboard') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $clients = $db->query("SELECT COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid")->fetch_assoc()['cnt'];

    $earnings = $db->query("SELECT
        COALESCE(SUM(CASE WHEN status='pending' THEN earned_amount ELSE 0 END),0) AS pending,
        COALESCE(SUM(CASE WHEN status='paid_out' THEN earned_amount ELSE 0 END),0) AS paid_out,
        COALESCE(SUM(earned_amount),0) AS total
        FROM reseller_earnings WHERE reseller_id = $rid")->fetch_assoc();

    $thisMonth = $db->query("SELECT COALESCE(SUM(earned_amount),0) AS amount
        FROM reseller_earnings WHERE reseller_id = $rid
        AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetch_assoc()['amount'];

    echo json_encode([
        'success' => true,
        'reseller' => [
            'id' => $rid,
            'company_name' => $reseller['company_name'],
            'custom_domain' => $reseller['custom_domain'],
            'logo_url' => $reseller['logo_url'],
            'accent_color' => $reseller['accent_color'],
            'commission_rate' => (float)$reseller['commission_rate'],
            'invite_token' => $reseller['invite_token'],
            'status' => $reseller['status'],
            'onboarding_mode' => (string)($reseller['onboarding_mode'] ?? $operatorDefaultMode),
            'growth_goal_clients_30d' => max(1, (int)($reseller['growth_goal_clients_30d'] ?? $operatorDefaultGrowthGoal)),
            'has_alert_webhook' => trim((string)($reseller['alert_webhook_url'] ?? '')) !== '',
            'alert_webhook_url' => (string)($reseller['alert_webhook_url'] ?? ''),
        ],
        'stats' => [
            'total_clients' => (int)$clients,
            'earnings_pending' => (float)$earnings['pending'],
            'earnings_paid_out' => (float)$earnings['paid_out'],
            'earnings_total' => (float)$earnings['total'],
            'earnings_this_month' => (float)$thisMonth,
        ],
    ]);
    exit;
}

// ════════════════════════════════
// GET OPERATOR OVERVIEW
// ════════════════════════════════
if ($action === 'get_operator_overview') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $clientsTotalRow = $db->query("SELECT COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid")->fetch_assoc();
    $totalClients = (int)($clientsTotalRow['cnt'] ?? 0);

    $newClients30Row = $db->query("SELECT COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();
    $newClientsPrev30Row = $db->query("SELECT COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();
    $newClients30 = (int)($newClients30Row['cnt'] ?? 0);
    $newClientsPrev30 = (int)($newClientsPrev30Row['cnt'] ?? 0);

    $earn30Row = $db->query("SELECT COALESCE(SUM(earned_amount),0) AS amount FROM reseller_earnings WHERE reseller_id = $rid AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();
    $earnPrev30Row = $db->query("SELECT COALESCE(SUM(earned_amount),0) AS amount FROM reseller_earnings WHERE reseller_id = $rid AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();
    $earn30 = (float)($earn30Row['amount'] ?? 0.0);
    $earnPrev30 = (float)($earnPrev30Row['amount'] ?? 0.0);

    $pendingRow = $db->query("SELECT COALESCE(SUM(earned_amount),0) AS pending FROM reseller_earnings WHERE reseller_id = $rid AND status = 'pending'")->fetch_assoc();
    $pendingPayout = (float)($pendingRow['pending'] ?? 0.0);

    $planRows = $db->query("SELECT LOWER(COALESCE(u.plan,'free')) AS plan_code, COUNT(*) AS cnt
        FROM reseller_clients rc
        JOIN users u ON u.id = rc.client_user_id
        WHERE rc.reseller_id = $rid
        GROUP BY LOWER(COALESCE(u.plan,'free'))");
    $planMix = [];
    while ($planRows && ($r = $planRows->fetch_assoc())) {
        $plan = (string)($r['plan_code'] ?? 'free');
        $planMix[] = [
            'plan' => $plan,
            'count' => (int)($r['cnt'] ?? 0),
        ];
    }

    $viaRows = $db->query("SELECT added_via, COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid GROUP BY added_via");
    $bySource = ['invite' => 0, 'manual' => 0, 'application' => 0];
    while ($viaRows && ($r = $viaRows->fetch_assoc())) {
        $k = strtolower((string)($r['added_via'] ?? 'invite'));
        if (isset($bySource[$k])) {
            $bySource[$k] = (int)$r['cnt'];
        }
    }

    $inviteSharePct = $totalClients > 0 ? ((float)$bySource['invite'] / (float)$totalClients) * 100.0 : 0.0;

    $prices = reseller_plan_price_map();
    $mrrEstimate = 0.0;
    foreach ($planMix as $mix) {
        $p = strtolower((string)$mix['plan']);
        $mrrEstimate += (float)($prices[$p] ?? 0.0) * (int)$mix['count'];
    }

    $growthPct = 0.0;
    if ($newClientsPrev30 > 0) {
        $growthPct = (($newClients30 - $newClientsPrev30) / $newClientsPrev30) * 100.0;
    } elseif ($newClients30 > 0) {
        $growthPct = 100.0;
    }

    $supportLoad = $totalClients > 0 ? ($pendingPayout / max(1.0, $mrrEstimate)) * 100.0 : 0.0;
    $operatorCtx = [
        'total_clients' => $totalClients,
        'new_clients_30d' => $newClients30,
        'new_clients_prev_30d' => $newClientsPrev30,
        'pending_payout' => $pendingPayout,
        'mrr_estimate' => $mrrEstimate,
        'commission_rate' => (float)($reseller['commission_rate'] ?? 0.0),
        'invite_share_pct' => $inviteSharePct,
    ];

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $username = (string)($_SESSION['username'] ?? '');
    $org = null;
    if ($uid > 0) {
        $org = saas_context($db, $uid, $username);
    }

    $campaignRows = $db->query("SELECT
        COALESCE(NULLIF(TRIM(utm_campaign), ''), '(none)') AS campaign,
        COUNT(*) AS event_count,
        SUM(CASE WHEN event_type = 'client_linked' THEN 1 ELSE 0 END) AS linked_count,
        MAX(created_at) AS last_seen
        FROM reseller_invite_events
        WHERE reseller_id = $rid
        GROUP BY COALESCE(NULLIF(TRIM(utm_campaign), ''), '(none)')
        ORDER BY linked_count DESC, event_count DESC
        LIMIT 15");
    $campaigns = [];
    while ($campaignRows && ($row = $campaignRows->fetch_assoc())) {
        $events = (int)($row['event_count'] ?? 0);
        $linked = (int)($row['linked_count'] ?? 0);
        $campaigns[] = [
            'campaign' => (string)($row['campaign'] ?? '(none)'),
            'events' => $events,
            'linked' => $linked,
            'conversion_pct' => $events > 0 ? round(($linked / $events) * 100.0, 2) : 0.0,
            'last_seen' => (string)($row['last_seen'] ?? ''),
        ];
    }

    echo json_encode([
        'success' => true,
        'overview' => [
            'total_clients' => $totalClients,
            'new_clients_30d' => $newClients30,
            'new_clients_prev_30d' => $newClientsPrev30,
            'client_growth_pct' => round($growthPct, 2),
            'earnings_30d' => round($earn30, 2),
            'earnings_prev_30d' => round($earnPrev30, 2),
            'pending_payout' => round($pendingPayout, 2),
            'estimated_mrr' => round($mrrEstimate, 2),
            'invite_share_pct' => round($inviteSharePct, 2),
            'support_load_index' => round($supportLoad, 2),
            'client_source_mix' => $bySource,
            'plan_mix' => $planMix,
            'active_org' => $org,
            'growth_goal_clients_30d' => max(1, (int)($reseller['growth_goal_clients_30d'] ?? $operatorDefaultGrowthGoal)),
            'onboarding_mode' => (string)($reseller['onboarding_mode'] ?? $operatorDefaultMode),
        ],
        'campaigns' => $campaigns,
        'playbook' => reseller_operator_playbook($operatorCtx),
    ]);
    exit;
}

if ($action === 'set_operator_goals') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $goal = (int)($_POST['growth_goal_clients_30d'] ?? 5);
    $goal = max(1, min(200, $goal));
    $mode = strtolower(trim((string)($_POST['onboarding_mode'] ?? 'guided')));
    if (!in_array($mode, ['guided', 'self_serve', 'hybrid'], true)) {
        $mode = 'guided';
    }

    $stmt = $db->prepare("UPDATE resellers SET growth_goal_clients_30d = ?, onboarding_mode = ? WHERE id = ?");
    $stmt->bind_param('isi', $goal, $mode, $rid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'growth_goal_clients_30d' => $goal, 'onboarding_mode' => $mode]);
    exit;
}

if ($action === 'set_operator_integrations') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $url = trim((string)($_POST['alert_webhook_url'] ?? ''));
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Webhook URL must be a valid URL']);
        exit;
    }
    if ($url !== '' && !preg_match('#^https://#i', $url)) {
        echo json_encode(['success' => false, 'error' => 'Webhook URL must use https']);
        exit;
    }
    $url = $url === '' ? null : mb_substr($url, 0, 500);

    $stmt = $db->prepare("UPDATE resellers SET alert_webhook_url = ? WHERE id = ?");
    $stmt->bind_param('si', $url, $rid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'has_alert_webhook' => $url !== null]);
    exit;
}

if ($action === 'send_operator_test_alert') {
    $reseller = reseller_require_reseller($db);
    $ok = reseller_send_operator_alert($reseller, 'operator.test_alert', [
        'message' => 'Operator integration test alert',
    ]);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Unable to deliver test alert']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// GET CLIENTS
// ════════════════════════════════
if ($action === 'get_clients') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $rows = $db->query("SELECT rc.id, rc.client_user_id, rc.added_via, rc.created_at,
        u.username, u.email, u.plan,
        COALESCE(SUM(re.earned_amount),0) AS total_earned
        FROM reseller_clients rc
        JOIN users u ON u.id = rc.client_user_id
        LEFT JOIN reseller_earnings re ON re.client_user_id = rc.client_user_id AND re.reseller_id = $rid
        WHERE rc.reseller_id = $rid
        GROUP BY rc.id
        ORDER BY rc.created_at DESC
        LIMIT $limit OFFSET $offset");

    $clients = [];
    while ($r = $rows->fetch_assoc()) {
        $clients[] = [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['client_user_id'],
            'username' => $r['username'],
            'email' => $r['email'],
            'plan' => $r['plan'],
            'added_via' => $r['added_via'],
            'joined' => $r['created_at'],
            'total_earned' => (float)$r['total_earned'],
        ];
    }

    $total = $db->query("SELECT COUNT(*) AS cnt FROM reseller_clients WHERE reseller_id = $rid")->fetch_assoc()['cnt'];
    echo json_encode(['success' => true, 'clients' => $clients, 'total' => (int)$total, 'page' => $page]);
    exit;
}

// ════════════════════════════════
// GET EARNINGS
// ════════════════════════════════
if ($action === 'get_earnings') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $rows = $db->query("SELECT re.*, u.username
        FROM reseller_earnings re
        JOIN users u ON u.id = re.client_user_id
        WHERE re.reseller_id = $rid
        ORDER BY re.created_at DESC
        LIMIT $limit OFFSET $offset");

    $earnings = [];
    while ($r = $rows->fetch_assoc()) {
        $earnings[] = [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'plan' => $r['plan'],
            'gross' => (float)$r['gross_amount'],
            'rate' => (float)$r['commission_rate'],
            'earned' => (float)$r['earned_amount'],
            'status' => $r['status'],
            'date' => $r['created_at'],
        ];
    }

    $total = $db->query("SELECT COUNT(*) AS cnt FROM reseller_earnings WHERE reseller_id = $rid")->fetch_assoc()['cnt'];
    echo json_encode(['success' => true, 'earnings' => $earnings, 'total' => (int)$total, 'page' => $page]);
    exit;
}

// ════════════════════════════════
// UPDATE BRANDING
// ════════════════════════════════
if ($action === 'update_branding') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $company  = trim($_POST['company_name'] ?? '');
    $domain   = trim($_POST['custom_domain'] ?? '');
    $logoUrl  = trim($_POST['logo_url'] ?? '');
    $accent   = trim($_POST['accent_color'] ?? '#7c3aed');

    if (!$company) { echo json_encode(['success' => false, 'error' => 'Company name required']); exit; }
    if ($domain && !filter_var('https://' . ltrim($domain, '/'), FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid domain']); exit;
    }
    if ($logoUrl && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid logo URL']); exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#7c3aed';
    }

    $domain  = $domain ?: null;
    $logoUrl = $logoUrl ?: null;

    $stmt = $db->prepare("UPDATE resellers SET company_name=?, custom_domain=?, logo_url=?, accent_color=? WHERE id=?");
    $stmt->bind_param('ssssi', $company, $domain, $logoUrl, $accent, $rid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// REGENERATE INVITE TOKEN
// ════════════════════════════════
if ($action === 'regenerate_invite') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];
    $token = reseller_generate_token(24);
    $stmt = $db->prepare("UPDATE resellers SET invite_token = ? WHERE id = ?");
    $stmt->bind_param('si', $token, $rid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'invite_token' => $token]);
    exit;
}

// ════════════════════════════════
// ADD CLIENT BY EMAIL (manual)
// ════════════════════════════════
if ($action === 'add_client_by_email') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];

    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email required']); exit;
    }

    $stmt = $db->prepare("SELECT id, username, plan FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) { echo json_encode(['success' => false, 'error' => 'No user found with that email']); exit; }

    // Check not already a client of any reseller
    $uid = (int)$user['id'];
    $stmt = $db->prepare("SELECT id FROM reseller_clients WHERE client_user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'User is already linked to a reseller']); exit;
    }
    $stmt->close();

    $via = 'manual';
    $stmt = $db->prepare("INSERT INTO reseller_clients (reseller_id, client_user_id, added_via) VALUES (?,?,?)");
    $stmt->bind_param('iis', $rid, $uid, $via);
    $stmt->execute();
    $stmt->close();

    reseller_send_operator_alert($reseller, 'operator.client_added_manual', [
        'client_user_id' => $uid,
        'client_username' => (string)($user['username'] ?? ''),
        'client_plan' => (string)($user['plan'] ?? 'free'),
    ]);

    echo json_encode(['success' => true, 'user' => ['id' => $uid, 'username' => $user['username'], 'plan' => $user['plan']]]);
    exit;
}

// ════════════════════════════════
// REMOVE CLIENT
// ════════════════════════════════
if ($action === 'remove_client') {
    $reseller = reseller_require_reseller($db);
    $rid = (int)$reseller['id'];
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$clientId) { echo json_encode(['success' => false, 'error' => 'client_id required']); exit; }

    $stmt = $db->prepare("DELETE FROM reseller_clients WHERE id = ? AND reseller_id = ?");
    $stmt->bind_param('ii', $clientId, $rid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['success' => $affected > 0]);
    exit;
}

// ════════════════════════════════
// RESOLVE INVITE — called when a user registers/logs in via invite link
// ════════════════════════════════
if ($action === 'resolve_invite') {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    if (!$token) { echo json_encode(['success' => false, 'error' => 'Token required']); exit; }
    $stmt = $db->prepare("SELECT id, company_name, logo_url, accent_color FROM resellers WHERE invite_token = ? AND status = 'active'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Invalid or expired invite']); exit; }
    reseller_record_invite_event($db, (int)$row['id'], 'invite_resolve', $token, null);
    echo json_encode(['success' => true, 'reseller' => ['company_name' => $row['company_name'], 'logo_url' => $row['logo_url'], 'accent_color' => $row['accent_color']]]);
    exit;
}

// ════════════════════════════════
// REGISTER CLIENT via invite token (called after user registers)
// Used internally after auth creates the user
// ════════════════════════════════
if ($action === 'link_invite_client') {
    $uid = reseller_require_auth();
    $token = trim($_POST['token'] ?? '');
    if (!$token) { echo json_encode(['success' => false, 'error' => 'Token required']); exit; }

    $stmt = $db->prepare("SELECT id FROM resellers WHERE invite_token = ? AND status = 'active'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $reseller = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$reseller) { echo json_encode(['success' => false, 'error' => 'Invalid invite']); exit; }

    // Check not already linked
    $stmt = $db->prepare("SELECT id FROM reseller_clients WHERE client_user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => true, 'already_linked' => true]); exit;
    }
    $stmt->close();

    $rid = (int)$reseller['id'];
    $via = 'invite';
    $stmt = $db->prepare("INSERT INTO reseller_clients (reseller_id, client_user_id, added_via) VALUES (?,?,?)");
    $stmt->bind_param('iis', $rid, $uid, $via);
    $stmt->execute();
    $stmt->close();

    reseller_record_invite_event($db, $rid, 'client_linked', $token, $uid);

    $resellerRow = $db->query("SELECT id, user_id, company_name, alert_webhook_url FROM resellers WHERE id = $rid LIMIT 1");
    $resellerAlert = $resellerRow ? $resellerRow->fetch_assoc() : null;
    if ($resellerAlert) {
        reseller_send_operator_alert($resellerAlert, 'operator.client_linked_invite', [
            'client_user_id' => $uid,
            'token' => $token,
        ]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// APPLICATION STATUS — check user's own application
// ════════════════════════════════
if ($action === 'get_application_status') {
    $uid = reseller_require_auth();
    $stmt = $db->prepare("SELECT status, created_at, admin_note FROM reseller_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $resellerStmt = $db->prepare("SELECT status, notes, updated_at, company_name FROM resellers WHERE user_id = ? LIMIT 1");
    $resellerStmt->bind_param('i', $uid);
    $resellerStmt->execute();
    $reseller = $resellerStmt->get_result()->fetch_assoc();
    $resellerStmt->close();

    $resellerStatus = $reseller ? strtolower(trim((string)($reseller['status'] ?? ''))) : null;

    echo json_encode([
        'success' => true,
        'application' => $application ?: null,
        'application_status' => (string)($application['status'] ?? ''),
        'admin_note' => (string)($application['admin_note'] ?? ''),
        'reseller_status' => $resellerStatus,
        'reseller_note' => (string)($reseller['notes'] ?? ''),
        'reseller_company_name' => (string)($reseller['company_name'] ?? ''),
        'support_email' => reseller_support_email(),
    ]);
    exit;
}

// ════════════════════════════════
// ADMIN — list applications
// ════════════════════════════════
if ($action === 'admin_get_applications') {
    reseller_require_admin();
    $status = $_GET['status'] ?? 'pending';
    $allowed = ['pending', 'approved', 'rejected', 'all'];
    if (!in_array($status, $allowed, true)) $status = 'pending';

    $where = $status === 'all' ? '1' : "status = '$status'";
    $rows = $db->query("SELECT ra.*, u.username FROM reseller_applications ra
        LEFT JOIN users u ON u.id = ra.user_id
        WHERE $where ORDER BY ra.created_at DESC LIMIT 100");

    $apps = [];
    while ($r = $rows->fetch_assoc()) $apps[] = $r;
    echo json_encode(['success' => true, 'applications' => $apps]);
    exit;
}

// ════════════════════════════════
// ADMIN — approve application
// ════════════════════════════════
if ($action === 'admin_approve') {
    reseller_require_admin();
    $appId = (int)($_POST['application_id'] ?? 0);
    $commission = (float)($_POST['commission_rate'] ?? 20.0);
    $commission = max(1, min(50, $commission));
    $note = trim($_POST['note'] ?? '');

    if (!$appId) { echo json_encode(['success' => false, 'error' => 'application_id required']); exit; }

    $stmt = $db->prepare("SELECT * FROM reseller_applications WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $appId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$app) { echo json_encode(['success' => false, 'error' => 'Application not found or already reviewed']); exit; }

    // Require user_id for creating a reseller account
    if (!$app['user_id']) {
        // Try to find user by application email
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $app['email']);
        $stmt->execute();
        $foundUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$foundUser) {
            echo json_encode(['success' => false, 'error' => 'No matching user account found for this application email. They must register first.']); exit;
        }
        $userId = (int)$foundUser['id'];
    } else {
        $userId = (int)$app['user_id'];
    }

    // Check if already a reseller
    $stmt = $db->prepare("SELECT id FROM resellers WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'User is already a reseller']); exit;
    }
    $stmt->close();

    $token = reseller_generate_token(24);
    $reviewedBy = $_SESSION['username'] ?? 'admin';
    $stmt = $db->prepare("INSERT INTO resellers (user_id, application_id, company_name, commission_rate, invite_token, onboarding_mode, growth_goal_clients_30d) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('iisdssi', $userId, $appId, $app['company'], $commission, $token, $operatorDefaultMode, $operatorDefaultGrowthGoal);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE reseller_applications SET status='approved', admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    $stmt->bind_param('ssi', $note, $reviewedBy, $appId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'invite_token' => $token]);
    exit;
}

// ════════════════════════════════
// ADMIN — reject application
// ════════════════════════════════
if ($action === 'admin_reject') {
    reseller_require_admin();
    $appId = (int)($_POST['application_id'] ?? 0);
    $note  = trim($_POST['note'] ?? '');
    if (!$appId) { echo json_encode(['success' => false, 'error' => 'application_id required']); exit; }

    $reviewedBy = $_SESSION['username'] ?? 'admin';
    $stmt = $db->prepare("UPDATE reseller_applications SET status='rejected', admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'");
    $stmt->bind_param('ssi', $note, $reviewedBy, $appId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['success' => $affected > 0]);
    exit;
}

// ════════════════════════════════
// ADMIN — list all resellers
// ════════════════════════════════
if ($action === 'admin_list_resellers') {
    reseller_require_admin();
    $rows = $db->query("SELECT r.*, u.username, u.email,
        (SELECT COUNT(*) FROM reseller_clients rc WHERE rc.reseller_id = r.id) AS client_count
        FROM resellers r
        JOIN users u ON u.id = r.user_id
        ORDER BY r.created_at DESC LIMIT 200");
    $resellers = [];
    while ($r = $rows->fetch_assoc()) $resellers[] = $r;
    echo json_encode(['success' => true, 'resellers' => $resellers]);
    exit;
}

// ════════════════════════════════
// ADMIN — set commission rate
// ════════════════════════════════
if ($action === 'admin_set_commission') {
    reseller_require_admin();
    $rid = (int)($_POST['reseller_id'] ?? 0);
    $rate = (float)($_POST['commission_rate'] ?? 0);
    $rate = max(1, min(50, $rate));
    if (!$rid) { echo json_encode(['success' => false, 'error' => 'reseller_id required']); exit; }
    $stmt = $db->prepare("UPDATE resellers SET commission_rate = ? WHERE id = ?");
    $stmt->bind_param('di', $rate, $rid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// ADMIN — toggle active/suspended
// ════════════════════════════════
if ($action === 'admin_toggle_status') {
    reseller_require_admin();
    $rid = (int)($_POST['reseller_id'] ?? 0);
    if (!$rid) { echo json_encode(['success' => false, 'error' => 'reseller_id required']); exit; }
    $stmt = $db->prepare("UPDATE resellers
        SET status = CASE
            WHEN status = 'active' THEN 'suspended'
            WHEN status = 'suspended' THEN 'active'
            WHEN status = 'terminated' THEN 'active'
            ELSE 'active'
        END
        WHERE id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $stmt->close();
    $row = $db->query("SELECT status FROM resellers WHERE id = $rid")->fetch_assoc();
    echo json_encode(['success' => true, 'status' => $row['status'] ?? 'unknown']);
    exit;
}

// ════════════════════════════════
// ADMIN — mark earnings as paid out
// ════════════════════════════════
if ($action === 'admin_mark_paid_out') {
    reseller_require_admin();
    $rid = (int)($_POST['reseller_id'] ?? 0);
    if (!$rid) { echo json_encode(['success' => false, 'error' => 'reseller_id required']); exit; }

    $stmt = $db->prepare("UPDATE reseller_earnings SET status='paid_out' WHERE reseller_id = ? AND status='pending'");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    // Sync total_paid_out on resellers row
    $db->query("UPDATE resellers r SET total_paid_out = (SELECT COALESCE(SUM(earned_amount),0) FROM reseller_earnings WHERE reseller_id = r.id AND status='paid_out') WHERE r.id = $rid");
    echo json_encode(['success' => true, 'marked' => $affected]);
    exit;
}

// ════════════════════════════════
// ADMIN — remove reseller (demote back to regular user)
// ════════════════════════════════
if ($action === 'admin_remove_reseller') {
    reseller_require_admin();
    $rid = (int)($_POST['reseller_id'] ?? 0);
    if (!$rid) { echo json_encode(['success' => false, 'error' => 'reseller_id required']); exit; }

    // Get user_id so we can update their application record.
    $stmt = $db->prepare("SELECT user_id FROM resellers WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $resellerRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$resellerRow) {
        echo json_encode(['success' => false, 'error' => 'Reseller not found']);
        exit;
    }

    $reviewedBy = (string)($_SESSION['username'] ?? 'admin');
    $terminationNote = 'Operator access terminated by admin (' . $reviewedBy . ').';
    $newToken = reseller_generate_token(24);

    $stmt = $db->prepare("UPDATE resellers SET status = 'terminated', notes = ?, invite_token = ? WHERE id = ?");
    $stmt->bind_param('ssi', $terminationNote, $newToken, $rid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        echo json_encode(['success' => false, 'error' => 'No changes made']);
        exit;
    }

    // Reset their most recent approved application so the apply page shows correct state
    $uid = (int)$resellerRow['user_id'];
    $note = 'Operator access terminated by admin.';
    $stmt = $db->prepare("UPDATE reseller_applications SET status = 'rejected', admin_note = ? WHERE user_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('si', $note, $uid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// ADMIN — reseller client list
// ════════════════════════════════
if ($action === 'admin_reseller_clients') {
    reseller_require_admin();
    $rid = (int)($_GET['reseller_id'] ?? 0);
    if (!$rid) { echo json_encode(['success' => false, 'error' => 'reseller_id required']); exit; }
    $rows = $db->query("SELECT rc.*, u.username, u.email, u.plan FROM reseller_clients rc JOIN users u ON u.id = rc.client_user_id WHERE rc.reseller_id = $rid ORDER BY rc.created_at DESC");
    $clients = [];
    while ($r = $rows->fetch_assoc()) $clients[] = $r;
    echo json_encode(['success' => true, 'clients' => $clients]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
