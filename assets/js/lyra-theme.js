/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — THEME AND NOTIFICATIONS
   ══════════════════════════════════════════════════════════════════════════
   Loaded WITHOUT defer, as the last script in <head>, so the theme attribute is
   set before first paint. A deferred script would let the page paint in the
   wrong theme first, which is the flash this exists to prevent.

   window.LyraTheme
       toggle()            dark <-> light, and remembers the choice
       set(theme)          'dark' | 'light'
       current()           what is on screen right now
       clear()             forget the choice, back to the OS preference

   window.LyraNotify
       toggle()            opens/closes the panel under the bell
       close()

   The default when nobody has chosen is the operating system preference, which
   assets/css/lyra-ui.css handles in pure CSS via prefers-color-scheme. That path
   needs no JavaScript at all, so it cannot flash. This file only takes over for
   a deliberate choice.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var KEY = 'lyralink-theme';
    var DARK = 'dark', LIGHT = 'light';
    var root = document.documentElement;

    /* ── storage, guarded ─────────────────────────────────────────────────────
       localStorage throws in some private-browsing modes. A failure here must
       degrade to "no remembered choice", never to a broken page. */
    function read() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }
    function write(v) {
        try { localStorage.setItem(KEY, v); } catch (e) { /* not fatal */ }
    }
    function forget() {
        try { localStorage.removeItem(KEY); } catch (e) { /* not fatal */ }
    }

    function osPrefers() {
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches)
            ? LIGHT : DARK;
    }

    function current() {
        var attr = root.getAttribute('data-theme');
        if (attr === LIGHT || attr === DARK) { return attr; }
        return osPrefers();
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        // Keeps native form controls and scrollbars in step with the page.
        root.style.colorScheme = theme;
        dispatch(theme);
    }

    function dispatch(theme) {
        try {
            document.dispatchEvent(new CustomEvent('lyralink:theme', { detail: { theme: theme } }));
        } catch (e) { /* older engines: no CustomEvent constructor */ }
    }

    function set(theme) {
        theme = (theme === LIGHT) ? LIGHT : DARK;
        apply(theme);
        write(theme);
        return theme;
    }

    /* Named distinctly from the notification toggle below. Both once shared the
     * name `toggle` in this one IIFE scope, and the LATER function declaration
     * won - so LyraTheme.toggle was really the notification toggle, which opened
     * the bell panel and returned undefined instead of switching the theme. Two
     * same-named function declarations in one scope is a silent collision, not a
     * syntax error. */
    function themeToggle() {
        return set(current() === DARK ? LIGHT : DARK);
    }

    function clear() {
        forget();
        root.removeAttribute('data-theme');
        root.style.colorScheme = osPrefers();
        dispatch(current());
    }

    /* Apply any stored choice immediately. An explicit choice beats the OS, and
       because this runs in <head> it happens before anything is painted. */
    var stored = read();
    if (stored === DARK || stored === LIGHT) {
        apply(stored);
    } else {
        root.style.colorScheme = osPrefers();
    }

    /* Follow the OS while no explicit choice has been made. */
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: light)');
        var onOsChange = function () {
            if (read() === null) {
                root.style.colorScheme = osPrefers();
                dispatch(osPrefers());
            }
        };
        if (mq.addEventListener) { mq.addEventListener('change', onOsChange); }
        else if (mq.addListener) { mq.addListener(onOsChange); }
    }

    window.LyraTheme = {
        toggle: themeToggle,
        set: set,
        current: current,
        clear: clear
    };

    /* ══ NOTIFICATIONS ══════════════════════════════════════════════════════
       The feed is real: api/status.php?action=get_status already returns
       `incidents` (currently unresolved) and `resolved` (the last 7 days), read
       from status_incidents. Nothing here is invented, and when both lists are
       empty the panel says so rather than showing sample rows. */
    var panel = null;
    var button = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function ago(iso) {
        var t = Date.parse(String(iso || '').replace(' ', 'T'));
        if (isNaN(t)) { return ''; }
        var s = Math.floor((Date.now() - t) / 1000);
        if (s < 0) { return 'scheduled'; }
        if (s < 60) { return 'just now'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
        if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
        return Math.floor(s / 86400) + 'd ago';
    }

    function impactClass(impact) {
        var i = String(impact || '').toLowerCase();
        if (i === 'critical' || i === 'major') { return 'is-bad'; }
        if (i === 'minor') { return 'is-warn'; }
        return 'is-info';
    }

    function buildPanel() {
        var el = document.createElement('div');
        el.className = 'ly-notify';
        el.id = 'lyraNotifyPanel';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Notifications');
        el.innerHTML =
            '<div class="ly-notify-head">'
          + '<b>Notifications</b>'
          + '<button type="button" class="ly-notify-x" aria-label="Close">&times;</button>'
          + '</div>'
          + '<div class="ly-notify-body" id="lyraNotifyBody">'
          + '<div class="ly-notify-empty">Loading&hellip;</div>'
          + '</div>'
          + '<div class="ly-notify-foot"><a href="/pages/status/">All system status &rarr;</a></div>';
        document.body.appendChild(el);
        el.querySelector('.ly-notify-x').addEventListener('click', close);
        return el;
    }

    function render(feed) {
        var body = document.getElementById('lyraNotifyBody');
        if (!body) { return; }
        var rows = [];

        (feed.incidents || []).forEach(function (i) {
            rows.push(
                '<a class="ly-notify-row ' + impactClass(i.impact) + '" href="/pages/status/">'
              + '<span class="ly-notify-title">' + esc(i.title || 'Service incident') + '</span>'
              + '<span class="ly-notify-meta">'
              +   esc(String(i.status || '').replace(/_/g, ' '))
              +   (i.impact ? ' &middot; ' + esc(i.impact) : '')
              +   ' &middot; ' + esc(ago(i.created_at))
              + '</span></a>');
        });
        (feed.resolved || []).forEach(function (i) {
            rows.push(
                '<a class="ly-notify-row is-ok" href="/pages/status/">'
              + '<span class="ly-notify-title">Resolved: ' + esc(i.title || 'Incident') + '</span>'
              + '<span class="ly-notify-meta">resolved &middot; ' + esc(ago(i.resolved_at || i.created_at)) + '</span></a>');
        });

        if (!rows.length) {
            body.innerHTML =
                '<div class="ly-notify-empty">'
              + '<b>No notifications yet.</b>'
              + '<span>Service incidents and account alerts will appear here.</span>'
              + '</div>';
            return;
        }
        body.innerHTML = rows.join('');
    }

    function load() {
        var body = document.getElementById('lyraNotifyBody');
        if (!body) { return; }
        fetch('/api/status.php?action=get_status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.success !== true) { throw new Error('bad payload'); }
                render(d);
            })
            .catch(function () {
                // A failed feed is reported, not papered over with fake rows.
                body.innerHTML =
                    '<div class="ly-notify-empty"><b>Could not load notifications.</b>'
                  + '<span>The status feed is unavailable right now.</span></div>';
            });
    }

    function open() {
        button = document.getElementById('lyraNotifyBtn')
              || document.querySelector('[aria-label="Notifications"]');
        if (!button) { return; }
        if (!panel) { panel = buildPanel(); }
        panel.classList.add('is-open');
        button.setAttribute('aria-expanded', 'true');
        load();
    }

    function close() {
        if (panel) { panel.classList.remove('is-open'); }
        if (button) { button.setAttribute('aria-expanded', 'false'); }
    }

    function notifyToggle() {
        if (panel && panel.classList.contains('is-open')) { close(); }
        else { open(); }
    }

    document.addEventListener('click', function (e) {
        if (!panel || !panel.classList.contains('is-open')) { return; }
        var t = e.target;
        if (panel.contains(t)) { return; }
        if (t && t.closest && t.closest('[aria-label="Notifications"]')) { return; }
        close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
    });

    window.LyraNotify = { toggle: notifyToggle, open: open, close: close };
})();
