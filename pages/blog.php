<?php
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
require_once __DIR__ . '/../api/security.php';

$dbCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
$dbOk = !$db->connect_error;
if ($dbOk) {
    $db->set_charset('utf8mb4');
}

function blog_sanitize_html(string $html): string {
    $clean = strip_tags($html, '<p><h2><h3><ul><ol><li><strong><em><a><blockquote><code>');
    return preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $clean) ?? $clean;
}

$slug = trim((string)($_GET['slug'] ?? ''));
$post = null;
$posts = [];

if ($dbOk && $slug !== '') {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($post) {
        $upd = $db->prepare("UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?");
        if ($upd) {
            $id = (int)$post['id'];
            $upd->bind_param('i', $id);
            $upd->execute();
            $upd->close();
        }
    }
} elseif ($dbOk) {
    $res = $db->query("SELECT id, slug, title, excerpt, tags, published_at, view_count FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 30");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $posts[] = $row;
        }
    }
}

$pageTitle = $post ? htmlspecialchars((string)$post['title']) . ' — Lyralink Blog' : 'Blog — Lyralink';
$pageDescription = $post
    ? htmlspecialchars((string)($post['meta_description'] ?? $post['excerpt'] ?? ''))
    : 'Insights on AI infrastructure, automation, and building with Lyralink.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="description" content="<?= $pageDescription ?>">
    <link rel="canonical" href="https://lyralinkai.com/pages/blog/<?= $post ? '?slug=' . urlencode((string)$post['slug']) : '' ?>">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#08080d; --surface:#0f0f16; --surface2:#14141d; --border:#1c1c28; --border2:#252535;
            --accent:#7c3aed; --accent-light:#a78bfa; --accent-glow:rgba(124,58,237,0.15);
            --text:#e8e8f0; --text-muted:#5a5a7a; --text-dim:#9898b8;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh}
        nav{position:sticky;top:0;z-index:100;padding:0 40px;height:60px;display:flex;align-items:center;gap:16px;background:rgba(8,8,13,0.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border)}
        .nav-logo{height:26px;mix-blend-mode:lighten}
        .nav-sep{width:1px;height:20px;background:var(--border2)}
        .nav-title{font-size:12px;color:var(--text-muted);letter-spacing:0.5px}
        .nav-right{margin-left:auto}
        .nav-btn{font-family:'DM Mono',monospace;font-size:11px;padding:6px 14px;border-radius:6px;text-decoration:none;border:1px solid var(--border2);color:var(--text-muted)}
        .nav-btn:hover{border-color:var(--accent);color:var(--accent-light)}
        .page{max-width:820px;margin:0 auto;padding:60px 24px 100px}
        .hero h1{font-family:'Syne',sans-serif;font-size:clamp(32px,5vw,52px);font-weight:800;letter-spacing:-1.5px;margin-bottom:16px}
        .hero p{color:var(--text-dim);font-size:14px;line-height:1.7;margin-bottom:40px}
        .post-card{display:block;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:24px;margin-bottom:14px;text-decoration:none;color:inherit;transition:border-color .2s}
        .post-card:hover{border-color:var(--accent)}
        .post-title{font-family:'Syne',sans-serif;font-size:19px;font-weight:700;margin-bottom:8px}
        .post-excerpt{color:var(--text-dim);font-size:13px;line-height:1.6;margin-bottom:10px}
        .post-meta{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
        .empty{color:var(--text-muted);font-size:13px;padding:40px 0;text-align:center}
        .article-meta{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:24px}
        .article h1{margin-bottom:20px}
        .article-body{font-size:15px;line-height:1.85;color:var(--text)}
        .article-body h2{font-family:'Syne',sans-serif;font-size:22px;margin:28px 0 12px}
        .article-body h3{font-family:'Syne',sans-serif;font-size:18px;margin:22px 0 10px}
        .article-body p{margin-bottom:16px}
        .article-body ul,.article-body ol{margin:0 0 16px 22px}
        .article-body a{color:var(--accent-light)}
        .article-body blockquote{border-left:3px solid var(--accent);padding-left:16px;color:var(--text-dim);margin:16px 0}
        .article-body code{background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:13px}
        .tag{display:inline-block;border:1px solid var(--border2);border-radius:4px;padding:2px 10px;font-size:10px;color:var(--text-muted);margin-right:6px}
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
    <link rel="stylesheet" href="/assets/css/lyra-theme.css">
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body>
<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <div class="nav-sep"></div>
    <span class="nav-title">Blog</span>
    <div class="nav-right"><a href="/" class="nav-btn">← Home</a></div>
</nav>

<div class="page">
<?php if ($post): ?>
    <div class="article">
        <div class="article-meta">
            <?= htmlspecialchars(gmdate('M j, Y', strtotime((string)$post['published_at']))) ?>
            &middot; <?= (int)$post['view_count'] + 1 ?> views
        </div>
        <h1><?= htmlspecialchars((string)$post['title']) ?></h1>
        <?php if (!empty($post['tags'])): ?>
            <div style="margin-bottom:24px">
                <?php foreach (array_filter(array_map('trim', explode(',', (string)$post['tags']))) as $tag): ?>
                    <span class="tag">#<?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="article-body"><?= blog_sanitize_html((string)$post['content_html']) ?></div>
    </div>
    <p style="margin-top:40px"><a href="/pages/blog.php" class="nav-btn">← All posts</a></p>
<?php elseif ($slug !== ''): ?>
    <div class="hero"><h1>Post not found</h1></div>
    <p class="empty"><a href="/pages/blog.php" class="nav-btn">← Back to blog</a></p>
<?php else: ?>
    <div class="hero">
        <h1>Lyralink <em style="color:var(--accent-light);font-style:normal">Blog</em></h1>
        <p>Notes on AI infrastructure, automation, and building Lyralink in public.</p>
    </div>
    <?php if (empty($posts)): ?>
        <div class="empty">No posts yet — check back soon.</div>
    <?php else: ?>
        <?php foreach ($posts as $p): ?>
            <a class="post-card" href="/pages/blog.php?slug=<?= urlencode((string)$p['slug']) ?>">
                <div class="post-title"><?= htmlspecialchars((string)$p['title']) ?></div>
                <?php if (!empty($p['excerpt'])): ?>
                    <div class="post-excerpt"><?= htmlspecialchars((string)$p['excerpt']) ?></div>
                <?php endif; ?>
                <div class="post-meta"><?= htmlspecialchars(gmdate('M j, Y', strtotime((string)$p['published_at']))) ?> &middot; <?= (int)$p['view_count'] ?> views</div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
