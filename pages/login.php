<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/lyra_art.php';

if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}

// Already signed in? Send them to the workspace.
if (!empty($_SESSION['user_id'])) {
    header('Location: /chat'); exit;
}

/* Sign-in page. Implements the approved LoginPage design.
 *
 * Wired to the REAL auth API. Contract verified against api/auth.php and the
 * existing client in assets/js/chat/05_auth_session_molt.js:
 *   action=login                  email, password
 *   action=verify_2fa             code | recovery_code
 *   action=request_password_reset email
 *   action=register               username, email, password
 * Responses are JSON with {success:true,...} or {success:false,error,...} plus
 * optional requires_2fa / requires_email_verification / requires_password_change.
 *
 * SSO buttons (Google/GitHub/Microsoft) are rendered from the design but
 * DISABLED: no matching auth action exists in the backend, and a button that
 * looks functional but is not would be worse than one that is clearly absent.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="description" content="Sign in to your Lyralink account.">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        /* ── page-specific ─────────────────────────────────────────────── */
        .lg-split { display: grid; grid-template-columns: 1.05fr 0.95fr; min-height: 100vh; }

        /* Left: CSS-rendered violet landscape (the mockup artwork is not in
           /images; this approximates the mood and can be swapped for the real
           asset by replacing .lg-scene with a background-image). */
        .lg-left {
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; justify-content: space-between;
            padding: 40px 48px;
            background: linear-gradient(180deg, #0B0620 0%, #14093A 42%, #1E0E52 100%);
        }
        .lg-scene { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        /* The generated SVG paints the whole scene; these hand-built
           approximations would otherwise draw on top of it. */
        .lg-scene .lg-moon,
        .lg-scene .lg-aurora,
        .lg-scene .lg-ridge,
        .lg-scene .lg-ridge-2,
        .lg-scene .lg-lake { display: none; }
        .lg-scene .lyra-art { position: absolute; inset: 0; width: 100%; height: 100%; }
        .lg-moon {
            position: absolute; right: 6%; top: 8%; width: 320px; height: 320px; border-radius: 50%;
            background: radial-gradient(circle at 38% 34%, rgba(180,150,255,.42), rgba(108,58,248,.16) 46%, transparent 68%);
            filter: blur(2px);
        }
        .lg-aurora {
            position: absolute; inset: 0;
            background:
              radial-gradient(680px 320px at 22% 78%, rgba(108,58,248,.34), transparent 62%),
              radial-gradient(520px 260px at 78% 62%, rgba(155,92,255,.22), transparent 64%);
            mix-blend-mode: screen;
        }
        .lg-ridge {
            position: absolute; left: -8%; right: -8%; bottom: 0; height: 46%;
            background: linear-gradient(180deg, #2A1470 0%, #170A3E 60%, #0A0520 100%);
            clip-path: polygon(0% 62%, 9% 40%, 18% 55%, 28% 26%, 38% 48%, 47% 22%, 57% 44%, 68% 18%, 78% 42%, 88% 30%, 100% 52%, 100% 100%, 0% 100%);
        }
        .lg-ridge-2 {
            position: absolute; left: -8%; right: -8%; bottom: 0; height: 30%;
            background: linear-gradient(180deg, #3A1E8C 0%, #1C0C4A 70%, #0A0520 100%);
            clip-path: polygon(0% 78%, 12% 52%, 24% 70%, 36% 44%, 50% 68%, 62% 40%, 74% 64%, 86% 48%, 100% 72%, 100% 100%, 0% 100%);
            opacity: .85;
        }
        .lg-lake {
            position: absolute; left: 0; right: 0; bottom: 0; height: 18%;
            background: linear-gradient(180deg, rgba(108,58,248,.30), rgba(10,5,32,.9));
            box-shadow: inset 0 40px 60px -30px rgba(155,92,255,.5);
        }
        .lg-left > *:not(.lg-scene) { position: relative; z-index: 2; }

        .lg-h1 { font-size: clamp(30px, 3.4vw, 46px); line-height: 1.12; letter-spacing: -0.035em; margin: 0 0 18px; }
        .lg-feats { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 18px; max-width: 560px; margin-top: 40px; }
        .lg-feat { text-align: left; }
        .lg-feat .ly-tile { margin-bottom: 10px; }
        .lg-feat b { display: block; font-size: 12.5px; }
        .lg-feat span { font-size: 11px; color: var(--ly-text-4); }

        /* Right: auth card */
        .lg-right {
            display: flex; align-items: center; justify-content: center;
            padding: 40px 24px; position: relative;
            background: linear-gradient(135deg, rgba(108,58,248,.06), rgba(2,9,26,0) 60%), var(--ly-bg);
        }
        .lg-card {
            width: 100%; max-width: 460px;
            background: rgba(3,10,26,.72);
            border: 1px solid var(--ly-border-2);
            border-radius: var(--ly-r-2xl);
            padding: 38px 36px;
            box-shadow: 0 32px 80px rgba(0,0,0,.55);
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
        }
        .lg-toplink { position: absolute; top: 22px; right: 28px; font-size: 13px; color: var(--ly-text-3); z-index: 3; }
        .lg-toplink a { font-weight: 600; }
        .lg-pass-wrap { position: relative; }
        .lg-pass-wrap .ly-input { padding-right: 46px; }
        .lg-reveal {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            background: transparent; border: 0; color: var(--ly-text-4); cursor: pointer; padding: 6px;
        }
        .lg-reveal:hover { color: var(--ly-text-2); }
        .lg-divider { display: flex; align-items: center; gap: 14px; margin: 22px 0; color: var(--ly-text-4); font-size: 12px; }
        .lg-divider::before, .lg-divider::after { content: ""; flex: 1; height: 1px; background: var(--ly-border); }
        .lg-sso { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .lg-sso .ly-btn { padding: 11px 8px; font-size: 12.5px; gap: 8px; }
        .lg-sso .ly-btn[disabled] { opacity: .48; }
        .lg-msg { margin-top: 14px; font-size: 13px; min-height: 18px; }
        .lg-msg.err { color: #FCA5A5; }
        .lg-msg.ok  { color: #6EE7A0; }
        .lg-msg.info{ color: var(--ly-text-2); }
        .lg-panel[hidden] { display: none; }
        .lg-note { display: flex; gap: 10px; align-items: center; margin-top: 22px; font-size: 11.5px; color: var(--ly-text-4); }

        @media (max-width: 1000px) {
            .lg-split { grid-template-columns: minmax(0,1fr); }
            .lg-left { display: none; }
            .lg-right { min-height: 100vh; }
        }
        @media (max-width: 520px) {
            .lg-card { padding: 26px 20px; }
            .lg-toplink { position: static; text-align: right; margin-bottom: 12px; }
        }
    </style>
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body class="ly">

<div class="lg-split">

    <!-- ══ LEFT: brand / artwork ══ -->
    <section class="lg-left">
        <div class="lg-scene" aria-hidden="true"><?php echo lyra_art_landscape('hero', 'lg'); ?></div>

        <a class="ly-logo" href="/" style="color:#fff">
            <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
            <span>Lyralink<br><span style="font-size:11.5px;font-weight:500;color:rgba(255,255,255,.62);letter-spacing:.02em">Next-Gen AI Infrastructure</span></span>
        </a>

        <div>
            <h1 class="lg-h1">
                Smarter tools.<br>
                <span class="ly-grad-text">Bigger possibilities.</span>
            </h1>
            <p style="max-width:520px;font-size:15px;color:rgba(255,255,255,.72);line-height:1.7">
                Lyralink is your personal and enterprise AI infrastructure platform.
                Automate, build, research, and manage your digital world &mdash; all in one place.
            </p>

            <div class="lg-feats">
                <?php
                $feats = [
                    ['brain',  'AI Agents',  'Work for you'],
                    ['bolt',   'Automation', 'Save time'],
                    ['shield', 'Secure',     'Your data'],
                    ['cloud',  'Any Device', 'Always with you'],
                ];
                $fi = [
                    'brain'  => '<path d="M12 5a3 3 0 0 0-3 3 3 3 0 0 0-3 3 3 3 0 0 0 1 5 3 3 0 0 0 5 2V5Z"/><path d="M12 5a3 3 0 0 1 3 3 3 3 0 0 1 3 3 3 3 0 0 1-1 5 3 3 0 0 1-5 2"/>',
                    'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
                    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z"/>',
                    'cloud'  => '<path d="M7 18a4 4 0 0 1 0-8 5.5 5.5 0 0 1 10.6 1.5A3.5 3.5 0 0 1 17 18H7Z"/>',
                ];
                foreach ($feats as $f): ?>
                <div class="lg-feat">
                    <div class="ly-tile"><svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><?php echo $fi[$f[0]]; ?></svg></div>
                    <b style="color:#fff"><?php echo $f[1]; ?></b>
                    <span><?php echo $f[2]; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="font-size:11.5px;color:rgba(255,255,255,.5)">
            Built for creators, developers, businesses, and dreamers.
        </div>
    </section>

    <!-- ══ RIGHT: auth ══ -->
    <section class="lg-right">
        <div class="lg-toplink">
            <a href="/pages/landing.php" style="margin-right:14px">&larr; New UI home</a>
            Don&rsquo;t have an account?
            <a href="#" id="toRegister">Sign Up
                <svg class="ly-ico ly-ico-sm" style="vertical-align:-3px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>

        <div class="lg-card">
            <div class="ly-row ly-mb-6" style="gap:12px">
                <img src="/images/lyralinklogobolt.png" alt="Lyralink" style="width:44px;height:44px;border-radius:11px">
                <span style="font-size:24px;font-weight:800;letter-spacing:-.035em">Lyralink</span>
            </div>

            <!-- ── SIGN IN ── -->
            <div class="lg-panel" id="panelSignin">
                <h2 style="font-size:24px;margin-bottom:6px">Welcome back</h2>
                <p style="font-size:13.5px;color:var(--ly-text-3);margin-bottom:24px">Sign in to your account to continue.</p>

                <form id="loginForm" method="post" action="/api/auth.php" autocomplete="on" novalidate>
                    <input type="hidden" name="action" value="login">
                    <label class="ly-field">
                        <span class="ly-label">Email Address</span>
                        <span class="ly-input-icon">
                            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            <input class="ly-input" type="email" id="loginEmail" name="email" placeholder="you@domain.com" autocomplete="username" required>
                        </span>
                    </label>

                    <label class="ly-field">
                        <span class="ly-label">Password</span>
                        <span class="lg-pass-wrap ly-input-icon">
                            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                            <input class="ly-input" type="password" id="loginPass" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button class="lg-reveal" type="button" id="togglePass" aria-label="Show password">
                                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </span>
                    </label>

                    <div class="ly-row-between ly-mb-5" style="margin-top:-4px">
                        <label class="ly-check"><input type="checkbox" id="rememberEmail" name="remember_email"> Remember me</label>
                        <a href="#" id="toForgot" style="font-size:13px">Forgot password?</a>
                    </div>

                    <button class="ly-btn ly-btn-primary ly-btn-block ly-btn-lg" type="submit" id="loginBtn">
                        Sign In
                        <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                    <div class="lg-msg" id="signinMsg" role="status" aria-live="polite"></div>
                </form>

                <div class="lg-divider">Or continue with</div>

                <div class="lg-sso">
                    <button class="ly-btn" type="button" disabled title="Not configured yet" aria-disabled="true">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" aria-hidden="true"><path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.4-1.7 4.1-5.5 4.1A6.2 6.2 0 0 1 12 5.8c1.8 0 2.9.7 3.6 1.4l2.6-2.5A9.5 9.5 0 0 0 12 2a10 10 0 1 0 0 20c5.8 0 9.6-4 9.6-9.8 0-.7-.1-1.2-.2-1.7H12Z"/></svg>
                        Google
                    </button>
                    <button class="ly-btn" type="button" disabled title="Not configured yet" aria-disabled="true">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.4-2.2-.2-4.6-1.1-4.6-5 0-1.1.4-2 1-2.7-.1-.2-.4-1.3.1-2.6 0 0 .8-.3 2.7 1a9.4 9.4 0 0 1 5 0c1.9-1.3 2.7-1 2.7-1 .5 1.3.2 2.4.1 2.6.6.7 1 1.6 1 2.7 0 3.9-2.4 4.8-4.6 5 .4.3.7.9.7 1.9v2.8c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>
                        GitHub
                    </button>
                    <button class="ly-btn" type="button" disabled title="Not configured yet" aria-disabled="true">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="9.5" height="9.5" fill="#F25022"/><rect x="12.5" y="2" width="9.5" height="9.5" fill="#7FBA00"/><rect x="2" y="12.5" width="9.5" height="9.5" fill="#00A4EF"/><rect x="12.5" y="12.5" width="9.5" height="9.5" fill="#FFB900"/></svg>
                        Microsoft
                    </button>
                </div>

                <div class="lg-note">
                    <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                    Your data is protected with industry-standard encryption and security measures.
                </div>
            </div>

            <!-- ── TWO-FACTOR ── -->
            <div class="lg-panel" id="panel2fa" hidden>
                <h2 style="font-size:22px;margin-bottom:6px">Two-factor verification</h2>
                <p style="font-size:13.5px;color:var(--ly-text-3);margin-bottom:22px">
                    Enter the 6-digit code from your authenticator app, or use a recovery code.
                </p>
                <form id="faForm" autocomplete="off" novalidate>
                    <label class="ly-field">
                        <span class="ly-label">Verification code</span>
                        <input class="ly-input ly-mono" type="text" id="faCode" inputmode="numeric" placeholder="000000" autocomplete="one-time-code" style="letter-spacing:.28em">
                    </label>
                    <button class="ly-btn ly-btn-primary ly-btn-block" type="submit" id="faBtn">Verify</button>
                    <div class="ly-row ly-mt-5" style="gap:10px">
                        <button class="ly-btn ly-btn-quiet ly-btn-sm" type="button" id="faRecoveryToggle">Use a recovery code</button>
                        <span class="ly-spacer"></span>
                        <button class="ly-btn ly-btn-quiet ly-btn-sm" type="button" id="faBack">Back to sign in</button>
                    </div>
                    <div class="lg-msg" id="faMsg" role="status" aria-live="polite"></div>
                </form>
            </div>

            <!-- ── FORGOT PASSWORD ── -->
            <div class="lg-panel" id="panelForgot" hidden>
                <h2 style="font-size:22px;margin-bottom:6px">Reset your password</h2>
                <p style="font-size:13.5px;color:var(--ly-text-3);margin-bottom:22px">
                    Enter your email and we&rsquo;ll send a reset code.
                </p>
                <form id="forgotForm" novalidate>
                    <label class="ly-field">
                        <span class="ly-label">Email Address</span>
                        <input class="ly-input" type="email" id="forgotEmail" placeholder="you@domain.com" autocomplete="username">
                    </label>
                    <button class="ly-btn ly-btn-primary ly-btn-block" type="submit" id="forgotBtn">Send reset code</button>
                    <div class="ly-row ly-mt-5">
                        <button class="ly-btn ly-btn-quiet ly-btn-sm" type="button" id="forgotBack">Back to sign in</button>
                    </div>
                    <div class="lg-msg" id="forgotMsg" role="status" aria-live="polite"></div>
                </form>
            </div>

            <!-- ── REGISTER ── -->
            <div class="lg-panel" id="panelRegister" hidden>
                <h2 style="font-size:24px;margin-bottom:6px">Create your account</h2>
                <p style="font-size:13.5px;color:var(--ly-text-3);margin-bottom:22px">Free to start. No credit card required.</p>
                <form id="registerForm" novalidate>
                    <label class="ly-field">
                        <span class="ly-label">Username</span>
                        <input class="ly-input" type="text" id="regUsername" placeholder="yourname" autocomplete="username">
                    </label>
                    <label class="ly-field">
                        <span class="ly-label">Email Address</span>
                        <input class="ly-input" type="email" id="regEmail" placeholder="you@domain.com" autocomplete="email">
                    </label>
                    <label class="ly-field">
                        <span class="ly-label">Password</span>
                        <input class="ly-input" type="password" id="regPass" placeholder="Create a password" autocomplete="new-password">
                    </label>
                    <button class="ly-btn ly-btn-primary ly-btn-block ly-btn-lg" type="submit" id="regBtn">Create Account</button>
                    <div class="ly-row ly-mt-5">
                        <button class="ly-btn ly-btn-quiet ly-btn-sm" type="button" id="regBack">Back to sign in</button>
                    </div>
                    <div class="lg-msg" id="regMsg" role="status" aria-live="polite"></div>
                </form>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
  "use strict";
  var API = "/api/auth.php";

  function $(id) { return document.getElementById(id); }
  function show(id) {
    ["panelSignin", "panel2fa", "panelForgot", "panelRegister"].forEach(function (p) {
      var el = $(p); if (el) el.hidden = (p !== id);
    });
  }
  function say(elId, text, kind) {
    var el = $(elId);
    if (!el) return;
    el.className = "lg-msg " + (kind || "info");
    el.textContent = text || "";
  }
  function busy(btn, on, label) {
    if (!btn) return;
    btn.disabled = !!on;
    if (on) { btn.dataset._t = btn.innerHTML; btn.textContent = label || "Working…"; }
    else if (btn.dataset._t) { btn.innerHTML = btn.dataset._t; }
  }
  function fd(obj) {
    var f = new FormData();
    Object.keys(obj).forEach(function (k) { f.append(k, obj[k]); });
    return f;
  }
  function post(obj) {
    return fetch(API, { method: "POST", body: fd(obj), credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, error: "Network error. Please try again." }; });
  }

  // ── password reveal ──
  var reveal = $("togglePass");
  if (reveal) reveal.addEventListener("click", function () {
    var p = $("loginPass");
    if (!p) return;
    var showing = p.type === "text";
    p.type = showing ? "password" : "text";
    reveal.setAttribute("aria-label", showing ? "Show password" : "Hide password");
  });

  // ── remembered email (client-side only; the API has no remember field) ──
  try {
    var saved = localStorage.getItem("lyra_email");
    if (saved && $("loginEmail")) { $("loginEmail").value = saved; if ($("rememberEmail")) $("rememberEmail").checked = true; }
  } catch (e) {}

  // ── navigation between panels ──
  if ($("toForgot"))     $("toForgot").addEventListener("click", function (e) { e.preventDefault(); show("panelForgot"); say("forgotMsg", ""); });
  if ($("forgotBack"))   $("forgotBack").addEventListener("click", function () { show("panelSignin"); });
  if ($("toRegister"))   $("toRegister").addEventListener("click", function (e) { e.preventDefault(); show("panelRegister"); say("regMsg", ""); });
  if ($("regBack"))      $("regBack").addEventListener("click", function () { show("panelSignin"); });
  if ($("faBack"))       $("faBack").addEventListener("click", function () { show("panelSignin"); });
  if ($("faRecoveryToggle")) $("faRecoveryToggle").addEventListener("click", function () {
    var c = $("faCode");
    var usingRecovery = c.dataset.mode === "recovery";
    c.dataset.mode = usingRecovery ? "code" : "recovery";
    c.placeholder = usingRecovery ? "000000" : "Recovery code";
    c.style.letterSpacing = usingRecovery ? ".28em" : "normal";
    this.textContent = usingRecovery ? "Use a recovery code" : "Use authenticator code";
    c.value = ""; c.focus();
  });

  // ── sign in ──
  var lf = $("loginForm");
  if (lf) lf.addEventListener("submit", function (e) {
    e.preventDefault();
    var email = ($("loginEmail").value || "").trim();
    var pass  = $("loginPass").value || "";
    if (!email || email.indexOf("@") === -1) { say("signinMsg", "Enter a valid email address.", "err"); return; }
    if (!pass) { say("signinMsg", "Enter your password.", "err"); return; }

    busy($("loginBtn"), true, "Signing in…");
    say("signinMsg", "");

    post({ action: "login", email: email, password: pass }).then(function (d) {
      busy($("loginBtn"), false);

      if (d.success) {
        try {
          if ($("rememberEmail") && $("rememberEmail").checked) localStorage.setItem("lyra_email", email);
          else localStorage.removeItem("lyra_email");
        } catch (e) {}
        say("signinMsg", "Signed in. Loading your workspace…", "ok");
        window.location.href = "/chat";
        return;
      }
      if (d.requires_2fa) { show("panel2fa"); say("faMsg", "", "info"); $("faCode").focus(); return; }
      if (d.requires_email_verification) { say("signinMsg", d.error || "Please verify your email before signing in.", "err"); return; }
      if (d.requires_password_change) { say("signinMsg", d.error || "You must set a new password before continuing.", "err"); return; }
      say("signinMsg", d.error || "Sign in failed.", "err");
    });
  });

  // ── 2FA ──
  var ff = $("faForm");
  if (ff) ff.addEventListener("submit", function (e) {
    e.preventDefault();
    var c = $("faCode");
    var val = (c.value || "").trim();
    if (!val) { say("faMsg", "Enter your code.", "err"); return; }
    var payload = (c.dataset.mode === "recovery")
      ? { action: "verify_2fa", recovery_code: val }
      : { action: "verify_2fa", code: val };

    busy($("faBtn"), true, "Verifying…");
    say("faMsg", "");
    post(payload).then(function (d) {
      busy($("faBtn"), false);
      if (d.success) { say("faMsg", "Verified. Loading your workspace…", "ok"); window.location.href = "/chat"; return; }
      say("faMsg", d.error || "Verification failed.", "err");
    });
  });

  // ── forgot password ──
  var gf = $("forgotForm");
  if (gf) gf.addEventListener("submit", function (e) {
    e.preventDefault();
    var email = ($("forgotEmail").value || "").trim();
    if (!email || email.indexOf("@") === -1) { say("forgotMsg", "Enter a valid email address.", "err"); return; }
    busy($("forgotBtn"), true, "Sending…");
    say("forgotMsg", "");
    post({ action: "request_password_reset", email: email }).then(function (d) {
      busy($("forgotBtn"), false);
      say("forgotMsg", d.error || "If that address exists, a reset code is on its way.", d.error ? "err" : "ok");
    });
  });

  // ── register ──
  var rf = $("registerForm");
  if (rf) rf.addEventListener("submit", function (e) {
    e.preventDefault();
    var u = ($("regUsername").value || "").trim();
    var em = ($("regEmail").value || "").trim();
    var pw = $("regPass").value || "";
    if (!u) { say("regMsg", "Choose a username.", "err"); return; }
    if (!em || em.indexOf("@") === -1) { say("regMsg", "Enter a valid email address.", "err"); return; }
    if (pw.length < 6) { say("regMsg", "Password must be at least 6 characters.", "err"); return; }

    busy($("regBtn"), true, "Creating account…");
    say("regMsg", "");
    post({ action: "register", username: u, email: em, password: pw }).then(function (d) {
      busy($("regBtn"), false);
      if (d.success) { say("regMsg", d.message || "Account created. Check your email to verify, then sign in.", "ok"); return; }
      say("regMsg", d.error || "Could not create the account.", "err");
    });
  });
})();
</script>

</body>
</html>
