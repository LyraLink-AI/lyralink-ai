<?php
session_start();
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['ai.cloudhavenx.com', 'www.ai.cloudhavenx.com'], true);
$forkModeEnv = getenv('FORK_MODE') ?: ($_ENV['FORK_MODE'] ?? '');
$isForkMode = ($forkModeEnv === '1') || ($host !== '' && !$isPrimaryHost);
$devUsername = 'developer';
if (!$isForkMode && (empty($_SESSION['username']) || $_SESSION['username'] !== $devUsername)) {
    header('Location: /'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Admin</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b;
            --success: #22c55e; --error: #ef4444; --warn: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; }
        body::before { content:''; position:fixed; top:-200px; left:30%; width:600px; height:400px; background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%); pointer-events:none; }

        nav { padding:14px 24px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(10,10,15,0.92); backdrop-filter:blur(12px); z-index:10; }
        .nav-logo { height:28px; width:auto; mix-blend-mode:lighten; }
        .nav-title { font-family:'Syne',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); }
        .nav-links { display:flex; gap:8px; margin-left:auto; }
        .nav-link { color:var(--text-muted); text-decoration:none; font-size:12px; border:1px solid var(--border); padding:5px 12px; border-radius:20px; transition:all 0.2s; }
        .nav-link:hover { border-color:var(--accent); color:var(--accent-light); }

        .container { max-width:960px; margin:0 auto; padding:36px 24px 80px; position:relative; z-index:1; }

        /* PAGE HEADER */
        .page-header { margin-bottom:32px; }
        .page-header h1 { font-family:'Syne',sans-serif; font-size:26px; font-weight:800; margin-bottom:4px; }
        .page-header h1 span { color:var(--accent-light); }
        .page-header p { font-size:12px; color:var(--text-muted); }

        /* GRID */
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        .grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px; }
        @media(max-width:700px) { .grid,.grid-3 { grid-template-columns:1fr; } }

        /* CARDS */
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; }
        .card-title { font-family:'Syne',sans-serif; font-size:13px; font-weight:700; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .card-title .icon { font-size:16px; }

        /* MAINTENANCE TOGGLE */
        .maint-status { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
        .status-pill { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; }
        .status-pill.online  { background:rgba(34,197,94,0.15);  color:var(--success); border:1px solid rgba(34,197,94,0.3); }
        .status-pill.offline { background:rgba(239,68,68,0.15);  color:var(--error);   border:1px solid rgba(239,68,68,0.3); }
        .status-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .status-dot.online  { background:var(--success); box-shadow:0 0 6px rgba(34,197,94,0.6); }
        .status-dot.offline { background:var(--error);   box-shadow:0 0 6px rgba(239,68,68,0.6); animation:pulse-red 1.5s infinite; }
        @keyframes pulse-red { 0%,100%{opacity:1} 50%{opacity:0.4} }

        .eta-row { display:flex; gap:8px; margin-bottom:12px; }
        .eta-input { flex:1; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:8px 12px; font-family:'DM Mono',monospace; font-size:12px; outline:none; }
        .eta-input:focus { border-color:var(--accent); }

        /* BUTTONS */
        .btn { padding:8px 16px; border-radius:10px; font-family:'DM Mono',monospace; font-size:12px; cursor:pointer; border:none; transition:all 0.2s; }
        .btn-red    { background:rgba(239,68,68,0.15);  color:var(--error);   border:1px solid rgba(239,68,68,0.3); }
        .btn-red:hover    { background:rgba(239,68,68,0.25); }
        .btn-green  { background:rgba(34,197,94,0.15);  color:var(--success); border:1px solid rgba(34,197,94,0.3); }
        .btn-green:hover  { background:rgba(34,197,94,0.25); }
        .btn-purple { background:var(--accent); color:white; box-shadow:0 0 10px var(--accent-glow); }
        .btn-purple:hover { background:#6d28d9; }
        .btn-outline { background:none; color:var(--text-muted); border:1px solid var(--border); }
        .btn-outline:hover { border-color:var(--accent); color:var(--accent-light); }
        .btn:disabled { opacity:0.4; cursor:not-allowed; }
        .btn-row { display:flex; gap:8px; flex-wrap:wrap; }

        /* STATS */
        .stat-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
        .stat-item { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px; text-align:center; }
        .stat-value { font-family:'Syne',sans-serif; font-size:22px; font-weight:800; color:var(--accent-light); }
        .stat-label { font-size:10px; color:var(--text-muted); margin-top:2px; text-transform:uppercase; letter-spacing:0.5px; }

        /* QUICK LINKS */
        .link-card { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px 16px; text-decoration:none; color:var(--text); display:flex; align-items:center; gap:12px; transition:all 0.2s; }
        .link-card:hover { border-color:var(--accent); background:rgba(124,58,237,0.05); }
        .link-icon { font-size:20px; }
        .link-info { flex:1; }
        .link-name { font-size:13px; font-weight:600; margin-bottom:2px; }
        .link-desc { font-size:11px; color:var(--text-muted); }
        .link-arrow { color:var(--text-muted); font-size:16px; }

        /* BOT STATUS */
        .bot-status-row { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
        .bot-uptime { font-size:11px; color:var(--text-muted); margin-left:auto; }

        /* TOAST */
        .toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 20px; font-size:12px; z-index:999; opacity:0; transition:opacity 0.3s; pointer-events:none; white-space:nowrap; }
        .toast.show { opacity:1; }
        .toast.success { border-color:var(--success); color:var(--success); }
        .toast.error   { border-color:var(--error);   color:var(--error); }
        .toast.warn    { border-color:var(--warn);    color:var(--warn); }

        /* SECTION LABEL */
        .section-label { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1.5px; margin-bottom:10px; margin-top:24px; }
        .section-label:first-child { margin-top:0; }
    </style>
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-title">/ Admin</span>
    <div class="nav-links">
        <a href="/chat" class="nav-link">← Chat</a>
    </div>
</nav>

<div class="container">

    <div class="page-header">
        <h1>System <span>Settings</span></h1>
        <p><?php echo $isForkMode ? 'Fork preview mode (read-only)' : 'Developer panel — only visible to you'; ?></p>
    </div>

    <?php if ($isForkMode): ?>
    <div class="card" style="margin-bottom:16px;border-color:rgba(245,158,11,0.35);background:rgba(245,158,11,0.06)">
        <div style="font-size:12px;color:var(--warn);line-height:1.7">
            Fork mode is enabled. Sensitive operations such as maintenance toggles and bot controls are disabled.
        </div>
    </div>
    <?php endif; ?>

    <!-- STATS ROW -->
    <div class="section-label">Site Overview</div>
    <div class="card" style="margin-bottom:16px">
        <div class="stat-grid" id="statsGrid">
            <div class="stat-item"><div class="stat-value" id="statUsers">—</div><div class="stat-label">Users</div></div>
            <div class="stat-item"><div class="stat-value" id="statConvs">—</div><div class="stat-label">Conversations</div></div>
            <div class="stat-item"><div class="stat-value" id="statMsgs">—</div><div class="stat-label">Messages</div></div>
            <div class="stat-item"><div class="stat-value" id="statDataset">—</div><div class="stat-label">Dataset Entries</div></div>
            <div class="stat-item"><div class="stat-value" id="statKeys">—</div><div class="stat-label">Active API Keys</div></div>
            <div class="stat-item"><div class="stat-value" id="statPending" style="color:var(--warn)">—</div><div class="stat-label">Pending Review</div></div>
        </div>
    </div>

    <div class="grid">

        <!-- MAINTENANCE TOGGLE -->
        <div class="card">
            <div class="card-title"><span class="icon">🚧</span> Maintenance Mode</div>
            <div class="maint-status">
                <div class="status-dot" id="maintDot"></div>
                <span class="status-pill" id="maintPill">Loading...</span>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.6">
                When enabled, all visitors are redirected to the maintenance page. You stay unaffected via your dev session cookie.
            </p>
            <div class="eta-row">
                <input class="eta-input" type="text" id="etaInput" placeholder="ETA (e.g. ~30 minutes, back soon...)">
            </div>
            <div class="btn-row">
                <button class="btn btn-red"   id="maintBtn" onclick="toggleMaintenance()">Loading...</button>
            </div>
            <div id="maintMsg" style="font-size:11px;color:var(--text-muted);margin-top:8px"></div>
        </div>

        <!-- DISCORD BOT -->
        <div class="card">
            <div class="card-title"><span class="icon">🤖</span> Discord Bot</div>
            <div class="bot-status-row">
                <div class="status-dot" id="botDot"></div>
                <span class="status-pill" id="botPill">Loading...</span>
                <span class="bot-uptime" id="botUptime"></span>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.6">
                Controls the Lyralink aoi.js Discord bot via PM2. Restart applies code changes without downtime.
            </p>
            <div class="btn-row">
                <button class="btn btn-green"   onclick="botAction('bot_restart')">↻ Restart</button>
                <button class="btn btn-red"     onclick="botAction('bot_stop')">■ Stop</button>
                <button class="btn btn-outline" onclick="loadStatus()">⟳ Refresh</button>
            </div>
            <div id="botMsg" style="font-size:11px;color:var(--text-muted);margin-top:8px"></div>
        </div>

    </div>

    <!-- QUICK LINKS -->
    <div class="section-label">Tools</div>
    <div class="grid-3">
        <a href="/pages/dataset_manager" class="link-card">
            <span class="link-icon">🗄️</span>
            <div class="link-info">
                <div class="link-name">Dataset Manager</div>
                <div class="link-desc">Review, approve & manage Q&A entries</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/api_keys" class="link-card">
            <span class="link-icon">🔑</span>
            <div class="link-info">
                <div class="link-name">API Keys</div>
                <div class="link-desc">Manage your public API keys</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/api_docs" class="link-card">
            <span class="link-icon">📄</span>
            <div class="link-info">
                <div class="link-name">API Docs</div>
                <div class="link-desc">Public developer documentation</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/support_admin" class="link-card">
            <span class="link-icon">🎫</span>
            <div class="link-info">
                <div class="link-name">Support Dashboard</div>
                <div class="link-desc">Manage tickets, agents & config</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/webbot_admin.php" class="link-card">
            <span class="link-icon">🧩</span>
            <div class="link-info">
                <div class="link-name">Web Bot Instances</div>
                <div class="link-desc">Inspect, restart, SFTP-enable, or delete every bot workspace</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
    </div>

</div>

<div class="toast" id="toast"></div>

<script>
let currentMaintenance = false;

async function api(action, body = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(body).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch('/api/admin.php', { method: 'POST', body: fd });
    return res.json();
}

async function loadStatus() {
    const data = await api('status').catch(() => null);
    if (!data?.success) return;

    currentMaintenance = data.maintenance;

    // Maintenance
    const dot  = document.getElementById('maintDot');
    const pill = document.getElementById('maintPill');
    const btn  = document.getElementById('maintBtn');
    const msg  = document.getElementById('maintMsg');
    if (data.maintenance) {
        dot.className  = 'status-dot offline';
        pill.className = 'status-pill offline';
        pill.textContent = 'MAINTENANCE ON';
        btn.className  = 'btn btn-green';
        btn.textContent = '✓ Disable Maintenance';
        msg.textContent  = data.eta ? `ETA: ${data.eta}` : '';
        document.getElementById('etaInput').value = data.eta || '';
    } else {
        dot.className  = 'status-dot online';
        pill.className = 'status-pill online';
        pill.textContent = 'SITE ONLINE';
        btn.className  = 'btn btn-red';
        btn.textContent = '⚠ Enable Maintenance';
        msg.textContent  = '';
    }

    // Bot
    const botDot   = document.getElementById('botDot');
    const botPill  = document.getElementById('botPill');
    const botUp    = document.getElementById('botUptime');
    if (data.bot.running) {
        botDot.className  = 'status-dot online';
        botPill.className = 'status-pill online';
        botPill.textContent = 'RUNNING';
        botUp.textContent   = data.bot.uptime ? `Up ${data.bot.uptime}` : '';
    } else {
        botDot.className  = 'status-dot offline';
        botPill.className = 'status-pill offline';
        botPill.textContent = 'OFFLINE';
        botUp.textContent   = '';
    }

    // Stats
    const s = data.stats;
    document.getElementById('statUsers').textContent   = s.users.toLocaleString();
    document.getElementById('statConvs').textContent   = s.convs.toLocaleString();
    document.getElementById('statMsgs').textContent    = s.msgs.toLocaleString();
    document.getElementById('statDataset').textContent = s.dataset.toLocaleString();
    document.getElementById('statKeys').textContent    = s.apiKeys.toLocaleString();
    document.getElementById('statPending').textContent = s.pending.toLocaleString();
}

async function toggleMaintenance() {
    if (window.__forkMode) {
        showToast('Disabled in fork preview mode', 'warn');
        return;
    }
    const btn = document.getElementById('maintBtn');
    const eta = document.getElementById('etaInput').value.trim();

    if (!currentMaintenance) {
        if (!confirm('Enable maintenance mode? All users will be redirected until you disable it.')) return;
    }

    btn.disabled = true;
    const data = await api('toggle_maintenance', { eta }).catch(() => null);
    btn.disabled = false;

    if (data?.success) {
        showToast(data.maintenance ? '🚧 Maintenance mode ON' : '✓ Site back online', data.maintenance ? 'warn' : 'success');
        loadStatus();
    } else {
        showToast('Failed to toggle', 'error');
    }
}

async function botAction(action) {
    if (window.__forkMode) {
        showToast('Disabled in fork preview mode', 'warn');
        return;
    }
    const msgEl = document.getElementById('botMsg');
    msgEl.textContent = action === 'bot_restart' ? 'Restarting...' : 'Stopping...';
    const data = await api(action).catch(() => null);
    if (data?.success) {
        showToast(data.message, 'success');
        setTimeout(loadStatus, 3000);
    } else {
        showToast(data?.error || 'Failed', 'error');
    }
    msgEl.textContent = '';
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

loadStatus();
setInterval(loadStatus, 30000);
window.__forkMode = <?php echo $isForkMode ? 'true' : 'false'; ?>;
</script>
</body>
</html>