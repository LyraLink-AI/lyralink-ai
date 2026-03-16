<?php
require_once __DIR__ . '/../api/security.php';
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php');
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Bot Panel - Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f; --surface:#111118; --surface2:#171724; --border:#24243a;
            --text:#d9e1ef; --text-muted:#7e8aa3;
            --accent:#ff6b35; --ok:#22c55e; --bad:#ef4444;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Mono', monospace; background:radial-gradient(1200px 600px at 80% -120px, rgba(255,107,53,.08), transparent 65%), radial-gradient(900px 500px at -10% 120%, rgba(245,158,11,.06), transparent 60%), var(--bg); color:var(--text); min-height:100vh; }
        nav { position:sticky; top:0; z-index:10; display:flex; align-items:center; gap:12px; padding:12px 20px; background:rgba(10,10,15,.92); border-bottom:1px solid var(--border); backdrop-filter: blur(10px); }
        .nav-logo { height:27px; mix-blend-mode:lighten; }
        .nav-title { font-family:'Syne', sans-serif; font-weight:800; font-size:13px; color:#ffb08e; }
        .nav-right { margin-left:auto; display:flex; gap:8px; }
        .nav-link { font-size:11px; color:var(--text-muted); text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:5px 10px; }
        .nav-link:hover { color:#fff; border-color:#ff8c5d; }
        .page { max-width:1480px; margin:0 auto; padding:22px; }
        .hero { background:linear-gradient(135deg, rgba(255,107,53,.13), rgba(245,158,11,.08)); border:1px solid rgba(255,140,93,.35); border-radius:16px; padding:18px; margin-bottom:16px; }
        .hero h1 { font-family:'Syne',sans-serif; font-weight:800; font-size:27px; line-height:1.12; }
        .hero p { margin-top:7px; color:#ffceb7; font-size:12px; }
        .status-row { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
        .stat, .panel { background:var(--surface); border:1px solid var(--border); border-radius:12px; }
        .stat { padding:12px; }
        .stat-label { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.7px; }
        .stat-value { margin-top:4px; font-size:13px; font-weight:600; }
        .controls { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:10px; }
        .btn { background:var(--surface2); border:1px solid #303049; color:var(--text); border-radius:10px; font-family:'DM Mono', monospace; font-size:12px; padding:8px 11px; cursor:pointer; }
        .btn:hover { border-color:#ff8c5d; color:#fff; }
        .btn:disabled { opacity:.48; cursor:not-allowed; border-color:#2a2a3c; color:#8a8fa1; }
        .btn:disabled:hover { border-color:#2a2a3c; color:#8a8fa1; }
        .btn.accent { background:rgba(255,107,53,.17); border-color:#ff8c5d; color:#ffd9c8; }
        .btn.ok { border-color:rgba(34,197,94,.5); color:#9ef0bb; }
        .btn.bad { border-color:rgba(239,68,68,.45); color:#ffb4b4; }
        .grid { display:grid; grid-template-columns:1.2fr 1.15fr .85fr; gap:12px; min-height:620px; }
        .panel { overflow:hidden; display:flex; flex-direction:column; min-height:320px; }
        .panel-head { display:flex; align-items:center; gap:8px; padding:9px 11px; border-bottom:1px solid var(--border); background:rgba(255,107,53,.05); }
        .panel-title { font-family:'Syne',sans-serif; font-size:13px; font-weight:700; }
        .panel-sub { margin-left:auto; color:var(--text-muted); font-size:10px; }
        .terminal { flex:1; padding:10px; overflow:auto; background:#0b0d12; color:#d3ffe0; white-space:pre-wrap; font-size:12px; line-height:1.5; }
        .terminal-row { display:flex; gap:8px; border-top:1px solid var(--border); padding:8px; background:var(--surface2); }
        .terminal-input { flex:1; background:#0b0d12; border:1px solid #2f3546; border-radius:8px; color:#d9e1ef; padding:9px 10px; font-family:'DM Mono',monospace; font-size:12px; }
        .file-tools { display:flex; gap:8px; padding:8px; border-bottom:1px solid var(--border); background:#0f1320; flex-wrap:wrap; }
        .file-wrap { display:flex; flex:1; min-height:0; }
        .file-list { width:44%; min-width:220px; max-width:360px; border-right:1px solid var(--border); overflow:auto; background:#0d1119; }
        .file-item { width:100%; border:0; border-bottom:1px solid rgba(36,36,58,.7); background:none; color:#dbe5f8; text-align:left; padding:9px 10px; cursor:pointer; font-family:'DM Mono',monospace; font-size:11px; }
        .file-item:hover, .file-item.active { background:rgba(255,107,53,.14); color:#fff; }
        .editor-wrap { flex:1; display:flex; flex-direction:column; min-width:0; }
        .editor-meta { border-bottom:1px solid var(--border); padding:8px 10px; font-size:10px; color:var(--text-muted); display:flex; align-items:center; gap:8px; }
        .editor { flex:1; width:100%; resize:none; border:0; outline:none; background:#0c1017; color:#dbe8ff; padding:11px; font-family:'DM Mono', monospace; font-size:12px; line-height:1.5; }
        .side-box { padding:12px; font-size:11px; line-height:1.8; color:var(--text-muted); }
        .side-tab-row { display:flex; gap:8px; padding:9px 11px; border-bottom:1px solid var(--border); background:#0f1320; }
        .side-tab-btn { flex:1; border:1px solid #2f3650; background:#131929; color:#b6c0d8; border-radius:8px; padding:7px 9px; font-family:'DM Mono',monospace; font-size:11px; cursor:pointer; }
        .side-tab-btn.active { background:rgba(255,107,53,.14); border-color:#ff8c5d; color:#fff; }
        .side-tab { display:none; }
        .side-tab.active { display:block; }
        .ai-helper-controls { display:grid; gap:8px; }
        .ai-input, .ai-select { width:100%; background:#0b0f18; border:1px solid #2d3448; color:#dbe8ff; border-radius:8px; padding:8px 9px; font-family:'DM Mono',monospace; font-size:11px; }
        .ai-row { display:flex; gap:8px; }
        .ai-row .btn { flex:1; }
        .ai-result { margin-top:10px; border:1px solid #2b3247; border-radius:10px; background:#0d1220; padding:10px; max-height:360px; overflow:auto; }
        .ai-title { font-family:'Syne',sans-serif; font-size:12px; font-weight:700; color:#ffd4c2; margin-bottom:6px; }
        .ai-find { border:1px solid #2f3851; border-radius:8px; padding:8px; margin-top:6px; background:#0a101c; }
        .ai-sev { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10px; text-transform:uppercase; letter-spacing:.4px; }
        .ai-sev.high { background:rgba(239,68,68,.2); color:#ffc2c2; border:1px solid rgba(239,68,68,.45); }
        .ai-sev.medium { background:rgba(245,158,11,.2); color:#ffe6b2; border:1px solid rgba(245,158,11,.45); }
        .ai-sev.low { background:rgba(34,197,94,.2); color:#c1f3d3; border:1px solid rgba(34,197,94,.45); }
        .code-line { display:block; background:#0b0f18; border:1px solid #242b3c; border-radius:8px; padding:7px 9px; color:#dbe8ff; margin-top:6px; word-break:break-all; }
        .msg { margin-top:12px; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--surface); font-size:12px; color:var(--text-muted); min-height:38px; }
        .msg.ok { border-color:rgba(34,197,94,.5); color:#9ef0bb; }
        .msg.bad { border-color:rgba(239,68,68,.45); color:#ffb4b4; }
        .diag { margin-top:10px; padding:10px 12px; border-radius:10px; border:1px solid rgba(239,68,68,.4); background:rgba(127,29,29,.12); color:#ffd3d3; font-size:11px; line-height:1.45; display:none; }
        .diag-title { font-family:'Syne',sans-serif; font-size:12px; font-weight:700; margin-bottom:4px; color:#ffb4b4; }
        .diag pre { margin-top:8px; background:#120f15; border:1px solid #3a2b3b; border-radius:8px; padding:8px; color:#ffd9d9; font-family:'DM Mono',monospace; font-size:10px; max-height:150px; overflow:auto; white-space:pre-wrap; }
        @media (max-width: 1180px) { .grid { grid-template-columns:1fr; } .status-row { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span style="color:#3c3f52">/</span>
    <span class="nav-title">Web Bot Panel</span>
    <div class="nav-right">
        <a href="/pages/deploy/" class="nav-link">Deploy</a>
        <a href="/chat.php" class="nav-link">Chat</a>
        <a href="/" class="nav-link">Home</a>
    </div>
</nav>
<div class="page">
    <div class="hero">
        <h1>Run a Discord bot in-browser with Docker, SFTP access, live terminal streaming, and realtime file sync.</h1>
        <p>The editor now reacts to outside file changes, including SFTP edits, so the panel stays in sync while you work.</p>
    </div>
    <div class="status-row">
        <div class="stat"><div class="stat-label">Account</div><div class="stat-value" id="sUser">Loading...</div></div>
        <div class="stat"><div class="stat-label">Workspace</div><div class="stat-value" id="sWorkspace">-</div></div>
        <div class="stat"><div class="stat-label">Container</div><div class="stat-value" id="sContainer">-</div></div>
        <div class="stat"><div class="stat-label">SFTP</div><div class="stat-value" id="sSftp">-</div></div>
        <div class="stat"><div class="stat-label">Watch</div><div class="stat-value" id="sWatch">-</div></div>
    </div>
    <div class="controls">
        <button id="btnCreate" class="btn accent" onclick="createWorkspace()">Create Workspace</button>
        <button id="btnStart" class="btn ok" onclick="startBot()">Start Bot</button>
        <button id="btnRestart" class="btn" onclick="restartBot()">Restart</button>
        <button id="btnStop" class="btn bad" onclick="stopBot()">Stop</button>
        <button id="btnManualRestart" class="btn accent" onclick="manualRestart()">Manual Restart</button>
        <button id="btnOpenTerminal" class="btn" onclick="connectTerminal()">Open Terminal</button>
        <button id="btnCloseTerminal" class="btn" onclick="disconnectTerminal()">Close Terminal</button>
        <button id="btnEnableSftp" class="btn" onclick="enableSftp()">Enable SFTP</button>
        <button id="btnDisableSftp" class="btn bad" onclick="disableSftp()">Disable SFTP</button>
        <button id="btnRefresh" class="btn" onclick="refreshAll()">Refresh</button>
    </div>
    <div class="diag" id="stabilityDiag"></div>
    <div class="grid">
        <section class="panel">
            <div class="panel-head"><div class="panel-title">Live Terminal</div><div class="panel-sub" id="terminalState">offline</div></div>
            <pre class="terminal" id="terminalOut">Terminal not connected.</pre>
            <div class="terminal-row">
                <input id="terminalInput" class="terminal-input" placeholder="Type a shell command and press Enter">
                <button id="btnSendTerminal" class="btn" onclick="sendTerminalLine()">Send</button>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div class="panel-title">Files</div><div class="panel-sub" id="openPath">no selection</div></div>
            <div class="file-tools">
                <button id="btnNewFile" class="btn" onclick="createEntry('file')">New File</button>
                <button id="btnNewFolder" class="btn" onclick="createEntry('dir')">New Folder</button>
                <button id="btnRename" class="btn" onclick="renameEntry()">Rename</button>
                <button id="btnDelete" class="btn bad" onclick="deleteEntry()">Delete</button>
                <button id="btnReloadFiles" class="btn" style="margin-left:auto" onclick="loadFiles()">Reload</button>
            </div>
            <div class="file-wrap">
                <div class="file-list" id="fileList"></div>
                <div class="editor-wrap">
                    <div class="editor-meta"><span id="editorHint">Select a file to edit.</span><button id="btnSaveFile" class="btn" style="margin-left:auto" onclick="saveFile()">Save File</button></div>
                    <textarea id="editor" class="editor" spellcheck="false" placeholder="Select a file from the left."></textarea>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div class="panel-title">SFTP + Notes</div></div>
            <div class="side-tab-row">
                <button id="sideTabBtnSftp" class="side-tab-btn active" onclick="showSideTab('sftp')">SFTP + Notes</button>
                <button id="sideTabBtnAi" class="side-tab-btn" onclick="showSideTab('ai')">AI Helper</button>
            </div>
            <div id="sideTabSftp" class="side-tab side-box active">
                <p>SFTP host:</p>
                <span class="code-line" id="sftpHost">-</span>
                <p style="margin-top:10px">SFTP port:</p>
                <span class="code-line" id="sftpPort">-</span>
                <p style="margin-top:10px">Username:</p>
                <span class="code-line" id="sftpUser">-</span>
                <p style="margin-top:10px">Password:</p>
                <span class="code-line" id="sftpPass">-</span>
                <hr style="border:0;border-top:1px solid var(--border);margin:12px 0">
                <p>Realtime sync notes:</p>
                <p>- File list refreshes when workspace contents change</p>
                <p>- If you are editing and the file changes externally, the panel warns instead of overwriting your buffer</p>
                <p>- If the current file is unchanged locally, it auto-reloads from disk</p>
            </div>
            <div id="sideTabAi" class="side-tab side-box">
                <div class="ai-helper-controls">
                    <label>Scope
                        <select id="aiScope" class="ai-select">
                            <option value="current">Current file</option>
                            <option value="all">All code files</option>
                            <option value="custom">Custom list</option>
                        </select>
                    </label>
                    <label id="aiCustomWrap" style="display:none">Custom paths (one per line)
                        <textarea id="aiCustomPaths" class="ai-input" rows="4" placeholder="index.js\nsrc/commands/ping.js"></textarea>
                    </label>
                    <label>Focus
                        <textarea id="aiPrompt" class="ai-input" rows="3" placeholder="Example: focus on crash causes, async errors, and token validation."></textarea>
                    </label>
                    <div class="ai-row">
                        <button id="btnAiScan" class="btn" onclick="runAiHelper('scan')">Find Issues</button>
                        <button id="btnAiFix" class="btn accent" onclick="runAiHelper('fix')">Find + Fix</button>
                    </div>
                    <button id="btnAiApply" class="btn ok" onclick="applyAiFixes()" disabled>Apply Suggested Fixes</button>
                </div>
                <div id="aiResult" class="ai-result" style="display:none"></div>
            </div>
        </section>
    </div>
    <div class="msg" id="msgBox">Ready.</div>
</div>
<script>
const API = '/api/webbot.php';
let state = null;
let selectedPath = '';
let selectedType = '';
let lastLoadedContent = '';
let lastLoadedMtime = 0;
let pollTimer = null;
let terminalSocket = null;
let watchSocket = null;
let lastWatchHash = '';
const pendingActions = new Set();
let workspaceFiles = [];
let lastAiAssistance = null;

function escHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function setDisabled(id, disabled) {
    const el = document.getElementById(id);
    if (el) el.disabled = !!disabled;
}

function withPending(action, run) {
    pendingActions.add(action);
    updateControlStates();
    return Promise.resolve()
        .then(run)
        .finally(() => {
            pendingActions.delete(action);
            updateControlStates();
        });
}

function updateControlStates() {
    const hasState = !!state;
    const workspaceExists = !!(state && state.workspace_exists);
    const running = !!(state && state.running);
    const restarting = !!(state && state.restarting);
    const terminalConnected = !!(terminalSocket && terminalSocket.readyState === WebSocket.OPEN);
    const sftpEnabled = !!(state && state.sftp && state.sftp.enabled);
    const hasSelection = !!selectedPath;
    const selectedFile = selectedType === 'file';
    const hasAiFixes = !!(lastAiAssistance && Array.isArray(lastAiAssistance.proposed_files) && lastAiAssistance.proposed_files.length > 0);

    setDisabled('btnCreate', !hasState || workspaceExists || pendingActions.has('create'));
    setDisabled('btnStart', !workspaceExists || running || restarting || pendingActions.has('start'));
    setDisabled('btnRestart', !running || restarting || pendingActions.has('restart'));
    setDisabled('btnStop', !running || restarting || pendingActions.has('stop'));
    setDisabled('btnManualRestart', !workspaceExists || pendingActions.has('manual_restart'));
    setDisabled('btnOpenTerminal', !running || restarting || terminalConnected || pendingActions.has('terminal_connect'));
    setDisabled('btnCloseTerminal', !terminalConnected);
    setDisabled('btnEnableSftp', !workspaceExists || sftpEnabled || pendingActions.has('sftp_enable'));
    setDisabled('btnDisableSftp', !workspaceExists || !sftpEnabled || pendingActions.has('sftp_disable'));
    setDisabled('btnRefresh', pendingActions.has('refresh'));
    setDisabled('btnSendTerminal', !terminalConnected);

    setDisabled('btnNewFile', !workspaceExists);
    setDisabled('btnNewFolder', !workspaceExists);
    setDisabled('btnRename', !workspaceExists || !hasSelection);
    setDisabled('btnDelete', !workspaceExists || !hasSelection);
    setDisabled('btnReloadFiles', !workspaceExists);
    setDisabled('btnSaveFile', !workspaceExists || !selectedFile);
    setDisabled('btnAiScan', !workspaceExists || pendingActions.has('ai_help'));
    setDisabled('btnAiFix', !workspaceExists || pendingActions.has('ai_help'));
    setDisabled('btnAiApply', !workspaceExists || pendingActions.has('ai_apply') || !hasAiFixes);
}

function setMsg(text, kind = '') {
    const el = document.getElementById('msgBox');
    el.textContent = text;
    el.className = 'msg' + (kind ? ' ' + kind : '');
}

async function apiCall(action, payload = {}, method = 'POST') {
    if (method === 'GET') {
        const qs = new URLSearchParams({ action, ...payload });
        const res = await fetch(API + '?' + qs.toString(), { credentials:'same-origin' });
        return res.json();
    }
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(API, { method:'POST', body:fd, credentials:'same-origin' });
    return res.json();
}

function appendTerminal(text) {
    const out = document.getElementById('terminalOut');
    const current = out.textContent === 'Terminal not connected.' ? '' : out.textContent;
    const cleaned = String(text || '')
        .replace(/\u001b\[[0-?]*[ -/]*[@-~]/g, '')
        .replace(/\u001b\][^\u0007]*(\u0007|\u001b\\)/g, '')
        .replace(/\u001b[PX^_][\s\S]*?\u001b\\/g, '')
        .replace(/\u001b[@-_]/g, '');
    out.textContent = current + cleaned;
    out.scrollTop = out.scrollHeight;
}

function setTerminalState(text) {
    document.getElementById('terminalState').textContent = text;
}

function renderSftp(sftp) {
    document.getElementById('sSftp').textContent = sftp?.enabled ? ('enabled:' + (sftp.port || '-')) : 'disabled';
    document.getElementById('sftpHost').textContent = sftp?.host || '-';
    document.getElementById('sftpPort').textContent = sftp?.port || '-';
    document.getElementById('sftpUser').textContent = sftp?.username || '-';
    document.getElementById('sftpPass').textContent = sftp?.password || '-';
}

function renderStability(diag) {
    const box = document.getElementById('stabilityDiag');
    if (!diag) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    const status = String(diag.status || 'unknown');
    if (status === 'missing') {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    const reason = String(diag.error || '').trim();
    const unstable = !!reason || !!diag.restarting || !diag.running;
    if (!unstable) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    const exitCode = typeof diag.exit_code === 'number' ? diag.exit_code : 0;
    const restartCount = typeof diag.restart_count === 'number' ? diag.restart_count : 0;
    const details = reason !== '' ? reason : ('Container status: ' + status + ', exit code: ' + exitCode);
    const logs = String(diag.logs_tail || '').trim();
    box.style.display = 'block';
    box.innerHTML = '<div class="diag-title">Container instability detected</div>'
        + '<div><strong>Reason:</strong> ' + details.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>'
        + '<div><strong>Status:</strong> ' + status.replace(/</g, '&lt;').replace(/>/g, '&gt;')
        + ' | <strong>Exit:</strong> ' + exitCode
        + ' | <strong>Restarts:</strong> ' + restartCount + '</div>'
        + (logs !== '' ? '<pre>' + logs.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>' : '');
}

function renderStatus(s) {
    state = s;
    const restarting = !!s.restarting;
    document.getElementById('sUser').textContent = (s.user?.username || 'guest') + ' (' + (s.user?.plan || '-') + ')';
    document.getElementById('sWorkspace').textContent = s.workspace_exists ? 'Created' : 'Missing';
    document.getElementById('sContainer').textContent = restarting
        ? ('Restarting (' + s.container_name + ')')
        : (s.running ? ('Running (' + s.container_name + ')') : (s.container_exists ? 'Stopped' : 'Not created'));
    document.getElementById('sWatch').textContent = watchSocket && watchSocket.readyState === WebSocket.OPEN ? 'connected' : (s.workspace_exists ? 'ready' : 'offline');
    if (!terminalSocket || terminalSocket.readyState !== WebSocket.OPEN) {
        setTerminalState(restarting ? 'restarting' : (s.running ? 'ready' : 'offline'));
    }
    renderSftp(s.sftp || {});
    renderStability(s.stability || null);
    updateControlStates();
}

async function loadStatus() {
    const data = await apiCall('status', {}, 'GET');
    if (!data.success) {
        if (data.disabled) {
            setMsg('Web bot panel is disabled. Set ENABLE_WEB_BOT_PANEL=1 in .env', 'bad');
        } else if ((data.error || '').toLowerCase().includes('login')) {
            setMsg('Please sign in first, then reload this page.', 'bad');
        } else {
            setMsg(data.error || 'Failed to load status', 'bad');
        }
        return false;
    }
    renderStatus(data);
    return true;
}

async function loadFiles() {
    if (!state || !state.workspace_exists) {
        const list = document.getElementById('fileList');
        list.innerHTML = '<div style="padding:10px;color:#7e8aa3;font-size:11px">Workspace not created yet.</div>';
        document.getElementById('editor').value = '';
        updateControlStates();
        return;
    }
    const data = await apiCall('list_files', {}, 'GET');
    if (!data.success) {
        setMsg(data.error || 'Failed to list files', 'bad');
        return;
    }
    const list = document.getElementById('fileList');
    const files = data.files || [];
    workspaceFiles = files;
    if (!files.length) {
        list.innerHTML = '<div style="padding:10px;color:#7e8aa3;font-size:11px">No files yet. Create workspace first.</div>';
        document.getElementById('editor').value = '';
        updateControlStates();
        return;
    }
    list.innerHTML = files.map(entry => {
        const active = selectedPath === entry.path ? 'active' : '';
        const icon = entry.type === 'dir' ? '[DIR] ' : '[FILE] ';
        return '<button class="file-item ' + active + '" onclick="selectEntry(\'' + entry.path.replace(/'/g, "\\'") + '\', \'' + entry.type + '\')">' + icon + entry.path + '</button>';
    }).join('');
    if (!selectedPath) {
        const preferred = files.find(f => f.type === 'file' && f.path === 'index.js') || files.find(f => f.type === 'file') || files[0];
        if (preferred) {
            await selectEntry(preferred.path, preferred.type);
        }
    }
    updateControlStates();
}

function showSideTab(tab) {
    const sftpBtn = document.getElementById('sideTabBtnSftp');
    const aiBtn = document.getElementById('sideTabBtnAi');
    const sftpTab = document.getElementById('sideTabSftp');
    const aiTab = document.getElementById('sideTabAi');
    const isAi = tab === 'ai';
    sftpBtn.classList.toggle('active', !isAi);
    aiBtn.classList.toggle('active', isAi);
    sftpTab.classList.toggle('active', !isAi);
    aiTab.classList.toggle('active', isAi);
}

function listAiPathsByScope() {
    const scope = document.getElementById('aiScope').value;
    if (scope === 'current') {
        if (selectedType === 'file' && selectedPath) {
            return [selectedPath];
        }
        return [];
    }
    if (scope === 'all') {
        return workspaceFiles
            .filter((f) => f.type === 'file')
            .map((f) => f.path)
            .filter((path) => /\.(js|cjs|mjs|ts|tsx|jsx|json|md|txt|py|php|sh|css|html|env|yml|yaml|ini|toml)$/i.test(path))
            .slice(0, 12);
    }
    const raw = document.getElementById('aiCustomPaths').value || '';
    return raw.split(/\r?\n|,/).map((s) => s.trim()).filter(Boolean).slice(0, 12);
}

function renderAiAssistance(assistance) {
    const box = document.getElementById('aiResult');
    const findings = Array.isArray(assistance?.findings) ? assistance.findings : [];
    const proposed = Array.isArray(assistance?.proposed_files) ? assistance.proposed_files : [];

    const fileList = (assistance?.reviewed_files || []).map((f) => '<span class="code-line">' + escHtml(f.path + ' (' + f.bytes + ' bytes)') + '</span>').join('');
    const findingList = findings.length
        ? findings.map((f) => '<div class="ai-find">'
            + '<div><span class="ai-sev ' + escHtml(f.severity || 'medium') + '">' + escHtml(f.severity || 'medium') + '</span> '
            + '<strong>' + escHtml(f.path || '') + '</strong>'
            + (f.line_hint ? ' <span style="color:#8fa1c7">@ ' + escHtml(f.line_hint) + '</span>' : '')
            + '</div>'
            + '<div style="margin-top:4px"><strong>Issue:</strong> ' + escHtml(f.issue || '') + '</div>'
            + '<div><strong>Why:</strong> ' + escHtml(f.why || '') + '</div>'
            + '<div><strong>Fix:</strong> ' + escHtml(f.fix || '') + '</div>'
            + '</div>').join('')
        : '<div class="ai-find">No issues detected.</div>';

    const fixList = proposed.length
        ? '<div class="ai-title" style="margin-top:10px">Proposed File Updates</div>'
            + proposed.map((p) => '<div class="ai-find"><strong>' + escHtml(p.path || '') + '</strong>'
            + (p.reason ? '<div style="margin-top:4px"><strong>Reason:</strong> ' + escHtml(p.reason) + '</div>' : '')
            + '</div>').join('')
        : '';

    box.style.display = 'block';
    box.innerHTML = '<div class="ai-title">AI Review Summary</div>'
        + '<div>' + escHtml(assistance?.summary || 'AI helper completed.') + '</div>'
        + '<div class="ai-title" style="margin-top:10px">Reviewed Files</div>'
        + (fileList || '<div class="ai-find">None</div>')
        + '<div class="ai-title" style="margin-top:10px">Findings</div>'
        + findingList
        + fixList;
}

async function runAiHelper(mode) {
    const paths = listAiPathsByScope();
    if (!paths.length) {
        setMsg('Select at least one file for AI helper', 'bad');
        return;
    }
    await withPending('ai_help', async () => {
        setMsg(mode === 'fix' ? 'AI helper reviewing and preparing fixes...' : 'AI helper reviewing files...', '');
        const prompt = document.getElementById('aiPrompt').value || '';
        const data = await apiCall('ai_help', {
            mode,
            prompt,
            paths: JSON.stringify(paths),
        });
        if (!data.success) {
            setMsg(data.error || 'AI helper failed', 'bad');
            return;
        }
        lastAiAssistance = data.assistance || null;
        renderAiAssistance(lastAiAssistance || {});
        const proposedCount = (lastAiAssistance?.proposed_files || []).length;
        setMsg(proposedCount > 0 ? ('AI helper prepared ' + proposedCount + ' file fix(es).') : 'AI helper review complete.', 'ok');
        updateControlStates();
        showSideTab('ai');
    });
}

async function applyAiFixes() {
    const proposed = (lastAiAssistance && Array.isArray(lastAiAssistance.proposed_files)) ? lastAiAssistance.proposed_files : [];
    if (!proposed.length) {
        setMsg('No suggested fixes to apply', 'bad');
        return;
    }
    await withPending('ai_apply', async () => {
        let okCount = 0;
        let failCount = 0;
        for (const file of proposed) {
            const data = await apiCall('save_file', { path: file.path, content: file.content || '' });
            if (data.success) {
                okCount += 1;
            } else {
                failCount += 1;
            }
        }
        await loadFiles();
        if (selectedPath && selectedType === 'file' && proposed.some((p) => p.path === selectedPath)) {
            await selectEntry(selectedPath, 'file');
        }
        setMsg('Applied fixes: ' + okCount + ' success, ' + failCount + ' failed.', failCount ? 'bad' : 'ok');
        if (failCount === 0) {
            lastAiAssistance.proposed_files = [];
        }
        updateControlStates();
    });
}

async function selectEntry(path, type) {
    selectedPath = path;
    selectedType = type;
    document.getElementById('openPath').textContent = path + ' (' + type + ')';
    document.getElementById('editorHint').textContent = type === 'file' ? 'Editing file' : 'Folders cannot be edited directly';
    if (type !== 'file') {
        document.getElementById('editor').value = '';
        updateControlStates();
        await loadFiles();
        return;
    }
    const data = await apiCall('read_file', { path }, 'GET');
    if (!data.success) {
        setMsg(data.error || 'Failed to open file', 'bad');
        return;
    }
    document.getElementById('editor').value = data.content || '';
    lastLoadedContent = data.content || '';
    lastLoadedMtime = data.mtime || 0;
    updateControlStates();
    await loadFiles();
}

async function refreshCurrentFileAfterExternalChange() {
    if (!selectedPath || selectedType !== 'file') {
        await loadFiles();
        return;
    }
    const editorValue = document.getElementById('editor').value;
    if (editorValue !== lastLoadedContent) {
        setMsg('Workspace changed externally. Reload skipped because you have unsaved edits.', 'bad');
        await loadFiles();
        return;
    }
    const data = await apiCall('read_file', { path: selectedPath }, 'GET');
    if (data.success && (data.mtime || 0) !== lastLoadedMtime) {
        document.getElementById('editor').value = data.content || '';
        lastLoadedContent = data.content || '';
        lastLoadedMtime = data.mtime || 0;
        setMsg('File updated from workspace change', 'ok');
    }
    await loadFiles();
}

async function saveFile() {
    if (!selectedPath || selectedType !== 'file') {
        setMsg('Select a file first', 'bad');
        return;
    }
    const content = document.getElementById('editor').value;
    const data = await apiCall('save_file', { path:selectedPath, content });
    if (!data.success) {
        setMsg(data.error || 'Save failed', 'bad');
        return;
    }
    lastLoadedContent = content;
    lastLoadedMtime = data.mtime || lastLoadedMtime;
    setMsg('Saved ' + selectedPath + ' (' + data.bytes + ' bytes)', 'ok');
    await loadFiles();
}

async function createEntry(kind) {
    const hint = kind === 'dir' ? 'New folder path' : 'New file path';
    const path = window.prompt(hint, kind === 'dir' ? 'src/' : 'src/example.js');
    if (!path) return;
    const data = await apiCall('create_entry', { path, kind });
    if (!data.success) {
        setMsg(data.error || 'Failed to create entry', 'bad');
        return;
    }
    setMsg(data.message || 'Created', 'ok');
    selectedPath = path;
    selectedType = kind;
    await loadFiles();
    if (kind === 'file') await selectEntry(path, 'file');
}

async function renameEntry() {
    if (!selectedPath) {
        setMsg('Select a file or folder first', 'bad');
        return;
    }
    const next = window.prompt('Rename to', selectedPath);
    if (!next || next === selectedPath) return;
    const data = await apiCall('rename_entry', { old_path:selectedPath, new_path:next });
    if (!data.success) {
        setMsg(data.error || 'Rename failed', 'bad');
        return;
    }
    setMsg(data.message || 'Renamed', 'ok');
    selectedPath = next;
    await loadFiles();
    if (selectedType === 'file') await selectEntry(next, 'file');
}

async function deleteEntry() {
    if (!selectedPath) {
        setMsg('Select a file or folder first', 'bad');
        return;
    }
    if (!window.confirm('Delete ' + selectedPath + '?')) return;
    const data = await apiCall('delete_entry', { path:selectedPath });
    if (!data.success) {
        setMsg(data.error || 'Delete failed', 'bad');
        return;
    }
    setMsg(data.message || 'Deleted', 'ok');
    selectedPath = '';
    selectedType = '';
    document.getElementById('editor').value = '';
    document.getElementById('openPath').textContent = 'no selection';
    await loadFiles();
}

async function createWorkspace() {
    await withPending('create', async () => {
        const data = await apiCall('create');
        if (!data.success) {
            setMsg(data.error || 'Create workspace failed', 'bad');
            return;
        }
        setMsg(data.message || 'Workspace created', 'ok');
        await refreshAll();
        await connectWatch();
    });
}

async function startBot() {
    await withPending('start', async () => {
        setMsg('Starting bot container...', '');
        const data = await apiCall('start');
        if (!data.success) {
            setMsg(data.error || 'Failed to start', 'bad');
            return;
        }
        setMsg(data.message || 'Started', 'ok');
        await refreshAll();
        await connectTerminal();
    });
}

async function stopBot() {
    await withPending('stop', async () => {
        const data = await apiCall('stop');
        if (!data.success) {
            setMsg(data.error || 'Failed to stop', 'bad');
            return;
        }
        disconnectTerminal(true);
        setMsg(data.message || 'Stopped', 'ok');
        await refreshAll();
    });
}

async function restartBot() {
    await withPending('restart', async () => {
        const data = await apiCall('restart');
        if (!data.success) {
            setMsg(data.error || 'Failed to restart', 'bad');
            return;
        }
        disconnectTerminal(true);
        setMsg(data.message || 'Restarted', 'ok');
        await refreshAll();
        await connectTerminal();
    });
}

async function manualRestart() {
    await withPending('manual_restart', async () => {
        setMsg('Manual restart in progress...', '');
        const data = await apiCall('manual_restart');
        if (!data.success) {
            setMsg(data.error || 'Manual restart failed', 'bad');
            await loadStatus();
            return;
        }
        disconnectTerminal(true);
        setMsg(data.message || 'Manual restart complete', 'ok');
        await refreshAll();
        await connectTerminal();
    });
}

async function enableSftp() {
    await withPending('sftp_enable', async () => {
        const data = await apiCall('sftp_enable');
        if (!data.success) {
            setMsg(data.error || 'Failed to enable SFTP', 'bad');
            return;
        }
        renderSftp(data.sftp || {});
        setMsg('SFTP enabled', 'ok');
        await loadStatus();
    });
}

async function disableSftp() {
    await withPending('sftp_disable', async () => {
        const data = await apiCall('sftp_disable');
        if (!data.success) {
            setMsg(data.error || 'Failed to disable SFTP', 'bad');
            return;
        }
        renderSftp(data.sftp || {});
        setMsg('SFTP disabled', 'ok');
        await loadStatus();
    });
}

async function connectTerminal() {
    if (terminalSocket && terminalSocket.readyState === WebSocket.OPEN) {
        setMsg('Terminal already connected', 'ok');
        updateControlStates();
        return;
    }
    await withPending('terminal_connect', async () => {
        const auth = await apiCall('ws_auth', {}, 'GET');
        if (!auth.success) {
            setMsg(auth.error || 'Failed to authorize terminal', 'bad');
            return;
        }
        terminalSocket = new WebSocket(auth.url + '?token=' + encodeURIComponent(auth.token));
        setTerminalState('connecting');
        document.getElementById('terminalOut').textContent = 'Connecting to container shell...\n';
        updateControlStates();
        terminalSocket.onopen = () => { document.getElementById('terminalOut').textContent = ''; setTerminalState('connected'); setMsg('Terminal connected', 'ok'); updateControlStates(); };
        terminalSocket.onmessage = (event) => handleSocketMessage(event, 'terminal');
        terminalSocket.onclose = () => { setTerminalState(state?.restarting ? 'restarting' : (state?.running ? 'ready' : 'offline')); terminalSocket = null; updateControlStates(); };
        terminalSocket.onerror = () => { setTerminalState('error'); setMsg('Terminal websocket failed to connect', 'bad'); updateControlStates(); };
    });
}

async function connectWatch() {
    if (watchSocket && watchSocket.readyState === WebSocket.OPEN) {
        return;
    }
    const auth = await apiCall('watch_auth', {}, 'GET');
    if (!auth.success) {
        document.getElementById('sWatch').textContent = state?.workspace_exists ? 'ready' : 'offline';
        return;
    }
    watchSocket = new WebSocket(auth.url + '?token=' + encodeURIComponent(auth.token));
    document.getElementById('sWatch').textContent = 'connecting';
    watchSocket.onopen = () => { document.getElementById('sWatch').textContent = 'connected'; };
    watchSocket.onmessage = (event) => handleSocketMessage(event, 'watch');
    watchSocket.onclose = () => { watchSocket = null; document.getElementById('sWatch').textContent = state?.workspace_exists ? 'ready' : 'offline'; };
    watchSocket.onerror = () => { document.getElementById('sWatch').textContent = 'error'; };
}

function handleSocketMessage(event, kind) {
    let payload = null;
    try { payload = JSON.parse(event.data); } catch (_err) { if (kind === 'terminal') appendTerminal(String(event.data || '')); return; }
    if (payload.type === 'status') {
        if (kind === 'terminal') {
            if ((payload.message || '') === 'terminal connected') {
                appendTerminal('Connected successfully. Interactive shell ready.\n');
            } else {
                appendTerminal('[status] ' + (payload.message || 'ok') + '\n');
            }
        }
        return;
    }
    if (payload.type === 'output') {
        appendTerminal(payload.data || '');
        return;
    }
    if (payload.type === 'file_sync') {
        if (payload.hash && payload.hash !== lastWatchHash) {
            lastWatchHash = payload.hash;
            refreshCurrentFileAfterExternalChange();
        }
        return;
    }
    if (payload.type === 'error') {
        if (kind === 'terminal') appendTerminal('\n[error] ' + (payload.message || 'Unknown error') + '\n');
        setMsg(payload.message || 'Socket error', 'bad');
        return;
    }
}

function disconnectTerminal(silent = false) {
    if (terminalSocket) { terminalSocket.close(); terminalSocket = null; }
    setTerminalState(state?.restarting ? 'restarting' : (state?.running ? 'ready' : 'offline'));
    if (!silent) setMsg('Terminal disconnected', 'ok');
    updateControlStates();
}

function sendTerminalLine() {
    const input = document.getElementById('terminalInput');
    const line = input.value;
    if (!line.trim()) return;
    if (!terminalSocket || terminalSocket.readyState !== WebSocket.OPEN) { setMsg('Open the terminal first', 'bad'); return; }
    terminalSocket.send(JSON.stringify({ type:'input', data: line + '\n' }));
    appendTerminal('$ ' + line + '\n');
    input.value = '';
}

async function refreshAll() {
    await withPending('refresh', async () => {
        const ok = await loadStatus();
        if (!ok) return;
        await loadFiles();
        await connectWatch();
    });
}

async function boot() {
    updateControlStates();
    const ok = await loadStatus();
    if (!ok) return;
    await loadFiles();
    await connectWatch();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(async () => { await loadStatus(); }, 4000);
}

document.getElementById('terminalInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendTerminalLine(); });
document.getElementById('aiScope').addEventListener('change', () => {
    const custom = document.getElementById('aiScope').value === 'custom';
    document.getElementById('aiCustomWrap').style.display = custom ? 'block' : 'none';
});
boot();
</script>
</body>
</html>
