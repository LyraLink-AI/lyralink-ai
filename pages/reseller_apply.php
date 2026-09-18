<?php
session_start();
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) { header('Location: /pages/maintenance.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Infrastructure — Operator Application</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;
            --accent:#7c3aed;--accent-glow:rgba(124,58,237,0.3);--accent-light:#a78bfa;
            --text:#e2e8f0;--text-muted:#64748b;--text-dim:#94a3b8;
            --success:#22c55e;--error:#ef4444;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        body::before{content:'';position:fixed;top:-200px;left:30%;width:600px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,0.1) 0%,transparent 70%);pointer-events:none}

        nav{padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,0.92);backdrop-filter:blur(12px);z-index:10}
        .nav-logo{height:28px;width:auto;mix-blend-mode:lighten}
        .nav-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700}
        .nav-title span{color:var(--accent-light)}
        .nav-links{display:flex;gap:8px;margin-left:auto;align-items:center}
        .nav-link{color:var(--text-muted);text-decoration:none;font-size:12px;border:1px solid var(--border);padding:5px 12px;border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

        .container{max-width:680px;margin:0 auto;padding:48px 24px 80px;position:relative;z-index:1}

        .page-header{margin-bottom:36px}
        .badge{display:inline-block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--accent-light);background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);border-radius:20px;padding:5px 14px;margin-bottom:16px}
        h1{font-family:'Syne',sans-serif;font-size:clamp(24px,4vw,36px);font-weight:800;line-height:1.2;margin-bottom:12px}
        h1 span{color:var(--accent-light)}
        .subtitle{font-size:13px;color:var(--text-muted);line-height:1.7;max-width:520px}

        .perks{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:28px 0}
        @media(max-width:500px){.perks{grid-template-columns:1fr}}
        .perk{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px;font-size:12px;color:var(--text-dim);display:flex;align-items:flex-start;gap:10px}
        .perk-icon{font-size:18px;flex-shrink:0;margin-top:1px}

        .tracks{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:18px 0 24px}
        @media(max-width:740px){.tracks{grid-template-columns:1fr}}
        .track{background:linear-gradient(180deg,rgba(17,17,24,0.95),rgba(17,17,24,0.8));border:1px solid var(--border);border-radius:12px;padding:12px}
        .track h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:5px}
        .track p{font-size:12px;color:var(--text-muted);line-height:1.55}
        .track .tag{display:inline-block;margin-top:8px;font-size:10px;letter-spacing:.05em;text-transform:uppercase;border:1px solid var(--border);border-radius:18px;padding:3px 8px;color:var(--accent-light);background:rgba(124,58,237,.12)}

        .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px}
        .card-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin-bottom:20px}

        .form-group{margin-bottom:16px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
        @media(max-width:500px){.form-row{grid-template-columns:1fr}}
        label{display:block;font-size:11px;color:var(--text-muted);margin-bottom:6px;letter-spacing:.04em;text-transform:uppercase}
        label .req{color:var(--accent-light)}
        input,select,textarea{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:11px 14px;color:var(--text);font-family:'DM Mono',monospace;font-size:13px;transition:border-color 0.2s;outline:none}
        input:focus,select:focus,textarea:focus{border-color:var(--accent)}
        textarea{resize:vertical;min-height:110px}
        select option{background:var(--surface)}

        .btn{padding:13px 28px;border-radius:12px;font-family:'DM Mono',monospace;font-size:13px;cursor:pointer;border:none;transition:all 0.25s;display:inline-flex;align-items:center;gap:8px}
        .btn-primary{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:white;box-shadow:0 0 20px rgba(124,58,237,0.3);width:100%;justify-content:center;font-size:14px}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 0 28px rgba(124,58,237,0.45)}
        .btn-primary:disabled{opacity:0.6;cursor:not-allowed;transform:none}

        .msg{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;display:none}
        .msg.success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--success)}
        .msg.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--error)}
        .msg.show{display:block}

        .status-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:32px;text-align:center;display:none}
        .status-card.show{display:block}
        .status-icon{font-size:48px;margin-bottom:16px}
        .status-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:700;margin-bottom:8px}
        .status-sub{font-size:13px;color:var(--text-muted);line-height:1.7}
        .status-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;margin-bottom:16px}
        .status-badge.pending{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
        .status-badge.approved{background:rgba(34,197,94,.15);color:var(--success);border:1px solid rgba(34,197,94,.3)}
        .status-badge.rejected{background:rgba(239,68,68,.15);color:var(--error);border:1px solid rgba(239,68,68,.3)}
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/images/lyralinkai.ico" class="nav-logo" alt="Lyralink Infrastructure">
    <div class="nav-title">Lyra<span>link</span></div>
    <div class="nav-links">
        <a href="/" class="nav-link">← Home</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="/chat.php" class="nav-link">Chat</a>
        <?php else: ?>
        <a href="/?login=1" class="nav-link">Sign In</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container">

    <!-- STATUS VIEW (shown if already applied or approved) -->
    <div class="status-card" id="statusCard">
        <div class="status-icon" id="statusIcon">📋</div>
        <div class="status-badge" id="statusBadge"></div>
        <div class="status-title" id="statusTitle"></div>
        <div class="status-sub" id="statusSub"></div>
        <div style="margin-top:24px">
            <a href="/pages/reseller.php" id="dashboardLink" style="display:none" class="btn btn-primary">Go to Operator Dashboard →</a>
            <a href="/" class="nav-link" style="display:inline-block;margin-top:12px">← Back to Home</a>
        </div>
    </div>

    <!-- APPLICATION FORM -->
    <div id="formSection">
        <div class="page-header">
            <div class="badge">Operator Program</div>
            <h1>Launch Your <span>AI Business</span></h1>
            <p class="subtitle">Apply to run on Lyralink infrastructure, brand the platform as your own, and scale recurring client revenue.</p>
        </div>

        <div class="perks">
            <div class="perk"><div class="perk-icon">🎨</div><div>White-label control for brand, domain, and presentation</div></div>
            <div class="perk"><div class="perk-icon">💰</div><div>Recurring revenue model with payout tracking</div></div>
            <div class="perk"><div class="perk-icon">📊</div><div>Operator dashboard for clients and growth metrics</div></div>
            <div class="perk"><div class="perk-icon">🔌</div><div>API and embed distribution for your product stack</div></div>
        </div>

            <div class="tracks">
                <div class="track">
                    <h3>Starter Track</h3>
                    <p>For people new to AI. Launch one niche offer, use referral links, and onboard your first paying clients quickly.</p>
                    <span class="tag">No-code friendly</span>
                </div>
                <div class="track">
                    <h3>Agency Track</h3>
                    <p>For operators managing multiple client accounts with branding, support workflows, and recurring revenue targets.</p>
                    <span class="tag">Growth focused</span>
                </div>
                <div class="track">
                    <h3>Business Track</h3>
                    <p>For larger teams running integrated operations, API-based products, and enterprise onboarding standards.</p>
                    <span class="tag">Scale ready</span>
                </div>
            </div>

        <div class="card">
            <div class="card-title">Operator Application</div>
            <div class="msg" id="msg"></div>

            <div class="form-row">
                <div class="form-group">
                    <label>Your Name <span class="req">*</span></label>
                    <input type="text" id="name" placeholder="Jane Smith" maxlength="120">
                </div>
                <div class="form-group">
                    <label>Contact Email <span class="req">*</span></label>
                    <input type="email" id="email" placeholder="jane@company.com" maxlength="255">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Company / Brand Name <span class="req">*</span></label>
                    <input type="text" id="company" placeholder="Acme AI Systems" maxlength="200">
                </div>
                <div class="form-group">
                    <label>Website</label>
                    <input type="text" id="website" placeholder="https://acmedigital.com" maxlength="255">
                </div>
            </div>

            <div class="form-group">
                    <label>Projected Active Clients</label>
                <select id="clientCount">
                    <option value="">Select range…</option>
                    <option value="1-5">1–5 clients</option>
                    <option value="6-20">6–20 clients</option>
                    <option value="21-50">21–50 clients</option>
                    <option value="51-100">51–100 clients</option>
                    <option value="100+">100+ clients</option>
                </select>
            </div>

            <div class="form-group">
                <label>Launch Template (optional)</label>
                <select id="launchTemplate" onchange="applyTemplate()">
                    <option value="">Select a template…</option>
                    <option value="starter_support">Starter: local business support assistant</option>
                    <option value="agency_reseller">Agency: multi-client reseller service</option>
                    <option value="enterprise_internal">Business: internal enterprise assistant</option>
                </select>
            </div>

            <div class="form-group">
                <label>How will you package and sell your AI offer? <span class="req">*</span></label>
                <textarea id="useCase" placeholder="Describe your target market, your offer packaging, projected pricing, and how Lyralink infrastructure fits into your business model..."></textarea>
            </div>

            <button class="btn btn-primary" id="submitBtn" onclick="submitApplication()">
                🚀 Submit Operator Application
            </button>
        </div>
    </div>
</div>

<script>
(async function checkStatus() {
    try {
        const r = await fetch('/api/reseller.php?action=get_application_status');
        const d = await r.json();
        if (!d.success) return;

        if (d.application) {
            showStatus(d.application.status, d.application.admin_note);
        }

        // Check if already a reseller
        const r2 = await fetch('/api/reseller.php?action=get_dashboard');
        const d2 = await r2.json();
        if (d2.success) showStatus('approved', null);
    } catch(e) {}
})();

function applyTemplate() {
    const value = document.getElementById('launchTemplate').value;
    const useCase = document.getElementById('useCase');
    if (!useCase || !value) return;

    const templates = {
        starter_support: 'I will offer a support assistant for local service businesses. My first target segment is small clinics and home service providers. I will package setup + monthly support, then onboard clients using a simple referral flow and branded widget.',
        agency_reseller: 'I run an agency model where we manage AI assistants for multiple clients. We will sell monthly plans with onboarding, analytics, and support. Lyralink powers infrastructure and billing while we focus on client acquisition and retention.',
        enterprise_internal: 'We are deploying an internal assistant for teams that need secure workflows and predictable operations. We will integrate via API, manage departments through role-based access, and use usage-aware pricing controls for sustainable scale.'
    };

    if (!useCase.value.trim()) {
        useCase.value = templates[value] || '';
        return;
    }

    const shouldReplace = confirm('Replace your current use-case text with the selected template?');
    if (shouldReplace) {
        useCase.value = templates[value] || useCase.value;
    }
}

function showStatus(status, note) {
    document.getElementById('formSection').style.display = 'none';
    const card = document.getElementById('statusCard');
    card.classList.add('show');

    const icons = {pending:'⏳',approved:'✅',rejected:'❌'};
    const titles = {pending:'Application Under Review',approved:'You\'re Approved to Operate',rejected:'Application Not Approved'};
    const subs = {
        pending: 'We\'re reviewing your operator application and will get back to you within 1–2 business days.',
        approved: 'Your operator account is active. Head to your dashboard to manage clients, distribution, and earnings.',
        rejected: note && note.toLowerCase().includes('removed by admin')
            ? 'Your operator access was removed by an administrator. If you believe this was a mistake, please contact support.'
            : 'Unfortunately your application wasn\'t approved at this time.' + (note ? ' Reason: ' + note : '') + ' You can re-apply below or contact us to discuss.',
    };

    document.getElementById('statusIcon').textContent = icons[status] || '📋';
    document.getElementById('statusTitle').textContent = titles[status] || status;
    document.getElementById('statusSub').textContent = subs[status] || '';
    const badge = document.getElementById('statusBadge');
    badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    badge.className = 'status-badge ' + status;

    if (status === 'approved') {
        document.getElementById('dashboardLink').style.display = 'inline-flex';
    }
    // If rejected due to removal, hide the form entirely but show re-apply link
    if (status === 'rejected') {
        document.getElementById('formSection').style.display = 'none';
    }
}

async function submitApplication() {
    const btn = document.getElementById('submitBtn');
    const msg = document.getElementById('msg');

    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const company = document.getElementById('company').value.trim();
    const website = document.getElementById('website').value.trim();
    const useCase = document.getElementById('useCase').value.trim();
    const clientCount = document.getElementById('clientCount').value;

    if (!name || !email || !company || !useCase) {
        showMsg('error', 'Please fill in all required fields.');
        return;
    }
    if (useCase.length < 30) {
        showMsg('error', 'Please describe your use case in more detail (at least 30 characters).');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Submitting…';

    try {
        const fd = new FormData();
        fd.append('name', name);
        fd.append('email', email);
        fd.append('company', company);
        fd.append('website', website);
        fd.append('use_case', useCase);
        fd.append('client_count', clientCount);

        const r = await fetch('/api/reseller.php?action=apply', {method:'POST', body:fd});
        const d = await r.json();

        if (d.success) {
            showStatus('pending', null);
        } else {
            showMsg('error', d.error || 'Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = '🚀 Submit Operator Application';
        }
    } catch(e) {
        showMsg('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = '🚀 Submit Operator Application';
    }
}

function showMsg(type, text) {
    const el = document.getElementById('msg');
    el.className = 'msg ' + type + ' show';
    el.textContent = text;
    el.scrollIntoView({behavior:'smooth', block:'nearest'});
}
</script>
</body>
</html>
