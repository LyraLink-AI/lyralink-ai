<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink - Accessibility Statement</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.7; }
        nav {
            padding: 14px 24px; display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid var(--border); position: sticky; top: 0;
            background: rgba(10,10,15,0.9); backdrop-filter: blur(12px); z-index: 10;
        }
        .nav-logo { height: 28px; width: auto; mix-blend-mode: lighten; }
        .nav-back {
            margin-left: auto; color: var(--text-muted); text-decoration: none;
            font-size: 12px; border: 1px solid var(--border); padding: 5px 12px;
            border-radius: 20px;
        }
        .page-wrap { max-width: 820px; margin: 0 auto; padding: 42px 20px 80px; }
        .page-header h1 { font-family: 'Syne', sans-serif; font-size: clamp(28px, 4vw, 38px); margin-bottom: 10px; }
        .page-header h1 span { color: var(--accent-light); }
        .meta { color: var(--text-muted); font-size: 12px; margin-bottom: 28px; }
        .section { margin-bottom: 28px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
        .section h2 { font-family: 'Syne', sans-serif; font-size: 18px; margin-bottom: 10px; }
        .section p, .section li { font-size: 13px; color: var(--text-muted); line-height: 1.7; }
        .section p { margin-bottom: 10px; }
        .section ul { margin: 10px 0 0 18px; display: flex; flex-direction: column; gap: 6px; }
        .section ul li { list-style: disc; }
        .contact-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-top: 14px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .contact-card .info h3 { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .contact-card .info p { font-size: 12px; color: var(--text-muted); }
        .contact-card a { margin-left: auto; color: var(--accent-light); text-decoration: none; font-size: 13px; border: 1px solid rgba(124,58,237,0.4); padding: 8px 16px; border-radius: 20px; transition: all 0.2s; white-space: nowrap; }
        .contact-card a:hover { background: rgba(124,58,237,0.15); }
        hr.divider { border: none; border-top: 1px solid var(--border); margin: 40px 0; }
        a { color: var(--accent-light); }
        @media(max-width:600px){ .contact-card { flex-direction: column; } .contact-card a { margin-left: 0; } }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <a href="/" class="nav-back">← Home</a>
</nav>
<div class="page-wrap">
    <div class="page-header">
        <h1>Accessibility <span>Statement</span></h1>
        <div class="meta">Effective September 7, 2026 · Lyralink by LyralinkAI</div>
    </div>

    <div class="section">
        <h2>Our Commitment</h2>
        <p>Lyralink is designed to be usable with keyboard navigation, screen readers, and responsive layouts across desktop and mobile devices.</p>
        <p>We aim to keep contrast, focus states, semantic structure, and readable typography accessible across the main product surfaces.</p>
    </div>

    <div class="section">
        <h2>Known Limitations</h2>
        <p>Some advanced UI interactions may still require refinement as the product evolves. We treat accessibility issues as fixable defects rather than cosmetic polish.</p>
        <ul>
            <li>We aim to avoid color-only status cues where possible</li>
            <li>We prefer semantic controls and labels for interactive elements</li>
            <li>We test core pages for readability on small screens</li>
        </ul>
    </div>

    <div class="section">
        <h2>Feedback</h2>
        <p>If you encounter an accessibility barrier, contact us with the page, the issue you experienced, and the assistive technology you were using.</p>
        <div class="contact-card">
            <div class="info">
                <h3>Lyralink Accessibility</h3>
                <p>support@lyralinkai.com</p>
            </div>
            <a href="mailto:support@lyralinkai.com">Email Support</a>
        </div>
    </div>

    <hr class="divider">
    <p style="font-size:11px;color:var(--text-muted)">See also our <a href="/pages/privacy.php">Privacy Policy</a>, <a href="/pages/tos.php">Terms of Service</a>, and <a href="/pages/security.php">Security Policy</a>.</p>
</div>
</body>
</html>