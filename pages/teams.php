<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lyra_ui_nav.php';
require_once __DIR__ . '/../api/lyra_teams_chrome.php';
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
/* Collaboration workspace. Implements the approved Teams-style design.
 * Reads real rows from the existing social_* tables. Uses SELECT * and defensive
 * key access so a schema change degrades to an empty state, not a fatal error.
 * Posting/presence/calls are NOT implemented here — this is the interface shell. */

$channels = $messages = $members = [];
$active = null; $dbError = null; $users = []; $chanCounts = []; $nOnline = 0;

try {
    $cfg = api_db_config(['host'=>'localhost','user'=>'app_user','pass'=>'','name'=>'aicloud']);
    $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
    if ($db->connect_error) { $dbError = $db->connect_error; }
    else {
        // Each query is isolated: mysli throws on error in PHP 8.1+, and an
        // unguarded failure here previously aborted every query after it,
        // blanking panels that had perfectly good data available.
        $run = function (string $sql) use ($db): array {
            try {
                $rows = [];
                $res = $db->query($sql);
                if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
                return $rows;
            } catch (\Throwable $e) { return []; }
        };

        $channels = $run('SELECT * FROM social_channels ORDER BY position, id');
        $members  = $run('SELECT * FROM social_server_members LIMIT 50');
        foreach ($run('SELECT id, username, plan FROM users') as $r) {
            $users[(int) $r['id']] = $r;
        }

        // Per-channel message totals, so the sidebar shows a real count for
        // every channel rather than only the one that happens to be open.
        foreach ($run(
            'SELECT cc.channel_id, COUNT(m.id) AS n '
            . 'FROM social_channel_conversations cc '
            . 'LEFT JOIN social_messages m ON m.conversation_id = cc.conversation_id '
            . 'GROUP BY cc.channel_id'
        ) as $r) {
            $chanCounts[(int) $r['channel_id']] = (int) $r['n'];
        }

        // Presence is derived from last_seen_at, which is the only signal the
        // schema actually provides. Anything seen in the last 5 minutes counts
        // as online; that is a real measurement, not an invented one.
        $cutoff = date('Y-m-d H:i:s', time() - 300);
        foreach ($members as $mm) {
            $ls = (string) ($mm['last_seen_at'] ?? '');
            if ($ls !== '' && $ls >= $cutoff) { $nOnline++; }
        }

        // Messages hang off conversations, which hang off channels:
        // social_channels <- social_channel_conversations -> social_messages
        if ($channels) {
            // Honour ?channel=<id> so the channel list is real navigation
            // instead of a row of links that all return the same channel.
            $want = isset($_GET['channel']) ? (int) $_GET['channel'] : 0;
            $active = $channels[0];
            if ($want > 0) {
                foreach ($channels as $c) {
                    if ((int) ($c['id'] ?? 0) === $want) { $active = $c; break; }
                }
            }
            $cid = (int) ($active['id'] ?? 0);
            $messages = $run(
                'SELECT m.* FROM social_messages m '
                . 'JOIN social_channel_conversations cc ON cc.conversation_id = m.conversation_id '
                . 'WHERE cc.channel_id = ' . $cid . ' ORDER BY m.id ASC LIMIT 200'
            );
        }
        $db->close();
    }
} catch (\Throwable $e) { $dbError = $e->getMessage(); }

function pk(array $r, array $keys, string $d = ''): string {
    foreach ($keys as $k) { if (isset($r[$k]) && trim((string)$r[$k]) !== '') return (string)$r[$k]; }
    return $d;
}
function inits(string $s): string {
    $c = preg_replace('/[^A-Za-z]/', '', $s);
    return strtoupper(substr($c !== '' ? $c : 'U', 0, 2));
}

/* Display name for a user id, falling back to an explicit identifier rather
 * than a silent zero. The previous version read `user_id`/`author_id`, but the
 * real column is `sender_user_id`, so every message was labelled "user 0". */
function tw_author(int $uid, array $users): string {
    if (isset($users[$uid]) && trim((string) $users[$uid]['username']) !== '') {
        return (string) $users[$uid]['username'];
    }
    return $uid > 0 ? 'user ' . $uid : 'unknown';
}

/* Relative time for recent items, absolute date for anything older, so an old
 * message never reads as if it just arrived. */
function tw_when(string $s): string {
    $t = strtotime($s);
    if ($t === false) { return $s; }
    $d = time() - $t;
    if ($d < 0)     { return date('M j, Y H:i', $t); }
    if ($d < 60)    { return 'just now'; }
    if ($d < 3600)  { return floor($d / 60) . 'm ago'; }
    if ($d < 86400) { return floor($d / 3600) . 'h ago'; }
    if ($d < 604800) { return floor($d / 86400) . 'd ago'; }
    return date('M j, Y', $t);
}

$nCh = count($channels); $nMsg = count($messages); $nMem = count($members);
$activeName = $active ? pk($active, ['name'], 'channel') : '';

/* LYRA_TEAMS_VIEWER_BLOCK -- idempotency marker; this exact string appears
 * only in the inserted block, never in the text it replaced, so a second run
 * of the patch cannot match its own output and insert a duplicate.
 *
 * Who is looking at this page. Both the top bar and the greeting previously
 * embedded a literal name and initials, so every account saw the same
 * identity regardless of who was signed in. Resolve it from the session,
 * falling back to an explicit guest label rather than a name that is wrong.
 * (The literal strings are deliberately not repeated in this comment, so that
 * searching for them finds markup, not prose.) */
$sessUid     = (int) ($_SESSION['user_id'] ?? 0);
$sessUname   = trim((string) ($_SESSION['username'] ?? ''));
$viewerName  = '';
$viewerPlan  = '';
if ($sessUid > 0 && isset($users[$sessUid])) {
    $viewerName = trim((string) ($users[$sessUid]['username'] ?? ''));
    $viewerPlan = trim((string) ($users[$sessUid]['plan'] ?? ''));
}
if ($viewerName === '' && $sessUname !== '') { $viewerName = $sessUname; }
$isGuest        = ($viewerName === '');
$viewerLabel    = $isGuest ? 'Guest' : $viewerName;
$viewerInitials = inits($viewerLabel);
$viewerStatus   = $isGuest
    ? 'Not signed in'
    : ($viewerPlan !== '' ? ucfirst($viewerPlan) . ' plan' : 'Online');

/* Time-of-day greeting, computed rather than written into the markup. */
$hourAtRender = (int) date('G');
$greetingWord = $hourAtRender < 12
    ? 'Good morning'
    : ($hourAtRender < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Workspace | Lyralink</title>
<link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/lyra-ui.css">
<link rel="stylesheet" href="/assets/css/lyra-teams.css">
<script src="/assets/js/lyra-ui.js" defer></script>
<style>
/* ── SHELL ────────────────────────────────────────────────────────────────
   Full-width top bar with the sidebar / channel / rail grid beneath it,
   matching the approved mockup. The shell is pinned to the viewport and each
   column scrolls on its own, so the page itself never scrolls and the columns
   cannot drift out of alignment. */
.tw-shell{display:grid;grid-template-columns:250px minmax(0,1fr) 320px;grid-template-rows:auto minmax(0,1fr);height:100vh;height:100dvh;overflow:hidden}

/* Top bar spans the full width with its own copy of the shell's column
   template, so the search field sits over the centre column and the account
   block over the rail, exactly as the mockup does. */
.tw-top{grid-column:1 / -1;grid-row:1;display:grid;grid-template-columns:250px minmax(0,1fr) 320px;align-items:center;height:var(--ly-topbar-h);padding:0 22px 0 18px;border-bottom:1px solid var(--ly-border);background:rgba(2,9,26,.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);position:relative;z-index:60}
.tw-brand{min-width:0;overflow:hidden}
.tw-search{grid-column:2;min-width:0;padding-right:22px}
.tw-user{grid-column:3;display:flex;align-items:center;gap:10px;justify-content:flex-end;min-width:0}

.tw-nav{grid-column:1;grid-row:2}
.tw-center{grid-column:2;grid-row:2;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden}
.tw-rail{grid-column:3;grid-row:2}

/* Sidebar and rail scroll internally. The sidebar keeps its own scroll region
   for the channel list so the promo card can stay pinned at the bottom.
   min-height must be reset here: the shared .ly-sidebar rule sets
   min-height:100vh, which inside a fixed-height shell makes the column taller
   than its grid row and pushes the promo card off-screen. */
.tw-nav{display:flex;flex-direction:column;overflow:hidden}
.tw-shell .ly-sidebar{min-height:0}
.tw-navscroll{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain}
.tw-rail{display:flex;flex-direction:column;gap:0;overflow-y:auto;overscroll-behavior:contain;padding:20px;border-left:1px solid var(--ly-border)}

/* Center column: hero + tiles are fixed height, the channel fills the rest
   and scrolls, the composer sits below it and never moves. */
/* The h1/p sizes and the 14px gaps were inline styles, so no media query
   could adjust them and the hero could not respond to a short viewport.
   They are classes here so the max-height block below can reach them. */
.tw-hero{background:linear-gradient(120deg,rgba(80,40,224,.55),rgba(155,92,255,.28) 55%,rgba(2,9,26,0) 100%),var(--ly-surface);border:1px solid var(--ly-primary-line);border-radius:var(--ly-r-xl);padding:18px 22px;margin-bottom:14px}
.tw-hero-h1{font-size:22px;margin:0 0 5px;color:#fff;letter-spacing:-.02em;line-height:1.2}
.tw-hero-p{font-size:13px;color:rgba(255,255,255,.78);margin:0}
.tw-pad{padding:18px 22px 0}
.tw-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.tw-tile{padding:13px;border:1px solid var(--ly-border);border-radius:var(--ly-r-md);background:var(--ly-glass);min-width:0}
.tw-tile b{display:block;font-size:20px;font-weight:800;letter-spacing:-.03em;line-height:1.2}
.tw-tile span{display:block;font-size:11px;color:var(--ly-text-4);overflow-wrap:anywhere;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.tw-chanhead{display:flex;align-items:center;gap:10px;padding:13px 22px;border-bottom:1px solid var(--ly-border);flex:0 0 auto}
.tw-chanhead h2{font-size:14px;font-weight:700;margin:0;letter-spacing:-.01em}

/* The message list is the scroll region for the channel. */
.tw-feed{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:6px 22px 4px}
.tw-post{display:flex;gap:11px;padding:13px 0;border-bottom:1px solid var(--ly-border)}
.tw-post:last-child{border-bottom:0}
.tw-post .who{font-size:12.5px;font-weight:600;display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}
.tw-post .when{font-size:11px;color:var(--ly-text-4);font-weight:400}
.tw-post .body{font-size:13px;color:var(--ly-text-2);margin-top:3px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
.tw-post .meta{font-size:11.5px;color:var(--ly-text-4);margin-top:3px;font-style:italic}
.tw-post .attach{display:inline-flex;align-items:center;gap:8px;margin-top:7px;padding:9px 12px;border:1px solid var(--ly-border);border-radius:var(--ly-r-md);background:var(--ly-glass);font-size:12px;color:var(--ly-text-3)}

/* Composer: fixed to the bottom of the center column. */
.tw-compose{flex:0 0 auto;border-top:1px solid var(--ly-border);padding:12px 22px 16px;background:var(--ly-surface)}
.tw-composebox{display:flex;align-items:flex-end;gap:10px;border:1px solid var(--ly-border-2);border-radius:var(--ly-r-md);background:var(--ly-glass);padding:8px 10px}
.tw-composebox:focus-within{border-color:var(--ly-primary);box-shadow:0 0 0 3px var(--ly-primary-soft)}
.tw-composebox textarea{flex:1 1 auto;min-width:0;background:transparent;border:0;outline:none;resize:none;color:var(--ly-text);font-family:inherit;font-size:13px;line-height:1.55;max-height:140px}
.tw-composebox textarea::placeholder{color:var(--ly-text-4)}
.tw-icobtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;flex:0 0 auto;border:0;border-radius:var(--ly-r-sm);background:transparent;color:var(--ly-text-4);cursor:not-allowed}
.tw-send{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;flex:0 0 auto;border:0;border-radius:var(--ly-r-sm);background:var(--ly-grad);color:#fff;opacity:.5;cursor:not-allowed}

.tw-chan{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:var(--ly-r-md);font-size:12.5px;color:var(--ly-text-2);min-width:0}
.tw-chan:hover{background:var(--ly-glass);color:var(--ly-text)}
.tw-chan.is-active{background:linear-gradient(90deg,rgba(108,58,248,.22),rgba(108,58,248,.05));color:var(--ly-text);box-shadow:inset 2px 0 0 var(--ly-primary)}
.tw-chan .n{margin-left:auto;font-size:11px;color:var(--ly-text-4);flex:0 0 auto}
.tw-member{display:flex;align-items:center;gap:9px;padding:7px 0;font-size:12.5px;min-width:0}
.tw-member .r{font-size:11px;color:var(--ly-text-4)}

@media (max-width:1240px){
  .tw-shell{grid-template-columns:230px minmax(0,1fr)}
  .tw-top{grid-template-columns:230px minmax(0,1fr) auto}
  .tw-rail{display:none}
}
/* Below ~1100px the centre column is ~280px once the 230px sidebar and 320px
   rail are subtracted, so four stat tiles no longer fit at a readable size.
   Two columns keeps the labels legible. */
@media (max-width:1100px){
  .tw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:820px){
  /* Single column. The sidebar and rail are removed rather than restacked,
     because two grid items sharing row 2 / column 1 would overlap. Channel
     switching on small screens needs a drawer, which is not built yet - the
     nav links are placeholders, so hiding them loses no function. */
  .tw-shell{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr)}
  .tw-nav,.tw-rail{display:none}
  .tw-top{grid-template-columns:minmax(0,1fr) auto;padding:0 14px;height:56px}
  .tw-brand{display:none}
  .tw-search{grid-column:1;padding-right:12px}
  .tw-user{grid-column:2}
  .tw-user > div{display:none}
  .tw-center{grid-column:1;grid-row:2}
  .tw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
  /* Wrap rather than ellipsise: a tile is self-contained, so a second line
     costs nothing, while "Messages in #gene…" is not readable. */
  .tw-tile span{font-size:10.5px;white-space:normal;overflow:visible;text-overflow:clip}
  .tw-pad{padding:14px 14px 0}
  .tw-chanhead{padding:11px 14px}
  .tw-feed{padding:4px 14px}
  .tw-compose{padding:10px 14px 14px}
  .tw-hero{padding:16px}
  .tw-hero h1{font-size:19px}
}
@media (max-width:420px){
  .tw-tiles{grid-template-columns:minmax(0,1fr)}
}

/* ── SHORT VIEWPORTS ────────────────────────────────────────────────────
   The centre column is a fixed-height stack: hero and tiles are fixed, the
   channel list takes the remainder, and the composer is pinned below it. At
   1366x768 the fixed parts consumed 439px of the 704px column - hero 205,
   channel head 53, tabs 39, composer 142 - leaving the message list only
   265px, so the newest post was clipped against the tab row and the column
   felt cramped. These rules compact the fixed chrome so the message list
   keeps the majority of the column, as the approved design shows.
   Scoped to min-width:821px so it cannot fight the small-screen rules above,
   which own the side padding at those widths. */
@media (max-height:860px) and (min-width:821px){
  .tw-pad{padding-top:10px}
  .tw-hero{padding:13px 18px;margin-bottom:10px}
  .tw-hero-h1{font-size:19px;margin-bottom:3px}
  .tw-hero-p{font-size:12.5px}
  .tw-tiles{gap:10px;margin-bottom:10px}
  .tw-tile{padding:10px}
  .tw-tile b{font-size:17px}
  .tw-chanhead{padding-top:9px;padding-bottom:9px}
  .lyra-tw-tab{padding-top:9px;padding-bottom:9px}
  .lyra-tw-chips{margin-bottom:4px}
  .tw-feed{padding-top:4px;padding-bottom:2px}
  .tw-compose{padding-top:8px;padding-bottom:10px}
}
</style>
</head>
<body class="ly">
<div class="tw-shell">

<!-- ══ TOP BAR (full width, above all three columns) ══ -->
<header class="tw-top">
    <a class="tw-brand ly-logo" href="/">
        <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
        <span style="font-size:16px">Lyralink</span>
    </a>

    <div class="tw-search">
        <div class="ly-input-icon">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input class="ly-input" placeholder="Search anything&hellip; (messages, files, people, projects)" disabled
                   title="Global search is not wired to an endpoint yet" style="padding-top:9px;padding-bottom:9px">
        </div>
    </div>

    <div class="tw-user">
        <span class="ly-avatar ly-avatar-sm"><?php echo htmlspecialchars($viewerInitials, ENT_QUOTES, 'UTF-8'); ?></span>
        <div style="min-width:0">
            <div style="font-size:12.5px;font-weight:600" class="ly-truncate"><?php echo htmlspecialchars($viewerLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="font-size:11px;color:var(--ly-text-4)"><?php echo htmlspecialchars($viewerStatus, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>
</header>

<!-- ══ SIDEBAR ══ -->
<aside class="ly-sidebar tw-nav">
    <div class="tw-navscroll">
        <div class="ly-sidebar-section" style="padding-top:0">Interface</div>
        <?php echo lyra_ui_nav_render('/pages/teams/'); ?>

        <div class="ly-sidebar-section">Workspace</div>
        <?php foreach ([
            ['Messages','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z','/chat'],
            ['People',  'M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8',''],
            ['Files',   'M6 3h8l4 4v14H6z',''],
            ['Search',  'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3',''],
        ] as $n):
            if ($n[2] === '') { echo lyra_ui_pending($n[0], $n[1]); continue; } ?>
        <a class="ly-navitem" href="<?php echo htmlspecialchars($n[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-sidebar-section">Channels</div>
        <?php if ($channels): foreach ($channels as $i => $ch):
            $nm = pk($ch, ['name'], 'channel');
            $cc = $chanCounts[(int) ($ch['id'] ?? 0)] ?? 0;
            $isVoice = pk($ch, ['type']) === 'voice'; ?>
        <a class="tw-chan<?php echo ((int) ($ch['id'] ?? 0) === (int) ($active['id'] ?? -1)) ? ' is-active' : ''; ?>"
           href="?channel=<?php echo (int) ($ch['id'] ?? 0); ?>"
           title="#<?php echo htmlspecialchars($nm); ?>">
            <span style="color:var(--ly-text-4);flex:0 0 auto"><?php echo $isVoice ? '&#128266;' : '#'; ?></span>
            <span class="ly-truncate"><?php echo htmlspecialchars($nm); ?></span>
            <span class="n"><?php echo $cc > 0 ? (int) $cc : ''; ?></span>
        </a>
        <?php endforeach; else: ?>
        <div style="font-size:11.5px;color:var(--ly-text-4);padding:10px">No channels yet.</div>
        <?php endif; ?>
    </div>

    <div class="ly-promo" style="flex:0 0 auto;margin-top:var(--ly-s3)">
        <div style="font-weight:700;font-size:13px;margin-bottom:5px">Smarter Collaboration</div>
        <p style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:10px">Bring your team together with AI assistance and seamless communication.</p>
        <a class="ly-btn ly-btn-primary ly-btn-sm ly-btn-block" href="/pages/social.php">Open workspace</a>
    </div>
</aside>

<!-- ══ CENTER ══ -->
<main class="tw-center">

    <div class="tw-pad">
        <div class="tw-hero">
            <h1 class="tw-hero-h1"><?php echo htmlspecialchars($greetingWord, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($viewerLabel, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="tw-hero-p">Here&rsquo;s what&rsquo;s happening across your workspace today.</p>
        </div>

        <div class="tw-tiles">
            <div class="tw-tile"><b data-ly-count="<?php echo $nCh; ?>"><?php echo $nCh; ?></b><span>Channels</span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMsg; ?>"><?php echo $nMsg; ?></b><span title="Messages in #<?php echo htmlspecialchars($activeName !== '' ? $activeName : '—'); ?>">Messages in #<?php echo htmlspecialchars($activeName !== '' ? $activeName : '—'); ?></span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMem; ?>"><?php echo $nMem; ?></b><span>Members</span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nOnline; ?>"><?php echo $nOnline; ?></b><span>Online now</span></div>
        </div>
    </div>

    <div class="tw-chanhead">
        <span style="color:var(--ly-text-4);flex:0 0 auto">#</span>
        <h2 class="ly-truncate"><?php echo $active ? htmlspecialchars($activeName) : 'No channel selected'; ?></h2>
        <?php if ($active && pk($active, ['topic']) !== ''): ?>
        <span class="ly-badge ly-truncate"><?php echo htmlspecialchars(pk($active, ['topic'])); ?></span>
        <?php endif; ?>
        <span class="ly-spacer"></span>
        <span class="ly-badge"><?php echo $nMsg; ?></span>
    </div>

    <!-- Scroll region: the full channel, newest at the bottom -->
    <?php echo lyra_tw_feed_head(); ?>
    <div class="tw-feed" id="tw-feed" tabindex="0" role="log" aria-label="Channel messages">
        <?php if ($messages): foreach ($messages as $msg):
            $uid    = (int) pk($msg, ['sender_user_id','user_id','author_id'], '0');
            $author = tw_author($uid, $users);
            $body   = pk($msg, ['content','message','body']);
            $at     = pk($msg, ['created_at']);
            $type   = pk($msg, ['message_type'], 'text'); ?>
        <div class="tw-post">
            <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad);flex:0 0 auto"><?php echo htmlspecialchars(inits($author)); ?></span>
            <div style="min-width:0;flex:1 1 auto">
                <div class="who">
                    <span class="ly-truncate" title="<?php echo htmlspecialchars($author); ?>"><?php echo htmlspecialchars($author); ?></span>
                    <?php if ($at): ?><span class="when" title="<?php echo htmlspecialchars($at); ?>"><?php echo htmlspecialchars(tw_when($at)); ?></span><?php endif; ?>
                </div>
                <?php if ($type === 'attachment'): ?>
                    <div class="attach">
                        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M6 3h8l4 4v14H6z"/></svg>
                        Attachment<?php echo $body !== '' ? ' &mdash; ' . htmlspecialchars($body) : ' (no filename recorded)'; ?>
                    </div>
                <?php elseif ($type === 'system'): ?>
                    <div class="meta"><?php echo $body !== '' ? htmlspecialchars($body) : 'System event (no text recorded).'; ?></div>
                <?php elseif ($body === ''): ?>
                    <div class="body" style="color:var(--ly-text-4)"><em>Empty message.</em></div>
                <?php else: ?>
                    <div class="body"><?php echo htmlspecialchars($body); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div style="font-size:12.5px;color:var(--ly-text-4);padding:16px 0">
            No messages in this channel yet.<?php echo $dbError !== null ? ' Database unavailable.' : ''; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="tw-compose">
        <?php echo lyra_tw_compose_chips(); ?>
        <div class="tw-composebox">
            <button class="tw-icobtn" type="button" disabled title="Not wired up yet" aria-label="Add attachment">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <textarea rows="1" placeholder="Type a message&hellip;" disabled
                      title="Posting is not wired to the API yet" aria-label="Message"></textarea>
            <span class="tw-send" title="Posting is not wired to the API yet" aria-hidden="true">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
        </div>
        <div style="font-size:11px;color:var(--ly-text-4);margin-top:7px">
            Composing is not wired to the API yet, so this field is disabled rather than appearing to send.
        </div>
    </div>
</main>

<!-- ══ RAIL ══ -->
<aside class="ly-rail tw-rail">
    <?php echo lyra_tw_team_panel(); ?>
    <?php echo lyra_tw_channels_panel(); ?>
    <?php echo lyra_tw_status_panel(); ?>
    <?php echo lyra_tw_activity_panel(); ?>

    <div class="ly-promo" style="flex:0 0 auto">
        <div style="font-weight:700;font-size:13px;margin-bottom:6px">Powered by Lyralink</div>
        <div style="font-size:11.5px;color:var(--ly-text-3);line-height:1.65">
            This workspace reads the live social_* tables. Member presence is derived from
            <span class="ly-mono" style="font-size:10.5px">last_seen_at</span>, and service health
            from the same source as the public status page. Upcoming meetings, tasks and files
            have no table in this schema yet, so those panels are not shown.
        </div>
    </div>
</aside>
</div>

<script>
/* Keep the newest message in view. The channel is a scroll region, so the
   browser will not do this on its own. */
(function () {
    var feed = document.getElementById('tw-feed');
    if (feed) { feed.scrollTop = feed.scrollHeight; }
})();
</script>
</body>
</html>
