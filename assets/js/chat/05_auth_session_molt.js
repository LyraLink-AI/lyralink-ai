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
    refreshYouTubeConnectionStatus();
}

function closeProfileSettings() {
    const backdrop = document.getElementById('profileSettingsBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}

async function connectYouTubeAccount() {
    const msg = document.getElementById('youtubeConnectMsg');
    if (msg) {
        msg.style.color = 'var(--text-muted)';
        msg.textContent = 'Opening Google consent flow...';
    }

    try {
        const res = await fetch('/api/marketing.php?action=youtube_connect', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data?.success || !data?.auth_url) {
            throw new Error(data?.error || 'Could not start YouTube connection.');
        }
        window.location.href = data.auth_url;
    } catch (err) {
        if (msg) {
            msg.style.color = 'var(--error)';
            msg.textContent = err?.message || 'Could not start YouTube connection.';
        }
    }
}

async function refreshYouTubeConnectionStatus() {
    const msg = document.getElementById('youtubeConnectMsg');
    if (!msg) return;

    try {
        const res = await fetch('/api/marketing.php?action=youtube_status', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data?.success) {
            throw new Error(data?.error || 'Unable to load YouTube status.');
        }

        if (data.connected) {
            const channelTitle = data.channel_title ? `Connected as ${data.channel_title}.` : 'YouTube is connected.';
            msg.style.color = 'var(--success)';
            msg.textContent = channelTitle + ' Autopublish is ready once the source video URL is configured.';
        } else {
            msg.style.color = 'var(--text-muted)';
            msg.textContent = 'YouTube is not connected yet. Use Connect YouTube to authorize the channel.';
        }
    } catch (err) {
        msg.style.color = 'var(--error)';
        msg.textContent = err?.message || 'Unable to load YouTube status.';
    }
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
        const widgetAcctBtn = document.getElementById('widgetAcctBtn');
        if (widgetAcctBtn) widgetAcctBtn.textContent = 'Account';
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
        const widgetAcctBtn = document.getElementById('widgetAcctBtn');
        if (widgetAcctBtn) widgetAcctBtn.textContent = username;
    showDevFab();
    loadGoalBoard();
    loadAgentStatusState();
    loadConvList();
    loadDiscordStatus();
    load2FAStatus();
    loadModelOptions();
    refreshOperatorDashboardAccess();
    /* Was: `if (username === DEV_USERNAME)` then an unguarded
     * getElementById('adminLink'). Two defects - a hardcoded username, so any
     * other administrator was missed, and no null guard, on an element whose
     * parent is display:none so it could never appear anyway.
     *
     * The visible admin entry is the top bar's server-rendered menu, which is
     * already gated on users.is_admin and needs no refresh. This only keeps the
     * flag handy for anything else that asks, reading the server's answer. */
    const adminLink = document.getElementById('adminLink');
    if (adminLink) {
        adminLink.style.display = window.LYRALINK_IS_ADMIN ? 'inline-block' : 'none';
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
