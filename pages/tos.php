<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink - Terms of Service</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b; --success: #22c55e; --error: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.7; }
        body::before { content:''; position:fixed; top:-200px; left:30%; width:600px; height:400px; background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%); pointer-events:none; z-index:0; }

        nav {
            padding: 14px 24px; display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid var(--border); position: sticky; top: 0;
            background: rgba(10,10,15,0.9); backdrop-filter: blur(12px); z-index: 10;
        }
        .nav-logo { height: 28px; width: auto; mix-blend-mode: lighten; }
        .nav-back {
            margin-left: auto; color: var(--text-muted); text-decoration: none;
            font-size: 12px; border: 1px solid var(--border); padding: 5px 12px;
            border-radius: 20px; transition: all 0.2s;
        }
        .nav-back:hover { border-color: var(--accent); color: var(--accent-light); }

        .page-wrap { max-width: 780px; margin: 0 auto; padding: 48px 24px 80px; position: relative; z-index: 1; }
        .page-header { margin-bottom: 40px; }
        .page-header h1 { font-family: 'Syne', sans-serif; font-size: clamp(26px, 4vw, 38px); font-weight: 800; margin-bottom: 10px; }
        .page-header h1 span { color: var(--accent-light); }
        .page-header .meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 20px; flex-wrap: wrap; }
        .page-header .meta span::before { content: '· '; }
        .page-header .meta span:first-child::before { content: ''; }

        .toc {
            background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
            padding: 20px 24px; margin-bottom: 40px;
        }
        .toc-title { font-family: 'Syne', sans-serif; font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }
        .toc ol { list-style: none; display: flex; flex-direction: column; gap: 6px; counter-reset: toc; }
        .toc ol li { counter-increment: toc; display: flex; gap: 8px; align-items: baseline; }
        .toc ol li::before { content: counter(toc) '.'; color: var(--accent-light); font-size: 11px; min-width: 18px; }
        .toc ol li a { color: var(--text-muted); text-decoration: none; font-size: 13px; transition: color 0.2s; }
        .toc ol li a:hover { color: var(--accent-light); }
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin: -10px 0 22px; }
        .quick-nav a { color: var(--text-muted); text-decoration: none; font-size: 11px; border: 1px solid var(--border); border-radius: 999px; padding: 6px 12px; background: rgba(124,58,237,0.07); transition: all .2s; }
        .quick-nav a:hover { border-color: var(--accent); color: var(--accent-light); background: rgba(124,58,237,0.14); }

        .section { margin-bottom: 44px; scroll-margin-top: 80px; }
        .section-num { font-size: 11px; color: var(--accent-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
        .section h2 { font-family: 'Syne', sans-serif; font-size: 20px; font-weight: 800; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
        .section p { font-size: 13px; color: var(--text-muted); margin-bottom: 12px; }
        .section p:last-child { margin-bottom: 0; }
        .section p strong { color: var(--text); }
        .section ul { list-style: none; display: flex; flex-direction: column; gap: 8px; margin: 12px 0; }
        .section ul li { font-size: 13px; color: var(--text-muted); padding-left: 16px; position: relative; }
        .section ul li::before { content: '-'; position: absolute; left: 0; color: var(--accent); }

        .callout {
            background: rgba(124,58,237,0.08); border: 1px solid rgba(124,58,237,0.25);
            border-radius: 10px; padding: 14px 16px; margin: 16px 0; font-size: 13px; color: var(--text-muted);
        }
        .callout.warn { background: rgba(239,68,68,0.07); border-color: rgba(239,68,68,0.25); }
        .callout strong { color: var(--text); display: block; margin-bottom: 4px; }

        .contact-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
            padding: 24px; margin-top: 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
        }
        .contact-card .contact-icon { font-size: 28px; }
        .contact-card .contact-info h3 { font-family: 'Syne', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .contact-card .contact-info p { font-size: 13px; color: var(--text-muted); }
        .contact-card a { margin-left: auto; color: var(--accent-light); text-decoration: none; font-size: 13px; border: 1px solid rgba(124,58,237,0.4); padding: 8px 16px; border-radius: 20px; transition: all 0.2s; white-space: nowrap; }
        .contact-card a:hover { background: rgba(124,58,237,0.15); }

        .divider { border: none; border-top: 1px solid var(--border); margin: 48px 0; }

        @media (max-width: 600px) {
            nav { padding: 10px 14px; }
            nav img { height: 24px; }
            .nav-back { font-size: 11px; padding: 4px 8px; }
            .page-wrap { padding: 24px 14px 60px; }
            .page-header h1 { font-size: 26px; }
            .toc { padding: 16px; }
            .toc ol { gap: 4px; }
            .section { margin-bottom: 28px; }
            .section h2 { font-size: 16px; }
            .section p, .section li { font-size: 13px; }
            .contact-card { flex-direction: column; padding: 18px; gap: 12px; }
            .contact-card a { margin-left: 0; width: 100%; text-align: center; }
            .quick-nav { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px; margin: -12px 0 18px; }
            .quick-nav a { white-space: nowrap; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
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
        <h1>Terms of <span>Service</span></h1>
        <div class="meta">
            <span>Effective: September 7, 2026</span>
            <span>Lyralink · a LyralinkAI service</span>
            <span>Version 2.1</span>
        </div>
    </div>

    <div class="toc">
        <div class="toc-title">Contents</div>
        <ol>
            <li><a href="#acceptance">Acceptance of Terms</a></li>
            <li><a href="#eligibility">Eligibility & Age Requirement</a></li>
            <li><a href="#accounts">Accounts & Registration</a></li>
            <li><a href="#security">Security, Monitoring & Verification</a></li>
            <li><a href="#acceptable-use">Acceptable Use</a></li>
            <li><a href="#prohibited">Prohibited Content & Actions</a></li>
            <li><a href="#api">API & Automation Policy</a></li>
            <li><a href="#billing">Billing & Payments</a></li>
            <li><a href="#refunds">Refund Policy</a></li>
            <li><a href="#data">Data, Privacy & Retention</a></li>
            <li><a href="#deletion">Data Deletion Requests</a></li>
            <li><a href="#termination">Account Termination</a></li>
            <li><a href="#disclaimer">Disclaimers & Limitation of Liability</a></li>
            <li><a href="#changes">Changes to These Terms</a></li>
            <li><a href="#contact">Contact</a></li>
        </ol>
    </div>

    <div class="quick-nav" aria-label="Terms quick navigation">
        <a href="#acceptance">Acceptance</a>
        <a href="#acceptable-use">Acceptable Use</a>
        <a href="#api">API</a>
        <a href="#billing">Billing</a>
        <a href="#data">Data</a>
        <a href="#contact">Contact</a>
    </div>

    <div class="section" id="acceptance">
        <div class="section-num">Section 01</div>
        <h2>Acceptance of Terms</h2>
        <p>By accessing or using Lyralink (the "Service"), you agree to be bound by these Terms of Service (the "Terms"). These Terms are a legally binding agreement between you and LyralinkAI, the operator of Lyralink.</p>
        <p>If you do not agree to these Terms, you must not access or use the Service. Continued use after updates to these Terms constitutes acceptance of the revised version.</p>
    </div>

    <div class="section" id="eligibility">
        <div class="section-num">Section 02</div>
        <h2>Eligibility & Age Requirement</h2>
        <p>You must be at least <strong>13 years of age</strong> to use Lyralink. By using the Service, you represent and warrant that you meet this age requirement.</p>
        <div class="callout warn">
            <strong>Note for users under 18</strong>
            If you are between 13 and 17 years of age, you represent that your parent or legal guardian has reviewed and agreed to these Terms on your behalf. Lyralink does not knowingly permit use by children under 13.
        </div>
        <p>If we become aware that a user is under 13, we will terminate their account and delete any associated data without notice.</p>
    </div>

    <div class="section" id="accounts">
        <div class="section-num">Section 03</div>
        <h2>Accounts & Registration</h2>
        <p>To access certain features, you must register an account and keep your account details accurate. You agree to:</p>
        <ul>
            <li>Provide accurate, current, and complete information during registration</li>
            <li>Maintain and promptly update your account information</li>
            <li>Keep your password secure and not share it with any third party</li>
            <li>Accept responsibility for all activity that occurs under your account</li>
            <li>Notify us immediately at <strong><a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--accent-light)"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a></strong> if you suspect unauthorized access</li>
        </ul>
        <p>Email verification may be required to activate or access your account. We may also require additional verification steps (such as two-factor authentication) for account security.</p>
        <p>You may not create accounts using automated means, create abusive duplicate accounts, or impersonate any person or entity.</p>
    </div>

    <div class="section" id="security">
        <div class="section-num">Section 04</div>
        <h2>Security, Monitoring & Verification</h2>
        <p>To protect users, platform integrity, and comply with legal obligations, we may log security and operational events including account events, authentication attempts, and abuse indicators.</p>
        <ul>
            <li>IP addresses and related request metadata may be processed for fraud prevention, abuse mitigation, and incident response</li>
            <li>Verification codes and security challenges may expire and be rate-limited</li>
            <li>Support and moderation actions may be recorded in internal systems and audit logs</li>
        </ul>
        <p>You agree not to bypass authentication controls, rate limits, or protective mechanisms.</p>
    </div>

    <div class="section" id="acceptable-use">
        <div class="section-num">Section 05</div>
        <h2>Acceptable Use</h2>
        <p>Lyralink is an AI-powered service. You agree to use it only for lawful purposes and in a manner that does not infringe the rights of others or restrict their use and enjoyment of the Service.</p>
        <p>Acceptable use includes:</p>
        <ul>
            <li>Asking questions, getting information, creative writing, and general assistance</li>
            <li>Code help, debugging, and technical questions</li>
            <li>Learning, research, and personal productivity</li>
        </ul>
        <div class="callout">
            <strong>AI Output Disclaimer</strong>
            Responses generated by Lyralink are produced by AI models and may be inaccurate, incomplete, or outdated. You are solely responsible for how you use AI-generated content.
        </div>
    </div>

    <div class="section" id="prohibited">
        <div class="section-num">Section 06</div>
        <h2>Prohibited Content & Actions</h2>
        <p>You must not use Lyralink to generate, transmit, or facilitate content or activities that:</p>
        <ul>
            <li>Violate applicable local, national, or international law or regulation</li>
            <li>Are used for illegal purposes including fraud, harassment, threats, or exploitation</li>
            <li>Infringe intellectual property, privacy, or other rights of any third party</li>
            <li>Involve malware creation, exploit code, unauthorized intrusion, or cyberattack tooling</li>
            <li>Constitute spam, phishing, impersonation, or deceptive communications</li>
            <li>Involve child sexual abuse material or exploitation of minors</li>
            <li>Promote terrorism, violent extremism, or actionable violence</li>
            <li>Attempt to bypass, reverse-engineer, disrupt, or abuse any part of the Service</li>
        </ul>
        <div class="callout warn">
            <strong>Zero tolerance</strong>
            Violations may result in immediate suspension or termination, reporting to relevant authorities, and legal action where applicable.
        </div>
    </div>

    <div class="section" id="api">
        <div class="section-num">Section 07</div>
        <h2>API & Automation Policy</h2>
        <p>Lyralink provides documented programmatic access, including a dataset API, for eligible users under applicable plan limits.</p>
        <p><strong>Permitted use:</strong></p>
        <ul>
            <li>Querying documented endpoints within your plan limits</li>
            <li>Integrating API responses into your own applications</li>
            <li>Using your own valid API key for authorized workloads</li>
        </ul>
        <p><strong>Prohibited use:</strong></p>
        <ul>
            <li>Sharing, selling, or transferring your API key to third parties</li>
            <li>Circumventing rate limits or account restrictions</li>
            <li>Scraping, mirroring, or bulk-reproducing protected data</li>
            <li>Accessing internal endpoints not documented for public use</li>
            <li>Load testing or security scanning without prior written approval</li>
        </ul>
        <p>LyralinkAI may revoke API access for violations, abuse, security risk, or legal compliance reasons.</p>
    </div>

    <div class="section" id="billing">
        <div class="section-num">Section 08</div>
        <h2>Billing & Payments</h2>
        <p>Lyralink offers free and paid plans, including one-time credit purchases. Payments are processed by third-party processors such as PayPal. By purchasing, you also agree to the processor's terms.</p>
        <ul>
            <li><strong>Subscriptions</strong> may auto-renew until canceled</li>
            <li><strong>Credits</strong> are account-bound unless otherwise stated</li>
            <li>Pricing, plan limits, and features may change with reasonable notice</li>
            <li>You are responsible for taxes and payment-method obligations</li>
        </ul>
    </div>

    <div class="section" id="refunds">
        <div class="section-num">Section 09</div>
        <h2>Refund Policy</h2>
        <div class="callout warn">
            <strong>All sales are final</strong>
            Except where required by applicable law, subscription fees and credit purchases are non-refundable.
        </div>
        <p>If you believe a charge was made in error, contact us at <strong><a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--accent-light)"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a></strong> within 14 days.</p>
    </div>

    <div class="section" id="data">
        <div class="section-num">Section 10</div>
        <h2>Data, Privacy & Retention</h2>
        <p>Lyralink collects and processes data necessary to operate, secure, and improve the Service.</p>
        <ul>
            <li><strong>Account data</strong> - username, email, credentials metadata, and account settings</li>
            <li><strong>Service data</strong> - prompts, responses, support tickets, and related operational records</li>
            <li><strong>Security data</strong> - IP and request metadata, abuse signals, and authentication events</li>
            <li><strong>Billing data</strong> - payment and subscription records provided by integrated processors</li>
        </ul>
        <div class="callout">
            <strong>AI and prompt handling</strong>
            Prompts, uploads, and other content you send to Lyralink may be processed by third-party model providers and stored in service logs or conversation history to provide the Service. You should not submit secrets or highly sensitive personal data unless your workflow explicitly requires it and you accept the associated risk.
        </div>
        <p>We do not sell personal data. Data may be retained for legal obligations, fraud prevention, security, audit, dispute handling, and service continuity requirements.</p>
        <p>Where legally required, we may disclose relevant data to law enforcement, regulators, or other authorized entities.</p>
    </div>

    <div class="section" id="deletion">
        <div class="section-num">Section 11</div>
        <h2>Data Deletion Requests</h2>
        <p>You may request account and data deletion through available account workflows or by contacting support.</p>
        <ul>
            <li>Deletion requests may generate internal support tickets and require verification</li>
            <li>Certain records may be retained where legally required or necessary for security and fraud prevention</li>
            <li>Processing times may vary based on legal and operational requirements</li>
        </ul>
        <p>For deletion-related support, contact <strong><a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--accent-light)"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a></strong>.</p>
    </div>

    <div class="section" id="termination">
        <div class="section-num">Section 12</div>
        <h2>Account Termination</h2>
        <p>LyralinkAI may suspend or terminate access, with or without notice, for reasons including policy violations, abuse, fraud, legal requirements, or platform risk.</p>
        <ul>
            <li>Violation of these Terms</li>
            <li>Fraudulent, abusive, or illegal activity</li>
            <li>Security threats to the platform or other users</li>
            <li>Lawful requests from regulators or law enforcement</li>
        </ul>
        <p>Upon termination, access rights end immediately. Unused paid time or credits may be forfeited where termination is for cause and where permitted by law.</p>
    </div>

    <div class="section" id="disclaimer">
        <div class="section-num">Section 13</div>
        <h2>Disclaimers & Limitation of Liability</h2>
        <p>The Service is provided <strong>"as is"</strong> and <strong>"as available"</strong> without warranties of any kind.</p>
        <p>To the maximum extent permitted by law, LyralinkAI and Lyralink are not liable for indirect, incidental, consequential, special, or punitive damages arising from use of the Service.</p>
        <p>To the extent liability cannot be excluded, total liability is limited to amounts paid by you to Lyralink in the three months prior to the event giving rise to the claim.</p>
    </div>

    <div class="section" id="changes">
        <div class="section-num">Section 14</div>
        <h2>Changes to These Terms</h2>
        <p>We may update these Terms from time to time. Material updates will be posted on this page with a revised effective date and, where appropriate, communicated to users.</p>
        <p>Your continued use of the Service after updates means you accept the revised Terms.</p>
    </div>

    <div class="section" id="contact">
        <div class="section-num">Section 15</div>
        <h2>Contact</h2>
        <p>If you have legal, compliance, or account questions about these Terms, contact us:</p>
        <div class="contact-card">
            <div class="contact-icon">📧</div>
            <div class="contact-info">
                <h3>Lyralink Support</h3>
                <p>LyralinkAI</p>
                <p>Email: <?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= htmlspecialchars($supportMailto, ENT_QUOTES, 'UTF-8') ?>">Email Support</a>
        </div>
    </div>

    <hr class="divider">
    <p style="font-size:11px;color:var(--text-muted)">If any provision of these Terms is found unenforceable, the remaining provisions remain in full force and effect. See our <a href="/pages/privacy.php">Privacy Policy</a> and <a href="/pages/security.php">Security Policy</a> for related disclosures.</p>
</div>

</body>
</html>
