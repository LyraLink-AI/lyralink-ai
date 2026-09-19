<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink - Security Policy</title>
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
        .callout { background: rgba(124,58,237,0.07); border: 1px solid rgba(124,58,237,0.2); border-radius: 10px; padding: 14px 16px; margin-top: 12px; font-size: 13px; color: var(--text-muted); }
        .callout strong { color: var(--text); display: block; margin-bottom: 4px; }
        .contact-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-top: 14px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .contact-card .info h3 { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .contact-card .info p { font-size: 12px; color: var(--text-muted); }
        .contact-card a { margin-left: auto; color: var(--accent-light); text-decoration: none; font-size: 13px; border: 1px solid rgba(124,58,237,0.4); padding: 8px 16px; border-radius: 20px; transition: all 0.2s; white-space: nowrap; }
        .contact-card a:hover { background: rgba(124,58,237,0.15); }
        .trust-links { display:flex; gap:8px; flex-wrap:wrap; margin: 0 0 22px; }
        .trust-link { color:var(--text-muted); text-decoration:none; font-size:11px; border:1px solid var(--border); border-radius:999px; padding:6px 12px; background:rgba(124,58,237,0.06); transition:all .2s; }
        .trust-link:hover, .trust-link.active { border-color:var(--accent); color:var(--accent-light); background:rgba(124,58,237,0.12); }
        hr.divider { border: none; border-top: 1px solid var(--border); margin: 40px 0; }
        a { color: var(--accent-light); }
        @media(max-width:600px){ .contact-card { flex-direction: column; } .contact-card a { margin-left: 0; } }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>
<?php
    $supportEmail = 'security@lyralinkai.com';
    $supportMailto = 'mailto:' . $supportEmail;
?>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <a href="/" class="nav-back">← Home</a>
</nav>
<div class="page-wrap">
    <div class="page-header">
        <h1>Security <span>Policy</span></h1>
        <div class="meta">Effective September 7, 2026 · Lyralink by LyralinkAI</div>
    </div>
    <div class="trust-links" aria-label="Trust center links">
        <a class="trust-link" href="/pages/status">Status</a>
        <a class="trust-link active" href="/pages/security.php">Security Policy</a>
        <a class="trust-link" href="/pages/api_docs/">API Docs</a>
        <a class="trust-link" href="/pages/support/">Support</a>
    </div>

    <div class="section">
        <h2>Reporting Vulnerabilities</h2>
        <p>Please do not open public issues for security vulnerabilities. Report issues privately so we can triage and respond without exposing users to additional risk.</p>
        <ul>
            <li>Email security concerns to <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a></li>
            <li>Include a clear description, reproduction steps, impact, and any suggested mitigation</li>
            <li>If email is unavailable, use a private GitHub Security Advisory</li>
        </ul>
        <div class="callout">
            <strong>Response targets</strong>
            Initial acknowledgment within 72 hours, triage within 7 days, and remediation timing based on severity and exploitability.
        </div>
    </div>

    <div class="section">
        <h2>Secret Handling</h2>
        <p>We avoid committing credentials, API keys, or tokens to the repository and expect operators to use environment variables for deployment secrets.</p>
        <ul>
            <li>Rotate any leaked secrets immediately</li>
            <li>Use least-privilege access for services and infrastructure</li>
            <li>Keep security updates on the current maintained branch</li>
        </ul>
    </div>

    <div class="section">
        <h2>Defense Controls</h2>
        <p>Lyralink uses HTTPS, session controls, rate limiting, verification safeguards, and logged security events to protect account access and platform integrity.</p>
        <p>Security-sensitive actions are gated by server-side checks, and operator workflows are audited where appropriate.</p>
    </div>

    <div class="section">
        <h2>Contact</h2>
        <div class="contact-card">
            <div class="info">
                <h3>Lyralink Security</h3>
                <p>LyralinkAI · <?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>">Email Security</a>
        </div>
    </div>

    <hr class="divider">
    <p style="font-size:11px;color:var(--text-muted)">See also <a href="/pages/status">Status</a>, <a href="/pages/api_docs/">API Docs</a>, <a href="/pages/privacy.php">Privacy Policy</a>, and <a href="/pages/tos.php">Terms of Service</a>.</p>
</div>
</body>
</html>