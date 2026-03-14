<?php
if (file_exists(__DIR__ . '/maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['ai.cloudhavenx.com', 'www.ai.cloudhavenx.com'], true);
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
    <title>Lyralink AI | Powered by CloudHavenX</title>
    <link rel="icon" type="image/x-icon" href="images/cloudhavenx.ico">

    <!-- SEO -->
    <meta name="description" content="Lyralink is a fast, free AI assistant powered by LLaMA 3 and Groq. Chat instantly, no account needed. Smarter plans for power users. Built by CloudHavenX.">
    <meta name="keywords" content="free AI chat, LLaMA chatbot, Groq AI, AI assistant, free chatbot, AI no login, Lyralink, CloudHavenX">
    <meta name="author" content="CloudHavenX">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://ai.cloudhavenx.com/">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://ai.cloudhavenx.com/">
    <meta property="og:title" content="Lyralink — Free AI Chat Powered by LLaMA & Groq">
    <meta property="og:description" content="Fast, free AI chat with no account required. Ask anything, get instant answers. Powered by LLaMA 3 via Groq.">
    <meta property="og:image" content="https://ai.cloudhavenx.com/assets/og-image.png">
    <meta property="og:site_name" content="Lyralink">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Lyralink — Free AI Chat Powered by LLaMA & Groq">
    <meta name="twitter:description" content="Fast, free AI chat with no account required. Ask anything, get instant answers.">
    <meta name="twitter:image" content="https://ai.cloudhavenx.com/assets/og-image.png">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "Lyralink",
        "url": "https://ai.cloudhavenx.com",
        "description": "Free AI chat assistant powered by LLaMA 3 and Groq. No account required.",
        "applicationCategory": "AIApplication",
        "operatingSystem": "Web",
        "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "USD"
        },
        "author": {
            "@type": "Organization",
            "name": "CloudHavenX",
            "url": "https://cloudhavenx.com"
        }
    }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f;
            --surface: #111118;
            --surface2: #16161f;
            --border: #1e1e2e;
            --accent: #7c3aed;
            --accent-glow: rgba(124,58,237,0.3);
            --accent-light: #a78bfa;
            --text: #e2e8f0;
            --text-muted: #64748b;
            --text-dim: #94a3b8;
            --success: #22c55e;
            --warn: #f59e0b;
            --orange: #ff6b35;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; overflow-x: hidden; }

        /* ── BACKGROUND FX ── */
        .bg-orb { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; }
        .bg-orb-1 { top: -300px; left: 20%; width: 900px; height: 700px; background: radial-gradient(ellipse, rgba(124,58,237,0.07) 0%, transparent 65%); }
        .bg-orb-2 { bottom: -200px; right: -100px; width: 600px; height: 500px; background: radial-gradient(ellipse, rgba(255,107,53,0.04) 0%, transparent 65%); }
        .noise { position: fixed; inset: 0; opacity: 0.025; pointer-events: none; z-index: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
        }

        /* ── NAV ── */
        nav {
            position: sticky; top: 0; z-index: 100;
            padding: 14px 32px; display: flex; align-items: center; gap: 16px;
            background: rgba(10,10,15,0.85); backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(30,30,46,0.8);
        }
        .nav-logo { height: 30px; width: auto; mix-blend-mode: lighten; }
        .nav-links { display: flex; gap: 6px; margin-left: auto; align-items: center; }
        .nav-link { color: var(--text-muted); text-decoration: none; font-size: 12px; padding: 6px 14px; border-radius: 20px; border: 1px solid transparent; transition: all 0.2s; }
        .nav-link:hover { color: var(--text); border-color: var(--border); }
        .nav-cta { background: var(--accent); color: white !important; border-color: var(--accent) !important; box-shadow: 0 0 14px var(--accent-glow); }
        .nav-cta:hover { background: #6d28d9 !important; }
        @media (max-width: 600px) { .nav-link:not(.nav-cta) { display: none; } }

        /* ── SECTION WRAPPER ── */
        section { position: relative; z-index: 1; }
        .container { max-width: 1060px; margin: 0 auto; padding: 0 24px; }

        /* ── HERO ── */
        .hero {
            padding: 100px 24px 80px;
            text-align: center;
            position: relative; z-index: 1;
        }
        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; color: var(--accent-light); letter-spacing: 2px; text-transform: uppercase;
            background: rgba(124,58,237,0.1); border: 1px solid rgba(124,58,237,0.25);
            padding: 5px 14px; border-radius: 20px; margin-bottom: 24px;
        }
        .hero-eyebrow .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-light); animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.5;transform:scale(0.8)} }

        h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(36px, 7vw, 72px);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -1px;
            margin-bottom: 22px;
        }
        h1 .accent { color: var(--accent-light); }
        h1 .strike {
            position: relative; color: var(--text-muted);
            text-decoration: line-through; text-decoration-color: var(--accent);
        }

        .hero-sub {
            font-size: clamp(14px, 2vw, 17px);
            color: var(--text-dim);
            max-width: 520px;
            margin: 0 auto 36px;
            line-height: 1.8;
        }

        .hero-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-bottom: 48px; }
        .btn-primary {
            padding: 14px 28px; border-radius: 12px; font-family: 'DM Mono', monospace; font-size: 13px;
            font-weight: 500; cursor: pointer; border: none; text-decoration: none;
            background: var(--accent); color: white; box-shadow: 0 0 24px var(--accent-glow);
            transition: all 0.25s; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary:hover { background: #6d28d9; transform: translateY(-2px); box-shadow: 0 4px 32px var(--accent-glow); }
        .btn-secondary {
            padding: 14px 28px; border-radius: 12px; font-family: 'DM Mono', monospace; font-size: 13px;
            cursor: pointer; text-decoration: none; background: none;
            border: 1px solid var(--border); color: var(--text-dim); transition: all 0.25s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-secondary:hover { border-color: var(--accent-light); color: var(--accent-light); transform: translateY(-2px); }

        /* CHAT MOCKUP */
        .chat-mockup {
            max-width: 620px; margin: 0 auto;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 18px; overflow: hidden;
            box-shadow: 0 0 80px rgba(124,58,237,0.1), 0 40px 80px rgba(0,0,0,0.4);
        }
        .mockup-bar {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 8px;
            background: rgba(124,58,237,0.04);
        }
        .mockup-dot { width: 10px; height: 10px; border-radius: 50%; }
        .mockup-title { font-size: 11px; color: var(--text-muted); margin-left: 4px; flex: 1; text-align: center; }
        .mockup-body { padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .msg { display: flex; gap: 10px; align-items: flex-start; }
        .msg.user { flex-direction: row-reverse; }
        .msg-avatar { width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .msg-avatar.ai   { background: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }
        .msg-avatar.user { background: var(--surface2); border: 1px solid var(--border); }
        .msg-bubble { padding: 10px 14px; border-radius: 12px; font-size: 12px; line-height: 1.6; max-width: 80%; }
        .msg-bubble.ai   { background: rgba(124,58,237,0.08); border: 1px solid rgba(124,58,237,0.2); color: var(--text); }
        .msg-bubble.user { background: var(--surface2); border: 1px solid var(--border); color: var(--text-dim); }
        .typing { display: flex; gap: 4px; padding: 12px 14px; }
        .typing span { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-light); opacity: 0.4; animation: typing 1.2s infinite; }
        .typing span:nth-child(2) { animation-delay: 0.2s; }
        .typing span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing { 0%,80%,100%{opacity:0.4;transform:scale(1)} 40%{opacity:1;transform:scale(1.2)} }

        /* ── STATS BAR ── */
        .stats-bar {
            border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);
            padding: 28px 24px;
        }
        .stats-inner { display: flex; justify-content: center; gap: 0; flex-wrap: wrap; max-width: 800px; margin: 0 auto; }
        .stat { padding: 16px 40px; text-align: center; border-right: 1px solid var(--border); }
        .stat:last-child { border-right: none; }
        .stat-num { font-family: 'Syne', sans-serif; font-size: 28px; font-weight: 800; color: var(--accent-light); }
        .stat-label { font-size: 11px; color: var(--text-muted); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
        @media(max-width:600px){ .stat { padding: 12px 20px; border-right: none; border-bottom: 1px solid var(--border); } .stat:last-child { border-bottom: none; } }

        /* ── SECTION TITLES ── */
        .section-title {
            font-family: 'Syne', sans-serif; font-size: clamp(24px, 4vw, 38px);
            font-weight: 800; line-height: 1.2; margin-bottom: 12px;
        }
        .section-title span { color: var(--accent-light); }
        .section-sub { font-size: 14px; color: var(--text-muted); line-height: 1.7; max-width: 520px; }
        .section-header { margin-bottom: 48px; }

        /* ── FEATURES ── */
        .features { padding: 80px 24px; }
        .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        @media(max-width:800px){ .features-grid { grid-template-columns: 1fr 1fr; } }
        @media(max-width:520px){ .features-grid { grid-template-columns: 1fr; } }

        .feature-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
            padding: 24px; transition: all 0.3s; position: relative; overflow: hidden;
        }
        .feature-card::before {
            content: ''; position: absolute; inset: 0; opacity: 0;
            background: radial-gradient(ellipse at top left, rgba(124,58,237,0.08) 0%, transparent 60%);
            transition: opacity 0.3s;
        }
        .feature-card:hover { border-color: rgba(124,58,237,0.4); transform: translateY(-3px); }
        .feature-card:hover::before { opacity: 1; }
        .feature-icon { font-size: 26px; margin-bottom: 14px; }
        .feature-name { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 8px; }
        .feature-desc { font-size: 12px; color: var(--text-muted); line-height: 1.7; }

        /* ── HOW IT WORKS ── */
        .how { padding: 80px 24px; background: linear-gradient(180deg, transparent 0%, rgba(124,58,237,0.03) 50%, transparent 100%); }
        .steps { display: grid; grid-template-columns: repeat(3,1fr); gap: 24px; position: relative; }
        .steps::before {
            content: ''; position: absolute; top: 28px; left: calc(16.6% + 20px); right: calc(16.6% + 20px);
            height: 1px; background: linear-gradient(90deg, var(--accent) 0%, var(--accent-light) 50%, var(--accent) 100%);
            opacity: 0.3;
        }
        @media(max-width:600px){ .steps { grid-template-columns: 1fr; } .steps::before { display: none; } }
        .step { text-align: center; padding: 0 16px; }
        .step-num {
            width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 18px;
            background: rgba(124,58,237,0.1); border: 1px solid rgba(124,58,237,0.3);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Syne', sans-serif; font-size: 20px; font-weight: 800; color: var(--accent-light);
            box-shadow: 0 0 20px rgba(124,58,237,0.15);
        }
        .step-title { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 8px; }
        .step-desc { font-size: 12px; color: var(--text-muted); line-height: 1.7; }

        /* ── USE CASES ── */
        .usecases { padding: 80px 24px; }
        .usecases-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media(max-width:640px){ .usecases-grid { grid-template-columns: 1fr; } }
        .usecase-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
            padding: 22px 24px; display: flex; gap: 16px; align-items: flex-start;
            transition: border-color 0.2s;
        }
        .usecase-card:hover { border-color: rgba(124,58,237,0.3); }
        .usecase-icon { font-size: 24px; flex-shrink: 0; margin-top: 2px; }
        .usecase-title { font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        .usecase-desc { font-size: 12px; color: var(--text-muted); line-height: 1.7; }

        /* ── DISCORD SECTION ── */
        .discord-section { padding: 80px 24px; }
        .discord-card {
            background: linear-gradient(135deg, rgba(88,101,242,0.08) 0%, rgba(124,58,237,0.06) 100%);
            border: 1px solid rgba(88,101,242,0.25); border-radius: 20px; padding: 48px;
            display: flex; gap: 48px; align-items: center; flex-wrap: wrap;
        }
        .discord-text { flex: 1; min-width: 260px; }
        .discord-icon { font-size: 48px; margin-bottom: 16px; }
        .discord-features { list-style: none; margin-top: 16px; display: flex; flex-direction: column; gap: 8px; }
        .discord-features li { font-size: 13px; color: var(--text-dim); display: flex; gap: 8px; align-items: flex-start; }
        .discord-features li::before { content: '→'; color: var(--accent-light); flex-shrink: 0; }
        .discord-cta { flex-shrink: 0; }
        .btn-discord {
            padding: 14px 28px; border-radius: 12px; font-family: 'DM Mono', monospace; font-size: 13px;
            cursor: pointer; text-decoration: none; background: #5865f2; color: white;
            border: none; transition: all 0.25s; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 0 20px rgba(88,101,242,0.3);
        }
        .btn-discord:hover { background: #4752c4; transform: translateY(-2px); }

        /* ── FAQ ── */
        .faq { padding: 80px 24px; }
        .faq-list { max-width: 720px; margin: 0 auto; display: flex; flex-direction: column; gap: 8px; }
        .faq-item {
            background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
        }
        .faq-q {
            padding: 18px 22px; font-size: 13px; font-weight: 600; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            transition: background 0.2s; user-select: none;
        }
        .faq-q:hover { background: rgba(124,58,237,0.05); }
        .faq-q .arrow { transition: transform 0.25s; font-size: 10px; color: var(--text-muted); flex-shrink: 0; }
        .faq-a { font-size: 12px; color: var(--text-muted); line-height: 1.8; padding: 0 22px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease, padding 0.3s; }
        .faq-item.open .faq-a { max-height: 300px; padding: 0 22px 18px; }
        .faq-item.open .arrow { transform: rotate(180deg); }

        /* ── CTA ── */
        .cta-section {
            padding: 100px 24px;
            text-align: center;
            position: relative; z-index: 1;
        }
        .cta-section::before {
            content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
            width: 600px; height: 400px;
            background: radial-gradient(ellipse, rgba(124,58,237,0.1) 0%, transparent 65%);
            pointer-events: none; z-index: -1;
        }
        .cta-section h2 { font-family: 'Syne', sans-serif; font-size: clamp(28px, 5vw, 48px); font-weight: 800; margin-bottom: 16px; line-height: 1.2; }
        .cta-section h2 span { color: var(--accent-light); }
        .cta-section p { font-size: 14px; color: var(--text-muted); margin-bottom: 36px; max-width: 460px; margin-left: auto; margin-right: auto; line-height: 1.7; }

        /* ── FOOTER ── */
        footer {
            border-top: 1px solid var(--border); padding: 40px 24px;
            position: relative; z-index: 1;
        }
        .footer-inner { max-width: 1060px; margin: 0 auto; display: flex; gap: 32px; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; }
        .footer-brand { max-width: 260px; }
        .footer-logo { height: 26px; width: auto; mix-blend-mode: lighten; margin-bottom: 12px; }
        .footer-tagline { font-size: 12px; color: var(--text-muted); line-height: 1.7; }
        .footer-links-group h4 { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); margin-bottom: 12px; }
        .footer-links-group a { display: block; font-size: 12px; color: var(--text-muted); text-decoration: none; margin-bottom: 8px; transition: color 0.2s; }
        .footer-links-group a:hover { color: var(--accent-light); }
        .footer-bottom { max-width: 1060px; margin: 24px auto 0; padding-top: 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .footer-copy { font-size: 11px; color: #2a2a3a; }
        .footer-legal { display: flex; gap: 16px; }
        .footer-legal a { font-size: 11px; color: #2a2a3a; text-decoration: none; transition: color 0.2s; }
        .footer-legal a:hover { color: var(--text-muted); }

        /* ── SCROLL ANIMATIONS ── */
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }
        .reveal-delay-4 { transition-delay: 0.4s; }
        .reveal-delay-5 { transition-delay: 0.5s; }
    </style>
</head>
<body>

<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>
<div class="noise"></div>

<!-- NAV -->
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink AI" class="nav-logo">
    <div class="nav-links">
        <a href="#features" class="nav-link">Features</a>
        <a href="#faq" class="nav-link">FAQ</a>
        <a href="/pages/webbot.php" class="nav-link">Web Bot Panel</a>
        <a href="/pages/pricing/" class="nav-link">Pricing</a>
        <a href="/chat" class="nav-link nav-cta">Start Chatting →</a>
    </div>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="hero-eyebrow"><div class="dot"></div> Powered by LLaMA 3 via Groq</div>
    <h1>
        AI that's actually<br>
        <span class="accent">fast.</span> Actually <span class="accent">free.</span>
    </h1>
    <p class="hero-sub">
        Lyralink gives you instant AI answers with no account required.
        Ask anything — code, research, writing, math — and get a real answer in seconds.
    </p>
    <div class="hero-btns">
        <a href="/chat" class="btn-primary">⚡ Start Chatting — It's Free</a>
        <a href="/pages/pricing/" class="btn-secondary">View Plans →</a>
    </div>

    <!-- CHAT MOCKUP -->
    <div class="chat-mockup">
        <div class="mockup-bar">
            <div class="mockup-dot" style="background:#ef4444"></div>
            <div class="mockup-dot" style="background:#f59e0b"></div>
            <div class="mockup-dot" style="background:#22c55e"></div>
            <span class="mockup-title">Lyralink AI</span>
        </div>
        <div class="mockup-body">
            <div class="msg user">
                <div class="msg-avatar user">👤</div>
                <div class="msg-bubble user">Can you write a Python function that checks if a number is prime?</div>
            </div>
            <div class="msg">
                <div class="msg-avatar ai">⚡</div>
                <div class="msg-bubble ai">Sure! Here's a clean, efficient implementation:
<br><br><code style="color:var(--accent-light)">def is_prime(n):<br>&nbsp;&nbsp;if n &lt; 2: return False<br>&nbsp;&nbsp;for i in range(2, int(n**0.5)+1):<br>&nbsp;&nbsp;&nbsp;&nbsp;if n % i == 0: return False<br>&nbsp;&nbsp;return True</code>
<br><br>This runs in O(√n) time — much faster than checking every number up to n.</div>
            </div>
            <div class="msg user">
                <div class="msg-avatar user">👤</div>
                <div class="msg-bubble user">What about very large numbers?</div>
            </div>
            <div class="msg">
                <div class="msg-avatar ai">⚡</div>
                <div class="msg-bubble ai">
                    <div class="typing"><span></span><span></span><span></span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- STATS -->
<section class="stats-bar">
    <div class="stats-inner">
        <div class="stat reveal">
            <div class="stat-num">~100ms</div>
            <div class="stat-label">Average Response Time</div>
        </div>
        <div class="stat reveal reveal-delay-1">
            <div class="stat-num">Free</div>
            <div class="stat-label">To Start, No Card</div>
        </div>
        <div class="stat reveal reveal-delay-2">
            <div class="stat-num">LLaMA 3</div>
            <div class="stat-label">Powered by Meta's Best</div>
        </div>
        <div class="stat reveal reveal-delay-3">
            <div class="stat-num">24/7</div>
            <div class="stat-label">Always Online</div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="container">
        <div class="section-header reveal">
            <div class="section-title">Everything you need<br>from an <span>AI assistant</span></div>
            <p class="section-sub">No fluff. No paywalls on the basics. Just a fast, capable AI that works the way you'd expect.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card reveal">
                <div class="feature-icon">⚡</div>
                <div class="feature-name">Blazing Fast Responses</div>
                <div class="feature-desc">Powered by Groq's inference hardware — responses in under a second, even for complex queries.</div>
            </div>
            <div class="feature-card reveal reveal-delay-1">
                <div class="feature-icon">🔓</div>
                <div class="feature-name">No Account Required</div>
                <div class="feature-desc">Jump straight in as a guest. Create a free account to save your conversations and unlock more features.</div>
            </div>
            <div class="feature-card reveal reveal-delay-2">
                <div class="feature-icon">💾</div>
                <div class="feature-name">Conversation History</div>
                <div class="feature-desc">All your chats are saved and synced across devices when you're logged in. Never lose a conversation.</div>
            </div>
            <div class="feature-card reveal reveal-delay-3">
                <div class="feature-icon">🤖</div>
                <div class="feature-name">Discord Bot Included</div>
                <div class="feature-desc">Use Lyralink directly in your Discord server with <code style="color:var(--accent-light)">.chat</code>, <code style="color:var(--accent-light)">.ask</code> and more. Conversations sync to your account.</div>
            </div>
            <div class="feature-card reveal reveal-delay-4">
                <div class="feature-icon">🗄️</div>
                <div class="feature-name">Public Dataset API</div>
                <div class="feature-desc">Developers can query Lyralink's knowledge dataset via a REST API. Free API keys for all users.</div>
            </div>
            <div class="feature-card reveal reveal-delay-5">
                <div class="feature-icon">🔒</div>
                <div class="feature-name">Private & Secure</div>
                <div class="feature-desc">Your conversations are yours. We don't sell your data and you can request deletion at any time.</div>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="how" id="how">
    <div class="container">
        <div class="section-header reveal" style="text-align:center">
            <div class="section-title">Up and running in <span>30 seconds</span></div>
            <p class="section-sub" style="margin:0 auto">No setup. No tutorial. Just open the chat and start.</p>
        </div>
        <div class="steps">
            <div class="step reveal">
                <div class="step-num">1</div>
                <div class="step-title">Open Lyralink</div>
                <div class="step-desc">Go to ai.cloudhavenx.com — no downloads, no install, works in any browser on any device.</div>
            </div>
            <div class="step reveal reveal-delay-2">
                <div class="step-num">2</div>
                <div class="step-title">Type your question</div>
                <div class="step-desc">Ask anything — a coding problem, a writing task, a research question. No special syntax required.</div>
            </div>
            <div class="step reveal reveal-delay-4">
                <div class="step-num">3</div>
                <div class="step-title">Get your answer</div>
                <div class="step-desc">Lyralink responds instantly. Follow up, go deeper, or start a new chat — it's all free.</div>
            </div>
        </div>
    </div>
</section>

<!-- USE CASES -->
<section class="usecases" id="usecases">
    <div class="container">
        <div class="section-header reveal">
            <div class="section-title">What can you <span>use it for?</span></div>
            <p class="section-sub">Lyralink handles a wide range of tasks out of the box.</p>
        </div>
        <div class="usecases-grid">
            <div class="usecase-card reveal">
                <div class="usecase-icon">💻</div>
                <div>
                    <div class="usecase-title">Writing & Debugging Code</div>
                    <div class="usecase-desc">Write functions, debug errors, explain what code does, suggest improvements. Works with Python, JS, PHP, SQL and more.</div>
                </div>
            </div>
            <div class="usecase-card reveal reveal-delay-1">
                <div class="usecase-icon">✍️</div>
                <div>
                    <div class="usecase-title">Writing & Editing</div>
                    <div class="usecase-desc">Draft emails, blog posts, essays, product descriptions. Improve tone, fix grammar, rewrite for clarity.</div>
                </div>
            </div>
            <div class="usecase-card reveal reveal-delay-2">
                <div class="usecase-icon">🔬</div>
                <div>
                    <div class="usecase-title">Research & Summarizing</div>
                    <div class="usecase-desc">Get quick explanations of complex topics, summarize long documents, compare ideas side by side.</div>
                </div>
            </div>
            <div class="usecase-card reveal reveal-delay-3">
                <div class="usecase-icon">🧮</div>
                <div>
                    <div class="usecase-title">Math & Logic</div>
                    <div class="usecase-desc">Solve equations, walk through proofs, explain concepts, check your work step by step.</div>
                </div>
            </div>
            <div class="usecase-card reveal reveal-delay-4">
                <div class="usecase-icon">💬</div>
                <div>
                    <div class="usecase-title">Brainstorming Ideas</div>
                    <div class="usecase-desc">Generate names, slogans, business ideas, story concepts, project structures. Think out loud with an AI that keeps up.</div>
                </div>
            </div>
            <div class="usecase-card reveal reveal-delay-5">
                <div class="usecase-icon">🌐</div>
                <div>
                    <div class="usecase-title">Language & Translation</div>
                    <div class="usecase-desc">Translate text, learn vocabulary, check phrasing in other languages, understand idioms and cultural context.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- DISCORD -->
<section class="discord-section" id="discord">
    <div class="container">
        <div class="discord-card reveal">
            <div class="discord-text">
                <div class="discord-icon">🎮</div>
                <div class="section-title" style="font-size:clamp(20px,3vw,28px)">Lyralink lives in <span>your Discord</span></div>
                <p style="font-size:13px;color:var(--text-muted);line-height:1.7;margin-top:10px">
                    Add the Lyralink bot to your server and chat with AI directly in Discord — no switching tabs.
                    Link your account and conversations sync between Discord and the web.
                </p>
                <ul class="discord-features">
                    <li><code style="color:var(--accent-light)">.chat</code> — persistent conversation with full history</li>
                    <li><code style="color:var(--accent-light)">.ask</code> — quick one-off answers in a private thread</li>
                    <li><code style="color:var(--accent-light)">.sync</code> — link your Discord to your Lyralink account</li>
                    <li><code style="color:var(--accent-light)">.status</code> — check your plan and usage at a glance</li>
                </ul>
            </div>
            <div class="discord-cta">
                <a href="https://discord.gg/JhyPNs5Khn" target="_blank" rel="noopener" class="btn-discord">
                    🎮 Join our Discord
                </a>
                <p style="font-size:11px;color:var(--text-muted);margin-top:12px;text-align:center">Free to join</p>
            </div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="faq" id="faq">
    <div class="container">
        <div class="section-header reveal" style="text-align:center">
            <div class="section-title">Frequently asked <span>questions</span></div>
        </div>
        <div class="faq-list">
            <div class="faq-item reveal">
                <div class="faq-q" onclick="toggleFaq(this)">
                    Is Lyralink really free? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">Yes. The free tier gives you 1,500 messages per month with no credit card required. Paid plans unlock higher limits, priority responses, and access to more powerful models.</div>
            </div>
            <div class="faq-item reveal reveal-delay-1">
                <div class="faq-q" onclick="toggleFaq(this)">
                    What AI model does Lyralink use? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">Lyralink is powered by Meta's LLaMA 3 models running on Groq's inference hardware. This means you get the quality of a state-of-the-art open model with extremely fast response times — typically under a second.</div>
            </div>
            <div class="faq-item reveal reveal-delay-2">
                <div class="faq-q" onclick="toggleFaq(this)">
                    Do I need an account to use it? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">No. You can start chatting immediately as a guest with no signup required. Creating a free account lets you save your conversation history, sync across devices, and track your usage.</div>
            </div>
            <div class="faq-item reveal reveal-delay-3">
                <div class="faq-q" onclick="toggleFaq(this)">
                    How does the Discord bot work? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">Once added to your server, use period-prefix commands like .chat, .ask, .sync and .status. The bot replies in private threads so it doesn't clutter your channels. Link your Discord account to your Lyralink account and all conversations sync between Discord and the web interface.</div>
            </div>
            <div class="faq-item reveal reveal-delay-4">
                <div class="faq-q" onclick="toggleFaq(this)">
                    Is my data private? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">Yes. Lyralink is built by CloudHavenX and we do not sell your data to third parties. Your conversations are stored securely and you can request deletion at any time from your account settings or by contacting support@cloudhavenx.com.</div>
            </div>
            <div class="faq-item reveal reveal-delay-5">
                <div class="faq-q" onclick="toggleFaq(this)">
                    What's the difference between the plans? <span class="arrow">▼</span>
                </div>
                <div class="faq-a">The Free plan gives 1,500 messages/month. Basic gives 2,500. Pro and Enterprise offer unlimited messages plus access to larger, more powerful model variants and priority support. See the full breakdown on the pricing page.</div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="section-title reveal" style="margin-bottom:16px">
        Ready to try it? <span>It's free.</span>
    </div>
    <p class="reveal reveal-delay-1">No account needed to start. Just open the chat and ask anything.</p>
    <div class="reveal reveal-delay-2">
        <a href="/chat" class="btn-primary" style="font-size:14px;padding:16px 36px">
            ⚡ Start Chatting Now
        </a>
    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="footer-inner">
        <div class="footer-brand">
            <img src="/assets/lyralinklogo.png" alt="Lyralink" class="footer-logo">
            <p class="footer-tagline">Fast, free AI chat powered by LLaMA 3 and Groq. A product of CloudHavenX.</p>
        </div>
        <div class="footer-links-group">
            <h4>Product</h4>
            <a href="/chat">Chat</a>
            <a href="/pages/pricing/">Pricing</a>
            <a href="/pages/api_docs/">API Docs</a>
            <a href="/pages/api_keys/">API Keys</a>
        </div>
        <div class="footer-links-group">
            <h4>Community</h4>
            <a href="https://discord.gg/JhyPNs5Khn" target="_blank" rel="noopener">Discord</a>
            <a href="/pages/support/">Support</a>
        </div>
        <div class="footer-links-group">
            <h4>Legal</h4>
            <a href="/pages/tos/">Terms of Service</a>
            <a href="https://cloudhavenx.com" target="_blank" rel="noopener">CloudHavenX</a>
        </div>
    </div>
    <div class="footer-bottom">
        <span class="footer-copy">© <?= date('Y') ?> Lyralink — An CloudHavenX product</span>
        <div class="footer-legal">
            <a href="/pages/tos/">Terms</a>
            <a href="/pages/support/">Support</a>
            <a href="mailto:support@cloudhavenx.com">Contact</a>
        </div>
    </div>
</footer>

<script>
// ── FAQ TOGGLE ──
function toggleFaq(el) {
    const item = el.closest('.faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
}

// ── SCROLL REVEAL ──
const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); } });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// ── SMOOTH NAV LINKS ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
});
</script>
</body>
</html>