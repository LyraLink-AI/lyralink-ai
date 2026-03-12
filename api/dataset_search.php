<?php
// ════════════════════════════════
// DATASET SEARCH ENGINE
// Keyword matching + cosine similarity on embeddings
// ════════════════════════════════

// ── KEYWORD SEARCH ──
// Uses MySQL FULLTEXT search for fast keyword matching
function datasetKeywordSearch($db, $query, $limit = 5) {
    // Try FULLTEXT first
        $stmt = $db->prepare("
                SELECT id, question, answer,
                             MATCH(question, answer) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                FROM dataset
                WHERE approved = 1
                    AND MATCH(question, answer) AGAINST(? IN NATURAL LANGUAGE MODE)
                ORDER BY score DESC
                LIMIT ?
        ");
        $stmt->bind_param('ssi', $query, $query, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id'       => $row['id'],
                'question' => $row['question'],
                'answer'   => $row['answer'],
                'score'    => (float)$row['score'],
                'method'   => 'keyword'
            ];
        }
    }
    $stmt->close();

    // Fallback: simple LIKE search if FULLTEXT returns nothing
    if (empty($rows)) {
        $words   = array_filter(explode(' ', preg_replace('/[^\w\s]/', '', strtolower($query))));
        $clauses = [];
        $types = '';
        $params = [];
        foreach (array_slice($words, 0, 5) as $word) {
            if (strlen($word) < 3) continue;
            $clauses[] = "(question LIKE ? OR answer LIKE ?)";
            $like = '%' . $word . '%';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($clauses)) {
            $where  = implode(' OR ', $clauses);
            $sql = "SELECT id, question, answer FROM dataset WHERE approved = 1 AND ($where) LIMIT ?";
            $stmt = $db->prepare($sql);
            $types .= 'i';
            $params[] = $limit;
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = [
                        'id'       => $row['id'],
                        'question' => $row['question'],
                        'answer'   => $row['answer'],
                        'score'    => 0.5,
                        'method'   => 'like'
                    ];
                }
            }
            $stmt->close();
        }
    }

    return $rows;
}

// ── COSINE SIMILARITY ──
function cosineSimilarity($a, $b) {
    if (empty($a) || empty($b) || count($a) !== count($b)) return 0;
    $dot = 0; $normA = 0; $normB = 0;
    for ($i = 0; $i < count($a); $i++) {
        $dot   += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    $denom = sqrt($normA) * sqrt($normB);
    return $denom > 0 ? $dot / $denom : 0;
}

// ── GET EMBEDDING FROM GROQ ──
// Groq doesn't have embeddings — use a lightweight local approach
// OR swap in OpenAI/any embedding API here
function getEmbedding($text, $groqApiKey) {
    // Use Groq to generate a pseudo-embedding via feature extraction prompt
    // This asks the model to output key semantic features as a vector-like structure
    // For real embeddings, replace with: https://api.openai.com/v1/embeddings
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model'       => 'llama-3.1-8b-instant',
        'messages'    => [
            ['role' => 'system', 'content' => 'Extract the 20 most important semantic keywords from the text. Reply ONLY with a JSON array of 20 strings. No explanation.'],
            ['role' => 'user',   'content' => substr($text, 0, 500)]
        ],
        'max_tokens'  => 100,
        'temperature' => 0.1
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $groqApiKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result  = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '[]';
    $content = trim(str_replace(['```json','```'], '', $content));
    $keywords = json_decode($content, true);
    if (!is_array($keywords)) return null;

    // Convert keywords to a simple hash-based float vector (64 dims)
    $vector = array_fill(0, 64, 0.0);
    foreach ($keywords as $kw) {
        $hash = crc32(strtolower(trim($kw)));
        $idx  = abs($hash) % 64;
        $vector[$idx] += 1.0;
    }
    // Normalize
    $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));
    if ($norm > 0) $vector = array_map(fn($v) => $v / $norm, $vector);

    return $vector;
}

// ── EMBEDDING SEARCH ──
function datasetEmbeddingSearch($db, $queryEmbedding, $limit = 5, $threshold = 0.3) {
    if (!$queryEmbedding) return [];

    $stmt = $db->prepare("SELECT id, question, answer, embedding FROM dataset WHERE approved = 1 AND embedding IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) return [];

    $scored = [];
    while ($row = $result->fetch_assoc()) {
        $emb = json_decode($row['embedding'], true);
        if (!$emb) continue;
        $sim = cosineSimilarity($queryEmbedding, $emb);
        if ($sim >= $threshold) {
            $scored[] = [
                'id'       => $row['id'],
                'question' => $row['question'],
                'answer'   => $row['answer'],
                'score'    => $sim,
                'method'   => 'embedding'
            ];
        }
    }

    $stmt->close();

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

// ── COMBINED SEARCH (keyword first, embedding fallback) ──
function datasetSearch($db, $query, $groqApiKey, $limit = 3) {
    // Step 1: keyword search
    $keywordResults = datasetKeywordSearch($db, $query, $limit * 2);

    // If keyword search found good results (score > 1), use those
    $goodKeyword = array_filter($keywordResults, fn($r) => $r['score'] > 0.8);
    if (count($goodKeyword) >= $limit) {
        return array_slice(array_values($goodKeyword), 0, $limit);
    }

    // Step 2: embedding fallback for remainder
    $queryEmbedding  = getEmbedding($query, $groqApiKey);
    $embeddingResults = datasetEmbeddingSearch($db, $queryEmbedding, $limit * 2);

    // Merge: deduplicate by id, prefer higher score
    $merged = [];
    foreach (array_merge($keywordResults, $embeddingResults) as $r) {
        $id = $r['id'];
        if (!isset($merged[$id]) || $r['score'] > $merged[$id]['score']) {
            $merged[$id] = $r;
        }
    }

    usort($merged, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice(array_values($merged), 0, $limit);
}

// ── EXTRACT KEYWORDS (for storage) ──
function extractKeywords($text) {
    // Simple keyword extraction: strip stop words, get most frequent meaningful words
    $stopWords = ['the','a','an','is','it','in','on','at','to','for','of','and','or','but',
                  'not','with','this','that','was','are','be','have','has','had','do','did',
                  'will','would','could','should','may','might','can','i','you','we','they',
                  'he','she','what','how','why','when','where','which','who','your','my'];

    $words = preg_split('/\W+/', strtolower($text));
    $freq  = [];
    foreach ($words as $w) {
        if (strlen($w) < 3 || in_array($w, $stopWords)) continue;
        $freq[$w] = ($freq[$w] ?? 0) + 1;
    }
    arsort($freq);
    return implode(',', array_slice(array_keys($freq), 0, 20));
}
?>