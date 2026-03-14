<?php
require __DIR__ . '/../api/security.php';

// ── CONFIG ──
$groqApiKey     = getenv('GROQ_API_KEY') ?: '';
$moltbookApiKey = getenv('MOLTBOOK_API_KEY') ?: '';
$agentName      = 'lyralink';

if ($groqApiKey === '' || $moltbookApiKey === '') {
    echo "[".date('Y-m-d H:i:s')."] Missing GROQ_API_KEY or MOLTBOOK_API_KEY, skipping.\n";
    exit;
}

function callGroq($apiKey, $messages, $maxTokens = 150, $temperature = 0.85) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model'=>'llama-3.1-8b-instant','messages'=>$messages,'max_tokens'=>$maxTokens,'temperature'=>$temperature]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Authorization: Bearer '.$apiKey]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true)['choices'][0]['message']['content'] ?? null;
}

function moltRequest($url, $key, $method = 'GET', $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Authorization: Bearer '.$key]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body ? json_encode($body) : '{}'); }
    $raw = curl_exec($ch); curl_close($ch);
    return ['raw' => $raw, 'data' => json_decode($raw, true) ?? []];
}

function checkSuspension($data) {
    if (($data['statusCode'] ?? 0) === 403 && strpos($data['message'] ?? '', 'suspended') !== false) {
        preg_match('/until\s+([\w\-T:.Z]+)/', $data['message'], $m);
        echo "[".date('Y-m-d H:i:s')."] SUSPENDED until " . ($m[1] ?? '?') . " — exiting.\n";
        exit;
    }
}

function solveVerification($data, $groqApiKey, $moltbookApiKey) {
    $v = $data['verification']
      ?? $data['post']['verification']
      ?? $data['comment']['verification']
      ?? $data['reply']['verification']
      ?? null;
    if (empty($v)) return false;

    $code      = $v['verification_code'] ?? null;
    $challenge = $v['challenge_text']    ?? null;
    if (!$code || !$challenge) { echo "[".date('H:i:s')."] Incomplete verification: ".json_encode($v)."\n"; return false; }

    echo "[".date('H:i:s')."] Challenge: $challenge\n";

    $raw = callGroq($groqApiKey, [
        ['role'=>'system','content'=>'You are a math solver. The challenge text may be obfuscated with random case, symbols, and spacing. (1) First, extract ONLY the actual math problem by removing obfuscation. (2) Parse all numbers and units carefully—handle unit conversions (e.g., meters/second to centimeters/second). (3) Solve step-by-step. (4) Reply with ONLY the final numeric answer rounded to 2 decimal places. No words, no units, no punctuation. Just the number.'],
        ['role'=>'user','content'=>$challenge]
    ], 30, 0.0);

    if (!$raw) { echo "[".date('H:i:s')."] Groq failed to solve\n"; return false; }

    $answer = trim(preg_replace('/[^0-9.\-]/', '', trim($raw)));
    if (!is_numeric($answer)) { echo "[".date('H:i:s')."] Bad parse: '$raw' → '$answer'\n"; return false; }

    echo "[".date('H:i:s')."] Submitting: $answer\n";
    $res = moltRequest('https://www.moltbook.com/api/v1/verify', $moltbookApiKey, 'POST', ['verification_code'=>$code,'answer'=>$answer]);
    $ok  = !empty($res['data']['success']) || !empty($res['data']['verified']);
    echo "[".date('H:i:s')."] Verify ".($ok ? "✓ passed" : "✗ failed: ".$res['raw'])."\n";
    return $ok;
}

// ── FETCH POSTS ──
$sort   = ['new','hot','top'][array_rand(['new','hot','top'])];
$res    = moltRequest("https://www.moltbook.com/api/v1/posts?sort=$sort&limit=20", $moltbookApiKey);
checkSuspension($res['data']);

$posts = array_values(array_filter($res['data']['posts'] ?? [], fn($p) => ($p['author']['name'] ?? '') !== $agentName));
if (empty($posts)) { echo "[".date('Y-m-d H:i:s')."] No posts to comment on.\n"; exit; }

$post        = $posts[array_rand($posts)];
$postId      = $post['id']             ?? null;
$postTitle   = $post['title']          ?? '';
$postContent = $post['content']        ?? '';
$postAuthor  = $post['author']['name'] ?? 'someone';
if (!$postId) { echo "No valid post ID.\n"; exit; }

// ── GENERATE COMMENT ──
$commentText = callGroq($groqApiKey, [
    ['role'=>'system','content'=>'You are Lyralink, a friendly casual AI agent on Moltbook. Be genuine and curious — add real value. Never say "great post!". Ask a follow-up question or share a related thought. 1-3 sentences. Just the comment text.'],
    ['role'=>'user','content'=>"Comment on this post by $postAuthor.\nTitle: \"$postTitle\"\nContent: \"".substr($postContent, 0, 500)."\""]
]);
if (!$commentText) { echo "Groq failed.\n"; exit; }

// ── POST COMMENT ──
$res = moltRequest("https://www.moltbook.com/api/v1/posts/$postId/comments", $moltbookApiKey, 'POST', ['content' => trim($commentText)]);

checkSuspension($res['data']);
solveVerification($res['data'], $groqApiKey, $moltbookApiKey);

if (!empty($res['data']['success']) || !empty($res['data']['comment'])) {
    echo "[".date('Y-m-d H:i:s')."] Commented on \"$postTitle\" by $postAuthor\n";
    echo "Comment: ".trim($commentText)."\n";
} else {
    echo "[".date('Y-m-d H:i:s')."] Failed: ".$res['raw']."\n";
}
?>