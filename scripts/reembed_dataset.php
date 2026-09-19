<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════
// RE-EMBED DATASET
//
// Recomputes `dataset.embedding` using a real embedding model via
// /api/embed (see dataset_real_embedding() in api/dataset_search.php).
//
// Background: the previous method asked a multi-gigabyte generative model
// for keywords and crc32-hashed them into 64 dimensions. That produced a
// non-semantic vector, cost a full generative call per lookup, and loaded
// ~8.6 GB RSS on an 11 GB host (it OOM-killed the server twice).
//
// This migration is safe to run while the site is live:
//   * rows not yet converted keep their old vector and simply stop matching
//     (cosineSimilarity returns 0 on a dimension mismatch, never a bad score)
//   * the script aborts if the embed model is unreachable, rather than
//     writing nulls over good data
//   * it is idempotent: rows already at the target dimension are skipped
//
// Usage:  php scripts/reembed_dataset.php [--limit=N] [--batch=N]
// ════════════════════════════════════════════════════════════════════

require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/dataset_search.php';

$targetDims = (int) api_get_secret('DATASET_EMBED_DIMS', '768');
$batch      = (int) api_get_secret('DATASET_REEMBED_BATCH', '200');
$limit      = 0;
$sleepMs    = (int) api_get_secret('DATASET_REEMBED_SLEEP_MS', '20');

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = (int) $m[1]; }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) { $batch = (int) $m[1]; }
    if (preg_match('/^--sleep=(\d+)$/', $arg, $m)) { $sleepMs = (int) $m[1]; }
}
if ($batch < 1)   { $batch = 200; }
if ($sleepMs < 0) { $sleepMs = 0; }

function reembed_log(string $msg): void {
    echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . "\n";
    flush();
}

$cfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
if ($db->connect_error) {
    reembed_log('db_connect_error=' . $db->connect_error);
    exit(1);
}

// Fail fast: confirm the embed model actually works before touching rows.
$probe = dataset_real_embedding('embedding availability probe');
if (!is_array($probe) || count($probe) !== $targetDims) {
    reembed_log('ABORT embed_model_unavailable got_dims=' . (is_array($probe) ? count($probe) : 'null')
        . ' expected=' . $targetDims);
    exit(2);
}
reembed_log('embed model ok dims=' . count($probe));

$total = 0;
$converted = 0;
$skipped = 0;
$failed = 0;

$stmt = $db->prepare("SELECT id, embedding FROM dataset WHERE approved = 1 ORDER BY id DESC");
$stmt->execute();
$res = $stmt->get_result();

$update = $db->prepare("UPDATE dataset SET embedding = ? WHERE id = ?");
$pending = [];

while ($row = $res->fetch_assoc()) {
    $id = (int) $row['id'];
    $total++;

    $existing = json_decode((string) ($row['embedding'] ?? ''), true);
    if (is_array($existing) && count($existing) === $targetDims) {
        $skipped++;
        continue;
    }
    $pending[] = $id;
}
$stmt->close();

reembed_log('scan complete rows=' . $total . ' need_conversion=' . count($pending) . ' already_ok=' . $skipped);

if ($limit > 0) {
    $pending = array_slice($pending, 0, $limit);
    reembed_log('limit applied, processing=' . count($pending));
}

foreach ($pending as $id) {
    // Prefer question; fall back to a combination so every row has usable text.
    $q = $db->prepare("SELECT question, answer FROM dataset WHERE id = ? LIMIT 1");
    $q->bind_param('i', $id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$r) { $failed++; continue; }

    $text = trim((string) ($r['question'] ?? ''));
    if ($text === '') {
        $text = trim((string) ($r['answer'] ?? ''));
    }
    if ($text === '') { $failed++; continue; }

    $vec = dataset_real_embedding($text);
    if (!is_array($vec) || count($vec) !== $targetDims) {
        $failed++;
        // A burst of failures means the runtime is unhealthy; stop rather than
        // hammer it or partially corrupt the table.
        if ($failed >= 10 && $converted === 0) {
            reembed_log('ABORT repeated embedding failures, no progress made');
            exit(3);
        }
        continue;
    }

    $json = json_encode($vec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $update->bind_param('si', $json, $id);
    if ($update->execute()) {
        $converted++;
    } else {
        $failed++;
    }

    if ($converted % $batch === 0 && $converted > 0) {
        reembed_log('progress converted=' . $converted . ' failed=' . $failed);
    }
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

reembed_log('DONE converted=' . $converted . ' skipped=' . $skipped . ' failed=' . $failed . ' scanned=' . $total);
$update->close();
$db->close();
