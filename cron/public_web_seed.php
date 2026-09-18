<?php
require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/lib/network_policy.php';
require __DIR__ . '/../api/dataset_search.php';
require __DIR__ . '/../api/lib/chat/conversation_intelligence.php';

$dryRun = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$maxPages = 0;
foreach ($argv as $index => $arg) {
    if ($arg === '--max-pages' && isset($argv[$index + 1])) {
        $maxPages = (int)$argv[$index + 1];
    }
}

if ($maxPages <= 0) {
    $maxPages = (int)api_get_secret('PUBLIC_WEB_SEED_MAX_PAGES', '12');
}
if ($maxPages < 1) {
    $maxPages = 1;
}

if ((api_get_secret('PUBLIC_WEB_SEED_ENABLED', '1') ?? '1') !== '1') {
    echo "[public_web_seed] disabled\n";
    exit(0);
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    echo "[public_web_seed] DB connection error: {$db->connect_error}\n";
    exit(1);
}

$storageDir = __DIR__ . '/../storage/public_web_seed';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
$maxBytes = (int)api_get_secret('PUBLIC_WEB_SEED_MAX_BYTES', (string)(20 * 1024 * 1024 * 1024));
if ($maxBytes <= 0) {
    $maxBytes = 20 * 1024 * 1024 * 1024;
}

$totalBytes = 0;
if (is_dir($storageDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $totalBytes += (int)$file->getSize();
        }
    }
}

$datasetCountQuery = $db->query("SELECT COUNT(*) AS c FROM dataset WHERE approved = 1");
$datasetCount = $datasetCountQuery ? (int)($datasetCountQuery->fetch_assoc()['c'] ?? 0) : 0;
$maxRows = (int)api_get_secret('PUBLIC_WEB_SEED_MAX_ROWS', '12000');
if ($maxRows < 100) {
    $maxRows = 100;
}

function public_web_seed_prune_old_rows(mysqli $db, int $targetRows): int {
    $countQuery = $db->query('SELECT COUNT(*) AS c FROM dataset WHERE approved = 1');
    $count = $countQuery ? (int)($countQuery->fetch_assoc()['c'] ?? 0) : 0;
    if ($count <= $targetRows) {
        return 0;
    }

    $deleteCount = $count - $targetRows;
    $stmt = $db->prepare('DELETE FROM dataset WHERE id IN (SELECT id FROM (SELECT id FROM dataset WHERE approved = 1 ORDER BY created_at ASC, id ASC LIMIT ?) AS prune_target)');
    $stmt->bind_param('i', $deleteCount);
    $ok = $stmt->execute();
    $deleted = $ok ? $stmt->affected_rows : 0;
    $stmt->close();
    return (int)$deleted;
}

$softCap = max(100, (int)floor($maxRows * 0.9));
$pruned = public_web_seed_prune_old_rows($db, $softCap);
if ($pruned > 0) {
    $datasetCountQuery = $db->query("SELECT COUNT(*) AS c FROM dataset WHERE approved = 1");
    $datasetCount = $datasetCountQuery ? (int)($datasetCountQuery->fetch_assoc()['c'] ?? 0) : 0;
    echo "[public_web_seed] pruned {$pruned} old dataset rows to stay under the soft cap: rows={$datasetCount}/{$maxRows}\n";
}

if ($datasetCount >= $maxRows || $totalBytes >= $maxBytes) {
    echo "[public_web_seed] dataset row cap or storage cap reached: rows={$datasetCount}/{$maxRows}, bytes={$totalBytes}/{$maxBytes}\n";
    exit(0);
}

function public_web_seed_extract_question(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return 'Public web knowledge excerpt';
    }
    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    foreach ($sentences as $sentence) {
        $sentence = trim((string)$sentence);
        if (strlen($sentence) >= 32 && strlen($sentence) <= 180) {
            return $sentence;
        }
    }
    if (strlen($text) > 180) {
        return substr($text, 0, 180) . '...';
    }
    return $text;
}

function public_web_seed_chunk_text(string $text, int $minChars = 140): array {
    $text = trim((string)$text);
    if ($text === '') {
        return [];
    }
    $normalized = preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text;
    $normalized = preg_replace('/\b(?:Figure|Table|Image|Source|References|Related|See also)\b.*$/i', '', $normalized) ?? $normalized;
    $sentences = preg_split('/(?<=[.!?])\s+/', $normalized);
    $chunks = [];
    $buffer = '';
    foreach ($sentences as $sentence) {
        $sentence = trim((string)$sentence);
        if ($sentence === '') {
            continue;
        }
        if (strlen($buffer) + strlen($sentence) > 650) {
            if (strlen($buffer) >= $minChars) {
                $chunks[] = $buffer;
            }
            $buffer = $sentence;
        } else {
            $buffer = $buffer === '' ? $sentence : ($buffer . ' ' . $sentence);
        }
    }
    if (strlen($buffer) >= $minChars) {
        $chunks[] = $buffer;
    }
    return array_values(array_filter(array_map('trim', $chunks), fn($c) => strlen($c) >= $minChars));
}

function public_web_seed_is_quality_chunk(string $text): bool {
    $text = trim((string)$text);
    if ($text === '') {
        return false;
    }
    if (strlen($text) < 180 || strlen($text) > 6500) {
        return false;
    }
    $lower = strtolower($text);
    foreach (['privacy policy', 'terms of service', 'cookie policy', 'subscribe to our newsletter', 'javascript:void(0)', 'all rights reserved', 'sign in', 'login'] as $flag) {
        if (str_contains($lower, $flag)) {
            return false;
        }
    }
    $wordCount = preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\-\']*\b/u', $text, $matches);
    if ($wordCount === false || $wordCount < 25) {
        return false;
    }
    if (preg_match('/[A-Za-z]{3,}/', $text) !== 1) {
        return false;
    }
    $alpha = preg_replace('/[^A-Za-z]/', '', $text);
    if (strlen($alpha) < 80) {
        return false;
    }
    return true;
}

function public_web_seed_store_chunk(mysqli $db, string $question, string $answer): bool {
    $question = dataset_sanitize_training_text($question);
    $answer = dataset_sanitize_training_text($answer);
    if ($question === '' || $answer === '') {
        return false;
    }
    if (!public_web_seed_is_quality_chunk($answer)) {
        return false;
    }

    $chk = $db->prepare('SELECT id FROM dataset WHERE question = ? AND answer = ? LIMIT 1');
    $chk->bind_param('ss', $question, $answer);
    $chk->execute();
    $checkResult = $chk->get_result();
    $exists = $checkResult && $checkResult->num_rows > 0;
    $chk->close();
    if ($exists) {
        return false;
    }

    $keywords = extractKeywords($question . ' ' . $answer);
    $stmt = $db->prepare('INSERT INTO dataset (source_id, question, answer, keywords, approved) VALUES (?, ?, ?, ?, 1)');
    $sourceId = 0;
    $stmt->bind_param('isss', $sourceId, $question, $answer, $keywords);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$seedUrls = array_values(array_filter(array_map('trim', explode(',', (string)api_get_secret('PUBLIC_WEB_SEED_URLS', ''))), fn($v) => $v !== ''));
if (empty($seedUrls)) {
    $seedUrls = [
        'https://en.wikipedia.org/wiki/Artificial_intelligence',
        'https://www.php.net/manual/en/index.php',
        'https://docs.python.org/3/',
        'https://developer.mozilla.org/en-US/docs/Web/JavaScript',
        'https://www.linux.org/',
        'https://learn.microsoft.com/en-us/',
    ];
}

$topicCsv = (string)api_get_secret('PUBLIC_WEB_SEED_TOPICS', 'artificial intelligence,software engineering,php,python,javascript,linux,business,marketing,cybersecurity');
$topics = array_values(array_filter(array_map('trim', explode(',', $topicCsv)), fn($v) => $v !== ''));
if (empty($topics)) {
    $topics = ['artificial intelligence', 'software engineering', 'php', 'python', 'javascript', 'linux', 'business'];
}

$candidates = [];
foreach ($seedUrls as $seedUrl) {
    $candidates[] = $seedUrl;
}
foreach ($topics as $topic) {
    $results = chat_web_search_query($topic, false);
    foreach ($results as $result) {
        $url = trim((string)($result['url'] ?? ''));
        if ($url !== '') {
            $candidates[] = $url;
        }
    }
}

$candidates = array_values(array_unique(array_filter($candidates, fn($v) => $v !== '')));
$added = 0;
$visited = 0;

foreach ($candidates as $url) {
    if ($visited >= $maxPages) {
        break;
    }
    if ($datasetCount + $added >= $maxRows) {
        break;
    }

    $urlCheck = netpolicy_validate_outbound_url($url, false);
    if (!($urlCheck['ok'] ?? false)) {
        continue;
    }

    $page = chat_web_fetch_html($url, 8);
    if (!$page || empty($page['body'])) {
        continue;
    }

    $text = chat_web_extract_text((string)$page['body'], 18000);
    $chunks = public_web_seed_chunk_text($text, 200);
    foreach ($chunks as $chunk) {
        if ($datasetCount + $added >= $maxRows) {
            break 2;
        }
        $question = public_web_seed_extract_question($chunk);
        if (public_web_seed_store_chunk($db, $question, $chunk)) {
            $added++;
        }
    }

    $visited++;

    if ($totalBytes >= $maxBytes) {
        break;
    }
}

$cachePath = $storageDir . '/seed_run_' . date('Ymd_His') . '.txt';
@file_put_contents($cachePath, "visited={$visited}\nadded={$added}\n", LOCK_EX);

$totalBytes = 0;
if (is_dir($storageDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $totalBytes += (int)$file->getSize();
        }
    }
}

if (!$quiet) {
    $rowTotal = $datasetCount + $added;
    echo "[public_web_seed] visited={$visited} added={$added} rows={$rowTotal} stored_bytes={$totalBytes}/{$maxBytes}\n";
}

$db->close();
exit(0);
