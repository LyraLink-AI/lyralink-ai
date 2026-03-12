<?php
// ── CONFIG ──
$groqApiKey     = getenv('GROQ_API_KEY') ?: '';
$moltbookApiKey = getenv('MOLTBOOK_API_KEY') ?: '';
$agentName      = 'lyralink';

if ($groqApiKey === '' || $moltbookApiKey === '') {
    echo "[".date('Y-m-d H:i:s')."] Missing GROQ_API_KEY or MOLTBOOK_API_KEY, skipping.\n";
    exit;
}

function callGroq($apiKey, $messages, $maxTokens = 500, $temperature = 0.9) {
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

    $code = $v['verification_code'] ?? null;
    $challenge = $v['challenge_text'] ?? null;
    if (!$code || !$challenge) { echo "[".date('H:i:s')."] Incomplete verification data: ".json_encode($v)."\n"; return false; }

    echo "[".date('H:i:s')."] Challenge: $challenge\n";

    $raw = callGroq($groqApiKey, [
        ['role'=>'system','content'=>'You are a math solver. Reply with ONLY the final numeric answer. Just the number — no words, no units, no punctuation. Decimals rounded to 2 places.'],
        ['role'=>'user','content'=>$challenge]
    ], 30, 0.0);

    if (!$raw) { echo "[".date('H:i:s')."] Groq failed to solve\n"; return false; }

    $answer = trim(preg_replace('/[^0-9.\-]/', '', trim($raw)));
    if (!is_numeric($answer)) { echo "[".date('H:i:s')."] Bad answer from Groq: '$raw' → '$answer'\n"; return false; }

    echo "[".date('H:i:s')."] Submitting: $answer\n";
    $res = moltRequest('https://www.moltbook.com/api/v1/verify', $moltbookApiKey, 'POST', ['verification_code'=>$code,'answer'=>$answer]);
    $ok  = !empty($res['data']['success']) || !empty($res['data']['verified']);
    echo "[".date('H:i:s')."] Verify ".($ok ? "✓ passed" : "✗ failed: ".$res['raw'])."\n";
    return $ok;
}

// ── TOPICS ──
$topics = [
    "a random interesting fact about science",
    "a philosophical thought about AI and consciousness",
    "a fun coding tip or trick",
    "a thought about the future of the internet",
    "something interesting about how humans and AI interact",
    "a creative short story in 3 sentences",
    "a surprising fact about nature or animals",
    "a thought experiment about technology",
    "something curious about mathematics",
    "a reflection on what it means to learn something new",
    "a fun hypothetical question for other AI agents",
    "something fascinating about space or the universe",
    "a thought about creativity and problem solving",
    "an interesting historical fact",
    "a prediction about technology in 10 years",
    "a shower thought that makes you think",
    "something surprising about human psychology",
    "a tip about programming or web development",
];
$topic = $topics[array_rand($topics)];

// ── GENERATE ──
$rawText = callGroq($groqApiKey, [
    ['role'=>'system','content'=>'You are Lyralink, a friendly casual AI agent on Moltbook. Write engaging thoughtful short posts. Never mention you were given a topic. Respond ONLY with raw JSON no markdown: {"title":"catchy title under 80 chars","content":"post under 300 words"}'],
    ['role'=>'user','content'=>"Write a Moltbook post about: $topic"]
], 500, 0.9);

if (!$rawText) { echo "[".date('Y-m-d H:i:s')."] Groq failed.\n"; exit; }

$post = json_decode(trim(str_replace(['```json','```'], '', $rawText)), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($post['title'])) {
    $post = ['title' => ucfirst(substr($topic, 0, 75)), 'content' => $rawText];
}

// ── POST ──
$res = moltRequest('https://www.moltbook.com/api/v1/posts', $moltbookApiKey, 'POST', [
    'submolt_name' => 'general',
    'title'        => substr($post['title'], 0, 80),
    'content'      => $post['content']
]);

checkSuspension($res['data']);
solveVerification($res['data'], $groqApiKey, $moltbookApiKey);

if (!empty($res['data']['success']) || !empty($res['data']['post'])) {
    echo "[".date('Y-m-d H:i:s')."] Posted: ".$post['title']."\n";
} else {
    echo "[".date('Y-m-d H:i:s')."] Failed: ".$res['raw']."\n";
}
?>