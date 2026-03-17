<?php
require_once __DIR__ . '/../api/security.php';
session_start();

function sso_fail(string $message): void {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Mail SSO Error</title>    <link rel="stylesheet" href="/assets/css/mobile.css">
</head><body style="font-family:monospace;background:#0b0d14;color:#e5e7eb;padding:24px">';
    echo '<h2 style="margin:0 0 12px">Mail SSO failed</h2>';
    echo '<div style="color:#fca5a5">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<p style="margin-top:16px"><a href="/pages/mail_admin.php" style="color:#93c5fd">Back to Mail Admin</a></p>';
    echo '</body></html>';
    exit;
}

function sso_is_dev_user(): bool {
    $expected = (string)(api_get_secret('ADMIN_DEV_USERNAME', 'developer') ?? 'developer');
    return (string)($_SESSION['username'] ?? '') === $expected;
}

function sso_abs_url(string $baseHost, string $url): string {
    $url = trim($url);
    if ($url === '') {
        return 'https://' . $baseHost . '/webmail/?_task=login';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (!str_starts_with($url, '/')) {
        $url = '/' . $url;
    }
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
    return $scheme . '://' . $baseHost . $url;
}

function sso_host_matches_cookie_domain(string $host, string $cookieDomain): bool {
    $host = strtolower(trim($host));
    $cookieDomain = strtolower(ltrim(trim($cookieDomain), '.'));
    if ($host === '' || $cookieDomain === '') {
        return false;
    }
    return $host === $cookieDomain || str_ends_with($host, '.' . $cookieDomain);
}

function sso_build_origin(array $parts, string $fallbackScheme = 'https'): string {
    $scheme = strtolower((string)($parts['scheme'] ?? $fallbackScheme));
    $host = (string)($parts['host'] ?? '');
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    if ($host === '') {
        return '';
    }
    $origin = $scheme . '://' . $host;
    if ($port > 0 && !($scheme === 'https' && $port === 443) && !($scheme === 'http' && $port === 80)) {
        $origin .= ':' . $port;
    }
    return $origin;
}

function sso_abs_url_from_origin(string $origin, string $url): string {
    $url = trim($url);
    if ($url === '') {
        return $origin;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (!str_starts_with($url, '/')) {
        $url = '/' . $url;
    }
    return rtrim($origin, '/') . $url;
}

function sso_parse_set_cookies(array $headers): array {
    $cookies = [];
    foreach ($headers as $h) {
        if (!str_starts_with(strtolower($h), 'set-cookie:')) {
            continue;
        }
        $raw = trim(substr($h, strlen('set-cookie:')));
        if ($raw === '') {
            continue;
        }
        $parts = array_map('trim', explode(';', $raw));
        $first = array_shift($parts);
        if ($first === null || !str_contains($first, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $first, 2);
        $cookie = [
            'name' => trim($name),
            'value' => (string)$value,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        foreach ($parts as $attr) {
            if ($attr === '') continue;
            $kv = explode('=', $attr, 2);
            $k = strtolower(trim($kv[0]));
            $v = isset($kv[1]) ? trim($kv[1]) : '';
            if ($k === 'path') $cookie['path'] = $v !== '' ? $v : '/';
            if ($k === 'domain') $cookie['domain'] = ltrim(strtolower($v), '.');
            if ($k === 'secure') $cookie['secure'] = true;
            if ($k === 'httponly') $cookie['httponly'] = true;
            if ($k === 'samesite') $cookie['samesite'] = $v !== '' ? $v : 'Lax';
        }
        if ($cookie['name'] !== '') {
            $cookies[] = $cookie;
        }
    }
    return $cookies;
}

function sso_cookie_header_value(array $cookies): string {
    $pairs = [];
    foreach ($cookies as $cookie) {
        $name = trim((string)($cookie['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $pairs[$name] = $name . '=' . (string)($cookie['value'] ?? '');
    }
    return implode('; ', array_values($pairs));
}

function sso_index_cookies(array $cookies): array {
    $indexed = [];
    foreach ($cookies as $cookie) {
        $name = trim((string)($cookie['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $indexed[$name] = $cookie;
    }
    return $indexed;
}

function sso_extract_last_location(array $headers): string {
    $location = '';
    foreach ($headers as $h) {
        if (str_starts_with(strtolower($h), 'location:')) {
            $location = trim(substr($h, strlen('location:')));
        }
    }
    return $location;
}

function sso_parse_hidden_fields(string $html): array {
    $fields = [];
    if (preg_match_all('/<input[^>]+type=["\']?hidden["\']?[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $input) {
            if (preg_match('/name=["\']([^"\']+)["\']/i', $input, $nm)) {
                $name = $nm[1];
                $value = '';
                if (preg_match('/value=["\']([^"\']*)["\']/i', $input, $vm)) {
                    $value = html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $fields[$name] = $value;
            }
        }
    }
    return $fields;
}

function sso_parse_form_action(string $html): ?string {
    if (preg_match('/<form[^>]+id=["\']?login-form["\']?[^>]*action=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<form[^>]+action=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

if (!sso_is_dev_user()) {
    sso_fail('Forbidden');
}

$ticket = (string)($_GET['t'] ?? '');
if ($ticket === '') {
    sso_fail('Missing SSO ticket');
}

$tickets = $_SESSION['mail_sso_tickets'] ?? [];
if (!is_array($tickets) || !isset($tickets[$ticket])) {
    sso_fail('Invalid or expired SSO ticket');
}

$entry = $tickets[$ticket];
unset($_SESSION['mail_sso_tickets'][$ticket]);

if (!is_array($entry)) {
    sso_fail('Malformed SSO ticket');
}
if ((int)($entry['expires_at'] ?? 0) < time()) {
    sso_fail('SSO ticket expired');
}

$email = (string)($entry['email'] ?? '');
$password = (string)($entry['password'] ?? '');
$webmailCfg = (string)($entry['webmail_url'] ?? '/webmail/?_task=login');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sso_fail('Invalid mailbox address');
}
if (strlen($password) < 10) {
    sso_fail('Invalid mailbox password');
}

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($host === '') {
    sso_fail('Unable to detect host');
}
$loginUrl = sso_abs_url($host, $webmailCfg);
$parts = parse_url($loginUrl);
if (!is_array($parts)) {
    sso_fail('Invalid MAIL_WEBMAIL_URL');
}

$panelHost = strtolower($host);
$webmailHost = strtolower((string)($parts['host'] ?? ''));
if ($webmailHost === '') {
    sso_fail('MAIL_WEBMAIL_URL must include a valid host');
}

$cookieDomainEnv = trim((string)api_get_secret('MAIL_WEBMAIL_COOKIE_DOMAIN', ''));
$cookieDomain = ltrim(strtolower($cookieDomainEnv), '.');
$crossHost = $webmailHost !== $panelHost;
if ($crossHost) {
    if ($cookieDomain === '') {
        sso_fail('Cross-host webmail requires MAIL_WEBMAIL_COOKIE_DOMAIN (example: .cloudhavenx.com)');
    }
    if (!sso_host_matches_cookie_domain($panelHost, $cookieDomain) || !sso_host_matches_cookie_domain($webmailHost, $cookieDomain)) {
        sso_fail('MAIL_WEBMAIL_COOKIE_DOMAIN must match both panel and webmail hosts');
    }
}

$responseHeaders = [];
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $line) use (&$responseHeaders) {
    $trim = trim($line);
    if ($trim !== '') {
        $responseHeaders[] = $trim;
    }
    return strlen($line);
});
$html = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if (!is_string($html) || $html === '') {
    sso_fail('Could not load Roundcube login page: ' . $curlErr);
}
if ($httpCode < 200 || $httpCode >= 400) {
    sso_fail('Roundcube login endpoint returned HTTP ' . $httpCode);
}

$cookies = sso_parse_set_cookies($responseHeaders);
if (empty($cookies)) {
    sso_fail('Roundcube did not set login cookies; cannot continue SSO handshake');
}

$hidden = sso_parse_hidden_fields($html);
$formAction = sso_parse_form_action($html);
if ($formAction === null || trim($formAction) === '') {
    $formAction = '/webmail/?_task=login';
}
$currentScheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
$webmailOrigin = sso_build_origin($parts, $currentScheme);
if ($webmailOrigin === '') {
    sso_fail('Could not determine webmail origin');
}
$actionUrl = sso_abs_url_from_origin($webmailOrigin, $formAction);

$hidden['_task'] = $hidden['_task'] ?? 'login';
$hidden['_action'] = $hidden['_action'] ?? 'login';
$hidden['_user'] = $email;
$hidden['_pass'] = $password;

$postHeaders = [];
$postBody = http_build_query($hidden);
$cookieHeader = sso_cookie_header_value($cookies);

$ch2 = curl_init($actionUrl);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $postBody);
curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Referer: ' . $loginUrl,
    'Origin: ' . $webmailOrigin,
    'Cookie: ' . $cookieHeader,
]);
curl_setopt($ch2, CURLOPT_HEADERFUNCTION, static function ($curl, $line) use (&$postHeaders) {
    $trim = trim($line);
    if ($trim !== '') {
        $postHeaders[] = $trim;
    }
    return strlen($line);
});
$postResp = curl_exec($ch2);
$postHttp = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$postErr = curl_error($ch2);
curl_close($ch2);

if ($postResp === false) {
    sso_fail('Could not submit Roundcube login: ' . $postErr);
}
if ($postHttp < 200 || $postHttp >= 400) {
    sso_fail('Roundcube login submit failed with HTTP ' . $postHttp);
}

$postCookies = sso_parse_set_cookies($postHeaders);
$cookieMap = sso_index_cookies($cookies);
foreach ($postCookies as $cookie) {
    $cookieMap[$cookie['name']] = $cookie;
}
$finalCookies = array_values($cookieMap);
if (empty($finalCookies)) {
    sso_fail('Roundcube login did not return session cookies');
}

$redirectPath = sso_extract_last_location($postHeaders);
if ($redirectPath === '') {
    $redirectPath = '/?_task=mail';
}
$redirectUrl = sso_abs_url_from_origin($webmailOrigin, $redirectPath);

foreach ($finalCookies as $cookie) {
    $cookieSetDomain = '';
    if ($crossHost) {
        $cookieSetDomain = $cookieDomain;
    } elseif (!empty($cookie['domain']) && sso_host_matches_cookie_domain($panelHost, $cookie['domain'])) {
        $cookieSetDomain = ltrim(strtolower($cookie['domain']), '.');
    }

    setcookie($cookie['name'], $cookie['value'], [
        'expires' => 0,
        'path' => $cookie['path'] ?: '/',
        'domain' => $cookieSetDomain !== '' ? $cookieSetDomain : null,
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => in_array($cookie['samesite'], ['Lax', 'Strict', 'None'], true) ? $cookie['samesite'] : 'Lax',
    ]);
}

header('Location: ' . $redirectUrl, true, 302);
exit;
