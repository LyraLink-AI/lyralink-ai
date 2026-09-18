<?php

function is_docker_available(): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $out = trim((string)shell_exec('command -v docker 2>/dev/null'));
    if ($out === '') {
        $cached = false;
        return false;
    }
    $ok = trim((string)shell_exec('docker info --format "{{.ServerVersion}}" 2>/dev/null'));
    $cached = $ok !== '';
    return $cached;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function extract_code_blocks(string $text): array {
    $blocks = [];
    if (!preg_match_all('/```([a-zA-Z0-9_+.-]*)\\n([\\s\\S]*?)```/', $text, $matches, PREG_SET_ORDER)) {
        return $blocks;
    }
    foreach ($matches as $m) {
        $lang = strtolower(trim($m[1] ?? ''));
        $code = rtrim((string)($m[2] ?? ''));
        if ($code === '') {
            continue;
        }
        $blocks[] = ['lang' => $lang, 'code' => $code];
    }
    return $blocks;
}

function normalize_lang(string $lang, string $code): string {
    $lang = strtolower(trim($lang));
    if ($lang === '' && str_contains($code, '<!DOCTYPE html')) return 'html';
    if ($lang === '' && preg_match('/<html|<body|<head/i', $code)) return 'html';
    if (in_array($lang, ['javascript', 'js', 'node'], true)) return 'javascript';
    if (in_array($lang, ['typescript', 'ts'], true)) return 'typescript';
    if (in_array($lang, ['py', 'python'], true)) return 'python';
    if (in_array($lang, ['php'], true)) return 'php';
    if (in_array($lang, ['bash', 'sh', 'shell'], true)) return 'bash';
    if (in_array($lang, ['html', 'css', 'json'], true)) return $lang;
    return $lang;
}

function code_runner_spec(string $lang): ?array {
    return match ($lang) {
        'javascript' => [
            'filename' => 'snippet.js',
            'image' => 'node:20-alpine',
            'docker_cmd' => 'node --check snippet.js',
            'host_cmd' => 'node --check snippet.js',
        ],
        'typescript' => [
            'filename' => 'snippet.ts',
            'image' => 'node:20-alpine',
            'docker_cmd' => 'node --check snippet.ts',
            'host_cmd' => 'node --check snippet.ts',
            'note' => 'TypeScript is syntax-checked with node parser and may fail on TS-only syntax.',
        ],
        'python' => [
            'filename' => 'snippet.py',
            'image' => 'python:3.12-alpine',
            'docker_cmd' => 'python -m py_compile snippet.py',
            'host_cmd' => 'python3 -m py_compile snippet.py',
        ],
        'php' => [
            'filename' => 'snippet.php',
            'image' => 'php:8.3-cli-alpine',
            'docker_cmd' => 'php -l snippet.php',
            'host_cmd' => 'php -l snippet.php',
        ],
        'bash' => [
            'filename' => 'snippet.sh',
            'image' => 'bash:5.2',
            'docker_cmd' => 'bash -n snippet.sh',
            'host_cmd' => 'bash -n snippet.sh',
        ],
        'json' => [
            'filename' => 'snippet.json',
            'image' => 'python:3.12-alpine',
            'docker_cmd' => 'python -m json.tool snippet.json > /dev/null',
            'host_cmd' => 'python3 -m json.tool snippet.json > /dev/null',
        ],
        'html' => [
            'filename' => 'snippet.html',
            'image' => 'python:3.12-alpine',
            'docker_cmd' => 'python -c "from html.parser import HTMLParser; HTMLParser().feed(open(\"snippet.html\",\"r\",encoding=\"utf-8\").read()); print(\"HTML parse OK\")"',
            'host_cmd' => 'python3 -c "from html.parser import HTMLParser; HTMLParser().feed(open(\"snippet.html\",\"r\",encoding=\"utf-8\").read()); print(\"HTML parse OK\")"',
        ],
        'css' => [
            'filename' => 'snippet.css',
            'image' => 'python:3.12-alpine',
            'docker_cmd' => 'python -c "import re; s=open(\"snippet.css\",\"r\",encoding=\"utf-8\").read(); opens=s.count(\"{\"); closes=s.count(\"}\"); print(\"CSS braces balanced\" if opens==closes else f\"CSS brace mismatch: {opens}!={closes}\"); raise SystemExit(0 if opens==closes else 1)"',
            'host_cmd' => 'python3 -c "import re; s=open(\"snippet.css\",\"r\",encoding=\"utf-8\").read(); opens=s.count(\"{\"); closes=s.count(\"}\"); print(\"CSS braces balanced\" if opens==closes else f\"CSS brace mismatch: {opens}!={closes}\"); raise SystemExit(0 if opens==closes else 1)"',
        ],
        default => null,
    };
}

function run_sandbox_check(string $lang, string $code, bool $preferDocker, bool $allowHostFallback): array {
    $spec = code_runner_spec($lang);
    if (!$spec) {
        return ['ok' => false, 'lang' => $lang, 'status' => 'unsupported', 'output' => 'No checker configured for this language.'];
    }

    if (strlen($code) > 60000) {
        return [
            'ok' => false,
            'lang' => $lang,
            'status' => 'skipped',
            'output' => 'Snippet is too large for sandbox validation. Reduce size and try again.',
        ];
    }

    $usedDocker = false;
    $cmd = '';
    $tmpDir = null;
    if ($preferDocker && is_docker_available()) {
        $usedDocker = true;
        $codeB64 = base64_encode($code);
        $fileName = $spec['filename'];
        $bootstrap = 'set -e; mkdir -p /work; printf %s "$CODE_B64" | base64 -d > '
            . escapeshellarg($fileName)
            . '; '
            . $spec['docker_cmd'];
        $cmd = 'timeout 30s docker run --rm --network none --memory 128m --cpus 0.5 '
            . '-e CODE_B64=' . escapeshellarg($codeB64)
            . ' -w /work '
            . escapeshellarg($spec['image'])
            . ' sh -lc '
            . escapeshellarg($bootstrap)
            . ' 2>&1';
    } elseif ($allowHostFallback) {
        $tmpDir = sys_get_temp_dir() . '/lyralink-test-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'lang' => $lang, 'status' => 'error', 'output' => 'Failed to create temp workspace.'];
        }
        $filePath = $tmpDir . '/' . $spec['filename'];
        $bytes = @file_put_contents($filePath, $code);
        if ($bytes === false || !is_file($filePath)) {
            rrmdir($tmpDir);
            return ['ok' => false, 'lang' => $lang, 'status' => 'error', 'output' => 'Failed to write snippet file for host validation.'];
        }
        $cmd = 'cd ' . escapeshellarg($tmpDir)
            . ' && timeout 20s sh -lc '
            . escapeshellarg($spec['host_cmd'])
            . ' 2>&1';
    } else {
        return [
            'ok' => false,
            'lang' => $lang,
            'status' => 'skipped',
            'output' => 'Docker unavailable and host fallback disabled.',
        ];
    }

    $out = [];
    $exit = 1;
    exec($cmd, $out, $exit);
    $output = trim(implode("\n", $out));
    if ($output === '') {
        $output = $exit === 0 ? 'No errors reported.' : 'Validation failed with no output.';
    }

    if ($tmpDir) {
        rrmdir($tmpDir);
    }
    return array_filter([
        'ok' => $exit === 0,
        'lang' => $lang,
        'status' => $exit === 0 ? 'passed' : 'failed',
        'runtime' => $usedDocker ? 'docker' : 'host',
        'output' => mb_substr($output, 0, 1400),
        'note' => $spec['note'] ?? null,
    ], fn($v) => $v !== null);
}

function run_generated_code_tests(string $reply, bool $preferDocker, bool $allowHostFallback, int $maxBlocks = 3): array {
    $blocks = extract_code_blocks($reply);
    if (empty($blocks)) {
        return [
            'enabled' => true,
            'found_blocks' => 0,
            'tested_blocks' => 0,
            'summary' => 'No fenced code blocks found to test.',
            'results' => [],
        ];
    }

    $results = [];
    $tested = 0;
    foreach ($blocks as $block) {
        if ($tested >= $maxBlocks) {
            break;
        }
        $lang = normalize_lang($block['lang'], $block['code']);
        if ($lang === '') {
            continue;
        }
        $res = run_sandbox_check($lang, $block['code'], $preferDocker, $allowHostFallback);
        $results[] = $res;
        if (($res['status'] ?? '') !== 'unsupported') {
            $tested++;
        }
    }

    $failed = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === false && in_array($r['status'] ?? '', ['failed', 'error'], true)));
    $passed = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));

    return [
        'enabled' => true,
        'found_blocks' => count($blocks),
        'tested_blocks' => count($results),
        'summary' => $failed > 0
            ? "Validation found {$failed} issue(s) across {$passed} passing block(s)."
            : "Validation passed for {$passed} block(s).",
        'results' => $results,
    ];
}

// ════════════════════════════════
// HELPER: SOLVE MOLTBOOK VERIFICATION
// ════════════════════════════════
// ── BUILD MOLTBOOK CONTEXT FROM DB ──
