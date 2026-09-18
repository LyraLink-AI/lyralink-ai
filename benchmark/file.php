<?php
require_once __DIR__ . '/_access.php';
require_once __DIR__ . '/_storage.php';
benchmark_require_access();

$relative = trim((string)($_GET['path'] ?? ''));
if ($relative === '') {
    http_response_code(400);
    echo 'Missing path';
    exit;
}

$allowedRoots = [
    'tasks/',
    'lyralink/',
    'scoring/',
    'external/',
];

$normalized = str_replace('\\', '/', $relative);
if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
    http_response_code(400);
    echo 'Invalid path';
    exit;
}

$allowed = false;
foreach ($allowedRoots as $root) {
    if (str_starts_with($normalized, $root)) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo 'Path not allowed';
    exit;
}

$full = realpath(benchmark_storage_path($normalized));
$base = realpath(benchmark_storage_root());
if ($full === false || $base === false || !str_starts_with($full, $base . '/')) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
if (!is_file($full) || !is_readable($full)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$contentType = 'text/plain; charset=utf-8';
if ($ext === 'json') {
    $contentType = 'application/json; charset=utf-8';
}

header('Content-Type: ' . $contentType);
header('X-Robots-Tag: noindex, nofollow, noarchive');
readfile($full);
