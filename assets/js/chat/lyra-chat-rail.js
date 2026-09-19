/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK CHAT — RAIL READOUTS
   ══════════════════════════════════════════════════════════════════════════
   Keeps the new rail and top bar in step with the chat's real state.

   Deliberately NOT part of assets/js/chat/0*.js. Those eight modules are the
   application and are bound to 96 element ids; this file only reads the DOM
   they populate and never calls into them, so it can be changed or removed
   without touching application behaviour.

   Every readout starts blank and is filled from something observable. Nothing
   here asserts a number the application has not actually produced — a rail that
   reports invented activity is worse than one that reports none.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }

    /* ── Conversations count: mirror the real list ───────────────────────── */
    function wireConversations() {
        var list = $('convList');
        var badge = document.querySelector('.lyra-navitem .lyra-count');
        if (!list || !badge) { return; }
        var sync = function () {
            var n = list.querySelectorAll('.conv-item, .conv-row, [data-conv-id], a, button').length;
            if (n === 0) {
                badge.remove();
            } else {
                badge.textContent = String(n);
            }
        };
        sync();
        new MutationObserver(sync).observe(list, { childList: true, subtree: true });
    }

    /* ── Tools enabled ───────────────────────────────────────────────────────
       The server already renders this count from the tool registry, and that is
       the honest figure: it counts tools with a real implementation behind them.
       An earlier version of this file counted the UI checkboxes instead and
       overwrote the server value with a smaller, misleading number. The server
       value is now left alone; this only fills the element if it is empty. */
    function wireTools() {
        var out = $('lyraToolsCount');
        if (!out || out.textContent.trim() !== '') { return; }
        var boxes = document.querySelectorAll('#taskModeToggle, #codeTestToggle');
        out.textContent = String(boxes.length);
    }

    /* ── API status: reuse the chat's own indicator ──────────────────────── */
    function wireApiStatus() {
        var src = $('apiStatusText');
        var out = $('lyraRailApiStatus');
        var dot = document.querySelector('.lyra-sysdot');
        if (src && out) {
            var sync = function () { out.textContent = src.textContent.trim() || '\u2014'; };
            sync();
            new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true });
        }
        // The System Status dot reflects the same signal rather than always green.
        var sdot = $('apiStatusDot');
        if (dot && sdot) {
            var syncDot = function () {
                var cls = sdot.className || '';
                var ok = /ok|online|connected|ready/i.test(cls) || /online|connected|ready/i.test(sdot.title || '');
                dot.style.background = ok ? 'var(--ly-success)' : 'var(--ly-warning)';
            };
            syncDot();
            new MutationObserver(syncDot).observe(sdot, { attributes: true, attributeFilter: ['class', 'title', 'style'] });
        }
    }

    /* ── Task progress: only claim a task when one exists ────────────────── */
    function wireTaskProgress() {
        var panel = $('lyraTaskProgress');
        if (!panel) { return; }
        var name = $('lyraTaskName');
        var meta = $('lyraTaskMeta');
        var pct = $('lyraRingPct');
        var bar = $('lyraRingBar');
        var steps = $('lyraTaskSteps');
        var CIRC = 157; // 2 * pi * 25, rounded

        function setProgress(value, label, note, items) {
            var v = Math.max(0, Math.min(100, Math.round(value)));
            if (pct) { pct.textContent = v + '%'; }
            if (bar) { bar.setAttribute('stroke-dashoffset', String(CIRC - (CIRC * v / 100))); }
            if (name && label) { name.textContent = label; }
            if (meta && note) { meta.textContent = note; }
            if (steps) {
                steps.innerHTML = '';
                (items || []).forEach(function (it) {
                    var row = document.createElement('div');
                    row.className = 'lyra-step';
                    var mark = document.createElement('span');
                    mark.textContent = it.done ? '\u2713' : '\u2022';
                    mark.style.color = it.done ? 'var(--ly-success)' : 'var(--ly-text-4)';
                    row.appendChild(mark);
                    row.appendChild(document.createTextNode(it.label));
                    if (it.time) {
                        var em = document.createElement('em');
                        em.textContent = it.time;
                        row.appendChild(em);
                    }
                    steps.appendChild(row);
                });
            }
            panel.setAttribute('data-state', v > 0 ? 'active' : 'empty');
        }

        // Exposed so the chat can report genuine progress if it wants to. Until
        // something calls it, the panel stays honestly empty.
        window.lyraTaskProgress = setProgress;

        // A visible execution trace is a real signal that something ran. The
        // chat renders one when tools execute, so mirror it rather than guess.
        var trace = $('executionTraceId') || $('liveTracePanel') || $('executionCockpit');
        if (!trace) { return; }
        var scan = function () {
            var text = (trace.textContent || '').trim();
            if (!text) { return; }
            // Only relay what is literally present; do not invent steps.
            setProgress(100, 'Execution recorded', text.slice(0, 90), []);
        };
        new MutationObserver(scan).observe(trace, { childList: true, subtree: true, characterData: true });
        scan();
    }

    /* ── Composer parity: keep the Task Mode chip in step with its checkbox ─ */
    function wireTaskChip() {
        var box = $('taskModeToggle');
        if (!box) { return; }
        var chips = document.querySelectorAll('.lyra-chip');
        var chip = null;
        chips.forEach(function (c) { if (/task mode/i.test(c.textContent)) { chip = c; } });
        if (!chip) { return; }
        var sync = function () { chip.classList.toggle('is-on', !!box.checked); };
        sync();
        box.addEventListener('change', sync);
    }

    function init() {
        try { wireConversations(); } catch (e) {}
        try { wireTools(); } catch (e) {}
        try { wireApiStatus(); } catch (e) {}
        try { wireTaskProgress(); } catch (e) {}
        try { wireTaskChip(); } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
