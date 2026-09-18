<?php
if (file_exists(__DIR__ . '/maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php');
    exit;
}

if (!empty($_GET['ref']) && preg_match('/^[a-f0-9]{48}$/', $_GET['ref'])) {
    setcookie('reseller_ref', $_GET['ref'], time() + 30 * 86400, '/', '', true, true);
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['lyralinkai.com', 'www.lyralinkai.com'], true);
$forkModeEnv = getenv('FORK_MODE') ?: ($_ENV['FORK_MODE'] ?? '');
$isForkMode = ($forkModeEnv === '1') || ($host !== '' && !$isPrimaryHost);
if ($isForkMode) {
    header('Location: /pages/admin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Infrastructure | Powered by LyralinkAI</title>
    <link rel="icon" type="image/x-icon" href="images/lyralinkai.ico">
    <meta name="description" content="Lyralink gives operators and teams a calmer path to launching AI chat, API access, and branded client experiences without rebuilding the backend stack.">
    <meta name="keywords" content="white-label AI infrastructure, AI SaaS reseller, branded AI chat, operator AI platform, Lyralink, LyralinkAI">
    <meta name="author" content="LyralinkAI">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://lyralinkai.com/">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://lyralinkai.com/">
    <meta property="og:title" content="Lyralink Infrastructure">
    <meta property="og:description" content="AI infrastructure for operators who want a usable product, API, and billing layer without the usual rebuild.">
    <meta property="og:image" content="https://lyralinkai.com/assets/og-image.png">
    <meta property="og:site_name" content="Lyralink">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Lyralink Infrastructure">
    <meta name="twitter:description" content="Launch a more polished AI product with chat, API access, and operator tooling already connected.">
    <meta name="twitter:image" content="https://lyralinkai.com/assets/og-image.png">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "Lyralink",
        "url": "https://lyralinkai.com",
        "description": "AI infrastructure for launching branded chat, API, and operator workflows.",
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web",
        "offers": {
            "@type": "Offer",
            "price": "5",
            "priceCurrency": "USD"
        },
        "author": {
            "@type": "Organization",
            "name": "LyralinkAI",
            "url": "https://lyralinkai.com"
        }
    }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f1e8;
            --surface: rgba(255, 252, 246, 0.82);
            --border: rgba(57, 53, 45, 0.12);
            --text: #211f1a;
            --text-soft: #5f5a51;
            --text-dim: #7a756c;
            --accent: #1e6b63;
            --accent-soft: #d6ece8;
            --accent-ink: #0f3d39;
            --secondary: #a55f3f;
            --secondary-soft: #f4dfd4;
            --shadow: 0 24px 60px rgba(57, 53, 45, 0.08);
            --radius: 24px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'DM Mono', monospace;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(30,107,99,0.08), transparent 36%),
                radial-gradient(circle at bottom right, rgba(165,95,63,0.08), transparent 28%),
                linear-gradient(180deg, #f7f3ea 0%, #f1ebdf 100%);
            min-height: 100vh;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image: linear-gradient(rgba(255,255,255,0.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.08) 1px, transparent 1px);
            background-size: 32px 32px;
            mask-image: linear-gradient(180deg, rgba(0,0,0,0.28), transparent 70%);
            opacity: 0.35;
        }
        a { color: inherit; }
        section { position: relative; z-index: 1; }
        .container { max-width: 1180px; margin: 0 auto; padding: 0 24px; }
        nav {
            position: sticky;
            top: 0;
            z-index: 30;
            backdrop-filter: blur(14px);
            background: rgba(8, 8, 17, 0.82);
            border-bottom: 1px solid rgba(124, 58, 237, 0.12);
        }
        .nav-inner { max-width: 1180px; margin: 0 auto; padding: 16px 24px; display: flex; align-items: center; gap: 18px; }
        .nav-logo { height: 30px; width: auto; }
        .nav-links { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .nav-link { text-decoration: none; color: var(--text-soft); font-size: 12px; padding: 9px 14px; border-radius: 999px; transition: background 0.2s ease, color 0.2s ease; }
        .nav-link:hover { background: rgba(33,31,26,0.06); color: var(--text); }
        .nav-link.primary { background: var(--accent); color: #f7fbfa; box-shadow: 0 10px 24px rgba(30,107,99,0.18); }
        .nav-link.primary:hover { background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; }
        .hero { padding: 56px 0 28px; }
        .hero-grid { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 30px; align-items: start; }
        .hero-copy, .hero-panel, .section-card, .timeline, .faq-shell, .footer-shell { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
        .hero-copy { padding: 46px 44px 40px; display: flex; flex-direction: column; justify-content: space-between; min-height: 560px; border-radius: 30px; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: rgba(30,107,99,0.08); color: var(--accent-ink); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; width: fit-content; }
        .eyebrow::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--accent); }
        h1 { font-family: 'Syne', sans-serif; font-size: clamp(42px, 6vw, 76px); line-height: 0.98; letter-spacing: -0.04em; margin: 24px 0 20px; max-width: 10ch; }
        .hero-copy h1 span { color: var(--accent); }
        .hero-sub { max-width: 58ch; color: var(--text-soft); font-size: 15px; line-height: 1.9; }
        .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; margin: 28px 0 22px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; border-radius: 14px; padding: 14px 18px; font-size: 13px; transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--accent); color: #f8fbfb; border: 1px solid transparent; }
        .btn-primary:hover { background: #8b5cf6; }
        .btn-secondary { background: rgba(255,255,255,0.46); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: rgba(255,255,255,0.75); }
        .hero-notes { display: flex; flex-wrap: wrap; gap: 10px; }
        .note-pill { padding: 9px 12px; border-radius: 999px; background: rgba(255,255,255,0.04); border: 1px solid rgba(124,58,237,0.12); color: var(--text-soft); font-size: 11px; }
        .trust-strip { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
        .trust-card { display: block; text-decoration: none; background: rgba(255,255,255,0.035); border: 1px solid rgba(56,189,248,0.16); border-radius: 18px; padding: 14px; transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease; }
        .trust-card:hover { transform: translateY(-1px); border-color: rgba(56,189,248,0.32); background: rgba(56,189,248,0.08); }
        .trust-card strong { display: block; font-family: 'Syne', sans-serif; font-size: 16px; margin-bottom: 6px; }
        .trust-card span { color: var(--text-soft); font-size: 11px; line-height: 1.7; }
        .trust-meta { color: var(--text-dim); font-size: 11px; line-height: 1.8; margin-bottom: 20px; max-width: 62ch; }
        .hero-panel { padding: 24px; display: grid; gap: 18px; margin-top: 26px; border-radius: 30px; }
        .workspace-card { background: linear-gradient(180deg, rgba(18, 16, 34, 0.96), rgba(9, 9, 16, 0.98)); border: 1px solid rgba(124,58,237,0.20); border-radius: 24px 24px 18px 30px; padding: 22px; }
        .workspace-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 16px; }
        .workspace-title { font-family: 'Syne', sans-serif; font-size: 22px; line-height: 1.1; }
        .workspace-tag { background: rgba(56,189,248,0.12); color: #c7f2ff; border-radius: 999px; padding: 8px 10px; font-size: 11px; white-space: nowrap; }
        .workspace-list { display: grid; gap: 12px; }
        .workspace-item { display: grid; grid-template-columns: 92px 1fr; gap: 12px; padding: 12px 0; border-top: 1px solid rgba(124,58,237,0.12); }
        .workspace-item:first-child { border-top: 0; padding-top: 0; }
        .workspace-kicker { font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); padding-top: 2px; }
        .workspace-item strong { display: block; font-size: 14px; margin-bottom: 4px; }
        .workspace-item p { font-size: 12px; line-height: 1.7; color: var(--text-soft); }
        .signal-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .signal { background: rgba(255,255,255,0.04); border: 1px solid rgba(124,58,237,0.18); border-radius: 20px 14px 22px 16px; padding: 16px; }
        .signal strong { font-family: 'Syne', sans-serif; font-size: 24px; display: block; margin-bottom: 6px; }
        .signal span { color: var(--text-soft); font-size: 11px; line-height: 1.6; }
        .section-block { padding: 18px 0; }
        .section-header { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 430px); gap: 20px; align-items: end; margin-bottom: 18px; }
        .section-kicker { color: var(--accent); font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 10px; }
        .section-title { font-family: 'Syne', sans-serif; font-size: clamp(28px, 4vw, 46px); line-height: 1.02; letter-spacing: -0.04em; max-width: 12ch; }
        .section-title span { color: var(--secondary); }
        .section-desc { color: var(--text-soft); font-size: 14px; line-height: 1.85; }
        .three-up { display: grid; grid-template-columns: 1.05fr 0.95fr 1fr; gap: 18px; align-items: start; }
        .section-card { padding: 22px; border-radius: 28px 20px 26px 18px; }
        .three-up .section-card:nth-child(2) { margin-top: 18px; }
        .three-up .section-card:nth-child(3) { margin-top: 34px; }
        .card-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-dim); margin-bottom: 14px; }
        .section-card h3 { font-family: 'Syne', sans-serif; font-size: 24px; margin-bottom: 10px; }
        .section-card p, .section-card li { color: var(--text-soft); font-size: 13px; line-height: 1.8; }
        .section-card ul { list-style: none; display: grid; gap: 10px; margin-top: 14px; }
        .section-card li::before { content: '•'; color: var(--accent); margin-right: 8px; }
        .split-panel { display: grid; grid-template-columns: 1fr 0.92fr; gap: 20px; align-items: start; }
        .narrative { padding: 30px 30px 28px; }
        .narrative p { color: var(--text-soft); line-height: 1.9; font-size: 14px; margin-top: 16px; max-width: 60ch; }
        .mini-grid { display: grid; grid-template-columns: 1.05fr 0.95fr; gap: 14px; }
        .mini-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(124,58,237,0.16); border-radius: 18px 22px 16px 20px; padding: 18px; }
        .mini-card strong { font-family: 'Syne', sans-serif; font-size: 20px; display: block; margin-bottom: 6px; }
        .mini-card p { color: var(--text-soft); font-size: 12px; line-height: 1.7; }
        .timeline { padding: 26px 24px; display: grid; gap: 18px; border-radius: 28px 18px 26px 22px; }
        .timeline-step { display: grid; grid-template-columns: 40px 1fr; gap: 14px; align-items: start; padding-top: 16px; border-top: 1px solid rgba(124,58,237,0.18); }
        .timeline-step:first-child { border-top: 0; padding-top: 0; }
        .timeline-num { width: 40px; height: 40px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-family: 'Syne', sans-serif; color: var(--accent-ink); background: rgba(124,58,237,0.18); }
        .timeline-step strong { display: block; font-size: 15px; margin-bottom: 6px; }
        .timeline-step p { color: var(--text-soft); font-size: 13px; line-height: 1.8; }
        .faq-shell { padding: 24px 22px; border-radius: 28px 22px 30px 18px; }
        .faq-item { border-top: 1px solid rgba(124,58,237,0.18); padding: 16px 0; }
        .faq-item:first-child { border-top: 0; }
        .faq-q { display: flex; justify-content: space-between; gap: 12px; cursor: pointer; font-family: 'Syne', sans-serif; font-size: 18px; }
        .faq-a { display: none; padding-top: 10px; color: var(--text-soft); font-size: 13px; line-height: 1.85; max-width: 70ch; }
        .faq-item.open .faq-a { display: block; }
        .cta-wrap { padding: 8px 0 32px; }
        .cta-card { background: linear-gradient(135deg, rgba(124,58,237,0.22), rgba(56,189,248,0.10)); border: 1px solid rgba(124,58,237,0.28); border-radius: 34px 22px 30px 18px; padding: 30px; display: flex; justify-content: space-between; gap: 18px; align-items: center; box-shadow: var(--shadow); }
        .cta-card h3 { font-family: 'Syne', sans-serif; font-size: clamp(26px, 4vw, 40px); line-height: 1.02; margin-bottom: 10px; }
        .cta-card p { color: var(--text-soft); font-size: 14px; line-height: 1.8; max-width: 52ch; }
        footer { padding: 8px 0 34px; }
        .footer-shell { padding: 26px; }
        .footer-inner { display: grid; grid-template-columns: 1.2fr repeat(3, minmax(0, 1fr)); gap: 22px; }
        .footer-logo { height: 28px; width: auto; margin-bottom: 12px; }
        .footer-tagline { color: var(--text-soft); font-size: 12px; line-height: 1.8; max-width: 32ch; }
        .footer-links-group h4 { font-family: 'Syne', sans-serif; font-size: 15px; margin-bottom: 12px; }
        .footer-links-group a, .footer-legal a { display: block; color: var(--text-soft); text-decoration: none; font-size: 12px; margin-bottom: 8px; }
        .footer-links-group a:hover, .footer-legal a:hover { color: var(--text); }
        .footer-bottom { margin-top: 22px; padding-top: 18px; border-top: 1px solid rgba(124,58,237,0.18); display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .footer-copy { font-size: 11px; color: var(--text-dim); }
        .footer-legal { display: flex; gap: 14px; flex-wrap: wrap; }
        .footer-legal a { margin-bottom: 0; }
        .reveal { opacity: 0; transform: translateY(20px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }
        @media (max-width: 980px) {
            .hero-grid, .split-panel, .section-header, .footer-inner { grid-template-columns: 1fr; }
            .three-up, .signal-grid, .trust-strip { grid-template-columns: 1fr; }
            .mini-grid { grid-template-columns: 1fr 1fr; }
            .hero-copy { min-height: auto; }
            .hero-panel { margin-top: 0; }
            .three-up .section-card:nth-child(2), .three-up .section-card:nth-child(3) { margin-top: 0; }
            .cta-card { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 700px) {
            .nav-inner, .container { padding-left: 16px; padding-right: 16px; }
            .nav-links { gap: 6px; }
            .nav-link:not(.primary) { display: none; }
            .hero { padding-top: 32px; }
            .hero-copy, .hero-panel, .section-card, .timeline, .faq-shell, .footer-shell, .narrative, .cta-card { padding: 20px; }
            .mini-grid { grid-template-columns: 1fr; }
            .workspace-item { grid-template-columns: 1fr; }
            .workspace-kicker { padding-top: 0; }
            .hero-actions { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; }
            .footer-legal { gap: 10px; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <div class="nav-inner">
        <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
        <div class="nav-links">
            <a href="/chat" class="nav-link">Workspace</a>
            <a href="#product" class="nav-link">Product</a>
            <a href="#operators" class="nav-link">Operators</a>
            <a href="#faq" class="nav-link">FAQ</a>
            <a href="/pages/pricing/" class="nav-link">Pricing</a>
            <a href="/pages/reseller_apply.php" class="nav-link primary">Apply</a>
        </div>
    </div>
</nav>
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy reveal visible">
            <div>
                <div class="eyebrow">AI infrastructure for operators</div>
                <h1>Make the product feel <span>finished</span> before you scale it.</h1>
                <p class="hero-sub">Lyralink gives you a usable AI workspace, API layer, billing flow, and operator tooling in one place. It is designed for people who want something calmer, more reliable, and easier to package under their own brand.</p>
                <div class="hero-actions">
                    <a href="/chat" class="btn btn-primary">Open the workspace</a>
                    <a href="/pages/pricing/" class="btn btn-secondary">See operator pricing</a>
                </div>
                <div class="trust-strip">
                    <a href="/pages/status" class="trust-card">
                        <strong>Status</strong>
                        <span>Check live uptime, degraded states, and incident visibility before you build on it.</span>
                    </a>
                    <a href="/pages/security.php" class="trust-card">
                        <strong>Security</strong>
                        <span>Review platform safeguards, reporting paths, and the public security stance directly.</span>
                    </a>
                    <a href="/pages/api_docs/" class="trust-card">
                        <strong>API</strong>
                        <span>See the documented integration surface instead of guessing what the product can actually support.</span>
                    </a>
                </div>
                <p class="trust-meta">Local-first Lyra routing, public trust pages, and documented operator surfaces are visible on purpose because credibility should not be hidden behind a sales form.</p>
            </div>
            <div class="hero-notes">
                <span class="note-pill">Chat, API, desktop, and Discord stay connected</span>
                <span class="note-pill">Operator billing and account controls are already wired</span>
                <span class="note-pill">Start hosted, then shape the branded experience</span>
            </div>
        </div>
        <div class="hero-panel reveal visible">
            <div class="workspace-card">
                <div class="workspace-head">
                    <div class="workspace-title">A quieter stack for launching AI products</div>
                    <div class="workspace-tag">Hosted by Lyralink</div>
                </div>
                <div class="workspace-list">
                    <div class="workspace-item"><div class="workspace-kicker">Workspace</div><div><strong>Give users a place that already feels operational.</strong><p>Chat history, attachments, code help, and multi-platform access are built in instead of bolted on later.</p></div></div>
                    <div class="workspace-item"><div class="workspace-kicker">API</div><div><strong>Expose the same intelligence through a clean public interface.</strong><p>Keys, limits, docs, and prompt access are already part of the platform so you can package a developer-facing offer faster.</p></div></div>
                    <div class="workspace-item"><div class="workspace-kicker">Ops</div><div><strong>Keep the operator layer close to the product.</strong><p>Billing, support, status, security reporting, and admin surfaces live in the same system instead of across disconnected tools.</p></div></div>
                </div>
            </div>
            <div class="signal-grid">
                <div class="signal"><strong>Web</strong><span>Main workspace, legal pages, pricing, support, and operator flows.</span></div>
                <div class="signal"><strong>API</strong><span>Documented prompt and dataset access with auth, limits, and safer defaults.</span></div>
                <div class="signal"><strong>Ops</strong><span>Security logs, status, admin tools, and daily reporting built into the stack.</span></div>
            </div>
        </div>
    </div>
</section>
<section class="section-block" id="product">
    <div class="container">
        <div class="section-header reveal">
            <div><div class="section-kicker">Product Surface</div><div class="section-title">Less noise. More <span>usable surface area.</span></div></div>
            <p class="section-desc">The point is not to overwhelm visitors with every possible feature. The point is to give you a product foundation that already covers the parts people actually notice once they start using it.</p>
        </div>
        <div class="three-up">
            <div class="section-card reveal"><div class="card-label">01</div><h3>Workspace quality</h3><p>The main chat experience already includes memory, attachments, tool-oriented replies, desktop parity, and operator-aware account flows.</p><ul><li>Persistent conversations and synced identity</li><li>AI tools, code help, and file-aware workflows</li><li>Designed to feel stable rather than experimental</li></ul></div>
            <div class="section-card reveal"><div class="card-label">02</div><h3>Developer access</h3><p>API users get a documented path into the same system instead of a thin addon that drifts behind the product.</p><ul><li>API keys, auth guidance, and usage limits</li><li>Prompt and dataset endpoints for integrations</li><li>Safer transport defaults and live testing routes</li></ul></div>
            <div class="section-card reveal"><div class="card-label">03</div><h3>Operator controls</h3><p>The business layer is already present, so billing, support, status, and admin actions stay close to the product they affect.</p><ul><li>Account and plan-aware infrastructure</li><li>Security logs and operational reporting</li><li>Support, admin, and reseller workflows</li></ul></div>
        </div>
    </div>
</section>
<section class="section-block">
    <div class="container split-panel">
        <div class="section-card narrative reveal"><div class="section-kicker">What You Actually Get</div><div class="section-title">A product you can <span>package</span>, not just demo.</div><p>Lyralink is meant for the stage where the question is no longer “can this answer prompts?” but “can this hold together as something people pay for, support, and return to?”</p><p>That means the surrounding layer matters: onboarding, trust pages, support surfaces, desktop access, safer AI defaults, operator workflows, and a public API that behaves like part of the same system.</p></div>
        <div class="timeline reveal">
            <div class="timeline-step"><div class="timeline-num">1</div><div><strong>Start with the hosted stack.</strong><p>Use the existing workspace, pricing, docs, and operator routes to get moving without rebuilding the basics.</p></div></div>
            <div class="timeline-step"><div class="timeline-num">2</div><div><strong>Package the offer around a real use case.</strong><p>Internal copilots, client portals, API access, branded chat, or a mixed operator model all fit the same foundation.</p></div></div>
            <div class="timeline-step"><div class="timeline-num">3</div><div><strong>Refine distribution, not core plumbing.</strong><p>Spend time on positioning, onboarding, and retention instead of rebuilding billing, auth, or security layers from scratch.</p></div></div>
        </div>
    </div>
</section>
<section class="section-block" id="operators">
    <div class="container">
        <div class="section-header reveal">
            <div><div class="section-kicker">For Operators</div><div class="section-title">Built for people selling <span>the experience</span>, not just the model.</div></div>
            <p class="section-desc">If you are packaging AI as a service, the offer only works when the product surface, controls, billing, and support all feel coherent. That is the layer this platform is meant to reduce for you.</p>
        </div>
        <div class="split-panel">
            <div class="section-card reveal"><div class="card-label">Where it fits</div><h3>Use it as your branded layer.</h3><p>You can use Lyralink as the main customer-facing workspace, as the operator layer behind another front-end, or as a hybrid setup that mixes chat, API, and embedded access.</p><ul><li>Agency or client-facing AI workspace</li><li>Developer-facing API resale or tool layer</li><li>Discord, desktop, and embedded distribution</li></ul></div>
            <div class="section-card reveal"><div class="card-label">Why it helps</div><h3>More calm in the operating model.</h3><div class="mini-grid"><div class="mini-card"><strong>Billing</strong><p>Plan-aware product access and payment return flows are already part of the system.</p></div><div class="mini-card"><strong>Trust</strong><p>Security reporting, legal pages, and safer AI defaults help the product feel more complete.</p></div><div class="mini-card"><strong>Reach</strong><p>One backend can support web, desktop, API, and Discord distribution paths.</p></div><div class="mini-card"><strong>Ops</strong><p>Admin, status, support, and logs live in the same product surface instead of several ad hoc tools.</p></div></div></div>
        </div>
    </div>
</section>
<section class="section-block" id="faq">
    <div class="container">
        <div class="section-header reveal">
            <div><div class="section-kicker">FAQ</div><div class="section-title">A few things people usually ask <span>first.</span></div></div>
            <p class="section-desc">The common question is not whether AI can generate text. It is whether the surrounding system is solid enough to sell, support, and grow.</p>
        </div>
        <div class="faq-shell reveal">
            <div class="faq-item open"><div class="faq-q" onclick="toggleFaq(this)">Is this just a chatbot?<span>+</span></div><div class="faq-a">No. The main value is the surrounding infrastructure: account flows, operator controls, API access, support surfaces, billing, status, security logging, and multi-channel access.</div></div>
            <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)">Can I use it under my own brand?<span>+</span></div><div class="faq-a">Yes. Lyralink is designed for operator and reseller use cases where the presentation, packaging, and client relationship belong to you while the backend stack stays centralized.</div></div>
            <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)">What if I need both workspace and API access?<span>+</span></div><div class="faq-a">That is a normal fit. The platform already exposes both user-facing and developer-facing surfaces, which makes it easier to package a blended offer.</div></div>
            <div class="faq-item"><div class="faq-q" onclick="toggleFaq(this)">How quickly can I start?<span>+</span></div><div class="faq-a">You can start with the hosted defaults immediately, then refine branding, packaging, and distribution once the offer is proven.</div></div>
        </div>
    </div>
</section>
<section class="cta-wrap">
    <div class="container">
        <div class="cta-card reveal">
            <div><h3>Start with the part that already works.</h3><p>Open the workspace if you want to see the product surface first, or go straight to operator pricing if you are evaluating it as infrastructure.</p></div>
            <div class="hero-actions" style="margin:0"><a href="/chat" class="btn btn-primary">Open workspace</a><a href="/pages/reseller_apply.php" class="btn btn-secondary">Apply as an operator</a></div>
        </div>
    </div>
</section>
<footer>
    <div class="container">
        <div class="footer-shell">
            <div class="footer-inner">
                <div class="footer-brand"><img src="/assets/lyralinklogo.png" alt="Lyralink" class="footer-logo"><p class="footer-tagline">AI infrastructure for operators who want the surrounding product to feel considered, not improvised.</p></div>
                <div class="footer-links-group"><h4>Platform</h4><a href="/chat">AI Workspace</a><a href="/social">Social Chat</a><a href="/download">Desktop App</a><a href="/pages/pricing/">Operator Pricing</a><a href="/pages/vscode_extension/">VS Code Extension</a><a href="/pages/api_docs/">API Docs</a><a href="/pages/api_keys.php">API Keys</a></div>
                <div class="footer-links-group"><h4>Community</h4><a href="https://discord.gg/JhyPNs5Khn" target="_blank" rel="noopener">Discord</a><a href="/pages/support/">Support</a></div>
                <div class="footer-links-group"><h4>Legal</h4><a href="/pages/tos/">Terms of Service</a><a href="/pages/privacy/">Privacy Policy</a><a href="/pages/security.php">Security Policy</a><a href="/pages/accessibility.php">Accessibility Statement</a><a href="https://lyralinkai.com" target="_blank" rel="noopener">LyralinkAI</a></div>
            </div>
            <div class="footer-bottom"><span class="footer-copy">© <?= date('Y') ?> Lyralink Infrastructure — a LyralinkAI product</span><div class="footer-legal"><a href="/pages/tos/">Terms</a><a href="/pages/privacy/">Privacy</a><a href="/pages/security.php">Security</a><a href="/pages/support/">Support</a><a href="mailto:support@lyralinkai.com">Contact</a></div></div>
        </div>
    </div>
</footer>
<script>
function toggleFaq(el) {
    const item = el.closest('.faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach((node) => node.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
}
const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.14 });
document.querySelectorAll('.reveal').forEach((node) => {
    if (!node.classList.contains('visible')) observer.observe(node);
});
</script>
</body>
</html>