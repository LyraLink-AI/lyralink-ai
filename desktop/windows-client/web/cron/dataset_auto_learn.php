<?php
// ════════════════════════════════
// DATASET AUTO-LEARN
// Periodically promotes qualifying logged conversations (any provider/model,
// including Hermes) from `conversations` into the searchable `dataset` table
// so future chats can draw on them as context — no manual admin approval needed.
// Low-quality/error exchanges are filtered out and marked as rejected (in_dataset = -1)
// so they don't keep blocking the queue, but remain in `conversations` for manual review.
// ════════════════════════════════
require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/dataset_search.php';

$groqApiKey = api_get_secret('GROQ_API_KEY', '');
$learnWithEmbeddings = api_get_secret('DATASET_AUTO_LEARN_EMBEDDINGS', '0') === '1';

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    echo "[".date('Y-m-d H:i:s')."] DB connection error: {$db->connect_error}\n";
    exit(1);
}

$batchLimit  = (int)api_get_secret('DATASET_AUTO_LEARN_BATCH', '25');
if ($batchLimit < 1) $batchLimit = 25;
if ($batchLimit > 200) $batchLimit = 200;

$minUserLen  = 8;
$minReplyLen = 20;
$errorPhrases = [
    'sorry, something went wrong',
    'ai returned empty',
    'try sending your message again',
];

$stmt = $db->prepare("SELECT id, user_message, ai_reply FROM conversations WHERE in_dataset = 0 ORDER BY created_at ASC LIMIT ?");
$stmt->bind_param('i', $batchLimit);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;
$stmt->close();

$added = 0;
$rejected = 0;

foreach ($rows as $conv) {
    $convId = (int)$conv['id'];
    $q = trim((string)$conv['user_message']);
    $a = trim((string)$conv['ai_reply']);

    $reject = (strlen($q) < $minUserLen || strlen($a) < $minReplyLen);
    if (!$reject) {
        $aLower = strtolower($a);
        foreach ($errorPhrases as $phrase) {
            if (strpos($aLower, $phrase) !== false) { $reject = true; break; }
        }
    }

    if ($reject) {
        $upd = $db->prepare("UPDATE conversations SET in_dataset = -1 WHERE id = ?");
        $upd->bind_param('i', $convId);
        $upd->execute();
        $upd->close();
        $rejected++;
        continue;
    }

    $keywords  = extractKeywords($q . ' ' . $a);
    $embJson   = null;
    if ($learnWithEmbeddings) {
        $embedding = getEmbedding($q . ' ' . $a, $groqApiKey);
        $embJson   = $embedding ? json_encode($embedding) : null;
    }

    $ins = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param('issss', $convId, $q, $a, $keywords, $embJson);
    if ($ins->execute()) {
        $datasetId = $db->insert_id;
        $upd = $db->prepare("UPDATE conversations SET in_dataset = 1, dataset_id = ? WHERE id = ?");
        $upd->bind_param('ii', $datasetId, $convId);
        $upd->execute();
        $upd->close();
        $added++;
    }
    $ins->close();

    if ($learnWithEmbeddings) {
        usleep(150000); // throttle embedding API calls
    }
}

echo "[".date('Y-m-d H:i:s')."] Dataset auto-learn: scanned=".count($rows)." added=$added rejected=$rejected\n";
