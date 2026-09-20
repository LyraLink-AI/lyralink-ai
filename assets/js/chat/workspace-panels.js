/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — WORKSPACE PANELS  (Files / Projects)
   ══════════════════════════════════════════════════════════════════════════
   Reads and writes api/workspace.php. Every request is same-origin and carries
   the CSRF token automatically, because chat.php loads assets/js/lyra-csrf.js
   in <head> before this file.

   Nothing here decides access. The server scopes every query to the session
   user, so a row this file should not see is never sent to it in the first
   place; the UI is a view, not a guard.

   Empty states are honest: they appear when the list is genuinely empty, and a
   failed request says so rather than looking like "no data".
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var MAX_BYTES = 25 * 1024 * 1024;

    function $(id) { return document.getElementById(id); }

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (s) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s];
        });
    }

    function bytes(n) {
        n = Number(n || 0);
        if (n < 1024) { return n + ' B'; }
        if (n < 1024 * 1024) { return (n / 1024).toFixed(1) + ' KB'; }
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function when(s) {
        var t = Date.parse(String(s || '').replace(' ', 'T'));
        if (isNaN(t)) { return ''; }
        var d = Math.floor((Date.now() - t) / 1000);
        if (d < 60) { return 'just now'; }
        if (d < 3600) { return Math.floor(d / 60) + 'm ago'; }
        if (d < 86400) { return Math.floor(d / 3600) + 'h ago'; }
        return Math.floor(d / 86400) + 'd ago';
    }

    /* The active conversation, for "link this chat". The chat modules declare
       `activeConvId` at the top level of a classic script, so it is reachable as
       a bare global; the URL is a fallback because the page keeps ?c= in step. */
    function currentConvId() {
        try {
            if (typeof activeConvId !== 'undefined' && activeConvId) { return String(activeConvId); }
        } catch (e) { /* not declared */ }
        try {
            var m = String(location.search).match(/[?&]c=([^&]+)/);
            if (m) { return decodeURIComponent(m[1]); }
        } catch (e) { /* ignore */ }
        return '';
    }

    function call(action, data, method) {
        method = method || 'POST';
        var url = '/api/workspace.php';
        var opts = { method: method, credentials: 'same-origin' };
        if (method === 'GET') {
            url += '?' + new URLSearchParams(Object.assign({ action: action }, data || {})).toString();
        } else {
            var fd = new FormData();
            fd.append('action', action);
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
            opts.body = fd;
        }
        return fetch(url, opts).then(function (r) {
            return r.text().then(function (t) {
                var p = null;
                try { p = JSON.parse(t); } catch (e) { p = null; }
                if (p === null) { throw new Error('Unexpected reply from the server'); }
                if (p.success !== true) { throw new Error(p.error || 'Request failed'); }
                return p;
            });
        });
    }

    function note(el, msg, isError) {
        if (!el) { return; }
        el.textContent = msg || '';
        el.className = 'ws-note' + (isError ? ' is-error' : '');
    }

    /* ── PROJECTS ──────────────────────────────────────────────────────────── */

    function renderProjects(p) {
        var box = $('wsProjectList');
        var list = p.projects || [];
        if (!list.length) {
            box.innerHTML =
                '<div class="lyra-panelempty"><b>No projects yet.</b>'
              + '<span>Create one to group conversations, files and goals behind a single objective.</span></div>';
            return;
        }
        box.innerHTML = list.map(function (pr) {
            var archived = pr.status === 'archived';
            return '<div class="ws-item" data-id="' + esc(pr.id) + '">'
              + '<div class="ws-item-main">'
              +   '<div class="ws-item-title">' + esc(pr.name)
              +     (archived ? ' <span class="ly-badge">archived</span>' : '') + '</div>'
              +   (pr.description ? '<div class="ws-item-sub">' + esc(pr.description) + '</div>' : '')
              +   '<div class="ws-item-meta">' + esc(pr.conv_count) + ' conversation'
              +     (String(pr.conv_count) === '1' ? '' : 's') + ' &middot; '
              +     esc(pr.file_count) + ' file' + (String(pr.file_count) === '1' ? '' : 's')
              +     ' &middot; ' + esc(when(pr.updated_at)) + '</div>'
              + '</div>'
              + '<div class="ws-item-actions">'
              +   '<button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" data-act="link" data-id="' + esc(pr.id) + '">Link this chat</button>'
              +   '<button type="button" class="ly-btn ly-btn-ghost ly-btn-sm" data-act="archive" data-id="' + esc(pr.id) + '" data-status="' + (archived ? 'active' : 'archived') + '">' + (archived ? 'Restore' : 'Archive') + '</button>'
              +   '<button type="button" class="ly-btn ly-btn-ghost ly-btn-sm ws-danger" data-act="delete" data-id="' + esc(pr.id) + '">Delete</button>'
              + '</div></div>';
        }).join('');
    }

    /* The "attach to project" select is driven by the same list. It used to be
       filled once at load, so a project created afterwards could not be chosen
       without a reload - found by creating one in the UI and then looking at the
       dropdown. Refreshing it whenever projects load keeps the two in step. */
    function renderProjectOptions(p) {
        var sel = $('wsFileProject');
        if (!sel) { return; }
        var keep = sel.value;
        var opts = ['<option value="">No project</option>'];
        (p.projects || []).forEach(function (pr) {
            if (pr.status === 'archived') { return; }
            opts.push('<option value="' + esc(pr.id) + '">' + esc(pr.name) + '</option>');
        });
        sel.innerHTML = opts.join('');
        if (keep) { sel.value = keep; }
    }

    function loadProjects() {
        var box = $('wsProjectList');
        if (!box) { return Promise.resolve(); }
        return call('list_projects', {}, 'GET')
            .then(function (p) { renderProjects(p); renderProjectOptions(p); })
            .catch(function (e) {
                box.innerHTML = '<div class="lyra-panelempty"><b>Could not load projects.</b><span>'
                    + esc(e.message) + '</span></div>';
            });
    }

    /* ── FILES ─────────────────────────────────────────────────────────────── */

    function renderFiles(p) {
        var box = $('wsFileList');
        var list = p.files || [];
        if (!list.length) {
            box.innerHTML =
                '<div class="lyra-panelempty"><b>No files yet.</b>'
              + '<span>Upload something to keep it here. Files you attach in a chat are sent with that message instead.</span></div>';
            return;
        }
        box.innerHTML = list.map(function (f) {
            return '<div class="ws-item">'
              + '<div class="ws-item-main">'
              +   '<div class="ws-item-title">' + esc(f.original_name) + '</div>'
              +   '<div class="ws-item-meta">' + esc(bytes(f.size_bytes)) + ' &middot; '
              +     esc(f.mime_type) + ' &middot; ' + esc(when(f.created_at))
              +     (f.project_name ? ' &middot; ' + esc(f.project_name) : '') + '</div>'
              + '</div>'
              + '<div class="ws-item-actions">'
              +   '<a class="ly-btn ly-btn-ghost ly-btn-sm" href="/api/workspace.php?action=download_file&key='
              +     encodeURIComponent(f.storage_key) + '">Download</a>'
              +   '<button type="button" class="ly-btn ly-btn-ghost ly-btn-sm ws-danger" data-fdel="'
              +     esc(f.storage_key) + '">Delete</button>'
              + '</div></div>';
        }).join('');
    }

    function loadFiles() {
        var box = $('wsFileList');
        if (!box) { return Promise.resolve(); }
        return call('list_files', {}, 'GET')
            .then(renderFiles)
            .catch(function (e) {
                box.innerHTML = '<div class="lyra-panelempty"><b>Could not load files.</b><span>'
                    + esc(e.message) + '</span></div>';
            });
    }

    /* ── wiring ────────────────────────────────────────────────────────────── */

    function init() {
        var projectForm = $('wsProjectForm');
        var newProjectBtn = $('wsNewProject');
        var createBtn = $('wsCreateProject');
        var fileInput = $('wsFileInput');
        var uploadBtn = $('wsUploadBtn');
        var projectNote = $('wsProjectNote');
        var fileNote = $('wsFileNote');

        if (newProjectBtn && projectForm) {
            newProjectBtn.addEventListener('click', function () {
                projectForm.hidden = !projectForm.hidden;
                if (!projectForm.hidden) { $('wsProjectName').focus(); }
            });
        }

        if (createBtn) {
            createBtn.addEventListener('click', function () {
                var name = ($('wsProjectName') || {}).value || '';
                var desc = ($('wsProjectDesc') || {}).value || '';
                if (!name.trim()) { note(projectNote, 'A project needs a name.', true); return; }
                createBtn.disabled = true;
                call('create_project', { name: name.trim(), description: desc.trim() })
                    .then(function () {
                        $('wsProjectName').value = '';
                        $('wsProjectDesc').value = '';
                        projectForm.hidden = true;
                        note(projectNote, 'Project created.', false);
                        return loadProjects();
                    })
                    .catch(function (e) { note(projectNote, e.message, true); })
                    .then(function () { createBtn.disabled = false; });
            });
        }

        var list = $('wsProjectList');
        if (list) {
            list.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('[data-act]') : null;
                if (!btn) { return; }
                var act = btn.getAttribute('data-act');
                var id = btn.getAttribute('data-id');

                if (act === 'link') {
                    var conv = currentConvId();
                    if (!conv) { note(projectNote, 'No conversation is open to link.', true); return; }
                    btn.disabled = true;
                    call('link_conversation', { id: id, conv_id: conv })
                        .then(function () { note(projectNote, 'Linked this conversation.', false); return loadProjects(); })
                        .catch(function (err) { note(projectNote, err.message, true); })
                        .then(function () { btn.disabled = false; });
                    return;
                }

                if (act === 'archive') {
                    btn.disabled = true;
                    call('update_project', {
                        id: id,
                        name: btn.closest('.ws-item').querySelector('.ws-item-title').textContent.replace('archived', '').trim(),
                        description: (btn.closest('.ws-item').querySelector('.ws-item-sub') || {}).textContent || '',
                        status: btn.getAttribute('data-status')
                    })
                        .then(loadProjects)
                        .catch(function (err) { note(projectNote, err.message, true); })
                        .then(function () { btn.disabled = false; });
                    return;
                }

                if (act === 'delete') {
                    if (!confirm('Delete this project? Its files are kept, but they stop belonging to a project.')) { return; }
                    btn.disabled = true;
                    call('delete_project', { id: id })
                        .then(function () { note(projectNote, 'Project deleted.', false); return loadProjects(); })
                        .catch(function (err) { note(projectNote, err.message, true); })
                        .then(function () { btn.disabled = false; });
                }
            });
        }

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () {
                var f = fileInput.files && fileInput.files[0];
                if (!f) { return; }
                if (f.size > MAX_BYTES) {
                    note(fileNote, 'That file is larger than 25MB.', true);
                    fileInput.value = '';
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'upload_file');
                fd.append('file', f);
                var sel = $('wsFileProject');
                if (sel && sel.value) { fd.append('project_id', sel.value); }

                note(fileNote, 'Uploading ' + f.name + '…', false);
                uploadBtn.disabled = true;
                fetch('/api/workspace.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) {
                        return r.text().then(function (t) {
                            var p = null; try { p = JSON.parse(t); } catch (e2) { p = null; }
                            if (!p) { throw new Error('Unexpected reply from the server'); }
                            if (p.success !== true) { throw new Error(p.error || 'Upload failed'); }
                            return p;
                        });
                    })
                    .then(function () { note(fileNote, 'Uploaded.', false); return loadFiles(); })
                    .catch(function (e) { note(fileNote, e.message, true); })
                    .then(function () { uploadBtn.disabled = false; fileInput.value = ''; });
            });
        }

        var flist = $('wsFileList');
        if (flist) {
            flist.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('[data-fdel]') : null;
                if (!btn) { return; }
                if (!confirm('Delete this file permanently?')) { return; }
                btn.disabled = true;
                call('delete_file', { key: btn.getAttribute('data-fdel') })
                    .then(function () { note(fileNote, 'File deleted.', false); return loadFiles(); })
                    .catch(function (err) { note(fileNote, err.message, true); })
                    .then(function () { btn.disabled = false; });
            });
        }

        /* loadProjects() also fills the select, so there is no second fetch here. */
        loadProjects();
        loadFiles();
    }

    /* Panels are opened by the inline handler in chat.php; refresh on open so the
       list is current rather than whatever it was when the page loaded. */
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-lyra-panel]') : null;
        if (!t) { return; }
        var which = t.getAttribute('data-lyra-panel');
        if (which === 'projects') { loadProjects(); }
        if (which === 'files') { loadFiles(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
