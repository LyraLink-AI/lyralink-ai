// ── GLOBALS ──
let currentUser    = null;
let currentMoltTab = 'hot';
const MOLTBOOK_ENABLED = false;
const DEV_USERNAME = 'developer';
let operatorDashCheckToken = 0;
let devModeEnabled = true;
let availableModelProviders = [];
let selectedLlmProvider = null;
let selectedLlmModel = null;
let pendingChatAttachment = null;
let aiAssistOptions = {
    taskMode: false,
    taskFocus: 'general',
    codeTest: true,
    liveTrace: false,
};
const GOAL_BOARD_KEY = 'lyralink_goal_board';
const AGENT_WORKSPACE_COLLAPSED_KEY = 'lyralink_agent_workspace_collapsed';
const AGENT_STATUS_KEY = 'lyralink_agent_status';
let agentWorkspaceCollapsed = false;
const validationReportStore = {};
const replyActionStore = {};
let goalBoard = [];
let agentRunState = {
    status: 'idle',
    summary: 'Mission mode is ready when deeper execution is useful.',
    nextStep: ''
};

// ════════════════════════════════
// CONVERSATION STORAGE
// Logged-in users: server-synced (works across all devices)
// Guests: localStorage fallback
// ════════════════════════════════

// Local cache so we don't hammer the server on every render
let convCache   = [];   // [{ conv_id, title, msg_count, updated_at }]
let msgCache    = {};   // { conv_id: [{role, content}] }
let activeConvId = null;
let syncPending = {};   // conv_id → true while a save is in flight

const ACTIVE_CONV_GLOBAL_KEY = 'lyralink_active_conv_global';
const ACTIVE_CONV_BACKUP_KEY = 'lyralink_active_conv_backup';
const SHADOW_CONVS_KEY = 'lyralink_convs_shadow';
const AI_ASSIST_PREFS_KEY = 'lyralink_ai_assist_prefs';
const EXECUTION_STATE_KEY = 'lyralink_execution_state';

let executionState = null;
let approvalArmed = false;
let pendingCheckpointNote = '';
let pendingRollbackCheckpoint = '';

let missionControl = {
    projectId: 'default',
    workMode: true,
    longRunning: false,
    cockpitExpanded: false,
    budgetTokens: 0,
    budgetSeconds: 180,
    approvalRequired: false,
    webhookEvents: ['task.updated', 'agent.checkpoint'],
};

function genId() { return 'conv_' + Date.now() + '_' + Math.random().toString(36).slice(2,7); }
function isLoggedIn() { return !!currentUser; }

function backupStorageKey() {
    if (isLoggedIn() && currentUser?.username) {
        return 'lyralink_convs_backup_' + currentUser.username;
    }
    return 'lyralink_convs_backup_guest';
}

function backupLoad() {
    try {
        return JSON.parse(localStorage.getItem(backupStorageKey()) || '[]');
    } catch (_) {
        return [];
    }
}

function shadowLoad() {
    try {
        return JSON.parse(localStorage.getItem(SHADOW_CONVS_KEY) || '[]');
    } catch (_) {
        return [];
    }
}

function persistBackupFromCache() {
    const rows = convCache.map(c => ({
        id: c.conv_id,
        title: c.title || 'New Chat',
        createdAt: c.updated_at || Date.now(),
        messages: msgCache[c.conv_id] || [],
    }));
    localStorage.setItem(backupStorageKey(), JSON.stringify(rows));
    localStorage.setItem(SHADOW_CONVS_KEY, JSON.stringify(rows));
}

function hydrateCacheFromRows(rows) {
    convCache = rows.map(c => ({
        conv_id: c.id,
        title: c.title,
        msg_count: c.messages?.length || 0,
        updated_at: c.createdAt || Date.now(),
    }));
    msgCache = {};
    rows.forEach(c => { msgCache[c.id] = Array.isArray(c.messages) ? c.messages : []; });
}

function setActiveConvState(id) {
    activeConvId = id || null;

    if (isLoggedIn() && currentUser?.username && activeConvId) {
        localStorage.setItem('lyralink_active_conv_' + currentUser.username, activeConvId);
    } else if (isLoggedIn() && currentUser?.username) {
        localStorage.removeItem('lyralink_active_conv_' + currentUser.username);
    } else if (!isLoggedIn()) {
        guestSetActive(activeConvId || '');
    }

    if (activeConvId) {
        localStorage.setItem(ACTIVE_CONV_GLOBAL_KEY, activeConvId);
        localStorage.setItem(ACTIVE_CONV_BACKUP_KEY, activeConvId);
        const next = new URL(window.location.href);
        next.searchParams.set('c', activeConvId);
        history.replaceState(null, '', next.pathname + next.search + next.hash);
    } else {
        localStorage.removeItem(ACTIVE_CONV_GLOBAL_KEY);
        localStorage.removeItem(ACTIVE_CONV_BACKUP_KEY);
        const next = new URL(window.location.href);
        next.searchParams.delete('c');
        history.replaceState(null, '', next.pathname + next.search + next.hash);
    }
}

function executionStorageKey() {
    const userPart = currentUser?.username ? currentUser.username : 'guest';
    return EXECUTION_STATE_KEY + '_' + userPart;
}

function loadExecutionState() {
    try {
        const raw = localStorage.getItem(executionStorageKey());
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') {
            missionControl = {
                ...missionControl,
                ...parsed,
            };
        }
    } catch (_) {}
}

function saveExecutionState() {
    try {
        localStorage.setItem(executionStorageKey(), JSON.stringify(missionControl));
    } catch (_) {}
}

function syncExecutionCockpitUi() {
    const panel = document.getElementById('executionCockpit');
    const toggleBtn = document.getElementById('executionToggleBtn');
    const toggleCta = document.getElementById('executionToggleCta');
    const expanded = !!missionControl.cockpitExpanded;

    if (panel) {
        panel.classList.toggle('hidden', !expanded);
    }
    if (toggleBtn) {
        toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggleBtn.classList.toggle('active', expanded);
    }
    if (toggleCta) {
        toggleCta.textContent = expanded ? 'Hide' : 'Show';
    }
}

function refreshExecutionShellVisibility() {
    const shell = document.getElementById('executionShell');
    const panel = document.getElementById('executionCockpit');
    if (!shell || !panel) return;

    const hasTrace = !!executionState?.trace_id || !!executionState?.execution?.trace_id;
    const hasProject = !!executionState?.project?.id;
    const shouldShow = !!aiAssistOptions.taskMode || hasTrace || hasProject;

    shell.classList.toggle('hidden', !shouldShow);
    if (!shouldShow) {
        panel.classList.add('hidden');
        return;
    }
    syncExecutionCockpitUi();
}

function toggleExecutionCockpit(forceExpanded = null) {
    const next = forceExpanded === null ? !missionControl.cockpitExpanded : !!forceExpanded;
    missionControl.cockpitExpanded = next;
    saveExecutionState();
    syncExecutionCockpitUi();
}

function bindExecutionControls() {
    const projectIdInput = document.getElementById('projectIdInput');
    const workModeToggle = document.getElementById('workModeToggle');
    const longRunningToggle = document.getElementById('longRunningToggle');
    const approvalRequiredToggle = document.getElementById('approvalRequiredToggle');
    const budgetTokensInput = document.getElementById('agentBudgetTokensInput');
    const budgetSecondsInput = document.getElementById('agentBudgetSecondsInput');

    if (projectIdInput) {
        projectIdInput.value = missionControl.projectId || 'default';
        projectIdInput.addEventListener('change', () => {
            missionControl.projectId = (projectIdInput.value || 'default').trim() || 'default';
            saveExecutionState();
        });
    }
    if (workModeToggle) {
        workModeToggle.checked = !!missionControl.workMode;
        workModeToggle.addEventListener('change', () => {
            missionControl.workMode = !!workModeToggle.checked;
            saveExecutionState();
        });
    }
    if (longRunningToggle) {
        longRunningToggle.checked = !!missionControl.longRunning;
        longRunningToggle.addEventListener('change', () => {
            missionControl.longRunning = !!longRunningToggle.checked;
            saveExecutionState();
        });
    }
    if (approvalRequiredToggle) {
        approvalRequiredToggle.checked = !!missionControl.approvalRequired;
        approvalRequiredToggle.addEventListener('change', () => {
            missionControl.approvalRequired = !!approvalRequiredToggle.checked;
            saveExecutionState();
        });
    }
    if (budgetTokensInput) {
        budgetTokensInput.value = String(Number(missionControl.budgetTokens || 0));
        budgetTokensInput.addEventListener('change', () => {
            missionControl.budgetTokens = Math.max(0, Number(budgetTokensInput.value || 0) || 0);
            saveExecutionState();
        });
    }
    if (budgetSecondsInput) {
        budgetSecondsInput.value = String(Number(missionControl.budgetSeconds || 180));
        budgetSecondsInput.addEventListener('change', () => {
            const next = Math.max(10, Math.min(900, Number(budgetSecondsInput.value || 180) || 180));
            missionControl.budgetSeconds = next;
            budgetSecondsInput.value = String(next);
            saveExecutionState();
        });
    }

    syncExecutionCockpitUi();
    refreshExecutionShellVisibility();
}

function buildExecutionControlPayload() {
    const projectId = (missionControl.projectId || '').trim() || activeConvId || 'default';
    const taskState = {
        goals_total: goalBoard.length,
        goals_done: goalBoard.filter(goal => goal.status === 'done').length,
        goals_active: goalBoard.filter(goal => goal.status !== 'done').length,
        task_focus: aiAssistOptions.taskFocus || 'general',
    };
    const payload = {
        project_id: projectId,
        work_mode: !!missionControl.workMode,
        long_running_agent: !!missionControl.longRunning,
        approval_required: !!missionControl.approvalRequired,
        approval_granted: approvalArmed,
        agent_budget_tokens: Math.max(0, Number(missionControl.budgetTokens || 0) || 0),
        agent_budget_seconds: Math.max(10, Math.min(900, Number(missionControl.budgetSeconds || 180) || 180)),
        agent_permissions: isDevUser()
            ? ['read', 'write', 'run_tests', 'network', 'deploy', 'billing', 'admin']
            : ['read', 'write', 'run_tests', 'network'],
        task_state: taskState,
        client_channel: window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'web',
        webhook_events: Array.isArray(missionControl.webhookEvents) ? missionControl.webhookEvents : [],
    };

    if (pendingCheckpointNote) {
        payload.agent_checkpoint_note = pendingCheckpointNote;
        pendingCheckpointNote = '';
    }
    if (pendingRollbackCheckpoint) {
        payload.rollback_to_checkpoint = pendingRollbackCheckpoint;
        pendingRollbackCheckpoint = '';
    }

    approvalArmed = false;
    updateApprovalButton();
    return payload;
}

function updateApprovalButton() {
    const btn = document.getElementById('approveGateBtn');
    if (!btn) return;
    btn.textContent = approvalArmed ? 'Approval armed for next run' : 'Approve next run';
    btn.classList.toggle('active', approvalArmed);
}

function armApprovalOnce() {
    approvalArmed = true;
    updateApprovalButton();
    showToast('Approval armed for next mission run', 'success');
}

function queueCheckpointSave() {
    const note = prompt('Checkpoint note (optional):', 'manual checkpoint');
    if (note === null) return;
    pendingCheckpointNote = String(note || '').trim().slice(0, 200) || 'manual checkpoint';
    showToast('Checkpoint will be saved on next run', 'success');
}

function queueRollbackCheckpoint() {
    const select = document.getElementById('checkpointRollbackSelect');
    if (!select || !select.value) {
        showToast('Select a checkpoint first', 'error');
        return;
    }
    pendingRollbackCheckpoint = select.value;
    showToast('Rollback queued for next run', 'success');
}

function setBadgeState(el, text, tone = 'neutral') {
    if (!el) return;
    el.className = `exec-badge ${tone}`;
    el.textContent = text;
}

function renderExecutionList(el, items, emptyText) {
    if (!el) return;
    if (!Array.isArray(items) || !items.length) {
        el.innerHTML = `<div class="execution-item">${escapeHtml(emptyText)}</div>`;
        return;
    }
    el.innerHTML = items.map(item => `<div class="execution-item">${item}</div>`).join('');
}

function applyExecutionPayload(data = {}) {
    executionState = data || null;
    const panel = document.getElementById('executionCockpit');
    if (!panel) return;

    const execution = data.execution || {};
    const confidence = data.confidence || execution.confidence || {};
    const verification = data.verification || execution.verification || {};
    const hallucination = data.hallucination || execution.hallucination || {};
    const project = data.project || {};
    const memory = data.memory || execution.memory || {};
    const trust = data.trust || {};
    const distribution = data.distribution || {};
    const developerEcosystem = data.developer_ecosystem || {};

    refreshExecutionShellVisibility();
    if (document.getElementById('executionShell')?.classList.contains('hidden')) return;

    const confidenceScore = Number(confidence.score || 0);
    const confidenceLabel = String(confidence.label || 'unknown');
    const confidenceTone = confidenceLabel === 'high' ? 'good' : confidenceLabel === 'medium' ? 'warn' : 'bad';
    setBadgeState(document.getElementById('execConfidenceBadge'), `Confidence: ${confidenceLabel}${confidenceScore ? ` (${confidenceScore})` : ''}`, confidenceTone);

    const verifyPass = !!verification.passed;
    setBadgeState(document.getElementById('execVerifyBadge'), `Verify: ${verifyPass ? 'pass' : 'check needed'}`, verifyPass ? 'good' : 'warn');

    const risk = String(hallucination.risk || 'low');
    const riskTone = risk === 'low' ? 'good' : (risk === 'medium' ? 'warn' : 'bad');
    setBadgeState(document.getElementById('execRiskBadge'), `Risk: ${risk}`, riskTone);

    const traceId = data.trace_id || execution.trace_id || '--';
    const timingMs = execution?.timings?.request_ms || execution?.timings?.provider_ms || '--';
    const routeModel = execution?.escalation?.used
        ? `${execution?.escalation?.from_model || '?'} -> ${execution?.escalation?.to_model || '?'}`
        : (project?.history?.[project.history.length - 1]?.model || '--');

    const traceEl = document.getElementById('executionTraceId');
    const timingEl = document.getElementById('executionTiming');
    const routeEl = document.getElementById('executionRoute');
    const subEl = document.getElementById('executionCockpitSub');
    const toggleSubEl = document.getElementById('executionToggleSub');
    if (traceEl) traceEl.textContent = `Trace: ${traceId}`;
    if (timingEl) timingEl.textContent = `Latency: ${timingMs}ms`;
    if (routeEl) routeEl.textContent = `Route: ${routeModel}`;
    const mode = execution?.mode || (aiAssistOptions.taskMode ? 'mission' : 'chat');
    const subText = `Mode: ${mode} • Project: ${project?.id || missionControl.projectId || 'default'}`;
    if (subEl) {
        subEl.textContent = subText;
    }
    if (toggleSubEl) {
        toggleSubEl.textContent = subText;
    }

    const progress = project.progress || execution?.project_state || {};
    const progressText = `${progress.done_tasks ?? progress.task_done ?? 0} done • ${progress.active_tasks ?? progress.task_active ?? 0} active • ${progress.completion_pct ?? 0}% complete`;
    const progressEl = document.getElementById('projectProgressMeta');
    if (progressEl) progressEl.textContent = progressText;

    const tasks = Array.isArray(project.tasks) ? project.tasks.slice(-8).reverse() : [];
    renderExecutionList(
        document.getElementById('projectTaskList'),
        tasks.map(task => {
            const status = String(task.status || 'active');
            return `<strong>${escapeHtml(task.title || 'Task')}</strong><br>${escapeHtml(status)} • ${escapeHtml(String(task.updated_at || task.created_at || ''))}`;
        }),
        'No project tasks yet.'
    );

    const memoryAudit = memory.audit || {};
    const memoryMeta = document.getElementById('memoryAuditMeta');
    if (memoryMeta) {
        const contradictions = Array.isArray(memoryAudit.contradictions) ? memoryAudit.contradictions.length : 0;
        memoryMeta.textContent = `${memoryAudit.selected || 0}/${memoryAudit.candidates || 0} selected • freshness ${memoryAudit.freshness || 'normal'} • contradictions ${contradictions}`;
    }
    const compressed = Array.isArray(memory.compressed_context) ? memory.compressed_context : [];
    renderExecutionList(
        document.getElementById('memoryAuditList'),
        compressed.map(item => `<strong>#${escapeHtml(String(item.id || '?'))}</strong> score ${escapeHtml(String(item.importance_score || '0'))}<br>${escapeHtml(String(item.summary || '').slice(0, 160))}`),
        'No memory context selected.'
    );

    const distributionMeta = document.getElementById('distributionMeta');
    if (distributionMeta) {
        distributionMeta.textContent = `Channel: ${distribution.channel || '--'} • Trace: ${distribution.supports_rich_trace ? 'yes' : 'no'} • Long-run: ${distribution.supports_long_running ? 'yes' : 'no'}`;
    }
    const eco = developerEcosystem.manifest || {};
    renderExecutionList(
        document.getElementById('developerEcosystemMeta'),
        [
            `<strong>SDK</strong>: ${escapeHtml(String(eco?.sdk?.status || 'n/a'))}`,
            `<strong>Tool SDK</strong>: ${escapeHtml(String(eco?.tool_sdk?.status || 'n/a'))}`,
            `<strong>Agent SDK</strong>: ${escapeHtml(String(eco?.agent_sdk?.status || 'n/a'))}`,
            `<strong>Webhooks</strong>: ${escapeHtml(String(eco?.webhooks?.status || 'n/a'))}`,
            `<strong>Marketplace</strong>: ${escapeHtml(String(eco?.marketplace?.status || 'n/a'))}`,
        ],
        'No ecosystem metadata yet.'
    );

    const checkpointSelect = document.getElementById('checkpointRollbackSelect');
    const checkpoints = execution?.checkpoint?.saved || execution?.checkpoint?.applied
        ? [execution?.checkpoint?.saved, execution?.checkpoint?.applied].filter(Boolean)
        : [];
    if (checkpointSelect && checkpoints.length) {
        const existing = new Set(Array.from(checkpointSelect.options).map(option => option.value));
        checkpoints.forEach(cp => {
            if (!existing.has(cp)) {
                const option = document.createElement('option');
                option.value = cp;
                option.textContent = cp;
                checkpointSelect.appendChild(option);
            }
        });
    }

    if (trust?.security_center?.approval_required && !approvalArmed) {
        toggleExecutionCockpit(true);
        setAgentStatus('waiting', 'Approval gate is active for this run.', 'Click "Approve next run" to continue mission actions.');
    }
}

function resolvePreferredConvId() {
    const urlId = new URLSearchParams(window.location.search).get('c');
    const userId = isLoggedIn() && currentUser?.username
        ? localStorage.getItem('lyralink_active_conv_' + currentUser.username)
        : null;
    const guestId = !isLoggedIn() ? guestGetActive() : null;
    const globalId = localStorage.getItem(ACTIVE_CONV_GLOBAL_KEY);
    const backupId = localStorage.getItem(ACTIVE_CONV_BACKUP_KEY);
    const candidates = [urlId, userId, guestId, globalId, backupId].filter(Boolean);

    for (const id of candidates) {
        if (convCache.find(c => c.conv_id === id)) return id;
    }
    return convCache[0]?.conv_id ?? null;
}

// ── GUEST FALLBACK (localStorage) ──
function guestLoad()       { return JSON.parse(localStorage.getItem('lyralink_convs') || '[]'); }
function guestSave(convs)  { localStorage.setItem('lyralink_convs', JSON.stringify(convs)); }
function guestGetActive()  { return localStorage.getItem('lyralink_active_conv'); }
function guestSetActive(id){ localStorage.setItem('lyralink_active_conv', id); }

// ── SERVER API ──
async function apiConv(action, params = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(params).forEach(([k,v]) => fd.append(k, v));
    try {
        const res = await fetch('/api/auth.php', { method: 'POST', body: fd });
        return await res.json();
    } catch(e) { return { success: false }; }
}

// ── LOAD CONVERSATION LIST (on login / page load) ──
async function loadConvList() {
    if (!isLoggedIn()) {
        // Guest: build cache from localStorage
        const stored = guestLoad();
        if (stored.length) {
            hydrateCacheFromRows(stored);
        } else {
            const backupRows = backupLoad();
            if (backupRows.length) {
                hydrateCacheFromRows(backupRows);
            } else {
                hydrateCacheFromRows(shadowLoad());
            }
        }
        setActiveConvState(resolvePreferredConvId());
        renderConvList();
        renderConvList(true);
        renderChat();
        persistBackupFromCache();
        return;
    }

    const data = await apiConv('list_convs');
    if (!data.success || !Array.isArray(data.convs)) {
        const fallbackRows = backupLoad().length ? backupLoad() : shadowLoad();
        if (fallbackRows.length) {
            hydrateCacheFromRows(fallbackRows);
            setActiveConvState(resolvePreferredConvId());
            renderConvList();
            renderConvList(true);
            renderChat();
            persistBackupFromCache();
            return;
        }
    }

    if (data.success) {
        convCache = data.convs;
        msgCache  = {};

        if (convCache.length === 0) {
            const backupRows = backupLoad().length ? backupLoad() : shadowLoad();
            if (backupRows.length > 0) {
                hydrateCacheFromRows(backupRows);
                setActiveConvState(resolvePreferredConvId());
                renderConvList();
                renderConvList(true);
                renderChat();
                return;
            }

            // First login — create a fresh conversation
            await newConversation();
            return;
        }

        // Restore active conversation from URL/session/local fallback.
        setActiveConvState(resolvePreferredConvId());

        renderConvList();
        renderConvList(true);
        await loadMessages(activeConvId);
        renderChat();
        persistBackupFromCache();
    }
}

// ── LOAD MESSAGES FOR A CONVERSATION ──
async function loadMessages(convId) {
    if (msgCache[convId]) return; // already cached

    if (!isLoggedIn()) {
        const stored = guestLoad().find(c => c.id === convId);
        msgCache[convId] = stored?.messages || [];
        return;
    }

    const data = await apiConv('get_conv', { conv_id: convId });
    if (data.success) {
        msgCache[convId] = data.messages.map(m => ({ role: m.role, content: m.content, thinking: m.thinking || '' }));
        persistBackupFromCache();
        return;
    }

    const backupRows = backupLoad();
    const backup = (backupRows.find(c => c.id === convId) || shadowLoad().find(c => c.id === convId));
    msgCache[convId] = backup?.messages || [];
}

// ── GET ACTIVE CONVERSATION MESSAGES ──
function getActiveMessages() {
    return activeConvId ? (msgCache[activeConvId] || []) : [];
}

// ── NEW CONVERSATION ──
async function newConversation() {
    const newId = genId();
    convCache.unshift({ conv_id: newId, title: 'New Chat', msg_count: 0, updated_at: Date.now() });
    msgCache[newId] = [];
    setActiveConvState(newId);

    if (isLoggedIn()) {
        // Persist empty conv so it shows up on reload — saved properly when first message arrives
    } else {
        const stored = guestLoad();
        stored.push({ id: newId, title: 'New Chat', messages: [], createdAt: Date.now() });
        guestSave(stored);
    }

    renderConvList();
    renderConvList(true);
    renderChat();
    persistBackupFromCache();
    document.getElementById('userInput').focus();
}

// ── SWITCH CONVERSATION ──
async function switchConversation(id) {
    setActiveConvState(id);

    renderConvList();
    renderConvList(true);
    showMobileScreen('chat');

    if (!msgCache[id]) {
        // Show loading state while fetching
        document.getElementById('chatbox').innerHTML = `<div class="empty-state"><div class="icon" style="font-size:20px;animation:pulse 1s infinite">⚡</div><p>Loading...</p></div>`;
        await loadMessages(id);
    }
    renderChat();
    document.getElementById('userInput').focus();
}

// ── DELETE CONVERSATION ──
async function deleteConversation(id) {
    convCache = convCache.filter(c => c.conv_id !== id);
    delete msgCache[id];

    if (isLoggedIn()) {
        apiConv('delete_conv', { conv_id: id }); // fire and forget
    } else {
        const stored = guestLoad().filter(c => c.id !== id);
        guestSave(stored);
    }

    if (activeConvId === id) {
        setActiveConvState(null);
        if (convCache.length > 0) {
            await switchConversation(convCache[0].conv_id);
        } else {
            await newConversation();
        }
        return;
    }
    renderConvList();
    renderConvList(true);
    persistBackupFromCache();
}

// ── CLEAR CURRENT CHAT ──
async function clearCurrentChat() {
    if (!activeConvId) return;
    msgCache[activeConvId] = [];
    const conv = convCache.find(c => c.conv_id === activeConvId);
    if (conv) { conv.title = 'New Chat'; conv.msg_count = 0; }

    if (isLoggedIn()) {
        // Delete and recreate as empty
        await apiConv('delete_conv', { conv_id: activeConvId });
        msgCache[activeConvId] = [];
    } else {
        const stored = guestLoad();
        const idx    = stored.findIndex(c => c.id === activeConvId);
        if (idx !== -1) { stored[idx].messages = []; stored[idx].title = 'New Chat'; guestSave(stored); }
    }
    renderConvList();
    renderConvList(true);
    renderChat();
    persistBackupFromCache();
}

// ── SAVE MESSAGE ──
async function saveMessage(role, content, thinking = '') {
    if (!activeConvId) return;

    // Keep current conversation pinned as active during refreshes.
    setActiveConvState(activeConvId);

    // Update local cache
    if (!msgCache[activeConvId]) msgCache[activeConvId] = [];
    msgCache[activeConvId].push({ role, content, thinking: role === 'assistant' ? String(thinking || '') : '' });

    // Update title from first user message
    const conv = convCache.find(c => c.conv_id === activeConvId);
    if (conv) {
        conv.msg_count = (conv.msg_count || 0) + 1;
        if (role === 'user' && conv.title === 'New Chat') {
            conv.title = content.length > 35 ? content.slice(0, 35) + '…' : content;
        }
    }

    renderConvList();
    renderConvList(true);
    persistBackupFromCache();

    if (isLoggedIn()) {
        const saveResult = await apiConv('save_msg', {
            conv_id: activeConvId,
            role,
            content,
            thinking: role === 'assistant' ? String(thinking || '') : '',
            title: conv?.title || 'New Chat'
        });
        if (!saveResult?.success) {
            console.warn('save_msg failed:', saveResult?.error || 'unknown error');
        }
    } else {
        const stored = guestLoad();
        const idx    = stored.findIndex(c => c.id === activeConvId);
        if (idx !== -1) {
            stored[idx].messages = msgCache[activeConvId];
            stored[idx].title    = conv?.title || 'New Chat';
            guestSave(stored);
        }
    }
}

// ── RENDER CONVERSATION LIST ──
function renderConvList(drawer = false) {
    const listId = drawer ? 'drawerConvList' : 'convList';
    const list   = document.getElementById(listId);
    if (!list) return;

    if (!convCache.length) {
        list.innerHTML = '<div class="conv-empty">No conversations yet.<br>Hit "+ New Chat" to start!</div>';
        return;
    }

    list.innerHTML = '';
    convCache.forEach(conv => {
        const id  = conv.conv_id;
        const div = document.createElement('div');
        div.className = 'conv-item' + (id === activeConvId ? ' active' : '');
        div.innerHTML = `
            <div class="conv-item-text" onclick="switchConversation('${id}')${drawer ? '; closeDrawer()' : ''}">
                <div class="conv-item-title">${escapeHtml(conv.title || 'New Chat')}</div>
                <div class="conv-item-meta">${conv.msg_count || 0} messages</div>
            </div>
            <button class="conv-item-del" onclick="deleteConversation('${id}')" title="Delete">✕</button>`;
        list.appendChild(div);
    });
}

// ── RENDER CHAT ──
function renderChat() {
    const chatbox   = document.getElementById('chatbox');
    const chatTitle = document.getElementById('chatTitle');
    const msgs      = getActiveMessages();
    const conv      = convCache.find(c => c.conv_id === activeConvId);

    if (!msgs.length) {
        chatbox.innerHTML = `<div class="empty-state" id="emptyState"><div class="icon">⚡</div><p>Hey! I'm Lyralink. Ask me about code, science, creative writing — anything really!</p></div>`;
        chatTitle.textContent = conv?.title || 'New Chat';
        chatTitle.className   = 'chat-title';
        return;
    }

    chatTitle.textContent = conv?.title || 'Chat';
    chatTitle.className   = 'chat-title has-msgs';
    chatbox.innerHTML     = '';
    msgs.forEach(msg => {
        if (msg.role === 'user') {
            chatbox.innerHTML += `<div class="msg user"><div class="avatar">👤</div><div class="bubble">${escapeHtml(msg.content)}</div></div>`;
        } else {
            chatbox.innerHTML += buildAssistantMessageHtml(msg.content, '', '', false, msg.thinking || '');
        }
    });
    chatbox.scrollTop = chatbox.scrollHeight;
}



// ── INPUT HELPERS ──
function getInputText() { return document.getElementById('userInput').innerText.trim(); }
function clearInput() { document.getElementById('userInput').innerText = ''; }
function formatAttachmentSize(bytes) {
    const n = Number(bytes || 0);
    if (!Number.isFinite(n) || n <= 0) return '';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
}
function renderPendingAttachment() {
    const bar = document.getElementById('chatAttachmentBar');
    const nameEl = document.getElementById('chatAttachmentName');
    const sizeEl = document.getElementById('chatAttachmentSize');
    if (!bar || !nameEl || !sizeEl) return;
    if (!pendingChatAttachment?.file) {
        bar.style.display = 'none';
        nameEl.textContent = 'No file selected';
        sizeEl.textContent = '';
        return;
    }
    bar.style.display = 'flex';
    nameEl.textContent = pendingChatAttachment.name || 'Attachment';
    sizeEl.textContent = [pendingChatAttachment.type || 'file', formatAttachmentSize(pendingChatAttachment.size)].filter(Boolean).join(' • ');
}
function openChatAttachmentPicker() {
    document.getElementById('chatAttachmentInput')?.click();
}
function onChatAttachmentSelected(event) {
    const file = event?.target?.files?.[0];
    if (!file) return;
    pendingChatAttachment = {
        file,
        name: file.name || 'attachment',
        size: file.size || 0,
        type: file.type || 'file'
    };
    renderPendingAttachment();
}
function clearPendingAttachment() {
    pendingChatAttachment = null;
    const input = document.getElementById('chatAttachmentInput');
    if (input) input.value = '';
    renderPendingAttachment();
}
function setInputDisabled(disabled) {
    const el = document.getElementById('userInput');
    el.contentEditable = disabled ? 'false' : 'true';
    el.style.opacity   = disabled ? '0.5' : '1';
    document.getElementById('sendBtn').disabled = disabled;
    const attachBtn = document.getElementById('attachBtn');
    if (attachBtn) attachBtn.disabled = disabled;
    const taskToggle = document.getElementById('taskModeToggle');
    const testToggle = document.getElementById('codeTestToggle');
    const traceToggle = document.getElementById('liveTraceToggle');
    if (taskToggle) taskToggle.disabled = disabled;
    if (testToggle) testToggle.disabled = disabled;
    if (traceToggle) traceToggle.disabled = disabled;
}

function goalBoardStorageKey() {
    const userPart = currentUser?.username ? currentUser.username : 'guest';
    return GOAL_BOARD_KEY + '_' + userPart;
}

function saveGoalBoard() {
    try {
        localStorage.setItem(goalBoardStorageKey(), JSON.stringify(goalBoard.slice(0, 24)));
    } catch (_) {}
}

function loadGoalBoard() {
    try {
        const raw = localStorage.getItem(goalBoardStorageKey());
        const parsed = raw ? JSON.parse(raw) : [];
        goalBoard = Array.isArray(parsed) ? parsed : [];
    } catch (_) {
        goalBoard = [];
    }
    renderGoalBoard();
}

function loadAgentWorkspaceState() {
    try {
        agentWorkspaceCollapsed = localStorage.getItem(AGENT_WORKSPACE_COLLAPSED_KEY) === '1';
    } catch (_) {
        agentWorkspaceCollapsed = false;
    }
}

function saveAgentWorkspaceState() {
    try {
        localStorage.setItem(AGENT_WORKSPACE_COLLAPSED_KEY, agentWorkspaceCollapsed ? '1' : '0');
    } catch (_) {}
}

function toggleAgentWorkspaceCollapse() {
    agentWorkspaceCollapsed = !agentWorkspaceCollapsed;
    saveAgentWorkspaceState();
    updateAgentModeState();
}

function getGoalProgressState() {
    const total = goalBoard.length;
    const done = goalBoard.filter(g => g.status === 'done').length;
    const active = Math.max(0, total - done);
    const pct = total ? Math.round((done / total) * 100) : 0;
    return { total, done, active, pct };
}

function renderGoalProgress() {
    const copy = document.getElementById('agentProgressCopy');
    const fill = document.getElementById('agentProgressFill');
    const stats = getGoalProgressState();
    if (copy) {
        copy.textContent = stats.total
            ? `${stats.done} done • ${stats.active} active • ${stats.pct}% complete`
            : 'No tracked goals yet.';
    }
    if (fill) {
        fill.style.width = `${stats.pct}%`;
    }
}

function updateAgentFocusButtons() {
    document.querySelectorAll('.agent-focus-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.agentFocus === (aiAssistOptions.taskFocus || 'general'));
    });
}

function agentStatusStorageKey() {
    const userPart = currentUser?.username ? currentUser.username : 'guest';
    return AGENT_STATUS_KEY + '_' + userPart;
}

function loadAgentStatusState() {
    try {
        const raw = localStorage.getItem(agentStatusStorageKey());
        if (!raw) return;
        const parsed = JSON.parse(raw);
        agentRunState = {
            status: normalizeAgentState(parsed?.status || 'idle'),
            summary: String(parsed?.summary || '').trim(),
            nextStep: String(parsed?.nextStep || '').trim(),
        };
    } catch (_) {}
}

function saveAgentStatusState() {
    try {
        localStorage.setItem(agentStatusStorageKey(), JSON.stringify(agentRunState));
    } catch (_) {}
}

function updateAgentModeState() {
    const chip = document.getElementById('agentModeChip');
    const sub = document.getElementById('agentWorkspaceSub');
    const wrap = document.getElementById('agentWorkspace');
    const collapseBtn = document.getElementById('agentCollapseBtn');
    const activeCount = goalBoard.filter(g => g.status !== 'done').length;
    const isTaskMode = !!aiAssistOptions.taskMode;

    if (wrap) {
        wrap.classList.toggle('hidden', !isTaskMode);
        wrap.classList.toggle('muted', false);
        wrap.classList.toggle('collapsed', isTaskMode && agentWorkspaceCollapsed);
        wrap.setAttribute('aria-hidden', isTaskMode ? 'false' : 'true');
    }
    if (chip) chip.textContent = activeCount ? `${activeCount} active` : 'Planner on';
    if (collapseBtn) collapseBtn.textContent = agentWorkspaceCollapsed ? 'Show panel' : 'Hide panel';
    if (sub) {
        sub.textContent = goalBoard.length
            ? `${activeCount} active goals saved for follow-through.`
            : 'Persistent goals, task execution, and follow-through.';
    }
    renderGoalProgress();
    updateAgentFocusButtons();
    setAgentStatus(agentRunState.status, agentRunState.summary, agentRunState.nextStep);
    refreshExecutionShellVisibility();
}

function renderGoalBoard() {
    const board = document.getElementById('goalBoard');
    updateAgentModeState();
    if (!board) return;
    if (!goalBoard.length) {
        board.innerHTML = '<div class="agent-empty-state">No persistent goals yet. Add one above or save tasks from a reply.</div>';
        return;
    }
    board.innerHTML = goalBoard.map(goal => `
        <div class="agent-goal-item ${goal.status === 'done' ? 'done' : ''}">
            <button type="button" class="agent-goal-check" onclick="toggleGoalStatus('${escapeHtml(goal.id)}')">${goal.status === 'done' ? '✓' : ''}</button>
            <div class="agent-goal-copy">
                <div class="agent-goal-text">${escapeHtml(goal.title || '')}</div>
                <div class="agent-goal-meta">${goal.status === 'done' ? 'Completed' : 'In progress'}</div>
            </div>
            <button type="button" class="agent-goal-remove" onclick="removeGoal('${escapeHtml(goal.id)}')">✕</button>
        </div>
    `).join('');
}

function addGoal(title, status = 'active') {
    const clean = String(title || '').replace(/\s+/g, ' ').trim();
    if (!clean) return false;
    if (goalBoard.some(g => (g.title || '').toLowerCase() === clean.toLowerCase())) return false;
    goalBoard.unshift({
        id: 'goal_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7),
        title: clean.slice(0, 220),
        status: status === 'done' ? 'done' : 'active',
        createdAt: Date.now(),
    });
    saveGoalBoard();
    renderGoalBoard();
    return true;
}

function addGoalFromInput() {
    const input = document.getElementById('goalInput');
    const value = input?.value?.trim() || '';
    if (!value) {
        showToast('Add a goal first', 'error');
        return;
    }
    const added = addGoal(value, 'active');
    if (input) input.value = '';
    showToast(added ? 'Goal saved' : 'Goal already exists', added ? 'success' : 'error');
}

function toggleGoalStatus(id) {
    goalBoard = goalBoard.map(goal => goal.id === id
        ? { ...goal, status: goal.status === 'done' ? 'active' : 'done' }
        : goal);
    saveGoalBoard();
    renderGoalBoard();
}

function removeGoal(id) {
    goalBoard = goalBoard.filter(goal => goal.id !== id);
    saveGoalBoard();
    renderGoalBoard();
}

function clearCompletedGoals() {
    const before = goalBoard.length;
    goalBoard = goalBoard.filter(goal => goal.status !== 'done');
    saveGoalBoard();
    renderGoalBoard();
    showToast(before !== goalBoard.length ? 'Completed goals cleared' : 'No completed goals to clear', before !== goalBoard.length ? 'success' : 'error');
}

function getPersistentGoalContext() {
    if (!aiAssistOptions.taskMode) return [];
    return goalBoard.slice(0, 12).map(goal => ({
        title: goal.title,
        status: goal.status,
    }));
}

function useAgentPreset(mode = 'general') {
    const input = document.getElementById('userInput');
    const taskModeToggle = document.getElementById('taskModeToggle');
    const prompts = {
        general: buildGoalPlanningPrompt(),
        plan: 'Plan the active goals into a short ordered checklist with the best next step first.',
        build: 'Help me execute the active goals now. Start with the first build step and keep it practical.',
        debug: 'Debug the current blocker or active goals. Find the root cause and the first fix to try.',
        ship: 'Prepare the active goals for launch. Give me the release checklist and the safest next action.'
    };
    aiAssistOptions.taskFocus = ['plan', 'build', 'debug', 'ship'].includes(mode) ? mode : 'general';
    if (!aiAssistOptions.taskMode) {
        aiAssistOptions.taskMode = true;
        if (taskModeToggle) taskModeToggle.checked = true;
    }
    saveAiAssistPrefs();
    updateAgentModeState();
    if (input) {
        input.innerText = prompts[aiAssistOptions.taskFocus] || prompts.general;
        input.focus();
    }
}

function normalizeAgentState(state) {
    const clean = String(state || '').toLowerCase().trim();
    if (['planning', 'working', 'waiting', 'done'].includes(clean)) return clean;
    return 'idle';
}

function setAgentStatus(state = 'idle', summary = '', nextStep = '') {
    const strip = document.getElementById('agentStatusStrip');
    const pill = document.getElementById('agentStatusPill');
    const summaryEl = document.getElementById('agentStatusSummary');
    const nextEl = document.getElementById('agentStatusNext');
    const normalized = normalizeAgentState(state);
    const labels = {
        idle: 'Idle',
        planning: 'Planning',
        working: 'Working',
        waiting: 'Waiting',
        done: 'Done'
    };

    agentRunState = {
        status: normalized,
        summary: String(summary || (normalized === 'idle' ? 'Mission mode is ready when deeper execution is useful.' : 'Working through your request.')).trim(),
        nextStep: String(nextStep || '').trim()
    };
    saveAgentStatusState();

    if (!strip || !pill || !summaryEl || !nextEl) return;
    strip.classList.toggle('hidden', !aiAssistOptions.taskMode);
    pill.className = `agent-status-pill ${normalized}`;
    pill.textContent = labels[normalized] || 'Idle';
    summaryEl.textContent = agentRunState.summary;
    nextEl.textContent = agentRunState.nextStep ? `Next: ${agentRunState.nextStep}` : '';
    nextEl.style.display = agentRunState.nextStep ? 'block' : 'none';
}

function applyAgentPayload(payload = {}, reply = '') {
    if (!aiAssistOptions.taskMode) {
        setAgentStatus('idle', 'Task mode is off.', '');
        return;
    }
    const fallbackTasks = extractTasksFromText(reply || '');
    const activeCount = goalBoard.filter(goal => goal.status !== 'done').length;
    const summary = String(payload?.summary || '').trim() || (activeCount ? `${activeCount} active goals are being tracked.` : 'Planner ready for the next move.');
    const nextStep = String(payload?.next_step || fallbackTasks[0] || '').trim();
    const status = payload?.status || (nextStep ? 'working' : 'planning');
    renderGoalProgress();
    setAgentStatus(status, summary, nextStep);
}

function buildGoalPlanningPrompt() {
    const active = goalBoard.filter(goal => goal.status !== 'done').map(goal => `- ${goal.title}`).join('\n');
    const focus = aiAssistOptions.taskFocus || 'general';
    const focusHint = {
        general: 'Give me the next best action and a short checklist.',
        plan: 'Return a compact ordered plan with the top priority first.',
        build: 'Focus on implementation and the first concrete build step.',
        debug: 'Focus on root cause, likely issue, and the first fix to test.',
        ship: 'Focus on release readiness, final checks, and rollout safety.'
    };
    return active
        ? `Help me execute these goals step by step:\n${active}\n\n${focusHint[focus] || focusHint.general}`
        : 'Help me create a short action plan with clear next steps for my current project.';
}

function planFromGoals() {
    const input = document.getElementById('userInput');
    if (!input) return;
    input.innerText = buildGoalPlanningPrompt();
    input.focus();
}

function extractTasksFromText(text) {
    const raw = String(text || '');
    const lines = raw.split(/\n+/)
        .map(line => line.trim())
        .filter(Boolean)
        .map(line => line.replace(/^[-*•]\s+/, '').replace(/^\d+[.)]\s+/, '').trim())
        .filter(line => line.length >= 4 && line.length <= 180 && !/^here('|’)s/i.test(line));
    const unique = [];
    for (const line of lines) {
        const key = line.toLowerCase();
        if (!unique.some(item => item.toLowerCase() === key)) unique.push(line);
        if (unique.length >= 6) break;
    }
    return unique;
}

function saveTasksFromReply(replyId) {
    const text = replyActionStore[replyId] || '';
    const tasks = extractTasksFromText(text);
    let added = 0;
    tasks.forEach(task => { if (addGoal(task)) added++; });
    if (!added && text.trim()) {
        added = addGoal(text.trim().slice(0, 140)) ? 1 : 0;
    }
    showToast(added ? `Saved ${added} task${added === 1 ? '' : 's'} to workspace` : 'No new tasks found to save', added ? 'success' : 'error');
}

async function runReplyAction(action, replyId) {
    if (action === 'tasks') {
        saveTasksFromReply(replyId);
        return;
    }
    const input = document.getElementById('userInput');
    const taskModeToggle = document.getElementById('taskModeToggle');
    if (!input) return;

    if (!aiAssistOptions.taskMode) {
        aiAssistOptions.taskMode = true;
        if (taskModeToggle) taskModeToggle.checked = true;
        saveAiAssistPrefs();
        updateAgentModeState();
    }

    if (action === 'continue') {
        input.innerText = 'Continue with the next best step only.';
        setAgentStatus('working', 'Continuing the task flow.', 'Generating the next best step.');
    } else if (action === 'execute') {
        input.innerText = 'Take the first concrete action from your last plan and update the checklist.';
        setAgentStatus('working', 'Turning the plan into action.', 'Executing the first concrete step.');
    } else {
        input.innerText = 'Summarize your last response into a short checklist with one next action.';
        setAgentStatus('planning', 'Condensing the plan into a cleaner checklist.', 'Preparing a short summary.');
    }
    input.focus();
    await sendMessage();
}

function loadAiAssistPrefs() {
    try {
        const raw = localStorage.getItem(AI_ASSIST_PREFS_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        aiAssistOptions.taskMode = !!parsed?.taskMode;
        aiAssistOptions.taskFocus = ['general', 'plan', 'build', 'debug', 'ship'].includes(parsed?.taskFocus) ? parsed.taskFocus : 'general';
        aiAssistOptions.codeTest = parsed?.codeTest !== false;
        aiAssistOptions.liveTrace = !!parsed?.liveTrace;
    } catch (_) {}
}

function saveAiAssistPrefs() {
    localStorage.setItem(AI_ASSIST_PREFS_KEY, JSON.stringify(aiAssistOptions));
}

function renderTracePanel(items, title = 'Live Trace') {
    const panel = document.getElementById('liveTracePanel');
    if (!panel) return;
    if (!aiAssistOptions.liveTrace) {
        panel.style.display = 'none';
        panel.innerHTML = '';
        return;
    }
    const rows = (items || []).map(item => {
        const ts = item.time || item.ts || '';
        const msg = item.message || item.msg || '';
        return `<div class="live-trace-item"><span class="live-trace-time">${escapeHtml(String(ts))}</span>${escapeHtml(String(msg))}</div>`;
    }).join('');
    panel.style.display = 'block';
    panel.innerHTML = `
        <div class="live-trace-title">${escapeHtml(title)}</div>
        <div class="live-trace-list">${rows || '<div class="live-trace-item">No trace events.</div>'}</div>
    `;
}

function renderClientTrace(message) {
    const now = new Date();
    const time = now.toLocaleTimeString([], { hour12: false });
    renderTracePanel([{ time, message }], 'Live Trace (In Progress)');
}

function formatServerTrace(trace) {
    if (!Array.isArray(trace)) return [];
    return trace.map(t => {
        let time = '';
        if (typeof t.ts === 'number') {
            const d = new Date(t.ts * 1000);
            time = d.toLocaleTimeString([], { hour12: false });
        }
        const prefix = t.stage ? `[${t.stage}] ` : '';
        return { time, message: prefix + (t.message || '') };
    });
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 90000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        return response;
    } finally {
        clearTimeout(timer);
    }
}

function fastHexHash(input) {
    const str = String(input || '');
    let hash = 5381;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) + hash + str.charCodeAt(i)) >>> 0;
    }
    return hash.toString(16).padStart(8, '0');
}

function buildEdgeChatCacheKey(messages, opts = {}) {
    const latest = Array.isArray(messages) && messages.length > 0
        ? String(messages[messages.length - 1]?.content || '').trim().toLowerCase().replace(/\s+/g, ' ')
        : '';
    const basis = [
        'v1',
        latest,
        opts.taskMode ? '1' : '0',
        String(opts.taskFocus || 'general').toLowerCase(),
        String(opts.provider || '').toLowerCase(),
        String(opts.model || '').toLowerCase()
    ].join('|');
    const h1 = fastHexHash(basis);
    const h2 = fastHexHash('salt|' + basis.split('').reverse().join(''));
    return (h1 + h2 + h1 + h2).slice(0, 32);
}

function setAiToolsOpen(open) {
    const wrap = document.getElementById('aiToolsWrap');
    const btn = document.getElementById('aiToolsToggleBtn');
    if (!wrap || !btn) return;
    wrap.classList.toggle('open', !!open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function startImagePrompt() {
    const input = document.getElementById('userInput');
    if (!input) return;
    setAiToolsOpen(true);
    const current = input.innerText.trim();
    if (!current) {
        input.innerText = '/image ';
    } else if (!current.startsWith('/image')) {
        input.innerText = '/image ' + current;
    }
    input.focus();
    const range = document.createRange();
    const sel = window.getSelection();
    range.selectNodeContents(input);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
}

function toggleAiTools() {
    const wrap = document.getElementById('aiToolsWrap');
    if (!wrap) return;
    setAiToolsOpen(!wrap.classList.contains('open'));
}

function initAiAssistControls() {
    loadAiAssistPrefs();
    loadAgentWorkspaceState();
    loadAgentStatusState();
    loadExecutionState();
    bindExecutionControls();
    updateApprovalButton();
    const taskModeToggle = document.getElementById('taskModeToggle');
    const codeTestToggle = document.getElementById('codeTestToggle');
    const liveTraceToggle = document.getElementById('liveTraceToggle');
    if (taskModeToggle) {
        taskModeToggle.checked = aiAssistOptions.taskMode;
        taskModeToggle.addEventListener('change', () => {
            aiAssistOptions.taskMode = !!taskModeToggle.checked;
            saveAiAssistPrefs();
            updateAgentModeState();
        });
    }
    if (codeTestToggle) {
        codeTestToggle.checked = aiAssistOptions.codeTest;
        codeTestToggle.addEventListener('change', () => {
            aiAssistOptions.codeTest = !!codeTestToggle.checked;
            saveAiAssistPrefs();
        });
    }
    if (liveTraceToggle) {
        liveTraceToggle.checked = aiAssistOptions.liveTrace;
        liveTraceToggle.addEventListener('change', () => {
            aiAssistOptions.liveTrace = !!liveTraceToggle.checked;
            saveAiAssistPrefs();
            if (!aiAssistOptions.liveTrace) renderTracePanel([]);
        });
    }
    updateAgentModeState();
}

document.getElementById('userInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// ── MOBILE DRAWER ──
function openDrawer() {
    renderConvList(true);
    document.getElementById('mobileDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
}
function closeDrawer() {
    document.getElementById('mobileDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
}

// ── MOBILE SCREEN SWITCHER ──
function showMobileScreen(screen) {
    document.getElementById('accountScreen')?.classList.remove('active');
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active'));

    if (screen === 'account') {
        document.getElementById('accountScreen')?.classList.add('active');
        document.getElementById('navAccount')?.classList.add('active');
    } else {
        document.getElementById('navChat')?.classList.add('active');
        document.getElementById('userInput')?.focus();
    }
}

// ── SEND MESSAGE ──
async function sendMessage() {
    const typedMessage = getInputText();
    const attachment = pendingChatAttachment;
    if (!typedMessage && !attachment) return;

    const message = typedMessage || 'Please analyze this attachment.';
    const attachmentLine = attachment ? `
<div style="margin-top:8px;font-size:11px;color:#a78bfa">📎 ${escapeHtml(attachment.name)}${attachment.size ? ` (${escapeHtml(formatAttachmentSize(attachment.size))})` : ''}</div>` : '';
    const displayMessage = attachment ? `${message}

[Attached: ${attachment.name}]` : message;

    if (!activeConvId) await newConversation();

    const chatbox    = document.getElementById('chatbox');
    const emptyState = document.getElementById('emptyState');
    if (emptyState) emptyState.remove();

    chatbox.innerHTML += `<div class="msg user"><div class="avatar">👤</div><div class="bubble">${escapeHtml(message)}${attachmentLine}</div></div>`;
    const thinkingId = 'thinking_' + Date.now();
    chatbox.innerHTML += `<div class="msg ai" id="${thinkingId}"><div class="msg-role-label">Lyralink</div><div class="bubble"><div class="thinking-box"><div class="thinking-box-header"><span class="thinking-box-label">Thinking</span><span class="thinking-dots"><span></span><span></span><span></span></span></div><div class="thinking-box-body">Checking the request, mapping the best path, and preparing the answer.</div></div></div></div>`;

    clearInput();
    clearPendingAttachment();
    if (window.matchMedia('(max-width: 767px)').matches) {
        setAiToolsOpen(false);
    }
    setInputDisabled(true);
    if (aiAssistOptions.taskMode) {
        setAgentStatus('planning', attachment ? 'Reviewing your request and attachment.' : 'Reviewing your request and mapping the next move.', 'Generating the next step now.');
    }
    if (aiAssistOptions.liveTrace) renderClientTrace('Preparing request');
    chatbox.scrollTop = chatbox.scrollHeight;
    await saveMessage('user', displayMessage);

    const messages = [
        ...getActiveMessages().slice(0, -1),
        { role: 'user', content: displayMessage }
    ];

    const storedModelPrefs = getStoredModelPrefs();
    const providerToSend = selectedLlmProvider || storedModelPrefs.provider || undefined;
    const modelToSend = selectedLlmModel || storedModelPrefs.model || undefined;
    const executionPayload = buildExecutionControlPayload();

    try {
        if (aiAssistOptions.liveTrace) renderClientTrace('Generating and validating response');
        const requestTimeoutMs = (aiAssistOptions.taskMode || aiAssistOptions.codeTest) ? 180000 : 90000;
        let response;
        if (attachment?.file) {
            const fd = new FormData();
            fd.append('messages', JSON.stringify(messages));
            fd.append('user_id', currentUser?.username || '');
            fd.append('username', currentUser?.username || '');
            fd.append('user_plan', currentUser?.plan || 'free');
            if (providerToSend) fd.append('provider', providerToSend);
            if (modelToSend) fd.append('model', modelToSend);
            fd.append('dev_mode', isDevUser() && devModeEnabled ? '1' : '0');
            fd.append('task_mode', aiAssistOptions.taskMode ? '1' : '0');
            fd.append('task_focus', aiAssistOptions.taskFocus || 'general');
            fd.append('persistent_goals', JSON.stringify(getPersistentGoalContext()));
            fd.append('run_code_tests', aiAssistOptions.codeTest ? '1' : '0');
            fd.append('live_trace', aiAssistOptions.liveTrace ? '1' : '0');
            fd.append('project_id', executionPayload.project_id || 'default');
            fd.append('work_mode', executionPayload.work_mode ? '1' : '0');
            fd.append('long_running_agent', executionPayload.long_running_agent ? '1' : '0');
            fd.append('approval_required', executionPayload.approval_required ? '1' : '0');
            fd.append('approval_granted', executionPayload.approval_granted ? '1' : '0');
            fd.append('agent_budget_tokens', String(executionPayload.agent_budget_tokens || 0));
            fd.append('agent_budget_seconds', String(executionPayload.agent_budget_seconds || 180));
            fd.append('agent_permissions', JSON.stringify(executionPayload.agent_permissions || []));
            fd.append('task_state', JSON.stringify(executionPayload.task_state || {}));
            fd.append('client_channel', executionPayload.client_channel || 'web');
            fd.append('webhook_events', JSON.stringify(executionPayload.webhook_events || []));
            if (executionPayload.agent_checkpoint_note) fd.append('agent_checkpoint_note', executionPayload.agent_checkpoint_note);
            if (executionPayload.rollback_to_checkpoint) fd.append('rollback_to_checkpoint', executionPayload.rollback_to_checkpoint);
            fd.append('attachment', attachment.file, attachment.name || 'attachment');
            response = await fetchWithTimeout('/api/chat.php', { method: 'POST', body: fd }, requestTimeoutMs);
        } else {
            const edgeCacheKey = (!currentUser?.username)
                ? buildEdgeChatCacheKey(messages, {
                    taskMode: aiAssistOptions.taskMode,
                    taskFocus: aiAssistOptions.taskFocus || 'general',
                    provider: providerToSend,
                    model: modelToSend
                })
                : '';
            const headers = { 'Content-Type': 'application/json' };
            if (edgeCacheKey) {
                headers['X-Chat-Cache-Key'] = edgeCacheKey;
            }
            response = await fetchWithTimeout('/api/chat.php', {
                method: 'POST',
                headers,
                body: JSON.stringify({
                    messages,
                    cache_key: edgeCacheKey || undefined,
                    user_id:    currentUser?.username || null,
                    username:   currentUser?.username || null,
                    user_plan:  currentUser?.plan     || 'free',
                    provider:   providerToSend,
                    model:      modelToSend,
                    dev_mode:   isDevUser() ? devModeEnabled : false,
                    task_mode: aiAssistOptions.taskMode,
                    task_focus: aiAssistOptions.taskFocus || 'general',
                    persistent_goals: getPersistentGoalContext(),
                    run_code_tests: aiAssistOptions.codeTest,
                    live_trace: aiAssistOptions.liveTrace,
                    project_id: executionPayload.project_id || 'default',
                    work_mode: executionPayload.work_mode,
                    long_running_agent: executionPayload.long_running_agent,
                    approval_required: executionPayload.approval_required,
                    approval_granted: executionPayload.approval_granted,
                    agent_budget_tokens: executionPayload.agent_budget_tokens,
                    agent_budget_seconds: executionPayload.agent_budget_seconds,
                    agent_permissions: executionPayload.agent_permissions,
                    task_state: executionPayload.task_state,
                    client_channel: executionPayload.client_channel || 'web',
                    webhook_events: executionPayload.webhook_events,
                    agent_checkpoint_note: executionPayload.agent_checkpoint_note || undefined,
                    rollback_to_checkpoint: executionPayload.rollback_to_checkpoint || undefined
                })
            }, requestTimeoutMs);
        }
        if (!response.ok) {
            let payload = {};
            try { payload = await response.json(); } catch (_) {}
            const httpCode = Number(payload?.http_code ?? payload?.status ?? response.status ?? 0);
            const detail = payload?.error_detail || payload?.message || payload?.error || `HTTP ${response.status || 'unknown'} response received.`;
            const devMessage = isDevUser()
                ? `Request failed (${httpCode || response.status || 'unknown'}): ${detail}`
                : 'The request could not be completed right now. Please try again.';
            document.getElementById(thinkingId)?.remove();
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(devMessage)}</div></div>`;
            setAgentStatus('waiting', devMessage, isDevUser() ? 'Review the developer error details in the request response.' : 'Retry or switch to a faster mode.');
            if (aiAssistOptions.liveTrace) renderClientTrace(`HTTP error ${httpCode || response.status || 'unknown'}: ${detail}`);
            return;
        }

        let data;
        let madeBy = 'Lyralink';
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        if (contentType.includes('text/event-stream') && response.body) {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let liveReply = '';
            let finalPayload = null;

            const updateLiveBubble = () => {
                const root = document.getElementById(thinkingId);
                const bubble = root?.querySelector('.bubble');
                if (!bubble) return;
                const readableLiveReply = repairJumbledReplyText(liveReply);
                bubble.innerHTML = buildStreamingAssistantBubbleHtml(readableLiveReply, '', '', aiAssistOptions.taskMode, '', '', '', madeBy);
                chatbox.scrollTop = chatbox.scrollHeight;
            };

            const handleEventBlock = (block) => {
                const lines = String(block || '').split(/\r?\n/);
                let eventName = 'message';
                const payloadLines = [];
                for (const line of lines) {
                    if (line.startsWith('event:')) {
                        eventName = line.slice(6).trim() || 'message';
                    } else if (line.startsWith('data:')) {
                        payloadLines.push(line.slice(5).trimStart());
                    }
                }
                const rawPayload = payloadLines.join('\n').trim();
                if (!rawPayload) return;
                let parsedPayload = null;
                try {
                    parsedPayload = JSON.parse(rawPayload);
                } catch (_) {
                    parsedPayload = null;
                }
                if (eventName === 'status' && document.getElementById(thinkingId) && parsedPayload?.message) {
                    const root = document.getElementById(thinkingId);
                    const body = root?.querySelector('.thinking-box-body');
                    if (body) body.textContent = parsedPayload.message;
                    return;
                }
                if (eventName === 'delta' && parsedPayload?.delta) {
                    liveReply += String(parsedPayload.delta);
                    updateLiveBubble();
                    return;
                }
                if (eventName === 'final' && parsedPayload && typeof parsedPayload === 'object') {
                    finalPayload = parsedPayload;
                    madeBy = parsedPayload.made_by || madeBy;
                }
            };

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let separatorIndex;
                while ((separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, separatorIndex);
                    buffer = buffer.slice(separatorIndex + 2);
                    handleEventBlock(block);
                }
            }

            buffer += decoder.decode();
            if (buffer.trim() !== '') {
                handleEventBlock(buffer);
            }
            data = finalPayload || { reply: liveReply, made_by: madeBy };
            document.getElementById(thinkingId)?.remove();
        } else {
            data = await response.json();
            document.getElementById(thinkingId)?.remove();
        }

        if (data.error === 'limit_reached') {
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble" style="border-left-color:rgba(255,107,53,0.6)">
                <strong style="color:#ff6b35">Monthly limit reached</strong><br><br>
                ${escapeHtml(data.message)}<br><br>
                <a href="/pages/pricing" style="display:inline-block;margin-top:4px;padding:8px 16px;background:#7c3aed;color:white;border-radius:8px;text-decoration:none;font-size:13px;">⚡ Upgrade Plan</a>
                &nbsp;
                <a href="/pages/pricing#credits" style="display:inline-block;margin-top:4px;padding:8px 16px;background:none;border:1px solid #ff6b35;color:#ff6b35;border-radius:8px;text-decoration:none;font-size:13px;">Buy Credits</a>
            </div></div>`;
            setAgentStatus('waiting', 'Usage limit reached for this month.', 'Upgrade or add credits to continue.');
            setInputDisabled(false);
            document.getElementById('userInput').focus();
            return;
        }

        if (data.error === 'attachment_error') {
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(data.message || 'That attachment could not be processed.')}</div></div>`;
            setAgentStatus('waiting', data.message || 'That attachment could not be processed.', 'Try another file or resend it.');
            setInputDisabled(false);
            document.getElementById('userInput').focus();
            return;
        }

        // Honest failure surface. Never present a silent void: state what
        // happened, whether anything ran, and what to do next.
        const lyraFailureText = (() => {
            const code = String((data && data.error) || '').trim();
            const known = {
                approval_required: 'This action needs your approval before I can continue. Approve it and resend, or tell me to proceed.',
                timeout: 'The model did not finish in time. Nothing was executed or changed. Try a shorter request, or ask me to split it into steps.',
                rate_limited: 'Too many requests right now. Nothing was executed or changed. Wait a moment and resend.',
                usage_limit: 'The usage limit for this period was reached. Nothing was executed or changed.',
                invalid_benchmark_payload: 'The request could not be parsed. Nothing was changed. Please resend.',
            };
            if (known[code]) {
                return known[code];
            }
            if (code) {
                return 'The request did not complete (' + code + '). Nothing was executed or changed. Resend to try again.';
            }
            return 'The request returned no output, so nothing was executed or changed. Resend to try again.';
        })();
        const reply = repairJumbledReplyText(
            (typeof data.reply === 'string' && data.reply.trim() !== '') ? data.reply : lyraFailureText
        );
        const aiThinking = data.thinking || '';
        const codeTestCard = renderCodeTestCard(data.code_test);
        const thinkingEl = document.getElementById(thinkingId);
        const generatedImageUrl = data.generated_image_url || '';
        const generatedImageDownloadUrl = data.generated_image_download_url || '';
        madeBy = data.made_by || madeBy;
        if (thinkingEl) {
            await streamAssistantMessage(thinkingId, reply, codeTestCard, '', aiAssistOptions.taskMode, aiThinking, generatedImageUrl, data.image_prompt || '', generatedImageDownloadUrl, madeBy);
            thinkingEl.removeAttribute('id');
        } else {
            chatbox.innerHTML += buildAssistantMessageHtml(reply, codeTestCard, '', aiAssistOptions.taskMode, aiThinking, generatedImageUrl, data.image_prompt || '', generatedImageDownloadUrl, madeBy);
        }

        applyAgentPayload(data.agent || {}, reply);
        applyExecutionPayload(data);

        if (data.error === 'approval_required') {
            setAgentStatus('waiting', 'Approval is required before the mission can continue.', 'Click "Approve next run" and resend your request.');
        }

        if (aiAssistOptions.liveTrace) {
            const items = formatServerTrace(data.trace);
            renderTracePanel(items, 'Live Trace');
        }

        await saveMessage('assistant', reply, aiThinking);
        const conv = convCache.find(c => c.conv_id === activeConvId);
        document.getElementById('chatTitle').textContent = conv?.title || 'Chat';
        document.getElementById('chatTitle').className   = 'chat-title has-msgs';
        if (data.debug) renderDebug(data.debug);

    } catch (e) {
        document.getElementById(thinkingId)?.remove();
        const errorName = e?.name || 'Error';
        const errorMsg = e?.message || 'The request was interrupted before a response was received.';
        const publicText = 'The request timed out before the model finished. Please retry or switch to a faster mode.';
        const devText = isDevUser()
            ? `Request failed (${errorName}): ${errorMsg}`
            : publicText;
        chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(devText)}</div></div>`;
        setAgentStatus('waiting', devText, isDevUser() ? 'Inspect the network failure and retry with a model route that matches the request.' : 'Retry or switch to a faster mode.');
        if (aiAssistOptions.liveTrace) renderClientTrace(isDevUser() ? `${errorName}: ${errorMsg}` : 'Request failed');
    }

    chatbox.scrollTop = chatbox.scrollHeight;
    setInputDisabled(false);
    document.getElementById('userInput').focus();
}

// ── AUTH (desktop) ──
function switchAuthTab(tab, el) {
    document.querySelectorAll('#userLoggedOut .auth-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('loginForm').style.display    = tab === 'login'    ? 'flex' : 'none';
    document.getElementById('registerForm').style.display = tab === 'register' ? 'flex' : 'none';
}

function closeAuthModal() {
    const backdrop = document.getElementById('authModalBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}
    function openAccountModal() {
        document.getElementById('acctBackdrop').classList.add('open');
    }
    function closeAccountModal() {
        document.getElementById('acctBackdrop').classList.remove('open');
    }
    function acctBackdropClick(e) {
        if (e.target === document.getElementById('acctBackdrop')) closeAccountModal();
    }
    function setInput(text) {
        const el = document.getElementById('userInput');
        if (!el) return;
        el.textContent = text;
        el.dispatchEvent(new Event('input'));
        el.focus();
        // Move cursor to end
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(el);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }

function showRecoveryCodesModal(codes) {
    const list = document.getElementById('recoveryCodesList');
    const backdrop = document.getElementById('recoveryCodesBackdrop');
    if (!list || !backdrop || !Array.isArray(codes) || !codes.length) return;
    list.innerHTML = codes.map(code => `<div class="auth-code-item">${escHtml(code)}</div>`).join('');
    backdrop.style.display = 'flex';
}

function closeRecoveryCodesModal() {
    const backdrop = document.getElementById('recoveryCodesBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}

function openProfileSettings() {
    const backdrop = document.getElementById('profileSettingsBackdrop');
    if (!backdrop) return;
    backdrop.style.display = 'flex';
    loadDiscordStatus();
    load2FAStatus();
    loadModelOptions();
    loadDataDeletionStatus();
}

function closeProfileSettings() {
    const backdrop = document.getElementById('profileSettingsBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}

function modelPrefKey(suffix) {
    const userPart = currentUser?.username ? currentUser.username : 'guest';
    return 'lyralink_model_' + suffix + '_' + userPart;
}

function getStoredModelPrefs() {
    return {
        provider: localStorage.getItem(modelPrefKey('provider')),
        model: localStorage.getItem(modelPrefKey('name')),
    };
}

function renderModelSelectors(defaultProvider = '', defaultModel = '') {
    const providerSelect = document.getElementById('modelProviderSelect');
    const modelSelect = document.getElementById('modelNameSelect');
    const msg = document.getElementById('modelSettingsMsg');
    if (!providerSelect || !modelSelect || !msg) return;

    if (!availableModelProviders.length) {
        providerSelect.innerHTML = '<option value="">No providers configured</option>';
        modelSelect.innerHTML = '<option value="">No models available</option>';
        providerSelect.disabled = true;
        modelSelect.disabled = true;
        selectedLlmProvider = null;
        selectedLlmModel = null;
        msg.style.color = 'var(--error)';
        msg.textContent = 'No model providers are configured with API keys.';
        return;
    }

    providerSelect.disabled = false;
    modelSelect.disabled = false;

    providerSelect.innerHTML = availableModelProviders
        .map(p => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.label)}</option>`)
        .join('');

    const stored = getStoredModelPrefs();
    const providerIds = availableModelProviders.map(p => p.id);
    const preferredProvider = [stored.provider, selectedLlmProvider, defaultProvider, providerIds[0]].find(v => v && providerIds.includes(v));
    providerSelect.value = preferredProvider || providerIds[0];

    onModelProviderChange(defaultModel || stored.model || '');
    msg.style.color = 'var(--text-muted)';
    msg.textContent = 'Configured providers only are shown.';
}

function onModelProviderChange(preferredModel = '') {
    const providerSelect = document.getElementById('modelProviderSelect');
    const modelSelect = document.getElementById('modelNameSelect');
    if (!providerSelect || !modelSelect) return;

    const current = availableModelProviders.find(p => p.id === providerSelect.value) || availableModelProviders[0];
    const models = Array.isArray(current?.models) ? current.models : [];

    modelSelect.innerHTML = models.length
        ? models.map(m => `<option value="${escapeHtml(m)}">${escapeHtml(m)}</option>`).join('')
        : '<option value="">No models available</option>';

    const stored = getStoredModelPrefs();
    const pick = [preferredModel, stored.model, models[0]].find(v => v && models.includes(v));
    if (pick) modelSelect.value = pick;

    selectedLlmProvider = current?.id || null;
    selectedLlmModel = modelSelect.value || null;
}

function onModelNameChange() {
    const providerSelect = document.getElementById('modelProviderSelect');
    const modelSelect = document.getElementById('modelNameSelect');
    if (!providerSelect || !modelSelect) return;
    selectedLlmProvider = providerSelect.value || selectedLlmProvider;
    selectedLlmModel = modelSelect.value || null;
}

function saveModelPreference() {
    const providerSelect = document.getElementById('modelProviderSelect');
    const modelSelect = document.getElementById('modelNameSelect');
    const msg = document.getElementById('modelSettingsMsg');
    if (!providerSelect || !modelSelect || !msg) return;

    if (!providerSelect.value || !modelSelect.value) {
        msg.style.color = 'var(--error)';
        msg.textContent = 'Choose a valid provider and model.';
        return;
    }

    selectedLlmProvider = providerSelect.value;
    selectedLlmModel = modelSelect.value;
    localStorage.setItem(modelPrefKey('provider'), selectedLlmProvider);
    localStorage.setItem(modelPrefKey('name'), selectedLlmModel);
    msg.style.color = 'var(--success)';
    msg.textContent = 'Saved. New messages will use this model.';
}

async function loadModelOptions() {
    const msg = document.getElementById('modelSettingsMsg');
    try {
        const fd = new FormData();
        fd.append('action', 'get_model_options');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to load model options');
        }
        availableModelProviders = Array.isArray(data.providers) ? data.providers : [];
        renderModelSelectors(data.default_provider || '', data.default_model || '');
    } catch (e) {
        availableModelProviders = [];
        renderModelSelectors('', '');
        if (msg) {
            msg.style.color = 'var(--error)';
            msg.textContent = 'Could not load model options.';
        }
    }
}

function openAuthPrompt(config = {}) {
    const backdrop = document.getElementById('authModalBackdrop');
    const titleEl = document.getElementById('authModalTitle');
    const subEl = document.getElementById('authModalSub');
    const inputEl = document.getElementById('authModalInput');
    const altEl = document.getElementById('authModalAlt');
    const errEl = document.getElementById('authModalError');
    const cancelBtn = document.getElementById('authModalCancel');
    const submitBtn = document.getElementById('authModalSubmit');

    if (!backdrop || !titleEl || !subEl || !inputEl || !errEl || !cancelBtn || !submitBtn || !altEl) {
        return Promise.resolve({ cancelled: true });
    }

    titleEl.textContent = config.title || 'Verification Required';
    subEl.textContent = config.subtitle || '';
    inputEl.type = config.type || 'text';
    inputEl.value = '';
    inputEl.placeholder = config.placeholder || '';
    inputEl.maxLength = config.maxLength || 128;
    inputEl.autocomplete = config.autocomplete || 'one-time-code';
    errEl.textContent = '';
    submitBtn.textContent = config.submitText || 'Continue';
    cancelBtn.textContent = config.cancelText || 'Cancel';

    if (config.altText) {
        altEl.style.display = 'inline-block';
        altEl.textContent = config.altText;
    } else {
        altEl.style.display = 'none';
    }

    backdrop.style.display = 'flex';
    setTimeout(() => inputEl.focus(), 20);

    return new Promise(resolve => {
        const cleanup = () => {
            submitBtn.onclick = null;
            cancelBtn.onclick = null;
            altEl.onclick = null;
            inputEl.onkeydown = null;
        };

        cancelBtn.onclick = () => {
            cleanup();
            closeAuthModal();
            resolve({ cancelled: true });
        };

        const submit = () => {
            const value = inputEl.value.trim();
            if (!value) {
                errEl.textContent = config.requiredError || 'This field is required';
                return;
            }
            cleanup();
            closeAuthModal();
            resolve({ cancelled: false, value, alt: false });
        };

        submitBtn.onclick = submit;
        inputEl.onkeydown = (e) => {
            if (e.key === 'Enter') submit();
            if (e.key === 'Escape') cancelBtn.onclick();
        };

        altEl.onclick = () => {
            cleanup();
            closeAuthModal();
            resolve({ cancelled: false, alt: true, value: '' });
        };
    });
}

async function verifyEmailFlow(email, msgEl) {
    const step = await openAuthPrompt({
        title: 'Verify Your Email',
        subtitle: 'Enter the 6-digit code sent to ' + email,
        placeholder: '123456',
        maxLength: 6,
        type: 'text',
        requiredError: 'Verification code is required'
    });
    if (step.cancelled) {
        return { success: false, error: 'Email verification required to continue.' };
    }
    const fd = new FormData();
    fd.append('action', 'verify_email_code');
    fd.append('email', email);
    fd.append('code', step.value.trim());
    let data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();

    if (data.requires_2fa) {
        data = await verify2FAFlow(data.method || 'totp', msgEl);
    }
    return data;
}

async function verify2FAFlow(method, msgEl) {
    const isYubi = method === 'yubikey';
    const step = await openAuthPrompt({
        title: 'Two-Factor Authentication',
        subtitle: isYubi ? 'Touch your YubiKey and paste the OTP.' : 'Enter your 6-digit authenticator code.',
        placeholder: isYubi ? 'YubiKey OTP' : '123456',
        maxLength: isYubi ? 64 : 6,
        type: 'text',
        altText: 'Use backup recovery code instead',
        requiredError: isYubi ? 'YubiKey OTP is required' : 'Authenticator code is required'
    });
    if (step.cancelled) {
        return { success: false, error: 'Two-factor verification required.' };
    }

    const fd = new FormData();
    fd.append('action', 'verify_2fa');
    if (step.alt) {
        const recoveryStep = await openAuthPrompt({
            title: 'Backup Recovery Code',
            subtitle: 'Enter one of your one-time backup codes.',
            placeholder: 'ABCD-EFGH',
            maxLength: 16,
            type: 'text',
            requiredError: 'Recovery code is required'
        });
        if (recoveryStep.cancelled) {
            return { success: false, error: 'Two-factor verification required.' };
        }
        fd.append('recovery_code', recoveryStep.value.trim());
    } else if (isYubi) {
        fd.append('yubikey_otp', step.value.trim());
    } else {
        fd.append('code', step.value.trim());
    }

    return await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
}

async function changePasswordFlow(msgEl) {
    // Step 1: new password
    const step1 = await openAuthPrompt({
        title: 'Set a New Password',
        subtitle: 'Your password was reset by an administrator. Choose a new password (min. 8 characters).',
        placeholder: 'New password',
        maxLength: 128,
        type: 'password',
        requiredError: 'Please enter a new password'
    });
    if (step1.cancelled) return { success: false, error: 'You must set a new password to continue.' };
    if (step1.value.length < 8) return { success: false, error: 'Password must be at least 8 characters.' };

    // Step 2: confirm
    const step2 = await openAuthPrompt({
        title: 'Confirm New Password',
        subtitle: 'Re-enter your new password to confirm.',
        placeholder: 'Confirm password',
        maxLength: 128,
        type: 'password',
        requiredError: 'Please confirm your new password'
    });
    if (step2.cancelled) return { success: false, error: 'You must set a new password to continue.' };
    if (step1.value !== step2.value) return { success: false, error: 'Passwords do not match. Please try again.' };

    const fd = new FormData();
    fd.append('action', 'change_temp_password');
    fd.append('new_password', step1.value);
    fd.append('confirm_password', step2.value);
    return await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
}

async function resolveAuthChallenges(data, email, msgEl) {
    let next = data;

    if (next.requires_password_change) {
        if (msgEl) { msgEl.className = 'auth-ok'; msgEl.textContent = 'Please set a new password…'; }
        next = await changePasswordFlow(msgEl);
        if (!next.success) return next;
    }

    if (next.requires_email_verification || next.needs_email_verification) {
        if (msgEl) {
            msgEl.className = 'auth-ok';
            msgEl.textContent = 'Verification email sent. Entering code...';
        }
        next = await verifyEmailFlow(next.email || email, msgEl);
    }

    if (next.requires_2fa) {
        if (msgEl) {
            msgEl.className = 'auth-ok';
            msgEl.textContent = 'Two-factor verification required...';
        }
        next = await verify2FAFlow(next.method || 'totp', msgEl);
    }

    return next;
}

async function login() {
    const msgEl = document.getElementById('loginMsg');
    const fd = new FormData();
    fd.append('action','login'); fd.append('email', document.getElementById('loginEmail').value); fd.append('password', document.getElementById('loginPass').value);
    let data = await (await fetch('/api/auth.php',{method:'POST',body:fd})).json();
    data = await resolveAuthChallenges(data, document.getElementById('loginEmail').value, msgEl);
    if (data.success) { currentUser={username:data.username}; showLoggedIn(data.username); }
    else { msgEl.className='auth-error'; msgEl.textContent=data.error || 'Login failed'; }
}

async function forgotPasswordFlow(isMobile) {
    const emailInput = document.getElementById(isMobile ? 'mobileLoginEmail' : 'loginEmail');
    const msgEl = document.getElementById(isMobile ? 'mobileLoginMsg' : 'loginMsg');
    const email = String(emailInput?.value || '').trim();
    if (!email || !email.includes('@')) {
        msgEl.className = 'auth-error';
        msgEl.textContent = 'Enter your email first.';
        return;
    }

    msgEl.className = 'auth-ok';
    msgEl.textContent = 'Sending reset code...';

    const req = new FormData();
    req.append('action', 'request_password_reset');
    req.append('email', email);
    await fetch('/api/auth.php', { method: 'POST', body: req });

    const codeStep = await openAuthPrompt({
        title: 'Password Reset Code',
        subtitle: 'Enter the 6-digit code sent to ' + email,
        placeholder: '123456',
        maxLength: 6,
        type: 'text',
        requiredError: 'Reset code is required'
    });
    if (codeStep.cancelled) {
        msgEl.className = 'auth-error';
        msgEl.textContent = 'Password reset cancelled.';
        return;
    }

    const passStep = await openAuthPrompt({
        title: 'New Password',
        subtitle: 'Create a new password (minimum 8 characters).',
        placeholder: 'New password',
        maxLength: 128,
        type: 'password',
        requiredError: 'New password is required'
    });
    if (passStep.cancelled) {
        msgEl.className = 'auth-error';
        msgEl.textContent = 'Password reset cancelled.';
        return;
    }

    const confirmStep = await openAuthPrompt({
        title: 'Confirm Password',
        subtitle: 'Re-enter your new password.',
        placeholder: 'Confirm password',
        maxLength: 128,
        type: 'password',
        requiredError: 'Password confirmation is required'
    });
    if (confirmStep.cancelled) {
        msgEl.className = 'auth-error';
        msgEl.textContent = 'Password reset cancelled.';
        return;
    }

    const fd = new FormData();
    fd.append('action', 'reset_password');
    fd.append('email', email);
    fd.append('code', codeStep.value.trim());
    fd.append('new_password', passStep.value);
    fd.append('confirm_password', confirmStep.value);
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();

    if (data.success) {
        const passInput = document.getElementById(isMobile ? 'mobileLoginPass' : 'loginPass');
        if (passInput) passInput.value = '';
        msgEl.className = 'auth-ok';
        msgEl.textContent = 'Password reset successful. You can log in now.';
    } else {
        msgEl.className = 'auth-error';
        msgEl.textContent = data.error || 'Password reset failed';
    }
}

async function register() {
    const msgEl = document.getElementById('registerMsg');
    const fd = new FormData();
    fd.append('action','register'); fd.append('username', document.getElementById('regUsername').value); fd.append('email', document.getElementById('regEmail').value); fd.append('password', document.getElementById('regPass').value);
    let data = await (await fetch('/api/auth.php',{method:'POST',body:fd})).json();
    data = await resolveAuthChallenges(data, document.getElementById('regEmail').value, msgEl);
    if (data.success) { currentUser={username:data.username}; showLoggedIn(data.username); }
    else { msgEl.className='auth-error'; msgEl.textContent=data.error || 'Registration failed'; }
}

// ── AUTH (mobile) ──
function switchMobileAuthTab(tab, el) {
    document.querySelectorAll('#mobileUserLoggedOut .auth-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('mobileLoginForm').style.display    = tab === 'login'    ? 'flex' : 'none';
    document.getElementById('mobileRegisterForm').style.display = tab === 'register' ? 'flex' : 'none';
}
async function mobileLogin() {
    const msgEl = document.getElementById('mobileLoginMsg');
    const fd = new FormData();
    fd.append('action','login'); fd.append('email', document.getElementById('mobileLoginEmail').value); fd.append('password', document.getElementById('mobileLoginPass').value);
    let data = await (await fetch('/api/auth.php',{method:'POST',body:fd})).json();
    data = await resolveAuthChallenges(data, document.getElementById('mobileLoginEmail').value, msgEl);
    if (data.success) { currentUser={username:data.username}; showLoggedIn(data.username); }
    else { msgEl.className='auth-error'; msgEl.textContent=data.error || 'Login failed'; }
}
async function mobileRegister() {
    const msgEl = document.getElementById('mobileRegisterMsg');
    const fd = new FormData();
    fd.append('action','register'); fd.append('username', document.getElementById('mobileRegUsername').value); fd.append('email', document.getElementById('mobileRegEmail').value); fd.append('password', document.getElementById('mobileRegPass').value);
    let data = await (await fetch('/api/auth.php',{method:'POST',body:fd})).json();
    data = await resolveAuthChallenges(data, document.getElementById('mobileRegEmail').value, msgEl);
    if (data.success) { currentUser={username:data.username}; showLoggedIn(data.username); }
    else { msgEl.className='auth-error'; msgEl.textContent=data.error || 'Registration failed'; }
}

async function logout() {
    const fd = new FormData(); fd.append('action','logout');
    await fetch('/api/auth.php',{method:'POST',body:fd});
    currentUser = null;
    setOperatorDashboardVisible(false);
    // Switch to guest mode — reload convs from localStorage
    document.getElementById('userLoggedIn').style.display='none'; document.getElementById('userLoggedOut').style.display='block';
    document.getElementById('mobileUserLoggedIn').style.display='none'; document.getElementById('mobileUserLoggedOut').style.display='block';
        const acctBtn = document.getElementById('headerAcctBtn');
        if (acctBtn) acctBtn.textContent = '👤 Sign in';
        closeAccountModal();
    convCache = []; msgCache = {}; activeConvId = null;
    loadGoalBoard();
    loadAgentStatusState();
    await loadConvList();
}

async function endImpersonation() {
    const fd = new FormData();
    fd.append('action', 'admin_stop_impersonation');
    const r = await fetch('/api/reseller.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.success) {
        alert(d.error || 'Unable to return to admin session');
        return;
    }
    window.location.href = d.redirect || '/pages/reseller_admin.php';
}

function showLoggedIn(username) {
    document.getElementById('userNameDisplay').textContent = username;
    document.getElementById('userLoggedIn').style.display  = 'block';
    document.getElementById('userLoggedOut').style.display = 'none';
    document.getElementById('mobileUserNameDisplay').textContent = username;
    document.getElementById('mobileUserLoggedIn').style.display  = 'block';
    document.getElementById('mobileUserLoggedOut').style.display = 'none';
        const acctBtn = document.getElementById('headerAcctBtn');
        if (acctBtn) acctBtn.textContent = '👤 ' + username;
    showDevFab();
    loadGoalBoard();
    loadAgentStatusState();
    loadConvList();
    loadDiscordStatus();
    load2FAStatus();
    loadModelOptions();
    refreshOperatorDashboardAccess();
    // Show admin link for dev account
    if (username === DEV_USERNAME) {
        document.getElementById('adminLink').style.display = 'inline-block';
    }
}

function setOperatorDashboardVisible(visible) {
    const desktopWrap = document.getElementById('operatorDashboardWrap');
    const mobileWrap = document.getElementById('mobileOperatorDashboardWrap');
    const display = visible ? 'block' : 'none';
    if (desktopWrap) desktopWrap.style.display = display;
    if (mobileWrap) mobileWrap.style.display = display;
}

async function refreshOperatorDashboardAccess() {
    const checkId = ++operatorDashCheckToken;
    if (!currentUser?.username) {
        setOperatorDashboardVisible(false);
        return;
    }

    try {
        const data = await (await fetch('/api/reseller.php?action=get_dashboard')).json();
        if (checkId !== operatorDashCheckToken) return;
        setOperatorDashboardVisible(!!data?.success);
    } catch (_) {
        if (checkId === operatorDashCheckToken) setOperatorDashboardVisible(false);
    }
}

function openOperatorDashboard() {
    window.location.href = '/pages/reseller.php';
}
async function checkSession() {
    const fd = new FormData(); fd.append('action','check');
    const data = await (await fetch('/api/auth.php',{method:'POST',body:fd})).json();
    if (data.logged_in) {
        currentUser = { username: data.username, plan: data.plan || 'free' };
        showLoggedIn(data.username);
    } else {
        await loadConvList(); // load guest convs from localStorage
    }
}

// ── MOLTBOOK ──
async function loadMoltbook() {
    if (!MOLTBOOK_ENABLED) return;
    const content = document.getElementById('moltContent');
    content.innerHTML = `<div class="molt-loading"><div class="molt-loading-dots"><span></span><span></span><span></span></div></div>`;
    try {
        const data = await (await fetch('/moltbook.php?sort=' + currentMoltTab)).json();
        if (!data.posts?.length) { content.innerHTML=`<div class="molt-loading">No posts yet 🦞</div>`; return; }
        content.innerHTML = '';
        data.posts.forEach(post => {
            const div = document.createElement('div');
            div.className = 'molt-post';
            div.innerHTML = buildPostHTML(post);
            div.onclick = () => window.open('https://www.moltbook.com','_blank');
            content.appendChild(div);
        });
        document.getElementById('moltStatusText').textContent = `Updated ${new Date().toLocaleTimeString('en-US', { timeZone: 'America/New_York' })}`;
    } catch(e) { content.innerHTML=`<div class="molt-loading" style="color:#ef4444">Failed to load</div>`; }
}

async function loadMobileMoltbook() {
    if (!MOLTBOOK_ENABLED) return;
    const content = document.getElementById('mobileMoltContent');
    content.innerHTML = `<div class="molt-loading"><div class="molt-loading-dots"><span></span><span></span><span></span></div></div>`;
    try {
        const data = await (await fetch('/moltbook.php?sort=' + currentMoltTab)).json();
        if (!data.posts?.length) { content.innerHTML=`<div class="molt-loading">No posts yet 🦞</div>`; return; }
        content.innerHTML = '';
        data.posts.forEach(post => {
            const div = document.createElement('div');
            div.className = 'molt-post';
            div.style.cssText = 'margin-bottom:10px; padding:14px; border-radius:12px;';
            div.innerHTML = buildPostHTML(post, true);
            div.onclick = () => window.open('https://www.moltbook.com','_blank');
            content.appendChild(div);
        });
    } catch(e) { content.innerHTML=`<div class="molt-loading" style="color:#ef4444">Failed to load</div>`; }
}

function buildPostHTML(post, large = false) {
    const titleSize = large ? '13px' : '11px';
    const metaSize  = large ? '11px' : '10px';
    return `<div class="molt-post-title" style="font-size:${titleSize}">${escapeHtml(post.title||'Untitled')}</div>
        <div class="molt-post-meta" style="font-size:${metaSize}">
            <span class="molt-upvotes">▲ ${post.upvotes||0}</span>
            <span class="molt-agent">🤖 ${escapeHtml(post.author?.name||'?')}</span>
            <span class="molt-submolt">m/${escapeHtml(post.submolt?.name||'general')}</span>
        </div>`;
}

function switchMoltTab(tab, el) {
    if (!MOLTBOOK_ENABLED) return;
    currentMoltTab = tab;
    document.querySelectorAll('.molt-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    loadMoltbook();
    loadMobileMoltbook();
}

// ── DEV PANEL ──
function isDevUser() { return currentUser?.username === DEV_USERNAME; }

function showDevFab() {
    const fab = document.getElementById('devFab');
    if (isDevUser()) fab.classList.add('visible');
    else fab.classList.remove('visible');
}

function toggleDevPanel() {
    const panel = document.getElementById('devPanel');
    const fab   = document.getElementById('devFab');
    panel.classList.toggle('open');
    fab.classList.toggle('panel-open');
}

function toggleDevMode() {
    devModeEnabled = !devModeEnabled;
    document.getElementById('devModeBtn').textContent = devModeEnabled ? 'ON' : 'OFF';
    document.getElementById('devModeBtn').style.color = devModeEnabled ? '#22c55e' : '#ef4444';
    document.getElementById('devModeBtn').style.borderColor = devModeEnabled ? '#22c55e' : '#ef4444';
}

function renderDebug(debug) {
    if (!debug || !isDevUser()) return;

    const usageCount = Number.isFinite(Number(debug.token_count)) ? Number(debug.token_count) : Number(debug.msg_count || 0);
    const usageLimit = Number.isFinite(Number(debug.token_limit)) ? Number(debug.token_limit) : Number(debug.msg_limit || 0);
    const pct     = usageLimit > 0 ? Math.min(100, Math.round((usageCount / usageLimit) * 100)) : 0;
    const scoreColor = debug.post_score >= 4 ? 'good' : debug.post_score >= 2 ? 'warn' : 'bad';
    const msColor    = debug.groq_ms < 1000 ? 'good' : debug.groq_ms < 3000 ? 'warn' : 'bad';
    const cooldownMin = Math.ceil(debug.cooldown_left / 60);
    const actualProvider = debug.provider || 'unknown';
    const actualModel = debug.model || 'unknown';
    const requestedProvider = debug.requested_provider || actualProvider;
    const requestedModel = debug.requested_model || actualModel;
    const requestedHttpCode = debug.requested_http_code || null;
    const providerChanged = requestedProvider !== actualProvider || requestedModel !== actualModel;
    const promptTokens = Number.isFinite(Number(debug.prompt_tokens)) ? Number(debug.prompt_tokens) : null;
    const completionTokens = Number.isFinite(Number(debug.completion_tokens)) ? Number(debug.completion_tokens) : null;
    const totalTokens = Number.isFinite(Number(debug.total_tokens)) ? Number(debug.total_tokens) : null;
    const confidenceLabel = debug?.confidence?.label || 'unknown';
    const confidenceScore = Number(debug?.confidence?.score || 0);
    const verifyPassed = !!debug?.verification?.passed;
    const hallucinationRisk = debug?.hallucination?.risk || 'low';
    const requestClass = String(debug?.request_class || 'GENERAL_INFORMATION');
    const riskLevel = String(debug?.risk_level || 'low');
    const responseMode = String(debug?.response_mode || 'general_information');
    const activeValidators = Array.isArray(debug?.active_validators) ? debug.active_validators : [];
    const evidenceRequired = !!debug?.evidence_required;
    const toolRequired = !!debug?.tool_required;
    const validatorCount = Number.isFinite(Number(debug?.validator_count)) ? Number(debug.validator_count) : activeValidators.length;
    const regenerationCount = Number.isFinite(Number(debug?.regeneration_count)) ? Number(debug.regeneration_count) : 0;
    const classificationMs = Number.isFinite(Number(debug?.classification_ms)) ? Number(debug.classification_ms) : null;
    const generationMs = Number.isFinite(Number(debug?.generation_ms)) ? Number(debug.generation_ms) : null;
    const validationMs = Number.isFinite(Number(debug?.validation_ms)) ? Number(debug.validation_ms) : null;
    const totalLatencyMs = Number.isFinite(Number(debug?.total_latency_ms)) ? Number(debug.total_latency_ms) : null;

    document.getElementById('devBody').innerHTML = `
        <div class="dev-section">
            <div class="dev-section-title">Model</div>
            <div class="dev-row">
                <span class="dev-label">Actual model</span>
                <span class="dev-value accent">${escapeHtml(actualModel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Actual provider</span>
                <span class="dev-value accent">${escapeHtml(actualProvider)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Requested route</span>
                <span class="dev-value ${providerChanged ? 'warn' : ''}">${escapeHtml(requestedProvider)} / ${escapeHtml(requestedModel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Requested status</span>
                <span class="dev-value ${(requestedHttpCode && requestedHttpCode >= 400) ? 'bad' : ''}">${requestedHttpCode || 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Response time</span>
                <span class="dev-value ${msColor}">${debug.groq_ms}ms</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Classify latency</span>
                <span class="dev-value">${classificationMs !== null ? `${classificationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Generation latency</span>
                <span class="dev-value">${generationMs !== null ? `${generationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Validation latency</span>
                <span class="dev-value">${validationMs !== null ? `${validationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Total latency</span>
                <span class="dev-value">${totalLatencyMs !== null ? `${totalLatencyMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Trace ID</span>
                <span class="dev-value accent">${escapeHtml(String(debug.trace_id || '--'))}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Confidence</span>
                <span class="dev-value ${confidenceLabel === 'high' ? 'good' : (confidenceLabel === 'medium' ? 'warn' : 'bad')}">${escapeHtml(confidenceLabel)} ${confidenceScore ? `(${confidenceScore})` : ''}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Request class</span>
                <span class="dev-value accent">${escapeHtml(requestClass)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Risk level</span>
                <span class="dev-value ${riskLevel === 'very_high' || riskLevel === 'high' ? 'bad' : (riskLevel === 'medium' ? 'warn' : 'good')}">${escapeHtml(riskLevel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Response mode</span>
                <span class="dev-value">${escapeHtml(responseMode)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Active validators</span>
                <span class="dev-value">${escapeHtml(activeValidators.length ? activeValidators.join(', ') : 'lightweight')}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Evidence required</span>
                <span class="dev-value ${evidenceRequired ? 'warn' : 'good'}">${evidenceRequired ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Tool required</span>
                <span class="dev-value ${toolRequired ? 'warn' : 'good'}">${toolRequired ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Validator count</span>
                <span class="dev-value">${validatorCount}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Regeneration count</span>
                <span class="dev-value">${regenerationCount}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Self-verify</span>
                <span class="dev-value ${verifyPassed ? 'good' : 'warn'}">${verifyPassed ? 'pass' : 'check needed'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Hallucination risk</span>
                <span class="dev-value ${hallucinationRisk === 'low' ? 'good' : (hallucinationRisk === 'medium' ? 'warn' : 'bad')}">${escapeHtml(hallucinationRisk)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Checkpoint</span>
                <span class="dev-value">saved: ${escapeHtml(String(debug?.checkpoint?.saved || 'n/a'))} • applied: ${escapeHtml(String(debug?.checkpoint?.applied || 'n/a'))}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Provider fallback</span>
                <span class="dev-value ${debug.fallback_used ? 'warn' : 'good'}">${debug.fallback_used ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">HTTP status</span>
                <span class="dev-value ${debug.http_code && debug.http_code >= 400 ? 'bad' : ''}">${debug.http_code || 'n/a'}</span>
            </div>
            ${debug.requested_error ? `
            <div class="dev-row">
                <span class="dev-label">Requested error</span>
                <span class="dev-value bad">${escapeHtml(debug.requested_error)}</span>
            </div>` : ''}
            <div class="dev-row">
                <span class="dev-label">Provider tokens</span>
                <span class="dev-value">${totalTokens !== null ? `${promptTokens ?? 0} in / ${completionTokens ?? 0} out / ${totalTokens} total` : 'Not returned by provider'}</span>
            </div>
            ${debug.llm_error ? `
            <div class="dev-row">
                <span class="dev-label">Provider error</span>
                <span class="dev-value bad">${escapeHtml(debug.llm_error)}</span>
            </div>` : ''}
            <div class="dev-row">
                <span class="dev-label">Messages sent</span>
                <span class="dev-value">${debug.messages_sent}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Messages trimmed</span>
                <span class="dev-value ${debug.messages_trimmed > 0 ? 'warn' : 'good'}">${debug.messages_trimmed}</span>
            </div>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">Plan & Usage</div>
            <div class="dev-row">
                <span class="dev-label">Plan</span>
                <span class="dev-value accent">${debug.plan}</span>
            </div>
            <div class="dev-row" style="gap:8px;align-items:center">
                <span class="dev-label" style="white-space:nowrap">${usageCount} / ${usageLimit >= 99999999 ? '∞' : usageLimit} tokens</span>
                <div class="dev-bar-wrap"><div class="dev-bar" style="width:${pct}%"></div></div>
                <span class="dev-value">${pct}%</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Credits remaining</span>
                <span class="dev-value ${debug.credits > 0 ? 'good' : 'bad'}">${debug.credits}</span>
            </div>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">Dataset RAG</div>
            <div class="dev-row">
                <span class="dev-label">Matches found</span>
                <span class="dev-value ${debug.dataset_matches > 0 ? 'good' : 'bad'}">${debug.dataset_matches}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Search method</span>
                <span class="dev-value accent">${debug.dataset_method}</span>
            </div>
            ${(debug.dataset_snippets || []).map((s, i) => `
            <div style="background:#0a0a0f;border:1px solid #1e1e2e;border-radius:6px;padding:6px 8px;margin-top:4px;font-size:10px;">
                <span style="color:#64748b">#${s.id} [${s.method}] score:${s.score}</span><br>
                <span style="color:#a78bfa">${escapeHtml(s.q)}…</span>
            </div>`).join('')}
            <button onclick="window.open('/pages/dataset_manager','_blank')" style="margin-top:8px;width:100%;padding:6px;background:rgba(124,58,237,0.15);border:1px solid #7c3aed;color:#a78bfa;border-radius:6px;font-family:'DM Mono',monospace;font-size:10px;cursor:pointer;">⚙ Open Dataset Manager</button>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">System Prompt</div>
            <div class="dev-prompt">${escapeHtml(debug.system_prompt)}</div>
        </div>
    `;

    // Auto-open panel when new debug data arrives on desktop only.
    const panel = document.getElementById('devPanel');
    const fab   = document.getElementById('devFab');
    if (window.matchMedia('(min-width: 768px)').matches && !panel.classList.contains('open')) {
        panel.classList.add('open');
        fab.classList.add('panel-open');
    }
}


function escapeHtml(text) {
    return String(text).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── MARKDOWN RENDERER ──
(function setupMarked() {
    // Configure marked to use highlight.js for syntax highlighting
    marked.setOptions({
        highlight: function(code, lang) {
            if (lang && hljs.getLanguage(lang)) {
                try { return hljs.highlight(code, { language: lang }).value; } catch(e) {}
            }
            return hljs.highlightAuto(code).value;
        },
        breaks: true,
        gfm: true,
    });

    // Custom renderer — wrap code blocks with header + copy button
    const renderer = new marked.Renderer();
    renderer.code = function(code, lang) {
        const language  = lang || 'plaintext';
        const langLabel = lang || 'code';
        const id        = 'cb-' + Math.random().toString(36).slice(2,8);

        let highlighted;
        if (lang && hljs.getLanguage(lang)) {
            try { highlighted = hljs.highlight(code, { language: lang }).value; }
            catch(e) { highlighted = hljs.highlightAuto(code).value; }
        } else {
            highlighted = hljs.highlightAuto(code).value;
        }

        return `<div class="code-block" id="${id}">
            <div class="code-block-header">
                <span class="code-lang">${escapeHtml(langLabel)}</span>
                <button class="code-copy-btn" onclick="copyCode('${id}',this)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    Copy
                </button>
            </div>
            <pre><code class="hljs language-${escapeHtml(language)}">${highlighted}</code></pre>
        </div>`;
    };
    marked.use({ renderer });
})();

function renderMarkdown(text) {
    return marked.parse(text || '');
}

function repairJumbledReplyText(text) {
    const raw = String(text || '');
    if (!raw || raw.includes('```')) return raw;

    const letterCount = (raw.match(/[A-Za-z]/g) || []).length;
    const spaceCount = (raw.match(/\s/g) || []).length;
    const longRuns = (raw.match(/[A-Za-z]{16,}/g) || []).length;
    const spaceRatio = letterCount > 0 ? (spaceCount / letterCount) : 1;
    const maybeJumbled = letterCount >= 36 && (
        longRuns > 0
        || spaceRatio < 0.09
        || /[a-z][A-Z]/.test(raw)
        || /[.,!?;:][A-Za-z]/.test(raw)
    );

    if (!maybeJumbled) return raw;

    return raw
        .replace(/([.,!?;:])([A-Za-z])/g, '$1 $2')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/([A-Za-z])(\d)/g, '$1 $2')
        .replace(/(\d)([A-Za-z])/g, '$1 $2')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

function renderAssistantActionRow(replyText, includeActions = true) {
    if (!includeActions) return '';
    const replyId = 'reply_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
    replyActionStore[replyId] = String(replyText || '');
    return `<div class="assistant-action-row">
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('tasks','${replyId}')">Save tasks</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('continue','${replyId}')">Next step</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('execute','${replyId}')">Take action</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('summary','${replyId}')">Summarize</button>
    </div>`;
}

function closeThoughtBubbles() {
    document.querySelectorAll('.thought-bubble.open').forEach(el => {
        el.classList.remove('open');
        el.setAttribute('aria-expanded', 'false');
    });
}

function toggleThoughtBubble(event, el) {
    if (!el) return;
    event.preventDefault();
    event.stopPropagation();
    const shouldOpen = !el.classList.contains('open');
    closeThoughtBubbles();
    if (shouldOpen) {
        el.classList.add('open');
        el.setAttribute('aria-expanded', 'true');
    }
}

function handleThoughtBubbleKey(event, el) {
    if (event.key === 'Enter' || event.key === ' ') {
        toggleThoughtBubble(event, el);
    } else if (event.key === 'Escape') {
        closeThoughtBubbles();
        el?.blur();
    }
}

document.addEventListener('click', event => {
    if (!event.target.closest('.thought-bubble')) closeThoughtBubbles();
});

function renderThoughtBubble(thinking) {
    const text = String(thinking || '').trim();
    if (!text) return '';
    const summary = text.length > 140 ? `${text.slice(0, 137).trim()}...` : text;
    return ` <span class="thought-bubble" tabindex="0" role="button" aria-expanded="false" aria-label="View Lyralink's reasoning for this reply" onclick="toggleThoughtBubble(event, this)" onkeydown="handleThoughtBubbleKey(event, this)"><span class="thought-bubble-icon">💭</span><span class="thought-bubble-content">${escapeHtml(text)}</span><span class="thought-bubble-label">Reasoning</span></span>`;
}

function renderGeneratedImageCard(imageUrl, prompt, downloadUrl = '', madeBy = 'Lyralink') {
    if (!imageUrl) return '';
    const safePrompt = escapeHtml(String(prompt || 'Generated image'));
    const safeMadeBy = escapeHtml(String(madeBy || 'Lyralink'));
    const previewUrl = escapeHtml(String(imageUrl));
    const downloadLink = downloadUrl ? escapeHtml(String(downloadUrl)) : previewUrl;
    return `<figure class="generated-image-card">
        <img class="generated-image-preview" src="${previewUrl}" alt="${safePrompt}" loading="lazy" referrerpolicy="no-referrer">
        <figcaption class="generated-image-caption">
            <div class="generated-image-title">${safePrompt}</div>
            <div class="generated-image-meta">Made by ${safeMadeBy}</div>
            <div class="generated-image-actions">
                <a class="generated-image-download" href="${downloadLink}" target="_blank" rel="noopener">Download image</a>
            </div>
        </figcaption>
    </figure>`;
}

function buildAssistantMessageHtml(reply, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, thinking = '', generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const imageCard = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || reply || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    return `<div class="msg ai"><div class="msg-role-label">Lyralink${renderThoughtBubble(thinking)}</div><div class="bubble">${imageCard}${renderMarkdown(reply || '')}${codeTestCard}${moltNotice}${renderAssistantActionRow(reply || '', includeActions)}</div></div>`;
}

function buildStreamingAssistantBubbleHtml(text, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const imageCard = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    const safeText = escapeHtml(String(text || ''));
    return `${imageCard}<div class="assistant-streaming-note">Generating response...</div><div class="assistant-streaming-text">${safeText}</div>${codeTestCard}${moltNotice}${renderAssistantActionRow('', includeActions)}`;
}

function sleep(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
}

function chunkTextForStreaming(text) {
    const source = String(text || '');
    if (!source) return [];
    const chunks = [];
    const parts = source.match(/\s+|[^\s]+/g) || [];
    let buffer = '';
    for (const part of parts) {
        buffer += part;
        if (buffer.length >= 18 || /[.!?]\s*$/.test(buffer) || /\n\s*$/.test(buffer)) {
            chunks.push(buffer);
            buffer = '';
        }
    }
    if (buffer) chunks.push(buffer);
    return chunks;
}

async function streamAssistantMessage(messageId, reply, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, thinking = '', generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const root = document.getElementById(messageId);
    const bubble = root?.querySelector('.bubble');
    if (!root || !bubble) {
        const chatbox = document.getElementById('chatbox');
        if (chatbox) chatbox.innerHTML += buildAssistantMessageHtml(reply, codeTestCard, moltNotice, includeActions, thinking, generatedImageUrl, imagePrompt, generatedImageDownloadUrl, madeBy);
        return;
    }

    const roleLabel = root.querySelector('.msg-role-label');
    if (roleLabel) {
        const existingThought = roleLabel.querySelector('.thought-bubble');
        if (existingThought) existingThought.remove();
        const thoughtBubbleHtml = renderThoughtBubble(thinking);
        if (thoughtBubbleHtml) roleLabel.insertAdjacentHTML('beforeend', thoughtBubbleHtml);
    }

    const text = String(reply || '');
    const previousText = String(root.dataset.renderedReply || '');
    const currentMessageKey = String(messageId || '');
    const priorMessageKey = String(root.dataset.renderMessageId || '');

    if (priorMessageKey && priorMessageKey !== currentMessageKey) {
        return;
    }
    if (previousText && previousText.length > 0 && text.length < previousText.length) {
        return;
    }

    root.dataset.renderMessageId = currentMessageKey;
    root.dataset.renderedReply = text;

    bubble.innerHTML = buildStreamingAssistantBubbleHtml(text, codeTestCard, moltNotice, includeActions, generatedImageUrl, imagePrompt, generatedImageDownloadUrl, madeBy);
    const chatbox = document.getElementById('chatbox');
    if (chatbox) chatbox.scrollTop = chatbox.scrollHeight;

    const chunks = chunkTextForStreaming(text);
    if (chunks.length === 0) {
        bubble.innerHTML = `${generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : ''}${renderMarkdown(text)}${codeTestCard}${moltNotice}${renderAssistantActionRow(text, includeActions)}`;
        return;
    }

    const imageHtml = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    const actionsHtml = renderAssistantActionRow(text, includeActions);
    let visibleText = '';
    for (let i = 0; i < chunks.length; i++) {
        visibleText += chunks[i];
        bubble.innerHTML = `${imageHtml}<div class="assistant-streaming-note">Generating response...</div><div class="assistant-streaming-text">${escapeHtml(visibleText)}</div>${codeTestCard}${moltNotice}${actionsHtml}`;
        if (i < chunks.length - 1) {
            await sleep(18 + Math.min(42, chunks[i].length * 2));
        }
    }

    bubble.innerHTML = `${imageHtml}${renderMarkdown(text)}${codeTestCard}${moltNotice}${actionsHtml}`;
}

function renderCodeTestCard(report) {
    if (!report || !report.enabled) return '';
    const rows = Array.isArray(report.results) ? report.results : [];
    const hasFailure = rows.some(r => ['failed', 'error'].includes(r.status));
    const statusClass = hasFailure ? 'fail' : 'pass';
    const reportId = 'vrep_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
    validationReportStore[reportId] = report;
    const lines = rows.map(r => {
        const runtime = r.runtime ? ` (${r.runtime})` : '';
        const title = `${r.lang || 'code'}: ${r.status || 'unknown'}${runtime}`;
        const note = r.note ? `<div class="code-test-line">${escapeHtml(r.note)}</div>` : '';
        return `<div class="code-test-line"><strong>${escapeHtml(title)}</strong>${note}<div>${escapeHtml(r.output || '')}</div></div>`;
    }).join('');
    const summary = escapeHtml(report.summary || 'Validation completed.');
    const action = hasFailure
        ? `<div class="code-test-actions"><button class="code-test-fix-btn" onclick="requestValidationFix('${reportId}')">Fix failing code</button></div>`
        : '';
    return `<div class="code-test-card ${statusClass}"><div class="code-test-title">Sandbox Validation</div><div class="code-test-summary">${summary}</div>${lines}${action}</div>`;
}

function buildValidationFixPrompt(report) {
    const rows = Array.isArray(report?.results) ? report.results : [];
    const failed = rows.filter(r => ['failed', 'error'].includes(r.status));
    const details = failed.map(r => {
        const runtime = r.runtime ? ` (${r.runtime})` : '';
        const output = String(r.output || '').slice(0, 1200);
        return `- ${r.lang || 'code'}: ${r.status || 'failed'}${runtime}\n${output}`;
    }).join('\n\n');

    return [
        'Fix the code from your previous answer so it passes validation.',
        'Return complete corrected code blocks only, with proper language fences.',
        'Do not include explanations before or after the code.',
        '',
        'Validation errors:',
        details || '- Validation failed, but no details were captured.',
    ].join('\n');
}

async function requestValidationFix(reportId) {
    const report = validationReportStore[reportId];
    if (!report) {
        showToast('Validation details are no longer available', 'error');
        return;
    }
    const prompt = buildValidationFixPrompt(report);
    const input = document.getElementById('userInput');
    if (!input) return;
    input.innerText = prompt;
    await sendMessage();
}

function copyCode(blockId, btn) {
    const block = document.getElementById(blockId);
    const code  = block ? block.querySelector('code') : null;
    if (!code) return;
    navigator.clipboard.writeText(code.innerText).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 18 4 13"/></svg> Copied!`;
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy`;
        }, 2000);
    });
}

// ── DISCORD SYNC ──
async function loadDiscordStatus() {
    const fd = new FormData(); fd.append('action', 'discord_status');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    const linked   = document.getElementById('discordLinked');
    const unlinked = document.getElementById('discordUnlinked');
    if (!linked || !unlinked) return;
    if (data.linked) {
        document.getElementById('discordTagDisplay').textContent = data.discord_tag;
        linked.style.display   = 'flex';
        unlinked.style.display = 'none';
    } else {
        linked.style.display   = 'none';
        unlinked.style.display = 'block';
    }
}

async function redeemDiscordSync() {
    const token = document.getElementById('discordSyncToken')?.value.trim().toUpperCase();
    const msg   = document.getElementById('discordSyncMsg');
    if (!token) { msg.style.color = 'var(--error)'; msg.textContent = 'Enter your sync code'; return; }

    msg.style.color   = 'var(--text-muted)';
    msg.textContent   = 'Linking...';

    const fd = new FormData(); fd.append('action', 'discord_sync'); fd.append('token', token);
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();

    if (data.success) {
        msg.style.color = 'var(--success)';
        msg.textContent = `✓ Linked to ${data.discord_tag}`;
        setTimeout(loadDiscordStatus, 1000);
    } else {
        msg.style.color = 'var(--error)';
        msg.textContent = data.error || 'Failed — try again';
    }
}

async function discordUnlink() {
    if (!confirm('Unlink your Discord account?')) return;
    const fd = new FormData(); fd.append('action', 'discord_unlink');
    await fetch('/api/auth.php', { method: 'POST', body: fd });
    loadDiscordStatus();
}

function setProfileStatus(id, message, color = 'var(--text-muted)') {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.color = color;
    el.textContent = message;
}

async function loadDataDeletionStatus() {
    const requestBtn = document.getElementById('requestDeletionBtn');
    const deleteBtn = document.getElementById('deleteSavedChatBtn');
    if (requestBtn) requestBtn.disabled = false;
    if (deleteBtn) deleteBtn.disabled = false;
    setProfileStatus('deleteSavedChatMsg', 'Delete saved chat history from this account immediately.');
    setProfileStatus('requestDeletionMsg', 'No full deletion request is currently pending.');

    try {
        const fd = new FormData();
        fd.append('action', 'get_data_deletion_status');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to load deletion status');
        }

        const request = data.request || null;
        if (request && request.status === 'pending') {
            if (requestBtn) requestBtn.disabled = true;
            const createdAt = request.created_at ? new Date(String(request.created_at).replace(' ', 'T')) : null;
            const when = createdAt && !Number.isNaN(createdAt.getTime())
                ? createdAt.toLocaleString()
                : 'recently';
            setProfileStatus('requestDeletionMsg', 'A full deletion request is already pending from ' + when + '.', '#f59e0b');
            return;
        }

        if (request && request.status && request.status !== 'pending') {
            setProfileStatus('requestDeletionMsg', 'Latest deletion request status: ' + request.status + '.', 'var(--text-muted)');
        }
    } catch (e) {
        setProfileStatus('requestDeletionMsg', 'Could not load deletion request status.', 'var(--error)');
    }
}

async function deleteSavedChatData() {
    const step = await openAuthPrompt({
        title: 'Delete Saved Chats',
        subtitle: 'Type DELETE to permanently remove synced chat history tied to this account.',
        placeholder: 'DELETE',
        maxLength: 12,
        type: 'text',
        submitText: 'Delete Chats',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'DELETE') {
        showToast('Type DELETE to confirm', 'error');
        return;
    }

    const deleteBtn = document.getElementById('deleteSavedChatBtn');
    if (deleteBtn) deleteBtn.disabled = true;
    setProfileStatus('deleteSavedChatMsg', 'Deleting saved chat history...', 'var(--text-muted)');

    try {
        const fd = new FormData();
        fd.append('action', 'delete_saved_chat_data');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to delete saved chat history');
        }

        convCache = [];
        msgCache = {};
        setActiveConvState(null);
        persistBackupFromCache();
        await newConversation();
        renderChat();

        const summary = 'Deleted ' + (data.deleted_conversations || 0) + ' conversations and ' + (data.deleted_logs || 0) + ' stored chat logs.';
        setProfileStatus('deleteSavedChatMsg', summary, 'var(--success)');
        showToast('Saved chat history deleted', 'success');
    } catch (e) {
        setProfileStatus('deleteSavedChatMsg', e.message || 'Failed to delete saved chat history.', 'var(--error)');
        showToast(e.message || 'Failed to delete saved chat history', 'error');
    } finally {
        if (deleteBtn) deleteBtn.disabled = false;
    }
}

async function requestDataDeletion() {
    const step = await openAuthPrompt({
        title: 'Request Full Data Deletion',
        subtitle: 'Type REQUEST to submit a manual deletion request for your full account data.',
        placeholder: 'REQUEST',
        maxLength: 12,
        type: 'text',
        submitText: 'Submit Request',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'REQUEST') {
        showToast('Type REQUEST to confirm', 'error');
        return;
    }

    const requestBtn = document.getElementById('requestDeletionBtn');
    if (requestBtn) requestBtn.disabled = true;
    setProfileStatus('requestDeletionMsg', 'Submitting deletion request...', 'var(--text-muted)');

    try {
        const fd = new FormData();
        fd.append('action', 'request_data_deletion');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to submit deletion request');
        }

        if (data.already_requested) {
            setProfileStatus('requestDeletionMsg', 'A full deletion request is already pending.', '#f59e0b');
            showToast('Deletion request already pending', 'error');
            return;
        }

        setProfileStatus('requestDeletionMsg', 'Full data deletion requested. Support will process it manually.', 'var(--success)');
        showToast('Deletion request submitted', 'success');
    } catch (e) {
        if (requestBtn) requestBtn.disabled = false;
        setProfileStatus('requestDeletionMsg', e.message || 'Failed to submit deletion request.', 'var(--error)');
        showToast(e.message || 'Failed to submit deletion request', 'error');
        return;
    }
}

async function load2FAStatus() {
    const el = document.getElementById('twoFAStatusMsg');
    if (!el) return;
    try {
        const fd = new FormData();
        fd.append('action', 'get_2fa_status');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data.success) {
            el.textContent = 'Unable to load 2FA status';
            return;
        }
        if (!data.enabled) {
            el.textContent = '2FA is currently disabled';
            return;
        }
        const method = data.method === 'yubikey' ? 'YubiKey' : 'Authenticator App';
        const remaining = Number(data.recovery_codes_remaining || 0);
        el.textContent = '2FA enabled via ' + method + ' · Backup codes left: ' + remaining;
    } catch (e) {
        el.textContent = 'Unable to load 2FA status';
    }
}

async function setupTotp2FA() {
    const setupFd = new FormData();
    setupFd.append('action', 'setup_2fa_totp');
    const setup = await (await fetch('/api/auth.php', { method: 'POST', body: setupFd })).json();
    if (!setup.success) {
        showToast(setup.error || 'Failed to start TOTP setup', 'error');
        return;
    }
    const step = await openAuthPrompt({
        title: 'Authenticator Setup',
        subtitle: 'Add this key to your authenticator app:\n' + setup.secret + '\nThen enter the 6-digit code.',
        placeholder: '123456',
        maxLength: 6,
        type: 'text',
        submitText: 'Enable 2FA',
        requiredError: 'Authenticator code is required'
    });
    if (step.cancelled) return;
    const enableFd = new FormData();
    enableFd.append('action', 'enable_2fa_totp');
    enableFd.append('code', step.value.trim());
    const enabled = await (await fetch('/api/auth.php', { method: 'POST', body: enableFd })).json();
    if (enabled.success) {
        if (Array.isArray(enabled.recovery_codes) && enabled.recovery_codes.length) {
            showRecoveryCodesModal(enabled.recovery_codes);
        }
        showToast('Authenticator app 2FA enabled', 'success');
        load2FAStatus();
    } else {
        showToast(enabled.error || 'Failed to enable authenticator app 2FA', 'error');
    }
}

async function registerYubiKey2FA() {
    const step = await openAuthPrompt({
        title: 'Enable YubiKey',
        subtitle: 'Touch your YubiKey and paste the generated OTP.',
        placeholder: 'YubiKey OTP',
        maxLength: 64,
        type: 'text',
        submitText: 'Enable 2FA',
        requiredError: 'YubiKey OTP is required'
    });
    if (step.cancelled) return;
    const fd = new FormData();
    fd.append('action', 'register_2fa_yubikey');
    fd.append('yubikey_otp', step.value.trim());
    fd.append('label', 'Primary YubiKey');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success) {
        if (Array.isArray(data.recovery_codes) && data.recovery_codes.length) {
            showRecoveryCodesModal(data.recovery_codes);
        }
        showToast('YubiKey 2FA enabled', 'success');
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to enable YubiKey 2FA', 'error');
    }
}

async function regenerateRecoveryCodes() {
    const step = await openAuthPrompt({
        title: 'Generate New Backup Codes',
        subtitle: 'Type NEW to replace all existing unused recovery codes.',
        placeholder: 'NEW',
        maxLength: 8,
        type: 'text',
        submitText: 'Generate',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'NEW') {
        showToast('Type NEW to confirm', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'regenerate_recovery_codes');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success && Array.isArray(data.recovery_codes)) {
        showRecoveryCodesModal(data.recovery_codes);
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to generate recovery codes', 'error');
    }
}

async function disable2FA() {
    const step = await openAuthPrompt({
        title: 'Disable Two-Factor Authentication',
        subtitle: 'Type DISABLE to confirm turning off 2FA.',
        placeholder: 'DISABLE',
        maxLength: 10,
        type: 'text',
        submitText: 'Disable',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled || step.value.trim().toUpperCase() !== 'DISABLE') {
        if (!step.cancelled) showToast('Type DISABLE to confirm', 'error');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'disable_2fa');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success) {
        showToast('2FA disabled', 'success');
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to disable 2FA', 'error');
    }
}

// ── VOICE MODE ──
let voiceRecognizer = null;
let voiceState = 'idle'; // idle | listening | processing | speaking
let serverTtsAvailable = null; // null = unknown, true/false after first probe

async function openVoicePanel() {
    const backdrop  = document.getElementById('voicePanelBackdrop');
    backdrop.style.display = 'flex';
    const SR        = window.SpeechRecognition || window.webkitSpeechRecognition;
    const noSupport = document.getElementById('voiceNoSupport');
    const orbWrap   = document.getElementById('voiceOrbWrap');
    const hint      = document.getElementById('voiceHint');

    if (!SR) {
        noSupport.style.display = 'block';
        orbWrap.style.pointerEvents = 'none';
        orbWrap.style.opacity = '0.4';
        hint.style.display = 'none';
        return;
    }

    noSupport.style.display = 'none';
    orbWrap.style.pointerEvents = '';
    orbWrap.style.opacity = '';

    // Probe server TTS availability once
    if (serverTtsAvailable === null) {
        hint.textContent = 'Checking AI voice...';
        try {
            const probe = await fetch('/api/tts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ probe: true })
            });
            const probeData = await probe.json().catch(() => ({}));
            serverTtsAvailable = !!probeData.configured;
        } catch(_) {
            serverTtsAvailable = false;
        }
    }

    if (serverTtsAvailable) {
        hint.textContent = 'Tap the orb to start speaking \u00b7 AI voice responds aloud';
    } else if (window.speechSynthesis) {
        hint.textContent = 'Tap the orb to start speaking \u00b7 using browser voice (add ElevenLabs key for AI voice)';
    } else {
        hint.textContent = 'Tap the orb to start speaking';
    }
}

function closeVoicePanel() {
    voiceStop();
    if (voiceAudio) { voiceAudio.pause(); voiceAudio = null; }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    document.getElementById('voicePanelBackdrop').style.display = 'none';
}

function voiceSetState(state) {
    voiceState = state;
    const orb     = document.getElementById('voiceOrb');
    const ring    = document.getElementById('voiceOrbRing');
    const ring2   = document.getElementById('voiceOrbRing2');
    const status  = document.getElementById('voiceStatus');
    const stopBtn = document.getElementById('voiceStopBtn');
    if (!orb) return;

    orb.className    = 'voice-orb' + (state !== 'idle' ? ' ' + state : '');
    ring.className   = 'voice-orb-ring'  + (state === 'listening' ? ' pulse' : '');
    ring2.className  = 'voice-orb-ring2' + (state === 'listening' ? ' pulse' : '');
    status.className = 'voice-status'    + (state !== 'idle' ? ' ' + state : '');
    stopBtn.style.display = state !== 'idle' ? 'inline-flex' : 'none';

    const labels = {
        idle:       'Tap to speak',
        listening:  'Listening...',
        processing: 'Thinking...',
        speaking:   'Speaking — tap orb to stop',
    };
    status.textContent = labels[state] || 'Tap to speak';
    orb.textContent = state === 'speaking' ? '🔊' : state === 'processing' ? '⏳' : '🎤';
}

function voiceOrbClick() {
    if (voiceState === 'idle')       { voiceStartListening(); return; }
    if (voiceState === 'listening')  { voiceRecognizer?.stop(); return; }
    if (voiceState === 'speaking')   {
        if (voiceAudio) { voiceAudio.pause(); voiceAudio = null; }
        window.speechSynthesis?.cancel();
        voiceSetState('idle');
        return;
    }
}

function voiceStartListening() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;
    if (window.speechSynthesis) window.speechSynthesis.cancel();

    const transcriptEl = document.getElementById('voiceTranscript');
    transcriptEl.textContent = '';
    transcriptEl.classList.remove('empty');
    transcriptEl.classList.add('active');

    voiceRecognizer = new SR();
    voiceRecognizer.continuous     = false;
    voiceRecognizer.interimResults = true;
    voiceRecognizer.lang           = 'en-US';

    voiceSetState('listening');

    voiceRecognizer.onresult = (event) => {
        let interim = '', final = '';
        for (const res of event.results) {
            if (res.isFinal) final   += res[0].transcript;
            else             interim += res[0].transcript;
        }
        transcriptEl.textContent = final || interim || '';
    };

    voiceRecognizer.onerror = (event) => {
        if (event.error === 'no-speech' || event.error === 'aborted') {
            voiceSetState('idle');
            transcriptEl.classList.remove('active');
            return;
        }
        transcriptEl.textContent = 'Mic error: ' + event.error;
        transcriptEl.classList.remove('active');
        voiceSetState('idle');
    };

    voiceRecognizer.onend = () => {
        transcriptEl.classList.remove('active');
        const text = transcriptEl.textContent.trim();
        if (voiceState === 'listening') {
            if (text) {
                voiceSendMessage(text);
            } else {
                voiceSetState('idle');
                transcriptEl.textContent = 'Your words will appear here...';
                transcriptEl.classList.add('empty');
            }
        }
    };

    try { voiceRecognizer.start(); } catch(e) { voiceSetState('idle'); }
}

function voiceStop() {
    if (voiceRecognizer) {
        try { voiceRecognizer.abort(); } catch(_) {}
        voiceRecognizer = null;
    }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    voiceSetState('idle');
    const tr = document.getElementById('voiceTranscript');
    if (tr) tr.classList.remove('active');
}

function voiceClear() {
    voiceStop();
    const tr = document.getElementById('voiceTranscript');
    if (tr) { tr.textContent = 'Your words will appear here...'; tr.classList.add('empty'); }
    const resp = document.getElementById('voiceResponse');
    if (resp) { resp.className = 'voice-response-wrap'; resp.textContent = ''; }
}

async function voiceSendMessage(text) {
    voiceSetState('processing');

    if (!activeConvId) await newConversation();

    const messages = [
        ...getActiveMessages(),
        { role: 'user', content: text }
    ];

    try {
        const edgeCacheKey = (!currentUser?.username)
            ? buildEdgeChatCacheKey(messages, {
                taskMode: aiAssistOptions.taskMode,
                taskFocus: aiAssistOptions.taskFocus || 'general',
                provider: selectedLlmProvider || undefined,
                model: selectedLlmModel || undefined
            })
            : '';
        const headers = { 'Content-Type': 'application/json' };
        if (edgeCacheKey) {
            headers['X-Chat-Cache-Key'] = edgeCacheKey;
        }
        const response = await fetchWithTimeout('/api/chat.php', {
            method:  'POST',
            headers,
            body:    JSON.stringify({
                messages,
                cache_key: edgeCacheKey || undefined,
                user_id:   currentUser?.username || null,
                username:  currentUser?.username || null,
                user_plan: currentUser?.plan     || 'free',
                provider:  selectedLlmProvider   || undefined,
                model:     selectedLlmModel      || undefined,
                task_mode: aiAssistOptions.taskMode,
                task_focus: aiAssistOptions.taskFocus || 'general',
                persistent_goals: getPersistentGoalContext(),
            })
        });
        const data  = await response.json();
        const reply = data.reply || 'Sorry, something went wrong.';
        const aiThinking = data.thinking || '';

        applyAgentPayload(data.agent || {}, reply);

        // Persist to conversation so it appears in chat history
        await saveMessage('user', text);
        await saveMessage('assistant', reply, aiThinking);
        renderChat();

        const respEl = document.getElementById('voiceResponse');
        if (respEl) {
            respEl.textContent = reply;
            respEl.className   = 'voice-response-wrap show';
        }

        voiceSpeak(reply);
    } catch(_) {
        const respEl = document.getElementById('voiceResponse');
        if (respEl) {
            respEl.textContent = 'Connection error. Please try again.';
            respEl.className   = 'voice-response-wrap show';
        }
        voiceSetState('idle');
    }
}

// Holds the currently playing AI audio so we can stop it
let voiceAudio = null;

async function voiceSpeak(text) {
    // Try server-side AI TTS first
    if (serverTtsAvailable !== false) {
        voiceSetState('speaking'); // set state optimistically so UI updates immediately
        try {
            const res = await fetch('/api/tts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text })
            });

            if (res.ok && res.headers.get('Content-Type')?.includes('audio')) {
                serverTtsAvailable = true;
                const blob = await res.blob();
                const url  = URL.createObjectURL(blob);
                voiceAudio = new Audio(url);
                voiceAudio.onended  = () => { URL.revokeObjectURL(url); voiceSetState('idle'); voiceAudio = null; };
                voiceAudio.onerror  = () => { URL.revokeObjectURL(url); voiceSetState('idle'); voiceAudio = null; };
                voiceAudio.play();
                return;
            }

            // Server responded with fallback JSON — mark unavailable and fall through
            serverTtsAvailable = false;
        } catch(_) {
            serverTtsAvailable = false;
        }
    }

    // ── Browser SpeechSynthesis fallback ─────────────────────────────────
    const synth = window.speechSynthesis;
    if (!synth) { voiceSetState('idle'); return; }

    const plain = text
        .replace(/```[\s\S]*?```/g, ' code block ')
        .replace(/`([^`]+)`/g,      '$1')
        .replace(/\*\*([^*]+)\*\*/g,'$1')
        .replace(/\*([^*]+)\*/g,    '$1')
        .replace(/#+\s/g,           '')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/\n{2,}/g,         '. ')
        .replace(/\n/g,             ' ')
        .trim()
        .slice(0, 2200);

    const utter   = new SpeechSynthesisUtterance(plain);
    utter.rate    = 1.05;
    utter.pitch   = 1.0;
    utter.volume  = 1.0;
    utter.onstart = () => voiceSetState('speaking');
    utter.onend   = () => voiceSetState('idle');
    utter.onerror = () => voiceSetState('idle');
    synth.speak(utter);
}

// ── API STATUS ──
async function checkApiStatus() {
    const dot  = document.getElementById('apiStatusDot');
    const text = document.getElementById('apiStatusText');
    if (!dot || !text) return;
    try {
        const start = Date.now();
        const fd = new FormData(); fd.append('action', 'check');
        const res = await fetch('/api/auth.php', { method: 'POST', body: fd });
        const ms  = Date.now() - start;
        if (res.ok) {
            dot.className  = 'status-dot online';
            text.textContent = ms < 500 ? 'API online' : `API online · ${ms}ms`;
        } else {
            throw new Error('non-ok');
        }
    } catch(e) {
        dot.className    = 'status-dot offline';
        text.textContent = 'API offline';
    }
}

// ── IDLE TIMEOUT ──
(function initIdleTimeout() {
    const IDLE_WARN_MS  = 8 * 60 * 1000;  // 8 min — show warning
    const IDLE_LIMIT_MS = 9 * 60 * 1000;  // 9 min — auto sign-out
    const PING_EVERY_MS = 2 * 60 * 1000;  // 2 min — heartbeat while active

    let lastActivity = Date.now();
    let warnShown = false;
    let warnEl = null;

    // Track any user interaction
    ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(evt =>
        document.addEventListener(evt, () => { lastActivity = Date.now(); hideWarn(); }, { passive: true })
    );

    function hideWarn() {
        if (warnShown && warnEl) {
            warnEl.remove(); warnEl = null; warnShown = false;
        }
    }

    function showWarn() {
        if (warnShown) return;
        warnShown = true;
        warnEl = document.createElement('div');
        warnEl.id = 'idleWarnBanner';
        Object.assign(warnEl.style, {
            position:'fixed', bottom:'24px', left:'50%', transform:'translateX(-50%)',
            background:'#1e1e2e', border:'1px solid #7c3aed', borderRadius:'12px',
            padding:'12px 20px', color:'#e2e8f0', fontSize:'13px', zIndex:'9999',
            boxShadow:'0 4px 24px rgba(0,0,0,0.5)', display:'flex', gap:'14px', alignItems:'center',
            fontFamily:'DM Mono,monospace'
        });
        warnEl.innerHTML = '<span>You\'ve been inactive. You\'ll be signed out in 1 minute.</span>'
            + '<button onclick="this.closest(\'#idleWarnBanner\').remove()" '
            + 'style="background:#7c3aed;border:none;color:white;padding:5px 14px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:12px">Stay signed in</button>';
        warnEl.querySelector('button').addEventListener('click', () => {
            lastActivity = Date.now(); hideWarn();
        });
        document.body.appendChild(warnEl);
    }

    // Heartbeat: keep server session alive while user is active
    async function ping() {
        if (!currentUser) return;
        if (Date.now() - lastActivity < PING_EVERY_MS) {
            const fd = new FormData(); fd.append('action', 'ping');
            try {
                const d = await (await fetch('/api/auth.php', { method:'POST', body:fd })).json();
                if (!d.success && d.reason === 'idle_timeout') handleIdleLogout();
            } catch(e) {}
        }
    }

    async function handleIdleLogout() {
        if (!currentUser) return;
        await logout();
        // Show signed-out notice
        const notice = document.createElement('div');
        Object.assign(notice.style, {
            position:'fixed', top:'50%', left:'50%', transform:'translate(-50%,-50%)',
            background:'#111118', border:'1px solid #1e1e2e', borderRadius:'16px',
            padding:'32px 40px', color:'#e2e8f0', fontSize:'14px', zIndex:'10000',
            boxShadow:'0 8px 40px rgba(0,0,0,0.7)', textAlign:'center', maxWidth:'340px',
            fontFamily:'DM Mono,monospace'
        });
        notice.innerHTML = '<div style="font-size:28px;margin-bottom:12px">🔒</div>'
            + '<div style="font-family:Syne,sans-serif;font-size:16px;font-weight:700;margin-bottom:8px">Signed out</div>'
            + '<div style="color:#64748b;font-size:13px">You were signed out due to inactivity.</div>';
        document.body.appendChild(notice);
        setTimeout(() => notice.remove(), 5000);
    }

    // Tick every 30 seconds
    setInterval(async () => {
        const idle = Date.now() - lastActivity;
        if (idle >= IDLE_LIMIT_MS && currentUser) {
            await handleIdleLogout();
        } else if (idle >= IDLE_WARN_MS && currentUser) {
            showWarn();
        }
    }, 30_000);

    setInterval(ping, PING_EVERY_MS);
})();

// ── INIT ──
(async function init() {
    initAiAssistControls();
    // checkSession handles conv loading via loadConvList internally
    await checkSession();
    loadGoalBoard();
    document.getElementById('goalInput')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addGoalFromInput();
        }
    });
    checkApiStatus();
    setInterval(checkApiStatus, 60 * 1000);
    document.getElementById('userInput').focus();
})();
