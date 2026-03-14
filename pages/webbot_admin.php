<?php
session_start();
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['ai.cloudhavenx.com', 'www.ai.cloudhavenx.com'], true);
$forkModeEnv = getenv('FORK_MODE') ?: ($_ENV['FORK_MODE'] ?? '');
$isForkMode = ($forkModeEnv === '1') || ($host !== '' && !$isPrimaryHost);
$devUsername = 'developer';
if (!$isForkMode && (empty($_SESSION['username']) || $_SESSION['username'] !== $devUsername) && empty($_SESSION['is_admin'])) {
    header('Location: /'); exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Bot Instances - Admin</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#0a0a0f; --surface:#111118; --border:#1e1e2e; --text:#e2e8f0; --muted:#64748b; --accent:#ff6b35; --ok:#22c55e; --bad:#ef4444; }
        *{box-sizing:border-box;margin:0;padding:0} body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        nav{padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,.92);backdrop-filter:blur(12px);z-index:10}
        .nav-logo{height:28px;mix-blend-mode:lighten}.nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--muted)}.nav-links{display:flex;gap:8px;margin-left:auto}.nav-link{color:var(--muted);text-decoration:none;font-size:12px;border:1px solid var(--border);padding:5px 12px;border-radius:20px}.nav-link:hover{border-color:var(--accent);color:#ffb08e}
        .page{max-width:1320px;margin:0 auto;padding:32px 20px 80px}.hero{margin-bottom:18px}.hero h1{font-family:'Syne',sans-serif;font-size:28px;font-weight:800}.hero p{margin-top:6px;font-size:12px;color:var(--muted)}
        .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.search{flex:1;min-width:220px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:10px 12px;font-family:'DM Mono',monospace}.btn{padding:9px 12px;border-radius:10px;border:1px solid #2b2b40;background:#171724;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;cursor:pointer}.btn:hover{border-color:var(--accent)}.btn.bad{border-color:rgba(239,68,68,.35);color:#ffb4b4}.btn.ok{border-color:rgba(34,197,94,.35);color:#a6f4c5}
        .table{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}.row{display:grid;grid-template-columns:1.1fr 1fr .8fr .8fr .8fr 1.4fr;gap:10px;padding:12px 14px;border-bottom:1px solid rgba(30,30,46,.7);align-items:center}.row:last-child{border-bottom:none}.head{background:rgba(255,107,53,.06);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}.cell{font-size:12px}.muted{color:var(--muted)}.pill{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;border:1px solid var(--border)}.pill.ok{color:#9ef0bb;border-color:rgba(34,197,94,.35)}.pill.bad{color:#ffb4b4;border-color:rgba(239,68,68,.35)}.pill.warn{color:#ffd7a6;border-color:rgba(245,158,11,.35)}
        .actions{display:flex;gap:6px;flex-wrap:wrap}.empty{padding:30px;color:var(--muted);text-align:center}.toast{position:fixed;bottom:20px;right:20px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:12px;opacity:0;transform:translateY(12px);transition:all .2s}.toast.show{opacity:1;transform:translateY(0)}
        @media(max-width:1100px){.row{grid-template-columns:1fr}.head{display:none}.cell{padding:2px 0}.cell::before{content:attr(data-label);display:block;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}}
    </style>
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-title">/ Web Bot Admin</span>
    <div class="nav-links">
        <a href="/pages/admin.php" class="nav-link">Admin</a>
        <a href="/pages/webbot.php" class="nav-link">User Panel</a>
    </div>
</nav>
<div class="page">
    <div class="hero"><h1>Web Bot Instances</h1><p>Inspect every workspace, container, and SFTP endpoint. Start, stop, enable SFTP, or delete an instance from here.</p></div>
    <div class="toolbar">
        <input id="search" class="search" placeholder="Search username, email, workspace, plan" oninput="render()">
        <button class="btn" onclick="loadInstances()">Refresh</button>
    </div>
    <div class="table" id="table">
        <div class="row head">
            <div>User</div><div>Workspace</div><div>Bot</div><div>SFTP</div><div>Plan</div><div>Actions</div>
        </div>
        <div class="empty">Loading...</div>
    </div>
</div>
<div class="toast" id="toast"></div>
<script>
const API='/api/webbot.php';
let instances=[];
function showToast(text){const t=document.getElementById('toast');t.textContent=text;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}
async function api(action,payload={},method='POST'){if(method==='GET'){const qs=new URLSearchParams({action,...payload});return fetch(API+'?'+qs.toString(),{credentials:'same-origin'}).then(r=>r.json())} const fd=new FormData();fd.append('action',action);Object.entries(payload).forEach(([k,v])=>fd.append(k,v));return fetch(API,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json())}
async function loadInstances(){const data=await api('admin_list_instances',{},'GET').catch(()=>null);if(!data?.success){document.getElementById('table').innerHTML='<div class="empty">Failed to load instances.</div>';return;}instances=data.instances||[];render();}
function pill(text,kind){return '<span class="pill '+kind+'">'+text+'</span>'}
function render(){const q=document.getElementById('search').value.toLowerCase();const filtered=instances.filter(i=>!q||[i.username,i.email,i.workspace,i.plan].join(' ').toLowerCase().includes(q));const table=document.getElementById('table');if(!filtered.length){table.innerHTML='<div class="empty">No matching instances.</div>';return;}table.innerHTML='<div class="row head"><div>User</div><div>Workspace</div><div>Bot</div><div>SFTP</div><div>Plan</div><div>Actions</div></div>'+filtered.map(i=>{const bot=i.running?pill('running','ok'):(i.container_exists?pill('stopped','warn'):pill('missing','bad'));const sftp=i.sftp?.enabled?(i.sftp.running?pill('enabled:'+i.sftp.port,'ok'):pill('configured','warn')):pill('disabled','bad');return '<div class="row">'
+'<div class="cell" data-label="User"><div>'+i.username+'</div><div class="muted">'+(i.email||'')+'</div><div class="muted">#'+i.user_id+'</div></div>'
+'<div class="cell" data-label="Workspace"><div>'+i.workspace+'</div><div class="muted">'+i.file_count+' entries</div></div>'
+'<div class="cell" data-label="Bot">'+bot+'</div>'
+'<div class="cell" data-label="SFTP">'+sftp+(i.sftp?.enabled?'<div class="muted" style="margin-top:4px">'+i.sftp.username+'@'+i.sftp.host+'</div>':'')+'</div>'
+'<div class="cell" data-label="Plan">'+(i.plan||'-')+'</div>'
+'<div class="cell" data-label="Actions"><div class="actions">'
+'<button class="btn ok" onclick="act(\'admin_start_instance\','+i.user_id+')">Start</button>'
+'<button class="btn" onclick="act(\'admin_restart_instance\','+i.user_id+')">Restart</button>'
+'<button class="btn" onclick="act(\'admin_stop_instance\','+i.user_id+')">Stop</button>'
+'<button class="btn" onclick="act(\''+(i.sftp?.enabled?'admin_sftp_disable':'admin_sftp_enable')+'\','+i.user_id+')">'+(i.sftp?.enabled?'Disable SFTP':'Enable SFTP')+'</button>'
+'<button class="btn bad" onclick="destroyInstance('+i.user_id+',\''+i.username.replace(/'/g,"\\'")+'\')">Delete</button>'
+'</div></div></div>'}).join('')}
async function act(action,userId){const data=await api(action,{user_id:userId}).catch(()=>null);if(!data?.success){showToast(data?.error||'Action failed');return;}showToast(data.message||'Updated');await loadInstances()}
async function destroyInstance(userId,username){if(!confirm('Delete '+username+'\'s instance? This removes containers and workspace files.'))return;await act('admin_delete_instance',userId)}
loadInstances();
</script>
</body>
</html>
