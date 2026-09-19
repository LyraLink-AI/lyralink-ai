<?php
session_start();
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) { header('Location: /pages/maintenance.php'); exit; }
if (empty($_SESSION['user_id'])) { header('Location: /?login=1&redirect=' . urlencode('/pages/reseller.php')); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Infrastructure — Operator Dashboard</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f;
            --surface: #121220;
            --surface-strong: #16162a;
            --border: #26263c;
            --accent: #7c3aed;
            --accent-light: #a78bfa;
            --accent-soft: rgba(124, 58, 237, 0.2);
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --text-dim: #cbd5e1;
            --success: #22c55e;
            --error: #ef4444;
            --warn: #f59e0b;
            --shadow: 0 18px 36px rgba(2, 6, 23, 0.35);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            scrollbar-width: thin;
            scrollbar-color: var(--accent) rgba(14,14,24,0.9);
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: var(--accent) rgba(14,14,24,0.9);
        }

        *::-webkit-scrollbar { width: 10px; height: 10px; }
        *::-webkit-scrollbar-track {
            background: rgba(14,14,24,0.9);
            border: 1px solid var(--border);
            border-radius: 999px;
        }
        *::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--accent-light), var(--accent));
            border-radius: 999px;
            border: 2px solid rgba(14,14,24,0.95);
        }
        *::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #c4b5fd, var(--accent));
        }
        *::-webkit-scrollbar-corner { background: transparent; }

        body {
            font-family: 'DM Mono', monospace;
            background: radial-gradient(circle at 14% -8%, rgba(124, 58, 237, 0.26), transparent 40%),
                        radial-gradient(circle at 84% 6%, rgba(59, 130, 246, 0.14), transparent 38%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image: linear-gradient(to right, rgba(148, 163, 184, 0.07) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(148, 163, 184, 0.07) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(circle at center, black 30%, transparent 100%);
        }

        nav {
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: rgba(10, 10, 15, 0.92);
            backdrop-filter: blur(10px);
            z-index: 10;
        }

        .nav-logo { height: 28px; width: auto; }
        .nav-title { font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; letter-spacing: 0.03em; }
        .nav-title span { color: var(--accent); }

        .nav-links { display: flex; gap: 8px; margin-left: auto; align-items: center; }

        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 12px;
            border: 1px solid var(--border);
            background: rgba(22, 22, 42, 0.75);
            padding: 6px 12px;
            border-radius: 999px;
            transition: all 0.2s;
        }

        .nav-link:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-1px);
        }

        .nav-badge {
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: var(--accent-soft);
            color: var(--accent-light);
            border: 1px solid rgba(124, 58, 237, 0.4);
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 32px 24px 80px;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.45s ease;
        }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: linear-gradient(130deg, rgba(18, 18, 32, 0.92), rgba(17, 24, 39, 0.9));
            box-shadow: var(--shadow);
        }

        h1 { font-family: 'Syne', sans-serif; font-size: 29px; font-weight: 800; line-height: 1.05; }
        h1 span { color: var(--accent); }
        .company-name { font-size: 13px; color: var(--text-muted); margin-top: 8px; }
        .header-actions { display: flex; gap: 8px; align-items: center; }

        .tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 24px;
            background: rgba(18, 18, 32, 0.82);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 6px;
            overflow-x: auto;
            box-shadow: 0 6px 18px rgba(2, 6, 23, 0.2);
        }

        .tab {
            flex: 0 0 auto;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 12px;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.22s;
            white-space: nowrap;
        }

        .tab:hover { color: var(--text); background: rgba(124, 58, 237, 0.14); }
        .tab.active { background: var(--accent); color: #ffffff; box-shadow: 0 8px 16px rgba(124, 58, 237, 0.34); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.25s ease; }

        .dashboard-tools {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
            align-items: end;
        }

        .tool-item label {
            display: block;
            font-size: 10px;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .tool-item select {
            width: 100%;
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            padding: 9px 10px;
        }

        .tool-item.toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #0f0f1a;
        }

        .tool-item.toggle input { width: auto; accent-color: var(--accent); }
        .tool-item.toggle span { font-size: 12px; color: var(--text-dim); }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            left: -50px;
            bottom: -58px;
            width: 130px;
            height: 130px;
            background: radial-gradient(circle, var(--accent-soft), transparent 70%);
        }

        .stat-card-wide {
            grid-column: span 2;
            min-height: 140px;
        }

        .insight-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 10px;
        }

        .insight-label { font-size: 11px; color: var(--text-muted); margin-bottom: 7px; }
        .insight-value { font-size: 12px; color: var(--text-dim); margin-bottom: 6px; }

        .progress-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.22);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #7c3aed, #a78bfa);
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        .stat-value { font-family: 'Syne', sans-serif; font-size: 27px; font-weight: 800; }
        .stat-value.accent { color: var(--accent); }
        .stat-value.green { color: var(--success); }
        .stat-sub { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .card-title .sub { font-size: 12px; font-weight: 400; color: var(--text-muted); }
        .client-actions { display: flex; gap: 8px; align-items: center; }
        .compact-input { width: 220px; padding: 8px 12px; font-size: 12px; }
        .compact-btn { padding: 8px 16px; font-size: 12px; }

        .invite-box {
            display: flex;
            gap: 8px;
            align-items: center;
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 12px;
            overflow: hidden;
        }

        .invite-url { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-dim); }

        .copy-btn {
            flex-shrink: 0;
            padding: 7px 14px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .copy-btn:hover { background: #0d6660; }
        .copy-btn.copied { background: var(--success); }

        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th {
            text-align: left;
            color: var(--text-muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: #141422;
        }
        td { padding: 11px 12px; border-bottom: 1px solid rgba(38, 38, 60, 0.72); color: var(--text-dim); }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(124, 58, 237, 0.08); }

        .plan-tag { padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 700; }
        .plan-free { background: rgba(100, 116, 139, 0.14); color: #94a3b8; }
        .plan-basic { background: rgba(21, 128, 61, 0.2); color: var(--success); }
        .plan-pro { background: rgba(124, 58, 237, 0.2); color: var(--accent-light); }
        .plan-enterprise { background: rgba(194, 65, 12, 0.2); color: var(--warn); }

        .btn-sm {
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 11px;
            cursor: pointer;
            border: 1px solid var(--border);
            background: #171728;
            color: var(--text-muted);
            transition: all 0.2s;
            font-family: 'DM Mono', monospace;
        }

        .btn-sm:hover { border-color: var(--error); color: var(--error); }

        .form-group { margin-bottom: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        label {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        input, select {
            width: 100%;
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            color: var(--text);
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }

        input:focus, select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.22);
        }

        .btn {
            padding: 11px 22px;
            border-radius: 10px;
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: white;
            box-shadow: 0 12px 22px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover { transform: translateY(-1px); filter: brightness(0.96); }

        .btn-outline {
            background: #171728;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .btn-outline:hover { border-color: var(--accent); color: var(--accent); }

        .code-block {
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
            font-size: 11px;
            color: var(--text-dim);
            overflow-x: auto;
            white-space: pre;
            line-height: 1.6;
            position: relative;
        }

        .code-copy {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 10px;
            cursor: pointer;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
        }

        .widget-preview {
            --preview-accent: #7c3aed;
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
            margin-bottom: 14px;
        }

        .widget-preview-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 4px 10px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .widget-preview-dots { display: inline-flex; gap: 5px; }
        .widget-preview-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2b2b40;
        }

        .widget-preview-canvas {
            position: relative;
            min-height: 270px;
            border: 1px solid #23233a;
            border-radius: 10px;
            background: radial-gradient(circle at 20% 15%, rgba(124, 58, 237, 0.08), transparent 42%), #0a0a14;
            overflow: hidden;
            padding: 16px;
        }

        .widget-preview-site {
            max-width: 82%;
            display: grid;
            gap: 8px;
        }

        .widget-preview-line {
            height: 10px;
            border-radius: 20px;
            background: rgba(148, 163, 184, 0.16);
        }

        .widget-preview-line.short { width: 45%; }
        .widget-preview-line.mid { width: 70%; }

        .widget-launcher-mock {
            position: absolute;
            right: 12px;
            bottom: 12px;
            border: none;
            border-radius: 999px;
            height: 42px;
            padding: 0 14px;
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            color: #fff;
            background: linear-gradient(135deg, var(--preview-accent), color-mix(in srgb, var(--preview-accent) 75%, #fff 25%));
            box-shadow: 0 8px 18px color-mix(in srgb, var(--preview-accent) 40%, transparent 60%);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            z-index: 2;
        }

        .widget-launcher-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.85);
        }

        .widget-panel-mock {
            position: absolute;
            right: 12px;
            bottom: 62px;
            width: 290px;
            border: 1px solid #2f2f48;
            border-radius: 14px;
            background: #0a0a13;
            box-shadow: 0 24px 34px rgba(0,0,0,0.42);
            transform: translateY(10px) scale(0.98);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            overflow: hidden;
            z-index: 3;
        }

        .widget-preview.open .widget-panel-mock {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        .widget-panel-head {
            background: var(--preview-accent);
            color: white;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .widget-panel-sub { opacity: 0.8; font-weight: 400; font-size: 10px; margin-top: 2px; }

        .widget-panel-body {
            padding: 10px;
            background: #0a0a13;
            display: grid;
            gap: 8px;
        }

        .widget-msg {
            max-width: 88%;
            border: 1px solid #26263e;
            border-radius: 10px;
            padding: 8px;
            font-size: 10px;
            color: #cbd5e1;
            line-height: 1.45;
        }

        .widget-msg.user {
            margin-left: auto;
            border-color: color-mix(in srgb, var(--preview-accent) 34%, #26263e 66%);
            color: #e9d5ff;
            background: color-mix(in srgb, var(--preview-accent) 14%, #101021 86%);
        }

        .widget-panel-input {
            border-top: 1px solid #25253d;
            padding: 8px 10px;
            color: #64748b;
            font-size: 10px;
        }

        .widget-preview-actions {
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .widget-preview-note {
            font-size: 11px;
            color: var(--text-muted);
        }

        .msg { padding: 10px 14px; border-radius: 10px; font-size: 12px; margin-bottom: 14px; display: none; }
        .msg.success { background: rgba(21, 128, 61, 0.1); border: 1px solid rgba(21, 128, 61, 0.26); color: var(--success); display: block; }
        .msg.error { background: rgba(185, 28, 28, 0.1); border: 1px solid rgba(185, 28, 28, 0.26); color: var(--error); display: block; }

        .loading { text-align: center; padding: 80px 20px; color: var(--text-muted); font-size: 13px; }

        .not-reseller {
            text-align: center;
            padding: 80px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .not-reseller-icon { font-size: 48px; margin-bottom: 16px; }
        .not-reseller h2 { font-family: 'Syne', sans-serif; font-size: 22px; font-weight: 700; margin-bottom: 12px; }
        .not-reseller p { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.7; }

        .pagination { display: flex; gap: 6px; justify-content: center; margin-top: 16px; }

        .page-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 11px;
            cursor: pointer;
            border: 1px solid var(--border);
            background: #171728;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            transition: all 0.2s;
        }

        .page-btn:hover, .page-btn.active {
            border-color: var(--accent);
            color: var(--accent-light);
            box-shadow: 0 6px 14px rgba(124, 58, 237, 0.22);
        }

        .commission-badge {
            padding: 4px 11px;
            background: var(--accent-soft);
            color: var(--accent-light);
            border: 1px solid rgba(124, 58, 237, 0.3);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .section-note { font-size: 13px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.7; }
        .helper-note { font-size: 11px; color: var(--text-muted); margin-top: 12px; }
        .inline-code { color: var(--accent); }
        .section-divider { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        .subtle-row { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
        .spaced-link { margin-left: 8px; }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .overview-kpi {
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
        }

        .overview-kpi .k { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .overview-kpi .v { font-family: 'Syne', sans-serif; font-size: 24px; font-weight: 800; margin-top: 4px; }
        .overview-kpi .s { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

        .mix-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mix-list { list-style: none; display: grid; gap: 8px; }
        .mix-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background: #0f0f1a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12px;
        }

        .playbook-list { list-style: none; display: grid; gap: 10px; }
        .playbook-item {
            border: 1px solid var(--border);
            background: #0f0f1a;
            border-radius: 12px;
            padding: 12px;
        }

        .playbook-item h4 { font-family: 'Syne', sans-serif; font-size: 14px; margin-bottom: 4px; }
        .playbook-item p { font-size: 12px; color: var(--text-muted); line-height: 1.6; margin-bottom: 8px; }
        .playbook-priority {
            display: inline-block;
            border-radius: 20px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 3px 8px;
            border: 1px solid var(--border);
            margin-bottom: 6px;
        }

        .playbook-priority.high { color: #fca5a5; border-color: rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.12); }
        .playbook-priority.medium { color: #fcd34d; border-color: rgba(245, 158, 11, 0.45); background: rgba(245, 158, 11, 0.12); }
        .playbook-priority.low { color: #86efac; border-color: rgba(34, 197, 94, 0.45); background: rgba(34, 197, 94, 0.12); }

        .task-list { list-style: none; display: grid; gap: 6px; }
        .task-list li { font-size: 12px; color: var(--text-dim); padding-left: 14px; position: relative; line-height: 1.55; }
        .task-list li::before { content: '•'; position: absolute; left: 0; color: var(--accent-light); }

        .launch-steps { list-style: none; display: grid; gap: 10px; margin-top: 10px; }
        .launch-steps li {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            background: #0f0f1a;
            font-size: 12px;
            color: var(--text-dim);
            line-height: 1.6;
        }

        .launch-steps li strong { color: var(--text); font-family: 'Syne', sans-serif; font-size: 13px; display: block; margin-bottom: 3px; }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .campaign-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
        .campaign-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .campaign-table th { text-align: left; color: var(--text-muted); font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em; padding: 10px 12px; border-bottom: 1px solid var(--border); background: #141422; }
        .campaign-table td { padding: 11px 12px; border-bottom: 1px solid rgba(38, 38, 60, 0.72); color: var(--text-dim); }
        .campaign-table tr:last-child td { border-bottom: none; }

        .integration-note { font-size: 12px; color: var(--text-muted); line-height: 1.6; margin-top: 8px; }

        #dashboard.dashboard--compact .card,
        #dashboard.dashboard--compact .stat-card {
            padding: 14px 16px;
        }

        #dashboard.dashboard--compact .card-title {
            margin-bottom: 12px;
        }

        #dashboard.dashboard--flat .card,
        #dashboard.dashboard--flat .stat-card,
        #dashboard.dashboard--flat .page-header {
            box-shadow: none;
        }

        #dashboard.dashboard--flat .stat-card::after {
            display: none;
        }

        #dashboard.dashboard--flat .tab.active {
            box-shadow: none;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 760px) {
            .container { padding: 20px 14px 60px; }
            .stats { grid-template-columns: 1fr 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .client-actions { width: 100%; }
            .compact-input { width: 100%; }
            .card-title { flex-direction: column; align-items: flex-start; }
            .dashboard-tools { grid-template-columns: 1fr; }
            .stat-card-wide { grid-column: span 1; }
            .insight-row { grid-template-columns: 1fr; }
            .overview-grid { grid-template-columns: 1fr 1fr; }
            .mix-grid { grid-template-columns: 1fr; }
            .settings-grid { grid-template-columns: 1fr; }
            .widget-preview-canvas { min-height: 250px; }
            .widget-panel-mock { width: min(290px, calc(100% - 24px)); }
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
            .overview-grid { grid-template-columns: 1fr; }
            nav { padding: 12px 14px; }
            .nav-links { gap: 6px; }
            .nav-link { padding: 5px 9px; }
            .widget-launcher-mock { height: 38px; padding: 0 12px; font-size: 10px; }
            .widget-preview-site { max-width: 96%; }
            .widget-panel-mock { right: 10px; bottom: 56px; width: calc(100% - 20px); }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>
<nav>
    <img src="/images/lyralinkai.ico" class="nav-logo" alt="Lyralink Infrastructure">
    <div class="nav-title">Lyra<span>link</span></div>
    <span class="nav-badge">Operator</span>
    <div class="nav-links">
        <a href="/chat.php" class="nav-link">Chat</a>
        <a href="/pages/pricing.php" class="nav-link">Pricing</a>
        <a href="/" class="nav-link">Home</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
    </div>
</nav>

<div class="container" id="app">
    <div class="loading" id="loading">Loading your operator dashboard…</div>

    <!-- NOT A RESELLER -->
    <div class="not-reseller" id="notReseller" style="display:none">
        <div class="not-reseller-icon">🏢</div>
        <h2 id="notResellerTitle">You are not an operator yet</h2>
        <p id="notResellerMessage">Apply to the Lyralink Operator Program to launch a branded AI business with built-in infrastructure, billing, and client controls.</p>
        <p id="notResellerMeta" class="helper-note" style="margin:0 0 14px 0"></p>
        <a href="/pages/reseller_apply.php" class="btn btn-primary" id="notResellerCta">Apply to Operate →</a>
    </div>

    <!-- DASHBOARD -->
    <div id="dashboard" style="display:none">
        <div class="page-header">
            <div>
                <h1>Operator <span>Dashboard</span></h1>
                <div class="company-name" id="companyName"></div>
            </div>
            <div class="header-actions">
                <span class="commission-badge" id="commissionBadge"></span>
            </div>
        </div>

        <!-- STATS -->
        <div class="stats" id="statsGrid"></div>
        <div class="card">
            <div class="card-title">Dashboard Preferences <span class="sub">Customizable display and density</span></div>
            <div class="dashboard-tools">
                <div class="tool-item">
                    <label>Density</label>
                    <select id="uiDensity" onchange="updateDashboardPrefs()">
                        <option value="comfortable">Comfortable</option>
                        <option value="compact">Compact</option>
                    </select>
                </div>
                <div class="tool-item">
                    <label>Card Style</label>
                    <select id="uiCardStyle" onchange="updateDashboardPrefs()">
                        <option value="glow">Glow</option>
                        <option value="flat">Flat</option>
                    </select>
                </div>
                <label class="tool-item toggle">
                    <input type="checkbox" id="uiInsights" onchange="updateDashboardPrefs()">
                    <span>Show insight bars in stats</span>
                </label>
            </div>
        </div>

        <!-- TABS -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('overview',this)">📈 Strategy</div>
            <div class="tab" onclick="switchTab('clients',this)">👥 Accounts</div>
            <div class="tab" onclick="switchTab('earnings',this)">💰 Revenue</div>
            <div class="tab" onclick="switchTab('invite',this)">🔗 Acquisition Link</div>
            <div class="tab" onclick="switchTab('acquisition',this)">🎯 Campaigns</div>
            <div class="tab" onclick="switchTab('integrations',this)">⚙ Integrations</div>
            <div class="tab" onclick="switchTab('branding',this)">🎨 Branding</div>
            <div class="tab" onclick="switchTab('embed',this)">🔌 Distribution</div>
            <div class="tab" onclick="switchTab('launchpad',this)">🚀 Launchpad</div>
        </div>

        <div class="tab-pane active" id="tab-overview">
            <div class="card">
                <div class="card-title">Operator Strategy Cockpit <span class="sub">Real-time growth and margin signals</span></div>
                <div class="overview-grid" id="overviewGrid">
                    <div class="overview-kpi"><div class="k">Clients (30d)</div><div class="v">0</div><div class="s">Loading…</div></div>
                    <div class="overview-kpi"><div class="k">Estimated MRR</div><div class="v">$0</div><div class="s">From active client plan mix</div></div>
                    <div class="overview-kpi"><div class="k">Pending Payout</div><div class="v">$0</div><div class="s">Unsettled earnings</div></div>
                    <div class="overview-kpi"><div class="k">Invite Share</div><div class="v">0%</div><div class="s">Attributed by invite link</div></div>
                </div>
                <div class="mix-grid">
                    <div>
                        <div class="subtle-row">Client source mix</div>
                        <ul class="mix-list" id="sourceMixList"></ul>
                    </div>
                    <div>
                        <div class="subtle-row">Plan mix</div>
                        <ul class="mix-list" id="planMixList"></ul>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Growth Playbook <span class="sub">Automatically generated next best moves</span></div>
                <ul class="playbook-list" id="playbookList"></ul>
            </div>
        </div>

        <!-- CLIENTS TAB -->
        <div class="tab-pane" id="tab-clients">
            <div class="card">
                <div class="card-title">
                    Managed Accounts
                    <div class="client-actions">
                        <input type="email" id="addClientEmail" class="compact-input" placeholder="Add by email…">
                        <button class="btn btn-primary compact-btn" onclick="addClient()">Add</button>
                    </div>
                </div>
                <div id="addClientMsg"></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>User</th><th>Email</th><th>Plan</th><th>Joined</th><th>Earned</th><th></th></tr></thead>
                        <tbody id="clientsTable"><tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">Loading…</td></tr></tbody>
                    </table>
                </div>
                <div class="pagination" id="clientsPagination"></div>
            </div>
        </div>

        <!-- EARNINGS TAB -->
        <div class="tab-pane" id="tab-earnings">
            <div class="card">
                <div class="card-title">Revenue History <span class="sub">Recurring earnings from active subscriptions</span></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Client</th><th>Plan</th><th>Gross</th><th>Rate</th><th>Earned</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody id="earningsTable"><tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Loading…</td></tr></tbody>
                    </table>
                </div>
                <div class="pagination" id="earningsPagination"></div>
            </div>
        </div>

        <!-- INVITE TAB -->
        <div class="tab-pane" id="tab-invite">
            <div class="card">
                <div class="card-title">Acquisition Link</div>
                <p class="section-note">Share this link in your funnel, onboarding, or campaigns. New signups are automatically attributed to your operator account.</p>
                <div class="invite-box">
                    <div class="invite-url" id="inviteUrl"></div>
                    <button class="copy-btn" id="copyInviteBtn" onclick="copyInvite()">Copy</button>
                </div>
                <p class="helper-note">You can also attach existing users manually from the Accounts tab using their email address.</p>
                <div class="section-divider">
                    <div class="subtle-row">Campaign tagging helper</div>
                    <div class="settings-grid">
                        <input type="text" id="inviteUtmSource" placeholder="utm_source (e.g. newsletter)">
                        <input type="text" id="inviteUtmCampaign" placeholder="utm_campaign (e.g. sept_launch)">
                    </div>
                    <button class="btn btn-outline" onclick="copyTaggedInvite()" style="font-size:12px">Copy Tagged Invite Link</button>
                </div>
                <div class="section-divider">
                    <div class="subtle-row">Need a fresh link? Regenerating invalidates the old one.</div>
                    <button class="btn btn-outline" onclick="regenerateInvite()" style="font-size:12px">🔄 Regenerate Acquisition Link</button>
                </div>
            </div>
        </div>

        <div class="tab-pane" id="tab-acquisition">
            <div class="card">
                <div class="card-title">Campaign Conversion Analytics <span class="sub">Invite resolves to linked clients</span></div>
                <p class="section-note">Track campaign quality by comparing invite visits and resulting linked accounts. Focus on campaigns with strong conversion, not just clicks.</p>
                <div class="campaign-table-wrap">
                    <table class="campaign-table">
                        <thead><tr><th>Campaign</th><th>Events</th><th>Linked</th><th>Conversion</th><th>Last Seen</th></tr></thead>
                        <tbody id="campaignTable"><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">Loading campaign analytics…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane" id="tab-integrations">
            <div class="card">
                <div class="card-title">Operator Integrations</div>
                <p class="section-note">Connect an HTTPS webhook for growth and client events, and configure your onboarding operating mode.</p>
                <div id="integrationMsg"></div>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Webhook URL (HTTPS)</label>
                        <input type="url" id="opWebhookUrl" placeholder="https://hooks.your-domain.com/lyralink-operator">
                    </div>
                    <div class="form-group">
                        <label>Onboarding Mode</label>
                        <select id="opOnboardingMode">
                            <option value="guided">Guided</option>
                            <option value="self_serve">Self Serve</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </div>
                </div>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Growth Goal: New Clients / 30 Days</label>
                        <input type="number" id="opGrowthGoal" min="1" max="200" value="5">
                    </div>
                    <div class="form-group">
                        <label>Integration Test</label>
                        <button class="btn btn-outline" type="button" onclick="sendOperatorTestAlert()">Send Test Alert</button>
                        <div class="integration-note">Test sends a signed event payload to your configured webhook endpoint.</div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="saveOperatorSettings()">Save Operator Settings</button>
            </div>
        </div>

        <!-- BRANDING TAB -->
        <div class="tab-pane" id="tab-branding">
            <div class="card">
                <div class="card-title">Branding Settings</div>
                <div id="brandingMsg"></div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" id="bCompany" maxlength="200">
                    </div>
                    <div class="form-group">
                        <label>Custom Domain</label>
                        <input type="text" id="bDomain" placeholder="ai.yourcompany.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Logo URL</label>
                        <input type="url" id="bLogo" placeholder="https://…/logo.png">
                    </div>
                    <div class="form-group">
                        <label>Accent Color</label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="text" id="bAccent" placeholder="#7c3aed" style="flex:1">
                            <input type="color" id="bAccentPicker" value="#7c3aed" oninput="document.getElementById('bAccent').value=this.value" style="width:40px;height:38px;border-radius:8px;border:1px solid var(--border);padding:2px;background:var(--bg);cursor:pointer">
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="saveBranding()">Save Branding</button>
            </div>
        </div>

        <!-- EMBED TAB -->
        <div class="tab-pane" id="tab-embed">
            <div class="card">
                <div class="card-title">Embed Distribution</div>
                <p class="section-note">Drop this snippet into any webpage to distribute your branded chat experience. The <code class="inline-code">data-ref</code> attribute attributes signups to your operator account automatically.</p>
                <div class="widget-preview" id="widgetPreview">
                    <div class="widget-preview-top">
                        <div class="widget-preview-dots"><span></span><span></span><span></span></div>
                        <span>Live Widget Look Preview</span>
                    </div>
                    <div class="widget-preview-canvas">
                        <div class="widget-preview-site">
                            <div class="widget-preview-line short"></div>
                            <div class="widget-preview-line"></div>
                            <div class="widget-preview-line mid"></div>
                            <div class="widget-preview-line"></div>
                            <div class="widget-preview-line short"></div>
                        </div>

                        <div class="widget-panel-mock" id="widgetPanelMock" aria-hidden="true">
                            <div class="widget-panel-head">
                                <div>
                                    <div id="widgetPreviewHeader">Chat with us</div>
                                    <div class="widget-panel-sub">We typically reply instantly</div>
                                </div>
                            </div>
                            <div class="widget-panel-body">
                                <div class="widget-msg">Hey there. Need help with setup, pricing, or integration?</div>
                                <div class="widget-msg user">Can I embed this on my site?</div>
                                <div class="widget-msg">Yes. Use the snippet below and it will keep your referral token attached.</div>
                            </div>
                            <div class="widget-panel-input">Type your message…</div>
                        </div>

                        <button type="button" class="widget-launcher-mock" onclick="toggleWidgetPreview()">
                            <span class="widget-launcher-dot"></span>
                            <span id="widgetPreviewLauncherLabel">Chat with us</span>
                        </button>
                    </div>
                    <div class="widget-preview-actions">
                        <button type="button" class="btn btn-outline" id="widgetPreviewToggleBtn" onclick="toggleWidgetPreview()">Open Preview</button>
                        <span class="widget-preview-note">Matches the look and placement users get from your embed snippet.</span>
                    </div>
                </div>
                <div style="position:relative">
                    <pre class="code-block" id="embedCode"></pre>
                    <button class="code-copy" onclick="copyEmbed()">Copy</button>
                </div>
                <p class="helper-note">For best conversion and trust, configure a custom domain that matches your operator brand.</p>
            </div>
            <div class="card">
                <div class="card-title">API Distribution</div>
                <p class="section-note">Use Lyralink APIs to package programmatic access into your own products, internal tooling, or paid developer plans.</p>
                <a href="/pages/api_keys.php" class="btn btn-outline">🔑 Manage API Keys</a>
                <a href="/pages/api_docs.php" class="btn btn-outline spaced-link">📄 API Docs</a>
            </div>
        </div>

        <div class="tab-pane" id="tab-launchpad">
            <div class="card">
                <div class="card-title">Beginner-to-Enterprise Launch System</div>
                <p class="section-note">Use this checklist to scale from zero AI experience to a full operator business with repeatable onboarding, monetization, and support operations.</p>
                <ol class="launch-steps">
                    <li><strong>Step 1: Pick a single niche offer</strong>Define one high-intent use case (support assistant, booking helper, lead qualifier) and write one sentence your clients can repeat to others.</li>
                    <li><strong>Step 2: Activate branded distribution</strong>Set company name, accent, domain, then deploy your referral widget snippet on your highest-traffic page.</li>
                    <li><strong>Step 3: Turn on API channel</strong>Issue API keys for power users and integrations so technical customers can embed your operator offering into their workflows.</li>
                    <li><strong>Step 4: Price for margin, not hype</strong>Use usage-aware pricing and auto-route discount positioning; reserve reasoning-heavy routes for premium workflows.</li>
                    <li><strong>Step 5: Add conversion loops</strong>Every campaign should drive to your acquisition link and include a simple onboarding path within 24 hours.</li>
                    <li><strong>Step 6: Build trust operations</strong>Use support, payout visibility, and account management to keep churn low and predictability high.</li>
                    <li><strong>Step 7: Expand to teams and enterprise</strong>Move into organization roles, audit trails, and integration-heavy workflows for larger accounts.</li>
                </ol>
                <div class="section-divider">
                    <div class="subtle-row">Fast actions</div>
                    <a href="/pages/pricing.php" class="btn btn-outline">Pricing & Units</a>
                    <a href="/pages/api_docs.php" class="btn btn-outline spaced-link">Developer Docs</a>
                    <a href="/pages/support.php" class="btn btn-outline spaced-link">Support Workflow</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let resellerData = null;
let operatorOverview = null;
let clientsPage = 1;
let earningsPage = 1;
let widgetPreviewOpen = false;
const DASHBOARD_PREFS_KEY = 'lyralink_operator_dashboard_prefs_v1';

let dashboardPrefs = {
    density: 'comfortable',
    cardStyle: 'glow',
    showInsights: true,
};

async function init() {
    try {
        const r = await fetch('/api/reseller.php?action=get_dashboard');
        const d = await r.json();
        document.getElementById('loading').style.display = 'none';

        if (!d.success) {
            await showNotResellerState(d);
            return;
        }

        resellerData = d;
        document.getElementById('dashboard').style.display = 'block';
        loadDashboardPrefs();
        syncDashboardControls();
        applyDashboardPrefs();
        renderDashboard(d);
        await loadOperatorOverview();
        loadClients();
        loadEarnings();
    } catch(e) {
        document.getElementById('loading').textContent = 'Failed to load dashboard. Please refresh.';
    }
}

async function showNotResellerState(accessData = null) {
    const titleEl = document.getElementById('notResellerTitle');
    const msgEl = document.getElementById('notResellerMessage');
    const metaEl = document.getElementById('notResellerMeta');
    const ctaEl = document.getElementById('notResellerCta');
    const supportEmail = (accessData && accessData.support_email) ? String(accessData.support_email) : 'support@lyralinkai.com';
    const statusNote = accessData && accessData.admin_note ? String(accessData.admin_note).trim() : '';
    const supportMailto = `mailto:${encodeURIComponent(supportEmail)}?subject=${encodeURIComponent('Operator account status review')}`;

    titleEl.textContent = 'You are not an operator yet';
    msgEl.textContent = 'Apply to the Lyralink Operator Program to launch a branded AI business with built-in infrastructure, billing, and client controls.';
    metaEl.textContent = '';
    ctaEl.href = '/pages/reseller_apply.php';
    ctaEl.textContent = 'Apply to Operate →';

    const stateCode = String((accessData && accessData.code) || '').toLowerCase();
    const resellerStatus = String((accessData && accessData.reseller_status) || '').toLowerCase();
    if (stateCode === 'reseller_suspended' || resellerStatus === 'suspended') {
        titleEl.textContent = 'Operator account suspended';
        msgEl.textContent = 'Your operator dashboard access is temporarily suspended. Billing and management actions are locked until your account is restored.';
        metaEl.innerHTML = `For reactivation, email <a href="mailto:${encodeURIComponent(supportEmail)}" style="color:var(--accent-light)">${supportEmail}</a>.${statusNote ? ' Admin note: ' + escapeHtml(statusNote) : ''}`;
        ctaEl.href = supportMailto;
        ctaEl.textContent = 'Email Support →';
        document.getElementById('notReseller').style.display = 'block';
        return;
    }
    if (stateCode === 'reseller_terminated' || resellerStatus === 'terminated') {
        titleEl.textContent = 'Operator account terminated';
        msgEl.textContent = 'Your operator account has been terminated and dashboard access has been disabled.';
        metaEl.innerHTML = `For account review, email <a href="mailto:${encodeURIComponent(supportEmail)}" style="color:var(--accent-light)">${supportEmail}</a>.${statusNote ? ' Admin note: ' + escapeHtml(statusNote) : ''}`;
        ctaEl.href = supportMailto;
        ctaEl.textContent = 'Contact Support →';
        document.getElementById('notReseller').style.display = 'block';
        return;
    }

    try {
        const statusResp = await fetch('/api/reseller.php?action=get_application_status');
        const statusData = await statusResp.json();

        const appResellerStatus = String(statusData.reseller_status || '').toLowerCase();
        const appResellerNote = String(statusData.reseller_note || '').trim();
        const appSupportEmail = String(statusData.support_email || supportEmail);
        if (appResellerStatus === 'suspended' || appResellerStatus === 'terminated') {
            const isTerminated = appResellerStatus === 'terminated';
            titleEl.textContent = isTerminated ? 'Operator account terminated' : 'Operator account suspended';
            msgEl.textContent = isTerminated
                ? 'Your operator account has been terminated and dashboard access has been disabled.'
                : 'Your operator dashboard access is temporarily suspended. Billing and management actions are locked until your account is restored.';
            metaEl.innerHTML = `Contact <a href="mailto:${encodeURIComponent(appSupportEmail)}" style="color:var(--accent-light)">${appSupportEmail}</a> for review.${appResellerNote ? ' Admin note: ' + escapeHtml(appResellerNote) : ''}`;
            ctaEl.href = `mailto:${encodeURIComponent(appSupportEmail)}?subject=${encodeURIComponent('Operator account status review')}`;
            ctaEl.textContent = 'Contact Support →';
            document.getElementById('notReseller').style.display = 'block';
            return;
        }

        if (statusData.success && statusData.application_status === 'rejected') {
            const note = (statusData.admin_note || '').toLowerCase();
            if (note.includes('removed by admin')) {
                titleEl.textContent = 'Operator access removed';
                msgEl.textContent = 'Your operator access was removed by an administrator. You can re-apply now, and our team will review your request again.';
                ctaEl.textContent = 'Re-apply to Operate →';
            }
        } else if (statusData.success && statusData.application_status === 'approved') {
            titleEl.textContent = 'Operator account inactive';
            msgEl.textContent = 'Your previous operator approval is no longer active. Please submit a new application to restore dashboard access.';
            ctaEl.textContent = 'Re-apply to Operate →';
        }
    } catch (e) {
        // Keep the default non-operator message if status lookup fails.
    }

    document.getElementById('notReseller').style.display = 'block';
}

function renderDashboard(d) {
    document.getElementById('companyName').textContent = d.reseller.company_name;
    document.getElementById('commissionBadge').textContent = d.reseller.commission_rate + '% commission';

    const s = d.stats;
    const totalClients = Number(s.total_clients || 0);
    const monthEarned = Number(s.earnings_this_month || 0);
    const pending = Number(s.earnings_pending || 0);
    const allTime = Number(s.earnings_total || 0);
    const avgPerClient = totalClients > 0 ? (allTime / totalClients) : 0;
    const payoutShare = allTime > 0 ? Math.min(100, (pending / allTime) * 100) : 0;
    const monthShare = allTime > 0 ? Math.min(100, (monthEarned / allTime) * 100) : 0;

    const insightsHtml = dashboardPrefs.showInsights ? `
        <div class="stat-card stat-card-wide">
            <div class="stat-label">Performance Insights</div>
            <div class="insight-row">
                <div>
                    <div class="insight-label">Pending payout share</div>
                    <div class="insight-value">${payoutShare.toFixed(1)}% of all-time earnings</div>
                    <div class="progress-track"><div class="progress-fill" style="width:${payoutShare.toFixed(1)}%"></div></div>
                </div>
                <div>
                    <div class="insight-label">This-month contribution</div>
                    <div class="insight-value">${monthShare.toFixed(1)}% of all-time earnings</div>
                    <div class="progress-track"><div class="progress-fill" style="width:${monthShare.toFixed(1)}%"></div></div>
                </div>
            </div>
        </div>
    ` : '';

    document.getElementById('statsGrid').innerHTML = `
        <div class="stat-card"><div class="stat-label">Total Clients</div><div class="stat-value accent">${totalClients}</div><div class="stat-sub">Managed accounts</div></div>
        <div class="stat-card"><div class="stat-label">This Month</div><div class="stat-value green">$${monthEarned.toFixed(2)}</div><div class="stat-sub">Current month earnings</div></div>
        <div class="stat-card"><div class="stat-label">Pending Payout</div><div class="stat-value">$${pending.toFixed(2)}</div><div class="stat-sub">Awaiting payout cycle</div></div>
        <div class="stat-card"><div class="stat-label">All Time Earned</div><div class="stat-value">$${allTime.toFixed(2)}</div><div class="stat-sub">Historical commissions</div></div>
        <div class="stat-card"><div class="stat-label">Avg / Client</div><div class="stat-value accent">$${avgPerClient.toFixed(2)}</div><div class="stat-sub">Lifetime per client</div></div>
        <div class="stat-card"><div class="stat-label">Commission Rate</div><div class="stat-value">${Number(d.reseller.commission_rate || 0).toFixed(0)}%</div><div class="stat-sub">Active commission split</div></div>
        ${insightsHtml}
    `;

    const inviteUrl = `${location.origin}/?ref=${d.reseller.invite_token}`;
    document.getElementById('inviteUrl').textContent = inviteUrl;

    document.getElementById('bCompany').value = d.reseller.company_name || '';
    document.getElementById('bDomain').value = d.reseller.custom_domain || '';
    document.getElementById('bLogo').value = d.reseller.logo_url || '';
    document.getElementById('bAccent').value = d.reseller.accent_color || '#7c3aed';
    document.getElementById('bAccentPicker').value = d.reseller.accent_color || '#7c3aed';
    const onboardingModeEl = document.getElementById('opOnboardingMode');
    const growthGoalEl = document.getElementById('opGrowthGoal');
    const webhookEl = document.getElementById('opWebhookUrl');
    if (onboardingModeEl) onboardingModeEl.value = d.reseller.onboarding_mode || 'guided';
    if (growthGoalEl) growthGoalEl.value = Number(d.reseller.growth_goal_clients_30d || 5);
    if (webhookEl) webhookEl.value = d.reseller.alert_webhook_url || '';

    document.getElementById('embedCode').textContent =
`<!-- Lyralink Chat Widget -->
<script>
  (function(){
    var s = document.createElement('script');
    s.src = 'https://lyralinkai.com/assets/js/widget.js?v=20260912-2';
    s.dataset.ref = '${d.reseller.invite_token}';
    s.dataset.accent = '${d.reseller.accent_color || '#7c3aed'}';
    s.dataset.embedMode = 'iframe';
    document.head.appendChild(s);
  })();
<\/script>`;

    updateWidgetPreview({
        companyName: d.reseller.company_name || '',
        accentColor: d.reseller.accent_color || '#7c3aed'
    });
}

async function loadOperatorOverview() {
    try {
        const r = await fetch('/api/reseller.php?action=get_operator_overview');
        const d = await r.json();
        if (!d.success) return;
        operatorOverview = d;
        renderOperatorOverview(d);
    } catch (e) {
        // Keep dashboard usable even if the overview endpoint is unavailable.
    }
}

function renderOperatorOverview(payload) {
    const o = payload && payload.overview ? payload.overview : {};
    const playbook = Array.isArray(payload && payload.playbook ? payload.playbook : []) ? payload.playbook : [];
    const campaigns = Array.isArray(payload && payload.campaigns ? payload.campaigns : []) ? payload.campaigns : [];

    const growthPct = Number(o.client_growth_pct || 0);
    const growthText = Number.isFinite(growthPct)
        ? (growthPct > 0 ? `+${growthPct.toFixed(1)}% vs previous 30d` : `${growthPct.toFixed(1)}% vs previous 30d`)
        : 'No comparison window yet';

    const grid = document.getElementById('overviewGrid');
    if (grid) {
        grid.innerHTML = `
            <div class="overview-kpi"><div class="k">Clients (30d)</div><div class="v">${Number(o.new_clients_30d || 0)}</div><div class="s">${growthText}</div></div>
            <div class="overview-kpi"><div class="k">Estimated MRR</div><div class="v">$${Number(o.estimated_mrr || 0).toFixed(2)}</div><div class="s">Current client plan distribution</div></div>
            <div class="overview-kpi"><div class="k">Pending Payout</div><div class="v">$${Number(o.pending_payout || 0).toFixed(2)}</div><div class="s">Settle regularly to reduce ops risk</div></div>
            <div class="overview-kpi"><div class="k">Invite Share</div><div class="v">${Number(o.invite_share_pct || 0).toFixed(1)}%</div><div class="s">Higher share = cleaner attribution</div></div>
        `;
    }

    const sourceMix = o.client_source_mix || {};
    const sourceEntries = [
        ['Invite link', Number(sourceMix.invite || 0)],
        ['Manual attach', Number(sourceMix.manual || 0)],
        ['Application flow', Number(sourceMix.application || 0)],
    ];
    const sourceList = document.getElementById('sourceMixList');
    if (sourceList) {
        sourceList.innerHTML = sourceEntries.map(([label, value]) => `<li><span>${label}</span><strong>${value}</strong></li>`).join('');
    }

    const mixRows = Array.isArray(o.plan_mix) ? o.plan_mix : [];
    const planList = document.getElementById('planMixList');
    if (planList) {
        if (!mixRows.length) {
            planList.innerHTML = '<li><span>No client plans yet</span><strong>0</strong></li>';
        } else {
            planList.innerHTML = mixRows.map(row => `<li><span>${esc(String(row.plan || 'free')).toUpperCase()}</span><strong>${Number(row.count || 0)}</strong></li>`).join('');
        }
    }

    const playbookList = document.getElementById('playbookList');
    if (playbookList) {
        if (!playbook.length) {
            playbookList.innerHTML = '<li class="playbook-item"><h4>No recommendations yet</h4><p>Start by adding your first clients and usage activity.</p></li>';
        } else {
            playbookList.innerHTML = playbook.map(item => {
                const tasks = Array.isArray(item.tasks) ? item.tasks : [];
                return `
                    <li class="playbook-item">
                        <span class="playbook-priority ${esc(String(item.priority || 'low').toLowerCase())}">${esc(String(item.priority || 'low'))} priority</span>
                        <h4>${esc(String(item.title || 'Action'))}</h4>
                        <p>${esc(String(item.why || ''))}</p>
                        <ul class="task-list">${tasks.map(t => `<li>${esc(String(t || ''))}</li>`).join('')}</ul>
                    </li>
                `;
            }).join('');
        }
    }

    const campaignTable = document.getElementById('campaignTable');
    if (campaignTable) {
        if (!campaigns.length) {
            campaignTable.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No campaign data yet. Start sharing tagged links to build this view.</td></tr>';
        } else {
            campaignTable.innerHTML = campaigns.map(c => `
                <tr>
                    <td>${esc(String(c.campaign || '(none)'))}</td>
                    <td>${Number(c.events || 0).toLocaleString()}</td>
                    <td>${Number(c.linked || 0).toLocaleString()}</td>
                    <td>${Number(c.conversion_pct || 0).toFixed(1)}%</td>
                    <td>${esc(String(c.last_seen || ''))}</td>
                </tr>
            `).join('');
        }
    }
}

function normalizeHexColor(value, fallback = '#7c3aed') {
    const v = String(value || '').trim();
    return /^#[0-9a-fA-F]{6}$/.test(v) ? v : fallback;
}

function toggleWidgetPreview(forceState = null) {
    const previewEl = document.getElementById('widgetPreview');
    const btn = document.getElementById('widgetPreviewToggleBtn');
    const panel = document.getElementById('widgetPanelMock');
    if (!previewEl || !btn || !panel) return;

    widgetPreviewOpen = forceState === null ? !widgetPreviewOpen : !!forceState;
    previewEl.classList.toggle('open', widgetPreviewOpen);
    panel.setAttribute('aria-hidden', widgetPreviewOpen ? 'false' : 'true');
    btn.textContent = widgetPreviewOpen ? 'Close Preview' : 'Open Preview';
}

function updateWidgetPreview(opts = {}) {
    const previewEl = document.getElementById('widgetPreview');
    if (!previewEl) return;

    const accent = normalizeHexColor(opts.accentColor, '#7c3aed');
    previewEl.style.setProperty('--preview-accent', accent);

    const rawName = String(opts.companyName || '').trim();
    const brandName = rawName ? rawName.slice(0, 28) : 'your team';
    const label = `Chat with ${brandName}`;
    const headerEl = document.getElementById('widgetPreviewHeader');
    const launcherEl = document.getElementById('widgetPreviewLauncherLabel');
    if (headerEl) headerEl.textContent = label;
    if (launcherEl) launcherEl.textContent = label;
}

async function loadClients(page=1) {
    clientsPage = page;
    const r = await fetch(`/api/reseller.php?action=get_clients&page=${page}`);
    const d = await r.json();
    if (!d.success) return;

    const tbody = document.getElementById('clientsTable');
    if (!d.clients.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:32px">No clients yet. Share your invite link to get started.</td></tr>';
        return;
    }

    tbody.innerHTML = d.clients.map(c => `
        <tr>
            <td>${esc(c.username)}</td>
            <td style="color:var(--text-muted)">${esc(c.email)}</td>
            <td><span class="plan-tag plan-${c.plan}">${c.plan}</span></td>
            <td style="color:var(--text-muted)">${c.joined.split(' ')[0]}</td>
            <td>$${c.total_earned.toFixed(2)}</td>
            <td><button class="btn-sm" onclick="removeClient(${c.id})">Remove</button></td>
        </tr>
    `).join('');

    renderPagination('clientsPagination', d.total, 25, page, loadClients);
}

async function loadEarnings(page=1) {
    earningsPage = page;
    const r = await fetch(`/api/reseller.php?action=get_earnings&page=${page}`);
    const d = await r.json();
    if (!d.success) return;

    const tbody = document.getElementById('earningsTable');
    if (!d.earnings.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:32px">No earnings yet. Earnings are recorded when your clients pay for a subscription.</td></tr>';
        return;
    }

    tbody.innerHTML = d.earnings.map(e => `
        <tr>
            <td>${esc(e.username)}</td>
            <td><span class="plan-tag plan-${e.plan}">${e.plan}</span></td>
            <td>$${e.gross.toFixed(2)}</td>
            <td>${e.rate}%</td>
            <td style="color:var(--success)">$${e.earned.toFixed(2)}</td>
            <td><span style="font-size:10px;padding:2px 8px;border-radius:20px;background:${e.status==='paid_out'?'rgba(34,197,94,.15)':'rgba(245,158,11,.15)'};color:${e.status==='paid_out'?'var(--success)':'#f59e0b'}">${e.status}</span></td>
            <td style="color:var(--text-muted)">${e.date.split(' ')[0]}</td>
        </tr>
    `).join('');

    renderPagination('earningsPagination', d.total, 25, page, loadEarnings);
}

function renderPagination(containerId, total, perPage, currentPage, callback) {
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) { document.getElementById(containerId).innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= pages; i++) {
        html += `<button class="page-btn${i===currentPage?' active':''}" onclick="${callback.name}(${i})">${i}</button>`;
    }
    document.getElementById(containerId).innerHTML = html;
}

async function addClient() {
    const email = document.getElementById('addClientEmail').value.trim();
    if (!email) return;
    const fd = new FormData();
    fd.append('email', email);
    const r = await fetch('/api/reseller.php?action=add_client_by_email', {method:'POST',body:fd});
    const d = await r.json();
    const msg = document.getElementById('addClientMsg');
    if (d.success) {
        msg.className = 'msg success';
        msg.textContent = `Added ${d.user.username} successfully.`;
        document.getElementById('addClientEmail').value = '';
        loadClients(1);
        init(); // refresh stats
    } else {
        msg.className = 'msg error';
        msg.textContent = d.error;
    }
    setTimeout(() => msg.className = 'msg', 4000);
}

async function removeClient(clientId) {
    if (!confirm('Remove this client from your operator account?')) return;
    const fd = new FormData();
    fd.append('client_id', clientId);
    const r = await fetch('/api/reseller.php?action=remove_client', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) loadClients(clientsPage);
}

function copyInvite() {
    const url = document.getElementById('inviteUrl').textContent;
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.getElementById('copyInviteBtn');
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
    });
}

function copyTaggedInvite() {
    const base = document.getElementById('inviteUrl').textContent || '';
    if (!base) return;
    const source = (document.getElementById('inviteUtmSource').value || '').trim();
    const campaign = (document.getElementById('inviteUtmCampaign').value || '').trim();
    const url = new URL(base);
    if (source) url.searchParams.set('utm_source', source);
    if (campaign) url.searchParams.set('utm_campaign', campaign);
    navigator.clipboard.writeText(url.toString()).then(() => {
        showInlineMsg('integrationMsg', 'success', 'Tagged invite link copied.');
    });
}

async function regenerateInvite() {
    if (!confirm('Regenerate invite link? The old link will stop working.')) return;
    const r = await fetch('/api/reseller.php?action=regenerate_invite', {method:'POST'});
    const d = await r.json();
    if (d.success) {
        const newUrl = `${location.origin}/?ref=${d.invite_token}`;
        document.getElementById('inviteUrl').textContent = newUrl;
        resellerData.reseller.invite_token = d.invite_token;
        renderDashboard(resellerData);
    }
}

async function saveBranding() {
    const companyName = document.getElementById('bCompany').value.trim();
    const customDomain = document.getElementById('bDomain').value.trim();
    const logoUrl = document.getElementById('bLogo').value.trim();
    const accentColor = document.getElementById('bAccent').value.trim();

    const fd = new FormData();
    fd.append('company_name', companyName);
    fd.append('custom_domain', customDomain);
    fd.append('logo_url', logoUrl);
    fd.append('accent_color', accentColor);

    const r = await fetch('/api/reseller.php?action=update_branding', {method:'POST',body:fd});
    const d = await r.json();
    const msg = document.getElementById('brandingMsg');
    if (d.success) {
        msg.className = 'msg success';
        msg.textContent = 'Branding saved!';
        document.getElementById('companyName').textContent = companyName;

        if (resellerData && resellerData.reseller) {
            resellerData.reseller.company_name = companyName;
            resellerData.reseller.custom_domain = customDomain;
            resellerData.reseller.logo_url = logoUrl;
            resellerData.reseller.accent_color = normalizeHexColor(accentColor, '#7c3aed');
            renderDashboard(resellerData);
        }
    } else {
        msg.className = 'msg error';
        msg.textContent = d.error;
    }
    setTimeout(() => msg.className = 'msg', 3000);
}

async function saveOperatorSettings() {
    const webhook = document.getElementById('opWebhookUrl').value.trim();
    const mode = document.getElementById('opOnboardingMode').value;
    const goal = document.getElementById('opGrowthGoal').value;

    const fdIntegrations = new FormData();
    fdIntegrations.append('alert_webhook_url', webhook);
    const iRes = await fetch('/api/reseller.php?action=set_operator_integrations', { method: 'POST', body: fdIntegrations });
    const iData = await iRes.json();
    if (!iData.success) {
        showInlineMsg('integrationMsg', 'error', iData.error || 'Failed to save integrations');
        return;
    }

    const fdGoals = new FormData();
    fdGoals.append('onboarding_mode', mode);
    fdGoals.append('growth_goal_clients_30d', goal);
    const gRes = await fetch('/api/reseller.php?action=set_operator_goals', { method: 'POST', body: fdGoals });
    const gData = await gRes.json();
    if (!gData.success) {
        showInlineMsg('integrationMsg', 'error', gData.error || 'Failed to save goals');
        return;
    }

    showInlineMsg('integrationMsg', 'success', 'Operator settings saved.');
    await init();
}

async function sendOperatorTestAlert() {
    const r = await fetch('/api/reseller.php?action=send_operator_test_alert', { method: 'POST' });
    const d = await r.json();
    if (d.success) {
        showInlineMsg('integrationMsg', 'success', 'Test alert delivered.');
    } else {
        showInlineMsg('integrationMsg', 'error', d.error || 'Unable to send test alert');
    }
}

function showInlineMsg(elId, type, text) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.className = 'msg ' + type;
    el.textContent = text;
    setTimeout(() => {
        el.className = '';
        el.textContent = '';
    }, 3500);
}

function copyEmbed() {
    const code = document.getElementById('embedCode').textContent;
    navigator.clipboard.writeText(code).then(() => {
        const btn = document.querySelector('.code-copy');
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}

function switchTab(name, el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
}

function loadDashboardPrefs() {
    try {
        const raw = localStorage.getItem(DASHBOARD_PREFS_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return;
        dashboardPrefs = {
            density: parsed.density === 'compact' ? 'compact' : 'comfortable',
            cardStyle: parsed.cardStyle === 'flat' ? 'flat' : 'glow',
            showInsights: parsed.showInsights !== false,
        };
    } catch (e) {
        // Keep defaults on invalid storage values.
    }
}

function syncDashboardControls() {
    const densityEl = document.getElementById('uiDensity');
    const cardStyleEl = document.getElementById('uiCardStyle');
    const insightsEl = document.getElementById('uiInsights');
    if (!densityEl || !cardStyleEl || !insightsEl) return;
    densityEl.value = dashboardPrefs.density;
    cardStyleEl.value = dashboardPrefs.cardStyle;
    insightsEl.checked = !!dashboardPrefs.showInsights;
}

function applyDashboardPrefs() {
    const dash = document.getElementById('dashboard');
    if (!dash) return;
    dash.classList.toggle('dashboard--compact', dashboardPrefs.density === 'compact');
    dash.classList.toggle('dashboard--flat', dashboardPrefs.cardStyle === 'flat');
}

function updateDashboardPrefs() {
    const densityEl = document.getElementById('uiDensity');
    const cardStyleEl = document.getElementById('uiCardStyle');
    const insightsEl = document.getElementById('uiInsights');
    if (!densityEl || !cardStyleEl || !insightsEl) return;

    dashboardPrefs = {
        density: densityEl.value === 'compact' ? 'compact' : 'comfortable',
        cardStyle: cardStyleEl.value === 'flat' ? 'flat' : 'glow',
        showInsights: insightsEl.checked,
    };

    localStorage.setItem(DASHBOARD_PREFS_KEY, JSON.stringify(dashboardPrefs));
    applyDashboardPrefs();
    if (resellerData) {
        renderDashboard(resellerData);
    }
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function escapeHtml(s) {
    return esc(s);
}

init();
</script>
</body>
</html>
