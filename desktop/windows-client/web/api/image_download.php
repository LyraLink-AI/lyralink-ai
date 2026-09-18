<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lib/network_policy.php';

header('X-Content-Type-Options: nosniff');

$url = trim((string)($_GET['url'] ?? ''));
$prompt = trim((string)($_GET['prompt'] ?? ''));

if ($url === '') {
    http_response_code(400);
    echo 'Missing image URL.';
    exit;
}

$check = netpolicy_validate_outbound_url($url, false);
if (!$check['ok']) {
    http_response_code(400);
    echo 'Invalid image URL.';
    exit;
}

$parts = parse_url($url);
$host = strtolower((string)($parts['host'] ?? ''));
if ($host !== 'image.pollinations.ai') {
    http_response_code(403);
    echo 'Unsupported image host.';
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $httpCode < 200 || $httpCode >= 300 || $body === '') {
    http_response_code(502);
    echo 'Unable to download image.';
    exit;
}

$ext = 'png';
if (str_contains($contentType, 'jpeg')) {
    $ext = 'jpg';
} elseif (str_contains($contentType, 'webp')) {
    $ext = 'webp';
}

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($prompt)) ?: 'generated-image';
$slug = trim(substr($slug, 0, 48), '-');
$filename = 'lyralink-made-by-lyralink-' . ($slug !== '' ? $slug . '-' : '') . substr(sha1($url), 0, 8) . '.' . $ext;

header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Image-Made-By: Lyralink');
if ($prompt !== '') {
    header('X-Image-Prompt: ' . mb_substr($prompt, 0, 150));
}

echo $body;