<?php
session_start();
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isPrimaryHost = in_array($host, ['lyralinkai.com', 'www.lyralinkai.com'], true);
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
    <title>Lyralink Infrastructure — Operator Admin</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;
            --accent:#7c3aed;--accent-glow:rgba(124,58,237,0.3);--accent-light:#a78bfa;
            --text:#e2e8f0;--text-muted:#64748b;--text-dim:#94a3b8;
            --success:#22c55e;--error:#ef4444;--warn:#f59e0b;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html,body{scrollbar-width:thin;scrollbar-color:var(--accent) rgba(14,14,24,0.9)}
        *{scrollbar-width:thin;scrollbar-color:var(--accent) rgba(14,14,24,0.9)}
        *::-webkit-scrollbar{width:10px;height:10px}
        *::-webkit-scrollbar-track{background:rgba(14,14,24,0.9);border:1px solid var(--border);border-radius:999px}
        *::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--accent-light),var(--accent));border-radius:999px;border:2px solid rgba(14,14,24,0.95)}
        *::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,#c4b5fd,var(--accent))}
        *::-webkit-scrollbar-corner{background:transparent}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        body::before{content:'';position:fixed;top:-200px;left:30%;width:600px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%);pointer-events:none}

        nav{padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,0.92);backdrop-filter:blur(12px);z-index:10}
        .nav-logo{height:28px;width:auto;mix-blend-mode:lighten}
        .nav-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700}
        .nav-title span{color:var(--accent-light)}
        .nav-links{display:flex;gap:8px;margin-left:auto}
        .nav-link{color:var(--text-muted);text-decoration:none;font-size:12px;border:1px solid var(--border);padding:5px 12px;border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

        .container{max-width:1000px;margin:0 auto;padding:32px 24px 80px;position:relative;z-index:1}
        h1{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:4px}
        h1 span{color:var(--accent-light)}
        .page-sub{font-size:12px;color:var(--text-muted);margin-bottom:24px}
        .page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
        .view-toggle{display:flex;gap:8px;align-items:center}
        .view-btn{padding:7px 12px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);font-size:11px;cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.2s}
        .view-btn.active{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,.14)}

        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
        .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;position:relative;overflow:hidden}
        .stat-card::after{content:'';position:absolute;right:-30px;bottom:-38px;width:100px;height:100px;background:radial-gradient(circle,rgba(124,58,237,.16),transparent 70%)}
        .stat-label{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
        .stat-value{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--text)}
        .stat-value.accent{color:var(--accent-light)}
        .stat-value.green{color:var(--success)}
        .stat-sub{font-size:10px;color:var(--text-muted);margin-top:4px}

        .prefs-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;align-items:end}
        .prefs-card label{display:block;font-size:10px;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}
        .prefs-card select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:7px 10px;font-family:'DM Mono',monospace;font-size:11px}
        .prefs-toggle{display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 10px}
        .prefs-toggle input{width:auto;accent-color:var(--accent)}
        .prefs-toggle span{font-size:11px;color:var(--text-dim)}

        body.view-mode-client .admin-only{display:none !important}
        body.layout-compact .card{padding:14px}
        body.layout-compact .stat-card{padding:12px}
        body.layout-flat .stat-card::after{display:none}

        /* TABS */
        .tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:4px}
        .tab{flex:1;text-align:center;padding:9px;border-radius:8px;font-size:12px;cursor:pointer;color:var(--text-muted);transition:all 0.2s}
        .tab.active{background:var(--accent);color:white}
        .tab-pane{display:none}
        .tab-pane.active{display:block}

        /* CARDS */
        .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:16px}
        .card-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:8px}

        /* TABLE */
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th{text-align:left;color:var(--text-muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em;padding:8px 12px;border-bottom:1px solid var(--border)}
        td{padding:10px 12px;border-bottom:1px solid rgba(30,30,46,0.5);color:var(--text-dim);vertical-align:middle}
        tr:last-child td{border-bottom:none}
        tr:hover td{background:rgba(124,58,237,0.02)}

        /* BADGES */
        .status-badge{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
        .status-pending{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
        .status-approved{background:rgba(34,197,94,.15);color:var(--success);border:1px solid rgba(34,197,94,.3)}
        .status-rejected{background:rgba(239,68,68,.15);color:var(--error);border:1px solid rgba(239,68,68,.3)}
        .status-active{background:rgba(34,197,94,.15);color:var(--success);border:1px solid rgba(34,197,94,.3)}
        .status-suspended{background:rgba(239,68,68,.15);color:var(--error);border:1px solid rgba(239,68,68,.3)}
        .status-terminated{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.35)}

        /* BUTTONS */
        .btn{padding:7px 14px;border-radius:8px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;border:none;transition:all 0.2s;display:inline-flex;align-items:center;gap:6px}
        .btn-approve{background:rgba(34,197,94,.15);color:var(--success);border:1px solid rgba(34,197,94,.3)}
        .btn-approve:hover{background:rgba(34,197,94,.25)}
        .btn-reject{background:rgba(239,68,68,.1);color:var(--error);border:1px solid rgba(239,68,68,.3)}
        .btn-reject:hover{background:rgba(239,68,68,.2)}
        .btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border)}
        .btn-outline:hover{border-color:var(--accent-light);color:var(--accent-light)}
        .btn-warn{background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
        .btn-warn:hover{background:rgba(245,158,11,.2)}
        .btn-danger{background:rgba(239,68,68,.1);color:var(--error);border:1px solid rgba(239,68,68,.3)}
        .btn-danger:hover{background:rgba(239,68,68,.2)}
        .btn-actions{display:flex;gap:6px;flex-wrap:wrap}

        /* MODAL */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
        .modal-overlay.open{display:flex}
        .modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:460px}
        .modal h2{font-family:'Syne',sans-serif;font-size:17px;font-weight:700;margin-bottom:16px}
        .modal-body{font-size:13px;color:var(--text-muted);line-height:1.7;margin-bottom:16px}
        .form-group{margin-bottom:12px}
        label{display:block;font-size:11px;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
        input,textarea,select{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:9px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;transition:border-color 0.2s;outline:none}
        input:focus,textarea:focus,select:focus{border-color:var(--accent)}
        textarea{resize:vertical;min-height:80px}
        .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}

        /* DETAIL PANEL */
        .detail-panel{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px;margin-top:8px;font-size:12px;color:var(--text-dim);line-height:1.8;display:none}
        .detail-panel.open{display:block}
        .detail-label{color:var(--text-muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}

        /* MSG */
        .msg{padding:10px 14px;border-radius:10px;font-size:12px;margin-bottom:14px}
        .msg.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--success)}
        .msg.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--error)}

        /* FILTER */
        .filter-row{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
        .filter-btn{padding:5px 14px;border-radius:20px;font-size:11px;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-family:'DM Mono',monospace;transition:all 0.2s}
        .filter-btn.active{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,.1)}
        .table-tools{display:grid;grid-template-columns:minmax(0,1fr) 170px 170px;gap:8px;margin-bottom:12px}
        .table-tools input,.table-tools select{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 10px;color:var(--text);font-family:'DM Mono',monospace;font-size:11px;outline:none}
        .table-tools input:focus,.table-tools select:focus{border-color:var(--accent)}
        .table-tools .hint{font-size:10px;color:var(--text-muted);grid-column:1/-1}
        .kbd{border:1px solid var(--border);border-bottom-width:2px;padding:1px 6px;border-radius:6px;font-size:10px;color:var(--text-muted)}

        .commission-input{width:70px;padding:5px 8px;font-size:12px;border-radius:8px;text-align:center}

        @media(max-width:820px){.prefs-card{grid-template-columns:1fr}.table-tools{grid-template-columns:1fr}}
        @media(max-width:640px){.container{padding:16px 12px 60px}.btn-actions{flex-direction:column}}
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/images/lyralinkai.ico" class="nav-logo" alt="Lyralink Infrastructure">
    <div class="nav-title">Lyra<span>link</span> Operator Admin</div>
    <div class="nav-links">
        <a href="/pages/admin.php" class="nav-link">← Admin</a>
        <a href="/" class="nav-link">Home</a>
    </div>
</nav>

<div class="container">
    <div class="page-head">
        <div>
            <h1>Operator <span>Management</span></h1>
            <div class="page-sub">Review operator applications, manage active operator accounts, and track revenue payouts</div>
        </div>
        <div class="view-toggle">
            <button type="button" class="view-btn active" id="adminViewBtn" onclick="setViewMode('admin')">Admin View</button>
            <button type="button" class="view-btn" id="clientViewBtn" onclick="setViewMode('client')">Client View</button>
        </div>
    </div>

    <div class="stats" id="adminStatsGrid">
        <div class="stat-card"><div class="stat-label">Pending Applications</div><div class="stat-value accent" id="statPendingApps">0</div><div class="stat-sub">Awaiting review</div></div>
        <div class="stat-card"><div class="stat-label">Active Operators</div><div class="stat-value" id="statActiveOperators">0</div><div class="stat-sub">Status active</div></div>
        <div class="stat-card"><div class="stat-label">Total Clients</div><div class="stat-value" id="statTotalClients">0</div><div class="stat-sub">Across operators</div></div>
        <div class="stat-card"><div class="stat-label">Pending Payouts</div><div class="stat-value green" id="statPendingPayouts">$0.00</div><div class="stat-sub">Estimated unsettled amount</div></div>
    </div>

    <div class="prefs-card">
        <div>
            <label>Density</label>
            <select id="layoutDensity" onchange="updateAdminPrefs()">
                <option value="comfortable">Comfortable</option>
                <option value="compact">Compact</option>
            </select>
        </div>
        <div>
            <label>Card Style</label>
            <select id="layoutStyle" onchange="updateAdminPrefs()">
                <option value="glow">Glow</option>
                <option value="flat">Flat</option>
            </select>
        </div>
        <label class="prefs-toggle">
            <input type="checkbox" id="prefShowHints" checked>
            <span>Client mode hides destructive buttons</span>
        </label>
    </div>

    <div class="tabs">
        <div class="tab active" data-tab="applications" onclick="switchTab('applications',this)">📋 Applications</div>
        <div class="tab" data-tab="resellers" onclick="switchTab('resellers',this)">🏢 Active Operators</div>
    </div>

    <!-- APPLICATIONS TAB -->
    <div class="tab-pane active" id="tab-applications">
        <div class="filter-row">
            <button class="filter-btn active" onclick="loadApplications('pending',this)">Pending</button>
            <button class="filter-btn" onclick="loadApplications('approved',this)">Approved</button>
            <button class="filter-btn" onclick="loadApplications('rejected',this)">Rejected</button>
            <button class="filter-btn" onclick="loadApplications('all',this)">All</button>
        </div>
        <div class="table-tools">
            <input id="appSearch" type="search" placeholder="Search applications by name, email, company, use case" oninput="renderApplications()">
            <select id="appSort" onchange="renderApplications()">
                <option value="newest">Sort: Newest</option>
                <option value="oldest">Sort: Oldest</option>
                <option value="name">Sort: Name</option>
            </select>
            <button class="btn btn-outline" type="button" onclick="clearAppSearch()">Clear Search</button>
            <div class="hint">Quick search: press <span class="kbd">/</span> from anywhere on this page.</div>
        </div>
        <div id="appsMsg"></div>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>Clients</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="applicationsTable"><tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
        <div id="applicationDetail"></div>
    </div>

    <!-- RESELLERS TAB -->
    <div class="tab-pane" id="tab-resellers">
        <div class="table-tools">
            <input id="resellerSearch" type="search" placeholder="Search operator by username, email, company" oninput="renderResellers()">
            <select id="resellerStatusFilter" onchange="renderResellers()">
                <option value="all">Status: All</option>
                <option value="active">Status: Active</option>
                <option value="suspended">Status: Suspended</option>
                <option value="terminated">Status: Terminated</option>
            </select>
            <select id="resellerSort" onchange="renderResellers()">
                <option value="pending_desc">Sort: Pending payout high-low</option>
                <option value="clients_desc">Sort: Most clients</option>
                <option value="earned_desc">Sort: Total earned high-low</option>
                <option value="company_asc">Sort: Company A-Z</option>
            </select>
            <div class="hint">Tip: use Client View or Admin View from each row to troubleshoot customer-side behavior quickly.</div>
        </div>
        <div id="resellersMsg"></div>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Company</th><th>Clients</th><th>Commission</th><th>Pending $</th><th>Total $</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="resellersTable"><tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px">Loading operator accounts…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- APPROVE MODAL -->
<div class="modal-overlay" id="approveModal">
    <div class="modal">
        <h2>✅ Approve Operator Application</h2>
        <input type="hidden" id="approveAppId">
        <div class="modal-body" id="approveAppInfo"></div>
        <div class="form-group">
            <label>Commission Rate (%)</label>
            <input type="number" id="approveCommission" value="20" min="1" max="50" step="1">
        </div>
        <div class="form-group">
            <label>Internal Note (optional)</label>
            <input type="text" id="approveNote" placeholder="e.g. Approved — solid use case">
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
            <button class="btn btn-approve" onclick="confirmApprove()">Approve & Activate Operator</button>
        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <h2>❌ Reject Application</h2>
        <input type="hidden" id="rejectAppId">
        <div class="modal-body" id="rejectAppInfo"></div>
        <div class="form-group">
            <label>Reason / Note (optional)</label>
            <textarea id="rejectNote" placeholder="Reason shown to applicant…"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn btn-reject" onclick="confirmReject()">Reject Application</button>
        </div>
    </div>
</div>

<!-- CLIENTS MODAL -->
<div class="modal-overlay" id="clientsModal">
    <div class="modal" style="max-width:600px">
        <h2>👥 Operator Clients</h2>
        <div id="clientsModalContent" style="max-height:400px;overflow-y:auto"></div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal('clientsModal')">Close</button>
        </div>
    </div>
</div>

<script>
let currentAppsFilter = 'pending';
let applicationsCache = [];
let resellersCache = [];
const ADMIN_PREFS_KEY = 'lyralink_operator_admin_prefs_v1';
let adminPrefs = { density: 'comfortable', style: 'glow', viewMode: 'admin', activeTab: 'applications' };

async function loadApplications(filter, btn) {
    currentAppsFilter = filter;
    if (btn) {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    const r = await fetch(`/api/reseller.php?action=admin_get_applications&status=${filter}`);
    const d = await r.json();
    if (!d.success) return;
    applicationsCache = d.applications || [];

    renderApplications();
    renderAdminStats();
}

function renderApplications() {
    const tbody = document.getElementById('applicationsTable');
    const q = (document.getElementById('appSearch')?.value || '').toLowerCase().trim();
    const sort = (document.getElementById('appSort')?.value || 'newest');

    let rows = applicationsCache.slice();
    if (q) {
        rows = rows.filter(a => {
            const hay = [a.name, a.email, a.company, a.use_case, a.status]
                .map(v => String(v || '').toLowerCase())
                .join(' ');
            return hay.includes(q);
        });
    }

    if (sort === 'name') {
        rows.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    } else {
        rows.sort((a, b) => {
            const at = Date.parse(a.created_at || '') || 0;
            const bt = Date.parse(b.created_at || '') || 0;
            return sort === 'oldest' ? (at - bt) : (bt - at);
        });
    }

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:32px">No matching applications.</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(a => `
        <tr>
            <td>${esc(a.name)}</td>
            <td style="color:var(--text-muted)">${esc(a.email)}</td>
            <td>${esc(a.company)}</td>
            <td style="color:var(--text-muted)">${esc(a.client_count || '—')}</td>
            <td style="color:var(--text-muted)">${formatShortDate(a.created_at)}</td>
            <td><span class="status-badge status-${a.status}">${a.status}</span></td>
            <td>
                <div class="btn-actions">
                    <button class="btn btn-outline" onclick="toggleDetail(${a.id})">Details</button>
                    ${a.user_id ? `<button class="btn btn-outline" onclick="impersonateClient(${Number(a.user_id)}, '/chat.php')">Client View</button><button class="btn btn-outline" onclick="openClientSupportView(${Number(a.user_id)},'security')">Admin View</button>` : ''}
                    ${a.status==='pending' ? `
                        <button class="btn btn-approve admin-only" onclick="openApproveById(${a.id})">Approve</button>
                        <button class="btn btn-reject admin-only" onclick="openRejectById(${a.id})">Reject</button>
                    ` : ''}
                </div>
                <div class="detail-panel" id="detail-${a.id}">
                    <div class="detail-label">Use Case</div>
                    <div>${esc(a.use_case)}</div>
                    ${a.website ? `<div class="detail-label" style="margin-top:8px">Website</div><div><a href="${esc(a.website)}" target="_blank" style="color:var(--accent-light)">${esc(a.website)}</a></div>` : ''}
                    ${a.admin_note ? `<div class="detail-label" style="margin-top:8px">Note</div><div>${esc(a.admin_note)}</div>` : ''}
                    ${a.reviewed_by ? `<div style="margin-top:8px;font-size:10px;color:var(--text-muted)">Reviewed by ${esc(a.reviewed_by)} on ${a.reviewed_at}</div>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

async function loadResellers() {
    const r = await fetch('/api/reseller.php?action=admin_list_resellers');
    const d = await r.json();
    if (!d.success) return;
    resellersCache = d.resellers || [];

    renderResellers();
    renderAdminStats();
}

function renderResellers() {
    const tbody = document.getElementById('resellersTable');
    const q = (document.getElementById('resellerSearch')?.value || '').toLowerCase().trim();
    const statusFilter = (document.getElementById('resellerStatusFilter')?.value || 'all').toLowerCase();
    const sort = (document.getElementById('resellerSort')?.value || 'pending_desc');

    let rows = resellersCache.slice();
    if (q) {
        rows = rows.filter(res => {
            const hay = [res.username, res.email, res.company_name, res.status]
                .map(v => String(v || '').toLowerCase())
                .join(' ');
            return hay.includes(q);
        });
    }

    if (statusFilter !== 'all') {
        rows = rows.filter(res => String(res.status || '').toLowerCase() === statusFilter);
    }

    rows.sort((a, b) => {
        const aPending = Number(a.total_earned || 0) - Number(a.total_paid_out || 0);
        const bPending = Number(b.total_earned || 0) - Number(b.total_paid_out || 0);
        const aClients = Number(a.client_count || 0);
        const bClients = Number(b.client_count || 0);
        const aEarned = Number(a.total_earned || 0);
        const bEarned = Number(b.total_earned || 0);

        if (sort === 'clients_desc') return bClients - aClients;
        if (sort === 'earned_desc') return bEarned - aEarned;
        if (sort === 'company_asc') return String(a.company_name || '').localeCompare(String(b.company_name || ''));
        return bPending - aPending;
    });

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:32px">No matching operator accounts.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(res => `
        <tr id="row-r${res.id}">
            <td>
                <div>${esc(res.username)}</div>
                <div style="font-size:10px;color:var(--text-muted)">${esc(res.email)}</div>
            </td>
            <td>${esc(res.company_name)}</td>
            <td style="text-align:center">${res.client_count}</td>
            <td>
                <div style="display:flex;align-items:center;gap:6px">
                    <input type="number" class="commission-input" id="comm-${res.id}" value="${res.commission_rate}" min="1" max="50" step="1">
                    <button class="btn btn-outline" onclick="setCommission(${res.id})" style="padding:4px 10px">Set</button>
                </div>
            </td>
            <td style="color:var(--warn)">$${Number(res.total_earned - res.total_paid_out).toFixed(2)}</td>
            <td>$${Number(res.total_earned).toFixed(2)}</td>
            <td><span class="status-badge status-${res.status}">${res.status}</span></td>
            <td>
                <div class="btn-actions">
                    <button class="btn btn-outline" onclick="viewClientsById(${res.id})">Clients</button>
                    <button class="btn btn-outline" onclick="impersonateClient(${Number(res.user_id || 0)}, '/chat.php')">Client View</button>
                    <button class="btn btn-outline" onclick="openClientSupportView(${Number(res.user_id || 0)},'security')">Admin View</button>
                    <button class="btn btn-warn admin-only" onclick="markPaidOut(${res.id})">Mark Paid</button>
                    <button class="btn btn-outline admin-only" onclick="toggleStatus(${res.id})">${res.status==='active'?'Suspend':'Activate'}</button>
                    <button class="btn btn-danger admin-only" onclick="removeResellerById(${res.id})">Terminate</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function clearAppSearch() {
    const input = document.getElementById('appSearch');
    if (!input) return;
    input.value = '';
    renderApplications();
    input.focus();
}

function formatShortDate(raw) {
    const t = Date.parse(raw || '');
    if (!Number.isFinite(t)) return '—';
    const d = new Date(t);
    return d.toISOString().slice(0, 10);
}

function findApplicationById(id) {
    return applicationsCache.find(a => Number(a.id || 0) === Number(id || 0)) || null;
}

function findResellerById(id) {
    return resellersCache.find(r => Number(r.id || 0) === Number(id || 0)) || null;
}

function openApproveById(id) {
    const app = findApplicationById(id);
    if (!app) return;
    openApprove(Number(app.id || 0), String(app.name || ''), String(app.company || ''));
}

function openRejectById(id) {
    const app = findApplicationById(id);
    if (!app) return;
    openReject(Number(app.id || 0), String(app.name || ''));
}

function viewClientsById(rid) {
    const reseller = findResellerById(rid);
    const company = reseller ? String(reseller.company_name || '') : '';
    viewClients(rid, company);
}

function toggleDetail(id) {
    const el = document.getElementById('detail-' + id);
    el.classList.toggle('open');
}

function openApprove(id, name, company) {
    document.getElementById('approveAppId').value = id;
    document.getElementById('approveAppInfo').textContent = `Applicant: ${name} — ${company}`;
    document.getElementById('approveModal').classList.add('open');
}

async function confirmApprove() {
    const id = document.getElementById('approveAppId').value;
    const commission = document.getElementById('approveCommission').value;
    const note = document.getElementById('approveNote').value;
    const fd = new FormData();
    fd.append('application_id', id);
    fd.append('commission_rate', commission);
    fd.append('note', note);
    const r = await fetch('/api/reseller.php?action=admin_approve', {method:'POST',body:fd});
    const d = await r.json();
    closeModal('approveModal');
    const msg = document.getElementById('appsMsg');
    if (d.success) {
        msg.className = 'msg success';
        msg.textContent = 'Application approved. Operator account activated.';
        loadApplications(currentAppsFilter, null);
    } else {
        msg.className = 'msg error';
        msg.textContent = d.error;
    }
    setTimeout(() => msg.className = 'msg', 5000);
}

function openReject(id, name) {
    document.getElementById('rejectAppId').value = id;
    document.getElementById('rejectAppInfo').textContent = `Reject application from: ${name}`;
    document.getElementById('rejectModal').classList.add('open');
}

async function confirmReject() {
    const id = document.getElementById('rejectAppId').value;
    const note = document.getElementById('rejectNote').value;
    const fd = new FormData();
    fd.append('application_id', id);
    fd.append('note', note);
    const r = await fetch('/api/reseller.php?action=admin_reject', {method:'POST',body:fd});
    const d = await r.json();
    closeModal('rejectModal');
    loadApplications(currentAppsFilter, null);
}

async function setCommission(rid) {
    const rate = document.getElementById('comm-' + rid).value;
    const fd = new FormData();
    fd.append('reseller_id', rid);
    fd.append('commission_rate', rate);
    const r = await fetch('/api/reseller.php?action=admin_set_commission', {method:'POST',body:fd});
    const d = await r.json();
    const msg = document.getElementById('resellersMsg');
    if (d.success) {
        msg.className = 'msg success';
        msg.textContent = 'Commission updated.';
    } else {
        msg.className = 'msg error';
        msg.textContent = d.error;
    }
    setTimeout(() => msg.className = 'msg', 3000);
}

async function toggleStatus(rid) {
    const fd = new FormData();
    fd.append('reseller_id', rid);
    const r = await fetch('/api/reseller.php?action=admin_toggle_status', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) loadResellers();
}

async function markPaidOut(rid) {
    if (!confirm('Mark all pending earnings for this operator as paid out?')) return;
    const fd = new FormData();
    fd.append('reseller_id', rid);
    const r = await fetch('/api/reseller.php?action=admin_mark_paid_out', {method:'POST',body:fd});
    const d = await r.json();
    const msg = document.getElementById('resellersMsg');
    if (d.success) {
        msg.className = 'msg success';
        msg.textContent = `Marked ${d.marked} earning(s) as paid out.`;
        loadResellers();
    } else {
        msg.className = 'msg error';
        msg.textContent = d.error;
    }
    setTimeout(() => msg.className = 'msg', 4000);
}

async function removeReseller(rid, username) {
    if (!confirm(`Terminate operator account for ${username}? They will lose operator dashboard access until reactivated by admin.`)) return;
    const fd = new FormData();
    fd.append('reseller_id', rid);
    const r = await fetch('/api/reseller.php?action=admin_remove_reseller', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) loadResellers();
}

function removeResellerById(rid) {
    const reseller = findResellerById(rid);
    const username = reseller ? String(reseller.username || 'this user') : 'this user';
    removeReseller(rid, username);
}

async function viewClients(rid, company) {
    document.getElementById('clientsModalContent').innerHTML = '<p style="color:var(--text-muted);padding:16px">Loading…</p>';
    document.getElementById('clientsModal').classList.add('open');
    const r = await fetch(`/api/reseller.php?action=admin_reseller_clients&reseller_id=${rid}`);
    const d = await r.json();
    if (!d.success || !d.clients.length) {
        document.getElementById('clientsModalContent').innerHTML = '<p style="color:var(--text-muted);padding:16px">No clients found.</p>';
        return;
    }
    document.getElementById('clientsModalContent').innerHTML = `
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">${company} — ${d.clients.length} client(s)</p>
        <table style="font-size:12px;width:100%;border-collapse:collapse">
            <thead><tr>
                <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">User</th>
                <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">Email</th>
                <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">Plan</th>
                <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">Added</th>
                <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:10px;text-transform:uppercase">Views</th>
            </tr></thead>
            <tbody>
                ${d.clients.map(c => `<tr>
                    <td style="padding:7px 8px">${esc(c.username)}</td>
                    <td style="padding:7px 8px;color:var(--text-muted)">${esc(c.email)}</td>
                    <td style="padding:7px 8px">${esc(c.plan)}</td>
                    <td style="padding:7px 8px;color:var(--text-muted)">${c.created_at.split(' ')[0]}</td>
                    <td style="padding:7px 8px"><div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn btn-outline" style="padding:4px 10px" onclick="impersonateClient(${Number(c.client_user_id || 0)}, '/chat.php')">Client View</button><button class="btn btn-outline" style="padding:4px 10px" onclick="openClientSupportView(${Number(c.client_user_id || 0)},'security')">Admin View</button></div></td>
                </tr>`).join('')}
            </tbody>
        </table>
    `;
}

async function impersonateClient(userId, redirect) {
    const uid = Number(userId || 0);
    if (!uid) return;
    const fd = new FormData();
    fd.append('action', 'admin_start_impersonation');
    fd.append('user_id', String(uid));
    fd.append('redirect', redirect || '/chat.php');
    const r = await fetch('/api/reseller.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.success) {
        alert(d.error || 'Failed to start client view');
        return;
    }
    window.location.href = d.redirect || '/chat.php';
}

function renderAdminStats() {
    const pendingApps = applicationsCache.filter(a => a.status === 'pending').length;
    const activeOperators = resellersCache.filter(r => r.status === 'active').length;
    const totalClients = resellersCache.reduce((sum, r) => sum + Number(r.client_count || 0), 0);
    const pendingPayouts = resellersCache.reduce((sum, r) => {
        const earned = Number(r.total_earned || 0);
        const paid = Number(r.total_paid_out || 0);
        return sum + Math.max(0, earned - paid);
    }, 0);

    document.getElementById('statPendingApps').textContent = String(pendingApps);
    document.getElementById('statActiveOperators').textContent = String(activeOperators);
    document.getElementById('statTotalClients').textContent = String(totalClients);
    document.getElementById('statPendingPayouts').textContent = '$' + pendingPayouts.toFixed(2);
}

function openClientSupportView(userId, focus) {
    const uid = Number(userId || 0);
    if (!uid) return;
    const section = focus === 'security' ? 'security' : 'profile';
    const url = `/pages/support_admin.php?tool=users&user_id=${uid}&focus=${section}`;
    window.open(url, '_blank', 'noopener');
}

function setViewMode(mode) {
    adminPrefs.viewMode = mode === 'client' ? 'client' : 'admin';
    document.getElementById('adminViewBtn').classList.toggle('active', adminPrefs.viewMode === 'admin');
    document.getElementById('clientViewBtn').classList.toggle('active', adminPrefs.viewMode === 'client');
    document.body.classList.toggle('view-mode-client', adminPrefs.viewMode === 'client');
    localStorage.setItem(ADMIN_PREFS_KEY, JSON.stringify(adminPrefs));
}

function loadAdminPrefs() {
    try {
        const raw = localStorage.getItem(ADMIN_PREFS_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return;
        adminPrefs = {
            density: parsed.density === 'compact' ? 'compact' : 'comfortable',
            style: parsed.style === 'flat' ? 'flat' : 'glow',
            viewMode: parsed.viewMode === 'client' ? 'client' : 'admin',
            activeTab: parsed.activeTab === 'resellers' ? 'resellers' : 'applications',
        };
    } catch (e) {
        // keep defaults
    }
}

function syncAdminPrefControls() {
    const density = document.getElementById('layoutDensity');
    const style = document.getElementById('layoutStyle');
    if (density) density.value = adminPrefs.density;
    if (style) style.value = adminPrefs.style;
}

function applyAdminPrefs() {
    document.body.classList.toggle('layout-compact', adminPrefs.density === 'compact');
    document.body.classList.toggle('layout-flat', adminPrefs.style === 'flat');
    setViewMode(adminPrefs.viewMode || 'admin');
}

function updateAdminPrefs() {
    const density = document.getElementById('layoutDensity')?.value;
    const style = document.getElementById('layoutStyle')?.value;
    adminPrefs.density = density === 'compact' ? 'compact' : 'comfortable';
    adminPrefs.style = style === 'flat' ? 'flat' : 'glow';
    localStorage.setItem(ADMIN_PREFS_KEY, JSON.stringify(adminPrefs));
    applyAdminPrefs();
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function switchTab(name, el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    if (el) {
        el.classList.add('active');
    } else {
        const autoTab = document.querySelector(`.tab[data-tab="${name}"]`);
        if (autoTab) autoTab.classList.add('active');
    }
    document.getElementById('tab-' + name).classList.add('active');
    adminPrefs.activeTab = name === 'resellers' ? 'resellers' : 'applications';
    localStorage.setItem(ADMIN_PREFS_KEY, JSON.stringify(adminPrefs));
    if (name === 'resellers') loadResellers();
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); }));

loadAdminPrefs();
syncAdminPrefControls();
applyAdminPrefs();
loadApplications('pending', null);
loadResellers();
if (adminPrefs.activeTab === 'resellers') {
    switchTab('resellers', null);
}

document.addEventListener('keydown', (e) => {
    if (e.key !== '/') return;
    const tag = String(e.target?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
    e.preventDefault();
    const activePane = document.querySelector('.tab-pane.active');
    const target = activePane?.id === 'tab-resellers'
        ? document.getElementById('resellerSearch')
        : document.getElementById('appSearch');
    if (target) target.focus();
});
</script>
</body>
</html>
