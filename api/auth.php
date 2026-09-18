<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
api_json_headers();

// Resolve action before expensive bootstrap work so frequent auth checks can
// return immediately without changing the existing auth contract.
$action = api_action();

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) {
    api_fail('DB connection failed', 500);
}
$db->set_charset('utf8mb4');

// ── FAST CHECK SESSION ──────────────────────────────────────────────────────
// Preserve the original `check` response shape, but handle it before the
// schema bootstrap / ALTER / CREATE work below. This keeps the rest of the
// application compatible while preventing routine auth checks from timing out.
if ($action === 'check') {
    // Preserve the original 9-minute idle timeout behavior.
    if (!empty($_SESSION['user_id']) && isset($_SESSION['last_active'])) {
        if ((time() - (int)$_SESSION['last_active']) > 540) {
            session_destroy();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Session expired due to inactivity.',
                'reason' => 'idle_timeout',
            ]);
            exit;
        }
    }

    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $username = $_SESSION['username'] ?? null;
        $plan = $_SESSION['plan'] ?? 'free';

        // A successful check still counts as user activity, same as before.
        $_SESSION['last_active'] = time();

        // Same fields as the original handler. If the refresh query fails,
        // fall back to session data instead of fataling and surfacing a 504.
        $row = [];
        $stmt = $db->prepare("SELECT plan, two_factor_enabled, two_factor_method, email_verified FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc() ?: [];
                }
            }
            $stmt->close();
        }

        $plan = $row['plan'] ?? $plan;
        $_SESSION['plan'] = $plan;

        echo json_encode([
            'logged_in' => true,
            'username' => $username,
            'plan' => $plan,
            'email_verified' => (int)($row['email_verified'] ?? 0) === 1,
            'two_factor_enabled' => (int)($row['two_factor_enabled'] ?? 0) === 1,
            'two_factor_method' => $row['two_factor_method'] ?? null,
            'privacy_url' => auth_base_url() . '/pages/privacy.php',
            'terms_url' => auth_base_url() . '/pages/tos.php',
            'support_url' => auth_base_url() . '/pages/support.php',
            'account_deletion_supported' => true,
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

saas_bootstrap_schema($db);

$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_method VARCHAR(20) DEFAULT NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(128) DEFAULT NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_pending_secret VARCHAR(128) DEFAULT NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");

$db->query("CREATE TABLE IF NOT EXISTS email_verification_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'verify_email',
    code_hash VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_purpose (user_id, purpose, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS user_yubikeys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    public_id VARCHAR(32) NOT NULL,
    label VARCHAR(100) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_key (user_id, public_id),
    KEY idx_user_active (user_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS user_2fa_recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    code_hash VARCHAR(128) NOT NULL,
    batch_id VARCHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_unused (user_id, used_at),
    KEY idx_user_batch (user_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(40) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bucket_identifier (bucket, identifier),
    KEY idx_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS user_convs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conv_id VARCHAR(80) NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL DEFAULT 'New Chat',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conv_id (conv_id),
    KEY idx_user_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS user_conv_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conv_id VARCHAR(80) NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    thinking MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conv_created (conv_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$thinkingColumn = $db->query("SHOW COLUMNS FROM user_conv_messages LIKE 'thinking'");
if ($thinkingColumn && !$thinkingColumn->num_rows) {
    $db->query("ALTER TABLE user_conv_messages ADD COLUMN thinking MEDIUMTEXT NULL AFTER content");
}

$db->query("CREATE TABLE IF NOT EXISTS user_data_deletion_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    request_type VARCHAR(32) NOT NULL DEFAULT 'full_account',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_ip VARCHAR(45) DEFAULT NULL,
    admin_note VARCHAR(255) DEFAULT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_status (user_id, status),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("ALTER TABLE user_data_deletion_requests ADD COLUMN IF NOT EXISTS support_ticket_id INT NULL");
$db->query("ALTER TABLE user_data_deletion_requests ADD COLUMN IF NOT EXISTS support_ticket_ref VARCHAR(40) NULL");

$db->query("CREATE TABLE IF NOT EXISTS user_mobile_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    device_name VARCHAR(120) NOT NULL DEFAULT 'Mobile App',
    last_user_agent VARCHAR(255) DEFAULT NULL,
    created_ip VARCHAR(45) DEFAULT NULL,
    last_ip VARCHAR(45) DEFAULT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_user_mobile_tokens (user_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

const AUTH_IDLE_TIMEOUT = 540; // 9 minutes in seconds

function auth_finalize_login(array $user): void {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_email'] = $user['email'] ?? null;
    $_SESSION['plan'] = $user['plan'] ?? 'free';
    $_SESSION['last_active'] = time();
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);

    if (($user['username'] ?? '') === 'developer') {
        setcookie('lyralink_dev', 'bypass', 0, '/', '', false, false);
    }
}

function auth_check_idle(): void {
    if (!empty($_SESSION['user_id']) && isset($_SESSION['last_active'])) {
        if ((time() - (int)$_SESSION['last_active']) > AUTH_IDLE_TIMEOUT) {
            session_destroy();
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Session expired due to inactivity.', 'reason' => 'idle_timeout']);
            exit;
        }
    }
}

function auth_is_mobile_client(): bool {
    return api_is_mobile_client();
}

function auth_issue_mobile_token(mysqli $db, int $userId, string $deviceName = 'Mobile App'): ?array {
    $plainToken = 'lym_' . bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $deviceName = substr(trim($deviceName) ?: 'Mobile App', 0, 120);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app'), 0, 255);
    $ip = substr(auth_client_ip(), 0, 45);
    $expiresAt = date('Y-m-d H:i:s', time() + (90 * 86400));

    $stmt = $db->prepare("INSERT INTO user_mobile_tokens (user_id, token_hash, device_name, last_user_agent, created_ip, last_ip, last_used_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('issssss', $userId, $tokenHash, $deviceName, $userAgent, $ip, $ip, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return null;
    }

    return [
        'token' => $plainToken,
        'device_name' => $deviceName,
        'expires_at' => $expiresAt,
    ];
}

function auth_mobile_auth_payload(mysqli $db, int $userId): ?array {
    if (!auth_is_mobile_client()) {
        return null;
    }
    return auth_issue_mobile_token($db, $userId, api_client_device_name());
}

function auth_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $result = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $chunks = str_split($bits, 5);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $result .= $alphabet[bindec($chunk)];
    }
    return $result;
}

function auth_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($clean) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = str_split($bits, 8);
    $result = '';
    foreach ($bytes as $byte) {
        if (strlen($byte) === 8) {
            $result .= chr(bindec($byte));
        }
    }
    return $result;
}

function auth_totp_code(string $secret, ?int $time = null, int $digits = 6, int $period = 30): string {
    $counter = (int)floor(($time ?? time()) / $period);
    $binaryCounter = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $secret, true);
    $offset = ord($hash[19]) & 0x0f;
    $truncated = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    );
    $mod = 10 ** $digits;
    return str_pad((string)($truncated % $mod), $digits, '0', STR_PAD_LEFT);
}

function auth_verify_totp(string $base32Secret, string $code): bool {
    $secret = auth_base32_decode($base32Secret);
    if ($secret === '' || !preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }
    $now = time();
    foreach ([-30, 0, 30] as $drift) {
        if (hash_equals(auth_totp_code($secret, $now + $drift), $code)) {
            return true;
        }
    }
    return false;
}

function auth_system_mail(mysqli $db, string $to, string $subject, string $html): bool {
    $smtpHost = null;
    $smtpPort = null;
    $smtpUser = null;
    $smtpPass = null;
    $smtpFrom = null;

    $cfgStmt = $db->prepare("SELECT `key`, `value` FROM support_config WHERE `key` IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from')");
    if ($cfgStmt) {
        $cfgStmt->execute();
        $cfgRes = $cfgStmt->get_result();
        while ($row = $cfgRes->fetch_assoc()) {
            if ($row['key'] === 'smtp_host') $smtpHost = $row['value'];
            if ($row['key'] === 'smtp_port') $smtpPort = (int)$row['value'];
            if ($row['key'] === 'smtp_user') $smtpUser = $row['value'];
            if ($row['key'] === 'smtp_pass') $smtpPass = $row['value'];
            if ($row['key'] === 'smtp_from') $smtpFrom = $row['value'];
        }
        $cfgStmt->close();
    }

    $smtpHost = $smtpHost ?: api_get_secret('SMTP_HOST', '');
    $smtpPort = $smtpPort ?: (int)api_get_secret('SMTP_PORT', '587');
    $smtpUser = $smtpUser ?: api_get_secret('SMTP_USER', '');
    $smtpPass = $smtpPass ?: api_get_secret('SMTP_PASS', '');
    $smtpFrom = $smtpFrom ?: api_get_secret('SMTP_FROM', 'no-reply@lyralinkai.com');

    if (!$smtpUser || !$smtpPass) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: Lyralink <$smtpFrom>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit";
        return mail($to, $subject, $html, $headers);
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return false;
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;
        $mail->Timeout = 8;
        $mail->CharSet = 'UTF-8';
        if ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpPort === 587) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->setFrom($smtpFrom, 'Lyralink');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('auth mailer error: ' . $e->getMessage());
        return false;
    }
}

function auth_support_email(mysqli $db): string {
    $stmt = $db->prepare("SELECT `value` FROM support_config WHERE `key` = 'support_email' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $email = trim((string)($row['value'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }
    return trim(api_get_secret('SUPPORT_EMAIL', 'support@lyralinkai.com'));
}

function auth_base_url(): string {
    return 'http://lyralinkai.com';
}

function auth_mail_layout(string $title, string $body, ?string $ticketRef = null): string {
    $ref = $ticketRef ? "<p style='color:#a78bfa;font-size:12px'>Ticket: {$ticketRef}</p>" : '';
    return "
    <div style='font-family:monospace;background:#0a0a0f;color:#e2e8f0;padding:32px;max-width:560px;margin:0 auto;border-radius:16px;border:1px solid #1e1e2e'>
        <div style='margin-bottom:24px'>
            <h2 style='font-family:sans-serif;color:#a78bfa;margin:0 0 4px'>Lyralink</h2>
            {$ref}
        </div>
        <h3 style='color:#e2e8f0;margin:0 0 16px'>{$title}</h3>
        <div style='color:#94a3b8;line-height:1.7;font-size:13px'>{$body}</div>
        <hr style='border:none;border-top:1px solid #1e1e2e;margin:24px 0'>
        <p style='color:#334155;font-size:11px'>Lyralink Team <a href='" . auth_base_url() . "/chat' style='color:#7c3aed'>Open Lyralink</a></p>
    </div>";
}

function auth_template_config(mysqli $db, string $templateId): array {
    $catalog = [
        'registration_welcome' => [
            'subject_key' => 'email_tpl_registration_welcome_subject',
            'body_key' => 'email_tpl_registration_welcome_body',
            'default_subject' => 'Welcome to Lyralink, {{username}}',
            'default_body' => "<p>Hi {{username}},</p>\n<p>Your Lyralink account has been created for {{email}}.</p>\n<p>You can sign in at <a href='{{login_url}}' style='color:#a78bfa'>{{login_url}}</a> once your email is verified.</p>\n<p>If you need help, contact {{support_email}}.</p>",
            'title' => 'Welcome to Lyralink',
        ],
        'email_verification' => [
            'subject_key' => 'email_tpl_email_verification_subject',
            'body_key' => 'email_tpl_email_verification_body',
            'default_subject' => 'Verify your Lyralink account',
            'default_body' => "<p>Hi {{username}}, use this code to verify your account:</p>\n<div style='font-size:28px;letter-spacing:4px;font-weight:700;color:#ffffff;background:#1e293b;padding:12px 16px;border-radius:8px;display:inline-block'>{{code}}</div>\n<p style='margin:12px 0 0;color:#94a3b8'>This code expires in {{expires_minutes}} minutes.</p>\n<p style='margin:12px 0 0'>If you did not request this, contact {{support_email}}.</p>",
            'title' => 'Verify your email',
        ],
        'password_reset_code' => [
            'subject_key' => 'email_tpl_password_reset_code_subject',
            'body_key' => 'email_tpl_password_reset_code_body',
            'default_subject' => 'Your Lyralink password reset code',
            'default_body' => "<p>Hi {{username}}, use this code to reset your password:</p>\n<div style='font-size:28px;letter-spacing:4px;font-weight:700;color:#ffffff;background:#1e293b;padding:12px 16px;border-radius:8px;display:inline-block'>{{code}}</div>\n<p style='margin:12px 0 0;color:#94a3b8'>This code expires in {{expires_minutes}} minutes.</p>\n<p style='margin:12px 0 0'>If you did not request a reset, you can ignore this email.</p>",
            'title' => 'Reset your password',
        ],
    ];

    $def = $catalog[$templateId] ?? null;
    if (!$def) {
        return ['subject' => '', 'body' => '', 'title' => 'Lyralink'];
    }

    $subject = '';
    $body = '';
    $stmt = $db->prepare("SELECT `key`, `value` FROM support_config WHERE `key` IN (?, ?)");
    if ($stmt) {
        $subjectKey = $def['subject_key'];
        $bodyKey = $def['body_key'];
        $stmt->bind_param('ss', $subjectKey, $bodyKey);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ($row['key'] === $subjectKey) $subject = trim((string)$row['value']);
            if ($row['key'] === $bodyKey) $body = trim((string)$row['value']);
        }
        $stmt->close();
    }

    return [
        'subject' => $subject !== '' ? $subject : $def['default_subject'],
        'body' => $body !== '' ? $body : $def['default_body'],
        'title' => $def['title'],
    ];
}

function auth_render_template_string(string $template, array $vars): string {
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function (array $matches) use ($vars): string {
        $key = strtolower((string)($matches[1] ?? ''));
        return array_key_exists($key, $vars) ? (string)$vars[$key] : '';
    }, $template) ?? $template;
}

function auth_mail_vars(array $vars): array {
    $normalized = [];
    foreach ($vars as $key => $value) {
        $normalized[strtolower((string)$key)] = (string)$value;
    }
    $normalized['login_url'] = $normalized['login_url'] ?? auth_base_url() . '/chat';
    $normalized['support_email'] = $normalized['support_email'] ?? 'support@lyralinkai.com';
    return $normalized;
}

function auth_render_mail_template(mysqli $db, string $templateId, array $vars): array {
    $config = auth_template_config($db, $templateId);
    $mailVars = auth_mail_vars($vars);
    $subject = auth_render_template_string($config['subject'], $mailVars);
    $body = auth_render_template_string($config['body'], $mailVars);
    return [
        'subject' => $subject,
        'html' => auth_mail_layout($config['title'] ?: $subject, $body),
    ];
}

function auth_send_registration_welcome(mysqli $db, string $email, string $username): bool {
    $mail = auth_render_mail_template($db, 'registration_welcome', [
        'username' => $username,
        'email' => $email,
        'login_url' => auth_base_url() . '/chat',
        'support_email' => auth_support_email($db),
    ]);
    return auth_system_mail($db, $email, $mail['subject'], $mail['html']);
}

function auth_support_ticket_ref(): string {
    return 'TKT-' . strtoupper(substr(md5(uniqid((string)random_int(1000, 999999), true)), 0, 8));
}

function auth_create_deletion_support_ticket(mysqli $db, int $userId, string $username, string $email, string $ip): ?array {
    $hasSupportTable = $db->query("SHOW TABLES LIKE 'support_tickets'");
    if (!$hasSupportTable || $hasSupportTable->num_rows === 0) {
        return null;
    }

    $ticketRef = auth_support_ticket_ref();
    $dupStmt = $db->prepare("SELECT id FROM support_tickets WHERE ticket_ref = ? LIMIT 1");
    if (!$dupStmt) {
        return null;
    }
    for ($i = 0; $i < 5; $i++) {
        $dupStmt->bind_param('s', $ticketRef);
        $dupStmt->execute();
        $dup = $dupStmt->get_result()->fetch_assoc();
        if (!$dup) {
            break;
        }
        $ticketRef = auth_support_ticket_ref();
    }
    $dupStmt->close();

    $subject = 'Data deletion request for ' . $username;
    $body = "User requested full account/data deletion from profile settings.\n\n"
        . "User ID: {$userId}\n"
        . "Username: {$username}\n"
        . "Email: " . ($email !== '' ? $email : 'n/a') . "\n"
        . "IP: {$ip}\n\n"
        . "Please review and process deletion in the support dashboard.";
    $priority = 'high';
    $category = 'account';

    $insertStmt = $db->prepare("INSERT INTO support_tickets (ticket_ref, user_id, guest_email, guest_name, category, subject, body, user_priority) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?)");
    if (!$insertStmt) {
        return null;
    }
    $insertStmt->bind_param('sissss', $ticketRef, $userId, $category, $subject, $body, $priority);
    $ok = $insertStmt->execute();
    $ticketId = (int)$insertStmt->insert_id;
    $insertStmt->close();
    if (!$ok || $ticketId <= 0) {
        return null;
    }

    return ['id' => $ticketId, 'ref' => $ticketRef];
}

function auth_issue_email_code(mysqli $db, int $userId): array {
    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user || empty($user['email'])) {
        return ['ok' => false, 'error' => 'User email missing'];
    }

    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $purpose = 'verify_email';

    $clearStmt = $db->prepare("UPDATE email_verification_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL");
    $clearStmt->bind_param('is', $userId, $purpose);
    $clearStmt->execute();
    $clearStmt->close();

    $insertStmt = $db->prepare("INSERT INTO email_verification_codes (user_id, purpose, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $insertStmt->bind_param('iss', $userId, $purpose, $codeHash);
    $insertStmt->execute();
    $insertStmt->close();

    $mail = auth_render_mail_template($db, 'email_verification', [
        'username' => (string)$user['username'],
        'code' => $code,
        'expires_minutes' => '15',
        'support_email' => auth_support_email($db),
    ]);
    $mailOk = auth_system_mail($db, $user['email'], $mail['subject'], $mail['html']);
    return ['ok' => $mailOk, 'email' => $user['email']];
}

function auth_issue_password_reset_code(mysqli $db, int $userId): array {
    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user || empty($user['email'])) {
        return ['ok' => false, 'error' => 'User email missing'];
    }

    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $purpose = 'password_reset';

    $clearStmt = $db->prepare("UPDATE email_verification_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL");
    $clearStmt->bind_param('is', $userId, $purpose);
    $clearStmt->execute();
    $clearStmt->close();

    $insertStmt = $db->prepare("INSERT INTO email_verification_codes (user_id, purpose, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE))");
    $insertStmt->bind_param('iss', $userId, $purpose, $codeHash);
    $insertStmt->execute();
    $insertStmt->close();

    $mail = auth_render_mail_template($db, 'password_reset_code', [
        'username' => (string)$user['username'],
        'code' => $code,
        'expires_minutes' => '20',
        'support_email' => auth_support_email($db),
    ]);
    $mailOk = auth_system_mail($db, $user['email'], $mail['subject'], $mail['html']);
    return ['ok' => $mailOk, 'email' => $user['email']];
}

function auth_validate_yubikey_otp(string $otp): array {
    $otp = trim($otp);
    if (!preg_match('/^[cbdefghijklnrtuv]{32,64}$/', $otp)) {
        return ['ok' => false, 'error' => 'Invalid YubiKey OTP format'];
    }

    $clientId = api_get_secret('YUBICO_CLIENT_ID', '');
    $secret = api_get_secret('YUBICO_SECRET', '');
    if ($clientId === '' || $secret === '') {
        return ['ok' => false, 'error' => 'YubiKey verification is not configured'];
    }

    $nonce = bin2hex(random_bytes(16));
    $params = [
        'id' => $clientId,
        'otp' => $otp,
        'nonce' => $nonce,
        'timestamp' => 1,
    ];
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $queryForSig = implode('&', $pairs);
    $sigRaw = hash_hmac('sha1', $queryForSig, base64_decode($secret), true);
    $params['h'] = base64_encode($sigRaw);

    $url = 'https://api.yubico.com/wsapi/2.0/verify?' . http_build_query($params);
    $res = @file_get_contents($url);
    if ($res === false) {
        return ['ok' => false, 'error' => 'Could not reach YubiKey validation service'];
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($res));
    $out = [];
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }
    }

    if (($out['status'] ?? '') !== 'OK') {
        return ['ok' => false, 'error' => 'YubiKey validation failed'];
    }
    if (($out['otp'] ?? '') !== $otp) {
        return ['ok' => false, 'error' => 'YubiKey OTP mismatch'];
    }
    return ['ok' => true, 'public_id' => substr($otp, 0, 12)];
}

function auth_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        $first = trim(explode(',', $candidate)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return '0.0.0.0';
}

function auth_logs_channel_id(): string {
    // Keep this aligned with the existing WebBot logs channel.
    return '1475657872862875727';
}

function auth_log_registration_to_discord(mysqli $db, int $userId, string $username, string $email): bool {
    $botToken = trim((string)api_get_secret('BOT_SECRET_KEY', ''));
    if ($botToken === '') {
        return false;
    }

    $channelId = auth_logs_channel_id();
    $endpoint = 'https://discord.com/api/v10/channels/' . rawurlencode($channelId) . '/messages';
    $ip = auth_client_ip();
    $supportEmail = auth_support_email($db);

    $payload = [
        'embeds' => [[
            'title' => 'New Lyralink Account Registered',
            'description' => 'A new user account was created on lyralinkai.com.',
            'color' => 0x22C55E,
            'fields' => [
                ['name' => 'User', 'value' => $username . ' (`' . $userId . '`)', 'inline' => true],
                ['name' => 'Email', 'value' => $email !== '' ? $email : 'n/a', 'inline' => true],
                ['name' => 'IP', 'value' => $ip, 'inline' => true],
                ['name' => 'Plan', 'value' => 'free', 'inline' => true],
                ['name' => 'Support Contact', 'value' => $supportEmail !== '' ? $supportEmail : 'n/a', 'inline' => true],
            ],
            'timestamp' => gmdate('c'),
        ]],
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $botToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('[auth] Failed to send registration log embed. code=' . $httpCode . ' curl=' . $curlError);
        return false;
    }
    return true;
}

function auth_rate_limit_status(mysqli $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): array {
    $stmt = $db->prepare("SELECT id, attempts, window_start, blocked_until FROM auth_rate_limits WHERE bucket = ? AND identifier = ? LIMIT 1");
    $stmt->bind_param('ss', $bucket, $identifier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['blocked' => false, 'retry_after' => 0, 'row_id' => null, 'attempts' => 0];
    }

    $nowTs = time();
    $windowStartTs = strtotime((string)$row['window_start']);
    $blockedUntilTs = !empty($row['blocked_until']) ? strtotime((string)$row['blocked_until']) : 0;

    if ($blockedUntilTs > $nowTs) {
        return [
            'blocked' => true,
            'retry_after' => max(1, $blockedUntilTs - $nowTs),
            'row_id' => (int)$row['id'],
            'attempts' => (int)$row['attempts'],
        ];
    }

    if ($windowStartTs <= 0 || ($nowTs - $windowStartTs) > $windowSeconds) {
        $resetStmt = $db->prepare("UPDATE auth_rate_limits SET attempts = 0, window_start = NOW(), blocked_until = NULL, last_attempt_at = NOW() WHERE id = ?");
        $rowId = (int)$row['id'];
        $resetStmt->bind_param('i', $rowId);
        $resetStmt->execute();
        $resetStmt->close();
        return ['blocked' => false, 'retry_after' => 0, 'row_id' => $rowId, 'attempts' => 0];
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        $blockStmt = $db->prepare("UPDATE auth_rate_limits SET blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), last_attempt_at = NOW() WHERE id = ?");
        $rowId = (int)$row['id'];
        $blockStmt->bind_param('ii', $lockoutSeconds, $rowId);
        $blockStmt->execute();
        $blockStmt->close();
        return ['blocked' => true, 'retry_after' => $lockoutSeconds, 'row_id' => $rowId, 'attempts' => (int)$row['attempts']];
    }

    return ['blocked' => false, 'retry_after' => 0, 'row_id' => (int)$row['id'], 'attempts' => (int)$row['attempts']];
}

function auth_rate_limit_fail(mysqli $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): void {
    $stmt = $db->prepare("INSERT INTO auth_rate_limits (bucket, identifier, attempts, window_start, last_attempt_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE
        attempts = CASE WHEN TIMESTAMPDIFF(SECOND, window_start, NOW()) > ? THEN 1 ELSE attempts + 1 END,
        window_start = CASE WHEN TIMESTAMPDIFF(SECOND, window_start, NOW()) > ? THEN NOW() ELSE window_start END,
        blocked_until = CASE
            WHEN TIMESTAMPDIFF(SECOND, window_start, NOW()) > ? THEN NULL
            WHEN attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
            ELSE blocked_until
        END,
        last_attempt_at = NOW()");
    $stmt->bind_param('ssiiiii', $bucket, $identifier, $windowSeconds, $windowSeconds, $windowSeconds, $maxAttempts, $lockoutSeconds);
    $stmt->execute();
    $stmt->close();
}

function auth_rate_limit_clear(mysqli $db, string $bucket, string $identifier): void {
    $stmt = $db->prepare("DELETE FROM auth_rate_limits WHERE bucket = ? AND identifier = ?");
    $stmt->bind_param('ss', $bucket, $identifier);
    $stmt->execute();
    $stmt->close();
}

function auth_normalize_recovery_code(string $code): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
}

function auth_generate_recovery_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4, 4);
}

function auth_create_recovery_codes(mysqli $db, int $userId, int $count = 10): array {
    $batchId = bin2hex(random_bytes(16));

    $invalidateStmt = $db->prepare("UPDATE user_2fa_recovery_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $invalidateStmt->bind_param('i', $userId);
    $invalidateStmt->execute();
    $invalidateStmt->close();

    $insertStmt = $db->prepare("INSERT INTO user_2fa_recovery_codes (user_id, code_hash, batch_id) VALUES (?, ?, ?)");
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $plain = auth_generate_recovery_code();
        $norm = auth_normalize_recovery_code($plain);
        $hash = hash('sha256', $norm);
        $insertStmt->bind_param('iss', $userId, $hash, $batchId);
        $insertStmt->execute();
        $codes[] = $plain;
    }
    $insertStmt->close();

    return $codes;
}

function auth_use_recovery_code(mysqli $db, int $userId, string $inputCode): bool {
    $norm = auth_normalize_recovery_code($inputCode);
    if ($norm === '' || strlen($norm) < 8) {
        return false;
    }
    $hash = hash('sha256', $norm);
    $stmt = $db->prepare("SELECT id FROM user_2fa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }

    $updateStmt = $db->prepare("UPDATE user_2fa_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $codeId = (int)$row['id'];
    $updateStmt->bind_param('i', $codeId);
    $updateStmt->execute();
    $ok = $updateStmt->affected_rows > 0;
    $updateStmt->close();
    return $ok;
}

if (empty($_SESSION['user_id'])) {
    $mobileUser = api_try_mobile_token_auth($db);
    if ($mobileUser) {
        auth_finalize_login($mobileUser);
    }
}

// $action was already resolved near the top of the request.

// Enforce idle timeout on all authenticated requests (skip login/register/public actions)
if (!empty($_SESSION['user_id']) && !in_array($action, ['login','register','verify_email_code','resend_verification_code','verify_2fa'], true)) {
    auth_check_idle();
    $_SESSION['last_active'] = time(); // refresh on activity
}

// ── PING (heartbeat to keep session alive) ──
if ($action === 'ping') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'reason' => 'not_logged_in']);
    } else {
        echo json_encode(['success' => true]);
    }
    exit;
}

api_enforce_post_and_origin_for_actions([
    'register',
    'login',
    'request_password_reset',
    'reset_password',
    'verify_email_code',
    'resend_verification_code',
    'verify_2fa',
    'setup_2fa_totp',
    'enable_2fa_totp',
    'register_2fa_yubikey',
    'regenerate_recovery_codes',
    'disable_2fa',
    'logout',
    'create_api_key',
    'revoke_api_key',
    'discord_sync',
    'discord_unlink',
    'get_model_options',
    'get_data_deletion_status',
    'request_data_deletion',
    'delete_saved_chat_data',
    'save_msg',
    'delete_conv',
    'rename_conv',
]);

if (!function_exists('auth_parse_model_list')) {
    function auth_parse_model_list(string $raw, array $fallback): array {
        $items = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
        return $items ?: $fallback;
    }
}

if (!function_exists('auth_parse_csv')) {
    function auth_parse_csv(string $raw): array {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    }
}

if (!function_exists('auth_allowed_providers_for_plan')) {
    function auth_allowed_providers_for_plan(string $plan): array {
        return auth_parse_csv(api_get_secret('LLM_ALLOWED_PROVIDERS_' . strtoupper($plan ?: 'free'), ''));
    }
}

if (!function_exists('auth_allowed_models_for_plan')) {
    function auth_allowed_models_for_plan(string $plan): array {
        return auth_parse_csv(api_get_secret('LLM_ALLOWED_MODELS_' . strtoupper($plan ?: 'free'), ''));
    }
}

if ($action === 'get_model_options') {
    $groqKey = api_get_secret('GROQ_API_KEY', '');
    $openRouterKey = api_get_secret('OPENROUTER_API_KEY', '');
    $openAiKey = api_get_secret('OPENAI_API_KEY', '');
    $localModelDefault = trim((string)api_get_secret('LOCAL_LLM_MODEL', api_get_secret('LLM_MODEL', 'lyralink-auto-canary:latest')));
    $localModels = auth_parse_model_list(api_get_secret('LLM_LOCAL_MODELS', ''), [
        $localModelDefault !== '' ? $localModelDefault : 'lyralink-auto-canary:latest',
    ]);
    if ($localModelDefault !== '') {
        $localModels[] = $localModelDefault;
    }
    $localModels[] = 'lyralink-auto-canary:latest';
    $localModels[] = 'lyralink-fast:latest';
    $localModels[] = 'lyralink-code:latest';
    $localModels[] = 'lyralink-reasoning:latest';
    $localModels[] = 'lyralink-creative:latest';
    $localModels = array_values(array_unique(array_filter(array_map('trim', $localModels), fn($m) => $m !== '')));

    $groqModels = auth_parse_model_list(api_get_secret('LLM_GROQ_MODELS', ''), [
        'llama-3.1-8b-instant',
        'llama-3.3-70b-versatile',
    ]);
    $openRouterModels = auth_parse_model_list(api_get_secret('LLM_OPENROUTER_MODELS', ''), [
        api_get_secret('OPENROUTER_MODEL', 'openclaw/openclaw-7b') ?: 'openclaw/openclaw-7b',
    ]);
    $openAiModels = auth_parse_model_list(api_get_secret('LLM_OPENAI_MODELS', ''), [
        api_get_secret('OPENAI_MODEL', 'gpt-4o-mini') ?: 'gpt-4o-mini',
    ]);

    $providers = [
        ['id' => 'local', 'label' => 'Local Lyralink', 'models' => $localModels],
    ];

    $viewerPlan = 'free';
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $planStmt = $db->prepare("SELECT plan FROM users WHERE id = ? LIMIT 1");
        if ($planStmt) {
            $planStmt->bind_param('i', $uid);
            $planStmt->execute();
            $row = $planStmt->get_result()->fetch_assoc();
            $planStmt->close();
            if (!empty($row['plan'])) {
                $viewerPlan = (string)$row['plan'];
            }
        }
    }

    $allowedProviders = array_map('strtolower', auth_allowed_providers_for_plan($viewerPlan));
    $allowedModels = auth_allowed_models_for_plan($viewerPlan);

    if ($allowedModels) {
        $providers = array_values(array_map(function ($p) use ($allowedModels) {
            $p['models'] = array_values(array_filter($p['models'], fn($m) => in_array($m, $allowedModels, true)));
            return $p;
        }, $providers));
        $providers = array_values(array_filter($providers, fn($p) => !empty($p['models'])));
    }

    $defaultProvider = strtolower(trim(api_get_secret('LLM_PROVIDER', 'local')));
    if ($defaultProvider === 'hermes') {
        $defaultProvider = 'local';
    }
    $providerIds = array_map(fn($p) => $p['id'], $providers);
    if (!in_array($defaultProvider, $providerIds, true)) {
        $defaultProvider = $providerIds[0] ?? '';
    }

    $defaultModel = $defaultProvider === 'local'
        ? trim((string)api_get_secret('LOCAL_LLM_MODEL', api_get_secret('LLM_MODEL', '')))
        : trim((string)api_get_secret('LLM_MODEL', ''));
    if ($defaultModel === '' && $defaultProvider !== '') {
        foreach ($providers as $p) {
            if ($p['id'] === $defaultProvider) {
                $defaultModel = $p['models'][0] ?? '';
                break;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'plan' => $viewerPlan,
        'providers' => $providers,
        'default_provider' => $defaultProvider,
        'default_model' => $defaultModel,
    ]);
    exit;
}

// ── REGISTER ──
if ($action === 'register') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$email || !$password) { echo json_encode(['success' => false, 'error' => 'All fields required']); exit; }
    if (strlen($password) < 6) { echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Invalid email address']); exit; }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $verified = 0;
    $stmt = $db->prepare("INSERT INTO users (username, email, password, email_verified) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('sssi', $username, $email, $hash, $verified);

    if ($stmt->execute()) {
        $newUserId = (int)$db->insert_id;
        $_SESSION['pending_verify_user_id'] = $newUserId;
        auth_send_registration_welcome($db, $email, $username);
        $mail = auth_issue_email_code($db, $newUserId);
        auth_log_registration_to_discord($db, $newUserId, $username, $email);

        // ── Reseller invite attribution ──────────────────────────────
        $resellerRef = isset($_COOKIE['reseller_ref']) ? trim($_COOKIE['reseller_ref']) : '';
        if ($resellerRef && preg_match('/^[a-f0-9]{48}$/', $resellerRef)) {
            $stmtR = $db->prepare("SELECT id FROM resellers WHERE invite_token = ? AND status = 'active'");
            $stmtR->bind_param('s', $resellerRef);
            $stmtR->execute();
            $resellerRow = $stmtR->get_result()->fetch_assoc();
            $stmtR->close();
            if ($resellerRow) {
                $rid = (int)$resellerRow['id'];
                $via = 'invite';
                $stmtC = $db->prepare("INSERT IGNORE INTO reseller_clients (reseller_id, client_user_id, added_via) VALUES (?,?,?)");
                $stmtC->bind_param('iis', $rid, $newUserId, $via);
                $stmtC->execute();
                $stmtC->close();
            }
        }
        // ──────────────────────────────────────────────────────────────

        echo json_encode([
            'success' => true,
            'registered' => true,
            'needs_email_verification' => true,
            'email' => $email,
            'mail_sent' => (bool)($mail['ok'] ?? false),
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Username or email already taken']);
    }
    exit;
}

// ── LOGIN ──
if ($action === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = auth_client_ip();
    $loginIdentifier = strtolower($email) . '|' . $clientIp;
    $loginRl = auth_rate_limit_status($db, 'login', $loginIdentifier, 8, 900, 900);
    if ($loginRl['blocked']) {
        echo json_encode(['success' => false, 'error' => 'Too many login attempts. Try again later.', 'retry_after' => $loginRl['retry_after']]);
        exit;
    }

    $stmt = $db->prepare("SELECT id, username, email, password, plan, email_verified, two_factor_enabled, two_factor_method, must_change_password FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        auth_rate_limit_clear($db, 'login', $loginIdentifier);
        if ((int)($user['email_verified'] ?? 0) !== 1) {
            $_SESSION['pending_verify_user_id'] = (int)$user['id'];
            $mail = auth_issue_email_code($db, (int)$user['id']);
            echo json_encode([
                'success' => false,
                'requires_email_verification' => true,
                'email' => $user['email'],
                'mail_sent' => (bool)($mail['ok'] ?? false),
                'error' => 'Please verify your email before logging in',
            ]);
            exit;
        }

        if ((int)($user['two_factor_enabled'] ?? 0) === 1) {
            $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
            $_SESSION['pending_2fa_expires'] = time() + 300;
            echo json_encode([
                'success' => false,
                'requires_2fa' => true,
                'method' => $user['two_factor_method'] ?: 'totp',
                'error' => 'Two-factor verification required',
            ]);
            exit;
        }

        if ((int)($user['must_change_password'] ?? 0) === 1) {
            $_SESSION['pending_change_password_user_id'] = (int)$user['id'];
            echo json_encode([
                'success' => false,
                'requires_password_change' => true,
                'error' => 'You must set a new password before continuing.',
            ]);
            exit;
        }

        auth_finalize_login($user);
        $mobileAuth = auth_mobile_auth_payload($db, (int)$user['id']);
        echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free', 'mobile_auth' => $mobileAuth]);
    } else {
        auth_rate_limit_fail($db, 'login', $loginIdentifier, 8, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
    }
    exit;
}

if ($action === 'request_password_reset') {
    $email = trim((string)($_POST['email'] ?? ''));
    $identifier = strtolower($email) . '|' . auth_client_ip();
    $rl = auth_rate_limit_status($db, 'password_reset_request', $identifier, 5, 1800, 1800);
    if ($rl['blocked']) {
        echo json_encode(['success' => true, 'message' => 'If that account exists, a reset code has been sent.']);
        exit;
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user) {
            auth_issue_password_reset_code($db, (int)$user['id']);
        }
    }

    auth_rate_limit_fail($db, 'password_reset_request', $identifier, 5, 1800, 1800);
    echo json_encode(['success' => true, 'message' => 'If that account exists, a reset code has been sent.']);
    exit;
}

if ($action === 'reset_password') {
    $email = trim((string)($_POST['email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');

    $identifier = strtolower($email) . '|' . auth_client_ip();
    $rl = auth_rate_limit_status($db, 'password_reset_verify', $identifier, 8, 1800, 1800);
    if ($rl['blocked']) {
        echo json_encode(['success' => false, 'error' => 'Too many reset attempts. Try again later.', 'retry_after' => $rl['retry_after']]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
        auth_rate_limit_fail($db, 'password_reset_verify', $identifier, 8, 1800, 1800);
        echo json_encode(['success' => false, 'error' => 'Invalid reset code']);
        exit;
    }
    if (strlen($newPass) < 8) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
        exit;
    }
    if (!hash_equals($newPass, $confirmPass)) {
        echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        auth_rate_limit_fail($db, 'password_reset_verify', $identifier, 8, 1800, 1800);
        echo json_encode(['success' => false, 'error' => 'Invalid reset code']);
        exit;
    }

    $uid = (int)$user['id'];
    $purpose = 'password_reset';
    $codeStmt = $db->prepare("SELECT id, code_hash, attempts, (expires_at > NOW()) AS not_expired FROM email_verification_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    $codeStmt->bind_param('is', $uid, $purpose);
    $codeStmt->execute();
    $row = $codeStmt->get_result()->fetch_assoc();
    $codeStmt->close();

    if (!$row || (int)($row['not_expired'] ?? 0) !== 1 || (int)($row['attempts'] ?? 0) >= 8) {
        auth_rate_limit_fail($db, 'password_reset_verify', $identifier, 8, 1800, 1800);
        echo json_encode(['success' => false, 'error' => 'Reset code expired or invalid']);
        exit;
    }

    $ok = hash_equals((string)$row['code_hash'], hash('sha256', $code));
    if (!$ok) {
        $attemptStmt = $db->prepare("UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = ?");
        $codeId = (int)$row['id'];
        $attemptStmt->bind_param('i', $codeId);
        $attemptStmt->execute();
        $attemptStmt->close();
        auth_rate_limit_fail($db, 'password_reset_verify', $identifier, 8, 1800, 1800);
        echo json_encode(['success' => false, 'error' => 'Invalid reset code']);
        exit;
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $upd = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
    $upd->bind_param('si', $hash, $uid);
    $upd->execute();
    $upd->close();

    $useStmt = $db->prepare("UPDATE email_verification_codes SET used_at = NOW() WHERE id = ?");
    $codeId = (int)$row['id'];
    $useStmt->bind_param('i', $codeId);
    $useStmt->execute();
    $useStmt->close();

    auth_rate_limit_clear($db, 'password_reset_verify', $identifier);
    echo json_encode(['success' => true, 'message' => 'Password reset successful. You can now log in.']);
    exit;
}

// ── CHANGE TEMPORARY PASSWORD ──
if ($action === 'change_temp_password') {
    $pendingId = (int)($_SESSION['pending_change_password_user_id'] ?? 0);
    if (!$pendingId) {
        echo json_encode(['success' => false, 'error' => 'No pending password change session']);
        exit;
    }
    $newPass     = $_POST['new_password']     ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    if (strlen($newPass) < 8) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
        exit;
    }
    if ($newPass !== $confirmPass) {
        echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
        exit;
    }
    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $upd = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
    if (!$upd) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }
    $upd->bind_param('si', $hash, $pendingId);
    $upd->execute();
    $upd->close();
    unset($_SESSION['pending_change_password_user_id']);

    $stmt = $db->prepare("SELECT id, username, email, password, plan, email_verified, two_factor_enabled, two_factor_method, must_change_password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $pendingId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) { echo json_encode(['success' => false, 'error' => 'User not found']); exit; }
    auth_finalize_login($user);
    $mobileAuth = auth_mobile_auth_payload($db, (int)$user['id']);
    echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free', 'mobile_auth' => $mobileAuth]);
    exit;
}

if ($action === 'resend_verification_code') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email']);
        exit;
    }
    $stmt = $db->prepare("SELECT id, email_verified FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }
    if ((int)$user['email_verified'] === 1) {
        echo json_encode(['success' => true, 'message' => 'Email already verified']);
        exit;
    }
    $mail = auth_issue_email_code($db, (int)$user['id']);
    echo json_encode(['success' => (bool)($mail['ok'] ?? false), 'mail_sent' => (bool)($mail['ok'] ?? false)]);
    exit;
}

if ($action === 'verify_email_code') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $verifyIdentifier = strtolower($email) . '|' . auth_client_ip();
    $verifyRl = auth_rate_limit_status($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
    if ($verifyRl['blocked']) {
        echo json_encode(['success' => false, 'error' => 'Too many verification attempts. Try again later.', 'retry_after' => $verifyRl['retry_after']]);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Invalid code']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, username, email, plan, two_factor_enabled, two_factor_method FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }

    $purpose = 'verify_email';
    $stmt = $db->prepare("SELECT id, code_hash, expires_at, attempts, (expires_at > NOW()) AS not_expired FROM email_verification_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    $uid = (int)$user['id'];
    $stmt->bind_param('is', $uid, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Verification code not found']);
        exit;
    }
    if ((int)($row['not_expired'] ?? 0) !== 1) {
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Verification code expired']);
        exit;
    }
    if ((int)$row['attempts'] >= 5) {
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Too many attempts, request a new code']);
        exit;
    }

    $ok = hash_equals((string)$row['code_hash'], hash('sha256', $code));
    if (!$ok) {
        $attemptStmt = $db->prepare("UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = ?");
        $codeId = (int)$row['id'];
        $attemptStmt->bind_param('i', $codeId);
        $attemptStmt->execute();
        $attemptStmt->close();
        auth_rate_limit_fail($db, 'verify_email_code', $verifyIdentifier, 6, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Invalid verification code']);
        exit;
    }
    auth_rate_limit_clear($db, 'verify_email_code', $verifyIdentifier);

    $verified = 1;
    $updateStmt = $db->prepare("UPDATE users SET email_verified = ?, email_verified_at = NOW() WHERE id = ?");
    $updateStmt->bind_param('ii', $verified, $uid);
    $updateStmt->execute();
    $updateStmt->close();

    $useStmt = $db->prepare("UPDATE email_verification_codes SET used_at = NOW() WHERE id = ?");
    $codeId = (int)$row['id'];
    $useStmt->bind_param('i', $codeId);
    $useStmt->execute();
    $useStmt->close();

    if ((int)($user['two_factor_enabled'] ?? 0) === 1) {
        $_SESSION['pending_2fa_user_id'] = $uid;
        $_SESSION['pending_2fa_expires'] = time() + 300;
        echo json_encode(['success' => false, 'requires_2fa' => true, 'method' => $user['two_factor_method'] ?: 'totp']);
        exit;
    }

    auth_finalize_login($user);
    $mobileAuth = auth_mobile_auth_payload($db, (int)$user['id']);
    echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free', 'mobile_auth' => $mobileAuth]);
    exit;
}

if ($action === 'verify_2fa') {
    $pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
    $verify2faIdentifier = 'u' . $pendingUserId . '|' . auth_client_ip();
        $verify2faRl = auth_rate_limit_status($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
        if ($verify2faRl['blocked']) {
            echo json_encode(['success' => false, 'error' => 'Too many 2FA attempts. Try again later.', 'retry_after' => $verify2faRl['retry_after']]);
            exit;
        }

    $pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
    $pendingExpiry = (int)($_SESSION['pending_2fa_expires'] ?? 0);
    if ($pendingUserId <= 0 || $pendingExpiry < time()) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);
        echo json_encode(['success' => false, 'error' => '2FA challenge expired, please login again']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, username, email, plan, two_factor_enabled, two_factor_method, totp_secret FROM users WHERE id = ?");
    $stmt->bind_param('i', $pendingUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int)$user['two_factor_enabled'] !== 1) {
        echo json_encode(['success' => false, 'error' => '2FA not enabled']);
        exit;
    }

    $method = $user['two_factor_method'] ?? 'totp';
    $recoveryCode = trim($_POST['recovery_code'] ?? '');
    if ($recoveryCode !== '') {
        if (!auth_use_recovery_code($db, $pendingUserId, $recoveryCode)) {
            auth_rate_limit_fail($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
            echo json_encode(['success' => false, 'error' => 'Invalid or already used recovery code']);
            exit;
        }
        auth_rate_limit_clear($db, 'verify_2fa', $verify2faIdentifier);
        auth_finalize_login($user);
        echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free', 'used_recovery_code' => true]);
        exit;
    }

    if ($method === 'totp') {
        $code = trim($_POST['code'] ?? '');
        if (!auth_verify_totp((string)$user['totp_secret'], $code)) {
            auth_rate_limit_fail($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
            echo json_encode(['success' => false, 'error' => 'Invalid authenticator code']);
            exit;
        }
    } elseif ($method === 'yubikey') {
        $otp = trim($_POST['yubikey_otp'] ?? '');
        $verify = auth_validate_yubikey_otp($otp);
        if (!$verify['ok']) {
            auth_rate_limit_fail($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
            echo json_encode(['success' => false, 'error' => $verify['error']]);
            exit;
        }
        $publicId = $verify['public_id'];
        $checkStmt = $db->prepare("SELECT id FROM user_yubikeys WHERE user_id = ? AND public_id = ? AND active = 1");
        $checkStmt->bind_param('is', $pendingUserId, $publicId);
        $checkStmt->execute();
        $key = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if (!$key) {
            auth_rate_limit_fail($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
            echo json_encode(['success' => false, 'error' => 'This YubiKey is not enrolled for your account']);
            exit;
        }
        $useStmt = $db->prepare("UPDATE user_yubikeys SET last_used_at = NOW() WHERE user_id = ? AND public_id = ?");
        $useStmt->bind_param('is', $pendingUserId, $publicId);
        $useStmt->execute();
        $useStmt->close();
    } else {
        auth_rate_limit_fail($db, 'verify_2fa', $verify2faIdentifier, 8, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Unsupported 2FA method']);
        exit;
    }

    auth_rate_limit_clear($db, 'verify_2fa', $verify2faIdentifier);

    auth_finalize_login($user);
    $mobileAuth = auth_mobile_auth_payload($db, (int)$user['id']);
    echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free', 'mobile_auth' => $mobileAuth]);
    exit;
}

if ($action === 'get_2fa_status') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT two_factor_enabled, two_factor_method FROM users WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $kStmt = $db->prepare("SELECT COUNT(*) c FROM user_yubikeys WHERE user_id = ? AND active = 1");
    $kStmt->bind_param('i', $uid);
    $kStmt->execute();
    $yubiCount = (int)($kStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $kStmt->close();

    $rStmt = $db->prepare("SELECT COUNT(*) c FROM user_2fa_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $rStmt->bind_param('i', $uid);
    $rStmt->execute();
    $recoveryRemaining = (int)($rStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $rStmt->close();

    echo json_encode([
        'success' => true,
        'enabled' => (int)($row['two_factor_enabled'] ?? 0) === 1,
        'method' => $row['two_factor_method'] ?? null,
        'has_yubikey' => $yubiCount > 0,
        'recovery_codes_remaining' => $recoveryRemaining,
    ]);
    exit;
}

if ($action === 'setup_2fa_totp') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $secret = auth_base32_encode(random_bytes(20));
    $stmt = $db->prepare("UPDATE users SET totp_pending_secret = ? WHERE id = ?");
    $stmt->bind_param('si', $secret, $uid);
    $stmt->execute();
    $stmt->close();

    $username = $_SESSION['username'] ?? 'user';
    $issuer = rawurlencode('Lyralink AI');
    $label = rawurlencode('Lyralink AI:' . $username);
    $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

    echo json_encode(['success' => true, 'secret' => $secret, 'otpauth_url' => $otpauth]);
    exit;
}

if ($action === 'enable_2fa_totp') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $code = trim($_POST['code'] ?? '');
    $stmt = $db->prepare("SELECT totp_pending_secret FROM users WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pendingSecret = $row['totp_pending_secret'] ?? null;
    if (!$pendingSecret) {
        echo json_encode(['success' => false, 'error' => 'Setup not started']);
        exit;
    }
    if (!auth_verify_totp($pendingSecret, $code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid authenticator code']);
        exit;
    }

    $enabled = 1;
    $method = 'totp';
    $stmt = $db->prepare("UPDATE users SET two_factor_enabled = ?, two_factor_method = ?, totp_secret = ?, totp_pending_secret = NULL WHERE id = ?");
    $stmt->bind_param('issi', $enabled, $method, $pendingSecret, $uid);
    $stmt->execute();
    $stmt->close();
    $codes = auth_create_recovery_codes($db, $uid);
    echo json_encode(['success' => true, 'method' => 'totp', 'recovery_codes' => $codes]);
    exit;
}

if ($action === 'register_2fa_yubikey') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $otp = trim($_POST['yubikey_otp'] ?? '');
    $label = trim($_POST['label'] ?? 'YubiKey');
    $verify = auth_validate_yubikey_otp($otp);
    if (!$verify['ok']) {
        echo json_encode(['success' => false, 'error' => $verify['error']]);
        exit;
    }
    $publicId = $verify['public_id'];

    $stmt = $db->prepare("INSERT INTO user_yubikeys (user_id, public_id, label, active, last_used_at) VALUES (?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE label = VALUES(label), active = 1, last_used_at = NOW()");
    $stmt->bind_param('iss', $uid, $publicId, $label);
    $stmt->execute();
    $stmt->close();

    $enabled = 1;
    $method = 'yubikey';
    $stmt = $db->prepare("UPDATE users SET two_factor_enabled = ?, two_factor_method = ? WHERE id = ?");
    $stmt->bind_param('isi', $enabled, $method, $uid);
    $stmt->execute();
    $stmt->close();

    $codes = auth_create_recovery_codes($db, $uid);
    echo json_encode(['success' => true, 'method' => 'yubikey', 'recovery_codes' => $codes]);
    exit;
}

if ($action === 'regenerate_recovery_codes') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT two_factor_enabled FROM users WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['two_factor_enabled'] ?? 0) !== 1) {
        echo json_encode(['success' => false, 'error' => 'Enable 2FA before generating recovery codes']);
        exit;
    }
    $codes = auth_create_recovery_codes($db, $uid);
    echo json_encode(['success' => true, 'recovery_codes' => $codes]);
    exit;
}

if ($action === 'disable_2fa') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $enabled = 0;
    $nullMethod = null;
    $nullSecret = null;
    $stmt = $db->prepare("UPDATE users SET two_factor_enabled = ?, two_factor_method = ?, totp_pending_secret = ?, totp_secret = ? WHERE id = ?");
    $stmt->bind_param('isssi', $enabled, $nullMethod, $nullSecret, $nullSecret, $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── LOGOUT ──
if ($action === 'logout') {
    $token = api_bearer_token();
    if ($token !== '') {
        $tokenHash = hash('sha256', $token);
        $revokeStmt = $db->prepare("UPDATE user_mobile_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL");
        if ($revokeStmt) {
            $revokeStmt->bind_param('s', $tokenHash);
            $revokeStmt->execute();
            $revokeStmt->close();
        }
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'app_bootstrap') {
    $plan = $_SESSION['plan'] ?? 'free';
    if (!empty($_SESSION['user_id'])) {
        $planStmt = $db->prepare("SELECT plan FROM users WHERE id = ? LIMIT 1");
        if ($planStmt) {
            $uid = (int)$_SESSION['user_id'];
            $planStmt->bind_param('i', $uid);
            $planStmt->execute();
            $row = $planStmt->get_result()->fetch_assoc();
            $planStmt->close();
            $plan = $row['plan'] ?? $plan;
            $_SESSION['plan'] = $plan;
        }
    }

    echo json_encode([
        'success' => true,
        'logged_in' => !empty($_SESSION['user_id']),
        'username' => $_SESSION['username'] ?? null,
        'plan' => $plan,
        'privacy_url' => auth_base_url() . '/pages/privacy.php',
        'terms_url' => auth_base_url() . '/pages/tos.php',
        'support_url' => auth_base_url() . '/pages/support.php',
        'account_deletion_supported' => true,
        'requires_apple_iap' => true,
    ]);
    exit;
}

// ── CHECK SESSION ──
if ($action === 'check') {
    if (!empty($_SESSION['user_id'])) {
        // Fetch fresh plan from DB in case it changed (e.g. after upgrade)
        $stmt = $db->prepare("SELECT plan, two_factor_enabled, two_factor_method, email_verified FROM users WHERE id = ?");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $plan = $row['plan'] ?? 'free';
        $_SESSION['plan'] = $plan;
        echo json_encode([
            'logged_in' => true,
            'username' => $_SESSION['username'],
            'plan' => $plan,
            'email_verified' => (int)($row['email_verified'] ?? 0) === 1,
            'two_factor_enabled' => (int)($row['two_factor_enabled'] ?? 0) === 1,
            'two_factor_method' => $row['two_factor_method'] ?? null,
            'privacy_url' => auth_base_url() . '/pages/privacy.php',
            'terms_url' => auth_base_url() . '/pages/tos.php',
            'support_url' => auth_base_url() . '/pages/support.php',
            'account_deletion_supported' => true,
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

// ── CREATE API KEY ──
if ($action === 'create_api_key') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    // Max 5 keys per user
    $stmt = $db->prepare("SELECT COUNT(*) c FROM api_keys WHERE user_id = ? AND active = 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    if ($count >= 5) { echo json_encode(['success' => false, 'error' => 'Maximum 5 active keys allowed']); exit; }

    $label  = trim($_POST['label'] ?? 'Default Key');
    $label  = substr($label, 0, 100) ?: 'Default Key';
    $newKey = 'lyr_' . bin2hex(random_bytes(28)); // lyr_ prefix + 56 char hex = 60 chars total

    $stmt = $db->prepare("INSERT INTO api_keys (user_id, api_key, label) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $_SESSION['user_id'], $newKey, $label);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'api_key' => $newKey]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create key']);
    }
    $stmt->close();
    exit;
}

// ── LIST API KEYS ──
if ($action === 'list_keys') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $stmt = $db->prepare("
        SELECT k.id, k.api_key, k.label, k.requests_today, k.requests_total,
               k.last_used_at, k.reset_at, k.active, k.created_at, u.plan
        FROM api_keys k
        JOIN users u ON u.id = k.user_id
        WHERE k.user_id = ?
        ORDER BY k.created_at DESC
    ");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $keys   = [];
    while ($row = $result->fetch_assoc()) {
        // Reset daily count if new day
        if ($row['reset_at'] !== date('Y-m-d')) {
            $resetAt = date('Y-m-d');
            $keyId = (int)$row['id'];
            $resetStmt = $db->prepare("UPDATE api_keys SET requests_today = 0, reset_at = ? WHERE id = ?");
            $resetStmt->bind_param('si', $resetAt, $keyId);
            $resetStmt->execute();
            $resetStmt->close();
            $row['requests_today'] = 0;
        }
        // Mask key for display: show prefix + last 4 chars
        $masked = substr($row['api_key'], 0, 8) . str_repeat('•', 40) . substr($row['api_key'], -4);
        $keys[] = array_merge($row, ['api_key' => $masked, 'full_key' => $row['api_key']]);
    }
    $stmt->close();
    echo json_encode(['success' => true, 'keys' => $keys]);
    exit;
}

// ── REVOKE API KEY ──
if ($action === 'revoke_api_key') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $keyId = (int)($_POST['key_id'] ?? 0);
    $stmt  = $db->prepare("UPDATE api_keys SET active = 0 WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $keyId, $_SESSION['user_id']);
    $stmt->execute();
    echo json_encode(['success' => $stmt->affected_rows > 0]);
    $stmt->close();
    exit;
}

// ── GET CHAT HISTORY ──
if ($action === 'history') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $stmt = $db->prepare("SELECT role, message FROM chat_history WHERE user_id = ? ORDER BY created_at ASC LIMIT 100");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = ['role' => $row['role'], 'content' => $row['message']];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

// ════════════════════════════════
// ── DISCORD SYNC — REDEEM TOKEN ──
if ($action === 'discord_sync') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $token = strtoupper(trim($_POST['token'] ?? ''));
    if (!$token) { echo json_encode(['success' => false, 'error' => 'Token required']); exit; }

    $stmt = $db->prepare("SELECT * FROM discord_sync_tokens WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['success' => false, 'error' => 'Invalid or expired code. Run .sync again in Discord.']); exit; }

    $stmt = $db->prepare("SELECT id FROM users WHERE discord_id = ? AND id != ?");
    $stmt->bind_param('si', $row['discord_id'], $_SESSION['user_id']);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($conflict) { echo json_encode(['success' => false, 'error' => 'This Discord is already linked to another account.']); exit; }

    $stmt = $db->prepare("UPDATE users SET discord_id = ?, discord_tag = ? WHERE id = ?");
    $stmt->bind_param('ssi', $row['discord_id'], $row['discord_tag'], $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("DELETE FROM discord_sync_tokens WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'discord_tag' => $row['discord_tag']]);
    exit;
}

// ── DISCORD SYNC — STATUS ──
if ($action === 'discord_status') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare("SELECT discord_id, discord_tag FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['success' => true, 'linked' => !empty($row['discord_id']), 'discord_tag' => $row['discord_tag'] ?? null]);
    exit;
}

// ── DISCORD SYNC — UNLINK ──
if ($action === 'discord_unlink') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("UPDATE users SET discord_id = NULL, discord_tag = NULL WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── DATA DELETION REQUEST STATUS ──
if ($action === 'get_data_deletion_status') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id, request_type, status, created_at, updated_at FROM user_data_deletion_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to load deletion status']);
        exit;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'has_request' => !empty($row),
        'request' => $row ?: null,
    ]);
    exit;
}

// ── REQUEST FULL ACCOUNT / DATA DELETION ──
if ($action === 'request_data_deletion') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];

    $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ? LIMIT 1");
    if (!$userStmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to load account']);
        exit;
    }
    $userStmt->bind_param('i', $uid);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }

    $pendingStmt = $db->prepare("SELECT id, status, created_at, support_ticket_ref FROM user_data_deletion_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
    if (!$pendingStmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to check existing requests']);
        exit;
    }
    $pendingStmt->bind_param('i', $uid);
    $pendingStmt->execute();
    $pending = $pendingStmt->get_result()->fetch_assoc();
    $pendingStmt->close();

    if ($pending) {
        echo json_encode(['success' => true, 'already_requested' => true, 'request' => $pending]);
        exit;
    }

    $username = (string)$user['username'];
    $email = trim((string)($user['email'] ?? ''));
    $ip = auth_client_ip();
    $ticket = auth_create_deletion_support_ticket($db, $uid, $username, $email, $ip);
    $ticketId = (int)($ticket['id'] ?? 0);
    $ticketRef = (string)($ticket['ref'] ?? '');
    $insertStmt = $db->prepare("INSERT INTO user_data_deletion_requests (user_id, username, email, request_type, status, requested_ip, support_ticket_id, support_ticket_ref) VALUES (?, ?, ?, 'full_account', 'pending', ?, NULLIF(?, 0), ?)");
    if (!$insertStmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to submit deletion request']);
        exit;
    }
    $insertStmt->bind_param('isssis', $uid, $username, $email, $ip, $ticketId, $ticketRef);
    $ok = $insertStmt->execute();
    $requestId = (int)$insertStmt->insert_id;
    $insertStmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to submit deletion request']);
        exit;
    }

    $supportEmail = auth_support_email($db);
    if ($supportEmail !== '') {
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email !== '' ? $email : 'No email on file', ENT_QUOTES, 'UTF-8');
        $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $body = "<div style='font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:24px;border-radius:10px'>"
            . "<h2 style='margin:0 0 12px;color:#fca5a5'>New data deletion request</h2>"
            . "<p style='margin:0 0 8px'><strong>User ID:</strong> {$uid}</p>"
            . "<p style='margin:0 0 8px'><strong>Username:</strong> {$safeUsername}</p>"
            . "<p style='margin:0 0 8px'><strong>Email:</strong> {$safeEmail}</p>"
            . "<p style='margin:0 0 8px'><strong>Request ID:</strong> {$requestId}</p>"
            . "<p style='margin:0 0 8px'><strong>IP:</strong> {$safeIp}</p>"
            . ($ticketRef !== '' ? "<p style='margin:0 0 8px'><strong>Support Ticket:</strong> {$ticketRef}</p>" : '')
            . "<p style='margin:12px 0 0;color:#94a3b8'>This request was submitted from the profile settings area.</p>"
            . "</div>";
        auth_system_mail($db, $supportEmail, 'Lyralink data deletion request #' . $requestId, $body);
    }

    echo json_encode([
        'success' => true,
        'request' => [
            'id' => $requestId,
            'request_type' => 'full_account',
            'status' => 'pending',
            'support_ticket_ref' => $ticketRef !== '' ? $ticketRef : null,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

// ── DELETE SAVED CHAT HISTORY ──
if ($action === 'delete_saved_chat_data') {
    if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
    $uid = (int)$_SESSION['user_id'];
    $username = (string)$_SESSION['username'];

    $deletedMessages = 0;
    $deletedConvs = 0;
    $deletedLogs = 0;

    try {
        $db->begin_transaction();

        $msgStmt = $db->prepare("DELETE m FROM user_conv_messages m INNER JOIN user_convs c ON c.conv_id = m.conv_id WHERE c.user_id = ?");
        if (!$msgStmt) {
            throw new RuntimeException('Failed to prepare message deletion');
        }
        $msgStmt->bind_param('i', $uid);
        $msgStmt->execute();
        $deletedMessages = $msgStmt->affected_rows;
        $msgStmt->close();

        $convStmt = $db->prepare("DELETE FROM user_convs WHERE user_id = ?");
        if (!$convStmt) {
            throw new RuntimeException('Failed to prepare conversation deletion');
        }
        $convStmt->bind_param('i', $uid);
        $convStmt->execute();
        $deletedConvs = $convStmt->affected_rows;
        $convStmt->close();

        $logStmt = $db->prepare("DELETE FROM conversations WHERE user_id = ?");
        if (!$logStmt) {
            throw new RuntimeException('Failed to prepare chat log deletion');
        }
        $logStmt->bind_param('s', $username);
        $logStmt->execute();
        $deletedLogs = $logStmt->affected_rows;
        $logStmt->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to delete saved chat data']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'deleted_messages' => $deletedMessages,
        'deleted_conversations' => $deletedConvs,
        'deleted_logs' => $deletedLogs,
    ]);
    exit;
}

// CONVERSATION SYNC ENDPOINTS// ════════════════════════════════

// ── LIST CONVERSATIONS ──
if ($action === 'list_convs') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'convs' => []]); exit; }
    $uid = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("\
        SELECT c.conv_id, c.title, c.updated_at,
               COUNT(m.id) AS msg_count
        FROM user_convs c
        LEFT JOIN user_conv_messages m ON m.conv_id = c.conv_id
        WHERE c.user_id = ?
        GROUP BY c.conv_id, c.title, c.updated_at
        ORDER BY c.updated_at DESC
        LIMIT 50
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'convs' => [], 'error' => 'Failed to prepare conversation list']);
        exit;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $convs = [];
    while ($row = $result->fetch_assoc()) $convs[] = $row;
    $stmt->close();
    echo json_encode(['success' => true, 'convs' => $convs]);
    exit;
}

// ── GET CONVERSATION MESSAGES ──
if ($action === 'get_conv') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $uid    = (int)$_SESSION['user_id'];
    $convId = trim($_POST['conv_id'] ?? '');
    if (!$convId) { echo json_encode(['success' => false, 'error' => 'No conv_id']); exit; }

    // Verify ownership
    $check = $db->prepare("SELECT id FROM user_convs WHERE conv_id = ? AND user_id = ?");
    if (!$check) {
        echo json_encode(['success' => false, 'error' => 'Failed to verify conversation ownership']);
        exit;
    }
    $check->bind_param('si', $convId, $uid);
    $check->execute();
    $checkResult = $check->get_result();
    if (!$checkResult->num_rows) {
        $check->close();
        echo json_encode(['success' => false, 'error' => 'Not found']);
        exit;
    }
    $check->close();

    $stmt = $db->prepare("SELECT role, content, thinking FROM user_conv_messages WHERE conv_id = ? ORDER BY created_at ASC");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to load conversation']);
        exit;
    }
    $stmt->bind_param('s', $convId);
    $stmt->execute();
    $result = $stmt->get_result();
    $msgs = [];
    while ($row = $result->fetch_assoc()) $msgs[] = $row;
    $stmt->close();
    echo json_encode(['success' => true, 'messages' => $msgs]);
    exit;
}

// ── SAVE MESSAGE (called after each send/receive) ──
if ($action === 'save_msg') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $uid    = (int)$_SESSION['user_id'];
    $convId = trim($_POST['conv_id'] ?? '');
    $role   = $_POST['role']    ?? '';
    $content = $_POST['content'] ?? '';
    $thinking = trim((string)($_POST['thinking'] ?? ''));
    $title  = substr($_POST['title'] ?? 'New Chat', 0, 100);

    if (!$convId || !in_array($role, ['user','assistant']) || !$content) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']); exit;
    }

    // Upsert conversation
    $stmt = $db->prepare("INSERT INTO user_convs (conv_id, user_id, title, updated_at) VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE title = VALUES(title), updated_at = NOW()");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare conversation save']);
        exit;
    }
    $stmt->bind_param('sis', $convId, $uid, $title);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Failed to save conversation']);
        exit;
    }
    $stmt->close();

    // Insert message
    $stmt = $db->prepare("INSERT INTO user_conv_messages (conv_id, role, content, thinking) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare message save']);
        exit;
    }
    $thinkingValue = ($role === 'assistant' && $thinking !== '') ? $thinking : null;
    $stmt->bind_param('ssss', $convId, $role, $content, $thinkingValue);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Failed to save message']);
        exit;
    }
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE CONVERSATION ──
if ($action === 'delete_conv') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $uid    = (int)$_SESSION['user_id'];
    $convId = trim($_POST['conv_id'] ?? '');

    $stmt = $db->prepare("DELETE FROM user_convs WHERE conv_id = ? AND user_id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete conversation']);
        exit;
    }
    $stmt->bind_param('si', $convId, $uid);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("DELETE FROM user_conv_messages WHERE conv_id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete conversation messages']);
        exit;
    }
    $stmt->bind_param('s', $convId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── RENAME CONVERSATION ──
if ($action === 'rename_conv') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }
    $uid    = (int)$_SESSION['user_id'];
    $convId = trim($_POST['conv_id'] ?? '');
    $title  = substr($_POST['title'] ?? 'New Chat', 0, 100);

    $stmt = $db->prepare("UPDATE user_convs SET title = ? WHERE conv_id = ? AND user_id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to rename conversation']);
        exit;
    }
    $stmt->bind_param('ssi', $title, $convId, $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>