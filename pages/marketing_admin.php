<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/security.php';

if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
if (empty($_SESSION['user_id'])) {
    header('Location: /?login=1&redirect=' . urlencode('/pages/marketing_admin.php')); exit;
}

$adminUser = trim((string)api_get_secret('ADMIN_DEV_USERNAME', 'developer')) ?: 'developer';
$isAdmin = !empty($_SESSION['is_admin']) || (($_SESSION['username'] ?? '') === $adminUser);
if (!$isAdmin) {
    header('Location: /automation'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lyralink - Marketing Admin</title>
<link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;--accent:#7c3aed;--accent-light:#a78bfa;--text:#e2e8f0;--muted:#94a3b8;--ok:#22c55e;--warn:#f59e0b;--bad:#ef4444}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'DM Mono',monospace}
nav{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;position:sticky;top:0;background:rgba(10,10,15,.95)}
.nav-logo{height:28px;mix-blend-mode:lighten}.nav-title{font-family:'Syne',sans-serif;font-size:13px;color:var(--muted)}
.nav-links{margin-left:auto;display:flex;gap:8px}.nav-link{text-decoration:none;color:var(--muted);border:1px solid var(--border);padding:5px 10px;border-radius:999px;font-size:11px}
.wrap{max-width:1080px;margin:0 auto;padding:22px 16px 60px}
.h1{font-family:'Syne',sans-serif;font-size:25px;font-weight:800;margin:0 0 8px}.sub{color:var(--muted);font-size:12px;margin-bottom:14px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.kpi{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:11px}
.kpi .k{font-size:10px;color:var(--muted);text-transform:uppercase}.kpi .v{font-family:'Syne',sans-serif;font-size:20px;color:var(--accent-light)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-top:12px}
.card h3{margin:0 0 8px;font-family:'Syne',sans-serif;font-size:14px}
pre{white-space:pre-wrap;word-break:break-word;font-size:11px;color:var(--muted);line-height:1.6;background:#0d0d14;border:1px solid var(--border);border-radius:10px;padding:10px;max-height:220px;overflow:auto}
.table{width:100%;border-collapse:collapse;font-size:11px}.table th,.table td{border-bottom:1px solid var(--border);padding:8px 6px;text-align:left;vertical-align:top}
.table th{color:var(--muted);font-size:10px;text-transform:uppercase}
.btn{border:1px solid var(--border);background:#0d0d14;color:var(--text);border-radius:8px;padding:5px 8px;font-size:10px;cursor:pointer}
.btn:hover{border-color:var(--accent-light);color:var(--accent-light)}
.btn.ok{color:var(--ok);border-color:rgba(34,197,94,.35)} .btn.warn{color:var(--warn);border-color:rgba(245,158,11,.35)} .btn.bad{color:var(--bad);border-color:rgba(239,68,68,.35)}
input,select,textarea{width:100%;background:#0d0d14;border:1px solid var(--border);color:var(--text);border-radius:8px;padding:7px 8px;font-family:'DM Mono',monospace;font-size:11px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.full{grid-column:1 / -1}
.pill{display:inline-flex;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:10px;color:var(--muted)}
@media(max-width:900px){.grid{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}}
</style>
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>
<nav>
  <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
  <span class="nav-title">/ Marketing Admin</span>
  <div class="nav-links">
    <a class="nav-link" href="/automation">Automations</a>
    <a class="nav-link" href="/pages/admin.php">Admin</a>
    <a class="nav-link" href="/chat">Chat</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
  </div>
</nav>
<div class="wrap">
  <h1 class="h1">Marketing Intelligence Control</h1>
  <div class="sub">Admin-only growth operations, opportunity lifecycle, and recommendation promotion.</div>

  <div class="grid" id="kpis"></div>

  <div class="card">
    <h3>Authorization Status</h3>
    <div id="authStatus"></div>
  </div>

  <div class="card">
    <h3>Top Recommendations</h3>
    <table class="table" id="recommendationTable"></table>
  </div>

  <div class="card">
    <h3>Opportunity Lifecycle</h3>
    <table class="table" id="opportunityTable"></table>
  </div>

  <div class="card">
    <h3>Create / Update Experiment</h3>
    <div class="form-grid">
      <input id="expId" placeholder="Experiment ID (blank for new)">
      <select id="expStatus">
        <option value="draft">draft</option>
        <option value="active">active</option>
        <option value="completed">completed</option>
        <option value="failed">failed</option>
      </select>
      <input id="expAudience" placeholder="Target audience">
      <input id="expVariable" placeholder="Variable">
      <input id="expControl" placeholder="Control / baseline" class="full">
      <textarea id="expHypothesis" rows="3" placeholder="Hypothesis" class="full"></textarea>
      <textarea id="expExpected" rows="3" placeholder="Expected outcome" class="full"></textarea>
    </div>
    <div style="margin-top:8px"><button class="btn ok" onclick="saveExperiment()">Save Experiment</button></div>
  </div>

  <div class="card">
    <h3>Recent Experiments</h3>
    <table class="table" id="experimentTable"></table>
  </div>

  <div class="card">
    <h3>Latest Report Summary</h3>
    <pre id="summaryBox">Loading...</pre>
  </div>
</div>

<script>
async function post(action, payload = {}) {
  const body = new URLSearchParams({ action, ...payload });
  const r = await fetch('/api/marketing.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
  return r.json();
}

function esc(v){return String(v ?? '').replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));}

async function refresh() {
  const d = await fetch('/api/marketing.php?action=admin_growth_snapshot', { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ success:false, error:'network error' }));
  if (!d.success) {
    document.getElementById('summaryBox').textContent = d.error || 'Failed to load snapshot';
    return;
  }

  const report = d.latest_report || {};
  const growth = report.growth_report || {};
  const recommendations = Array.isArray(growth.recommendations) ? growth.recommendations : [];
  const opportunities = Array.isArray(d.opportunities) ? d.opportunities : [];
  const experiments = Array.isArray(d.experiments) ? d.experiments : [];

  document.getElementById('kpis').innerHTML = [
    ['Recommendations', recommendations.length],
    ['Opportunities', opportunities.length],
    ['Experiments', experiments.length],
    ['Run Duration', (d.latest_run?.duration_seconds ? Math.round(Number(d.latest_run.duration_seconds)) + 's' : 'n/a')]
  ].map(k => `<div class="kpi"><div class="k">${k[0]}</div><div class="v">${k[1]}</div></div>`).join('');

  const auth = d.authorization || {};
  const authRows = Object.keys(auth).map(k => `<span class="pill" style="margin-right:6px">${esc(k)}: ${auth[k]?.allow ? 'enabled' : 'blocked'}</span>`).join('');
  document.getElementById('authStatus').innerHTML = authRows || '<span class="pill">No auth data</span>';

  const recHead = '<tr><th>Priority</th><th>Audience</th><th>Recommendation</th><th>Action</th></tr>';
  const recRows = recommendations.map(r => {
    const packed = encodeURIComponent(JSON.stringify(r));
    return `<tr>
    <td>${esc(r.priority || 'P3')}</td>
    <td>${esc(r.audience || 'general')}</td>
    <td>${esc(r.recommendation || '')}</td>
    <td><button class="btn" onclick="promoteRecommendation('${packed}')">Promote</button></td>
  </tr>`;
  }).join('');
  document.getElementById('recommendationTable').innerHTML = recHead + (recRows || '<tr><td colspan="4">No recommendations available.</td></tr>');

  const oppHead = '<tr><th>ID</th><th>Priority</th><th>Audience</th><th>Problem</th><th>Status</th><th>Actions</th></tr>';
  const oppRows = opportunities.slice(0, 100).map(o => `<tr>
    <td>${Number(o.id || 0)}</td>
    <td>${esc(o.priority || 'P3')} (${esc(o.score || 0)})</td>
    <td>${esc(o.audience || '')}</td>
    <td>${esc(o.problem || '')}</td>
    <td>${esc(o.status || '')}</td>
    <td>
      <button class="btn ok" onclick="setOpportunityStatus(${Number(o.id || 0)}, 'open')">Open</button>
      <button class="btn warn" onclick="setOpportunityStatus(${Number(o.id || 0)}, 'monitoring')">Monitor</button>
      <button class="btn bad" onclick="setOpportunityStatus(${Number(o.id || 0)}, 'archived')">Archive</button>
    </td>
  </tr>`).join('');
  document.getElementById('opportunityTable').innerHTML = oppHead + (oppRows || '<tr><td colspan="6">No opportunities yet.</td></tr>');

  const expHead = '<tr><th>ID</th><th>Status</th><th>Audience</th><th>Hypothesis</th><th>Variable</th><th>Expected</th></tr>';
  const expRows = experiments.slice(0, 100).map(e => `<tr>
    <td>${Number(e.id || 0)}</td>
    <td>${esc(e.status || 'draft')}</td>
    <td>${esc(e.target_audience || '')}</td>
    <td>${esc(e.hypothesis || '')}</td>
    <td>${esc(e.variable || '')}</td>
    <td>${esc(e.expected_outcome || '')}</td>
  </tr>`).join('');
  document.getElementById('experimentTable').innerHTML = expHead + (expRows || '<tr><td colspan="6">No experiments yet.</td></tr>');

  const summary = {
    latest_run: d.latest_run || null,
    executive_summary: growth.executive_summary || {},
    recommendation_count: recommendations.length,
    opportunity_count: opportunities.length,
    experiment_count: experiments.length,
  };
  document.getElementById('summaryBox').textContent = JSON.stringify(summary, null, 2);
}

async function setOpportunityStatus(id, status) {
  if (!id) return;
  const d = await post('admin_opportunity_status', { id, status });
  if (!d.success) { alert(d.error || 'Status update failed'); return; }
  refresh();
}

async function saveExperiment() {
  const id = document.getElementById('expId').value.trim();
  const hypothesis = document.getElementById('expHypothesis').value.trim();
  const target_audience = document.getElementById('expAudience').value.trim();
  const variable = document.getElementById('expVariable').value.trim();
  const control_baseline = document.getElementById('expControl').value.trim();
  const expected_outcome = document.getElementById('expExpected').value.trim();
  const status = document.getElementById('expStatus').value;
  const d = await post('admin_save_experiment', { id, hypothesis, target_audience, variable, control_baseline, expected_outcome, status });
  if (!d.success) { alert(d.error || 'Save failed'); return; }
  refresh();
}

async function promoteRecommendation(raw) {
  const item = JSON.parse(decodeURIComponent(raw));
  const d = await post('admin_promote_recommendation', {
    recommendation: item.recommendation || '',
    audience: item.audience || 'general',
    priority: item.priority || 'P3'
  });
  if (!d.success) { alert(d.error || 'Promote failed'); return; }
  refresh();
}

refresh();
</script>
</body>
</html>
