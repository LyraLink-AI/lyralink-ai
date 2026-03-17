<?php
session_start();
// Dev only
if (($_SESSION['username'] ?? '') !== 'developer') {
    http_response_code(403);
    die('Unauthorized');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Dataset Manager</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
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

        nav { padding: 14px 20px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); }
        .nav-logo { width:30px; height:30px; background:var(--accent); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; box-shadow:0 0 12px var(--accent-glow); }
        nav h1 { font-family:'Syne',sans-serif; font-weight:800; font-size:16px; }
        nav h1 span { color:var(--accent-light); }
        .nav-badge { background:rgba(124,58,237,0.15); border:1px solid rgba(124,58,237,0.3); color:var(--accent-light); font-size:10px; padding:2px 8px; border-radius:20px; }
        .nav-back { margin-left:auto; color:var(--text-muted); text-decoration:none; font-size:12px; border:1px solid var(--border); padding:4px 10px; border-radius:20px; transition:all 0.2s; }
        .nav-back:hover { border-color:var(--accent); color:var(--accent-light); }

        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }

        /* STATS ROW */
        .stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:24px; }
        .stat-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px; text-align:center; }
        .stat-num { font-family:'Syne',sans-serif; font-size:28px; font-weight:800; color:var(--accent-light); }
        .stat-label { font-size:11px; color:var(--text-muted); margin-top:4px; }

        /* TABS */
        .tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--border); }
        .tab { padding:10px 16px; font-size:12px; cursor:pointer; color:var(--text-muted); border-bottom:2px solid transparent; transition:all 0.2s; font-family:'DM Mono',monospace; }
        .tab.active { color:var(--accent-light); border-bottom-color:var(--accent); }

        /* TOOLBAR */
        .toolbar { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
        .search-box { flex:1; min-width:200px; background:var(--surface); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:8px 12px; font-size:12px; font-family:'DM Mono',monospace; outline:none; }
        .search-box:focus { border-color:var(--accent); }
        .btn { padding:8px 14px; border-radius:8px; font-family:'DM Mono',monospace; font-size:12px; cursor:pointer; border:none; transition:all 0.2s; white-space:nowrap; }
        .btn-primary { background:var(--accent); color:white; box-shadow:0 0 10px var(--accent-glow); }
        .btn-primary:hover { background:#6d28d9; }
        .btn-success { background:rgba(34,197,94,0.15); color:var(--success); border:1px solid rgba(34,197,94,0.3); }
        .btn-success:hover { background:rgba(34,197,94,0.25); }
        .btn-danger  { background:rgba(239,68,68,0.1); color:var(--error); border:1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover  { background:rgba(239,68,68,0.2); }
        .btn-outline { background:none; color:var(--text-muted); border:1px solid var(--border); }
        .btn-outline:hover { border-color:var(--accent); color:var(--accent-light); }
        .btn:disabled { opacity:0.4; cursor:not-allowed; }

        /* TABLE */
        .table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
        table { width:100%; border-collapse:collapse; }
        th { padding:10px 14px; text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid var(--border); background:rgba(0,0,0,0.2); }
        td { padding:10px 14px; font-size:12px; border-bottom:1px solid rgba(30,30,46,0.5); vertical-align:top; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:rgba(124,58,237,0.04); }
        .td-q { color:var(--text); max-width:280px; }
        .td-a { color:var(--text-muted); max-width:320px; }
        .td-q, .td-a { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .td-actions { white-space:nowrap; display:flex; gap:4px; }
        .badge-emb { background:rgba(34,197,94,0.1); color:var(--success); border:1px solid rgba(34,197,94,0.2); border-radius:4px; padding:1px 6px; font-size:10px; }
        .badge-noemb { background:rgba(245,158,11,0.1); color:var(--warn); border:1px solid rgba(245,158,11,0.2); border-radius:4px; padding:1px 6px; font-size:10px; }
        .badge-method { background:rgba(124,58,237,0.1); color:var(--accent-light); border-radius:4px; padding:1px 6px; font-size:10px; border:1px solid rgba(124,58,237,0.2); }

        /* PAGINATION */
        .pagination { display:flex; gap:6px; justify-content:center; padding:16px; }
        .page-btn { padding:5px 10px; background:var(--surface); border:1px solid var(--border); color:var(--text-muted); border-radius:6px; cursor:pointer; font-family:'DM Mono',monospace; font-size:11px; }
        .page-btn.active { border-color:var(--accent); color:var(--accent-light); }
        .page-btn:hover { border-color:var(--accent); }

        /* MODAL */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:100; display:none; align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.open { display:flex; }
        .modal { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px; max-width:600px; width:100%; position:relative; max-height:90vh; overflow-y:auto; }
        .modal h3 { font-family:'Syne',sans-serif; font-size:16px; font-weight:700; margin-bottom:16px; }
        .modal-close { position:absolute; top:16px; right:16px; background:none; border:none; color:var(--text-muted); font-size:20px; cursor:pointer; }
        .modal label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px; margin-top:12px; }
        .modal textarea { width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:10px; font-family:'DM Mono',monospace; font-size:12px; outline:none; resize:vertical; min-height:80px; }
        .modal textarea:focus { border-color:var(--accent); }
        .modal-actions { display:flex; gap:8px; margin-top:16px; justify-content:flex-end; }

        /* SEARCH RESULTS */
        .search-result { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px; margin-bottom:10px; }
        .search-result-score { font-size:10px; color:var(--text-muted); margin-bottom:6px; }
        .search-result-q { font-size:12px; color:var(--accent-light); margin-bottom:4px; }
        .search-result-a { font-size:12px; color:var(--text-muted); line-height:1.5; }

        /* TOAST */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 18px; font-size:12px; z-index:999; opacity:0; transition:opacity 0.3s; pointer-events:none; white-space:nowrap; }
        .toast.show { opacity:1; }
        .toast.success { border-color:var(--success); color:var(--success); }
        .toast.error   { border-color:var(--error);   color:var(--error); }
        .toast.warn    { border-color:var(--warn);     color:var(--warn); }

        .empty { text-align:center; padding:40px; color:var(--text-muted); font-size:13px; }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>

<nav>
    <div class="nav-logo">⚡</div>
    <h1>Lyra<span>link</span></h1>
    <span class="nav-badge">Dataset Manager</span>
    <a href="/chat" class="nav-back">← Back to Chat</a>
</nav>

<div class="container">

    <!-- STATS -->
    <div class="stats-row" id="statsRow">
        <div class="stat-card"><div class="stat-num" id="statTotal">—</div><div class="stat-label">Dataset Entries</div></div>
        <div class="stat-card"><div class="stat-num" id="statPending">—</div><div class="stat-label">Pending Approval</div></div>
        <div class="stat-card"><div class="stat-num" id="statEmbeddings">—</div><div class="stat-label">With Embeddings</div></div>
    </div>

    <!-- TABS -->
    <div class="tabs">
        <div class="tab active" onclick="switchTab('pending', this)">Pending</div>
        <div class="tab" onclick="switchTab('dataset', this)">Dataset</div>
        <div class="tab" onclick="switchTab('search', this)">Test Search</div>
    </div>

    <!-- PENDING TAB -->
    <div id="tab-pending">
        <div class="toolbar">
            <span style="font-size:12px;color:var(--text-muted)">Conversations waiting to be added to the dataset</span>
            <button class="btn btn-success" onclick="bulkApprove()" id="bulkBtn">⚡ Approve All (50)</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>User</th><th>Question</th><th>Answer</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody id="pendingBody"><tr><td colspan="6" class="empty">Loading...</td></tr></tbody>
            </table>
        </div>
        <div class="pagination" id="pendingPagination"></div>
    </div>

    <!-- DATASET TAB -->
    <div id="tab-dataset" style="display:none">
        <div class="toolbar">
            <input class="search-box" type="text" id="datasetSearch" placeholder="Filter dataset..." oninput="debounceDatasetSearch()">
            <button class="btn btn-outline" onclick="generateEmbeddings()" id="embBtn">🔮 Generate Missing Embeddings</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Question</th><th>Answer</th><th>Embedding</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody id="datasetBody"><tr><td colspan="6" class="empty">Loading...</td></tr></tbody>
            </table>
        </div>
        <div class="pagination" id="datasetPagination"></div>
    </div>

    <!-- SEARCH TEST TAB -->
    <div id="tab-search" style="display:none">
        <div class="toolbar">
            <input class="search-box" type="text" id="testQuery" placeholder="Type a test query to search the dataset..." style="flex:1">
            <button class="btn btn-primary" onclick="testSearch()">Search</button>
        </div>
        <div id="searchResults" style="margin-top:12px"></div>
    </div>

</div>

<!-- APPROVE/EDIT MODAL -->
<div class="modal-overlay" id="approveModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('approveModal')">✕</button>
        <h3 id="modalTitle">Approve Conversation</h3>
        <label>Question (editable)</label>
        <textarea id="modalQuestion" rows="3"></textarea>
        <label>Answer (editable)</label>
        <textarea id="modalAnswer" rows="5"></textarea>
        <input type="hidden" id="modalConvId">
        <input type="hidden" id="modalDatasetId">
        <input type="hidden" id="modalMode"> <!-- 'approve' or 'edit' -->
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitModal()" id="modalSubmitBtn">Add to Dataset</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
let pendingPage = 1;
let datasetPage = 1;
let datasetSearchTimer = null;

// ── STATS ──
async function loadStats() {
    const data = await api('GET', 'stats');
    if (data.success) {
        document.getElementById('statTotal').textContent      = data.total;
        document.getElementById('statPending').textContent    = data.pending;
        document.getElementById('statEmbeddings').textContent = data.with_embeddings;
    }
}

// ── TABS ──
function switchTab(tab, el) {
    ['pending','dataset','search'].forEach(t => {
        document.getElementById('tab-' + t).style.display = 'none';
    });
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + tab).style.display = 'block';
    el.classList.add('active');
    if (tab === 'pending') loadPending();
    if (tab === 'dataset') loadDataset();
}

// ── PENDING ──
async function loadPending(page = 1) {
    pendingPage = page;
    const data = await api('GET', 'list_pending', { page });
    const tbody = document.getElementById('pendingBody');

    if (!data.rows?.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty">No pending conversations 🎉</td></tr>';
        document.getElementById('pendingPagination').innerHTML = '';
        return;
    }

    tbody.innerHTML = data.rows.map(r => `
        <tr>
            <td style="color:var(--text-muted)">#${r.id}</td>
            <td style="color:var(--accent-light);font-size:11px">${escHtml(r.user_id)}</td>
            <td class="td-q">${escHtml(r.user_message)}</td>
            <td class="td-a">${escHtml(r.ai_reply)}</td>
            <td style="color:var(--text-muted);font-size:11px;white-space:nowrap">${r.created_at.slice(0,10)}</td>
            <td><div class="td-actions">
                <button class="btn btn-success" style="padding:4px 8px;font-size:10px" onclick="openApprove(${r.id},'${escHtml(r.user_message).replace(/'/g,"\\'")}','${escHtml(r.ai_reply).replace(/'/g,"\\'")}')">✓ Add</button>
                <button class="btn btn-outline" style="padding:4px 8px;font-size:10px" onclick="skipConv(${r.id})">Skip</button>
            </div></td>
        </tr>`).join('');

    renderPagination('pendingPagination', page, Math.ceil(data.total / 20), loadPending);
}

// ── DATASET ──
async function loadDataset(page = 1) {
    datasetPage = page;
    const search = document.getElementById('datasetSearch')?.value || '';
    const data   = await api('GET', 'list_dataset', { page, search });
    const tbody  = document.getElementById('datasetBody');

    if (!data.rows?.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty">No dataset entries yet</td></tr>';
        document.getElementById('datasetPagination').innerHTML = '';
        return;
    }

    tbody.innerHTML = data.rows.map(r => `
        <tr>
            <td style="color:var(--text-muted)">#${r.id}</td>
            <td class="td-q">${escHtml(r.question)}</td>
            <td class="td-a">${escHtml(r.answer)}</td>
            <td>${r.has_embedding ? '<span class="badge-emb">✓ Yes</span>' : '<span class="badge-noemb">No</span>'}</td>
            <td style="color:var(--text-muted);font-size:11px;white-space:nowrap">${r.created_at.slice(0,10)}</td>
            <td><div class="td-actions">
                <button class="btn btn-outline" style="padding:4px 8px;font-size:10px" onclick="openEdit(${r.id},'${escHtml(r.question).replace(/'/g,"\\'")}','${escHtml(r.answer).replace(/'/g,"\\'")}')">Edit</button>
                <button class="btn btn-danger"  style="padding:4px 8px;font-size:10px" onclick="removeEntry(${r.id})">✕</button>
            </div></td>
        </tr>`).join('');

    renderPagination('datasetPagination', page, Math.ceil(data.total / 20), loadDataset);
}

function debounceDatasetSearch() {
    clearTimeout(datasetSearchTimer);
    datasetSearchTimer = setTimeout(() => loadDataset(1), 400);
}

// ── APPROVE ──
function openApprove(convId, question, answer) {
    document.getElementById('modalTitle').textContent     = 'Add to Dataset';
    document.getElementById('modalQuestion').value        = question;
    document.getElementById('modalAnswer').value          = answer;
    document.getElementById('modalConvId').value          = convId;
    document.getElementById('modalDatasetId').value       = '';
    document.getElementById('modalMode').value            = 'approve';
    document.getElementById('modalSubmitBtn').textContent = 'Add to Dataset';
    document.getElementById('approveModal').classList.add('open');
}

function openEdit(datasetId, question, answer) {
    document.getElementById('modalTitle').textContent     = 'Edit Dataset Entry';
    document.getElementById('modalQuestion').value        = question;
    document.getElementById('modalAnswer').value          = answer;
    document.getElementById('modalConvId').value          = '';
    document.getElementById('modalDatasetId').value       = datasetId;
    document.getElementById('modalMode').value            = 'edit';
    document.getElementById('modalSubmitBtn').textContent = 'Save Changes';
    document.getElementById('approveModal').classList.add('open');
}

async function submitModal() {
    const mode      = document.getElementById('modalMode').value;
    const question  = document.getElementById('modalQuestion').value.trim();
    const answer    = document.getElementById('modalAnswer').value.trim();
    if (!question || !answer) { showToast('Question and answer are required', 'error'); return; }

    const btn = document.getElementById('modalSubmitBtn');
    btn.disabled = true; btn.textContent = 'Processing...';

    let data;
    if (mode === 'approve') {
        data = await api('POST', 'approve', {
            conv_id:  document.getElementById('modalConvId').value,
            question, answer
        });
    } else {
        data = await api('POST', 'edit', {
            dataset_id: document.getElementById('modalDatasetId').value,
            question, answer
        });
    }

    btn.disabled = false;
    btn.textContent = mode === 'approve' ? 'Add to Dataset' : 'Save Changes';

    if (data.success) {
        closeModal('approveModal');
        showToast(mode === 'approve' ? '✓ Added to dataset' : '✓ Entry updated', 'success');
        loadStats();
        if (mode === 'approve') loadPending(pendingPage);
        else loadDataset(datasetPage);
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

async function skipConv(convId) {
    // Mark as "in_dataset" without adding, just to skip it from pending
    const data = await api('POST', 'approve', { conv_id: convId });
    // Then immediately remove from dataset
    if (data.success) {
        await api('POST', 'remove', { dataset_id: data.dataset_id });
        showToast('Skipped', 'warn');
        loadPending(pendingPage);
        loadStats();
    }
}

async function bulkApprove() {
    const btn = document.getElementById('bulkBtn');
    btn.disabled = true; btn.textContent = '⏳ Processing...';
    const data = await api('POST', 'bulk_approve', { limit: 50 });
    btn.disabled = false; btn.textContent = '⚡ Approve All (50)';
    if (data.success) {
        showToast(`✓ Added ${data.added} entries (${data.failed} failed)`, 'success');
        loadStats(); loadPending(1);
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

async function removeEntry(datasetId) {
    if (!confirm('Remove this entry from the dataset?')) return;
    const data = await api('POST', 'remove', { dataset_id: datasetId });
    if (data.success) {
        showToast('Entry removed', 'warn');
        loadStats(); loadDataset(datasetPage);
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

// ── EMBEDDINGS ──
async function generateEmbeddings() {
    const btn = document.getElementById('embBtn');
    btn.disabled = true; btn.textContent = '⏳ Generating...';
    const data = await api('POST', 'generate_embeddings');
    btn.disabled = false; btn.textContent = '🔮 Generate Missing Embeddings';
    if (data.success) {
        showToast(`✓ Generated ${data.updated} embeddings`, 'success');
        loadStats(); loadDataset(datasetPage);
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

// ── TEST SEARCH ──
async function testSearch() {
    const query = document.getElementById('testQuery').value.trim();
    if (!query) return;

    document.getElementById('searchResults').innerHTML = '<div style="color:var(--text-muted);font-size:12px">Searching...</div>';
    const data = await api('POST', 'search', { query });

    if (!data.success || !data.results?.length) {
        document.getElementById('searchResults').innerHTML = '<div class="empty">No results found for that query</div>';
        return;
    }

    document.getElementById('searchResults').innerHTML = data.results.map(r => `
        <div class="search-result">
            <div class="search-result-score">
                <span class="badge-method">${r.method}</span>
                Score: ${r.score.toFixed(3)} &nbsp;·&nbsp; Dataset #${r.id}
            </div>
            <div class="search-result-q">Q: ${escHtml(r.question)}</div>
            <div class="search-result-a">A: ${escHtml(r.answer.slice(0, 300))}${r.answer.length > 300 ? '...' : ''}</div>
        </div>`).join('');
}

// ── PAGINATION ──
function renderPagination(containerId, current, total, loadFn) {
    const container = document.getElementById(containerId);
    if (total <= 1) { container.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= Math.min(total, 10); i++) {
        html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="${loadFn.name}(${i})">${i}</button>`;
    }
    if (total > 10) html += `<span style="color:var(--text-muted);font-size:11px;padding:5px">... ${total} pages</span>`;
    container.innerHTML = html;
}

// ── MODAL ──
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── API ──
async function api(method, action, params = {}) {
    try {
        let url = '/api/dataset.php';
        const opts = { method };
        if (method === 'GET') {
            const qs = new URLSearchParams({ action, ...params });
            url += '?' + qs;
        } else {
            const fd = new FormData();
            fd.append('action', action);
            Object.entries(params).forEach(([k,v]) => fd.append(k, v));
            opts.body = fd;
        }
        return await (await fetch(url, opts)).json();
    } catch(e) {
        return { success: false, error: e.message };
    }
}

function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

// ── INIT ──
loadStats();
loadPending();
</script>
</body>
</html>