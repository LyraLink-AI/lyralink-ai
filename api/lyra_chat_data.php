<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK CHAT — DATA PROVIDERS
   ══════════════════════════════════════════════════════════════════════════
   Supplies the real values the Chat-Page design shows in its rail and top bar.
   Every function here reads something that exists; none of them invent a number.
   Where a source genuinely does not exist, the function returns null and the
   caller renders an explicit empty state instead of a plausible-looking figure.

   This exists because the design displays figures the original chat page had no
   source for. Building the source was the right fix; hardcoding the design's
   illustrative values was not.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/security.php';

if (!function_exists('lyra_chat_db')) {
    /** Shared connection. Returns null rather than throwing, so a database
     *  outage degrades the rail to empty states instead of breaking the page. */
    function lyra_chat_db(): ?mysqli
    {
        static $db = false;
        if ($db !== false) {
            return $db;
        }
        try {
            $cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
            $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
            $db = $conn->connect_error ? null : $conn;
        } catch (\Throwable $e) {
            $db = null;
        }
        return $db;
    }
}

if (!function_exists('lyra_chat_q')) {
    /** Run a query, returning [] on any failure. Callers never see an exception. */
    function lyra_chat_q(string $sql): array
    {
        $db = lyra_chat_db();
        if ($db === null) {
            return [];
        }
        try {
            $res = $db->query($sql);
            if (!$res) {
                return [];
            }
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('lyra_chat_viewer')) {
    /**
     * Who is looking at the page. The design shows an avatar, display name and
     * plan, so the plan is read from the users table rather than assumed.
     * Returns null when signed out, so the caller can show the sign-in control.
     */
    function lyra_chat_viewer(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $cached = null;

        $username = (string) ($_SESSION['username'] ?? '');
        if ($username === '') {
            return null;
        }

        $rows = lyra_chat_q(
            "SELECT username, plan, is_admin FROM users WHERE username = '"
            . lyra_chat_escape($username) . "' LIMIT 1"
        );
        if (!$rows) {
            // Session exists but the row is gone; still show a name rather than
            // pretending the visitor is anonymous.
            $cached = ['username' => $username, 'plan' => '', 'is_admin' => false, 'initials' => lyra_chat_initials($username)];
            return $cached;
        }
        $r = $rows[0];
        $cached = [
            'username' => (string) $r['username'],
            'plan'     => (string) ($r['plan'] ?? ''),
            'is_admin' => (int) ($r['is_admin'] ?? 0) === 1,
            'initials' => lyra_chat_initials((string) $r['username']),
        ];
        return $cached;
    }
}

if (!function_exists('lyra_chat_escape')) {
    function lyra_chat_escape(string $s): string
    {
        $db = lyra_chat_db();
        return $db ? $db->real_escape_string($s) : preg_replace('/[^A-Za-z0-9_.@-]/', '', $s);
    }
}

if (!function_exists('lyra_chat_initials')) {
    function lyra_chat_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'U';
        }
        $parts = preg_split('/[\s._-]+/', $name) ?: [];
        $a = strtoupper(substr($parts[0] ?? '', 0, 1));
        $b = count($parts) > 1 ? strtoupper(substr($parts[1], 0, 1)) : strtoupper(substr($parts[0] ?? '', 1, 1));
        return ($a . $b) !== '' ? ($a . $b) : 'U';
    }
}

if (!function_exists('lyra_chat_uptime')) {
    /**
     * Fleet uptime across the trailing window, rounded to two decimals.
     *
     * status_uptime holds one row per service per day. Services added later have
     * fewer rows, so a plain AVG over all rows would be biased toward whichever
     * service has the longest history. Each service's own mean is averaged
     * instead, which weights every service equally.
     */
    function lyra_chat_uptime(int $days = 30): ?float
    {
        static $cache = [];
        if (isset($cache[$days])) {
            return $cache[$days];
        }
        $rows = lyra_chat_q(
            'SELECT service_id, AVG(uptime_pct) AS avg_pct FROM status_uptime '
            . 'WHERE date >= DATE_SUB(CURDATE(), INTERVAL ' . (int) $days . ' DAY) '
            . 'GROUP BY service_id'
        );
        if (!$rows) {
            return $cache[$days] = null;
        }
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) $r['avg_pct'];
        }
        return $cache[$days] = round($sum / count($rows), 2);
    }
}

if (!function_exists('lyra_chat_services')) {
    /** Live service health, for the System Status readout. */
    function lyra_chat_services(): array
    {
        $rows = lyra_chat_q('SELECT id, name, status FROM status_services ORDER BY id');
        $out = ['total' => count($rows), 'operational' => 0, 'degraded' => []];
        foreach ($rows as $r) {
            $st = strtolower((string) ($r['status'] ?? ''));
            if ($st === 'operational') {
                $out['operational']++;
            } else {
                $out['degraded'][] = ['name' => (string) $r['name'], 'status' => $st];
            }
        }
        return $out;
    }
}

if (!function_exists('lyra_chat_tools')) {
    /**
     * The tool set, and which of them are actually wired up.
     *
     * The three identifiers marked true are the ones api/lib/chat/orchestrator.php
     * can genuinely select: web.search, shell.execute and server.inspect. Code
     * checks and image generation are driven by the chat's own tool toggles.
     * Everything else in the design's list has no implementation behind it, so it
     * is reported as unavailable rather than shown as active.
     *
     * Returning this honestly matters more than filling the panel: a rail that
     * claims nine live tools when three exist is the same class of error as a
     * reply that claims it ran something it did not.
     */
    function lyra_chat_tools(): array
    {
        return [
            ['Web Search',         true,  'orchestrator: web.search'],
            ['Code Analysis',      true,  'task toggle: code checks'],
            ['GitHub',             false, 'not implemented'],
            ['Database',           false, 'not implemented'],
            ['Docker',             false, 'not implemented'],
            ['Image Generation',   true,  'task toggle: generate image'],
            ['Terminal',           true,  'orchestrator: shell.execute'],
            ['Browser Automation', false, 'not implemented'],
            ['File System',        true,  'orchestrator: server.inspect'],
            ['More Tools',         null,  'see api docs'],
        ];
    }
}

if (!function_exists('lyra_chat_tool_counts')) {
    function lyra_chat_tool_counts(): array
    {
        $tools = lyra_chat_tools();
        $active = 0;
        $known = 0;
        foreach ($tools as $t) {
            if ($t[1] === true) {
                $active++;
            }
            if ($t[1] !== null) {
                $known++;
            }
        }
        return ['active' => $active, 'known' => $known, 'listed' => count($tools)];
    }
}

if (!function_exists('lyra_chat_model_info')) {
    /**
     * The active model. The design shows a name and a capability line; the
     * capability line is derived from what the runtime reports rather than
     * asserted, so it stays true if the model changes.
     */
    function lyra_chat_model_info(): array
    {
        $name = 'Lyra-1';
        $detail = 'Local model';

        // The chat page publishes its model selection to the client; mirror the
        // same env value here so server and client agree.
        $envName = trim((string) (getenv('MODEL_ROUTER_DEFAULT') ?: ''));
        if ($envName !== '') {
            $name = 'Lyra-1 (' . (stripos($envName, 'latest') !== false ? 'Latest' : $envName) . ')';
        }
        $ctx = trim((string) (getenv('LOCAL_LLM_CONTEXT') ?: ''));
        if ($ctx !== '' && is_numeric($ctx)) {
            $detail = 'Local model &middot; ' . round(((int) $ctx) / 1024) . 'K context';
        }
        return ['name' => $name, 'detail' => $detail];
    }
}

