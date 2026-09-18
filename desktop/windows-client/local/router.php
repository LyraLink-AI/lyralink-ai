<?php
// Router for PHP built-in server used by desktop local mode.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docRoot = __DIR__ . '/../web';

if ($uri === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}

$filePath = realpath($docRoot . $uri);
if ($filePath && str_starts_with($filePath, realpath($docRoot)) && is_file($filePath)) {
    return false;
}

if ($uri === '/' || $uri === '') {
    require $docRoot . '/landing.php';
    return true;
}

if ($uri === '/chat') {
    require $docRoot . '/chat.php';
    return true;
}

if (preg_match('#^/pages/([^/]+)/?$#', $uri, $m)) {
    $page = $docRoot . '/pages/' . $m[1] . '.php';
    if (is_file($page)) {
        require $page;
        return true;
    }
}

$directCandidate = $docRoot . $uri;
if (str_ends_with($directCandidate, '.php')) {
    $directPhp = realpath($directCandidate);
    $docRootReal = realpath($docRoot);
    if ($directPhp && $docRootReal && str_starts_with($directPhp, $docRootReal) && is_file($directPhp)) {
        require $directPhp;
        return true;
    }
}

http_response_code(404);
echo 'Not Found';
