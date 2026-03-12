<?php
/**
 * cron/moltscrape.php
 * Scrapes Moltbook posts + comments and saves to DB for use in AI context.
 * Run every 15 minutes: * /15 * * * * php /path/to/cron/moltscrape.php
 */

$moltbookApiKey = getenv('MOLTBOOK_API_KEY') ?: '';
$agentName      = 'lyralink'; // our AI's username on Moltbook — marks posts as is_our_ai

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

// ── SCRAPE HOT POSTS ──
$sorts      = ['hot', 'new', 'top'];
$totalPosts = 0;
$totalComments = 0;

foreach ($sorts as $sort) {
    echo "[moltscrape] Fetching $sort posts...\n";
    $data = moltGet("https://www.moltbook.com/api/v1/posts?sort=$sort&limit=30", $headers);
    $posts = $data['posts'] ?? $data['data'] ?? $data ?? [];
    if (!is_array($posts)) { echo "[moltscrape] No posts for $sort\n"; continue; }

    foreach ($posts as $post) {
        $moltId = savePost($db, $post, $agentName);
        if (!$moltId) continue;
        $totalPosts++;

        // Fetch comments for this post
        $commentData = moltGet("https://www.moltbook.com/api/v1/posts/$moltId/comments?limit=20", $headers);
        $comments    = $commentData['comments'] ?? $commentData['data'] ?? [];
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                saveComment($db, $comment, $moltId, $agentName);
                $totalComments++;
            }
        }

        // Small delay to avoid rate limiting
        usleep(200000); // 200ms
    }

    sleep(1);
}

// ── PRUNE OLD POSTS (keep last 90 days) ──
$db->query("DELETE FROM moltbook_posts    WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$db->query("DELETE FROM moltbook_comments WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

echo "[moltscrape] Done. Saved $totalPosts posts, $totalComments comments.\n";
$db->close();
?>