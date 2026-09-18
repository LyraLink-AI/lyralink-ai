<?php
require __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/dataset_search.php';

/**
 * cron/moltscrape.php
 * Scrapes Moltbook posts + comments and saves to DB for use in AI context.
 * Run every 15 minutes: * /15 * * * * php /path/to/cron/moltscrape.php
 */

$moltbookApiKey = getenv('MOLTBOOK_API_KEY') ?: '';
$agentName      = 'lyralink'; // our AI's username on Moltbook — marks posts as is_our_ai
$groqApiKey     = api_get_secret('GROQ_API_KEY', '');
$autoLearnEnabled = api_get_secret('MOLT_SCRAPE_AUTO_LEARN', '1') === '1';
$autoLearnEmbeddings = api_get_secret('MOLT_SCRAPE_AUTO_LEARN_EMBEDDINGS', '0') === '1';
$autoLearnMax = (int)api_get_secret('MOLT_SCRAPE_AUTO_LEARN_MAX', '40');
$autoLearnMinUpvotes = (int)api_get_secret('MOLT_SCRAPE_MIN_UPVOTES', '2');
$autoLearnMinPostLen = (int)api_get_secret('MOLT_SCRAPE_MIN_POST_CHARS', '120');
$autoLearnMinCommentLen = (int)api_get_secret('MOLT_SCRAPE_MIN_COMMENT_CHARS', '140');
if ($autoLearnMax < 1) $autoLearnMax = 40;
if ($autoLearnMax > 300) $autoLearnMax = 300;
if ($autoLearnMinUpvotes < 0) $autoLearnMinUpvotes = 0;
if ($autoLearnMinPostLen < 40) $autoLearnMinPostLen = 40;
if ($autoLearnMinCommentLen < 60) $autoLearnMinCommentLen = 60;

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'app_user';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'aicloud';

if ($moltbookApiKey === '') {
    die("[moltscrape] Missing MOLTBOOK_API_KEY, skipping.\n");
}

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { die("[moltscrape] DB error: " . $db->connect_error . "\n"); }

$db->set_charset('utf8mb4');

$db->query("CREATE TABLE IF NOT EXISTS moltbook_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    moltbook_id VARCHAR(100) NOT NULL,
    author_name VARCHAR(200) NOT NULL,
    author_id VARCHAR(100) DEFAULT NULL,
    is_our_ai TINYINT(1) NOT NULL DEFAULT 0,
    title VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    upvotes INT NOT NULL DEFAULT 0,
    comment_count INT NOT NULL DEFAULT 0,
    tags VARCHAR(500) DEFAULT NULL,
    posted_at DATETIME NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_moltbook_post_id (moltbook_id),
    KEY idx_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS moltbook_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    moltbook_id VARCHAR(100) NOT NULL,
    post_id VARCHAR(100) NOT NULL,
    author_name VARCHAR(200) NOT NULL,
    author_id VARCHAR(100) DEFAULT NULL,
    is_our_ai TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NOT NULL,
    posted_at DATETIME NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_moltbook_comment_id (moltbook_id),
    KEY idx_post_id (post_id),
    KEY idx_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$headers = [
    'Authorization: Bearer ' . $moltbookApiKey,
    'Content-Type: application/json',
    'Accept: application/json',
];

function moltGet($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'LyralinkBot/1.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    return json_decode($body, true);
}

function savePost($db, $post, $agentName) {
    $moltId      = $db->real_escape_string($post['id']           ?? '');
    $authorName  = $db->real_escape_string($post['author']['name'] ?? ($post['author']['username'] ?? 'Unknown'));
    $authorId    = $db->real_escape_string($post['author']['id']   ?? '');
    $isOurAi     = (strtolower($authorName) === strtolower($agentName)) ? 1 : 0;
    $title       = $db->real_escape_string(substr($post['title'] ?? '', 0, 500));
    $body        = $db->real_escape_string(substr($post['body']  ?? $post['content'] ?? '', 0, 5000));
    $upvotes     = (int)($post['upvotes'] ?? $post['score'] ?? 0);
    $comments    = (int)($post['comment_count'] ?? $post['comments'] ?? 0);
    $tags        = $db->real_escape_string(implode(',', array_slice(array_map(fn($t) => is_string($t) ? $t : ($t['name'] ?? ''), $post['tags'] ?? []), 0, 10)));
    $postedAt    = $db->real_escape_string($post['created_at'] ?? $post['posted_at'] ?? date('Y-m-d H:i:s'));

    if (!$moltId || !$title) return false;

    $db->query("INSERT INTO moltbook_posts
        (moltbook_id, author_name, author_id, is_our_ai, title, body, upvotes, comment_count, tags, posted_at, fetched_at)
        VALUES ('$moltId','$authorName','$authorId',$isOurAi,'$title','$body',$upvotes,$comments,'$tags','$postedAt',NOW())
        ON DUPLICATE KEY UPDATE
            upvotes=$upvotes, comment_count=$comments, fetched_at=NOW(),
            body='$body', tags='$tags'");

    return $moltId;
}

function saveComment($db, $comment, $postMoltId, $agentName) {
    $moltId     = $db->real_escape_string($comment['id'] ?? '');
    $authorName = $db->real_escape_string($comment['author']['name'] ?? ($comment['author']['username'] ?? 'Unknown'));
    $authorId   = $db->real_escape_string($comment['author']['id']   ?? '');
    $isOurAi    = (strtolower($authorName) === strtolower($agentName)) ? 1 : 0;
    $body       = $db->real_escape_string(substr($comment['body'] ?? $comment['content'] ?? '', 0, 2000));
    $postId     = $db->real_escape_string($postMoltId);
    $postedAt   = $db->real_escape_string($comment['created_at'] ?? date('Y-m-d H:i:s'));

    if (!$moltId || !$body) return;

    $db->query("INSERT INTO moltbook_comments
        (moltbook_id, post_id, author_name, author_id, is_our_ai, body, posted_at, fetched_at)
        VALUES ('$moltId','$postId','$authorName','$authorId',$isOurAi,'$body','$postedAt',NOW())
        ON DUPLICATE KEY UPDATE body='$body', fetched_at=NOW()");
}

function learnable_text(string $raw, int $maxLen): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
    if ($text === '') {
        return '';
    }
    return substr($text, 0, $maxLen);
}

function upsert_dataset_memory(mysqli $db, int $sourceId, string $question, string $answer, string $groqApiKey, bool $withEmbeddings): bool {
    $question = learnable_text($question, 400);
    $answer = learnable_text($answer, 1800);
    if (strlen($question) < 12 || strlen($answer) < 60) {
        return false;
    }

    $exists = $db->prepare("SELECT id FROM dataset WHERE source_id = ? AND question = ? LIMIT 1");
    if ($exists) {
        $exists->bind_param('is', $sourceId, $question);
        $exists->execute();
        $has = $exists->get_result()->fetch_assoc();
        $exists->close();
        if ($has) {
            return false;
        }
    }

    $keywords = extractKeywords($question . ' ' . $answer);
    $embeddingJson = null;
    if ($withEmbeddings) {
        $embedding = getEmbedding($question . ' ' . $answer, $groqApiKey);
        $embeddingJson = $embedding ? json_encode($embedding) : null;
    }

    $ins = $db->prepare("INSERT INTO dataset (source_id, question, answer, keywords, embedding, approved) VALUES (?, ?, ?, ?, ?, 1)");
    if (!$ins) {
        return false;
    }
    $ins->bind_param('issss', $sourceId, $question, $answer, $keywords, $embeddingJson);
    $ok = $ins->execute();
    $ins->close();
    return (bool)$ok;
}

function molt_source_id(string $prefix, string $id): int {
    $hash = sprintf('%u', crc32($prefix . ':' . $id));
    $n = (int)$hash;
    return -1 * max(1, $n);
}

// ── SCRAPE HOT POSTS ──
$sorts      = ['hot', 'new', 'top'];
$totalPosts = 0;
$totalComments = 0;
$learnPool = [];

foreach ($sorts as $sort) {
    echo "[moltscrape] Fetching $sort posts...\n";
    $data = moltGet("https://www.moltbook.com/api/v1/posts?sort=$sort&limit=30", $headers);
    $posts = $data['posts'] ?? $data['data'] ?? $data ?? [];
    if (!is_array($posts)) { echo "[moltscrape] No posts for $sort\n"; continue; }

    foreach ($posts as $post) {
        $moltId = savePost($db, $post, $agentName);
        if (!$moltId) continue;
        $totalPosts++;

        $title = (string)($post['title'] ?? '');
        $body = (string)($post['body'] ?? ($post['content'] ?? ''));
        $upvotes = (int)($post['upvotes'] ?? $post['score'] ?? 0);
        $isOur = strtolower((string)($post['author']['name'] ?? ($post['author']['username'] ?? ''))) === strtolower($agentName);
        if (!$isOur && strlen($title) >= 10 && strlen($body) >= $autoLearnMinPostLen && $upvotes >= $autoLearnMinUpvotes) {
            $learnPool[] = [
                'source_id' => molt_source_id('moltpost', (string)$moltId),
                'question' => 'Moltbook post: ' . $title,
                'answer' => $body,
            ];
        }

        // Fetch comments for this post
        $commentData = moltGet("https://www.moltbook.com/api/v1/posts/$moltId/comments?limit=20", $headers);
        $comments    = $commentData['comments'] ?? $commentData['data'] ?? [];
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                saveComment($db, $comment, $moltId, $agentName);
                $totalComments++;

                $commentBody = (string)($comment['body'] ?? ($comment['content'] ?? ''));
                $commentIsOur = strtolower((string)($comment['author']['name'] ?? ($comment['author']['username'] ?? ''))) === strtolower($agentName);
                if (!$commentIsOur && strlen($title) >= 10 && strlen($commentBody) >= $autoLearnMinCommentLen) {
                    $learnPool[] = [
                        'source_id' => molt_source_id('moltcomment', (string)($comment['id'] ?? '')),
                        'question' => 'Moltbook thread context: ' . $title,
                        'answer' => $commentBody,
                    ];
                }
            }
        }

        // Small delay to avoid rate limiting
        usleep(200000); // 200ms
    }

    sleep(1);
}

$learned = 0;
if ($autoLearnEnabled && !empty($learnPool)) {
    // Deduplicate by source id before embedding calls.
    $unique = [];
    foreach ($learnPool as $item) {
        $unique[(string)$item['source_id']] = $item;
    }
    $queued = array_values($unique);
    $queued = array_slice($queued, 0, $autoLearnMax);

    foreach ($queued as $item) {
        if (upsert_dataset_memory($db, (int)$item['source_id'], (string)$item['question'], (string)$item['answer'], $groqApiKey, $autoLearnEmbeddings)) {
            $learned++;
        }
        if ($autoLearnEmbeddings) {
            usleep(120000);
        }
    }
}

// ── PRUNE OLD POSTS (keep last 90 days) ──
$db->query("DELETE FROM moltbook_posts    WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$db->query("DELETE FROM moltbook_comments WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

echo "[moltscrape] Done. Saved $totalPosts posts, $totalComments comments, learned $learned dataset items.\n";
$db->close();
?>