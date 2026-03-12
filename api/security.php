<?php

function api_bootstrap_env(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPath = dirname(__DIR__) . '/.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

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

        if (getenv($key) === false || getenv($key) === '') {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
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

function api_allowed_origins(): array {
    $configured = $_ENV['ALLOWED_ORIGINS'] ?? getenv('ALLOWED_ORIGINS') ?: '';
    $origins = array_values(array_filter(array_map('trim', explode(',', $configured))));

    if (!$origins) {
        $origins = [
            'https://ai.cloudhavenx.com',
            'https://cloudhavenx.com',
        ];
    }

    return $origins;
}

function api_is_same_origin_request(array $allowedOrigins): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        return in_array($origin, $allowedOrigins, true);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        foreach ($allowedOrigins as $allowed) {
            if (strpos($referer, $allowed . '/') === 0 || $referer === $allowed) {
                return true;
            }
        }
        return false;
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
    $defaults = [
        'host' => $fallback['host'] ?? 'localhost',
        'user' => $fallback['user'] ?? 'app_user',
        'pass' => $fallback['pass'] ?? '',
        'name' => $fallback['name'] ?? 'aicloud',
    ];

    return [
        'host' => api_get_secret('DB_HOST', $defaults['host']),
        'user' => api_get_secret('DB_USER', $defaults['user']),
        'pass' => api_get_secret('DB_PASS', $defaults['pass']),
        'name' => api_get_secret('DB_NAME', $defaults['name']),
    ];
}
