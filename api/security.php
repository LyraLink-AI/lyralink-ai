<?php

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

function api_bootstrap_env(): void {
    static $lastLoadedMtime = null;
    static $dotenvKeys = [];

    $envPath = dirname(__DIR__) . '/.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $mtime = filemtime($envPath);
    $mtime = $mtime === false ? 0 : $mtime;
    if ($lastLoadedMtime !== null && $lastLoadedMtime === $mtime) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    $parsed = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $eqPos = strpos($trimmed, '=');
        if ($eqPos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $eqPos));
        $value = trim(substr($trimmed, $eqPos + 1));
        if ($key === '') {
            continue;
        }

        $first = $value[0] ?? '';
        $last = $value[strlen($value) - 1] ?? '';
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        $parsed[$key] = $value;
    }

    foreach (array_keys($dotenvKeys) as $key) {
        if (array_key_exists($key, $parsed)) {
            continue;
        }
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key], $dotenvKeys[$key]);
    }

    foreach ($parsed as $key => $value) {
        $envValue = getenv($key);
        $loadedFromDotenv = isset($dotenvKeys[$key]);
        if ($loadedFromDotenv || $envValue === false || $envValue === '') {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $dotenvKeys[$key] = true;
        }
    }

    $lastLoadedMtime = $mtime;
}

api_bootstrap_env();

function api_json_headers(): void {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

function api_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function api_action(): string {
    return $_POST['action'] ?? $_GET['action'] ?? '';
}

function api_request_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function api_bearer_token(): string {
    $auth = api_request_header('Authorization');
    if ($auth !== '' && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
        return trim((string)($matches[1] ?? ''));
    }

    $fallbacks = [
        api_request_header('X-Auth-Token'),
        api_request_header('X-Mobile-Token'),
        (string)($_POST['mobile_token'] ?? ''),
        (string)($_GET['mobile_token'] ?? ''),
    ];

    foreach ($fallbacks as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function api_is_mobile_client(): bool {
    $raw = strtolower(trim((string)($_POST['mobile_client'] ?? $_GET['mobile_client'] ?? api_request_header('X-Mobile-Client'))));
    return in_array($raw, ['1', 'true', 'yes', 'mobile', 'ios', 'android'], true);
}

function api_client_device_name(): string {
    $name = trim((string)($_POST['device_name'] ?? $_GET['device_name'] ?? api_request_header('X-Device-Name')));
    return $name !== '' ? substr($name, 0, 120) : 'Mobile App';
}

function api_try_mobile_token_auth(mysqli $db): ?array {
    $token = api_bearer_token();
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.plan
        FROM user_mobile_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ?
          AND t.revoked_at IS NULL
          AND t.expires_at > NOW()
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($user) {
        $useStmt = $db->prepare("UPDATE user_mobile_tokens
            SET last_used_at = NOW(),
                last_ip = LEFT(?, 45),
                last_user_agent = LEFT(?, 255)
            WHERE token_hash = ?");
        if ($useStmt) {
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app'), 0, 255);
            $useStmt->bind_param('sss', $ip, $ua, $tokenHash);
            $useStmt->execute();
            $useStmt->close();
        }
    }

    return $user;
}

function api_allowed_origins(): array {
    $configured = $_ENV['ALLOWED_ORIGINS'] ?? getenv('ALLOWED_ORIGINS') ?: '';
    $origins = array_values(array_filter(array_map('trim', explode(',', $configured))));

    if (!$origins) {
        $origins = api_default_allowed_origins();
    }

    $normalized = [];
    foreach ($origins as $origin) {
        $norm = api_normalize_origin($origin);
        if ($norm !== '') {
            $normalized[$norm] = true;
        }
    }

    // If ALLOWED_ORIGINS is present but malformed, fall back to safe defaults.
    if (!$normalized) {
        foreach (api_default_allowed_origins() as $origin) {
            $norm = api_normalize_origin($origin);
            if ($norm !== '') {
                $normalized[$norm] = true;
            }
        }
    }

    return array_keys($normalized);
}

function api_default_allowed_origins(): array {
    $origins = [
        'https://lyralinkai.com',
        'https://www.lyralinkai.com',
        'http://lyralinkai.com',
        'http://www.lyralinkai.com',
    ];

    $current = api_current_request_origin();
    if ($current !== '') {
        $origins[] = $current;
    }

    return array_values(array_unique($origins));
}

function api_origin_from_url(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = (string)($parts['scheme'] ?? '');
    $host = (string)($parts['host'] ?? '');
    if ($scheme === '' || $host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    if (isset($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }

    return $origin;
}

function api_current_request_origin(): string {
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostHeader === '') {
        return '';
    }

    $host = strtolower($hostHeader);
    $port = null;
    if (strpos($host, ':') !== false) {
        [$hostOnly, $portPart] = array_pad(explode(':', $host, 2), 2, '');
        $host = $hostOnly;
        if ($portPart !== '' && ctype_digit($portPart)) {
            $port = (int)$portPart;
        }
    }

    if ($host === '') {
        return '';
    }

    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $origin = $scheme . '://' . $host;
    if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
        $origin .= ':' . $port;
    }

    return $origin;
}

function api_normalize_origin(string $origin): string {
    $origin = trim($origin);
    if ($origin === '') {
        return '';
    }

    if ($origin === '*') {
        return '*';
    }

    $origin = rtrim($origin, '/');
    $parts = parse_url($origin);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($scheme === '' || $host === '') {
        return '';
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = null;
    }

    return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
}

function api_is_same_origin_request(array $allowedOrigins): bool {
    $currentOrigin = api_normalize_origin(api_current_request_origin());

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $requestOrigin = api_normalize_origin($origin);
        if ($requestOrigin === '') {
            return false;
        }
        if ($currentOrigin !== '' && $requestOrigin === $currentOrigin) {
            return true;
        }
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }
        return in_array($requestOrigin, $allowedOrigins, true);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $refererOrigin = api_normalize_origin(api_origin_from_url($referer));
        if ($refererOrigin === '') {
            return false;
        }
        if ($currentOrigin !== '' && $refererOrigin === $currentOrigin) {
            return true;
        }
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }
        return in_array($refererOrigin, $allowedOrigins, true);
    }

    // If no Origin/Referer exists, do not hard-fail for compatibility with some clients.
    return true;
}
function api_enforce_post_and_origin_for_actions(array $actions): void {
    $action = api_action();
    if (!in_array($action, $actions, true)) {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_fail('Method not allowed', 405);
    }

    if (!api_is_same_origin_request(api_allowed_origins())) {
        api_fail('Forbidden origin', 403);
    }
}

function api_get_secret(string $key, ?string $default = null): ?string {
    api_bootstrap_env();
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function api_require_secret(string $key): string {
    $value = api_get_secret($key);
    if ($value === null || $value === '') {
        api_fail('Server misconfigured: missing secret ' . $key, 500);
    }
    return $value;
}

function api_db_config(array $fallback = []): array {
    // Callers historically passed ['user' => 'app_user', 'pass' => '', 'name' => 'aicloud'].
    // Every one of those values is WRONG for this deployment (app_user does not
    // exist, the schema is admin_). They were harmless only while .env was
    // readable, because api_get_secret() overrode them. The moment .env became
    // unreadable, the code silently attempted a PASSWORDLESS connection as a
    // non-existent user and reported the misleading
    //   "Access denied for user 'app_user'@'localhost' (using password: NO)"
    // That exact sequence took the support WebSocket server down for days.
    // Strip any placeholder before it can ever become a live connection target.
    $placeholders = ['app_user', 'app_pass', 'aicloud', 'change_me', 'changeme', 'password'];

    $clean = [];
    foreach (['host', 'user', 'pass', 'name'] as $k) {
        $v = $fallback[$k] ?? null;
        if (is_string($v)) {
            $t = strtolower(trim($v));
            if ($t === '' || in_array($t, $placeholders, true)) {
                $v = null; // never inherit a placeholder
            }
        }
        $clean[$k] = $v;
    }

    $cfg = [
        'host' => (string)api_get_secret('DB_HOST', $clean['host'] ?? 'localhost'),
        'user' => (string)api_get_secret('DB_USER', $clean['user'] ?? ''),
        'pass' => (string)api_get_secret('DB_PASS', $clean['pass'] ?? ''),
        'name' => (string)api_get_secret('DB_NAME', $clean['name'] ?? 'admin_'),
    ];

    if ($cfg['user'] === '') {
        // Loud and specific, so the log names the real problem instead of
        // blaming a phantom account.
        error_log('[lyra-db] DB_USER could not be resolved (.env missing or unreadable). '
            . 'Refusing to attempt a passwordless connection as a placeholder user.');
    }

    return $cfg;
}
