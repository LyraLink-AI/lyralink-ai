<?php
$manifestPath = __DIR__ . '/../desktop-updates/latest.json';
$manifestRaw = @file_get_contents($manifestPath);
$manifest = json_decode((string)$manifestRaw, true);
if (!is_array($manifest)) {
    $manifest = [];
}

$latestVersion = trim((string)($manifest['version'] ?? '1.0.2'));
$releaseNotes = trim((string)($manifest['notes'] ?? 'Latest stable desktop release.'));
$downloadUrl = trim((string)($manifest['installerUrl'] ?? ''));
if ($downloadUrl === '') {
    $downloadUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&version=' . rawurlencode($latestVersion);
}

$webInstallerPath = __DIR__ . '/../desktop-updates/Lyralink-Web-Setup.exe';
$offlineInstallerPath = __DIR__ . '/../desktop-updates/Lyralink-Setup.exe';
$portableZipPath = __DIR__ . '/../desktop-updates/Lyralink-win32-x64.zip';
$linuxTarballPath = __DIR__ . '/../desktop-updates/Lyralink-linux-x64.tar.gz';
$linuxDebPath = __DIR__ . '/../desktop-updates/Lyralink-linux-x64.deb';
$linuxAppImagePath = __DIR__ . '/../desktop-updates/Lyralink-linux-x86_64.AppImage';

$webInstallerExists = is_file($webInstallerPath);
$offlineInstallerExists = is_file($offlineInstallerPath);
$portableZipExists = is_file($portableZipPath);
$linuxTarballExists = is_file($linuxTarballPath);
$linuxDebExists = is_file($linuxDebPath);
$linuxAppImageExists = is_file($linuxAppImagePath);

$webInstallerUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=web_setup&version=' . rawurlencode($latestVersion);
$offlineInstallerUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=offline_setup&version=' . rawurlencode($latestVersion);
$portableZipUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=portable_zip&version=' . rawurlencode($latestVersion);
$linuxTarballUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=linux_tarball&version=' . rawurlencode($latestVersion);
$linuxDebUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=linux_deb&version=' . rawurlencode($latestVersion);
$linuxAppImageUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=linux_appimage&version=' . rawurlencode($latestVersion);
$bazziteAppImageUrl = '/desktop-updates/download.php?channel=stable&source=web_download_page&artifact=bazzite_appimage&version=' . rawurlencode($latestVersion);

$ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isLinuxVisitor = strpos($ua, 'linux') !== false && strpos($ua, 'android') === false;
$isBazziteVisitor = strpos($ua, 'bazzite') !== false;

$windowsPrimaryUrl = $webInstallerExists ? $webInstallerUrl : $offlineInstallerUrl;
$windowsPrimaryLabel = $webInstallerExists ? 'Download Windows Web Installer' : 'Download Windows Offline Installer';

$primaryDownloadUrl = $windowsPrimaryUrl;
$primaryDownloadLabel = $windowsPrimaryLabel;
if ($isBazziteVisitor && $linuxAppImageExists) {
    $primaryDownloadUrl = $bazziteAppImageUrl;
    $primaryDownloadLabel = 'Download for Bazzite (AppImage)';
} else if ($isLinuxVisitor && $linuxAppImageExists) {
    $primaryDownloadUrl = $linuxAppImageUrl;
    $primaryDownloadLabel = 'Download Linux AppImage (x64)';
} else if ($isLinuxVisitor && $linuxDebExists) {
    $primaryDownloadUrl = $linuxDebUrl;
    $primaryDownloadLabel = 'Download Linux .deb (x64)';
} else if ($isLinuxVisitor && $linuxTarballExists) {
    $primaryDownloadUrl = $linuxTarballUrl;
    $primaryDownloadLabel = 'Download Linux tar.gz (x64)';
}

$webInstallerSize = $webInstallerExists ? filesize($webInstallerPath) : 0;
$offlineInstallerSize = $offlineInstallerExists ? filesize($offlineInstallerPath) : 0;
$portableZipSize = $portableZipExists ? filesize($portableZipPath) : 0;
$linuxTarballSize = $linuxTarballExists ? filesize($linuxTarballPath) : 0;
$linuxDebSize = $linuxDebExists ? filesize($linuxDebPath) : 0;
$linuxAppImageSize = $linuxAppImageExists ? filesize($linuxAppImagePath) : 0;
$anyLinuxArtifactExists = $linuxAppImageExists || $linuxDebExists || $linuxTarballExists;

$artifactMtimes = [];
if ($webInstallerExists) $artifactMtimes[] = (int)filemtime($webInstallerPath);
if ($offlineInstallerExists) $artifactMtimes[] = (int)filemtime($offlineInstallerPath);
if ($portableZipExists) $artifactMtimes[] = (int)filemtime($portableZipPath);
if ($linuxTarballExists) $artifactMtimes[] = (int)filemtime($linuxTarballPath);
if ($linuxDebExists) $artifactMtimes[] = (int)filemtime($linuxDebPath);
if ($linuxAppImageExists) $artifactMtimes[] = (int)filemtime($linuxAppImagePath);
$latestArtifactMtime = !empty($artifactMtimes) ? max($artifactMtimes) : 0;

function human_bytes($bytes) {
    if ($bytes <= 0) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return number_format($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

$updatedLabel = $latestArtifactMtime ? date('M j, Y g:i A', $latestArtifactMtime) . ' UTC' : 'Unknown';

$latestVersionEsc = htmlspecialchars($latestVersion, ENT_QUOTES, 'UTF-8');
$releaseNotesEsc = htmlspecialchars($releaseNotes, ENT_QUOTES, 'UTF-8');
$downloadUrlEsc = htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8');
$updatedLabelEsc = htmlspecialchars($updatedLabel, ENT_QUOTES, 'UTF-8');
$primaryDownloadUrlEsc = htmlspecialchars($primaryDownloadUrl, ENT_QUOTES, 'UTF-8');
$primaryDownloadLabelEsc = htmlspecialchars($primaryDownloadLabel, ENT_QUOTES, 'UTF-8');
$webInstallerUrlEsc = htmlspecialchars($webInstallerUrl, ENT_QUOTES, 'UTF-8');
$offlineInstallerUrlEsc = htmlspecialchars($offlineInstallerUrl, ENT_QUOTES, 'UTF-8');
$portableZipUrlEsc = htmlspecialchars($portableZipUrl, ENT_QUOTES, 'UTF-8');
$linuxTarballUrlEsc = htmlspecialchars($linuxTarballUrl, ENT_QUOTES, 'UTF-8');
$linuxDebUrlEsc = htmlspecialchars($linuxDebUrl, ENT_QUOTES, 'UTF-8');
$linuxAppImageUrlEsc = htmlspecialchars($linuxAppImageUrl, ENT_QUOTES, 'UTF-8');
$webInstallerSizeEsc = htmlspecialchars(human_bytes((int)$webInstallerSize), ENT_QUOTES, 'UTF-8');
$offlineInstallerSizeEsc = htmlspecialchars(human_bytes((int)$offlineInstallerSize), ENT_QUOTES, 'UTF-8');
$portableZipSizeEsc = htmlspecialchars(human_bytes((int)$portableZipSize), ENT_QUOTES, 'UTF-8');
$linuxTarballSizeEsc = htmlspecialchars(human_bytes((int)$linuxTarballSize), ENT_QUOTES, 'UTF-8');
$linuxDebSizeEsc = htmlspecialchars(human_bytes((int)$linuxDebSize), ENT_QUOTES, 'UTF-8');
$linuxAppImageSizeEsc = htmlspecialchars(human_bytes((int)$linuxAppImageSize), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink Desktop Download</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f;
            --surface: #111118;
            --surface2: #151522;
            --border: #25263a;
            --text: #e2e8f0;
            --text-muted: #64748b;
            --text-dim: #94a3b8;
            --accent: #7c3aed;
            --accent-light: #a78bfa;
            --accent-glow: rgba(124,58,237,0.28);
            --bolt: #ff7a2f;
            --bolt-glow: rgba(255,122,47,0.25);
            --ok: #22c55e;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Mono', monospace;
            background: radial-gradient(1100px 500px at 12% -10%, rgba(124,58,237,0.18), transparent 70%),
                        radial-gradient(900px 460px at 92% -5%, rgba(255,122,47,0.18), transparent 72%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .noise {
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .035;
            background-image: radial-gradient(rgba(255,255,255,.6) .4px, transparent .4px);
            background-size: 3px 3px;
            z-index: 0;
        }

        nav {
            position: sticky;
            top: 0;
            z-index: 20;
            backdrop-filter: blur(12px);
            background: rgba(10,10,15,.78);
            border-bottom: 1px solid var(--border);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .nav-logo {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 0 18px var(--accent-glow);
        }
        .nav-title {
            font-family: 'Syne', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dim);
            letter-spacing: .5px;
        }
        .nav-links { margin-left: auto; display: flex; gap: 8px; }
        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 11px;
            transition: .2s;
        }
        .nav-link:hover { border-color: var(--accent-light); color: var(--accent-light); }

        .wrap {
            position: relative;
            z-index: 2;
            max-width: 1080px;
            margin: 0 auto;
            padding: 48px 20px 84px;
        }
        .platform-jump {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0 0 18px;
        }
        .platform-jump a {
            text-decoration: none;
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 11px;
            background: rgba(124,58,237,.08);
            transition: .2s;
        }
        .platform-jump a:hover { border-color: var(--accent-light); color: var(--accent-light); background: rgba(124,58,237,.16); }

        .hero {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 24px;
            align-items: stretch;
        }
        @media (max-width: 900px) { .hero { grid-template-columns: 1fr; } }
        @media (max-width: 700px) {
            .platform-jump { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px; }
            .platform-jump a { white-space: nowrap; }
        }

        .card {
            border: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(17,17,24,.92) 0%, rgba(12,12,18,.95) 100%);
            border-radius: 20px;
            padding: 28px;
            position: relative;
            overflow: hidden;
        }
        .card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(125deg, rgba(124,58,237,.08), transparent 45%, rgba(255,122,47,.08));
            pointer-events: none;
        }

        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border: 1px solid rgba(124,58,237,.34);
            color: var(--accent-light);
            border-radius: 999px;
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 5px 12px;
            margin-bottom: 14px;
        }
        .kicker .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent-light);
            box-shadow: 0 0 0 0 rgba(167,139,250,.5);
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(167,139,250,.5); }
            70% { box-shadow: 0 0 0 8px rgba(167,139,250,0); }
            100% { box-shadow: 0 0 0 0 rgba(167,139,250,0); }
        }

        h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(30px, 6vw, 54px);
            line-height: 1.08;
            margin-bottom: 14px;
            letter-spacing: -.8px;
        }
        h1 .bolt {
            color: var(--bolt);
            text-shadow: 0 0 20px var(--bolt-glow);
        }
        .lead {
            color: var(--text-dim);
            font-size: 14px;
            line-height: 1.8;
            max-width: 58ch;
        }

        .action-row {
            margin-top: 22px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: linear-gradient(120deg, var(--accent), #6530c9);
            color: #fff;
            box-shadow: 0 0 28px var(--accent-glow);
        }
        .btn-primary:hover { transform: translateY(-1px); filter: brightness(1.08); }
        .btn-secondary {
            border-color: var(--border);
            color: var(--text-dim);
            background: rgba(255,255,255,.02);
        }
        .btn-secondary:hover { border-color: var(--accent-light); color: var(--accent-light); }

        .meta {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 10px;
        }
        @media (max-width: 640px) { .meta { grid-template-columns: 1fr; } }
        .meta-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(8,8,12,.6);
            padding: 11px 12px;
        }
        .meta-k { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .8px; }
        .meta-v { margin-top: 4px; font-size: 13px; color: var(--text); }

        .panel h3 {
            font-family: 'Syne', sans-serif;
            font-size: 20px;
            margin-bottom: 14px;
        }
        .release-note {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            background: rgba(10,10,15,.8);
            color: var(--text-dim);
            line-height: 1.7;
            font-size: 12px;
            margin-bottom: 12px;
        }
        .mini-list {
            list-style: none;
            display: grid;
            gap: 8px;
        }
        .mini-list li {
            font-size: 11px;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mini-list .tick {
            color: var(--ok);
            font-weight: 700;
        }

        .badge {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(34,197,94,.12);
            border: 1px solid rgba(34,197,94,.3);
            color: var(--ok);
            font-size: 11px;
            padding: 6px 10px;
            border-radius: 999px;
        }

        .spot {
            position: absolute;
            width: 220px;
            height: 220px;
            right: -60px;
            top: -70px;
            border-radius: 24px;
            transform: rotate(22deg);
            background: linear-gradient(130deg, rgba(255,122,47,.14), rgba(124,58,237,.18));
            border: 1px solid rgba(255,255,255,.09);
            box-shadow: 0 0 40px rgba(255,122,47,.12);
            pointer-events: none;
        }

        .foot {
            margin-top: 18px;
            color: var(--text-muted);
            font-size: 11px;
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
</head>
<body>
<div class="noise"></div>
<nav>
    <img src="/images/lyralinklogobolt.png" alt="Lyralink" class="nav-logo">
    <div class="nav-title">Lyralink Desktop</div>
    <div class="nav-links">
        <a class="nav-link" href="/chat">Chat</a>
        <a class="nav-link" href="/pages/pricing/">Pricing</a>
        <a href="/pages/landing/" class="nav-link">New UI</a>
    </div>
</nav>

<main class="wrap">
    <div class="platform-jump" aria-label="Download platform quick links">
        <a href="#windowsInstallers">Windows</a>
        <a href="#linuxArtifacts">Linux</a>
        <a href="#releaseSnapshot">Release Notes</a>
    </div>

    <section class="hero">
        <article class="card">
            <div class="spot"></div>
            <div class="kicker"><span class="dot"></span>Stable Channel</div>
            <h1>Download <span class="bolt">Lyralink</span> Desktop</h1>
            <p class="lead">A focused desktop shell for faster launches, cleaner updates, and native voice-ready workflows. Built to feel sharp, minimal, and unmistakably Lyralink.</p>
            <div class="action-row">
                <a class="btn btn-primary" id="windowsInstallers" href="<?php echo $primaryDownloadUrlEsc; ?>">⬇ <?php echo $primaryDownloadLabelEsc; ?></a>
                <a class="btn btn-secondary" href="<?php echo $offlineInstallerUrlEsc; ?>">Windows Offline Installer (<?php echo $offlineInstallerSizeEsc; ?>)</a>
                <?php if ($linuxAppImageExists): ?>
                    <a class="btn btn-secondary" id="linuxArtifacts" href="<?php echo $linuxAppImageUrlEsc; ?>">Linux AppImage (<?php echo $linuxAppImageSizeEsc; ?>)</a>
                <?php endif; ?>
                <?php if ($linuxDebExists): ?>
                    <a class="btn btn-secondary" href="<?php echo $linuxDebUrlEsc; ?>">Linux .deb (<?php echo $linuxDebSizeEsc; ?>)</a>
                <?php endif; ?>
                <?php if ($linuxTarballExists): ?>
                    <a class="btn btn-secondary" href="<?php echo $linuxTarballUrlEsc; ?>">Linux tar.gz (<?php echo $linuxTarballSizeEsc; ?>)</a>
                <?php elseif (!$anyLinuxArtifactExists): ?>
                    <span class="btn btn-secondary" style="opacity:.72; cursor:not-allowed;">Linux Build: Publishing Soon</span>
                <?php endif; ?>
                <a class="btn btn-secondary" href="/chat">Open Web Version</a>
            </div>
            <div class="meta">
                <div class="meta-item"><div class="meta-k">Latest Version</div><div class="meta-v">v<?php echo $latestVersionEsc; ?></div></div>
                <div class="meta-item"><div class="meta-k">Web Installer Size</div><div class="meta-v"><?php echo $webInstallerExists ? $webInstallerSizeEsc : 'Not published yet'; ?></div></div>
                <div class="meta-item"><div class="meta-k">Linux AppImage</div><div class="meta-v"><?php echo $linuxAppImageExists ? $linuxAppImageSizeEsc : 'Not published yet'; ?></div></div>
                <div class="meta-item"><div class="meta-k">Linux .deb</div><div class="meta-v"><?php echo $linuxDebExists ? $linuxDebSizeEsc : 'Not published yet'; ?></div></div>
                <div class="meta-item"><div class="meta-k">Last Updated</div><div class="meta-v"><?php echo $updatedLabelEsc; ?></div></div>
            </div>
            <div class="foot">If your browser blocks direct downloads, right-click the button and open in a new tab.</div>
            <?php if ($portableZipExists): ?>
                <div class="foot" style="margin-top:8px;">Need a portable artifact? <a href="<?php echo $portableZipUrlEsc; ?>" style="color:var(--accent-light)">Download ZIP (<?php echo $portableZipSizeEsc; ?>)</a>.</div>
            <?php endif; ?>
        </article>

        <aside class="card panel" id="releaseSnapshot">
            <h3>Release Snapshot</h3>
            <div class="release-note"><?php echo $releaseNotesEsc; ?></div>
            <ul class="mini-list">
                <li><span class="tick">✓</span> Auto-update checks against your stable manifest</li>
                <li><span class="tick">✓</span> Branded top bar and native-feel controls</li>
                <li><span class="tick">✓</span> Desktop package excludes bundled core web/PHP files</li>
                <li><span class="tick">✓</span> Linux AppImage and .deb packages are now published</li>
                <li><span class="tick">✓</span> Bazzite visitors default to AppImage for compatibility</li>
                <li><span class="tick">✓</span> Download events tracked in developer admin</li>
                <li><span class="tick">✓</span> Web installer can bootstrap full app from your update endpoint</li>
            </ul>
            <div class="badge">⚡ Brand Build: Lyralink Bolt Edition</div>
        </aside>
    </section>
</main>
</body>
</html>
