<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — SITE INDEX
   ══════════════════════════════════════════════════════════════════════════
   The marketing landing page is the front door, so this file serves it rather
   than duplicating 400 lines of markup that would immediately drift.

   pages/landing.php stays the single source. It is a complete document and
   every asset path in it is already absolute, so it renders correctly whether
   it is reached at / or at /pages/landing/. A canonical tag in its <head>
   points search engines at / to avoid treating the two as duplicates.

   What stays HERE, and why it cannot live in the landing page:
     * the reseller attribution cookie, which must be set before anything is
       rendered
     * the maintenance gate, which must run before output
     * the fork redirect
   These are entry-point concerns, and /pages/landing/ is reached directly by
   existing links, so moving them into landing.php would make them run twice on
   that route.

   The previous index.php was a separate 673-line marketing page with its own
   navigation. It is removed rather than kept as an alternate: one site, one
   front door.
   ══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/api/session_boot.php';

/* No session is created for an anonymous visitor. An existing one is continued
 * so a signed-in developer is recognised for the maintenance bypass. */
if (isset($_COOKIE['LYRASESS']) || isset($_COOKIE[session_name()])) {
    lyra_session_boot();
}

if (file_exists(__DIR__ . '/maintenance.flag') && !lyra_dev_preview()) {
    header('Location: /pages/maintenance.php');
    exit;
}

/* Reseller attribution. Must happen before any output. */
if (!empty($_GET['ref']) && preg_match('/^[a-f0-9]{48}$/', $_GET['ref'])) {
    setcookie('reseller_ref', $_GET['ref'], time() + 30 * 86400, '/', '', true, true);
}

/* A fork is a white-label deployment. Fork mode is configuration only - it used
 * to be inferred from the Host header, which the caller controls.
 *
 * This previously redirected to /pages/admin.php, which is admin-gated: a fork
 * without ALLOW_UNAUTH_FORK_ADMIN would be sent to a gated page that bounces
 * back to /, which redirects here. That is a loop. The chat application is the
 * product surface, it is ungated, and it is what a fork's visitor actually came
 * for. */
if (lyra_is_fork_mode()) {
    header('Location: /chat/');
    exit;
}

/* Landing is the single source for the front page. */
require __DIR__ . '/pages/landing.php';
