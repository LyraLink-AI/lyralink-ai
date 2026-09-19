<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK TEAMS — RAIL AND ACTIVITY CHROME
   ══════════════════════════════════════════════════════════════════════════
   The Teams-page design shows four rail panels and an activity feed. This builds
   them from the tables that actually exist:

     Team              social_server_members joined to users
     Channels          social_channels, with real per-channel message counts
     System Status     status_services, the same source the status page uses
     Recent Activity   social_messages, with the sender and channel resolved

   Where a panel in the design has no source it renders an empty state rather
   than placeholder rows. The design also shows Upcoming Meetings, My Tasks and
   Quick Files; there is no meeting, task or file table in this schema, so those
   blocks are not fabricated - they say so on hover and the tabs that depend on
   them are marked as not built.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

require_once __DIR__ . '/lyra_chat_data.php';

if (!function_exists('lyra_tw_q')) {
    /** Query returning [] on failure; $db comes from the shared chat helper. */
    function lyra_tw_q(string $sql): array
    {
        return lyra_chat_q($sql);
    }
}

if (!function_exists('lyra_tw_when')) {
    /** Relative time, so an old entry never reads as recent. */
    function lyra_tw_when(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return '';
        }
        $t = strtotime($ts);
        if ($t === false) {
            return '';
        }
        $d = time() - $t;
        if ($d < 0)      { return date('M j', $t); }
        if ($d < 60)     { return 'just now'; }
        if ($d < 3600)   { return floor($d / 60) . 'm ago'; }
        if ($d < 86400)  { return floor($d / 3600) . 'h ago'; }
        if ($d < 604800) { return floor($d / 86400) . 'd ago'; }
        return date('M j', $t);
    }
}

if (!function_exists('lyra_tw_initials')) {
    function lyra_tw_initials(string $s): string
    {
        return lyra_chat_initials($s);
    }
}

if (!function_exists('lyra_tw_members')) {
    /** Real members, with role and a presence flag derived from last_seen_at. */
    function lyra_tw_members(int $limit = 12): array
    {
        $rows = lyra_tw_q(
            'SELECT m.user_id, m.role, m.last_seen_at, u.username '
            . 'FROM social_server_members m '
            . 'LEFT JOIN users u ON u.id = m.user_id '
            . 'ORDER BY m.role, m.user_id LIMIT ' . (int) $limit
        );
        $cut = date('Y-m-d H:i:s', time() - 300);
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            $name = trim((string) ($r['username'] ?? ''));
            if ($name === '') {
                $name = $uid > 0 ? 'user ' . $uid : 'unknown';
            }
            $ls = (string) ($r['last_seen_at'] ?? '');
            $out[] = [
                'name' => $name,
                'role' => ucfirst((string) ($r['role'] ?? 'member')),
                'online' => ($ls !== '' && $ls >= $cut),
                'seen' => $ls,
                'initials' => lyra_tw_initials($name),
            ];
        }
        return $out;
    }
}

if (!function_exists('lyra_tw_channels')) {
    /** Channels with real message counts. */
    function lyra_tw_channels(): array
    {
        $rows = lyra_tw_q(
            'SELECT c.id, c.name, c.type, c.topic, '
            . '(SELECT COUNT(*) FROM social_messages m '
            . ' JOIN social_channel_conversations cc ON cc.conversation_id = m.conversation_id '
            . ' WHERE cc.channel_id = c.id) AS n '
            . 'FROM social_channels c ORDER BY c.position, c.id'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'type' => (string) ($r['type'] ?? 'text'),
                'count' => (int) $r['n'],
            ];
        }
        return $out;
    }
}

if (!function_exists('lyra_tw_activity')) {
    /** Recent messages as feed entries, with sender and channel resolved. */
    function lyra_tw_activity(int $limit = 6): array
    {
        $rows = lyra_tw_q(
            'SELECT m.id, m.content, m.message_type, m.created_at, m.sender_user_id, '
            . 'c.name AS channel, u.username '
            . 'FROM social_messages m '
            . 'LEFT JOIN social_channel_conversations cc ON cc.conversation_id = m.conversation_id '
            . 'LEFT JOIN social_channels c ON c.id = cc.channel_id '
            . 'LEFT JOIN users u ON u.id = m.sender_user_id '
            . 'ORDER BY m.id DESC LIMIT ' . (int) $limit
        );
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['sender_user_id'] ?? 0);
            $who = trim((string) ($r['username'] ?? ''));
            if ($who === '') {
                $who = $uid > 0 ? 'user ' . $uid : 'unknown';
            }
            $body = trim((string) ($r['content'] ?? ''));
            $type = (string) ($r['message_type'] ?? 'text');
            if ($body === '' && $type === 'attachment') {
                $body = 'shared an attachment';
            } elseif ($body === '') {
                $body = 'posted a message';
            }
            $out[] = [
                'id' => (int) $r['id'],
                'who' => $who,
                'initials' => lyra_tw_initials($who),
                'channel' => (string) ($r['channel'] ?? ''),
                'body' => $body,
                'when' => lyra_tw_when((string) ($r['created_at'] ?? '')),
            ];
        }
        return $out;
    }
}

if (!function_exists('lyra_tw_system_status')) {
    /** Service health, same source as the public status page. */
    function lyra_tw_system_status(int $limit = 6): array
    {
        $rows = lyra_tw_q('SELECT name, status FROM status_services ORDER BY id LIMIT ' . (int) $limit);
        $out = [];
        foreach ($rows as $r) {
            $st = strtolower((string) ($r['status'] ?? ''));
            $out[] = [
                'name' => (string) $r['name'],
                'status' => $st,
                'ok' => $st === 'operational' || $st === '',
            ];
        }
        return $out;
    }
}

/* ── Renderers ───────────────────────────────────────────────────────────── */

if (!function_exists('lyra_tw_team_panel')) {
    function lyra_tw_team_panel(): string
    {
        $members = lyra_tw_members();
        $rows = '';
        foreach ($members as $m) {
            $title = $m['online']
                ? 'Active in the last 5 minutes'
                : ($m['seen'] !== '' ? 'Last seen ' . lyra_tw_when($m['seen']) : 'No presence recorded');
            $dot = $m['online']
                ? '<span class="ly-dot ly-dot-online"></span>'
                : '<span class="ly-dot" style="background:var(--ly-text-4)"></span>';
            $rows .= '<div class="lyra-tw-member">'
                   . '<span class="lyra-tw-av">' . htmlspecialchars($m['initials']) . '</span>'
                   . '<span class="lyra-tw-mmeta"><b>' . htmlspecialchars($m['name']) . '</b>'
                   . '<em>' . htmlspecialchars($m['role']) . '</em></span>'
                   . '<span title="' . htmlspecialchars($title, ENT_QUOTES) . '">' . $dot . '</span>'
                   . '</div>';
        }
        if ($rows === '') {
            $rows = '<div class="lyra-tw-empty">No members recorded.</div>';
        }
        return '<section class="lyra-tw-panel">'
             . '<div class="lyra-tw-phead"><h2>Team</h2>'
             . '<span class="lyra-tw-badge">' . count($members) . '</span></div>'
             . '<div class="lyra-tw-pbody">'
             . '<input class="lyra-tw-search" placeholder="Search team members&hellip;" disabled '
             . 'title="Member search is not wired up yet">'
             . $rows . '</div></section>';
    }
}

if (!function_exists('lyra_tw_channels_panel')) {
    function lyra_tw_channels_panel(): string
    {
        $chans = lyra_tw_channels();
        $rows = '';
        foreach ($chans as $c) {
            $hash = $c['type'] === 'voice' ? '&#128266;' : '#';
            $rows .= '<a class="lyra-tw-chan" href="?channel=' . $c['id'] . '">'
                   . '<span class="lyra-tw-hash">' . $hash . '</span>'
                   . '<span class="ly-truncate">' . htmlspecialchars($c['name']) . '</span>'
                   . '<span class="lyra-tw-badge">' . $c['count'] . '</span></a>';
        }
        if ($rows === '') {
            $rows = '<div class="lyra-tw-empty">No channels.</div>';
        }
        return '<section class="lyra-tw-panel">'
             . '<div class="lyra-tw-phead"><h2>Channels</h2>'
             . '<span class="lyra-tw-badge">' . count($chans) . '</span></div>'
             . '<div class="lyra-tw-pbody">'
             . '<input class="lyra-tw-search" placeholder="Search channels&hellip;" disabled '
             . 'title="Channel search is not wired up yet">'
             . $rows . '</div></section>';
    }
}

if (!function_exists('lyra_tw_status_panel')) {
    function lyra_tw_status_panel(): string
    {
        $svc = lyra_tw_system_status();
        $allOk = true;
        foreach ($svc as $s) {
            if (!$s['ok']) {
                $allOk = false;
            }
        }
        $rows = '';
        foreach ($svc as $s) {
            $label = $s['ok'] ? 'Online' : ucfirst($s['status']);
            $cls = $s['ok'] ? 'is-on' : 'is-warn';
            $rows .= '<div class="lyra-tw-svcrow">'
                   . '<span class="lyra-tw-svcdot ' . ($s['ok'] ? 'is-on' : 'is-warn') . '"></span>'
                   . '<span class="ly-truncate">' . htmlspecialchars($s['name']) . '</span>'
                   . '<em class="' . $cls . '">' . htmlspecialchars($label) . '</em></div>';
        }
        if ($rows === '') {
            $rows = '<div class="lyra-tw-empty">Service status unavailable.</div>';
        }
        $head = $allOk && $svc !== []
            ? '<span class="lyra-tw-svcdot is-on"></span>All systems operational'
            : ($svc === [] ? 'Status unavailable' : 'Some services degraded');
        return '<section class="lyra-tw-panel">'
             . '<div class="lyra-tw-phead"><h2>System Status</h2>'
             . '<a class="lyra-tw-link" href="/pages/status">View all &rarr;</a></div>'
             . '<div class="lyra-tw-pbody">'
             . '<div class="lyra-tw-svchead">' . $head . '</div>'
             . $rows . '</div></section>';
    }
}

if (!function_exists('lyra_tw_activity_panel')) {
    function lyra_tw_activity_panel(): string
    {
        $items = lyra_tw_activity();
        $rows = '';
        foreach ($items as $a) {
            $where = $a['channel'] !== '' ? ' in <b>#' . htmlspecialchars($a['channel']) . '</b>' : '';
            $rows .= '<div class="lyra-tw-actrow">'
                   . '<span class="lyra-tw-av">' . htmlspecialchars($a['initials']) . '</span>'
                   . '<div class="lyra-tw-actmeta">'
                   . '<span class="lyra-tw-actline"><b>' . htmlspecialchars($a['who']) . '</b>' . $where . '</span>'
                   . '<em class="ly-truncate" title="' . htmlspecialchars($a['body'], ENT_QUOTES) . '">'
                   . htmlspecialchars($a['body']) . '</em>'
                   . '</div>'
                   . '<span class="lyra-tw-when">' . htmlspecialchars($a['when']) . '</span>'
                   . '</div>';
        }
        if ($rows === '') {
            $rows = '<div class="lyra-tw-empty">No recent activity.</div>';
        }
        return '<section class="lyra-tw-panel">'
             . '<div class="lyra-tw-phead"><h2>Recent Activity</h2>'
             . '<a class="lyra-tw-link" href="#feed">View all &rarr;</a></div>'
             . '<div class="lyra-tw-pbody">' . $rows . '</div></section>';
    }
}

if (!function_exists('lyra_tw_feed_head')) {
    /** Tabs above the activity feed. Only the first has content behind it. */
    function lyra_tw_feed_head(): string
    {
        return '<div class="lyra-tw-feedhead">'
             . '<div class="lyra-tw-tabs" role="tablist">'
             . '<button type="button" class="lyra-tw-tab is-active" role="tab" aria-selected="true">Recent Activity</button>'
             . '<button type="button" class="lyra-tw-tab" role="tab" aria-selected="false" disabled '
             . 'title="No task source exists in this schema yet">My Tasks</button>'
             . '<button type="button" class="lyra-tw-tab" role="tab" aria-selected="false" disabled '
             . 'title="Pinning is not built yet">Pinned</button>'
             . '</div>'
             . '<a class="lyra-tw-link" href="#feed">View All &rarr;</a>'
             . '</div>';
    }
}

if (!function_exists('lyra_tw_compose_chips')) {
    /** The design's attachment and emoji actions on the composer. */
    function lyra_tw_compose_chips(): string
    {
        $icon = function (string $d) {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
                 . 'stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"/></svg>';
        };
        $note = 'Posting is not wired to the API yet';
        return '<div class="lyra-tw-chips">'
             . '<button type="button" class="lyra-tw-chip" disabled title="' . $note . '">'
             . '<span class="lyra-tw-plus">+</span></button>'
             . '<span class="lyra-tw-chipspacer"></span>'
             . '<button type="button" class="lyra-tw-chip" disabled title="' . $note . '" aria-label="Emoji">'
             . $icon('M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM9 10h.01M15 10h.01M8.5 14.5a4 4 0 0 0 7 0') . '</button>'
             . '<button type="button" class="lyra-tw-chip" disabled title="' . $note . '" aria-label="Attach">'
             . $icon('M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7') . '</button>'
             . '</div>';
    }
}
