/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — CSRF TOKEN TRANSPORT
   ══════════════════════════════════════════════════════════════════════════
   The API already rejects cross-origin state-changing requests by checking
   Origin/Referer. This file adds the second, independent layer: a per-session
   token that the action itself must carry.

   How the token reaches the browser
   ---------------------------------
   session_boot.php publishes it in a readable cookie, LYRA_CSRF, alongside the
   session. That cookie is not a secret on its own — a request presenting it
   must also present the matching session — which is why it is safe for script
   to read.

   How it is sent
   --------------
   This patches fetch and XMLHttpRequest once, at load, so every same-origin
   non-GET request carries X-CSRF-Token automatically. That means no call site
   has to know about CSRF, and a newly added call site is covered by default
   rather than by remembering.

   Deliberately narrow:
     * same-origin only — a cross-origin request must not receive our token
     * non-GET/HEAD/OPTIONS only — a token on a read is pointless
     * never overwrites an existing X-CSRF-Token
     * does not touch the response at all, so streaming is unaffected
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    /* Read once, at load. Reading document.cookie at request time could pick up
     * a value some later script planted, which would defeat the point: the
     * token we send must be the one the server issued to this page load. */
    var TOKEN = (function () {
        var m = document.cookie.match(/(?:^|;\s*)LYRA_CSRF=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : '';
    })();

    if (!TOKEN) { return; }

    var SAFE = /^(GET|HEAD|OPTIONS|TRACE)$/i;

    function isSameOrigin(url) {
        if (!url) { return true; }            // relative or unspecified: same origin
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin;
        } catch (e) {
            return false;                      // unparseable: do not attach
        }
    }

    /* ── fetch ────────────────────────────────────────────────────────────── */
    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var method = (init && init.method) || (input && input.method) || 'GET';

            if (!SAFE.test(method) && isSameOrigin(url)) {
                init = init || {};
                try {
                    var headers = new Headers(init.headers || (input && input.headers) || {});
                    if (!headers.has('X-CSRF-Token')) {
                        headers.set('X-CSRF-Token', TOKEN);
                    }
                    init.headers = headers;
                } catch (e) {
                    /* Never let header construction break a working request. */
                }
            }
            return origFetch.call(this, input, init);
        };
    }

    /* ── XMLHttpRequest ───────────────────────────────────────────────────── */
    if (typeof XMLHttpRequest === 'function') {
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this.__lyraCsrf = !SAFE.test(String(method || 'GET')) && isSameOrigin(url);
            return origOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            if (this.__lyraCsrf) {
                try {
                    this.setRequestHeader('X-CSRF-Token', TOKEN);
                } catch (e) {
                    /* setRequestHeader throws if the request already started. */
                }
            }
            return origSend.apply(this, arguments);
        };
    }
})();
