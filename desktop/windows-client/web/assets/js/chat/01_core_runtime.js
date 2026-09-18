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
    const agentEconomy = data.agent_economy || execution.agent_economy || {};
    const intelligence = data.intelligence || execution.intelligence_layer || {};

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

    const economyMeta = document.getElementById('agentEconomyMeta');
    if (economyMeta) {
        economyMeta.textContent = `Balance ${agentEconomy.balance ?? 0} • level ${agentEconomy.level ?? 1} • streak ${agentEconomy.streak ?? 0} • focus ${agentEconomy.last_focus || 'general'}`;
    }
    const economyItems = [];
    if (typeof agentEconomy.last_delta !== 'undefined') {
        const tone = Number(agentEconomy.last_delta || 0) >= 0 ? '+' : '';
        economyItems.push(`<strong>Last reward</strong>: ${escapeHtml(String(tone + Number(agentEconomy.last_delta || 0)))} credits`);
    }
    if (typeof agentEconomy.jobs_completed !== 'undefined') {
        economyItems.push(`<strong>Jobs completed</strong>: ${escapeHtml(String(agentEconomy.jobs_completed || 0))}`);
    }
    if (typeof agentEconomy.failures !== 'undefined') {
        economyItems.push(`<strong>Failed turns</strong>: ${escapeHtml(String(agentEconomy.failures || 0))}`);
    }
    const lastEventReasons = Array.isArray(agentEconomy.last_event?.reasons) ? agentEconomy.last_event.reasons : [];
    if (lastEventReasons.length) {
        economyItems.push(...lastEventReasons.slice(-4).map(item => {
            const delta = Number(item?.delta || 0);
            const sign = delta >= 0 ? '+' : '';
            return `<strong>${escapeHtml(String(item?.label || 'reward'))}</strong>: ${escapeHtml(String(sign + delta))}`;
        }));
    }
    renderExecutionList(
        document.getElementById('agentEconomyList'),
        economyItems,
        'No motivation events recorded yet.'
    );

    const worldState = intelligence.world_state || {};
    const capabilityPlan = Array.isArray(intelligence.capability_plan) ? intelligence.capability_plan : [];
    const scientificLoop = intelligence.scientific_loop || {};
    const improvements = Array.isArray(intelligence.improvement_signals) ? intelligence.improvement_signals : [];
    const timeline = Array.isArray(intelligence.timeline) ? intelligence.timeline : [];

    const intelligenceMeta = document.getElementById('intelligenceLayerMeta');
    if (intelligenceMeta) {
        const domains = Array.isArray(worldState.domains) ? worldState.domains.length : 0;
        intelligenceMeta.textContent = `Projects ${worldState.projects ?? 0} • goals ${worldState.goals ?? 0} • services ${worldState.services ?? 0} • models ${worldState.models ?? 0} • domains ${domains}`;
    }
    const intelligenceItems = [];
    if (capabilityPlan.length) {
        intelligenceItems.push(...capabilityPlan.slice(0, 6).map(item => `<strong>${escapeHtml(String(item.capability || 'capability'))}</strong>: ${escapeHtml(String(item.reason || 'No reason'))}`));
    }
    if (scientificLoop.hypothesize) {
        intelligenceItems.push(`<strong>Hypothesis</strong>: ${escapeHtml(String(scientificLoop.hypothesize))}`);
    }
    if (Array.isArray(scientificLoop.plan) && scientificLoop.plan.length) {
        intelligenceItems.push(`<strong>Scientific loop</strong>: ${escapeHtml(scientificLoop.plan.join(' -> '))}`);
    }
    renderExecutionList(
        document.getElementById('intelligenceLayerList'),
        intelligenceItems,
        'No intelligence-layer planning yet.'
    );

    const improvementMeta = document.getElementById('improvementEngineMeta');
    if (improvementMeta) {
        improvementMeta.textContent = `${improvements.length} improvement signal${improvements.length === 1 ? '' : 's'} • ${timeline.length} recent observations`;
    }
    const improvementItems = [];
    if (improvements.length) {
        improvementItems.push(...improvements.slice(0, 5).map(item => `<strong>${escapeHtml(String(item.title || 'Signal'))}</strong>: ${escapeHtml(String(item.action || ''))}`));
    }
    if (timeline.length) {
        improvementItems.push(...timeline.slice(-3).reverse().map(item => `<strong>${escapeHtml(String(item.task_focus || 'general'))}</strong>: ${escapeHtml(String(item.message_excerpt || '').slice(0, 120))}`));
    }
    renderExecutionList(
        document.getElementById('improvementEngineList'),
        improvementItems,
        'No improvement signals yet.'
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

