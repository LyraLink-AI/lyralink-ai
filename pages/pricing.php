<?php
session_start();
require_once __DIR__ . '/../api/security.php';
$paypalClientId = htmlspecialchars(api_get_secret('PAYPAL_CLIENT_ID', ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Pricing</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
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
        }

        /* ── MOBILE ── */
        @media (max-width: 640px) {
            nav { padding: 12px 16px; gap: 8px; }
            nav img { height: 24px; }
            .nav-back { font-size: 11px; padding: 4px 10px; }
            .hero { padding: 36px 16px 28px; }
            .hero h2 { font-size: 26px; }
            .hero p { font-size: 13px; }
            .status-banner { margin: 0 16px 28px; padding: 12px 14px; }
            .plans { grid-template-columns: 1fr; padding: 0 16px 48px; gap: 14px; }
            .plan-card { padding: 22px 18px; }
            .credits-section { padding: 0 16px 48px; }
            .credits-grid { grid-template-columns: 1fr 1fr !important; }
            .faq-section { padding: 0 16px 60px; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>

<nav>
    <div class="nav-logo">⚡</div>
    <h1>Lyra<span>link</span></h1>
    <a href="/chat" class="nav-back">← Back to Chat</a>
</nav>

<div class="hero">
    <h2>Simple, <span>transparent</span> pricing</h2>
    <p>Pick the plan that works for you. Upgrade or cancel anytime. Top up with credits whenever you need more.</p>
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
<div class="plans">

    <!-- FREE -->
    <div class="plan-card" id="card-free">
        <div class="plan-name">Free</div>
        <div class="plan-price"><span class="amount">$0</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> 1,500 messages/month</li>
            <li><span class="check">✓</span> Lyralink AI chat</li>
            <li><span class="check">✓</span> Multiple conversations</li>
            <li><span class="check">✓</span> Moltbook feed</li>
        </ul>
        <div id="free-action"><div class="current-plan-label" id="free-current" style="display:none">✓ Your current plan</div></div>
    </div>

    <!-- BASIC -->
    <div class="plan-card" id="card-basic">
        <div class="plan-name">Basic</div>
        <div class="plan-price"><span class="amount">$5</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> 2,500 messages/month</li>
            <li><span class="check">✓</span> Everything in Free</li>
            <li><span class="check">✓</span> Priority support</li>
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
            <li class="highlight"><span class="check">✓</span> Unlimited messages</li>
            <li><span class="check">✓</span> Everything in Basic</li>
            <li><span class="check">✓</span> No monthly limits ever</li>
        </ul>
        <div id="pro-action">
            <button class="plan-btn primary" onclick="subscribePlan('pro')">Get Pro</button>
        </div>
    </div>

    <!-- ENTERPRISE -->
    <div class="plan-card enterprise-card" id="card-enterprise">
        <div class="plan-name">Enterprise</div>
        <div class="plan-price"><span class="amount">$30</span><span class="period">/mo</span></div>
        <ul class="plan-features">
            <li class="highlight"><span class="check">✓</span> Unlimited messages</li>
            <li><span class="check">✓</span> LLaMA 70B — smarter model</li>
            <li><span class="check">✓</span> Everything in Pro</li>
            <li><span class="check">✓</span> Priority processing</li>
        </ul>
        <div id="enterprise-action">
            <button class="plan-btn enterprise-btn" onclick="subscribePlan('enterprise')">Get Enterprise</button>
        </div>
    </div>

</div>

<!-- CREDITS -->
<div class="credits-section">
    <h3>Need more messages?</h3>
    <p>Top up with credits — each credit = 1 message, never expires.</p>
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

    // ── LOAD STATUS ──
    async function loadStatus() {
        try {
            const fd = new FormData(); fd.append('action', 'status');
            const data = await (await fetch('/api/billing.php', {method:'POST', body:fd})).json();

            if (data.logged_in) {
                currentPlan    = data.plan;
                currentCredits = data.credits;

                document.getElementById('statusBanner').style.display = 'flex';
                document.getElementById('statusPlanName').textContent  = data.plan_name;
                document.getElementById('statusBadge').textContent     = data.plan_name;
                document.getElementById('statusBadge').className       = 'plan-badge ' + data.plan;

                const used  = data.messages_used;
                const limit = data.messages_limit;
                document.getElementById('statusUsage').textContent = data.unlimited
                    ? `Credits: ${data.credits} · Unlimited messages`
                    : `${used} / ${limit} messages used · ${data.credits} credits`;

                updatePlanButtons(data.plan);
            }
        } catch(e) {}
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
</html>