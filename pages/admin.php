<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
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
    <title>Lyralink — Admin</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
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
        .quick-jump { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
        .quick-jump a { color:var(--text-muted); text-decoration:none; font-size:11px; border:1px solid var(--border); border-radius:999px; padding:6px 12px; background:rgba(124,58,237,0.06); transition:all .2s; }
        .quick-jump a:hover { border-color:var(--accent); color:var(--accent-light); background:rgba(124,58,237,0.14); }
        .quick-hint { font-size:10px; color:var(--text-muted); margin:-2px 0 14px; }

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

        .download-list { margin-top:10px; border:1px solid var(--border); border-radius:10px; overflow:hidden; }
        .download-row { display:grid; grid-template-columns:150px 90px 100px 100px 1fr; gap:8px; padding:9px 10px; border-bottom:1px solid var(--border); font-size:11px; align-items:center; }
        .download-row:last-child { border-bottom:none; }
        .download-row.head { background:rgba(124,58,237,0.08); color:var(--accent-light); font-size:10px; text-transform:uppercase; letter-spacing:.6px; }
        .download-row .muted { color:var(--text-muted); }
        @media(max-width:900px){ .download-row { grid-template-columns:1fr; gap:4px; } }

        .billing-kpi-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:12px; }
        .billing-kpi { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px 12px; }
        .billing-kpi .k { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:4px; }
        .billing-kpi .v { font-family:'Syne',sans-serif; font-size:20px; font-weight:800; color:var(--accent-light); }
        .billing-subgrid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; }
        .billing-subgrid > div { min-width:0; }
        .billing-table { border:1px solid var(--border); border-radius:10px; overflow-x:auto; overflow-y:hidden; max-width:100%; }
        .billing-row { display:grid; grid-template-columns:1.1fr 1.2fr .7fr .8fr .9fr .9fr .8fr; gap:8px; padding:9px 10px; font-size:11px; border-bottom:1px solid var(--border); align-items:center; min-width:760px; }
        .billing-row:last-child { border-bottom:none; }
        .billing-row.head { background:rgba(124,58,237,0.08); color:var(--accent-light); font-size:10px; text-transform:uppercase; letter-spacing:.6px; }
        .billing-row.customer { cursor:pointer; }
        .billing-row.customer:hover { background:rgba(124,58,237,0.05); }
        .billing-pill { display:inline-flex; padding:2px 8px; border-radius:999px; border:1px solid var(--border); font-size:10px; }
        .billing-pill.active { color:var(--success); border-color:rgba(34,197,94,0.35); }
        .billing-pill.at_risk, .billing-pill.overdue { color:var(--warn); border-color:rgba(245,158,11,0.35); }
        .billing-pill.inactive, .billing-pill.draft { color:var(--text-muted); }
        .billing-pill.paid { color:var(--success); border-color:rgba(34,197,94,0.35); }
        .billing-note { font-size:11px; color:var(--text-muted); margin-top:8px; }
        .billing-tools { display:flex; gap:8px; margin-bottom:8px; }
        .billing-tools input,.billing-tools select { background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:6px 8px; font-size:11px; font-family:'DM Mono',monospace; }
        .billing-tools input { flex:1; min-width:0; }
        .invoice-actions { display:flex; gap:4px; flex-wrap:wrap; }
        .btn-mini { border:1px solid var(--border); background:var(--bg); color:var(--text-muted); border-radius:6px; padding:3px 7px; font-size:10px; cursor:pointer; }
        .btn-mini:hover { border-color:var(--accent); color:var(--accent-light); }
        .btn-mini.pay { color:var(--success); border-color:rgba(34,197,94,0.35); }
        .btn-mini.void { color:var(--error); border-color:rgba(239,68,68,0.35); }
        .billing-modal-wrap { position:fixed; inset:0; background:rgba(0,0,0,0.7); display:none; align-items:center; justify-content:center; z-index:1001; }
        .billing-modal { width:min(900px, calc(100vw - 24px)); max-height:85vh; overflow:auto; background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; }
        .billing-modal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
        .billing-modal-title { font-family:'Syne',sans-serif; font-size:16px; font-weight:800; }
        .billing-modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .billing-modal-box { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px; }
        .billing-modal-row { display:flex; justify-content:space-between; gap:12px; font-size:11px; color:var(--text-muted); padding:4px 0; border-bottom:1px dashed rgba(100,116,139,0.15); }
        .billing-modal-row:last-child { border-bottom:none; }
        .audit-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:8px; margin-top:12px; }
        .audit-kpi { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:8px 10px; }
        .audit-kpi .k { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:4px; }
        .audit-kpi .v { font-family:'Syne',sans-serif; font-size:16px; font-weight:800; color:var(--accent-light); }
        .audit-kpi .v.warn { color:var(--warn); }
        .audit-kpi .v.bad { color:var(--error); }
        .audit-lists { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:10px; margin-top:10px; }
        .audit-box { border:1px solid var(--border); border-radius:10px; background:var(--bg); padding:8px; min-height:140px; }
        .audit-title { font-size:11px; color:var(--accent-light); margin-bottom:6px; text-transform:uppercase; letter-spacing:.6px; }
        .audit-item { border-bottom:1px dashed rgba(100,116,139,0.18); padding:7px 2px; font-size:11px; }
        .audit-item:last-child { border-bottom:none; }
        .audit-item .meta { color:var(--text-muted); margin-top:3px; font-size:10px; }
        .audit-empty { color:var(--text-muted); font-size:11px; padding:6px 2px; }
        .audit-pill { display:inline-flex; border:1px solid var(--border); border-radius:999px; padding:1px 7px; font-size:10px; }
        .audit-pill.failed { color:var(--error); border-color:rgba(239,68,68,0.35); }
        .audit-pill.ignored { color:var(--warn); border-color:rgba(245,158,11,0.35); }
        .audit-pill.processed { color:var(--success); border-color:rgba(34,197,94,0.35); }
        @media(max-width:1100px){ .billing-kpi-grid { grid-template-columns:1fr 1fr; } .billing-subgrid{grid-template-columns:1fr;} .billing-row{grid-template-columns:1fr;} }
        @media(max-width:1100px){ .audit-grid{grid-template-columns:1fr 1fr;} .audit-lists{grid-template-columns:1fr;} }
        @media(max-width:700px){ .quick-jump { flex-wrap:nowrap; overflow-x:auto; padding-bottom:2px; } .quick-jump a { white-space:nowrap; } }

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
        .diag-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; margin-bottom:12px; }
        .diag-item { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px 12px; }
        .diag-item .k { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:5px; }
        .diag-item .v { font-size:12px; color:var(--text); line-height:1.5; word-break:break-word; }
        .diag-item .v.good { color:var(--success); }
        .diag-item .v.warn { color:var(--warn); }
        .diag-item .v.bad { color:var(--error); }
        .diag-note { font-size:11px; color:var(--text-muted); margin-top:8px; }
        @media(max-width:1100px){ .diag-grid { grid-template-columns:1fr 1fr; } }
        @media(max-width:700px){ .diag-grid { grid-template-columns:1fr; } }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-title">/ Admin</span>
    <div class="nav-links">
        <a href="/pages/reseller_admin.php" class="nav-link">🏢 Operators</a>
        <a href="/chat" class="nav-link">← Chat</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
    </div>
</nav>

<div class="container">

    <div class="page-header">
        <h1>System <span>Settings</span></h1>
        <p><?php echo $isForkMode ? 'Fork preview mode (read-only)' : 'Developer panel — only visible to you'; ?></p>
    </div>

    <div class="quick-jump" aria-label="Admin sections">
        <a href="#siteOverview">Overview</a>
        <a href="#runtimeDiagnostics">AI Runtime</a>
        <a href="#downloadAnalytics">Downloads</a>
        <a href="#billingOps">Billing</a>
        <a href="#operatorTools">Operator Tools</a>
    </div>

    <?php if ($isForkMode): ?>
    <div class="card" style="margin-bottom:16px;border-color:rgba(245,158,11,0.35);background:rgba(245,158,11,0.06)">
        <div style="font-size:12px;color:var(--warn);line-height:1.7">
            Fork mode is enabled. Sensitive operations such as maintenance toggles and bot controls are disabled.
        </div>
    </div>
    <?php endif; ?>

    <!-- STATS ROW -->
    <div class="section-label" id="siteOverview">Site Overview</div>
    <div class="card" style="margin-bottom:16px">
        <div class="stat-grid" id="statsGrid">
            <div class="stat-item"><div class="stat-value" id="statUsers">—</div><div class="stat-label">Users</div></div>
            <div class="stat-item"><div class="stat-value" id="statConvs">—</div><div class="stat-label">Conversations</div></div>
            <div class="stat-item"><div class="stat-value" id="statMsgs">—</div><div class="stat-label">Messages</div></div>
            <div class="stat-item"><div class="stat-value" id="statDataset">—</div><div class="stat-label">Dataset Entries</div></div>
            <div class="stat-item"><div class="stat-value" id="statKeys">—</div><div class="stat-label">Active API Keys</div></div>
            <div class="stat-item"><div class="stat-value" id="statPending" style="color:var(--warn)">—</div><div class="stat-label">Pending Review</div></div>
            <div class="stat-item"><div class="stat-value" id="statDownloadsTotal">—</div><div class="stat-label">Downloads (All)</div></div>
            <div class="stat-item"><div class="stat-value" id="statDownloads24">—</div><div class="stat-label">Downloads (24h)</div></div>
            <div class="stat-item"><div class="stat-value" id="statDownloadUnique24">—</div><div class="stat-label">Unique IPs (24h)</div></div>
        </div>
    </div>

    <div class="section-label" id="runtimeDiagnostics">AI Runtime Diagnostics</div>
    <div class="card" style="margin-bottom:16px">
        <div class="card-title"><span class="icon">🧠</span> Routing + Health</div>
        <div class="diag-grid">
            <div class="diag-item"><div class="k">Chat API Health</div><div class="v" id="diagHealth">Loading...</div></div>
            <div class="diag-item"><div class="k">Status Mode</div><div class="v" id="diagStatusMode">—</div></div>
            <div class="diag-item"><div class="k">Default Route</div><div class="v" id="diagDefaultRoute">—</div></div>
            <div class="diag-item"><div class="k">Fallback Route</div><div class="v" id="diagFallbackRoute">—</div></div>
            <div class="diag-item"><div class="k">Provider Default</div><div class="v" id="diagProvider">—</div></div>
            <div class="diag-item"><div class="k">Model Default</div><div class="v" id="diagModel">—</div></div>
            <div class="diag-item"><div class="k">Latency</div><div class="v" id="diagLatency">—</div></div>
            <div class="diag-item"><div class="k">Loaded Models</div><div class="v" id="diagModelCount">—</div></div>
        </div>
        <div class="btn-row">
            <button class="btn btn-outline" onclick="loadRuntimeDiagnostics()">⟳ Refresh Runtime</button>
            <a class="btn btn-outline" style="text-decoration:none" href="/api/chat.php?health=1" target="_blank" rel="noopener">Open Raw Health JSON</a>
        </div>
        <div class="diag-note" id="diagNote">This panel reads /api/chat.php?health=1 and /api/auth.php model options to show the active route posture.</div>
    </div>

    <div class="section-label" id="downloadAnalytics">Desktop Download Analytics</div>
    <div class="card" style="margin-bottom:16px">
        <div class="card-title"><span class="icon">⬇️</span> Recent Installer Downloads</div>
        <div style="font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:10px">
            Tracks requests hitting <a href="/desktop-updates/download.php" target="_blank" style="color:var(--accent-light)">desktop-updates/download.php</a>.
        </div>
        <div class="download-list" id="downloadList"></div>
    </div>

    <div class="section-label" id="billingOps">Billing Operations</div>
    <div class="card" style="margin-bottom:16px">
        <div class="card-title"><span class="icon">💳</span> Billing Command Center</div>
        <div class="billing-kpi-grid">
            <div class="billing-kpi"><div class="k">Active Subs</div><div class="v" id="billActive">—</div></div>
            <div class="billing-kpi"><div class="k">MRR Estimate</div><div class="v" id="billMrr">—</div></div>
            <div class="billing-kpi"><div class="k">At Risk</div><div class="v" id="billAtRisk" style="color:var(--warn)">—</div></div>
            <div class="billing-kpi"><div class="k">New (30d)</div><div class="v" id="billNew30">—</div></div>
            <div class="billing-kpi"><div class="k">Credit Transfers (30d)</div><div class="v" id="billTransfers">—</div></div>
        </div>
        <div class="billing-note" id="billMethods">Payment Methods — PayPal: —, Apple: —, Unlinked: —</div>
        <div class="billing-subgrid" style="margin-top:12px">
            <div>
                <div class="card-title" style="margin:0 0 8px 0;font-size:12px"><span class="icon">👤</span> Customer Billing Accounts</div>
                <div class="billing-table" id="billCustomersList"></div>
            </div>
            <div>
                <div class="card-title" style="margin:0 0 8px 0;font-size:12px"><span class="icon">🧾</span> Recent Invoices</div>
                <div class="billing-tools">
                    <input id="billInvoiceSearch" type="text" placeholder="Search invoice/customer" oninput="renderBilling(lastBillingSnapshot)">
                    <select id="billInvoiceStatus" onchange="renderBilling(lastBillingSnapshot)">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="paid">Paid</option>
                        <option value="overdue">Overdue</option>
                        <option value="void">Void</option>
                    </select>
                </div>
                <div class="quick-hint" id="billInvoiceMeta">Showing 0 invoices</div>
                <div class="billing-table" id="billInvoicesList"></div>
            </div>
        </div>
        <div class="card-title" style="margin:12px 0 8px 0;font-size:12px"><span class="icon">🛰️</span> Billing Audit & Reconciliation</div>
        <div class="audit-grid">
            <div class="audit-kpi"><div class="k">Drift Candidates</div><div class="v bad" id="billAuditDriftCount">—</div></div>
            <div class="audit-kpi"><div class="k">Webhook Events (24h)</div><div class="v" id="billAuditWebhook24">—</div></div>
            <div class="audit-kpi"><div class="k">Webhook Failures (24h)</div><div class="v warn" id="billAuditWebhookFail24">—</div></div>
            <div class="audit-kpi"><div class="k">Webhook Events (7d)</div><div class="v" id="billAuditWebhook7">—</div></div>
            <div class="audit-kpi"><div class="k">Signature Verify</div><div class="v" id="billAuditVerify">—</div></div>
        </div>
        <div class="billing-note" id="billAuditLastEvent">Last webhook event: —</div>
        <div class="audit-lists">
            <div class="audit-box">
                <div class="audit-title">Drift Candidates</div>
                <div id="billAuditDriftList"></div>
            </div>
            <div class="audit-box">
                <div class="audit-title">Recent Webhook Events</div>
                <div id="billAuditEventsList"></div>
            </div>
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
            <div style="border:1px solid rgba(148,163,184,.18);border-radius:12px;padding:12px 14px;margin-bottom:14px;background:rgba(15,23,42,.28)">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Bot Logs</div>
                <div style="font-size:12px;color:var(--text);line-height:1.5">
                    A daily security report is posted automatically to Discord channel <strong>1475657872862875727</strong>, alongside intrusion alerts.
                </div>
            </div>
            <div class="btn-row">
                <button class="btn btn-green"   onclick="botAction('bot_restart')">↻ Restart</button>
                <button class="btn btn-red"     onclick="botAction('bot_stop')">■ Stop</button>
                <button class="btn btn-outline" onclick="loadStatus()">⟳ Refresh</button>
            </div>
            <div id="botMsg" style="font-size:11px;color:var(--text-muted);margin-top:8px"></div>
        </div>

    </div>

    <!-- QUICK LINKS -->
    <div class="section-label" id="operatorTools">Operator Tools</div>
    <div class="grid-3">
        <a href="/pages/dataset_manager" class="link-card">
            <span class="link-icon">🗄️</span>
            <div class="link-info">
                <div class="link-name">Dataset Manager</div>
                <div class="link-desc">Review, approve & manage Q&A entries</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/api_keys.php" class="link-card">
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
        <a href="/pages/support_admin?tool=users" class="link-card">
            <span class="link-icon">👤</span>
            <div class="link-info">
                <div class="link-name">User Accounts</div>
                <div class="link-desc">Search, review, and update customer accounts</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/mail_admin.php" class="link-card">
            <span class="link-icon">📬</span>
            <div class="link-info">
                <div class="link-name">Mail Admin + SSO</div>
                <div class="link-desc">Manage Plesk mailboxes and launch Roundcube one-click SSO</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/plesk_admin.php" class="link-card">
            <span class="link-icon">🧰</span>
            <div class="link-info">
                <div class="link-name">Plesk + MySQL Ops</div>
                <div class="link-desc">Inspect scheduler, PHP handlers, and live database table metrics</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/security_log.php" class="link-card">
            <span class="link-icon">🛡️</span>
            <div class="link-info">
                <div class="link-name">Security Logs</div>
                <div class="link-desc">Review suspicious events, failed logins, and IP activity</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
        <a href="/pages/marketing_admin.php" class="link-card">
            <span class="link-icon">📈</span>
            <div class="link-info">
                <div class="link-name">Marketing Intelligence</div>
                <div class="link-desc">Review autonomous opportunities, experiments, and authorization status</div>
            </div>
            <span class="link-arrow">→</span>
        </a>
    </div>

</div>

<div class="billing-modal-wrap" id="billingCustomerModal" onclick="if(event.target===this)closeCustomerBilling()">
    <div class="billing-modal">
        <div class="billing-modal-head">
            <div class="billing-modal-title" id="billingModalTitle">Customer Billing</div>
            <button class="btn btn-outline" onclick="closeCustomerBilling()">Close</button>
        </div>
        <div class="billing-modal-grid">
            <div class="billing-modal-box">
                <div class="card-title" style="margin-bottom:8px;font-size:12px"><span class="icon">🧑</span> Account</div>
                <div id="billingModalAccount"></div>
            </div>
            <div class="billing-modal-box">
                <div class="card-title" style="margin-bottom:8px;font-size:12px"><span class="icon">🧾</span> Invoice Timeline</div>
                <div id="billingModalInvoices"></div>
            </div>
        </div>
        <div class="billing-modal-box" style="margin-top:12px">
            <div class="card-title" style="margin-bottom:8px;font-size:12px"><span class="icon">📚</span> Credit Ledger</div>
            <div id="billingModalLedger"></div>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let currentMaintenance = false;
let lastBillingSnapshot = null;

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

    const d = data.downloads?.summary || {};
    document.getElementById('statDownloadsTotal').textContent = Number(d.total || 0).toLocaleString();
    document.getElementById('statDownloads24').textContent = Number(d.downloads_24h || 0).toLocaleString();
    document.getElementById('statDownloadUnique24').textContent = Number(d.unique_ips_24h || 0).toLocaleString();

    const rows = data.downloads?.recent || [];
    const list = document.getElementById('downloadList');
    if (!rows.length) {
        list.innerHTML = '<div class="download-row"><div class="muted">No downloads logged yet.</div></div>';
    } else {
        const head = '<div class="download-row head"><div>Time</div><div>Channel</div><div>Source</div><div>IP</div><div>User-Agent</div></div>';
        const body = rows.map(r => {
            const t = formatClientDate(r.created_at);
            const ua = escapeHtml((r.ua || '').slice(0, 130) || '—');
            const src = escapeHtml(r.source || '—');
            const channel = escapeHtml(r.channel || 'stable');
            const ip = escapeHtml(r.ip || '—');
            return `<div class="download-row"><div>${t}</div><div>${channel}</div><div>${src}</div><div>${ip}</div><div class="muted" title="${ua}">${ua}</div></div>`;
        }).join('');
        list.innerHTML = head + body;
    }

    renderBilling(data.billing || null);
    loadRuntimeDiagnostics();
}

async function loadRuntimeDiagnostics() {
    const setText = (id, value, cls = '') => {
        const node = document.getElementById(id);
        if (!node) return;
        node.className = cls ? `v ${cls}` : 'v';
        node.textContent = value;
    };

    const healthReq = fetch('/api/chat.php?health=1', { cache: 'no-store' }).then(r => r.json()).catch(() => null);
    const authFd = new FormData();
    authFd.append('action', 'get_model_options');
    const modelReq = fetch('/api/auth.php', { method: 'POST', body: authFd }).then(r => r.json()).catch(() => null);

    const [healthData, modelData] = await Promise.all([healthReq, modelReq]);
    if (!healthData?.success) {
        setText('diagHealth', 'Unavailable', 'bad');
        setText('diagNote', 'Chat health endpoint did not return success.');
        return;
    }

    const healthOk = !!healthData.ok;
    setText('diagHealth', healthOk ? 'Healthy' : 'Degraded', healthOk ? 'good' : 'warn');
    setText('diagStatusMode', String(healthData?.status?.service_status || healthData?.status?.probe_status || 'unknown'));
    setText('diagDefaultRoute', String(healthData?.routing?.default_model || '—'));
    setText('diagFallbackRoute', String(healthData?.routing?.fallback_model || '—'));
    setText('diagLatency', `${Number(healthData?.runtime?.request_ms || 0).toLocaleString()} ms`);

    const loaded = Array.isArray(healthData?.runtime?.loaded_models) ? healthData.runtime.loaded_models : [];
    setText('diagModelCount', `${loaded.length} model(s)`);

    if (modelData?.success) {
        setText('diagProvider', String(modelData.default_provider || 'local'));
        setText('diagModel', String(modelData.default_model || '—'));
    } else {
        setText('diagProvider', 'Unavailable', 'warn');
        setText('diagModel', 'Unavailable', 'warn');
    }

    const dbConnected = !!healthData?.db?.connected;
    const runtimeModel = String(healthData?.runtime?.configured_model || 'unknown');
    const runtimeAvailable = !!healthData?.runtime?.model_available;
    const dbState = dbConnected ? 'db ok' : 'db down';
    const modelState = runtimeAvailable ? 'model loaded' : 'model missing';
    setText('diagNote', `Runtime ${runtimeModel} · ${dbState} · ${modelState}`);
}

function renderBilling(billing) {
    lastBillingSnapshot = billing;
    const summary = billing?.summary || {};
    const methods = billing?.methods || {};
    const customers = Array.isArray(billing?.customers) ? billing.customers : [];
    const invoices = Array.isArray(billing?.invoices) ? billing.invoices : [];
    const audit = billing?.audit || null;

    document.getElementById('billActive').textContent = Number(summary.active_subscriptions || 0).toLocaleString();
    document.getElementById('billMrr').textContent = '$' + Number(summary.mrr || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    document.getElementById('billAtRisk').textContent = Number(summary.at_risk || 0).toLocaleString();
    document.getElementById('billNew30').textContent = Number(summary.new_30d || 0).toLocaleString();
    document.getElementById('billTransfers').textContent = Number(summary.credit_transfers_30d || 0).toLocaleString();
    document.getElementById('billMethods').textContent = `Payment Methods — PayPal: ${Number(methods.paypal || 0).toLocaleString()}, Apple: ${Number(methods.apple || 0).toLocaleString()}, Unlinked: ${Number(methods.none || 0).toLocaleString()}`;

    const custEl = document.getElementById('billCustomersList');
    if (!customers.length) {
        custEl.innerHTML = '<div class="billing-row"><div style="color:var(--text-muted)">No billable customers found yet.</div></div>';
    } else {
        const head = '<div class="billing-row head"><div>Customer</div><div>Email</div><div>Plan</div><div>Provider</div><div>Status</div><div>MRR</div><div>Details</div></div>';
        const body = customers.slice(0, 20).map(c => {
            const status = escapeHtml(c.status || 'inactive');
            const mrr = Number(c.mrr || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
            const userId = Number(c.user_id || 0);
            return `<div class="billing-row customer" onclick="openCustomerBilling(${userId})"><div>${escapeHtml(c.username || 'user')}</div><div>${escapeHtml(c.email || '—')}</div><div>${escapeHtml(String(c.plan || 'free').toUpperCase())}</div><div>${escapeHtml(c.provider || 'none')}</div><div><span class="billing-pill ${status}">${status.replace('_',' ')}</span></div><div>$${mrr}</div><div><button class="btn-mini" onclick="event.stopPropagation();openCustomerBilling(${userId})">Open</button></div></div>`;
        }).join('');
        custEl.innerHTML = head + body;
    }

    const search = (document.getElementById('billInvoiceSearch')?.value || '').toLowerCase().trim();
    const statusFilter = (document.getElementById('billInvoiceStatus')?.value || '').toLowerCase();
    const filteredInvoices = invoices.filter(i => {
        const st = String(i.status || '').toLowerCase();
        if (statusFilter && st !== statusFilter) return false;
        if (!search) return true;
        const hay = [i.invoice_id, i.customer, i.email, i.status, i.source].map(v => String(v || '').toLowerCase()).join(' ');
        return hay.includes(search);
    });
    const invMeta = document.getElementById('billInvoiceMeta');
    if (invMeta) {
        const statusLabel = statusFilter ? ` with status "${statusFilter}"` : '';
        invMeta.textContent = `Showing ${filteredInvoices.length} of ${invoices.length} invoices${statusLabel}`;
    }

    const invEl = document.getElementById('billInvoicesList');
    if (!filteredInvoices.length) {
        invEl.innerHTML = '<div class="billing-row"><div style="color:var(--text-muted)">No invoices generated yet.</div></div>';
    } else {
        const head = '<div class="billing-row head"><div>Invoice</div><div>Customer</div><div>Amount</div><div>Status</div><div>Due</div><div>Source</div><div>Actions</div></div>';
        const body = filteredInvoices.slice(0, 20).map(i => {
            const status = escapeHtml(i.status || 'draft');
            const amount = Number(i.amount || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
            const invoiceId = Number(i.id || 0);
            return `<div class="billing-row"><div>${escapeHtml(i.invoice_id || '—')}</div><div>${escapeHtml(i.customer || '—')}</div><div>$${amount}</div><div><span class="billing-pill ${status}">${status.replace('_',' ')}</span></div><div>${formatClientDate(i.due_date)}</div><div>${escapeHtml(i.source || 'system')}</div><div class="invoice-actions"><button class="btn-mini pay" onclick="runInvoiceAction('invoice_mark_paid', ${invoiceId})">Mark Paid</button><button class="btn-mini" onclick="runInvoiceAction('invoice_resend', ${invoiceId})">Resend</button><button class="btn-mini void" onclick="runInvoiceAction('invoice_void', ${invoiceId})">Void</button></div></div>`;
        }).join('');
        invEl.innerHTML = head + body;
    }

    renderBillingAudit(audit);
}

function renderBillingAudit(audit) {
    const summary = audit?.summary || {};
    const drift = Array.isArray(audit?.drift) ? audit.drift : [];
    const events = Array.isArray(audit?.webhook_recent) ? audit.webhook_recent : [];

    const driftCountEl = document.getElementById('billAuditDriftCount');
    const ev24El = document.getElementById('billAuditWebhook24');
    const fail24El = document.getElementById('billAuditWebhookFail24');
    const ev7El = document.getElementById('billAuditWebhook7');
    const verifyEl = document.getElementById('billAuditVerify');
    const lastEventEl = document.getElementById('billAuditLastEvent');
    const driftListEl = document.getElementById('billAuditDriftList');
    const eventsListEl = document.getElementById('billAuditEventsList');

    if (!driftCountEl || !ev24El || !fail24El || !ev7El || !verifyEl || !lastEventEl || !driftListEl || !eventsListEl) {
        return;
    }

    driftCountEl.textContent = Number(summary.drift_candidates || 0).toLocaleString();
    ev24El.textContent = Number(summary.webhook_24h_total || 0).toLocaleString();
    fail24El.textContent = Number(summary.webhook_24h_failed || 0).toLocaleString();
    ev7El.textContent = Number(summary.webhook_7d_total || 0).toLocaleString();
    verifyEl.textContent = summary.verification_enabled ? 'ON' : 'OFF';
    lastEventEl.textContent = `Last webhook event: ${formatClientDate(summary.last_event_at)}`;

    if (!drift.length) {
        driftListEl.innerHTML = '<div class="audit-empty">No drift candidates found.</div>';
    } else {
        driftListEl.innerHTML = drift.slice(0, 12).map(d => {
            const plan = escapeHtml(String(d.plan || 'free').toUpperCase());
            const userLabel = `${escapeHtml(d.username || 'user')} (#${Number(d.user_id || 0)})`;
            const reason = escapeHtml(String(d.reason || 'unknown').replaceAll('_', ' '));
            const billingHint = d.paypal_sub_id ? 'PayPal linked' : (d.apple_product_id ? `Apple ${escapeHtml(d.apple_subscription_status || 'unknown')}` : 'No linked billing');
            return `<div class="audit-item"><div><strong style="color:var(--text)">${userLabel}</strong> · ${plan}</div><div class="meta">${reason} · ${billingHint}</div></div>`;
        }).join('');
    }

    if (!events.length) {
        eventsListEl.innerHTML = '<div class="audit-empty">No webhook events logged yet.</div>';
    } else {
        eventsListEl.innerHTML = events.slice(0, 12).map(e => {
            const status = String(e.status || 'received').toLowerCase();
            const pill = `<span class="audit-pill ${escapeHtml(status)}">${escapeHtml(status)}</span>`;
            const title = escapeHtml(e.event_type || 'event');
            const msg = escapeHtml(e.message || '');
            const rid = escapeHtml(e.resource_id || '');
            return `<div class="audit-item"><div>${pill} <strong style="color:var(--text)">${title}</strong></div><div class="meta">${formatClientDate(e.created_at)}${rid ? ` · ${rid}` : ''}${msg ? ` · ${msg}` : ''}</div></div>`;
        }).join('');
    }
}

async function ensureBillingAuditLoaded() {
    if (lastBillingSnapshot?.audit) {
        return;
    }
    const data = await api('billing_audit').catch(() => null);
    if (!data?.success || !data.audit) {
        return;
    }
    if (!lastBillingSnapshot) {
        lastBillingSnapshot = {};
    }
    lastBillingSnapshot.audit = data.audit;
    renderBillingAudit(data.audit);
}

async function runInvoiceAction(action, invoiceId) {
    const data = await api(action, { invoice_id: invoiceId }).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Invoice action failed', 'error');
        return;
    }
    showToast('✓ Invoice updated', 'success');
    loadStatus();
}

async function openCustomerBilling(userId) {
    if (!userId) return;
    const modal = document.getElementById('billingCustomerModal');
    const title = document.getElementById('billingModalTitle');
    const account = document.getElementById('billingModalAccount');
    const invoices = document.getElementById('billingModalInvoices');
    const ledger = document.getElementById('billingModalLedger');
    modal.style.display = 'flex';
    title.textContent = 'Customer Billing';
    account.innerHTML = '<div class="billing-note">Loading...</div>';
    invoices.innerHTML = '<div class="billing-note">Loading...</div>';
    ledger.innerHTML = '<div class="billing-note">Loading...</div>';

    const data = await api('billing_customer_detail', { user_id: userId }).catch(() => null);
    if (!data?.success) {
        account.innerHTML = '<div class="billing-note" style="color:var(--error)">Failed to load customer details.</div>';
        invoices.innerHTML = '';
        ledger.innerHTML = '';
        return;
    }

    const c = data.customer || {};
    title.textContent = `Billing · ${c.username || 'User'}`;
    account.innerHTML = [
        ['Email', c.email || '—'],
        ['Plan', (c.plan || 'free').toUpperCase()],
        ['Credits', Number(c.credits || 0).toLocaleString()],
        ['PayPal Sub', c.paypal_sub_id || '—'],
        ['Apple Product', c.apple_product_id || '—'],
        ['Apple Status', c.apple_subscription_status || '—'],
        ['Apple Expires', formatClientDate(c.apple_subscription_expires_at)],
        ['Joined', formatClientDate(c.created_at)],
    ].map(([k, v]) => `<div class="billing-modal-row"><span>${escapeHtml(k)}</span><strong style="color:var(--text)">${escapeHtml(String(v ?? '—'))}</strong></div>`).join('');

    const invRows = Array.isArray(data.invoices) ? data.invoices : [];
    invoices.innerHTML = invRows.length
        ? invRows.map(r => `<div class="billing-modal-row"><span>${escapeHtml(r.invoice_no || '—')} · ${escapeHtml(r.status || '')}</span><strong style="color:var(--text)">$${Number(r.amount || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })} · ${formatClientDate(r.due_date)}</strong></div>`).join('')
        : '<div class="billing-note">No invoice history yet.</div>';

    const ledgerRows = Array.isArray(data.ledger) ? data.ledger : [];
    ledger.innerHTML = ledgerRows.length
        ? ledgerRows.map(r => `<div class="billing-modal-row"><span>${escapeHtml(r.transfer_ref || '—')} · ${escapeHtml(r.transfer_type || '')}</span><strong style="color:var(--text)">${Number(r.amount || 0).toLocaleString()} · ${formatClientDate(r.created_at)}</strong></div>`).join('')
        : '<div class="billing-note">No ledger records yet.</div>';
}

function closeCustomerBilling() {
    const modal = document.getElementById('billingCustomerModal');
    modal.style.display = 'none';
}

function parseServerDate(input) {
    if (!input) return null;
    const raw = String(input).trim();
    if (!raw) return null;
    const hasTz = /([zZ]|[+\-]\d{2}:?\d{2})$/.test(raw);
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const iso = hasTz ? normalized : normalized + 'Z';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d;
}

function formatClientDate(raw) {
    const d = parseServerDate(raw);
    if (!d) return '—';
    return d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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

document.addEventListener('keydown', (e) => {
    const tag = String(e.target?.tagName || '').toLowerCase();
    const editable = tag === 'input' || tag === 'textarea' || e.target?.isContentEditable;
    if (e.key === '/' && !editable && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        document.getElementById('billInvoiceSearch')?.focus();
    }
    if (e.key === 'Escape' && document.activeElement?.id === 'billInvoiceSearch') {
        document.getElementById('billInvoiceSearch').value = '';
        renderBilling(lastBillingSnapshot);
        document.getElementById('billInvoiceSearch').blur();
    }
});

loadStatus();
setInterval(loadStatus, 30000);
window.__forkMode = <?php echo $isForkMode ? 'true' : 'false'; ?>;
setTimeout(ensureBillingAuditLoaded, 1200);
</script>
</body>
</html>