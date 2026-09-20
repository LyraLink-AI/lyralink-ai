<?php require_once __DIR__ . '/../api/session_boot.php'; lyra_session_boot(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Support Dashboard</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#0a0a0f;--surface:#111118;--border:#1e1e2e;--accent:#7c3aed;--accent-glow:rgba(124,58,237,0.3);--accent-light:#a78bfa;--text:#e2e8f0;--text-muted:#64748b;--success:#22c55e;--error:#ef4444;--warn:#f59e0b}
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        body::before{content:'';position:fixed;top:-200px;left:30%;width:600px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%);pointer-events:none}

        /* NAV */
        nav{padding:12px 24px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(10,10,15,0.95);backdrop-filter:blur(12px);z-index:100}
        .nav-logo{height:26px;width:auto;mix-blend-mode:lighten}
        .nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text-muted)}
        .nav-right{display:flex;gap:8px;margin-left:auto;align-items:center}
        .nav-menu-toggle{display:none;padding:6px 10px;border-radius:8px;border:1px solid var(--border);background:none;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer}
        .nav-menu-toggle:hover{border-color:var(--accent);color:var(--accent-light)}
        .agent-badge{font-size:11px;padding:3px 10px;border-radius:20px;background:rgba(124,58,237,0.15);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}
        .btn-sm{padding:5px 12px;border-radius:20px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;border:1px solid var(--border);color:var(--text-muted);background:none;transition:all 0.2s}
        .btn-sm:hover{border-color:var(--accent);color:var(--accent-light)}

        .mobile-pane-toggle{display:none}

        /* LOGIN */
        #loginScreen{display:flex;align-items:center;justify-content:center;min-height:80vh}
        .login-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:380px}
        .login-card h2{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-bottom:20px}
        .login-card h2 span{color:var(--accent-light)}
        .f-field{margin-bottom:12px}
        .f-field label{display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px}
        .f-field input,.f-field select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none}
        .f-field input:focus{border-color:var(--accent)}
        .btn-full{width:100%;padding:10px;border-radius:10px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;border:none;background:var(--accent);color:white;box-shadow:0 0 12px var(--accent-glow);margin-top:4px;transition:all 0.2s}
        .btn-full:hover{background:#6d28d9}
        .err-msg{font-size:12px;color:var(--error);margin-top:8px}

        /* LAYOUT */
        #dashScreen{display:none}
        .layout{display:flex;height:calc(100vh - 53px)}

        /* SIDEBAR */
        .sidebar{width:260px;border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;flex-shrink:0}
        .sidebar-section{padding:12px;border-bottom:1px solid var(--border)}
        .sidebar-label{font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:8px;padding:0 4px}

        /* STATS BAR */
        .stat-row{display:grid;grid-template-columns:1fr 1fr;gap:6px}
        .stat-mini{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 10px;text-align:center}
        .stat-mini .val{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--accent-light)}
        .stat-mini .val.crit{color:var(--error)}
        .stat-mini .lbl{font-size:9px;color:var(--text-muted);text-transform:uppercase}

        /* FILTERS */
        .filter-btn{display:block;width:100%;text-align:left;padding:7px 10px;border-radius:8px;font-size:12px;color:var(--text-muted);background:none;border:none;cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.15s;margin-bottom:2px}
        .filter-btn:hover,.filter-btn.active{background:rgba(124,58,237,0.1);color:var(--accent-light)}
        .filter-btn .count{float:right;background:var(--border);border-radius:10px;padding:1px 6px;font-size:10px}

        /* SEARCH */
        .search-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:7px 10px;font-family:'DM Mono',monospace;font-size:12px;outline:none}
        .search-input:focus{border-color:var(--accent)}
        .ticket-tools{display:flex;flex-direction:column;gap:8px}
        .tool-row{display:flex;gap:6px;flex-wrap:wrap}
        .tool-chip{padding:5px 9px;border-radius:999px;border:1px solid var(--border);background:rgba(124,58,237,0.06);color:var(--text-muted);font-family:'DM Mono',monospace;font-size:10px;cursor:pointer;transition:all .15s}
        .tool-chip:hover{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,0.14)}
        .ticket-summary{font-size:10px;color:var(--text-muted);line-height:1.5;padding:0 2px}
        .kbd-hint{font-size:10px;color:var(--text-muted);opacity:.9}

        /* TICKET LIST */
        .ticket-list{flex:1;overflow-y:auto;padding:8px}
        .ticket-row{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:6px;cursor:pointer;transition:border-color 0.15s}
        .ticket-row:hover,.ticket-row.active{border-color:var(--accent)}
        .ticket-row-top{display:flex;align-items:center;gap:8px;margin-bottom:6px}
        .ticket-row-ref{font-size:10px;color:var(--text-muted)}
        .ticket-row-subject{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
        .ticket-row-badges{display:flex;gap:4px;flex-wrap:wrap}
        .ticket-row-meta{font-size:10px;color:var(--text-muted);display:flex;gap:10px}

        /* MAIN PANEL */
        .main-panel{flex:1;display:flex;flex-direction:column;overflow:hidden}
        .main-empty{display:flex;align-items:center;justify-content:center;flex:1;color:var(--text-muted);font-size:13px;flex-direction:column;gap:10px}
        .main-empty .ei{font-size:36px}

        /* TICKET DETAIL */
        .ticket-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:12px}
        .ticket-header-info{flex:1}
        .ticket-header-ref{font-size:11px;color:var(--text-muted);margin-bottom:4px}
        .ticket-header-subject{font-family:'Syne',sans-serif;font-size:17px;font-weight:700;margin-bottom:8px}
        .ticket-header-badges{display:flex;gap:6px;flex-wrap:wrap}
        .ticket-controls{display:flex;flex-direction:column;gap:6px;min-width:160px}
        .ctrl-select{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:6px 10px;font-family:'DM Mono',monospace;font-size:11px;outline:none;width:100%}
        .ctrl-select:focus{border-color:var(--accent)}
        .ctrl-label{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px}

        /* REPLIES */
        .replies-area{flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:10px}
        .reply-bubble{padding:12px 14px;border-radius:10px;border:1px solid var(--border)}
        .reply-bubble.user-msg{background:var(--bg)}
        .reply-bubble.agent-msg{background:rgba(124,58,237,0.06);border-color:rgba(124,58,237,0.25)}
        .reply-bubble.internal-msg{background:rgba(245,158,11,0.05);border-color:rgba(245,158,11,0.25)}
        .reply-bubble.system-msg{background:transparent;border:none;text-align:center}
        .reply-header{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:11px;color:var(--text-muted)}
        .reply-author{font-weight:600;color:var(--text)}
        .reply-body{font-size:12px;line-height:1.7;color:var(--text-muted);white-space:pre-wrap}
        .reply-body.system{font-size:11px;color:var(--text-muted);font-style:italic}

        /* REPLY BOX */
        .reply-box{padding:12px 20px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px}
        .reply-textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;resize:none;height:70px}
        .reply-textarea:focus{border-color:var(--accent)}
        .reply-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding-right:76px}
        .reply-btn{padding:7px 16px;border-radius:8px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;border:none;transition:all 0.2s}
        .reply-btn.primary{background:var(--accent);color:white}
        .reply-btn.primary:hover{background:#6d28d9}
        .reply-btn.secondary{background:rgba(245,158,11,0.1);color:var(--warn);border:1px solid rgba(245,158,11,0.3)}
        .reply-btn.secondary:hover{background:rgba(245,158,11,0.2)}
        .internal-note{font-size:10px;color:var(--warn)}

        /* BADGES */
        .pri{padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700}
        .pri.low    {background:rgba(34,197,94,0.15);color:var(--success);border:1px solid rgba(34,197,94,0.3)}
        .pri.medium {background:rgba(245,158,11,0.15);color:var(--warn);border:1px solid rgba(245,158,11,0.3)}
        .pri.high   {background:rgba(249,115,22,0.15);color:#f97316;border:1px solid rgba(249,115,22,0.3)}
        .pri.critical{background:rgba(239,68,68,0.15);color:var(--error);border:1px solid rgba(239,68,68,0.3)}
        .status{padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700}
        .status.open      {background:rgba(124,58,237,0.15);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}
        .status.in_progress{background:rgba(56,189,248,0.15);color:#38bdf8;border:1px solid rgba(56,189,248,0.3)}
        .status.waiting   {background:rgba(245,158,11,0.15);color:var(--warn);border:1px solid rgba(245,158,11,0.3)}
        .status.resolved  {background:rgba(34,197,94,0.15);color:var(--success);border:1px solid rgba(34,197,94,0.3)}
        .status.closed    {background:rgba(100,116,139,0.15);color:var(--text-muted);border:1px solid var(--border)}
        .role-badge{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
        .role-badge.admin        {background:rgba(239,68,68,0.15);color:var(--error);border:1px solid rgba(239,68,68,0.3)}
        .role-badge.senior_agent {background:rgba(249,115,22,0.15);color:#f97316;border:1px solid rgba(249,115,22,0.3)}
        .role-badge.agent        {background:rgba(124,58,237,0.15);color:var(--accent-light);border:1px solid rgba(124,58,237,0.3)}
        .role-badge.trial_agent  {background:rgba(100,116,139,0.15);color:var(--text-muted);border:1px solid var(--border)}

        .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 18px;font-size:12px;z-index:999;opacity:0;transition:opacity 0.3s;pointer-events:none;white-space:nowrap}
        .toast.show{opacity:1}
        .toast.success{border-color:var(--success);color:var(--success)}
        .toast.error{border-color:var(--error);color:var(--error)}
        .ai-assist-launcher{position:fixed;right:18px;bottom:18px;z-index:1000;width:58px;height:58px;border-radius:50%;border:1px solid rgba(124,58,237,0.35);background:linear-gradient(145deg,#7c3aed,#5b21b6);color:#fff;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 10px 32px rgba(124,58,237,0.38);transition:transform .2s ease,box-shadow .2s ease;animation:ai-assist-pulse 2.8s ease-in-out infinite}
        .ai-assist-launcher:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(124,58,237,0.42)}
        .ai-assist-launcher.active{transform:scale(0.96);animation:none;box-shadow:0 8px 22px rgba(124,58,237,0.3)}
        .ai-assist-panel{position:fixed;right:18px;bottom:86px;z-index:1000;width:min(380px,calc(100vw - 24px));height:min(520px,calc(100vh - 120px));background:var(--surface);border:1px solid var(--border);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 40px rgba(0,0,0,0.45);opacity:0;transform:translateY(16px) scale(.96);pointer-events:none;transition:opacity .22s ease,transform .22s ease}
        .ai-assist-panel.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
        .ai-assist-head{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--border);background:rgba(124,58,237,0.1)}
        .ai-assist-title{font-size:12px;font-weight:700;color:var(--accent-light)}
        .ai-assist-sub{font-size:10px;color:var(--text-muted)}
        .ai-assist-close{margin-left:auto;border:none;background:none;color:var(--text-muted);cursor:pointer;font-size:13px}
        .ai-assist-body{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px}
        .ai-msg{padding:9px 10px;border-radius:10px;font-size:11px;line-height:1.6;white-space:pre-wrap;animation:ai-msg-in .18s ease both}
        .ai-msg.user{align-self:flex-end;max-width:88%;background:rgba(124,58,237,0.18);border:1px solid rgba(124,58,237,0.3);color:#ddd6fe}
        .ai-msg.bot{align-self:flex-start;max-width:92%;background:var(--bg);border:1px solid var(--border);color:#cbd5e1}
        .ai-typing{display:flex;align-items:center;gap:5px;min-height:28px}
        .ai-typing-dot{width:6px;height:6px;border-radius:50%;background:#94a3b8;opacity:.45;animation:ai-typing-bounce .9s infinite}
        .ai-typing-dot:nth-child(2){animation-delay:.12s}
        .ai-typing-dot:nth-child(3){animation-delay:.24s}
        .ai-assist-foot{border-top:1px solid var(--border);padding:10px;display:flex;gap:8px;align-items:flex-end;background:rgba(10,10,15,0.7)}
        .ai-assist-input{flex:1;min-height:64px;max-height:130px;resize:vertical;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:11px;padding:8px 10px;outline:none}
        .ai-assist-input:focus{border-color:var(--accent)}
        .ai-assist-send{padding:8px 11px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:11px;font-family:'DM Mono',monospace;cursor:pointer}
        .ai-assist-send:disabled{opacity:.55;cursor:not-allowed}
        @keyframes ai-assist-pulse{0%,100%{box-shadow:0 10px 32px rgba(124,58,237,0.36)}50%{box-shadow:0 10px 32px rgba(124,58,237,0.36),0 0 0 10px rgba(124,58,237,0.08)}}
        @keyframes ai-msg-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        @keyframes ai-typing-bounce{0%,80%,100%{transform:translateY(0);opacity:.35}40%{transform:translateY(-3px);opacity:1}}
        .dock-table{display:flex;flex-direction:column;gap:8px}
        .dock-row{display:grid;grid-template-columns:1.1fr 1fr .85fr .95fr 1.4fr;gap:10px;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:var(--surface);align-items:start}
        .dock-head{background:rgba(124,58,237,0.08);font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
        .dock-actions{display:flex;flex-wrap:wrap;gap:6px}
        .dock-pill{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;border:1px solid var(--border)}
        .dock-pill.ok{color:var(--success);border-color:rgba(34,197,94,0.35)}
        .dock-pill.bad{color:var(--error);border-color:rgba(239,68,68,0.35)}
        .dock-pill.warn{color:var(--warn);border-color:rgba(245,158,11,0.35)}
        .dock-diag{margin-top:8px;padding:8px 10px;border-radius:10px;border:1px solid rgba(239,68,68,0.25);background:rgba(239,68,68,0.06);font-size:10px;color:#fecaca;line-height:1.5;white-space:pre-wrap;max-height:180px;overflow:auto}
        .cfg-note{font-size:11px;color:var(--text-muted);line-height:1.6;margin:-6px 0 14px}
        .template-shell{display:grid;grid-template-columns:240px 1fr;gap:14px;margin-top:14px}
        .template-list{display:flex;flex-direction:column;gap:8px;max-height:520px;overflow:auto;padding-right:2px}
        .template-card{padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:var(--bg);cursor:pointer;transition:border-color .15s,background .15s}
        .template-card:hover,.template-card.active{border-color:var(--accent);background:rgba(124,58,237,0.08)}
        .template-card-title{font-size:12px;font-weight:700;color:var(--text);margin-bottom:5px}
        .template-card-desc{font-size:10px;line-height:1.5;color:var(--text-muted)}
        .template-editor{display:flex;flex-direction:column;gap:12px;min-width:0}
        .template-panel{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px}
        .template-panel-title{font-size:12px;font-weight:700;color:var(--accent-light);margin-bottom:10px}
        .template-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
        .template-pill{display:inline-flex;padding:4px 8px;border-radius:999px;border:1px solid rgba(124,58,237,0.28);background:rgba(124,58,237,0.08);color:var(--accent-light);font-size:10px}
        .template-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap}
        .template-meta h4{font-family:'Syne',sans-serif;font-size:16px;line-height:1.2}
        .template-meta p{font-size:11px;color:var(--text-muted);line-height:1.6}
        .template-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
        .template-test-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}
        .template-preview{border:1px solid var(--border);border-radius:12px;background:#08080d;padding:16px;min-height:240px;overflow:auto}
        .template-preview-subject{font-size:11px;color:var(--text-muted);margin-bottom:12px;word-break:break-word}
        .template-preview-frame{background:#0a0a0f;border:1px solid #1e1e2e;border-radius:14px;padding:18px}
        .template-preview-frame h2{font-family:'Syne',sans-serif;color:var(--accent-light);margin:0 0 4px}
        .template-preview-frame h3{margin:0 0 14px;color:var(--text);font-size:16px}
        .template-preview-frame .preview-ref{color:var(--accent-light);font-size:12px;margin-bottom:16px}
        .template-preview-frame .preview-body{color:#94a3b8;line-height:1.7;font-size:13px}
        .template-preview-frame .preview-footer{border-top:1px solid #1e1e2e;margin-top:20px;padding-top:12px;font-size:11px;color:#334155}
        .template-editor textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;resize:vertical}
        .template-editor textarea:focus{border-color:var(--accent)}
        .btn-sm[disabled],.btn-full[disabled]{opacity:.5;cursor:not-allowed}
        .user-shell{display:grid;grid-template-columns:320px 1fr;gap:14px;margin-top:14px}
        .user-panel{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px}
        .user-results{display:flex;flex-direction:column;gap:8px;max-height:56vh;overflow:auto}
        .user-card{padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:var(--surface);cursor:pointer;transition:border-color .15s,background .15s}
        .user-card:hover,.user-card.active{border-color:var(--accent);background:rgba(124,58,237,0.08)}
        .user-card-top{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px}
        .user-card-name{font-size:12px;font-weight:700;color:var(--text)}
        .user-card-meta{font-size:10px;color:var(--text-muted);line-height:1.6;word-break:break-word}
        .user-summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0 16px}
        .user-summary-item{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
        .user-summary-item .k{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .user-summary-item .v{font-size:12px;font-weight:700;color:var(--text)}
        .user-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .user-section-title{font-size:11px;font-weight:700;color:var(--accent-light);margin:14px 0 8px}
        .user-action-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
        .user-note{font-size:11px;color:var(--text-muted);line-height:1.6}
        .user-inline-note{font-size:10px;color:var(--text-muted);margin-top:4px}
        .user-log-list{display:flex;flex-direction:column;gap:6px;margin-top:8px}
        .user-log-item{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:11px;line-height:1.6}
        .user-shortcuts{display:flex;gap:6px;flex-wrap:wrap}
        .user-shortcut-btn{display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:999px;border:1px solid rgba(124,58,237,0.28);background:rgba(124,58,237,0.08);color:var(--accent-light);font-size:10px;font-family:'DM Mono',monospace;cursor:pointer;transition:all .15s}
        .user-shortcut-btn:hover{border-color:var(--accent);background:rgba(124,58,237,0.14)}
        .user-target{scroll-margin-top:16px;transition:box-shadow .2s,border-color .2s,background .2s}
        .user-target.flash{box-shadow:0 0 0 1px rgba(124,58,237,0.35),0 0 18px rgba(124,58,237,0.12);border-color:var(--accent)!important;background:rgba(124,58,237,0.08)!important}

        @media(max-width:768px){.sidebar{width:100%;height:auto}.layout{flex-direction:column}}
        @media(max-width:1100px){.dock-row{grid-template-columns:1fr}}
        @media(max-width:980px){.template-shell,.user-shell,.user-summary-grid,.user-edit-grid{grid-template-columns:1fr}.template-list{max-height:none}}
        @media(max-width:900px){
            nav{padding:10px 14px;flex-wrap:wrap}
            .nav-title{font-size:12px}
            .nav-menu-toggle{display:inline-flex;margin-left:auto}
            .nav-right{display:none;width:100%;margin-left:0;padding-top:8px;flex-wrap:wrap;gap:6px}
            .nav-right.open{display:flex}
            .nav-right .btn-sm,.nav-right .agent-badge{flex:1 1 calc(50% - 6px);text-align:center;justify-content:center}

            .mobile-pane-toggle{display:flex;gap:6px;padding:10px 12px;border-bottom:1px solid var(--border);background:rgba(10,10,15,0.95)}
            .pane-btn{flex:1;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:none;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer}
            .pane-btn.active{border-color:var(--accent);color:var(--accent-light);background:rgba(124,58,237,0.1)}

            #dashScreen.dash-pane-list .main-panel{display:none}
            #dashScreen.dash-pane-list .sidebar{display:flex;width:100%;height:calc(100vh - 150px)}
            #dashScreen.dash-pane-detail .sidebar{display:none}
            #dashScreen.dash-pane-detail .main-panel{display:flex;height:calc(100vh - 150px)}
            .reply-actions{padding-right:64px}
            .ai-assist-launcher{right:12px;bottom:12px}
            .ai-assist-panel{right:12px;bottom:80px;width:calc(100vw - 24px);height:calc(100vh - 120px)}
            .tool-chip{flex:1 1 calc(50% - 6px);text-align:center}
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
<link rel="stylesheet" href="/assets/css/lyra-theme.css">
<link rel="stylesheet" href="/assets/css/lyra-support.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>

<!-- NAV -->
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-title">/ Support Dashboard</span>
    <button class="nav-menu-toggle" id="navMenuToggle" onclick="toggleNavMenu()">Menu</button>
    <div class="nav-right">
        <span class="agent-badge" id="agentBadge" style="display:none"></span>
        <button class="btn-sm" id="agentMgrBtn" style="display:none" onclick="showAgentManager()">👥 Agents</button>
        <button class="btn-sm" id="userMgrBtn" style="display:none" onclick="showUserManager()">👤 Users</button>
        <button class="btn-sm" id="configBtn" style="display:none" onclick="showConfig()">⚙ Config</button>
        <button class="btn-sm" id="queueBtn" style="display:none" onclick="showQueueMonitor()">📨 Queue</button>
        <button class="btn-sm" id="transcriptsBtn" style="display:none" onclick="showTranscripts()">📋 Transcripts</button>
        <button class="btn-sm" id="statusBtn" style="display:none" onclick="showStatusManager()">🟢 Status</button>
        <button class="btn-sm" id="careersBtn" style="display:none" onclick="showCareers()">💼 Careers</button>
        <button class="btn-sm" id="resellerAdminBtn" style="display:none" onclick="window.location.href='/pages/reseller_admin.php'">🏢 Reseller Admin</button>
        <button class="btn-sm" id="backToChatBtn" style="display:none" onclick="window.location.href='/chat'">← Back to Chat</button>
        <button class="btn-sm" id="myMailBtn" style="display:none" onclick="window.location.href='/pages/my_mail.php'">✉ My Email</button>
        <button class="btn-sm" id="logoutBtn" style="display:none" onclick="agentLogout()">Logout</button>
    </div>
</nav>

<!-- LOGIN SCREEN -->
<div id="loginScreen">
    <div class="login-card">
        <h2>Support <span>Login</span></h2>
        <div class="f-field"><label>Email</label><input type="email" id="loginEmail" placeholder="agent@lyralinkai.com"></div>
        <div class="f-field"><label>Password</label><input type="password" id="loginPass" placeholder="••••••••"></div>
        <button class="btn-full" onclick="agentLogin()">Sign In</button>
        <div class="err-msg" id="loginErr"></div>
    </div>
</div>

<!-- DASHBOARD -->
<div id="dashScreen" class="dash-pane-list">
<div class="mobile-pane-toggle" id="mobilePaneToggle">
    <button class="pane-btn active" id="paneListBtn" onclick="setMobilePane('list')">Tickets</button>
    <button class="pane-btn" id="paneDetailBtn" onclick="setMobilePane('detail')">Detail</button>
</div>
<div class="layout">

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-section">
            <div class="stat-row" id="statsRow">
                <div class="stat-mini"><div class="val" id="sOpen">—</div><div class="lbl">Open</div></div>
                <div class="stat-mini"><div class="val crit" id="sCrit">—</div><div class="lbl">Critical</div></div>
                <div class="stat-mini"><div class="val" id="sMine">—</div><div class="lbl">Queue</div></div>
                <div class="stat-mini"><div class="val" id="sOnline">—</div><div class="lbl">Online</div></div>
            </div>
            <div id="workflowSummary" style="margin-top:8px;font-size:10px;color:var(--text-muted);line-height:1.5">Loading workflow...</div>
        </div>

        <div class="sidebar-section">
            <input class="search-input" id="searchInput" placeholder="Search tickets..." oninput="debounceSearch()">
        </div>

        <div class="sidebar-section ticket-tools">
            <div class="tool-row">
                <button class="tool-chip" type="button" onclick="applyQuickFilter('open','critical')">Critical Open</button>
                <button class="tool-chip" type="button" onclick="applyQuickFilter('in_progress','')">In Progress</button>
                <button class="tool-chip" type="button" onclick="applyQuickFilter('waiting','')">Waiting</button>
                <button class="tool-chip" type="button" onclick="applyQuickFilter('open','')">Open Queue</button>
                <button class="tool-chip" type="button" onclick="clearTicketSearch()">Clear Search</button>
            </div>
            <div class="ticket-summary" id="ticketSummary">Loading ticket queue...</div>
            <div class="kbd-hint">Shortcuts: / focus search, Esc clear search</div>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-label">Status</div>
            <button class="filter-btn active" data-filter-type="status" data-filter-value="open" onclick="setFilter('status','open',this)">🟣 Open</button>
            <button class="filter-btn" data-filter-type="status" data-filter-value="in_progress" onclick="setFilter('status','in_progress',this)">🔵 In Progress</button>
            <button class="filter-btn" data-filter-type="status" data-filter-value="waiting" onclick="setFilter('status','waiting',this)">🟡 Waiting</button>
            <button class="filter-btn" data-filter-type="status" data-filter-value="resolved" onclick="setFilter('status','resolved',this)">🟢 Resolved</button>
            <button class="filter-btn" data-filter-type="status" data-filter-value="all" onclick="setFilter('status','all',this)">📋 All</button>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-label">Priority</div>
            <button class="filter-btn" data-filter-type="priority" data-filter-value="critical" onclick="setFilter('priority','critical',this)">🔴 Critical</button>
            <button class="filter-btn" data-filter-type="priority" data-filter-value="high" onclick="setFilter('priority','high',this)">🟠 High</button>
            <button class="filter-btn" data-filter-type="priority" data-filter-value="medium" onclick="setFilter('priority','medium',this)">🟡 Medium</button>
            <button class="filter-btn" data-filter-type="priority" data-filter-value="low" onclick="setFilter('priority','low',this)">🟢 Low</button>
            <button class="filter-btn" data-filter-type="priority" data-filter-value="" onclick="setFilter('priority','',this)">All Priorities</button>
        </div>

        <div class="sidebar-section" style="flex:1;overflow:hidden;padding:0;display:flex;flex-direction:column">
            <div class="sidebar-label" style="padding:12px 12px 0">Tickets</div>
            <div class="ticket-list" id="ticketList">
                <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Loading...</div>
            </div>
        </div>
    </div>

    <!-- MAIN PANEL -->
    <div class="main-panel" id="mainPanel">
        <div class="main-empty" id="emptyPanel">
            <div class="ei">🎫</div>
            <p>Select a ticket to view</p>
        </div>
        <div id="ticketDetail" style="display:none;display:flex;flex-direction:column;flex:1;overflow:hidden"></div>
    </div>

</div>
</div>

<!-- AGENT MANAGER MODAL -->
<div id="agentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;display:none;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:680px;width:100%;max-height:80vh;overflow-y:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">👥 Agent Manager</h3>
            <button onclick="closeModal('agentModal')" class="btn-sm">✕ Close</button>
        </div>
        <div id="agentList" style="margin-bottom:20px"></div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px">
            <div style="font-size:12px;font-weight:600;margin-bottom:12px">Add New Agent</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div class="f-field"><label>Username</label><input type="text" id="newAgentUser" placeholder="agent_name"></div>
                <div class="f-field"><label>Email</label><input type="email" id="newAgentEmail" placeholder="agent@..."></div>
                <div class="f-field"><label>Password</label><input type="password" id="newAgentPass" placeholder="min 6 chars"></div>
                <div class="f-field"><label>Role</label>
                    <select id="newAgentRole">
                        <option value="trial_agent">Trial Agent</option>
                        <option value="agent">Agent</option>
                        <option value="senior_agent">Senior Agent</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <button class="btn-full" onclick="createAgent()" style="margin-top:4px">Add Agent</button>
            <div class="err-msg" id="agentErr"></div>
        </div>
    </div>
</div>

<!-- USER MANAGER MODAL -->
<div id="userModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:205;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:1120px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:8px;flex-wrap:wrap">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">👤 User Account Manager</h3>
            <button onclick="closeModal('userModal')" class="btn-sm">✕ Close</button>
        </div>
        <div class="cfg-note">Agents can view user accounts, senior agents can edit profile and billing fields, and admins can also reset security and linked-service access.</div>
        <div class="user-shell">
            <div class="user-panel user-target" id="userSearchPanel">
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <input type="text" id="userSearchInput" class="search-input" placeholder="Search username, email, or user ID" oninput="debounceUserSearch()" style="flex:1">
                    <button class="btn-sm" onclick="searchUsers(true)">↻ Refresh</button>
                </div>
                <div id="userPermissionNote" class="user-note" style="margin-bottom:10px">Search to view user accounts and recent changes.</div>
                <div id="userSearchResults" class="user-results">
                    <div style="padding:18px;text-align:center;color:var(--text-muted);font-size:12px;border:1px dashed var(--border);border-radius:12px">User results will appear here.</div>
                </div>
            </div>
            <div class="user-panel" id="userDetailPane">
                <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Select a user account to inspect or edit it.</div>
            </div>
        </div>
    </div>
</div>

<!-- CONFIG MODAL -->
<div id="configModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:1080px;width:100%;max-height:86vh;overflow-y:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">⚙ Configuration</h3>
            <button onclick="closeModal('configModal')" class="btn-sm">✕ Close</button>
        </div>
        <div style="font-size:12px;font-weight:600;margin-bottom:10px;color:var(--accent-light)">📧 SMTP Settings</div>
        <div class="cfg-note">These values are loaded from the live support configuration and save back into the same store the notification worker already uses.</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px">
            <div class="f-field"><label>SMTP Host</label><input type="text" id="cfSmtpHost" placeholder="smtp.gmail.com"></div>
            <div class="f-field"><label>SMTP Port</label><input type="text" id="cfSmtpPort" placeholder="587"></div>
            <div class="f-field"><label>SMTP User</label><input type="text" id="cfSmtpUser" placeholder="user@gmail.com"></div>
            <div class="f-field"><label>SMTP Pass</label><input type="password" id="cfSmtpPass" placeholder="app password"></div>
            <div class="f-field"><label>From Address</label><input type="text" id="cfSmtpFrom" placeholder="noreply@lyralinkai.com"></div>
            <div class="f-field"><label>Support Email</label><input type="text" id="cfSupportEmail" placeholder="support@lyralinkai.com"></div>
        </div>
        <button class="btn-full" id="saveSmtpBtn" onclick="saveSmtp()" style="margin-bottom:20px">Save SMTP</button>

        <div style="font-size:12px;font-weight:600;margin-bottom:10px;color:var(--accent-light)">🎮 Discord Webhook & Role Pings</div>
        <div class="f-field" style="margin-bottom:10px"><label>Webhook URL</label><input type="text" id="cfWebhook" placeholder="https://discord.com/api/webhooks/..."></div>
        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px">
            <span class="pri low" style="white-space:nowrap">low</span>
            <div class="f-field" style="margin:0"><input type="text" id="cf_low_id" placeholder="Role ID"></div>
            <div class="f-field" style="margin:0"><input type="text" id="cf_low_name" placeholder="Role name"></div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px">
            <span class="pri medium" style="white-space:nowrap">medium</span>
            <div class="f-field" style="margin:0"><input type="text" id="cf_medium_id" placeholder="Role ID"></div>
            <div class="f-field" style="margin:0"><input type="text" id="cf_medium_name" placeholder="Role name"></div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px">
            <span class="pri high" style="white-space:nowrap">high</span>
            <div class="f-field" style="margin:0"><input type="text" id="cf_high_id" placeholder="Role ID"></div>
            <div class="f-field" style="margin:0"><input type="text" id="cf_high_name" placeholder="Role name"></div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px">
            <span class="pri critical" style="white-space:nowrap">critical</span>
            <div class="f-field" style="margin:0"><input type="text" id="cf_critical_id" placeholder="Role ID"></div>
            <div class="f-field" style="margin:0"><input type="text" id="cf_critical_name" placeholder="Role name"></div>
        </div>
        <button class="btn-full" id="saveDiscordBtn" onclick="saveDiscordConfig()" style="margin-bottom:22px">Save Discord Config</button>

        <div style="font-size:12px;font-weight:600;margin-bottom:10px;color:var(--accent-light)">✉ Email Templates</div>
        <div class="cfg-note">Edit the actual support emails here. Use placeholder tokens like <strong>{{ticket_ref}}</strong> and <strong>{{message}}</strong>; the preview below shows how each template will render with sample data.</div>
        <div class="template-shell">
            <div class="template-list" id="emailTemplateList">
                <div style="padding:18px;text-align:center;color:var(--text-muted);font-size:12px;border:1px dashed var(--border);border-radius:12px">Loading templates...</div>
            </div>
            <div class="template-editor">
                <div class="template-panel">
                    <div class="template-meta">
                        <div>
                            <h4 id="tplEditorTitle">Select a template</h4>
                            <p id="tplEditorDesc">Choose a template from the left to edit its subject and HTML body.</p>
                        </div>
                        <div class="template-actions">
                            <button class="btn-sm" id="tplSendTestBtn" onclick="sendTemplateTestEmail()">Send Test</button>
                            <button class="btn-sm" id="tplResetBtn" onclick="resetActiveTemplate()">Reset to Default</button>
                            <button class="btn-sm" id="tplSaveBtn" onclick="saveEmailTemplates()">Save Templates</button>
                        </div>
                    </div>
                    <div class="template-test-row" style="margin-bottom:12px">
                        <div class="f-field" style="margin-bottom:0"><label>Test Recipient</label><input type="email" id="tplTestRecipient" placeholder="name@example.com"></div>
                        <button class="btn-sm" id="tplSendInlineBtn" onclick="sendTemplateTestEmail()" style="height:38px">Send Test Email</button>
                    </div>
                    <div class="f-field"><label>Subject</label><input type="text" id="tplSubject" placeholder="Email subject"></div>
                    <div class="f-field" style="margin-bottom:0"><label>HTML Body</label><textarea id="tplBody" placeholder="<p>Your email content...</p>" style="min-height:220px"></textarea></div>
                </div>
                <div class="template-panel">
                    <div class="template-panel-title">Available Placeholders</div>
                    <div class="template-pills" id="tplPlaceholders"></div>
                </div>
                <div class="template-panel">
                    <div class="template-panel-title">Preview</div>
                    <div class="template-preview" id="tplPreview"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TRANSCRIPTS MODAL -->
<div id="transcriptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:900px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">📋 Discord Ticket Transcripts</h3>
            <button onclick="closeModal('transcriptModal')" class="btn-sm">✕ Close</button>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:16px">
            <input type="text" id="transcriptSearch" placeholder="Search by user, ref, channel..." class="search-input" style="flex:1" oninput="searchTranscripts()">
        </div>
        <div id="transcriptList" style="display:flex;flex-direction:column;gap:6px;max-height:60vh;overflow-y:auto"></div>
    </div>
</div>

<!-- TRANSCRIPT VIEWER -->
<div id="transcriptViewer" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:300;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:860px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800" id="viewerTitle">Transcript</h3>
            <div style="display:flex;gap:8px">
                <button onclick="closeModal('transcriptViewer')" class="btn-sm">✕ Close</button>
            </div>
        </div>
        <div id="transcriptContent" style="max-height:70vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px;overflow:hidden"></div>
    </div>
</div>

<!-- CAREERS MODAL -->
<div id="careersModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:1000px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">💼 Careers Manager</h3>
            <div style="display:flex;gap:8px">
                <a href="/pages/careers/" target="_blank" class="btn-sm">View Public Page ↗</a>
                <button onclick="careersTab('jobs')" class="btn-sm" id="cTabJobs">📋 Jobs</button>
                <button onclick="careersTab('applications')" class="btn-sm" id="cTabApps">📥 Applications</button>
                <button onclick="closeModal('careersModal')" class="btn-sm">✕ Close</button>
            </div>
        </div>

        <!-- JOBS TAB -->
        <div id="cJobsTab">
            <div style="display:flex;gap:8px;margin-bottom:16px;align-items:center">
                <span style="font-size:12px;font-weight:600;color:var(--accent-light)">Job Listings</span>
                <button onclick="openJobEditor(null)" class="btn-sm" style="margin-left:auto">+ New Job</button>
            </div>
            <div id="cJobList"></div>
        </div>

        <!-- APPLICATIONS TAB -->
        <div id="cAppsTab" style="display:none">
            <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
                <select id="cAppJobFilter" onchange="loadApplications()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:5px 10px;font-size:11px;font-family:'DM Mono',monospace">
                    <option value="">All Jobs</option>
                </select>
                <select id="cAppStatusFilter" onchange="loadApplications()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:5px 10px;font-size:11px;font-family:'DM Mono',monospace">
                    <option value="">All Statuses</option>
                    <option value="new">New</option>
                    <option value="reviewing">Reviewing</option>
                    <option value="interview">Interview</option>
                    <option value="offer">Offer</option>
                    <option value="rejected">Rejected</option>
                    <option value="withdrawn">Withdrawn</option>
                </select>
                <input type="text" id="cAppSearch" placeholder="Search name / email..." oninput="debounceAppSearch()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:5px 10px;font-size:11px;font-family:'DM Mono',monospace;flex:1;min-width:140px;outline:none">
            </div>
            <div id="cAppList"></div>
        </div>

        <!-- JOB EDITOR -->
        <div id="cJobEditor" style="display:none;background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:20px;margin-top:12px">
            <div style="font-size:13px;font-weight:600;margin-bottom:14px" id="editorTitle">New Job Listing</div>
            <input type="hidden" id="edJobId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div class="f-field"><label>Job Title *</label><input type="text" id="edTitle" placeholder="e.g. Senior AI Engineer"></div>
                <div class="f-field"><label>Department</label><input type="text" id="edDept" placeholder="e.g. Engineering" value="Engineering"></div>
                <div class="f-field"><label>Location</label><input type="text" id="edLocation" placeholder="Remote" value="Remote"></div>
                <div class="f-field"><label>Type</label>
                    <select id="edType">
                        <option value="full_time">Full-time</option>
                        <option value="part_time">Part-time</option>
                        <option value="contract">Contract</option>
                        <option value="internship">Internship</option>
                    </select>
                </div>
                <div class="f-field"><label>Min Salary (USD)</label><input type="number" id="edSalMin" placeholder="e.g. 80000"></div>
                <div class="f-field"><label>Max Salary (USD)</label><input type="number" id="edSalMax" placeholder="e.g. 120000"></div>
            </div>
            <div class="f-field" style="margin-bottom:10px"><label>Description * (what the role involves)</label>
                <textarea id="edDesc" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:100px;resize:vertical"></textarea>
            </div>
            <div class="f-field" style="margin-bottom:10px"><label>Requirements *</label>
                <textarea id="edReqs" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:80px;resize:vertical" placeholder="• 3+ years experience&#10;• Proficiency in X&#10;..."></textarea>
            </div>
            <div class="f-field" style="margin-bottom:10px"><label>Perks (optional)</label>
                <textarea id="edPerks" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:60px;resize:vertical" placeholder="• Fully remote&#10;• Equity&#10;..."></textarea>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <div class="f-field" style="flex-direction:row;align-items:center;gap:8px;margin:0">
                    <input type="checkbox" id="edActive" checked style="accent-color:var(--accent)">
                    <label style="font-size:12px;color:var(--text-muted)">Active (visible on careers page)</label>
                </div>
                <button class="btn-full" style="flex:1" onclick="saveJob()">Save Job</button>
                <button class="btn-sm" onclick="document.getElementById('cJobEditor').style.display='none'">Cancel</button>
            </div>
        </div>

        <!-- APPLICATION DETAIL -->
        <div id="cAppDetail" style="display:none;background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:20px;margin-top:12px">
            <div id="cAppDetailContent"></div>
        </div>
    </div>
</div>

<!-- QUEUE MONITOR MODAL -->
<div id="queueModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:920px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">📨 Notification Queue</h3>
            <div style="display:flex;gap:8px">
                <button onclick="loadQueueMonitor()" class="btn-sm">↻ Refresh</button>
                <button onclick="closeModal('queueModal')" class="btn-sm">✕ Close</button>
            </div>
        </div>
        <div id="queueSummary" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px"></div>
        <div id="queueJobs" style="display:flex;flex-direction:column;gap:8px"></div>
    </div>
</div>

<!-- STATUS MANAGER MODAL -->
<div id="statusModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;max-width:760px;width:100%;margin:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
            <h3 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800">🟢 Status Manager</h3>
            <div style="display:flex;gap:8px">
                <a href="/pages/status/" target="_blank" class="btn-sm">View Public Page ↗</a>
                <button onclick="closeModal('statusModal')" class="btn-sm">✕ Close</button>
            </div>
        </div>

        <!-- SERVICE STATUSES -->
        <div style="font-size:12px;font-weight:600;margin-bottom:10px;color:var(--accent-light)">⚙ Service Statuses</div>
        <div id="statusServiceList" style="margin-bottom:24px"></div>

        <!-- CREATE INCIDENT -->
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px">
            <div style="font-size:12px;font-weight:600;margin-bottom:12px">🚨 Create Incident</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                <div class="f-field"><label>Title</label><input type="text" id="incTitle" placeholder="Brief incident title"></div>
                <div class="f-field"><label>Impact</label>
                    <select id="incImpact">
                        <option value="minor">Minor</option>
                        <option value="major">Major</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="f-field"><label>Initial Status</label>
                    <select id="incStatus">
                        <option value="investigating">Investigating</option>
                        <option value="identified">Identified</option>
                        <option value="monitoring">Monitoring</option>
                    </select>
                </div>
            </div>
            <div class="f-field" style="margin-bottom:8px"><label>Message</label>
                <textarea id="incMessage" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:70px;resize:vertical" placeholder="Describe the issue..."></textarea>
            </div>
            <button class="btn-full" onclick="createIncident()">Create Incident</button>
        </div>

        <!-- UPDATE EXISTING INCIDENT -->
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px">
            <div style="font-size:12px;font-weight:600;margin-bottom:12px">📝 Post Incident Update</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                <div class="f-field"><label>Incident</label>
                    <select id="updateIncidentId"><option value="">Select incident...</option></select>
                </div>
                <div class="f-field"><label>New Status</label>
                    <select id="updateIncStatus">
                        <option value="investigating">Investigating</option>
                        <option value="identified">Identified</option>
                        <option value="monitoring">Monitoring</option>
                        <option value="resolved">Resolved ✓</option>
                    </select>
                </div>
            </div>
            <div class="f-field" style="margin-bottom:8px"><label>Update Message</label>
                <textarea id="updateIncMessage" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:70px;resize:vertical" placeholder="Describe the latest update..."></textarea>
            </div>
            <button class="btn-full" onclick="postIncidentUpdate()">Post Update</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<button class="ai-assist-launcher" id="aiAssistLauncher" onclick="toggleAiAssist()">AI</button>
<div class="ai-assist-panel" id="aiAssistPanel">
    <div class="ai-assist-head">
        <div>
            <div class="ai-assist-title">Agent AI Assist</div>
            <div class="ai-assist-sub" id="aiAssistTicketRef">Open a ticket to diagnose issues</div>
        </div>
        <button class="ai-assist-close" onclick="toggleAiAssist(false)">✕</button>
    </div>
    <div class="ai-assist-body" id="aiAssistBody"></div>
    <div class="ai-assist-foot">
        <textarea id="aiAssistInput" class="ai-assist-input" placeholder="Ask: why is this happening, what should I check, and what should I reply?"></textarea>
        <button id="aiAssistSend" class="ai-assist-send" onclick="sendAiAssist()">Send</button>
    </div>
</div>

<script>
let agentSession = null;
let filters = { status: 'open', priority: '', search: '' };
let activeTicketRef = null;
let searchTimer = null;
let ticketRefreshTimer = null;
let supportAdminSocket = null;
let supportAdminSocketTicketRef = null;
let supportAdminSocketReconnect = null;
let supportAdminSocketManualClose = false;
let mobilePane = 'list';
let supportConfigCache = null;
let emailTemplatesState = {};
let activeTemplateId = null;
let aiAssistOpen = false;
let aiAssistBusy = false;
let userSearchTimer = null;
let userSearchResults = [];
let activeUserAccountId = null;
let userManagerPermissions = null;
const supportAdminQuery = new URLSearchParams(window.location.search);
const requestedUserId = Number(supportAdminQuery.get('user_id') || 0);
const requestedUserFocus = supportAdminQuery.get('focus') || '';

async function api(action, body = {}, method = 'POST') {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(body).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch('/api/support.php', { method, body: fd });
    return res.json();
}

async function apiGet(action, params = {}) {
    const q = new URLSearchParams({ action, ...params }).toString();
    const res = await fetch('/api/support.php?' + q);
    return res.json();
}

// ── AUTH ──
async function agentLogin() {
    const email = document.getElementById('loginEmail').value.trim();
    const pass  = document.getElementById('loginPass').value;
    const data  = await api('agent_login', { email, password: pass });
    if (data.success) { agentSession = data; showDash(); }
    else document.getElementById('loginErr').textContent = data.error || 'Invalid credentials';
}

document.getElementById('loginPass')?.addEventListener('keydown', e => { if (e.key === 'Enter') agentLogin(); });

function showDash() {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('dashScreen').style.display  = 'block';
    document.getElementById('agentBadge').textContent    = agentSession.username + ' · ' + agentSession.role.replace('_',' ');
    document.getElementById('agentBadge').style.display  = 'inline-block';
    document.getElementById('logoutBtn').style.display   = 'inline-block';
    document.getElementById('myMailBtn').style.display   = 'inline-block';
    if (['admin','senior_agent','agent'].includes(agentSession.role)) {
        document.getElementById('userMgrBtn').style.display   = 'inline-block';
    }
    if (['admin','senior_agent'].includes(agentSession.role)) {
        document.getElementById('agentMgrBtn').style.display  = 'inline-block';
        document.getElementById('configBtn').style.display    = 'inline-block';
        document.getElementById('statusBtn').style.display    = 'inline-block';
        document.getElementById('careersBtn').style.display   = 'inline-block';
    }
    if (agentSession.role === 'admin') {
        document.getElementById('resellerAdminBtn').style.display = 'inline-block';
        document.getElementById('queueBtn').style.display     = 'inline-block';
        document.getElementById('backToChatBtn').style.display = 'inline-block';
        document.getElementById('transcriptsBtn').style.display = 'inline-block';
    }
    setMobilePane(window.innerWidth <= 900 ? 'list' : 'detail');
    loadTickets();
    loadStats();
    startHeartbeat();
    startTicketLiveLoop();
    const requestedTool = new URLSearchParams(window.location.search).get('tool');
    if (requestedTool === 'users' && ['admin','senior_agent','agent'].includes(agentSession.role)) {
        setTimeout(() => showUserManager(), 120);
    }
}

function toggleNavMenu() {
    const navRight = document.querySelector('.nav-right');
    if (!navRight) return;
    navRight.classList.toggle('open');
}

function setMobilePane(pane) {
    const dash = document.getElementById('dashScreen');
    if (!dash) return;
    if (window.innerWidth > 900) {
        dash.classList.remove('dash-pane-list', 'dash-pane-detail');
        document.getElementById('paneListBtn')?.classList.remove('active');
        document.getElementById('paneDetailBtn')?.classList.remove('active');
        return;
    }

    mobilePane = pane === 'detail' ? 'detail' : 'list';
    dash.classList.remove('dash-pane-list', 'dash-pane-detail');
    dash.classList.add(mobilePane === 'detail' ? 'dash-pane-detail' : 'dash-pane-list');
    document.getElementById('paneListBtn')?.classList.toggle('active', mobilePane === 'list');
    document.getElementById('paneDetailBtn')?.classList.toggle('active', mobilePane === 'detail');
}

window.addEventListener('resize', () => {
    setMobilePane(mobilePane);
    if (window.innerWidth > 900) {
        document.querySelector('.nav-right')?.classList.remove('open');
    }
});

async function agentLogout() {
    closeSupportAdminSocket(true);
    await api('agent_logout');
    location.reload();
}

// ── HEARTBEAT ──
function startHeartbeat() {
    api('heartbeat');
    setInterval(() => { api('heartbeat'); loadStats(); }, 30000);
}

function canAutoRefreshActiveTicket() {
    if (!activeTicketRef) return true;
    const replyBox = document.getElementById('replyText');
    if (!replyBox) return true;
    const hasDraft = replyBox.value.trim() !== '';
    const isTyping = document.activeElement === replyBox;
    return !(hasDraft && isTyping);
}

function isSupportAdminSocketOpen() {
    return supportAdminSocket && supportAdminSocket.readyState === WebSocket.OPEN;
}

function closeSupportAdminSocket(manual = true) {
    if (supportAdminSocketReconnect) {
        clearTimeout(supportAdminSocketReconnect);
        supportAdminSocketReconnect = null;
    }
    supportAdminSocketManualClose = manual;
    if (supportAdminSocket) {
        try { supportAdminSocket.close(); } catch (_) {}
        supportAdminSocket = null;
    }
    if (manual) {
        supportAdminSocketTicketRef = null;
    }
}

function supportAdminSocketUrl(url, token) {
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}token=${encodeURIComponent(token)}`;
}

function scheduleSupportAdminSocketReconnect() {
    if (!activeTicketRef || supportAdminSocketReconnect) return;
    supportAdminSocketReconnect = setTimeout(() => {
        supportAdminSocketReconnect = null;
        connectSupportAdminSocketForActiveTicket(true);
    }, 3000);
}

async function connectSupportAdminSocketForActiveTicket(force = false) {
    if (!activeTicketRef) return;
    if (!force && supportAdminSocketTicketRef === activeTicketRef && isSupportAdminSocketOpen()) return;

    closeSupportAdminSocket(true);
    const auth = await api('ws_auth_agent', { ref: activeTicketRef }).catch(() => null);
    if (!auth?.success || !auth.url || !auth.token) {
        return;
    }

    supportAdminSocketTicketRef = activeTicketRef;
    supportAdminSocketManualClose = false;
    try {
        supportAdminSocket = new WebSocket(supportAdminSocketUrl(auth.url, auth.token));
    } catch (_) {
        scheduleSupportAdminSocketReconnect();
        return;
    }

    supportAdminSocket.onmessage = async (event) => {
        let payload = null;
        try {
            payload = JSON.parse(event.data);
        } catch (_) {
            return;
        }
        if (!payload || payload.type !== 'ticket_update') return;
        if (!payload.ticket_ref || payload.ticket_ref !== activeTicketRef) return;
        if (!canAutoRefreshActiveTicket()) return;
        await openTicket(activeTicketRef);
    };

    supportAdminSocket.onclose = () => {
        const shouldReconnect = !supportAdminSocketManualClose && supportAdminSocketTicketRef === activeTicketRef;
        supportAdminSocket = null;
        if (shouldReconnect) {
            scheduleSupportAdminSocketReconnect();
        }
    };
}

function startTicketLiveLoop() {
    if (ticketRefreshTimer) clearInterval(ticketRefreshTimer);
    ticketRefreshTimer = setInterval(async () => {
        await loadTickets();
        if (activeTicketRef && canAutoRefreshActiveTicket()) {
            await openTicket(activeTicketRef);
        }
    }, 5000);
}

// ── STATS ──
async function loadStats() {
    const statsData = await apiGet('agent_stats').catch(() => null);
    if (statsData?.success) {
        const s = statsData.stats || {};
        document.getElementById('sOpen').textContent = s.open ?? 0;
        document.getElementById('sCrit').textContent = s.critical ?? 0;
        document.getElementById('sMine').textContent = s.mine ?? 0;
        document.getElementById('sOnline').textContent = s.online ?? 0;
    }

    const workflow = await apiGet('workflow_summary').catch(() => null);
    const summaryEl = document.getElementById('workflowSummary');
    if (!summaryEl) return;

    if (!workflow?.success || !workflow.summary) {
        summaryEl.textContent = 'Workflow summary unavailable.';
        return;
    }

    const w = workflow.summary;
    document.getElementById('sOpen').textContent = Number(w.open || 0) + Number(w.in_progress || 0);
    document.getElementById('sCrit').textContent = w.critical_open ?? 0;
    document.getElementById('sMine').textContent = w.unassigned_open ?? 0;

    summaryEl.textContent = `In progress: ${w.in_progress || 0} • Waiting: ${w.waiting || 0} • Resolved: ${w.resolved || 0}`;
}

// ── FILTERS ──
function setFilter(type, val, el) {
    if (type === 'status')   filters.status   = val;
    if (type === 'priority') filters.priority = val;
    if (el) {
        el.closest('.sidebar-section')?.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
    }
    syncFilterButtons();
    loadTickets();
}

function syncFilterButtons() {
    document.querySelectorAll('.filter-btn[data-filter-type]').forEach(btn => {
        const type = btn.dataset.filterType;
        const value = btn.dataset.filterValue ?? '';
        const active = type === 'status'
            ? String(filters.status) === String(value)
            : String(filters.priority) === String(value);
        btn.classList.toggle('active', active);
    });
}

function applyQuickFilter(status, priority) {
    filters.status = status || 'all';
    filters.priority = priority || '';
    syncFilterButtons();
    loadTickets();
}

function clearTicketSearch() {
    const input = document.getElementById('searchInput');
    if (input) input.value = '';
    filters.search = '';
    loadTickets();
}

function updateTicketSummary(tickets) {
    const summary = document.getElementById('ticketSummary');
    if (!summary) return;
    if (!Array.isArray(tickets) || tickets.length === 0) {
        summary.textContent = 'No tickets match current filters.';
        return;
    }
    const counts = { open: 0, in_progress: 0, waiting: 0, resolved: 0, critical: 0 };
    tickets.forEach(t => {
        const status = String(t.status || '');
        const pri = String(t.agent_priority || t.user_priority || '');
        if (counts[status] !== undefined) counts[status] += 1;
        if (pri === 'critical') counts.critical += 1;
    });
    summary.textContent = `Showing ${tickets.length} tickets • Open ${counts.open} • In Progress ${counts.in_progress} • Waiting ${counts.waiting} • Critical ${counts.critical}`;
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { filters.search = document.getElementById('searchInput').value; loadTickets(); }, 300);
}

// ── TICKET LIST ──
async function loadTickets() {
    const data = await apiGet('ticket_list', filters);
    const list = document.getElementById('ticketList');
    if (!data.success) {
        updateTicketSummary([]);
        list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--error);font-size:12px">Failed to load</div>';
        return;
    }
    updateTicketSummary(data.tickets || []);
    if (!data.tickets?.length) { list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">No tickets</div>'; return; }
    list.innerHTML = data.tickets.map(t => {
        const effPri = t.agent_priority || t.user_priority;
        return `<div class="ticket-row ${t.ticket_ref === activeTicketRef ? 'active' : ''}" onclick="openTicket('${t.ticket_ref}')">
            <div class="ticket-row-top"><span class="ticket-row-ref">${t.ticket_ref}</span><span class="ticket-row-subject">${escHtml(t.subject)}</span></div>
            <div class="ticket-row-badges" style="margin-bottom:4px">
                <span class="status ${t.status}">${t.status.replace('_',' ')}</span>
                <span class="pri ${effPri}">${effPri}</span>
                ${t.agent_name ? `<span style="font-size:10px;color:var(--text-muted)">→ ${escHtml(t.agent_name)}</span>` : ''}
            </div>
            <div class="ticket-row-meta">
                <span>${escHtml(t.username || t.guest_name || 'Guest')}</span>
                <span>${t.reply_count} replies</span>
                <span>${formatDate(t.updated_at)}</span>
            </div>
        </div>`;
    }).join('');

    if (!activeTicketRef && data.tickets.length > 0) {
        const preferred = data.tickets.find(t => Number(t.assigned_to || 0) === Number(agentSession?.id || 0)) || data.tickets[0];
        if (preferred?.ticket_ref) {
            await openTicket(preferred.ticket_ref);
        }
    }
}

// ── TICKET DETAIL ──
async function openTicket(ref) {
    activeTicketRef = ref;
    loadTickets();
    connectSupportAdminSocketForActiveTicket();
    const data = await apiGet('get_ticket_admin', { ref });
    if (!data.success) return;
    const t = data.ticket, replies = data.replies, agents = data.agents, viewer = data.viewer;
    const effPri = t.agent_priority || t.user_priority;
    const canEdit     = ['admin','senior_agent','agent'].includes(viewer.role);
    const canAssign   = ['admin','senior_agent'].includes(viewer.role);
    const canInternal = ['admin','senior_agent','agent'].includes(viewer.role);
    const canDelete   = viewer.role === 'admin';
    const assignedToViewer = Number(t.assigned_to || 0) === Number(viewer.id || 0);
    const isUnassigned = Number(t.assigned_to || 0) === 0;
    const canTakeOver = ['admin','senior_agent'].includes(viewer.role);
    const canClaimTicket = canEdit && (isUnassigned || assignedToViewer || canTakeOver) && !['resolved','closed'].includes(String(t.status || ''));
    const canResolveTicket = canEdit && !['resolved','closed'].includes(String(t.status || ''));
    const canReopenTicket = canEdit && ['resolved','closed'].includes(String(t.status || ''));
    const statusOptions   = ['open','in_progress','waiting','resolved','closed'].map(s => `<option value="${s}" ${t.status===s?'selected':''}>${s.replace('_',' ')}</option>`).join('');
    const priorityOptions = ['low','medium','high','critical'].map(p => `<option value="${p}" ${effPri===p?'selected':''}>${p}</option>`).join('');
    const agentOptions    = `<option value="">Unassigned</option>` + agents.map(a => `<option value="${a.id}" ${t.assigned_to==a.id?'selected':''}>${a.username} (${a.role.replace('_',' ')})</option>`).join('');
    const detail = document.getElementById('ticketDetail');
    document.getElementById('emptyPanel').style.display = 'none';
    detail.style.display = 'flex';
    if (window.innerWidth <= 900) setMobilePane('detail');
    detail.innerHTML = `
        <div class="ticket-header">
            <div class="ticket-header-info">
                <div class="ticket-header-ref">${t.ticket_ref} · ${escHtml(t.username||t.guest_name||'Guest')} · ${formatDate(t.created_at)}</div>
                <div class="ticket-header-subject">${escHtml(t.subject)}</div>
                <div class="ticket-header-badges">
                    <span class="status ${t.status}">${t.status.replace('_',' ')}</span>
                    <span class="pri ${effPri}">${effPri}</span>
                    <span style="font-size:11px;color:var(--text-muted)">${t.category.replace('_',' ')}</span>
                    ${t.user_email||t.guest_email?`<span style="font-size:11px;color:var(--text-muted)">${t.user_email||t.guest_email}</span>`:''}
                </div>
            </div>
            ${canEdit?`<div class="ticket-controls">
                <div><div class="ctrl-label">Status</div><select class="ctrl-select" onchange="updateTicket('status',this.value)">${statusOptions}</select></div>
                <div><div class="ctrl-label">Priority</div><select class="ctrl-select" onchange="updateTicket('agent_priority',this.value)">${priorityOptions}</select></div>
                ${canAssign?`<div><div class="ctrl-label">Assigned To</div><select class="ctrl-select" onchange="updateTicket('assigned_to',this.value)">${agentOptions}</select></div>`:''}
                ${canClaimTicket?`<button class="btn-sm" style="margin-top:4px" onclick="claimTicket(${Number(t.id)})">Claim Ticket</button>`:''}
                ${canResolveTicket?`<button class="btn-sm" style="border-color:rgba(34,197,94,0.45);color:var(--success);margin-top:4px" onclick="resolveTicket(${Number(t.id)})">Resolve Ticket</button>`:''}
                ${canReopenTicket?`<button class="btn-sm" style="border-color:rgba(245,158,11,0.45);color:var(--warn);margin-top:4px" onclick="reopenTicket(${Number(t.id)})">Reopen Ticket</button>`:''}
                ${canDelete && Number(t.user_id) > 0 ?`<button class="btn-sm" style="border-color:#f59e0b;color:#f59e0b;margin-top:4px" onclick="deleteUserFromTicket()">Delete User Data</button>`:''}
                ${canDelete?`<button class="btn-sm" style="border-color:var(--error);color:var(--error);margin-top:4px" onclick="deleteActiveTicket()">Delete Ticket</button>`:''}
            </div>`:''}
        </div>
        <div class="replies-area" id="repliesArea">
            <div class="reply-bubble user-msg">
                <div class="reply-header"><span class="reply-author">${escHtml(t.username||t.guest_name||'Guest')}</span><span>${formatDate(t.created_at)}</span></div>
                <div class="reply-body">${escHtml(t.body)}</div>
            </div>
            ${replies.map(r => {
                if (r.author_type==='system') return `<div class="reply-bubble system-msg"><div class="reply-body system">— ${escHtml(r.message)} —</div></div>`;
                const cls = r.author_type==='agent'?(r.internal?'internal-msg':'agent-msg'):'user-msg';
                const who = r.author_type==='agent'?`⚡ ${escHtml(r.agent_name||'Agent')}${r.internal?' [internal]':''}`:escHtml(t.username||t.guest_name||'User');
                return `<div class="reply-bubble ${cls}"><div class="reply-header"><span class="reply-author">${who}</span><span>${formatDate(r.created_at)}</span></div><div class="reply-body">${escHtml(r.message)}</div></div>`;
            }).join('')}
        </div>
        ${canEdit?`<div class="reply-box">
            <textarea class="reply-textarea" id="replyText" placeholder="Write a reply..."></textarea>
            <div class="reply-actions">
                <button id="replySendBtn" class="reply-btn primary" onclick="agentReply(false)">Send Reply</button>
                ${canInternal?`<button id="replyInternalBtn" class="reply-btn secondary" onclick="agentReply(true)">📝 Internal Note</button>`:''}
            </div>
        </div>`:''}
    `;
    const ra = document.getElementById('repliesArea');
    if (ra) ra.scrollTop = ra.scrollHeight;
    updateAiAssistTicketRef();
}

function updateAiAssistTicketRef() {
    const el = document.getElementById('aiAssistTicketRef');
    if (!el) return;
    if (!activeTicketRef) {
        el.textContent = 'Open a ticket to diagnose issues';
        return;
    }
    el.textContent = 'Context: ' + activeTicketRef;
}

function aiAssistBody() {
    return document.getElementById('aiAssistBody');
}

function appendAiAssistMessage(role, text) {
    const body = aiAssistBody();
    if (!body) return;
    const msg = document.createElement('div');
    msg.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
    msg.textContent = text;
    body.appendChild(msg);
    body.scrollTop = body.scrollHeight;
}

function appendAiTyping() {
    const body = aiAssistBody();
    if (!body) return null;
    const msg = document.createElement('div');
    msg.className = 'ai-msg bot ai-typing';
    msg.innerHTML = '<span class="ai-typing-dot"></span><span class="ai-typing-dot"></span><span class="ai-typing-dot"></span>';
    body.appendChild(msg);
    body.scrollTop = body.scrollHeight;
    return msg;
}

function toggleAiAssist(force) {
    const panel = document.getElementById('aiAssistPanel');
    const launcher = document.getElementById('aiAssistLauncher');
    if (!panel) return;
    aiAssistOpen = typeof force === 'boolean' ? force : !aiAssistOpen;
    panel.classList.toggle('open', aiAssistOpen);
    if (launcher) launcher.classList.toggle('active', aiAssistOpen);
    updateAiAssistTicketRef();
    if (aiAssistOpen) {
        if (!aiAssistBody()?.children.length) {
            appendAiAssistMessage('bot', 'I can help diagnose the current ticket. Ask for likely causes, checks, and a suggested customer reply.');
        }
        const input = document.getElementById('aiAssistInput');
        if (input) input.focus();
    }
}

async function sendAiAssist() {
    if (aiAssistBusy) return;
    const input = document.getElementById('aiAssistInput');
    const button = document.getElementById('aiAssistSend');
    if (!input || !button) return;

    const question = input.value.trim();
    if (!question) return;
    if (!activeTicketRef) {
        showToast('Open a ticket first', 'error');
        return;
    }

    appendAiAssistMessage('user', question);
    input.value = '';
    aiAssistBusy = true;
    button.disabled = true;
    button.textContent = 'Thinking...';
    const typing = appendAiTyping();

    const data = await api('agent_ai_assist', { ref: activeTicketRef, question }).catch(() => null);
    if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
    if (!data?.success || !data.answer) {
        appendAiAssistMessage('bot', data?.error || 'Assistant is unavailable right now.');
    } else {
        appendAiAssistMessage('bot', String(data.answer));
    }

    aiAssistBusy = false;
    button.disabled = false;
    button.textContent = 'Send';
}

async function updateTicket(field, value) {
    if (!activeTicketRef) return;
    const ticket = await apiGet('get_ticket_admin', { ref: activeTicketRef });
    if (!ticket.success) return;
    const data = await api('update_ticket', { ticket_id: ticket.ticket.id, field, value });
    if (data.success) {
        showToast('✓ Updated','success');
        loadStats();
        loadTickets();
        openTicket(activeTicketRef);
    } else {
        showToast(data.error||'Failed','error');
    }
}

async function claimTicket(ticketId) {
    const data = await api('claim_ticket', { ticket_id: ticketId }).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Failed to claim ticket', 'error');
        return;
    }
    showToast(data.message || '✓ Ticket claimed', 'success');
    await loadStats();
    await loadTickets();
    if (activeTicketRef) await openTicket(activeTicketRef);
}

async function resolveTicket(ticketId) {
    if (!confirm('Resolve this ticket now?')) return;
    const data = await api('resolve_ticket', { ticket_id: ticketId }).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Failed to resolve ticket', 'error');
        return;
    }
    showToast(data.message || '✓ Ticket resolved', 'success');
    await loadStats();
    await loadTickets();
    if (activeTicketRef) await openTicket(activeTicketRef);
}

async function reopenTicket(ticketId) {
    if (!confirm('Reopen this ticket?')) return;
    const data = await api('reopen_ticket', { ticket_id: ticketId }).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Failed to reopen ticket', 'error');
        return;
    }
    showToast(data.message || '✓ Ticket reopened', 'success');
    await loadStats();
    await loadTickets();
    if (activeTicketRef) await openTicket(activeTicketRef);
}

async function agentReply(internal) {
    const clickedBtn = document.getElementById(internal ? 'replyInternalBtn' : 'replySendBtn');
    const sendBtn = document.getElementById('replySendBtn');
    const internalBtn = document.getElementById('replyInternalBtn');

    const setSavingState = (saving) => {
        if (!clickedBtn) return;
        if (saving) {
            clickedBtn.dataset.prevLabel = clickedBtn.textContent || '';
            clickedBtn.disabled = true;
            clickedBtn.textContent = 'Saving...';
            if (sendBtn && sendBtn !== clickedBtn) sendBtn.disabled = true;
            if (internalBtn && internalBtn !== clickedBtn) internalBtn.disabled = true;
            return;
        }
        clickedBtn.disabled = false;
        clickedBtn.textContent = clickedBtn.dataset.prevLabel || (internal ? '📝 Internal Note' : 'Send Reply');
        if (sendBtn && sendBtn !== clickedBtn) sendBtn.disabled = false;
        if (internalBtn && internalBtn !== clickedBtn) internalBtn.disabled = false;
    };

    const msg = document.getElementById('replyText')?.value.trim();
    if (!msg) {
        showToast(internal ? 'Write an internal note first' : 'Write a reply first', 'error');
        return;
    }
    if (!activeTicketRef) {
        showToast('Open a ticket first', 'error');
        return;
    }

    setSavingState(true);
    const ticket = await apiGet('get_ticket_admin', { ref: activeTicketRef });
    if (!ticket.success) {
        setSavingState(false);
        return;
    }
    const data = await api('agent_reply', { ticket_id: ticket.ticket.id, message: msg, internal: internal ? 1 : 0 });
    if (data.success) { document.getElementById('replyText').value=''; openTicket(activeTicketRef); loadTickets(); }
    else {
        setSavingState(false);
        showToast(data.error||'Failed','error');
    }
}

async function deleteActiveTicket() {
    if (!activeTicketRef) return;
    const ticket = await apiGet('get_ticket_admin', { ref: activeTicketRef });
    if (!ticket.success) {
        showToast('Unable to load ticket', 'error');
        return;
    }
    const ref = ticket.ticket.ticket_ref;
    if (!confirm(`Delete ticket ${ref} permanently? This cannot be undone.`)) return;

    const data = await api('delete_ticket', { ticket_id: ticket.ticket.id });
    if (!data.success) {
        showToast(data.error || 'Delete failed', 'error');
        return;
    }

    activeTicketRef = null;
    closeSupportAdminSocket(true);
    document.getElementById('ticketDetail').style.display = 'none';
    document.getElementById('emptyPanel').style.display = 'flex';
    if (window.innerWidth <= 900) setMobilePane('list');
    showToast(`Deleted ${ref}`, 'success');
    loadTickets();
}

async function deleteUserFromTicket() {
    if (!activeTicketRef) return;
    const ticket = await apiGet('get_ticket_admin', { ref: activeTicketRef });
    if (!ticket.success) {
        showToast('Unable to load ticket', 'error');
        return;
    }

    const t = ticket.ticket;
    const uid = Number(t.user_id || 0);
    if (uid <= 0) {
        showToast('This ticket is not linked to a user account', 'error');
        return;
    }

    const label = t.username || `user #${uid}`;
    if (!confirm(`Delete all data for ${label}? This will delete the account and close this ticket.`)) return;

    const data = await api('delete_user_from_ticket', { ticket_id: t.id });
    if (!data.success) {
        showToast(data.error || 'User deletion failed', 'error');
        return;
    }

    showToast(`Deleted user data for ${label}`, 'success');
    loadTickets();
    openTicket(activeTicketRef);
}

// ── AGENT MANAGER ──
async function showAgentManager() {
    const data = await api('list_agents');
    const list = document.getElementById('agentList');
    if (!data.success) { list.innerHTML = '<p style="color:var(--error);font-size:12px">Failed to load agents</p>'; }
    else {
        list.innerHTML = data.agents.map(a => `
            <div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:8px;margin-bottom:6px">
                <span class="role-badge ${a.role}">${a.role.replace('_',' ')}</span>
                <span style="flex:1;font-size:12px">${escHtml(a.username)} <span style="color:var(--text-muted)">${escHtml(a.email)}</span></span>
                <span style="font-size:10px;color:${a.active?'var(--success)':'var(--error)'}">${a.active?'active':'inactive'}</span>
                <select onchange="updateAgent(${a.id},this.value,${a.active})" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:3px 6px;font-size:11px;font-family:'DM Mono',monospace">
                    ${['trial_agent','agent','senior_agent','admin'].map(r=>`<option value="${r}" ${a.role===r?'selected':''}>${r.replace('_',' ')}</option>`).join('')}
                </select>
            </div>`).join('');
    }
    document.getElementById('agentModal').style.display = 'flex';
}

async function updateAgent(id, role, active) { await api('update_agent',{agent_id:id,role,active}); showAgentManager(); }

async function createAgent() {
    const data = await api('create_agent',{
        username: document.getElementById('newAgentUser').value.trim(),
        email:    document.getElementById('newAgentEmail').value.trim(),
        password: document.getElementById('newAgentPass').value,
        role:     document.getElementById('newAgentRole').value,
    });
    if (data.success) { showToast('✓ Agent created','success'); showAgentManager(); }
    else document.getElementById('agentErr').textContent = data.error||'Failed';
}

// ── USER MANAGER ──
async function showUserManager() {
    document.getElementById('userModal').style.display = 'flex';
    await searchUsers(true);
    if (requestedUserId > 0) {
        await openUserAccount(requestedUserId);
        if (requestedUserFocus) {
            jumpUserSection(requestedUserFocus);
        }
    }
}

function debounceUserSearch() {
    clearTimeout(userSearchTimer);
    userSearchTimer = setTimeout(() => searchUsers(false), 250);
}

function userPermissionPills(perms = {}) {
    const pills = [];
    if (perms.can_view_users) pills.push('<button type="button" class="user-shortcut-btn" onclick="jumpUserSection(\'search\')">View Accounts</button>');
    if (perms.can_edit_profile) pills.push('<button type="button" class="user-shortcut-btn" onclick="jumpUserSection(\'profile\')">Edit Profile</button>');
    if (perms.can_edit_billing) pills.push('<button type="button" class="user-shortcut-btn" onclick="jumpUserSection(\'billing\')">Plan & Credits</button>');
    if (perms.can_edit_security) pills.push('<button type="button" class="user-shortcut-btn" onclick="jumpUserSection(\'security\')">Security Reset</button>');
    return pills.length ? `<div class="user-shortcuts">${pills.join('')}</div>` : '';
}

function jumpUserSection(section) {
    const map = {
        search: document.getElementById('userSearchPanel') || document.getElementById('userSearchInput'),
        profile: document.getElementById('userSectionProfile'),
        billing: document.getElementById('userSectionBilling'),
        security: document.getElementById('userSectionSecurity'),
    };
    const target = map[section];
    if (!target) return;
    if (section === 'search') {
        document.getElementById('userSearchInput')?.focus();
    }
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    target.classList.add('flash');
    setTimeout(() => target.classList.remove('flash'), 1200);
}

async function searchUsers(forceRefresh = false) {
    const query = document.getElementById('userSearchInput')?.value.trim() || '';
    const wrap = document.getElementById('userSearchResults');
    if (!wrap) return;
    wrap.innerHTML = '<div style="padding:18px;text-align:center;color:var(--text-muted);font-size:12px;border:1px dashed var(--border);border-radius:12px">Loading users...</div>';
    const data = await apiGet('search_users', { q: query }).catch(() => null);
    if (!data?.success) {
        wrap.innerHTML = `<div style="padding:18px;text-align:center;color:var(--error);font-size:12px;border:1px dashed rgba(239,68,68,0.3);border-radius:12px">${escHtml(data?.error || 'Failed to load users')}</div>`;
        return;
    }
    userSearchResults = data.users || [];
    userManagerPermissions = data.permissions || userManagerPermissions;
    const note = document.getElementById('userPermissionNote');
    if (note) {
        note.textContent = userManagerPermissions?.can_edit_security
            ? 'Admin access: full view, profile edits, billing changes, and security resets are enabled.'
            : (userManagerPermissions?.can_edit_profile
                ? 'Senior agent access: you can update profile details, plan, and credits.'
                : 'View-only access: you can search and inspect user accounts.');
    }
    renderUserSearchResults();
    if (forceRefresh && !activeUserAccountId && userSearchResults.length) {
        openUserAccount(userSearchResults[0].id);
    }
}

function renderUserSearchResults() {
    const wrap = document.getElementById('userSearchResults');
    if (!wrap) return;
    if (!userSearchResults.length) {
        wrap.innerHTML = '<div style="padding:18px;text-align:center;color:var(--text-muted);font-size:12px;border:1px dashed var(--border);border-radius:12px">No users matched that search.</div>';
        return;
    }
    wrap.innerHTML = userSearchResults.map(u => `
        <div class="user-card ${Number(activeUserAccountId) === Number(u.id) ? 'active' : ''}" onclick="openUserAccount(${u.id})">
            <div class="user-card-top">
                <div class="user-card-name">${escHtml(u.username || ('User #' + u.id))}</div>
                <span class="pri ${escHtml(u.plan || 'free') === 'enterprise' ? 'critical' : (escHtml(u.plan || 'free') === 'pro' ? 'high' : (escHtml(u.plan || 'free') === 'basic' ? 'medium' : 'low'))}">${escHtml(u.plan || 'free')}</span>
            </div>
            <div class="user-card-meta">#${u.id} · ${escHtml(u.email || '')}<br>Credits: ${Number(u.credits || 0)} · ${u.email_verified ? 'Verified' : 'Unverified'} · ${u.two_factor_enabled ? '2FA on' : '2FA off'}</div>
        </div>`).join('');
}

async function openUserAccount(userId) {
    activeUserAccountId = Number(userId);
    renderUserSearchResults();
    const pane = document.getElementById('userDetailPane');
    if (!pane) return;
    pane.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Loading account...</div>';
    const data = await apiGet('get_user_account', { user_id: userId }).catch(() => null);
    if (!data?.success) {
        pane.innerHTML = `<div style="padding:20px;text-align:center;color:var(--error);font-size:12px">${escHtml(data?.error || 'Failed to load account')}</div>`;
        return;
    }
    userManagerPermissions = data.permissions || userManagerPermissions;
    renderUserAccount(data.user || {}, data.permissions || {}, data.recent_tickets || [], data.recent_audit || [], data.plan_options || []);
}

function renderUserAccount(user = {}, permissions = {}, recentTickets = [], recentAudit = [], planOptions = []) {
    const pane = document.getElementById('userDetailPane');
    if (!pane) return;
    const escAttr = (value) => String(value ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const profileDisabled = permissions.can_edit_profile ? '' : 'disabled';
    const billingDisabled = permissions.can_edit_billing ? '' : 'disabled';
    const securityDisabled = permissions.can_edit_security ? '' : 'disabled';
    const plans = (planOptions?.length ? planOptions : ['free','basic','pro','enterprise']).map(plan => `<option value="${escAttr(plan)}" ${String(user.plan || 'free') === String(plan) ? 'selected' : ''}>${escHtml(String(plan).replace('_',' '))}</option>`).join('');
    const ticketHtml = recentTickets.length
        ? recentTickets.map(t => `<div class="user-log-item"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><strong>${escHtml(t.ticket_ref || '')}</strong><span class="status ${escHtml(t.status || 'open')}">${escHtml(String(t.status || 'open').replace('_',' '))}</span></div><div style="margin-top:4px">${escHtml(t.subject || 'No subject')}</div><div class="user-inline-note">Updated ${formatDate(t.updated_at)}</div></div>`).join('')
        : '<div class="user-log-item" style="color:var(--text-muted)">No support tickets linked to this account yet.</div>';
    const auditHtml = recentAudit.length
        ? recentAudit.map(entry => {
            const fields = Array.isArray(entry.details?.fields) ? entry.details.fields.map(f => String(f).replace(/_/g, ' ')).join(', ') : '';
            return `<div class="user-log-item"><strong>${escHtml(entry.agent_username || 'Agent')}</strong> · ${escHtml(String(entry.action || '').replace(/_/g,' '))}${fields ? `<div class="user-inline-note">Fields: ${escHtml(fields)}</div>` : ''}<div class="user-inline-note">${formatDate(entry.created_at)}</div></div>`;
        }).join('')
        : '<div class="user-log-item" style="color:var(--text-muted)">No recent account changes logged.</div>';

    pane.innerHTML = `
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800">${escHtml(user.username || ('User #' + user.id))}</div>
                <div class="user-inline-note">User #${Number(user.id || 0)} · ${escHtml(user.email || '')}</div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">${userPermissionPills(permissions)}</div>
        </div>

        <div class="user-summary-grid">
            <div class="user-summary-item"><div class="k">Plan</div><div class="v">${escHtml(user.plan || 'free')}</div></div>
            <div class="user-summary-item"><div class="k">Credits</div><div class="v">${Number(user.credits || 0)}</div></div>
            <div class="user-summary-item"><div class="k">Joined</div><div class="v">${formatDate(user.created_at) || '—'}</div></div>
            <div class="user-summary-item"><div class="k">Email</div><div class="v">${user.email_verified ? 'Verified' : 'Not verified'}</div></div>
            <div class="user-summary-item"><div class="k">2FA</div><div class="v">${user.two_factor_enabled ? (escHtml(user.two_factor_method || 'enabled')) : 'disabled'}</div></div>
            <div class="user-summary-item"><div class="k">Linked Billing</div><div class="v">${user.has_linked_billing ? escHtml(user.billing_provider || 'yes') : 'none'}</div></div>
        </div>

        <div class="user-section-title user-target" id="userSectionProfile">Edit Profile</div>
        <div class="user-edit-grid user-target" id="userProfileFields">
            <div class="f-field"><label>Username</label><input type="text" id="umUsername" value="${escAttr(user.username || '')}" ${profileDisabled}></div>
            <div class="f-field"><label>Email</label><input type="email" id="umEmail" value="${escAttr(user.email || '')}" ${profileDisabled}></div>
        </div>

        <div class="user-section-title user-target" id="userSectionBilling">Plan & Credits</div>
        <div class="user-edit-grid user-target" id="userBillingFields">
            <div class="f-field"><label>Plan</label><select id="umPlan" ${billingDisabled}>${plans}</select></div>
            <div class="f-field"><label>Credits</label><input type="number" id="umCredits" min="0" value="${Number(user.credits || 0)}" ${billingDisabled}></div>
        </div>

        <div class="user-section-title user-target" id="userSectionSecurity">Security Reset</div>
        <div class="user-edit-grid user-target" id="userSecurityFields">
            <div class="f-field" style="display:flex;align-items:center;gap:8px;margin:0">
                <input type="checkbox" id="umVerified" ${user.email_verified ? 'checked' : ''} ${securityDisabled} style="width:auto;accent-color:var(--accent)">
                <label style="margin:0;font-size:11px;color:var(--text-muted)">Email verified</label>
            </div>
            <div class="user-note">${permissions.can_edit_security ? 'Admins can reset 2FA, revoke mobile tokens, disconnect Discord, and clear billing links.' : 'Security resets are restricted to admin staff.'}</div>
        </div>

        ${permissions.can_edit_security ? `
        <div class="user-section-title user-target">Reset Password</div>
        <div class="user-edit-grid user-target">
            <div class="user-note" style="grid-column:1/-1">
                Generates a secure temporary password and emails it to the user. They will be required to set a new password on next login.
            </div>
        </div>` : ''}

        <div class="user-action-row">
            ${(permissions.can_edit_profile || permissions.can_edit_billing || permissions.can_edit_security) ? '<button class="btn-sm" style="border-color:var(--accent);color:var(--accent-light)" onclick="saveUserAccount()">Save Changes</button>' : '<span class="user-note">This role can view accounts but cannot change them.</span>'}
            ${permissions.can_edit_security ? '<button class="btn-sm" onclick="adminResetPassword()">🔑 Reset Password</button>' : ''}
            ${permissions.can_edit_security ? '<button class="btn-sm" onclick="runUserSecurityAction(\'clear_two_factor\', \'Reset 2FA\', \'Reset two-factor auth for this account?\')">Reset 2FA</button>' : ''}
            ${permissions.can_edit_security ? '<button class="btn-sm" onclick="runUserSecurityAction(\'revoke_mobile_tokens\', \'Revoke Mobile Tokens\', \'Revoke all mobile sessions for this user?\')">Revoke Mobile Tokens</button>' : ''}
            ${permissions.can_edit_security ? '<button class="btn-sm" onclick="runUserSecurityAction(\'clear_discord_link\', \'Disconnect Discord\', \'Disconnect this user from Discord sync?\')">Disconnect Discord</button>' : ''}
            ${permissions.can_edit_security ? '<button class="btn-sm" onclick="runUserSecurityAction(\'clear_billing_links\', \'Clear Billing Links\', \'Clear linked PayPal and Apple billing IDs for this user?\')">Clear Billing Links</button>' : ''}
        </div>

        <div class="user-section-title">Recent Tickets</div>
        <div class="user-log-list">${ticketHtml}</div>

        <div class="user-section-title">Recent Audit Log</div>
        <div class="user-log-list">${auditHtml}</div>`;
}

async function saveUserAccount() {
    if (!activeUserAccountId) return;
    const payload = { user_id: activeUserAccountId };
    payload.username = document.getElementById('umUsername')?.value.trim() || '';
    payload.email = document.getElementById('umEmail')?.value.trim() || '';
    payload.plan = document.getElementById('umPlan')?.value || '';
    payload.credits = document.getElementById('umCredits')?.value || '0';
    if (userManagerPermissions?.can_edit_security) {
        payload.email_verified = document.getElementById('umVerified')?.checked ? 1 : 0;
    }
    const data = await api('update_user_account', payload).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Failed to update user', 'error');
        return;
    }
    showToast(data.message || '✓ User updated', 'success');
    await searchUsers(false);
    await openUserAccount(activeUserAccountId);
}

async function adminResetPassword() {
    if (!activeUserAccountId || !userManagerPermissions?.can_edit_security) return;
    if (!confirm('Generate a temporary password and email it to the user? Their existing sessions will be revoked.')) return;
    const data = await api('update_user_account', { user_id: activeUserAccountId, reset_password: 'auto' }).catch(() => null);
    if (!data?.success) { showToast(data?.error || 'Password reset failed', 'error'); return; }
    if (data.generated_password) {
        // Show the temp password to the agent so they can share it if email fails
        const msg = 'Password reset. Temporary password:\n\n' + data.generated_password + '\n\nAn email has been sent to the user.';
        alert(msg);
    } else {
        showToast('Password reset successfully — email sent to user', 'success');
    }
    await openUserAccount(activeUserAccountId);
}

async function runUserSecurityAction(flag, label, promptText) {
    if (!activeUserAccountId || !userManagerPermissions?.can_edit_security) return;
    if (!confirm(promptText)) return;
    const payload = { user_id: activeUserAccountId };
    payload[flag] = 1;
    const data = await api('update_user_account', payload).catch(() => null);
    if (!data?.success) {
        showToast(data?.error || (label + ' failed'), 'error');
        return;
    }
    showToast(label + ' complete', 'success');
    await searchUsers(false);
    await openUserAccount(activeUserAccountId);
}

// ── CONFIG ──
async function showConfig() {
    document.getElementById('configModal').style.display = 'flex';
    await loadSupportConfig(true);
}

async function loadSupportConfig(force = false) {
    if (supportConfigCache && !force) {
        populateConfigFields(supportConfigCache);
        return supportConfigCache;
    }
    const data = await apiGet('get_admin_config').catch(() => null);
    if (!data?.success) {
        showToast(data?.error || 'Failed to load config', 'error');
        return null;
    }
    supportConfigCache = data.config || {};
    populateConfigFields(supportConfigCache);
    return supportConfigCache;
}

function populateConfigFields(config) {
    const smtp = config?.smtp || {};
    const discord = config?.discord || {};
    const roles = discord.roles || {};

    document.getElementById('cfSmtpHost').value = smtp.smtp_host || '';
    document.getElementById('cfSmtpPort').value = smtp.smtp_port || '';
    document.getElementById('cfSmtpUser').value = smtp.smtp_user || '';
    document.getElementById('cfSmtpPass').value = smtp.smtp_pass || '';
    document.getElementById('cfSmtpFrom').value = smtp.smtp_from || '';
    document.getElementById('cfSupportEmail').value = smtp.support_email || '';
    document.getElementById('cfWebhook').value = discord.webhook_url || '';
    ['low','medium','high','critical'].forEach(priority => {
        document.getElementById('cf_' + priority + '_id').value = roles[priority]?.role_id || '';
        document.getElementById('cf_' + priority + '_name').value = roles[priority]?.role_name || '';
    });

    emailTemplatesState = JSON.parse(JSON.stringify(config?.email_templates || {}));
    renderTemplateList();

    if (!activeTemplateId || !emailTemplatesState[activeTemplateId]) {
        activeTemplateId = Object.keys(emailTemplatesState)[0] || null;
    }
    loadActiveTemplate();

    const canSave = agentSession?.role === 'admin';
    ['saveSmtpBtn','saveDiscordBtn','tplSaveBtn','tplResetBtn','tplSendTestBtn','tplSendInlineBtn'].forEach(id => {
        const button = document.getElementById(id);
        if (button) button.disabled = !canSave;
    });
}

function renderTemplateList() {
    const list = document.getElementById('emailTemplateList');
    const ids = Object.keys(emailTemplatesState || {});
    if (!ids.length) {
        list.innerHTML = '<div style="padding:18px;text-align:center;color:var(--text-muted);font-size:12px;border:1px dashed var(--border);border-radius:12px">No templates configured.</div>';
        return;
    }
    list.innerHTML = ids.map(id => {
        const tpl = emailTemplatesState[id] || {};
        return `<button type="button" class="template-card ${id === activeTemplateId ? 'active' : ''}" onclick="selectEmailTemplate('${id}')">
            <div class="template-card-title">${escHtml(tpl.label || id)}</div>
            <div class="template-card-desc">${escHtml(tpl.description || '')}</div>
        </button>`;
    }).join('');
}

function selectEmailTemplate(templateId) {
    persistActiveTemplateDraft();
    activeTemplateId = templateId;
    renderTemplateList();
    loadActiveTemplate();
}

function persistActiveTemplateDraft() {
    if (!activeTemplateId || !emailTemplatesState[activeTemplateId]) return;
    emailTemplatesState[activeTemplateId].subject = document.getElementById('tplSubject')?.value || '';
    emailTemplatesState[activeTemplateId].body = document.getElementById('tplBody')?.value || '';
}

function loadActiveTemplate() {
    const tpl = activeTemplateId ? emailTemplatesState[activeTemplateId] : null;
    document.getElementById('tplEditorTitle').textContent = tpl?.label || 'Select a template';
    document.getElementById('tplEditorDesc').textContent = tpl?.description || 'Choose a template from the left to edit its subject and HTML body.';
    document.getElementById('tplSubject').value = tpl?.subject || '';
    document.getElementById('tplBody').value = tpl?.body || '';
    document.getElementById('tplPlaceholders').innerHTML = tpl?.placeholders?.length
        ? tpl.placeholders.map(name => `<span class="template-pill">{{${escHtml(name)}}}</span>`).join('')
        : '<span style="font-size:11px;color:var(--text-muted)">No placeholders for this template.</span>';
    renderTemplatePreview();
}

function templatePreviewVars() {
    return {
        ticket_ref: 'TKT-DEMO42',
        priority: 'high',
        priority_upper: 'HIGH',
        category: 'account',
        from_name: 'Jane Doe',
        from_email: 'jane@example.com',
        email: 'jane@example.com',
        subject: 'Delete my account',
        message: 'Hello support, please help me with my account request.\nThis is a sample preview message.',
        message_html: 'Hello support, please help me with my account request.<br>This is a sample preview message.',
        dashboard_url: 'http://lyralinkai.com/pages/support_admin/',
        ticket_url: 'http://lyralinkai.com/pages/support/',
        login_url: 'http://lyralinkai.com/chat',
        agent_name: 'Support Agent Nova',
        username: 'JaneDoe',
        code: '482931',
        expires_minutes: '15',
        status: 'closed',
        status_label: 'Closed',
        support_email: document.getElementById('cfSupportEmail')?.value || 'support@lyralinkai.com',
    };
}

function renderTemplateString(template, vars) {
    return String(template || '').replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, key) => {
        const normalized = String(key || '').toLowerCase();
        return Object.prototype.hasOwnProperty.call(vars, normalized) ? String(vars[normalized]) : '';
    });
}

function renderTemplatePreview() {
    const preview = document.getElementById('tplPreview');
    const tpl = activeTemplateId ? emailTemplatesState[activeTemplateId] : null;
    if (!preview) return;
    if (!tpl) {
        preview.innerHTML = '<div style="color:var(--text-muted);font-size:12px">Select a template to preview it.</div>';
        return;
    }
    persistActiveTemplateDraft();
    const vars = templatePreviewVars();
    const subject = renderTemplateString(tpl.subject || '', vars);
    const body = renderTemplateString(tpl.body || '', vars);
    preview.innerHTML = `
        <div class="template-preview-subject"><strong>Subject:</strong> ${escHtml(subject)}</div>
        <div class="template-preview-frame">
            <h2>Lyralink Support</h2>
            <div class="preview-ref">Ticket: ${escHtml(vars.ticket_ref)}</div>
            <h3>${escHtml(subject || tpl.label || 'Preview')}</h3>
            <div class="preview-body">${body}</div>
            <div class="preview-footer">Lyralink Support Team <a href="${escHtml(vars.ticket_url)}" style="color:#7c3aed">View Ticket</a></div>
        </div>`;
}

function resetActiveTemplate() {
    if (!activeTemplateId || !emailTemplatesState[activeTemplateId] || agentSession?.role !== 'admin') return;
    const tpl = emailTemplatesState[activeTemplateId];
    tpl.subject = tpl.default_subject || '';
    tpl.body = tpl.default_body || '';
    loadActiveTemplate();
}

async function saveSmtp() {
    const data = await api('set_smtp',{
        smtp_host: document.getElementById('cfSmtpHost').value,
        smtp_port: document.getElementById('cfSmtpPort').value,
        smtp_user: document.getElementById('cfSmtpUser').value,
        smtp_pass: document.getElementById('cfSmtpPass').value,
        smtp_from: document.getElementById('cfSmtpFrom').value,
        support_email: document.getElementById('cfSupportEmail').value,
    });
    if (data.success && supportConfigCache?.smtp) {
        supportConfigCache.smtp.support_email = document.getElementById('cfSupportEmail').value;
    }
    renderTemplatePreview();
    showToast(data.success?'✓ SMTP saved':'Failed', data.success?'success':'error');
}

async function saveDiscordConfig() {
    const body = { webhook_url: document.getElementById('cfWebhook').value };
    ['low','medium','high','critical'].forEach(p => {
        body[p+'_role_id']   = document.getElementById('cf_'+p+'_id')?.value||'';
        body[p+'_role_name'] = document.getElementById('cf_'+p+'_name')?.value||'';
    });
    const data = await api('set_discord_roles', body);
    showToast(data.success?'✓ Discord config saved':'Failed', data.success?'success':'error');
}

async function saveEmailTemplates() {
    if (agentSession?.role !== 'admin') {
        showToast('Only admins can save templates', 'error');
        return;
    }
    persistActiveTemplateDraft();
    const payload = {};
    Object.entries(emailTemplatesState || {}).forEach(([id, tpl]) => {
        payload[id] = {
            subject: tpl.subject || '',
            body: tpl.body || '',
        };
    });
    const data = await api('set_email_templates', { templates: JSON.stringify(payload) });
    if (!data?.success) {
        showToast(data?.error || 'Failed to save templates', 'error');
        return;
    }
    if (supportConfigCache) {
        supportConfigCache.email_templates = data.templates || payload;
    }
    emailTemplatesState = JSON.parse(JSON.stringify(data.templates || {}));
    renderTemplateList();
    loadActiveTemplate();
    showToast('✓ Email templates saved', 'success');
}

async function sendTemplateTestEmail() {
    if (agentSession?.role !== 'admin') {
        showToast('Only admins can send test emails', 'error');
        return;
    }
    if (!activeTemplateId || !emailTemplatesState[activeTemplateId]) {
        showToast('Select a template first', 'error');
        return;
    }
    persistActiveTemplateDraft();
    const recipient = document.getElementById('tplTestRecipient')?.value.trim() || document.getElementById('cfSupportEmail')?.value.trim() || '';
    if (!recipient) {
        showToast('Enter a test recipient email', 'error');
        return;
    }
    const template = emailTemplatesState[activeTemplateId];
    const data = await api('send_test_email_template', {
        template_id: activeTemplateId,
        recipient,
        subject: template.subject || '',
        body: template.body || '',
    });
    showToast(data?.success ? '✓ Test email sent' : (data?.error || 'Failed to send test email'), data?.success ? 'success' : 'error');
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// ── QUEUE MONITOR ──
async function showQueueMonitor() {
    document.getElementById('queueModal').style.display = 'flex';
    loadQueueMonitor();
}

async function loadQueueMonitor() {
    const summary = document.getElementById('queueSummary');
    const jobs = document.getElementById('queueJobs');
    summary.innerHTML = '<div style="padding:16px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text-muted);font-size:12px;grid-column:1/-1">Loading queue...</div>';
    jobs.innerHTML = '';

    const data = await apiGet('notification_queue_status').catch(() => null);
    if (!data?.success) {
        summary.innerHTML = '<div style="padding:16px;background:var(--bg);border:1px solid rgba(239,68,68,0.3);border-radius:10px;color:var(--error);font-size:12px;grid-column:1/-1">Failed to load queue status</div>';
        return;
    }

    summary.innerHTML = [
        { label: 'Pending', value: data.summary.pending },
        { label: 'Processing', value: data.summary.processing },
        { label: 'Failed', value: data.summary.failed },
        { label: 'Sent 24h', value: data.summary.sent_24h },
    ].map(card => `
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 14px;text-align:center">
            <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--accent-light)">${card.value}</div>
            <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">${card.label}</div>
        </div>`).join('');

    if (!data.jobs?.length) {
        jobs.innerHTML = '<div style="padding:16px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text-muted);font-size:12px;text-align:center">No pending or failed notification jobs</div>';
        return;
    }

    jobs.innerHTML = data.jobs.map(job => `
        <div style="background:var(--bg);border:1px solid ${job.status === 'failed' ? 'rgba(239,68,68,0.35)' : 'var(--border)'};border-radius:10px;padding:14px 16px;display:flex;gap:14px;align-items:flex-start">
            <div style="flex:1;min-width:0">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                    <span class="status ${job.status === 'failed' ? 'closed' : 'waiting'}">${escHtml(job.status)}</span>
                    <span class="pri ${job.channel === 'email' ? 'medium' : 'high'}">${escHtml(job.channel)}</span>
                    <span style="font-size:11px;color:var(--text-muted)">Job #${job.id}</span>
                    <span style="font-size:11px;color:var(--text-muted)">Attempts: ${job.attempts}</span>
                </div>
                <div style="font-size:13px;color:var(--text);margin-bottom:4px;word-break:break-word">${escHtml(job.target || 'Unknown target')}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Created ${formatDate(job.created_at)} · Available ${formatDate(job.available_at)}</div>
                ${job.last_error ? `<div style="font-size:11px;color:var(--error);white-space:pre-wrap">${escHtml(job.last_error)}</div>` : ''}
            </div>
            <div style="flex-shrink:0">
                ${job.status === 'failed' ? `<button class="btn-sm" onclick="retryQueueJob(${job.id})">Retry</button>` : ''}
            </div>
        </div>`).join('');
}

async function retryQueueJob(jobId) {
    const data = await api('retry_notification_job', { job_id: jobId });
    if (data.success) {
        showToast('✓ Queue job retried', 'success');
        loadQueueMonitor();
    } else {
        showToast(data.error || 'Retry failed', 'error');
    }
}

// ── TRANSCRIPTS ──
async function showTranscripts() {
    document.getElementById('transcriptModal').style.display = 'flex';
    loadTranscripts();
}

let transcriptSearchTimer = null;
function searchTranscripts() {
    clearTimeout(transcriptSearchTimer);
    transcriptSearchTimer = setTimeout(loadTranscripts, 300);
}

async function loadTranscripts() {
    const search = document.getElementById('transcriptSearch')?.value || '';
    const list   = document.getElementById('transcriptList');
    list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Loading...</div>';
    const data = await apiGet('list_transcripts', { search }).catch(() => null);
    if (!data?.success) { list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--error);font-size:12px">Failed to load</div>'; return; }
    if (!data.transcripts?.length) { list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">No transcripts yet</div>'; return; }
    list.innerHTML = data.transcripts.map(t => `
        <div onclick="viewTranscript(${t.id},'${escHtml(t.ticket_ref)}')" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;cursor:pointer;display:flex;align-items:center;gap:12px" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
            <div style="flex:1;min-width:0">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">${escHtml(t.ticket_ref)} · ${escHtml(t.category||'Unknown')}</div>
                <div style="font-size:13px;font-weight:600;margin-bottom:2px">#${escHtml(t.channel_name)}</div>
                <div style="font-size:11px;color:var(--text-muted)">Opened by ${escHtml(t.opened_by)} · Closed by ${escHtml(t.closed_by)}</div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-size:11px;color:var(--accent-light)">${t.message_count} messages</div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px">${formatDate(t.created_at)}</div>
            </div>
        </div>`).join('');
}

async function viewTranscript(id, ref) {
    document.getElementById('viewerTitle').textContent = `Transcript — ${ref}`;
    document.getElementById('transcriptContent').innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Loading...</div>';
    document.getElementById('transcriptViewer').style.display = 'flex';
    const data = await apiGet('get_transcript', { id }).catch(() => null);
    if (!data?.success) { document.getElementById('transcriptContent').innerHTML = '<div style="padding:20px;text-align:center;color:var(--error);font-size:12px">Failed to load</div>'; return; }
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:100%;height:65vh;border:none;border-radius:10px;background:#0a0a0f';
    iframe.srcdoc = data.transcript.transcript;
    document.getElementById('transcriptContent').innerHTML = '';
    document.getElementById('transcriptContent').appendChild(iframe);
}

// ── UTILS ──
function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }
function parseServerDate(input) {
    if (!input) return null;
    const normalized = String(input).trim().replace(' ', 'T');
    const hasZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized);
    const iso = hasZone ? normalized : normalized + 'Z';
    const parsed = new Date(iso);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}
function formatDate(d) {
    const parsed = parseServerDate(d);
    return parsed ? parsed.toLocaleString(undefined, { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }) : '';
}
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2500);
}

document.getElementById('aiAssistInput')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendAiAssist();
    }
});

function isEditableTarget(target) {
    if (!target) return false;
    const tag = String(target.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || target.isContentEditable;
}

document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isEditableTarget(e.target)) {
        e.preventDefault();
        document.getElementById('searchInput')?.focus();
        return;
    }
    if (e.key === 'Escape' && document.activeElement?.id === 'searchInput') {
        clearTicketSearch();
        document.getElementById('searchInput')?.blur();
    }
});

document.getElementById('tplSubject')?.addEventListener('input', renderTemplatePreview);
document.getElementById('tplBody')?.addEventListener('input', renderTemplatePreview);
window.addEventListener('beforeunload', () => closeSupportAdminSocket(true));

// ── STATUS MANAGER ──
async function showStatusManager() {
    document.getElementById('statusModal').style.display = 'flex';
    loadStatusServices();
    loadStatusIncidents();
}

async function loadStatusServices() {
    const res  = await fetch('/api/status.php?action=get_status').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;
    const list = document.getElementById('statusServiceList');
    if (!data?.success) { list.innerHTML = '<p style="color:var(--error);font-size:12px">Failed to load</p>'; return; }

    const statusOpts = ['operational','degraded','partial_outage','major_outage','maintenance'];
    list.innerHTML = data.services.map(s => `
        <div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;margin-bottom:6px">
            <span style="flex:1;font-size:12px;font-weight:600">${escHtml(s.name)}</span>
            <span style="font-size:10px;color:var(--text-muted)">${escHtml(s.category)}</span>
            <select onchange="updateServiceStatus(${s.id},this.value)" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:4px 8px;font-size:11px;font-family:'DM Mono',monospace">
                ${statusOpts.map(o => `<option value="${o}" ${s.status===o?'selected':''}>${o.replace('_',' ')}</option>`).join('')}
            </select>
        </div>`).join('');
}

async function loadStatusIncidents() {
    const res  = await fetch('/api/status.php?action=list_incidents').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;
    const sel  = document.getElementById('updateIncidentId');
    if (!data?.success) return;
    const active = data.incidents.filter(i => !i.resolved_at);
    sel.innerHTML = '<option value="">Select incident...</option>' +
        active.map(i => `<option value="${i.id}">[${i.status}] ${escHtml(i.title)}</option>`).join('');
}

async function updateServiceStatus(id, status) {
    const fd = new FormData();
    fd.append('action', 'update_service');
    fd.append('id', id);
    fd.append('status', status);
    const res  = await fetch('/api/status.php', { method:'POST', body:fd });
    const data = await res.json();
    if (!data?.success) {
        showToast(data?.error || 'Failed', 'error');
        return;
    }
    showToast('✓ Status updated', 'success');
    if (data.auto_incident_created && data.incident_id) {
        showToast(`🤖 Incident auto-created #${data.incident_id}`, 'success');
        loadStatusIncidents();
    }
}

async function createIncident() {
    const fd = new FormData();
    fd.append('action',  'create_incident');
    fd.append('title',   document.getElementById('incTitle').value.trim());
    fd.append('impact',  document.getElementById('incImpact').value);
    fd.append('status',  document.getElementById('incStatus').value);
    fd.append('message', document.getElementById('incMessage').value.trim());
    const res  = await fetch('/api/status.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        showToast('✓ Incident created', 'success');
        document.getElementById('incTitle').value   = '';
        document.getElementById('incMessage').value = '';
        loadStatusIncidents();
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

async function postIncidentUpdate() {
    const incId = document.getElementById('updateIncidentId').value;
    if (!incId) { showToast('Select an incident first', 'error'); return; }
    const fd = new FormData();
    fd.append('action',      'update_incident');
    fd.append('incident_id', incId);
    fd.append('status',      document.getElementById('updateIncStatus').value);
    fd.append('message',     document.getElementById('updateIncMessage').value.trim());
    const res  = await fetch('/api/status.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        showToast('✓ Update posted', 'success');
        document.getElementById('updateIncMessage').value = '';
        loadStatusIncidents();
    } else {
        showToast(data.error || 'Failed', 'error');
    }
}

// ── CAREERS ──
let appSearchTimer = null;

async function showCareers() {
    document.getElementById('careersModal').style.display = 'flex';
    careersTab('jobs');
}

function careersTab(tab) {
    document.getElementById('cJobsTab').style.display  = tab==='jobs'  ? 'block' : 'none';
    document.getElementById('cAppsTab').style.display  = tab==='applications' ? 'block' : 'none';
    document.getElementById('cJobEditor').style.display   = 'none';
    document.getElementById('cAppDetail').style.display   = 'none';
    document.getElementById('cTabJobs').style.borderColor = tab==='jobs'  ? 'var(--accent)' : '';
    document.getElementById('cTabApps').style.borderColor = tab==='applications' ? 'var(--accent)' : '';
    if (tab === 'jobs')         loadAdminJobs();
    if (tab === 'applications') { loadApplications(); loadJobsForFilter(); }
}

async function loadAdminJobs() {
    const list = document.getElementById('cJobList');
    list.innerHTML = '<div style="padding:12px;font-size:11px;color:var(--text-muted)">Loading...</div>';
    const data = await fetch('/api/careers.php?action=admin_list_jobs').then(r=>r.json()).catch(()=>null);
    if (!data?.success) { list.innerHTML = '<div style="padding:12px;font-size:11px;color:var(--error)">Failed to load</div>'; return; }
    if (!data.jobs.length) { list.innerHTML = '<div style="padding:20px;text-align:center;font-size:12px;color:var(--text-muted)">No jobs yet. Click "+ New Job" to add one.</div>'; return; }
    const typeMap = {full_time:'Full-time',part_time:'Part-time',contract:'Contract',internship:'Internship'};
    list.innerHTML = data.jobs.map(j => `
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px">
            <div style="width:8px;height:8px;border-radius:50%;background:${j.active?'var(--success)':'var(--text-muted)'};flex-shrink:0"></div>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600">${escHtml(j.title)}</div>
                <div style="font-size:10px;color:var(--text-muted)">${escHtml(j.department)} · ${typeMap[j.type]||j.type} · ${j.app_count} applications</div>
            </div>
            <button onclick="openJobEditor(${JSON.stringify(j).replace(/"/g,'&quot;')})" class="btn-sm">Edit</button>
            <button onclick="deleteJob(${j.id})" class="btn-sm" style="border-color:var(--error);color:var(--error)">Archive</button>
        </div>`).join('');
}

function openJobEditor(job) {
    const editor = document.getElementById('cJobEditor');
    editor.style.display = 'block';
    document.getElementById('cAppDetail').style.display = 'none';
    document.getElementById('editorTitle').textContent = job ? 'Edit Job' : 'New Job Listing';
    document.getElementById('edJobId').value    = job?.id || '';
    document.getElementById('edTitle').value    = job?.title || '';
    document.getElementById('edDept').value     = job?.department || 'Engineering';
    document.getElementById('edLocation').value = job?.location || 'Remote';
    document.getElementById('edType').value     = job?.type || 'full_time';
    document.getElementById('edSalMin').value   = job?.salary_min || '';
    document.getElementById('edSalMax').value   = job?.salary_max || '';
    document.getElementById('edDesc').value     = job?.description || '';
    document.getElementById('edReqs').value     = job?.requirements || '';
    document.getElementById('edPerks').value    = job?.perks || '';
    document.getElementById('edActive').checked = job ? !!parseInt(job.active) : true;
    editor.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

async function saveJob() {
    const fd = new FormData();
    fd.append('action',       'save_job');
    fd.append('id',           document.getElementById('edJobId').value);
    fd.append('title',        document.getElementById('edTitle').value.trim());
    fd.append('department',   document.getElementById('edDept').value.trim());
    fd.append('location',     document.getElementById('edLocation').value.trim());
    fd.append('type',         document.getElementById('edType').value);
    fd.append('salary_min',   document.getElementById('edSalMin').value);
    fd.append('salary_max',   document.getElementById('edSalMax').value);
    fd.append('salary_currency','USD');
    fd.append('description',  document.getElementById('edDesc').value.trim());
    fd.append('requirements', document.getElementById('edReqs').value.trim());
    fd.append('perks',        document.getElementById('edPerks').value.trim());
    fd.append('active',       document.getElementById('edActive').checked ? 1 : 0);
    const data = await fetch('/api/careers.php',{method:'POST',body:fd}).then(r=>r.json());
    if (data.success) {
        showToast('✓ Job saved','success');
        document.getElementById('cJobEditor').style.display = 'none';
        loadAdminJobs();
    } else showToast(data.error||'Failed','error');
}

async function deleteJob(id) {
    if (!confirm('Archive this job listing? It will be hidden from the public page.')) return;
    const fd = new FormData(); fd.append('action','delete_job'); fd.append('id',id);
    const data = await fetch('/api/careers.php',{method:'POST',body:fd}).then(r=>r.json());
    if (data.success) { showToast('Archived','success'); loadAdminJobs(); }
    else showToast('Failed','error');
}

async function loadJobsForFilter() {
    const data = await fetch('/api/careers.php?action=admin_list_jobs').then(r=>r.json()).catch(()=>null);
    const sel  = document.getElementById('cAppJobFilter');
    if (!data?.success) return;
    sel.innerHTML = '<option value="">All Jobs</option>' + data.jobs.map(j=>`<option value="${j.id}">${escHtml(j.title)}</option>`).join('');
}

async function loadApplications() {
    const jobId  = document.getElementById('cAppJobFilter').value;
    const status = document.getElementById('cAppStatusFilter').value;
    const search = document.getElementById('cAppSearch').value;
    const list   = document.getElementById('cAppList');
    list.innerHTML = '<div style="padding:12px;font-size:11px;color:var(--text-muted)">Loading...</div>';
    const params = new URLSearchParams({ action:'list_applications', job_id:jobId, status, search });
    const data   = await fetch('/api/careers.php?'+params).then(r=>r.json()).catch(()=>null);
    if (!data?.success) { list.innerHTML = '<div style="padding:12px;font-size:11px;color:var(--error)">Failed</div>'; return; }
    if (!data.applications.length) { list.innerHTML = '<div style="padding:20px;text-align:center;font-size:12px;color:var(--text-muted)">No applications found.</div>'; return; }
    const statusColors = {new:'var(--accent-light)',reviewing:'var(--yellow)',interview:'var(--success)',offer:'var(--success)',rejected:'var(--error)',withdrawn:'var(--text-muted)'};
    list.innerHTML = data.applications.map(a => `
        <div onclick="viewApplication(${a.id})" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:6px;cursor:pointer;display:flex;align-items:center;gap:12px" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600">${escHtml(a.name)}</div>
                <div style="font-size:10px;color:var(--text-muted)">${escHtml(a.email)} · ${escHtml(a.job_title||'Unknown role')}</div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-size:10px;font-weight:700;color:${statusColors[a.status]||'var(--text-muted)'}">${a.status.toUpperCase()}</div>
                <div style="font-size:10px;color:var(--text-muted)">${formatDate(a.applied_at)}</div>
            </div>
        </div>`).join('');
}

function debounceAppSearch() {
    clearTimeout(appSearchTimer);
    appSearchTimer = setTimeout(loadApplications, 300);
}

async function viewApplication(id) {
    const detail = document.getElementById('cAppDetail');
    detail.style.display = 'block';
    document.getElementById('cJobEditor').style.display = 'none';
    document.getElementById('cAppDetailContent').innerHTML = '<div style="font-size:11px;color:var(--text-muted)">Loading...</div>';
    detail.scrollIntoView({ behavior:'smooth', block:'nearest' });
    const data = await fetch(`/api/careers.php?action=get_application&id=${id}`).then(r=>r.json()).catch(()=>null);
    if (!data?.success) { document.getElementById('cAppDetailContent').innerHTML = '<p style="color:var(--error);font-size:12px">Failed to load</p>'; return; }
    const a = data.application;
    const statusOpts = ['new','reviewing','interview','offer','rejected','withdrawn'];
    document.getElementById('cAppDetailContent').innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800">${escHtml(a.name)}</div>
                <div style="font-size:11px;color:var(--text-muted)">${escHtml(a.job_title||'')} · Applied ${formatDate(a.applied_at)}</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                ${a.resume_name ? `<a href="/api/careers.php?action=download_resume&id=${a.id}" target="_blank" class="btn-sm">📄 Resume</a>` : ''}
                <select id="appStatusSel" onchange="updateApplication(${a.id})" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:5px 8px;font-size:11px;font-family:'DM Mono',monospace">
                    ${statusOpts.map(s=>`<option value="${s}" ${a.status===s?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('')}
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;font-size:11px;color:var(--text-muted)">
            <div>📧 ${escHtml(a.email)}</div>
            ${a.phone?`<div>📞 ${escHtml(a.phone)}</div>`:''}
            ${a.location?`<div>📍 ${escHtml(a.location)}</div>`:''}
            ${a.experience?`<div>⏱ ${escHtml(a.experience)} yrs exp</div>`:''}
            ${a.linkedin?`<div><a href="${escHtml(a.linkedin)}" target="_blank" style="color:var(--accent-light)">LinkedIn ↗</a></div>`:''}
            ${a.portfolio?`<div><a href="${escHtml(a.portfolio)}" target="_blank" style="color:var(--accent-light)">Portfolio ↗</a></div>`:''}
        </div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:6px">Cover Letter</div>
        <div style="font-size:12px;color:var(--text-dim);line-height:1.8;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px;white-space:pre-wrap;max-height:200px;overflow-y:auto">${escHtml(a.cover_letter)}</div>
        <div class="f-field" style="margin-bottom:10px"><label>Internal Notes</label>
            <textarea id="appNotes" style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 12px;font-family:'DM Mono',monospace;font-size:12px;outline:none;min-height:60px;resize:vertical">${escHtml(a.notes||'')}</textarea>
        </div>
        <button class="btn-full" onclick="updateApplication(${a.id})">Save Notes & Status</button>
    `;
}

async function updateApplication(id) {
    const fd = new FormData();
    fd.append('action', 'update_application');
    fd.append('id',     id);
    fd.append('status', document.getElementById('appStatusSel')?.value || 'new');
    fd.append('notes',  document.getElementById('appNotes')?.value || '');
    const data = await fetch('/api/careers.php',{method:'POST',body:fd}).then(r=>r.json());
    if (data.success) { showToast('✓ Updated','success'); loadApplications(); }
    else showToast(data.error||'Failed','error');
}

setInterval(loadTickets, 60000);
</script>
</body>
</html>