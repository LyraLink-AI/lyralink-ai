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

