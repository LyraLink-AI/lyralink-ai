<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
/* Fork mode from configuration only - it used to be inferred from the Host
 * header, and in that state this page skipped the login check below entirely. */
$isForkMode = lyra_is_fork_mode();
if (!lyra_admin_gate_ok()) {
    header('Location: /');
    exit;
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plesk + MySQL Ops - Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d11;
            --surface: #121920;
            --surface-soft: #161f28;
            --border: #253243;
            --text: #d8e6f4;
            --muted: #86a0b8;
            --accent: #12b5a5;
            --accent-soft: rgba(18, 181, 165, 0.2);
            --ok: #22c55e;
            --bad: #ef4444;
            --warn: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Mono', monospace;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(1000px 600px at -20% -30%, rgba(18, 181, 165, 0.2), transparent 60%),
                radial-gradient(900px 500px at 120% -20%, rgba(59, 130, 246, 0.16), transparent 60%),
                var(--bg);
        }

        nav {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            background: rgba(9, 13, 17, 0.92);
            backdrop-filter: blur(10px);
        }

        .nav-logo { height: 28px; mix-blend-mode: lighten; }
        .nav-title {
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.6px;
            color: #8be9de;
        }

        .nav-right { margin-left: auto; display: flex; gap: 8px; }
        .nav-link {
            text-decoration: none;
            color: var(--muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 11px;
            padding: 5px 10px;
        }
        .nav-link:hover { color: #bff9f2; border-color: var(--accent); }

        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 24px;
        }

        .hero {
            border: 1px solid rgba(18, 181, 165, 0.38);
            background: linear-gradient(145deg, rgba(18, 181, 165, 0.15), rgba(59, 130, 246, 0.1));
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 8px 40px rgba(18, 181, 165, 0.12);
        }

        .hero h1 {
            font-family: 'Syne', sans-serif;
            font-size: 26px;
            line-height: 1.1;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .hero p { font-size: 12px; color: #c7e5eb; line-height: 1.7; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin: 14px 0 18px;
        }

        .kpi {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            padding: 10px 12px;
        }

        .kpi .k {
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }

        .kpi .v {
            font-family: 'Syne', sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: #affcf4;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            overflow: hidden;
            min-width: 0;
        }

        .card-head {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-soft);
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            font-weight: 700;
        }

        .card-body { padding: 12px; }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .btn {
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            border-radius: 10px;
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .btn:hover { border-color: var(--accent); color: #c8fef9; }
        .btn.accent { border-color: rgba(18, 181, 165, 0.5); color: #bff9f2; background: var(--accent-soft); }

        .meta {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 8px;
            line-height: 1.6;
        }

        .state-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 10px;
            border: 1px solid;
            margin-right: 6px;
        }

        .state-pill.ok { border-color: rgba(34, 197, 94, 0.5); color: #a4f4bd; background: rgba(34, 197, 94, 0.13); }
        .state-pill.bad { border-color: rgba(239, 68, 68, 0.5); color: #ffbcbc; background: rgba(239, 68, 68, 0.13); }
        .state-pill.warn { border-color: rgba(245, 158, 11, 0.5); color: #ffd89f; background: rgba(245, 158, 11, 0.13); }

        .terminal {
            font-size: 11px;
            line-height: 1.5;
            color: #d0e6fb;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #0c1218;
            padding: 10px;
            overflow: auto;
            max-height: 360px;
            white-space: pre;
        }

        .db-table-wrap {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: auto;
            max-height: 460px;
        }

        .db-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
            font-size: 11px;
        }

        .db-table th,
        .db-table td {
            border-bottom: 1px solid rgba(37, 50, 67, 0.75);
            padding: 7px 8px;
            text-align: left;
            white-space: nowrap;
        }

        .db-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #16202a;
            color: #9dd3de;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 10px;
        }

        .search {
            width: 100%;
            border: 1px solid var(--border);
            background: #0d141b;
            color: var(--text);
            border-radius: 9px;
            padding: 8px 10px;
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            margin-bottom: 8px;
        }

        .muted { color: var(--muted); }

        @media (max-width: 1100px) {
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span style="color:#3f5b72">/</span>
    <span class="nav-title">Plesk + MySQL Ops</span>
    <div class="nav-right">
        <a href="/pages/admin.php" class="nav-link">Admin</a>
        <a href="/chat" class="nav-link">Chat</a>
    </div>
</nav>

<div class="page">
    <div class="hero">
        <h1>Operator Diagnostics</h1>
        <p>Live snapshot of Plesk internals and MySQL table health for this host. This page is read-only and is intended for quick operations checks from the admin panel.</p>
        <div class="kpi-grid">
            <div class="kpi"><div class="k">Database</div><div class="v" id="kDbName">-</div></div>
            <div class="kpi"><div class="k">Tables</div><div class="v" id="kTableCount">-</div></div>
            <div class="kpi"><div class="k">Rows (est)</div><div class="v" id="kRows">-</div></div>
            <div class="kpi"><div class="k">Storage</div><div class="v" id="kSize">-</div></div>
            <div class="kpi"><div class="k">Captured At</div><div class="v" id="kCaptured">-</div></div>
        </div>
        <div class="toolbar">
            <button class="btn accent" onclick="loadSnapshot()">Refresh Snapshot</button>
            <span class="meta" id="runtimeMeta">Runtime: -</span>
        </div>
        <?php if ($isForkMode): ?>
            <div class="meta" style="color:var(--warn)">Fork preview mode detected. Data queries remain read-only.</div>
        <?php endif; ?>
    </div>

    <div class="grid">
        <section class="card">
            <div class="card-head"><span>🖥️</span><div class="card-title">Plesk Runtime</div></div>
            <div class="card-body">
                <div id="pleskFlags" style="margin-bottom:8px"></div>
                <div class="meta">Subscription: <span id="subName" class="muted">-</span></div>
                <div class="meta" style="margin-top:8px">Plesk Version</div>
                <div class="terminal" id="pleskVersion">Loading...</div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><span>⏱️</span><div class="card-title">Scheduler Tasks</div></div>
            <div class="card-body">
                <div class="meta">Current Plesk subscription scheduler inventory.</div>
                <div class="terminal" id="pleskTasks">Loading...</div>
            </div>
        </section>
    </div>

    <section class="card" style="margin-bottom:12px">
        <div class="card-head"><span>🐘</span><div class="card-title">Plesk PHP Handlers</div></div>
        <div class="card-body">
            <div class="meta">Exact PHP handlers registered in Plesk for this server.</div>
            <div class="terminal" id="phpHandlers">Loading...</div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><span>🗃️</span><div class="card-title">MySQL Tables</div></div>
        <div class="card-body">
            <input class="search" id="tableSearch" placeholder="Filter table names..." oninput="renderTables()">
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Engine</th>
                            <th>Rows</th>
                            <th>Data MB</th>
                            <th>Index MB</th>
                            <th>Total MB</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody id="dbRows">
                        <tr><td colspan="7" class="muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
let snapshot = null;

function esc(v) {
    return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function num(v) {
    return Number(v || 0).toLocaleString();
}

function shortDate(raw) {
    if (!raw) return '-';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return String(raw);
    return d.toLocaleString();
}

function statePill(ok, label) {
    const cls = ok ? 'ok' : 'bad';
    const txt = ok ? 'OK' : 'ERROR';
    return '<span class="state-pill ' + cls + '">' + txt + ' · ' + esc(label) + '</span>';
}

async function loadSnapshot() {
    const ver = document.getElementById('pleskVersion');
    const tasks = document.getElementById('pleskTasks');
    const handlers = document.getElementById('phpHandlers');

    ver.textContent = 'Loading...';
    tasks.textContent = 'Loading...';
    handlers.textContent = 'Loading...';

    const res = await fetch('/api/plesk_admin.php?action=snapshot', { credentials: 'same-origin' }).catch(() => null);
    if (!res) {
        ver.textContent = 'Failed to reach API endpoint.';
        tasks.textContent = 'Failed to reach API endpoint.';
        handlers.textContent = 'Failed to reach API endpoint.';
        return;
    }

    const data = await res.json().catch(() => null);
    if (!data || !data.success) {
        const msg = data?.error || 'Failed to load snapshot';
        ver.textContent = msg;
        tasks.textContent = msg;
        handlers.textContent = msg;
        return;
    }

    snapshot = data;

    document.getElementById('runtimeMeta').textContent = 'Runtime: ' + (data.runtime?.user || 'unknown') + ' (uid ' + String(data.runtime?.uid ?? '-') + ')';
    document.getElementById('subName').textContent = data.plesk?.subscription || '-';
    document.getElementById('kCaptured').textContent = shortDate(data.captured_at);

    const meta = data.mysql?.meta || {};
    document.getElementById('kDbName').textContent = meta.database || '-';
    document.getElementById('kTableCount').textContent = num(meta.table_count || 0);
    document.getElementById('kRows').textContent = num(meta.row_estimate || 0);
    document.getElementById('kSize').textContent = Number(meta.total_mb || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' MB';

    const flags = [];
    flags.push(statePill(Boolean(data.plesk?.version?.ok), 'plesk version'));
    flags.push(statePill(Boolean(data.plesk?.php_handlers?.ok), 'php_handler --list'));
    flags.push(statePill(Boolean(data.plesk?.scheduled_tasks?.ok), 'scheduler --list'));
    document.getElementById('pleskFlags').innerHTML = flags.join('');

    ver.textContent = data.plesk?.version?.output || 'No output.';
    tasks.textContent = data.plesk?.scheduled_tasks?.output || 'No output.';
    handlers.textContent = data.plesk?.php_handlers?.output || 'No output.';

    renderTables();
}

function renderTables() {
    const tbody = document.getElementById('dbRows');
    const filter = (document.getElementById('tableSearch').value || '').trim().toLowerCase();
    const rows = Array.isArray(snapshot?.mysql?.tables) ? snapshot.mysql.tables : [];

    const filtered = rows.filter((r) => String(r.name || '').toLowerCase().includes(filter));
    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="muted">No matching tables.</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map((r) => {
        return '<tr>'
            + '<td>' + esc(r.name || '-') + '</td>'
            + '<td>' + esc(r.engine || '-') + '</td>'
            + '<td>' + num(r.rows || 0) + '</td>'
            + '<td>' + Number(r.data_mb || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) + '</td>'
            + '<td>' + Number(r.index_mb || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) + '</td>'
            + '<td>' + Number(r.total_mb || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) + '</td>'
            + '<td>' + esc(shortDate(r.updated_at)) + '</td>'
            + '</tr>';
    }).join('');
}

loadSnapshot();
</script>
</body>
</html>
