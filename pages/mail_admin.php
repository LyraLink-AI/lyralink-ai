<?php
session_start();
$devUsername = 'developer';
if (empty($_SESSION['username']) || $_SESSION['username'] !== $devUsername) {
    header('Location: /');
    exit;
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Admin - Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#0a0a0f; --surface:#111118; --surface2:#171724; --border:#24243a; --text:#d9e1ef; --muted:#7e8aa3; --accent:#ff6b35; --ok:#22c55e; --bad:#ef4444; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Mono', monospace; background:radial-gradient(1000px 520px at 90% -120px, rgba(255,107,53,.09), transparent 65%), var(--bg); color:var(--text); min-height:100vh; }
        nav { position:sticky; top:0; z-index:10; display:flex; align-items:center; gap:12px; padding:12px 20px; background:rgba(10,10,15,.92); border-bottom:1px solid var(--border); backdrop-filter: blur(10px); }
        .nav-logo { height:27px; mix-blend-mode:lighten; }
        .nav-title { font-family:'Syne',sans-serif; font-weight:800; font-size:13px; color:#ffb08e; }
        .nav-right { margin-left:auto; display:flex; gap:8px; }
        .nav-link { font-size:11px; color:var(--muted); text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:5px 10px; }
        .nav-link:hover { color:#fff; border-color:#ff8c5d; }
        .page { max-width:1200px; margin:0 auto; padding:22px; }
        .hero { background:linear-gradient(135deg, rgba(255,107,53,.13), rgba(245,158,11,.08)); border:1px solid rgba(255,140,93,.35); border-radius:16px; padding:18px; margin-bottom:16px; }
        .hero h1 { font-family:'Syne',sans-serif; font-weight:800; font-size:24px; line-height:1.12; }
        .hero p { margin-top:7px; color:#ffceb7; font-size:12px; line-height:1.6; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .panel { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; }
        .panel h3 { font-family:'Syne',sans-serif; font-size:13px; margin-bottom:10px; }
        .row { display:flex; gap:8px; margin-bottom:8px; }
        input, select { flex:1; background:#0b0d12; border:1px solid #2f3546; border-radius:8px; color:#d9e1ef; padding:9px 10px; font-family:'DM Mono',monospace; font-size:12px; }
        .btn { background:var(--surface2); border:1px solid #303049; color:var(--text); border-radius:10px; font-family:'DM Mono', monospace; font-size:12px; padding:8px 11px; cursor:pointer; }
        .btn:hover { border-color:#ff8c5d; color:#fff; }
        .btn.accent { background:rgba(255,107,53,.17); border-color:#ff8c5d; color:#ffd9c8; }
        .btn.ok { border-color:rgba(34,197,94,.5); color:#9ef0bb; }
        .btn.bad { border-color:rgba(239,68,68,.45); color:#ffb4b4; }
        .list { margin-top:10px; max-height:460px; overflow:auto; border:1px solid var(--border); border-radius:10px; }
        .item { display:flex; gap:8px; align-items:center; justify-content:space-between; padding:9px 10px; border-bottom:1px solid rgba(36,36,58,.7); }
        .item:last-child { border-bottom:0; }
        .item code { color:#dbe8ff; font-size:12px; }
        .small { font-size:10px; color:var(--muted); }
        .msg { margin-top:12px; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--surface); font-size:12px; color:var(--muted); min-height:38px; }
        .msg.ok { border-color:rgba(34,197,94,.5); color:#9ef0bb; }
        .msg.bad { border-color:rgba(239,68,68,.45); color:#ffb4b4; }
        @media (max-width: 980px) { .grid { grid-template-columns:1fr; } }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span style="color:#3c3f52">/</span>
    <span class="nav-title">Mail Admin</span>
    <div class="nav-right">
        <a href="/pages/admin.php" class="nav-link">Admin</a>
        <a href="/" class="nav-link">Home</a>
    </div>
</nav>
<div class="page">
    <div class="hero">
        <h1>Plesk Mail + Roundcube SSO</h1>
        <p>Create and manage mailboxes, then launch one-click Roundcube sign-in using a short-lived SSO ticket. For security, SSO requires webmail on the same host.</p>
    </div>

    <div class="grid">
        <section class="panel">
            <h3>Mailbox Management</h3>
            <div class="small" id="mailMeta">Loading...</div>
            <div class="row" style="margin-top:10px">
                <input id="newEmail" placeholder="newuser@domain.com">
                <input id="newPass" type="password" placeholder="Mailbox password">
                <button class="btn ok" onclick="createMailbox()">Create</button>
            </div>
            <div class="row">
                <input id="resetEmail" placeholder="user@domain.com">
                <input id="resetPass" type="password" placeholder="New password">
                <button class="btn" onclick="resetMailboxPassword()">Reset Password</button>
            </div>

            <div class="list" id="mailboxList"></div>
        </section>

        <section class="panel">
            <h3>Roundcube SSO Launch</h3>
            <div class="small">Use mailbox credentials to create a one-time login ticket. Ticket expires in 90 seconds.</div>
            <div class="row" style="margin-top:10px">
                <input id="ssoEmail" placeholder="mailbox@domain.com">
                <input id="ssoPass" type="password" placeholder="Mailbox password">
            </div>
            <div class="row">
                <button class="btn accent" onclick="launchSso()">Open Roundcube via SSO</button>
                <button class="btn" onclick="openWebmailLogin()">Open Webmail Login</button>
            </div>
            <div class="small" id="webmailHint" style="margin-top:8px"></div>

            <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
            <h3 style="margin-bottom:6px">Safety Notes</h3>
            <div class="small">- SSO tickets are stored in session only and consumed once.</div>
            <div class="small">- Credentials are never placed in URL query strings.</div>
            <div class="small">- If MAIL_WEBMAIL_URL is on another subdomain, set MAIL_WEBMAIL_COOKIE_DOMAIN (example: .cloudhavenx.com).</div>
        </section>
    </div>

    <div class="msg" id="msgBox">Ready.</div>
</div>

<script>
const API = '/api/mail_admin.php';
let webmailUrl = '/webmail/?_task=login';

function setMsg(text, kind = '') {
    const el = document.getElementById('msgBox');
    el.textContent = text;
    el.className = 'msg' + (kind ? ' ' + kind : '');
}

async function apiCall(action, payload = {}, method = 'POST') {
    if (method === 'GET') {
        const qs = new URLSearchParams({ action, ...payload });
        const res = await fetch(API + '?' + qs.toString(), { credentials: 'same-origin' });
        return res.json();
    }
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
}

function escHtml(v) {
    return String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;');
}

function renderMailboxes(mailboxes) {
    const list = document.getElementById('mailboxList');
    if (!Array.isArray(mailboxes) || mailboxes.length === 0) {
        list.innerHTML = '<div class="item"><span class="small">No mailboxes found.</span></div>';
        return;
    }
    list.innerHTML = mailboxes.map((email) => {
        const safe = escHtml(email);
        return '<div class="item">'
            + '<code>' + safe + '</code>'
            + '<div style="display:flex;gap:8px">'
            + '<button class="btn" onclick="useForSso(\'' + safe + '\')">Use for SSO</button>'
            + '<button class="btn bad" onclick="deleteMailbox(\'' + safe + '\')">Delete</button>'
            + '</div>'
            + '</div>';
    }).join('');
}

function useForSso(email) {
    document.getElementById('ssoEmail').value = email;
    document.getElementById('resetEmail').value = email;
    setMsg('Selected ' + email + ' for SSO and password reset.', 'ok');
}

async function loadStatus() {
    const data = await apiCall('status', {}, 'GET');
    if (!data.success) {
        setMsg(data.error || 'Failed to load mail status', 'bad');
        await loadDiagnostics();
        return;
    }
    webmailUrl = data.webmail_url || '/webmail/?_task=login';
    const scopeLabel = data.scope === 'all-domains' ? 'All domains' : ('Domain: ' + (data.domain || '-'));
    document.getElementById('mailMeta').textContent = scopeLabel + ' · Mailboxes: ' + (data.mailboxes || []).length;
    document.getElementById('webmailHint').textContent = 'Webmail URL: ' + webmailUrl;
    renderMailboxes(data.mailboxes || []);
}

async function loadDiagnostics() {
    const diag = await apiCall('diagnostics', {}, 'GET').catch(() => null);
    if (!diag || !diag.success) {
        return;
    }
    const id = diag.runtime || {};
    const parts = [];
    parts.push('Runtime user: ' + (id.user || 'unknown') + ' (uid ' + (id.uid ?? '-') + ')');
    parts.push('Sudo enabled: ' + (diag.sudo_enabled ? 'yes' : 'no'));
    parts.push('Privileged test: ' + (diag.test_ok ? 'ok' : ('failed (' + diag.test_code + ')')));
    if (!diag.test_ok && diag.test_error) {
        parts.push('Error: ' + diag.test_error);
    }
    setMsg(parts.join(' | '), diag.test_ok ? 'ok' : 'bad');
}

async function createMailbox() {
    const email = document.getElementById('newEmail').value.trim().toLowerCase();
    const password = document.getElementById('newPass').value;
    if (!email || !password) {
        setMsg('Email and password are required', 'bad');
        return;
    }
    const data = await apiCall('create_mailbox', { email, password });
    if (!data.success) {
        setMsg(data.error || 'Create failed', 'bad');
        return;
    }
    setMsg(data.message || 'Mailbox created', 'ok');
    document.getElementById('newPass').value = '';
    await loadStatus();
}

async function resetMailboxPassword() {
    const email = document.getElementById('resetEmail').value.trim().toLowerCase();
    const password = document.getElementById('resetPass').value;
    if (!email || !password) {
        setMsg('Email and new password are required', 'bad');
        return;
    }
    const data = await apiCall('reset_mailbox_password', { email, password });
    if (!data.success) {
        setMsg(data.error || 'Password reset failed', 'bad');
        return;
    }
    setMsg(data.message || 'Mailbox password updated', 'ok');
    document.getElementById('resetPass').value = '';
}

async function deleteMailbox(email) {
    if (!confirm('Delete mailbox ' + email + '?')) return;
    const data = await apiCall('delete_mailbox', { email });
    if (!data.success) {
        setMsg(data.error || 'Delete failed', 'bad');
        return;
    }
    setMsg(data.message || 'Mailbox deleted', 'ok');
    await loadStatus();
}

async function launchSso() {
    const email = document.getElementById('ssoEmail').value.trim().toLowerCase();
    const password = document.getElementById('ssoPass').value;
    if (!email || !password) {
        setMsg('SSO email and password are required', 'bad');
        return;
    }
    const data = await apiCall('create_sso_ticket', { email, password });
    if (!data.success) {
        setMsg(data.error || 'Failed to create SSO ticket', 'bad');
        return;
    }
    window.open(data.launch_url, '_blank', 'noopener');
    setMsg('Opened Roundcube SSO window.', 'ok');
    document.getElementById('ssoPass').value = '';
}

function openWebmailLogin() {
    window.open(webmailUrl || '/webmail/?_task=login', '_blank', 'noopener');
}

loadStatus();
</script>
</body>
</html>
