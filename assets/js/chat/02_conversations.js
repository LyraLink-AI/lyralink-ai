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
    const widgetMode = document.body.classList.contains('widget-chat-shell');

    if (!msgs.length) {
        chatbox.innerHTML = widgetMode
            ? `<div class="empty-state" id="emptyState"><div class="icon">⚡</div><p>Ask a question, get support, or start a quick conversation right here without leaving the page.</p><div class="quick-prompts"><span class="quick-prompt" onclick="setInput('What can you help me with today?')">What can you do?</span><span class="quick-prompt" onclick="setInput('I need help with pricing and plans')">Pricing help</span><span class="quick-prompt" onclick="setInput('Can you help me with setup?')">Setup help</span></div></div>`
            : `<div class="empty-state" id="emptyState"><div class="icon">⚡</div><p>Hey! I'm Lyralink. Ask me about code, science, creative writing — anything really!</p></div>`;
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

