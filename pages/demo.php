<?php
if (file_exists(__DIR__ . '/maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
	header('Location: /pages/maintenance.php');
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Lyralink Demo | Product Tour</title>
	<link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
	<style>
		:root {
			--bg: #0a0a0f;
			--surface: #111118;
			--surface-2: #151524;
			--border: #1f1f33;
			--accent: #7c3aed;
			--accent-light: #b8a3ff;
			--text: #e6eaf2;
			--muted: #8b93a7;
			--ok: #22c55e;
			--warn: #f59e0b;
			--orange: #ff6b35;
		}

		* { box-sizing: border-box; margin: 0; padding: 0; }
		html { scroll-behavior: smooth; }
		body {
			font-family: 'DM Mono', monospace;
			background: var(--bg);
			color: var(--text);
			min-height: 100vh;
			overflow-x: hidden;
		}

		body::before,
		body::after {
			content: '';
			position: fixed;
			pointer-events: none;
			z-index: 0;
			border-radius: 50%;
		}

		body::before {
			width: 760px;
			height: 500px;
			top: -220px;
			left: 18%;
			background: radial-gradient(ellipse at center, rgba(124,58,237,0.12) 0%, transparent 70%);
		}

		body::after {
			width: 560px;
			height: 380px;
			right: -180px;
			bottom: -140px;
			background: radial-gradient(ellipse at center, rgba(255,107,53,0.08) 0%, transparent 70%);
		}

		.noise {
			position: fixed;
			inset: 0;
			pointer-events: none;
			opacity: 0.03;
			z-index: 0;
			background-image: radial-gradient(rgba(255,255,255,0.3) 0.5px, transparent 0.5px);
			background-size: 3px 3px;
		}

		.page {
			position: relative;
			z-index: 1;
		}

		nav {
			position: sticky;
			top: 0;
			z-index: 20;
			backdrop-filter: blur(14px);
			background: rgba(10,10,15,0.78);
			border-bottom: 1px solid rgba(31,31,51,0.9);
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 12px 24px;
		}

		.nav-logo {
			height: 28px;
			width: auto;
			mix-blend-mode: lighten;
		}

		.nav-title {
			font-family: 'Syne', sans-serif;
			font-weight: 800;
			letter-spacing: 0.02em;
			font-size: 15px;
		}

		.nav-title span { color: var(--accent-light); }

		.nav-links {
			margin-left: auto;
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
		}

		.nav-link {
			text-decoration: none;
			color: var(--muted);
			font-size: 12px;
			border: 1px solid var(--border);
			padding: 6px 12px;
			border-radius: 999px;
			transition: all 0.2s ease;
		}

		.nav-link:hover {
			color: var(--accent-light);
			border-color: var(--accent);
		}

		.container {
			width: min(1140px, 100% - 40px);
			margin: 0 auto;
		}

		.hero {
			padding: 74px 0 58px;
			display: grid;
			grid-template-columns: 1.05fr 0.95fr;
			gap: 26px;
			align-items: center;
		}

		.eyebrow {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.18em;
			color: var(--accent-light);
			border: 1px solid rgba(124,58,237,0.35);
			background: rgba(124,58,237,0.12);
			border-radius: 999px;
			padding: 6px 12px;
			margin-bottom: 16px;
		}

		.pulse {
			width: 7px;
			height: 7px;
			border-radius: 50%;
			background: var(--accent-light);
			animation: pulse 1.8s ease-in-out infinite;
		}

		@keyframes pulse {
			0%, 100% { opacity: 1; transform: scale(1); }
			50% { opacity: 0.45; transform: scale(0.8); }
		}

		h1 {
			font-family: 'Syne', sans-serif;
			font-size: clamp(34px, 5vw, 58px);
			line-height: 1.08;
			letter-spacing: -0.02em;
			margin-bottom: 16px;
		}

		h1 .accent { color: var(--accent-light); }

		.hero-sub {
			color: var(--muted);
			font-size: 14px;
			line-height: 1.8;
			max-width: 580px;
			margin-bottom: 22px;
		}

		.hero-actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
			margin-bottom: 20px;
		}

		.btn {
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 11px 16px;
			font-family: 'DM Mono', monospace;
			font-size: 12px;
			text-decoration: none;
			cursor: pointer;
			transition: all 0.2s ease;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
		}

		.btn-primary {
			color: white;
			border-color: rgba(124,58,237,0.4);
			background: linear-gradient(135deg, #7c3aed, #6327c7);
			box-shadow: 0 10px 28px rgba(124,58,237,0.35);
		}

		.btn-primary:hover { transform: translateY(-1px); }

		.btn-ghost {
			color: var(--muted);
			background: rgba(21,21,36,0.9);
		}

		.btn-ghost:hover {
			color: var(--accent-light);
			border-color: var(--accent);
		}

		.hero-meta {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}

		.meta-pill {
			font-size: 11px;
			color: var(--muted);
			border: 1px solid var(--border);
			background: rgba(17,17,24,0.85);
			border-radius: 999px;
			padding: 6px 10px;
		}

		.hero-panel {
			background: linear-gradient(160deg, rgba(124,58,237,0.16), rgba(21,21,36,0.98));
			border: 1px solid rgba(124,58,237,0.32);
			border-radius: 18px;
			padding: 18px;
			box-shadow: 0 20px 44px rgba(0,0,0,0.32);
		}

		.hero-panel-top {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 12px;
			color: #d8cdf7;
			font-size: 11px;
		}

		.sparkline {
			height: 120px;
			border-radius: 12px;
			border: 1px solid rgba(124,58,237,0.2);
			background:
				linear-gradient(180deg, rgba(124,58,237,0.16), rgba(124,58,237,0.02)),
				repeating-linear-gradient(
					90deg,
					rgba(124,58,237,0.08) 0,
					rgba(124,58,237,0.08) 1px,
					transparent 1px,
					transparent 42px
				),
				#0f0f1a;
			position: relative;
			overflow: hidden;
			margin-bottom: 12px;
		}

		.sparkline::after {
			content: '';
			position: absolute;
			inset: 0;
			background: linear-gradient(110deg, transparent 0%, rgba(124,58,237,0.24) 50%, transparent 100%);
			transform: translateX(-100%);
			animation: sweep 3.6s ease-in-out infinite;
		}

		@keyframes sweep {
			0%, 20% { transform: translateX(-100%); }
			70%, 100% { transform: translateX(120%); }
		}

		.kpis {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 8px;
		}

		.kpi {
			border: 1px solid rgba(124,58,237,0.24);
			background: rgba(15,15,26,0.8);
			border-radius: 10px;
			padding: 8px;
		}

		.kpi .label {
			font-size: 10px;
			color: #9ca3b8;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			margin-bottom: 2px;
		}

		.kpi .value {
			font-family: 'Syne', sans-serif;
			font-weight: 700;
			font-size: 18px;
			color: #efe9ff;
		}

		.section {
			padding: 14px 0 44px;
		}

		.section-head {
			margin-bottom: 16px;
		}

		.section-head h2 {
			font-family: 'Syne', sans-serif;
			font-size: clamp(24px, 3.2vw, 36px);
			line-height: 1.12;
			margin-bottom: 10px;
		}

		.section-head p {
			color: var(--muted);
			font-size: 13px;
			line-height: 1.75;
			max-width: 760px;
		}

		.benefit-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 12px;
		}

		.benefit {
			background: var(--surface);
			border: 1px solid var(--border);
			border-radius: 14px;
			padding: 14px;
			min-height: 152px;
			transition: transform 0.2s ease, border-color 0.2s ease;
		}

		.benefit:hover {
			transform: translateY(-2px);
			border-color: rgba(124,58,237,0.45);
		}

		.benefit .icon {
			width: 30px;
			height: 30px;
			border-radius: 9px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 15px;
			margin-bottom: 9px;
			background: rgba(124,58,237,0.18);
			border: 1px solid rgba(124,58,237,0.26);
		}

		.benefit h3 {
			font-family: 'Syne', sans-serif;
			font-size: 16px;
			margin-bottom: 6px;
		}

		.benefit p {
			color: var(--muted);
			font-size: 12px;
			line-height: 1.65;
		}

		.tour {
			background: linear-gradient(180deg, rgba(124,58,237,0.08), rgba(17,17,24,0.92));
			border: 1px solid rgba(124,58,237,0.24);
			border-radius: 16px;
			padding: 16px;
		}

		.tour-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 10px;
		}

		.tour-step {
			background: rgba(12,12,21,0.75);
			border: 1px solid rgba(124,58,237,0.24);
			border-radius: 12px;
			padding: 12px;
			position: relative;
		}

		.tour-step .num {
			width: 22px;
			height: 22px;
			border-radius: 50%;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 11px;
			font-weight: 700;
			color: #f1ecff;
			border: 1px solid rgba(124,58,237,0.5);
			background: rgba(124,58,237,0.25);
			margin-bottom: 9px;
		}

		.tour-step h4 {
			font-family: 'Syne', sans-serif;
			font-size: 15px;
			margin-bottom: 6px;
		}

		.tour-step p {
			font-size: 12px;
			color: var(--muted);
			line-height: 1.6;
		}

		.channels {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 10px;
		}

		.channel {
			background: var(--surface);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 12px;
		}

		.channel h4 {
			font-family: 'Syne', sans-serif;
			font-size: 15px;
			margin-bottom: 4px;
		}

		.channel p {
			color: var(--muted);
			font-size: 12px;
			line-height: 1.6;
		}

		.embed {
			background: linear-gradient(170deg, rgba(124,58,237,0.1), rgba(18,18,28,0.96));
			border: 1px solid rgba(124,58,237,0.3);
			border-radius: 16px;
			padding: 16px;
		}

		.embed-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 14px;
		}

		.widget-demo {
			--wdg-accent: #7c3aed;
			border: 1px solid rgba(124,58,237,0.24);
			border-radius: 13px;
			background: #0f0f1a;
			padding: 10px;
		}

		.widget-demo-top {
			display: flex;
			justify-content: space-between;
			align-items: center;
			color: #9ca3b8;
			font-size: 10px;
			margin-bottom: 9px;
		}

		.widget-dots {
			display: inline-flex;
			gap: 5px;
		}

		.widget-dots span {
			width: 7px;
			height: 7px;
			border-radius: 50%;
			background: #2d2d45;
		}

		.widget-canvas {
			position: relative;
			min-height: 258px;
			border: 1px solid #24243a;
			border-radius: 11px;
			background: radial-gradient(circle at 20% 20%, rgba(124,58,237,0.09), transparent 40%), #0b0b14;
			overflow: hidden;
			padding: 12px;
		}

		.canvas-lines {
			width: 80%;
			display: grid;
			gap: 7px;
		}

		.canvas-lines div {
			height: 10px;
			border-radius: 999px;
			background: rgba(148,163,184,0.16);
		}

		.canvas-lines div:nth-child(2) { width: 72%; }
		.canvas-lines div:nth-child(3) { width: 58%; }
		.canvas-lines div:nth-child(5) { width: 64%; }

		.launcher {
			position: absolute;
			right: 10px;
			bottom: 10px;
			border: none;
			border-radius: 999px;
			background: linear-gradient(130deg, var(--wdg-accent), #8a56ec);
			color: white;
			height: 40px;
			padding: 0 13px;
			font-family: 'DM Mono', monospace;
			font-size: 11px;
			display: inline-flex;
			align-items: center;
			gap: 7px;
			cursor: pointer;
			box-shadow: 0 10px 22px rgba(124,58,237,0.36);
			z-index: 3;
		}

		.launcher-dot {
			width: 7px;
			height: 7px;
			border-radius: 50%;
			background: rgba(255,255,255,0.84);
		}

		.widget-panel {
			position: absolute;
			right: 10px;
			bottom: 58px;
			width: 292px;
			border-radius: 13px;
			border: 1px solid #2b2b46;
			background: #0a0a13;
			box-shadow: 0 26px 38px rgba(0,0,0,0.44);
			transform: translateY(8px) scale(0.98);
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
			overflow: hidden;
			z-index: 4;
		}

		.widget-demo.open .widget-panel {
			transform: translateY(0) scale(1);
			opacity: 1;
			pointer-events: auto;
		}

		.widget-head {
			background: var(--wdg-accent);
			color: white;
			padding: 9px 11px;
			font-size: 11px;
			font-weight: 700;
			line-height: 1.2;
		}

		.widget-head span {
			display: block;
			margin-top: 2px;
			font-size: 10px;
			opacity: 0.78;
			font-weight: 400;
		}

		.widget-body {
			padding: 10px;
			display: grid;
			gap: 8px;
		}

		.widget-msg {
			max-width: 88%;
			border: 1px solid #26263f;
			border-radius: 10px;
			padding: 7px;
			color: #cbd5e1;
			font-size: 10px;
			line-height: 1.45;
		}

		.widget-msg.user {
			margin-left: auto;
			color: #e9d5ff;
			border-color: rgba(124,58,237,0.32);
			background: rgba(124,58,237,0.1);
		}

		.widget-input {
			border-top: 1px solid #25253e;
			color: #64748b;
			font-size: 10px;
			padding: 8px 10px;
		}

		.embed-actions {
			margin-top: 10px;
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			align-items: center;
		}

		.embed-note {
			color: var(--muted);
			font-size: 11px;
		}

		.snippet-wrap {
			background: rgba(10,10,15,0.86);
			border: 1px solid rgba(124,58,237,0.3);
			border-radius: 12px;
			overflow: hidden;
		}

		.snippet-head {
			border-bottom: 1px solid rgba(124,58,237,0.25);
			padding: 8px 11px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			font-size: 11px;
			color: #b8a3ff;
			background: rgba(124,58,237,0.08);
		}

		.copy {
			border: 1px solid rgba(124,58,237,0.35);
			border-radius: 8px;
			background: #171728;
			color: var(--muted);
			font-family: 'DM Mono', monospace;
			font-size: 10px;
			padding: 5px 9px;
			cursor: pointer;
		}

		.copy:hover { color: var(--accent-light); border-color: var(--accent); }

		pre {
			margin: 0;
			padding: 11px;
			overflow-x: auto;
			color: #c8d1e4;
			font-size: 11px;
			line-height: 1.6;
			white-space: pre;
		}

		.kw { color: #c084fc; }
		.str { color: #86efac; }
		.cm { color: #6b7280; }

		.tour-cta {
			margin-top: 28px;
			border: 1px solid rgba(124,58,237,0.28);
			border-radius: 14px;
			background: rgba(17,17,24,0.84);
			padding: 16px;
			display: flex;
			justify-content: space-between;
			gap: 14px;
			align-items: center;
			flex-wrap: wrap;
		}

		.tour-cta h3 {
			font-family: 'Syne', sans-serif;
			font-size: 20px;
			margin-bottom: 4px;
		}

		.tour-cta p {
			color: var(--muted);
			font-size: 12px;
			line-height: 1.7;
			max-width: 620px;
		}

		.footer {
			padding: 28px 0 42px;
			text-align: center;
			color: #65708a;
			font-size: 11px;
		}

		@media (max-width: 980px) {
			.hero { grid-template-columns: 1fr; }
			.benefit-grid { grid-template-columns: 1fr 1fr; }
			.tour-grid { grid-template-columns: 1fr 1fr; }
			.channels { grid-template-columns: 1fr 1fr; }
			.embed-grid { grid-template-columns: 1fr; }
		}

		@media (max-width: 680px) {
			nav { padding: 10px 14px; }
			.container { width: min(1140px, 100% - 24px); }
			.hero { padding: 48px 0 40px; }
			.benefit-grid,
			.tour-grid,
			.channels { grid-template-columns: 1fr; }
			.kpis { grid-template-columns: 1fr; }
			.widget-panel {
				width: calc(100% - 20px);
				right: 10px;
			}
			.launcher {
				height: 36px;
				font-size: 10px;
				padding: 0 11px;
			}
			.nav-link:not(.nav-main) { display: none; }
		}
	</style>
	<link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>
	<div class="noise"></div>
	<div class="page">
		<nav>
			<img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
			<div class="nav-title">Lyra<span>link</span> Demo</div>
			<div class="nav-links">
				<a class="nav-link" href="#benefits">Benefits</a>
				<a class="nav-link" href="#tour">Tour</a>
				<a class="nav-link" href="#embed">Embed</a>
				<a class="nav-link nav-main" href="/chat">Open Chat</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
			</div>
		</nav>

		<main class="container">
			<section class="hero">
				<div>
					<div class="eyebrow"><span class="pulse"></span> Interactive Product Tour</div>
					<h1>See What <span class="accent">Lyralink</span> Can Do In One Page</h1>
					<p class="hero-sub">
						This demo walks through the core value: AI chat, operator controls, dataset-backed context, API distribution,
						and embeddable widget deployment so you can launch a branded AI experience fast.
					</p>
					<div class="hero-actions">
						<a href="#tour" class="btn btn-primary">Start The Tour</a>
						<a href="#embed" class="btn btn-ghost">See Embed Distribution</a>
					</div>
					<div class="hero-meta">
						<span class="meta-pill">Dataset context injection</span>
						<span class="meta-pill">Web + API + Widget channels</span>
					</div>
				</div>

				<div class="hero-panel">
					<div class="hero-panel-top">
						<span>Operator Snapshot</span>
						<span>Live-style Preview</span>
					</div>
					<div class="sparkline"></div>
					<div class="kpis">
						<div class="kpi"><div class="label">Channels</div><div class="value">4</div></div>
						<div class="kpi"><div class="label">Setup</div><div class="value">~10m</div></div>
						<div class="kpi"><div class="label">Embed</div><div class="value">1 Snippet</div></div>
					</div>
				</div>
			</section>

			<section class="section" id="benefits">
				<div class="section-head">
					<h2>Why Teams Use Lyralink</h2>
					<p>
						Lyralink is built for operators who want distribution and control, not just a chat box. You get branded rollout,
						monetization hooks, and a unified backend that can serve website visitors, product users, and developers.
					</p>
				</div>
				<div class="benefit-grid">
					<article class="benefit">
						<div class="icon">⚡</div>
						<h3>Fast Launch</h3>
						<p>Spin up a production-ready assistant with account controls, pricing paths, and live chat flows without building backend plumbing first.</p>
					</article>
					<article class="benefit">
						<div class="icon">🧠</div>
						<h3>Context Aware</h3>
						<p>Responses can be guided by your dataset context so answers align with your product, docs, and prior approved knowledge.</p>
					</article>
					<article class="benefit">
						<div class="icon">🎯</div>
						<h3>Operator Tooling</h3>
						<p>Manage clients, branding, distribution, and monetization surfaces from one dashboard instead of scattered point tools.</p>
					</article>
					<article class="benefit">
						<div class="icon">🔌</div>
						<h3>Embed Distribution</h3>
						<p>Drop a single script onto any site to deploy a branded widget and route traffic through your attribution and growth flow.</p>
					</article>
					<article class="benefit">
						<div class="icon">📈</div>
						<h3>Revenue Ready</h3>
						<p>Use plans, credits, and referrals to package your own offer while keeping infrastructure and delivery stable underneath.</p>
					</article>
					<article class="benefit">
						<div class="icon">🔐</div>
						<h3>Secure Foundation</h3>
						<p>API key controls, origin checks, and operational guardrails help keep your deployment dependable as usage scales up.</p>
					</article>
				</div>
			</section>

			<section class="section" id="tour">
				<div class="section-head">
					<h2>90-Second Product Tour</h2>
					<p>
						This is the practical flow most operators follow: configure brand, validate quality, distribute by embed/API,
						then monitor and tune from one command center.
					</p>
				</div>

				<div class="tour">
					<div class="tour-grid">
						<article class="tour-step">
							<div class="num">1</div>
							<h4>Configure Brand</h4>
							<p>Set company identity, colors, and domain strategy so your assistant feels native inside your own customer experience.</p>
						</article>
						<article class="tour-step">
							<div class="num">2</div>
							<h4>Test Real Prompts</h4>
							<p>Run product and support scenarios in chat to verify tone, persona, and answer quality before distribution.</p>
						</article>
						<article class="tour-step">
							<div class="num">3</div>
							<h4>Ship Channels</h4>
							<p>Deploy across web chat, embedded widget, API clients, and internal workflows from the same backend model layer.</p>
						</article>
						<article class="tour-step">
							<div class="num">4</div>
							<h4>Grow + Optimize</h4>
							<p>Use analytics, dataset curation, and automation to improve response relevance and conversion outcomes over time.</p>
						</article>
					</div>
				</div>
			</section>

			<section class="section" id="channels">
				<div class="section-head">
					<h2>Distribution Channels</h2>
					<p>
						Lyralink can be experienced directly by users or integrated into existing stacks. The same intelligence layer powers all channels.
					</p>
				</div>

				<div class="channels">
					<article class="channel">
						<h4>Website Chat</h4>
						<p>Full chat interface for direct conversations, onboarding flows, and in-app guidance.</p>
					</article>
					<article class="channel">
						<h4>Embed Widget</h4>
						<p>Floating launcher for any external site with low-friction installation and branded presentation.</p>
					</article>
					<article class="channel">
						<h4>Public API</h4>
						<p>Programmatic access for extensions, backend services, and custom product experiences.</p>
					</article>
					<article class="channel">
						<h4>Operator Dashboard</h4>
						<p>Control branding, clients, referrals, and distribution snippets from one place.</p>
					</article>
				</div>
			</section>

			<section class="section" id="embed">
				<div class="section-head">
					<h2>Embed Distribution Preview</h2>
					<p>
						This section shows what the widget looks like and exactly what code to place on a site.
						Click the launcher in the preview to simulate the end-user experience.
					</p>
				</div>

				<div class="embed">
					<div class="embed-grid">
						<div class="widget-demo" id="widgetDemo">
							<div class="widget-demo-top">
								<div class="widget-dots"><span></span><span></span><span></span></div>
								<span>Widget Look Preview</span>
							</div>
							<div class="widget-canvas">
								<div class="canvas-lines">
									<div></div><div></div><div></div><div></div><div></div>
								</div>

								<div class="widget-panel" id="widgetPanel" aria-hidden="true">
									<div class="widget-head" id="widgetHeadText">Chat with us<span>We typically reply instantly</span></div>
									<div class="widget-body">
										<div class="widget-msg">Hi there. Need help with setup, pricing, or API integration?</div>
										<div class="widget-msg user">Can I put this on my own website?</div>
										<div class="widget-msg">Yes. Drop in the embed script and your launcher appears automatically.</div>
									</div>
									<div class="widget-input">Type your message...</div>
								</div>

								<button type="button" class="launcher" onclick="toggleDemoWidget()">
									<span class="launcher-dot"></span>
									<span id="launcherLabel">Chat with us</span>
								</button>
							</div>
							<div class="embed-actions">
								<button type="button" class="btn btn-ghost" id="toggleWidgetBtn" onclick="toggleDemoWidget()">Open Preview</button>
								<span class="embed-note">Interactive mock of the real widget launcher + panel behavior.</span>
							</div>
						</div>

						<div>
							<div class="snippet-wrap" style="margin-bottom:10px">
								<div class="snippet-head">
									<span>Standard Install Snippet</span>
									<button class="copy" onclick="copySnippet('snippetA', this)">Copy</button>
								</div>
								<pre id="snippetA"><code><span class="cm">&lt;!-- Lyralink Chat Widget --&gt;</span>
<span class="kw">&lt;script</span> <span class="str">src="https://lyralinkai.com/assets/js/widget.js?v=20260912-2"</span>
  <span class="str">data-ref="YOUR_OPERATOR_TOKEN"</span>
  <span class="str">data-accent="#7c3aed"</span>
	<span class="str">data-embed-mode="iframe"</span>
  <span class="str">data-label="Chat with us"</span><span class="kw">&gt;&lt;/script&gt;</span></code></pre>
							</div>

							<div class="snippet-wrap">
								<div class="snippet-head">
									<span>Dynamic Injection Variant</span>
									<button class="copy" onclick="copySnippet('snippetB', this)">Copy</button>
								</div>
								<pre id="snippetB"><code><span class="kw">&lt;script&gt;</span>
  <span class="kw">(function</span>() {
	<span class="kw">var</span> s = document.createElement(<span class="str">'script'</span>);
	s.src = <span class="str">'https://lyralinkai.com/assets/js/widget.js?v=20260912-2'</span>;
	s.dataset.ref = <span class="str">'YOUR_OPERATOR_TOKEN'</span>;
	s.dataset.accent = <span class="str">'#7c3aed'</span>;
	s.dataset.embedMode = <span class="str">'iframe'</span>;
	s.dataset.label = <span class="str">'Chat with us'</span>;
	document.head.appendChild(s);
  })();
<span class="kw">&lt;/script&gt;</span></code></pre>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="tour-cta">
				<div>
					<h3>Ready To Launch Your Demo Experience?</h3>
					<p>
						Open live chat for hands-on testing, or jump into operator controls to configure branding, distribution, and referral flow.
					</p>
				</div>
				<div style="display:flex; gap:10px; flex-wrap:wrap;">
					<a href="/chat" class="btn btn-primary">Launch Chat</a>
					<a href="/pages/reseller.php" class="btn btn-ghost">Operator Dashboard</a>
				</div>
			</section>

			<div class="footer">Lyralink Demo Page • Built for operators, teams, and embed-ready distribution.</div>
		</main>
	</div>

	<script>
		let widgetOpen = false;

		function setWidgetOpen(open) {
			widgetOpen = !!open;
			const demo = document.getElementById('widgetDemo');
			const panel = document.getElementById('widgetPanel');
			const btn = document.getElementById('toggleWidgetBtn');
			if (!demo || !panel || !btn) return;

			demo.classList.toggle('open', widgetOpen);
			panel.setAttribute('aria-hidden', widgetOpen ? 'false' : 'true');
			btn.textContent = widgetOpen ? 'Close Preview' : 'Open Preview';
		}

		function toggleDemoWidget() {
			setWidgetOpen(!widgetOpen);
		}

		async function copySnippet(id, btn) {
			const el = document.getElementById(id);
			if (!el) return;
			const text = el.innerText;
			try {
				await navigator.clipboard.writeText(text);
				const old = btn.textContent;
				btn.textContent = 'Copied';
				setTimeout(() => { btn.textContent = old; }, 1200);
			} catch (_) {
				btn.textContent = 'Copy Failed';
				setTimeout(() => { btn.textContent = 'Copy'; }, 1200);
			}
		}

		// Keep the preview closed initially for a cleaner first paint.
		setWidgetOpen(false);
	</script>
</body>
</html>
