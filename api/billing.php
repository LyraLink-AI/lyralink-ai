<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';
session_start();
api_json_headers();

// ════════════════════════════════
// CONFIG — fill these in
// ════════════════════════════════
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
$paypalClientId  = api_get_secret('PAYPAL_CLIENT_ID', '');
$paypalSecret    = api_get_secret('PAYPAL_SECRET', '');
$paypalMode      = api_get_secret('PAYPAL_MODE', 'live'); // 'sandbox' for testing, 'live' for real payments

// ════════════════════════════════
// PLANS CONFIG
// ════════════════════════════════
$defaultLlmModel = trim((string)api_get_secret('LLM_MODEL', 'nousresearch/hermes-3-llama-3.1-70b')) ?: 'nousresearch/hermes-3-llama-3.1-70b';
$freeTokens = max(1, (int)api_get_secret('CHAT_TOKENS_FREE', '1200000'));
$basicTokens = max($freeTokens, (int)api_get_secret('CHAT_TOKENS_BASIC', '2200000'));
$proTokens = max($basicTokens, (int)api_get_secret('CHAT_TOKENS_PRO', '99999999'));
$enterpriseTokens = max($proTokens, (int)api_get_secret('CHAT_TOKENS_ENTERPRISE', '99999999'));
$plans = [
    'free'       => ['name' => 'Free',       'price' => 0,  'tokens' => $freeTokens,  'model' => $defaultLlmModel,       'unlimited' => false],
    'basic'      => ['name' => 'Basic',      'price' => 5,  'tokens' => $basicTokens,  'model' => $defaultLlmModel,       'unlimited' => false],
    'pro'        => ['name' => 'Pro',        'price' => 15, 'tokens' => $proTokens, 'model' => $defaultLlmModel,       'unlimited' => true],
    'enterprise' => ['name' => 'Enterprise', 'price' => 30, 'tokens' => $enterpriseTokens, 'model' => $defaultLlmModel,       'unlimited' => true],
];

$creditPacks = [
    'pack_100' => ['credits' => 100,  'price' => 3.00,  'label' => '100 Credits'],
    'pack_500' => ['credits' => 500,  'price' => 10.00, 'label' => '500 Credits'],
];

// PayPal plan IDs — create these in PayPal dashboard and paste the IDs here
$paypalPlanIds = [
    'basic'      => api_get_secret('PAYPAL_PLAN_BASIC', ''),
    'pro'        => api_get_secret('PAYPAL_PLAN_PRO', ''),
    'enterprise' => api_get_secret('PAYPAL_PLAN_ENTERPRISE', ''),
];

// ════════════════════════════════
// DB + AUTH CHECK
// ════════════════════════════════
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }
saas_bootstrap_schema($db);

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'create_order',
    'capture_order',
    'create_subscription',
    'activate_subscription',
    'cancel_subscription',
    'reconcile_subscription',
    'gift_credits',
    'gift_preview_recipient',
    'gift_history',
    'admin_gift_credits',
    'admin_gift_analytics',
    'verify_apple_receipt',
]);

$db->query("CREATE TABLE IF NOT EXISTS credit_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_ref VARCHAR(64) NOT NULL,
    transfer_type ENUM('user_gift','admin_grant') NOT NULL,
    from_user_id INT NULL,
    to_user_id INT NOT NULL,
    amount INT NOT NULL,
    note VARCHAR(255) NULL,
    status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
    sender_ip VARCHAR(45) DEFAULT NULL,
    actor_username VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transfer_ref (transfer_ref),
    KEY idx_from_user (from_user_id, created_at),
    KEY idx_to_user (to_user_id, created_at),
    KEY idx_transfer_type (transfer_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS billing_rate_limits (
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

$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS token_count INT UNSIGNED NOT NULL DEFAULT 0");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS token_reset_at DATE NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS apple_product_id VARCHAR(128) NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS apple_subscription_status VARCHAR(32) NULL");
$db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS apple_subscription_expires_at DATETIME NULL");
$db->query("CREATE TABLE IF NOT EXISTS app_store_receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    environment VARCHAR(20) DEFAULT NULL,
    product_id VARCHAR(128) DEFAULT NULL,
    original_transaction_id VARCHAR(128) DEFAULT NULL,
    expires_at DATETIME NULL,
    status_code INT NOT NULL DEFAULT 0,
    receipt_hash CHAR(64) NOT NULL,
    raw_response MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipt_hash (receipt_hash),
    KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (empty($_SESSION['user_id'])) {
    $mobileUser = api_try_mobile_token_auth($db);
    if ($mobileUser) {
        $_SESSION['user_id'] = (int)$mobileUser['id'];
        $_SESSION['username'] = (string)($mobileUser['username'] ?? '');
        $_SESSION['user_email'] = $mobileUser['email'] ?? null;
        $_SESSION['plan'] = $mobileUser['plan'] ?? 'free';
    }
}

$billingCtx = null;
$billingOrgId = 0;
$billingRole = '';
if (!empty($_SESSION['user_id'])) {
    $billingCtx = saas_context($db, (int)$_SESSION['user_id'], (string)($_SESSION['username'] ?? ''));
    $billingOrgId = (int)($billingCtx['org_id'] ?? 0);
    $billingRole = (string)($billingCtx['role'] ?? '');
}

const BILLING_HIGH_VALUE_2FA_THRESHOLD = 1000;
const BILLING_PAIR_COOLDOWN_SECONDS = 120;
const BILLING_NEW_ACCOUNT_HOURS = 48;
const BILLING_NEW_ACCOUNT_MAX_SINGLE = 250;
const BILLING_NEW_ACCOUNT_MAX_DAILY = 800;

function billing_apple_shared_secret(): string {
    return trim((string)api_get_secret('APPLE_IAP_SHARED_SECRET', api_get_secret('APPLE_SHARED_SECRET', '')));
}

function billing_apple_product_map(): array {
    return [
        'basic' => trim((string)api_get_secret('APPLE_IAP_PRODUCT_BASIC', 'com.lyralinkai.lyralink.basic')),
        'pro' => trim((string)api_get_secret('APPLE_IAP_PRODUCT_PRO', 'com.lyralinkai.lyralink.pro')),
        'enterprise' => trim((string)api_get_secret('APPLE_IAP_PRODUCT_ENTERPRISE', 'com.lyralinkai.lyralink.enterprise')),
    ];
}

function billing_plan_from_apple_product(string $productId): ?string {
    foreach (billing_apple_product_map() as $plan => $mappedProductId) {
        if ($mappedProductId !== '' && hash_equals($mappedProductId, $productId)) {
            return $plan;
        }
    }
    return null;
}

function billing_normalize_plan_code(string $plan): string {
    $normalized = strtolower(trim($plan));
    $aliases = [
        'starter' => 'basic',
        'plus' => 'pro',
        'business' => 'enterprise',
        'team' => 'enterprise',
    ];
    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }
    return in_array($normalized, ['free', 'basic', 'pro', 'enterprise'], true) ? $normalized : 'free';
}

function billing_plan_rank(string $plan): int {
    $ranks = ['free' => 0, 'basic' => 1, 'pro' => 2, 'enterprise' => 3];
    return $ranks[billing_normalize_plan_code($plan)] ?? 0;
}

function billing_highest_plan(string $a, string $b): string {
    $na = billing_normalize_plan_code($a);
    $nb = billing_normalize_plan_code($b);
    return billing_plan_rank($nb) > billing_plan_rank($na) ? $nb : $na;
}

function billing_verify_apple_receipt_remote(string $receiptData): array {
    $sharedSecret = billing_apple_shared_secret();
    if ($sharedSecret === '') {
        return ['ok' => false, 'error' => 'Apple IAP secret is not configured'];
    }

    $payload = json_encode([
        'receipt-data' => $receiptData,
        'password' => $sharedSecret,
        'exclude-old-transactions' => true,
    ]);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Failed to encode receipt payload'];
    }

    $request = static function (string $url, string $jsonBody): ?array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        curl_close($ch);
        return is_string($raw) ? json_decode($raw, true) : null;
    };

    $result = $request('https://buy.itunes.apple.com/verifyReceipt', $payload);
    if (($result['status'] ?? null) === 21007) {
        $result = $request('https://sandbox.itunes.apple.com/verifyReceipt', $payload);
    }

    if (!is_array($result)) {
        return ['ok' => false, 'error' => 'No response from Apple receipt verification'];
    }

    $status = (int)($result['status'] ?? -1);
    if ($status !== 0) {
        return ['ok' => false, 'error' => 'Apple receipt verification failed', 'status_code' => $status, 'raw' => $result];
    }

    $items = $result['latest_receipt_info'] ?? ($result['receipt']['in_app'] ?? []);
    if (!is_array($items) || !$items) {
        return ['ok' => false, 'error' => 'No in-app purchase items found in receipt', 'status_code' => $status, 'raw' => $result];
    }

    usort($items, static function (array $a, array $b): int {
        $aTs = (int)($a['expires_date_ms'] ?? $a['purchase_date_ms'] ?? 0);
        $bTs = (int)($b['expires_date_ms'] ?? $b['purchase_date_ms'] ?? 0);
        return $bTs <=> $aTs;
    });

    $latest = $items[0];
    $productId = (string)($latest['product_id'] ?? '');
    $plan = billing_plan_from_apple_product($productId);
    $expiresMs = (int)($latest['expires_date_ms'] ?? 0);
    $expiresAt = $expiresMs > 0 ? gmdate('Y-m-d H:i:s', (int)floor($expiresMs / 1000)) : null;
    $active = $expiresMs === 0 ? true : ($expiresMs > (int)(microtime(true) * 1000));

    return [
        'ok' => true,
        'environment' => (string)($result['environment'] ?? 'Production'),
        'product_id' => $productId,
        'plan' => $plan,
        'active' => $active,
        'expires_at' => $expiresAt,
        'original_transaction_id' => (string)($latest['original_transaction_id'] ?? ''),
        'status_code' => $status,
        'raw' => $result,
    ];
}

function billing_base32_decode(string $b32): string {
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

function billing_totp_code(string $secret, ?int $time = null, int $digits = 6, int $period = 30): string {
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

function billing_verify_totp(string $base32Secret, string $code): bool {
    $secret = billing_base32_decode($base32Secret);
    if ($secret === '' || !preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }
    $now = time();
    foreach ([-30, 0, 30] as $drift) {
        if (hash_equals(billing_totp_code($secret, $now + $drift), $code)) {
            return true;
        }
    }
    return false;
}

function billing_validate_yubikey_otp(string $otp): array {
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

function billing_use_recovery_code(mysqli $db, int $userId, string $inputCode): bool {
    $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $inputCode));
    if ($norm === '' || strlen($norm) < 8) {
        return false;
    }
    $hash = hash('sha256', $norm);
    $stmt = $db->prepare("SELECT id FROM user_2fa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    $updateStmt = $db->prepare("UPDATE user_2fa_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    if (!$updateStmt) {
        return false;
    }
    $codeId = (int)$row['id'];
    $updateStmt->bind_param('i', $codeId);
    $updateStmt->execute();
    $ok = $updateStmt->affected_rows > 0;
    $updateStmt->close();
    return $ok;
}

function billing_verify_gift_2fa(mysqli $db, int $userId, string $code, string $recoveryCode, string $yubikeyOtp): array {
    $stmt = $db->prepare("SELECT two_factor_enabled, two_factor_method, totp_secret FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to verify 2FA status'];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)($user['two_factor_enabled'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Enable account 2FA before sending high-value gifts'];
    }

    if ($recoveryCode !== '') {
        if (!billing_use_recovery_code($db, $userId, $recoveryCode)) {
            return ['ok' => false, 'error' => 'Invalid recovery code'];
        }
        return ['ok' => true];
    }

    $method = (string)($user['two_factor_method'] ?? 'totp');
    if ($method === 'totp') {
        if (!billing_verify_totp((string)($user['totp_secret'] ?? ''), $code)) {
            return ['ok' => false, 'error' => 'Invalid authenticator code'];
        }
        return ['ok' => true];
    }

    if ($method === 'yubikey') {
        $verify = billing_validate_yubikey_otp($yubikeyOtp);
        if (!$verify['ok']) {
            return ['ok' => false, 'error' => (string)$verify['error']];
        }
        $publicId = (string)$verify['public_id'];
        $checkStmt = $db->prepare("SELECT id FROM user_yubikeys WHERE user_id = ? AND public_id = ? AND active = 1");
        if (!$checkStmt) {
            return ['ok' => false, 'error' => 'Unable to verify YubiKey'];
        }
        $checkStmt->bind_param('is', $userId, $publicId);
        $checkStmt->execute();
        $key = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if (!$key) {
            return ['ok' => false, 'error' => 'This YubiKey is not enrolled for your account'];
        }
        $useStmt = $db->prepare("UPDATE user_yubikeys SET last_used_at = NOW() WHERE user_id = ? AND public_id = ?");
        if ($useStmt) {
            $useStmt->bind_param('is', $userId, $publicId);
            $useStmt->execute();
            $useStmt->close();
        }
        return ['ok' => true];
    }

    return ['ok' => false, 'error' => 'Unsupported 2FA method'];
}

function billing_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            continue;
        }
        $parts = explode(',', $raw);
        foreach ($parts as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

function billing_is_admin(): bool {
    $devUsername = api_get_secret('ADMIN_DEV_USERNAME', 'developer') ?? 'developer';
    $sessionUser = trim((string)($_SESSION['username'] ?? ''));
    return !empty($_SESSION['is_admin']) || ($sessionUser !== '' && $sessionUser === $devUsername);
}

function billing_transfer_ref(): string {
    return 'GFT-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}

function billing_rate_limit_status(mysqli $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): array {
    $stmt = $db->prepare("SELECT id, attempts, window_start, blocked_until FROM billing_rate_limits WHERE bucket = ? AND identifier = ? LIMIT 1");
    if (!$stmt) {
        return ['blocked' => false, 'attempts' => 0, 'retry_after' => 0];
    }
    $stmt->bind_param('ss', $bucket, $identifier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['blocked' => false, 'attempts' => 0, 'retry_after' => 0];
    }

    $now = time();
    $windowStart = strtotime((string)$row['window_start']) ?: $now;
    $blockedUntilTs = !empty($row['blocked_until']) ? strtotime((string)$row['blocked_until']) : 0;

    if ($now - $windowStart >= $windowSeconds) {
        $resetStmt = $db->prepare("UPDATE billing_rate_limits SET attempts = 0, window_start = NOW(), blocked_until = NULL, last_attempt_at = NOW() WHERE id = ?");
        if ($resetStmt) {
            $id = (int)$row['id'];
            $resetStmt->bind_param('i', $id);
            $resetStmt->execute();
            $resetStmt->close();
        }
        return ['blocked' => false, 'attempts' => 0, 'retry_after' => 0];
    }

    if ($blockedUntilTs > $now) {
        return ['blocked' => true, 'attempts' => (int)$row['attempts'], 'retry_after' => $blockedUntilTs - $now];
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        $blockStmt = $db->prepare("UPDATE billing_rate_limits SET blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), last_attempt_at = NOW() WHERE id = ?");
        if ($blockStmt) {
            $lock = $lockoutSeconds;
            $id = (int)$row['id'];
            $blockStmt->bind_param('ii', $lock, $id);
            $blockStmt->execute();
            $blockStmt->close();
        }
        return ['blocked' => true, 'attempts' => (int)$row['attempts'], 'retry_after' => $lockoutSeconds];
    }

    return ['blocked' => false, 'attempts' => (int)$row['attempts'], 'retry_after' => 0];
}

function billing_rate_limit_fail(mysqli $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): void {
    $stmt = $db->prepare("INSERT INTO billing_rate_limits (bucket, identifier, attempts, window_start, last_attempt_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE
        attempts = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1),
        window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, NOW(), window_start),
        blocked_until = IF(
            IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1) >= ?,
            DATE_ADD(NOW(), INTERVAL ? SECOND),
            blocked_until
        ),
        last_attempt_at = NOW()");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ssiiiii', $bucket, $identifier, $windowSeconds, $windowSeconds, $windowSeconds, $maxAttempts, $lockoutSeconds);
    $stmt->execute();
    $stmt->close();
}

function billing_rate_limit_clear(mysqli $db, string $bucket, string $identifier): void {
    $stmt = $db->prepare("DELETE FROM billing_rate_limits WHERE bucket = ? AND identifier = ?");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $bucket, $identifier);
    $stmt->execute();
    $stmt->close();
}

function billing_find_recipient(mysqli $db, string $input): ?array {
    $needle = trim($input);
    if ($needle === '') {
        return null;
    }

    if (preg_match('/^[0-9]+$/', $needle)) {
        $stmt = $db->prepare("SELECT id, username, email, credits FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $id = (int)$needle;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    $stmt = $db->prepare("SELECT id, username, email, credits FROM users WHERE username = ? OR email = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $needle, $needle);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function billing_mask_email(string $email): string {
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) {
        $local = str_repeat('*', strlen($local));
    } else {
        $local = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
    }
    return $local . '@' . $domain;
}

function billing_plan_from_paypal_plan_id(string $paypalPlanId, array $paypalPlanIds): ?string {
    $paypalPlanId = trim($paypalPlanId);
    if ($paypalPlanId === '') {
        return null;
    }
    foreach ($paypalPlanIds as $planCode => $configuredId) {
        if (is_string($configuredId) && $configuredId !== '' && hash_equals($configuredId, $paypalPlanId)) {
            return billing_normalize_plan_code((string)$planCode);
        }
    }
    return null;
}

// ════════════════════════════════
// HELPER: GET PAYPAL ACCESS TOKEN
// ════════════════════════════════
function getPaypalToken($clientId, $secret, $mode) {
    $url = $mode === 'live'
        ? 'https://api-m.paypal.com/v1/oauth2/token'
        : 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
}

// ════════════════════════════════
// HELPER: PAYPAL API CALL
// ════════════════════════════════
function paypalRequest($endpoint, $method, $body, $token, $mode) {
    $base = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch   = curl_init($base . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $data = [];
    if (is_string($response) && trim($response) !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $data['_http_status'] = $httpCode;
    if ($curlErr !== '') {
        $data['_curl_error'] = $curlErr;
    }
    return $data;
}

// ════════════════════════════════
// VERIFY APPLE IAP RECEIPT
// ════════════════════════════════
if ($action === 'verify_apple_receipt') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }

    $receiptData = trim((string)($_POST['receipt_data'] ?? ''));
    if ($receiptData === '') {
        echo json_encode(['success' => false, 'error' => 'Missing receipt_data']);
        exit;
    }

    $verification = billing_verify_apple_receipt_remote($receiptData);
    if (!$verification['ok']) {
        echo json_encode([
            'success' => false,
            'error' => $verification['error'] ?? 'Receipt verification failed',
            'status_code' => $verification['status_code'] ?? null,
        ]);
        exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $productId = (string)($verification['product_id'] ?? '');
    $plan = (string)($verification['plan'] ?? 'free');
    $status = !empty($verification['active']) ? 'active' : 'expired';
    $expiresAt = $verification['expires_at'] ?: null;
    $originalTransactionId = (string)($verification['original_transaction_id'] ?? '');
    $receiptHash = hash('sha256', $receiptData);
    $rawResponse = json_encode($verification['raw'] ?? []);
    $environment = (string)($verification['environment'] ?? 'Production');
    $statusCode = (int)($verification['status_code'] ?? 0);

    $storeStmt = $db->prepare("INSERT INTO app_store_receipts (user_id, environment, product_id, original_transaction_id, expires_at, status_code, receipt_hash, raw_response)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            environment = VALUES(environment),
            product_id = VALUES(product_id),
            original_transaction_id = VALUES(original_transaction_id),
            expires_at = VALUES(expires_at),
            status_code = VALUES(status_code),
            raw_response = VALUES(raw_response)");
    if ($storeStmt) {
        $storeStmt->bind_param('issssiss', $uid, $environment, $productId, $originalTransactionId, $expiresAt, $statusCode, $receiptHash, $rawResponse);
        $storeStmt->execute();
        $storeStmt->close();
    }

    if ($plan !== '' && $plan !== 'free' && !empty($verification['active'])) {
        $updateStmt = $db->prepare("UPDATE users SET plan = ?, apple_product_id = ?, apple_subscription_status = ?, apple_subscription_expires_at = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('ssssi', $plan, $productId, $status, $expiresAt, $uid);
            $updateStmt->execute();
            $updateStmt->close();
            $_SESSION['plan'] = $plan;
        }
    }

    echo json_encode([
        'success' => true,
        'provider' => 'apple_iap',
        'plan' => $plan,
        'product_id' => $productId,
        'active' => !empty($verification['active']),
        'expires_at' => $expiresAt,
        'environment' => $environment,
    ]);
    exit;
}

// ════════════════════════════════
// GET USER STATUS
// ════════════════════════════════
if ($action === 'status') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => true, 'logged_in' => false, 'plan' => 'free', 'tokens_used' => 0, 'tokens_limit' => 1200000, 'messages_used' => 0, 'messages_limit' => 1200000, 'credits' => 0]);
        exit;
    }

    $stmt = $db->prepare("SELECT plan, credits, token_count, token_reset_at FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Reset monthly token count if new month
    if (($user['token_reset_at'] ?? null) !== date('Y-m-01')) {
        $resetAt = date('Y-m-01');
        $stmt = $db->prepare("UPDATE users SET token_count = 0, token_reset_at = ? WHERE id = ?");
        $stmt->bind_param('si', $resetAt, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
        $user['token_count'] = 0;
    }

    $userPlanCode = billing_normalize_plan_code((string)($user['plan'] ?? 'free'));
    $orgPlanCode = $billingOrgId > 0
        ? billing_normalize_plan_code(saas_get_org_plan($db, $billingOrgId, $userPlanCode))
        : $userPlanCode;
    $effectivePlanCode = billing_highest_plan($userPlanCode, $orgPlanCode);
    $plan  = $plans[$effectivePlanCode] ?? $plans['free'];
    $usagePricing = [
        'input_tokens_per_block' => max(1, (int)api_get_secret('CHAT_USAGE_INPUT_TOKENS_PER_BLOCK', '1')),
        'usage_units_per_block' => max(1, (int)api_get_secret('CHAT_USAGE_UNITS_PER_BLOCK', '1')),
        'min_units_per_request' => max(0, (int)api_get_secret('CHAT_USAGE_MIN_UNITS_PER_REQUEST', '1')),
        'multiplier_default_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_DEFAULT_BPS', '10000')),
        'multiplier_3b_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_3B_BPS', '7500')),
        'multiplier_8b_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_8B_BPS', '10000')),
        'multiplier_70b_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_70B_BPS', '18500')),
        'multiplier_reasoning_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_REASONING_BPS', '21000')),
        'multiplier_code_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CODE_BPS', '11500')),
        'multiplier_creative_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CREATIVE_BPS', '10500')),
        'multiplier_cloud_bps' => max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CLOUD_BPS', '14000')),
        'auto_discount_bps' => max(100, min(10000, (int)api_get_secret('CHAT_USAGE_AUTO_DISCOUNT_BPS', '9000'))),
    ];
    echo json_encode([
        'success'        => true,
        'logged_in'      => true,
        'is_admin'       => billing_is_admin(),
        'plan'           => $effectivePlanCode,
        'plan_name'      => $plan['name'],
        'requires_apple_iap' => true,
        'ios_products'   => billing_apple_product_map(),
        'tokens_used'    => (int)($user['token_count'] ?? 0),
        'tokens_limit'   => (int)($plan['tokens'] ?? 1200000),
        // Legacy aliases for older clients.
        'messages_used'  => (int)($user['token_count'] ?? 0),
        'messages_limit' => (int)($plan['tokens'] ?? 1200000),
        'unlimited'      => $plan['unlimited'],
        'credits'        => (int)$user['credits'],
        'model'          => $plan['model'],
        'usage_pricing'  => $usagePricing,
        'org'            => $billingCtx,
    ]);
    exit;
}

if ($action === 'gift_preview_recipient') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $input = trim((string)($_POST['recipient'] ?? ''));
    if ($input === '') {
        echo json_encode(['success' => false, 'error' => 'Recipient required']);
        exit;
    }

    $recipient = billing_find_recipient($db, $input);
    if (!$recipient) {
        echo json_encode(['success' => false, 'error' => 'Recipient not found']);
        exit;
    }

    if ((int)$recipient['id'] === (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'You cannot gift credits to yourself']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'recipient' => [
            'id' => (int)$recipient['id'],
            'username' => (string)$recipient['username'],
            'email_masked' => billing_mask_email((string)($recipient['email'] ?? '')),
        ],
    ]);
    exit;
}

if ($action === 'gift_history') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.transfer_ref, t.transfer_type, t.amount, t.note, t.created_at,
        t.from_user_id, t.to_user_id,
        su.username AS sender_username,
        ru.username AS recipient_username
        FROM credit_transfers t
        LEFT JOIN users su ON su.id = t.from_user_id
        LEFT JOIN users ru ON ru.id = t.to_user_id
        WHERE t.from_user_id = ? OR t.to_user_id = ?
        ORDER BY t.created_at DESC
        LIMIT 30");
    $stmt->bind_param('ii', $uid, $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'transfer_ref' => $r['transfer_ref'],
            'type' => $r['transfer_type'],
            'amount' => (int)$r['amount'],
            'note' => (string)($r['note'] ?? ''),
            'created_at' => $r['created_at'],
            'direction' => ((int)$r['from_user_id'] === $uid) ? 'sent' : 'received',
            'from' => (string)($r['sender_username'] ?? 'system'),
            'to' => (string)($r['recipient_username'] ?? ''),
        ];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'history' => $rows]);
    exit;
}

if ($action === 'gift_credits') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }

    $senderId = (int)$_SESSION['user_id'];
    $recipientInput = trim((string)($_POST['recipient'] ?? ''));
    $amount = (int)($_POST['amount'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $factorCode = trim((string)($_POST['factor_code'] ?? ''));
    $factorRecoveryCode = trim((string)($_POST['factor_recovery_code'] ?? ''));
    $factorYubikeyOtp = trim((string)($_POST['factor_yubikey_otp'] ?? ''));
    $ip = billing_client_ip();

    if ($recipientInput === '') {
        echo json_encode(['success' => false, 'error' => 'Recipient is required']);
        exit;
    }
    if ($amount < 1 || $amount > 2000) {
        echo json_encode(['success' => false, 'error' => 'Amount must be between 1 and 2000 credits']);
        exit;
    }
    if (strlen($note) > 200) {
        echo json_encode(['success' => false, 'error' => 'Note is too long']);
        exit;
    }

    $identifier = 'u' . $senderId . '|' . $ip;
    $rl = billing_rate_limit_status($db, 'gift_credits', $identifier, 12, 900, 900);
    if ($rl['blocked']) {
        echo json_encode(['success' => false, 'error' => 'Too many gift attempts. Please try again later.', 'retry_after' => $rl['retry_after']]);
        exit;
    }

    $dailyStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS sent_today, COUNT(*) AS gifts_today
        FROM credit_transfers
        WHERE from_user_id = ? AND transfer_type = 'user_gift' AND status = 'completed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $dailyStmt->bind_param('i', $senderId);
    $dailyStmt->execute();
    $daily = $dailyStmt->get_result()->fetch_assoc();
    $dailyStmt->close();
    $sentToday = (int)($daily['sent_today'] ?? 0);
    $giftsToday = (int)($daily['gifts_today'] ?? 0);
    if ($giftsToday >= 25 || ($sentToday + $amount) > 10000) {
        billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Daily gifting limit reached']);
        exit;
    }

    $recipient = billing_find_recipient($db, $recipientInput);
    if (!$recipient) {
        billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
        echo json_encode(['success' => false, 'error' => 'Recipient not found']);
        exit;
    }

    $recipientId = (int)$recipient['id'];
    if ($recipientId === $senderId) {
        billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
        echo json_encode(['success' => false, 'error' => 'You cannot gift credits to yourself']);
        exit;
    }

    // Anti-fraud: lower limits for newly-created accounts.
    $riskStmt = $db->prepare("SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) AS account_hours FROM users WHERE id = ? LIMIT 1");
    if ($riskStmt) {
        $riskStmt->bind_param('i', $senderId);
        $riskStmt->execute();
        $risk = $riskStmt->get_result()->fetch_assoc();
        $riskStmt->close();
        $accountHours = (int)($risk['account_hours'] ?? 0);
        if ($accountHours < BILLING_NEW_ACCOUNT_HOURS) {
            if ($amount > BILLING_NEW_ACCOUNT_MAX_SINGLE || ($sentToday + $amount) > BILLING_NEW_ACCOUNT_MAX_DAILY) {
                billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
                echo json_encode(['success' => false, 'error' => 'New accounts have temporary lower gifting limits']);
                exit;
            }
        }
    }

    // Anti-fraud: cooldown between repeated gifts to the same recipient.
    $pairStmt = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS since_last_sec
        FROM credit_transfers
        WHERE from_user_id = ? AND to_user_id = ? AND transfer_type = 'user_gift' AND status = 'completed'");
    if ($pairStmt) {
        $pairStmt->bind_param('ii', $senderId, $recipientId);
        $pairStmt->execute();
        $pair = $pairStmt->get_result()->fetch_assoc();
        $pairStmt->close();
        $sinceLast = isset($pair['since_last_sec']) ? (int)$pair['since_last_sec'] : 999999;
        if ($sinceLast >= 0 && $sinceLast < BILLING_PAIR_COOLDOWN_SECONDS) {
            billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
            echo json_encode(['success' => false, 'error' => 'Please wait before sending another gift to this recipient']);
            exit;
        }
    }

    // Anti-fraud: cap daily spread to too many distinct recipients.
    $recipientDiversityStmt = $db->prepare("SELECT COUNT(DISTINCT to_user_id) AS recipient_count
        FROM credit_transfers
        WHERE from_user_id = ? AND transfer_type = 'user_gift' AND status = 'completed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    if ($recipientDiversityStmt) {
        $recipientDiversityStmt->bind_param('i', $senderId);
        $recipientDiversityStmt->execute();
        $diversity = $recipientDiversityStmt->get_result()->fetch_assoc();
        $recipientDiversityStmt->close();
        if ((int)($diversity['recipient_count'] ?? 0) >= 12) {
            billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
            echo json_encode(['success' => false, 'error' => 'Recipient diversity limit reached for today']);
            exit;
        }
    }

    if ($amount >= BILLING_HIGH_VALUE_2FA_THRESHOLD) {
        $verify2fa = billing_verify_gift_2fa($db, $senderId, $factorCode, $factorRecoveryCode, $factorYubikeyOtp);
        if (!$verify2fa['ok']) {
            billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
            echo json_encode([
                'success' => false,
                'requires_2fa' => true,
                'error' => (string)($verify2fa['error'] ?? 'Two-factor authentication is required for this transfer'),
            ]);
            exit;
        }
    }

    $db->begin_transaction();
    try {
        $senderStmt = $db->prepare("SELECT id, credits FROM users WHERE id = ? FOR UPDATE");
        $senderStmt->bind_param('i', $senderId);
        $senderStmt->execute();
        $sender = $senderStmt->get_result()->fetch_assoc();
        $senderStmt->close();
        if (!$sender) {
            throw new RuntimeException('Sender not found');
        }

        $recipientStmt = $db->prepare("SELECT id, username FROM users WHERE id = ? FOR UPDATE");
        $recipientStmt->bind_param('i', $recipientId);
        $recipientStmt->execute();
        $recipientLocked = $recipientStmt->get_result()->fetch_assoc();
        $recipientStmt->close();
        if (!$recipientLocked) {
            throw new RuntimeException('Recipient missing');
        }

        $balance = (int)($sender['credits'] ?? 0);
        if ($balance < $amount) {
            throw new RuntimeException('Insufficient credits');
        }

        $decStmt = $db->prepare("UPDATE users SET credits = credits - ? WHERE id = ?");
        $decStmt->bind_param('ii', $amount, $senderId);
        $decStmt->execute();
        $decStmt->close();

        $incStmt = $db->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
        $incStmt->bind_param('ii', $amount, $recipientId);
        $incStmt->execute();
        $incStmt->close();

        $transferRef = billing_transfer_ref();
        $actor = (string)($_SESSION['username'] ?? '');
        $insertStmt = $db->prepare("INSERT INTO credit_transfers (transfer_ref, transfer_type, from_user_id, to_user_id, amount, note, status, sender_ip, actor_username) VALUES (?, 'user_gift', ?, ?, ?, ?, 'completed', ?, ?)");
        $insertStmt->bind_param('siiisss', $transferRef, $senderId, $recipientId, $amount, $note, $ip, $actor);
        $insertStmt->execute();
        $insertStmt->close();

        $db->commit();
        billing_rate_limit_clear($db, 'gift_credits', $identifier);
        echo json_encode([
            'success' => true,
            'transfer_ref' => $transferRef,
            'recipient' => $recipientLocked['username'],
            'amount' => $amount,
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        billing_rate_limit_fail($db, 'gift_credits', $identifier, 12, 900, 900);
        $msg = $e->getMessage() === 'Insufficient credits' ? 'Insufficient credits' : 'Gift transfer failed';
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    exit;
}

if ($action === 'admin_gift_credits') {
    if (empty($_SESSION['user_id']) || !billing_is_admin()) {
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $recipientInput = trim((string)($_POST['recipient'] ?? ''));
    $amount = (int)($_POST['amount'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $ip = billing_client_ip();

    if ($recipientInput === '') {
        echo json_encode(['success' => false, 'error' => 'Recipient is required']);
        exit;
    }
    if ($amount < 1 || $amount > 50000) {
        echo json_encode(['success' => false, 'error' => 'Amount must be between 1 and 50000 credits']);
        exit;
    }
    if (strlen($note) > 200) {
        echo json_encode(['success' => false, 'error' => 'Note is too long']);
        exit;
    }

    $recipient = billing_find_recipient($db, $recipientInput);
    if (!$recipient) {
        echo json_encode(['success' => false, 'error' => 'Recipient not found']);
        exit;
    }
    $recipientId = (int)$recipient['id'];

    $db->begin_transaction();
    try {
        $lockStmt = $db->prepare("SELECT id, username FROM users WHERE id = ? FOR UPDATE");
        $lockStmt->bind_param('i', $recipientId);
        $lockStmt->execute();
        $recipientLocked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if (!$recipientLocked) {
            throw new RuntimeException('Recipient missing');
        }

        $incStmt = $db->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
        $incStmt->bind_param('ii', $amount, $recipientId);
        $incStmt->execute();
        $incStmt->close();

        $transferRef = billing_transfer_ref();
        $actor = (string)($_SESSION['username'] ?? 'admin');
        $insertStmt = $db->prepare("INSERT INTO credit_transfers (transfer_ref, transfer_type, from_user_id, to_user_id, amount, note, status, sender_ip, actor_username) VALUES (?, 'admin_grant', NULL, ?, ?, ?, 'completed', ?, ?)");
        $insertStmt->bind_param('siisss', $transferRef, $recipientId, $amount, $note, $ip, $actor);
        $insertStmt->execute();
        $insertStmt->close();

        $db->commit();
        echo json_encode([
            'success' => true,
            'transfer_ref' => $transferRef,
            'recipient' => $recipientLocked['username'],
            'amount' => $amount,
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => 'Admin gift failed']);
    }
    exit;
}

if ($action === 'admin_gift_analytics') {
    if (empty($_SESSION['user_id']) || !billing_is_admin()) {
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $summary = [
        'user_gift_24h_amount' => 0,
        'user_gift_24h_count' => 0,
        'admin_grant_24h_amount' => 0,
        'admin_grant_24h_count' => 0,
        'user_gift_7d_amount' => 0,
        'admin_grant_7d_amount' => 0,
    ];
    $summaryStmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN transfer_type = 'user_gift' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN amount ELSE 0 END), 0) AS user_gift_24h_amount,
        COALESCE(SUM(CASE WHEN transfer_type = 'user_gift' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS user_gift_24h_count,
        COALESCE(SUM(CASE WHEN transfer_type = 'admin_grant' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN amount ELSE 0 END), 0) AS admin_grant_24h_amount,
        COALESCE(SUM(CASE WHEN transfer_type = 'admin_grant' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS admin_grant_24h_count,
        COALESCE(SUM(CASE WHEN transfer_type = 'user_gift' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount ELSE 0 END), 0) AS user_gift_7d_amount,
        COALESCE(SUM(CASE WHEN transfer_type = 'admin_grant' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount ELSE 0 END), 0) AS admin_grant_7d_amount
        FROM credit_transfers
        WHERE status = 'completed'");
    if ($summaryStmt) {
        $summaryStmt->execute();
        $row = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();
        if ($row) {
            $summary = [
                'user_gift_24h_amount' => (int)$row['user_gift_24h_amount'],
                'user_gift_24h_count' => (int)$row['user_gift_24h_count'],
                'admin_grant_24h_amount' => (int)$row['admin_grant_24h_amount'],
                'admin_grant_24h_count' => (int)$row['admin_grant_24h_count'],
                'user_gift_7d_amount' => (int)$row['user_gift_7d_amount'],
                'admin_grant_7d_amount' => (int)$row['admin_grant_7d_amount'],
            ];
        }
    }

    $topSenders = [];
    $topSendersStmt = $db->prepare("SELECT u.username, t.from_user_id, SUM(t.amount) AS total_amount, COUNT(*) AS transfer_count
        FROM credit_transfers t
        LEFT JOIN users u ON u.id = t.from_user_id
        WHERE t.transfer_type = 'user_gift' AND t.status = 'completed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY t.from_user_id, u.username
        ORDER BY total_amount DESC
        LIMIT 10");
    if ($topSendersStmt) {
        $topSendersStmt->execute();
        $res = $topSendersStmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $topSenders[] = [
                'user_id' => (int)($r['from_user_id'] ?? 0),
                'username' => (string)($r['username'] ?? 'unknown'),
                'total_amount' => (int)($r['total_amount'] ?? 0),
                'transfer_count' => (int)($r['transfer_count'] ?? 0),
            ];
        }
        $topSendersStmt->close();
    }

    $topRecipients = [];
    $topRecipientsStmt = $db->prepare("SELECT u.username, t.to_user_id, SUM(t.amount) AS total_amount, COUNT(*) AS transfer_count
        FROM credit_transfers t
        LEFT JOIN users u ON u.id = t.to_user_id
        WHERE t.status = 'completed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY t.to_user_id, u.username
        ORDER BY total_amount DESC
        LIMIT 10");
    if ($topRecipientsStmt) {
        $topRecipientsStmt->execute();
        $res = $topRecipientsStmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $topRecipients[] = [
                'user_id' => (int)($r['to_user_id'] ?? 0),
                'username' => (string)($r['username'] ?? 'unknown'),
                'total_amount' => (int)($r['total_amount'] ?? 0),
                'transfer_count' => (int)($r['transfer_count'] ?? 0),
            ];
        }
        $topRecipientsStmt->close();
    }

    $highValue = [];
    $highValueStmt = $db->prepare("SELECT t.transfer_ref, t.transfer_type, t.amount, t.created_at,
        su.username AS sender_username, ru.username AS recipient_username
        FROM credit_transfers t
        LEFT JOIN users su ON su.id = t.from_user_id
        LEFT JOIN users ru ON ru.id = t.to_user_id
        WHERE t.status = 'completed' AND t.amount >= 1000
        ORDER BY t.created_at DESC
        LIMIT 25");
    if ($highValueStmt) {
        $highValueStmt->execute();
        $res = $highValueStmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $highValue[] = [
                'transfer_ref' => (string)$r['transfer_ref'],
                'type' => (string)$r['transfer_type'],
                'amount' => (int)$r['amount'],
                'created_at' => (string)$r['created_at'],
                'from' => (string)($r['sender_username'] ?? 'system'),
                'to' => (string)($r['recipient_username'] ?? 'unknown'),
            ];
        }
        $highValueStmt->close();
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'top_senders' => $topSenders,
        'top_recipients' => $topRecipients,
        'high_value' => $highValue,
    ]);
    exit;
}

// ════════════════════════════════
// CREATE PAYPAL ORDER (credit packs)
// ════════════════════════════════
if ($action === 'create_order') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $packId = $_POST['pack'] ?? '';
    $pack   = $creditPacks[$packId] ?? null;
    if (!$pack) { echo json_encode(['success' => false, 'error' => 'Invalid pack']); exit; }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) { echo json_encode(['success' => false, 'error' => 'PayPal auth failed']); exit; }

    $order = paypalRequest('/v2/checkout/orders', 'POST', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => ['currency_code' => 'USD', 'value' => number_format($pack['price'], 2)],
            'description' => 'Lyralink ' . $pack['label'],
            'custom_id'   => $_SESSION['user_id'] . ':' . $packId
        ]]
    ], $token, $paypalMode);

    if (!empty($order['id'])) {
        // Log pending transaction
        $stmt = $db->prepare("INSERT INTO transactions (user_id, paypal_order_id, type, credits_added, amount, status) VALUES (?, ?, 'credits', ?, ?, 'pending')");
        $stmt->bind_param('isid', $_SESSION['user_id'], $order['id'], $pack['credits'], $pack['price']);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'order_id' => $order['id']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Order creation failed', 'debug' => $order]);
    }
    exit;
}

// ════════════════════════════════
// CAPTURE PAYPAL ORDER (credit packs)
// ════════════════════════════════
if ($action === 'capture_order') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $orderId = $_POST['order_id'] ?? '';
    if (!$orderId) { echo json_encode(['success' => false, 'error' => 'No order ID']); exit; }

    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    $result = paypalRequest("/v2/checkout/orders/$orderId/capture", 'POST', [], $token, $paypalMode);

    if (($result['status'] ?? '') === 'COMPLETED') {
        $uid = (int)$_SESSION['user_id'];
        $db->begin_transaction();
        try {
            // Lock user + transaction rows to make capture idempotent under retries.
            $lockUserStmt = $db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
            $lockUserStmt->bind_param('i', $uid);
            $lockUserStmt->execute();
            $lockUserStmt->close();

            $stmt = $db->prepare("SELECT id, credits_added, status FROM transactions WHERE paypal_order_id = ? AND user_id = ? AND type = 'credits' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('si', $orderId, $uid);
            $stmt->execute();
            $tx = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$tx) {
                throw new RuntimeException('Transaction not found');
            }

            $creditsAdded = (int)($tx['credits_added'] ?? 0);
            $txStatus = strtolower((string)($tx['status'] ?? 'pending'));

            if ($txStatus === 'completed') {
                $db->commit();
                echo json_encode(['success' => true, 'credits_added' => $creditsAdded, 'already_processed' => true]);
                exit;
            }

            $applyStmt = $db->prepare("UPDATE transactions SET status = 'completed' WHERE id = ? AND status = 'pending'");
            $txId = (int)$tx['id'];
            $applyStmt->bind_param('i', $txId);
            $applyStmt->execute();
            $applied = $applyStmt->affected_rows > 0;
            $applyStmt->close();

            if (!$applied) {
                $db->commit();
                echo json_encode(['success' => true, 'credits_added' => $creditsAdded, 'already_processed' => true]);
                exit;
            }

            $creditStmt = $db->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
            $creditStmt->bind_param('ii', $creditsAdded, $uid);
            $creditStmt->execute();
            $creditStmt->close();

            $db->commit();
            echo json_encode(['success' => true, 'credits_added' => $creditsAdded]);
        } catch (Throwable $e) {
            $db->rollback();
            $msg = $e->getMessage() === 'Transaction not found' ? 'Transaction not found' : 'Capture processing failed';
            echo json_encode(['success' => false, 'error' => $msg]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Payment not completed', 'status' => $result['status'] ?? 'unknown']);
    }
    exit;
}

// ════════════════════════════════
// CREATE PAYPAL SUBSCRIPTION
// ════════════════════════════════
if ($action === 'create_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $planKey   = $_POST['plan'] ?? '';
    $paypalPlanId = $paypalPlanIds[$planKey] ?? null;
    if (!$paypalPlanId || $paypalPlanId === 'YOUR_PAYPAL_' . strtoupper($planKey) . '_PLAN_ID') {
        echo json_encode(['success' => false, 'error' => 'Plan not configured yet']);
        exit;
    }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) { echo json_encode(['success' => false, 'error' => 'PayPal auth failed']); exit; }

    $returnUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/billing_return.php?plan=' . $planKey;
    $cancelUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/?billing=cancelled';

    $sub = paypalRequest('/v1/billing/subscriptions', 'POST', [
        'plan_id'       => $paypalPlanId,
        'custom_id'     => 'uid:' . (int)$_SESSION['user_id'],
        'subscriber'    => ['email_address' => $_SESSION['user_email'] ?? ''],
        'application_context' => [
            'return_url'  => $returnUrl,
            'cancel_url'  => $cancelUrl,
            'brand_name'  => 'Lyralink AI',
            'user_action' => 'SUBSCRIBE_NOW'
        ]
    ], $token, $paypalMode);

    if (!empty($sub['id'])) {
        // Find the approval link
        $approvalUrl = null;
        foreach ($sub['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') { $approvalUrl = $link['href']; break; }
        }
        echo json_encode(['success' => true, 'subscription_id' => $sub['id'], 'approval_url' => $approvalUrl]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Subscription creation failed', 'debug' => $sub]);
    }
    exit;
}

// ════════════════════════════════
// ACTIVATE SUBSCRIPTION (after PayPal redirect back)
// ════════════════════════════════
if ($action === 'activate_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $subId   = $_POST['subscription_id'] ?? '';
    $planKey = $_POST['plan'] ?? '';

    if (!$subId || !isset($plans[$planKey])) {
        echo json_encode(['success' => false, 'error' => 'Invalid subscription data']);
        exit;
    }

    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    $subInfo = paypalRequest("/v1/billing/subscriptions/$subId", 'GET', null, $token, $paypalMode);

    if (($subInfo['status'] ?? '') === 'ACTIVE') {
        $uid = (int)$_SESSION['user_id'];
        $amount = (float)$plans[$planKey]['price'];
        $db->begin_transaction();
        try {
            $userLockStmt = $db->prepare("SELECT id, plan, paypal_sub_id FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
            $userLockStmt->bind_param('i', $uid);
            $userLockStmt->execute();
            $lockedUser = $userLockStmt->get_result()->fetch_assoc();
            $userLockStmt->close();
            if (!$lockedUser) {
                throw new RuntimeException('User not found');
            }

            $alreadyApplied = ((string)($lockedUser['paypal_sub_id'] ?? '') === $subId)
                && (billing_normalize_plan_code((string)($lockedUser['plan'] ?? 'free')) === billing_normalize_plan_code($planKey));

            $stmt = $db->prepare("SELECT id FROM transactions WHERE user_id = ? AND paypal_order_id = ? AND type = 'subscription' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('is', $uid, $subId);
            $stmt->execute();
            $existingTx = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($alreadyApplied && $existingTx) {
                $db->commit();
                echo json_encode(['success' => true, 'plan' => $planKey, 'already_processed' => true]);
                exit;
            }

            $updateUserStmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = ? WHERE id = ?");
            $updateUserStmt->bind_param('ssi', $planKey, $subId, $uid);
            $updateUserStmt->execute();
            $updateUserStmt->close();

            if (!$existingTx) {
                $insertTxStmt = $db->prepare("INSERT INTO transactions (user_id, paypal_order_id, type, plan, amount, status) VALUES (?, ?, 'subscription', ?, ?, 'completed')");
                $insertTxStmt->bind_param('issd', $uid, $subId, $planKey, $amount);
                $insertTxStmt->execute();
                $insertTxStmt->close();
            }

            // ── Reseller earnings hook (idempotent by deterministic tx ref) ─────
            if ($amount > 0 && !$existingTx) {
                $billingUserId = $uid;
                $resellerRow = $db->query("SELECT r.id, r.commission_rate FROM reseller_clients rc JOIN resellers r ON r.id = rc.reseller_id WHERE rc.client_user_id = $billingUserId AND r.status = 'active' LIMIT 1")->fetch_assoc();
                if ($resellerRow) {
                    $rid = (int)$resellerRow['id'];
                    $commRate = (float)$resellerRow['commission_rate'];
                    $earned = round($amount * $commRate / 100, 2);
                    $txRef = 'paypal_sub_' . $subId;
                    $stmtE = $db->prepare("INSERT IGNORE INTO reseller_earnings (reseller_id, client_user_id, transaction_ref, plan, gross_amount, commission_rate, earned_amount) VALUES (?,?,?,?,?,?,?)");
                    $stmtE->bind_param('iissddd', $rid, $billingUserId, $txRef, $planKey, $amount, $commRate, $earned);
                    $stmtE->execute();
                    $earnedInserted = $stmtE->affected_rows > 0;
                    $stmtE->close();
                    if ($earnedInserted) {
                        $db->query("UPDATE resellers SET total_earned = total_earned + $earned WHERE id = $rid");
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────────────

            $_SESSION['plan'] = $planKey;
            $db->commit();
            echo json_encode(['success' => true, 'plan' => $planKey]);
        } catch (Throwable $e) {
            $db->rollback();
            $msg = $e->getMessage() === 'User not found' ? 'User not found' : 'Subscription activation failed';
            echo json_encode(['success' => false, 'error' => $msg]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Subscription not active yet', 'status' => $subInfo['status'] ?? 'unknown']);
    }
    exit;
}

// ════════════════════════════════
// CANCEL SUBSCRIPTION
// ════════════════════════════════
if ($action === 'cancel_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $stmt = $db->prepare("SELECT paypal_sub_id FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user['paypal_sub_id']) { echo json_encode(['success' => false, 'error' => 'No active subscription']); exit; }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'PayPal auth failed']);
        exit;
    }

    $result = paypalRequest("/v1/billing/subscriptions/{$user['paypal_sub_id']}/cancel", 'POST', ['reason' => 'User requested cancellation'], $token, $paypalMode);
    $httpStatus = (int)($result['_http_status'] ?? 0);
    $paypalSuccess = in_array($httpStatus, [200, 202, 204], true)
        && empty($result['name'])
        && empty($result['error']);

    if (!$paypalSuccess) {
        echo json_encode([
            'success' => false,
            'error' => 'PayPal cancellation failed',
            'paypal_status' => $httpStatus,
        ]);
        exit;
    }

    $freePlan = 'free';
    $stmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = NULL WHERE id = ?");
    $stmt->bind_param('si', $freePlan, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
    $_SESSION['plan'] = $freePlan;
    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// RECONCILE SUBSCRIPTION WITH PAYPAL
// ════════════════════════════════
if ($action === 'reconcile_subscription') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT plan, paypal_sub_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $subId = trim((string)($row['paypal_sub_id'] ?? ''));
    if ($subId === '') {
        echo json_encode(['success' => false, 'error' => 'No linked subscription']);
        exit;
    }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'PayPal auth failed']);
        exit;
    }

    $subInfo = paypalRequest("/v1/billing/subscriptions/$subId", 'GET', null, $token, $paypalMode);
    $subStatus = strtoupper((string)($subInfo['status'] ?? ''));
    $paypalPlanId = (string)($subInfo['plan_id'] ?? '');
    $resolvedPlan = billing_plan_from_paypal_plan_id($paypalPlanId, $paypalPlanIds);
    $currentPlan = billing_normalize_plan_code((string)($row['plan'] ?? 'free'));

    if (in_array($subStatus, ['CANCELLED', 'EXPIRED', 'SUSPENDED'], true)) {
        $freePlan = 'free';
        $updateStmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = NULL WHERE id = ?");
        $updateStmt->bind_param('si', $freePlan, $uid);
        $updateStmt->execute();
        $updateStmt->close();
        $_SESSION['plan'] = $freePlan;
        echo json_encode([
            'success' => true,
            'reconciled' => true,
            'plan_before' => $currentPlan,
            'plan_after' => $freePlan,
            'paypal_status' => $subStatus,
        ]);
        exit;
    }

    if ($subStatus === 'ACTIVE' && $resolvedPlan !== null) {
        $updateStmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = ? WHERE id = ?");
        $updateStmt->bind_param('ssi', $resolvedPlan, $subId, $uid);
        $updateStmt->execute();
        $updateStmt->close();
        $_SESSION['plan'] = $resolvedPlan;
        echo json_encode([
            'success' => true,
            'reconciled' => true,
            'plan_before' => $currentPlan,
            'plan_after' => $resolvedPlan,
            'paypal_status' => $subStatus,
            'paypal_plan_id' => $paypalPlanId,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'reconciled' => false,
        'plan' => $currentPlan,
        'paypal_status' => $subStatus !== '' ? $subStatus : 'UNKNOWN',
        'paypal_plan_id' => $paypalPlanId,
        'error' => $resolvedPlan === null ? 'Unknown PayPal plan_id mapping' : null,
    ]);
    exit;
}

// ════════════════════════════════
// GET PLANS INFO (for frontend)
// ════════════════════════════════
if ($action === 'plans') {
    echo json_encode([
        'success' => true,
        'plans' => $plans,
        'packs' => $creditPacks,
        'requires_apple_iap' => true,
        'ios_products' => billing_apple_product_map(),
        'ios_purchase_note' => 'Use Apple in-app purchase for digital upgrades inside the iPhone app.',
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>