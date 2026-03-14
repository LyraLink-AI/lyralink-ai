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

$personality = 'You are Lyralink, a friendly casual AI agent on Moltbook. You are genuine, curious, and add real value. Keep replies 1-3 sentences. Write naturally.';

function callGroq($apiKey, $messages, $maxTokens = 100, $temperature = 0.5) {
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

    if (!$raw) { echo "[".date('H:i:s')."] Groq failed\n"; return false; }

    $answer = trim(preg_replace('/[^0-9.\-]/', '', trim($raw)));
    if (!is_numeric($answer)) { echo "[".date('H:i:s')."] Bad parse: '$raw' → '$answer'\n"; return false; }

    echo "[".date('H:i:s')."] Submitting: $answer\n";
    $res = moltRequest('https://www.moltbook.com/api/v1/verify', $moltbookApiKey, 'POST', ['verification_code'=>$code,'answer'=>$answer]);
    $ok  = !empty($res['data']['success']) || !empty($res['data']['verified']);
    echo "[".date('H:i:s')."] Verify ".($ok ? "✓ passed" : "✗ failed: ".$res['raw'])."\n";
    return $ok;
}

// ── FETCH FEED ──
$sort = ['hot','new','top'][array_rand(['hot','new','top'])];
$res  = moltRequest("https://www.moltbook.com/api/v1/posts?sort=$sort&limit=20", $moltbookApiKey);
checkSuspension($res['data']); // exits immediately if suspended

$posts = array_values(array_filter($res['data']['posts'] ?? [], fn($p) => ($p['author']['name'] ?? '') !== $agentName));
if (empty($posts)) { echo "[".date('Y-m-d H:i:s')."] No posts in feed.\n"; exit; }

// ── UPVOTE ──
$target   = $posts[array_rand($posts)];
$targetId = $target['id'] ?? null;
if ($targetId) {
    $res        = moltRequest("https://www.moltbook.com/api/v1/posts/$targetId/upvote", $moltbookApiKey, 'POST');
    $statusCode = $res['data']['statusCode'] ?? 0;

    checkSuspension($res['data']);

    if ($statusCode === 500) {
        // Moltbook server error — not our fault, skip silently
        echo "[".date('Y-m-d H:i:s')."] Upvote: Moltbook server error (500), skipping.\n";
    } else {
        solveVerification($res['data'], $groqApiKey, $moltbookApiKey);
        echo "[".date('Y-m-d H:i:s')."] Upvote: ".(!empty($res['data']['success']) ? "✓" : "✗ ".$res['raw'])."\n";
    }
}

// ── REPLY TO A COMMENT ──
$postWithComments = null;
foreach (array_slice($posts, 0, 5) as $p) {
    if (!empty($p['comment_count']) && $p['comment_count'] > 0) { $postWithComments = $p; break; }
}

if ($postWithComments) {
    $pid   = $postWithComments['id'];
    $pTitle = $postWithComments['title'] ?? '';
    $cRes   = moltRequest("https://www.moltbook.com/api/v1/posts/$pid/comments", $moltbookApiKey);
    checkSuspension($cRes['data']);

    $comments = array_values(array_filter($cRes['data']['comments'] ?? [], fn($c) => ($c['author']['name'] ?? '') !== $agentName));
    if (!empty($comments)) {
        $comment   = $comments[array_rand($comments)];
        $commentId = $comment['id'] ?? null;
        $commentContent = $comment['content'] ?? '';

        if ($commentId) {
            $replyText = callGroq($groqApiKey, [
                ['role'=>'system','content'=>'You are Lyralink, a friendly AI agent. Write a short genuine reply (1-2 sentences). Add value, be curious. No filler. Just the reply text.'],
                ['role'=>'user','content'=>"Post: \"$pTitle\"\nComment: \"".substr($commentContent, 0, 300)."\"\n\nWrite a reply:"]
            ], 80, 0.85);

            if ($replyText) {
                $res = moltRequest("https://www.moltbook.com/api/v1/posts/$pid/comments/$commentId/replies", $moltbookApiKey, 'POST', ['content' => trim($replyText)]);
                checkSuspension($res['data']);
                if (($res['data']['statusCode'] ?? 0) === 500) {
                    echo "[".date('Y-m-d H:i:s')."] Reply: Moltbook server error (500), skipping.\n";
                } else {
                    solveVerification($res['data'], $groqApiKey, $moltbookApiKey);
                    echo "[".date('Y-m-d H:i:s')."] Reply: ".(!empty($res['data']['success'])||!empty($res['data']['reply']) ? "✓ ".trim($replyText) : "✗ ".$res['raw'])."\n";
                }
            }
        }
    }
}

// ── FOLLOW ──
$authors = array_unique(array_filter(array_map(fn($p) => $p['author']['name'] ?? null, $posts), fn($n) => $n && $n !== $agentName));
if (!empty($authors)) {
    $toFollow = $authors[array_rand($authors)];
    $res      = moltRequest("https://www.moltbook.com/api/v1/agents/$toFollow/follow", $moltbookApiKey, 'POST');
    checkSuspension($res['data']);
    if (($res['data']['statusCode'] ?? 0) === 500) {
        echo "[".date('Y-m-d H:i:s')."] Follow $toFollow: Moltbook server error (500), skipping.\n";
    } else {
        solveVerification($res['data'], $groqApiKey, $moltbookApiKey);
        echo "[".date('Y-m-d H:i:s')."] Follow $toFollow: ".(!empty($res['data']['success']) ? "✓" : "✗ ".$res['raw'])."\n";
    }
}
?>