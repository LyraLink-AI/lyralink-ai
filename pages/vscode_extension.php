<?php
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php');
    exit;
}

$extDir = dirname(__DIR__) . '/vscodeextention/lyralink-vscode';
$files = glob($extDir . '/*.vsix') ?: [];

usort($files, static function (string $a, string $b): int {
    return (int)filemtime($b) <=> (int)filemtime($a);
});

$latestFilePath = $files[0] ?? null;
$latestFileName = $latestFilePath ? basename($latestFilePath) : '';
$downloadUrl = $latestFileName !== '' ? '/vscodeextention/lyralink-vscode/' . rawurlencode($latestFileName) : '';
$sizeText = '-';
$versionText = '-';

if ($latestFilePath && is_file($latestFilePath)) {
    $bytes = filesize($latestFilePath);
    if ($bytes !== false) {
        $sizeText = number_format(((float)$bytes) / (1024 * 1024), 2) . ' MB';
    }
    if (preg_match('/-(\d+\.\d+\.\d+)\.vsix$/', $latestFileName, $m)) {
        $versionText = $m[1];
    }
}

$pkgPath = $extDir . '/package.json';
$publisher = 'lyralink';
$name = 'lyralink-ai';
if (is_file($pkgPath)) {
    $pkgRaw = file_get_contents($pkgPath);
    if ($pkgRaw !== false) {
        $pkg = json_decode($pkgRaw, true);
        if (is_array($pkg)) {
            $publisher = (string)($pkg['publisher'] ?? $publisher);
            $name = (string)($pkg['name'] ?? $name);
        }
    }
}
$extensionId = $publisher . '.' . $name;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink VS Code Extension</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f;
            --surface:#111118;
            --surface2:#171724;
            --border:#24243a;
            --text:#d9e1ef;
            --muted:#7e8aa3;
            --accent:#ff6b35;
            --accent2:#f59e0b;
            --ok:#22c55e;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'DM Mono', monospace;
            background:
                radial-gradient(1000px 500px at 85% -120px, rgba(255,107,53,.09), transparent 65%),
                radial-gradient(850px 460px at -10% 120%, rgba(245,158,11,.07), transparent 60%),
                var(--bg);
            color:var(--text);
            min-height:100vh;
        }
        nav {
            position:sticky;
            top:0;
            z-index:20;
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 20px;
            border-bottom:1px solid var(--border);
            background:rgba(10,10,15,.92);
            backdrop-filter:blur(12px);
        }
        .nav-logo { height:28px; mix-blend-mode:lighten; }
        .nav-right { margin-left:auto; display:flex; gap:8px; }
        .nav-link {
            font-size:11px;
            color:var(--muted);
            text-decoration:none;
            border:1px solid var(--border);
            border-radius:999px;
            padding:5px 10px;
        }
        .nav-link:hover { color:#fff; border-color:#ff8c5d; }
        .page { max-width:980px; margin:0 auto; padding:28px 20px 40px; }
        .hero {
            border:1px solid rgba(255,140,93,.38);
            border-radius:16px;
            background:linear-gradient(135deg, rgba(255,107,53,.12), rgba(245,158,11,.07));
            padding:20px;
            margin-bottom:14px;
        }
        h1 {
            font-family:'Syne', sans-serif;
            font-size:31px;
            line-height:1.15;
            margin-bottom:8px;
        }
        .hero p { color:#ffd9c8; font-size:12px; line-height:1.8; }
        .meta {
            margin-top:14px;
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }
        .pill {
            display:inline-flex;
            border:1px solid var(--border);
            border-radius:999px;
            padding:4px 10px;
            font-size:11px;
            color:var(--muted);
            background:rgba(0,0,0,.2);
        }
        .cards { display:grid; grid-template-columns:1.1fr .9fr; gap:12px; margin-top:12px; }
        .card {
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:14px;
            padding:14px;
        }
        .title {
            font-family:'Syne', sans-serif;
            font-size:16px;
            margin-bottom:8px;
        }
        .btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border-radius:10px;
            font-size:12px;
            padding:10px 14px;
            border:1px solid #303049;
            background:var(--surface2);
            color:var(--text);
            cursor:pointer;
        }
        .btn.primary {
            background:rgba(255,107,53,.18);
            border-color:#ff8c5d;
            color:#ffe1d3;
        }
        .btn:hover { border-color:#ff8c5d; color:#fff; }
        .list { margin-top:10px; display:flex; flex-direction:column; gap:9px; }
        .item {
            border:1px solid var(--border);
            border-radius:10px;
            padding:10px;
            background:#0f1220;
        }
        .item strong { color:#ffcea8; font-size:12px; display:block; margin-bottom:5px; }
        .item p { color:var(--muted); font-size:11px; line-height:1.7; }
        .code {
            margin-top:8px;
            border:1px solid #2a2f41;
            border-radius:10px;
            background:#0b0d12;
            color:#dfffe7;
            padding:10px;
            font-size:11px;
            overflow:auto;
            white-space:pre;
        }
        .warn {
            margin-top:10px;
            border:1px solid rgba(245,158,11,.4);
            border-radius:10px;
            background:rgba(245,158,11,.07);
            color:#ffdcb2;
            padding:10px;
            font-size:11px;
            line-height:1.7;
        }
        .ok {
            color:#9ef0bb;
            border-color:rgba(34,197,94,.4);
            background:rgba(34,197,94,.08);
        }
        @media (max-width: 900px) {
            .cards { grid-template-columns:1fr; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span style="color:#3c3f52">/</span>
    <span style="font-family:'Syne',sans-serif;font-size:13px;color:#ffb08e">VS Code Extension</span>
    <div class="nav-right">
        <a href="/pages/api_keys/" class="nav-link">API Keys</a>
        <a href="/pages/api_docs/" class="nav-link">API Docs</a>
        <a href="/" class="nav-link">Home</a>
    </div>
</nav>

<div class="page">
    <section class="hero">
        <h1>Install Lyralink AI in VS Code</h1>
        <p>Download the packaged extension, install it in seconds, then connect your API key and start using Run and Auto-Fix directly in your editor.</p>
        <div class="meta">
            <span class="pill">Extension ID: <?= htmlspecialchars($extensionId, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="pill">Version: <?= htmlspecialchars($versionText, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="pill">Package size: <?= htmlspecialchars($sizeText, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </section>

    <div class="cards">
        <section class="card">
            <div class="title">1) Download</div>
            <?php if ($downloadUrl !== ''): ?>
                <a class="btn primary" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" download>Download <?= htmlspecialchars($latestFileName, ENT_QUOTES, 'UTF-8') ?></a>
                <div class="warn ok" style="margin-top:10px">Latest package detected automatically from the server: <?= htmlspecialchars($latestFileName, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="warn">No .vsix file was found in /vscodeextention/lyralink-vscode/. Add a packaged extension and this page will auto-detect it.</div>
            <?php endif; ?>

            <div class="list">
                <div class="item">
                    <strong>Install from VS Code UI</strong>
                    <p>Open Extensions view, click the three-dot menu, choose Install from VSIX, and select the downloaded file.</p>
                </div>
                <div class="item">
                    <strong>Install from command line</strong>
                    <p>Run this from your terminal:</p>
                    <div class="code">code --install-extension &lt;path-to-your-vsix-file&gt;</div>
                </div>
                <div class="item">
                    <strong>Set your API key</strong>
                    <p>Open Command Palette and run Lyralink: Set API Key. Get your key here:</p>
                    <div class="code">https://ai.cloudhavenx.com/pages/api_keys/</div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="title">2) Quick Start</div>
            <div class="list">
                <div class="item">
                    <strong>Run and Auto-Fix</strong>
                    <p>Use Ctrl+Shift+R (Cmd+Shift+R on macOS) to run current file and apply AI fixes when errors are detected.</p>
                </div>
                <div class="item">
                    <strong>Fix selected code</strong>
                    <p>Select code and use Ctrl+Shift+F (Cmd+Shift+F on macOS) to rewrite the selected block.</p>
                </div>
                <div class="item">
                    <strong>Explain an error</strong>
                    <p>Right-click in the editor and choose Lyralink: Explain Error to get a plain-language explanation.</p>
                </div>
            </div>
            <div class="warn">If <span style="color:#fff">code</span> command is missing, run the VS Code command: Shell Command: Install 'code' command in PATH.</div>
        </section>
    </div>
</div>
</body>
</html>
