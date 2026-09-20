<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink - Privacy Policy</title>
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
        .section h2 { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        .section p, .section li { font-size: 13px; color: var(--text-muted); line-height: 1.7; }
        .section p { margin-bottom: 10px; }
        .section p:last-child { margin-bottom: 0; }
        .section ul { margin: 10px 0 0 18px; display: flex; flex-direction: column; gap: 6px; }
        .section ul li { list-style: disc; }
        .section-num { font-size: 10px; color: var(--accent-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
        .callout { background: rgba(124,58,237,0.07); border: 1px solid rgba(124,58,237,0.2); border-radius: 10px; padding: 14px 16px; margin-top: 12px; font-size: 13px; color: var(--text-muted); }
        .callout strong { color: var(--text); display: block; margin-bottom: 4px; }
        .toc { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px 24px; margin-bottom: 36px; }
        .toc-title { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; font-weight: 700; }
        .toc ol { list-style: none; display: flex; flex-direction: column; gap: 6px; counter-reset: toc; }
        .toc ol li { counter-increment: toc; display: flex; gap: 8px; align-items: baseline; }
        .toc ol li::before { content: counter(toc) '.'; color: var(--accent-light); font-size: 11px; min-width: 18px; }
        .toc ol li a { color: var(--text-muted); text-decoration: none; font-size: 13px; transition: color 0.2s; }
        .toc ol li a:hover { color: var(--accent-light); }
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin: -8px 0 20px; }
        .quick-nav a { color: var(--text-muted); text-decoration: none; font-size: 11px; border: 1px solid var(--border); border-radius: 999px; padding: 6px 12px; background: rgba(124,58,237,0.07); transition: all .2s; }
        .quick-nav a:hover { border-color: var(--accent); color: var(--accent-light); background: rgba(124,58,237,0.14); }
        .contact-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; margin-top: 14px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .contact-card .info h3 { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .contact-card .info p { font-size: 12px; color: var(--text-muted); }
        .contact-card a { margin-left: auto; color: var(--accent-light); text-decoration: none; font-size: 13px; border: 1px solid rgba(124,58,237,0.4); padding: 8px 16px; border-radius: 20px; transition: all 0.2s; white-space: nowrap; }
        .contact-card a:hover { background: rgba(124,58,237,0.15); }
        hr.divider { border: none; border-top: 1px solid var(--border); margin: 40px 0; }
        a { color: var(--accent-light); }
        @media(max-width:600px){ .contact-card { flex-direction: column; } .contact-card a { margin-left: 0; } .quick-nav { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px; } .quick-nav a { white-space: nowrap; } }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>
<?php
    $supportEmail = 'support@lyralinkai.com';
    $supportMailto = 'mailto:' . $supportEmail;
?>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <a href="/" class="nav-back">← Home</a>
</nav>
<div class="page-wrap">
    <div class="page-header">
        <h1>Privacy <span>Policy</span></h1>
        <div class="meta">Effective September 7, 2026 · Lyralink by LyralinkAI · Version 1.2</div>
    </div>

    <div class="toc">
        <div class="toc-title">Contents</div>
        <ol>
            <li><a href="#collect">Data We Collect</a></li>
            <li><a href="#use">How We Use Your Data</a></li>
            <li><a href="#cookies">Cookies & Local Storage</a></li>
            <li><a href="#third-party">Third-Party Processing</a></li>
            <li><a href="#operators">Operator Data Responsibilities</a></li>
            <li><a href="#retention">Retention & Deletion</a></li>
            <li><a href="#rights">Your Rights</a></li>
            <li><a href="#children">Children's Privacy</a></li>
            <li><a href="#transfers">International Transfers</a></li>
            <li><a href="#security">Security</a></li>
            <li><a href="#changes">Changes to This Policy</a></li>
            <li><a href="#contact">Contact</a></li>
        </ol>
    </div>

    <div class="quick-nav" aria-label="Privacy quick navigation">
        <a href="#collect">Collect</a>
        <a href="#use">Use</a>
        <a href="#third-party">Third Parties</a>
        <a href="#retention">Retention</a>
        <a href="#rights">Rights</a>
        <a href="#contact">Contact</a>
    </div>

    <div class="section" id="collect">
        <div class="section-num">Section 01</div>
        <h2>Data We Collect</h2>
        <p>We collect the following categories of data when you use Lyralink:</p>
        <ul>
            <li>Account details such as username and email address</li>
            <li>Authentication and security data including login attempts, 2FA status, and device session metadata</li>
            <li>Chat content and saved conversation history when you use account-based sync</li>
            <li>Operational diagnostics such as IP address, browser/app user agent, and abuse-prevention logs</li>
            <li>Billing status and subscription information needed to provide paid service tiers</li>
            <li>Support communications when you open a support ticket or contact us</li>
            <li>Usage data including feature interactions, API call counts, and plan utilization</li>
        </ul>
    </div>

    <div class="section" id="use">
        <div class="section-num">Section 02</div>
        <h2>How We Use Your Data</h2>
        <ul>
            <li>To operate the Lyralink service and deliver AI responses</li>
            <li>To secure accounts, detect abuse, and investigate incidents</li>
            <li>To manage plans, support requests, and compliance obligations</li>
            <li>To improve reliability, performance, and product quality</li>
            <li>To communicate service updates, account notices, and security alerts</li>
            <li>To enforce these policies and any applicable legal requirements</li>
        </ul>
        <p>We do not use your data for targeted advertising or behavioral profiling for ad purposes.</p>
    </div>

    <div class="section" id="cookies">
        <div class="section-num">Section 03</div>
        <h2>Cookies & Local Storage</h2>
        <p>Lyralink uses session cookies to maintain authenticated sessions. We do not use third-party tracking cookies or advertising cookies.</p>
        <ul>
            <li><strong>Session cookies</strong> — required for login and account state; expire when your session ends or you log out</li>
            <li><strong>Preference cookies</strong> — store non-sensitive UI state (e.g. dev mode flag) for authenticated users only</li>
            <li><strong>Local storage</strong> — used by the web app to cache interface state client-side; not sent to our servers</li>
        </ul>
        <p>Essential session cookies cannot be disabled without breaking authentication. You can clear cookies and local storage at any time through your browser settings.</p>
    </div>

    <div class="section" id="third-party">
        <div class="section-num">Section 04</div>
        <h2>Third-Party Processing</h2>
        <p>To operate the service, we share limited data with trusted third parties:</p>
        <ul>
            <li><strong>Lyralink AI infrastructure</strong> — prompt content and request metadata are processed by Lyralink-managed model infrastructure to generate AI responses. Avoid submitting sensitive personal information unless your workflow explicitly requires it.</li>
            <li><strong>Payment processors (PayPal)</strong> — payment and subscription records. We do not store full card numbers.</li>
            <li><strong>Hosting & infrastructure</strong> — server, CDN, and database providers operating under data processing agreements.</li>
        </ul>
        <div class="callout">
            <strong>Prompt privacy note</strong>
            Anything you submit to the chat or API may be processed by the selected model provider and stored in service logs or conversation history where needed to operate the product. Avoid sending secrets, credentials, health data, or other sensitive personal information unless you explicitly understand the risk.
        </div>
        <p>We do not sell, rent, or trade personal information to third parties for their own marketing purposes.</p>
    </div>

    <div class="section" id="operators">
        <div class="section-num">Section 05</div>
        <h2>Operator Data Responsibilities</h2>
        <p>Lyralink offers an Operator Program that allows approved businesses to deploy AI products under their own brand using Lyralink infrastructure. When you operate a Lyralink-powered product:</p>
        <ul>
            <li>You are responsible for your own end-user privacy disclosures and data practices</li>
            <li>Client account data generated through your operator deployment is subject to this policy as well as your own obligations</li>
            <li>You must not use the platform to collect data in ways that violate applicable privacy law</li>
            <li>Operator earnings, client counts, and payout records are retained for audit and compliance purposes</li>
        </ul>
        <div class="callout">
            <strong>Operator Note</strong>
            If you run a white-label deployment, your clients interact with Lyralink infrastructure. You should provide your own privacy notice that references this policy where applicable.
        </div>
    </div>

    <div class="section" id="retention">
        <div class="section-num">Section 06</div>
        <h2>Retention & Deletion</h2>
        <p>We retain your data for as long as your account is active or as needed to provide the service. Specific retention guidelines:</p>
        <ul>
            <li><strong>Chat history</strong> — retained while your account is active; deletable from within account settings</li>
            <li><strong>Account data</strong> — retained until account deletion is processed and any hold period expires</li>
            <li><strong>Security & audit logs</strong> — retained for up to 12 months for fraud prevention and incident response</li>
            <li><strong>Billing records</strong> — retained for up to 7 years where required by financial regulations</li>
            <li><strong>Support tickets</strong> — retained for up to 3 years for quality and compliance purposes</li>
        </ul>
        <p>You can request deletion of chat history or your full account from within the account area or by contacting support. Certain records may be retained beyond deletion where legally required.</p>
    </div>

    <div class="section" id="rights">
        <div class="section-num">Section 07</div>
        <h2>Your Rights</h2>
        <p>Depending on your jurisdiction, you may have the following rights regarding your personal data:</p>
        <ul>
            <li><strong>Access</strong> — request a copy of the data we hold about you</li>
            <li><strong>Correction</strong> — request correction of inaccurate or incomplete data</li>
            <li><strong>Deletion</strong> — request deletion of your account and associated personal data</li>
            <li><strong>Portability</strong> — request your data in a structured, machine-readable format where feasible</li>
            <li><strong>Objection</strong> — object to certain processing activities where applicable law permits</li>
            <li><strong>Restriction</strong> — request that we restrict processing of your data in certain circumstances</li>
        </ul>
        <p>To exercise any of these rights, contact us at <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a>. We will respond within 30 days. Identity verification may be required before processing requests.</p>
    </div>

    <div class="section" id="children">
        <div class="section-num">Section 08</div>
        <h2>Children's Privacy</h2>
        <p>Lyralink is not directed to children under 13. We do not knowingly collect personal data from children under 13. Users between 13 and 17 must have parental or guardian consent as described in our Terms of Service.</p>
        <p>If we learn that we have collected data from a child under 13 without parental consent, we will delete that data promptly. If you believe a child under 13 has created an account, contact us at <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a>.</p>
    </div>

    <div class="section" id="transfers">
        <div class="section-num">Section 09</div>
        <h2>International Transfers</h2>
        <p>Lyralink is operated from the United States. If you access the service from outside the US, your data may be transferred to and processed in the United States or other countries where our infrastructure providers operate.</p>
        <p>We rely on infrastructure and AI providers that operate internationally. By using Lyralink, you consent to this transfer. We take steps to ensure that such transfers are handled in accordance with applicable data protection law.</p>
    </div>

    <div class="section" id="security">
        <div class="section-num">Section 10</div>
        <h2>Security</h2>
        <p>We use HTTPS, session controls, rate limiting, two-factor authentication, and verification safeguards to protect account access. Sensitive credentials are hashed and never stored in plaintext.</p>
        <p>No system can guarantee absolute security. In the event of a breach affecting your data, we will notify affected users as required by applicable law.</p>
        <p>You are responsible for maintaining the security of your account credentials. Do not share your password or 2FA recovery codes.</p>
    </div>

    <div class="section" id="changes">
        <div class="section-num">Section 11</div>
        <h2>Changes to This Policy</h2>
        <p>We may update this Privacy Policy from time to time. Material changes will be posted on this page with a revised effective date. Where appropriate, we will notify users via email or in-app notice.</p>
        <p>Continued use of Lyralink after policy changes constitutes acceptance of the updated policy.</p>
    </div>

    <div class="section" id="contact">
        <div class="section-num">Section 12</div>
        <h2>Contact</h2>
        <p>For privacy questions, data requests, or concerns, contact us:</p>
        <div class="contact-card">
            <div class="info">
                <h3>Lyralink Privacy</h3>
                <p>LyralinkAI · <?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>">Email Us</a>
        </div>
    </div>

    <hr class="divider">
    <p style="font-size:11px;color:var(--text-muted)">This policy applies to <strong>lyralinkai.com</strong> and Lyralink-branded products operated by LyralinkAI. It does not apply to third-party websites or services we may link to. See also our <a href="/pages/tos.php">Terms of Service</a> and <a href="/pages/security.php">Security Policy</a>.</p>
</div>
</body>
</html>
