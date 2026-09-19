/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK UI — INTERACTION LAYER
   ══════════════════════════════════════════════════════════════════════════
   Small, dependency-free behaviours for the design system. Deliberately
   vanilla: the project has no build step and avoids unnecessary dependencies,
   and none of these need a framework.

   Everything is progressive enhancement — every element works without JS
   (tabs, for example, are plain anchors with [hidden] panels).
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  /* ── Tabs: [data-ly-tabs] wraps .ly-tab buttons and .ly-tabpanel panels ── */
  function initTabs(root) {
    var tabs = root.querySelectorAll(".ly-tab");
    var panels = root.querySelectorAll(".ly-tabpanel");

    function activate(name) {
      tabs.forEach(function (t) {
        var on = t.getAttribute("data-tab") === name;
        t.classList.toggle("is-active", on);
        t.setAttribute("aria-selected", on ? "true" : "false");
      });
      panels.forEach(function (p) {
        p.hidden = p.getAttribute("data-panel") !== name;
      });
    }

    tabs.forEach(function (t) {
      t.addEventListener("click", function () {
        activate(t.getAttribute("data-tab"));
      });
    });

    var first = root.querySelector(".ly-tab.is-active") || tabs[0];
    if (first) activate(first.getAttribute("data-tab"));
  }

  /* ── Segmented control: [data-ly-seg] ── */
  function initSeg(root) {
    var buttons = root.querySelectorAll("button");
    var targetSel = root.getAttribute("data-target");

    buttons.forEach(function (b) {
      b.addEventListener("click", function () {
        buttons.forEach(function (o) { o.classList.remove("is-active"); });
        b.classList.add("is-active");
        // Optional: reveal a matching panel elsewhere in the page.
        if (targetSel) {
          var key = b.getAttribute("data-value");
          document.querySelectorAll(targetSel).forEach(function (p) {
            p.hidden = p.getAttribute("data-value") !== key;
          });
        }
      });
    });
  }

  /* ── Dropdown: [data-ly-drop] contains .ly-drop-trigger + .ly-drop-menu ── */
  function initDrop(root) {
    var trigger = root.querySelector(".ly-drop-trigger");
    var menu = root.querySelector(".ly-drop-menu");
    if (!trigger || !menu) return;

    trigger.setAttribute("aria-haspopup", "true");
    trigger.setAttribute("aria-expanded", "false");

    function close() {
      menu.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    }
    function open() {
      menu.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
    }

    trigger.addEventListener("click", function (e) {
      e.stopPropagation();
      if (menu.hidden) open(); else close();
    });
    document.addEventListener("click", close);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") close();
    });
    menu.addEventListener("click", function (e) { e.stopPropagation(); });
  }

  /* ── Copy to clipboard: [data-ly-copy] ── */
  function initCopy() {
    document.querySelectorAll("[data-ly-copy]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var text = btn.getAttribute("data-ly-copy");
        if (!text) return;
        var done = function () {
          var prev = btn.getAttribute("data-label") || btn.textContent;
          btn.setAttribute("data-label", prev);
          btn.textContent = "Copied";
          setTimeout(function () { btn.textContent = prev; }, 1400);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
          var ta = document.createElement("textarea");
          ta.value = text;
          ta.setAttribute("readonly", "");
          ta.style.position = "fixed";
          ta.style.opacity = "0";
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand("copy"); done(); } catch (err) {}
          document.body.removeChild(ta);
        }
      });
    });
  }

  /* ── Sidebar toggle for narrow viewports: [data-ly-sidebar-toggle] ── */
  function initSidebarToggle() {
    var btn = document.querySelector("[data-ly-sidebar-toggle]");
    if (!btn) return;
    btn.addEventListener("click", function () {
      var shell = document.querySelector(".ly-shell, .ly-shell-has-rail");
      if (!shell) return;
      var open = shell.classList.toggle("is-sidebar-open");
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  /* ── Reveal on scroll: [data-ly-reveal] ── */
  function initReveal() {
    var els = document.querySelectorAll("[data-ly-reveal]");
    if (!els.length || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* ── Ring progress: [data-ly-ring="0-100"] ── */
  function initRings() {
    document.querySelectorAll("[data-ly-ring]").forEach(function (el) {
      var pct = Math.max(0, Math.min(100, parseFloat(el.getAttribute("data-ly-ring")) || 0));
      var r = 36;
      var circ = 2 * Math.PI * r;
      var ns = "http://www.w3.org/2000/svg";
      var svg = document.createElementNS(ns, "svg");
      svg.setAttribute("width", "88");
      svg.setAttribute("height", "88");
      svg.setAttribute("viewBox", "0 0 88 88");
      svg.setAttribute("aria-hidden", "true");

      var track = document.createElementNS(ns, "circle");
      track.setAttribute("class", "ly-ring-track");
      track.setAttribute("cx", "44");
      track.setAttribute("cy", "44");
      track.setAttribute("r", String(r));

      var val = document.createElementNS(ns, "circle");
      val.setAttribute("class", "ly-ring-val");
      val.setAttribute("cx", "44");
      val.setAttribute("cy", "44");
      val.setAttribute("r", String(r));
      val.setAttribute("stroke-dasharray", String(circ));
      val.setAttribute("stroke-dashoffset", String(circ));
      if (el.getAttribute("data-ly-ring-color")) {
        val.style.stroke = el.getAttribute("data-ly-ring-color");
      }

      svg.appendChild(track);
      svg.appendChild(val);
      el.insertBefore(svg, el.firstChild);

      // animate after paint so the transition is visible
      requestAnimationFrame(function () {
        setTimeout(function () {
          val.setAttribute("stroke-dashoffset", String(circ * (1 - pct / 100)));
        }, 80);
      });
    });
  }

  /* ── Count-up: [data-ly-count="1234"] ── */
  function initCounts() {
    var els = document.querySelectorAll("[data-ly-count]");
    if (!els.length || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        io.unobserve(el);
        var raw = el.getAttribute("data-ly-count");
        var target = parseFloat(String(raw).replace(/[^0-9.]/g, "")) || 0;
        var suffix = String(raw).replace(/[0-9.,]/g, "");
        var decimals = (String(raw).split(".")[1] || "").replace(/[^0-9]/g, "").length;
        var start = performance.now();
        var dur = 900;
        function tick(now) {
          var p = Math.min(1, (now - start) / dur);
          var eased = 1 - Math.pow(1 - p, 3);
          var v = target * eased;
          var shown = decimals
            ? v.toFixed(decimals)
            : Math.round(v).toLocaleString();
          el.textContent = shown + suffix;
          if (p < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
      });
    }, { threshold: 0.4 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* ── Auto-grow textareas: [data-ly-autogrow] ── */
  function initAutogrow() {
    document.querySelectorAll("[data-ly-autogrow]").forEach(function (ta) {
      function resize() {
        ta.style.height = "auto";
        ta.style.height = Math.min(ta.scrollHeight, 220) + "px";
      }
      ta.addEventListener("input", resize);
      resize();
    });
  }

  ready(function () {
    document.querySelectorAll("[data-ly-tabs]").forEach(initTabs);
    document.querySelectorAll("[data-ly-seg]").forEach(initSeg);
    document.querySelectorAll("[data-ly-drop]").forEach(initDrop);
    initCopy();
    initSidebarToggle();
    initReveal();
    initRings();
    initCounts();
    initAutogrow();
  });
})();
