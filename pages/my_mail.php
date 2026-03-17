<?php
session_start();
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: /'); exit;
}
$sessionEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
$username = htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Email - Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#0a0a0f; --surface:#111118; --surface2:#171724; --border:#24243a; --text:#d9e1ef; --muted:#7e8aa3; --accent:#7c3aed; --accent-glow:rgba(124,58,237,.28); --ok:#22c55e; --bad:#ef4444; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Mono', monospace; background:radial-gradient(900px 480px at 70% -80px, rgba(124,58,237,.09), transparent 65%), var(--bg); color:var(--text); min-height:100vh; }
        nav { position:sticky; top:0; z-index:10; display:flex; align-items:center; gap:12px; padding:12px 20px; background:rgba(10,10,15,.92); border-bottom:1px solid var(--border); backdrop-filter:blur(10px); }
        .nav-logo { height:27px; mix-blend-mode:lighten; }
        .nav-title { font-family:'Syne',sans-serif; font-weight:800; font-size:13px; color:#a78bfa; }
        .nav-right { margin-left:auto; display:flex; gap:8px; }
        .nav-link { font-size:11px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:5px 10px; transition:all .2s; }
        .nav-link:hover { color:#fff; border-color:#7c3aed; }

        .page { max-width:600px; margin:0 auto; padding:36px 20px 80px; }
        .hero { background:linear-gradient(135deg, rgba(124,58,237,.12), rgba(167,139,250,.07)); border:1px solid rgba(124,58,237,.35); border-radius:16px; padding:22px 20px; margin-bottom:20px; }
        .hero h1 { font-family:'Syne',sans-serif; font-weight:800; font-size:22px; }
        .hero h1 span { color:#a78bfa; }
        .hero p { margin-top:8px; color:#b8bfd4; font-size:12px; line-height:1.6; }

        .panel { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .panel-title { font-family:'Syne',sans-serif; font-size:12px; font-weight:700; color:var(--muted); margin-bottom:12px; text-transform:uppercase; letter-spacing:.06em; }

        .email-pill { display:inline-flex; align-items:center; gap:7px; background:rgba(124,58,237,.12); border:1px solid rgba(124,58,237,.3); border-radius:999px; padding:7px 14px; font-size:13px; color:#a78bfa; }
        .email-dot { width:7px; height:7px; border-radius:50%; background:var(--muted); flex-shrink:0; }
        .email-dot.ok { background:var(--ok); box-shadow:0 0 6px rgba(34,197,94,.5); }
        .email-dot.bad { background:var(--bad); }

        .status-row { display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap; }
        .status-badge { font-size:11px; padding:4px 10px; border-radius:999px; border:1px solid var(--border); color:var(--muted); }
        .status-badge.ok { border-color:rgba(34,197,94,.4); color:#9ef0bb; background:rgba(34,197,94,.08); }
        .status-badge.bad { border-color:rgba(239,68,68,.4); color:#ffb4b4; background:rgba(239,68,68,.08); }
        .status-badge.loading { border-color:rgba(124,58,237,.35); color:#a78bfa; background:rgba(124,58,237,.08); }

        label { display:block; font-size:11px; color:var(--muted); margin-bottom:5px; }
        input[type=password] { width:100%; background:#0b0d12; border:1px solid #2f3546; border-radius:8px; color:var(--text); padding:9px 12px; font-family:'DM Mono',monospace; font-size:13px; outline:none; transition:border-color .2s; }
        input[type=password]:focus { border-color:#7c3aed; }
        .input-hint { font-size:10px; color:var(--muted); margin-top:5px; }

        .btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:10px; font-family:'DM Mono',monospace; font-size:12px; cursor:pointer; border:none; transition:all .2s; }
        .btn-primary { background:var(--accent); color:#fff; box-shadow:0 0 14px var(--accent-glow); }
        .btn-primary:hover { background:#6d28d9; }
        .btn-primary:disabled { opacity:.4; cursor:not-allowed; box-shadow:none; }
        .btn-secondary { background:var(--surface2); border:1px solid var(--border); color:var(--text); }
        .btn-secondary:hover { border-color:#7c3aed; color:#a78bfa; }
        .btn-check { background:rgba(124,58,237,.12); border:1px solid rgba(124,58,237,.3); color:#a78bfa; }
        .btn-check:hover { background:rgba(124,58,237,.2); }

        .msg { margin-top:12px; padding:10px 12px; border-radius:10px; border:1px solid var(--border); font-size:12px; color:var(--muted); }
        .msg.ok { border-color:rgba(34,197,94,.4); color:#9ef0bb; background:rgba(34,197,94,.06); }
        .msg.bad { border-color:rgba(239,68,68,.4); color:#ffb4b4; background:rgba(239,68,68,.06); }

        .hidden { display:none !important; }
        .mt12 { margin-top:12px; }
        .mt16 { margin-top:16px; }

        .no-mailbox-info { font-size:12px; color:var(--muted); line-height:1.7; margin-top:10px; }
        .no-mailbox-info strong { color:var(--text); }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span style="color:#3c3f52">/</span>
    <span class="nav-title">My Email</span>
    <div class="nav-right">
        <a href="/" class="nav-link">Home</a>
    </div>
</nav>
<div class="page">
    <div class="hero">
        <h1>My <span>Email</span></h1>
        <p>Access your company email account via webmail. You can open Roundcube directly without entering your Plesk password on every visit.</p>
    </div>

    <!-- Email identity panel -->
    <div class="panel">
        <div class="panel-title">Your Account Email</div>
        <?php if ($sessionEmail !== ''): ?>
        <div class="email-pill">
            <span class="email-dot" id="statusDot"></span>
            <?= htmlspecialchars($sessionEmail, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="status-row">
            <span class="status-badge loading" id="statusBadge">Checking mailbox&hellip;</span>
            <button class="btn btn-check" id="btnCheck" onclick="checkStatus()" style="display:none">Re-check</button>
        </div>
        <?php else: ?>
        <p style="font-size:12px;color:var(--muted)">No email address is associated with your account. Please contact an administrator.</p>
        <?php endif; ?>
    </div>

    <!-- SSO panel (shown when mailbox exists) -->
    <div class="panel hidden" id="ssoPanel">
        <div class="panel-title">Open Webmail</div>
        <p style="font-size:12px;color:var(--muted);margin-bottom:14px;">Enter your email password to launch Roundcube. Your password is used only for this one-time login handshake and is not stored.</p>
        <label for="mailPass">Email Password</label>
        <input type="password" id="mailPass" placeholder="Your webmail password" autocomplete="current-password">
        <div class="input-hint">This is the password for your <strong><?= htmlspecialchars($sessionEmail, ENT_QUOTES, 'UTF-8') ?></strong> mailbox in Plesk.</div>
        <div class="mt16">
            <button class="btn btn-primary" id="btnLaunch" onclick="launchWebmail()">Open My Email &rarr;</button>
        </div>
        <div id="launchMsg" class="msg hidden"></div>
    </div>

    <!-- No-mailbox panel (shown when no mailbox found) -->
    <div class="panel hidden" id="noMailboxPanel">
        <div class="panel-title">No Mailbox Found</div>
        <div class="no-mailbox-info">
            No Plesk mailbox exists for <strong><?= htmlspecialchars($sessionEmail, ENT_QUOTES, 'UTF-8') ?></strong>.<br><br>
            If you believe you should have a company email account, please contact your administrator to have your mailbox created.
        </div>
    </div>
</div>
<script>
const ACCOUNT_EMAIL = <?= json_encode($sessionEmail) ?>;

async function apiFetch(action, body = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(body)) fd.append(k, v);
    const r = await fetch('/api/mail_admin.php', { method:'POST', body: fd, credentials:'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Origin': location.origin } });
    return r.json();
}

async function checkStatus() {
    const dot = document.getElementById('statusDot');
    const badge = document.getElementById('statusBadge');
    badge.className = 'status-badge loading';
    badge.textContent = 'Checking\u2026';
    dot.className = 'email-dot';
    document.getElementById('ssoPanel').classList.add('hidden');
    document.getElementById('noMailboxPanel').classList.add('hidden');

    try {
        const d = await apiFetch('my_mail_status');
        if (!d.success) throw new Error(d.error || 'Unknown error');
        if (d.has_mailbox) {
            dot.className = 'email-dot ok';
            badge.className = 'status-badge ok';
            badge.textContent = 'Mailbox active';
            document.getElementById('ssoPanel').classList.remove('hidden');
        } else {
            dot.className = 'email-dot bad';
            badge.className = 'status-badge bad';
            badge.textContent = 'No mailbox';
            document.getElementById('noMailboxPanel').classList.remove('hidden');
        }
    } catch (e) {
        badge.className = 'status-badge bad';
        badge.textContent = 'Check failed';
    }
    document.getElementById('btnCheck').style.display = '';
}

async function launchWebmail() {
    const pass = document.getElementById('mailPass').value;
    const btn = document.getElementById('btnLaunch');
    const msg = document.getElementById('launchMsg');
    msg.className = 'msg hidden';
    if (pass.length < 10) {
        msg.textContent = 'Please enter your email password (at least 10 characters).';
        msg.className = 'msg bad';
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Connecting\u2026';
    try {
        const d = await apiFetch('my_sso_ticket', { password: pass });
        if (!d.success) {
            msg.textContent = d.error || 'SSO failed.';
            msg.className = 'msg bad';
            return;
        }
        msg.textContent = 'Redirecting to webmail\u2026';
        msg.className = 'msg ok';
        document.getElementById('mailPass').value = '';
        setTimeout(() => { window.location.href = d.launch_url; }, 400);
    } catch (e) {
        msg.textContent = 'Request failed. Please try again.';
        msg.className = 'msg bad';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Open My Email \u2192';
    }
}

// Auto-check on load if we have an email
if (ACCOUNT_EMAIL) checkStatus();
</script>
</body>
</html>
