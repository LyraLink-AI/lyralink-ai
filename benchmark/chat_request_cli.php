<?php

declare(strict_types=1);

ob_start();

register_shutdown_function(static function (): void {
    $buffer = ob_get_contents();
    $lastError = error_get_last();
    if ($buffer !== false && trim($buffer) !== '') {
        ob_end_flush();
        return;
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (is_array($lastError) && in_array((int)($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode([
            'reply' => '',
            'error' => 'php_fatal',
            'message' => (string)($lastError['message'] ?? 'Fatal CLI benchmark error'),
            'file' => (string)($lastError['file'] ?? ''),
            'line' => (int)($lastError['line'] ?? 0),
        ]);
        return;
    }

    echo json_encode([
        'reply' => '',
        'error' => 'empty_cli_response',
        'message' => 'CLI benchmark bridge produced no output.',
    ]);
});

$raw = stream_get_contents(STDIN);
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    echo json_encode([
        'reply' => null,
        'error' => 'invalid_benchmark_payload',
        'message' => 'Benchmark payload must be valid JSON.',
    ]);
    exit;
}

$_POST = $input;
$_GET = [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
$_SERVER['HTTP_USER_AGENT'] = 'LyralinkBenchmarkCLI/1.0';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require __DIR__ . '/../api/chat.php';
