<?php

if (is_readable(__DIR__ . '/../api/security.php')) {
    require_once __DIR__ . '/../api/security.php';
}

function benchmark_configured_access_key(): string {
    if (function_exists('api_get_secret')) {
        $secret = trim((string)api_get_secret('BENCHMARK_VIEW_KEY', ''));
        if ($secret !== '') {
            return $secret;
        }
    }
    $env = getenv('BENCHMARK_VIEW_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return trim((string)($_SERVER['BENCHMARK_VIEW_KEY'] ?? ''));
}

function benchmark_request_access_key(): string {
    $query = trim((string)($_GET['k'] ?? ''));
    if ($query !== '') {
        return $query;
    }
    $header = trim((string)($_SERVER['HTTP_X_BENCHMARK_KEY'] ?? ''));
    return $header;
}

function benchmark_request_is_chatgpt_agent(): bool {
    $ua = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '') {
        return false;
    }
    return str_contains($ua, 'chatgpt') || str_contains($ua, 'openai') || str_contains($ua, 'gptbot');
}

function benchmark_require_access(): void {
    // The benchmark pages are intentionally public for transparent, reproducible visibility.
    // Keep them readable without a secret key or ChatGPT-user-agent requirement.
    header('X-Robots-Tag: index, follow, archive');
    header('Cache-Control: public, max-age=300');
}
