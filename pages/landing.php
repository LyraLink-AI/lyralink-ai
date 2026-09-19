<?php
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
/* Marketing landing page. Implements the approved Splashpage design.
   Self-contained by convention (the project has no shared header partial);
   shared visual language comes from /assets/css/lyra-ui.css. */

$lyraMark = '<svg class="ly-logo-mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">'
    . '<defs><linearGradient id="lmg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">'
    . '<stop offset="0" stop-color="#8B5CF6"/><stop offset="0.55" stop-color="#6C3AF8"/><stop offset="1" stop-color="#4C1FD6"/>'
    . '</linearGradient></defs>'
    . '<path d="M16 2.6c1.5 0 2.9.4 4.1 1.1l7.2 4.2c2.2 1.3 3.6 3.7 3.6 6.3v6.6c0 2.6-1.4 5-3.6 6.3l-7.2 4.2c-1.2.7-2.6 1.1-4.1 1.1s-2.9-.4-4.1-1.1l-7.2-4.2C2.5 26.4 1.1 24 1.1 21.4v-6.6c0-2.6 1.4-5 3.6-6.3l7.2-4.2C13.1 3 14.5 2.6 16 2.6Z" fill="url(#lmg)"/>'
    . '<path d="M12.4 10.2h3.1v7.2h4.6v2.9h-7.7V10.2Z" fill="#fff" fill-opacity="0.95"/>'
    . '<circle cx="22.6" cy="11.9" r="2.1" fill="#fff" fill-opacity="0.72"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink | The AI operating system for real work</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="description" content="Lyralink is your personal and enterprise AI infrastructure platform. It understands your goals, researches when needed, uses the right tools, executes the work, verifies the results, and remembers context.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://lyralinkai.com/">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://lyralinkai.com/">
    <meta property="og:title" content="Lyralink | The AI operating system for real work">
    <meta property="og:description" content="More than a chatbot. An AI workspace that plans, researches, executes and verifies.">
    <meta name="twitter:card" content="summary_large_image">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        /* ── page-specific ─────────────────────────────────────────────── */
        .lp-nav { position: sticky; top: 0; z-index: 60; }
        .lp-hero { padding: 72px 0 96px; }
        .lp-hero-grid {
            display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.06fr);
            gap: 56px; align-items: center;
        }
        .lp-h1 {
            font-size: clamp(34px, 4.6vw, 58px); line-height: 1.08;
            letter-spacing: -0.035em; margin: 0 0 20px;
        }
        .lp-trust { display: flex; flex-wrap: wrap; gap: 22px; margin-top: 26px; }
        .lp-trust span { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--ly-text-3); }

        /* App mockup window */
        .lp-app {
            display: grid; grid-template-columns: 168px minmax(0,1fr) 212px;
            background: var(--ly-bg); border: 1px solid var(--ly-border-2);
            border-radius: var(--ly-r-xl); overflow: hidden;
            box-shadow: 0 40px 90px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03);
            min-height: 380px;
        }
        .lp-app-side { background: var(--ly-rail); border-right: 1px solid var(--ly-border); padding: 14px 10px; }
        .lp-app-side .ly-navitem { padding: 7px 9px; font-size: 12.5px; }
        .lp-app-main { padding: 18px; display: flex; flex-direction: column; gap: 14px; }
        .lp-app-rail { background: var(--ly-rail); border-left: 1px solid var(--ly-border); padding: 14px 12px; }
        .lp-app-bar { display:flex; align-items:center; gap:8px; font-size:11.5px; color: var(--ly-text-3); }
        .lp-app-input {
            margin-top: auto; display:flex; align-items:center; gap:10px;
            background: var(--ly-surface); border:1px solid var(--ly-border-2);
            border-radius: var(--ly-r-md); padding: 11px 12px; font-size: 12.5px; color: var(--ly-text-4);
        }
        .lp-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .lp-chip {
            display:inline-flex; align-items:center; gap:6px; padding:5px 11px;
            border:1px solid var(--ly-border-2); border-radius: var(--ly-r-full);
            font-size:11.5px; color: var(--ly-text-2); background: var(--ly-glass);
        }
        .lp-wf {
            display:flex; align-items:center; gap:10px; padding:12px 14px;
            border:1px solid var(--ly-primary-line); border-radius: var(--ly-r-md);
            background: var(--ly-primary-soft); font-size:12px; color: var(--ly-text);
        }
        .lp-rail-title { font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color: var(--ly-text-4); margin: 0 0 10px; }
        .lp-tool { display:flex; align-items:center; gap:8px; padding:6px 0; font-size:12px; color: var(--ly-text-2); }
        .lp-tool .ly-dot { margin-left:auto; }

        .lp-feat { display:grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 0; }
        .lp-feat > div { padding: 0 22px; border-left: 1px solid var(--ly-border); }
        .lp-feat > div:first-child { border-left: 0; padding-left: 0; }

        .lp-cta-panel {
            display:grid; grid-template-columns: minmax(0,1fr) minmax(0,1.25fr) minmax(0,0.9fr);
            gap: 32px; align-items:center;
            border:1px solid var(--ly-border); border-radius: var(--ly-r-2xl);
            background: linear-gradient(135deg, rgba(80,40,224,.10), rgba(2,9,26,0) 55%), var(--ly-glass);
            padding: 40px;
        }
        .lp-check { display:flex; align-items:center; gap:10px; font-size:13.5px; color: var(--ly-text-2); margin-bottom:12px; }
        .lp-code {
            background: var(--ly-bg-deep); border:1px solid var(--ly-border); border-radius: var(--ly-r-lg);
            padding: 16px; font-family: var(--ly-mono); font-size: 11.5px; line-height:1.75; color: var(--ly-text-2);
        }
        .lp-code .k { color:#C4B5FD; } .lp-code .s { color:#6EE7A0; } .lp-code .c { color: var(--ly-text-4); }
        .lp-quote { font-size: 14px; color: var(--ly-text-2); font-style: italic; line-height: 1.7; }

        @media (max-width: 1180px) {
            .lp-app { grid-template-columns: 150px minmax(0,1fr); }
            .lp-app-rail { display:none; }
        }
        @media (max-width: 980px) {
            .lp-hero-grid { grid-template-columns: minmax(0,1fr); gap: 40px; }
            .lp-feat { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 28px 0; }
            .lp-feat > div:nth-child(odd) { border-left: 0; padding-left: 0; }
            .lp-cta-panel { grid-template-columns: minmax(0,1fr); padding: 26px; }
        }
        @media (max-width: 620px) {
            .lp-app { grid-template-columns: minmax(0,1fr); }
            .lp-app-side { display:none; }
            .lp-feat { grid-template-columns: minmax(0,1fr); }
            .lp-feat > div { border-left:0; padding: 0; }
        }
    </style>
</head>
<body class="ly">

<!-- ══ TOP NAV ══ -->
<nav class="ly-topnav lp-nav">
    <a class="ly-logo" href="/"><?php echo $lyraMark; ?> Lyralink</a>
    <div class="ly-navlinks">
        <a class="ly-navlink is-active" href="/">Home</a>
        <a class="ly-navlink" href="/#features">Features</a>
        <a class="ly-navlink" href="/pages/pricing/">Pricing</a>
        <a class="ly-navlink" href="/pages/api_docs/">Docs</a>
        <a class="ly-navlink" href="/pages/about/">About</a>
    </div>
    <div class="ly-spacer"></div>
    <a class="ly-btn ly-btn-quiet ly-btn-icon" href="#" aria-label="Toggle theme" title="Theme">
        <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </a>
    <a class="ly-btn ly-btn-ghost ly-btn-sm" href="/pages/login.php">Sign In</a>
    <a class="ly-btn ly-btn-primary ly-btn-sm" href="/pages/login.php">Get Started Free
        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </a>
</nav>

<!-- ══ HERO ══ -->
<header class="ly-wrap lp-hero">
    <div class="lp-hero-grid">
        <div>
            <span class="ly-pill ly-mb-5" style="display:inline-flex">
                <span class="ly-dot ly-dot-online"></span> Next-Gen AI Infrastructure
            </span>
            <h1 class="lp-h1">
                <span class="ly-grad-text">More</span> than a chatbot.<br>
                It&rsquo;s your AI <span class="ly-grad-text">workspace</span>.
            </h1>
            <p class="ly-lead" style="max-width:560px">
                Lyralink is your personal and enterprise AI infrastructure platform. It understands your
                goals, researches when needed, uses the right tools, executes the work, verifies the
                results, and remembers context &mdash; so you don&rsquo;t have to.
            </p>
            <div class="ly-row ly-mt-6" style="gap:14px;flex-wrap:wrap">
                <a class="ly-btn ly-btn-primary ly-btn-lg" href="/pages/login.php">Start For Free
                    <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a class="ly-btn ly-btn-ghost ly-btn-lg" href="#features">Explore Features</a>
            </div>
            <div class="lp-trust">
                <?php foreach (['No credit card required','Free plan available','Open source &amp; self-hostable'] as $t): ?>
                <span>
                    <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="#6EE7A0" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <?php echo $t; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- App mockup -->
        <div class="lp-app" role="img" aria-label="Lyralink workspace interface preview">
            <div class="lp-app-side">
                <div class="ly-row" style="gap:8px;padding:4px 6px 12px">
                    <svg class="ly-logo-mark" viewBox="0 0 32 32" fill="none" style="width:20px;height:20px"><path d="M16 2.6c1.5 0 2.9.4 4.1 1.1l7.2 4.2c2.2 1.3 3.6 3.7 3.6 6.3v6.6c0 2.6-1.4 5-3.6 6.3l-7.2 4.2c-1.2.7-2.6 1.1-4.1 1.1s-2.9-.4-4.1-1.1l-7.2-4.2C2.5 26.4 1.1 24 1.1 21.4v-6.6c0-2.6 1.4-5 3.6-6.3l7.2-4.2C13.1 3 14.5 2.6 16 2.6Z" fill="#6C3AF8"/></svg>
                    <strong style="font-size:12.5px;letter-spacing:-.02em">Lyralink</strong>
                </div>
                <?php
                $sideItems = [['Home','M3 10.5 12 3l9 7.5V21H3z'],['Chat','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z'],['Projects','M3 7h7l2 2h9v10H3z'],['Automations','M13 2 4 14h7l-1 8 9-12h-7z'],['Knowledge','M4 5h16v14H4z'],['Files','M6 3h8l4 4v14H6z']];
                foreach ($sideItems as $i => $it): ?>
                <a class="ly-navitem<?php echo $i === 1 ? ' is-active' : ''; ?>" href="#">
                    <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $it[1]; ?>"/></svg>
                    <?php echo $it[0]; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="lp-app-main">
                <div class="lp-app-bar">
                    <span class="ly-badge">Llama 3.3 70B</span>
                    <span><span class="ly-dot ly-dot-online"></span> Production</span>
                    <span class="ly-spacer"></span>
                    <span class="ly-badge ly-badge-primary">Task Mode</span>
                </div>

                <div>
                    <div style="font-size:17px;font-weight:700;letter-spacing:-.025em">Good morning, Alex</div>
                    <div style="font-size:12.5px;color:var(--ly-text-3)">What would you like to work on today?</div>
                </div>

                <div class="lp-app-input">
                    Describe your goal or ask a question&hellip;
                    <span style="margin-left:auto;display:flex;gap:8px;color:var(--ly-text-4)">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 3v18M3 12h18"/></svg>
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </span>
                </div>

                <div class="lp-chips">
                    <?php foreach (['Search','Analyze','Code','Create','More'] as $c): ?>
                    <span class="lp-chip">
                        <svg class="ly-ico ly-ico-sm" style="width:12px;height:12px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <?php echo $c; ?>
                    </span>
                    <?php endforeach; ?>
                </div>

                <div class="lp-wf">
                    <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="var(--ly-primary-text)" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/><circle cx="12" cy="12" r="4"/></svg>
                    <span><strong>Example workflow</strong><br>
                    <span style="color:var(--ly-text-3);font-size:11.5px">Research &rarr; Plan &rarr; Build &rarr; Deliver</span></span>
                    <span class="ly-spacer"></span>
                    <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="var(--ly-text-3)" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </div>
            </div>

            <div class="lp-app-rail">
                <div class="ly-row-between ly-mb-3">
                    <span class="lp-rail-title" style="margin:0">Active Context</span>
                </div>
                <div style="border:1px solid var(--ly-border);border-radius:var(--ly-r-md);padding:10px;margin-bottom:14px">
                    <div style="font-size:10.5px;color:var(--ly-text-4);margin-bottom:6px">Current Model</div>
                    <div class="ly-row" style="gap:8px">
                        <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad)">L</span>
                        <span style="font-size:11.5px">Llama 3.3 70B</span>
                        <span class="ly-status ly-status-online" style="margin-left:auto;font-size:10.5px">Online</span>
                    </div>
                </div>
                <div class="lp-rail-title">Tools</div>
                <?php foreach (['Web Search','Code Interpreter','File System','Database','Image Generation'] as $i => $t): ?>
                <div class="lp-tool">
                    <svg class="ly-ico ly-ico-sm" style="width:13px;height:13px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <?php echo $t; ?>
                    <?php if ($i < 4): ?><span class="ly-status ly-status-online" style="font-size:10.5px">Active</span>
                    <?php else: ?><span class="ly-muted" style="font-size:10.5px">Idle</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="lp-rail-title" style="margin-top:16px">Task Progress</div>
                <div style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:6px">Research &amp; Analysis</div>
                <div class="ly-bar"><span style="width:94%"></span></div>
                <div style="font-size:10.5px;color:var(--ly-text-4);margin-top:5px">94%</div>
            </div>
        </div>
    </div>
</header>

<!-- ══ FEATURES ══ -->
<section class="ly-section" id="features" style="border-top:1px solid var(--ly-border)">
    <div class="ly-wrap ly-center">
        <span class="ly-eyebrow">Everything you need, in one place</span>
        <h2 style="font-size:clamp(26px,3vw,36px)">Built for real work</h2>
        <p class="ly-lead" style="max-width:660px;margin:0 auto var(--ly-s9)">
            Lyralink combines the power of advanced AI with the tools you already use,
            giving you a flexible, secure, and scalable platform for any project.
        </p>

        <div class="lp-feat ly-center">
            <?php
            $features = [
                ['brain',   'Smart Orchestration', 'Chooses the best model, tools, and approach for every task &mdash; automatically.'],
                ['search',  'Deep Research',       'Searches the web, reads, analyzes, and finds what you need.'],
                ['code',    'Built-in Tools',      'Code, files, database, automation, and more &mdash; all in one place.'],
                ['shield',  'Secure &amp; Private',    'Your data stays yours. Self-hostable, open source, and built for privacy.'],
                ['bolt',    'Across All Devices',  'Use it in your browser, desktop app, or mobile &mdash; wherever you are.'],
            ];
            $icons = [
                'brain'  => '<path d="M12 5a3 3 0 0 0-3 3 3 3 0 0 0-3 3 3 3 0 0 0 1 5 3 3 0 0 0 5 2V5Z"/><path d="M12 5a3 3 0 0 1 3 3 3 3 0 0 1 3 3 3 3 0 0 1-1 5 3 3 0 0 1-5 2"/>',
                'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
                'code'   => '<path d="m8 6-6 6 6 6M16 6l6 6-6 6"/>',
                'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z"/>',
                'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
            ];
            foreach ($features as $f): ?>
            <div>
                <div class="ly-tile ly-tile-lg" style="margin:0 auto var(--ly-s4)">
                    <svg class="ly-ico ly-ico-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><?php echo $icons[$f[0]]; ?></svg>
                </div>
                <h3 style="font-size:15px;margin-bottom:8px"><?php echo $f[1]; ?></h3>
                <p style="font-size:13px;color:var(--ly-text-3);margin:0"><?php echo $f[2]; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ CTA / WORKFLOW ══ -->
<section class="ly-section-sm">
    <div class="ly-wrap">
        <div class="lp-cta-panel">
            <div>
                <span class="ly-badge ly-badge-primary ly-mb-3">Work Smarter</span>
                <h2 style="font-size:26px;margin-bottom:10px">Your ideas. Ambiently amplified.</h2>
                <p style="font-size:13.5px;color:var(--ly-text-3);margin-bottom:20px">
                    From simple questions to complex projects, Lyralink turns your goals into results.
                </p>
                <?php foreach (['Plan your workflow','Let Lyralink handle the rest','Ship verified results'] as $c): ?>
                <div class="lp-check">
                    <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="var(--ly-primary-text)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <?php echo $c; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div>
                <div class="ly-steps ly-mb-5" style="justify-content:center">
                    <?php
                    $steps = ['Plan','Build','Verify','Deliver'];
                    foreach ($steps as $i => $s): ?>
                        <span class="ly-step <?php echo $i === 0 ? 'ly-step-done' : 'ly-step-todo'; ?>">
                            <span class="ly-step-mark"><?php echo $i < 1 ? '&#10003;' : $i + 1; ?></span><?php echo $s; ?>
                        </span>
                        <?php if ($i < 3): ?><span class="ly-step-line<?php echo $i === 0 ? ' is-done' : ''; ?>"></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="ly-code lp-code">
                    <div><span class="c"># lyralink workflow</span></div>
                    <div><span class="k">def</span> <span style="color:#7DD3FC">automate</span>():</div>
                    <div>&nbsp;&nbsp;<span class="k">return</span> <span class="s">"More time for what matters."</span></div>
                </div>
            </div>

            <div>
                <div class="ly-card">
                    <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="var(--ly-primary-text)" stroke-linecap="round" style="margin-bottom:10px"><path d="M7 7h4v4H7zM13 13h4v4h-4z"/><path d="M11 9h2v2M13 11v2"/></svg>
                    <p class="lp-quote">&ldquo;Lyralink helps me get more done, without the constant back and forth.&rdquo;</p>
                    <div class="ly-row ly-mt-5" style="gap:10px">
                        <span class="ly-avatar ly-avatar-sm">AW</span>
                        <div>
                            <div style="font-size:12.5px;font-weight:600">&mdash; Alex W.</div>
                            <div style="font-size:11px;color:var(--ly-text-4)">Operator</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ FOOTER ══ -->
<footer style="border-top:1px solid var(--ly-border);margin-top:56px">
    <div class="ly-wrap" style="padding:36px 24px">
        <div class="ly-row-between" style="flex-wrap:wrap;gap:20px">
            <div class="ly-row" style="gap:10px">
                <svg class="ly-logo-mark" viewBox="0 0 32 32" fill="none" style="width:24px;height:24px"><path d="M16 2.6c1.5 0 2.9.4 4.1 1.1l7.2 4.2c2.2 1.3 3.6 3.7 3.6 6.3v6.6c0 2.6-1.4 5-3.6 6.3l-7.2 4.2c-1.2.7-2.6 1.1-4.1 1.1s-2.9-.4-4.1-1.1l-7.2-4.2C2.5 26.4 1.1 24 1.1 21.4v-6.6c0-2.6 1.4-5 3.6-6.3l7.2-4.2C13.1 3 14.5 2.6 16 2.6Z" fill="#6C3AF8"/></svg>
                <div>
                    <div style="font-weight:800;letter-spacing:-.03em">Lyralink</div>
                    <div style="font-size:11.5px;color:var(--ly-text-4)">Next-Gen AI Infrastructure</div>
                </div>
            </div>
            <div class="ly-row" style="gap:22px;flex-wrap:wrap;font-size:12.5px">
                <a href="/pages/privacy.php" class="ly-muted">Privacy</a>
                <a href="/pages/tos.php" class="ly-muted">Terms</a>
                <a href="/pages/status.php" class="ly-muted">Status</a>
                <a href="/pages/careers.php" class="ly-muted">Careers</a>
                <a href="/pages/support.php" class="ly-muted">Support</a>
            </div>
            <div style="font-size:11.5px;color:var(--ly-text-4)">&copy; <?php echo date('Y'); ?> Lyralink AI</div>
        </div>
    </div>
</footer>

</body>
</html>
