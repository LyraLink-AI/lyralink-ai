<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Terms of Service</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
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

        /* NAV */
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

        /* LAYOUT */
        .page-wrap { max-width: 780px; margin: 0 auto; padding: 48px 24px 80px; position: relative; z-index: 1; }

        /* HEADER */
        .page-header { margin-bottom: 40px; }
        .page-header h1 { font-family: 'Syne', sans-serif; font-size: clamp(26px, 4vw, 38px); font-weight: 800; margin-bottom: 10px; }
        .page-header h1 span { color: var(--accent-light); }
        .page-header .meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 20px; flex-wrap: wrap; }
        .page-header .meta span::before { content: '· '; }
        .page-header .meta span:first-child::before { content: ''; }

        /* TOC */
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

        /* SECTIONS */
        .section { margin-bottom: 44px; scroll-margin-top: 80px; }
        .section-num { font-size: 11px; color: var(--accent-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
        .section h2 { font-family: 'Syne', sans-serif; font-size: 20px; font-weight: 800; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
        .section p { font-size: 13px; color: var(--text-muted); margin-bottom: 12px; }
        .section p:last-child { margin-bottom: 0; }
        .section p strong { color: var(--text); }
        .section ul { list-style: none; display: flex; flex-direction: column; gap: 8px; margin: 12px 0; }
        .section ul li { font-size: 13px; color: var(--text-muted); padding-left: 16px; position: relative; }
        .section ul li::before { content: '—'; position: absolute; left: 0; color: var(--accent); }

        /* CALLOUT */
        .callout {
            background: rgba(124,58,237,0.08); border: 1px solid rgba(124,58,237,0.25);
            border-radius: 10px; padding: 14px 16px; margin: 16px 0; font-size: 13px; color: var(--text-muted);
        }
        .callout.warn { background: rgba(239,68,68,0.07); border-color: rgba(239,68,68,0.25); }
        .callout strong { color: var(--text); display: block; margin-bottom: 4px; }

        /* CONTACT CARD */
        .contact-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
            padding: 24px; margin-top: 48px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
        }
        .contact-card .contact-icon { font-size: 28px; }
        .contact-card .contact-info h3 { font-family: 'Syne', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .contact-card .contact-info p { font-size: 13px; color: var(--text-muted); }
        .contact-card a { margin-left: auto; color: var(--accent-light); text-decoration: none; font-size: 13px; border: 1px solid rgba(124,58,237,0.4); padding: 8px 16px; border-radius: 20px; transition: all 0.2s; white-space: nowrap; }
        .contact-card a:hover { background: rgba(124,58,237,0.15); }

        /* DIVIDER */
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
        }
    </style>
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <a href="/chat" class="nav-back">← Back to Chat</a>
</nav>

<div class="page-wrap">

    <div class="page-header">
        <h1>Terms of <span>Service</span></h1>
        <div class="meta">
            <span>Effective: <?= date('F j, Y') ?></span>
            <span>Lyralink · an CloudHavenX company</span>
            <span>Version 1.0</span>
        </div>
    </div>

    <!-- TABLE OF CONTENTS -->
    <div class="toc">
        <div class="toc-title">Contents</div>
        <ol>
            <li><a href="#acceptance">Acceptance of Terms</a></li>
            <li><a href="#eligibility">Eligibility & Age Requirement</a></li>
            <li><a href="#accounts">Accounts & Registration</a></li>
            <li><a href="#acceptable-use">Acceptable Use</a></li>
            <li><a href="#prohibited">Prohibited Content & Actions</a></li>
            <li><a href="#api">API & Scraping Policy</a></li>
            <li><a href="#billing">Billing & Payments</a></li>
            <li><a href="#refunds">Refund Policy</a></li>
            <li><a href="#data">Data & Privacy</a></li>
            <li><a href="#termination">Account Termination</a></li>
            <li><a href="#disclaimer">Disclaimers & Limitation of Liability</a></li>
            <li><a href="#changes">Changes to These Terms</a></li>
            <li><a href="#contact">Contact</a></li>
        </ol>
    </div>

    <!-- 1. ACCEPTANCE -->
    <div class="section" id="acceptance">
        <div class="section-num">Section 01</div>
        <h2>Acceptance of Terms</h2>
        <p>By accessing or using Lyralink ("the Service"), you agree to be bound by these Terms of Service ("Terms"). These Terms constitute a legally binding agreement between you and Lyralink, a service operated under <strong>ARXD Hosting</strong>.</p>
        <p>If you do not agree to these Terms, you must not access or use the Service. Continued use of the Service after any modifications to these Terms constitutes your acceptance of the revised Terms.</p>
    </div>

    <!-- 2. ELIGIBILITY -->
    <div class="section" id="eligibility">
        <div class="section-num">Section 02</div>
        <h2>Eligibility & Age Requirement</h2>
        <p>You must be at least <strong>13 years of age</strong> to use Lyralink. By using the Service, you represent and warrant that you meet this age requirement.</p>
        <div class="callout warn">
            <strong>Note for users under 18</strong>
            If you are between 13 and 17 years of age, you represent that your parent or legal guardian has reviewed and agreed to these Terms on your behalf. Lyralink complies with the Children's Online Privacy Protection Act (COPPA) and does not knowingly collect personal information from children under 13.
        </div>
        <p>If we become aware that a user is under 13, we will terminate their account and delete any associated data without notice.</p>
    </div>

    <!-- 3. ACCOUNTS -->
    <div class="section" id="accounts">
        <div class="section-num">Section 03</div>
        <h2>Accounts & Registration</h2>
        <p>To access certain features of the Service you must register for an account. You agree to:</p>
        <ul>
            <li>Provide accurate, current, and complete information during registration</li>
            <li>Maintain and promptly update your account information</li>
            <li>Keep your password secure and not share it with any third party</li>
            <li>Accept responsibility for all activity that occurs under your account</li>
            <li>Notify us immediately at <strong><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="a4c8c1c3c5c8e4c7c8cbd1c0ccc5d2c1cadc8ac7cbc9">[email&#160;protected]</a></strong> if you suspect unauthorized access to your account</li>
        </ul>
        <p>You may not create accounts using automated means, create multiple accounts for abusive purposes, or impersonate any person or entity.</p>
    </div>

    <!-- 4. ACCEPTABLE USE -->
    <div class="section" id="acceptable-use">
        <div class="section-num">Section 04</div>
        <h2>Acceptable Use</h2>
        <p>Lyralink is an AI-powered chat service. You agree to use it only for lawful purposes and in a manner that does not infringe the rights of others or restrict their use and enjoyment of the Service.</p>
        <p>Acceptable use includes:</p>
        <ul>
            <li>Asking questions, getting information, creative writing, and general assistance</li>
            <li>Code help, debugging, and technical questions</li>
            <li>Learning, research, and personal productivity</li>
        </ul>
        <div class="callout">
            <strong>AI Output Disclaimer</strong>
            Responses generated by Lyralink are produced by an AI model and may be inaccurate, incomplete, or outdated. You are solely responsible for how you use AI-generated content. Do not rely on it for legal, medical, financial, or safety-critical decisions without independent verification.
        </div>
    </div>

    <!-- 5. PROHIBITED -->
    <div class="section" id="prohibited">
        <div class="section-num">Section 05</div>
        <h2>Prohibited Content & Actions</h2>
        <p>You must not use Lyralink to generate, transmit, or facilitate content or activities that:</p>
        <ul>
            <li>Violate any applicable local, national, or international law or regulation</li>
            <li>Are used for illegal purposes including fraud, harassment, or threats</li>
            <li>Infringe on intellectual property, privacy, or other rights of any third party</li>
            <li>Involve the generation of malware, exploit code, or cyberattack tools</li>
            <li>Constitute spam, phishing, or other deceptive communications</li>
            <li>Involve the sexual exploitation of minors (CSAM) in any form</li>
            <li>Promote, glorify, or incite violence, terrorism, or self-harm</li>
            <li>Attempt to bypass, reverse-engineer, or exploit any part of the Service</li>
        </ul>
        <div class="callout warn">
            <strong>Zero tolerance</strong>
            Violations of this section may result in immediate account termination, reporting to relevant authorities, and potential legal action. Lyralink cooperates fully with law enforcement investigations.
        </div>
    </div>

    <!-- 6. API -->
    <div class="section" id="api">
        <div class="section-num">Section 06</div>
        <h2>API Access & Usage</h2>
        <p>Lyralink provides a <strong>public Dataset API</strong> that allows registered users to programmatically query the Lyralink Q&A dataset. Access requires a valid API key tied to your account.</p>

        <p><strong>Permitted API use:</strong></p>
        <ul>
            <li>Querying the dataset for relevant Q&A pairs within your plan's rate limits</li>
            <li>Integrating API responses into your own applications and services</li>
            <li>Automated or programmatic access using your issued API key</li>
        </ul>

        <p><strong>Prohibited API use:</strong></p>
        <ul>
            <li>Sharing, selling, or transferring your API key to any third party</li>
            <li>Circumventing or attempting to bypass rate limits by generating multiple accounts or keys</li>
            <li>Using the API to scrape, mirror, or reproduce the entire dataset</li>
            <li>Accessing any internal endpoints, infrastructure, or APIs not documented at <a href="/pages/api_docs" style="color:var(--accent-light)">/pages/api_docs</a></li>
            <li>Reverse-engineering the Service or AI model through API responses</li>
            <li>Conducting load testing or vulnerability scanning without prior written consent from ARXD Hosting</li>
        </ul>

        <div class="callout">
            <strong>Rate limits apply</strong>
            All API keys are subject to daily request limits based on your account plan. Exceeding your limit will result in a 429 response until the limit resets at midnight UTC. See the <a href="/pages/api_docs#rate-limits" style="color:var(--accent-light)">API documentation</a> for full rate limit details.
        </div>

        <p>Lyralink reserves the right to revoke API access at any time for violations of these Terms, abuse, or any reason at our sole discretion. Unauthorized programmatic access to any part of the Service outside of the documented API remains strictly prohibited.</p>
    </div>

    <!-- 7. BILLING -->
    <div class="section" id="billing">
        <div class="section-num">Section 07</div>
        <h2>Billing & Payments</h2>
        <p>Lyralink offers both free and paid subscription plans, as well as one-time credit purchases. All payments are processed securely through <strong>PayPal</strong>. By subscribing or making a purchase you agree to PayPal's terms of service in addition to these Terms.</p>
        <ul>
            <li><strong>Subscriptions</strong> are billed on a recurring monthly basis and will auto-renew unless cancelled before the renewal date</li>
            <li><strong>Credits</strong> are one-time purchases that do not expire and are tied to your account</li>
            <li>Prices are displayed in USD and are subject to change with reasonable notice</li>
            <li>You are responsible for any taxes applicable to your purchases</li>
        </ul>
        <p>To cancel a subscription, you may do so at any time from your account settings. Cancellation takes effect at the end of the current billing period.</p>
    </div>

    <!-- 8. REFUNDS -->
    <div class="section" id="refunds">
        <div class="section-num">Section 08</div>
        <h2>Refund Policy</h2>
        <div class="callout warn">
            <strong>All sales are final</strong>
            Lyralink does not offer refunds for any subscription payments or credit purchases, except where required by applicable law.
        </div>
        <p>This includes but is not limited to:</p>
        <ul>
            <li>Subscription fees for the current or past billing periods</li>
            <li>Unused credits remaining on an account at the time of cancellation or termination</li>
            <li>Partial month usage of a subscription plan</li>
        </ul>
        <p>If you believe a charge was made in error, contact us at <strong><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="5a363f3d3b361a3936352f3e323b2c3f342274393537">[email&#160;protected]</a></strong> within 14 days of the charge and we will investigate at our discretion.</p>
    </div>

    <!-- 9. DATA -->
    <div class="section" id="data">
        <div class="section-num">Section 09</div>
        <h2>Data & Privacy</h2>
        <p>Lyralink collects and stores certain data to operate the Service. By using Lyralink you consent to the following data practices:</p>
        <ul>
            <li><strong>Account data</strong> — username, email address, and hashed password stored securely in our database</li>
            <li><strong>Conversation data</strong> — messages you send and AI responses are stored to enable conversation history and may be used to improve the Service</li>
            <li><strong>Usage data</strong> — message counts, plan information, and billing records are stored for account management</li>
            <li><strong>No sale of data</strong> — we do not sell your personal data to third parties</li>
        </ul>
        <p>Conversation data may be reviewed by our team for safety, quality assurance, or to train and improve Lyralink's AI responses. You may request deletion of your account and associated data by contacting <strong><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="214d4446404d61424d4e5445494057444f590f424e4c">[email&#160;protected]</a></strong>.</p>
        <p>Lyralink is operated under <strong>ARXD Hosting</strong> and data is stored on servers within our infrastructure. We implement industry-standard security measures including password hashing and encrypted connections.</p>
    </div>

    <!-- 10. TERMINATION -->
    <div class="section" id="termination">
        <div class="section-num">Section 10</div>
        <h2>Account Termination</h2>
        <p>Lyralink reserves the right to suspend or permanently terminate your account at our sole discretion, with or without notice, for reasons including but not limited to:</p>
        <ul>
            <li>Violation of any section of these Terms</li>
            <li>Fraudulent, abusive, or illegal activity</li>
            <li>Extended periods of inactivity on free accounts</li>
            <li>Requests from law enforcement or regulatory authorities</li>
            <li>Circumstances where continued access poses a risk to the Service or other users</li>
        </ul>
        <p>Upon termination, your right to access the Service ceases immediately. Any unused credits or subscription time remaining are <strong>forfeited and non-refundable</strong> upon termination for cause.</p>
        <p>You may delete your own account at any time by contacting us at <strong><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="2b474e4c4a476b4847445e4f434a5d4e455305484446">[email&#160;protected]</a></strong>. We will process deletion requests within 30 days.</p>
    </div>

    <!-- 11. DISCLAIMER -->
    <div class="section" id="disclaimer">
        <div class="section-num">Section 11</div>
        <h2>Disclaimers & Limitation of Liability</h2>
        <p>The Service is provided <strong>"as is"</strong> and <strong>"as available"</strong> without warranties of any kind, either express or implied. Lyralink and ARXD Hosting expressly disclaim all warranties including fitness for a particular purpose, merchantability, and non-infringement.</p>
        <p>We do not warrant that:</p>
        <ul>
            <li>The Service will be uninterrupted, error-free, or secure at all times</li>
            <li>AI-generated responses will be accurate, complete, or suitable for any particular purpose</li>
            <li>Any defects in the Service will be corrected</li>
        </ul>
        <p>To the maximum extent permitted by law, Lyralink and ARXD Hosting shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of or inability to use the Service, even if we have been advised of the possibility of such damages.</p>
        <p>Our total liability to you for any claim arising from these Terms or the Service shall not exceed the total amount you have paid to Lyralink in the 3 months preceding the claim.</p>
    </div>

    <!-- 12. CHANGES -->
    <div class="section" id="changes">
        <div class="section-num">Section 12</div>
        <h2>Changes to These Terms</h2>
        <p>Lyralink reserves the right to modify these Terms at any time. When we make material changes, we will update the effective date at the top of this page and, where appropriate, notify registered users by email.</p>
        <p>Your continued use of the Service after changes are posted constitutes your acceptance of the revised Terms. If you do not agree to the updated Terms, you must stop using the Service.</p>
        <p>We encourage you to review these Terms periodically to stay informed of any updates.</p>
    </div>

    <!-- 13. CONTACT -->
    <div class="section" id="contact">
        <div class="section-num">Section 13</div>
        <h2>Contact</h2>
        <p>If you have any questions, concerns, or legal inquiries regarding these Terms, please contact us:</p>
    </div>