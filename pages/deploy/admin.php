<?php
session_start();
if (empty($_SESSION['is_admin'])) {
    header('Location: /'); exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosting Admin — Lyralink</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f; --surface:#111118; --surface2:#16161f; --border:#1e1e2e;
            --accent:#7c3aed; --accent-glow:rgba(124,58,237,0.2); --accent-light:#a78bfa;
            --text:#e2e8f0; --text-muted:#64748b; --text-dim:#94a3b8;
            --success:#22c55e; --success-bg:rgba(34,197,94,0.1); --success-border:rgba(34,197,94,0.25);
            --error:#ef4444; --warn:#f59e0b;
            --orange:#f97316;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        body::before{content:'';position:fixed;top:-200px;left:20%;width:600px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,0.06) 0%,transparent 65%);pointer-events:none}

        nav{position:sticky;top:0;z-index:100;padding:12px 28px;display:flex;align-items:center;gap:12px;background:rgba(10,10,15,0.95);backdrop-filter:blur(16px);border-bottom:1px solid var(--border)}
        .nav-logo{height:26px;mix-blend-mode:lighten}
        .nav-sep{color:var(--border)}
        .nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text-muted)}
        .nav-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .nav-link{font-size:11px;color:var(--text-muted);text-decoration:none;padding:4px 10px;border:1px solid var(--border);border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

        .page{max-width:1200px;margin:0 auto;padding:36px 24px 80px;position:relative;z-index:1}

        /* STATS ROW */
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:32px}
        .stat{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
        .stat-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
        .stat-val{font-family:'Syne',sans-serif;font-size:26px;font-weight:800}
        .stat-val.green{color:var(--success)}
        .stat-val.purple{color:var(--accent-light)}
        .stat-val.orange{color:var(--orange)}

        /* FILTER BAR */
        .filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
        .filter-btn{font-size:11px;padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:none;color:var(--text-muted);cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.2s}
        .filter-btn:hover,.filter-btn.active{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,0.08)}
        .search-input{flex:1;min-width:180px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:6px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;transition:border-color 0.2s}
        .search-input:focus{border-color:var(--accent)}
        .search-input::placeholder{color:var(--text-muted)}

        /* TABLE */
        .table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden}
        table{width:100%;border-collapse:collapse}
        thead{background:rgba(124,58,237,0.06);border-bottom:1px solid var(--border)}
        th{padding:11px 14px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);text-align:left;font-weight:600;white-space:nowrap}
        td{padding:13px 14px;font-size:12px;border-bottom:1px solid rgba(30,30,46,0.6);vertical-align:middle}
        tr:last-child td{border-bottom:none}
        tr:hover td{background:rgba(124,58,237,0.03)}

        .badge{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
        .badge-active{background:var(--success-bg);color:var(--success);border:1px solid var(--success-border)}
        .badge-pending{background:rgba(245,158,11,0.1);color:var(--warn);border:1px solid rgba(245,158,11,0.3)}
        .badge-cancelled{background:rgba(100,116,139,0.1);color:var(--text-muted);border:1px solid var(--border)}
        .badge-suspended{background:rgba(239,68,68,0.1);color:var(--error);border:1px solid rgba(239,68,68,0.3)}
        .badge-small{background:rgba(34,197,94,0.1);color:var(--success);border:1px solid rgba(34,197,94,0.2)}
        .badge-medium{background:rgba(124,58,237,0.1);color:var(--accent-light);border:1px solid rgba(124,58,237,0.2)}
        .badge-large{background:rgba(249,115,22,0.1);color:var(--orange);border:1px solid rgba(249,115,22,0.2)}

        /* PLESK SETUP CARD */
        .plesk-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 14px;font-size:11px;line-height:1.8;color:var(--text-muted);white-space:pre;font-family:'DM Mono',monospace;overflow-x:auto}
        .plesk-card .hl{color:var(--accent-light)}
        .plesk-card .hl-green{color:var(--success)}

        /* ACTION BUTTONS */
        .btn{padding:5px 12px;border-radius:7px;font-family:'DM Mono',monospace;font-size:11px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;white-space:nowrap}
        .btn-purple{background:rgba(124,58,237,0.15);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}
        .btn-purple:hover{background:rgba(124,58,237,0.25)}
        .btn-green{background:var(--success-bg);color:var(--success);border:1px solid var(--success-border)}
        .btn-green:hover{background:rgba(34,197,94,0.2)}
        .btn-red{background:rgba(239,68,68,0.1);color:var(--error);border:1px solid rgba(239,68,68,0.25)}
        .btn-red:hover{background:rgba(239,68,68,0.2)}
        .btn-muted{background:none;color:var(--text-muted);border:1px solid var(--border)}
        .btn-muted:hover{border-color:var(--accent);color:var(--accent-light)}
        .actions{display:flex;gap:6px;flex-wrap:wrap}

        /* DETAIL DRAWER */
        .drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;opacity:0;pointer-events:none;transition:opacity 0.2s}
        .drawer-overlay.open{opacity:1;pointer-events:all}
        .drawer{position:fixed;top:0;right:0;width:480px;max-width:95vw;height:100%;background:var(--surface);border-left:1px solid var(--border);z-index:201;transform:translateX(100%);transition:transform 0.3s ease;display:flex;flex-direction:column;overflow:hidden}
        .drawer.open{transform:translateX(0)}
        .drawer-header{padding:20px 20px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
        .drawer-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;flex:1}
        .drawer-close{background:none;border:none;color:var(--text-muted);font-size:22px;cursor:pointer;padding:4px;line-height:1}
        .drawer-body{flex:1;overflow-y:auto;padding:20px}
        .drawer-section{margin-bottom:20px}
        .drawer-section-title{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .info-item{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 12px}
        .info-label{font-size:10px;color:var(--text-muted);margin-bottom:3px}
        .info-val{font-size:12px;font-weight:600;word-break:break-all}
        .copy-row{display:flex;align-items:center;gap:6px}
        .copy-btn{background:none;border:1px solid var(--border);color:var(--text-muted);border-radius:4px;padding:2px 6px;font-size:10px;cursor:pointer;font-family:'DM Mono',monospace;flex-shrink:0}
        .copy-btn:hover{border-color:var(--accent);color:var(--accent-light)}

        /* PLESK INSTRUCTIONS */
        .plesk-instructions{background:var(--bg);border:1px solid rgba(124,58,237,0.25);border-radius:10px;padding:14px 16px;font-size:11px;line-height:1.9}
        .plesk-instructions .step-num{display:inline-block;width:18px;height:18px;border-radius:50%;background:rgba(124,58,237,0.2);color:var(--accent-light);text-align:center;line-height:18px;font-size:10px;font-weight:700;margin-right:6px;flex-shrink:0}
        .plesk-step{display:flex;align-items:flex-start;gap:0;margin-bottom:8px}
        .mono{font-family:'DM Mono',monospace;background:rgba(124,58,237,0.1);color:var(--accent-light);padding:1px 6px;border-radius:4px;font-size:11px}

        /* TOAST */
        .toast{position:fixed;bottom:20px;right:20px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:12px;z-index:999;transform:translateY(60px);opacity:0;transition:all 0.3s;max-width:280px}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{border-color:var(--success-border);color:var(--success)}
        .toast.error{border-color:rgba(239,68,68,0.3);color:var(--error)}

        .spinner{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,0.2);border-top-color:currentColor;border-radius:50%;animation:spin 0.7s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        .empty{text-align:center;padding:48px;color:var(--text-muted);font-size:13px}

        @media(max-width:900px){
            .stats{grid-template-columns:1fr 1fr}
            .drawer{width:100%;max-width:100%}
            th:nth-child(n+5),td:nth-child(n+5){display:none}
        }
        @media(max-width:600px){
            .stats{grid-template-columns:1fr 1fr}
            nav{padding:10px 14px}
            .page{padding:20px 12px 60px}
        }
    </style>
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-sep">/</span>
    <span class="nav-title">Hosting Admin</span>
    <div class="nav-right">
        <a href="/pages/admin.php" class="nav-link">← Admin</a>
        <a href="/pages/deploy/" class="nav-link">Deploy Page</a>
    </div>
</nav>

<div class="page">

    <!-- STATS -->
    <div class="stats" id="statsRow">
        <div class="stat"><div class="stat-label">Total Deployments</div><div class="stat-val purple" id="statTotal">—</div></div>
        <div class="stat"><div class="stat-label">Active</div><div class="stat-val green" id="statActive">—</div></div>
        <div class="stat"><div class="stat-label">Pending</div><div class="stat-val" style="color:var(--warn)" id="statPending">—</div></div>
        <div class="stat"><div class="stat-label">Monthly Revenue</div><div class="stat-val orange" id="statRevenue">—</div></div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
        <button class="filter-btn" onclick="setFilter('active',this)">Active</button>
        <button class="filter-btn" onclick="setFilter('pending',this)">Pending</button>
        <button class="filter-btn" onclick="setFilter('cancelled',this)">Cancelled</button>
        <input class="search-input" id="searchInput" placeholder="Search username, subdomain, ID…" oninput="renderTable()">
        <button class="btn btn-muted" onclick="loadDeployments()">↻ Refresh</button>
    </div>

    <!-- TABLE -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Tier</th>
                    <th>Status</th>
                    <th>Subdomain</th>
                    <th>Port</th>
                    <th>Node IP</th>
                    <th>Next Billing</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <tr><td colspan="9" class="empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>

</div>

<!-- DETAIL DRAWER -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-header">
        <div class="drawer-title" id="drawerTitle">Deployment Details</div>
        <button class="drawer-close" onclick="closeDrawer()">×</button>
    </div>
    <div class="drawer-body" id="drawerBody"></div>
</div>

<div class="toast" id="toast"></div>

<script>
const API        = '/api/pelican.php';
let allDeps      = [];
let pleskDomain  = 'cloudhavenx.com';
let panelUrl     = 'https://panel.cloudhavenx.com';
let activeFilter = 'all';

const TIER_PRICES = { small:5, medium:12, large:25 };

async function loadDeployments() {
    const res  = await fetch(API + '?action=admin_list').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;

    if (!data?.success) {
        document.getElementById('tableBody').innerHTML =
            '<tr><td colspan="9" class="empty">Failed to load — are you logged in as admin?</td></tr>';
        return;
    }

    allDeps     = data.deployments || [];
    pleskDomain = data.plesk_domain || pleskDomain;
    panelUrl    = data.panel_url    || panelUrl;

    updateStats();
    renderTable();
}

function updateStats() {
    const active   = allDeps.filter(d => d.status === 'active').length;
    const pending  = allDeps.filter(d => d.status === 'pending' || d.status === 'pending_approval').length;
    const revenue  = allDeps.filter(d => d.status === 'active').reduce((s,d) => s + (TIER_PRICES[d.tier]||0), 0);

    document.getElementById('statTotal').textContent   = allDeps.length;
    document.getElementById('statActive').textContent  = active;
    document.getElementById('statPending').textContent = pending;
    document.getElementById('statRevenue').textContent = '$' + revenue + '/mo';
}

function setFilter(f, el) {
    activeFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    renderTable();
}

function renderTable() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    let deps = allDeps;

    if (activeFilter !== 'all') deps = deps.filter(d => d.status === activeFilter || (activeFilter === 'pending' && d.status === 'pending_approval'));
    if (search) deps = deps.filter(d =>
        (d.username||'').toLowerCase().includes(search) ||
        (d.subdomain||'').toLowerCase().includes(search) ||
        String(d.id).includes(search) ||
        (d.email||'').toLowerCase().includes(search)
    );

    if (!deps.length) {
        document.getElementById('tableBody').innerHTML = '<tr><td colspan="9" class="empty">No deployments found</td></tr>';
        return;
    }

    document.getElementById('tableBody').innerHTML = deps.map(d => {
        const subFull = d.subdomain ? d.subdomain + '.' + pleskDomain : '—';
        const needsPlesk = d.status === 'active' && d.server_port && !d.subdomain_live;
        return `<tr>
            <td style="color:var(--text-muted)">#${d.id}</td>
            <td>
                <div style="font-weight:600">${esc(d.username||'—')}</div>
                <div style="font-size:10px;color:var(--text-muted)">${esc(d.email||'')}</div>
            </td>
            <td><span class="badge badge-${d.tier||'small'}">${(d.tier||'—').toUpperCase()}</span></td>
            <td><span class="badge badge-${d.status||'pending'}">${d.status||'—'}</span></td>
            <td style="font-size:11px;color:var(--accent-light)">${d.subdomain ? esc(subFull) : '<span style="color:var(--text-muted)">Not set</span>'}</td>
            <td style="font-family:'DM Mono',monospace">${d.server_port || '—'}</td>
            <td style="font-size:11px;color:var(--text-muted)">${esc(d.node_ip||'—')}</td>
            <td style="font-size:11px;color:var(--text-muted)">${d.next_billing||'—'}</td>
            <td>
                <div class="actions">
                    <button class="btn btn-purple" onclick="openDrawer(${d.id})">Details</button>
                    ${d.status === 'active' && d.server_port ? `<button class="btn btn-green" onclick="retryPlesk(${d.id})">↻ Plesk</button>` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
}

function openDrawer(id) {
    const d = allDeps.find(x => x.id == id);
    if (!d) return;

    const subFull     = d.subdomain ? d.subdomain + '.' + pleskDomain : null;
    const proxyTarget = d.node_ip && d.server_port ? `http://${d.node_ip}:${d.server_port}` : null;

    document.getElementById('drawerTitle').textContent = `Deployment #${d.id} — ${d.username}`;
    document.getElementById('drawerBody').innerHTML = `

        <div class="drawer-section">
            <div class="drawer-section-title">Deployment Info</div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Status</div><div class="info-val"><span class="badge badge-${d.status}">${d.status}</span></div></div>
                <div class="info-item"><div class="info-label">Tier</div><div class="info-val"><span class="badge badge-${d.tier}">${(d.tier||'').toUpperCase()}</span></div></div>
                <div class="info-item"><div class="info-label">User</div><div class="info-val">${esc(d.username)}</div></div>
                <div class="info-item"><div class="info-label">Email</div><div class="info-val">${esc(d.email||'—')}</div></div>
                <div class="info-item"><div class="info-label">Created</div><div class="info-val">${d.created_at||'—'}</div></div>
                <div class="info-item"><div class="info-label">Next Billing</div><div class="info-val">${d.next_billing||'—'}</div></div>
                <div class="info-item"><div class="info-label">PayPal Sub ID</div><div class="info-val copy-row"><span style="overflow:hidden;text-overflow:ellipsis">${esc(d.paypal_sub_id||'—')}</span>${d.paypal_sub_id?`<button class="copy-btn" onclick="cp('${esc(d.paypal_sub_id)}')">copy</button>`:''}</div></div>
                <div class="info-item"><div class="info-label">Pelican Server ID</div><div class="info-val">${d.pelican_server_id||'—'}</div></div>
            </div>
        </div>

        <div class="drawer-section">
            <div class="drawer-section-title">Server Details</div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Node IP</div><div class="info-val copy-row">${esc(d.node_ip||'—')}${d.node_ip?`<button class="copy-btn" onclick="cp('${esc(d.node_ip)}')">copy</button>`:''}</div></div>
                <div class="info-item"><div class="info-label">Server Port</div><div class="info-val copy-row">${d.server_port||'—'}${d.server_port?`<button class="copy-btn" onclick="cp('${d.server_port}')">copy</button>`:''}</div></div>
                <div class="info-item"><div class="info-label">Subdomain</div><div class="info-val">${subFull ? esc(subFull) : '—'}</div></div>
                <div class="info-item"><div class="info-label">Server UUID</div><div class="info-val" style="font-size:10px">${esc(d.server_uuid||'—')}</div></div>
            </div>
        </div>

        ${subFull && proxyTarget ? `
        <div class="drawer-section">
            <div class="drawer-section-title">Plesk Setup Instructions</div>
            <div class="plesk-instructions">
                <div class="plesk-step"><span class="step-num">1</span> In Plesk, go to <strong>Websites &amp; Domains</strong> → <strong>Add Subdomain</strong></div>
                <div class="plesk-step"><span class="step-num">2</span> Set subdomain name to <span class="mono">${esc(d.subdomain)}</span> under <span class="mono">${esc(pleskDomain)}</span></div>
                <div class="plesk-step"><span class="step-num">3</span> Go to <strong>Apache &amp; nginx Settings</strong> for that subdomain</div>
                <div class="plesk-step"><span class="step-num">4</span> Under <strong>Additional nginx directives</strong>, paste:</div>
                <div style="margin:8px 0 10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span style="font-size:10px;color:var(--text-muted)">nginx reverse proxy config</span>
                        <button class="copy-btn" onclick="cp(\`location / {\\n    proxy_pass ${proxyTarget};\\n    proxy_set_header Host \$host;\\n    proxy_set_header X-Real-IP \$remote_addr;\\n    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\\n    proxy_set_header X-Forwarded-Proto \$scheme;\\n    proxy_read_timeout 90;\\n}\`)">copy</button>
                    </div>
                    <pre class="plesk-card">location / {
    proxy_pass <span class="hl">${proxyTarget}</span>;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 90;
}</pre>
                </div>
                <div class="plesk-step"><span class="step-num">5</span> Click <strong>Apply</strong> — subdomain should go live within seconds</div>
            </div>
        </div>` : d.status === 'active' && !d.server_port ? `
        <div class="drawer-section">
            <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:10px;padding:12px 14px;font-size:12px;color:var(--warn)">
                ⚠ Server port not recorded — the Pelican allocation may not have been fetched correctly. Check the Pelican panel for the port assigned to server #${d.pelican_server_id}.
            </div>
        </div>` : ''}

        ${d.status === 'active' ? `
        <div class="drawer-section">
            <div class="drawer-section-title">Actions</div>
            <div class="actions">
                <a href="${panelUrl}" target="_blank" class="btn btn-purple">Open Pelican Panel ↗</a>
                ${d.server_port ? `<button class="btn btn-green" onclick="retryPlesk(${d.id})">↻ Re-run Plesk API</button>` : ''}
                <button class="btn btn-muted" onclick="cp('${esc(subFull||'')}')">Copy Subdomain</button>
            </div>
        </div>` : ''}
    `;

    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('drawer').classList.add('open');
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('drawer').classList.remove('open');
}

async function retryPlesk(id) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    const fd = new FormData();
    fd.append('action', 'admin_retry_plesk');
    fd.append('deployment_id', id);

    const res  = await fetch(API, { method:'POST', body:fd }).catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;

    btn.disabled = false;
    btn.textContent = '↻ Plesk';

    if (data?.success) {
        showToast('✓ Plesk subdomain created', 'success');
    } else {
        showToast('Plesk failed: ' + (data?.plesk?.step || data?.error || 'Unknown error'), 'error');
        console.error('Plesk error:', data);
    }
}

function cp(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied!', 'success'));
}

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadDeployments();
</script>
</body>
</html>