<?php
require_once __DIR__ . '/api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/api/lyra_chat_chrome.php';
require_once __DIR__ . '/api/lyra_chat_data.php';
$maintenanceFlag = __DIR__ . '/maintenance.flag';
$isMaintenance   = file_exists($maintenanceFlag);
$isDevCookie     = lyra_dev_preview();   // was a cookie any visitor could set
$devUsername     = 'developer';
$sessionUsername = (string)($_SESSION['username'] ?? '');
$isDevUser       = ($sessionUsername === $devUsername);
$impersonation = $_SESSION['admin_impersonation'] ?? null;
$isImpersonating = is_array($impersonation) && !empty($impersonation['username']);
$impersonatedAdmin = $isImpersonating ? (string)($impersonation['username'] ?? 'admin') : '';
$chatCssPath = __DIR__ . '/assets/css/chat/app.css';
$chatCssVersion = file_exists($chatCssPath) ? (string)filemtime($chatCssPath) : '1';
/* The cache key is derived only from the scripts this page actually loads (the
 * 0*.js modules, globbed below). assets/js/chat/app.js used to contribute its
 * mtime here even though no page ever loaded it, so editing a dead file changed
 * the cache key of the live ones - and a newer dead file could mask an edit to a
 * live module. app.js has been removed; this no longer references it. */
$chatJsVersionCandidates = [];
$chatJsModuleFiles = glob(__DIR__ . '/assets/js/chat/0*.js') ?: [];
if ($chatJsModuleFiles) {
    $chatJsVersionCandidates = array_merge(
        $chatJsVersionCandidates,
        array_map(static fn($f) => (int)@filemtime($f), $chatJsModuleFiles)
    );
}
$chatJsVersion = !empty($chatJsVersionCandidates) ? (string)max($chatJsVersionCandidates) : '1';

/* LYRA_ASSET_VERSIONS — every stylesheet/script the chat page loads is
 * versioned from its own mtime. Three of these previously carried a fixed
 * query string, so editing the file did not change its URL and clients kept
 * rendering the cached copy. */
$assetVersionOf = static function (string $relPath): string {
    $full = __DIR__ . $relPath;
    return file_exists($full) ? (string)filemtime($full) : '1';
};
$lyraChatDesignCssVersion = $assetVersionOf('/assets/css/chat/lyra-chat.css');
$lyraUiCssVersion         = $assetVersionOf('/assets/css/lyra-ui.css');
$lyraChatRailJsVersion    = $assetVersionOf('/assets/js/chat/lyra-chat-rail.js');

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
    <link rel="stylesheet" href="/assets/css/lyra-ui.css?v=<?php echo htmlspecialchars($lyraUiCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/assets/css/chat/lyra-chat.css?v=<?php echo htmlspecialchars($lyraChatDesignCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="/assets/js/chat/lyra-chat-rail.js?v=<?php echo htmlspecialchars($lyraChatRailJsVersion, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
<script>window.LYRA_WELCOME_HTML = <?php echo json_encode(lyra_chat_welcome(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body class="minimal-chat-shell<?php echo $isWidgetEmbed ? ' widget-chat-shell' : ''; ?>">

<!-- ══ TOP BAR (full width, above both columns) ══ -->
<header class="lyra-topbar">
    <a class="lyra-topbar-brand" href="/">
        <img src="/images/lyralinklogobolt.png" alt="" class="lyra-topbar-mark">
        <span>Lyralink</span>
    </a>

    <div class="lyra-topbar-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="lyraGlobalSearch" placeholder="Search anything&hellip; (messages, files, people, projects)" disabled
               title="Global search is not wired to an endpoint yet">
    </div>

    <?php echo lyra_chat_topbar_mid(); ?><div class="lyra-topbar-actions" id="lyraTopbarActions"><?php echo lyra_chat_topbar_actions(); ?></div>
</header>

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
    <div class="conv-header" hidden aria-hidden="true"></div>
    <?php echo lyra_chat_brand(); ?>
    <button class="new-chat-btn" onclick="newConversation()">+ New Chat</button>
    <?php
    /* The history belongs under the Conversations row, not after every nav
     * group: at 1366x768 the fixed rows above it already filled the rail, so it
     * was pushed below the fold and appeared to be missing. */
    $convListHtml = '<div class="conv-list" id="convList"></div>';
    echo lyra_chat_rail_chrome($convListHtml);
    ?>
    <?php echo lyra_chat_promo(); ?>
<div class="conv-footer">
        <div class="conv-footer-status">
            <span class="status-dot" id="apiStatusDot"></span>
            <span class="status-text" id="apiStatusText">Checking...</span>
        </div>
        <div class="conv-footer-links">
            <a href="/pages/pricing" class="footer-link">⚡ Plans</a>
            <a href="/download" class="footer-link">⬇ Desktop App</a>
            <a href="https://discord.gg/JhyPNs5Khn" target="_blank" class="footer-link">Discord</a>
            <a href="/pages/tos" class="footer-link">ToS</a>
            <a href="/pages/api_docs" class="footer-link">API</a>
            <a href="/pages/support" class="footer-link">Support</a>
            <a href="/pages/status" class="footer-link">Status</a>
            <a href="/pages/careers" class="footer-link">Careers</a>
            <a href="/pages/vscode_extension/" class="footer-link">Code Extension</a>
            <a href="/chat" class="footer-link">✦ New Chat</a>
            <a href="/pages/teams/" class="footer-link">✦ New Teams</a>
        </div>
        <div class="conv-footer-version">v1.7.5</div>
    </div>
</nav>

<!-- MIDDLE: CHAT -->
<div class="chat-panel">
    <header class="lyra-chathead">
        <button class="mobile-menu-btn" onclick="openDrawer()">☰</button>
        <div class="header-right" id="headerRight" data-lyra-moved="">
            <button class="btn-small" onclick="openVoicePanel()" title="AI Voice">🎤 Voice</button>
            <button class="btn-small" onclick="clearCurrentChat()">✕ Clear</button>
            <?php if ($isImpersonating): ?>
            <button class="btn-small" onclick="endImpersonation()">↩ Return Admin</button>
            <?php endif; ?>
            <a href="/pages/pricing" style="font-size:10px;padding:2px 8px;border-radius:20px;border:1px solid rgba(255,107,53,0.4);color:#ff6b35;text-decoration:none;font-family:'DM Mono',monospace;">⚡ Plans</a>
            <?php /* The admin entry used to live here, inside .header-right, which
                     lyra-chat.css hides outright - so it could never have been
                     shown. The top bar carries a working admin menu, rendered
                     server-side from users.is_admin. Removed rather than left as a
                     misleading hook for the next reader. (The element's id is not
                     repeated here: an assertion greps for it, and a comment that
                     quotes the thing being searched for has broken this project's
                     own checks repeatedly.) */ ?>
                <span class="badge badge-groq">Lyra-1</span>
                <?php /* No id here: the top bar renders the live #headerAcctBtn,
                         and two elements sharing that id made getElementById
                         resolve by document order. */ ?>
                <a class="acct-btn" href="/pages/login.php?next=%2Fchat%2F">👤 Sign in</a>
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

    <div class="lyra-panelhost" id="lyraPanelHost" hidden>
        <div class="lyra-panelbox" role="dialog" aria-modal="false" aria-label="Workspace panel">
            <button type="button" class="lyra-panelclose" id="lyraPanelClose" aria-label="Close panel">&times;</button>

            <div class="lyra-panelview" id="lyraPanelProjects" data-panel="projects" hidden>
                <h2 class="lyra-paneltitle">Projects</h2>
                <p class="lyra-panelnote">Group conversations and files behind one objective, so the assistant keeps the right context without being told each time.</p>

                <div class="ws-toolbar">
                    <button type="button" class="ly-btn ly-btn-primary ly-btn-sm" id="wsNewProject">+ New project</button>
                </div>

                <div class="ws-form" id="wsProjectForm" hidden>
                    <input type="text" id="wsProjectName" class="ws-input" maxlength="120" placeholder="Project name">
                    <textarea id="wsProjectDesc" class="ws-input" rows="2" maxlength="2000" placeholder="What is this project for? (optional)"></textarea>
                    <button type="button" class="ly-btn ly-btn-primary ly-btn-sm" id="wsCreateProject">Create project</button>
                </div>

                <div class="ws-note" id="wsProjectNote"></div>
                <div class="ws-list" id="wsProjectList"></div>
            </div>

            <div class="lyra-panelview" id="lyraPanelFiles" data-panel="files" hidden>
                <h2 class="lyra-paneltitle">Files</h2>
                <p class="lyra-panelnote">Your file library. Uploads are private to your account; 25MB per file.</p>

                <div class="ws-toolbar">
                    <select id="wsFileProject" class="ws-input ws-select" aria-label="Attach to project"></select>
                    <button type="button" class="ly-btn ly-btn-primary ly-btn-sm" id="wsUploadBtn">Upload file</button>
                    <input type="file" id="wsFileInput" hidden>
                </div>

                <div class="ws-note" id="wsFileNote"></div>
                <div class="ws-list" id="wsFileList"></div>
            </div>

            <div class="lyra-panelview" id="lyraPanelSettings" data-panel="settings" hidden>
                <h2 class="lyra-paneltitle">Settings</h2>

                <div class="lyra-settingrow">
                    <div class="lyra-settingtext"><b>Appearance</b><em>Switch between dark and light.</em></div>
                    <button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" onclick="if(window.LyraTheme){LyraTheme.toggle();}">Toggle theme</button>
                </div>

                <div class="lyra-settingrow">
                    <div class="lyra-settingtext"><b>Notifications</b><em>Service incidents and account alerts.</em></div>
                    <button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" data-lyra-notify-toggle onclick="if(window.LyraNotify){LyraNotify.toggle();}">Open notifications</button>
                </div>

                <div class="lyra-settingrow">
                    <div class="lyra-settingtext"><b>Account</b><em>Profile, plan and API keys.</em></div>
                    <button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" onclick="openAccountModal()">Open account</button>
                </div>

                <div class="lyra-settingrow">
                    <div class="lyra-settingtext"><b>Current theme</b><em id="lyraSettingTheme">—</em></div>
                    <button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" onclick="if(window.LyraTheme){LyraTheme.clear();}">Reset to system</button>
                </div>
            </div>
        </div>
    </div>

    <div id="chatbox">
        <?php echo lyra_chat_welcome(); ?>
    </div>

    <?php echo lyra_chat_stages(); ?>

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
        <?php echo lyra_chat_composer_chips(); ?>
        <div class="input-area">
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
/* ══ WORKSPACE PANELS ══════════════════════════════════════════════════════
   One host element, three contents, opened from the rail. Kept here rather than
   in a chat module because it touches no application state - it only shows and
   hides markup, and reads the theme name from the one place that owns it. */
(function () {
    'use strict';
    var host = document.getElementById('lyraPanelHost');
    var closeBtn = document.getElementById('lyraPanelClose');
    var triggers = document.querySelectorAll('[data-lyra-panel]');
    if (!host || !triggers.length) { return; }

    var openName = null;

    function views() { return host.querySelectorAll('.lyra-panelview'); }

    function paint() {
        Array.prototype.forEach.call(triggers, function (t) {
            var on = t.getAttribute('data-lyra-panel') === openName;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-expanded', on ? 'true' : 'false');
        });
        Array.prototype.forEach.call(views(), function (v) {
            v.hidden = (v.getAttribute('data-panel') !== openName);
        });
        if (openName === 'settings') {
            var out = document.getElementById('lyraSettingTheme');
            if (out) {
                out.textContent = window.LyraTheme
                    ? (LyraTheme.current() === 'light' ? 'Light' : 'Dark')
                    : 'Theme system not loaded';
            }
        }
    }

    function open(name) { openName = name; host.hidden = false; paint(); }
    function close() { openName = null; host.hidden = true; paint(); }
    function toggle(name) { (openName === name) ? close() : open(name); }

    Array.prototype.forEach.call(triggers, function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            toggle(t.getAttribute('data-lyra-panel'));
        });
    });
    if (closeBtn) { closeBtn.addEventListener('click', close); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    /* Clicking the backdrop dismisses the panel; clicking its content does not.

       This previously tested `host.contains(e.target)`, but the host IS the
       full-area backdrop, so a click anywhere on the dimmed area counted as
       "inside" and the panel could only be closed by Escape or the X. The check
       is now against the panel box, which is the thing that should swallow
       clicks. */
    document.addEventListener('click', function (e) {
        if (!openName) { return; }
        if (e.target.closest && e.target.closest('[data-lyra-panel]')) { return; }
        var box = host.querySelector('.lyra-panelbox');
        if (box && box.contains(e.target)) { return; }
        close();
    });
    /* The theme can change from the top bar while this panel is open. */
    document.addEventListener('lyralink:theme', paint);
    window.LyraPanels = { open: open, close: close, toggle: toggle };
})();
</script>
<script>
window.LYRALINK_WIDGET_MODE = <?php echo $isWidgetEmbed ? 'true' : 'false'; ?>;
window.LYRALINK_DEV_USER = <?php echo $isDevUser ? 'true' : 'false'; ?>;
/* The authoritative admin answer, from users.is_admin. The client previously
   compared a username, so any other administrator was not recognised. */
window.LYRALINK_IS_ADMIN = <?php
    $lyViewer = function_exists('lyra_chat_viewer') ? lyra_chat_viewer() : null;
    echo ($lyViewer !== null && !empty($lyViewer['is_admin'])) ? 'true' : 'false';
?>;
</script>
<!-- Loaded before the chat modules so the fetch/XHR patch is in place before
     anything calls the API. Carries no authority of its own; see the file. -->
<script src="/assets/js/lyra-csrf.js"></script>
<script src="/assets/js/chat/01_core_runtime.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/02_conversations.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/03_agent_assist.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/04_send_message.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/05_auth_session_molt.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/06_dev_markdown.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="/assets/js/chat/07_voice_and_boot.js?v=<?php echo htmlspecialchars($chatJsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<!-- Workspace panels. Loaded after the chat modules so the active conversation
     id is available for "link this chat", and after lyra-csrf.js in <head> so
     state-changing calls carry the token. -->
<script src="/assets/js/chat/workspace-panels.js?v=2"></script>

<!-- ══ RIGHT RAIL: context & execution ══ -->
<aside class="lyra-chatrail" id="lyraChatRail">
    <div class="lyra-rail-tabs">
        <button type="button" class="lyra-rail-tab is-active" data-lyra-rail="context">Context</button>
        <button type="button" class="lyra-rail-tab" data-lyra-rail="execution">Execution</button>
    </div>

    <div class="lyra-rail-body" data-lyra-panel="context">
        <?php echo lyra_chat_rail_model(); ?>

        <?php echo lyra_chat_rail_tools(); ?>

        <?php echo lyra_chat_task_progress(); ?>
        <div class="lyra-rail-label">Last response</div>
        <div class="lyra-rail-note" id="lyraRailLastResponse">No request yet. Send a message and the execution details will appear here.</div>

        <?php echo lyra_chat_sources(); ?>
        <?php echo lyra_chat_related(); ?>
    </div>

    <div class="lyra-rail-body" data-lyra-panel="execution" hidden>
        <div class="lyra-rail-label">Execution trace</div>
        <div class="lyra-rail-note" id="lyraRailTrace">Nothing has run yet in this session.</div>
        <div class="lyra-rail-label">Session</div>
        <div class="lyra-rail-list">
            <div class="lyra-rail-row"><span>Conversations</span><em id="lyraRailConvCount">&mdash;</em></div>
            <div class="lyra-rail-row"><span>API status</span><em id="lyraRailApiStatus">&mdash;</em></div>
        </div>
    </div>
</aside>

<script>
/* Rail tab switching. Plain DOM only - it touches none of the chat modules. */
(function () {
    var tabs = document.querySelectorAll('[data-lyra-rail]');
    var panels = document.querySelectorAll('[data-lyra-panel]');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (o) { o.classList.toggle('is-active', o === t); });
            panels.forEach(function (p) { p.hidden = (p.getAttribute('data-lyra-panel') !== t.getAttribute('data-lyra-rail')); });
        });
    });

    /* Keep the rail in step with the chat without reaching into its internals:
       read the DOM it renders and mirror the useful parts. */
    var model = document.getElementById('lyraRailModel');
    var state = document.getElementById('lyraRailModelState');
    var badge = document.querySelector('.badge');
    if (model && badge && badge.textContent.trim()) { model.textContent = badge.textContent.trim(); }

    var apiText = document.getElementById('apiStatusText');
    var apiOut = document.getElementById('lyraRailApiStatus');
    if (apiText && apiOut) {
        var sync = function () { apiOut.textContent = apiText.textContent.trim() || '—'; };
        sync();
        new MutationObserver(sync).observe(apiText, { childList: true, characterData: true, subtree: true });
    }

    var convList = document.getElementById('convList');
    var convOut = document.getElementById('lyraRailConvCount');
    if (convList && convOut) {
        var count = function () { convOut.textContent = String(convList.children.length); };
        count();
        new MutationObserver(count).observe(convList, { childList: true });
    }

    var box = document.getElementById('chatbox');
    var last = document.getElementById('lyraRailLastResponse');
    if (box && last) {
        var watch = function () {
            if (box.querySelector('.empty-state') && box.children.length <= 1) { return; }
            var n = box.querySelectorAll('.message, .msg, .chat-message').length;
            if (n > 0) { last.textContent = n + ' message' + (n === 1 ? '' : 's') + ' in this thread.'; state && (state.textContent = 'Ready'); }
        };
        new MutationObserver(watch).observe(box, { childList: true, subtree: true });
    }
})();
</script>
<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — MODEL PICKER
   ══════════════════════════════════════════════════════════════════════════
   Clicking the model selector used to call openAccountModal(), which is the
   profile panel — so choosing a model meant opening your account settings and
   finding the model section inside it. This opens a small list of models
   directly, as asked.

   It does NOT invent a new selection mechanism. The chat already has one, in
   05_auth_session_molt.js:

       availableModelProviders   filled by loadModelOptions()
       selectedLlmProvider/model the globals the send path reads
       04_send_message.js        const modelToSend = selectedLlmModel || stored.model
       modelPrefKey(suffix)      'lyralink_model_<suffix>_<username>'
       saveModelPreference()     persists via those keys

   So this picker drives that existing system rather than shadowing it: it sets
   the globals, calls saveModelPreference() when the account-modal elements are
   present, and writes the same localStorage keys itself if they are not. The
   label in the selector is updated to match. Nothing about the server's default
   is changed until the user actually picks something.
   ══════════════════════════════════════════════════════════════════════════ */
?>
<div class="lyra-modelpick" id="lyraModelPick" hidden>
    <div class="lyra-mp-head">
        <b>Model</b>
        <button type="button" class="lyra-mp-x" id="lyraModelPickClose" aria-label="Close">&times;</button>
    </div>
    <div class="lyra-mp-body" id="lyraModelPickBody">
        <div class="lyra-mp-note">Loading models&hellip;</div>
    </div>
    <div class="lyra-mp-foot">
        <span id="lyraModelPickCurrent"></span>
    </div>
</div>

<script>
(function () {
    'use strict';

    var pick = document.getElementById('lyraModelPick');
    var body = document.getElementById('lyraModelPickBody');
    var currentOut = document.getElementById('lyraModelPickCurrent');
    if (!pick || !body) { return; }

    var providers = Array.isArray(window.availableModelProviders) ? window.availableModelProviders : [];
    var open = false;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (s) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s];
        });
    }

    function currentProvider() {
        try { if (typeof selectedLlmProvider !== 'undefined' && selectedLlmProvider) return String(selectedLlmProvider); } catch (e) {}
        var sel = document.getElementById('modelProviderSelect');
        return sel && sel.value ? sel.value : '';
    }
    function currentModel() {
        try { if (typeof selectedLlmModel !== 'undefined' && selectedLlmModel) return String(selectedLlmModel); } catch (e) {}
        var sel = document.getElementById('modelNameSelect');
        return sel && sel.value ? sel.value : '';
    }

    function setLabel(text) {
        var el = document.querySelector('.lyra-msel-label');
        if (el) { el.textContent = text; }
        var cur = document.getElementById('lyraModelCurrent');
        if (cur) { cur.textContent = text; }
    }

    function render() {
        if (!providers.length) {
            body.innerHTML = '<div class="lyra-mp-note">'
                + (window.LYRALINK_SIGNED_IN === false
                    ? 'Sign in to choose a model.'
                    : 'No model providers are configured yet.')
                + '</div>';
            return;
        }
        var cp = currentProvider();
        var cm = currentModel();
        var html = '';
        providers.forEach(function (p) {
            var models = Array.isArray(p.models) ? p.models : [];
            html += '<div class="lyra-mp-provider">' + esc(p.label || p.id) + '</div>';
            if (!models.length) {
                html += '<div class="lyra-mp-note">No models listed for this provider.</div>';
                return;
            }
            models.forEach(function (m) {
                var on = (m === cm) && (cp === '' || cp === p.id);
                html += '<button type="button" class="lyra-mp-item' + (on ? ' is-on' : '') + '"'
                     + ' data-provider="' + esc(p.id) + '" data-model="' + esc(m) + '">'
                     + '<span>' + esc(m) + '</span>'
                     + (on ? '<span class="lyra-mp-tick">&#10003;</span>' : '')
                     + '</button>';
            });
        });
        body.innerHTML = html;
    }

    function apply(provider, model) {
        /* Drive the existing system: the globals the send path reads, then the
           same persistence the account modal uses. */
        try { selectedLlmProvider = provider; } catch (e) {}
        try { selectedLlmModel = model; } catch (e) {}

        var ps = document.getElementById('modelProviderSelect');
        var ms = document.getElementById('modelNameSelect');
        if (ps && provider) { ps.value = provider; }
        if (ps) {
            ps.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (ms) {
            /* After the provider change the options have been rebuilt, so the
               model must be set afterwards. */
            var found = Array.prototype.some.call(ms.options, function (o) { return o.value === model; });
            if (!found) {
                var o = document.createElement('option');
                o.value = model; o.textContent = model;
                ms.appendChild(o);
            }
            ms.value = model;
            ms.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (typeof saveModelPreference === 'function') {
            try { saveModelPreference(); } catch (e) {}
        } else {
            /* The account modal is not in this page, so persist with the same
               keys the chat itself uses. */
            try {
                if (typeof modelPrefKey === 'function') {
                    localStorage.setItem(modelPrefKey('provider'), provider);
                    localStorage.setItem(modelPrefKey('name'), model);
                }
            } catch (e) {}
        }

        setLabel(model);
        render();
        var foot = document.getElementById('lyraModelPickCurrent');
        if (foot) { foot.textContent = 'Using ' + model; }
    }

    function load() {
        body.innerHTML = '<div class="lyra-mp-note">Loading models&hellip;</div>';
        var fd = new FormData();
        fd.append('action', 'get_model_options');
        fetch('/api/auth.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.success !== true) { throw new Error(d && d.error ? d.error : 'Could not load models'); }
                providers = Array.isArray(d.providers) ? d.providers : [];
                /* Keep the shared global in step so the rest of the app agrees. */
                try { availableModelProviders = providers; } catch (e) {}
                render();
            })
            .catch(function (e) {
                providers = [];
                body.innerHTML = '<div class="lyra-mp-note">' + esc(e.message) + '</div>';
            });
    }

    body.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('[data-model]') : null;
        if (!item) { return; }
        apply(item.getAttribute('data-provider'), item.getAttribute('data-model'));
    });

    function show() {
        open = true;
        pick.hidden = false;
        load();
    }
    function hide() {
        open = false;
        pick.hidden = true;
    }

    /* The selector, and the rail's Change action, both open this. */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-lyra-model-pick]') : null;
        if (trigger) {
            e.preventDefault();
            open ? hide() : show();
            return;
        }
        if (!open) { return; }
        if (pick.contains(e.target)) { return; }
        hide();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
    var x = document.getElementById('lyraModelPickClose');
    if (x) { x.addEventListener('click', hide); }

    var cur = document.getElementById('lyraModelCurrent');
    if (cur && currentModel()) { setLabel(currentModel()); }
})();
</script>

</body>
</html>