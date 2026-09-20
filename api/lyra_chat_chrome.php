<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK CHAT — CHROME PARTIALS
   ══════════════════════════════════════════════════════════════════════════
   Every surrounding element of the Chat-Page design, in one place.

   chat.php is the working application — ~8,000 lines of JavaScript bound to 96
   element ids. Keeping the decoration out of it means the application stays
   readable and this chrome can be changed or tested without touching behaviour.

   Data comes from api/lyra_chat_data.php. Where a source exists the real value
   is shown; where it does not, the element renders an explicit empty state. The
   design's own figures (12 active tools, 99.98% uptime, a completed deployment
   with per-step timings) are illustrative, so they are not reproduced as fact.

   No id used by the chat's JavaScript is defined or altered here.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

require_once __DIR__ . '/lyra_chat_data.php';

if (!function_exists('lyra_chat_icon')) {
    function lyra_chat_icon(string $d, float $w = 1.7, string $cls = ''): string
    {
        $c = $cls !== '' ? ' class="' . $cls . '"' : '';
        return '<svg' . $c . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
             . $w . '" stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"/></svg>';
    }
}

if (!function_exists('lyra_chat_icons')) {
    function lyra_chat_icons(): array
    {
        return [
            'bolt'   => 'M13 2 4 14h7l-1 8 9-12h-7z',
            'conv'   => 'M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z',
            'proj'   => 'M3 7h7l2 2h9v10H3z',
            'auto'   => 'M13 2 4 14h7l-1 8 9-12h-7z',
            'files'  => 'M6 3h8l4 4v14H6z',
            'know'   => 'M4 5h16v14H4zM4 9h16',
            'set'    => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
            'deploy' => 'M12 3v12M8 11l4 4 4-4M5 21h14',
            'code'   => 'm8 6-6 6 6 6M16 6l6 6-6 6',
            'mon'    => 'M3 12h4l3 8 4-16 3 8h4',
            'res'    => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3',
            'doc'    => 'M6 3h8l4 4v14H6zM9 12h6M9 16h4',
            'think'  => 'M12 3a6 6 0 0 0-4 10v3h8v-3a6 6 0 0 0-4-10Z',
            'deliv'  => 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
            'bell'   => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
            'sun'    => 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
            'check'  => 'M20 6 9 17l-5-5',
            'tag'    => 'M20.6 13.4 12 22l-9-9V3h10z',
            'mic'    => 'M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3ZM5 11a7 7 0 0 0 14 0M12 18v4',
            'paper'  => 'M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7',
            'img'    => 'M3 4h18v16H3zM8.5 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM21 15l-5-5L5 20',
            'chev'   => 'm6 9 6 6 6-6',
            'gh'     => 'M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7C6.7 19.7 6.1 17.8 6.1 17.8c-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.1-.2-4.3-1-4.3-4.6 0-1 .4-1.9 1-2.5-.1-.2-.4-1.2.1-2.5 0 0 .8-.3 2.6 1a9 9 0 0 1 4.7 0c1.8-1.3 2.6-1 2.6-1 .5 1.3.2 2.3.1 2.5.6.7 1 1.6 1 2.5 0 3.6-2.2 4.4-4.3 4.6.3.3.6.9.6 1.8v2.6c0 .3.2.6.7.5A10 10 0 0 0 12 2Z',
            'docker' => 'M4 11h3v3H4zM8 11h3v3H8zM12 11h3v3h-3zM8 7h3v3H8zM12 7h3v3h-3zM16 11h3v3h-3zM3 15h16a6 6 0 0 1-6 5H7a5 5 0 0 1-4-5Z',
            'term'   => 'm4 7 4 5-4 5M12 17h8',
            'db'     => 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3ZM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6',
            'browse' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z',
            'grid'   => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
            'src'    => 'M6 3h8l4 4v14H6zM14 3v4h4',
            'shield' => 'M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z',
        ];
    }
}

/* ── Left rail ───────────────────────────────────────────────────────────── */

if (!function_exists('lyra_chat_brand')) {
    function lyra_chat_brand(): string
    {
        return '<a class="lyra-brand" href="/">'
             . '<span class="lyra-brand-mark">' . lyra_chat_icon(lyra_chat_icons()['bolt'], 2) . '</span>'
             . '<span class="lyra-brand-text">'
             . '<b>Lyralink</b>'
             . '<em>Next-Gen AI Infrastructure</em>'
             . '</span></a>';
    }
}

if (!function_exists('lyra_chat_login_url')) {
    /**
     * Where to send a signed-out visitor, returning to the chat page afterwards.
     * Built here rather than calling lyra_ui_next_url() from api/lyra_ui_nav.php,
     * because chat.php does not load that file and a shared partial should not
     * depend on a caller having included something else - the failure mode that
     * has already produced one silent bug in this codebase.
     */
    function lyra_chat_login_url(string $next = '/chat/'): string
    {
        return '/pages/login.php?next=' . rawurlencode($next);
    }
}

if (!function_exists('lyra_chat_navitem')) {
    /**
     * $href null  -> inert row (designed, not built) with an explanatory title
     * $count null -> no badge
     */
    function lyra_chat_navitem(string $icon, string $label, ?int $count = null, ?string $href = null, bool $active = false, ?string $reason = null): string
    {
        $ic = lyra_chat_icons()[$icon] ?? '';
        $cls = 'lyra-navitem' . ($active ? ' is-active' : '');
        /* A row with no destination is non-interactive, and now says why. The
         * generic "Not built yet" told the user nothing; a specific reason is the
         * difference between a dead control and an explained one. */
        $title = ($reason !== null && $reason !== '') ? $reason : 'Not built yet';
        if ($href === null) {
            $cls .= ' is-pending';
        }
        $attrs = $href !== null
            ? ' href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
            : ' href="#" onclick="return false;" aria-disabled="true" title="' . htmlspecialchars($title, ENT_QUOTES) . '"';
        $badge = $count !== null ? '<span class="lyra-count" id="lyraCount' . preg_replace('/[^a-z]/i', '', $label) . '">' . $count . '</span>' : '';
        return '<a class="' . $cls . '"' . $attrs . '>'
             . '<span class="lyra-navicon">' . lyra_chat_icon($ic) . '</span>'
             . '<span>' . $label . '</span>' . $badge . '</a>';
    }
}

if (!function_exists('lyra_chat_rail_chrome')) {
    /**
     * $convListHtml is emitted directly beneath the Conversations row so the
     * saved conversations read as that row's contents. Before this, the history
     * was rendered after both nav groups and was pushed below the fold at
     * 1366x768, making it look as though no conversations existed.
     * The parameter is optional so existing callers keep working.
     */
    function lyra_chat_rail_chrome(string $convListHtml = ''): string
    {
        /* Knowledge maps to the dataset manager, which is admin-gated. Offering
         * that link to a non-admin would send them to a 403, so it is only
         * linked for an admin and explained for everyone else. */
        $railViewer = lyra_chat_viewer();
        $knowHref = ($railViewer !== null && !empty($railViewer['is_admin']))
            ? '/pages/dataset_manager/'
            : null;

        return '<div class="lyra-railhead">Main</div>'
             . '<div class="lyra-railnav">'
             . '<a class="lyra-navitem is-active" href="#" onclick="return false;">'
             . '<span class="lyra-navicon">' . lyra_chat_icon(lyra_chat_icons()['conv']) . '</span>'
             . '<span>Conversations</span><span class="lyra-count" id="lyraCountConv"></span></a>'
             . $convListHtml
             . lyra_chat_navitem('proj', 'Projects', null, null, false,
                   'There is no projects store in this database yet, so there is nothing to list')
             . lyra_chat_navitem('auto', 'Automations', null, '/pages/automation/')
             . lyra_chat_navitem('files', 'Files', null, null, false,
                   'There is no files table in this database yet, so there is nothing to list')
             . lyra_chat_navitem('know', 'Knowledge', null,
                   $knowHref, false,
                   $knowHref === null ? 'The knowledge base is administrator-only' : null)
             . lyra_chat_navitem('set', 'Settings', null, null, false,
                   'Chat settings are not built yet; account settings are under your avatar')
             . '</div>'
             . '<div class="lyra-railhead">Quick access</div>'
             . '<div class="lyra-quick">'
             . lyra_chat_navitem('deploy', 'Deploy Application', null, '/automation')
             . lyra_chat_navitem('code', 'Code Assistant', null, '/pages/vscode_extension/')
             . lyra_chat_navitem('mon', 'System Monitor', null, '/pages/status')
             . lyra_chat_navitem('res', 'Research &amp; Analyze', null, null, false,
                   'Use the chat composer to research; there is no separate research screen yet')
             . lyra_chat_navitem('doc', 'Create Documentation', null, '/pages/api_docs/')
             . '</div>';
    }
}

if (!function_exists('lyra_chat_promo')) {
    function lyra_chat_promo(): string
    {
        $up = lyra_chat_uptime(30);
        $svc = lyra_chat_services();
        $allOk = $svc['total'] > 0 && $svc['operational'] === $svc['total'];

        $statusText = $svc['total'] === 0
            ? 'Status unavailable'
            : ($allOk ? 'All Systems Operational'
                      : $svc['operational'] . ' of ' . $svc['total'] . ' services operational');

        $uptimeText = $up === null ? 'Uptime: not reported' : 'Uptime: ' . number_format($up, 2) . '%';

        return '<div class="lyra-promo">'
             . '<b>Automate Your Workflow</b>'
             . '<p>Create custom automations, schedule tasks, and let Lyralink handle the rest.</p>'
             . '<a href="/automation">View Automations &rarr;</a>'
             . '</div>'
             . '<div class="lyra-sysstatus">'
             . '<div class="lyra-railhead">System Status</div>'
             . '<div class="lyra-sysrow' . ($allOk ? '' : ' is-warn') . '">'
             . '<span class="lyra-sysdot"></span>' . htmlspecialchars($statusText) . '</div>'
             . '<div class="lyra-sysrow">' . htmlspecialchars($uptimeText) . ' <span class="lyra-sysnote">(30d)</span></div>'
             . '</div>';
    }
}

/* ── Top bar ─────────────────────────────────────────────────────────────── */

if (!function_exists('lyra_chat_topbar_mid')) {
    function lyra_chat_topbar_mid(): string
    {
        $ic = lyra_chat_icons();
        $model = lyra_chat_model_info();
        $tc = lyra_chat_tool_counts();

        return '<div class="lyra-topbar-mid">'
             . '<button type="button" class="lyra-modelselect" onclick="openAccountModal()" title="Model settings">'
             . '<span class="lyra-mdot">' . lyra_chat_icon($ic['bolt'], 2) . '</span>'
             . '<span class="lyra-msel-label">' . htmlspecialchars($model['name']) . '</span>'
             . lyra_chat_icon($ic['chev'], 2) . '</button>'
             . '<div class="lyra-pillgroup">'
             . '<div class="lyra-pillcell"><span class="lyra-pdot"></span><span>Workspace<b>Production</b></span></div>'
             . '<div class="lyra-pillcell">' . lyra_chat_icon($ic['set']) . '<span>Tools<b>' . $tc['active'] . ' Enabled</b></span></div>'
             . '<div class="lyra-pillcell">' . lyra_chat_icon($ic['auto']) . '<span>Task Mode<b>Autonomous</b></span></div>'
             . '</div></div>';
    }
}

if (!function_exists('lyra_chat_topbar_actions')) {
    /** Voice with its label, appearance, notifications, then the account. */
    function lyra_chat_topbar_actions(): string
    {
        $ic = lyra_chat_icons();
        $v = lyra_chat_viewer();

        $account = '';
        if ($v !== null) {
            $plan = trim((string) $v['plan']);
            $account = '<button type="button" class="lyra-user" onclick="openAccountModal()" title="Account">'
                     . '<span class="lyra-uav">' . htmlspecialchars($v['initials']) . '</span>'
                     . '<span class="lyra-utext"><b>' . htmlspecialchars($v['username']) . '</b>'
                     . '<em>' . ($plan !== '' ? htmlspecialchars(ucfirst($plan)) . ' Plan' : 'Signed in') . '</em></span>'
                     . lyra_chat_icon($ic['chev'], 2) . '</button>';
        } else {
            /* A signed-out visitor clicking this must reach the login page.
             * It previously opened the account modal, which cannot sign anyone
             * in, so the chat page had no route to /pages/login/ at all. An
             * anchor also makes middle-click and open-in-new-tab work. */
            $account = '<a class="acct-btn" id="headerAcctBtn" href="' . htmlspecialchars(lyra_chat_login_url(), ENT_QUOTES) . '">'
                     . lyra_chat_icon($ic['mic'], 1.7) . 'Sign in</a>';
        }

        return lyra_chat_admin_menu()
             . '<button type="button" class="lyra-btn-voice" onclick="openVoicePanel()" title="Voice">'
             . lyra_chat_icon($ic['mic']) . '<span>Voice</span></button>'
             /* Call sites for the theme and notification systems, which ship
              * separately. Guarded so a page that has not loaded them yet does
              * not throw; the control is inert until they arrive, and each has a
              * title saying what it will do. */
             . '<button type="button" class="lyra-iconbtn" title="Appearance: switch dark / light" aria-label="Appearance" onclick="if(window.LyraTheme){LyraTheme.toggle();}">' . lyra_chat_icon($ic['sun']) . '</button>'
             . '<button type="button" class="lyra-iconbtn" title="Notifications" aria-label="Notifications" aria-expanded="false" onclick="if(window.LyraNotify){LyraNotify.toggle();}">' . lyra_chat_icon($ic['bell']) . '</button>'
             . $account;
    }
}

/* ── Right rail ──────────────────────────────────────────────────────────── */

if (!function_exists('lyra_chat_rail_model')) {
    function lyra_chat_rail_model(): string
    {
        $m = lyra_chat_model_info();
        return '<div class="lyra-rail-head-row">'
             . '<span class="lyra-rail-label">Current Models</span>'
             . '<button type="button" class="lyra-rail-action" onclick="openAccountModal()">Change</button>'
             . '</div>'
             . '<div class="lyra-rail-card">'
             . '<span class="lyra-rail-dot">' . lyra_chat_icon(lyra_chat_icons()['bolt'], 2) . '</span>'
             . '<div class="lyra-rail-model">'
             . '<strong id="lyraRailModel">' . htmlspecialchars($m['name']) . '</strong>'
             . '<span id="lyraRailModelDetail">' . $m['detail'] . '</span>'
             . '</div></div>';
    }
}

if (!function_exists('lyra_chat_rail_tools')) {
    /**
     * Two-column tool grid. A green dot means the tool is genuinely wired up; a
     * grey dot means it is listed but not implemented. The design shows ten tools
     * all active; reporting that would be false, so the state is real.
     */
    function lyra_chat_rail_tools(): string
    {
        $ic = lyra_chat_icons();
        $tools = lyra_chat_tools();
        $tc = lyra_chat_tool_counts();

        $icons = [
            'Web Search' => 'res', 'Code Analysis' => 'code', 'GitHub' => 'gh', 'Database' => 'db',
            'Docker' => 'docker', 'Image Generation' => 'img', 'Terminal' => 'term',
            'Browser Automation' => 'browse', 'File System' => 'files', 'More Tools' => 'grid',
        ];

        $cells = '';
        foreach ($tools as $t) {
            [$label, $state, $note] = $t;
            $icon = $ic[$icons[$label] ?? 'grid'] ?? $ic['grid'];
            $cls = $state === true ? 'is-on' : ($state === null ? 'is-more' : 'is-off');
            $dot = $state === true ? 'is-on' : 'is-off';
            $title = htmlspecialchars($label . ' — ' . $note, ENT_QUOTES);
            $body = $state === null
                ? '<a class="lyra-tool is-more" href="/pages/api_docs/" title="' . $title . '">'
                  . lyra_chat_icon($icon) . '<span>' . $label . '</span></a>'
                : '<div class="lyra-tool ' . $cls . '" title="' . $title . '">'
                  . lyra_chat_icon($icon) . '<span>' . $label . '</span>'
                  . '<i class="lyra-tooldot ' . $dot . '"></i></div>';
            $cells .= $body;
        }

        return '<div class="lyra-rail-head-row">'
             . '<span class="lyra-rail-label">Tools Enabled</span>'
             . '<span class="lyra-tool-sum"><i class="lyra-tooldot is-on"></i>'
             . '<b id="lyraToolsCount">' . $tc['active'] . '</b> active</span>'
             . '<button type="button" class="lyra-rail-action" onclick="toggleAiTools()">Manage</button>'
             . '</div>'
             . '<div class="lyra-toolgrid">' . $cells . '</div>';
    }
}

if (!function_exists('lyra_chat_task_progress')) {
    /**
     * Renders the ring and step list but claims no task. The design shows a
     * completed deployment with per-step timings; reproducing those would present
     * invented execution telemetry as observed fact. window.lyraTaskProgress lets
     * the chat report genuine progress into this panel.
     */
    function lyra_chat_task_progress(): string
    {
        return '<div class="lyra-rail-label">Task Progress</div>'
             . '<div id="lyraTaskProgress" data-state="empty">'
             . '<div class="lyra-progress">'
             . '<div class="lyra-ring" role="img" aria-label="No task running">'
             . '<svg viewBox="0 0 60 60"><circle class="lyra-ringtrack" cx="30" cy="30" r="25"/>'
             . '<circle class="lyra-ringbar" id="lyraRingBar" cx="30" cy="30" r="25" '
             . 'stroke-dasharray="157" stroke-dashoffset="157"/></svg>'
             . '<b id="lyraRingPct">&mdash;</b></div>'
             . '<div class="lyra-progress-meta">'
             . '<strong id="lyraTaskName">No task running</strong>'
             . '<span id="lyraTaskMeta">Send a request to see execution progress.</span>'
             . '</div></div>'
             . '<div class="lyra-steps" id="lyraTaskSteps"></div>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_sources')) {
    function lyra_chat_sources(): string
    {
        return '<div class="lyra-rail-head-row">'
             . '<span class="lyra-rail-label">Sources</span>'
             . '<button type="button" class="lyra-rail-action" id="lyraSourcesView" disabled title="No sources yet">View All</button>'
             . '</div>'
             . '<div class="lyra-srclist" id="lyraRailSources">'
             . '<div class="lyra-rail-note">No retrieved sources in the last response.</div>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_related')) {
    function lyra_chat_related(): string
    {
        $v = lyra_chat_viewer();
        $who = $v !== null ? htmlspecialchars($v['username']) : 'Guest session';
        return '<div class="lyra-rail-label">Session Context</div>'
             . '<div class="lyra-rail-list">'
             . '<div class="lyra-rail-row"><span>User &middot; ' . $who . '</span><em class="is-idle">active</em></div>'
             . '<div class="lyra-rail-row"><span>Environment &middot; Production</span><em class="is-idle">live</em></div>'
             . '<div class="lyra-rail-row"><span>Domain &middot; lyralinkai.com</span><em>SSL</em></div>'
             . '</div>';
    }
}

/* ── Chat column ─────────────────────────────────────────────────────────── */

if (!function_exists('lyra_chat_admin_menu')) {
    /**
     * Admin entry point for the top bar.
     *
     * The chat page previously had an admin link that was permanently hidden:
     * <a id="adminLink" style="display:none"> with no code anywhere that ever
     * revealed it, and its visibility rule elsewhere was based on the literal
     * username "developer". Two accounts carry users.is_admin, so that check
     * missed one of them as well.
     *
     * This renders only for a verified administrator, reading the real flag
     * through lyra_chat_viewer(), and links to the admin surfaces that exist.
     * <details> is used rather than a JS dropdown so it works without script.
     */
    function lyra_chat_admin_menu(): string
    {
        $v = lyra_chat_viewer();
        if ($v === null || empty($v['is_admin'])) {
            return '';
        }
        $ic = lyra_chat_icons();
        $items = [
            ['/pages/admin-dashboard/', 'Admin Dashboard', 'Traffic, tasks, resources'],
            ['/pages/dev-stats/',       'Developer Stats', 'Audit log and runtime'],
            ['/pages/support_admin.php','Support Dashboard', 'Tickets and agents'],
            ['/pages/admin.php',        'Legacy Console',    'Original admin page'],
        ];
        $links = '';
        foreach ($items as $it) {
            $links .= '<a class="lyra-adminitem" href="' . htmlspecialchars($it[0], ENT_QUOTES) . '">'
                    . '<b>' . htmlspecialchars($it[1]) . '</b>'
                    . '<em>' . htmlspecialchars($it[2]) . '</em></a>';
        }
        return '<details class="lyra-adminmenu">'
             . '<summary class="lyra-adminbtn" title="Administration">'
             . lyra_chat_icon($ic['shield'] ?? $ic['set'])
             . '<span>Admin</span>' . lyra_chat_icon($ic['chev'], 2)
             . '</summary>'
             . '<div class="lyra-adminpop">'
             . '<div class="lyra-adminhead">Signed in as <b>' . htmlspecialchars($v['username']) . '</b></div>'
             . $links . '</div></details>';
    }
}

if (!function_exists('lyra_chat_composer_chips')) {

    function lyra_chat_composer_chips(): string
    {
        $ic = lyra_chat_icons();
        $tm = "var t=document.getElementById('taskModeToggle');"
            . "if(t){t.checked=!t.checked;t.dispatchEvent(new Event('change',{bubbles:true}));}";
        return '<div class="lyra-composer-actions">'
             . '<button type="button" class="lyra-chip" title="Attach a file" '
             . 'onclick="var a=document.getElementById(\'chatAttachmentInput\');if(a){a.click();}">'
             . lyra_chat_icon($ic['paper']) . 'Attach</button>'
             . '<button type="button" class="lyra-chip" onclick="toggleAiTools()">'
             . lyra_chat_icon($ic['code']) . 'Tools</button>'
             . '<button type="button" class="lyra-chip is-on" onclick="' . htmlspecialchars($tm, ENT_QUOTES) . '">'
             . lyra_chat_icon($ic['auto']) . 'Task Mode</button>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_stages')) {
    /**
     * The Plan / Build / Verify / Deliver rail under the message list. Shows the
     * pipeline stages; none is marked complete because no work has run yet, and
     * the chat marks them via window.lyraStage() as a request progresses.
     */
    function lyra_chat_stages(): string
    {
        $ic = lyra_chat_icons();
        $stages = [
            ['plan',   'Plan',   'Analyse requirements'],
            ['build',  'Build',  'Implement changes'],
            ['verify', 'Verify', 'Run tests &amp; checks'],
            ['deliver','Deliver','Complete &amp; notify'],
        ];
        $out = '<div class="lyra-stages" id="lyraStages" aria-label="Task pipeline">';
        foreach ($stages as $i => $s) {
            $out .= '<div class="lyra-stage" data-stage="' . $s[0] . '">'
                  . '<span class="lyra-stagemark">' . lyra_chat_icon($ic['check'], 2) . '</span>'
                  . '<span class="lyra-stagetext"><b>' . $s[1] . '</b><em>' . $s[2] . '</em></span>'
                  . '</div>';
            if ($i < count($stages) - 1) {
                $out .= '<span class="lyra-stagebar" aria-hidden="true"></span>';
            }
        }
        return $out . '</div>';
    }
}

if (!function_exists('lyra_chat_welcome')) {
    /** The welcome state. Shared with JavaScript via window.LYRA_WELCOME_HTML. */
    function lyra_chat_welcome(): string
    {
        $ic = lyra_chat_icons();
        $cap = function (string $icon, string $title, string $text) use ($ic): string {
            return '<div class="lyra-cap"><i>' . lyra_chat_icon($ic[$icon], 2) . '</i>'
                 . '<b>' . $title . '</b><span>' . $text . '</span></div>';
        };
        $sg = function (string $prompt, string $tag, string $icon) use ($ic): string {
            $esc = htmlspecialchars($prompt, ENT_QUOTES);
            return '<button type="button" class="lyra-suggest" onclick="setInput(&quot;' . $esc . '&quot;)">'
                 . '<p>' . $esc . '</p>'
                 . '<em>' . lyra_chat_icon($ic[$icon], 2) . $tag . '</em></button>';
        };

        return '<div class="empty-state lyra-welcome-state" id="emptyState">'
             . '<div class="lyra-welcome">'
             . '<div class="lyra-welcome-head">'
             . '<span class="lyra-welcome-mark">' . lyra_chat_icon($ic['bolt'], 2) . '</span>'
             . '<div><h1>Lyralink AI</h1>'
             . '<p>Your intelligent workspace for building, automating, and managing your digital world.</p></div>'
             . '</div>'
             . '<div class="lyra-capgrid">'
             . $cap('think', 'Think', 'Understand your goals and context')
             . $cap('res', 'Research', 'Find the right information and sources')
             . $cap('auto', 'Execute', 'Use tools and run tasks autonomously')
             . $cap('deliv', 'Deliver', 'Verify results and provide the outcome')
             . '</div>'
             . '<div class="lyra-suggest-label">Try asking something like:</div>'
             . '<div class="lyra-suggestgrid">'
             . $sg('Deploy my web application to production', 'Deployment', 'deploy')
             . $sg('Analyze this code and fix the issues', 'Development', 'code')
             . $sg('Research the latest updates in AI infrastructure', 'Research', 'res')
             . $sg('Create a project plan for my next feature', 'Planning', 'tag')
             . '</div></div></div>';
    }
}
