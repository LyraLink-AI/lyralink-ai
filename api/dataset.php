<?php
require_once __DIR__ . '/security.php';
session_start();
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
$isDevUser   = ($_SESSION['username'] ?? '') === $devUsername;

require_once __DIR__ . '/dataset_search.php';

$db     = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$action = api_action();

api_enforce_post_and_origin_for_actions([
    'approve',
    'bulk_approve',
    'remove',
    'generate_embeddings',
    'edit',
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
        SELECT id, user_id, user_message, ai_reply, created_at
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