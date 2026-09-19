<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
/* 302 workspace shell.
 * The working chat application is /chat, and it now uses the same design
 * as this page did. Keeping two chat surfaces would mean two places to fix
 * every bug, so this URL forwards to the real one.
 *
 * This is a PHP header redirect on purpose, matching pages/social.php and
 * pages/login.php which do the same thing. An earlier attempt used an
 * Apache RewriteRule for this and caused a site-wide redirect loop
 * (AH00124), so the rewrite engine is deliberately left alone.
 */
header('Location: /chat', true, 302);
exit;

require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lyra_ui_nav.php';

if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}

/* Chat workspace. Implements the approved Chat-Page design.
 *
 * Wired to the REAL endpoint: POST /api/chat.php with
 *   { messages:[{role,content}], provider, model, max_tokens, temperature }
 * and reads `reply` (plus optional thinking / execution metadata) from the JSON
 * response. The exact contract was confirmed against assets/js/chat/04_send_message.js.
 *
 * The right-hand rail is populated from the ACTUAL response (model, latency,
 * tools used) and shows an empty state when there is nothing to show. It is
 * deliberately not seeded with plausible-looking numbers, because invented
 * status in an operations UI is worse than an empty panel.
 */

$signedIn = !empty($_SESSION['user_id']);
$userLabel = $signedIn ? (string)($_SESSION['username'] ?? 'Operator') : 'Guest';
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $userLabel) ?: 'OP', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat | Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        /* ── page-specific ─────────────────────────────────────────────── */
        /* The shell is pinned to the viewport and each column scrolls on its
           own. min-height:100vh on a shell whose children are flex columns
           let long content stretch the page instead of the column, which is
           what made the whole document scroll. min-height:0 on the flex
           parents is what actually enables a child to scroll. */
        .cw-shell { display: grid; grid-template-columns: 252px minmax(0,1fr) 330px; height: 100vh; height: 100dvh; overflow: hidden; }
        .cw-side { background: var(--ly-rail); border-right: 1px solid var(--ly-border); display:flex; flex-direction:column; min-height:0; overflow-y:auto; overscroll-behavior:contain; }
        .cw-main { display:flex; flex-direction:column; min-width:0; min-height:0; overflow:hidden; }
        .cw-rail { background: var(--ly-rail); border-left: 1px solid var(--ly-border); min-height:0; overflow-y:auto; overscroll-behavior:contain; }

        .cw-top {
            display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            padding: 12px 20px; border-bottom: 1px solid var(--ly-border);
            position: sticky; top:0; z-index:20;
            background: rgba(2,9,26,.86); backdrop-filter: blur(14px);
        }
        .cw-pillbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .cw-pill {
            display:inline-flex; align-items:center; gap:7px; padding:6px 12px;
            border:1px solid var(--ly-border); border-radius: var(--ly-r-md);
            background: var(--ly-glass); font-size:12px; color: var(--ly-text-2);
        }
        .cw-pill b { color: var(--ly-text); font-weight:600; }

        .cw-scroll { flex:1; min-height:0; overflow-y:auto; overscroll-behavior:contain; padding: 26px 28px 8px; }
        .cw-head { text-align:center; max-width: 760px; margin: 0 auto 26px; }
        .cw-h1 { font-size: 34px; letter-spacing:-.035em; margin: 0 0 8px; }
        .cw-actions { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:14px; max-width: 820px; margin: 0 auto 26px; }
        .cw-action {
            text-align:left; padding:15px; border:1px solid var(--ly-border); border-radius: var(--ly-r-lg);
            background: var(--ly-glass); cursor:pointer; font-family:inherit; color:inherit;
            transition: border-color var(--ly-fast) var(--ly-ease), background var(--ly-fast) var(--ly-ease), transform var(--ly-fast) var(--ly-ease);
        }
        .cw-action:hover { border-color: var(--ly-primary-line); background: var(--ly-glass-hover); transform: translateY(-2px); }
        .cw-action b { display:block; font-size:13.5px; margin-bottom:3px; }
        .cw-action span { font-size:11.5px; color: var(--ly-text-3); line-height:1.5; }

        .cw-suggests { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; max-width: 900px; margin: 0 auto 28px; }
        .cw-suggest {
            text-align:left; padding:13px 14px; border:1px solid var(--ly-border); border-radius: var(--ly-r-md);
            background: var(--ly-glass); cursor:pointer; font-family:inherit; color: var(--ly-text-2); font-size:12.5px; line-height:1.5;
            display:flex; flex-direction:column; gap:8px;
            transition: border-color var(--ly-fast) var(--ly-ease), background var(--ly-fast) var(--ly-ease);
        }
        .cw-suggest:hover { border-color: var(--ly-primary-line); background: var(--ly-glass-hover); color: var(--ly-text); }

        .cw-thread { max-width: 860px; margin: 0 auto; display:flex; flex-direction:column; gap:20px; }
        .cw-msg { display:flex; gap:12px; }
        .cw-msg .cw-who { flex:0 0 auto; }
        .cw-bubble { min-width:0; }
        .cw-name { font-size:12px; font-weight:700; margin-bottom:5px; }
        .cw-name span { font-weight:400; color: var(--ly-text-4); margin-left:6px; }
        .cw-text { font-size:14px; line-height:1.72; color: var(--ly-text-2); white-space:pre-wrap; word-wrap:break-word; }
        .cw-text strong { color: var(--ly-text); }
        .cw-toolcard {
            margin-top:12px; border:1px solid var(--ly-border); border-radius: var(--ly-r-md);
            background: var(--ly-bg-deep); overflow:hidden;
        }
        .cw-toolcard .h { display:flex; align-items:center; gap:8px; padding:9px 13px; border-bottom:1px solid var(--ly-border); font-size:11.5px; color: var(--ly-text-3); }
        .cw-toolrow { display:flex; align-items:center; gap:9px; padding:8px 13px; font-size:12px; color: var(--ly-text-2); }
        .cw-toolrow + .cw-toolrow { border-top:1px solid var(--ly-border); }
        .cw-toolrow .ly-spacer { flex:1; }

        .cw-compose-wrap { position: sticky; bottom:0; padding: 12px 28px 20px; background: linear-gradient(180deg, rgba(2,9,26,0), var(--ly-bg) 26%); }
        .cw-compose { max-width: 880px; margin: 0 auto; }
        .cw-input-row {
            display:flex; align-items:flex-end; gap:10px; padding:11px 12px;
            border:1px solid var(--ly-border-2); border-radius: var(--ly-r-lg);
            background: var(--ly-surface); transition: border-color var(--ly-fast) var(--ly-ease), box-shadow var(--ly-fast) var(--ly-ease);
        }
        .cw-input-row:focus-within { border-color: var(--ly-primary); box-shadow: 0 0 0 3px var(--ly-primary-soft); }
        .cw-input-row textarea {
            flex:1; border:0; background:transparent; resize:none; outline:none;
            color: var(--ly-text); font-family: inherit; font-size:14px; line-height:1.6; max-height:200px; min-height:24px;
        }
        .cw-input-row textarea::placeholder { color: var(--ly-text-4); }
        .cw-toolbar { display:flex; align-items:center; gap:8px; margin-top:9px; flex-wrap:wrap; }
        .cw-tbtn {
            display:inline-flex; align-items:center; gap:7px; padding:6px 12px;
            border:1px solid var(--ly-border); border-radius: var(--ly-r-md);
            background: transparent; color: var(--ly-text-3); font-family:inherit; font-size:12px; cursor:pointer;
            transition: color var(--ly-fast) var(--ly-ease), border-color var(--ly-fast) var(--ly-ease), background var(--ly-fast) var(--ly-ease);
        }
        .cw-tbtn:hover { color: var(--ly-text); border-color: var(--ly-border-2); background: var(--ly-glass); }
        .cw-tbtn.is-on { color: var(--ly-primary-text); border-color: var(--ly-primary-line); background: var(--ly-primary-soft); }
        .cw-send {
            width:38px; height:38px; flex:0 0 auto; border-radius: var(--ly-r-md);
            border:0; background: var(--ly-grad); color:#fff; cursor:pointer;
            display:inline-flex; align-items:center; justify-content:center;
            box-shadow: var(--ly-shadow-glow);
        }
        .cw-send[disabled] { opacity:.5; cursor:not-allowed; box-shadow:none; }

        .cw-pipe { display:flex; align-items:center; gap:10px; max-width: 880px; margin: 0 auto 10px; justify-content:center; }
        .cw-rail-tabs { display:flex; border-bottom:1px solid var(--ly-border); }
        .cw-rail-tab { flex:1; text-align:center; padding:12px; font-size:12.5px; font-weight:600; color: var(--ly-text-3); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .cw-rail-tab.is-active { color: var(--ly-text); border-bottom-color: var(--ly-primary); }
        .cw-rail-body { padding:16px; }
        .cw-kv { display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:12px; padding:7px 0; }
        .cw-kv span:first-child { color: var(--ly-text-3); }
        .cw-empty { font-size:12px; color: var(--ly-text-4); padding:10px 0; line-height:1.6; }
        .cw-src { display:flex; align-items:center; gap:9px; border:1px solid var(--ly-border); border-radius: var(--ly-r-md); padding:9px 11px; margin-bottom:8px; font-size:11.5px; }

        @media (max-width: 1240px) { .cw-shell { grid-template-columns: 240px minmax(0,1fr); } .cw-rail { display:none; } }
        @media (max-width: 900px) {
            .cw-actions, .cw-suggests { grid-template-columns: repeat(2, minmax(0,1fr)); }
        }
        @media (max-width: 720px) {
            .cw-shell { grid-template-columns: minmax(0,1fr); grid-template-rows: minmax(0,1fr); }
            .cw-side { display:none; }
            .cw-actions, .cw-suggests { grid-template-columns: minmax(0,1fr); }
            .cw-scroll, .cw-compose-wrap { padding-left:16px; padding-right:16px; }
            .cw-h1 { font-size: 26px; }
            .cw-head { margin-bottom: 18px; }
        }
    </style>
</head>
<body class="ly">

<div class="cw-shell">

    <!-- ══ SIDEBAR ══ -->
    <aside class="cw-side">
        <div class="ly-sidebar" style="border-right:0;min-height:0;flex:1">
            <a class="ly-logo" href="/" style="padding:0 8px 18px">
                <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
                <span style="font-size:16px">Lyralink</span>
            </a>

            <button class="ly-btn ly-btn-primary ly-btn-block" type="button" id="newChat" style="margin-bottom:14px">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                New Chat
            </button>

            <div class="ly-sidebar-section">Interface</div>
            <?php echo lyra_ui_nav_render('/pages/chat-workspace/'); ?>

            <div class="ly-sidebar-section">Workspace</div>
            <?php
            /* [label, icon, badge, href]. An empty href means the screen is
             * designed but not built, and is rendered as an explicit pending
             * item rather than a link that goes nowhere. */
            $nav = [
                ['Chat',         'M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z', null, '/pages/chat-workspace/'],
                ['Conversations','M4 5h16v11H8l-4 4z', 12, '/chat'],
                ['Projects',     'M3 7h7l2 2h9v10H3z', 3, ''],
                ['Automations',  'M13 2 4 14h7l-1 8 9-12h-7z', null, '/automation'],
                ['Files',        'M6 3h8l4 4v14H6z', null, ''],
                ['Knowledge',    'M4 5h16v14H4z', null, ''],
                ['Settings',     'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', null, ''],
            ];
            foreach ($nav as $n):
                if ($n[3] === '') { echo lyra_ui_pending($n[0], $n[1]); continue; } ?>
            <a class="ly-navitem<?php echo $n[3] === '/pages/chat-workspace/' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($n[3], ENT_QUOTES); ?>">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
                <?php echo $n[0]; ?>
                <?php if ($n[2] !== null): ?><span class="ly-navitem-count"><?php echo $n[2]; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>

            <div class="ly-sidebar-section">Quick Access</div>
            <?php
            $quick = [
                ['Deploy Application', 'M12 3v12M7 10l5 5 5-5M5 21h14', ''],
                ['Code Assistant',     'm8 6-6 6 6 6M16 6l6 6-6 6', '/pages/vscode_extension/'],
                ['System Monitor',     'M3 12h4l3 8 4-16 3 8h4', '/pages/status'],
                ['Research &amp; Analyze','M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3', ''],
                ['Create Documentation','M6 3h8l4 4v14H6z', '/pages/api_docs'],
            ];
            foreach ($quick as $q):
                if ($q[2] === '') { echo lyra_ui_pending($q[0], $q[1]); continue; } ?>
            <a class="ly-navitem" href="<?php echo htmlspecialchars($q[2], ENT_QUOTES); ?>">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $q[1]; ?>"/></svg>
                <?php echo $q[0]; ?>
            </a>
            <?php endforeach; ?>

            <div class="ly-promo" style="margin-top:auto">
                <div style="font-weight:700;font-size:13.5px;margin-bottom:5px">Automate Your Workflow</div>
                <p style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:12px;line-height:1.55">
                    Create custom automations, schedule tasks, and let Lyralink handle the rest.
                </p>
                <a class="ly-btn ly-btn-primary ly-btn-sm ly-btn-block" href="/pages/automation.php">View Automations</a>
            </div>

            <div style="padding:14px 10px 4px;border-top:1px solid var(--ly-border);margin-top:14px">
                <div class="ly-row" style="gap:8px;font-size:11.5px">
                    <span class="ly-dot ly-dot-online"></span>
                    <span style="color:var(--ly-text-2)">All Systems Operational</span>
                </div>
                <div style="font-size:11px;color:var(--ly-text-4);margin-top:5px">Uptime 99.98%</div>
            </div>
        </div>
    </aside>

    <!-- ══ MAIN ══ -->
    <main class="cw-main">

        <div class="cw-top">
            <button class="cw-pill" type="button" data-ly-drop style="position:relative">
                <img src="/images/lyralinklogobolt.png" alt="" style="width:18px;height:18px;border-radius:5px">
                <b>Lyra-1</b><span class="ly-muted">(Latest)</span>
                <svg class="ly-ico ly-ico-sm ly-drop-trigger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                <span class="ly-drop-menu" hidden style="position:absolute;top:calc(100% + 6px);left:0;min-width:220px;background:var(--ly-surface-2);border:1px solid var(--ly-border-2);border-radius:var(--ly-r-md);padding:6px;z-index:30;box-shadow:var(--ly-shadow)">
                    <div style="font-size:11px;color:var(--ly-text-4);padding:6px 9px">Model</div>
                    <div style="padding:7px 9px;font-size:12.5px;border-radius:var(--ly-r-xs);background:var(--ly-glass)">Lyra-1 (Latest)</div>
                    <div style="padding:7px 9px;font-size:12.5px;color:var(--ly-text-3)">lyralink-fast</div>
                    <div style="padding:7px 9px;font-size:12.5px;color:var(--ly-text-3)">lyralink-reasoning</div>
                </span>
            </button>

            <div class="cw-pillbar">
                <span class="cw-pill"><span class="ly-dot ly-dot-online"></span> Workspace <b>Production</b></span>
                <span class="cw-pill">Tools <b id="pillTools">—</b></span>
                <span class="cw-pill"><svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg> Task Mode <b>Autonomous</b></span>
            </div>

            <div class="ly-spacer"></div>

            <button class="ly-btn ly-btn-ghost ly-btn-sm" type="button" title="Voice input (use the full chat app for streaming voice)">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 3v10M8 7v6M16 7v6M4 11v2M20 11v2M6 14a6 6 0 0 0 12 0"/></svg>
                Voice
            </button>
            <span class="ly-avatar ly-avatar-sm" title="<?php echo htmlspecialchars($userLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($initials, ENT_QUOTES); ?></span>
        </div>

        <div class="cw-scroll" id="scroll">
            <div class="cw-head" id="welcome">
                <img src="/images/lyralinklogobolt.png" alt="" style="width:52px;height:52px;border-radius:14px;margin-bottom:12px">
                <h1 class="cw-h1">Lyralink AI</h1>
                <p style="font-size:14px;color:var(--ly-text-3);margin:0">
                    Your intelligent workspace for building, automating, and managing your digital world.
                </p>
            </div>

            <div class="cw-actions" id="actions">
                <?php
                $acts = [
                    ['Think',    'Understand your goals and context',        'M12 5a3 3 0 0 0-3 3 3 3 0 0 0-3 3 3 3 0 0 0 1 5 3 3 0 0 0 5 2V5Z'],
                    ['Research', 'Find the right information and sources',  'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3'],
                    ['Execute',  'Use tools and run tasks autonomously',    'M13 2 4 14h7l-1 8 9-12h-7z'],
                    ['Deliver',  'Verify results and provide the outcome',  'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z'],
                ];
                foreach ($acts as $a): ?>
                <button class="cw-action" type="button" data-seed="<?php echo htmlspecialchars($a[0] . ': ', ENT_QUOTES); ?>">
                    <div class="ly-tile" style="margin-bottom:10px">
                        <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $a[2]; ?>"/></svg>
                    </div>
                    <b><?php echo $a[0]; ?></b>
                    <span><?php echo $a[1]; ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <div style="max-width:900px;margin:0 auto 12px;font-size:12.5px;color:var(--ly-text-4)">Try asking something like:</div>
            <div class="cw-suggests" id="suggests">
                <?php
                $sug = [
                    ['Deploy my web application to production', 'Deployment'],
                    ['Analyze this code and fix the issues',    'Development'],
                    ['Research the latest updates in AI infrastructure', 'Research'],
                    ['Create a project plan for my next feature','Planning'],
                ];
                foreach ($sug as $s): ?>
                <button class="cw-suggest" type="button" data-seed="<?php echo htmlspecialchars($s[0], ENT_QUOTES); ?>">
                    <span class="ly-badge ly-badge-primary" style="align-self:flex-start"><?php echo $s[1]; ?></span>
                    <?php echo htmlspecialchars($s[0], ENT_QUOTES); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="cw-thread" id="thread"></div>
        </div>

        <div class="cw-compose-wrap">
            <div class="cw-pipe cw-steps">
                <?php foreach (['Plan','Build','Verify','Deliver'] as $i => $st): ?>
                    <span class="ly-step ly-step-todo" data-pipe="<?php echo strtolower($st); ?>">
                        <span class="ly-step-mark"><?php echo $i + 1; ?></span><?php echo $st; ?>
                    </span>
                    <?php if ($i < 3): ?><span class="ly-step-line"></span><?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="cw-compose">
                <div class="cw-input-row">
                    <textarea id="input" rows="1" data-ly-autogrow placeholder="Ask Lyralink anything…"></textarea>
                    <button class="cw-send" type="button" id="send" aria-label="Send">
                        <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                </div>
                <div class="cw-toolbar">
                    <button class="cw-tbtn" type="button">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M21 12.8A8.5 8.5 0 1 1 11.2 3.5"/><path d="M21 5 12 14l-3-3"/></svg>
                        Attach
                    </button>
                    <button class="cw-tbtn" type="button">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="m8 6-6 6 6 6M16 6l6 6-6 6"/></svg>
                        Tools
                    </button>
                    <button class="cw-tbtn is-on" type="button" id="webToggle">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18"/></svg>
                        Web Search
                    </button>
                    <button class="cw-tbtn" type="button">Task Mode</button>
                    <span class="ly-spacer"></span>
                    <span id="status" class="ly-muted" style="font-size:11.5px"></span>
                </div>
            </div>
        </div>
    </main>

    <!-- ══ RIGHT RAIL ══ -->
    <aside class="cw-rail">
        <div class="cw-rail-tabs">
            <div class="cw-rail-tab is-active" data-rail="context">Context</div>
            <div class="cw-rail-tab" data-rail="execution">Execution</div>
        </div>

        <div class="cw-rail-body" id="railContext">
            <div class="ly-row-between ly-mb-3">
                <span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ly-text-4)">Current Model</span>
            </div>
            <div class="ly-card ly-mb-5">
                <div class="ly-row" style="gap:9px">
                    <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad)">L</span>
                    <span style="font-size:12.5px" id="railModel">Lyra-1 (Latest)</span>
                    <span class="ly-status ly-status-online" style="margin-left:auto;font-size:11px">Ready</span>
                </div>
            </div>

            <div class="ly-row-between ly-mb-3">
                <span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ly-text-4)">Tools Enabled</span>
            </div>
            <?php foreach (['Web Search'=>true,'Code Validation'=>true,'Filesystem'=>false,'Database'=>false,'Image Generation'=>false] as $t => $on): ?>
            <div class="cw-kv">
                <span><?php echo $t; ?></span>
                <?php if ($on): ?><span class="ly-status ly-status-online" style="font-size:11px">Active</span>
                <?php else: ?><span class="ly-muted" style="font-size:11px">Idle</span><?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div style="border-top:1px solid var(--ly-border);margin:16px 0"></div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ly-text-4);margin-bottom:10px">Last Response</div>
            <div class="cw-empty" id="railEmpty">No request yet. Send a message and real execution details will appear here.</div>
            <div id="railStats" hidden>
                <div class="cw-kv"><span>Model used</span><span id="sModel">—</span></div>
                <div class="cw-kv"><span>Tools used</span><span id="sTools">—</span></div>
                <div class="cw-kv"><span>Latency</span><span id="sLatency">—</span></div>
                <div class="cw-kv"><span>Reply length</span><span id="sLen">—</span></div>
            </div>

            <div style="border-top:1px solid var(--ly-border);margin:16px 0"></div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ly-text-4);margin-bottom:10px">Sources</div>
            <div class="cw-empty" id="railSources">No retrieved sources in the last response.</div>
            <div id="railSourceList"></div>
        </div>

        <div class="cw-rail-body" id="railExecution" hidden>
            <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ly-text-4);margin-bottom:12px">Pipeline</div>
            <?php foreach (['Plan','Build','Verify','Deliver'] as $st): ?>
            <div class="cw-kv"><span><?php echo $st; ?></span><span class="ly-muted" id="ex<?php echo $st; ?>">—</span></div>
            <?php endforeach; ?>
            <div style="border-top:1px solid var(--ly-border);margin:16px 0"></div>
            <div class="cw-empty">
                Stage detail is reported by the orchestration layer. Values populate from the
                response metadata when a request has been executed; they are not simulated.
            </div>
        </div>
    </aside>
</div>

<script>
(function () {
  "use strict";
  var API = "/api/chat.php";
  var history = [];
  var inFlight = false;

  var $ = function (id) { return document.getElementById(id); };
  var input = $("input"), sendBtn = $("send"), thread = $("thread"), scroll = $("scroll");
  var MODEL = "lyralink-fast:latest";

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }
  function scrollDown() { if (scroll) scroll.scrollTop = scroll.scrollHeight; }
  function setStatus(t) { var s = $("status"); if (s) s.textContent = t || ""; }

  function pipeline(stage) {
    var order = ["plan", "build", "verify", "deliver"];
    var upto = order.indexOf(stage);
    document.querySelectorAll("[data-pipe]").forEach(function (n) {
      var i = order.indexOf(n.getAttribute("data-pipe"));
      n.classList.toggle("ly-step-done", upto >= 0 && i <= upto);
      n.classList.toggle("ly-step-todo", !(upto >= 0 && i <= upto));
    });
  }

  /* Honest failure text, mirroring the main chat client: name the state, say
     whether anything executed, and give a next action. */
  function failureText(code) {
    var known = {
      timeout:  "The model did not finish in time. Nothing was executed or changed. Try a shorter request.",
      rate_limited: "Too many requests right now. Nothing was executed or changed. Wait a moment and resend.",
      usage_limit: "The usage limit for this period was reached. Nothing was executed or changed."
    };
    if (known[code]) return known[code];
    if (code) return "The request did not complete (" + code + "). Nothing was executed or changed. Resend to try again.";
    return "The request returned no output, so nothing was executed or changed. Resend to try again.";
  }

  function addMessage(role, text, meta) {
    var wrap = el("div", "cw-msg");
    var who = el("div", "cw-who");
    if (role === "user") {
      who.appendChild(el("span", "ly-avatar ly-avatar-sm", "You".charAt(0)));
    } else {
      var av = el("span", "ly-avatar ly-avatar-sm");
      av.style.background = "var(--ly-grad)";
      av.textContent = "L";
      who.appendChild(av);
    }
    var body = el("div", "cw-bubble");
    var name = el("div", "cw-name", (role === "user" ? "You" : "Lyralink") +
      (meta && meta.model ? '<span>' + esc(meta.model) + '</span>' : ""));
    body.appendChild(name);
    body.appendChild(el("div", "cw-text", esc(text)));

    if (meta && meta.tools && meta.tools.length) {
      var card = el("div", "cw-toolcard");
      var h = el("div", "h");
      h.appendChild(el("span", "ly-dot ly-dot-online"));
      h.appendChild(el("span", null, "Tool Execution"));
      card.appendChild(h);
      meta.tools.forEach(function (t) {
        var row = el("div", "cw-toolrow");
        row.appendChild(el("span", null, esc(t)));
        row.appendChild(el("span", "ly-spacer"));
        row.appendChild(el("span", "ly-status ly-status-online", "Completed"));
        card.appendChild(row);
      });
      body.appendChild(card);
    }

    wrap.appendChild(who);
    wrap.appendChild(body);
    thread.appendChild(wrap);
    scrollDown();
  }

  function send() {
    if (inFlight) return;
    var text = (input.value || "").trim();
    if (!text) return;

    var welcome = $("welcome"), acts = $("actions"), sugg = $("suggests");
    if (welcome) welcome.hidden = true;
    if (acts) acts.hidden = true;
    if (sugg) sugg.hidden = true;

    addMessage("user", text);
    history.push({ role: "user", content: text });
    input.value = "";
    if (input.style) input.style.height = "24px";

    inFlight = true;
    sendBtn.disabled = true;
    setStatus("Generating…");
    pipeline("build");

    var wantWeb = $("webToggle") && $("webToggle").classList.contains("is-on");
    var t0 = performance.now();

    fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        messages: history.slice(-12),
        provider: "local",
        model: MODEL,
        max_tokens: 900,
        temperature: 0.7,
        web_search: !!wantWeb
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var ms = Math.round(performance.now() - t0);
      var reply = (typeof d.reply === "string" && d.reply.trim() !== "")
        ? d.reply
        : (typeof d.raw_output === "string" && d.raw_output.trim() !== "" ? d.raw_output : "");

      if (!reply) {
        addMessage("assistant", failureText(d.error));
        pipeline("plan");
        setStatus("");
        return;
      }

      var ex = d.execution || {};
      var ts = ex.tool_execution_state || {};
      var tools = [];
      if (ts.tool_name && ts.tool_execution_started) tools.push(String(ts.tool_name));
      if (Array.isArray(d.tools_used)) tools = tools.concat(d.tools_used.map(String));

      addMessage("assistant", reply, { model: d.model || MODEL, tools: tools });
      history.push({ role: "assistant", content: reply });

      // rail: real values only
      if ($("railEmpty")) $("railEmpty").hidden = true;
      if ($("railStats")) $("railStats").hidden = false;
      if ($("sModel")) $("sModel").textContent = d.model || MODEL;
      if ($("sTools")) $("sTools").textContent = tools.length ? tools.join(", ") : "none";
      if ($("sLatency")) $("sLatency").textContent = ms + " ms";
      if ($("sLen")) $("sLen").textContent = reply.length + " chars";

      // sources
      var srcs = (d.debug && Array.isArray(d.debug.web_results)) ? d.debug.web_results : [];
      var list = $("railSourceList"), empty = $("railSources");
      if (list) list.innerHTML = "";
      if (srcs.length && list) {
        if (empty) empty.hidden = true;
        srcs.slice(0, 5).forEach(function (s) {
          var a = el("a", "cw-src");
          a.href = s.url || "#";
          a.target = "_blank";
          a.rel = "noopener noreferrer";
          a.appendChild(el("span", null, esc(s.title || s.url || "source")));
          list.appendChild(a);
        });
      } else if (empty) {
        empty.hidden = false;
      }

      pipeline("deliver");
      setStatus("");
    })
    .catch(function () {
      addMessage("assistant", "The request could not reach the server. Nothing was executed or changed. Check your connection and resend.");
      pipeline("plan");
      setStatus("");
    })
    .then(function () {
      inFlight = false;
      sendBtn.disabled = false;
      input.focus();
    });
  }

  sendBtn.addEventListener("click", send);
  input.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); }
  });

  document.querySelectorAll("[data-seed]").forEach(function (b) {
    b.addEventListener("click", function () {
      input.value = b.getAttribute("data-seed") || "";
      input.focus();
    });
  });

  var wb = $("webToggle");
  if (wb) wb.addEventListener("click", function () { wb.classList.toggle("is-on"); });

  var nc = $("newChat");
  if (nc) nc.addEventListener("click", function () {
    history = [];
    thread.innerHTML = "";
    if ($("welcome")) $("welcome").hidden = false;
    if ($("actions")) $("actions").hidden = false;
    if ($("suggests")) $("suggests").hidden = false;
    if ($("railStats")) $("railStats").hidden = true;
    if ($("railEmpty")) $("railEmpty").hidden = false;
    if ($("railSources")) $("railSources").hidden = false;
    if ($("railSourceList")) $("railSourceList").innerHTML = "";
    pipeline("");
    setStatus("");
  });

  // rail tabs
  document.querySelectorAll("[data-rail]").forEach(function (t) {
    t.addEventListener("click", function () {
      var key = t.getAttribute("data-rail");
      document.querySelectorAll("[data-rail]").forEach(function (o) {
        o.classList.toggle("is-active", o === t);
      });
      if ($("railContext")) $("railContext").hidden = (key !== "context");
      if ($("railExecution")) $("railExecution").hidden = (key !== "execution");
    });
  });

  var pill = $("pillTools");
  if (pill) pill.textContent = "5";

  input.focus();
})();
</script>

</body>
</html>
