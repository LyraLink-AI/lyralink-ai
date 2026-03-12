<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

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

$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_method VARCHAR(20) DEFAULT NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(128) DEFAULT NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_pending_secret VARCHAR(128) DEFAULT NULL");

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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conv_created (conv_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function auth_finalize_login(array $user): void {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_email'] = $user['email'] ?? null;
    $_SESSION['plan'] = $user['plan'] ?? 'free';
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);

    if (($user['username'] ?? '') === 'developer') {
        setcookie('lyralink_dev', 'bypass', 0, '/', '', false, false);
    }
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
    $smtpFrom = $smtpFrom ?: api_get_secret('SMTP_FROM', 'no-reply@cloudhavenx.com');

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

    $subject = 'Verify your Lyralink account';
    $safeUsername = htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = "<div style='font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:24px;border-radius:10px'>"
        . "<h2 style='margin:0 0 12px;color:#a78bfa'>Verify your email</h2>"
        . "<p style='margin:0 0 12px'>Hi {$safeUsername}, use this code to verify your account:</p>"
        . "<div style='font-size:28px;letter-spacing:4px;font-weight:700;color:#ffffff;background:#1e293b;padding:12px 16px;border-radius:8px;display:inline-block'>{$safeCode}</div>"
        . "<p style='margin:12px 0 0;color:#94a3b8'>This code expires in 15 minutes.</p>"
        . "</div>";

    $mailOk = auth_system_mail($db, $user['email'], $subject, $html);
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

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'register',
    'login',
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
    'save_msg',
    'delete_conv',
    'rename_conv',
]);

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
        $mail = auth_issue_email_code($db, $newUserId);
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

    $stmt = $db->prepare("SELECT id, username, email, password, plan, email_verified, two_factor_enabled, two_factor_method FROM users WHERE email = ?");
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

        auth_finalize_login($user);
        echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free']);
    } else {
        auth_rate_limit_fail($db, 'login', $loginIdentifier, 8, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
    }
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
    $stmt = $db->prepare("SELECT id, code_hash, expires_at, attempts FROM email_verification_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
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
    if (strtotime($row['expires_at']) < time()) {
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
    echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free']);
    exit;
}

if ($action === 'verify_2fa') {
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
    echo json_encode(['success' => true, 'username' => $user['username'], 'plan' => $user['plan'] ?? 'free']);
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
    session_destroy();
    echo json_encode(['success' => true]);
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

    $stmt = $db->prepare("SELECT role, content FROM user_conv_messages WHERE conv_id = ? ORDER BY created_at ASC");
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
    $stmt = $db->prepare("INSERT INTO user_conv_messages (conv_id, role, content) VALUES (?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare message save']);
        exit;
    }
    $stmt->bind_param('sss', $convId, $role, $content);
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