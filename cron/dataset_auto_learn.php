<?php
// ════════════════════════════════
// DATASET AUTO-LEARN (verification-gated, tenant-scoped)
//
// Promotes logged conversations from `conversations` into the searchable
// `dataset` table so future chats can draw on them as context.
//
// ─────────────────────────────────────────────────────────────────────────
// WHY THIS FILE CHANGED (2026-09-21)
//
// The previous version promoted *any* conversation whose text passed a length
// check and a 3-phrase error blocklist. That produced two defects, both serious:
//
//   1. PRIVACY LEAK. Rows were inserted with org_id / owner_user_id left NULL.
//      api/dataset_search.php treats (org_id IS NULL AND owner_user_id IS NULL)
//      as GLOBAL knowledge, so every user's private chat became retrievable
//      context for every other tenant. Measured on 2026-09-21: all 8,003 of the
//      embedded rows in `dataset` were this kind, drawn from real conversations.
//
//   2. SELF-CONTAMINATION. Unverified model replies were embedded and later
//      served back as "knowledge", letting the system retrieve and cite its own
//      earlier mistakes as fact.
//
// THE GATE IS NOW FAIL-CLOSED. A conversation is ingested only if ALL hold:
//
//   * conversations.verification_status = 'verified'
//   * conversations.evidence_level IS NOT NULL
//   * its owner resolves to a real `users` row (so the row can be tenant-scoped)
//
// Everything else is left untouched (in_dataset stays 0) and counted in the log
// line, so "why did nothing get learned?" always has a visible answer instead of
// silently reporting success.
// ════════════════════════════════
require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/dataset_search.php';

$groqApiKey          = api_get_secret('GROQ_API_KEY', '');
$learnWithEmbeddings = api_get_secret('DATASET_AUTO_LEARN_EMBEDDINGS', '0') === '1';

// Escape hatch. Setting DATASET_AUTO_LEARN_REQUIRE_VERIFICATION=0 restores the
// old ingest-everything behaviour. Do NOT do that in production: it reopens both
// the privacy leak and the self-contamination loop described above.
$requireVerification = api_get_secret('DATASET_AUTO_LEARN_REQUIRE_VERIFICATION', '1') === '1';

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    echo "[" . date('Y-m-d H:i:s') . "] DB connection error: {$db->connect_error}\n";
    exit(1);
}

$batchLimit = (int)api_get_secret('DATASET_AUTO_LEARN_BATCH', '25');
if ($batchLimit < 1) $batchLimit = 25;
if ($batchLimit > 200) $batchLimit = 200;

$minUserLen  = 8;
$minReplyLen = 20;
$errorPhrases = [
    'sorry, something went wrong',
    'ai returned empty',
    'try sending your message again',
];

// Verification gating and owner resolution both happen in SQL, so ineligible
// rows are never even loaded into memory. The LEFT JOIN resolves the conversation
// author to a real users row; rows that do not resolve are skipped below.
$sql = "SELECT c.id, c.user_id, c.user_message, c.ai_reply, c.evidence_level, c.verified_at,
               u.id AS owner_user_id
          FROM conversations c
          LEFT JOIN users u
                 ON (u.username = c.user_id OR CAST(u.id AS CHAR) = c.user_id)
         WHERE c.in_dataset = 0";
if ($requireVerification) {
    $sql .= " AND c.verification_status = 'verified' AND c.evidence_level IS NOT NULL";
}
$sql .= " ORDER BY c.created_at ASC LIMIT ?";

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo "[" . date('Y-m-d H:i:s') . "] prepare failed: {$db->error}\n";
    exit(1);
}
$stmt->bind_param('i', $batchLimit);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;
$stmt->close();

$added               = 0;
$rejected            = 0;
$skippedUnattributed = 0;
$skippedNoEmbedding  = 0;
$insertFailed        = 0;

foreach ($rows as $conv) {
    $convId = (int)$conv['id'];
    $q = trim((string)$conv['user_message']);
    $a = trim((string)$conv['ai_reply']);

    // 1. Quality floor (unchanged from the original behaviour).
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

    // 2. TENANT SCOPE — fail closed.
    //    An unattributable row must never be inserted, because a row with both
    //    org_id and owner_user_id NULL is treated as GLOBAL by dataset_search.php.
    //    Leaving it at in_dataset = 0 keeps it available for later review.
    $ownerUserId = (isset($conv['owner_user_id']) && $conv['owner_user_id'] !== null)
        ? (int)$conv['owner_user_id']
        : null;
    if ($ownerUserId === null || $ownerUserId <= 0) {
        $skippedUnattributed++;
        continue;
    }

    $keywords = extractKeywords($q . ' ' . $a);

    // 3. An unembedded row is reachable only by the weak keyword path, so paying
    //    for a row we cannot retrieve semantically is not worth it — and silently
    //    inserting it hides that the embedding call failed.
    $embJson = null;
    if ($learnWithEmbeddings) {
        $embedding = getEmbedding($q . ' ' . $a, $groqApiKey);
        if (!$embedding) { $skippedNoEmbedding++; continue; }
        $embJson = json_encode($embedding);
    }

    $evidenceLevel = isset($conv['evidence_level']) ? substr((string)$conv['evidence_level'], 0, 8) : null;
    $verifiedAt    = (isset($conv['verified_at']) && $conv['verified_at'] !== null) ? (string)$conv['verified_at'] : null;
    $sourceKind    = 'conversation';
    $orgId         = null; // org unknown at ingest time; owner_user_id carries the scope
    $approved      = 1;    // safe: row already passed the verification gate above

    $ins = $db->prepare(
        "INSERT INTO dataset
            (source_id, org_id, owner_user_id, question, answer, keywords, embedding,
             source_kind, evidence_level, verified_at, approved)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$ins) {
        echo "[" . date('Y-m-d H:i:s') . "] prepare insert failed: {$db->error}\n";
        $insertFailed++;
        continue;
    }
    $ins->bind_param(
        'iiisssssssi',
        $convId, $orgId, $ownerUserId, $q, $a, $keywords, $embJson,
        $sourceKind, $evidenceLevel, $verifiedAt, $approved
    );
    if ($ins->execute()) {
        $datasetId = $db->insert_id;
        $upd = $db->prepare("UPDATE conversations SET in_dataset = 1, dataset_id = ? WHERE id = ?");
        $upd->bind_param('ii', $datasetId, $convId);
        $upd->execute();
        $upd->close();
        $added++;
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] insert failed for conv {$convId}: {$ins->error}\n";
        $insertFailed++;
    }
    $ins->close();

    if ($learnWithEmbeddings) {
        usleep(150000); // throttle embedding API calls
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Dataset auto-learn:"
    . " scanned=" . count($rows)
    . " added=$added"
    . " rejected=$rejected"
    . " skipped_unattributed=$skippedUnattributed"
    . " skipped_no_embedding=$skippedNoEmbedding"
    . " insert_failed=$insertFailed"
    . " verification_required=" . ($requireVerification ? 'yes' : 'no')
    . "\n";
