<?php
session_start();

// If already logged in, go straight to deploy
if (!empty($_SESSION['user_id'])) {
    header('Location: /pages/deploy/'); exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Lyralink AI Hosting</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f; --surface:#111118; --border:#1e1e2e;
            --accent:#7c3aed; --accent-glow:rgba(124,58,237,0.25); --accent-light:#a78bfa;
            --text:#e2e8f0; --text-muted:#64748b;
            --success:#22c55e; --error:#ef4444;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px}
        body::before{content:'';position:fixed;top:-200px;left:20%;width:600px;height:500px;background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 65%);pointer-events:none}

        .card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:36px 32px;width:100%;max-width:400px;position:relative;z-index:1}

        .card-logo{text-align:center;margin-bottom:24px}
        .card-logo img{height:32px;mix-blend-mode:lighten}
        .card-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;text-align:center;margin-bottom:4px}
        .card-sub{font-size:12px;color:var(--text-muted);text-align:center;margin-bottom:28px;line-height:1.5}

        .tabs{display:flex;gap:4px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:3px;margin-bottom:20px}
        .tab{flex:1;text-align:center;padding:7px;border-radius:7px;font-size:12px;cursor:pointer;color:var(--text-muted);transition:all 0.2s}
        .tab.active{background:var(--surface);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}

        .form{display:flex;flex-direction:column;gap:10px}
        .form-hidden{display:none}
        input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:11px 13px;font-family:'DM Mono',monospace;font-size:13px;outline:none;transition:border-color 0.2s}
        input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
        input::placeholder{color:var(--text-muted)}

        .btn{width:100%;padding:12px;border-radius:10px;font-family:'DM Mono',monospace;font-size:13px;font-weight:600;cursor:pointer;border:none;background:var(--accent);color:white;transition:all 0.2s;box-shadow:0 0 16px var(--accent-glow);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
        .btn:hover{background:#6d28d9}
        .btn:disabled{opacity:0.5;cursor:not-allowed}

        .msg{font-size:11px;text-align:center;min-height:16px;margin-top:2px}
        .msg.error{color:var(--error)}
        .msg.success{color:var(--success)}

        .back-link{text-align:center;margin-top:20px;font-size:11px;color:var(--text-muted)}
        .back-link a{color:var(--accent-light);text-decoration:none}
        .back-link a:hover{text-decoration:underline}

        .spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:white;border-radius:50%;animation:spin 0.7s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        .deploy-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:20px;padding:4px 12px;font-size:10px;color:var(--accent-light);margin-bottom:20px;letter-spacing:.5px}
        .deploy-badge-dot{width:5px;height:5px;border-radius:50%;background:var(--success);animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:0.3}}

        .auth-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.7);display:none;align-items:center;justify-content:center;z-index:99;padding:16px}
        .auth-modal-card{width:min(420px,100%);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px}
        .auth-modal-title{font-family:'Syne',sans-serif;font-size:19px;font-weight:700;margin-bottom:6px}
        .auth-modal-sub{font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.55;white-space:pre-line}
        .auth-modal-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:11px 12px;font-family:'DM Mono',monospace;font-size:13px;outline:none}
        .auth-modal-input:focus{border-color:var(--accent)}
        .auth-modal-link{font-size:11px;color:var(--accent-light);margin-top:8px;display:inline-block;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
        .auth-modal-error{font-size:11px;color:var(--error);min-height:15px;margin-top:6px}
        .auth-modal-actions{display:flex;gap:8px;margin-top:10px}
        .auth-modal-btn{flex:1;padding:10px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-muted);font-family:'DM Mono',monospace;font-size:12px;cursor:pointer}
        .auth-modal-btn.primary{background:var(--accent);color:white;border-color:var(--accent)}
    </style>
</head>
<body>

<div class="card">
    <div class="card-logo">
        <img src="/assets/lyralinklogo.png" alt="Lyralink">
    </div>

    <div style="text-align:center">
        <div class="deploy-badge" style="display:inline-flex">
            <span class="deploy-badge-dot"></span>
            Returning to AI Hosting after sign in
        </div>
    </div>

    <div class="card-title">Welcome back</div>
    <p class="card-sub">Sign in or create an account to<br>deploy your AI server.</p>

    <div class="tabs">
        <div class="tab active" id="tabLogin" onclick="switchTab('login')">Sign In</div>
        <div class="tab" id="tabReg" onclick="switchTab('register')">Create Account</div>
    </div>

    <!-- LOGIN FORM -->
    <div class="form" id="loginForm">
        <input type="email" id="loginEmail" placeholder="Email" autocomplete="email">
        <input type="password" id="loginPass" placeholder="Password" autocomplete="current-password">
        <button class="btn" id="loginBtn" onclick="doLogin()">Sign In →</button>
        <div class="msg" id="loginMsg"></div>
    </div>

    <!-- REGISTER FORM -->
    <div class="form form-hidden" id="registerForm">
        <input type="text" id="regUsername" placeholder="Username" autocomplete="off">
        <input type="email" id="regEmail" placeholder="Email" autocomplete="email">
        <input type="password" id="regPass" placeholder="Password" autocomplete="new-password">
        <button class="btn" id="regBtn" onclick="doRegister()">Create Account →</button>
        <div class="msg" id="regMsg"></div>
    </div>

    <div class="back-link">
        <a href="/pages/deploy/">← Back to Deploy</a>
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

<script>
function switchTab(tab) {
    document.getElementById('tabLogin').classList.toggle('active', tab === 'login');
    document.getElementById('tabReg').classList.toggle('active', tab === 'register');
    document.getElementById('loginForm').classList.toggle('form-hidden', tab !== 'login');
    document.getElementById('registerForm').classList.toggle('form-hidden', tab !== 'register');
}

function closeAuthModal() {
    const backdrop = document.getElementById('authModalBackdrop');
    if (backdrop) backdrop.style.display = 'none';
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

    if (!backdrop || !titleEl || !subEl || !inputEl || !altEl || !errEl || !cancelBtn || !submitBtn) {
        return Promise.resolve({ cancelled: true });
    }

    titleEl.textContent = config.title || 'Verification Required';
    subEl.textContent = config.subtitle || '';
    inputEl.type = config.type || 'text';
    inputEl.value = '';
    inputEl.placeholder = config.placeholder || '';
    inputEl.maxLength = config.maxLength || 128;
    errEl.textContent = '';
    submitBtn.textContent = config.submitText || 'Continue';

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
            cancelBtn.onclick = null;
            submitBtn.onclick = null;
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

async function verifyEmailFlow(email) {
    const step = await openAuthPrompt({
        title: 'Verify Your Email',
        subtitle: 'Enter the 6-digit code sent to ' + email,
        placeholder: '123456',
        maxLength: 6,
        requiredError: 'Verification code is required'
    });
    if (step.cancelled) {
        return { success: false, error: 'Email verification required.' };
    }
    const fd = new FormData();
    fd.append('action', 'verify_email_code');
    fd.append('email', email);
    fd.append('code', step.value.trim());
    let data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.requires_2fa) {
        data = await verify2FAFlow(data.method || 'totp');
    }
    return data;
}

async function verify2FAFlow(method) {
    const isYubi = method === 'yubikey';
    const step = await openAuthPrompt({
        title: 'Two-Factor Authentication',
        subtitle: isYubi ? 'Touch your YubiKey and paste the OTP.' : 'Enter your 6-digit authenticator code.',
        placeholder: isYubi ? 'YubiKey OTP' : '123456',
        maxLength: isYubi ? 64 : 6,
        altText: 'Use backup recovery code instead',
        requiredError: isYubi ? 'YubiKey OTP is required' : 'Authenticator code is required'
    });
    if (step.cancelled) return { success: false, error: 'Two-factor verification required.' };

    const fd = new FormData();
    fd.append('action', 'verify_2fa');
    if (step.alt) {
        const recoveryStep = await openAuthPrompt({
            title: 'Backup Recovery Code',
            subtitle: 'Enter one of your one-time backup codes.',
            placeholder: 'ABCD-EFGH',
            maxLength: 16,
            requiredError: 'Recovery code is required'
        });
        if (recoveryStep.cancelled) return { success: false, error: 'Two-factor verification required.' };
        fd.append('recovery_code', recoveryStep.value.trim());
    } else if (isYubi) {
        fd.append('yubikey_otp', step.value.trim());
    } else {
        fd.append('code', step.value.trim());
    }

    return await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
}

async function resolveAuthChallenges(data, email) {
    let next = data;
    if (next.requires_email_verification || next.needs_email_verification) {
        next = await verifyEmailFlow(next.email || email);
    }
    if (next.requires_2fa) {
        next = await verify2FAFlow(next.method || 'totp');
    }
    return next;
}

async function doLogin() {
    const btn = document.getElementById('loginBtn');
    const msg = document.getElementById('loginMsg');
    const email = document.getElementById('loginEmail').value.trim();
    const pass  = document.getElementById('loginPass').value;

    if (!email || !pass) { msg.className='msg error'; msg.textContent='Please fill in all fields.'; return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Signing in…';
    msg.textContent = '';

    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('email', email);
    fd.append('password', pass);

    const res  = await fetch('/api/auth.php', { method:'POST', body:fd }).catch(() => null);
    let data = res ? await res.json().catch(() => null) : null;
    if (data) {
        data = await resolveAuthChallenges(data, email);
    }

    if (data?.success) {
        msg.className = 'msg success';
        msg.textContent = '✓ Signed in! Redirecting…';
        setTimeout(() => { window.location.href = '/pages/deploy/'; }, 600);
    } else {
        btn.disabled = false;
        btn.innerHTML = 'Sign In →';
        msg.className = 'msg error';
        msg.textContent = data?.error || 'Login failed. Please try again.';
    }
}

async function doRegister() {
    const btn      = document.getElementById('regBtn');
    const msg      = document.getElementById('regMsg');
    const username = document.getElementById('regUsername').value.trim();
    const email    = document.getElementById('regEmail').value.trim();
    const pass     = document.getElementById('regPass').value;

    if (!username || !email || !pass) { msg.className='msg error'; msg.textContent='Please fill in all fields.'; return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creating account…';
    msg.textContent = '';

    const fd = new FormData();
    fd.append('action', 'register');
    fd.append('username', username);
    fd.append('email', email);
    fd.append('password', pass);

    const res  = await fetch('/api/auth.php', { method:'POST', body:fd }).catch(() => null);
    let data = res ? await res.json().catch(() => null) : null;
    if (data) {
        data = await resolveAuthChallenges(data, email);
    }

    if (data?.success) {
        msg.className = 'msg success';
        msg.textContent = '✓ Account created! Redirecting…';
        setTimeout(() => { window.location.href = '/pages/deploy/'; }, 600);
    } else {
        btn.disabled = false;
        btn.innerHTML = 'Create Account →';
        msg.className = 'msg error';
        msg.textContent = data?.error || 'Registration failed. Please try again.';
    }
}

// Allow Enter key to submit
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const loginHidden = document.getElementById('loginForm').classList.contains('form-hidden');
    if (!loginHidden) doLogin();
    else doRegister();
});
</script>
</body>
</html>