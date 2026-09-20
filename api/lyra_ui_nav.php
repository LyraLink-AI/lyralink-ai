<?php
/* Shared navigation for the new interface (the "New UI" pages).
 *
 * Why this exists: the new pages were built standalone, with every nav item
 * pointing at "#". That meant the new interface was unreachable from the rest
 * of the site and did not link to itself either — you could open one page and
 * get stuck on it. This file is the single place that defines that navigation,
 * so it cannot drift between the six pages.
 *
 * It follows the existing convention rather than inventing one: pages already
 * `require_once __DIR__ . '/../api/security.php'`, so a sibling file in api/ is
 * the natural home.
 *
 * Admin detection mirrors pages/admin.php (session username) but resolves the
 * flag from `users.is_admin` instead of hardcoding a username, so any account
 * flagged as an administrator is handled correctly.
 */
declare(strict_types=1);

/* LYRA_UI_VIEWER_HELPER -- idempotency marker; this string is introduced only by
 * this block, never present in the text it replaced, so a re-run is a no-op.
 *
 * This library calls api_db_config(), which lives in security.php. It previously
 * relied on the caller having included that first. A shared library must declare
 * its own dependencies: when it does not, an undefined function surfaces as a
 * fatal that a surrounding try/catch can swallow into a silent empty result.
 * require_once is a no-op when the caller already loaded it. */
require_once __DIR__ . '/security.php';

if (!function_exists('lyra_ui_viewer_row')) {
    /**
     * The users row for the signed-in session, cached per request.
     *
     * Returns null when there is no session, no matching row, or the lookup
     * fails. Callers must treat null as "unknown viewer", never as an admin, and
     * must not fall back to a hardcoded name.
     */
    function lyra_ui_viewer_row(): ?array
    {
        static $row = false;   // false = not looked up yet, null = looked up empty
        if ($row !== false) {
            return $row;
        }
        $row = null;

        $user = trim((string) ($_SESSION['username'] ?? ''));
        if ($user === '') {
            return $row;
        }

        try {
            $cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
            $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
            if ($db->connect_error) {
                return $row;
            }
            $st = $db->prepare('SELECT username, is_admin, plan FROM users WHERE username = ? LIMIT 1');
            if ($st) {
                $st->bind_param('s', $user);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
            }
            $db->close();
        } catch (\Throwable $e) {
            // Never let a failed lookup invent an identity; fall through to guest.
            $row = null;
        }

        return $row;
    }
}

if (!function_exists('lyra_ui_viewer')) {
    /**
     * Who is viewing this page. The single source of truth for the new UI's
     * identity chrome, so the top bar and any greeting cannot disagree.
     *
     * 'label' and 'initials' fall back to an explicit Guest rather than a name
     * taken from a template, because a wrong name shown as fact is worse than
     * an honest placeholder.
     */
    function lyra_ui_viewer(): array
    {
        static $viewer = null;
        if ($viewer !== null) {
            return $viewer;
        }

        $row  = lyra_ui_viewer_row();
        $sess = trim((string) ($_SESSION['username'] ?? ''));

        $name = $row !== null ? trim((string) ($row['username'] ?? '')) : '';
        if ($name === '') {
            $name = $sess;
        }

        $plan    = $row !== null ? trim((string) ($row['plan'] ?? '')) : '';
        $isGuest = ($name === '');

        $letters  = preg_replace('/[^A-Za-z]/', '', $name);
        $initials = $isGuest ? 'GU' : strtoupper(substr($letters !== '' ? $letters : 'U', 0, 2));

        $viewer = [
            'name'     => $name,
            'label'    => $isGuest ? 'Guest' : $name,
            'initials' => $initials,
            'plan'     => $plan,
            'is_guest' => $isGuest,
            'status'   => $isGuest
                ? 'Not signed in'
                : ($plan !== '' ? ucfirst($plan) . ' plan' : 'Online'),
        ];

        return $viewer;
    }
}

if (!function_exists('lyra_ui_is_admin')) {
    /**
     * True when the current session belongs to an administrator.
     * Reads the same cached row as lyra_ui_viewer(), so this costs no extra query.
     */
    function lyra_ui_is_admin(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $row = lyra_ui_viewer_row();
        $cached = $row !== null && (int) ($row['is_admin'] ?? 0) === 1;
        return $cached;
    }
}

if (!function_exists('lyra_ui_nav_items')) {
    /**
     * Every page in the new interface that the current viewer may open.
     * Admin-only destinations are omitted entirely rather than shown and then
     * bounced, because a link that redirects you away is worse than no link.
     */
    function lyra_ui_nav_items(): array
    {
        $items = [
            ['Chat',  '/chat/',                 'M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z'],
            ['Teams', '/pages/teams/',          'M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8'],
        ];

        if (lyra_ui_is_admin()) {
            $items[] = ['Admin',     '/pages/admin-dashboard/', 'M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z'];
            $items[] = ['Dev Stats', '/pages/dev-stats/',       'M4 20V10M10 20V4M16 20v-7M2 20h20'];
        }

        $items[] = ['Landing',      '/pages/landing/', 'M3 10.5 12 3l9 7.5V21H3z'];
        $items[] = ['Classic site', '/',               'M19 12H5M11 18l-6-6 6-6'];

        return $items;
    }
}

if (!function_exists('lyra_ui_nav_render')) {
    /** Render the new-interface nav. $active is the URL of the current page. */
    function lyra_ui_nav_render(string $active = ''): string
    {
        $out = '';
        foreach (lyra_ui_nav_items() as $it) {
            $label = (string) $it[0];
            $href  = (string) $it[1];
            $d     = (string) $it[2];
            $is    = ($active !== '' && $active === $href) ? ' is-active' : '';
            $out  .= '<a class="ly-navitem' . $is . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                   . '<svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                   . ' stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"/></svg>'
                   . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }
        return $out;
    }
}

if (!function_exists('lyra_ui_pending')) {
    /**
     * Markup for a nav item that is designed but not built yet.
     *
     * Deliberately not `href="#"`: that jumps the page to the top and looks
     * like a broken control. This is inert, unfocusable-by-tab-order only via
     * aria-disabled, and says so on hover.
     */
    function lyra_ui_pending(string $label, string $iconPath, string $reason = 'Not built yet'): string
    {
        return '<a class="ly-navitem is-pending" href="#" aria-disabled="true"'
             . ' title="' . htmlspecialchars($reason, ENT_QUOTES) . '" onclick="return false;">'
             . '<svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
             . ' stroke-linecap="round" stroke-linejoin="round"><path d="' . $iconPath . '"/></svg>'
             . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
}

if (!function_exists('lyra_ui_next_url')) {
    /** Where to send someone after sign-in so they land back where they were. */
    function lyra_ui_next_url(string $fallback = '/pages/chat-workspace/'): string
    {
        return '/pages/login.php?next=' . rawurlencode($fallback);
    }
}
