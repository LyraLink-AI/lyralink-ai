<?php

function benchmark_storage_root(): string {
    $env = getenv('BENCHMARK_STORAGE_DIR');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    return dirname(dirname(__DIR__)) . '/benchmark_private';
}

function benchmark_storage_path(string $relative = ''): string {
    $root = benchmark_storage_root();
    if ($relative === '') {
        return $root;
    }
    return $root . '/' . ltrim($relative, '/');
}
