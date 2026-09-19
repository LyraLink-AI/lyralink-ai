<?php
session_start();
$maintenanceFlag = __DIR__ . '/maintenance.flag';
$isMaintenance   = file_exists($maintenanceFlag);
$isDevCookie     = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
$devUsername     = 'developer';
$sessionUsername = (string)($_SESSION['username'] ?? '');
$isDevUser       = ($sessionUsername === $devUsername);
$impersonation = $_SESSION['admin_impersonation'] ?? null;
$isImpersonating = is_array($impersonation) && !empty($impersonation['username']);
$impersonatedAdmin = $isImpersonating ? (string)($impersonation['username'] ?? 'admin') : '';
$chatCssPath = __DIR__ . '/assets/css/chat/app.css';
$chatJsPath = __DIR__ . '/assets/js/chat/app.js';
$chatCssVersion = file_exists($chatCssPath) ? (string)filemtime($chatCssPath) : '1';
$chatJsVersionCandidates = [];
if (file_exists($chatJsPath)) {
    $chatJsVersionCandidates[] = (int)filemtime($chatJsPath);
}
$chatJsModuleFiles = glob(__DIR__ . '/assets/js/chat/0*.js') ?: [];
if ($chatJsModuleFiles) {
    $chatJsVersionCandidates = array_merge(
        $chatJsVersionCandidates,
        array_map(static fn($f) => (int)@filemtime($f), $chatJsModuleFiles)
    );
}
$chatJsVersion = !empty($chatJsVersionCandidates) ? (string)max($chatJsVersionCandidates) : '1';
$isWidgetEmbed = isset($_GET['widget']) && (string)$_GET['widget'] === '1';
if ($isMaintenance && !$isDevCookie) {
    header('Location: /pages/maintenance.php'); exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0a0a0f">
    <title>Lyralink AI | Powered by LyralinkAI</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/9.1.6/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/chat/app.css?v=<?php echo htmlspecialchars($chatCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="minimal-chat-shell<?php echo $isWidgetEmbed ? ' widget-chat-shell' : ''; ?>">

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
            <a href="/automation" class="footer-link">🔁 Automations</a>
            <a href="/download" class="footer-link">⬇ Desktop App</a>
            <a href="https://discord.gg/JhyPNs5Khn" target="_blank" class="footer-link">Discord</a>
            <a href="/pages/tos" class="footer-link">ToS</a>
            <a href="/pages/api_docs" class="footer-link">API</a>
            <a href="/pages/support" class="footer-link">Support</a>
            <a href="/pages/status" class="footer-link">Status</a>
            <a href="/pages/careers" class="footer-link">Careers</a>
            <a href="/pages/vscode_extension/" class="footer-link">Code Extension</a>
            <a href="/pages/landing/" class="footer-link">✦ New UI</a>
            <a href="/pages/chat-workspace/" class="footer-link">✦ New Chat</a>
            <a href="/pages/teams/" class="footer-link">✦ New Teams</a>
        </div>
        <div class="conv-footer-version">v1.7.5</div>
    </div>
</nav>

<!-- MIDDLE: CHAT -->
<div class="chat-panel">
    <header>
        <button class="mobile-menu-btn" onclick="openDrawer()">☰</button>
        <div class="chat-title" id="chatTitle">New Chat</div>
        <div class="header-right" id="headerRight">
            <button class="btn-small" onclick="openVoicePanel()" title="AI Voice">🎤 Voice</button>
            <button class="btn-small" onclick="clearCurrentChat()">✕ Clear</button>
            <?php if ($isImpersonating): ?>
            <button class="btn-small" onclick="endImpersonation()">↩ Return Admin</button>
            <?php endif; ?>
            <a href="/pages/pricing" style="font-size:10px;padding:2px 8px;border-radius:20px;border:1px solid rgba(255,107,53,0.4);color:#ff6b35;text-decoration:none;font-family:'DM Mono',monospace;">⚡ Plans</a>
            <a href="/pages/admin" id="adminLink" style="display:none;font-size:10px;padding:2px 8px;border-radius:20px;border:1px solid rgba(124,58,237,0.4);color:#a78bfa;text-decoration:none;font-family:'DM Mono',monospace;">⚙ Admin</a>
                <span class="badge badge-groq">Lyra-1</span>
                <button class="acct-btn" id="headerAcctBtn" onclick="openAccountModal()">👤 Sign in</button>
        </div>
    </header>

    <?php if ($isImpersonating): ?>
    <div class="impersonation-banner" id="impersonationBanner">
        <span>Client session mode active. Viewing as end-user. Admin: <?php echo htmlspecialchars($impersonatedAdmin, ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" onclick="endImpersonation()">Return to Admin</button>
    </div>
    <?php endif; ?>

    <?php if ($isWidgetEmbed): ?>
    <div class="widget-utility-bar">
        <button type="button" class="widget-utility-btn" onclick="newConversation()">+ New chat</button>
        <button type="button" class="widget-utility-btn" id="widgetAcctBtn" onclick="openAccountModal()">Account</button>
        <button type="button" class="widget-utility-btn" onclick="if (window.parent !== window) { window.parent.postMessage('lyralink:close', '*'); }">Close</button>
    </div>
    <?php endif; ?>

    <div class="chat-top-tools">
        <div class="ai-tools-wrap" id="aiToolsWrap">
            <button type="button" class="ai-tools-toggle-btn" id="aiToolsToggleBtn" onclick="toggleAiTools()" aria-expanded="false">Tools</button>
            <div class="ai-tools-row">
                <label class="ai-tool-toggle">
                    <input type="checkbox" id="taskModeToggle">
                    <span class="tool-label-full">Task mode</span>
                    <span class="tool-label-short">Tasks</span>
                </label>
                <label class="ai-tool-toggle">
                    <input type="checkbox" id="codeTestToggle">
                    <span class="tool-label-full">Code checks</span>
                    <span class="tool-label-short">Checks</span>
                </label>
                <button type="button" class="ai-tool-action-btn" onclick="startImagePrompt()">Generate image</button>
            </div>
        </div>
    </div>

    <div id="chatbox">
        <div class="empty-state" id="emptyState">
            <div class="icon">⚡</div>
                <p>Ask Lyralink anything, or start a real execution flow.</p>
                <div class="quick-prompts">
                    <span class="quick-prompt" onclick="setInput('Plan the next steps for this product launch')">Plan</span>
                    <span class="quick-prompt" onclick="setInput('Debug this code and explain the root cause')">Debug</span>
                    <span class="quick-prompt" onclick="setInput('Design a clean architecture for this feature')">Build</span>
                    <span class="quick-prompt" onclick="setInput('Turn this rough idea into a clear execution plan')">Ship</span>
                </div>
        </div>
    </div>

    <div class="composer-wrap">
        <div class="chat-attachment-bar" id="chatAttachmentBar">
            <div class="chat-attachment-meta">
                <span>📎</span>
                <div>
                    <div class="chat-attachment-name" id="chatAttachmentName">No file selected</div>
                    <div class="chat-attachment-size" id="chatAttachmentSize"></div>
                </div>
            </div>
            <button type="button" class="chat-attachment-remove" onclick="clearPendingAttachment()">Remove</button>
        </div>
        <div class="input-area">
            <button type="button" id="attachBtn" class="chat-attach-btn" onclick="openChatAttachmentPicker()" title="Attach image or file">📎</button>
            <input type="file" id="chatAttachmentInput" style="display:none" accept=".txt,.md,.markdown,.pdf,.csv,.json,.docx,.png,.jpg,.jpeg,.gif,.webp,.svg,.js,.ts,.py,.php,.html,.css,.xml,.log" onchange="onChatAttachmentSelected(event)">
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
</div>

<!-- ACCOUNT SLIDE PANEL -->
<div class="acct-backdrop" id="acctBackdrop" onclick="acctBackdropClick(event)">
    <div class="acct-card" id="acctCard">
        <div class="acct-card-header">
            <span class="acct-card-title">Account</span>
            <button class="acct-close-btn" onclick="closeAccountModal()">✕</button>
        </div>
        <div id="userLoggedIn" style="display:none">
            <div class="user-info" style="margin-bottom:14px">
                <div class="user-avatar">👤</div>
                <div class="user-name" id="userNameDisplay"></div>
                <button class="btn-small" onclick="logout()">Logout</button>
            </div>
            <div class="profile-settings-wrap">
                <div class="profile-settings-title">Account Settings</div>
                <button class="btn-small profile-settings-btn" onclick="openProfileSettings()">Open Profile Settings</button>
                <div id="operatorDashboardWrap" style="display:none; margin-top:8px;">
                    <button class="btn-small profile-settings-btn" onclick="openOperatorDashboard()">Open Operator Dashboard</button>
                </div>
            </div>
        </div>
        <div id="userLoggedOut">
            <div class="auth-tabs" style="margin-bottom:10px">
                <div class="auth-tab active" onclick="switchAuthTab('login',this)">Login</div>
                <div class="auth-tab" onclick="switchAuthTab('register',this)">Register</div>
            </div>
            <form id="loginForm" class="auth-form" method="post" action="/api/auth.php" autocomplete="on" onsubmit="event.preventDefault(); login();">
                <input class="auth-input" type="email" id="loginEmail" name="username" placeholder="Email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false">
                <input class="auth-input" type="password" id="loginPass" name="password" placeholder="Password" autocomplete="current-password">
                <button class="auth-btn" type="submit">Login</button>
                <div class="auth-help-link" onclick="forgotPasswordFlow(false)">Forgot password?</div>
                <div id="loginMsg"></div>
            </form>
            <div id="registerForm" class="auth-form" style="display:none">
                <input class="auth-input" type="text" id="regUsername" name="username" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false">
                <input class="auth-input" type="email" id="regEmail" name="regEmail" placeholder="Email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false">
                <input class="auth-input" type="password" id="regPass" placeholder="Password" autocomplete="new-password">
                <button class="auth-btn" onclick="register()">Create Account</button>
                <div id="registerMsg"></div>
            </div>
        </div>
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
                    <div class="profile-settings-wrap" style="margin-top:12px;">
                        <div class="profile-settings-title">Account Settings</div>
                        <button class="btn-small profile-settings-btn" onclick="openProfileSettings()">Open Profile Settings</button>
                        <div id="mobileOperatorDashboardWrap" style="display:none; margin-top:8px;">
                            <button class="btn-small profile-settings-btn" onclick="openOperatorDashboard()">Open Operator Dashboard</button>
                        </div>
                    </div>
                </div>
                <div id="mobileUserLoggedOut">
                    <div class="auth-tabs" style="margin-bottom:12px;">
                        <div class="auth-tab active" onclick="switchMobileAuthTab('login',this)">Login</div>
                        <div class="auth-tab" onclick="switchMobileAuthTab('register',this)">Register</div>
                    </div>
                    <form id="mobileLoginForm" class="auth-form" method="post" action="/api/auth.php" autocomplete="on" onsubmit="event.preventDefault(); mobileLogin();">
                        <input class="auth-input" type="email" id="mobileLoginEmail" name="username" placeholder="Email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false">
                        <input class="auth-input" type="password" id="mobileLoginPass" name="password" placeholder="Password" autocomplete="current-password">
                        <button class="auth-btn" type="submit">Login</button>
                        <div class="auth-help-link" onclick="forgotPasswordFlow(true)">Forgot password?</div>
                        <div id="mobileLoginMsg"></div>
                    </form>
                    <div id="mobileRegisterForm" class="auth-form" style="display:none">
                        <input class="auth-input" type="text" id="mobileRegUsername" name="mobileUsername" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false">
                        <input class="auth-input" type="email" id="mobileRegEmail" name="mobileRegEmail" placeholder="Email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false">
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
    <button class="bottom-nav-item" id="navVoice" onclick="openVoicePanel()">
        <span class="nav-icon">🎤</span>Voice
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

<!-- VOICE PANEL -->
<div class="voice-panel-backdrop" id="voicePanelBackdrop">
    <div class="voice-panel-card">
        <button class="voice-close-btn" onclick="closeVoicePanel()" aria-label="Close">✕</button>
        <div class="voice-title">🎤 AI Voice</div>
        <div class="voice-orb-wrap" id="voiceOrbWrap" onclick="voiceOrbClick()" role="button" aria-label="Voice button" tabindex="0">
            <div class="voice-orb-ring" id="voiceOrbRing"></div>
            <div class="voice-orb-ring2" id="voiceOrbRing2"></div>
            <div class="voice-orb" id="voiceOrb">🎤</div>
        </div>
        <div class="voice-status" id="voiceStatus">Tap once to enter voice mode</div>
        <div class="voice-transcript-wrap empty" id="voiceTranscript">Your words will appear here...</div>
        <div class="voice-response-wrap" id="voiceResponse"></div>
        <div class="voice-controls">
            <button class="voice-ctrl-btn" id="voiceStopBtn" onclick="voiceStop()" style="display:none">■ End voice mode</button>
            <button class="voice-ctrl-btn danger" onclick="voiceClear()">✕ Clear</button>
        </div>
        <div class="voice-no-support" id="voiceNoSupport" style="display:none">
            Voice input not supported in this browser. Try Chrome or Edge.
        </div>
        <div class="voice-hint" id="voiceHint">Tap once to enter voice mode · responses are spoken aloud and listening resumes automatically</div>
        <?php if ($isDevUser): ?>
        <div class="voice-dev-lab" id="voiceDevLab">
            <div class="voice-dev-lab-title">Developer Voice Lab</div>
            <label class="voice-dev-toggle">
                <input type="checkbox" id="voiceLiveLoopToggle" onchange="voiceApplyLabSettings()">
                <span>Live loop (auto-listen after response)</span>
            </label>
            <label class="voice-dev-toggle">
                <input type="checkbox" id="voiceDeepThinkingToggle" onchange="voiceApplyLabSettings()">
                <span>Deep thinking mode</span>
            </label>
            <label class="voice-dev-toggle">
                <input type="checkbox" id="voiceShowThinkingToggle" onchange="voiceApplyLabSettings()">
                <span>Show model thinking trace</span>
            </label>
            <div class="voice-dev-note" id="voiceDevNote">Lab mode lets you stress-test streaming voice turns and barge-in cancellation.</div>
            <div class="voice-thinking-wrap" id="voiceThinkingWrap" style="display:none"></div>
        </div>
        <?php endif; ?>
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
            <?php if ($isDevUser): ?>
            <div class="profile-block" id="youtubeConnectSection">
                <h4>YouTube Connection</h4>
                <div class="profile-note">Authorize Google so this developer account can publish videos and refresh metrics automatically.</div>
                <div class="discord-sync-row" style="gap:6px;display:flex;flex-wrap:wrap">
                    <button class="btn-small" onclick="connectYouTubeAccount()">Connect YouTube</button>
                    <button class="btn-small" onclick="refreshYouTubeConnectionStatus()">Refresh Status</button>
                </div>
                <div id="youtubeConnectMsg" class="profile-status-msg"></div>
            </div>
            <?php endif; ?>
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
                    <select class="auth-input" id="modelNameSelect" onchange="onModelNameChange()"></select>
                    <button class="btn-small" onclick="saveModelPreference()">Save Model Settings</button>
                    <div id="modelSettingsMsg" style="font-size:11px;color:var(--text-muted)"></div>
                </div>
            </div>
            <div class="profile-block profile-block-danger" style="grid-column:1/-1">
                <h4>Data & Privacy</h4>
                <div class="profile-note">Delete your saved chat history immediately, or submit a full account-data deletion request for manual processing. Full requests cover account records, API keys, support data, and any linked workspace cleanup.</div>
                <div class="profile-danger-actions">
                    <button class="btn-small btn-danger-small" id="deleteSavedChatBtn" onclick="deleteSavedChatData()">Delete Saved Chats</button>
                    <button class="btn-small btn-danger-small" id="requestDeletionBtn" onclick="requestDataDeletion()">Request Full Data Deletion</button>
                </div>
                <div id="deleteSavedChatMsg" class="profile-status-msg"></div>
                <div id="requestDeletionMsg" class="profile-status-msg"></div>
            </div>
        </div>
        <div class="auth-modal-actions" style="margin-top:14px">
            <button class="auth-modal-btn primary" onclick="closeProfileSettings()">Done</button>
        </div>
    </div>
</div>

<script>
window.LYRALINK_WIDGET_MODE = <?php echo $isWidgetEmbed ? 'true' : 'false'; ?>;
window.LYRALINK_DEV_USER = <?php echo $isDevUser ? 'true' : 'false'; ?>;
</script>
<script src="/assets/js/chat/01_core_runtime.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/02_conversations.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/03_agent_assist.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/04_send_message.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/05_auth_session_molt.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/06_dev_markdown.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/07_voice_and_boot.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>