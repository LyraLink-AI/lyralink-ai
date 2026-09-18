<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /chat');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Social</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #070910;
            --panel: #111423;
            --panel-2: #141a30;
            --border: #27314f;
            --text: #ecf2ff;
            --muted: #9aacce;
            --dim: #6f7fa3;
            --accent: #2f8eff;
            --accent-2: #00d4ff;
            --accent-3: #3c6cff;
            --green: #22c55e;
            --orange: #ff7a2f;
            --red: #ef4444;
            --shadow: 0 18px 48px rgba(4, 12, 34, 0.38);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            overflow: hidden;
        }
        body {
            font-family: 'DM Mono', monospace;
            background: radial-gradient(860px 460px at 4% -12%, rgba(0,212,255,0.16), transparent 70%),
                        radial-gradient(820px 520px at 96% -8%, rgba(60,108,255,0.22), transparent 74%),
                        linear-gradient(160deg, #070910 0%, #0c1222 48%, #070910 100%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
            letter-spacing: .1px;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image: radial-gradient(rgba(255,255,255,.08) .4px, transparent .4px);
            background-size: 2px 2px;
            opacity: .07;
            z-index: 0;
        }
        .app {
            display: grid;
            grid-template-columns: 84px 280px 1fr 300px;
            height: 100vh;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        .col {
            border-right: 1px solid var(--border);
            min-height: 100vh;
        }
        .servers {
            background: linear-gradient(180deg, rgba(14,18,34,.92), rgba(10,14,26,.88));
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            box-shadow: inset -1px 0 0 rgba(82,120,210,.2);
        }
        .server-bubble {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            border: 1px solid rgba(105,132,219,.35);
            background: linear-gradient(160deg, rgba(16,24,47,.95), rgba(16,23,39,.85));
            color: #e8f0ff;
            font-weight: 700;
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
            box-shadow: 0 6px 14px rgba(0,0,0,.26);
        }
        .server-bubble:hover, .server-bubble.active {
            border-color: var(--accent-2);
            box-shadow: 0 0 0 3px rgba(0,194,255,.2), 0 10px 22px rgba(0,102,255,.25);
            transform: translateY(-2px);
        }
        .server-add {
            margin-top: auto;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            border: 1px dashed rgba(135,170,255,.5);
            color: #d5e4ff;
            background: rgba(14,23,42,.7);
            font-size: 24px;
            cursor: pointer;
        }

        .sidebar {
            background: linear-gradient(180deg, rgba(16,22,40,.9), rgba(13,20,35,.88));
            padding: 16px;
            display: grid;
            grid-template-rows: auto 1fr auto;
            gap: 12px;
            backdrop-filter: blur(14px);
        }
        .brand {
            font-family: 'Syne', sans-serif;
            font-size: 22px;
            letter-spacing: .5px;
            margin-bottom: 4px;
            color: #f6f9ff;
        }
        .server-name { color: #b7c8ec; font-size: 11px; }
        .sections { overflow-y: auto; padding-right: 4px; }
        .section { margin-bottom: 20px; }
        .section h4 {
            color: var(--dim);
            font-size: 10px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .item {
            width: 100%;
            border: 1px solid rgba(87,117,194,0);
            border-radius: 10px;
            color: var(--muted);
            text-align: left;
            background: rgba(17,24,42,.18);
            padding: 8px 10px;
            margin-bottom: 5px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }
        .item:hover, .item.active {
            border-color: rgba(106,145,236,.55);
            background: linear-gradient(120deg, rgba(39,71,144,.35), rgba(22,34,60,.68));
            color: var(--text);
            transform: translateX(2px);
        }
        .item small { color: var(--dim); font-size: 10px; }

        .quick-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .quick-actions button {
            border: 1px solid rgba(103,136,217,.38);
            border-radius: 10px;
            background: linear-gradient(160deg, rgba(17,26,48,.9), rgba(15,23,40,.86));
            color: #d9e6ff;
            font-size: 11px;
            padding: 8px 10px;
            cursor: pointer;
            transition: transform .16s ease, box-shadow .16s ease;
        }
        .quick-actions button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(27,53,117,.26);
        }

        .main {
            display: grid;
            grid-template-rows: auto 1fr auto auto;
            min-height: 100vh;
        }
        .topbar {
            border-bottom: 1px solid var(--border);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(120deg, rgba(18,24,44,.93), rgba(15,22,39,.9));
            box-shadow: 0 8px 18px rgba(0,0,0,.22);
        }
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .topbar h2 {
            font-family: 'Syne', sans-serif;
            font-size: 21px;
            letter-spacing: .3px;
        }
        .presence-select {
            border: 1px solid rgba(113,145,230,.45);
            border-radius: 10px;
            background: rgba(15,24,44,.9);
            color: #dde9ff;
            padding: 9px 10px;
            font-size: 11px;
            font-family: inherit;
        }
        .presence-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .presence-online { background: var(--green); }
        .presence-away { background: var(--orange); }
        .presence-offline { background: var(--dim); }

        .messages {
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: linear-gradient(180deg, rgba(8,13,26,.4), rgba(6,10,21,.48));
            scrollbar-width: thin;
            scrollbar-color: rgba(103,147,255,.65) rgba(255,255,255,.06);
        }
        .messages::-webkit-scrollbar {
            width: 10px;
        }
        .messages::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(110,149,255,.7), rgba(64,107,232,.7));
            border-radius: 999px;
            border: 2px solid rgba(10,14,28,.6);
        }
        .message {
            border: 1px solid rgba(93,126,201,.4);
            background: linear-gradient(160deg, rgba(15,22,39,.94), rgba(14,20,33,.86));
            border-radius: 12px;
            padding: 10px 12px;
            max-width: 95%;
            box-shadow: 0 10px 20px rgba(0,0,0,.22);
            animation: msgIn .2s ease;
        }
        .message.mine {
            align-self: flex-end;
            background: linear-gradient(125deg, rgba(47,142,255,.25), rgba(0,212,255,.22));
            border-color: rgba(0,194,255,.55);
            box-shadow: 0 12px 24px rgba(0,130,255,.24);
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: var(--dim);
            font-size: 10px;
            margin-bottom: 7px;
        }
        .body { font-size: 13px; line-height: 1.6; white-space: pre-wrap; }
        .attachment {
            margin-top: 8px;
            border: 1px solid rgba(105,141,221,.35);
            border-radius: 10px;
            padding: 8px 10px;
            display: inline-flex;
            gap: 8px;
            font-size: 11px;
            color: #dce8ff;
            text-decoration: none;
            background: rgba(30,44,76,.45);
        }
        .delivery {
            margin-top: 6px;
            color: var(--dim);
            font-size: 10px;
            text-align: right;
        }

        .typing {
            border-top: 1px solid var(--border);
            color: var(--dim);
            font-size: 11px;
            padding: 7px 16px;
            min-height: 29px;
        }

        .composer {
            border-top: 1px solid var(--border);
            padding: 12px 16px;
            background: linear-gradient(180deg, rgba(14,20,36,.92), rgba(12,18,31,.94));
        }
        .composer-row {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 10px;
            align-items: center;
        }
        .composer input[type=text] {
            width: 100%;
            border: 1px solid rgba(101,134,210,.45);
            border-radius: 10px;
            background: rgba(12,18,33,.95);
            color: var(--text);
            padding: 11px 12px;
            font-family: inherit;
            font-size: 13px;
            transition: border-color .16s ease, box-shadow .16s ease;
        }
        .composer input[type=text]:focus {
            outline: none;
            border-color: var(--accent-2);
            box-shadow: 0 0 0 3px rgba(0,212,255,.16);
        }
        .btn {
            border: 1px solid rgba(108,140,217,.4);
            border-radius: 10px;
            background: linear-gradient(145deg, rgba(17,27,49,.96), rgba(14,22,39,.94));
            color: #dce8ff;
            padding: 10px 12px;
            font-size: 11px;
            cursor: pointer;
            transition: transform .16s ease, box-shadow .16s ease, filter .16s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 9px 16px rgba(14,31,80,.3);
        }
        .btn.primary {
            background: linear-gradient(120deg, var(--accent), var(--accent-3));
            color: #fff;
            border-color: transparent;
        }

        .voice-status {
            border-top: 1px solid var(--border);
            padding: 10px 16px;
            color: #d4e4ff;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
            background: rgba(12,18,33,.55);
        }

        .right {
            background: linear-gradient(180deg, rgba(13,19,36,.9), rgba(10,15,28,.88));
            padding: 16px;
            backdrop-filter: blur(14px);
        }
        .right h3 {
            font-family: 'Syne', sans-serif;
            font-size: 17px;
            margin-bottom: 10px;
        }
        .member-list {
            display: grid;
            gap: 8px;
        }
        .member {
            border: 1px solid rgba(93,125,201,.36);
            border-radius: 10px;
            padding: 8px 10px;
            display: flex;
            justify-content: space-between;
            color: #d6e3ff;
            font-size: 11px;
            background: rgba(20,30,54,.45);
        }
        .rtc-box {
            margin-top: 18px;
            border: 1px solid rgba(90,124,204,.35);
            border-radius: 12px;
            padding: 12px;
            background: linear-gradient(165deg, rgba(16,24,44,.85), rgba(12,19,35,.94));
            box-shadow: var(--shadow);
        }
        .rtc-box p { color: var(--dim); font-size: 11px; line-height: 1.7; }
        .rtc-meta {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }
        .rtc-pill {
            border: 1px solid rgba(95,132,215,.32);
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 10px;
            color: #cfe1ff;
            background: rgba(30,44,77,.4);
        }
        .rtc-pill strong {
            color: var(--text);
            display: block;
            margin-bottom: 4px;
            font-size: 11px;
        }

        @media (max-width: 1080px) {
            .app { grid-template-columns: 74px 250px 1fr; }
            .right { display: none; }
        }
        @media (max-width: 760px) {
            .app { grid-template-columns: 1fr; }
            .servers, .sidebar { display: none; }
            .main { min-height: 100vh; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<div class="app">
    <aside class="col servers">
        <button class="server-bubble" onclick="location.href='/chat'">AI</button>
        <div id="serversList"></div>
        <button class="server-add" id="addServerBtn">+</button>
    </aside>

    <aside class="col sidebar">
        <div>
            <div class="brand">Lyralink Social</div>
            <div class="server-name" id="serverName">Loading server...</div>
        </div>
        <div class="sections">
            <div class="section">
                <h4>Text Channels</h4>
                <div id="textChannels"></div>
            </div>
            <div class="section">
                <h4>Voice Channels</h4>
                <div id="voiceChannels"></div>
            </div>
            <div class="section">
                <h4>Direct Messages</h4>
                <div id="dmList"></div>
            </div>
            <div class="section">
                <h4>Group Chats</h4>
                <div id="groupList"></div>
            </div>
        </div>
        <div class="quick-actions">
            <button id="newDmBtn">New DM</button>
            <button id="newGroupBtn">New Group</button>
            <button id="newTextChannelBtn">+ Text</button>
            <button id="newVoiceChannelBtn">+ Voice</button>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h2 id="channelTitle">Loading...</h2>
            <div class="topbar-actions">
                <select id="presenceSelect" class="presence-select" title="Set your status">
                    <option value="online">Online</option>
                    <option value="away">Away</option>
                    <option value="offline">Invisible</option>
                </select>
                <button class="btn" id="joinVoiceBtn">Join Voice</button>
                <button class="btn" id="leaveVoiceBtn">Leave</button>
            </div>
        </header>

        <section class="messages" id="messages"></section>
        <div class="typing" id="typingLine"></div>

        <div class="composer">
            <div class="composer-row">
                <input type="file" id="attachInput" style="display:none">
                <button class="btn" id="attachBtn">Attach</button>
                <input type="text" id="messageInput" placeholder="Send a message...">
                <button class="btn primary" id="sendBtn">Send</button>
            </div>
        </div>

        <div class="voice-status" id="voiceStatus">
            <span>Voice disconnected</span>
            <span id="voiceMembersCount">0 members</span>
        </div>
    </main>

    <aside class="right">
        <h3>Presence</h3>
        <div class="member-list" id="presenceList"></div>
        <div class="rtc-box">
            <h3 style="font-size:14px;margin-bottom:8px">WebRTC Notes</h3>
            <p>Voice uses peer-to-peer WebRTC with API signaling. TURN is required for many mobile networks, office Wi-Fi, and symmetric NATs.</p>
            <div class="rtc-meta">
                <div class="rtc-pill"><strong>Relay Mode</strong><span id="rtcMode">Loading...</span></div>
                <div class="rtc-pill"><strong>ICE Servers</strong><span id="rtcServers">Loading...</span></div>
                <div class="rtc-pill"><strong>Connection State</strong><span id="rtcState">Idle</span></div>
                <div class="rtc-pill"><strong>ICE Candidate Type</strong><span id="rtcCandidateType">Unknown</span></div>
            </div>
        </div>
    </aside>
</div>

<script>
let state = {
    me: null,
    servers: [],
    dms: [],
    groups: [],
    selectedServerId: 0,
    activeConversationId: 0,
    activeVoiceChannelId: 0,
    lastMessageId: 0,
    lastSignalId: 0,
    rtc: { stun: ['stun:stun.l.google.com:19302'], turn: [], turn_username: '', turn_credential: '' },
    peers: new Map(),
    localStream: null,
    pendingAttachment: null,
    typingUntil: 0,
    typingStopTimer: null,
    lastTypingSentAt: 0,
    pollBusy: false,
    pollTick: 0,
    rtcCandidateTypes: new Set(),
    presenceStatus: localStorage.getItem('lyralink_social_presence') || 'online',
    lastPresenceRows: [],
};

const el = {
    serversList: document.getElementById('serversList'),
    serverName: document.getElementById('serverName'),
    textChannels: document.getElementById('textChannels'),
    voiceChannels: document.getElementById('voiceChannels'),
    dmList: document.getElementById('dmList'),
    groupList: document.getElementById('groupList'),
    messages: document.getElementById('messages'),
    messageInput: document.getElementById('messageInput'),
    sendBtn: document.getElementById('sendBtn'),
    typingLine: document.getElementById('typingLine'),
    channelTitle: document.getElementById('channelTitle'),
    addServerBtn: document.getElementById('addServerBtn'),
    newDmBtn: document.getElementById('newDmBtn'),
    newGroupBtn: document.getElementById('newGroupBtn'),
    newTextChannelBtn: document.getElementById('newTextChannelBtn'),
    newVoiceChannelBtn: document.getElementById('newVoiceChannelBtn'),
    attachBtn: document.getElementById('attachBtn'),
    attachInput: document.getElementById('attachInput'),
    joinVoiceBtn: document.getElementById('joinVoiceBtn'),
    leaveVoiceBtn: document.getElementById('leaveVoiceBtn'),
    voiceStatus: document.getElementById('voiceStatus'),
    voiceMembersCount: document.getElementById('voiceMembersCount'),
    presenceList: document.getElementById('presenceList'),
    rtcMode: document.getElementById('rtcMode'),
    rtcServers: document.getElementById('rtcServers'),
    rtcState: document.getElementById('rtcState'),
    rtcCandidateType: document.getElementById('rtcCandidateType'),
    presenceSelect: document.getElementById('presenceSelect'),
};

async function api(action, payload = {}, isFormData = false) {
    const body = isFormData ? payload : new URLSearchParams({ action, ...payload });
    if (isFormData) body.append('action', action);
    const res = await fetch('/api/social.php', {
        method: 'POST',
        body,
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data;
}

function renderServers() {
    el.serversList.innerHTML = '';
    state.servers.forEach(server => {
        const b = document.createElement('button');
        b.className = 'server-bubble' + (server.id === state.selectedServerId ? ' active' : '');
        b.textContent = (server.name || 'S').slice(0, 2).toUpperCase();
        b.title = server.name;
        b.onclick = () => {
            state.selectedServerId = server.id;
            const firstText = (server.channels || []).find(c => c.type === 'text' && c.conversation_id);
            if (firstText) selectConversation(firstText.conversation_id, '#' + firstText.name, 0);
            renderAll();
        };
        el.serversList.appendChild(b);
    });
}

function selectedServer() {
    return state.servers.find(s => s.id === state.selectedServerId) || null;
}

function renderChannelLists() {
    const server = selectedServer();
    el.serverName.textContent = server ? server.name : 'No server selected';

    el.textChannels.innerHTML = '';
    el.voiceChannels.innerHTML = '';

    if (!server) return;

    const channels = server.channels || [];
    channels.filter(c => c.type === 'text').forEach(c => {
        const item = document.createElement('button');
        item.className = 'item' + (state.activeConversationId === c.conversation_id ? ' active' : '');
        item.innerHTML = `<span># ${escapeHtml(c.name)}</span>`;
        item.onclick = () => selectConversation(c.conversation_id, '#' + c.name, 0);
        el.textChannels.appendChild(item);
    });

    channels.filter(c => c.type === 'voice').forEach(c => {
        const item = document.createElement('button');
        item.className = 'item' + (state.activeVoiceChannelId === c.id ? ' active' : '');
        item.innerHTML = `<span>🔊 ${escapeHtml(c.name)}</span><small>voice</small>`;
        item.onclick = () => {
            state.activeVoiceChannelId = c.id;
            el.channelTitle.textContent = '🔊 ' + c.name;
            renderAll();
        };
        el.voiceChannels.appendChild(item);
    });
}

function renderDmGroupLists() {
    el.dmList.innerHTML = '';
    state.dms.forEach(dm => {
        const item = document.createElement('button');
        item.className = 'item' + (state.activeConversationId === dm.id ? ' active' : '');
        item.innerHTML = `<span>@ ${escapeHtml(dm.title)}</span><small>DM</small>`;
        item.onclick = () => selectConversation(dm.id, '@ ' + dm.title, 0);
        el.dmList.appendChild(item);
    });

    el.groupList.innerHTML = '';
    state.groups.forEach(g => {
        const item = document.createElement('button');
        item.className = 'item' + (state.activeConversationId === g.id ? ' active' : '');
        item.innerHTML = `<span>👥 ${escapeHtml(g.title)}</span><small>${g.member_count} users</small>`;
        item.onclick = () => selectConversation(g.id, '👥 ' + g.title, 0);
        el.groupList.appendChild(item);
    });
}

function renderMessages(messages = [], append = false) {
    if (!append) el.messages.innerHTML = '';
    messages.forEach(m => {
        const box = document.createElement('article');
        box.className = 'message' + (m.is_mine ? ' mine' : '');
        box.dataset.id = m.id;

        let html = `<div class="meta"><span>${escapeHtml(m.sender_username)}</span><span>${escapeHtml(m.created_at)}</span></div>`;
        if (m.content) html += `<div class="body">${escapeHtml(m.content)}</div>`;
        if (m.attachment) {
            html += `<a class="attachment" target="_blank" href="${escapeAttr(m.attachment.url)}">📎 ${escapeHtml(m.attachment.name)} (${humanBytes(m.attachment.size_bytes)})</a>`;
        }
        if (m.is_mine) {
            html += `<div class="delivery">${deliveryLabel(m.delivery)}</div>`;
        }

        box.innerHTML = html;
        el.messages.appendChild(box);
        state.lastMessageId = Math.max(state.lastMessageId, Number(m.id) || 0);
    });

    el.messages.scrollTop = el.messages.scrollHeight;
}

function deliveryLabel(delivery) {
    if (!delivery) return 'sent';
    const total = Number(delivery.recipient_count || 0);
    const delivered = Number(delivery.delivered_count || 0);
    const read = Number(delivery.read_count || 0);
    if (total <= 0) return 'sent';
    if (read >= total) return 'read by all';
    if (delivered >= total) return 'delivered to all';
    return `delivered ${delivered}/${total}`;
}

async function selectConversation(conversationId, title, voiceChannelId = 0) {
    if (state.activeConversationId && state.activeConversationId !== Number(conversationId || 0)) {
        await safeLeaveTyping();
    }
    state.activeConversationId = Number(conversationId) || 0;
    state.activeVoiceChannelId = Number(voiceChannelId) || state.activeVoiceChannelId;
    state.lastMessageId = 0;
    el.channelTitle.textContent = title || 'Conversation';

    if (!state.activeConversationId) {
        el.messages.innerHTML = '';
        return;
    }

    const data = await api('list_messages', { conversation_id: state.activeConversationId });
    renderMessages(data.messages || []);

    if (state.lastMessageId > 0) {
        await api('mark_read', { conversation_id: state.activeConversationId, message_id: state.lastMessageId });
    }

    renderAll();
}

async function safeLeaveTyping() {
    if (!state.activeConversationId) return;
    try {
        await api('set_typing', { conversation_id: state.activeConversationId, is_typing: 0 });
    } catch (_) {}
}

async function sendMessage() {
    if (!state.activeConversationId) return;
    const content = el.messageInput.value.trim();
    const attachment = state.pendingAttachment;
    if (!content && !attachment) return;

    const payload = {
        conversation_id: state.activeConversationId,
        content,
    };
    if (attachment?.id) payload.attachment_id = attachment.id;

    const data = await api('send_message', payload);
    if (data.message) renderMessages([data.message], true);

    el.messageInput.value = '';
    state.pendingAttachment = null;
    el.attachBtn.textContent = 'Attach';
}

async function handleAttachment(file) {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    const data = await api('upload_attachment', fd, true);
    state.pendingAttachment = data.attachment;
    el.attachBtn.textContent = 'Attached: ' + (data.attachment?.name || 'file');
}

async function doPoll() {
    if (state.pollBusy) return;
    state.pollBusy = true;

    try {
        const includeStructure = state.pollTick % 6 === 0 ? 1 : 0;
        const data = await api('poll', {
            conversation_id: state.activeConversationId || 0,
            last_message_id: state.lastMessageId || 0,
            include_structure: includeStructure,
            presence_status: state.presenceStatus,
        });
        state.pollTick += 1;

        if (typeof data.my_presence_status === 'string') {
            const serverStatus = ['online', 'away', 'offline'].includes(data.my_presence_status) ? data.my_presence_status : 'online';
            state.presenceStatus = serverStatus;
            localStorage.setItem('lyralink_social_presence', serverStatus);
            if (el.presenceSelect && el.presenceSelect.value !== serverStatus) {
                el.presenceSelect.value = serverStatus;
            }
        }

        if (Array.isArray(data.messages) && data.messages.length) {
            renderMessages(data.messages, true);
            await api('mark_read', { conversation_id: state.activeConversationId, message_id: state.lastMessageId });
        }

        state.servers = Array.isArray(data.server_list) ? data.server_list : state.servers;
        state.dms = Array.isArray(data.dms) ? data.dms : state.dms;
        state.groups = Array.isArray(data.groups) ? data.groups : state.groups;

        renderTyping(data.typing || []);
        state.lastPresenceRows = Array.isArray(data.presence) ? data.presence : [];
        renderPresence(state.lastPresenceRows);
        renderAll();

        if (state.activeVoiceChannelId) {
            await voicePoll();
        }
    } finally {
        state.pollBusy = false;
    }
}

function renderTyping(users) {
    if (!users.length) {
        el.typingLine.textContent = '';
        return;
    }
    const names = users.map(u => u.username).slice(0, 3);
    const extra = users.length > 3 ? ` +${users.length - 3}` : '';
    el.typingLine.textContent = `${names.join(', ')} typing${extra}`;
}

function renderPresence(presenceRows) {
    const rows = Array.isArray(presenceRows) ? [...presenceRows] : [];

    if (state.me?.id) {
        const meIdx = rows.findIndex(p => Number(p.user_id) === Number(state.me.id));
        const meRow = {
            user_id: state.me.id,
            username: state.me.username,
            status: state.presenceStatus,
        };
        if (meIdx >= 0) {
            rows[meIdx] = { ...rows[meIdx], status: state.presenceStatus };
        } else {
            rows.unshift(meRow);
        }
    }

    el.presenceList.innerHTML = '';
    rows.forEach(p => {
        const item = document.createElement('div');
        item.className = 'member';
        const cls = p.status === 'online' ? 'presence-online' : (p.status === 'away' ? 'presence-away' : 'presence-offline');
        const isMe = Number(p.user_id) === Number(state.me?.id || 0);
        const label = isMe ? `${p.username} (You)` : p.username;
        item.innerHTML = `<span><span class="presence-dot ${cls}"></span>${escapeHtml(label)}</span><span>${escapeHtml(p.status)}</span>`;
        el.presenceList.appendChild(item);
    });
}

async function createServer() {
    const name = prompt('Server name?');
    if (!name) return;
    await api('create_server', { name });
    await bootstrap();
}

async function createDm() {
    const username = prompt('Username to DM?');
    if (!username) return;
    const d = await api('create_dm', { username });
    await bootstrap();
    await selectConversation(d.conversation_id, '@ ' + username, 0);
}

async function createGroup() {
    const title = prompt('Group title?', 'New Group');
    if (!title) return;
    const usernames = prompt('Comma-separated usernames to add?');
    if (!usernames) return;
    const d = await api('create_group', { title, usernames });
    await bootstrap();
    await selectConversation(d.conversation_id, '👥 ' + title, 0);
}

async function createChannel(type) {
    const server = selectedServer();
    if (!server) return;
    const name = prompt(`New ${type} channel name?`);
    if (!name) return;
    await api('create_channel', { server_id: server.id, name, type });
    await bootstrap();
}

function setupTypingHooks() {
    el.messageInput.addEventListener('input', async () => {
        if (!state.activeConversationId) return;
        const now = Date.now();
        if (now - state.lastTypingSentAt > 700) {
            state.typingUntil = now + 1400;
            state.lastTypingSentAt = now;
            try {
                await api('set_typing', { conversation_id: state.activeConversationId, is_typing: 1 });
            } catch (_) {}
        }

        if (state.typingStopTimer) {
            clearTimeout(state.typingStopTimer);
        }

        state.typingStopTimer = setTimeout(async () => {
            state.typingStopTimer = null;
            state.typingUntil = 0;
            state.lastTypingSentAt = 0;
            await safeLeaveTyping();
        }, 1100);

        doPoll().catch(() => {});
    });
}

async function bootstrap() {
    const authFd = new URLSearchParams({ action: 'check' });
    const auth = await (await fetch('/api/auth.php', { method: 'POST', body: authFd })).json();
    if (!auth.logged_in) {
        location.href = '/chat';
        return;
    }

    const data = await api('bootstrap', { presence_status: state.presenceStatus });
    state.me = data.me;
    if (data?.me?.presence_status) {
        state.presenceStatus = data.me.presence_status;
        localStorage.setItem('lyralink_social_presence', state.presenceStatus);
    }
    if (el.presenceSelect) {
        el.presenceSelect.value = state.presenceStatus;
    }
    state.servers = data.servers || [];
    state.dms = data.dms || [];
    state.groups = data.groups || [];
    state.rtc = data.rtc || state.rtc;
    renderRtcDiagnostics();

    state.selectedServerId = data.default_server_id || (state.servers[0]?.id || 0);

    renderAll();

    const firstServer = selectedServer();
    const firstText = firstServer?.channels?.find(c => c.type === 'text' && c.conversation_id);
    if (firstText) {
        await selectConversation(firstText.conversation_id, '#' + firstText.name, 0);
    }
}

function renderAll() {
    renderServers();
    renderChannelLists();
    renderDmGroupLists();
    el.joinVoiceBtn.disabled = !state.activeVoiceChannelId;
    el.leaveVoiceBtn.disabled = !state.activeVoiceChannelId;
}

async function setPresenceStatus(status) {
    const normalized = ['online', 'away', 'offline'].includes(status) ? status : 'online';
    state.presenceStatus = normalized;
    localStorage.setItem('lyralink_social_presence', normalized);
    if (el.presenceSelect && el.presenceSelect.value !== normalized) {
        el.presenceSelect.value = normalized;
    }
    renderPresence(state.lastPresenceRows);
    await api('heartbeat', { status: normalized });
    doPoll().catch(() => {});
}

function rtcConfig() {
    const iceServers = [];
    (state.rtc.stun || []).forEach(url => iceServers.push({ urls: url }));
    if ((state.rtc.turn || []).length && state.rtc.turn_username && state.rtc.turn_credential) {
        (state.rtc.turn || []).forEach(url => {
            iceServers.push({ urls: url, username: state.rtc.turn_username, credential: state.rtc.turn_credential });
        });
    }
    return {
        iceServers,
        iceTransportPolicy: state.rtc.ice_transport_policy === 'relay' ? 'relay' : 'all',
        iceCandidatePoolSize: 4,
    };
}

function renderRtcDiagnostics() {
    const hasTurn = Array.isArray(state.rtc.turn) && state.rtc.turn.length > 0 && state.rtc.turn_username && state.rtc.turn_credential;
    el.rtcMode.textContent = hasTurn
        ? `${state.rtc.ice_transport_policy === 'relay' ? 'TURN only' : 'STUN + TURN ready'}`
        : 'STUN only, relay unavailable';
    const serverCount = (state.rtc.stun?.length || 0) + (state.rtc.turn?.length || 0);
    el.rtcServers.textContent = `${serverCount} configured (${state.rtc.stun?.length || 0} STUN, ${state.rtc.turn?.length || 0} TURN)`;
}

function setRtcState(text) {
    el.rtcState.textContent = text;
}

function parseIceType(candidateLine) {
    const m = String(candidateLine || '').match(/\styp\s([a-zA-Z0-9_-]+)/);
    return m ? m[1].toLowerCase() : '';
}

function updateRtcCandidateTypeLabel() {
    const types = Array.from(state.rtcCandidateTypes.values());
    if (!types.length) {
        el.rtcCandidateType.textContent = 'Unknown';
        return;
    }
    if (types.includes('relay')) {
        el.rtcCandidateType.textContent = 'relay' + (types.length > 1 ? ' (mixed)' : '');
        return;
    }
    el.rtcCandidateType.textContent = types.join(', ');
}

function trackIceCandidateType(candidateObj) {
    const type = parseIceType(candidateObj?.candidate || '');
    if (!type) return;
    state.rtcCandidateTypes.add(type);
    updateRtcCandidateTypeLabel();
}

async function inspectSelectedCandidateType(pc) {
    try {
        const stats = await pc.getStats();
        let pair = null;
        stats.forEach(report => {
            if (report.type === 'transport' && report.selectedCandidatePairId && stats.get(report.selectedCandidatePairId)) {
                pair = stats.get(report.selectedCandidatePairId);
            }
        });
        if (!pair) {
            stats.forEach(report => {
                if (report.type === 'candidate-pair' && report.state === 'succeeded' && report.nominated) {
                    pair = report;
                }
            });
        }
        if (!pair) return;

        const local = pair.localCandidateId ? stats.get(pair.localCandidateId) : null;
        const remote = pair.remoteCandidateId ? stats.get(pair.remoteCandidateId) : null;
        if (local?.candidateType) state.rtcCandidateTypes.add(String(local.candidateType).toLowerCase());
        if (remote?.candidateType) state.rtcCandidateTypes.add(String(remote.candidateType).toLowerCase());
        updateRtcCandidateTypeLabel();
    } catch (_) {}
}

function getOrCreatePeer(remoteUserId) {
    if (state.peers.has(remoteUserId)) return state.peers.get(remoteUserId);
    const pc = new RTCPeerConnection(rtcConfig());

    if (state.localStream) {
        state.localStream.getTracks().forEach(track => pc.addTrack(track, state.localStream));
    }

    pc.onicecandidate = async e => {
        if (!e.candidate || !state.activeVoiceChannelId) return;
        trackIceCandidateType(e.candidate);
        await api('voice_signal_send', {
            channel_id: state.activeVoiceChannelId,
            to_user_id: remoteUserId,
            signal_type: 'ice',
            payload: JSON.stringify({ candidate: e.candidate }),
        });
    };

    pc.ontrack = e => {
        const id = 'remote-audio-' + remoteUserId;
        let audio = document.getElementById(id);
        if (!audio) {
            audio = document.createElement('audio');
            audio.id = id;
            audio.autoplay = true;
            document.body.appendChild(audio);
        }
        audio.srcObject = e.streams[0];
    };

    pc.oniceconnectionstatechange = () => {
        setRtcState(`ICE ${pc.iceConnectionState}`);
        inspectSelectedCandidateType(pc).catch(() => {});
    };

    pc.onconnectionstatechange = () => {
        setRtcState(`Peer ${pc.connectionState}`);
        inspectSelectedCandidateType(pc).catch(() => {});
    };

    state.peers.set(remoteUserId, pc);
    return pc;
}

async function startVoice() {
    if (!state.activeVoiceChannelId) return;

    if (!window.RTCPeerConnection || !navigator.mediaDevices?.getUserMedia) {
        throw new Error('This browser does not support WebRTC voice');
    }

    state.lastSignalId = 0;
    state.rtcCandidateTypes = new Set();
    updateRtcCandidateTypeLabel();
    setRtcState('Requesting microphone');

    if (!state.localStream) {
        state.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    }

    const join = await api('voice_join', { channel_id: state.activeVoiceChannelId });
    updateVoiceMembers(join.members || []);

    const others = (join.members || []).filter(m => Number(m.user_id) !== Number(state.me.id));
    for (const m of others) {
        const pc = getOrCreatePeer(m.user_id);
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await api('voice_signal_send', {
            channel_id: state.activeVoiceChannelId,
            to_user_id: m.user_id,
            signal_type: 'offer',
            payload: JSON.stringify({ sdp: offer }),
        });
    }

    el.voiceStatus.firstElementChild.textContent = 'Voice connected';
    setRtcState('Waiting for peers');
}

async function leaveVoice() {
    if (state.activeVoiceChannelId) {
        await api('voice_leave', { channel_id: state.activeVoiceChannelId });
    }

    for (const [uid, pc] of state.peers.entries()) {
        try { pc.close(); } catch (_) {}
        state.peers.delete(uid);
    }

    document.querySelectorAll('audio[id^="remote-audio-"]').forEach(a => a.remove());

    if (state.localStream) {
        state.localStream.getTracks().forEach(t => t.stop());
        state.localStream = null;
    }

    el.voiceStatus.firstElementChild.textContent = 'Voice disconnected';
    el.voiceMembersCount.textContent = '0 members';
    setRtcState('Idle');
}

async function voicePoll() {
    if (!state.activeVoiceChannelId) return;

    const d = await api('voice_signal_poll', {
        channel_id: state.activeVoiceChannelId,
        last_signal_id: state.lastSignalId,
    });

    updateVoiceMembers(d.members || []);

    const signals = d.signals || [];
    for (const s of signals) {
        state.lastSignalId = Math.max(state.lastSignalId, Number(s.id) || 0);
        if (Number(s.from_user_id) === Number(state.me.id)) continue;

        const payload = s.payload || {};
        const pc = getOrCreatePeer(s.from_user_id);

        if (s.signal_type === 'offer' && payload.sdp) {
            await pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await api('voice_signal_send', {
                channel_id: state.activeVoiceChannelId,
                to_user_id: s.from_user_id,
                signal_type: 'answer',
                payload: JSON.stringify({ sdp: answer }),
            });
        } else if (s.signal_type === 'answer' && payload.sdp) {
            await pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
        } else if (s.signal_type === 'ice' && payload.candidate) {
            trackIceCandidateType(payload.candidate);
            try {
                await pc.addIceCandidate(new RTCIceCandidate(payload.candidate));
            } catch (_) {}
        }
    }

    if (state.localStream) {
        await api('voice_ping', { channel_id: state.activeVoiceChannelId });
    }
}

function updateVoiceMembers(members) {
    el.voiceMembersCount.textContent = `${members.length} member${members.length === 1 ? '' : 's'}`;
}

function humanBytes(bytes) {
    const val = Number(bytes || 0);
    if (val <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = val;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function escapeHtml(input) {
    return String(input ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function escapeAttr(input) {
    return escapeHtml(input).replaceAll('`', '&#96;');
}

el.sendBtn.addEventListener('click', () => sendMessage().catch(err => alert(err.message)));
el.messageInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage().catch(err => alert(err.message));
    }
});
el.attachBtn.addEventListener('click', () => el.attachInput.click());
el.attachInput.addEventListener('change', async e => {
    try {
        await handleAttachment(e.target.files?.[0]);
    } catch (err) {
        alert(err.message);
    }
});
el.addServerBtn.addEventListener('click', () => createServer().catch(err => alert(err.message)));
el.newDmBtn.addEventListener('click', () => createDm().catch(err => alert(err.message)));
el.newGroupBtn.addEventListener('click', () => createGroup().catch(err => alert(err.message)));
el.newTextChannelBtn.addEventListener('click', () => createChannel('text').catch(err => alert(err.message)));
el.newVoiceChannelBtn.addEventListener('click', () => createChannel('voice').catch(err => alert(err.message)));
el.joinVoiceBtn.addEventListener('click', () => startVoice().catch(err => alert(err.message)));
el.leaveVoiceBtn.addEventListener('click', () => leaveVoice().catch(err => alert(err.message)));
el.presenceSelect.addEventListener('change', () => {
    setPresenceStatus(el.presenceSelect.value).catch(err => alert(err.message));
});

setupTypingHooks();
bootstrap().catch(err => {
    alert(err.message || 'Failed to load social chat');
    console.error(err);
});

setInterval(() => doPoll().catch(() => {}), 850);
window.addEventListener('beforeunload', () => {
    if (state.activeConversationId) {
        navigator.sendBeacon('/api/social.php', new URLSearchParams({ action: 'set_typing', conversation_id: String(state.activeConversationId), is_typing: '0' }));
    }
    if (state.activeVoiceChannelId) {
        navigator.sendBeacon('/api/social.php', new URLSearchParams({ action: 'voice_leave', channel_id: String(state.activeVoiceChannelId) }));
    }
    navigator.sendBeacon('/api/social.php', new URLSearchParams({ action: 'heartbeat', status: 'offline' }));
});
</script>
</body>
</html>
