<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — API Keys</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b; --success: #22c55e; --error: #ef4444; --warn: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; }
        body::before { content:''; position:fixed; top:-200px; left:30%; width:600px; height:400px; background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%); pointer-events:none; }

        nav { padding: 14px 24px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(10,10,15,0.9); backdrop-filter:blur(12px); z-index:10; }
        .nav-logo { height: 28px; width: auto; mix-blend-mode: lighten; }
        .nav-links { display:flex; gap:8px; margin-left:auto; }
        .nav-link { color:var(--text-muted); text-decoration:none; font-size:12px; border:1px solid var(--border); padding:5px 12px; border-radius:20px; transition:all 0.2s; }
        .nav-link:hover { border-color:var(--accent); color:var(--accent-light); }

        .container { max-width: 800px; margin: 0 auto; padding: 40px 24px 80px; }

        .page-header { margin-bottom: 32px; }
        .page-header h1 { font-family:'Syne',sans-serif; font-size:28px; font-weight:800; margin-bottom:8px; }
        .page-header h1 span { color:var(--accent-light); }
        .page-header p { color:var(--text-muted); font-size:13px; line-height:1.6; }

        /* LOGIN WALL */
        .login-wall { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:40px; text-align:center; }
        .login-wall h2 { font-family:'Syne',sans-serif; font-size:20px; font-weight:700; margin-bottom:10px; }
        .login-wall p { color:var(--text-muted); font-size:13px; margin-bottom:20px; }
        .btn { padding:10px 20px; border-radius:10px; font-family:'DM Mono',monospace; font-size:12px; cursor:pointer; border:none; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-primary { background:var(--accent); color:white; box-shadow:0 0 12px var(--accent-glow); }
        .btn-primary:hover { background:#6d28d9; }
        .btn-danger { background:rgba(239,68,68,0.1); color:var(--error); border:1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover { background:rgba(239,68,68,0.2); }
        .btn-outline { background:none; color:var(--text-muted); border:1px solid var(--border); }
        .btn-outline:hover { border-color:var(--accent); color:var(--accent-light); }
        .btn:disabled { opacity:0.4; cursor:not-allowed; }

        /* RATE LIMIT CARD */
        .plan-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:24px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .plan-info { flex:1; }
        .plan-info h3 { font-family:'Syne',sans-serif; font-size:15px; font-weight:700; margin-bottom:4px; }
        .plan-info p { font-size:12px; color:var(--text-muted); }
        .plan-badge { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; }
        .plan-badge.free       { background:rgba(100,116,139,0.2); color:var(--text-muted); border:1px solid var(--border); }
        .plan-badge.basic      { background:rgba(34,197,94,0.15); color:var(--success); border:1px solid rgba(34,197,94,0.3); }
        .plan-badge.pro        { background:rgba(124,58,237,0.2); color:var(--accent-light); border:1px solid rgba(124,58,237,0.4); }
        .plan-badge.enterprise { background:rgba(255,107,53,0.15); color:#ff6b35; border:1px solid rgba(255,107,53,0.3); }

        /* KEYS LIST */
        .section-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; }
        .key-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:10px; }
        .key-top { display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .key-label { font-size:13px; font-weight:600; }
        .key-active { background:rgba(34,197,94,0.1); color:var(--success); border:1px solid rgba(34,197,94,0.2); border-radius:4px; padding:2px 8px; font-size:10px; }
        .key-disabled { background:rgba(239,68,68,0.1); color:var(--error); border:1px solid rgba(239,68,68,0.2); border-radius:4px; padding:2px 8px; font-size:10px; }
        .key-string { display:flex; align-items:center; gap:8px; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:10px 12px; font-size:12px; margin-bottom:10px; }
        .key-string code { flex:1; color:var(--accent-light); word-break:break-all; }
        .copy-btn { background:none; border:1px solid var(--border); color:var(--text-muted); border-radius:6px; padding:4px 10px; font-size:11px; cursor:pointer; font-family:'DM Mono',monospace; transition:all 0.2s; white-space:nowrap; }
        .copy-btn:hover { border-color:var(--accent); color:var(--accent-light); }
        .key-meta { display:flex; gap:16px; flex-wrap:wrap; font-size:11px; color:var(--text-muted); margin-bottom:12px; }
        .key-actions { display:flex; gap:8px; }

        /* USAGE BAR */
        .usage-bar-wrap { height:3px; background:var(--border); border-radius:4px; margin:6px 0; overflow:hidden; }
        .usage-bar { height:100%; background:var(--accent); border-radius:4px; transition:width 0.3s; }

        /* NEW KEY FORM */
        .new-key-form { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-top:16px; display:none; }
        .new-key-form.open { display:block; }
        .form-row { display:flex; gap:10px; align-items:flex-end; }
        .form-field { flex:1; }
        .form-field label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:6px; }
        .form-field input { width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:9px 12px; font-family:'DM Mono',monospace; font-size:12px; outline:none; }
        .form-field input:focus { border-color:var(--accent); }

        /* TOAST */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 18px; font-size:12px; z-index:999; opacity:0; transition:opacity 0.3s; pointer-events:none; white-space:nowrap; }
        .toast.show { opacity:1; }
        .toast.success { border-color:var(--success); color:var(--success); }
        .toast.error   { border-color:var(--error);   color:var(--error); }

        .docs-banner { background:rgba(124,58,237,0.08); border:1px solid rgba(124,58,237,0.2); border-radius:12px; padding:16px 20px; margin-bottom:24px; display:flex; align-items:center; gap:14px; }
        .docs-banner p { font-size:13px; color:var(--text-muted); flex:1; }
        .docs-banner p strong { color:var(--text); }

        @media (max-width: 640px) {
            nav { padding: 10px 14px; gap: 8px; }
            nav img { height: 24px; }
            .nav-link { font-size: 11px; padding: 4px 8px; }
            .container { padding: 24px 14px 60px; }
            h1 { font-size: 20px !important; }
            .plan-card { flex-direction: column; align-items: flex-start; gap: 10px; padding: 16px; }
            .key-card { padding: 14px; }
            .key-string { flex-wrap: wrap; gap: 8px; font-size: 11px; }
            .key-string span { word-break: break-all; flex: 1; min-width: 0; }
            .copy-btn { flex-shrink: 0; }
            .key-actions { flex-wrap: wrap; gap: 6px; }
            .new-key-form { padding: 16px; }
            input, select { font-size: 16px; }
            .btn { padding: 10px 16px; }
            .login-wall { padding: 24px 18px; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <div class="nav-links">
        <a href="/pages/api_docs" class="nav-link">📄 Docs</a>
        <a href="/chat" class="nav-link">← Chat</a>
    </div>
</nav>

<div class="container">
    <div class="page-header">
        <h1>API <span>Keys</span></h1>
        <p>Generate a key to query the Lyralink dataset. Each key is tied to your account plan and inherits its rate limits.</p>
    </div>

    <div id="app">
        <div class="login-wall">
            <h2>Sign in to manage API keys</h2>
            <p>You need a Lyralink account to generate API keys.</p>
            <a href="/chat" class="btn btn-primary">Go to Chat & Sign In</a>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const rateLimits = { free: 100, basic: 500, pro: 2000, enterprise: 10000 };

async function init() {
    const fd = new FormData(); fd.append('action','check');
    const res = await (await fetch('/api/auth.php', {method:'POST', body:fd})).json();
    if (!res.logged_in) return; // stays on login wall

    const fd2 = new FormData(); fd2.append('action','status');
    const billing = await (await fetch('/api/billing.php', {method:'POST', body:fd2})).json();

    renderApp(res.username, billing.plan || 'free');
    loadKeys();
}

function renderApp(username, plan) {
    const limit = rateLimits[plan] || 100;
    document.getElementById('app').innerHTML = `
        <div class="plan-card">
            <div class="plan-info">
                <h3>Welcome, ${username}</h3>
                <p>Your API rate limit: <strong>${limit.toLocaleString()} requests/day</strong> — resets at midnight UTC</p>
            </div>
            <span class="plan-badge ${plan}">${plan.charAt(0).toUpperCase()+plan.slice(1)}</span>
            <a href="/pages/pricing" class="btn btn-outline" style="font-size:11px;padding:6px 12px">Upgrade</a>
        </div>

        <div class="docs-banner">
            <p><strong>New to the API?</strong> Check out the documentation to learn how to authenticate and make requests.</p>
            <a href="/pages/api_docs" class="btn btn-outline" style="font-size:11px;padding:6px 12px;white-space:nowrap">View Docs →</a>
        </div>

        <div class="section-title">
            <span>Your API Keys</span>
            <button class="btn btn-primary" style="font-size:11px;padding:6px 14px" onclick="toggleNewKeyForm()">+ New Key</button>
        </div>

        <div class="new-key-form" id="newKeyForm">
            <div class="form-row">
                <div class="form-field">
                    <label>Key Label (optional)</label>
                    <input type="text" id="keyLabel" placeholder="e.g. My App, Production..." maxlength="100">
                </div>
                <button class="btn btn-primary" onclick="createKey()" id="createBtn">Generate Key</button>
            </div>
        </div>

        <div id="keysList"></div>
    `;
}

async function loadKeys() {
    const fd = new FormData(); fd.append('action','list_keys');
    const data = await (await fetch('/api/auth.php', {method:'POST', body:fd})).json();
    const container = document.getElementById('keysList');
    if (!container) return;

    if (!data.keys?.length) {
        container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">No API keys yet — generate one above</div>';
        return;
    }

    container.innerHTML = data.keys.map(k => {
        const limit   = rateLimits[k.plan] || 100;
        const pct     = Math.min(100, Math.round((k.requests_today / limit) * 100));
        const lastUsed = k.last_used_at ? k.last_used_at.slice(0,10) : 'Never';
        return `
        <div class="key-card">
            <div class="key-top">
                <span class="key-label">${escHtml(k.label)}</span>
                <span class="${k.active == 1 ? 'key-active' : 'key-disabled'}">${k.active == 1 ? 'Active' : 'Disabled'}</span>
                <span style="font-size:11px;color:var(--text-muted);margin-left:auto">Created ${k.created_at.slice(0,10)}</span>
            </div>
            <div class="key-string">
                <code>${k.api_key}</code>
            </div>
            <div class="key-meta">
                <span>Today: ${k.requests_today} / ${limit}</span>
                <span>Total: ${k.requests_total.toLocaleString()}</span>
                <span>Last used: ${lastUsed}</span>
            </div>
            <div class="usage-bar-wrap"><div class="usage-bar" style="width:${pct}%"></div></div>
            <div class="key-actions" style="margin-top:10px">
                <button class="btn btn-danger" style="font-size:11px;padding:5px 12px" onclick="revokeKey(${k.id})">Revoke</button>
            </div>
        </div>`;
    }).join('');
}

function toggleNewKeyForm() {
    document.getElementById('newKeyForm')?.classList.toggle('open');
}

async function createKey() {
    const label = document.getElementById('keyLabel')?.value.trim() || 'Default Key';
    const btn   = document.getElementById('createBtn');
    btn.disabled = true; btn.textContent = 'Generating...';

    const fd = new FormData();
    fd.append('action', 'create_api_key');
    fd.append('label', label);
    const data = await (await fetch('/api/auth.php', {method:'POST', body:fd})).json();

    btn.disabled = false; btn.textContent = 'Generate Key';

    if (data.success) {
        document.getElementById('newKeyForm').classList.remove('open');
        document.getElementById('keyLabel').value = '';
        showKeyModal(data.api_key);
        loadKeys();
    } else {
        showToast(data.error || 'Failed to create key', 'error');
    }
}

function showKeyModal(key) {
    // Remove existing modal if any
    document.getElementById('keyModal')?.remove();

    const modal = document.createElement('div');
    modal.id = 'keyModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:100;display:flex;align-items:center;justify-content:center;padding:20px';
    modal.innerHTML = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:540px;width:100%">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span style="font-size:22px">🔑</span>
                <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">Save your API key</h3>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;line-height:1.6">
                This is the <strong style="color:var(--error)">only time</strong> your full key will be shown. Copy it now — it will be masked after you close this.
            </p>
            <div style="background:var(--bg);border:1px solid rgba(124,58,237,0.4);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <code id="fullKeyDisplay" style="flex:1;color:var(--accent-light);font-size:12px;word-break:break-all">${key}</code>
                <button onclick="copyFullKey()" style="background:var(--accent);border:none;color:white;border-radius:8px;padding:7px 14px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;white-space:nowrap" id="copyFullBtn">Copy</button>
            </div>
            <button onclick="document.getElementById('keyModal').remove()" style="width:100%;padding:10px;background:rgba(124,58,237,0.15);border:1px solid rgba(124,58,237,0.3);color:var(--accent-light);border-radius:10px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer">
                I've saved my key — close
            </button>
        </div>
    `;
    document.body.appendChild(modal);
}

function copyFullKey() {
    const key = document.getElementById('fullKeyDisplay')?.textContent;
    if (!key) return;
    navigator.clipboard.writeText(key).then(() => {
        const btn = document.getElementById('copyFullBtn');
        btn.textContent = '✓ Copied';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}

async function revokeKey(keyId) {
    if (!confirm('Revoke this API key? Any apps using it will stop working immediately.')) return;
    const fd = new FormData(); fd.append('action','revoke_api_key'); fd.append('key_id', keyId);
    const data = await (await fetch('/api/auth.php', {method:'POST', body:fd})).json();
    if (data.success) { showToast('Key revoked', 'success'); loadKeys(); }
    else showToast(data.error || 'Failed', 'error');
}

function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

init();
</script>
</body>
</html>