<?php
$flagFile = __DIR__ . '/../maintenance.flag';
$infoFile = __DIR__ . '/../maintenance.info';

// If maintenance is off, redirect home
if (!file_exists($flagFile)) {
    header('Location: /'); exit;
}

$eta = file_exists($infoFile) ? trim(file_get_contents($infoFile)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — Down for Maintenance</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <!-- Auto-refresh every 60 seconds to check if site is back -->
    <meta http-equiv="refresh" content="60">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Mono', monospace;
            background: var(--bg); color: var(--text);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }

        /* Animated background orb */
        body::before {
            content: '';
            position: fixed; top: -100px; left: 50%; transform: translateX(-50%);
            width: 800px; height: 500px;
            background: radial-gradient(ellipse, rgba(124,58,237,0.12) 0%, transparent 70%);
            pointer-events: none; animation: float 8s ease-in-out infinite;
        }
        @keyframes float {
            0%,100% { transform: translateX(-50%) translateY(0); }
            50%      { transform: translateX(-50%) translateY(-20px); }
        }

        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 20px; padding: 48px 40px; max-width: 480px; width: 100%;
            text-align: center; position: relative; z-index: 1;
            box-shadow: 0 0 60px rgba(124,58,237,0.08);
        }

        .logo { height: 36px; width: auto; mix-blend-mode: lighten; margin-bottom: 28px; }

        .icon-wrap {
            width: 64px; height: 64px; background: rgba(124,58,237,0.12);
            border: 1px solid rgba(124,58,237,0.3); border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; margin: 0 auto 24px;
            animation: pulse-glow 3s ease-in-out infinite;
        }
        @keyframes pulse-glow {
            0%,100% { box-shadow: 0 0 12px rgba(124,58,237,0.2); }
            50%      { box-shadow: 0 0 28px rgba(124,58,237,0.4); }
        }

        h1 {
            font-family: 'Syne', sans-serif; font-size: 26px; font-weight: 800;
            margin-bottom: 10px; line-height: 1.2;
        }
        h1 span { color: var(--accent-light); }

        .sub {
            font-size: 13px; color: var(--text-muted); line-height: 1.7; margin-bottom: 24px;
        }

        <?php if ($eta): ?>
        .eta-box {
            background: var(--bg); border: 1px solid var(--border); border-radius: 10px;
            padding: 12px 16px; margin-bottom: 24px; font-size: 13px;
        }
        .eta-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .eta-value { color: var(--accent-light); }
        <?php endif; ?>

        .divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }

        .refresh-note {
            font-size: 11px; color: #2a2a3a; display: flex; align-items: center;
            justify-content: center; gap: 6px;
        }
        .refresh-dot {
            width: 6px; height: 6px; border-radius: 50%; background: var(--accent);
            animation: blink 2s infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.2} }

        .discord-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--text-muted); text-decoration: none; font-size: 12px;
            border: 1px solid var(--border); padding: 8px 16px; border-radius: 20px;
            transition: all 0.2s; margin-top: 4px;
        }
        .discord-link:hover { border-color: #5865f2; color: #7289da; }
    </style>
</head>
<body>
<div class="card">
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="logo">

    <div class="icon-wrap">🔧</div>

    <h1>Down for <span>Maintenance</span></h1>
    <p class="sub">
        We're making some improvements to Lyralink.<br>
        We'll be back shortly — thanks for your patience.
    </p>

    <?php if ($eta): ?>
    <div class="eta-box">
        <div class="eta-label">Estimated return</div>
        <div class="eta-value"><?= htmlspecialchars($eta) ?></div>
    </div>
    <?php endif; ?>

    <a href="https://discord.gg/JhyPNs5Khn" target="_blank" class="discord-link">
        <span>🎮</span> Join our Discord for updates
    </a>

    <hr class="divider">

    <div class="refresh-note">
        <div class="refresh-dot"></div>
        Page refreshes automatically every 60 seconds
    </div>
</div>
</body>
</html>