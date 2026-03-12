<?php
session_start();
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) { header('Location: /pages/maintenance.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Support</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;
            --accent:#7c3aed;--accent-glow:rgba(124,58,237,0.3);--accent-light:#a78bfa;
            --text:#e2e8f0;--text-muted:#64748b;
            --success:#22c55e;--error:#ef4444;--warn:#f59e0b;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        body::before{content:'';position:fixed;top:-200px;left:30%;width:600px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%);pointer-events:none}

        nav{padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,0.92);backdrop-filter:blur(12px);z-index:10}
        .nav-logo{height:28px;width:auto;mix-blend-mode:lighten}
        .nav-links{display:flex;gap:8px;margin-left:auto;align-items:center}
        .nav-link{color:var(--text-muted);text-decoration:none;font-size:12px;border:1px solid var(--border);padding:5px 12px;border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}

        /* AGENT ONLINE COUNTER */
        .agent-counter{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted);padding:4px 10px;border:1px solid var(--border);border-radius:20px}
        .agent-dot{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 6px rgba(34,197,94,0.5);animation:pulse-green 2s infinite}
        @keyframes pulse-green{0%,100%{opacity:1}50%{opacity:0.5}}
        .agent-dot.none{background:var(--text-muted);box-shadow:none;animation:none}

        .container{max-width:760px;margin:0 auto;padding:36px 24px 80px;position:relative;z-index:1}

        /* TABS */
        .tabs{display:flex;gap:4px;margin-bottom:24px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:4px}
        .tab{flex:1;text-align:center;padding:8px;border-radius:8px;font-size:12px;cursor:pointer;color:var(--text-muted);transition:all 0.2s}
        .tab.active{background:var(--accent);color:white}

        /* FORM */
        .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media(max-width:640px){
            .form-grid{grid-template-columns:1fr}
            nav{padding:10px 14px;gap:8px}
            nav img{height:24px}
            .nav-link{font-size:11px;padding:4px 8px}
            .agent-counter{display:none}
            .container{padding:20px 14px 60px}
            .tabs{gap:2px;padding:3px}
            .tab{font-size:11px;padding:6px 4px}
            .card{padding:16px}
            input,select,textarea{font-size:16px}
            .ticket-item{flex-wrap:wrap;gap:8px}
            .ticket-item-title{font-size:13px}
            h1{font-size:20px !important}
            h2{font-size:16px !important}
        }
        .form-field{margin-bottom:14px}
        .form-field.full{grid-column:1/-1}
        label{display:block;font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px}
        input,select,textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;transition:border-color 0.2s}
        input:focus,select:focus,textarea:focus{border-color:var(--accent)}
        textarea{resize:vertical;min-height:120px}
        select option{background:var(--bg)}

        .btn{padding:10px 20px;border-radius:10px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;border:none;transition:all 0.2s}
        .btn-primary{background:var(--accent);color:white;box-shadow:0 0 12px var(--accent-glow);width:100%;margin-top:4px}
        .btn-primary:hover{background:#6d28d9}
        .btn-primary:disabled{opacity:0.5;cursor:not-allowed}

        /* PRIORITY BADGES */
        .pri{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
        .pri.low     {background:rgba(34,197,94,0.15); color:var(--success);border:1px solid rgba(34,197,94,0.3)}
        .pri.medium  {background:rgba(245,158,11,0.15);color:var(--warn);   border:1px solid rgba(245,158,11,0.3)}
        .pri.high    {background:rgba(249,115,22,0.15);color:#f97316;       border:1px solid rgba(249,115,22,0.3)}
        .pri.critical{background:rgba(239,68,68,0.15); color:var(--error);  border:1px solid rgba(239,68,68,0.3)}

        /* STATUS BADGES */
        .status{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
        .status.open       {background:rgba(124,58,237,0.15);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}
        .status.in_progress{background:rgba(56,189,248,0.15);color:#38bdf8;border:1px solid rgba(56,189,248,0.3)}
        .status.waiting    {background:rgba(245,158,11,0.15);color:var(--warn);border:1px solid rgba(245,158,11,0.3)}
        .status.resolved   {background:rgba(34,197,94,0.15); color:var(--success);border:1px solid rgba(34,197,94,0.3)}
        .status.closed     {background:rgba(100,116,139,0.15);color:var(--text-muted);border:1px solid var(--border)}

        /* TICKET LIST */
        .ticket-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:border-color 0.2s;text-decoration:none;color:var(--text)}
        .ticket-item:hover{border-color:var(--accent)}
        .ticket-info{flex:1;min-width:0}
        .ticket-ref{font-size:10px;color:var(--text-muted);margin-bottom:2px}
        .ticket-subject{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px}
        .ticket-meta{font-size:11px;color:var(--text-muted)}
        .ticket-badges{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}

        .msg-box{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:8px}
        .msg-box.agent{border-color:rgba(124,58,237,0.3);background:rgba(124,58,237,0.05)}
        .msg-header{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:11px;color:var(--text-muted)}
        .msg-author{color:var(--text);font-weight:600}
        .msg-body{font-size:13px;line-height:1.7;color:var(--text-muted);white-space:pre-wrap}

        .empty-state{text-align:center;padding:48px 24px;color:var(--text-muted)}
        .empty-state .ei{font-size:32px;margin-bottom:12px}

        .alert{padding:12px 16px;border-radius:10px;font-size:12px;margin-bottom:16px}
        .alert.success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--success)}
        .alert.error  {background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--error)}

        .section-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);font-size:12px;cursor:pointer;margin-bottom:16px;background:none;border:none;font-family:'DM Mono',monospace;padding:0}
        .section-back:hover{color:var(--accent-light)}

        #ticketView,.screen{display:none}
        #ticketView.active,.screen.active{display:block}
    </style>
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <div class="nav-links">
        <div class="agent-counter" id="agentCounter">
            <div class="agent-dot none" id="agentDot"></div>
            <span id="agentCountText">Checking...</span>
        </div>
        <a href="/chat" class="nav-link">← Chat</a>
    </div>
</nav>

<div class="container">

    <!-- MAIN VIEW -->
    <div id="mainView" class="screen active">
        <div style="margin-bottom:20px">
            <h1 style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:4px">Support <span style="color:var(--accent-light)">Center</span></h1>
            <p style="font-size:12px;color:var(--text-muted)">Create a ticket or check your existing ones</p>
        </div>

        <div class="tabs">
            <div class="tab active" onclick="switchTab('new',this)">🎫 New Ticket</div>
            <div class="tab" onclick="switchTab('mine',this)">📋 My Tickets</div>
        </div>

        <!-- NEW TICKET FORM -->
        <div id="tabNew" class="card">
            <div id="formAlert"></div>
            <?php if (!isset($_SESSION['user_id'])): ?>
            <div class="form-grid">
                <div class="form-field">
                    <label>Your Name</label>
                    <input type="text" id="guestName" placeholder="Display name">
                </div>
                <div class="form-field">
                    <label>Email *</label>
                    <input type="email" id="guestEmail" placeholder="you@email.com">
                </div>
            </div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="form-field">
                    <label>Category</label>
                    <select id="ticketCat">
                        <option value="general">General Support</option>
                        <option value="billing">Billing</option>
                        <option value="bug_report">Bug Report</option>
                        <option value="account">Account Issue</option>
                        <option value="live_chat">Live Chat</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Priority</label>
                    <select id="ticketPri">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🟡 Medium</option>
                        <option value="high">🟠 High</option>
                        <option value="critical">🔴 Critical</option>
                    </select>
                </div>
                <div class="form-field full">
                    <label>Subject *</label>
                    <input type="text" id="ticketSubject" placeholder="Brief summary of your issue">
                </div>
                <div class="form-field full">
                    <label>Message *</label>
                    <textarea id="ticketBody" placeholder="Describe your issue in detail..."></textarea>
                </div>
            </div>
            <button class="btn btn-primary" id="submitBtn" onclick="submitTicket()">Submit Ticket</button>
        </div>

        <!-- MY TICKETS -->
        <div id="tabMine" style="display:none">
            <?php if (!isset($_SESSION['user_id'])): ?>
            <div class="card" style="margin-bottom:16px">
                <label>Enter your email to find your tickets</label>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <input type="email" id="lookupEmail" placeholder="you@email.com" style="flex:1">
                    <button class="btn btn-primary" style="width:auto;margin:0" onclick="loadMyTickets()">Look up</button>
                </div>
            </div>
            <?php endif; ?>
            <div id="ticketList"><div class="empty-state"><div class="ei">🎫</div><p>No tickets found</p></div></div>
        </div>
    </div>

    <!-- TICKET DETAIL VIEW -->
    <div id="ticketView">
        <button class="section-back" onclick="showMain()">← Back to tickets</button>
        <div id="ticketDetail"></div>
        <div style="margin-top:20px">
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px">Your Reply</label>
            <textarea id="replyMsg" placeholder="Write your reply..." style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:80px;resize:vertical;margin-bottom:8px"></textarea>
            <button class="btn btn-primary" style="width:auto;padding:9px 20px" onclick="submitReply()">Send Reply</button>
        </div>
    </div>

</div>

<script>
let currentRef   = null;
let currentEmail = null;
let activeTab    = 'new';

function switchTab(tab, el) {
    activeTab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tabNew').style.display  = tab === 'new'  ? 'block' : 'none';
    document.getElementById('tabMine').style.display = tab === 'mine' ? 'block' : 'none';
    if (tab === 'mine') loadMyTickets();
}

async function api(action, body = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(body).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch('/api/support.php', { method: 'POST', body: fd });
    return res.json();
}

async function submitTicket() {
    const btn  = document.getElementById('submitBtn');
    const alert = document.getElementById('formAlert');
    alert.innerHTML = '';
    btn.disabled    = true;
    btn.textContent = 'Submitting...';

    const params = {
        subject:  document.getElementById('ticketSubject')?.value.trim(),
        body:     document.getElementById('ticketBody')?.value.trim(),
        category: document.getElementById('ticketCat')?.value,
        priority: document.getElementById('ticketPri')?.value,
    };
    const nameEl  = document.getElementById('guestName');
    const emailEl = document.getElementById('guestEmail');
    if (nameEl)  params.name  = nameEl.value.trim();
    if (emailEl) params.email = emailEl.value.trim();

    const data = await api('create_ticket', params).catch(() => ({ success: false, error: 'Network error' }));

    btn.disabled    = false;
    btn.textContent = 'Submit Ticket';

    if (data.success) {
        alert.innerHTML = `<div class="alert success">✓ Ticket created! Your reference is <strong>${data.ticket_ref}</strong>. Check your email for confirmation.</div>`;
        document.getElementById('ticketSubject').value = '';
        document.getElementById('ticketBody').value    = '';
    } else {
        alert.innerHTML = `<div class="alert error">✗ ${data.error}</div>`;
    }
}

async function loadMyTickets() {
    const emailEl = document.getElementById('lookupEmail');
    if (emailEl) currentEmail = emailEl.value.trim();
    const body = currentEmail ? { email: currentEmail } : {};
    const data = await api('my_tickets', body).catch(() => ({ success: false }));
    const list = document.getElementById('ticketList');
    if (!data.success || !data.tickets?.length) {
        list.innerHTML = '<div class="empty-state"><div class="ei">🎫</div><p>No tickets found</p></div>';
        return;
    }
    list.innerHTML = data.tickets.map(t => `
        <div class="ticket-item" onclick="openTicket('${t.ticket_ref}')">
            <div class="ticket-info">
                <div class="ticket-ref">${t.ticket_ref}</div>
                <div class="ticket-subject">${escHtml(t.subject)}</div>
                <div class="ticket-meta">${formatDate(t.updated_at)}</div>
            </div>
            <div class="ticket-badges">
                <span class="status ${t.status}">${t.status.replace('_',' ')}</span>
                <span class="pri ${t.agent_priority || t.user_priority}">${t.agent_priority || t.user_priority}</span>
            </div>
        </div>
    `).join('');
}

async function openTicket(ref) {
    currentRef = ref;
    const body = currentEmail ? { ref, email: currentEmail } : { ref };
    const data = await api('get_ticket', body).catch(() => null);
    if (!data?.success) return;

    document.getElementById('mainView').classList.remove('active');
    document.getElementById('ticketView').classList.add('active');

    const t = data.ticket;
    const effPri = t.agent_priority || t.user_priority;
    document.getElementById('ticketDetail').innerHTML = `
        <div class="card" style="margin-bottom:16px">
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px">
                <div style="flex:1">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">${t.ticket_ref} · ${formatDate(t.created_at)}</div>
                    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:700">${escHtml(t.subject)}</div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <span class="status ${t.status}">${t.status.replace('_',' ')}</span>
                    <span class="pri ${effPri}">${effPri}</span>
                </div>
            </div>
            <div class="msg-box">
                <div class="msg-header"><span class="msg-author">You</span><span>${formatDate(t.created_at)}</span></div>
                <div class="msg-body">${escHtml(t.body)}</div>
            </div>
            ${data.replies.map(r => `
                <div class="msg-box ${r.author_type === 'agent' ? 'agent' : ''}">
                    <div class="msg-header">
                        <span class="msg-author">${r.author_type === 'agent' ? '⚡ ' + escHtml(r.agent_name || 'Support') : 'You'}</span>
                        <span>${formatDate(r.created_at)}</span>
                    </div>
                    <div class="msg-body">${escHtml(r.message)}</div>
                </div>
            `).join('')}
        </div>
    `;

    // Hide reply box if closed
    document.querySelector('#ticketView > div:last-child').style.display =
        ['resolved','closed'].includes(t.status) ? 'none' : 'block';
}

function showMain() {
    document.getElementById('ticketView').classList.remove('active');
    document.getElementById('mainView').classList.add('active');
    if (activeTab === 'mine') loadMyTickets();
}

async function submitReply() {
    const msg  = document.getElementById('replyMsg').value.trim();
    if (!msg) return;
    const body = currentEmail ? { ref: currentRef, message: msg, email: currentEmail } : { ref: currentRef, message: msg };
    const data = await api('user_reply', body).catch(() => null);
    if (data?.success) {
        document.getElementById('replyMsg').value = '';
        openTicket(currentRef);
    }
}

async function checkAgentCount() {
    const data = await fetch('/api/support.php?action=agent_count').then(r => r.json()).catch(() => ({ count: 0 }));
    const dot  = document.getElementById('agentDot');
    const text = document.getElementById('agentCountText');
    const count = data.count || 0;
    if (count > 0) {
        dot.className  = 'agent-dot';
        text.textContent = `${count} agent${count > 1 ? 's' : ''} online`;
    } else {
        dot.className  = 'agent-dot none';
        text.textContent = 'No agents online';
    }
}

function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }
function formatDate(d) { return d ? new Date(d).toLocaleDateString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '' }

checkAgentCount();
setInterval(checkAgentCount, 30000);
<?php if (isset($_SESSION['user_id'])): ?>
// Auto load tickets if logged in
document.addEventListener('DOMContentLoaded', () => {});
<?php endif; ?>
</script>
</body>
</html>