<?php
if (file_exists(__DIR__ . '/../../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
$success   = isset($_GET['success']);
$cancelled = isset($_GET['cancelled']);
$tierParam = htmlspecialchars($_GET['tier'] ?? '');
$paypalClientId = htmlspecialchars(getenv('PAYPAL_CLIENT_ID') ?: ($_ENV['PAYPAL_CLIENT_ID'] ?? ''), ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deploy Your AI — Lyralink Hosting</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <meta name="description" content="Deploy your own AI-powered website on Lyralink's infrastructure. Choose a plan and go live in minutes.">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <?php if ($paypalClientId !== ''): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo $paypalClientId; ?>&vault=true&intent=subscription" data-sdk-integration-source="button-factory"></script>
    <?php endif; ?>
    <style>
        :root {
            --bg:#0a0a0f; --surface:#111118; --surface2:#16161f; --border:#1e1e2e;
            --accent:#7c3aed; --accent-glow:rgba(124,58,237,0.25); --accent-light:#a78bfa;
            --text:#e2e8f0; --text-muted:#64748b; --text-dim:#94a3b8;
            --success:#22c55e; --success-bg:rgba(34,197,94,0.1); --success-border:rgba(34,197,94,0.25);
            --error:#ef4444; --warn:#f59e0b;
            --orange:#f97316; --orange-bg:rgba(249,115,22,0.1); --orange-border:rgba(249,115,22,0.3);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

        /* BG effects */
        body::before{content:'';position:fixed;top:-200px;left:15%;width:700px;height:500px;background:radial-gradient(ellipse,rgba(124,58,237,0.07) 0%,transparent 65%);pointer-events:none;z-index:0}
        body::after{content:'';position:fixed;bottom:-100px;right:-50px;width:400px;height:300px;background:radial-gradient(ellipse,rgba(249,115,22,0.04) 0%,transparent 65%);pointer-events:none;z-index:0}

        /* NAV */
        nav{position:sticky;top:0;z-index:100;padding:14px 32px;display:flex;align-items:center;gap:14px;background:rgba(10,10,15,0.92);backdrop-filter:blur(16px);border-bottom:1px solid rgba(30,30,46,0.7)}
        .nav-logo{height:28px;width:auto;mix-blend-mode:lighten}
        .nav-sep{color:var(--border);font-size:16px}
        .nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text-muted)}
        .nav-right{margin-left:auto;display:flex;align-items:center;gap:8px}
        .nav-link{font-size:12px;color:var(--text-muted);text-decoration:none;padding:5px 12px;border:1px solid var(--border);border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

        /* LAYOUT */
        .page{max-width:1000px;margin:0 auto;padding:60px 24px 100px;position:relative;z-index:1}

        /* HERO */
        .hero{text-align:center;margin-bottom:60px}
        .hero-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(124,58,237,0.3);border-radius:4px;padding:4px 14px;font-size:10px;color:var(--accent-light);letter-spacing:2px;text-transform:uppercase;margin-bottom:20px;background:rgba(124,58,237,0.06)}
        .hero-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 2s infinite}
        @keyframes blink{0%,100%{opacity:1}50%{opacity:0.3}}
        .hero h1{font-family:'Syne',sans-serif;font-size:clamp(32px,5vw,56px);font-weight:800;line-height:1.1;letter-spacing:-1.5px;margin-bottom:16px}
        .hero h1 em{font-style:normal;color:var(--accent-light)}
        .hero-sub{font-size:15px;color:var(--text-muted);max-width:520px;margin:0 auto;line-height:1.7}

        /* STEP INDICATOR */
        .steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:48px}
        .step{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text-muted)}
        .step.active{color:var(--text)}
        .step.done{color:var(--success)}
        .step-num{width:24px;height:24px;border-radius:50%;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
        .step.active .step-num{background:var(--accent);border-color:var(--accent);color:white}
        .step.done .step-num{background:var(--success-bg);border-color:var(--success);color:var(--success)}
        .step-line{width:40px;height:1px;background:var(--border);margin:0 6px}

        /* PLANS */
        .plans-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:48px}
        .plan-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:28px;display:flex;flex-direction:column;gap:18px;position:relative;transition:all 0.25s;cursor:pointer}
        .plan-card:hover{border-color:rgba(124,58,237,0.4);transform:translateY(-3px);box-shadow:0 12px 40px rgba(0,0,0,0.3)}
        .plan-card.selected{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent),0 12px 40px rgba(124,58,237,0.2)}
        .plan-card.popular{border-color:rgba(124,58,237,0.5);box-shadow:0 0 30px rgba(124,58,237,0.12)}
        .plan-card.popular-orange{border-color:var(--orange-border);box-shadow:0 0 30px rgba(249,115,22,0.08)}
        .popular-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--accent);color:white;font-size:10px;font-weight:700;padding:3px 14px;border-radius:20px;white-space:nowrap;font-family:'Syne',sans-serif;letter-spacing:.5px}
        .plan-header{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
        .plan-name{font-family:'Syne',sans-serif;font-size:20px;font-weight:800}
        .plan-check{width:22px;height:22px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s}
        .plan-card.selected .plan-check{background:var(--accent);border-color:var(--accent);color:white;font-size:12px}
        .plan-price{display:flex;align-items:baseline;gap:3px}
        .plan-price .amount{font-family:'Syne',sans-serif;font-size:38px;font-weight:800;line-height:1}
        .plan-price .currency{font-size:18px;color:var(--text-muted);align-self:flex-start;margin-top:6px}
        .plan-price .period{font-size:12px;color:var(--text-muted)}
        .plan-specs{display:flex;flex-direction:column;gap:8px}
        .spec-row{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text-dim)}
        .spec-icon{font-size:14px;flex-shrink:0;width:20px;text-align:center}
        .spec-val{color:var(--text);font-weight:600}
        .plan-divider{height:1px;background:var(--border)}
        .plan-features{display:flex;flex-direction:column;gap:8px}
        .feat{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-muted)}
        .feat .ck{color:var(--success);font-size:13px;flex-shrink:0}

        /* DEPLOY AREA */
        .deploy-area{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:32px;margin-bottom:24px}
        .deploy-area h3{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin-bottom:6px}
        .deploy-area p{font-size:13px;color:var(--text-muted);margin-bottom:20px}
        #paypalButtonContainer{min-height:50px}
        .no-plan-msg{text-align:center;color:var(--text-muted);font-size:13px;padding:20px;border:1px dashed var(--border);border-radius:12px}

        /* ACTIVE DEPLOYMENT CARD */
        .deployment-card{background:var(--surface);border:1px solid var(--success-border);border-radius:18px;padding:28px;margin-bottom:32px}
        .deployment-card-header{display:flex;align-items:center;gap:12px;margin-bottom:20px}
        .dep-status-dot{width:10px;height:10px;border-radius:50%;background:var(--success);box-shadow:0 0 8px rgba(34,197,94,0.5);animation:blink 2s infinite;flex-shrink:0}
        .dep-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800}
        .dep-badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;background:var(--success-bg);color:var(--success);border:1px solid var(--success-border);margin-left:auto;text-transform:uppercase}
        .dep-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px}
        .dep-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
        .dep-item-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .dep-item-val{font-size:13px;font-weight:600;word-break:break-all}
        .dep-actions{display:flex;gap:10px;flex-wrap:wrap}
        .btn{padding:10px 20px;border-radius:10px;font-family:'DM Mono',monospace;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
        .btn-primary{background:var(--accent);color:white;box-shadow:0 0 16px var(--accent-glow)}
        .btn-primary:hover{background:#6d28d9}
        .btn-outline{background:none;color:var(--text-muted);border:1px solid var(--border)}
        .btn-outline:hover{border-color:var(--accent);color:var(--accent-light)}
        .btn-danger{background:none;color:var(--error);border:1px solid rgba(239,68,68,0.3)}
        .btn-danger:hover{background:rgba(239,68,68,0.1)}

        /* LOGIN WALL */
        .login-wall{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:48px;text-align:center;margin-bottom:32px}
        .login-wall h3{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-bottom:8px}
        .login-wall p{color:var(--text-muted);font-size:13px;margin-bottom:24px}

        /* ALERT BANNERS */
        .alert{border-radius:12px;padding:14px 18px;font-size:13px;margin-bottom:24px;display:flex;align-items:flex-start;gap:10px}
        .alert-success{background:var(--success-bg);border:1px solid var(--success-border);color:var(--success)}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--error)}
        .alert-info{background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.25);color:var(--accent-light)}

        /* HOW IT WORKS */
        .how-section{margin-top:64px;padding-top:48px;border-top:1px solid var(--border)}
        .how-section h2{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:32px;text-align:center}
        .how-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
        .how-step{text-align:center;padding:24px 16px}
        .how-num{width:40px;height:40px;border-radius:12px;background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.25);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:16px;color:var(--accent-light);margin:0 auto 14px}
        .how-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:6px}
        .how-desc{font-size:12px;color:var(--text-muted);line-height:1.6}

        /* TOAST */
        .toast{position:fixed;bottom:24px;right:24px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 18px;font-size:12px;z-index:999;transform:translateY(80px);opacity:0;transition:all 0.3s;max-width:300px}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{border-color:var(--success-border);color:var(--success)}
        .toast.error{border-color:rgba(239,68,68,0.3);color:var(--error)}

        /* SPINNER */
        .spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:white;border-radius:50%;animation:spin 0.7s linear infinite;flex-shrink:0}
        @keyframes spin{to{transform:rotate(360deg)}}

        /* MOBILE */
        @media(max-width:760px){
            nav{padding:10px 16px;gap:8px}
            .nav-logo{height:24px}
            .nav-title{display:none}
            .page{padding:32px 14px 80px}
            .hero{margin-bottom:36px}
            .hero h1{font-size:28px}
            .hero-sub{font-size:13px}
            .steps{gap:0;flex-wrap:nowrap}
            .step-label{display:none}
            .step-line{width:24px}
            .plans-grid{grid-template-columns:1fr;gap:14px}
            .plan-card{padding:20px}
            .popular-badge{font-size:9px}
            .deploy-area{padding:20px}
            .dep-grid{grid-template-columns:1fr}
            .how-grid{grid-template-columns:1fr 1fr;gap:14px}
            .dep-actions{flex-direction:column}
            .btn{justify-content:center}
        }
        @media(max-width:400px){
            .how-grid{grid-template-columns:1fr}
        }
        /* DEV PANEL */
        .dev-bar{position:fixed;bottom:0;left:0;right:0;z-index:999;background:#0d0d14;border-top:1px solid #7c3aed;padding:8px 16px;display:none;align-items:center;gap:12px;font-size:11px;font-family:'DM Mono',monospace}
        .dev-bar.visible{display:flex}
        .dev-badge{background:rgba(124,58,237,0.2);color:#a78bfa;border:1px solid #7c3aed;border-radius:4px;padding:2px 8px;font-weight:700;letter-spacing:.5px;flex-shrink:0}
        .dev-status{color:#64748b;flex:1}
        .dev-status span{color:#e2e8f0}
        .dev-btn{background:none;border:1px solid #1e1e2e;color:#64748b;border-radius:6px;padding:4px 10px;font-size:10px;cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.2s}
        .dev-btn:hover{border-color:#7c3aed;color:#a78bfa}
        .dev-btn.active{border-color:#22c55e;color:#22c55e}
        .dev-btn.danger{border-color:rgba(239,68,68,0.4);color:#ef4444}
        .dev-btn.danger:hover{background:rgba(239,68,68,0.1)}
    </style>
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-sep">/</span>
    <span class="nav-title">AI Hosting</span>
    <div class="nav-right">
        <a href="/" class="nav-link">← Back</a>
        <a href="/pages/pricing/" class="nav-link">Plans</a>
    </div>
</nav>

<div class="page">

    <!-- HERO -->
    <div class="hero">
        <div class="hero-tag"><span class="hero-dot"></span> Powered by Pelican Panel</div>
        <h1>Deploy Your Own<br><em>AI Website</em></h1>
        <p class="hero-sub">Get a fully managed AI-powered web server up and running in minutes. Your instance, your code, your domain.</p>
    </div>

    <!-- ALERTS -->
    <?php if ($cancelled): ?>
    <div class="alert alert-info">⚠️ Payment was cancelled. No charges were made. Select a plan below to try again.</div>
    <?php endif; ?>
    <div id="alertArea"></div>

    <!-- STEP INDICATOR -->
    <div class="steps">
        <div class="step done" id="step1El">
            <div class="step-num" id="step1Num">1</div>
            <span class="step-label">Choose Plan</span>
        </div>
        <div class="step-line"></div>
        <div class="step" id="step2El">
            <div class="step-num">2</div>
            <span class="step-label">Pay</span>
        </div>
        <div class="step-line"></div>
        <div class="step" id="step3El">
            <div class="step-num">3</div>
            <span class="step-label">Deployed</span>
        </div>
    </div>

    <!-- ACTIVE DEPLOYMENT (shown if user already has one) -->
    <div id="activeDeployment" style="display:none"></div>

    <!-- LOGIN WALL (shown if not logged in) -->
    <div id="loginWall" style="display:none" class="login-wall">
        <div style="font-size:36px;margin-bottom:16px">🔒</div>
        <h3>Sign In to Deploy</h3>
        <p>You need a Lyralink account to deploy an AI server.<br>It's free to create one.</p>
        <a href="/pages/deploy/login.php" class="btn btn-primary">Sign In / Register →</a>
    </div>

    <!-- PLAN PICKER -->
    <div id="plansSection">
        <div class="plans-grid" id="plansGrid">
            <!-- Populated by JS -->
        </div>

        <!-- DEPLOY AREA -->
        <div class="deploy-area" id="deployArea">
            <h3>Complete Your Deployment</h3>
            <p>Select a plan above, then confirm payment to provision your server instantly.</p>
            <div class="no-plan-msg" id="noPlanMsg">👆 Select a plan to continue</div>
            <div id="paypalButtonContainer" style="display:none"></div>
            <div id="confirmingSpinner" style="display:none;text-align:center;padding:20px;color:var(--text-muted);font-size:13px">
                <div class="spinner" style="margin:0 auto 10px;width:24px;height:24px;border-top-color:var(--accent-light)"></div>
                Confirming payment and provisioning your server…
            </div>
        </div>
    </div>

    <!-- HOW IT WORKS -->
    <div class="how-section">
        <h2>How It Works</h2>
        <div class="how-grid">
            <div class="how-step">
                <div class="how-num">1</div>
                <div class="how-title">Pick a Plan</div>
                <div class="how-desc">Choose the RAM, CPU, and storage that fits your project.</div>
            </div>
            <div class="how-step">
                <div class="how-num">2</div>
                <div class="how-title">Pay Monthly</div>
                <div class="how-desc">Secure PayPal subscription. Cancel anytime with one click.</div>
            </div>
            <div class="how-step">
                <div class="how-num">3</div>
                <div class="how-title">Auto-Provisioned</div>
                <div class="how-desc">Your server is created on our Pelican node within seconds of payment.</div>
            </div>
            <div class="how-step">
                <div class="how-num">4</div>
                <div class="how-title">You're Live</div>
                <div class="how-desc">Log into the panel, upload your AI app, and go live on your subdomain.</div>
            </div>
        </div>
    </div>

</div>

<div class="toast" id="toast"></div>

<script>
const API = '/api/pelican.php';
let selectedTier = null;
let paypalRendered = false;
let currentUser   = null;
let tiers         = {};

// ── INIT ──
async function init() {
    await Promise.all([loadTiers(), checkAuth()]);
    <?php if ($success && $tierParam): ?>
    // Return from PayPal approval — confirm the subscription
    await handlePaypalReturn('<?= $tierParam ?>');
    <?php endif; ?>

    // Init dev mode after auth check
    initDevMode();
}

async function loadTiers() {
    const res  = await fetch(API + '?action=get_tiers').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;
    if (!data?.success) return;
    tiers = data.tiers;
    renderPlans();
}

async function checkAuth() {
    const res  = await fetch('/api/auth.php?action=check').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;

    if (!data?.logged_in) {
        document.getElementById('loginWall').style.display    = 'block';
        document.getElementById('plansSection').style.display = 'none';
        return;
    }

    currentUser = { username: data.username, plan: data.plan };

    // Check for existing deployment
    const dr   = await fetch(API + '?action=get_deployment').catch(() => null);
    const ddat = dr ? await dr.json().catch(() => null) : null;
    if (ddat?.deployment && ddat.deployment.status !== 'cancelled') {
        renderActiveDeployment(ddat.deployment);
        document.getElementById('plansSection').style.display = 'none';
    }
}

// ── RENDER PLANS ──
function renderPlans() {
    const tierDefs = [
        { key:'small',  icon:'🟢', color:'#22c55e' },
        { key:'medium', icon:'🟣', color:'#7c3aed' },
        { key:'large',  icon:'🟠', color:'#f97316' },
    ];
    const grid = document.getElementById('plansGrid');
    grid.innerHTML = tierDefs.map(({ key, icon, color }) => {
        const t = tiers[key];
        if (!t) return '';
        const isPopular = t.popular;
        return `<div class="plan-card ${isPopular ? 'popular' : ''}" id="planCard_${key}" onclick="selectPlan('${key}')">
            ${isPopular ? '<div class="popular-badge">⚡ Most Popular</div>' : ''}
            <div class="plan-header">
                <span class="plan-name">${t.name}</span>
                <div class="plan-check" id="planCheck_${key}"></div>
            </div>
            <div class="plan-price">
                <span class="currency">$</span>
                <span class="amount">${t.price}</span>
                <span class="period">/mo</span>
            </div>
            <div class="plan-specs">
                <div class="spec-row"><span class="spec-icon">🧠</span><span class="spec-val">${formatRam(t.ram)}</span> RAM</div>
                <div class="spec-row"><span class="spec-icon">⚡</span><span class="spec-val">${formatCpu(t.cpu)}</span> CPU</div>
                <div class="spec-row"><span class="spec-icon">💾</span><span class="spec-val">${formatDisk(t.disk)}</span> Disk</div>
            </div>
            <div class="plan-divider"></div>
            <div class="plan-features">
                <div class="feat"><span class="ck">✓</span> Pelican panel access</div>
                <div class="feat"><span class="ck">✓</span> Custom egg (ID: 312)</div>
                <div class="feat"><span class="ck">✓</span> Subdomain included</div>
                <div class="feat"><span class="ck">✓</span> ${t.name === 'Large' ? '2' : '1'} database${t.name === 'Large' ? 's' : ''}</div>
                <div class="feat"><span class="ck">✓</span> Cancel anytime</div>
            </div>
        </div>`;
    }).join('');
}

function selectPlan(tier) {
    selectedTier = tier;
    // Update card styles
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.plan-check').forEach(c => { c.textContent = ''; });
    const card = document.getElementById('planCard_' + tier);
    const chk  = document.getElementById('planCheck_' + tier);
    if (card) card.classList.add('selected');
    if (chk)  chk.textContent = '✓';

    // Show PayPal button
    document.getElementById('noPlanMsg').style.display = 'none';
    document.getElementById('paypalButtonContainer').style.display = 'block';
    setStep(2);

    if (!paypalRendered) {
        renderPayPalButton();
        paypalRendered = true;
    }
}

// ── PAYPAL BUTTON ──
function renderPayPalButton() {
    paypal.Buttons({
        style: {
            shape:  'rect',
            color:  'black',
            layout: 'vertical',
            label:  'subscribe',
        },
        createSubscription: async function(data, actions) {
            if (!selectedTier) { showToast('Select a plan first', 'error'); return; }
            // Create subscription via our API
            const fd = new FormData();
            fd.append('action', 'create_subscription');
            fd.append('tier', selectedTier);
            const res  = await fetch(API, { method:'POST', body:fd });
            const json = await res.json();
            if (!json.success) { showToast(json.error || 'Failed to create subscription', 'error'); return; }
            return json.subscription_id;
        },
        onApprove: async function(data, actions) {
            document.getElementById('paypalButtonContainer').style.display  = 'none';
            document.getElementById('confirmingSpinner').style.display = 'block';
            setStep(3);
            await confirmSubscription(data.subscriptionID);
        },
        onError: function(err) {
            showToast('PayPal error. Please try again.', 'error');
            console.error('PayPal error:', err);
        },
        onCancel: function() {
            showToast('Payment cancelled.', 'error');
            setStep(1);
        }
    }).render('#paypalButtonContainer');
}

// ── CONFIRM SUBSCRIPTION ──
async function confirmSubscription(subscriptionId) {
    const fd = new FormData();
    fd.append('action', 'confirm_subscription');
    fd.append('subscription_id', subscriptionId);

    let attempts = 0;
    const maxAttempts = 8;

    const tryConfirm = async () => {
        attempts++;
        const res  = await fetch(API, { method:'POST', body:fd }).catch(() => null);
        const data = res ? await res.json().catch(() => null) : null;

        if (data?.success) {
            document.getElementById('confirmingSpinner').style.display = 'none';
            document.getElementById('plansSection').style.display = 'none';
            renderActiveDeployment(data.deployment, data.credentials);
            setStep(3, true);
            showToast('🎉 Server deployed successfully!', 'success');
            return;
        }

        // PayPal sometimes takes a moment to mark subscription active
        if (attempts < maxAttempts && data?.paypal_status) {
            setTimeout(tryConfirm, 3000);
            return;
        }

        document.getElementById('confirmingSpinner').style.display = 'none';
        showAlert('Your payment was received but server provisioning is still in progress. Refresh this page in a minute.', 'info');
    };

    await tryConfirm();
}

// ── HANDLE PAYPAL RETURN (redirect flow fallback) ──
async function handlePaypalReturn(tier) {
    // Check if subscription was confirmed (user may have been redirected back)
    const dr   = await fetch(API + '?action=get_deployment').catch(() => null);
    const ddat = dr ? await dr.json().catch(() => null) : null;

    if (ddat?.deployment?.status === 'pending') {
        // Try to confirm it
        document.getElementById('plansSection').style.display = 'none';
        document.getElementById('confirmingSpinner').style.display = 'block';
        const fd = new FormData();
        fd.append('action', 'confirm_subscription');
        fd.append('subscription_id', ddat.deployment.paypal_sub_id);
        const res  = await fetch(API, { method:'POST', body:fd }).catch(() => null);
        const data = res ? await res.json().catch(() => null) : null;
        document.getElementById('confirmingSpinner').style.display = 'none';
        if (data?.success) {
            renderActiveDeployment(data.deployment, data.credentials);
            setStep(3, true);
            showToast('🎉 Server deployed!', 'success');
        } else {
            showAlert('Payment received — your server should provision shortly. Refresh in a minute.', 'info');
        }
    } else if (ddat?.deployment?.status === 'active') {
        renderActiveDeployment(ddat.deployment);
        setStep(3, true);
    }
}

// ── RENDER ACTIVE DEPLOYMENT ──
function renderActiveDeployment(dep, creds) {
    const panel = 'https://panel.cloudhavenx.com';
    const tierName = dep.tier ? (dep.tier.charAt(0).toUpperCase() + dep.tier.slice(1)) : dep.tier;
    const statusLabel = dep.status === 'active' ? 'Active' : dep.status;

    let credsHtml = '';
    if (creds) {
        credsHtml = `<div class="alert alert-success" style="margin-bottom:16px">
            🎉 <strong>Server provisioned!</strong> Save your panel credentials below — they won't be shown again.
        </div>
        <div class="dep-grid" style="margin-bottom:16px">
            <div class="dep-item">
                <div class="dep-item-label">Panel Username</div>
                <div class="dep-item-val">${esc(creds.username)}</div>
            </div>
            <div class="dep-item">
                <div class="dep-item-label">Panel Password</div>
                <div class="dep-item-val" id="passVal" style="cursor:pointer" onclick="copyText('${esc(creds.password)}','passVal')">
                    ${esc(creds.password)} <span style="font-size:10px;color:var(--text-muted)">(click to copy)</span>
                </div>
            </div>
        </div>`;
    }

    document.getElementById('activeDeployment').style.display = 'block';
    document.getElementById('activeDeployment').innerHTML = `
        <div class="deployment-card">
            <div class="deployment-card-header">
                <div class="dep-status-dot"></div>
                <div class="dep-title">Your AI Server</div>
                <div class="dep-badge">${statusLabel}</div>
            </div>
            ${credsHtml}
            <div class="dep-grid">
                <div class="dep-item">
                    <div class="dep-item-label">Plan</div>
                    <div class="dep-item-val">${esc(tierName)}</div>
                </div>
                <div class="dep-item">
                    <div class="dep-item-label">Server ID</div>
                    <div class="dep-item-val">${dep.pelican_server_id || '—'}</div>
                </div>
                <div class="dep-item">
                    <div class="dep-item-label">Subdomain</div>
                    <div class="dep-item-val">${dep.subdomain ? dep.subdomain + '.cloudhavenx.com' : 'Pending…'}</div>
                </div>
                <div class="dep-item">
                    <div class="dep-item-label">Next Billing</div>
                    <div class="dep-item-val">${dep.next_billing || '—'}</div>
                </div>
            </div>
            <div class="dep-actions">
                <a href="${panel}" target="_blank" class="btn btn-primary">🖥 Open Panel</a>
                ${dep.subdomain ? `<a href="https://${esc(dep.subdomain)}.cloudhavenx.com" target="_blank" class="btn btn-outline">🌐 View Your Site</a>` : ''}
                <button class="btn btn-danger" onclick="cancelDeployment()">✕ Cancel Subscription</button>
            </div>
        </div>`;
}

// ── CANCEL ──
async function cancelDeployment() {
    if (!confirm('Cancel your subscription? Your server will be suspended at end of billing period.')) return;
    const fd = new FormData();
    fd.append('action', 'cancel');
    const res  = await fetch(API, { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        showToast('Subscription cancelled.', 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(data.error || 'Failed to cancel', 'error');
    }
}

// ── STEPS ──
function setStep(n, done) {
    for (let i = 1; i <= 3; i++) {
        const el  = document.getElementById('step' + i + 'El');
        const num = document.getElementById('step' + i + 'Num');
        if (!el) continue;
        el.classList.remove('active','done');
        if (i < n) el.classList.add('done');
        else if (i === n) el.classList.add(done ? 'done' : 'active');
    }
}

// ── UTILS ──
function formatRam(mb)  { return mb >= 1024 ? (mb/1024) + ' GB' : mb + ' MB'; }
function formatDisk(mb) { return mb >= 1024 ? (mb/1024) + ' GB' : mb + ' MB'; }
function formatCpu(pct) { return (pct/100) + ' vCPU' + (pct >= 200 ? 's' : ''); }
function esc(s)         { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function copyText(text, elId) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied!', 'success'));
}

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3500);
}

function showAlert(msg, type='info') {
    document.getElementById('alertArea').innerHTML =
        `<div class="alert alert-${type}">${esc(msg)}</div>`;
}

// ── DEV MODE ──
// ── DEV MODE ──
function isDevMode() {
    return document.cookie.split(';').some(c => c.trim() === 'lyralink_dev=bypass');
}

function initDevMode() {
    console.log('[DevMode] cookie check:', document.cookie);
    console.log('[DevMode] isDevMode():', isDevMode());
    if (!isDevMode()) return;
    document.getElementById('devBar').classList.add('visible');
    document.getElementById('devCookieStatus').textContent = 'Cookie: lyralink_dev=bypass';
    // Add dev banner to page
    const banner = document.createElement('div');
    banner.style.cssText = 'background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.25);border-radius:10px;padding:10px 16px;font-size:12px;color:#a78bfa;margin-bottom:20px;text-align:center';
    banner.innerHTML = '⚙ <strong>Dev Mode Active</strong> — PayPal is bypassed. Servers will be provisioned on your real Pelican node. Use the bar below to test.';
    document.querySelector('.page').insertBefore(banner, document.querySelector('.hero'));
}

let devPendingSubId = null;

async function devTestDeploy(tier) {
    if (!isDevMode()) return;

    // If there's already a pending sub queued, ask before replacing
    if (devPendingSubId) {
        if (!confirm('You already have a pending dev subscription queued. Replace it with a new ' + tier + ' deployment?')) return;
        devPendingSubId = null;
        document.getElementById('devDeployBtn').style.display = 'none';
    }

    // Update button states
    document.querySelectorAll('.dev-btn[data-tier]').forEach(b => b.classList.remove('active'));
    const tierBtn = document.querySelector(`.dev-btn[data-tier="${tier}"]`);
    if (tierBtn) tierBtn.classList.add('active');

    selectPlan(tier);

    // Step 1: Create the subscription record (skips PayPal in dev mode)
    const fd = new FormData();
    fd.append('action', 'create_subscription');
    fd.append('tier', tier);

    const res  = await fetch(API, { method:'POST', body:fd });
    const data = await res.json();

    if (!data.success) {
        showToast(data.error || 'Failed to queue deployment', 'error');
        return;
    }

    devPendingSubId = data.subscription_id;

    // Show the manual deploy button in the dev bar
    const btn = document.getElementById('devDeployBtn');
    btn.style.display   = 'inline-block';
    btn.textContent     = '▶ Deploy ' + tier.charAt(0).toUpperCase() + tier.slice(1) + ' Now';
    btn.dataset.subId   = devPendingSubId;
    btn.dataset.tier    = tier;

    showToast('✓ ' + tier + ' queued — hit Deploy when ready', 'success');
    setStep(2);
}

async function devProvisionNow() {
    if (!devPendingSubId) { showToast('No deployment queued', 'error'); return; }

    const btn = document.getElementById('devDeployBtn');
    btn.disabled    = true;
    btn.innerHTML   = '<span class="spinner"></span> Provisioning…';

    document.getElementById('paypalButtonContainer').style.display = 'none';
    document.getElementById('confirmingSpinner').style.display     = 'block';
    setStep(3);

    const fd = new FormData();
    fd.append('action', 'confirm_subscription');
    fd.append('subscription_id', devPendingSubId);

    const res  = await fetch(API, { method:'POST', body:fd });
    const data = await res.json();

    document.getElementById('confirmingSpinner').style.display = 'none';
    btn.style.display = 'none';
    btn.disabled      = false;
    devPendingSubId   = null;
    document.querySelectorAll('.dev-btn[data-tier]').forEach(b => b.classList.remove('active'));

    if (data.success) {
        document.getElementById('plansSection').style.display = 'none';
        renderActiveDeployment(data.deployment, data.credentials);
        setStep(3, true);
        showToast('✓ Server provisioned!', 'success');
    } else {
        showToast(data.error || 'Provision failed', 'error');
        showAlert('<strong>Pelican error:</strong> ' + (data.error || 'Unknown') +
            (data.detail ? '<br><pre style="font-size:10px;margin-top:8px;overflow:auto;background:#0a0a0f;padding:8px;border-radius:6px">' +
            JSON.stringify(data.detail, null, 2) + '</pre>' : ''), 'error');
        setStep(1);
    }
}

async function devClearDeployment() {
    if (!confirm('Cancel + clear your active deployment? (dev only)')) return;
    const fd = new FormData();
    fd.append('action', 'cancel');
    const res  = await fetch(API, { method:'POST', body:fd });
    const data = await res.json();
    showToast(data.success ? 'Cleared' : (data.error || 'Failed'), data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 1000);
}

function devDisable() {
    document.cookie = 'lyralink_dev=bypass; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
    location.reload();
}

// Init
setStep(1, false);
document.addEventListener('DOMContentLoaded', init);
</script>
<!-- DEV BAR -->
<div class="dev-bar" id="devBar">
    <span class="dev-badge">⚙ DEV</span>
    <span class="dev-status">PayPal bypassed — queue a tier then manually provision. <span id="devCookieStatus"></span></span>
    <button class="dev-btn" data-tier="small"  onclick="devTestDeploy('small')">Queue Small</button>
    <button class="dev-btn" data-tier="medium" onclick="devTestDeploy('medium')">Queue Medium</button>
    <button class="dev-btn" data-tier="large"  onclick="devTestDeploy('large')">Queue Large</button>
    <button class="dev-btn" id="devDeployBtn" onclick="devProvisionNow()" style="display:none;border-color:#22c55e;color:#22c55e">▶ Deploy Now</button>
    <button class="dev-btn danger" onclick="devClearDeployment()">Clear</button>
    <button class="dev-btn danger" onclick="devDisable()">Disable Dev</button>
</div>
</body>
</html>