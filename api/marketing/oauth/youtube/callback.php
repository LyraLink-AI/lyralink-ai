<?php
require_once __DIR__ . '/../../../marketing_lib.php';

session_start();

$oauthError = trim((string)($_GET['error'] ?? ''));
$oauthErrorDescription = trim((string)($_GET['error_description'] ?? ''));
if ($oauthError !== '') {
    http_response_code(403);
    $message = $oauthErrorDescription !== '' ? $oauthErrorDescription : $oauthError;
    echo '<html><body><h1>YouTube authorization was denied.</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>If this account should have access, add it as a Google OAuth test user in the Cloud Console and try again.</p></body></html>';
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));
$expectedState = trim((string)($_SESSION['marketing_youtube_oauth_state'] ?? ''));

if ($code === '' || $state === '' || $state !== $expectedState) {
    http_response_code(400);
    echo '<html><body><h1>Invalid YouTube OAuth callback.</h1><p>The state token did not match. Please try reconnecting.</p></body></html>';
    exit;
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    http_response_code(500);
    echo '<html><body><h1>Database connection failure.</h1></body></html>';
    exit;
}
$db->set_charset('utf8mb4');
marketing_ensure_schema($db);

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo '<html><body><h1>Session expired.</h1><p>Please log in again.</p></body></html>';
    exit;
}

$redirectUri = marketing_base_redirect_uri();
$clientId = trim((string)api_get_secret('YOUTUBE_CLIENT_ID', api_get_secret('GOOGLE_CLIENT_ID', '')));
$clientSecret = trim((string)api_get_secret('YOUTUBE_CLIENT_SECRET', api_get_secret('GOOGLE_CLIENT_SECRET', '')));
if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    echo '<html><body><h1>YouTube OAuth is not configured.</h1><p>Set YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET in the environment.</p></body></html>';
    exit;
}

$result = marketing_http_json(
    'https://oauth2.googleapis.com/token',
    'POST',
    [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ],
    ['Content-Type: application/x-www-form-urlencoded']
);

if (!$result['ok'] || empty($result['body']['access_token'])) {
    http_response_code(400);
    $err = $result['body']['error_description'] ?? ($result['body']['error'] ?? 'unknown oauth error');
    echo '<html><body><h1>YouTube OAuth exchange failed.</h1><p>' . htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

$tokenData = $result['body'];
$channelSummary = null;
$token = trim((string)($tokenData['access_token'] ?? ''));
if ($token !== '') {
    $channelResult = marketing_http_json(
        'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true',
        'GET',
        [],
        ['Content-Type: application/json'],
        $token
    );
    if ($channelResult['ok']) {
        $items = $channelResult['body']['items'] ?? [];
        if (!empty($items)) {
            $channelSummary = [
                'id' => (string)($items[0]['id'] ?? ''),
                'title' => (string)($items[0]['snippet']['title'] ?? ''),
            ];
        }
    }
}

$uid = (int)$_SESSION['user_id'];
if (!marketing_store_youtube_tokens($db, $uid, $tokenData, $channelSummary)) {
    http_response_code(500);
    echo '<html><body><h1>Could not store YouTube connection.</h1></body></html>';
    exit;
}

unset($_SESSION['marketing_youtube_oauth_state']);
$redirectTarget = rtrim((string)api_get_secret('APP_BASE_URL', 'https://lyralinkai.com'), '/') . '/pages/automation.php?youtube_connected=1';
header('Location: ' . $redirectTarget, true, 302);
exit;
