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
        .code-line { display:block; background:#0b0f18; border:1px solid #242b3c; border-radius:8px; padding:7px 9px; color:#dbe8ff; margin-top:6px; word-break:break-all; }
        .msg { margin-top:12px; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--surface); font-size:12px; color:var(--text-muted); min-height:38px; }
        .msg.ok { border-color:rgba(34,197,94,.5); color:#9ef0bb; }
        .msg.bad { border-color:rgba(239,68,68,.45); color:#ffb4b4; }
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
        <button class="btn accent" onclick="createWorkspace()">Create Workspace</button>
        <button class="btn ok" onclick="startBot()">Start Bot</button>
        <button class="btn" onclick="restartBot()">Restart</button>
        <button class="btn bad" onclick="stopBot()">Stop</button>
        <button class="btn" onclick="connectTerminal()">Open Terminal</button>
        <button class="btn" onclick="disconnectTerminal()">Close Terminal</button>
        <button class="btn" onclick="enableSftp()">Enable SFTP</button>
        <button class="btn bad" onclick="disableSftp()">Disable SFTP</button>
        <button class="btn" onclick="refreshAll()">Refresh</button>
    </div>
    <div class="grid">
        <section class="panel">
            <div class="panel-head"><div class="panel-title">Live Terminal</div><div class="panel-sub" id="terminalState">offline</div></div>
            <pre class="terminal" id="terminalOut">Terminal not connected.</pre>
            <div class="terminal-row">
                <input id="terminalInput" class="terminal-input" placeholder="Type a shell command and press Enter">
                <button class="btn" onclick="sendTerminalLine()">Send</button>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div class="panel-title">Files</div><div class="panel-sub" id="openPath">no selection</div></div>
            <div class="file-tools">
                <button class="btn" onclick="createEntry('file')">New File</button>
                <button class="btn" onclick="createEntry('dir')">New Folder</button>
                <button class="btn" onclick="renameEntry()">Rename</button>
                <button class="btn bad" onclick="deleteEntry()">Delete</button>
                <button class="btn" style="margin-left:auto" onclick="loadFiles()">Reload</button>
            </div>
            <div class="file-wrap">
                <div class="file-list" id="fileList"></div>
                <div class="editor-wrap">
                    <div class="editor-meta"><span id="editorHint">Select a file to edit.</span><button class="btn" style="margin-left:auto" onclick="saveFile()">Save File</button></div>
                    <textarea id="editor" class="editor" spellcheck="false" placeholder="Select a file from the left."></textarea>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div class="panel-title">SFTP + Notes</div></div>
            <div class="side-box">
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
    out.textContent = current + text;
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

function renderStatus(s) {
    state = s;
    document.getElementById('sUser').textContent = (s.user?.username || 'guest') + ' (' + (s.user?.plan || '-') + ')';
    document.getElementById('sWorkspace').textContent = s.workspace_exists ? 'Created' : 'Missing';
    document.getElementById('sContainer').textContent = s.running ? ('Running (' + s.container_name + ')') : (s.container_exists ? 'Stopped' : 'Not created');
    document.getElementById('sWatch').textContent = watchSocket && watchSocket.readyState === WebSocket.OPEN ? 'connected' : (s.workspace_exists ? 'ready' : 'offline');
    if (!terminalSocket || terminalSocket.readyState !== WebSocket.OPEN) {
        setTerminalState(s.running ? 'ready' : 'offline');
    }
    renderSftp(s.sftp || {});
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
    const data = await apiCall('list_files', {}, 'GET');
    if (!data.success) {
        setMsg(data.error || 'Failed to list files', 'bad');
        return;
    }
    const list = document.getElementById('fileList');
    const files = data.files || [];
    if (!files.length) {
        list.innerHTML = '<div style="padding:10px;color:#7e8aa3;font-size:11px">No files yet. Create workspace first.</div>';
        document.getElementById('editor').value = '';
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
}

async function selectEntry(path, type) {
    selectedPath = path;
    selectedType = type;
    document.getElementById('openPath').textContent = path + ' (' + type + ')';
    document.getElementById('editorHint').textContent = type === 'file' ? 'Editing file' : 'Folders cannot be edited directly';
    if (type !== 'file') {
        document.getElementById('editor').value = '';
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
    const data = await apiCall('create');
    if (!data.success) {
        setMsg(data.error || 'Create workspace failed', 'bad');
        return;
    }
    setMsg(data.message || 'Workspace created', 'ok');
    await refreshAll();
    await connectWatch();
}

async function startBot() {
    setMsg('Starting bot container...', '');
    const data = await apiCall('start');
    if (!data.success) {
        setMsg(data.error || 'Failed to start', 'bad');
        return;
    }
    setMsg(data.message || 'Started', 'ok');
    await refreshAll();
    await connectTerminal();
}

async function stopBot() {
    const data = await apiCall('stop');
    if (!data.success) {
        setMsg(data.error || 'Failed to stop', 'bad');
        return;
    }
    disconnectTerminal(true);
    setMsg(data.message || 'Stopped', 'ok');
    await refreshAll();
}

async function restartBot() {
    const data = await apiCall('restart');
    if (!data.success) {
        setMsg(data.error || 'Failed to restart', 'bad');
        return;
    }
    disconnectTerminal(true);
    setMsg(data.message || 'Restarted', 'ok');
    await refreshAll();
    await connectTerminal();
}

async function enableSftp() {
    const data = await apiCall('sftp_enable');
    if (!data.success) {
        setMsg(data.error || 'Failed to enable SFTP', 'bad');
        return;
    }
    renderSftp(data.sftp || {});
    setMsg('SFTP enabled', 'ok');
    await loadStatus();
}

async function disableSftp() {
    const data = await apiCall('sftp_disable');
    if (!data.success) {
        setMsg(data.error || 'Failed to disable SFTP', 'bad');
        return;
    }
    renderSftp(data.sftp || {});
    setMsg('SFTP disabled', 'ok');
    await loadStatus();
}

async function connectTerminal() {
    if (terminalSocket && terminalSocket.readyState === WebSocket.OPEN) {
        setMsg('Terminal already connected', 'ok');
        return;
    }
    const auth = await apiCall('ws_auth', {}, 'GET');
    if (!auth.success) {
        setMsg(auth.error || 'Failed to authorize terminal', 'bad');
        return;
    }
    terminalSocket = new WebSocket(auth.url + '?token=' + encodeURIComponent(auth.token));
    setTerminalState('connecting');
    terminalSocket.onopen = () => { document.getElementById('terminalOut').textContent = ''; setTerminalState('connected'); setMsg('Terminal connected', 'ok'); };
    terminalSocket.onmessage = (event) => handleSocketMessage(event, 'terminal');
    terminalSocket.onclose = () => { setTerminalState(state?.running ? 'ready' : 'offline'); terminalSocket = null; };
    terminalSocket.onerror = () => { setTerminalState('error'); setMsg('Terminal websocket failed to connect', 'bad'); };
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
    setTerminalState(state?.running ? 'ready' : 'offline');
    if (!silent) setMsg('Terminal disconnected', 'ok');
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
    const ok = await loadStatus();
    if (!ok) return;
    await loadFiles();
    await connectWatch();
}

async function boot() {
    const ok = await loadStatus();
    if (!ok) return;
    await loadFiles();
    await connectWatch();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(async () => { await loadStatus(); }, 4000);
}

document.getElementById('terminalInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendTerminalLine(); });
boot();
</script>
</body>
</html>
