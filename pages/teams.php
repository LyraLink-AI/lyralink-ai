<?php
session_start();
require_once __DIR__ . '/../api/security.php';
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
/* Collaboration workspace. Implements the approved Teams-style design.
 * Reads real rows from the existing social_* tables. Uses SELECT * and defensive
 * key access so a schema change degrades to an empty state, not a fatal error.
 * Posting/presence/calls are NOT implemented here — this is the interface shell. */

$channels = $messages = $members = [];
$active = null; $dbError = null; $users = [];

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
        $members  = $run('SELECT * FROM social_server_members LIMIT 20');
        foreach ($run('SELECT id, username, plan FROM users') as $r) {
            $users[(int) $r['id']] = $r;
        }

        // Messages hang off conversations, which hang off channels:
        // social_channels <- social_channel_conversations -> social_messages
        if ($channels) {
            $active = $channels[0];
            $cid = (int) ($active['id'] ?? 0);
            $messages = $run(
                'SELECT m.* FROM social_messages m '
                . 'JOIN social_channel_conversations cc ON cc.conversation_id = m.conversation_id '
                . 'WHERE cc.channel_id = ' . $cid . ' ORDER BY m.id ASC LIMIT 40'
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
$nCh = count($channels); $nMsg = count($messages); $nMem = count($members);
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
<script src="/assets/js/lyra-ui.js" defer></script>
<style>
.tw-shell{display:grid;grid-template-columns:250px minmax(0,1fr) 320px;min-height:100vh}
.tw-center{min-width:0;display:flex;flex-direction:column}
.tw-hero{background:linear-gradient(120deg,rgba(80,40,224,.55),rgba(155,92,255,.28) 55%,rgba(2,9,26,0) 100%),var(--ly-surface);border:1px solid var(--ly-primary-line);border-radius:var(--ly-r-xl);padding:22px 24px}
.tw-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.tw-tile{padding:14px;border:1px solid var(--ly-border);border-radius:var(--ly-r-md);background:var(--ly-glass)}
.tw-tile b{display:block;font-size:20px;font-weight:800;letter-spacing:-.03em}
.tw-tile span{font-size:11px;color:var(--ly-text-4)}
.tw-post{display:flex;gap:11px;padding:13px 0;border-bottom:1px solid var(--ly-border)}
.tw-post:last-child{border-bottom:0}
.tw-post .who{font-size:12.5px;font-weight:600}
.tw-post .when{font-size:11px;color:var(--ly-text-4);margin-left:7px;font-weight:400}
.tw-post .body{font-size:13px;color:var(--ly-text-2);margin-top:3px;line-height:1.6;white-space:pre-wrap}
.tw-chan{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:var(--ly-r-md);font-size:12.5px;color:var(--ly-text-2)}
.tw-chan:hover{background:var(--ly-glass);color:var(--ly-text)}
.tw-chan.is-active{background:linear-gradient(90deg,rgba(108,58,248,.22),rgba(108,58,248,.05));color:var(--ly-text);box-shadow:inset 2px 0 0 var(--ly-primary)}
.tw-chan .n{margin-left:auto;font-size:11px;color:var(--ly-text-4)}
.tw-member{display:flex;align-items:center;gap:9px;padding:7px 0;font-size:12.5px}
.tw-member .r{font-size:11px;color:var(--ly-text-4)}
@media (max-width:1240px){.tw-shell{grid-template-columns:230px minmax(0,1fr)}.tw-rail{display:none}}
@media (max-width:820px){.tw-shell{grid-template-columns:minmax(0,1fr)}.tw-nav{display:none}.tw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body class="ly">
<div class="tw-shell">

<aside class="ly-sidebar tw-nav">
    <a class="ly-sidebar-brand ly-logo" href="/">
        <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
        <span style="font-size:16px">Lyralink</span>
    </a>
    <div class="ly-sidebar-section">Main</div>
    <?php foreach ([
        ['Home','M3 10.5 12 3l9 7.5V21H3z',1],
        ['Messages','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z',0],
        ['Files','M6 3h8l4 4v14H6z',0],
        ['People','M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8',0],
        ['Search','M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM21 21l-4.3-4.3',0],
    ] as $n): ?>
    <a class="ly-navitem<?php echo $n[2] ? ' is-active' : ''; ?>" href="#">
        <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
        <?php echo $n[0]; ?>
    </a>
    <?php endforeach; ?>

    <div class="ly-sidebar-section">Channels</div>
    <?php if ($channels): foreach ($channels as $i => $ch):
        $nm = pk($ch, ['name'], 'channel'); ?>
    <a class="tw-chan<?php echo $i === 0 ? ' is-active' : ''; ?>" href="#">
        <span style="color:var(--ly-text-4)">#</span>
        <span class="ly-truncate"><?php echo htmlspecialchars($nm); ?></span>
        <span class="n"><?php echo $i === 0 ? $nMsg : ''; ?></span>
    </a>
    <?php endforeach; else: ?>
    <div style="font-size:11.5px;color:var(--ly-text-4);padding:10px">No channels yet.</div>
    <?php endif; ?>

    <div class="ly-promo" style="margin-top:auto">
        <div style="font-weight:700;font-size:13px;margin-bottom:5px">Smarter Collaboration</div>
        <p style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:10px">Bring your team together with AI assistance and seamless communication.</p>
        <a class="ly-btn ly-btn-primary ly-btn-sm ly-btn-block" href="/pages/social.php">Open workspace</a>
    </div>
</aside>

<main class="tw-center">
    <div class="ly-topnav" style="position:sticky;top:0;z-index:30">
        <div class="ly-input-icon" style="flex:1;max-width:520px">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input class="ly-input" placeholder="Search anything&hellip; (messages, files, people, projects)" style="padding-top:9px;padding-bottom:9px">
        </div>
        <div class="ly-spacer"></div>
        <span class="ly-avatar ly-avatar-sm">AW</span>
        <div>
            <div style="font-size:12.5px;font-weight:600">Alex West</div>
            <div style="font-size:11px;color:var(--ly-text-4)">Online</div>
        </div>
    </div>

    <div style="padding:22px;flex:1">
        <div class="tw-hero ly-mb-6">
            <h1 style="font-size:24px;margin-bottom:6px;color:#fff">Good morning, Alex</h1>
            <p style="font-size:13.5px;color:rgba(255,255,255,.78);margin:0">Here&rsquo;s what&rsquo;s happening across your workspace today.</p>
        </div>

        <div class="tw-tiles ly-mb-6">
            <div class="tw-tile"><b data-ly-count="<?php echo $nCh; ?>"><?php echo $nCh; ?></b><span>Channels</span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMsg; ?>"><?php echo $nMsg; ?></b><span>Messages in #<?php echo htmlspecialchars($active ? pk($active,['name'],'channel') : '—'); ?></span></div>
            <div class="tw-tile"><b data-ly-count="<?php echo $nMem; ?>"><?php echo $nMem; ?></b><span>Members</span></div>
            <div class="tw-tile"><b><?php echo $dbError === null ? 'OK' : '—'; ?></b><span>Data source</span></div>
        </div>

        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head">
                <h2 class="ly-panel-title">
                    <?php if ($active): ?># <?php echo htmlspecialchars(pk($active,['name'],'channel')); ?>
                    <?php else: ?>No channel selected<?php endif; ?>
                </h2>
                <?php if ($active && pk($active,['topic']) !== ''): ?>
                <span class="ly-badge"><?php echo htmlspecialchars(pk($active,['topic'])); ?></span>
                <?php endif; ?>
            </div>
            <div class="ly-panel-body">
                <?php if ($messages): foreach ($messages as $msg):
                    $uid = (int)($msg['user_id'] ?? $msg['author_id'] ?? 0);
                    $author = pk($msg, ['username','author_name']);
                    if ($author === '' && isset($users[$uid])) $author = (string)$users[$uid]['username'];
                    if ($author === '') $author = 'user ' . $uid;
                    $body = pk($msg, ['content','message','body']);
                    $at = pk($msg, ['created_at']); ?>
                <div class="tw-post">
                    <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad)"><?php echo htmlspecialchars(inits($author)); ?></span>
                    <div style="min-width:0">
                        <div class="who"><?php echo htmlspecialchars($author); ?><?php if ($at): ?><span class="when"><?php echo htmlspecialchars($at); ?></span><?php endif; ?></div>
                        <div class="body"><?php echo htmlspecialchars($body); ?></div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div style="font-size:12.5px;color:var(--ly-text-4);padding:16px 0">
                    No messages in this channel yet.<?php echo $dbError !== null ? ' Database unavailable.' : ''; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ly-panel">
            <div class="ly-panel-head"><h2 class="ly-panel-title">Compose</h2></div>
            <div class="ly-panel-body">
                <textarea class="ly-textarea" placeholder="Type a message&hellip;" disabled></textarea>
                <div class="ly-row ly-mt-5">
                    <span class="ly-muted" style="font-size:11.5px">Posting is not wired to the API yet &mdash; this field is disabled rather than pretending to send.</span>
                    <span class="ly-spacer"></span>
                    <button class="ly-btn ly-btn-primary ly-btn-sm" type="button" disabled>Send</button>
                </div>
            </div>
        </div>
    </div>
</main>

<aside class="ly-rail tw-rail" style="padding:20px;border-left:1px solid var(--ly-border)">
    <div class="ly-panel ly-mb-6">
        <div class="ly-panel-head"><h2 class="ly-panel-title">Members</h2><span class="ly-badge"><?php echo $nMem; ?></span></div>
        <div class="ly-panel-body">
            <?php if ($members): foreach ($members as $mm):
                $muid = (int)($mm['user_id'] ?? 0);
                $mname = pk($mm, ['username','display_name','name']);
                if ($mname === '' && isset($users[$muid])) $mname = (string)$users[$muid]['username'];
                if ($mname === '') $mname = 'user ' . $muid;
                $role = pk($mm, ['role','member_role'], 'Member'); ?>
            <div class="tw-member">
                <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad)"><?php echo htmlspecialchars(inits($mname)); ?></span>
                <div style="min-width:0">
                    <div class="ly-truncate"><?php echo htmlspecialchars($mname); ?></div>
                    <div class="r"><?php echo htmlspecialchars(ucfirst($role)); ?></div>
                </div>
                <span class="ly-spacer"></span>
                <span class="ly-dot ly-dot-online"></span>
            </div>
            <?php endforeach; else: ?>
            <div style="font-size:12.5px;color:var(--ly-text-4)">No members recorded.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ly-panel ly-mb-6">
        <div class="ly-panel-head"><h2 class="ly-panel-title">Channels</h2><span class="ly-badge"><?php echo $nCh; ?></span></div>
        <div class="ly-panel-body">
            <?php if ($channels): foreach ($channels as $ch): ?>
            <div class="ly-row-between" style="font-size:12.5px;padding:6px 0">
                <span class="ly-muted"># <?php echo htmlspecialchars(pk($ch,['name'],'channel')); ?></span>
            </div>
            <?php endforeach; else: ?>
            <div style="font-size:12.5px;color:var(--ly-text-4)">No channels.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ly-promo">
        <div style="font-weight:700;font-size:13px;margin-bottom:6px">Powered by Lyralink</div>
        <div style="font-size:11.5px;color:var(--ly-text-3);line-height:1.65">
            This workspace reads the live social_* tables. Panels with no data show an
            empty state rather than placeholder content.
        </div>
    </div>
</aside>
</div>
</body>
</html>
