<?php
/**
 * Public email-capture endpoint (launch list).
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this, the site had no way to capture an email address anywhere. For a
 * launch, the subscriber list is the only marketing asset that compounds and
 * that we own outright, so it is worth having in place before any launch
 * happens rather than after.
 *
 * DELIBERATELY PUBLIC
 * -------------------
 * There is no session check, and that is intentional: the whole point is to
 * capture interest from people who are not users yet. The controls that
 * actually matter for a public write endpoint are enforced below.
 *
 * HARDENING
 * ---------
 *  - POST only, same-origin enforced (api_enforce_post_and_origin_for_actions)
 *  - honeypot field: filled only by bots; such requests report success but
 *    write nothing, so a bot learns nothing about being detected
 *  - email length + format validated before it reaches SQL
 *  - all SQL is prepared statements; no string interpolation of input
 *  - per-IP rate limit backed by a table (not a session, which is forgeable)
 *  - client IP is stored ONLY as a salted HMAC, never raw. An unsalted hash of
 *    an IPv4 address is trivially reversible (the entire space is 2^32), so
 *    the salt is what makes the hashing meaningful rather than decorative.
 *    Storing raw IPs in a marketing table would also contradict the privacy
 *    posture the product claims.
 *  - responses never reveal whether an address was already on the list, so the
 *    endpoint cannot be used to enumerate subscribers
 */

require_once __DIR__ . '/session_boot.php';

/* security.php defines api_json_headers(), api_fail(),
 * api_enforce_post_and_origin_for_actions() and api_get_secret().
 *
 * session_boot.php loads it only on code paths that involve a session, so a
 * fully ANONYMOUS request never gets it. That makes this require mandatory for
 * any public endpoint rather than optional: without it the request dies with
 * "Call to undefined function api_json_headers()" and an opaque 500, which is
 * exactly what happened on the first deploy of this file. */
require_once __DIR__ . '/security.php';

api_json_headers();

/* Enforces POST + same-origin for this action. Named action so the guard has
 * something to match on. */
api_enforce_post_and_origin_for_actions(['subscribe']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_fail('Method not allowed', 405);
}

/**
 * Read a scalar POST field defensively.
 *
 * Never trusts the shape of $_POST: a client may send arrays (which would make
 * trim() fatal on some builds) or control characters. Rejects non-strings
 * rather than coercing them.
 */
function subscribe_field(string $key, int $maxLength): string
{
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        return '';
    }
    $value = trim($_POST[$key]);
    // Strip control characters, including NUL, CR and LF.
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    if (!is_string($value)) {
        return '';
    }
    return mb_substr($value, 0, $maxLength);
}

/* Honeypot. Named so that browser autofill will not populate it. */
$honeypot = subscribe_field('lyra_hp', 200);

if ($honeypot !== '') {
    // Behave exactly like success so the caller cannot tell it was rejected.
    echo json_encode(['success' => true, 'message' => 'You are on the list.']);
    exit;
}

$email  = subscribe_field('email', 254);
$source = subscribe_field('source', 64);
$refRaw = subscribe_field('ref', 255);

if ($source === '') {
    $source = 'landing';
}

if ($email === '') {
    api_fail('Please enter an email address.', 422);
}

$email = mb_strtolower($email);

if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_fail('That does not look like a valid email address.', 422);
}

[$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');

if ($localPart === '' || $domain === '' || strpos($domain, '.') === false) {
    api_fail('That does not look like a valid email address.', 422);
}

// Require a plausible TLD. filter_var accepts some forms that are not useful
// as contact addresses (e.g. trailing-dot or single-label domains).
if (!preg_match('/\.[a-z]{2,}$/i', $domain)) {
    api_fail('That does not look like a valid email address.', 422);
}

/* ── database ──────────────────────────────────────────────────────────── */

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('Signup is temporarily unavailable.', 503);
}
$db->set_charset('utf8mb4');

$db->query(
    "CREATE TABLE IF NOT EXISTS marketing_subscribers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(254) NOT NULL,
        email_domain VARCHAR(190) NOT NULL DEFAULT '',
        source VARCHAR(64) NOT NULL DEFAULT 'landing',
        status ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed',
        ip_hash CHAR(64) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        referrer VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        unsubscribed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_subscriber_email (email),
        KEY idx_subscriber_status (status),
        KEY idx_subscriber_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$db->query(
    "CREATE TABLE IF NOT EXISTS marketing_subscribe_limits (
        ip_hash CHAR(64) NOT NULL,
        window_start DATETIME NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (ip_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* ── client identity (hashed, never raw) ───────────────────────────────── */

$salt = (string) (api_get_secret('SUBSCRIBE_IP_SALT') ?? '');
if ($salt === '') {
    // Fall back to an existing server secret so the hash is still salted if the
    // dedicated setting has not been filled in yet.
    $salt = (string) (api_get_secret('BOT_SECRET_KEY') ?? '');
}

$rawIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = ($salt !== '' && $rawIp !== '') ? hash_hmac('sha256', $rawIp, $salt) : '';

/* ── rate limit ────────────────────────────────────────────────────────── */

$limitPerHour = (int) (api_get_secret('SUBSCRIBE_RATE_LIMIT_PER_HOUR') ?? 5);
if ($limitPerHour < 1 || $limitPerHour > 100) {
    $limitPerHour = 5;
}

if ($ipHash !== '') {
    /* The "is this attempt inside the window" test is done ENTIRELY in SQL,
     * deliberately.
     *
     * Doing it in PHP mixed two clocks: MySQL wrote window_start via NOW()
     * (server local time) while PHP compared it against time() interpreted in
     * PHP's timezone. With a 4-hour offset the computed age was always larger
     * than an hour, so the limiter took the "expired" branch on EVERY request
     * and never accumulated - six rapid posts produced a single attempt and no
     * limit was ever enforced. Keeping both sides in MySQL's own clock removes
     * the whole class of bug rather than patching one instance of it. */
    $inWindowAttempts = null;

    $stmt = $db->prepare(
        'SELECT attempts FROM marketing_subscribe_limits
          WHERE ip_hash = ? AND window_start > (NOW() - INTERVAL 1 HOUR)
          LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('s', $ipHash);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $inWindowAttempts = (int) $row['attempts'];
        }
    }

    if ($inWindowAttempts !== null && $inWindowAttempts >= $limitPerHour) {
        api_fail('Too many attempts. Please try again later.', 429);
    }

    if ($inWindowAttempts !== null) {
        $stmt = $db->prepare('UPDATE marketing_subscribe_limits SET attempts = attempts + 1 WHERE ip_hash = ?');
    } else {
        /* No row for this caller, or the previous window has expired. The
         * ON DUPLICATE branch covers the expired case, which is why the row is
         * not simply updated above. */
        $stmt = $db->prepare(
            'INSERT INTO marketing_subscribe_limits (ip_hash, window_start, attempts)
             VALUES (?, NOW(), 1)
             ON DUPLICATE KEY UPDATE attempts = 1, window_start = NOW()'
        );
    }
    if ($stmt) {
        $stmt->bind_param('s', $ipHash);
        $stmt->execute();
        $stmt->close();
    }
}

/* ── store ─────────────────────────────────────────────────────────────── */

$userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$referrerHost = '';
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($referer !== '') {
    $host = parse_url($referer, PHP_URL_HOST);
    if (is_string($host)) {
        $referrerHost = mb_substr($host, 0, 255);
    }
}
// Prefer an explicit ref= parameter if the form supplies one.
if ($refRaw !== '') {
    $referrerHost = mb_substr($refRaw, 0, 255);
}

/* ON DUPLICATE KEY UPDATE email = email is deliberately a no-op:
 * re-submitting an address must never resurrect a row the person explicitly
 * unsubscribed from, and must never overwrite the original source or date. */
$stmt = $db->prepare(
    "INSERT INTO marketing_subscribers
        (email, email_domain, source, status, ip_hash, user_agent, referrer)
     VALUES (?, ?, ?, 'subscribed', ?, ?, ?)
     ON DUPLICATE KEY UPDATE email = email"
);

if (!$stmt) {
    api_fail('Signup is temporarily unavailable.', 503);
}

$stmt->bind_param('ssssss', $email, $domain, $source, $ipHash, $userAgent, $referrerHost);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    api_fail('Signup is temporarily unavailable.', 503);
}

/* Same response whether newly added or already present - see note at the top. */
echo json_encode(['success' => true, 'message' => 'You are on the list.']);
