<?php
// ════════════════════════════════
// DATASET SEARCH ENGINE
// Keyword matching + cosine similarity on embeddings
// ════════════════════════════════

function dataset_sanitize_training_text($value): string {
    $text = is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
    $text = trim($text);
    $text = preg_replace('/https?:\/\/[^\s]+/i', ' [link] ', $text) ?? $text;
    $text = preg_replace('/\b(?:my name is|i am|i\'m|call me|this is)\s+(?:developer|alex|[A-Z][a-z]{1,30})\b/i', ' ', $text) ?? $text;
    $text = preg_replace('/\b(?:developer|alex)\b/i', ' ', $text) ?? $text;
    $text = preg_replace('/\[redacted-user-identity\]/i', ' ', $text) ?? $text;
    $text = preg_replace('/\b[A-Z][a-z]+\s+[A-Z][a-z]+\s+\d{2,}\b/', ' [redacted-identifier] ', $text) ?? $text;
    $text = preg_replace('/\b[\w\.-]+@(?:[\w-]+\.)+[A-Za-z]{2,}\b/', ' [redacted-email] ', $text) ?? $text;
    $text = preg_replace('/\b(?:\+?\d{1,3}[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)\d{3}[-.\s]?\d{4}\b/', ' [redacted-phone] ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/\s+([,;.!?])/', '$1', $text) ?? $text;
    return trim((string)$text);
}

function dataset_search_cache_dir(): string {
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $dir = $root . '/storage/cache/dataset_search';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function dataset_search_lower(string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed, 'UTF-8');
    }
    return strtolower($trimmed);
}

function dataset_search_cache_key(string $query, int $limit): string {
    $signature = [
        'query' => dataset_search_lower($query),
        'limit' => max(1, $limit),
        'version' => 1,
    ];
    return hash('sha256', json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function dataset_search_cache_get(string $query, int $limit, int $ttlSeconds = 300): ?array {
    $cacheKey = dataset_search_cache_key($query, $limit);
    $path = dataset_search_cache_dir() . '/' . $cacheKey . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    if (!isset($decoded['created_at'], $decoded['rows'])) {
        return null;
    }
    if ((time() - (int)$decoded['created_at']) > $ttlSeconds) {
        @unlink($path);
        return null;
    }
    return (array)$decoded['rows'];
}

function dataset_search_cache_set(string $query, int $limit, array $rows): void {
    if ($query === '' || empty($rows)) {
        return;
    }
    $cacheKey = dataset_search_cache_key($query, $limit);
    $path = dataset_search_cache_dir() . '/' . $cacheKey . '.json';
    $payload = [
        'created_at' => time(),
        'rows' => $rows,
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

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

function dataset_embed_model_default(): string {
    $model = trim((string)api_get_secret('LOCAL_LLM_EMBED_MODEL', api_get_secret('LOCAL_LLM_MODEL', 'lyralink-fast:latest')));
    return $model !== '' ? $model : 'lyralink-fast:latest';
}

// ── GET EMBEDDING FROM LOCAL LYRA MODEL ──
// Uses the local OpenAI-compatible endpoint to extract semantic keywords,
// then maps those keywords into a stable hash vector.
function getEmbedding($text, $groqApiKey) {
    $localBase = rtrim((string)api_get_secret('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1'), '/');
    $localRoot = preg_replace('#/v1$#', '', $localBase) ?: $localBase;
    $localModel = dataset_embed_model_default();
    $localApiKey = trim((string)api_get_secret('LOCAL_LLM_API_KEY', 'local-ollama'));

    $payload = [
        'model'       => $localModel,
        'messages'    => [
            ['role' => 'system', 'content' => 'Extract the 20 most important semantic keywords from the text. Reply ONLY with a JSON array of 20 strings. No explanation.'],
            ['role' => 'user',   'content' => substr($text, 0, 500)]
        ],
        'max_tokens'  => 100,
        'temperature' => 0.1,
        'stream'      => false,
    ];

    $requestLocal = function (string $url) use ($payload, $localApiKey) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)api_get_secret('LOCAL_LLM_CONNECT_TIMEOUT', '3'));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)api_get_secret('LOCAL_LLM_EMBED_TIMEOUT', '8'));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, (int)api_get_secret('LOCAL_LLM_EMBED_LOW_SPEED_TIME', '6'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $localApiKey
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            return null;
        }
        return $response;
    };

    $response = $requestLocal($localRoot . '/api/chat');
    if ($response === null) {
        $response = $requestLocal($localBase . '/chat/completions');
    }
    if ($response === null) {
        return null;
    }

    $result  = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? ($result['message']['content'] ?? '[]');
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

    $maxRows = (int)api_get_secret('DATASET_EMBEDDING_SEARCH_MAX_ROWS', '300');
    if ($maxRows < 50) $maxRows = 50;
    if ($maxRows > 5000) $maxRows = 5000;

    $stmt = $db->prepare("SELECT id, question, answer, embedding FROM dataset WHERE approved = 1 AND embedding IS NOT NULL ORDER BY id DESC LIMIT ?");
    $stmt->bind_param('i', $maxRows);
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
        unset($emb);
    }

    $stmt->close();

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

// ── COMBINED SEARCH (keyword first, embedding fallback) ──
function datasetSearch($db, $query, $groqApiKey, $limit = 3) {
    $normalizedQuery = trim((string)$query);
    if ($normalizedQuery === '') {
        return [];
    }

    $cachedRows = dataset_search_cache_get($normalizedQuery, (int)$limit, 300);
    if (is_array($cachedRows) && !empty($cachedRows)) {
        return array_slice($cachedRows, 0, max(1, (int)$limit));
    }

    // Step 1: keyword search
    $keywordResults = datasetKeywordSearch($db, $normalizedQuery, $limit * 2);

    // If keyword search found good results (score > 1), use those
    $goodKeyword = array_filter($keywordResults, fn($r) => $r['score'] > 0.8);
    if (count($goodKeyword) >= $limit) {
        $rows = array_slice(array_values($goodKeyword), 0, $limit);
        dataset_search_cache_set($normalizedQuery, (int)$limit, $rows);
        return $rows;
    }

    $embeddingFallbackEnabled = api_get_secret('DATASET_ENABLE_EMBEDDING_FALLBACK', '0') === '1';
    if (!$embeddingFallbackEnabled) {
        $rows = array_slice(array_values($keywordResults), 0, $limit);
        dataset_search_cache_set($normalizedQuery, (int)$limit, $rows);
        return $rows;
    }

    // Step 2: embedding fallback for remainder
    $queryEmbedding  = getEmbedding($normalizedQuery, $groqApiKey);
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
    $rows = array_slice(array_values($merged), 0, $limit);
    dataset_search_cache_set($normalizedQuery, (int)$limit, $rows);
    return $rows;
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