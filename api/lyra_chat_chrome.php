<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK CHAT — CHROME PARTIALS
   ══════════════════════════════════════════════════════════════════════════
   All the chat page's surrounding chrome in one place: the rail navigation,
   the top bar groups, the right-rail panels, the composer chips and the
   welcome state.

   Why this file exists: chat.php is the working application (~8,000 lines of
   JavaScript bound to 96 element ids). Adding the Chat-Page design meant
   roughly 14KB of markup, and inlining that into chat.php would have buried
   the application logic in decoration.

   IMPORTANT — the welcome markup is shared with JavaScript. The chat rebuilds
   #emptyState with chatbox.innerHTML whenever it switches or clears a
   conversation, so the PHP copy is destroyed on first render unless the JS
   emits the same thing. Rather than maintain two copies, chat.php assigns
   lyra_chat_welcome() to window.LYRA_WELCOME_HTML and the chat modules read it.
   If you change the welcome, change it here only.

   Every function returns a string and echoes nothing, so a caller can capture,
   reorder or omit any block. No id used by the chat's JavaScript is defined or
   touched here.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);

if (!function_exists('lyra_chat_icon')) {
    /** Inline stroke icon. $d is the path data. */
    function lyra_chat_icon(string $d, int $w = 2): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
             . $w . '" stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"/></svg>';
    }
}

if (!function_exists('lyra_chat_icons')) {
    /** Named path data, so callers are not passing magic strings around. */
    function lyra_chat_icons(): array
    {
        return [
            'conv'    => 'M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z',
            'proj'    => 'M3 7h7l2 2h9v10H3z',
            'auto'    => 'M13 2 4 14h7l-1 8 9-12h-7z',
            'files'   => 'M6 3h8l4 4v14H6z',
            'know'    => 'M4 5h16v14H4zM4 9h16',
            'set'     => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
            'deploy'  => 'M12 3v12M8 11l4 4 4-4M5 21h14',
            'code'    => 'm8 6-6 6 6 6M16 6l6 6-6 6',
            'mon'     => 'M3 12h4l3 8 4-16 3 8h4',
            'res'     => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3',
            'doc'     => 'M6 3h8l4 4v14H6zM9 12h6M9 16h4',
            'think'   => 'M12 3a6 6 0 0 0-4 10v3h8v-3a6 6 0 0 0-4-10Z',
            'deliv'   => 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
            'bell'    => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
            'sun'     => 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
            'check'   => 'M20 6 9 17l-5-5',
            'tag'     => 'M20.6 13.4 12 22l-9-9V3h10z',
            'mic'     => 'M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3ZM5 11a7 7 0 0 0 14 0M12 18v4',
            'paper'   => 'M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7',
            'chev'    => 'm6 9 6 6 6-6',
        ];
    }
}

if (!function_exists('lyra_chat_navitem')) {
    /**
     * A rail navigation row.
     * $href null renders an inert, aria-disabled row for a screen that is
     * designed but not built, rather than a link that goes nowhere.
     */
    function lyra_chat_navitem(string $icon, string $label, ?int $count = null, ?string $href = null, bool $active = false): string
    {
        $ic = lyra_chat_icons()[$icon] ?? '';
        $cls = 'lyra-navitem' . ($active ? ' is-active' : '');
        $attrs = $href !== null
            ? ' href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
            : ' href="#" onclick="return false;" aria-disabled="true" title="Not built yet"';
        $badge = $count !== null ? '<span class="lyra-count">' . $count . '</span>' : '';
        return '<a class="' . $cls . '"' . $attrs . '>'
             . '<span class="lyra-navicon">' . lyra_chat_icon($ic) . '</span>'
             . '<span>' . $label . '</span>' . $badge . '</a>';
    }
}

if (!function_exists('lyra_chat_rail_chrome')) {
    /** Left rail: Main nav, Quick access. Sits above the conversation list. */
    function lyra_chat_rail_chrome(): string
    {
        return '<div class="lyra-railhead">Main</div>'
             . '<div class="lyra-railnav">'
             . lyra_chat_navitem('conv', 'Conversations', null, null, true)
             . lyra_chat_navitem('proj', 'Projects')
             . lyra_chat_navitem('auto', 'Automations')
             . lyra_chat_navitem('files', 'Files')
             . lyra_chat_navitem('know', 'Knowledge')
             . lyra_chat_navitem('set', 'Settings')
             . '</div>'
             . '<div class="lyra-railhead">Quick access</div>'
             . '<div class="lyra-quick">'
             . lyra_chat_navitem('deploy', 'Deploy Application', null, '/pages/automation.php')
             . lyra_chat_navitem('code', 'Code Assistant', null, '/pages/vscode_extension/')
             . lyra_chat_navitem('mon', 'System Monitor', null, '/pages/status')
             . lyra_chat_navitem('res', 'Research &amp; Analyze')
             . lyra_chat_navitem('doc', 'Create Documentation', null, '/pages/api_docs/')
             . '</div>';
    }
}

if (!function_exists('lyra_chat_promo')) {
    /** Promo card plus live system status, above the rail footer. */
    function lyra_chat_promo(): string
    {
        return '<div class="lyra-promo">'
             . '<b>Automate Your Workflow</b>'
             . '<p>Create custom automations, schedule tasks, and let Lyralink handle the rest.</p>'
             . '<a href="/pages/automation.php">View Automations &rarr;</a>'
             . '</div>'
             . '<div class="lyra-sysstatus">'
             . '<div class="lyra-sysrow"><span class="lyra-sysdot"></span>All Systems Operational</div>'
             . '<div class="lyra-sysrow" id="lyraUptimeRow">Uptime not reported</div>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_topbar_mid')) {
    /** Model selector and the Workspace / Tools / Task Mode pills. */
    function lyra_chat_topbar_mid(): string
    {
        $ic = lyra_chat_icons();
        return '<div class="lyra-topbar-mid">'
             . '<button type="button" class="lyra-modelselect" onclick="openAccountModal()" title="Model and plan settings">'
             . '<span class="lyra-mdot"></span>Lyra-1 (Latest)' . lyra_chat_icon($ic['chev'], 2) . '</button>'
             . '<div class="lyra-pillgroup">'
             . '<div class="lyra-pillcell"><span class="lyra-pdot"></span><span>Workspace<b>Production</b></span></div>'
             . '<div class="lyra-pillcell">' . lyra_chat_icon($ic['set']) . '<span>Tools<b id="lyraToolsCount">&mdash;</b></span></div>'
             . '<div class="lyra-pillcell">' . lyra_chat_icon($ic['auto']) . '<span>Task Mode<b>Autonomous</b></span></div>'
             . '</div></div>';
    }
}

if (!function_exists('lyra_chat_topbar_actions')) {
    /** Voice, appearance and notifications, left of the account controls. */
    function lyra_chat_topbar_actions(): string
    {
        $ic = lyra_chat_icons();
        return '<button type="button" class="lyra-iconbtn" onclick="openVoicePanel()" title="Voice" aria-label="Voice">' . lyra_chat_icon($ic['mic']) . '</button>'
             . '<button type="button" class="lyra-iconbtn" title="Appearance" aria-label="Appearance">' . lyra_chat_icon($ic['sun']) . '</button>'
             . '<button type="button" class="lyra-iconbtn" title="Notifications" aria-label="Notifications">' . lyra_chat_icon($ic['bell']) . '</button>';
    }
}

if (!function_exists('lyra_chat_task_progress')) {
    /**
     * Task progress for the Context rail.
     *
     * The approved mockup shows a completed workflow with per-step timings.
     * Those numbers are illustrative in the design, not data, and rendering them
     * on a fresh conversation would present invented execution telemetry as if it
     * were real - exactly the confusion between generated and observed content
     * the product is meant to avoid. So the ring and step list render filled but
     * the panel only claims a task when one actually exists: it starts in an
     * empty state and is populated from real tool/execution activity.
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

if (!function_exists('lyra_chat_related')) {
    /** Related context rows for the rail. */
    function lyra_chat_related(): string
    {
        return '<div class="lyra-rail-label">Session Context</div>'
             . '<div class="lyra-rail-list">'
             . '<div class="lyra-rail-row"><span>Project &middot; Lyralink Main App</span><em class="is-idle">now</em></div>'
             . '<div class="lyra-rail-row"><span>Environment &middot; Production</span><em class="is-idle">live</em></div>'
             . '<div class="lyra-rail-row"><span>Domain &middot; lyralinkai.com</span><em>SSL</em></div>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_composer_chips')) {
    /** Attach / Tools / Task Mode, above the input row. */
    function lyra_chat_composer_chips(): string
    {
        $ic = lyra_chat_icons();
        // The Task Mode chip mirrors the real #taskModeToggle checkbox so the
        // design's control and the application's control cannot disagree.
        $tm = "var t=document.getElementById('taskModeToggle');"
            . "if(t){t.checked=!t.checked;t.dispatchEvent(new Event('change',{bubbles:true}));}";
        return '<div class="lyra-composer-actions">'
             . '<button type="button" class="lyra-chip" title="Attach a file" '
             . 'onclick="var a=document.getElementById(\'chatAttachmentInput\');if(a){a.click();}">'
             . lyra_chat_icon($ic['paper']) . 'Attach</button>'
             . '<button type="button" class="lyra-chip" onclick="toggleAiTools()">'
             . lyra_chat_icon($ic['set']) . 'Tools</button>'
             . '<button type="button" class="lyra-chip is-on" onclick="' . htmlspecialchars($tm, ENT_QUOTES) . '">'
             . lyra_chat_icon($ic['auto']) . 'Task Mode</button>'
             . '</div>';
    }
}

if (!function_exists('lyra_chat_welcome')) {
    /**
     * The welcome state. Shared with JavaScript — see the file header.
     * Returns a single line so it is safe to embed in a JSON string.
     */
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
                 . '<p>' . htmlspecialchars($prompt, ENT_QUOTES) . '</p>'
                 . '<em>' . lyra_chat_icon($ic[$icon], 2) . $tag . '</em></button>';
        };

        return '<div class="empty-state lyra-welcome-state" id="emptyState">'
             . '<div class="lyra-welcome">'
             . '<div class="lyra-welcome-head">'
             . '<span class="lyra-welcome-mark" aria-hidden="true"></span>'
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
