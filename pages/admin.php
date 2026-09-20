<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — RETIRED CONSOLE (redirect)
   ══════════════════════════════════════════════════════════════════════════
   This was the original administration console. Everything it offered is now in
   the dashboard:

     * the operator tools grid, with the tiles for the three retired features
       removed
     * the maintenance control, ported with the same API contract

   It is a redirect rather than a deletion so that a bookmark, a deep link or a
   link inside an older page still lands somewhere useful instead of a 404. The
   endpoints it used are untouched - api/admin.php now serves the dashboard.

   The page's own access check is preserved as a belt-and-braces measure: the
   redirect target is admin-gated, but a redirect that runs before any check
   would disclose that this path exists to an unauthenticated caller.
   ══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/../api/security.php';
lyra_session_boot();

if (!lyra_admin_ok()) {
    header('Location: /');
    exit;
}

header('Location: /pages/admin-dashboard/', true, 302);
exit;