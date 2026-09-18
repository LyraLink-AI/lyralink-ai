/*!
 * Lyralink Chat Widget
 * Embed: <script src="https://lyralinkai.com/assets/js/widget.js?v=20260912-2" data-ref="TOKEN" data-embed-mode="iframe"></script>
 * Safari defaults to inline iframe even if popup mode is requested.
 * Set data-safari-inline="0" to allow popup behavior on Safari.
 */
(function () {
    'use strict';

    var script = document.currentScript ||
        (function () {
            var scripts = document.getElementsByTagName('script');
            return scripts[scripts.length - 1];
        })();

    var ref    = (script && script.dataset.ref)    || '';
    var accent = (script && script.dataset.accent) || '#7c3aed';
    var label  = (script && script.dataset.label)  || 'Chat with us';
    var base   = (function () {
      var explicitBase = (script && script.dataset.base) || '';
      if (explicitBase) {
        try {
          return new URL(explicitBase, window.location.href).origin;
        } catch (_) {}
      }
      if (script && script.src) {
        try {
          return new URL(script.src, window.location.href).origin;
        } catch (_) {}
      }
      return 'https://lyralinkai.com';
    })();
    var userAgent = (window.navigator && window.navigator.userAgent) ? window.navigator.userAgent : '';
    var isAppleWebKit = /AppleWebKit/i.test(userAgent) && /Macintosh|iPhone|iPad|iPod/i.test(userAgent) && !/Chrome|Chromium|CriOS|Edg|OPR|Firefox|FxiOS/i.test(userAgent);
    var embedMode = String(((script && script.dataset.embedMode) || 'iframe')).toLowerCase();
    var safariInlineSetting = String((script && script.dataset.safariInline) || '1').toLowerCase();
    var forceInlineOnSafari = safariInlineSetting !== '0' && safariInlineSetting !== 'false' && safariInlineSetting !== 'no';
    var canUseIframeEmbed = embedMode !== 'popup' || (isAppleWebKit && forceInlineOnSafari);

    // Derive a slightly lighter version of accent for the gradient
    var accentAlpha = accent.replace('#', '');
    var r = parseInt(accentAlpha.substring(0,2), 16);
    var g = parseInt(accentAlpha.substring(2,4), 16);
    var b = parseInt(accentAlpha.substring(4,6), 16);
    var accentGlow = 'rgba(' + r + ',' + g + ',' + b + ',0.35)';
    var accentFaint = 'rgba(' + r + ',' + g + ',' + b + ',0.12)';

    // ── Styles ──────────────────────────────────────────────────────────────
    var css = '\
#ll-widget-launcher{\
  position:fixed;bottom:24px;right:24px;z-index:2147483646;\
  display:flex;align-items:center;gap:10px;\
  cursor:pointer;border:none;background:none;padding:0;\
}\
#ll-widget-launcher-pill{\
  background:' + accent + ';\
  color:#fff;\
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;\
  font-size:13px;font-weight:600;letter-spacing:0.01em;\
  padding:0 18px;\
  height:44px;border-radius:22px;\
  display:flex;align-items:center;gap:8px;\
  box-shadow:0 4px 18px ' + accentGlow + ';\
  transition:transform 0.2s,box-shadow 0.2s;\
  white-space:nowrap;\
}\
#ll-widget-launcher:hover #ll-widget-launcher-pill{\
  transform:translateY(-2px);\
  box-shadow:0 8px 28px ' + accentGlow + ';\
}\
#ll-widget-launcher-pill svg{width:16px;height:16px;fill:#fff;flex-shrink:0}\
\
#ll-widget-panel{\
  position:fixed;bottom:84px;right:24px;z-index:2147483645;\
  width:380px;\
  border-radius:20px;\
  overflow:hidden;\
  box-shadow:0 20px 60px rgba(0,0,0,0.4),0 0 0 1px rgba(255,255,255,0.06);\
  opacity:0;pointer-events:none;\
  transform:translateY(16px) scale(0.97);\
  transform-origin:bottom right;\
  transition:opacity 0.22s ease,transform 0.22s ease;\
  display:flex;flex-direction:column;will-change:transform,opacity;\
}\
#ll-widget-panel.open{\
  opacity:1;pointer-events:auto;\
  transform:translateY(0) scale(1) translateZ(0);\
}\
#ll-widget-header{\
  background:' + accent + ';\
  padding:14px 16px;\
  display:flex;align-items:center;gap:10px;\
  flex-shrink:0;\
}\
#ll-widget-header-dot{\
  width:8px;height:8px;border-radius:50%;\
  background:rgba(255,255,255,0.7);\
  box-shadow:0 0 0 2px rgba(255,255,255,0.2);\
  animation:ll-pulse 2s infinite;\
}\
@keyframes ll-pulse{\
  0%,100%{opacity:1}50%{opacity:0.45}\
}\
#ll-widget-header-title{\
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;\
  font-size:13px;font-weight:600;color:#fff;flex:1;\
  letter-spacing:0.01em;\
}\
#ll-widget-header-sub{\
  font-size:11px;color:rgba(255,255,255,0.7);margin-top:1px;\
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;\
}\
#ll-widget-close{\
  width:28px;height:28px;border-radius:50%;\
  background:rgba(255,255,255,0.15);border:none;cursor:pointer;\
  display:flex;align-items:center;justify-content:center;\
  transition:background 0.15s;\
  flex-shrink:0;\
}\
#ll-widget-close:hover{background:rgba(255,255,255,0.28)}\
#ll-widget-close svg{width:14px;height:14px;stroke:#fff;fill:none}\
\
#ll-widget-body{position:relative;flex:1;background:#0a0a0f;}\
#ll-widget-panel.apple-friendly{backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}\
#ll-widget-panel.apple-friendly #ll-widget-frame{-webkit-transform:translateZ(0);transform:translateZ(0)}\
#ll-widget-fallback{\
  padding:18px 16px 16px;display:flex;flex-direction:column;gap:12px;\
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;\
  color:rgba(255,255,255,0.84);background:#0a0a0f;\
}\
#ll-widget-fallback p{margin:0;font-size:13px;line-height:1.6;color:rgba(255,255,255,0.64)}\
#ll-widget-fallback-btn{\
  border:none;border-radius:12px;padding:12px 14px;cursor:pointer;\
  background:' + accent + ';color:#fff;font-size:13px;font-weight:600;\
  box-shadow:0 8px 22px ' + accentGlow + ';\
}\
#ll-widget-fallback-note{font-size:11px;color:rgba(255,255,255,0.42)}\
#ll-widget-frame{\
  width:100%;height:520px;border:none;display:block;\
}\
#ll-widget-loading{\
  position:absolute;inset:0;\
  background:#0a0a0f;\
  display:flex;flex-direction:column;\
  align-items:center;justify-content:center;\
  gap:12px;\
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;\
  font-size:13px;color:rgba(255,255,255,0.45);\
}\
#ll-widget-loading-ring{\
  width:32px;height:32px;border-radius:50%;\
  border:2px solid ' + accentFaint + ';\
  border-top-color:' + accent + ';\
  animation:ll-spin 0.8s linear infinite;\
}\
@keyframes ll-spin{to{transform:rotate(360deg)}}\
\
@media(max-width:480px){\
  #ll-widget-panel{\
    right:0;bottom:0;left:0;\
    width:100%;border-radius:20px 20px 0 0;\
    transform:translateY(100%);\
  }\
  #ll-widget-panel.open{transform:translateY(0)}\
  #ll-widget-launcher{bottom:16px;right:16px}\
  #ll-widget-frame{height:65vh}\
}';

    var styleEl = document.createElement('style');
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    // ── SVGs ─────────────────────────────────────────────────────────────────
    var svgChat = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
        + '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
        + '</svg>';

    var svgClose = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" stroke-width="2.2" stroke-linecap="round">'
        + '<path d="M18 6 6 18M6 6l12 12"/>'
        + '</svg>';

    // ── Build DOM ─────────────────────────────────────────────────────────────
    // Launcher button
    var launcher = document.createElement('button');
    launcher.id = 'll-widget-launcher';
    launcher.setAttribute('aria-label', 'Open chat');
    launcher.innerHTML = '<div id="ll-widget-launcher-pill">' + svgChat + '<span>' + label + '</span></div>';

    // Panel
    var panel = document.createElement('div');
    panel.id = 'll-widget-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Chat');
    if (isAppleWebKit) {
      panel.className = 'apple-friendly';
    }

    var header = document.createElement('div');
    header.id = 'll-widget-header';
    header.innerHTML = ''
        + '<div id="ll-widget-header-dot"></div>'
        + '<div><div id="ll-widget-header-title">' + label + '</div>'
        + '<div id="ll-widget-header-sub">We typically reply instantly</div></div>'
        + '<button id="ll-widget-close" aria-label="Close chat">' + svgClose + '</button>';

    var body = document.createElement('div');
    body.id = 'll-widget-body';

    var loading = document.createElement('div');
    loading.id = 'll-widget-loading';
    loading.innerHTML = '<div id="ll-widget-loading-ring"></div><span>Launching your assistant…</span>';

    var frameUrl = base + '/chat/?' + (ref ? 'ref=' + encodeURIComponent(ref) + '&' : '') + 'widget=1';
    var frame = document.createElement('iframe');
    frame.id = 'll-widget-frame';
    frame.setAttribute('allow', 'microphone; clipboard-write');
    frame.setAttribute('title', 'Lyralink Chat');

    var fallback = document.createElement('div');
    fallback.id = 'll-widget-fallback';
    fallback.innerHTML = ''
      + '<strong>Open secure chat</strong>'
      + '<p>Open chat in a dedicated window if you prefer popup mode for this site.</p>'
      + '<button type="button" id="ll-widget-fallback-btn">Open chat</button>'
      + '<div id="ll-widget-fallback-note">Popups blocked? The launcher will open the chat in a new tab.</div>';

    if (canUseIframeEmbed) {
      body.appendChild(loading);
      body.appendChild(frame);
    } else {
      body.appendChild(fallback);
    }
    panel.appendChild(header);
    panel.appendChild(body);

    document.body.appendChild(panel);
    document.body.appendChild(launcher);

    // Hide loading spinner once iframe has loaded
    frame.addEventListener('load', function () {
        loading.style.display = 'none';
    });

    // ── Toggle ───────────────────────────────────────────────────────────────
    var open = false;
    var frameLoaded = false;

    function openPopupWidget() {
      var popupName = 'lyralink_chat_widget';
      var popupFeatures = 'popup=yes,width=420,height=760,menubar=no,toolbar=no,location=yes,status=no,resizable=yes,scrollbars=yes';
      var popup = null;
      try {
        popup = window.open(frameUrl, popupName, popupFeatures);
      } catch (_) {
        popup = null;
      }
      if (popup && typeof popup.focus === 'function') {
        popup.focus();
        return;
      }
      window.open(frameUrl, '_blank', 'noopener');
    }

    function openWidget() {
        open = true;
        panel.classList.add('open');
        launcher.setAttribute('aria-label', 'Close chat');
        if (panel.querySelector('#ll-widget-close')) {
            panel.querySelector('#ll-widget-close').focus();
        }
        if (!frameLoaded) {
            frame.src = frameUrl;
            frameLoaded = true;
        }
    }

    function closeWidget() {
        open = false;
        panel.classList.remove('open');
        launcher.setAttribute('aria-label', 'Open chat');
    }

    launcher.addEventListener('click', function () {
      if (!canUseIframeEmbed) {
        openPopupWidget();
        return;
      }
      open ? closeWidget() : openWidget();
    });

    header.querySelector('#ll-widget-close').addEventListener('click', closeWidget);
    fallback.querySelector('#ll-widget-fallback-btn').addEventListener('click', openPopupWidget);

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && open) closeWidget();
    });

    // Allow iframe to close the widget via postMessage
    window.addEventListener('message', function (e) {
        if (!base) return;
        try {
            var origin = new URL(base).origin;
            if (e.origin !== origin) return;
        } catch (_) {
            return;
        }
        if (e.data === 'lyralink:close') closeWidget();
    });
})();
