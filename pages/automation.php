<?php
session_start();
require_once __DIR__ . '/../api/security.php';
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
if (empty($_SESSION['user_id'])) {
    header('Location: /?login=1&redirect=' . urlencode('/automation')); exit;
}
$marketingAdminUser = trim((string)api_get_secret('ADMIN_DEV_USERNAME', 'developer')) ?: 'developer';
$canViewMarketingReports = !empty($_SESSION['is_admin']) || (($_SESSION['username'] ?? '') === $marketingAdminUser);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lyralink — Automations</title>
<link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0f;--surface:#111118;--surface2:#16161f;--border:#1e1e2e;
  --accent:#7c3aed;--accent-glow:rgba(124,58,237,0.25);--accent-light:#a78bfa;
  --text:#e2e8f0;--text-muted:#64748b;--text-dim:#94a3b8;
  --ok:#22c55e;--ok-bg:rgba(34,197,94,0.1);--ok-border:rgba(34,197,94,0.25);
  --err:#ef4444;--err-bg:rgba(239,68,68,0.1);--err-border:rgba(239,68,68,0.25);
  --warn:#f59e0b;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;top:-200px;left:25%;width:650px;height:420px;background:radial-gradient(ellipse,rgba(124,58,237,0.07),transparent 65%);pointer-events:none}

nav{padding:13px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,0.94);backdrop-filter:blur(14px);z-index:30}
.nav-logo{height:28px;mix-blend-mode:lighten}
.nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text-muted)}
.nav-links{display:flex;gap:8px;margin-left:auto}
.nav-link{color:var(--text-muted);text-decoration:none;font-size:12px;border:1px solid var(--border);padding:5px 12px;border-radius:20px;transition:.2s}
.nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

.page{max-width:960px;margin:0 auto;padding:36px 20px 80px;position:relative;z-index:1}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:24px;flex-wrap:wrap}
h1{font-family:'Syne',sans-serif;font-size:24px;font-weight:800}
h1 span{color:var(--accent-light)}
.quick-jump{display:flex;gap:8px;flex-wrap:wrap;margin:-6px 0 14px}
.quick-jump a{color:var(--text-muted);text-decoration:none;font-size:11px;border:1px solid var(--border);padding:6px 12px;border-radius:999px;background:rgba(124,58,237,.08);transition:.2s}
.quick-jump a:hover{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,.14)}
.filter-row{display:flex;gap:8px;align-items:center;margin:0 0 14px;flex-wrap:wrap}
.filter-input{flex:1;min-width:220px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:9px;padding:8px 10px;font-family:'DM Mono',monospace;font-size:12px;outline:none}
.filter-input:focus{border-color:var(--accent)}
.filter-hint{font-size:10px;color:var(--text-muted)}
.plan-tag{font-size:11px;padding:3px 10px;border-radius:20px;border:1px solid rgba(124,58,237,.3);background:rgba(124,58,237,.1);color:var(--accent-light);margin-top:6px;display:inline-block}
.usage-bar-wrap{height:3px;background:var(--border);border-radius:4px;margin-top:8px;overflow:hidden;width:160px}
.usage-bar{height:100%;background:var(--accent);border-radius:4px;transition:width .3s}

/* EMPTY STATE */
.empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.empty-icon{font-size:40px;margin-bottom:14px}

/* GRID */
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:700px){.grid{grid-template-columns:1fr}}

/* AUTO CARD */
.auto-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;position:relative;transition:border-color .2s}
.auto-card:hover{border-color:rgba(124,58,237,.35)}
.auto-card.disabled{opacity:.58}
.auto-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px}
.auto-name{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;line-height:1.3}
.auto-schedule{font-size:10px;color:var(--text-muted);margin-top:3px;display:flex;align-items:center;gap:5px}
.auto-schedule .dot{width:6px;height:6px;border-radius:50%;background:var(--ok);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.auto-card.disabled .auto-schedule .dot{background:var(--text-muted);animation:none}
.auto-prompt{font-size:11px;color:var(--text-dim);line-height:1.55;margin-bottom:10px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.auto-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
.meta-tag{padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;border:1px solid var(--border);color:var(--text-muted)}
.meta-tag.ok{border-color:var(--ok-border);color:var(--ok);background:var(--ok-bg)}
.meta-tag.err{border-color:var(--err-border);color:var(--err);background:var(--err-bg)}
.auto-actions{display:flex;gap:7px;margin-top:12px;flex-wrap:wrap}

/* BUTTONS */
.btn{padding:7px 13px;border-radius:9px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;border:none;transition:.2s;display:inline-flex;align-items:center;gap:5px}
.btn-primary{background:var(--accent);color:#fff;box-shadow:0 0 16px var(--accent-glow)}
.btn-primary:hover{background:#6d28d9}
.btn-outline{border:1px solid var(--border);color:var(--text-muted);background:none}
.btn-outline:hover{border-color:var(--accent-light);color:var(--accent-light)}
.btn-danger{border:1px solid var(--err-border);color:var(--err);background:none}
.btn-danger:hover{background:var(--err-bg)}
.btn-sm{padding:5px 10px;font-size:10px}
.btn:disabled{opacity:.45;cursor:not-allowed}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:200;display:none;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:28px;max-width:520px;width:100%;margin:auto;position:relative}
.modal h2{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;margin-bottom:18px}
.modal-close{position:absolute;top:16px;right:16px;background:none;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;padding:3px 7px;cursor:pointer;font-size:14px}
.modal-close:hover{border-color:var(--accent-light);color:var(--accent-light)}
.form-group{margin-bottom:14px}
label{display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px}
input[type=text],input[type=url],input[type=number],select,textarea{
  width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);
  border-radius:9px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;transition:border-color .2s
}
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
textarea{resize:vertical;min-height:100px}
select option{background:var(--surface)}
.interval-row{display:grid;grid-template-columns:80px 1fr;gap:8px}
.hint{font-size:10px;color:var(--text-muted);margin-top:4px;line-height:1.5}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}

/* RESULT PANEL */
.result-panel{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-top:14px;font-size:12px;color:var(--text-dim);line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:300px;overflow:auto;display:none}
.result-panel.show{display:block}

/* HISTORY */
.history-list{display:flex;flex-direction:column;gap:8px;max-height:380px;overflow-y:auto;margin-top:12px}
.history-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px}
.history-item .h-header{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:11px}
.history-item .h-body{font-size:11px;color:var(--text-muted);white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:auto}

/* OPERATOR QUEUE */
.operator-panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px 18px;margin-bottom:16px;display:none}
.operator-panel.open{display:block}
.operator-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.operator-head h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:800}
.operator-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
.operator-stat{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px;text-align:center}
.operator-stat .v{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--accent-light)}
.operator-stat .l{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.operator-list{display:flex;flex-direction:column;gap:8px;margin-top:12px;max-height:280px;overflow:auto}
.operator-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.operator-item.failed{border-color:var(--err-border)}
.operator-meta{font-size:11px;color:var(--text-muted);line-height:1.6}
.operator-title{font-size:12px;color:var(--text);font-weight:700}
.operator-actions{display:flex;gap:6px;flex-wrap:wrap}

/* TOAST */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 18px;font-size:12px;z-index:999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap}
.toast.show{opacity:1}
.toast.success{border-color:var(--ok);color:var(--ok)}
.toast.error{border-color:var(--err);color:var(--err)}

/* NEW AUTOMATION TOP BANNER */
.add-banner{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:20px;flex-wrap:wrap}
.add-banner p{font-size:13px;color:var(--text-muted);max-width:60ch}

@media(max-width:640px){.page-header,.add-banner{flex-direction:column;align-items:flex-start}.add-banner .btn{width:100%;justify-content:center}.operator-summary{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.quick-jump{flex-wrap:nowrap;overflow-x:auto;padding-bottom:2px}.quick-jump a{white-space:nowrap}.filter-input{min-width:0;width:100%}}
</style>
<link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
  <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
  <span class="nav-title">/ Automations</span>
  <div class="nav-links">
    <a href="/chat" class="nav-link">Chat</a>
    <a href="/pages/pricing" class="nav-link">Upgrade</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
  </div>
</nav>

<div class="page">
  <div class="quick-jump" aria-label="Automation sections">
    <a href="#operatorPanel">Operator Queue</a>
    <?php if ($canViewMarketingReports): ?>
    <a href="#marketingReportPanel">Marketing Report</a>
    <?php endif; ?>
    <a href="#autoGrid">Automation List</a>
  </div>

  <div class="page-header">
    <div>
      <h1>Your <span>Automations</span></h1>
      <div id="planInfo"></div>
    </div>
    <button class="btn btn-primary" onclick="openCreate()" id="addBtn">＋ New Automation</button>
  </div>

  <div class="add-banner">
    <p>Set a prompt, pick a schedule, and Lyralink runs it automatically — daily summaries, reminders, reports, and more. Results land in your run history.</p>
    <button class="btn btn-primary btn-sm" onclick="openCreate()">Get started →</button>
  </div>

  <div class="filter-row">
    <input id="automationSearch" class="filter-input" type="text" placeholder="Filter automations by name, schedule, or prompt..." oninput="render()">
    <span class="filter-hint">Shortcuts: / focus filter, n new automation</span>
  </div>

  <section id="operatorPanel" class="operator-panel" aria-live="polite">
    <div class="operator-head">
      <h3>Operator Error Queue</h3>
      <button class="btn btn-outline btn-sm" onclick="refreshOperatorQueue()">Refresh Queue</button>
    </div>
    <div id="operatorSummary" class="operator-summary"></div>
    <div id="operatorRuns" class="operator-list"></div>
  </section>

  <?php if ($canViewMarketingReports): ?>
  <section id="marketingReportPanel" class="operator-panel open" aria-live="polite">
    <div class="operator-head">
      <h3>Marketing Department Report</h3>
      <button class="btn btn-outline btn-sm" onclick="refreshMarketingReport()">Refresh Report</button>
    </div>
    <div id="marketingReportSummary" class="operator-summary"></div>
    <div id="marketingReportContent" class="result-panel show" style="margin-top:12px">Loading marketing report...</div>
    <div id="marketingGrowthContent" class="result-panel show" style="margin-top:12px">Loading growth opportunities...</div>
  </section>
  <?php endif; ?>

  <div id="autoGrid" class="grid"></div>
  <div id="emptyState" class="empty" style="display:none">
    <div class="empty-icon">⚡</div>
    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin-bottom:8px">No automations yet</div>
    <p style="font-size:13px;max-width:400px;margin:0 auto">Create one to run prompts on a schedule — every hour, day, week, or month.</p>
    <button class="btn btn-primary" onclick="openCreate()" style="margin-top:20px">＋ Create your first automation</button>
  </div>
</div>

<!-- CREATE / EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <h2 id="modalTitle">New Automation</h2>
    <input type="hidden" id="editId">

    <div class="form-group">
      <label>Name <span style="color:var(--err)">*</span></label>
      <input type="text" id="fName" placeholder="e.g. Daily standup summary" maxlength="200">
    </div>

    <div class="form-group">
      <label>Prompt <span style="color:var(--err)">*</span></label>
      <textarea id="fPrompt" maxlength="4000" placeholder="Write the task you want Lyralink to run automatically.&#10;&#10;Examples:&#10;• Summarise news about AI today in 5 bullet points.&#10;• Give me a morning motivation quote.&#10;• List 3 things I should focus on this week."></textarea>
      <div class="hint" id="promptCount">0 / 4000</div>
    </div>

    <div class="form-group">
      <label>Run every</label>
      <div class="interval-row">
        <input type="number" id="fIntervalValue" value="1" min="1" max="999">
        <select id="fIntervalUnit">
          <option value="minute">Minute(s)</option>
          <option value="hour">Hour(s)</option>
          <option value="day" selected>Day(s)</option>
          <option value="week">Week(s)</option>
          <option value="month">Month(s)</option>
        </select>
      </div>
      <div class="hint">First run will be at <span id="nextRunPreview">—</span></div>
    </div>

    <div class="form-group">
      <label>Webhook URL (optional)</label>
      <input type="url" id="fWebhook" placeholder="https://discord.com/api/webhooks/..." oninput="updateWebhookHint()">
      <div class="hint" id="webhookHint">Paste a Discord webhook URL for rich embeds, or any HTTPS URL to receive a JSON POST.</div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveAuto()">Create Automation</button>
    </div>
  </div>
</div>

<!-- RESULT MODAL -->
<div class="modal-overlay" id="resultModal">
  <div class="modal" style="max-width:620px">
    <button class="modal-close" onclick="closeResultModal()">✕</button>
    <h2 id="resultModalTitle">Last Result</h2>
    <div id="resultContent" class="result-panel show" style="margin-top:0"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeResultModal()">Close</button>
    </div>
  </div>
</div>

<!-- HISTORY MODAL -->
<div class="modal-overlay" id="histModal">
  <div class="modal" style="max-width:620px">
    <button class="modal-close" onclick="closeHistModal()">✕</button>
    <h2 id="histModalTitle">Run History</h2>
    <div id="histContent" class="history-list"><div style="color:var(--text-muted);font-size:12px">Loading…</div></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeHistModal()">Close</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = '/api/automation.php';
const CAN_VIEW_MARKETING_REPORTS = <?php echo $canViewMarketingReports ? 'true' : 'false'; ?>;
let automations = [], maxAutos = 2, plan = 'free', orgContext = {}, isOperator = false;

// ── INIT ──────────────────────────────────────────────────────────────────────
async function init() {
  const d = await call('list', {}, 'GET');
  if (!d.success) { showToast(d.error || 'Failed to load', 'error'); return; }
  automations = d.automations || [];
  maxAutos = d.max || 2;
  plan = d.plan || 'free';
  orgContext = d.org || {};
  const role = String(orgContext.role || orgContext.membership_role || '').toLowerCase();
  isOperator = role === 'owner' || role === 'admin';

  const used = automations.length;
  document.getElementById('planInfo').innerHTML = `
    <span class="plan-tag">${plan} — ${used}/${maxAutos} automations</span>
    <div class="usage-bar-wrap"><div class="usage-bar" style="width:${Math.round(used/maxAutos*100)}%"></div></div>
  `;
  document.getElementById('addBtn').disabled = (used >= maxAutos);

  render();
  toggleOperatorPanel();
  if (isOperator) {
    refreshOperatorQueue();
  }
  if (CAN_VIEW_MARKETING_REPORTS) {
    refreshMarketingReport();
  }
}

// ── RENDER ────────────────────────────────────────────────────────────────────
function render() {
  const grid = document.getElementById('autoGrid');
  const empty = document.getElementById('emptyState');
  const q = String(document.getElementById('automationSearch')?.value || '').trim().toLowerCase();
  const filtered = q
    ? automations.filter(a => [a.name, a.prompt, a.interval_unit, a.interval_value].map(v => String(v || '').toLowerCase()).join(' ').includes(q))
    : automations;
  if (!automations.length) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
  if (!filtered.length) {
    empty.style.display = 'none';
    grid.innerHTML = '<div class="auto-card" style="grid-column:1 / -1"><div class="auto-prompt">No automations matched your filter.</div></div>';
    return;
  }
  empty.style.display = 'none';
  grid.innerHTML = filtered.map(a => {
    const statusClass = a.last_status === 'ok' ? 'ok' : (a.last_status === 'error' ? 'err' : '');
    const statusLabel = a.last_status === 'ok' ? '✓ Last run OK' : (a.last_status === 'error' ? '✗ Last run failed' : 'Never run');
    const nextLabel = a.enabled ? `Next: ${formatDate(a.next_run_at)}` : 'Paused';
    return `<div class="auto-card${a.enabled ? '' : ' disabled'}" id="card-${a.id}">
      <div class="auto-card-top">
        <div>
          <div class="auto-name">${esc(a.name)}</div>
          <div class="auto-schedule">
            <span class="dot"></span>
            Every ${a.interval_value} ${a.interval_unit}${a.interval_value > 1 ? 's' : ''} · ${nextLabel}
          </div>
        </div>
        <span class="meta-tag ${statusClass}" style="flex-shrink:0">${statusLabel}</span>
      </div>
      <div class="auto-prompt">${esc(a.prompt)}</div>
      <div class="auto-meta">
        <span class="meta-tag">🔁 ${a.run_count} run${a.run_count !== 1 ? 's' : ''}</span>
        ${a.last_run_at ? `<span class="meta-tag">Last: ${formatDate(a.last_run_at)}</span>` : ''}
        ${a.webhook_url ? `<span class="meta-tag">🔗 Webhook</span>` : ''}
      </div>
      <div class="auto-actions">
        <button class="btn btn-outline btn-sm" onclick="runNow(${a.id})" id="run-${a.id}">▶ Run Now</button>
        ${a.last_result ? `<button class="btn btn-outline btn-sm" onclick="showResult(${a.id})">Result</button>` : ''}
        <button class="btn btn-outline btn-sm" onclick="showHistory(${a.id},'${esc(a.name)}')">History</button>
        <button class="btn btn-outline btn-sm" onclick="toggleAuto(${a.id})">${a.enabled ? 'Pause' : 'Resume'}</button>
        <button class="btn btn-outline btn-sm" onclick="openEdit(${a.id})">Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteAuto(${a.id})">Delete</button>
      </div>
    </div>`;
  }).join('');
}

// ── CREATE / EDIT ─────────────────────────────────────────────────────────────
function openCreate() {
  if (automations.length >= maxAutos) {
    showToast('Upgrade your plan to add more automations', 'error'); return;
  }
  document.getElementById('editId').value = '';
  document.getElementById('fName').value = '';
  document.getElementById('fPrompt').value = '';
  document.getElementById('fIntervalValue').value = 1;
  document.getElementById('fIntervalUnit').value = 'day';
  document.getElementById('fWebhook').value = '';
  document.getElementById('modalTitle').textContent = 'New Automation';
  document.getElementById('saveBtn').textContent = 'Create Automation';
  updateNextPreview();
  updatePromptCount();
  document.getElementById('editModal').classList.add('open');
}

function openEdit(id) {
  const a = automations.find(x => x.id === id);
  if (!a) return;
  document.getElementById('editId').value = id;
  document.getElementById('fName').value = a.name;
  document.getElementById('fPrompt').value = a.prompt;
  document.getElementById('fIntervalValue').value = a.interval_value;
  document.getElementById('fIntervalUnit').value = a.interval_unit;
  document.getElementById('fWebhook').value = a.webhook_url || '';
  document.getElementById('modalTitle').textContent = 'Edit Automation';
  document.getElementById('saveBtn').textContent = 'Save Changes';
  updateNextPreview();
  updatePromptCount();
  document.getElementById('editModal').classList.add('open');
}

function closeModal() { document.getElementById('editModal').classList.remove('open'); }

async function saveAuto() {
  const id      = document.getElementById('editId').value;
  const name    = document.getElementById('fName').value.trim();
  const prompt  = document.getElementById('fPrompt').value.trim();
  const intVal  = document.getElementById('fIntervalValue').value;
  const intUnit = document.getElementById('fIntervalUnit').value;
  const webhook = document.getElementById('fWebhook').value.trim();

  if (!name || !prompt) { showToast('Name and prompt are required', 'error'); return; }

  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  const data = { name, prompt, interval_value: intVal, interval_unit: intUnit, webhook_url: webhook };
  const action = id ? 'update' : 'create';
  if (id) data.id = id;
  const d = await call(action, data);
  btn.disabled = false;
  if (d.success) {
    closeModal();
    showToast(id ? '✓ Automation updated' : '✓ Automation created', 'success');
    await init();
  } else {
    showToast(d.error || 'Save failed', 'error');
  }
}

// ── TOGGLE ────────────────────────────────────────────────────────────────────
async function toggleAuto(id) {
  const d = await call('toggle', { id });
  if (d.success) {
    const a = automations.find(x => x.id === id);
    if (a) a.enabled = d.enabled;
    render();
    showToast(d.enabled ? 'Automation resumed' : 'Automation paused', 'success');
  }
}

// ── DELETE ────────────────────────────────────────────────────────────────────
async function deleteAuto(id) {
  const a = automations.find(x => x.id === id);
  if (!confirm(`Delete "${a?.name || 'this automation'}"? This cannot be undone.`)) return;
  const d = await call('delete', { id });
  if (d.success) { showToast('Deleted', 'success'); await init(); }
  else showToast(d.error || 'Delete failed', 'error');
}

// ── RUN NOW ───────────────────────────────────────────────────────────────────
async function runNow(id) {
  const btn = document.getElementById(`run-${id}`);
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Running…'; }
  const d = await call('run_now', { id });
  if (btn) { btn.disabled = false; btn.textContent = '▶ Run Now'; }
  if (d.success) {
    showToast('✓ Run complete', 'success');
    await init();
    if (d.result) showResult(id, d.result);
  } else {
    showToast('Run failed: ' + (d.error || 'Unknown error'), 'error');
  }
}

// ── RESULT MODAL ──────────────────────────────────────────────────────────────
function showResult(id, overrideText) {
  const a = automations.find(x => x.id === id);
  const text = overrideText ?? (a?.last_result || '(no result)');
  document.getElementById('resultModalTitle').textContent = `Result — ${a?.name || id}`;
  document.getElementById('resultContent').textContent = text;
  document.getElementById('resultModal').classList.add('open');
}
function closeResultModal() { document.getElementById('resultModal').classList.remove('open'); }

// ── HISTORY MODAL ─────────────────────────────────────────────────────────────
async function showHistory(id, name) {
  document.getElementById('histModalTitle').textContent = `History — ${name}`;
  document.getElementById('histContent').innerHTML = '<div style="color:var(--text-muted);font-size:12px">Loading…</div>';
  document.getElementById('histModal').classList.add('open');
  const d = await call('history', { automation_id: id }, 'GET');
  if (!d.success || !d.runs.length) {
    document.getElementById('histContent').innerHTML = '<div style="color:var(--text-muted);font-size:12px">No runs recorded yet.</div>';
    return;
  }
  document.getElementById('histContent').innerHTML = d.runs.map(r => `
    <div class="history-item">
      <div class="h-header">
        <span class="meta-tag ${r.status === 'ok' ? 'ok' : 'err'}">${r.status === 'ok' ? '✓ OK' : '✗ Error'}</span>
        <span style="color:var(--text-muted)">${formatDate(r.ran_at)}</span>
      </div>
      <div class="h-body">${esc(r.status === 'ok' ? (r.result_preview || '') : (r.error_message || ''))}</div>
    </div>
  `).join('');
}
function closeHistModal() { document.getElementById('histModal').classList.remove('open'); }

// ── OPERATOR QUEUE ────────────────────────────────────────────────────────────
function toggleOperatorPanel() {
  const panel = document.getElementById('operatorPanel');
  if (!panel) return;
  panel.classList.toggle('open', isOperator);
}

async function refreshOperatorQueue() {
  if (!isOperator) return;
  const summaryEl = document.getElementById('operatorSummary');
  const runsEl = document.getElementById('operatorRuns');
  if (!summaryEl || !runsEl) return;

  summaryEl.innerHTML = '<div class="operator-stat"><div class="v">...</div><div class="l">Loading</div></div>';
  runsEl.innerHTML = '<div class="operator-item"><div class="operator-meta">Loading failed runs...</div></div>';

  const d = await call('operator_queue', {}, 'GET').catch(() => null);
  if (!d?.success) {
    summaryEl.innerHTML = '<div class="operator-stat"><div class="v">0</div><div class="l">Queue</div></div>';
    runsEl.innerHTML = `<div class="operator-item failed"><div class="operator-meta">${esc(d?.error || 'Failed to load operator queue')}</div></div>`;
    return;
  }

  const s = d.summary || {};
  summaryEl.innerHTML = [
    { label: 'Unacked Fails', value: s.unacked_failed_runs ?? 0 },
    { label: 'Failed Autos', value: s.failed_automations ?? 0 },
    { label: 'Enabled', value: s.enabled_automations ?? 0 },
    { label: 'Total', value: s.total_automations ?? 0 },
  ].map(item => `<div class="operator-stat"><div class="v">${item.value}</div><div class="l">${item.label}</div></div>`).join('');

  if (!Array.isArray(d.runs) || !d.runs.length) {
    runsEl.innerHTML = '<div class="operator-item"><div class="operator-meta">No unacknowledged failed runs in queue.</div></div>';
    return;
  }

  runsEl.innerHTML = d.runs.map(run => {
    const runId = Number(run.id || 0);
    const automationId = Number(run.automation_id || 0);
    return `<div class="operator-item failed" id="op-run-${runId}">
      <div>
        <div class="operator-title">${esc(run.automation_name || 'Automation #' + automationId)}</div>
        <div class="operator-meta">Run #${runId} · Automation #${automationId} · ${esc(run.username || 'unknown user')}</div>
        <div class="operator-meta">${esc(formatDate(run.ran_at))}</div>
        <div class="operator-meta" style="color:var(--err)">${esc(run.error_message || 'No error payload')}</div>
      </div>
      <div class="operator-actions">
        <button class="btn btn-outline btn-sm" onclick="ackOperatorRun(${runId})">Acknowledge</button>
        <button class="btn btn-danger btn-sm" onclick="retryOperatorAutomation(${automationId}, ${runId})">Retry</button>
      </div>
    </div>`;
  }).join('');
}

async function refreshMarketingReport() {
  const summaryEl = document.getElementById('marketingReportSummary');
  const contentEl = document.getElementById('marketingReportContent');
  const growthEl = document.getElementById('marketingGrowthContent');
  if (!summaryEl || !contentEl || !growthEl) return;

  summaryEl.innerHTML = '<div class="operator-stat"><div class="v">...</div><div class="l">Loading</div></div>';
  contentEl.textContent = 'Loading marketing report...';
  growthEl.textContent = 'Loading growth opportunities...';

  const d = await fetch('/api/marketing.php?action=marketing_report', { credentials: 'same-origin' })
    .then(r => r.json())
    .catch(() => ({ success: false, error: 'Network error' }));

  if (!d?.success) {
    summaryEl.innerHTML = '<div class="operator-stat"><div class="v">0</div><div class="l">Report</div></div>';
    contentEl.textContent = d?.error || 'Failed to load marketing report.';
    growthEl.textContent = 'Growth opportunities unavailable.';
    return;
  }

  const latest = d.latest_run || {};
  const report = d.latest_report || {};
  const stats = report.stats || {};
  const growth = report.growth_report || {};
  const opportunities = Array.isArray(growth.opportunities) ? growth.opportunities : [];
  const recommendations = Array.isArray(growth.recommendations) ? growth.recommendations : [];
  const executive = growth.executive_summary || {};
  const campaigns = Array.isArray(d.campaigns) ? d.campaigns : [];

  summaryEl.innerHTML = [
    { label: 'Campaigns', value: campaigns.length },
    { label: 'Analysis Mode', value: stats.analysis_only ? 'yes' : 'no' },
    { label: 'Public Posting', value: stats.public_posts_allowed ? 'enabled' : 'blocked' },
    { label: 'Opportunities', value: opportunities.length },
    { label: 'Recommendations', value: recommendations.length },
  ].map(item => `<div class="operator-stat"><div class="v">${item.value}</div><div class="l">${item.label}</div></div>`).join('');

  const lines = [];
  lines.push(`Latest run: ${latest.started_at ? formatDate(latest.started_at) : 'No marketing run yet'}`);
  lines.push(`Duration: ${latest.duration_seconds ? Math.round(Number(latest.duration_seconds)) + 's' : 'n/a'}`);
  lines.push(`Top segment: ${executive.top_segment || 'n/a'}`);
  lines.push(`Primary focus: ${executive.focus || 'n/a'}`);
  lines.push(`Benchmark status: ${executive.benchmark_status || 'n/a'}`);
  lines.push(`Analysis-only mode: ${stats.analysis_only ? 'yes' : 'no'}`);
  lines.push(`Public posting allowed: ${stats.public_posts_allowed ? 'yes' : 'no'}`);
  lines.push(`YouTube connected: ${d.youtube_connected ? (d.youtube_channel_title || 'yes') : 'no'}`);

  if (Array.isArray(report.actions) && report.actions.length) {
    lines.push('');
    lines.push('Recent actions:');
    report.actions.slice(0, 5).forEach(a => {
      lines.push(`${a.ok ? 'OK' : 'FAIL'} - ${a.title}: ${a.detail}`);
    });
  }

  if (Array.isArray(report.findings) && report.findings.length) {
    lines.push('');
    lines.push('Recent findings:');
    report.findings.slice(0, 5).forEach(f => {
      lines.push(`[${String(f.severity || '').toUpperCase()}] ${f.title}: ${f.detail}`);
    });
  }

  if (campaigns.length) {
    lines.push('');
    lines.push('Recent campaigns:');
    campaigns.slice(0, 5).forEach(c => {
      const suffix = c.youtube_url ? ` -> ${c.youtube_url}` : '';
      lines.push(`${c.title} (${c.platform}/${c.status})${suffix}`);
    });
  }

  contentEl.textContent = lines.join('\n');

  const growthLines = [];
  growthLines.push('Top recommendations:');
  if (!recommendations.length) {
    growthLines.push('No recommendation set yet.');
  } else {
    recommendations.slice(0, 5).forEach(r => {
      growthLines.push(`[${r.priority || 'P3'}] ${r.audience || 'general'}: ${r.recommendation || 'No recommendation detail'}`);
    });
  }

  growthLines.push('');
  growthLines.push('Scored opportunities:');
  if (!opportunities.length) {
    growthLines.push('No opportunities scored yet.');
  } else {
    opportunities.slice(0, 8).forEach(o => {
      growthLines.push(`[${o.priority || 'P3'} | ${o.score ?? 0}] ${o.audience || 'general'} - ${o.problem || 'No problem summary'}`);
    });
  }

  growthEl.textContent = growthLines.join('\n');
}

async function ackOperatorRun(runId) {
  const d = await call('operator_ack_run', { run_id: runId });
  if (!d?.success) {
    showToast(d?.error || 'Failed to acknowledge run', 'error');
    return;
  }
  showToast('Run acknowledged', 'success');
  await refreshOperatorQueue();
  await init();
}

async function retryOperatorAutomation(automationId, runId) {
  const d = await call('operator_retry', { automation_id: automationId });
  if (!d?.success) {
    showToast(d?.error || 'Retry failed', 'error');
    return;
  }
  showToast('Retry executed', 'success');
  const row = document.getElementById(`op-run-${runId}`);
  if (row) row.style.opacity = '0.5';
  await refreshOperatorQueue();
  await init();
}

// ── UTILITIES ─────────────────────────────────────────────────────────────────
function updateNextPreview() {
  const val = parseInt(document.getElementById('fIntervalValue').value) || 1;
  const unit = document.getElementById('fIntervalUnit').value;
  const labels = {minute:'minute',hour:'hour',day:'day',week:'week',month:'month'};
  document.getElementById('nextRunPreview').textContent = `~${val} ${labels[unit]}${val>1?'s':''} from now`;
}

function updatePromptCount() {
  const len = document.getElementById('fPrompt').value.length;
  document.getElementById('promptCount').textContent = `${len} / 4000`;
}

document.getElementById('fIntervalValue').addEventListener('input', updateNextPreview);
document.getElementById('fIntervalUnit').addEventListener('change', updateNextPreview);
document.getElementById('fPrompt').addEventListener('input', updatePromptCount);

function updateWebhookHint() {
  const val = document.getElementById('fWebhook').value.trim();
  const el  = document.getElementById('webhookHint');
  if (val && (val.includes('discord.com/api/webhooks/') || val.includes('discordapp.com/api/webhooks/'))) {
    el.innerHTML = '🎮 <strong style="color:var(--accent-light)">Discord detected</strong> — results will be posted as a rich embed to your channel.';
  } else if (val) {
    el.textContent = 'Results will be POST-ed as JSON: { automation_id, name, result, ran_at }.';
  } else {
    el.textContent = 'Paste a Discord webhook URL for rich embeds, or any HTTPS URL to receive a JSON POST.';
  }
}

document.querySelectorAll('.modal-overlay').forEach(o =>
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); })
);

async function call(action, body = {}, method = 'POST') {
  if (method === 'GET') {
    const qs = new URLSearchParams({ action, ...body });
    return fetch(`${API}?${qs}`).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
  }
  const fd = new FormData();
  fd.append('action', action);
  Object.entries(body).forEach(([k, v]) => fd.append(k, v));
  return fetch(API, { method: 'POST', body: fd }).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function parseServerDate(input) {
  if (!input) return null;
  const normalized = String(input).trim().replace(' ', 'T');
  const hasZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized);
  const d = new Date(hasZone ? normalized : normalized + 'Z');
  return isNaN(d) ? null : d;
}

function formatDate(d) {
  const parsed = parseServerDate(d);
  if (!parsed) return '—';
  return parsed.toLocaleString(undefined, { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = `toast show ${type}`;
  setTimeout(() => t.className = 'toast', 2800);
}

document.addEventListener('keydown', (e) => {
  const tag = String(e.target?.tagName || '').toLowerCase();
  const editable = tag === 'input' || tag === 'textarea' || e.target?.isContentEditable;
  if (e.key === '/' && !editable && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    document.getElementById('automationSearch')?.focus();
    return;
  }
  if ((e.key === 'n' || e.key === 'N') && !editable && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    openCreate();
    return;
  }
  if (e.key === 'Escape' && document.activeElement?.id === 'automationSearch') {
    document.getElementById('automationSearch').value = '';
    render();
    document.getElementById('automationSearch').blur();
  }
});

init();
</script>
</body>
</html>
