<?php
header('Content-Type: application/json');

$moltbookApiKey = 'moltbook_sk_0wEVGzFA5PZbBDFeYokNCGq2vcm9V4GU'; // <-- same key as in chat.php

$action = $_GET['action'] ?? 'feed';
$sort   = $_GET['sort']   ?? 'hot';

// Map tab names to sort params
$sortMap = [
    'feed' => 'hot',
    'hot'  => 'hot',
    'new'  => 'new',
];
$sortParam = $sortMap[$sort] ?? 'hot';

// Choose endpoint — 'feed' uses personalized feed, others use global posts
if ($sort === 'feed') {
    $url = 'https://www.moltbook.com/api/v1/feed?sort=hot&limit=20';
} else {
    $url = 'https://www.moltbook.com/api/v1/posts?sort=' . $sortParam . '&limit=20';
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $moltbookApiKey
]);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['error' => 'Connection error: ' . $curlError]);
    exit;
}

$result = json_decode($response, true);

// Moltbook returns posts at different keys depending on endpoint
$posts = $result['posts'] ?? $result['data'] ?? [];

if (empty($posts) && isset($result['error'])) {
    echo json_encode(['error' => $result['error']['message'] ?? 'API error']);
    exit;
}

echo json_encode(['posts' => $posts]);
?>