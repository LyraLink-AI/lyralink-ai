<?php
declare(strict_types=1);
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — MOLTBOOK FEED PROXY
   ══════════════════════════════════════════════════════════════════════════
   Read-only bridge from the chat UI to the Moltbook API.

   Two independent defects lived here and one masked the other:

   1. The key was read with getenv('MOLTBOOK_API_KEY'). This host runs
      nginx -> Apache -> PHP-FPM (Plesk) and .env is never placed in the FPM
      process environment, so getenv() returned false and EVERY caller -
      anonymous included - received:
          {"error":"MOLTBOOK_API_KEY not configured"}
      The feed panel could not have worked. api_get_secret() is the loader
      the rest of this codebase uses, and it is what loads .env.

   2. There was no authentication of any kind. The default action is 'feed',
      which is Moltbook's PERSONALIZED feed for our account, not the public
      post list. Repairing (1) on its own would have turned a dead endpoint
      into an anonymous data leak - so the two are fixed together, which is
      the only order that is safe.

   Authorization is a signed-in session: the same signal api/automation.php
   and api/chat.php use via $_SESSION['user_id'] / $_SESSION['username'].
   A session is the correct gate here rather than an Origin or Referer test,
   because a browser cannot manufacture a session it does not hold, while
   Origin/Referer is a request header that any non-browser client sets
   freely.

   Upstream calls are bounded by a timeout. The previous version had none,
   so an unresponsive moltbook.com would hold a PHP-FPM worker open until
   the pool was exhausted. A read-only feed refresh is never worth a wedged
   worker pool.

   Response shape for the happy path is unchanged ({"posts":[...]}), so the
   existing client in assets/js/chat/05_auth_session_molt.js keeps working.
   That client treats a missing `posts` array as "no posts", so the new
   401/502/503 error responses degrade cleanly rather than throwing.
   ══════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/api/session_boot.php';
require_once __DIR__ . '/api/security.php';

lyra_session_boot();
api_json_headers();

/* ── 1. Authentication — a session, not a header, decides identity ──────
   Deliberately session-only. api_try_mobile_token_auth() exists and is used
   by other endpoints, but it requires a database handle, and this endpoint
   needs no database at all; adding one here to serve a client that does not
   call it would be cost without benefit. If a native client ever needs this
   feed, add token auth at that point, with its own audit. */
$uid = (int) ($_SESSION['user_id'] ?? 0);
$username = trim((string) ($_SESSION['username'] ?? ''));
if ($uid <= 0 && $username === '') {
    api_fail('Authentication required', 401);
}

/* ── 2. Key ─────────────────────────────────────────────────────────────── */
$moltbookApiKey = (string) api_get_secret('MOLTBOOK_API_KEY', '');
if ($moltbookApiKey === '') {
    api_fail('MOLTBOOK_API_KEY not configured', 503);
}

/* ── 3. Input — whitelist ────────────────────────────────────────────────
   $sort is used only as an array key, so no caller string ever reaches the
   upstream URL. An unrecognised tab falls back to 'feed'. */
$allowedSorts = ['feed' => 'hot', 'hot' => 'hot', 'new' => 'new'];
$sort = (string) ($_GET['sort'] ?? 'feed');
if (!isset($allowedSorts[$sort])) {
    $sort = 'feed';
}

if ($sort === 'feed') {
    /* Personalized feed — this is the call that must never be anonymous. */
    $url = 'https://www.moltbook.com/api/v1/feed?sort=hot&limit=20';
} else {
    $url = 'https://www.moltbook.com/api/v1/posts?sort=' . $allowedSorts[$sort] . '&limit=20';
}

/* ── 4. Fetch, bounded ──────────────────────────────────────────────────── */
$ch = curl_init($url);
if ($ch === false) {
    api_fail('Moltbook request could not be initialised', 502);
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $moltbookApiKey]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 12);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'LyralinkAI/1.0 (+https://lyralinkai.com)');

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false || $curlError !== '') {
    /* Do not relay curl_error() to the caller: it can disclose internal
       hostnames, ports and proxy configuration. */
    api_fail('Moltbook is unreachable', 502);
}

if ($httpCode < 200 || $httpCode >= 300) {
    /* Do not relay the upstream body either; an error page can echo the
       request, including the Authorization header on some gateways. */
    api_fail('Moltbook returned an error (HTTP ' . $httpCode . ')', 502);
}

$result = json_decode((string) $response, true);
if (!is_array($result)) {
    api_fail('Moltbook returned an unreadable response', 502);
}

/* Moltbook returns posts under different keys depending on endpoint. */
$posts = $result['posts'] ?? $result['data'] ?? [];
if (!is_array($posts)) {
    $posts = [];
}

if ($posts === [] && isset($result['error'])) {
    $message = $result['error']['message'] ?? 'API error';
    api_fail(is_string($message) ? $message : 'API error', 502);
}

echo json_encode(['posts' => array_values($posts)]);
