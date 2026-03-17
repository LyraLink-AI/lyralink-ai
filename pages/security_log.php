<?php
require_once __DIR__ . '/../api/security.php';
secureSessionInit();
if (empty($_SESSION['is_admin'])) { header('Location: /'); exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Security Log — Lyralink</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;--accent:#7c3aed;--accent-light:#a78bfa;--text:#e2e8f0;--muted:#64748b;--success:#22c55e;--warn:#f59e0b;--error:#ef4444;--orange:#f97316}
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        nav{padding:12px 28px;display:flex;align-items:center;gap:12px;background:rgba(10,10,15,0.95);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10}
        .nav-logo{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--accent-light)}
        .nav-link{margin-left:auto;font-size:11px;color:var(--muted);text-decoration:none;padding:4px 12px;border:1px solid var(--border);border-radius:20px}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}
        .page{max-width:1300px;margin:0 auto;padding:32px 20px 80px}
        h1{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:24px}

        /* STATS */
        .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:28px}
        .stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px}
        .stat-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .stat-val{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}

        /* FILTERS */
        .toolbar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
        .filter-btn{font-size:11px;padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:none;color:var(--muted);cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.15s}
        .filter-btn:hover,.filter-btn.active{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,0.08)}
        .search{flex:1;min-width:160px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:6px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none}
        .search:focus{border-color:var(--accent)}

        /* TABLE */
        .table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
        table{width:100%;border-collapse:collapse}
        thead{background:rgba(124,58,237,0.05);border-bottom:1px solid var(--border)}
        th{padding:10px 14px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);text-align:left;white-space:nowrap}
        td{padding:11px 14px;font-size:12px;border-bottom:1px solid rgba(30,30,46,0.5);vertical-align:middle}
        tr:last-child td{border-bottom:none}
        tr:hover td{background:rgba(124,58,237,0.02)}

        .badge{padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700}
        .ev-login_success{background:rgba(34,197,94,0.1);color:var(--success);border:1px solid rgba(34,197,94,0.2)}
        .ev-login_fail{background:rgba(239,68,68,0.1);color:var(--error);border:1px solid rgba(239,68,68,0.2)}
        .ev-register{background:rgba(124,58,237,0.1);color:var(--accent-light);border:1px solid rgba(124,58,237,0.2)}
        .ev-admin_access_denied{background:rgba(239,68,68,0.15);color:var(--error);border:1px solid rgba(239,68,68,0.3)}
        .ev-default{background:rgba(100,116,139,0.1);color:var(--muted);border:1px solid var(--border)}

        .ip-cell{font-family:'DM Mono',monospace;font-size:11px;color:var(--muted)}
        .detail-cell{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:var(--muted)}
        .empty{text-align:center;padding:48px;color:var(--muted)}

        /* BRUTE FORCE PANEL */
        .alert-box{background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.25);border-radius:12px;padding:16px 18px;margin-bottom:24px;display:none}
        .alert-box.show{display:block}
        .alert-title{color:var(--error);font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:10px}
        .blocked-ip{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(239,68,68,0.1);font-size:12px}
        .blocked-ip:last-child{border-bottom:none}
        .btn-unblock{background:none;border:1px solid rgba(239,68,68,0.3);color:var(--error);border-radius:5px;padding:2px 8px;font-size:10px;cursor:pointer;font-family:'DM Mono',monospace}
        .btn-unblock:hover{background:rgba(239,68,68,0.1)}
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <span class="nav-logo">⚡ Lyralink</span>
    <span style="color:var(--border)">/</span>
    <span style="font-size:12px;color:var(--muted)">Security Log</span>
    <a href="/pages/admin.php" class="nav-link">← Admin</a>
</nav>

<div class="page">
    <h1>Security Log</h1>

    <!-- STATS -->
    <div class="stats" id="statsRow">
        <div class="stat"><div class="stat-label">Total Events (24h)</div><div class="stat-val" id="s-total" style="color:var(--accent-light)">—</div></div>
        <div class="stat"><div class="stat-label">Login Failures (24h)</div><div class="stat-val" id="s-fail" style="color:var(--error)">—</div></div>
        <div class="stat"><div class="stat-label">Registrations (24h)</div><div class="stat-val" id="s-reg" style="color:var(--success)">—</div></div>
        <div class="stat"><div class="stat-label">Admin Blocked</div><div class="stat-val" id="s-admin" style="color:var(--warn)">—</div></div>
        <div class="stat"><div class="stat-label">Unique IPs (24h)</div><div class="stat-val" id="s-ips" style="color:var(--orange)">—</div></div>
    </div>

    <!-- BRUTE FORCE ALERT -->
    <div class="alert-box" id="bruteAlert">
        <div class="alert-title">⚠ Suspicious IPs (5+ failed logins in 15 min)</div>
        <div id="bruteList"></div>
    </div>

    <!-- FILTER BAR -->
    <div class="toolbar">
        <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
        <button class="filter-btn" onclick="setFilter('login_fail',this)">Login Fails</button>
        <button class="filter-btn" onclick="setFilter('login_success',this)">Logins</button>
        <button class="filter-btn" onclick="setFilter('register',this)">Registrations</button>
        <button class="filter-btn" onclick="setFilter('admin_access_denied',this)">Admin Blocks</button>
        <input class="search" id="searchInput" placeholder="Filter by IP or detail…" oninput="render()">
        <button class="filter-btn" onclick="load()">↻ Refresh</button>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Time</th><th>Event</th><th>IP</th><th>User ID</th><th>Detail</th></tr></thead>
            <tbody id="tbody"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
let events = [];
let filter  = 'all';

async function load() {
    const r    = await fetch('/api/security_log_api.php').catch(() => null);
    const data = r ? await r.json().catch(() => null) : null;
    if (!data?.success) { document.getElementById('tbody').innerHTML = '<tr><td colspan="5" class="empty">Failed to load.</td></tr>'; return; }

    events = data.events || [];

    // Stats
    const now24 = events.filter(e => new Date(e.created_at) > new Date(Date.now() - 86400000));
    document.getElementById('s-total').textContent = now24.length;
    document.getElementById('s-fail').textContent  = now24.filter(e => e.event_type === 'login_fail').length;
    document.getElementById('s-reg').textContent   = now24.filter(e => e.event_type === 'register').length;
    document.getElementById('s-admin').textContent = now24.filter(e => e.event_type === 'admin_access_denied').length;
    document.getElementById('s-ips').textContent   = new Set(now24.map(e => e.ip)).size;

    // Brute force detection: IPs with 5+ login_fail in last 15 min
    const cutoff   = Date.now() - 900000;
    const failRecent = now24.filter(e => e.event_type === 'login_fail' && new Date(e.created_at) > cutoff);
    const ipCounts = {};
    failRecent.forEach(e => { ipCounts[e.ip] = (ipCounts[e.ip] || 0) + 1; });
    const suspiciousIps = Object.entries(ipCounts).filter(([,n]) => n >= 5);

    const alertBox = document.getElementById('bruteAlert');
    if (suspiciousIps.length > 0) {
        alertBox.classList.add('show');
        document.getElementById('bruteList').innerHTML = suspiciousIps.map(([ip, n]) =>
            `<div class="blocked-ip"><span>🚨 <strong>${esc(ip)}</strong> — ${n} failures in last 15 min</span></div>`
        ).join('');
    } else {
        alertBox.classList.remove('show');
    }

    render();
}

function setFilter(f, el) {
    filter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    render();
}

function render() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    let rows = events;
    if (filter !== 'all') rows = rows.filter(e => e.event_type === filter);
    if (search) rows = rows.filter(e => (e.ip||'').includes(search) || (e.detail||'').toLowerCase().includes(search));

    if (!rows.length) { document.getElementById('tbody').innerHTML = '<tr><td colspan="5" class="empty">No events found.</td></tr>'; return; }

    document.getElementById('tbody').innerHTML = rows.slice(0, 500).map(e => {
        const cls = 'ev-' + (e.event_type || 'default');
        const d   = new Date(e.created_at);
        const ts  = d.toLocaleString();
        return `<tr>
            <td style="white-space:nowrap;font-size:11px;color:var(--muted)">${ts}</td>
            <td><span class="badge ${cls}">${esc(e.event_type)}</span></td>
            <td class="ip-cell">${esc(e.ip||'—')}</td>
            <td style="color:var(--muted)">${e.user_id||'—'}</td>
            <td class="detail-cell" title="${esc(e.detail||'')}">${esc(e.detail||'—')}</td>
        </tr>`;
    }).join('');
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

load();
</script>
</body>
</html>