<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lib/network_policy.php';
lyra_session_boot();
api_json_headers();

// ════════════════════════════════
// CONFIG — fill these in
// ════════════════════════════════
$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];
$groqApiKey = api_get_secret('GROQ_API_KEY', '');

// ════════════════════════════════
// DEV ONLY — all dataset management requires developer login
// ════════════════════════════════
$devUsername = 'developer';
$devCookieBypass = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
$isDevUser = (($_SESSION['username'] ?? '') === $devUsername) || $devCookieBypass;

require_once __DIR__ . '/dataset_search.php';

function dataset_hf_access_token(): string {
    return trim((string)api_get_secret('HF_TOKEN', api_get_secret('HUGGINGFACE_TOKEN', '')));
}

function dataset_hf_require_token(): string {
    $token = dataset_hf_access_token();
    if ($token === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Missing Hugging Face token. Set HF_TOKEN (or HUGGINGFACE_TOKEN) in environment/.env.',
            'auth' => ['token_configured' => false],
        ]);
        exit;
    }
    return $token;
}

function dataset_hf_auth_headers(): array {
    $headers = [
        'Accept: application/json',
        'User-Agent: LyralinkDatasetManager/1.0 (+https://lyralinkai.com)',
    ];

    $token = dataset_hf_access_token();
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return $headers;
}

function dataset_hf_api_get_json(string $url, int $timeout = 12): ?array {
    $policy = netpolicy_validate_outbound_url($url, false);
    if (!($policy['ok'] ?? false)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => max(4, $timeout),
        CURLOPT_HTTPHEADER => dataset_hf_auth_headers(),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function dataset_hf_try_readme_excerpt(string $repoId, int $maxChars = 1200): string {
    $repoId = trim($repoId);
    if ($repoId === '') {
        return '';
    }

    $parts = explode('/', $repoId, 2);
    if (count($parts) !== 2) {
        return '';
    }
    $repoPath = rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
    $url = 'https://huggingface.co/datasets/' . $repoPath . '/resolve/main/README.md';
    $policy = netpolicy_validate_outbound_url($url, false);
    if (!($policy['ok'] ?? false)) {
        return '';
    }

    $ch = curl_init($url);
    $headers = dataset_hf_auth_headers();
    $headers[0] = 'Accept: text/markdown, text/plain;q=0.9, */*;q=0.1';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
        return '';
    }

    $text = preg_replace('/```[\s\S]*?```/u', ' ', $raw) ?? $raw;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', (string)$text) ?? (string)$text;
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    if (strlen($text) > $maxChars) {
        $text = rtrim(substr($text, 0, $maxChars)) . '...';
    }
    return $text;
}

function dataset_hf_detail_to_row(string $repoId, array $detail): array {
    $card = is_array($detail['cardData'] ?? null) ? $detail['cardData'] : [];
    $description = trim((string)($detail['description'] ?? ($card['pretty_name'] ?? '')));
    if ($description === '' && is_string($card['dataset_summary'] ?? null)) {
        $description = trim((string)$card['dataset_summary']);
    }

    $tags = array_values(array_filter(array_map('strval', (array)($detail['tags'] ?? [])), static fn($v) => trim($v) !== ''));
    $license = $card['license'] ?? '';
    if (is_array($license)) {
        $license = implode(', ', array_slice(array_map('strval', $license), 0, 6));
    } else {
        $license = (string)$license;
    }
    $languages = $card['language'] ?? ($card['languages'] ?? []);
    if (is_string($languages)) {
        $languages = [$languages];
    }
    $languagesText = implode(', ', array_slice(array_map('strval', (array)$languages), 0, 6));

    $downloads = isset($detail['downloads']) ? (int)$detail['downloads'] : null;
    $likes = isset($detail['likes']) ? (int)$detail['likes'] : null;
    $lastModified = trim((string)($detail['lastModified'] ?? ''));
    $private = !empty($detail['private']);
    $gated = !empty($detail['gated']);

    $question = 'Hugging Face dataset summary: ' . $repoId;
    $answerParts = [];
    $answerParts[] = 'Dataset: ' . $repoId;
    $answerParts[] = 'Source: https://huggingface.co/datasets/' . $repoId;
    if ($description !== '') {
        $answerParts[] = 'Description: ' . $description;
    }
    if ($downloads !== null) {
        $answerParts[] = 'Downloads: ' . number_format($downloads);
    }
    if ($likes !== null) {
        $answerParts[] = 'Likes: ' . number_format($likes);
    }
    if ($license !== '') {
        $answerParts[] = 'License: ' . $license;
    }
    if ($languagesText !== '') {
        $answerParts[] = 'Languages: ' . $languagesText;
    }
    if ($lastModified !== '') {
        $answerParts[] = 'Last updated: ' . $lastModified;
    }
    if ($private || $gated) {
        $answerParts[] = 'Access: ' . ($private ? 'private' : 'public') . ($gated ? ', gated' : '');
    }
    if (!empty($tags)) {
        $answerParts[] = 'Tags: ' . implode(', ', array_slice($tags, 0, 12));
    }

    $readmeExcerpt = dataset_hf_try_readme_excerpt($repoId, 1200);
    if ($readmeExcerpt !== '') {
        $answerParts[] = 'README excerpt: ' . $readmeExcerpt;
    }

    $answer = dataset_sanitize_training_text(implode("\n", $answerParts));
    return [$question, $answer, $tags];
}

function dataset_hf_candidate_score(array $item, string $query): float {
    $repoId = strtolower(trim((string)($item['id'] ?? '')));
    $card = is_array($item['cardData'] ?? null) ? $item['cardData'] : [];
    $description = trim((string)($item['description'] ?? ($card['pretty_name'] ?? '')));
    if ($description === '' && is_string($card['dataset_summary'] ?? null)) {
        $description = trim((string)$card['dataset_summary']);
    }
    $tags = array_values(array_filter(array_map('strval', (array)($item['tags'] ?? [])), static fn($v) => trim($v) !== ''));

    $haystack = strtolower($repoId . ' ' . $description . ' ' . implode(' ', $tags));
    $queryTokens = array_values(array_unique(array_filter(
        preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [],
        static fn($t) => strlen($t) >= 3
    )));

    $tokenHits = 0;
    foreach ($queryTokens as $token) {
        if (strpos($haystack, $token) !== false) {
            $tokenHits++;
        }
    }

    $downloads = max(0, (int)($item['downloads'] ?? 0));
    $likes = max(0, (int)($item['likes'] ?? 0));
    $private = !empty($item['private']);
    $gated = !empty($item['gated']);
    $lastModifiedRaw = trim((string)($item['lastModified'] ?? ''));

    $relevance = !empty($queryTokens) ? ($tokenHits / max(1, count($queryTokens))) : 0.0;
    $popularity = min(1.0, log10((float)$downloads + 1.0) / 6.0);
    $likesScore = min(1.0, log10((float)$likes + 1.0) / 4.0);

    $recency = 0.0;
    if ($lastModifiedRaw !== '') {
        $ts = strtotime($lastModifiedRaw);
        if ($ts !== false) {
            $days = max(0.0, (time() - $ts) / 86400.0);
            $recency = max(0.0, 1.0 - min(1.0, $days / 3650.0));
        }
    }

    $licenseRaw = $card['license'] ?? '';
    if (is_array($licenseRaw)) {
        $licenseRaw = implode(' ', array_map('strval', $licenseRaw));
    }
    $licenseText = strtolower((string)$licenseRaw);
    $openLicense = preg_match('/\b(mit|apache|bsd|cc|odc|openrail|gpl|lgpl|mpl)\b/i', $licenseText) === 1;

    $score = 0.0;
    $score += $relevance * 0.55;
    $score += $popularity * 0.2;
    $score += $likesScore * 0.1;
    $score += $recency * 0.1;
    if ($openLicense) {
        $score += 0.05;
    }
    if ($private) {
        $score -= 1.5;
    }
    if ($gated) {
        $score -= 1.0;
    }

    return round($score, 6);
}

function dataset_hf_search_rows(string $query, int $limit): array {
    $url = 'https://huggingface.co/api/datasets?search=' . rawurlencode($query) . '&limit=' . max(5, min(50, $limit * 3)) . '&full=true';
    $payload = dataset_hf_api_get_json($url, 12);
    if (!is_array($payload)) {
        return [];
    }

    $rows = [];
    foreach ($payload as $item) {
        if (!is_array($item)) {
            continue;
        }
        $repoId = trim((string)($item['id'] ?? ''));
        if ($repoId === '') {
            continue;
        }
        $card = is_array($item['cardData'] ?? null) ? $item['cardData'] : [];
        $desc = trim((string)($item['description'] ?? ($card['pretty_name'] ?? '')));
        if ($desc === '' && is_string($card['dataset_summary'] ?? null)) {
            $desc = trim((string)$card['dataset_summary']);
        }
        $tags = array_values(array_filter(array_map('strval', (array)($item['tags'] ?? [])), static fn($v) => trim($v) !== ''));
        $rows[] = [
            'repo_id' => $repoId,
            'url' => 'https://huggingface.co/datasets/' . $repoId,
            'description' => $desc,
            'likes' => isset($item['likes']) ? (int)$item['likes'] : null,
            'downloads' => isset($item['downloads']) ? (int)$item['downloads'] : null,
            'last_modified' => (string)($item['lastModified'] ?? ''),
            'gated' => !empty($item['gated']),
            'private' => !empty($item['private']),
            'tags' => array_slice($tags, 0, 12),
            'score' => dataset_hf_candidate_score($item, $query),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']);
    });

    return array_slice($rows, 0, max(1, $limit));
}

function dataset_hf_import_repo(mysqli $db, string $repoId, string $groqApiKey): array {
    $repoId = trim($repoId);
    if ($repoId === '' || preg_match('/^[a-zA-Z0-9_\-\.]+\/[a-zA-Z0-9_\-\.]+$/', $repoId) !== 1) {
        return ['success' => false, 'error' => 'Invalid repo_id'];
    }

    $existingQuestion = 'Hugging Face dataset summary: ' . $repoId;
    $deleteStmt = $db->prepare("DELETE FROM dataset WHERE question = ?");
    if ($deleteStmt) {
        $deleteStmt->bind_param('s', $existingQuestion);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    $checkStmt = $db->prepare("SELECT id FROM dataset WHERE approved = 1 AND question = ? LIMIT 1");
    $checkStmt->bind_param('s', $existingQuestion);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $parts = explode('/', $repoId, 2);
    if (count($parts) !== 2) {
        return ['success' => false, 'error' => 'Invalid repo_id'];
    }
    $repoPath = rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
    $detailUrl = 'https://huggingface.co/api/datasets/' . $repoPath;
    $detail = dataset_hf_api_get_json($detailUrl, 12);
    if (!is_array($detail)) {
        return ['success' => false, 'error' => 'Could not fetch dataset details from Hugging Face'];
    }

    [$question, $answer, $tags] = dataset_hf_detail_to_row($repoId, $detail);
    if ($question === '' || $answer === '') {
        return ['success' => false, 'error' => 'Failed to build dataset entry'];
    }

    return [
        'success' => true,
        'imported' => true,
        'dataset_id' => 0,
        'repo_id' => $repoId,
        'has_embedding' => false,
        'message' => 'Dataset metadata verified; real rows will be ingested separately.',
    ];
}

function dataset_hf_workspace_root(): string {
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

function dataset_hf_storage_dir(string $workspaceRoot, string $repoId): string {
    $safeRepo = preg_replace('/[^a-zA-Z0-9._-]+/', '__', trim($repoId)) ?? 'dataset';
    $safeRepo = trim($safeRepo, '_');
    if ($safeRepo === '') {
        $safeRepo = 'dataset';
    }
    return rtrim($workspaceRoot, '/') . '/storage/hf_datasets/' . $safeRepo;
}

function dataset_hf_list_repo_files(string $repoId): array {
    $parts = explode('/', $repoId, 2);
    if (count($parts) !== 2) {
        return [];
    }
    $repoPath = rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
    $url = 'https://huggingface.co/api/datasets/' . $repoPath . '/tree/main?recursive=1&expand=false';
    $payload = dataset_hf_api_get_json($url, 15);
    if (!is_array($payload)) {
        return [];
    }

    $out = [];
    foreach ($payload as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = trim((string)($item['path'] ?? ''));
        if ($path === '' || str_contains($path, '..')) {
            continue;
        }
        $type = strtolower(trim((string)($item['type'] ?? '')));
        if ($type !== 'file') {
            continue;
        }
        $size = (int)($item['size'] ?? 0);
        $out[] = [
            'path' => $path,
            'size' => max(0, $size),
        ];
    }
    return $out;
}

function dataset_hf_repo_has_parquet_files(string $repoId): bool {
    foreach (dataset_hf_list_repo_files($repoId) as $file) {
        $path = strtolower((string)($file['path'] ?? ''));
        if (str_ends_with($path, '.parquet')) {
            return true;
        }
    }
    return false;
}

function dataset_hf_import_jsonl_training_rows(mysqli $db, string $workspaceRoot, string $repoId, string $config, string $split, int $maxRows): array {
    $script = rtrim($workspaceRoot, '/') . '/scripts/hf_dataset_to_training_jsonl.py';
    $outputPath = dataset_hf_training_jsonl_path($workspaceRoot, $repoId);
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'output_dir_mkdir_failed'];
    }

    if (!is_file($script)) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'script_missing'];
    }

    $pythonBin = dataset_cli_python_bin($workspaceRoot);
    $cmd = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($script)
        . ' --repo-id ' . escapeshellarg($repoId)
        . ' --output ' . escapeshellarg($outputPath)
        . ' --max-rows ' . (string)max(1, min(250000, $maxRows));
    if ($config !== '') {
        $cmd .= ' --config ' . escapeshellarg($config);
    }
    if ($split !== '') {
        $cmd .= ' --split ' . escapeshellarg($split);
    }

    $env = [
        'HF_TOKEN' => dataset_hf_access_token(),
        'HUGGINGFACE_TOKEN' => dataset_hf_access_token(),
    ];
    $res = dataset_exec_command_with_env($cmd, max(120, min(7200, (int)api_get_secret('HF_LOAD_DATASET_TIMEOUT', '1200'))), $env);
    if (!($res['ok'] ?? false) || !is_file($outputPath) || filesize($outputPath) === 0) {
        return [
            'enabled' => true,
            'attempted' => true,
            'ok' => false,
            'exit_code' => (int)($res['exit_code'] ?? 1),
            'output' => (string)($res['output'] ?? 'dataset_export_failed'),
            'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
        ];
    }

    $insertStmt = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding) VALUES (?, ?, ?, ?, ?)");
    if (!$insertStmt) {
        return [
            'enabled' => true,
            'attempted' => true,
            'ok' => false,
            'output' => 'db_insert_prepare_failed',
            'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
        ];
    }

    $sourceId = 0;
    $inserted = 0;
    $skipped = 0;
    $handle = @fopen($outputPath, 'rb');
    if (!$handle) {
        $insertStmt->close();
        return [
            'enabled' => true,
            'attempted' => true,
            'ok' => false,
            'output' => 'output_open_failed',
            'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
        ];
    }

    while (($line = fgets($handle)) !== false) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            $skipped++;
            continue;
        }

        $messages = is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [];
        $userText = '';
        $assistantText = '';
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = strtolower(trim((string)($msg['role'] ?? '')));
            $content = trim((string)($msg['content'] ?? ''));
            if ($role === 'user' && $userText === '') {
                $userText = $content;
            }
            if ($role === 'assistant' && $assistantText === '') {
                $assistantText = $content;
            }
        }
        if ($userText === '' || $assistantText === '') {
            $skipped++;
            continue;
        }

        $checkStmt = $db->prepare("SELECT id FROM dataset WHERE question = ? AND answer = ? LIMIT 1");
        if (!$checkStmt) {
            $insertStmt->close();
            @fclose($handle);
            return [
                'enabled' => true,
                'attempted' => true,
                'ok' => false,
                'output' => 'db_check_prepare_failed',
                'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
            ];
        }
        $checkStmt->bind_param('ss', $userText, $assistantText);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if ($existing) {
            $skipped++;
            continue;
        }

        $keywords = extractKeywords($userText . ' ' . $assistantText);
        $embeddingJson = null;
        $insertStmt->bind_param('issss', $sourceId, $userText, $assistantText, $keywords, $embeddingJson);
        if ($insertStmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
    @fclose($handle);
    $insertStmt->close();

    return [
        'enabled' => true,
        'attempted' => true,
        'ok' => true,
        'output' => 'parquet_dataset_import_ok',
        'inserted' => $inserted,
        'skipped' => $skipped,
        'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
    ];
}

function dataset_hf_download_file(string $repoId, string $filePath, string $destPath, int $timeout = 90): array {
    $parts = explode('/', $repoId, 2);
    if (count($parts) !== 2) {
        return ['ok' => false, 'error' => 'invalid_repo'];
    }
    $repoPath = rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
    $segments = array_values(array_filter(explode('/', $filePath), static fn($s) => $s !== ''));
    $encodedFile = implode('/', array_map('rawurlencode', $segments));
    $url = 'https://huggingface.co/datasets/' . $repoPath . '/resolve/main/' . $encodedFile;

    $policy = netpolicy_validate_outbound_url($url, false);
    if (!($policy['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'blocked_url'];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'mkdir_failed'];
    }

    $tmpPath = $destPath . '.part';
    $fp = @fopen($tmpPath, 'wb');
    if (!$fp) {
        return ['ok' => false, 'error' => 'open_failed'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => max(10, $timeout),
        CURLOPT_HTTPHEADER => dataset_hf_auth_headers(),
    ]);
    $okExec = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @fclose($fp);

    if (!$okExec || $code < 200 || $code >= 300) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'http_' . $code, 'detail' => $err];
    }

    if (!@rename($tmpPath, $destPath)) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'rename_failed'];
    }

    $size = (int)(@filesize($destPath) ?: 0);
    return ['ok' => true, 'bytes' => max(0, $size), 'path' => $destPath];
}

function dataset_hf_auto_download_repo(string $workspaceRoot, string $repoId): array {
    $enabled = api_get_secret('HF_AUTO_DOWNLOAD_ENABLED', '1') === '1';
    if (!$enabled) {
        return ['enabled' => false, 'attempted' => false, 'downloaded' => [], 'skipped' => [['reason' => 'disabled']]];
    }

    $allowedExt = array_values(array_filter(array_map('trim', explode(',', strtolower((string)api_get_secret('HF_AUTO_DOWNLOAD_EXTS', 'parquet,jsonl,csv,json,txt,tsv'))))));
    if (empty($allowedExt)) {
        $allowedExt = ['parquet', 'jsonl', 'csv', 'json', 'txt', 'tsv'];
    }
    $maxFiles = max(1, min(20, (int)api_get_secret('HF_AUTO_DOWNLOAD_MAX_FILES', '3')));
    $maxBytesPerFile = max(1024 * 100, min(1024 * 1024 * 1024, (int)api_get_secret('HF_AUTO_DOWNLOAD_MAX_BYTES_PER_FILE', '52428800')));
    $maxBytesTotal = max($maxBytesPerFile, min(2 * 1024 * 1024 * 1024, (int)api_get_secret('HF_AUTO_DOWNLOAD_MAX_BYTES_TOTAL', '157286400')));

    $files = dataset_hf_list_repo_files($repoId);
    if (empty($files)) {
        return ['enabled' => true, 'attempted' => true, 'downloaded' => [], 'skipped' => [['reason' => 'no_files_found']]];
    }

    usort($files, static function (array $a, array $b): int {
        return ($a['size'] <=> $b['size']);
    });

    $baseDir = dataset_hf_storage_dir($workspaceRoot, $repoId);
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        return ['enabled' => true, 'attempted' => true, 'downloaded' => [], 'skipped' => [['reason' => 'storage_mkdir_failed']]];
    }

    $downloaded = [];
    $skipped = [];
    $totalBytes = 0;

    foreach ($files as $file) {
        $path = (string)$file['path'];
        $size = (int)$file['size'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            $skipped[] = ['path' => $path, 'reason' => 'ext_not_allowed'];
            continue;
        }
        if ($size > 0 && $size > $maxBytesPerFile) {
            $skipped[] = ['path' => $path, 'reason' => 'file_too_large', 'bytes' => $size];
            continue;
        }
        if ($totalBytes + max(0, $size) > $maxBytesTotal) {
            $skipped[] = ['path' => $path, 'reason' => 'total_budget_exceeded'];
            continue;
        }
        if (count($downloaded) >= $maxFiles) {
            $skipped[] = ['path' => $path, 'reason' => 'max_files_reached'];
            continue;
        }

        $dest = $baseDir . '/' . $path;
        $result = dataset_hf_download_file($repoId, $path, $dest, 120);
        if (!($result['ok'] ?? false)) {
            $skipped[] = ['path' => $path, 'reason' => (string)($result['error'] ?? 'download_failed')];
            continue;
        }

        $bytes = (int)($result['bytes'] ?? 0);
        $totalBytes += max(0, $bytes);
        $downloaded[] = [
            'path' => $path,
            'bytes' => $bytes,
            'local_path' => 'storage/hf_datasets/' . basename($baseDir) . '/' . $path,
        ];
    }

    $manifest = [
        'repo_id' => $repoId,
        'downloaded_at' => gmdate('c'),
        'downloaded' => $downloaded,
        'skipped' => $skipped,
        'total_bytes' => $totalBytes,
    ];
    @file_put_contents($baseDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return [
        'enabled' => true,
        'attempted' => true,
        'downloaded' => $downloaded,
        'skipped' => $skipped,
        'total_bytes' => $totalBytes,
        'storage_dir' => 'storage/hf_datasets/' . basename($baseDir),
    ];
}

function dataset_hf_fetch_splits(string $repoId): array {
    $url = 'https://datasets-server.huggingface.co/splits?dataset=' . rawurlencode($repoId);
    $payload = dataset_hf_api_get_json($url, 15);
    $splits = is_array($payload['splits'] ?? null) ? $payload['splits'] : [];
    $out = [];
    foreach ($splits as $split) {
        if (!is_array($split)) {
            continue;
        }
        $config = trim((string)($split['config'] ?? ''));
        $name = trim((string)($split['split'] ?? ''));
        if ($config === '' || $name === '') {
            continue;
        }
        $out[] = ['config' => $config, 'split' => $name];
    }
    return $out;
}

function dataset_hf_fetch_first_rows(string $repoId, string $config, string $split): array {
    $url = 'https://datasets-server.huggingface.co/first-rows?dataset=' . rawurlencode($repoId)
        . '&config=' . rawurlencode($config)
        . '&split=' . rawurlencode($split);
    $payload = dataset_hf_api_get_json($url, 20);
    return is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
}

function dataset_hf_fetch_rows_page(string $repoId, string $config, string $split, int $offset, int $length): array {
    $length = max(1, min(1000, (int)$length));
    $url = 'https://datasets-server.huggingface.co/rows?dataset=' . rawurlencode($repoId)
        . '&config=' . rawurlencode($config)
        . '&split=' . rawurlencode($split)
        . '&offset=' . (int)$offset
        . '&length=' . $length;
    $payload = dataset_hf_api_get_json($url, 20);
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    return [
        'rows' => $rows,
        'num_rows_total' => (int)($payload['num_rows_total'] ?? count($rows)),
        'partial' => !empty($payload['partial']),
    ];
}

function dataset_hf_fetch_all_rows(string $repoId, string $config, string $split, int $maxRows = 20000): array {
    $config = trim($config);
    $split = trim($split);
    if ($config === '') {
        $selection = dataset_hf_pick_config_split($repoId, '', $split);
        $config = (string)($selection['config'] ?? 'default');
        $split = (string)($selection['split'] ?? ($split !== '' ? $split : 'train'));
    }
    if ($split === '') {
        $split = 'train';
    }

    $maxRows = max(1, min(250000, (int)$maxRows));
    $rows = [];
    $offset = 0;
    $length = 100;
    $numRowsTotal = 0;
    $pageCount = 0;

    while ($offset < $maxRows) {
        $page = dataset_hf_fetch_rows_page($repoId, $config, $split, $offset, $length);
        $pageRows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
        if (empty($pageRows)) {
            break;
        }
        foreach ($pageRows as $r) {
            $rows[] = $r;
            if (count($rows) >= $maxRows) {
                break 2;
            }
        }
        $numRowsTotal = max((int)($page['num_rows_total'] ?? 0), count($rows));
        $pageCount++;
        if (!empty($page['partial']) || count($pageRows) < $length) {
            break;
        }
        $offset += $length;
    }

    return [
        'rows' => $rows,
        'num_rows_total' => $numRowsTotal,
        'config' => $config,
        'split' => $split,
        'pages' => $pageCount,
    ];
}

function dataset_hf_pick_config_split(string $repoId, string $preferredConfig = '', string $preferredSplit = ''): array {
    $splits = dataset_hf_fetch_splits($repoId);
    if (empty($splits)) {
        return ['config' => $preferredConfig, 'split' => $preferredSplit, 'splits_available' => 0];
    }

    $config = trim($preferredConfig);
    $split = trim($preferredSplit);
    if ($config !== '' && $split !== '') {
        foreach ($splits as $item) {
            if ((string)$item['config'] === $config && (string)$item['split'] === $split) {
                return ['config' => $config, 'split' => $split, 'splits_available' => count($splits)];
            }
        }
    }

    if ($config !== '' && $split === '') {
        foreach ($splits as $item) {
            if ((string)$item['config'] === $config) {
                return ['config' => (string)$item['config'], 'split' => (string)$item['split'], 'splits_available' => count($splits)];
            }
        }
    }

    if ($config === '' && $split !== '') {
        foreach ($splits as $item) {
            if ((string)$item['split'] === $split) {
                return ['config' => (string)$item['config'], 'split' => (string)$item['split'], 'splits_available' => count($splits)];
            }
        }
    }

    $first = $splits[0];
    return ['config' => (string)$first['config'], 'split' => (string)$first['split'], 'splits_available' => count($splits)];
}

function dataset_hf_extract_text_list($value): array {
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? [] : [$trimmed];
    }
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
    }
    return $out;
}

function dataset_hf_row_to_pair(array $row, string $repoId, string $split): ?array {
    $r = is_array($row['row'] ?? null) ? $row['row'] : [];
    if (empty($r)) {
        return null;
    }

    $lower = [];
    foreach ($r as $k => $v) {
        if (is_string($k)) {
            $lower[strtolower($k)] = $v;
        }
    }

    $question = trim((string)(
        $r['question']
        ?? $r['instruction']
        ?? $r['prompt']
        ?? $r['user']
        ?? $r['query']
        ?? $r['task']
        ?? $lower['question']
        ?? $lower['instruction']
        ?? $lower['prompt']
        ?? $lower['user']
        ?? $lower['query']
        ?? $lower['task']
        ?? ''
    ));
    $answer = '';

    $labelText = trim((string)($r['label_text'] ?? ($lower['label_text'] ?? '')));
    if ($labelText !== '') {
        $answer = $labelText;
    }

    $answersBlob = $r['answers'] ?? ($lower['answers'] ?? null);
    if (is_array($answersBlob)) {
        $texts = dataset_hf_extract_text_list($answersBlob['text'] ?? ($answersBlob['Text'] ?? []));
        if (!empty($texts)) {
            $answer = $texts[0];
        }
    }
    if ($answer === '') {
        $answer = trim((string)(
            $r['answer']
            ?? $r['response']
            ?? $r['output']
            ?? $r['completion']
            ?? $r['assistant']
            ?? $r['target']
            ?? $lower['answer']
            ?? $lower['response']
            ?? $lower['output']
            ?? $lower['completion']
            ?? $lower['assistant']
            ?? $lower['target']
            ?? ''
        ));
    }

    $textBlob = $r['text'] ?? ($lower['text'] ?? null);
    if (is_string($textBlob)) {
        $text = trim((string)$textBlob);
        if ($text !== '') {
            if ($question === '') {
                if ($answer !== '') {
                    $question = 'Classify this text from ' . $repoId . ': ' . $text;
                } else {
                    $question = 'Hugging Face sample from ' . $repoId . ': ' . $text;
                }
            }
            if ($answer === '') {
                $answer = $text;
            }
        }
    }

    $sentenceBlob = $r['sentence'] ?? ($lower['sentence'] ?? null);
    if ($question === '' && is_string($sentenceBlob)) {
        $sentence = trim((string)$sentenceBlob);
        if ($sentence !== '') {
            $question = 'Classify this sentence from ' . $repoId . ': ' . $sentence;
        }
    }

    if ($answer === '') {
        $label = $r['label'] ?? ($lower['label'] ?? null);
        if ($label !== null) {
            $answer = trim((string)$label);
        }
    }

    if ($question === '' || $answer === '') {
        return null;
    }

    $context = trim((string)($r['context'] ?? ''));
    if ($context !== '' && strlen($answer) < 1200) {
        $answer .= "\nContext: " . substr($context, 0, 600);
    }

    $question = dataset_sanitize_training_text(substr($question, 0, 400));
    $answer = dataset_sanitize_training_text(substr($answer, 0, 2200));
    if ($question === '' || $answer === '') {
        return null;
    }

    $qid = (int)($row['row_idx'] ?? 0);
    return [
        'question' => $question,
        'answer' => $answer,
        'source_tag' => 'hf:' . $repoId . ':' . $split . ':' . $qid,
    ];
}

function dataset_hf_auto_ingest_samples(mysqli $db, string $repoId, string $groqApiKey, string $preferredConfig = '', string $preferredSplit = '', int $requestedMaxRows = 0, bool $preferRowsApi = false): array {
    $enabled = api_get_secret('HF_AUTO_INGEST_ENABLED', '1') === '1';
    if (!$enabled) {
        return ['enabled' => false, 'attempted' => false, 'inserted' => 0, 'skipped' => 0];
    }

    $forceFullImport = (api_get_secret('HF_FORCE_FULL_IMPORT', '1') === '1' || dataset_hf_repo_has_parquet_files($repoId)) && !$preferRowsApi;
    $requestedMaxRows = max(0, min(250000, (int)$requestedMaxRows));
    if ($forceFullImport) {
        $workspaceRoot = dataset_hf_workspace_root();
        $selection = dataset_hf_pick_config_split($repoId, $preferredConfig, $preferredSplit);
        $config = (string)($selection['config'] ?? $preferredConfig);
        $split = (string)($selection['split'] ?? $preferredSplit);
        $maxRows = $requestedMaxRows > 0
            ? max(100, min(250000, $requestedMaxRows))
            : max(500, min(250000, (int)api_get_secret('HF_AUTO_INGEST_MAX_ROWS', '10000')));
        return dataset_hf_import_jsonl_training_rows($db, $workspaceRoot, $repoId, $config, $split, $maxRows);
    }

    $maxRows = $requestedMaxRows > 0
        ? max(1, min(250000, $requestedMaxRows))
        : max(1, min(3000, (int)api_get_secret('HF_AUTO_INGEST_MAX_ROWS', '300')));
    $maxSplits = max(1, min(5, (int)api_get_secret('HF_AUTO_INGEST_MAX_SPLITS', '2')));
    $allSplits = dataset_hf_fetch_splits($repoId);
    $splits = [];
    if ($preferredConfig !== '' || $preferredSplit !== '') {
        foreach ($allSplits as $item) {
            $cfg = (string)($item['config'] ?? '');
            $spl = (string)($item['split'] ?? '');
            if (($preferredConfig === '' || $cfg === $preferredConfig) && ($preferredSplit === '' || $spl === $preferredSplit)) {
                $splits[] = ['config' => $cfg, 'split' => $spl];
            }
        }
    }
    if (empty($splits)) {
        $splits = $allSplits;
    }
    $splits = array_slice($splits, 0, $maxSplits);
    if (empty($splits)) {
        return ['enabled' => true, 'attempted' => true, 'inserted' => 0, 'skipped' => 0, 'reason' => 'no_splits'];
    }

    $checkStmt = $db->prepare("SELECT id FROM dataset WHERE question = ? AND answer = ? LIMIT 1");
    $insertStmt = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding) VALUES (?, ?, ?, ?, ?)");
    if (!$checkStmt || !$insertStmt) {
        if ($checkStmt) {
            $checkStmt->close();
        }
        if ($insertStmt) {
            $insertStmt->close();
        }
        return ['enabled' => true, 'attempted' => true, 'inserted' => 0, 'skipped' => 0, 'reason' => 'db_prepare_failed'];
    }

    $inserted = 0;
    $skipped = 0;
    $sourceId = 0;

    foreach ($splits as $splitInfo) {
        if ($inserted >= $maxRows) {
            break;
        }
        $config = (string)$splitInfo['config'];
        $split = (string)$splitInfo['split'];
        $rows = dataset_hf_fetch_all_rows($repoId, $config, $split, $maxRows - $inserted);
        foreach (($rows['rows'] ?? []) as $row) {
            if ($inserted >= $maxRows) {
                break;
            }
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $pair = dataset_hf_row_to_pair($row, $repoId, $split);
            if ($pair === null) {
                $skipped++;
                continue;
            }

            $question = (string)$pair['question'];
            $answer = (string)$pair['answer'];
            $checkStmt->bind_param('ss', $question, $answer);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            if ($existing) {
                $skipped++;
                continue;
            }

            $keywords = extractKeywords($question . ' ' . $answer);
            $embeddingJson = null;
            $insertStmt->bind_param('issss', $sourceId, $question, $answer, $keywords, $embeddingJson);
            if ($insertStmt->execute()) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
    }

    $checkStmt->close();
    $insertStmt->close();

    return [
        'enabled' => true,
        'attempted' => true,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'max_rows' => $maxRows,
        'source' => 'rows_api',
    ];
}

function dataset_exec_command(string $command, int $timeoutSec = 300): array {
    $wrapped = 'timeout ' . max(10, min(1800, $timeoutSec)) . 's bash --noprofile --norc -lc ' . escapeshellarg($command) . ' 2>&1';
    $output = [];
    $code = 0;
    @exec($wrapped, $output, $code);
    $joined = trim(implode("\n", $output));
    if (strlen($joined) > 8000) {
        $joined = substr($joined, -8000);
    }
    return ['ok' => $code === 0, 'exit_code' => $code, 'output' => $joined];
}

function dataset_exec_command_with_env(string $command, int $timeoutSec, array $env): array {
    $prefix = [];
    foreach ($env as $key => $value) {
        $name = (string)$key;
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }
        $prefix[] = $name . '=' . escapeshellarg((string)$value);
    }
    $composed = empty($prefix) ? $command : (implode(' ', $prefix) . ' ' . $command);
    return dataset_exec_command($composed, $timeoutSec);
}

function dataset_cli_python_bin(string $workspaceRoot): string {
    $envCandidate = trim((string)api_get_secret('HF_DATASET_PYTHON_BIN', api_get_secret('CONTINUOUS_FINETUNE_PYTHON_BIN', '')));
    if ($envCandidate !== '' && is_executable($envCandidate)) {
        return $envCandidate;
    }

    $candidates = [
        rtrim($workspaceRoot, '/') . '/.venv-finetune/bin/python',
        rtrim($workspaceRoot, '/') . '/.venv/bin/python',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
    ];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'python3';
}

function dataset_hf_training_jsonl_path(string $workspaceRoot, string $repoId): string {
    $safeRepo = str_replace('/', '__', trim($repoId));
    return rtrim($workspaceRoot, '/') . '/storage/model_training/hf_imports/' . $safeRepo . '.jsonl';
}

function dataset_hf_rows_to_training_jsonl(string $workspaceRoot, string $repoId, string $config, string $split, int $maxRows): array {
    $outputPath = dataset_hf_training_jsonl_path($workspaceRoot, $repoId);
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'output_dir_mkdir_failed'];
    }

    $selection = dataset_hf_pick_config_split($repoId, $config, $split);
    $effectiveConfig = (string)($selection['config'] ?? ($config !== '' ? $config : 'default'));
    $effectiveSplit = (string)($selection['split'] ?? ($split !== '' ? $split : 'train'));
    $rowsRes = dataset_hf_fetch_all_rows($repoId, $effectiveConfig, $effectiveSplit, $maxRows);
    $rows = is_array($rowsRes['rows'] ?? null) ? $rowsRes['rows'] : [];
    $trainingRows = [];
    foreach ($rows as $rowIdx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $pair = dataset_hf_row_to_pair($row, $repoId, $effectiveSplit);
        if ($pair === null) {
            continue;
        }
        $trainingRows[] = [
            'messages' => [
                ['role' => 'user', 'content' => (string)$pair['question']],
                ['role' => 'assistant', 'content' => (string)$pair['answer']],
            ],
            'metadata' => [
                'training_source' => 'hf_rows_api',
                'repo_id' => $repoId,
                'config' => $effectiveConfig,
                'split' => $effectiveSplit,
                'row_idx' => $rowIdx,
            ],
        ];
    }

    if (empty($trainingRows)) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'no_rows_for_training', 'repo_id' => $repoId, 'config' => $effectiveConfig, 'split' => $effectiveSplit, 'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath)];
    }

    $handle = @fopen($outputPath, 'wb');
    if (!$handle) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'open_output_failed', 'repo_id' => $repoId, 'config' => $effectiveConfig, 'split' => $effectiveSplit, 'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath)];
    }

    foreach ($trainingRows as $trainingRow) {
        $line = json_encode($trainingRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line) || @fwrite($handle, $line . "\n") === false) {
            @fclose($handle);
            return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'write_output_failed', 'repo_id' => $repoId, 'config' => $effectiveConfig, 'split' => $effectiveSplit, 'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath)];
        }
    }
    @fclose($handle);

    return [
        'enabled' => true,
        'attempted' => true,
        'ok' => true,
        'output' => 'rows_api_export_ok',
        'rows_written' => count($trainingRows),
        'rows_seen' => count($rows),
        'repo_id' => $repoId,
        'config' => $effectiveConfig,
        'split' => $effectiveSplit,
        'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
    ];
}

function dataset_hf_prepare_training_corpus(string $workspaceRoot, string $repoId, string $config = '', string $split = ''): array {
    $enabled = api_get_secret('HF_LOAD_DATASET_ENABLED', '1') === '1';
    if (!$enabled) {
        return ['enabled' => false, 'attempted' => false, 'ok' => false, 'output' => 'disabled'];
    }

    $outputPath = dataset_hf_training_jsonl_path($workspaceRoot, $repoId);
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        return ['enabled' => true, 'attempted' => true, 'ok' => false, 'output' => 'output_dir_mkdir_failed'];
    }

    $maxRows = max(200, min(250000, (int)api_get_secret('HF_LOAD_DATASET_MAX_ROWS', '250000')));
    $selection = dataset_hf_pick_config_split($repoId, $config, $split);
    $effectiveConfig = (string)($selection['config'] ?? ($config !== '' ? $config : 'default'));
    $effectiveSplit = (string)($selection['split'] ?? ($split !== '' ? $split : 'train'));

    $forceFullImport = api_get_secret('HF_FORCE_FULL_IMPORT', '1') === '1' || dataset_hf_repo_has_parquet_files($repoId);
    if ($forceFullImport) {
        $script = rtrim($workspaceRoot, '/') . '/scripts/hf_dataset_to_training_jsonl.py';
        if (!is_file($script)) {
            return array_merge(['enabled' => true, 'attempted' => false, 'ok' => false, 'output' => 'script_missing'], ['repo_id' => $repoId, 'config' => $effectiveConfig, 'split' => $effectiveSplit, 'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath)]);
        }

        $timeoutSec = max(60, min(7200, (int)api_get_secret('HF_LOAD_DATASET_TIMEOUT', '1200')));
        $pythonBin = dataset_cli_python_bin($workspaceRoot);
        $cmd = escapeshellarg($pythonBin)
            . ' ' . escapeshellarg($script)
            . ' --repo-id ' . escapeshellarg($repoId)
            . ' --output ' . escapeshellarg($outputPath)
            . ' --max-rows ' . (string)$maxRows;
        if ($effectiveConfig !== '') {
            $cmd .= ' --config ' . escapeshellarg($effectiveConfig);
        }
        if ($effectiveSplit !== '') {
            $cmd .= ' --split ' . escapeshellarg($effectiveSplit);
        }

        $env = [
            'HF_TOKEN' => dataset_hf_access_token(),
            'HUGGINGFACE_TOKEN' => dataset_hf_access_token(),
        ];
        $res = dataset_exec_command_with_env($cmd, $timeoutSec, $env);

        $summary = [
            'enabled' => true,
            'attempted' => true,
            'ok' => (bool)($res['ok'] ?? false),
            'exit_code' => (int)($res['exit_code'] ?? 1),
            'output' => (string)($res['output'] ?? ''),
            'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
            'repo_id' => $repoId,
            'config' => $effectiveConfig,
            'split' => $effectiveSplit,
        ];

        $rawOutput = (string)($res['output'] ?? '');
        $decoded = json_decode($rawOutput, true);
        if (!is_array($decoded)) {
            $lines = preg_split('/\r?\n/', $rawOutput) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $candidate = trim((string)$lines[$i]);
                if ($candidate === '' || ($candidate[0] ?? '') !== '{') {
                    continue;
                }
                $try = json_decode($candidate, true);
                if (is_array($try)) {
                    $decoded = $try;
                    break;
                }
            }
        }
        if (is_array($decoded)) {
            if (isset($decoded['rows_written'])) {
                $summary['rows_written'] = (int)$decoded['rows_written'];
            }
            if (isset($decoded['rows_seen'])) {
                $summary['rows_seen'] = (int)$decoded['rows_seen'];
            }
            if (isset($decoded['rows_skipped'])) {
                $summary['rows_skipped'] = (int)$decoded['rows_skipped'];
            }
            if (isset($decoded['splits'])) {
                $summary['splits'] = $decoded['splits'];
            }
        }

        if (($summary['ok'] ?? false) && (!is_file($outputPath) || filesize($outputPath) === 0)) {
            $summary['ok'] = false;
            $summary['output'] = 'empty_output_file';
        }

        return $summary;
    }

    $rowsExport = dataset_hf_rows_to_training_jsonl($workspaceRoot, $repoId, $effectiveConfig, $effectiveSplit, $maxRows);
    if (($rowsExport['ok'] ?? false) && is_file($outputPath) && filesize($outputPath) > 0) {
        return $rowsExport;
    }

    $script = rtrim($workspaceRoot, '/') . '/scripts/hf_dataset_to_training_jsonl.py';
    if (!is_file($script)) {
        return array_merge(['enabled' => true, 'attempted' => false, 'ok' => false, 'output' => 'script_missing'], ['repo_id' => $repoId, 'config' => $effectiveConfig, 'split' => $effectiveSplit, 'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath)]);
    }

    $timeoutSec = max(60, min(7200, (int)api_get_secret('HF_LOAD_DATASET_TIMEOUT', '1200')));
    $pythonBin = dataset_cli_python_bin($workspaceRoot);

    $cmd = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($script)
        . ' --repo-id ' . escapeshellarg($repoId)
        . ' --output ' . escapeshellarg($outputPath)
        . ' --max-rows ' . (string)$maxRows;
    if ($effectiveConfig !== '') {
        $cmd .= ' --config ' . escapeshellarg($effectiveConfig);
    }
    if ($effectiveSplit !== '') {
        $cmd .= ' --split ' . escapeshellarg($effectiveSplit);
    }

    $env = [
        'HF_TOKEN' => dataset_hf_access_token(),
        'HUGGINGFACE_TOKEN' => dataset_hf_access_token(),
    ];
    $res = dataset_exec_command_with_env($cmd, $timeoutSec, $env);

    $summary = [
        'enabled' => true,
        'attempted' => true,
        'ok' => (bool)($res['ok'] ?? false),
        'exit_code' => (int)($res['exit_code'] ?? 1),
        'output' => (string)($res['output'] ?? ''),
        'output_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $outputPath),
        'repo_id' => $repoId,
        'config' => $effectiveConfig,
        'split' => $effectiveSplit,
    ];

    $rawOutput = (string)($res['output'] ?? '');
    $decoded = json_decode($rawOutput, true);
    if (!is_array($decoded)) {
        $lines = preg_split('/\r?\n/', $rawOutput) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $candidate = trim((string)$lines[$i]);
            if ($candidate === '' || ($candidate[0] ?? '') !== '{') {
                continue;
            }
            $try = json_decode($candidate, true);
            if (is_array($try)) {
                $decoded = $try;
                break;
            }
        }
    }
    if (is_array($decoded)) {
        if (isset($decoded['rows_written'])) {
            $summary['rows_written'] = (int)$decoded['rows_written'];
        }
        if (isset($decoded['rows_seen'])) {
            $summary['rows_seen'] = (int)$decoded['rows_seen'];
        }
        if (isset($decoded['rows_skipped'])) {
            $summary['rows_skipped'] = (int)$decoded['rows_skipped'];
        }
        if (isset($decoded['splits'])) {
            $summary['splits'] = $decoded['splits'];
        }
    }

    if (($summary['ok'] ?? false) && (!is_file($outputPath) || filesize($outputPath) === 0)) {
        $summary['ok'] = false;
        $summary['output'] = 'empty_output_file';
    }

    return $summary;
}

function dataset_cli_php_bin(): string {
    $envCandidate = trim((string)api_get_secret('HF_TRAIN_PHP_BIN', api_get_secret('CONTINUOUS_LEARNING_PHP_BIN', '')));
    if ($envCandidate !== '' && is_executable($envCandidate)) {
        return $envCandidate;
    }

    $current = PHP_BINARY ?: '';
    if ($current !== '' && is_executable($current) && stripos(basename($current), 'php-fpm') === false) {
        return $current;
    }

    $candidates = [
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/opt/plesk/php/8.3/bin/php',
        '/opt/plesk/php/8.2/bin/php',
        '/opt/plesk/php/8.1/bin/php',
    ];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function dataset_run_continuous_learning(string $workspaceRoot, int $timeoutSec = 300, array $extraEnv = []): array {
    $script = rtrim($workspaceRoot, '/') . '/cron/continuous_model_learning.php';
    if (!is_file($script)) {
        return ['ok' => false, 'exit_code' => 127, 'output' => 'continuous_model_learning.php not found'];
    }

    $phpBin = dataset_cli_php_bin();
    $command = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    if (!empty($extraEnv)) {
        return dataset_exec_command_with_env($command, $timeoutSec, $extraEnv);
    }
    return dataset_exec_command($command, $timeoutSec);
}

function dataset_run_continuous_learning_async(string $workspaceRoot, array $extraEnv = []): array {
    $script = rtrim($workspaceRoot, '/') . '/cron/continuous_model_learning.php';
    if (!is_file($script)) {
        return ['ok' => false, 'output' => 'continuous_model_learning.php not found'];
    }

    $logDir = rtrim($workspaceRoot, '/') . '/storage/logs';
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return ['ok' => false, 'output' => 'log_dir_mkdir_failed'];
    }

    $phpBin = dataset_cli_php_bin();
    $logPath = $logDir . '/hf_delegate_train_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.log';
    $envPrefix = [];
    foreach ($extraEnv as $key => $value) {
        $name = (string)$key;
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }
        $envPrefix[] = $name . '=' . escapeshellarg((string)$value);
    }
    $command = trim(implode(' ', $envPrefix) . ' ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script));
    $spawn = 'nohup bash --noprofile --norc -lc ' . escapeshellarg($command) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';

    $out = [];
    $code = 1;
    @exec($spawn, $out, $code);
    $pid = 0;
    if (!empty($out)) {
        $pid = (int)trim((string)end($out));
    }

    if ($code !== 0 || $pid <= 0) {
        return ['ok' => false, 'output' => 'spawn_failed', 'log_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $logPath)];
    }

    return [
        'ok' => true,
        'queued' => true,
        'pid' => $pid,
        'log_path' => str_replace(rtrim($workspaceRoot, '/') . '/', '', $logPath),
    ];
}

$db     = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$action = api_action();

api_enforce_post_and_origin_for_actions([
    'approve',
    'bulk_approve',
    'remove',
    'generate_embeddings',
    'edit',
    'hf_import',
    'hf_delegate_train',
]);

// ════════════════════════════════
// STATS — public endpoint (used by chat.php dev panel)
// ════════════════════════════════
if ($action === 'stats') {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1");
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM conversations WHERE in_dataset = 0");
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1 AND embedding IS NOT NULL");
    $stmt->execute();
    $withEmb = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['success' => true, 'total' => (int)$total, 'pending' => (int)$pending, 'with_embeddings' => (int)$withEmb]);
    exit;
}

// ════════════════════════════════
// All actions below require dev login
// ════════════════════════════════
if (!$isDevUser) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ════════════════════════════════
// LIST PENDING (conversations not yet in dataset)
// ════════════════════════════════
if ($action === 'list_pending') {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("
        SELECT id, user_id, ip_address, user_message, ai_reply, created_at
        FROM conversations
        WHERE in_dataset = 0
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows  = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    $countStmt = $db->prepare("SELECT COUNT(*) c FROM conversations WHERE in_dataset = 0");
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['c'];
    $countStmt->close();
    echo json_encode(['success' => true, 'rows' => $rows, 'total' => (int)$total, 'page' => $page]);
    exit;
}

// ════════════════════════════════
// LIST DATASET ENTRIES
// ════════════════════════════════
if ($action === 'list_dataset') {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $db->prepare("SELECT id, question, answer, keywords, embedding IS NOT NULL AS has_embedding, created_at FROM dataset WHERE approved = 1 AND (question LIKE ? OR answer LIKE ?) ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('ssii', $like, $like, $limit, $offset);
        $countStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1 AND (question LIKE ? OR answer LIKE ?)");
        $countStmt->bind_param('ss', $like, $like);
    } else {
        $stmt = $db->prepare("SELECT id, question, answer, keywords, embedding IS NOT NULL AS has_embedding, created_at FROM dataset WHERE approved = 1 ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('ii', $limit, $offset);
        $countStmt = $db->prepare("SELECT COUNT(*) c FROM dataset WHERE approved = 1");
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['c'];
    $countStmt->close();
    echo json_encode(['success' => true, 'rows' => $rows, 'total' => (int)$total, 'page' => $page]);
    exit;
}

// ════════════════════════════════
// HUGGING FACE DATASET SEARCH
// Returns dataset metadata candidates from huggingface.co
// ════════════════════════════════
if ($action === 'hf_search') {
    dataset_hf_require_token();
    $query = trim((string)($_GET['query'] ?? $_POST['query'] ?? ''));
    $limit = max(1, min(30, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10)));
    if ($query === '') {
        echo json_encode(['success' => false, 'error' => 'No query']);
        exit;
    }

    $rows = dataset_hf_search_rows($query, $limit);
    if (empty($rows)) {
        echo json_encode(['success' => false, 'error' => 'Could not fetch Hugging Face datasets']);
        exit;
    }

    echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
    exit;
}

// ════════════════════════════════
// HUGGING FACE IMPORT
// Stores dataset metadata summary in local dataset table
// ════════════════════════════════
if ($action === 'hf_import') {
    dataset_hf_require_token();
    $repoId = trim((string)($_POST['repo_id'] ?? ''));
    $config = trim((string)($_POST['config'] ?? $_POST['subset'] ?? ''));
    $split = trim((string)($_POST['split'] ?? ''));
    $ingestMaxRows = max(100, min(250000, (int)($_POST['ingest_max_rows'] ?? $_GET['ingest_max_rows'] ?? api_get_secret('HF_WEB_INGEST_MAX_ROWS', '200'))));
    $downloadFiles = (string)($_POST['download_files'] ?? $_GET['download_files'] ?? api_get_secret('HF_WEB_DOWNLOAD_FILES_DEFAULT', '0')) === '1';
    $preferRowsApi = (string)($_POST['prefer_rows_api'] ?? $_GET['prefer_rows_api'] ?? api_get_secret('HF_WEB_PREFER_ROWS_API', '1')) === '1';
    $prepareCorpus = (string)($_POST['prepare_corpus'] ?? $_GET['prepare_corpus'] ?? '0') === '1';
    $res = dataset_hf_import_repo($db, $repoId, $groqApiKey);
    if (($res['success'] ?? false) && isset($res['repo_id'])) {
        $workspaceRoot = dataset_hf_workspace_root();
        $selection = dataset_hf_pick_config_split((string)$res['repo_id'], $config, $split);
        $loaderSplit = $split !== '' ? (string)($selection['split'] ?? '') : '';
        $res['dataset_selection'] = $selection;
        $res['download'] = $downloadFiles
            ? dataset_hf_auto_download_repo($workspaceRoot, (string)$res['repo_id'])
            : ['enabled' => true, 'attempted' => false, 'downloaded' => [], 'skipped' => [['reason' => 'skipped_by_request']]];
        $res['ingest'] = dataset_hf_auto_ingest_samples($db, (string)$res['repo_id'], $groqApiKey, (string)($selection['config'] ?? ''), (string)($selection['split'] ?? ''), $ingestMaxRows, $preferRowsApi);
        if ($prepareCorpus) {
            $res['load_dataset'] = dataset_hf_prepare_training_corpus($workspaceRoot, (string)$res['repo_id'], (string)($selection['config'] ?? ''), $loaderSplit);
        } else {
            $res['load_dataset'] = ['enabled' => true, 'attempted' => false, 'ok' => null, 'output' => 'skipped'];
        }
    }
    $res['auth'] = [
        'token_configured' => dataset_hf_access_token() !== '',
    ];
    echo json_encode($res);
    exit;
}

if ($action === 'hf_delegate_train') {
    dataset_hf_require_token();
    $query = trim((string)($_POST['query'] ?? $_GET['query'] ?? ''));
    $repoOverride = trim((string)($_POST['repo_id'] ?? $_GET['repo_id'] ?? ''));
    $limit = max(3, min(25, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
    $config = trim((string)($_POST['config'] ?? $_POST['subset'] ?? $_GET['config'] ?? $_GET['subset'] ?? ''));
    $split = trim((string)($_POST['split'] ?? $_GET['split'] ?? ''));
    $ingestMaxRows = max(100, min(250000, (int)($_POST['ingest_max_rows'] ?? $_GET['ingest_max_rows'] ?? api_get_secret('HF_WEB_INGEST_MAX_ROWS', '200'))));
    $downloadFiles = (string)($_POST['download_files'] ?? $_GET['download_files'] ?? api_get_secret('HF_WEB_DOWNLOAD_FILES_DEFAULT', '0')) === '1';
    $preferRowsApi = (string)($_POST['prefer_rows_api'] ?? $_GET['prefer_rows_api'] ?? api_get_secret('HF_WEB_PREFER_ROWS_API', '1')) === '1';
    $trainNow = (string)($_POST['train_now'] ?? $_GET['train_now'] ?? api_get_secret('HF_DELEGATE_TRAIN_DEFAULT', '0')) === '1';
    $waitForTraining = (string)($_POST['wait_for_training'] ?? $_GET['wait_for_training'] ?? '0') === '1';
    $prepareCorpus = $trainNow || ((string)($_POST['prepare_corpus'] ?? $_GET['prepare_corpus'] ?? '0') === '1');

    if ($repoOverride === '' && ($query === '' || strlen($query) < 2)) {
        echo json_encode(['success' => false, 'error' => 'Query is required']);
        exit;
    }

    $rows = [];
    $best = null;
    if ($repoOverride !== '') {
        $best = [
            'repo_id' => $repoOverride,
            'url' => 'https://huggingface.co/datasets/' . $repoOverride,
            'score' => 1.0,
            'private' => false,
            'gated' => false,
        ];
        $rows = [$best];
    } else {
        $rows = dataset_hf_search_rows($query, $limit);
        if (empty($rows)) {
            echo json_encode(['success' => false, 'error' => 'No Hugging Face datasets found']);
            exit;
        }

        foreach ($rows as $row) {
            if (empty($row['private']) && empty($row['gated'])) {
                $best = $row;
                break;
            }
        }
        if ($best === null) {
            $best = $rows[0];
        }
    }

    $importRes = dataset_hf_import_repo($db, (string)($best['repo_id'] ?? ''), $groqApiKey);
    if (!($importRes['success'] ?? false)) {
        echo json_encode($importRes);
        exit;
    }

    $workspaceRoot = dataset_hf_workspace_root();
    $selectedRepo = (string)($importRes['repo_id'] ?? $best['repo_id'] ?? '');
    $selection = dataset_hf_pick_config_split($selectedRepo, $config, $split);
    $loaderSplit = $split !== '' ? (string)($selection['split'] ?? '') : '';
    $downloadRes = $downloadFiles
        ? dataset_hf_auto_download_repo($workspaceRoot, $selectedRepo)
        : ['enabled' => true, 'attempted' => false, 'downloaded' => [], 'skipped' => [['reason' => 'skipped_by_request']]];
    $ingestRes = dataset_hf_auto_ingest_samples($db, $selectedRepo, $groqApiKey, (string)($selection['config'] ?? ''), (string)($selection['split'] ?? ''), $ingestMaxRows, $preferRowsApi);
    if ($prepareCorpus) {
        $loadDatasetRes = dataset_hf_prepare_training_corpus($workspaceRoot, $selectedRepo, (string)($selection['config'] ?? ''), $loaderSplit);
    } else {
        $loadDatasetRes = ['enabled' => true, 'attempted' => false, 'ok' => null, 'output' => 'skipped'];
    }

    $training = ['attempted' => false, 'ok' => null, 'exit_code' => null, 'output' => 'not_requested'];
    if ($trainNow) {
        $trainEnv = [];
        $ingestOutputAbs = '';
        if (!empty($ingestRes['output_path'])) {
            $ingestOutputAbs = rtrim($workspaceRoot, '/') . '/' . ltrim((string)$ingestRes['output_path'], '/');
        }
        if ($ingestOutputAbs !== '' && is_file($ingestOutputAbs) && filesize($ingestOutputAbs) > 0) {
            $trainEnv['LYRALINK_HF_EXTRA_JSONL'] = $ingestOutputAbs;
        } elseif (($loadDatasetRes['ok'] ?? false) && !empty($loadDatasetRes['output_path'])) {
            $trainEnv['LYRALINK_HF_EXTRA_JSONL'] = rtrim($workspaceRoot, '/') . '/' . ltrim((string)$loadDatasetRes['output_path'], '/');
        }
        if ($waitForTraining) {
            $timeoutSec = max(60, min(1800, (int)api_get_secret('HF_DELEGATE_TRAIN_TIMEOUT', '300')));
            $trainRes = dataset_run_continuous_learning($workspaceRoot, $timeoutSec, $trainEnv);
            $training = [
                'attempted' => true,
                'queued' => false,
                'ok' => (bool)($trainRes['ok'] ?? false),
                'exit_code' => (int)($trainRes['exit_code'] ?? 1),
                'output' => (string)($trainRes['output'] ?? ''),
                'hf_extra_jsonl' => (string)($trainEnv['LYRALINK_HF_EXTRA_JSONL'] ?? ''),
            ];
        } else {
            $trainRes = dataset_run_continuous_learning_async($workspaceRoot, $trainEnv);
            $training = [
                'attempted' => true,
                'queued' => true,
                'ok' => (bool)($trainRes['ok'] ?? false),
                'output' => (string)($trainRes['output'] ?? 'queued'),
                'pid' => (int)($trainRes['pid'] ?? 0),
                'log_path' => (string)($trainRes['log_path'] ?? ''),
                'hf_extra_jsonl' => (string)($trainEnv['LYRALINK_HF_EXTRA_JSONL'] ?? ''),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'query' => $query,
        'selected' => $best,
        'dataset_selection' => $selection,
        'import' => $importRes,
        'download' => $downloadRes,
        'ingest' => $ingestRes,
        'load_dataset' => $loadDatasetRes,
        'auth' => ['token_configured' => dataset_hf_access_token() !== ''],
        'training' => $training,
        'candidates' => array_slice($rows, 0, 5),
    ]);
    exit;
}

// ════════════════════════════════
// APPROVE SINGLE CONVERSATION
// ════════════════════════════════
if ($action === 'approve') {
    $convId = (int)($_POST['conv_id'] ?? 0);
    if (!$convId) { echo json_encode(['success' => false, 'error' => 'No conv_id']); exit; }

    $stmt = $db->prepare("SELECT * FROM conversations WHERE id = ?");
    $stmt->bind_param('i', $convId);
    $stmt->execute();
    $conv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$conv) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

    // Custom question/answer override (for editing before approving)
    $question = trim($_POST['question'] ?? $conv['user_message']);
    $answer   = trim($_POST['answer']   ?? $conv['ai_reply']);
    $keywords = extractKeywords($question . ' ' . $answer);

    // Generate embedding
    $embedding    = getEmbedding($question . ' ' . $answer, $groqApiKey);
    $embeddingJson = $embedding ? json_encode($embedding) : null;

    $stmt = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $convId, $question, $answer, $keywords, $embeddingJson);
    $stmt->execute();
    $datasetId = $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("UPDATE conversations SET in_dataset = 1, dataset_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $datasetId, $convId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'dataset_id' => $datasetId, 'has_embedding' => $embedding !== null]);
    exit;
}

// ════════════════════════════════
// BULK APPROVE (approve all pending)
// ════════════════════════════════
if ($action === 'bulk_approve') {
    $limit  = min(100, (int)($_POST['limit'] ?? 50));
    $stmt = $db->prepare("SELECT * FROM conversations WHERE in_dataset = 0 ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $added  = 0; $failed = 0;

    while ($conv = $result->fetch_assoc()) {
        $question  = $conv['user_message'];
        $answer    = $conv['ai_reply'];
        $keywords  = extractKeywords($question . ' ' . $answer);
        $embedding = getEmbedding($question . ' ' . $answer, $groqApiKey);
        $embJson   = $embedding ? json_encode($embedding) : null;

        $stmt = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $conv['id'], $question, $answer, $keywords, $embJson);
        if ($stmt->execute()) {
            $datasetId = $db->insert_id;
            $updateStmt = $db->prepare("UPDATE conversations SET in_dataset = 1, dataset_id = ? WHERE id = ?");
            $convRowId = (int)$conv['id'];
            $updateStmt->bind_param('ii', $datasetId, $convRowId);
            $updateStmt->execute();
            $updateStmt->close();
            $added++;
        } else {
            $failed++;
        }
        $stmt->close();
        usleep(100000); // 100ms between API calls to avoid rate limiting
    }

    echo json_encode(['success' => true, 'added' => $added, 'failed' => $failed]);
    exit;
}

// ════════════════════════════════
// REMOVE FROM DATASET
// ════════════════════════════════
if ($action === 'remove') {
    $datasetId = (int)($_POST['dataset_id'] ?? 0);
    if (!$datasetId) { echo json_encode(['success' => false, 'error' => 'No dataset_id']); exit; }

    $stmt = $db->prepare("UPDATE conversations SET in_dataset = 0, dataset_id = NULL WHERE dataset_id = ?");
    $stmt->bind_param('i', $datasetId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("DELETE FROM dataset WHERE id = ?");
    $stmt->bind_param('i', $datasetId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// SEARCH DATASET (manual test)
// ════════════════════════════════
if ($action === 'search') {
    $query   = trim($_POST['query'] ?? $_GET['query'] ?? '');
    if (!$query) { echo json_encode(['success' => false, 'error' => 'No query']); exit; }

    $results = datasetSearch($db, $query, $groqApiKey, 5);
    echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
    exit;
}

// ════════════════════════════════
// GENERATE MISSING EMBEDDINGS
// ════════════════════════════════
if ($action === 'generate_embeddings') {
    $stmt = $db->prepare("SELECT id, question, answer FROM dataset WHERE approved = 1 AND embedding IS NULL LIMIT 20");
    $stmt->execute();
    $result = $stmt->get_result();
    $updated = 0;

    while ($row = $result->fetch_assoc()) {
        $embedding = getEmbedding($row['question'] . ' ' . $row['answer'], $groqApiKey);
        if ($embedding) {
            $embJson = json_encode($embedding);
            $updateStmt = $db->prepare("UPDATE dataset SET embedding = ? WHERE id = ?");
            $rowId = (int)$row['id'];
            $updateStmt->bind_param('si', $embJson, $rowId);
            $updateStmt->execute();
            $updateStmt->close();
            $updated++;
        }
        usleep(100000);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'updated' => $updated]);
    exit;
}

// ════════════════════════════════
// EDIT DATASET ENTRY
// ════════════════════════════════
if ($action === 'edit') {
    $datasetId = (int)($_POST['dataset_id'] ?? 0);
    $question  = trim($_POST['question'] ?? '');
    $answer    = trim($_POST['answer']   ?? '');
    if (!$datasetId || !$question || !$answer) { echo json_encode(['success' => false, 'error' => 'Missing fields']); exit; }

    $keywords  = extractKeywords($question . ' ' . $answer);
    $embedding = getEmbedding($question . ' ' . $answer, $groqApiKey);
    $embJson   = $embedding ? json_encode($embedding) : null;

    $stmt = $db->prepare("UPDATE dataset SET question = ?, answer = ?, keywords = ?, embedding = ? WHERE id = ?");
    $embStr = $embedding ? json_encode($embedding) : null;
    $stmt->bind_param('ssssi', $question, $answer, $keywords, $embStr, $datasetId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>