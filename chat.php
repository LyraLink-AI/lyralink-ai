<?php
$maintenanceFlag = __DIR__ . '/maintenance.flag';
$isMaintenance   = file_exists($maintenanceFlag);
$isDevCookie     = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
if ($isMaintenance && !$isDevCookie) {
    header('Location: /pages/maintenance.php'); exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0a0a0f">
    <title>Lyralink AI | Powered by CloudHavenX</title>
    <link rel="icon" type="image/x-icon" href="images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/9.1.6/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
        <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .auth-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.72);display:none;align-items:center;justify-content:center;z-index:1200;padding:16px}
        .auth-modal-card{width:min(460px,100%);background:#0f172a;border:1px solid #1e293b;border-radius:14px;padding:18px;box-shadow:0 16px 48px rgba(2,6,23,.45)}
        .auth-modal-title{font-family:'Syne',sans-serif;font-size:19px;font-weight:700;margin-bottom:6px;color:#e2e8f0}
        .auth-modal-sub{font-size:12px;color:#94a3b8;margin-bottom:12px;line-height:1.6}
        .auth-modal-input{width:100%;background:#020617;border:1px solid #334155;color:#e2e8f0;border-radius:8px;padding:11px 12px;font-family:'DM Mono',monospace;font-size:13px;outline:none}
        .auth-modal-input:focus{border-color:#7c3aed}
        .auth-modal-actions{display:flex;gap:8px;margin-top:12px}
        .auth-modal-btn{flex:1;padding:10px 12px;border-radius:8px;border:1px solid #334155;background:#111827;color:#cbd5e1;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer}
        .auth-modal-btn.primary{background:#7c3aed;color:#fff;border-color:#7c3aed}
        .auth-modal-btn.primary:hover{background:#6d28d9}
        .auth-modal-link{margin-top:9px;font-size:11px;color:#a78bfa;cursor:pointer;text-decoration:underline;text-underline-offset:2px;display:inline-block}
        .auth-modal-error{margin-top:8px;font-size:11px;color:#ef4444;min-height:16px}
        .auth-code-list{margin-top:10px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
        .auth-code-item{background:#020617;border:1px solid #334155;color:#e2e8f0;border-radius:6px;padding:6px 8px;font-size:12px;text-align:center;letter-spacing:1px}
        .profile-settings-wrap{margin-top:10px;padding:10px;border:1px solid var(--border);border-radius:10px;background:rgba(124,58,237,0.06)}
        .profile-settings-title{font-size:11px;color:var(--text-muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.6px}
        .profile-settings-btn{width:100%}
        .profile-modal-card{width:min(620px,100%);max-height:min(88vh,760px);overflow:auto}
        .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
        .profile-block{background:#020617;border:1px solid #334155;border-radius:10px;padding:12px}
        .profile-block h4{font-size:12px;color:#cbd5e1;margin-bottom:8px}
        .profile-note{font-size:11px;color:#94a3b8;line-height:1.6;margin-bottom:8px}
        @media(max-width:700px){.profile-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>

<!-- DRAWER OVERLAY (mobile) -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- MOBILE DRAWER: Conversations -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="drawer-header">
        <img src="/assets/lyralogowide.png" alt="Lyralink" class="drawer-logo">
        <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <button class="drawer-new-btn" onclick="newConversation(); closeDrawer()">+ New Chat</button>
    <div class="drawer-conv-list" id="drawerConvList"></div>
</div>

<!-- LEFT: CONVERSATIONS (desktop) -->
<nav class="conv-panel">
    <div class="conv-header">
        <img src="/assets/lyralogowide.png" alt="Lyralink" class="conv-logo">
    </div>
    <button class="new-chat-btn" onclick="newConversation()">+ New Chat</button>
    <div class="conv-list" id="convList"></div>
    <div class="conv-footer">
        <div class="conv-footer-status">
            <span class="status-dot" id="apiStatusDot"></span>
            <span class="status-text" id="apiStatusText">Checking...</span>
        </div>
        <div class="conv-footer-links">
            <a href="/pages/pricing" class="footer-link">⚡ Plans</a>
            <a href="https://discord.gg/JhyPNs5Khn" target="_blank" class="footer-link">Discord</a>
            <a href="/pages/tos" class="footer-link">ToS</a>
            <a href="/pages/api_docs" class="footer-link">API</a>
            <a href="/pages/support" class="footer-link">Support</a>
            <a href="/pages/status" class="footer-link">Status</a>
            <a href="/pages/careers" class="footer-link">Careers</a>
        </div>
        <div class="conv-footer-version">v1.0.0</div>
    </div>
</nav>

<!-- MIDDLE: CHAT -->
<div class="chat-panel">
    <header>
        <button class="mobile-menu-btn" onclick="openDrawer()">☰</button>
        <div class="chat-title" id="chatTitle">New Chat</div>
        <div class="header-right" id="headerRight">
            <button class="btn-small" onclick="clearCurrentChat()">✕ Clear</button>
            <a href="/pages/pricing" style="font-size:10px;padding:2px 8px;border-radius:20px;border:1px solid rgba(255,107,53,0.4);color:#ff6b35;text-decoration:none;font-family:'DM Mono',monospace;">⚡ Plans</a>
            <a href="/pages/admin" id="adminLink" style="display:none;font-size:10px;padding:2px 8px;border-radius:20px;border:1px solid rgba(124,58,237,0.4);color:#a78bfa;text-decoration:none;font-family:'DM Mono',monospace;">⚙ Admin</a>
            <span class="badge badge-groq">Lyra-1</span>
            <span class="badge badge-molt">🦞</span>
        </div>
    </header>

    <div id="chatbox">
        <div class="empty-state" id="emptyState">
            <div class="icon">⚡</div>
            <p>Hey! I'm Lyralink. Ask me about code, science, creative writing — anything really!</p>
        </div>
    </div>

    <div class="input-area">
        <div
            id="userInput"
            contenteditable="true"
            data-placeholder="Ask Lyralink anything..."
            role="textbox"
            aria-label="Chat input"
        ></div>
        <button id="sendBtn" onclick="sendMessage()">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </div>
</div>

<!-- RIGHT: ACCOUNT + MOLTBOOK (desktop) -->
<aside class="sidebar">
    <div class="auth-panel">
        <h3>Account</h3>
        <div id="userLoggedIn" style="display:none">
            <div class="user-info">
                <div class="user-avatar">👤</div>
                <div class="user-name" id="userNameDisplay"></div>
                <button class="btn-small" onclick="logout()">Logout</button>
            </div>
            <div class="profile-settings-wrap">
                <div class="profile-settings-title">Account Settings</div>
                <button class="btn-small profile-settings-btn" onclick="openProfileSettings()">Open Profile Settings</button>
            </div>
        </div>
        <div id="userLoggedOut">
            <div class="auth-tabs">
                <div class="auth-tab active" onclick="switchAuthTab('login',this)">Login</div>
                <div class="auth-tab" onclick="switchAuthTab('register',this)">Register</div>
            </div>
            <div id="loginForm" class="auth-form">
                <input class="auth-input" type="email" id="loginEmail" placeholder="Email" autocomplete="off">
                <input class="auth-input" type="password" id="loginPass" placeholder="Password" autocomplete="new-password">
                <button class="auth-btn" onclick="login()">Login</button>
                <div id="loginMsg"></div>
            </div>
            <div id="registerForm" class="auth-form" style="display:none">
                <input class="auth-input" type="text" id="regUsername" placeholder="Username" autocomplete="off">
                <input class="auth-input" type="email" id="regEmail" placeholder="Email" autocomplete="off">
                <input class="auth-input" type="password" id="regPass" placeholder="Password" autocomplete="new-password">
                <button class="auth-btn" onclick="register()">Create Account</button>
                <div id="registerMsg"></div>
            </div>
        </div>
    </div>
    <div class="molt-header">
        <div class="molt-logo">🦞</div>
        <div class="molt-header-text"><h2>Moltbook</h2><p>AI Agent Social Network</p></div>
        <button class="molt-refresh" onclick="loadMoltbook()">↻</button>
    </div>
    <div class="molt-tabs">
        <div class="molt-tab active" onclick="switchMoltTab('hot',this); loadMoltbook()">Hot</div>
        <div class="molt-tab" onclick="switchMoltTab('new',this); loadMoltbook()">New</div>
        <div class="molt-tab" onclick="switchMoltTab('top',this); loadMoltbook()">Top</div>
    </div>
    <div class="molt-content" id="moltContent">
        <div class="molt-loading"><div class="molt-loading-dots"><span></span><span></span><span></span></div><p style="margin-top:8px">Loading...</p></div>
    </div>
    <div class="molt-status">
        <div class="molt-status-dot"></div>
        <span id="moltStatusText">Lyralink is live</span>
    </div>
</aside>

<!-- MOBILE SCREEN: Moltbook -->
<div class="mobile-screen" id="moltScreen">
    <div class="mobile-screen-header">
        🦞 <span>Moltbook</span>
    </div>
    <div style="display:flex; border-bottom:1px solid var(--border); flex-shrink:0;">
        <div class="molt-tab active" onclick="switchMoltTab('hot',this)" style="flex:1;padding:10px;font-size:12px;text-align:center;cursor:pointer;color:var(--text-muted);border-bottom:2px solid transparent;transition:all 0.2s;font-family:'DM Mono',monospace;">Hot</div>
        <div class="molt-tab" onclick="switchMoltTab('new',this)" style="flex:1;padding:10px;font-size:12px;text-align:center;cursor:pointer;color:var(--text-muted);border-bottom:2px solid transparent;transition:all 0.2s;font-family:'DM Mono',monospace;">New</div>
        <div class="molt-tab" onclick="switchMoltTab('top',this)" style="flex:1;padding:10px;font-size:12px;text-align:center;cursor:pointer;color:var(--text-muted);border-bottom:2px solid transparent;transition:all 0.2s;font-family:'DM Mono',monospace;">Top</div>
    </div>
    <div class="mobile-screen-body" id="mobileMoltContent">
        <div class="molt-loading"><div class="molt-loading-dots"><span></span><span></span><span></span></div></div>
    </div>
</div>

<!-- MOBILE SCREEN: Account -->
<div class="mobile-screen" id="accountScreen">
    <div class="mobile-screen-header">
        👤 <span class="accent">Account</span>
    </div>
    <div class="mobile-screen-body">
        <div class="mobile-auth">
            <div class="auth-panel" style="padding:16px;">
                <div id="mobileUserLoggedIn" style="display:none">
                    <div class="user-info">
                        <div class="user-avatar">👤</div>
                        <div class="user-name" id="mobileUserNameDisplay"></div>
                        <button class="btn-small" onclick="logout()">Logout</button>
                    </div>
                </div>
                <div id="mobileUserLoggedOut">
                    <div class="auth-tabs" style="margin-bottom:12px;">
                        <div class="auth-tab active" onclick="switchMobileAuthTab('login',this)">Login</div>
                        <div class="auth-tab" onclick="switchMobileAuthTab('register',this)">Register</div>
                    </div>
                    <div id="mobileLoginForm" class="auth-form">
                        <input class="auth-input" type="email" id="mobileLoginEmail" placeholder="Email" autocomplete="off">
                        <input class="auth-input" type="password" id="mobileLoginPass" placeholder="Password" autocomplete="new-password">
                        <button class="auth-btn" onclick="mobileLogin()">Login</button>
                        <div id="mobileLoginMsg"></div>
                    </div>
                    <div id="mobileRegisterForm" class="auth-form" style="display:none">
                        <input class="auth-input" type="text" id="mobileRegUsername" placeholder="Username" autocomplete="off">
                        <input class="auth-input" type="email" id="mobileRegEmail" placeholder="Email" autocomplete="off">
                        <input class="auth-input" type="password" id="mobileRegPass" placeholder="Password" autocomplete="new-password">
                        <button class="auth-btn" onclick="mobileRegister()">Create Account</button>
                        <div id="mobileRegisterMsg"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BOTTOM NAV (mobile only) -->
<nav class="bottom-nav">
    <button class="bottom-nav-item active" id="navChat" onclick="showMobileScreen('chat')">
        <span class="nav-icon">💬</span>Chat
    </button>
    <button class="bottom-nav-item" id="navConvs" onclick="openDrawer()">
        <span class="nav-icon">📋</span>Chats
    </button>
    <button class="bottom-nav-item" id="navMolt" onclick="showMobileScreen('moltbook')">
        <span class="nav-icon">🦞</span>Moltbook
    </button>
    <button class="bottom-nav-item" id="navAccount" onclick="showMobileScreen('account')">
        <span class="nav-icon">👤</span>Account
    </button>
</nav>

<!-- DEV FAB BUTTON -->
<button class="dev-fab" id="devFab" onclick="toggleDevPanel()" title="Dev Panel">⚙</button>

<!-- DEV PANEL -->
<div class="dev-panel" id="devPanel">
    <div class="dev-header">
        <span>⚙ DEV PANEL</span>
        <button class="dev-toggle-btn" onclick="toggleDevMode()" id="devModeBtn">ON</button>
    </div>
    <div class="dev-body" id="devBody">
        <div style="color:#64748b;font-size:11px;text-align:center;padding:10px 0">
            Send a message to see debug info
        </div>
    </div>
</div>

<div class="auth-modal-backdrop" id="authModalBackdrop">
    <div class="auth-modal-card">
        <div class="auth-modal-title" id="authModalTitle">Verification Required</div>
        <div class="auth-modal-sub" id="authModalSub"></div>
        <input id="authModalInput" class="auth-modal-input" type="text" autocomplete="one-time-code">
        <div class="auth-modal-link" id="authModalAlt" style="display:none"></div>
        <div class="auth-modal-error" id="authModalError"></div>
        <div class="auth-modal-actions">
            <button class="auth-modal-btn" id="authModalCancel">Cancel</button>
            <button class="auth-modal-btn primary" id="authModalSubmit">Continue</button>
        </div>
    </div>
</div>

<div class="auth-modal-backdrop" id="recoveryCodesBackdrop">
    <div class="auth-modal-card">
        <div class="auth-modal-title">Backup Recovery Codes</div>
        <div class="auth-modal-sub">Store these in a password manager. Each code can only be used once.</div>
        <div id="recoveryCodesList" class="auth-code-list"></div>
        <div class="auth-modal-actions">
            <button class="auth-modal-btn primary" onclick="closeRecoveryCodesModal()">Done</button>
        </div>
    </div>
</div>

<div class="auth-modal-backdrop" id="profileSettingsBackdrop">
    <div class="auth-modal-card profile-modal-card">
        <div class="auth-modal-title">Profile Settings</div>
        <div class="auth-modal-sub">Manage account security and integrations from one place.</div>
        <div class="profile-grid">
            <div class="profile-block" id="discordSyncSection">
                <h4>Discord Link</h4>
                <div class="profile-note">Connect your Discord account to sync support and identity features.</div>
                <div class="discord-linked" id="discordLinked" style="display:none">
                    <span class="discord-icon">🔗</span>
                    <span class="discord-tag-display" id="discordTagDisplay"></span>
                    <button class="btn-small btn-danger-small" onclick="discordUnlink()">Unlink</button>
                </div>
                <div class="discord-unlinked" id="discordUnlinked" style="display:none">
                    <div class="discord-sync-row">
                        <input class="auth-input" type="text" id="discordSyncToken" placeholder="Enter code from .sync" maxlength="10" style="text-transform:uppercase;letter-spacing:2px">
                        <button class="auth-btn" onclick="redeemDiscordSync()" style="margin-top:4px">Link</button>
                    </div>
                    <div id="discordSyncMsg" style="font-size:11px;margin-top:4px"></div>
                </div>
            </div>
            <div class="profile-block">
                <h4>Two-Factor Authentication</h4>
                <div id="twoFAStatusMsg" class="profile-note">Loading 2FA status...</div>
                <div class="discord-sync-row" style="gap:6px;display:flex;flex-wrap:wrap">
                    <button class="btn-small" onclick="setupTotp2FA()">Enable Authenticator App</button>
                    <button class="btn-small" onclick="registerYubiKey2FA()">Enable YubiKey</button>
                    <button class="btn-small" onclick="regenerateRecoveryCodes()">New Recovery Codes</button>
                    <button class="btn-small btn-danger-small" onclick="disable2FA()">Disable 2FA</button>
                </div>
            </div>
            <div class="profile-block" style="grid-column:1/-1">
                <h4>AI Model</h4>
                <div class="profile-note">Only providers with configured API keys are shown.</div>
                <div class="discord-sync-row" style="gap:6px">
                    <select class="auth-input" id="modelProviderSelect" onchange="onModelProviderChange()"></select>
                    <select class="auth-input" id="modelNameSelect"></select>
                    <button class="btn-small" onclick="saveModelPreference()">Save Model Settings</button>
                    <div id="modelSettingsMsg" style="font-size:11px;color:var(--text-muted)"></div>
                </div>
            </div>
        </div>
        <div class="auth-modal-actions" style="margin-top:14px">
            <button class="auth-modal-btn primary" onclick="closeProfileSettings()">Done</button>
        </div>
    </div>
</div>

<script>
// ── GLOBALS ──
let currentUser    = null;
let currentMoltTab = 'hot';
const DEV_USERNAME = 'developer';
let devModeEnabled = true;
let availableModelProviders = [];
let selectedLlmProvider = null;
let selectedLlmModel = null;

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
        msgCache[convId] = data.messages.map(m => ({ role: m.role, content: m.content }));
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
async function saveMessage(role, content) {
    if (!activeConvId) return;

    // Keep current conversation pinned as active during refreshes.
    setActiveConvState(activeConvId);

    // Update local cache
    if (!msgCache[activeConvId]) msgCache[activeConvId] = [];
    msgCache[activeConvId].push({ role, content });

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
            chatbox.innerHTML += `<div class="msg ai"><div class="avatar">⚡</div><div class="bubble">${renderMarkdown(msg.content)}</div></div>`;
        }
    });
    chatbox.scrollTop = chatbox.scrollHeight;
}



// ── INPUT HELPERS ──
function getInputText() { return document.getElementById('userInput').innerText.trim(); }
function clearInput() { document.getElementById('userInput').innerText = ''; }
function setInputDisabled(disabled) {
    const el = document.getElementById('userInput');
    el.contentEditable = disabled ? 'false' : 'true';
    el.style.opacity   = disabled ? '0.5' : '1';
    document.getElementById('sendBtn').disabled = disabled;
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
    document.getElementById('moltScreen').classList.remove('active');
    document.getElementById('accountScreen').classList.remove('active');
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active'));

    if (screen === 'moltbook') {
        document.getElementById('moltScreen').classList.add('active');
        document.getElementById('navMolt').classList.add('active');
        loadMobileMoltbook();
    } else if (screen === 'account') {
        document.getElementById('accountScreen').classList.add('active');
        document.getElementById('navAccount').classList.add('active');
    } else {
        document.getElementById('navChat').classList.add('active');
        document.getElementById('userInput').focus();
    }
}

// ── SEND MESSAGE ──
async function sendMessage() {
    const message = getInputText();
    if (!message) return;

    if (!activeConvId) await newConversation();

    const chatbox    = document.getElementById('chatbox');
    const emptyState = document.getElementById('emptyState');
    if (emptyState) emptyState.remove();

    chatbox.innerHTML += `<div class="msg user"><div class="avatar">👤</div><div class="bubble">${escapeHtml(message)}</div></div>`;
    const thinkingId = 'thinking_' + Date.now();
    chatbox.innerHTML += `<div class="msg ai" id="${thinkingId}"><div class="avatar">⚡</div><div class="bubble"><div class="thinking-dots"><span></span><span></span><span></span></div></div></div>`;

    clearInput();
    setInputDisabled(true);
    chatbox.scrollTop = chatbox.scrollHeight;
    await saveMessage('user', message);

    const messages = [
        ...getActiveMessages().slice(0, -1),
        { role: 'user', content: message }
    ];

    // Fetch recent moltbook posts to give AI context
    let moltPosts = [];
    try {
        const moltData = await (await fetch('/moltbook.php?sort=new&limit=5')).json();
        moltPosts = (moltData.posts || []).slice(0, 5).map(p =>
            `- "${p.title}" by ${p.author?.name || '?'} (▲${p.upvotes || 0})`
        );
    } catch(e) {}

    try {
        const response = await fetch('/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                messages,
                user_id:    currentUser?.username || null,
                username:   currentUser?.username || null,
                user_plan:  currentUser?.plan     || 'free',
                molt_posts: moltPosts,
                provider:   selectedLlmProvider || undefined,
                model:      selectedLlmModel || undefined,
                dev_mode:   isDevUser() ? devModeEnabled : false
            })
        });
        const data  = await response.json();

        document.getElementById(thinkingId)?.remove();

        // Handle limit reached
        if (data.error === 'limit_reached') {
            chatbox.innerHTML += `<div class="msg ai"><div class="avatar">⚡</div><div class="bubble" style="border-color:rgba(255,107,53,0.4)">
                <strong style="color:#ff6b35">Monthly limit reached</strong><br><br>
                ${escapeHtml(data.message)}<br><br>
                <a href="/pages/pricing" style="display:inline-block;margin-top:4px;padding:8px 16px;background:#7c3aed;color:white;border-radius:8px;text-decoration:none;font-size:13px;">⚡ Upgrade Plan</a>
                &nbsp;
                <a href="/pages/pricing#credits" style="display:inline-block;margin-top:4px;padding:8px 16px;background:none;border:1px solid #ff6b35;color:#ff6b35;border-radius:8px;text-decoration:none;font-size:13px;">Buy Credits</a>
            </div></div>`;
            setInputDisabled(false);
            document.getElementById('userInput').focus();
            return;
        }

        const reply = data.reply || 'Something went wrong.';
        const moltNotice = data.posted_to_moltbook ? `<div class="molt-notice">🦞 Shared to Moltbook!</div>` : '';
        chatbox.innerHTML += `<div class="msg ai"><div class="avatar">⚡</div><div class="bubble">${renderMarkdown(reply)}${moltNotice}</div></div>`;

        await saveMessage('assistant', reply);
        const conv = convCache.find(c => c.conv_id === activeConvId);
        document.getElementById('chatTitle').textContent = conv?.title || 'Chat';
        document.getElementById('chatTitle').className   = 'chat-title has-msgs';
        if (data.posted_to_moltbook) setTimeout(() => { loadMoltbook(); loadMobileMoltbook(); }, 3000);

        // Render dev debug panel
        if (data.debug) renderDebug(data.debug);

    } catch (e) {
        document.getElementById(thinkingId)?.remove();
        chatbox.innerHTML += `<div class="msg ai"><div class="avatar">⚡</div><div class="bubble">Connection error. Please try again.</div></div>`;
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

async function resolveAuthChallenges(data, email, msgEl) {
    let next = data;

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
    // Switch to guest mode — reload convs from localStorage
    document.getElementById('userLoggedIn').style.display='none'; document.getElementById('userLoggedOut').style.display='block';
    document.getElementById('mobileUserLoggedIn').style.display='none'; document.getElementById('mobileUserLoggedOut').style.display='block';
    convCache = []; msgCache = {}; activeConvId = null;
    await loadConvList();
}
function showLoggedIn(username) {
    document.getElementById('userNameDisplay').textContent = username;
    document.getElementById('userLoggedIn').style.display  = 'block';
    document.getElementById('userLoggedOut').style.display = 'none';
    document.getElementById('mobileUserNameDisplay').textContent = username;
    document.getElementById('mobileUserLoggedIn').style.display  = 'block';
    document.getElementById('mobileUserLoggedOut').style.display = 'none';
    showDevFab();
    loadConvList();
    loadDiscordStatus();
    load2FAStatus();
    loadModelOptions();
    // Show admin link for dev account
    if (username === DEV_USERNAME) {
        document.getElementById('adminLink').style.display = 'inline-block';
    }
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

    const pct     = Math.min(100, Math.round((debug.msg_count / debug.msg_limit) * 100));
    const scoreColor = debug.post_score >= 4 ? 'good' : debug.post_score >= 2 ? 'warn' : 'bad';
    const msColor    = debug.groq_ms < 1000 ? 'good' : debug.groq_ms < 3000 ? 'warn' : 'bad';
    const cooldownMin = Math.ceil(debug.cooldown_left / 60);

    document.getElementById('devBody').innerHTML = `
        <div class="dev-section">
            <div class="dev-section-title">Model</div>
            <div class="dev-row">
                <span class="dev-label">Active model</span>
                <span class="dev-value accent">${debug.model}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Response time</span>
                <span class="dev-value ${msColor}">${debug.groq_ms}ms</span>
            </div>
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
                <span class="dev-label" style="white-space:nowrap">${debug.msg_count} / ${debug.msg_limit === 99999 ? '∞' : debug.msg_limit}</span>
                <div class="dev-bar-wrap"><div class="dev-bar" style="width:${pct}%"></div></div>
                <span class="dev-value">${pct}%</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Credits remaining</span>
                <span class="dev-value ${debug.credits > 0 ? 'good' : 'bad'}">${debug.credits}</span>
            </div>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">Moltbook Post Score</div>
            <div class="dev-row">
                <span class="dev-label">Score</span>
                <span class="dev-value ${scoreColor}">${debug.post_score} / 4 threshold</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Posted this message</span>
                <span class="dev-value ${debug.posted ? 'good' : 'bad'}">${debug.posted ? 'Yes ✓' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Cooldown remaining</span>
                <span class="dev-value ${debug.cooldown_left === 0 ? 'good' : 'warn'}">${debug.cooldown_left === 0 ? 'Ready' : cooldownMin + 'm'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Moltbook posts injected</span>
                <span class="dev-value">${debug.molt_posts_injected}</span>
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

    // Auto-open panel when new debug data arrives
    const panel = document.getElementById('devPanel');
    const fab   = document.getElementById('devFab');
    if (!panel.classList.contains('open')) {
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

// ── INIT ──
(async function init() {
    // checkSession handles conv loading via loadConvList internally
    await checkSession();
    loadMoltbook();
    setInterval(loadMoltbook, 5 * 60 * 1000);
    checkApiStatus();
    setInterval(checkApiStatus, 60 * 1000);
    document.getElementById('userInput').focus();
})();
</script>
</body>
</html>