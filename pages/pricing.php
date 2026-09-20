<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/security.php';
/* The two admin-only cards further down are rendered only for an administrator.
 * Previously they were always rendered and hidden by a client-side is_admin
 * check, so the markup shipped to every visitor. The API behind them was never
 * exposed - api/billing.php returns "Forbidden" for both admin_gift_credits and
 * admin_gift_analytics - but hidden markup invites a later change that unhides
 * it by accident, so the server decides instead. */
$lyIsAdmin = lyra_admin_ok();
$paypalClientId = htmlspecialchars(api_get_secret('PAYPAL_CLIENT_ID', ''), ENT_QUOTES, 'UTF-8');
$usageTokensPerBlock = max(1, (int)api_get_secret('CHAT_USAGE_INPUT_TOKENS_PER_BLOCK', '100000'));
$usageUnitsPerBlock = max(1, (int)api_get_secret('CHAT_USAGE_UNITS_PER_BLOCK', '10'));
$usageMinUnits = max(0, (int)api_get_secret('CHAT_USAGE_MIN_UNITS_PER_REQUEST', '1'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Infrastructure — Operator Pricing</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <?php if ($paypalClientId !== ''): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo $paypalClientId; ?>&vault=true&intent=subscription" data-sdk-integration-source="button-factory"></script>
    <?php endif; ?>
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b;
            --molt-orange: #ff6b35; --success: #22c55e; --error: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            scrollbar-width: thin;
            scrollbar-color: var(--accent) rgba(14,14,24,0.9);
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--accent) rgba(14,14,24,0.9);
        }
        *::-webkit-scrollbar { width: 10px; height: 10px; }
        *::-webkit-scrollbar-track {
            background: rgba(14,14,24,0.9);
            border: 1px solid var(--border);
            border-radius: 999px;
        }
        *::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--accent-light), var(--accent));
            border-radius: 999px;
            border: 2px solid rgba(14,14,24,0.95);
        }
        *::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #c4b5fd, var(--accent));
        }
        *::-webkit-scrollbar-corner { background: transparent; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; }
        body::before { content:''; position:fixed; top:-200px; left:30%; width:600px; height:400px; background:radial-gradient(ellipse,rgba(124,58,237,0.1) 0%,transparent 70%); pointer-events:none; }

        nav { padding: 16px 24px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .nav-logo { width: 32px; height: 32px; background: var(--accent); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; box-shadow: 0 0 16px var(--accent-glow); }
        nav h1 { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 17px; }
        nav h1 span { color: var(--accent-light); }
        .nav-back { margin-left: auto; color: var(--text-muted); text-decoration: none; font-size: 13px; border: 1px solid var(--border); padding: 5px 12px; border-radius: 20px; transition: all 0.2s; }
        .nav-back:hover { border-color: var(--accent); color: var(--accent-light); }

        .hero { text-align: center; padding: 60px 20px 40px; }
        .hero h2 { font-family: 'Syne', sans-serif; font-size: clamp(28px, 5vw, 48px); font-weight: 800; line-height: 1.2; margin-bottom: 14px; }
        .hero h2 span { color: var(--accent-light); }
        .hero p { color: var(--text-muted); font-size: 15px; max-width: 480px; margin: 0 auto; line-height: 1.6; }
        .quick-jump { max-width: 980px; margin: 0 auto 24px; padding: 0 20px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
        .quick-jump a { color: var(--text-muted); text-decoration: none; font-size: 11px; border: 1px solid var(--border); border-radius: 999px; padding: 6px 12px; background: rgba(124,58,237,0.06); transition: all 0.2s; }
        .quick-jump a:hover { border-color: var(--accent); color: var(--accent-light); background: rgba(124,58,237,0.14); }

        .value-strip { max-width: 980px; margin: 0 auto 32px; padding: 0 20px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .value-pill { background: linear-gradient(180deg, rgba(17,17,24,0.9), rgba(17,17,24,0.75)); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; }
        .value-pill .k { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .value-pill .v { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-top: 4px; }

        /* STATUS BANNER */
        .status-banner { max-width: 500px; margin: 0 auto 40px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; display: flex; align-items: center; gap: 12px; }
        .status-plan { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 13px; }
        .status-usage { font-size: 12px; color: var(--text-muted); margin-left: auto; }
        .plan-badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .plan-badge.free       { background: rgba(100,116,139,0.2); color: var(--text-muted); border: 1px solid var(--border); }
        .plan-badge.basic      { background: rgba(34,197,94,0.15); color: var(--success); border: 1px solid rgba(34,197,94,0.3); }
        .plan-badge.pro        { background: rgba(124,58,237,0.2); color: var(--accent-light); border: 1px solid rgba(124,58,237,0.4); }
        .plan-badge.enterprise { background: rgba(255,107,53,0.15); color: var(--molt-orange); border: 1px solid rgba(255,107,53,0.3); }

        /* PLANS GRID */
        .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; max-width: 1000px; margin: 0 auto; padding: 0 20px 60px; }
        .plan-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 28px; display: flex; flex-direction: column; gap: 16px; position: relative; transition: all 0.2s; }
        .plan-card:hover { border-color: rgba(124,58,237,0.4); transform: translateY(-2px); }
        .plan-card.popular { border-color: var(--accent); box-shadow: 0 0 30px var(--accent-glow); }
        .popular-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--accent); color: white; font-size: 11px; font-weight: 700; padding: 3px 12px; border-radius: 20px; white-space: nowrap; font-family: 'Syne', sans-serif; }
        .plan-card.enterprise-card { border-color: rgba(255,107,53,0.4); }
        .plan-card.enterprise-card:hover { box-shadow: 0 0 30px rgba(255,107,53,0.2); }
        .plan-name { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 18px; }
        .plan-price { display: flex; align-items: baseline; gap: 4px; }
        .plan-price .amount { font-family: 'Syne', sans-serif; font-size: 36px; font-weight: 800; }
        .plan-price .period { color: var(--text-muted); font-size: 13px; }
        .plan-features { list-style: none; display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .plan-features li { font-size: 13px; display: flex; align-items: center; gap: 8px; color: var(--text-muted); }
        .plan-features li .check { color: var(--success); font-size: 14px; }
        .plan-features li.highlight { color: var(--text); font-weight: 600; }
        .plan-btn { padding: 12px; border-radius: 10px; font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; text-align: center; }
        .plan-btn.primary { background: var(--accent); color: white; box-shadow: 0 0 16px var(--accent-glow); }
        .plan-btn.primary:hover { background: #6d28d9; }
        .plan-btn.enterprise-btn { background: var(--molt-orange); color: white; }
        .plan-btn.enterprise-btn:hover { background: #e85d25; }
        .plan-btn.outline { background: none; color: var(--text-muted); border: 1px solid var(--border); }
        .plan-btn.outline:hover { border-color: var(--accent); color: var(--accent-light); }
        .plan-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .current-plan-label { text-align: center; font-size: 12px; color: var(--success); padding: 10px; }

        .popular-badge.enterprise { background: var(--molt-orange); }

        .section-intro { max-width: 880px; margin: 0 auto 16px; padding: 0 20px; text-align: center; }
        .section-intro h3 { font-family: 'Syne', sans-serif; font-size: clamp(20px, 3vw, 28px); font-weight: 800; margin-bottom: 8px; }
        .section-intro p { font-size: 13px; color: var(--text-muted); line-height: 1.6; }

        /* PAYPAL BUTTON CONTAINER */
        .paypal-container { margin-top: -8px; }

        /* CREDITS SECTION */
        .credits-section { max-width: 600px; margin: 0 auto; padding: 0 20px 60px; }
        .credits-section h3 { font-family: 'Syne', sans-serif; font-size: 22px; font-weight: 800; text-align: center; margin-bottom: 8px; }
        .credits-section p { text-align: center; color: var(--text-muted); font-size: 13px; margin-bottom: 24px; }
        .credits-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .credit-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 22px; text-align: center; transition: all 0.2s; }
        .credit-card:hover { border-color: rgba(124,58,237,0.4); }
        .credit-amount { font-family: 'Syne', sans-serif; font-size: 28px; font-weight: 800; color: var(--accent-light); }
        .credit-label { font-size: 12px; color: var(--text-muted); margin: 4px 0 12px; }
        .credit-price { font-family: 'Syne', sans-serif; font-size: 20px; font-weight: 700; margin-bottom: 14px; }
        .credit-btn { width: 100%; padding: 10px; background: var(--accent); color: white; border: none; border-radius: 9px; font-family: 'DM Mono', monospace; font-size: 12px; cursor: pointer; transition: all 0.2s; box-shadow: 0 0 12px var(--accent-glow); }
        .credit-btn:hover { background: #6d28d9; }
        .credit-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .usage-explainer { max-width: 980px; margin: 0 auto; padding: 0 20px 56px; }
        .usage-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 22px; }
        .usage-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 16px; }
        .usage-list { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .usage-list li { font-size: 13px; color: var(--text-muted); line-height: 1.5; }
        .usage-list li strong { color: var(--text); font-weight: 700; }
        .usage-math { border: 1px dashed var(--border); border-radius: 12px; padding: 14px; background: rgba(10,10,15,0.7); }
        .usage-math .line { font-size: 12px; color: var(--text-muted); line-height: 1.8; }
        .usage-math .line strong { color: var(--text); }

        .faq-section { max-width: 980px; margin: 0 auto; padding: 0 20px 64px; }
        .faq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .faq-item { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
        .faq-item h4 { font-family: 'Syne', sans-serif; font-size: 15px; margin-bottom: 6px; }
        .faq-item p { font-size: 12px; color: var(--text-muted); line-height: 1.6; }

        /* GIFT SECTION */
        .gift-section { max-width: 760px; margin: 0 auto; padding: 0 20px 60px; }
        .gift-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .gift-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
        .gift-card h4 { font-family: 'Syne', sans-serif; font-size: 18px; margin-bottom: 8px; }
        .gift-card p { color: var(--text-muted); font-size: 12px; margin-bottom: 14px; }
        .gift-row { display: flex; gap: 8px; margin-bottom: 10px; }
        .gift-row input { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 8px; padding: 9px 10px; font-family: 'DM Mono', monospace; font-size: 12px; outline: none; }
        .gift-row input:focus { border-color: var(--accent); }
        .gift-row button { flex-shrink: 0; }
        .gift-hint { font-size: 11px; color: var(--text-muted); min-height: 16px; margin: 6px 0 8px; }
        .gift-history { max-height: 190px; overflow: auto; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); padding: 8px; }
        .gift-item { display: flex; justify-content: space-between; gap: 10px; padding: 8px; border-bottom: 1px solid var(--border); font-size: 11px; }
        .gift-item:last-child { border-bottom: none; }
        .gift-tag { font-weight: 700; }
        .gift-tag.sent { color: var(--molt-orange); }
        .gift-tag.received { color: var(--success); }
        .gift-tag.admin { color: var(--accent-light); }
        .gift-security { font-size: 11px; color: var(--text-muted); margin: 8px 0 0; }
        .gift-divider { border-top: 1px dashed var(--border); margin: 12px 0; }
        .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .analytics-stat { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 10px; }
        .analytics-stat .k { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .analytics-stat .v { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 800; margin-top: 4px; }
        .analytics-list { max-height: 180px; overflow: auto; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); padding: 8px; }
        .analytics-item { display: flex; justify-content: space-between; gap: 10px; padding: 8px; border-bottom: 1px solid var(--border); font-size: 11px; }
        .analytics-item:last-child { border-bottom: none; }

        /* TOAST */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 20px; font-size: 13px; z-index: 999; opacity: 0; transition: opacity 0.3s; pointer-events: none; white-space: nowrap; }
        .toast.show { opacity: 1; }
        .toast.success { border-color: var(--success); color: var(--success); }
        .toast.error   { border-color: var(--error);   color: var(--error); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 100; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 28px; max-width: 420px; width: 100%; position: relative; }
        .modal h3 { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .modal p { color: var(--text-muted); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
        .modal-close { position: absolute; top: 16px; right: 16px; background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; }

        @media (max-width: 480px) {
            .plans { grid-template-columns: 1fr; }
            .credits-grid { grid-template-columns: 1fr; }
            .gift-grid { grid-template-columns: 1fr; }
            .faq-grid { grid-template-columns: 1fr; }
            .usage-grid { grid-template-columns: 1fr; }
            .value-strip { grid-template-columns: 1fr; padding: 0 16px; }
        }

        /* ── MOBILE ── */
        @media (max-width: 640px) {
            nav { padding: 12px 16px; gap: 8px; }
            nav img { height: 24px; }
            .nav-back { font-size: 11px; padding: 4px 10px; }
            .hero { padding: 36px 16px 28px; }
            .hero h2 { font-size: 26px; }
            .hero p { font-size: 13px; }
            .quick-jump { justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding: 0 16px; margin-bottom: 18px; }
            .quick-jump a { white-space: nowrap; }
            .status-banner { margin: 0 16px 20px; padding: 12px 14px; }
            .section-intro { padding: 0 16px; }
            .plans { grid-template-columns: 1fr; padding: 0 16px 48px; gap: 14px; }
            .plan-card { padding: 22px 18px; }
            .credits-section { padding: 0 16px 48px; }
            .credits-grid { grid-template-columns: 1fr 1fr !important; }
            .usage-explainer { padding: 0 16px 48px; }
            .gift-section { padding: 0 16px 48px; }
            .gift-grid { grid-template-columns: 1fr; }
            .faq-section { padding: 0 16px 60px; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>

<nav>
    <div class="nav-logo">⚡</div>
    <h1>Lyra<span>link</span></h1>
    <a href="/chat" class="nav-back">← Back to Chat</a>
</nav>

<div class="hero">
    <h2>Execution pricing for <span>AI operators</span></h2>
    <p>Simple monthly plans plus usage-based execution units. You stay competitive with auto-routing discounts while heavy models are billed fairly.</p>
</div>

<div class="quick-jump" aria-label="Pricing sections">
    <a href="#plansSection">Plans</a>
    <a href="#usageSection">Usage</a>
    <a href="#creditsSection">Credits</a>
    <a href="#faqSection">FAQ</a>
    <a href="#giftSection">Gift Credits</a>
</div>

<div class="value-strip">
    <div class="value-pill">
        <div class="k">Base Billing Block</div>
        <div class="v"><?php echo number_format($usageTokensPerBlock); ?> tokens = <?php echo number_format($usageUnitsPerBlock); ?> units</div>
    </div>
    <div class="value-pill">
        <div class="k">Auto Route Benefit</div>
        <div class="v">Automatic routing receives discounted unit billing</div>
    </div>
    <div class="value-pill">
        <div class="k">Minimum Charge</div>
        <div class="v"><?php echo number_format($usageMinUnits); ?> unit<?php echo $usageMinUnits === 1 ? '' : 's'; ?> per request</div>
    </div>
</div>

<!-- CURRENT STATUS -->
<div class="status-banner" id="statusBanner" style="display:none">
    <div>
        <div class="status-plan">Current Plan: <span id="statusPlanName">Free</span></div>
        <div class="status-usage" id="statusUsage"></div>
    </div>
    <span class="plan-badge free" id="statusBadge">Free</span>
</div>

<!-- PLANS -->
<div class="section-intro">
    <h3>Choose your operator lane</h3>
    <p>All plans include full API access. Move up when your live volume, routing complexity, or support requirements grow.</p>
</div>
<div class="plans" id="plansSection">

    <!-- FREE -->
    <div class="plan-card" id="card-free">
        <div class="plan-name">Free</div>
        <div class="plan-price"><span class="amount">$0</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> 1.2M tokens/month</li>
            <li><span class="check">✓</span> Core AI infrastructure access</li>
            <li><span class="check">✓</span> Platform onboarding and testing</li>
            <li><span class="check">✓</span> Good for initial validation</li>
        </ul>
        <div id="free-action"><div class="current-plan-label" id="free-current" style="display:none">✓ Your current plan</div></div>
    </div>

    <!-- BASIC -->
    <div class="plan-card" id="card-basic">
        <div class="plan-name">Basic</div>
        <div class="plan-price"><span class="amount">$5</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> 2.2M tokens/month</li>
            <li><span class="check">✓</span> Better capacity for live client traffic</li>
            <li><span class="check">✓</span> Lower risk baseline for paid rollouts</li>
            <li><span class="check">✓</span> Best for early-stage operator monetization</li>
        </ul>
        <div id="basic-action">
            <button class="plan-btn outline" onclick="subscribePlan('basic')">Get Basic</button>
        </div>
    </div>

    <!-- PRO -->
    <div class="plan-card popular" id="card-pro">
        <div class="popular-badge">Most Popular</div>
        <div class="plan-name">Pro</div>
        <div class="plan-price"><span class="amount">$15</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> Higher monthly execution allowance</li>
            <li><span class="check">✓</span> Strong fit for reseller growth loops</li>
            <li><span class="check">✓</span> Faster throughput during active usage windows</li>
            <li><span class="check">✓</span> Balanced unit economics for margin at scale</li>
        </ul>
        <div id="pro-action">
            <button class="plan-btn primary" onclick="subscribePlan('pro')">Get Pro</button>
        </div>
    </div>

    <!-- ENTERPRISE -->
    <div class="plan-card enterprise-card" id="card-enterprise">
        <div class="popular-badge enterprise">High Volume</div>
        <div class="plan-name">Enterprise</div>
        <div class="plan-price"><span class="amount">$30</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> Priority routing and higher capacity</li>
            <li><span class="check">✓</span> Premium execution profiles and support</li>
            <li><span class="check">✓</span> Built for high-volume client offers</li>
            <li><span class="check">✓</span> Stronger operational visibility</li>
        </ul>
        <div id="enterprise-action">
            <button class="plan-btn enterprise-btn" onclick="subscribePlan('enterprise')">Get Enterprise</button>
        </div>
    </div>

</div>

<div class="usage-explainer" id="usageSection">
    <div class="section-intro" style="padding:0;margin-bottom:12px;max-width:none">
        <h3>How usage billing works</h3>
        <p>Execution usage is token-based, then adjusted by model tier and routing behavior. Light/cheap routes cost less, heavy/reasoning routes cost more.</p>
    </div>
    <div class="usage-card">
        <div class="usage-grid">
            <ul class="usage-list">
                <li><strong>Base conversion:</strong> tokens are converted into usage units using your configured block size.</li>
                <li><strong>Model multipliers:</strong> lightweight, coding, reasoning, and high-parameter models can each have different rates.</li>
                <li><strong>Auto-route discount:</strong> when the system auto-selects an efficient route, discounted usage units are applied.</li>
                <li><strong>Predictable floor:</strong> each request still respects a minimum unit charge for sustainable operations.</li>
            </ul>
            <div class="usage-math">
                <div class="line"><strong>1)</strong> base = ceil(tokens * units_per_block / tokens_per_block)</div>
                <div class="line"><strong>2)</strong> tiered = ceil(base * multiplier_bps / 10000)</div>
                <div class="line"><strong>3)</strong> auto = ceil(tiered * auto_discount_bps / 10000)</div>
                <div class="line"><strong>4)</strong> billed = max(min_units_per_request, auto)</div>
            </div>
        </div>
    </div>
</div>

<!-- CREDITS -->
<div class="credits-section" id="creditsSection">
    <h3>Need burst capacity?</h3>
    <p>Top up with credits for campaigns, launches, and temporary demand spikes. Each credit covers one over-limit request and never expires.</p>
    <div class="credits-grid">
        <div class="credit-card">
            <div class="credit-amount">100</div>
            <div class="credit-label">Credits</div>
            <div class="credit-price">$3.00</div>
            <button class="credit-btn" onclick="buyCredits('pack_100')">Buy Credits</button>
        </div>
        <div class="credit-card">
            <div class="credit-amount">500</div>
            <div class="credit-label">Credits</div>
            <div class="credit-price">$10.00</div>
            <button class="credit-btn" onclick="buyCredits('pack_500')">Buy Credits</button>
        </div>
    </div>
</div>

<div class="faq-section" id="faqSection">
    <div class="section-intro" style="padding:0;margin-bottom:12px;max-width:none">
        <h3>Quick answers</h3>
        <p>Common operator questions before upgrading.</p>
    </div>
    <div class="faq-grid">
        <div class="faq-item">
            <h4>Do credits expire?</h4>
            <p>No. Credit packs are persistent and designed for burst traffic, campaign spikes, and launch weeks.</p>
        </div>
        <div class="faq-item">
            <h4>Can I downgrade later?</h4>
            <p>Yes. You can cancel your active subscription and your account falls back to the free lane.</p>
        </div>
        <div class="faq-item">
            <h4>Why are some requests billed higher?</h4>
            <p>Large or reasoning-heavy models use higher multipliers because their compute cost is materially higher.</p>
        </div>
        <div class="faq-item">
            <h4>How do I get lower usage cost?</h4>
            <p>Use auto routing and lighter models for everyday flows, then reserve premium models for critical tasks.</p>
        </div>
    </div>
</div>

<!-- GIFT CREDITS -->
<div class="gift-section" id="giftSection" style="display:none">
    <h3 style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;text-align:center;margin-bottom:8px">Gift Credits</h3>
    <p style="text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:20px">Send credits securely to another account by username, user ID, or email.</p>
    <div class="gift-grid">
        <div class="gift-card">
            <h4>Send Gift</h4>
            <p>Transfers are final. Recipient is validated before any credit movement.</p>
            <div class="gift-row">
                <input type="text" id="giftRecipient" placeholder="Recipient username, email, or user ID">
                <button class="plan-btn outline" onclick="previewGiftRecipient()">Verify</button>
            </div>
            <div class="gift-hint" id="giftRecipientHint"></div>
            <div class="gift-row">
                <input type="number" id="giftAmount" min="1" max="2000" placeholder="Amount (1-2000)">
            </div>
            <div class="gift-row">
                <input type="text" id="giftNote" maxlength="200" placeholder="Optional note (max 200 chars)">
            </div>
            <div class="gift-divider"></div>
            <div class="gift-row">
                <input type="text" id="giftFactorCode" maxlength="6" placeholder="Authenticator code (required for 1000+)">
            </div>
            <div class="gift-row">
                <input type="text" id="giftRecoveryCode" maxlength="16" placeholder="Or recovery code (optional)">
            </div>
            <div class="gift-row">
                <input type="text" id="giftYubikeyOtp" maxlength="64" placeholder="Or YubiKey OTP if your 2FA method is YubiKey">
            </div>
            <div class="gift-security">High-value gifts (1000+ credits) require 2FA step-up verification.</div>
            <button class="plan-btn primary" style="width:100%" onclick="sendGiftCredits()">Send Credits</button>
            <div class="gift-hint" id="giftActionHint"></div>
        </div>
        <div class="gift-card">
            <h4>Recent Transfers</h4>
            <p>Your latest sent/received credit transfers.</p>
            <div class="gift-history" id="giftHistoryList">
                <div style="padding:8px;font-size:11px;color:var(--text-muted)">No transfers yet.</div>
            </div>
        </div>
    </div>

    <?php if ($lyIsAdmin): ?>
    <div class="gift-card" id="adminGiftCard" style="margin-top:16px;display:none">
        <h4>Admin Credit Grant</h4>
        <p>Administrative grant path. This does not deduct from your own balance.</p>
        <div class="gift-row">
            <input type="text" id="adminGiftRecipient" placeholder="Recipient username, email, or user ID">
            <input type="number" id="adminGiftAmount" min="1" max="50000" placeholder="Amount">
        </div>
        <div class="gift-row">
            <input type="text" id="adminGiftNote" maxlength="200" placeholder="Reason / note">
        </div>
        <button class="plan-btn enterprise-btn" style="width:100%" onclick="adminGiftCredits()">Grant Credits</button>
        <div class="gift-hint" id="adminGiftHint"></div>
    </div>

    <div class="gift-card" id="adminAnalyticsCard" style="margin-top:16px;display:none">
        <h4>Transfer Analytics</h4>
        <p>7-day operational visibility for user gifts and admin grants.</p>
        <div class="analytics-grid" id="adminAnalyticsSummary">
            <div class="analytics-stat"><div class="k">User Gifts 24h</div><div class="v">0</div></div>
            <div class="analytics-stat"><div class="k">Admin Grants 24h</div><div class="v">0</div></div>
            <div class="analytics-stat"><div class="k">User Gifts 7d</div><div class="v">0</div></div>
            <div class="analytics-stat"><div class="k">Admin Grants 7d</div><div class="v">0</div></div>
        </div>
        <div class="gift-grid" style="margin-top:12px">
            <div>
                <p style="margin-bottom:6px">Top Senders (7d)</p>
                <div class="analytics-list" id="adminTopSenders"></div>
            </div>
            <div>
                <p style="margin-bottom:6px">Top Recipients (7d)</p>
                <div class="analytics-list" id="adminTopRecipients"></div>
            </div>
        </div>
        <p style="margin:12px 0 6px">Recent High-Value Transfers (1000+)</p>
        <div class="analytics-list" id="adminHighValueTransfers"></div>
    </div>
    <?php endif; ?>
</div>

<!-- PAYPAL MODAL (credits) -->
<div class="modal-overlay" id="paypalModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()">✕</button>
        <h3 id="modalTitle">Buy Credits</h3>
        <p id="modalDesc">Complete your purchase with PayPal.</p>
        <div id="paypal-button-container"></div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
    let currentPlan    = 'free';
    let currentCredits = 0;
    let pendingPack    = null;
    let currentIsAdmin = false;
    const HIGH_VALUE_GIFT_THRESHOLD = 1000;

    function parseServerDate(input) {
        if (!input) return null;
        const normalized = String(input).trim().replace(' ', 'T');
        const hasZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized);
        const iso = hasZone ? normalized : normalized + 'Z';
        const parsed = new Date(iso);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatClientDate(input) {
        const parsed = parseServerDate(input);
        return parsed ? parsed.toLocaleString() : 'Unknown time';
    }

    function normalizePlan(plan) {
        const value = String(plan || '').trim().toLowerCase();
        const aliases = { starter: 'basic', plus: 'pro', business: 'enterprise', team: 'enterprise' };
        const normalized = aliases[value] || value;
        return ['free', 'basic', 'pro', 'enterprise'].includes(normalized) ? normalized : 'free';
    }

    // ── LOAD STATUS ──
    async function loadStatus() {
        try {
            const fd = new FormData(); fd.append('action', 'status');
            const data = await (await fetch('/api/billing.php', {method:'POST', body:fd})).json();

            if (data.logged_in) {
                const planCode = normalizePlan(data.plan);
                currentPlan    = planCode;
                currentCredits = data.credits;
                currentIsAdmin = !!data.is_admin;

                document.getElementById('statusBanner').style.display = 'flex';
                document.getElementById('statusPlanName').textContent  = data.plan_name;
                document.getElementById('statusBadge').textContent     = data.plan_name;
                document.getElementById('statusBadge').className       = 'plan-badge ' + planCode;

                const used  = Number.isFinite(Number(data.tokens_used)) ? Number(data.tokens_used) : Number(data.messages_used || 0);
                const limit = Number.isFinite(Number(data.tokens_limit)) ? Number(data.tokens_limit) : Number(data.messages_limit || 0);
                document.getElementById('statusUsage').textContent = data.unlimited
                    ? `Credits: ${data.credits} · Fair-use execution allowance`
                    : `${used.toLocaleString()} / ${limit.toLocaleString()} tokens used · ${data.credits} credits`;

                updatePlanButtons(planCode);
                document.getElementById('giftSection').style.display = 'block';
                /* Absent for a non-admin, because the server no longer renders
                   them, so both lookups are guarded rather than assumed. */
                var lyGiftCard = document.getElementById('adminGiftCard');
                if (lyGiftCard) { lyGiftCard.style.display = currentIsAdmin ? 'block' : 'none'; }
                var lyAnalyticsCard = document.getElementById('adminAnalyticsCard');
                if (lyAnalyticsCard) { lyAnalyticsCard.style.display = currentIsAdmin ? 'block' : 'none'; }
                loadGiftHistory();
                if (currentIsAdmin) {
                    loadAdminGiftAnalytics();
                }
            } else {
                document.getElementById('giftSection').style.display = 'none';
            }
        } catch(e) {}
    }

    async function previewGiftRecipient() {
        const recipient = document.getElementById('giftRecipient').value.trim();
        const hint = document.getElementById('giftRecipientHint');
        if (!recipient) {
            hint.textContent = 'Enter a recipient first.';
            return;
        }
        const fd = new FormData();
        fd.append('action', 'gift_preview_recipient');
        fd.append('recipient', recipient);
        const data = await (await fetch('/api/billing.php', { method: 'POST', body: fd })).json();
        if (data.success) {
            hint.textContent = `Verified: ${data.recipient.username} (${data.recipient.email_masked})`;
            hint.style.color = 'var(--success)';
        } else {
            hint.textContent = data.error || 'Recipient not found';
            hint.style.color = 'var(--error)';
        }
    }

    async function sendGiftCredits() {
        const recipient = document.getElementById('giftRecipient').value.trim();
        const amount = document.getElementById('giftAmount').value.trim();
        const note = document.getElementById('giftNote').value.trim();
        const factorCode = document.getElementById('giftFactorCode').value.trim();
        const factorRecoveryCode = document.getElementById('giftRecoveryCode').value.trim();
        const factorYubikeyOtp = document.getElementById('giftYubikeyOtp').value.trim();
        const hint = document.getElementById('giftActionHint');
        if (!recipient || !amount) {
            hint.textContent = 'Recipient and amount are required.';
            hint.style.color = 'var(--error)';
            return;
        }

        if (parseInt(amount, 10) >= HIGH_VALUE_GIFT_THRESHOLD && !factorCode && !factorRecoveryCode && !factorYubikeyOtp) {
            hint.textContent = 'This amount requires 2FA verification input.';
            hint.style.color = 'var(--error)';
            return;
        }

        if (!confirm(`Send ${amount} credits to ${recipient}? This cannot be reversed.`)) {
            return;
        }

        const fd = new FormData();
        fd.append('action', 'gift_credits');
        fd.append('recipient', recipient);
        fd.append('amount', amount);
        fd.append('note', note);
        fd.append('factor_code', factorCode);
        fd.append('factor_recovery_code', factorRecoveryCode);
        fd.append('factor_yubikey_otp', factorYubikeyOtp);
        const data = await (await fetch('/api/billing.php', { method: 'POST', body: fd })).json();
        if (data.success) {
            hint.textContent = `Transfer ${data.transfer_ref} sent to ${data.recipient}.`;
            hint.style.color = 'var(--success)';
            showToast(`Gifted ${data.amount} credits to ${data.recipient}`, 'success');
            document.getElementById('giftAmount').value = '';
            document.getElementById('giftNote').value = '';
            document.getElementById('giftFactorCode').value = '';
            document.getElementById('giftRecoveryCode').value = '';
            document.getElementById('giftYubikeyOtp').value = '';
            loadStatus();
        } else {
            hint.textContent = data.error || 'Transfer failed';
            hint.style.color = 'var(--error)';
            showToast(data.error || 'Transfer failed', 'error');
        }
    }

    async function loadGiftHistory() {
        const fd = new FormData();
        fd.append('action', 'gift_history');
        const data = await (await fetch('/api/billing.php', { method: 'POST', body: fd })).json();
        const list = document.getElementById('giftHistoryList');
        if (!data.success || !Array.isArray(data.history) || data.history.length === 0) {
            list.innerHTML = '<div style="padding:8px;font-size:11px;color:var(--text-muted)">No transfers yet.</div>';
            return;
        }
        list.innerHTML = data.history.map(item => {
            const when = formatClientDate(item.created_at);
            const dirClass = item.type === 'admin_grant' ? 'admin' : (item.direction === 'sent' ? 'sent' : 'received');
            const dirLabel = item.type === 'admin_grant' ? 'ADMIN GRANT' : (item.direction === 'sent' ? 'SENT' : 'RECEIVED');
            const counterpart = item.direction === 'sent' ? `to ${item.to}` : `from ${item.from}`;
            return `<div class="gift-item">
                <div>
                    <div><span class="gift-tag ${dirClass}">${dirLabel}</span> ${item.amount} credits ${counterpart}</div>
                    <div style="color:var(--text-muted)">${item.transfer_ref} · ${when}</div>
                </div>
            </div>`;
        }).join('');
    }

    async function adminGiftCredits() {
        if (!currentIsAdmin) {
            showToast('Admin privileges required', 'error');
            return;
        }
        const recipient = document.getElementById('adminGiftRecipient').value.trim();
        const amount = document.getElementById('adminGiftAmount').value.trim();
        const note = document.getElementById('adminGiftNote').value.trim();
        const hint = document.getElementById('adminGiftHint');
        if (!recipient || !amount) {
            hint.textContent = 'Recipient and amount are required.';
            hint.style.color = 'var(--error)';
            return;
        }
        if (!confirm(`Admin grant ${amount} credits to ${recipient}?`)) {
            return;
        }
        const fd = new FormData();
        fd.append('action', 'admin_gift_credits');
        fd.append('recipient', recipient);
        fd.append('amount', amount);
        fd.append('note', note);
        const data = await (await fetch('/api/billing.php', { method: 'POST', body: fd })).json();
        if (data.success) {
            hint.textContent = `Granted ${data.amount} credits to ${data.recipient} (${data.transfer_ref}).`;
            hint.style.color = 'var(--success)';
            showToast(`Admin grant complete: ${data.amount} credits`, 'success');
            loadGiftHistory();
            loadAdminGiftAnalytics();
        } else {
            hint.textContent = data.error || 'Admin grant failed';
            hint.style.color = 'var(--error)';
            showToast(data.error || 'Admin grant failed', 'error');
        }
    }

    function renderAnalyticsList(elementId, rows, formatter) {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            el.innerHTML = '<div style="padding:8px;font-size:11px;color:var(--text-muted)">No data yet.</div>';
            return;
        }
        el.innerHTML = rows.map(formatter).join('');
    }

    async function loadAdminGiftAnalytics() {
        if (!currentIsAdmin) return;
        const fd = new FormData();
        fd.append('action', 'admin_gift_analytics');
        const data = await (await fetch('/api/billing.php', { method: 'POST', body: fd })).json();
        if (!data.success) {
            return;
        }

        const s = data.summary || {};
        const summary = document.getElementById('adminAnalyticsSummary');
        if (summary) {
            summary.innerHTML = `
                <div class="analytics-stat"><div class="k">User Gifts 24h</div><div class="v">${(s.user_gift_24h_amount || 0).toLocaleString()} <span style="font-size:11px;color:var(--text-muted)">(${s.user_gift_24h_count || 0})</span></div></div>
                <div class="analytics-stat"><div class="k">Admin Grants 24h</div><div class="v">${(s.admin_grant_24h_amount || 0).toLocaleString()} <span style="font-size:11px;color:var(--text-muted)">(${s.admin_grant_24h_count || 0})</span></div></div>
                <div class="analytics-stat"><div class="k">User Gifts 7d</div><div class="v">${(s.user_gift_7d_amount || 0).toLocaleString()}</div></div>
                <div class="analytics-stat"><div class="k">Admin Grants 7d</div><div class="v">${(s.admin_grant_7d_amount || 0).toLocaleString()}</div></div>
            `;
        }

        renderAnalyticsList('adminTopSenders', data.top_senders, row => `
            <div class="analytics-item">
                <div>${row.username}</div>
                <div>${Number(row.total_amount || 0).toLocaleString()} (${row.transfer_count || 0})</div>
            </div>
        `);

        renderAnalyticsList('adminTopRecipients', data.top_recipients, row => `
            <div class="analytics-item">
                <div>${row.username}</div>
                <div>${Number(row.total_amount || 0).toLocaleString()} (${row.transfer_count || 0})</div>
            </div>
        `);

        renderAnalyticsList('adminHighValueTransfers', data.high_value, row => {
            const when = formatClientDate(row.created_at);
            const tag = row.type === 'admin_grant' ? 'ADMIN' : 'USER';
            return `
                <div class="analytics-item">
                    <div>
                        <div><span class="gift-tag ${row.type === 'admin_grant' ? 'admin' : 'sent'}">${tag}</span> ${Number(row.amount || 0).toLocaleString()} from ${row.from} to ${row.to}</div>
                        <div style="color:var(--text-muted)">${row.transfer_ref} · ${when}</div>
                    </div>
                </div>
            `;
        });
    }

    function updatePlanButtons(plan) {
        ['free','basic','pro','enterprise'].forEach(p => {
            const action = document.getElementById(p + '-action');
            if (!action) return;
            if (p === plan) {
                action.innerHTML = `<div class="current-plan-label">✓ Your current plan</div>`;
                if (plan !== 'free') {
                    action.innerHTML += `<button class="plan-btn outline" style="margin-top:8px" onclick="cancelSub()">Cancel</button>`;
                }
            }
        });
    }

    // ── SUBSCRIBE ──
    async function subscribePlan(plan) {
        const fd = new FormData(); fd.append('action', 'create_subscription'); fd.append('plan', plan);
        const data = await (await fetch('/api/billing.php', {method:'POST', body:fd})).json();

        if (data.success && data.approval_url) {
            window.location.href = data.approval_url;
        } else if (data.error === 'Plan not configured yet') {
            showToast('PayPal plan IDs not configured yet — see setup guide', 'error');
        } else if (!data.success && data.error === 'Not logged in') {
            showToast('Please log in first to subscribe', 'error');
        } else {
            showToast(data.error || 'Something went wrong', 'error');
        }
    }

    // ── CANCEL SUBSCRIPTION ──
    async function cancelSub() {
        if (!confirm('Cancel your subscription? You\'ll drop to the free plan.')) return;
        const fd = new FormData(); fd.append('action', 'cancel_subscription');
        const data = await (await fetch('/api/billing.php', {method:'POST', body:fd})).json();
        if (data.success) { showToast('Subscription cancelled', 'success'); setTimeout(() => location.reload(), 1500); }
        else showToast(data.error || 'Failed to cancel', 'error');
    }

    // ── BUY CREDITS ──
    function buyCredits(pack) {
        if (typeof paypal === 'undefined' || !paypal || typeof paypal.Buttons !== 'function') {
            showToast('PayPal checkout is not configured yet.', 'error');
            return;
        }
        pendingPack = pack;
        const titles = { pack_100: '100 Credits — $3.00', pack_500: '500 Credits — $10.00' };
        document.getElementById('modalTitle').textContent = titles[pack] || 'Buy Credits';
        document.getElementById('modalDesc').textContent  = 'Each credit = 1 extra message. Never expires.';
        document.getElementById('paypalModal').classList.add('open');
        renderPaypalButton(pack);
    }

    function closeModal() {
        document.getElementById('paypalModal').classList.remove('open');
        document.getElementById('paypal-button-container').innerHTML = '';
    }

    function renderPaypalButton(pack) {
        document.getElementById('paypal-button-container').innerHTML = '';
        paypal.Buttons({
            createOrder: async function() {
                const fd = new FormData(); fd.append('action','create_order'); fd.append('pack', pack);
                const data = await (await fetch('/api/billing.php',{method:'POST',body:fd})).json();
                if (!data.success) { showToast(data.error || 'Failed to create order', 'error'); throw new Error(data.error); }
                return data.order_id;
            },
            onApprove: async function(data) {
                const fd = new FormData(); fd.append('action','capture_order'); fd.append('order_id', data.orderID);
                const result = await (await fetch('/api/billing.php',{method:'POST',body:fd})).json();
                if (result.success) {
                    closeModal();
                    showToast('✓ ' + result.credits_added + ' credits added!', 'success');
                    setTimeout(() => loadStatus(), 1000);
                } else {
                    showToast(result.error || 'Payment failed', 'error');
                }
            },
            onError: function(err) {
                showToast('PayPal error — please try again', 'error');
            }
        }).render('#paypal-button-container');
    }

    // ── CHECK URL PARAMS (after PayPal redirect) ──
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('billing') === 'success') {
        showToast('✓ Plan upgraded successfully!', 'success');
        history.replaceState({}, '', '/pages/pricing.php');
    } else if (urlParams.get('billing') === 'cancelled') {
        showToast('Subscription cancelled', 'error');
        history.replaceState({}, '', '/pages/pricing.php');
    }

    function showToast(msg, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent  = msg;
        toast.className    = 'toast show ' + type;
        setTimeout(() => toast.className = 'toast', 3000);
    }

    loadStatus();
</script>
</body>
</html>@@@teams
<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lyra_ui_nav.php';
require_once __DIR__ . '/../api/lyra_teams_chrome.php';
if (file_exists(__DIR__ . '/../maintenance.flag') && !lyra_dev_preview()) {
    header('Location: /pages/maintenance.php'); exit;
}
/* Collaboration workspace. Implements the approved Teams-style design.
 * Reads real rows from the existing social_* tables. Uses SELECT * and defensive
 * key access so a schema change degrades to an empty state, not a fatal error.
 * Posting/presence/calls are NOT implemented here — this is the interface shell. */

$channels = $messages = $members = [];
$active = null; $dbError = null; $users = []; $chanCounts = []; $nOnline = 0;

try {
    $cfg = api_db_config(['host'=>'localhost','user'=>'app_user','pass'=>'','name'=>'aicloud']);
    $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
    if ($db->connect_error) { $dbError = $db->connect_error; }
    else {
        // Each query is isolated: mysli throws on error in PHP 8.1+, and an
        // unguarded failure here previously aborted every query after it,
        // blanking panels that had perfectly good data available.
        $run = function (string $sql) use ($db): array {
            try {
                $rows = [];
                $res = $db->query($sql);
                if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
                return $rows;
            } catch (\Throwable $e) { return []; }
        };

        $channels = $run('SELECT * FROM social_channels ORDER BY position, id');
        $members  = $run('SELECT * FROM social_server_members LIMIT 50');
        foreach ($run('SELECT id, username, plan FROM users') as $r) {
            $users[(int) $r['id']] = $r;
        }

        // Per-channel message totals, so the sidebar shows a real count for
        // every channel rather than only the one that happens to be open.
        foreach ($run(
            'SELECT cc.channel_id, COUNT(m.id) AS n '
            . 'FROM social_channel_conversations cc '
            . 'LEFT JOIN social_messages m ON m.conversation_id = cc.conversation_id '
            . 'GROUP BY cc.channel_id'
        ) as $r) {
            $chanCounts[(int) $r['channel_id']] = (int) $r['n'];
        }

        // Presence is derived from last_seen_at, which is the only signal the
        // schema actually provides. Anything seen in the last 5 minutes counts
        // as online; that is a real measurement, not an invented one.
        $cutoff = date('Y-m-d H:i:s', time() - 300);
        foreach ($members as $mm) {
            $ls = (string) ($mm['last_seen_at'] ?? '');
            if ($ls !== '' && $ls >= $cutoff) { $nOnline++; }
        }

        // Messages hang off conversations, which hang off channels:
        // social_channels <- social_channel_conversations -> social_messages
        if ($channels) {
            // Honour ?channel=<id> so the channel list is real navigation
            // instead of a row of links that all return the same channel.
            $want = isset($_GET['channel']) ? (int) $_GET['channel'] : 0;
            $active = $channels[0];
            if ($want > 0) {
                foreach ($channels as $c) {
                    if ((int) ($c['id'] ?? 0) === $want) { $active = $c; break; }
                }
            }
            $cid = (int) ($active['id'] ?? 0);
            $messages = $run(
                'SELECT m.* FROM social_messages m '
                . 'JOIN social_channel_conversations cc ON cc.conversation_id = m.conversation_id '
                . 'WHERE cc.channel_id = ' . $cid . ' ORDER BY m.id ASC LIMIT 200'
            );
        }
        $db->close();
    }
} catch (\Throwable $e) { $dbError = $e->getMessage(); }

function pk(array $r, array $keys, string $d = ''): string {
    foreach ($keys as $k) { if (isset($r[$k]) && trim((string)$r[$k]) !== '') return (string)$r[$k]; }
    return $d;
}
function inits(string $s): string {
    $c = preg_replace('/[^A-Za-z]/', '', $s);
    return strtoupper(substr($c !== '' ? $c : 'U', 0, 2));
}

/* Display name for a user id, falling back to an explicit identifier rather
 * than a silent zero. The previous version read `user_id`/`author_id`, but the
 * real column is `sender_user_id`, so every message was labelled "user 0". */
function tw_author(int $uid, array $users): string {
    if (isset($users[$uid]) && trim((string) $users[$uid]['username']) !== '') {
        return (string) $users[$uid]['username'];
    }
    return $uid > 0 ? 'user ' . $uid : 'unknown';
}

/* Relative time for recent items, absolute date for anything older, so an old
 * message never reads as if it just arrived. */
function tw_when(string $s): string {
    $t = strtotime($s);
    if ($t === false) { return $s; }
    $d = time() - $t;
    if ($d < 0)     { return date('M j, Y H:i', $t); }
    if ($d < 60)    { return 'just now'; }
    if ($d < 3600)  { return floor($d / 60) . 'm ago'; }
    if ($d < 86400) { return floor($d / 3600) . 'h ago'; }
    if ($d < 604800) { return floor($d / 86400) . 'd ago'; }
    return date('M j, Y', $t);
}

$nCh = count($channels); $nMsg = count($messages); $nMem = count($members);
$activeName = $active ? pk($active, ['name'], 'channel') : '';

/* Viewer identity comes from api/lyra_ui_nav.php (already required above).
 * This page previously had its own copy of the lookup; two implementations of
 * "who is the viewer" is exactly how the pages drifted apart, so it now shares
 * the single one. $viewerLabel / $viewerInitials / $viewerStatus keep their
 * names because the markup below references them. */
$lyViewer       = lyra_ui_viewer();
$viewerLabel    = (string) $lyViewer['label'];
$viewerInitials = (string) $lyViewer['initials'];
$viewerStatus   = (string) $lyViewer['status'];

/* Time-of-day greeting, computed rather than written into the markup. */
$hourAtRender = (int) date('G');
$greetingWord = $hourAtRender < 12
    ? 'Good morning'
    : ($hourAtRender < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Workspace | Lyralink</title>
<link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/lyra-ui.css">
<link rel="stylesheet" href="/assets/css/lyra-teams.css">
<script src="/assets/js/lyra-ui.js" defer></script>
<style>
/* ── SHELL ────────────────────────────────────────────────────────────────
   Full-width top bar with the sidebar / channel / rail grid beneath it,
   matching the approved mockup. The shell is pinned to the viewport and each
   column scrolls on its own, so the page itself never scrolls and the columns
   cannot drift out of alignment. */
.tw-shell{display:grid;grid-template-columns:250px minmax(0,1fr) 320px;grid-template-rows:auto minmax(0,1fr);height:100vh;height:100dvh;overflow:hidden}

/* Top bar spans the full width with its own copy of the shell's column
   template, so the search field sits over the centre column and the account
   block over the rail, exactly as the mockup does. */
.tw-top{grid-column:1 / -1;grid-row:1;display:grid;grid-template-columns:250px minmax(0,1fr) 320px;align-items:center;height:var(--ly-topbar-h);padding:0 22px 0 18px;border-bottom:1px solid var(--ly-border);background:rgba(2,9,26,.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);position:relative;z-index:60}
.tw-brand{min-width:0;overflow:hidden}
.tw-search{grid-column:2;min-width:0;padding-right:22px}
.tw-user{grid-column:3;display:flex;align-items:center;gap:10px;justify-content:flex-end;min-width:0}

.tw-nav{grid-column:1;grid-row:2}
.tw-center{grid-column:2;grid-row:2;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden}
.tw-rail{grid-column:3;grid-row:2}

/* Sidebar and rail scroll internally. The sidebar keeps its own scroll region
   for the channel list so the promo card can stay pinned at the bottom.
   min-height must be reset here: the shared .ly-sidebar rule sets
   min-height:100vh, which inside a fixed-height shell makes the column taller
   than its grid row and pushes the promo card off-screen. */
.tw-nav{display:flex;flex-direction:column;overflow:hidden}
.tw-shell .ly-sidebar{min-height:0}
.tw-navscroll{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain}
.tw-rail{display:flex;flex-direction:column;gap:0;overflow-y:auto;overscroll-behavior:contain;padding:20px;border-left:1px solid var(--ly-border)}

/* Center column: hero + tiles are fixed height, the channel fills the rest
   and scrolls, the composer sits below it and never moves. */
/* The h1/p sizes and the 14px gaps were inline styles, so no media query
   could adjust them and the hero could not respond to a short viewport.
   They are classes here so the max-height block below can reach them. */
.tw-hero{background:linear-gradient(120deg,rgba(80,40,224,.55),rgba(155,92,255,.28) 55%,rgba(2,9,26,0) 100%),var(--ly-surface);border:1px solid var(--ly-primary-line);border-radius:var(--ly-r-xl);padding:18px 22px;margin-bottom:14px}
.tw-hero-h1{font-size:22px;margin:0 0 5px;color:#fff;letter-spacing:-.02em;line-height:1.2}
.tw-hero-p{font-size:13px;color:rgba(255,255,255,.78);margin:0}
.tw-pad{padding:18px 22px 0}
.tw-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.tw-tile{padding:13px;border:1px solid var(--ly-border);border-radius:var(--ly-r-md);background:var(--ly-glass);min-width:0}
.tw-tile b{display:block;font-size:20px;font-weight:800;letter-spacing:-.03em;line-height:1.2}
.tw-tile span{display:block;font-size:11px;color:var(--ly-text-4);overflow-wrap:anywhere;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.tw-chanhead{display:flex;align-items:center;gap:10px;padding:13px 22px;border-bottom:1px solid var(--ly-border);flex:0 0 auto}
.tw-chanhead h2{font-size:14px;font-weight:700;margin:0;letter-spacing:-.01em}

/* The message list is the scroll region for the channel. */
.tw-feed{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:6px 22px 4px}
.tw-post{display:flex;gap:11px;padding:13px 0;border-bottom:1px solid var(--ly-border)}
.tw-post:last-child{border-bottom:0}
.tw-post .who{font-size:12.5px;font-weight:600;display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}
.tw-post .when{font-size:11px;color:var(--ly-text-4);font-weight:400}
.tw-post .body{font-size:13px;color:var(--ly-text-2);margin-top:3px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
.tw-post .meta{font-size:11.5px;color:var(--ly-text-4);margin-top:3px;font-style:italic}
.tw-post .attach{display:inline-flex;align-items:center;gap:8px;margin-top:7px;padding:9px 12px;border:1px solid var(--ly-border);border-radius:var(--ly-r-md);background:var(--ly-glass);font-size:12px;color:var(--ly-text-3)}

/* Composer: fixed to the bottom of the center column. */
.tw-compose{flex:0 0 auto;border-top:1px solid var(--ly-border);padding:12px 22px 16px;background:var(--ly-surface)}
.tw-composebox{display:flex;align-items:flex-end;gap:10px;border:1px solid var(--ly-border-2);border-radius:var(--ly-r-md);background:var(--ly-glass);padding:8px 10px}
.tw-composebox:focus-within{border-color:var(--ly-primary);box-shadow:0 0 0 3px var(--ly-primary-soft)}
.tw-composebox textarea{flex:1 1 auto;min-width:0;background:transparent;border:0;outline:none;resize:none;color:var(--ly-text);font-family:inherit;font-size:13px;line-height:1.55;max-height:140px}
.tw-composebox textarea::placeholder{color:var(--ly-text-4)}
.tw-icobtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;flex:0 0 auto;border:0;border-radius:var(--ly-r-sm);background:transparent;color:var(--ly-text-4);cursor:not-allowed}
.tw-send{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;flex:0 0 auto;border:0;border-radius:var(--ly-r-sm);background:var(--ly-grad);color:#fff;opacity:.5;cursor:not-allowed}

.tw-chan{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:var(--ly-r-md);font-size:12.5px;color:var(--ly-text-2);min-width:0}
.tw-chan:hover{background:var(--ly-glass);color:var(--ly-text)}
.tw-chan.is-active{background:linear-gradient(90deg,rgba(108,58,248,.22),rgba(108,58,248,.05));color:var(--ly-text);box-shadow:inset 2px 0 0 var(--ly-primary)}
.tw-chan .n{margin-left:auto;font-size:11px;color:var(--ly-text-4);flex:0 0 auto}
.tw-member{display:flex;align-items:center;gap:9px;padding:7px 0;font-size:12.5px;min-width:0}
.tw-member .r{font-size:11px;color:var(--ly-text-4)}

@media (max-width:1240px){
  .tw-shell{grid-template-columns:230px minmax(0,1fr)}
  .tw-top{grid-template-columns:230px minmax(0,1fr) auto}
  .tw-rail{display:none}
}
/* Below ~1100px the centre column is ~280px once the 230px sidebar and 320px
   rail are subtracted, so four stat tiles no longer fit at a readable size.
   Two columns keeps the labels legible. */
@media (max-width:1100px){
  .tw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:820px){
  /* Single column. The sidebar and rail are removed rather than restacked,
     because two grid items sharing row 2 / column 1 would overlap. Channel
     switching on small screens needs a drawer, which is not built yet - the
     nav links are placeholders, so hiding them loses no function. */
  .tw-shell{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr)}
  .tw-nav,.tw-rail{display:none}
  .tw-top{grid-template-columns:minmax(0,1fr) auto;padding:0 14px;height:56px}
  .tw-brand{display:none}
  .tw-search{grid-column:1;padding-right:12px}
  .tw-user{grid-column:2}
  .tw-user > div{display:none}
  .tw-center{grid-column:1;grid-row:2}
  .tw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
  /* Wrap rather than ellipsise: a tile is self-contained, so a second line
     costs nothing, while "Messages in #gene…" is not readable. */
  .tw-tile span{font-size:10.5px;white-space:normal;overflow:visible;text-overflow:clip}
  .tw-pad{padding:14px 14px 0}
  .tw-chanhead{padding:11px 14px}
  .tw-feed{padding:4px 14px}
  .tw-compose{padding:10px 14px 14px}
  .tw-hero{padding:16px}
  .tw-hero h1{font-size:19px}
}
@media (max-width:420px){
  .tw-tiles{grid-template-columns:minmax(0,1fr)}
}

/* ── SHORT VIEWPORTS ────────────────────────────────────────────────────
   The centre column is a fixed-height stack: hero and tiles are fixed, the
   channel list takes the remainder, and the composer is pinned below it. At
   1366x768 the fixed parts consumed 439px of the 704px column - hero 205,
   channel head 53, tabs 39, composer 142 - leaving the message list only
   265px, so the newest post was clipped against the tab row and the column
   felt cramped. These rules compact the fixed chrome so the message list
   keeps the majority of the column, as the approved design shows.
   Scoped to min-width:821px so it cannot fight the small-screen rules above,
   which own the side padding at those widths. */
@media (max-height:860px) and (min-width:821px){
  .tw-pad{padding-top:10px}
  .tw-hero{padding:13px 18px;margin-bottom:10px}
  .tw-hero-h1{font-size:19px;margin-bottom:3px}
  .tw-hero-p{font-size:12.5px}
  .tw-tiles{gap:10px;margin-bottom:10px}
  .tw-tile{padding:10px}
  .tw-tile b{font-size:17px}
  .tw-chanhead{padding-top:9px;padding-bottom:9px}
  .lyra-tw-tab{padding-top:9px;padding-bottom:9px}
  .lyra-tw-chips{margin-bottom:4px}
  .tw-feed{padding-top:4px;padding-bottom:2px}
  .tw-compose{padding-top:8px;padding-bottom:10px}
}
</style>
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body class="ly">
<div class="tw-shell">

<!-- ══ TOP BAR (full width, above all three columns) ══ -->
<header class="tw-top">
    <a class="tw-brand ly-logo" href="/">
        <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
        <span style="font-size:16px">Lyralink</span>
    </a>

    <div class="tw-search">
        <div class="ly-input-icon">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input class="ly-input" placeholder="Search anything&hellip; (messages, files, people, projects)" disabled
                   title="Global search is not wired to an endpoint yet" style="padding-top:9px;padding-bottom:9px">
        </div>
    </div>

    <div class="tw-user">
        <span class="ly-avatar ly-avatar-sm"><?php echo htmlspecialchars($viewerInitials, ENT_QUOTES, 'UTF-8'); ?></span>
        <div style="min-width:0">
            <div style="font-size:12.5px;font-weight:600" class="ly-truncate"><?php echo htmlspecialchars($viewerLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="font-size:11px;color:var(--ly-text-4)"><?php echo htmlspecialchars($viewerStatus, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>
</header>

<!-- ══ SIDEBAR ══ -->
<aside class="ly-sidebar tw-nav">
    <div class="tw-navscroll">
        <div class="ly-sidebar-section" style="padding-top:0">Interface</div>
        <?php echo lyra_ui_nav_render('/pages/teams/'); ?>

        <div class="ly-sidebar-section">Workspace</div>
        <?php foreach ([
            ['Messages','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z','/chat'],
            ['People',  'M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8',''],
            ['Files',   'M6 3h8l4 4v14H6z',''],
            ['Search',  'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3',''],
        ] as $n):
            if ($n[2] === '') { echo lyra_ui_pending($n[0], $n[1]); continue; } ?>
        <a class="ly-navitem" href="<?php echo htmlspecialchars($n[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-sidebar-section">Channels</div>
        <?php if ($channels): foreach ($channels as $i => $ch):
            $nm = pk($ch, ['name'], 'channel');
            $cc = $chanCounts[(int) ($ch['id'] ?? 0)] ?? 0;
            $isVoice = pk($ch, ['type']) === 'voice'; ?>
        <a class="tw-chan<?php echo ((int) ($ch['id'] ?? 0) === (int) ($active['id'] ?? -1)) ? ' is-active' : ''; ?>"
           href="?channel=<?php echo (int) ($ch['id'] ?? 0); ?>"
           title="#<?php echo htmlspecialchars($nm); ?>">
            <span style="color:var(--ly-text-4);flex:0 0 auto"><?php echo $isVoice ? '&#128266;' : '#'; ?></span>
            <span class="ly-truncate"><?php echo htmlspecialchars($nm); ?></span>
            <span class="n"><?php echo $cc > 0 ? (int) $cc : ''; ?></span>
        </a>
        <?php endforeach; else: ?>
        <div style="font-size:11.5px;color:var(--ly-text-4);padding:10px">No channels yet.</div>
        <?php endif; ?>
    </div>

    <div class="ly-promo" style="flex:0 0 auto;margin-top:var(--ly-s3)">
        <div style="font-weight:700;font-size:13px;margin-bottom:5px">Smarter Collaboration</div>
        <p style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:10px">Bring your team together with AI assistance and seamless communication.</p>
        <a class="ly-btn ly-btn-primary ly-btn-sm ly-btn-block" href="/pages/social.php">Open workspace</a>
    </div>
</aside>

<!-- ══ CENTER ══ -->
<main class="tw-center">

    <div class="tw-pad">
        <div class="tw-hero">
            <h1 class="tw-hero-h1"><?php echo htmlspecialchars($greetingWord, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($viewerLabel, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="tw-hero-p">Here&rsquo;s what&rsquo;s happening across your workspace today.</p>
        </div>

        <div class="tw-tiles">
            <div class="tw-tile"><b data-ly-count="<?php echo $nCh; ?>"><?php echo $nCh; ?></b><span>Channels</span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMsg; ?>"><?php echo $nMsg; ?></b><span title="Messages in #<?php echo htmlspecialchars($activeName !== '' ? $activeName : '—'); ?>">Messages in #<?php echo htmlspecialchars($activeName !== '' ? $activeName : '—'); ?></span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMem; ?>"><?php echo $nMem; ?></b><span>Members</span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nOnline; ?>"><?php echo $nOnline; ?></b><span>Online now</span></div>
        </div>
    </div>

    <div class="tw-chanhead">
        <span style="color:var(--ly-text-4);flex:0 0 auto">#</span>
        <h2 class="ly-truncate"><?php echo $active ? htmlspecialchars($activeName) : 'No channel selected'; ?></h2>
        <?php if ($active && pk($active, ['topic']) !== ''): ?>
        <span class="ly-badge ly-truncate"><?php echo htmlspecialchars(pk($active, ['topic'])); ?></span>
        <?php endif; ?>
        <span class="ly-spacer"></span>
        <span class="ly-badge"><?php echo $nMsg; ?></span>
    </div>

    <!-- Scroll region: the full channel, newest at the bottom -->
    <?php echo lyra_tw_feed_head(); ?>
    <div class="tw-feed" id="tw-feed" tabindex="0" role="log" aria-label="Channel messages">
        <?php if ($messages): foreach ($messages as $msg):
            $uid    = (int) pk($msg, ['sender_user_id','user_id','author_id'], '0');
            $author = tw_author($uid, $users);
            $body   = pk($msg, ['content','message','body']);
            $at     = pk($msg, ['created_at']);
            $type   = pk($msg, ['message_type'], 'text'); ?>
        <div class="tw-post">
            <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad);flex:0 0 auto"><?php echo htmlspecialchars(inits($author)); ?></span>
            <div style="min-width:0;flex:1 1 auto">
                <div class="who">
                    <span class="ly-truncate" title="<?php echo htmlspecialchars($author); ?>"><?php echo htmlspecialchars($author); ?></span>
                    <?php if ($at): ?><span class="when" title="<?php echo htmlspecialchars($at); ?>"><?php echo htmlspecialchars(tw_when($at)); ?></span><?php endif; ?>
                </div>
                <?php if ($type === 'attachment'): ?>
                    <div class="attach">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M6 3h8l4 4v14H6z"/></svg>
                        Attachment<?php echo $body !== '' ? ' &mdash; ' . htmlspecialchars($body) : ' (no filename recorded)'; ?>
                    </div>
                <?php elseif ($type === 'system'): ?>
                    <div class="meta"><?php echo $body !== '' ? htmlspecialchars($body) : 'System event (no text recorded).'; ?></div>
                <?php elseif ($body === ''): ?>
                    <div class="body" style="color:var(--ly-text-4)"><em>Empty message.</em></div>
                <?php else: ?>
                    <div class="body"><?php echo htmlspecialchars($body); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div style="font-size:12.5px;color:var(--ly-text-4);padding:16px 0">
            No messages in this channel yet.<?php echo $dbError !== null ? ' Database unavailable.' : ''; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="tw-compose">
        <?php echo lyra_tw_compose_chips(); ?>
        <div class="tw-composebox">
            <button class="tw-icobtn" type="button" disabled title="Not wired up yet" aria-label="Add attachment">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <textarea rows="1" placeholder="Type a message&hellip;" disabled
                      title="Posting is not wired to the API yet" aria-label="Message"></textarea>
            <span class="tw-send" title="Posting is not wired to the API yet" aria-hidden="true">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
        </div>
        <div style="font-size:11px;color:var(--ly-text-4);margin-top:7px">
            Composing is not wired to the API yet, so this field is disabled rather than appearing to send.
        </div>
    </div>
</main>

<!-- ══ RAIL ══ -->
<aside class="ly-rail tw-rail">
    <?php echo lyra_tw_team_panel(); ?>
    <?php echo lyra_tw_channels_panel(); ?>
    <?php echo lyra_tw_status_panel(); ?>
    <?php echo lyra_tw_activity_panel(); ?>

    <div class="ly-promo" style="flex:0 0 auto">
        <div style="font-weight:700;font-size:13px;margin-bottom:6px">Powered by Lyralink</div>
        <div style="font-size:11.5px;color:var(--ly-text-3);line-height:1.65">
            This workspace reads the live social_* tables. Member presence is derived from
            <span class="ly-mono" style="font-size:10.5px">last_seen_at</span>, and service health
            from the same source as the public status page. Upcoming meetings, tasks and files
            have no table in this schema yet, so those panels are not shown.
        </div>
    </div>
</aside>
</div>

<script>
/* Keep the newest message in view. The channel is a scroll region, so the
   browser will not do this on its own. */
(function () {
    var feed = document.getElementById('tw-feed');
    if (feed) { feed.scrollTop = feed.scrollHeight; }
})();
</script>
</body>
</html>
