<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — HARDENED SESSION BOOTSTRAP
   ══════════════════════════════════════════════════════════════════════════
   One place that starts a session, so every entrypoint gets the same security
   properties. Before this existed, 44 files each called session_start() bare,
   and the effective configuration was php.ini defaults:

       session.cookie_httponly   = no value   -> cookie readable by JavaScript
       session.cookie_samesite   = no value   -> no CSRF mitigation
       session.cookie_secure     = 0          -> cookie could travel over HTTP
       session.use_strict_mode   = 0          -> uninitialised ids accepted

   Together those four mean any XSS anywhere is full account takeover, and a
   session id an attacker chooses before login is accepted afterwards.

   USAGE — replace `session_start();` with:

       require_once __DIR__ . '/session_boot.php';   // or ../api/ from pages/
       lyra_session_boot();

   Calling it twice is safe; it returns early when a session is already active.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

if (!function_exists('lyra_session_is_https')) {
    /**
     * True when the request reached us over TLS.
     *
     * The stack is nginx -> Apache -> PHP-FPM (Plesk), and nginx terminates TLS,
     * so PHP often sees plain HTTP. HTTP_X_FORWARDED_PROTO is the reliable
     * signal; the other two are fallbacks for a direct connection.
     */
    function lyra_session_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto === 'https') {
            return true;
        }
        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}

if (!function_exists('lyra_session_is_widget_embed')) {
    /**
     * True when this request is the embeddable chat widget.
     *
     * This matters for SameSite. The widget is built to be framed by other
     * websites - .htaccess sets `Content-Security-Policy: frame-ancestors *` for
     * /chat precisely so customers can embed it, and assets/js/widget.js
     * injects an iframe pointing there. An iframe on someone else's domain is a
     * cross-site context, so a SameSite=Lax cookie is NOT sent inside it and the
     * widget would lose its session.
     *
     * So the embed path gets SameSite=None, which browsers only honour together
     * with Secure - hence the requirement below. Everything else stays Lax,
     * which is the stronger default.
     */
    function lyra_session_is_widget_embed(): bool
    {
        // Apache sets this via the RewriteRule env flag in .htaccess.
        if ((string) ($_SERVER['REDIRECT_LYRA_WIDGET_EMBED'] ?? '') === '1') {
            return true;
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return (bool) preg_match('#^/chat(?:/|$)#', $path);
    }
}

if (!function_exists('lyra_session_boot')) {
    function lyra_session_boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // A fixed name avoids collisions with any other application on the box.
        if (session_name() === 'PHPSESSID') {
            session_name('LYRASESS');
        }

        ini_set('session.use_strict_mode', '1');   // reject unknown session ids
        ini_set('session.use_only_cookies', '1');  // never accept an id from the URL
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        $secure = lyra_session_is_https();
        $embed  = lyra_session_is_widget_embed();
        $sameSite = ($embed && $secure) ? 'None' : 'Lax';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        session_start();
    }
}

if (!function_exists('lyra_session_elevate')) {
    /**
     * Regenerate the session id at any change of privilege.
     *
     * Call this on successful login, on successful two-factor verification, and
     * on logout. Without it the id an anonymous visitor was given before logging
     * in remains valid afterwards, which is session fixation: an attacker who can
     * plant an id in a victim's browser is then holding an authenticated session.
     *
     * `true` deletes the old session file so a pre-login id cannot be replayed.
     */
    function lyra_session_elevate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

/* ── CSRF ────────────────────────────────────────────────────────────────
   A same-site cookie already blocks most cross-site form posts, but that is a
   single control and it does not cover the widget path (SameSite=None). These
   helpers let a state-changing action require a token as well.

   Design: one token per session, compared with hash_equals. Not rotated per
   request, because that breaks any page with two forms open; rotation happens
   at privilege change via lyra_session_elevate().
   ──────────────────────────────────────────────────────────────────────── */

if (!function_exists('lyra_csrf_secret')) {
    function lyra_csrf_secret(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }
}

if (!function_exists('lyra_csrf_field')) {
    /** Hidden input to drop into a form. */
    function lyra_csrf_field(): string
    {
        $t = lyra_csrf_secret();
        return $t === ''
            ? ''
            : '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
    }
}

if (!function_exists('lyra_csrf_verify')) {
    /**
     * Compare a submitted token. Accepts it from POST or the X-CSRF-Token header
     * so fetch()-based clients can send it either way.
     */
    function lyra_csrf_verify(): bool
    {
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        if ($expected === '') {
            return false;
        }
        $given = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($given === '') {
            return false;
        }
        return hash_equals($expected, $given);
    }
}

if (!function_exists('lyra_csrf_require')) {
    /**
     * Hard gate for a state-changing action. Emits JSON and exits when the token
     * is missing or wrong.
     *
     * Deliberately opt-in rather than forced on every POST: turning it on
     * globally would reject requests from every existing client until each one
     * was updated, which on a live site means breaking working flows. It is
     * applied to the actions that change credentials or permissions.
     */
    function lyra_csrf_require(): void
    {
        if (lyra_csrf_verify()) {
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(403);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Request rejected: missing or invalid CSRF token.',
            'code'    => 'CSRF_FAILED',
        ]);
        exit;
    }
}
