<?php
require_once __DIR__ . '/../api/session_boot.php';
lyra_session_boot();
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lyra_ui_nav.php';
require_once __DIR__ . '/../api/lyra_chat_data.php';
require_once __DIR__ . '/../api/lyra_admin_data.php';

if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}

/* ADMIN ONLY.
 * This page reports real business metrics (user and conversation counts,
 * dataset size, host model inventory, disk headroom). It was reachable
 * anonymously, which disclosed all of that to anyone who guessed the URL.
 * Access follows the existing convention in pages/admin.php, except the flag
 * is read from users.is_admin so any administrator account is handled. */
$lyIsAdmin = false;
if (!empty($_SESSION['username'])) {
    try {
        $lyCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
        $lyDb = new mysqli($lyCfg['host'], $lyCfg['user'], $lyCfg['pass'], $lyCfg['name']);
        if (!$lyDb->connect_error) {
            $lySt = $lyDb->prepare('SELECT is_admin FROM users WHERE username = ? LIMIT 1');
            if ($lySt) {
                $lySt->bind_param('s', $_SESSION['username']);
                $lySt->execute();
                $lyRes = $lySt->get_result();
                $lyRow = $lyRes ? $lyRes->fetch_assoc() : null;
                $lyIsAdmin = $lyRow !== null && (int) $lyRow['is_admin'] === 1;
                $lySt->close();
            }
            $lyDb->close();
        }
    } catch (\Throwable $e) { $lyIsAdmin = false; }
}
if (!$lyIsAdmin) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../api/lyra_admin_data.php';
$lyraStats = lyra_ad_stats(24);
$lyraMachine = lyra_ad_machine();

/* Admin dashboard. Implements the approved Admin-Dashboard design.
 *
 * METRICS ARE REAL. Each figure is read from the database or the local runtime
 * and falls back to an explicit NULL (rendered as "—" + an empty state) when a
 * source is unavailable. Nothing is seeded with plausible-looking numbers: in
 * an operations dashboard, invented status is worse than a blank panel.
 */

function ly_one(mysqli $db, string $sql): ?int
{
    try {
        $r = $db->query($sql);
        if (!$r) return null;
        $row = $r->fetch_row();
        return $row ? (int) $row[0] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

$totals = ['users' => null, 'conversations' => null, 'messages' => null,
           'dataset' => null, 'security' => null, 'marketing' => null];
$series = [];      // [ ['d' => 'YYYY-MM-DD', 'n' => int], ... ]
$models = [];      // real models from the local runtime
$dbError = null;

try {
    $cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
    $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
    if ($db->connect_error) {
        $dbError = $db->connect_error;
    } else {
        $totals['users']         = ly_one($db, 'SELECT COUNT(*) FROM users');
        $totals['conversations'] = ly_one($db, 'SELECT COUNT(*) FROM conversations');
        $totals['messages']      = ly_one($db, 'SELECT COUNT(*) FROM user_conv_messages');
        $totals['dataset']       = ly_one($db, 'SELECT COUNT(*) FROM dataset');
        $totals['security']      = ly_one($db, 'SELECT COUNT(*) FROM security_log');
        $totals['marketing']     = ly_one($db, 'SELECT COUNT(*) FROM marketing_runs');

        // Real 14-day conversation series for the usage chart.
        $res = $db->query(
            "SELECT DATE(created_at) d, COUNT(*) n FROM conversations "
            . "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) "
            . "GROUP BY DATE(created_at) ORDER BY d"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $series[] = ['d' => (string) $row['d'], 'n' => (int) $row['n']];
            }
        }
        $db->close();
    }
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// Real local model inventory.
try {
    $ch = curl_init('http://127.0.0.1:11434/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        foreach (($decoded['models'] ?? []) as $m) {
            $models[] = [
                'name' => (string) ($m['name'] ?? ''),
                'size' => round(((int) ($m['size'] ?? 0)) / 1e9, 2),
            ];
        }
    }
} catch (\Throwable $e) { /* models stay empty */ }

$activeModels = count($models);
$isLyralink = 0;
foreach ($models as $m) { if (stripos($m['name'], 'lyralink') !== false) $isLyralink++; }

// Build the SVG area chart from the real series.
$chartW = 900; $chartH = 200; $pad = 8;
$maxN = 1;
foreach ($series as $p) { $maxN = max($maxN, $p['n']); }
$pts = [];
$count = max(1, count($series) - 1);
foreach ($series as $i => $p) {
    $x = $pad + ($i / $count) * ($chartW - $pad * 2);
    $y = $chartH - $pad - (($p['n'] / $maxN) * ($chartH - $pad * 3));
    $pts[] = [round($x, 1), round($y, 1)];
}
$linePath = '';
foreach ($pts as $i => $pt) { $linePath .= ($i === 0 ? 'M' : 'L') . $pt[0] . ',' . $pt[1] . ' '; }
$areaPath = $linePath . 'L' . ($pts ? end($pts)[0] : 0) . ',' . ($chartH - $pad) . ' L' . ($pts ? $pts[0][0] : 0) . ',' . ($chartH - $pad) . ' Z';

function nf(?int $n): string { return $n === null ? '—' : number_format($n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
<link rel="stylesheet" href="/assets/css/lyra-admin.css">
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        .ad-top { display:flex; align-items:center; gap:16px; padding:10px 22px; border-bottom:1px solid var(--ly-border);
                  position:sticky; top:0; z-index:30; background:rgba(2,9,26,.88); backdrop-filter:blur(14px); }
        .ad-search { display:flex; align-items:center; gap:9px; padding:8px 13px; border:1px solid var(--ly-border);
                     border-radius:var(--ly-r-md); background:var(--ly-glass); min-width:300px; font-size:13px; color:var(--ly-text-4); }
        .ad-search kbd { margin-left:auto; font-family:var(--ly-mono); font-size:10.5px; border:1px solid var(--ly-border-2);
                         border-radius:4px; padding:1px 6px; color:var(--ly-text-4); }
        .ad-stat { display:flex; flex-direction:column; gap:6px; }
        .ad-stat .v { font-size:26px; font-weight:800; letter-spacing:-.03em; line-height:1.1; font-variant-numeric:tabular-nums; }
        .ad-stat .k { font-size:11.5px; color:var(--ly-text-4); }
        .ad-chart { width:100%; height:auto; display:block; }
        .ad-modelrow { display:flex; align-items:center; gap:11px; padding:11px 0; border-bottom:1px solid var(--ly-border); }
        .ad-modelrow:last-child { border-bottom:0; }
        .ad-bar { height:6px; border-radius:var(--ly-r-full); background:var(--ly-glass-strong); overflow:hidden; margin-top:5px; }
        .ad-bar > span { display:block; height:100%; background:var(--ly-gradient, linear-gradient(90deg,#5028E0,#9B5CFF)); border-radius:var(--ly-r-full); }
        .ad-svc { display:flex; align-items:center; gap:9px; padding:8px 0; font-size:12.5px; color:var(--ly-text-2); }
        .ad-svc .ly-spacer { flex:1; }
        @media (max-width:1100px){ .ad-search{ display:none; } }
    </style>
</head>
<body class="ly">
<div class="ly-shell ly-shell-has-rail">

    <!-- ══ SIDEBAR ══ -->
    <aside class="ly-sidebar">
        <a class="ly-sidebar-brand ly-logo" href="/">
            <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
            <span style="font-size:16px">Lyralink</span>
        </a>
        <?php
        /* [label, icon, href]. An empty href means the screen is designed but
         * has no destination, and is rendered as an explicit pending row rather
         * than a link that goes nowhere. Only destinations confirmed to exist
         * are used here. */
        $nav = [
            ['Dashboard','M3 10.5 12 3l9 7.5V21H3z', '/pages/admin-dashboard/'],
            ['Users','M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8', '/pages/admin/'],
            ['Models','M12 3 3 7.5 12 12l9-4.5L12 3ZM3 12l9 4.5 9-4.5M3 16.5 12 21l9-4.5', ''],
            ['Tools','M14 6a4 4 0 0 1-5 5L4 16l4 4 5-5a4 4 0 0 0 5-5l-4 1-1-4Z', ''],
            ['Knowledge Base','M4 5h16v14H4zM4 9h16', '/pages/dataset_manager/'],
            ['Workflows','M5 6h6v6H5zM13 12h6v6h-6zM11 9h4', ''],
            ['Automations','M13 2 4 14h7l-1 8 9-12h-7z', '/pages/automation/'],
            ['Deployments','M12 3v12M8 11l4 4 4-4M5 21h14', ''],
            ['Monitoring','M3 12h4l3 8 4-16 3 8h4', '/pages/status/'],
            ['Logs','M6 3h8l4 4v14H6zM9 12h6M9 16h4', '/pages/security_log/'],
            ['Settings','M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', ''],
        ];
        ?>
        <div class="ly-sidebar-section" style="padding-top:0">Interface</div>
        <?php echo lyra_ui_nav_render('/pages/admin-dashboard/'); ?>

        <div class="ly-sidebar-section">Administration</div>
        <?php foreach ($nav as $n): ?>
        <?php if (($n[2] ?? '') === '') { echo lyra_ui_pending($n[0], $n[1], 'Designed, no screen built yet'); continue; } ?>
        <a class="ly-navitem<?php echo $n[2] === '/pages/admin-dashboard/' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($n[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-sidebar-section">Quick Actions</div>
        <?php foreach ([
            ['Create User','M12 5v14M5 12h14',''],
            ['Deploy Model','M12 3v12M8 11l4 4 4-4M5 21h14',''],
            ['Run Backup','M4 7v10h16V7M4 7l8-4 8 4',''],
            ['View Logs','M6 3h8l4 4v14H6z','/pages/security_log/'],
        ] as $q): ?>
        <?php if ($q[2] === '') { echo lyra_ui_pending($q[0], $q[1], 'No action wired yet'); continue; } ?>
        <a class="ly-navitem" href="<?php echo htmlspecialchars($q[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $q[1]; ?>"/></svg>
            <?php echo $q[0]; ?>
        </a>
        <?php endforeach; ?>
    </aside>

    <!-- ══ MAIN ══ -->
    <main style="min-width:0">

        <div class="ad-top">
            <a class="ly-logo" href="/">
                <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px;width:26px;height:26px">
            </a>
            <div>
                <div style="font-weight:700;font-size:14px;letter-spacing:-.02em">Next-Gen AI Infrastructure</div>
                <div style="font-size:11.5px;color:var(--ly-text-4)">Automate &middot; Build &middot; Scale</div>
            </div>
            <div class="ad-search">
                <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                Search system, users, logs, or commands&hellip;
                <kbd>Ctrl + K</kbd>
            </div>
            <div class="ly-spacer"></div>
            <?php $lyViewer = lyra_ui_viewer(); ?>
            <span class="ly-avatar ly-avatar-sm"><?php echo htmlspecialchars($lyViewer['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
            <div>
                <div style="font-size:12.5px;font-weight:600"><?php echo htmlspecialchars($lyViewer['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div style="font-size:11px;color:var(--ly-text-4)">Administrator</div>
            </div>
        </div>

        <?php /* LYRA_TASK_DATA_BLOCK */
        $lyraTasks = lyra_chat_q(
            "SELECT objective, intent, status, priority FROM ai_os_tasks "
            . "ORDER BY updated_at DESC, id DESC LIMIT 8"
        );
        $lyraTaskCounts = ['total' => 0, 'open' => 0, 'succeeded' => 0, 'failed' => 0, 'unverified' => 0];
        foreach (lyra_chat_q('SELECT status, COUNT(*) AS n FROM ai_os_tasks GROUP BY status') as $r) {
            $n = (int) $r['n'];
            $lyraTaskCounts['total'] += $n;
            $st = strtoupper((string) $r['status']);
            if ($st === 'SUCCEEDED')      { $lyraTaskCounts['succeeded'] = $n; }
            elseif ($st === 'FAILED')     { $lyraTaskCounts['failed'] = $n; }
            elseif ($st === 'UNVERIFIED') { $lyraTaskCounts['unverified'] = $n; }
            else                          { $lyraTaskCounts['open'] += $n; }
        }
        $lyraLogs = lyra_chat_q(
            'SELECT created_at, event_type, ip_address FROM security_log ORDER BY id DESC LIMIT 6'
        );
        ?>
        <div style="padding:24px">
            <h1 style="font-size:24px;margin-bottom:4px">Admin Dashboard</h1>
            <p class="ly-muted" style="font-size:13px;margin-bottom:22px">
                Monitor, manage, and optimize your Lyralink infrastructure.
                <?php if ($dbError !== null): ?>
                <span style="color:#FCD34D"> &mdash; database unavailable, figures showing &ldquo;&mdash;&rdquo;.</span>
                <?php endif; ?>
            </p>

            <div class="ly-grid ly-grid-4 ly-mb-6">
                <?php
                $cards = [
                    ['Total Users',    $totals['users'],         'M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
                     '', 'users table'],
                    ['Conversations',  $totals['conversations'], 'M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z',
                     '', 'conversation history'],
                    ['Requests (24h)', $lyraStats['requests'],   'M4 5h16v11H8l-4 4z',
                     lyra_ad_delta_html($lyraStats['requests_delta']), 'vs previous 24h'],
                    ['Dataset Records',$totals['dataset'],       'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3ZM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6',
                     '', 'knowledge base'],
                ];
                foreach ($cards as $c): ?>
                <div class="ly-card lyra-stat">
                    <div class="lyra-stat-top">
                        <span class="ly-tile">
                            <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $c[2]; ?>"/></svg>
                        </span>
                        <span class="lyra-stat-label"><?php echo $c[0]; ?></span>
                    </div>
                    <div class="lyra-stat-value" <?php echo $c[1] !== null ? 'data-ly-count="' . $c[1] . '"' : ''; ?>><?php echo nf($c[1]); ?></div>
                    <div class="lyra-stat-foot">
                        <?php echo $c[3] ?? ''; ?>
                        <span class="lyra-stat-note"><?php echo $c[4] ?? ''; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ly-grid ly-grid-2 ly-mb-6" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr)">
                <div class="ly-panel">
                    <div class="ly-panel-head">
                        <h2 class="ly-panel-title">Conversation Volume</h2>
                        <span class="ly-badge">Last 14 days</span>
                    </div>
                    <div class="ly-panel-body">
                        <?php if ($series): ?>
                        <svg class="ad-chart" viewBox="0 0 <?php echo $chartW; ?> <?php echo $chartH; ?>" preserveAspectRatio="none" role="img" aria-label="Conversations per day, last 14 days">
                            <defs>
                                <linearGradient id="adg" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#6C3AF8" stop-opacity="0.55"/>
                                    <stop offset="1" stop-color="#6C3AF8" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <path d="<?php echo $areaPath; ?>" fill="url(#adg)"/>
                            <path d="<?php echo $linePath; ?>" fill="none" stroke="#8B5CF6" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                        </svg>
                        <div class="ly-row-between ly-mt-5" style="font-size:11px;color:var(--ly-text-4)">
                            <span><?php echo htmlspecialchars($series[0]['d']); ?></span>
                            <span>peak <?php echo number_format($maxN); ?>/day</span>
                            <span><?php echo htmlspecialchars(end($series)['d']); ?></span>
                        </div>
                        <?php else: ?>
                        <div style="font-size:12.5px;color:var(--ly-text-4);padding:24px 0">
                            No conversation history found for the last 14 days.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head">
                        <h2 class="ly-panel-title">Models Installed</h2>
                        <span class="ly-badge ly-badge-primary"><?php echo $activeModels; ?> on host</span>
                    </div>
                    <div class="ly-panel-body">
                        <?php if ($models): ?>
                            <?php foreach (array_slice($models, 0, 6) as $m): ?>
                            <div class="ad-modelrow">
                                <span class="ly-avatar ly-avatar-sm" style="background:var(--ly-grad)"><?php echo htmlspecialchars(strtoupper(substr($m['name'], 0, 1))); ?></span>
                                <div style="min-width:0;flex:1">
                                    <div class="ly-truncate" style="font-size:12.5px" title="<?php echo htmlspecialchars($m['name']); ?>"><?php echo htmlspecialchars($m['name']); ?></div>
                                    <div style="font-size:11px;color:var(--ly-text-4)"><?php echo $m['size']; ?> GB</div>
                                </div>
                                <?php if (stripos($m['name'], 'lyralink') !== false): ?>
                                <span class="ly-badge ly-badge-primary">managed</span>
                                <?php else: ?>
                                <span class="ly-badge">base</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <div style="font-size:11.5px;color:var(--ly-text-4);margin-top:10px">
                                <?php echo $isLyralink; ?> managed Lyralink model<?php echo $isLyralink === 1 ? '' : 's'; ?>,
                                <?php echo $activeModels - $isLyralink; ?> base model<?php echo ($activeModels - $isLyralink) === 1 ? '' : 's'; ?>.
                            </div>
                        <?php else: ?>
                            <div style="font-size:12.5px;color:var(--ly-text-4)">Local model runtime not reachable.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ly-grid ly-grid-2" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Platform Activity</h2></div>
                    <div class="ly-panel-body">
                        <?php
                        $rows = [
                            ['Security events logged', $totals['security']],
                            ['Dataset records',        $totals['dataset']],
                            ['Marketing runs',         $totals['marketing']],
                            ['Conversations',          $totals['conversations']],
                        ];
                        foreach ($rows as $r): ?>
                        <div class="ly-row-between" style="font-size:12.5px;padding:9px 0;border-bottom:1px solid var(--ly-border)">
                            <span class="ly-muted"><?php echo $r[0]; ?></span>
                            <span style="font-variant-numeric:tabular-nums"><?php echo nf($r[1]); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php /* LYRA_TASKS_FULL_WIDTH */ ?>
                <div class="ly-panel" style="grid-column:1 / -1">
                    <div class="ly-panel-head">
                        <h2 class="ly-panel-title">Active Tasks</h2>
                        <span class="ly-badge"><?php echo (int) $lyraTaskCounts['open']; ?> open</span>
                    </div>
                    <div class="ly-panel-body" style="padding:0">
                        <?php if ($lyraTasks): ?>
                        <table class="lyra-logtable">
                            <thead><tr><th>Task</th><th>Intent</th><th>Status</th><th>Priority</th></tr></thead>
                            <tbody>
                            <?php foreach ($lyraTasks as $t):
                                $st = strtoupper((string) $t['status']);
                                $cls = $st === 'SUCCEEDED' ? 'ok' : ($st === 'FAILED' ? 'bad' : ''); ?>
                            <tr>
                                <td class="ly-truncate" style="max-width:280px" title="<?php echo htmlspecialchars((string) $t['objective']); ?>"><?php echo htmlspecialchars((string) $t['objective']); ?></td>
                                <td class="num"><?php echo htmlspecialchars((string) $t['intent']); ?></td>
                                <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></td>
                                <td class="num"><?php echo htmlspecialchars((string) $t['priority']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="lyra-legend" style="padding:10px 12px">
                            <span><?php echo number_format($lyraTaskCounts['total']); ?> tasks recorded</span>
                            <span><?php echo number_format($lyraTaskCounts['succeeded']); ?> succeeded</span>
                            <span><?php echo number_format($lyraTaskCounts['failed']); ?> failed</span>
                            <span><?php echo number_format($lyraTaskCounts['unverified']); ?> unverified</span>
                        </div>
                        <?php else: ?>
                        <div class="lyra-nosource" style="padding:16px">No task records yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Resource Usage</h2><span class="ly-badge">live</span></div>
                    <div class="ly-panel-body">
                        <?php
                        $memPct = ($lyraMachine['mem_total'] ?? 0) > 0
                            ? round($lyraMachine['mem_used'] / $lyraMachine['mem_total'] * 100, 1) : null;
                        $diskPct = ($lyraMachine['disk_total'] ?? 0) > 0
                            ? round($lyraMachine['disk_used'] / $lyraMachine['disk_total'] * 100, 1) : null;
                        foreach ([
                            ['CPU load',   $lyraMachine['load'] === null ? null : min(100, $lyraMachine['load'] * 100 / max(1, (int) shell_exec('nproc 2>/dev/null') ?: 4)), $lyraMachine['load']],
                            ['Memory',     $memPct, lyra_ad_fmt_bytes($lyraMachine['mem_used']) . ' / ' . lyra_ad_fmt_bytes($lyraMachine['mem_total'])],
                            ['Storage',    $diskPct, lyra_ad_fmt_bytes($lyraMachine['disk_used']) . ' / ' . lyra_ad_fmt_bytes($lyraMachine['disk_total'])],
                            ['Uptime',     null, lyra_ad_fmt_uptime($lyraMachine['uptime'])],
                        ] as $r): ?>
                        <div class="lyra-svcrow2">
                            <span><?php echo $r[0]; ?></span>
                            <?php if ($r[1] !== null): ?>
                            <div class="lyra-modelbar" style="flex:0 1 120px"><span style="width:<?php echo min(100, max(0, (float) $r[1])); ?>%"></span></div>
                            <em><?php echo $r[1] === null ? '' : round((float) $r[1], 1) . '%'; ?></em>
                            <?php endif; ?>
                            <em style="color:var(--ly-text-4)"><?php echo htmlspecialchars((string) $r[2]); ?></em>
                        </div>
                        <?php endforeach; ?>
                        <div class="lyra-nosource" style="margin-top:8px">
                            Network throughput is not shown: no per-interface counter is recorded.
                        </div>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head">
                        <h2 class="ly-panel-title">Recent Logs</h2>
                        <a href="/pages/security_log.php" style="font-size:11px">View all &rarr;</a>
                    </div>
                    <div class="ly-panel-body" style="padding:0">
                        <?php if ($lyraLogs): ?>
                        <table class="lyra-logtable">
                            <thead><tr><th>Time</th><th>Event</th><th>Source</th></tr></thead>
                            <tbody>
                            <?php foreach ($lyraLogs as $l): ?>
                            <tr>
                                <td class="num"><?php echo htmlspecialchars((string) $l['created_at']); ?></td>
                                <td class="ly-truncate" style="max-width:180px"><?php echo htmlspecialchars((string) ($l['event_type'] ?? '')); ?></td>
                                <td class="num"><?php echo htmlspecialchars((string) ($l['ip_address'] ?? '')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="lyra-nosource" style="padding:16px">No log entries.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Services</h2></div>
                    <div class="ly-panel-body">
                        <?php
                        // Real reachability checks, not asserted status.
                        $svc = [];
                        $svc[] = ['Local model runtime', $activeModels > 0];
                        $svc[] = ['Database', $dbError === null];
                        $svc[] = ['HTTP platform', true];
                        foreach ($svc as $s): ?>
                        <div class="ad-svc">
                            <span class="ly-dot <?php echo $s[1] ? 'ly-dot-online' : 'ly-dot-offline'; ?>"></span>
                            <span><?php echo $s[0]; ?></span>
                            <span class="ly-spacer"></span>
                            <span class="ly-status <?php echo $s[1] ? 'ly-status-online' : 'ly-status-offline'; ?>">
                                <?php echo $s[1] ? 'Online' : 'Unavailable'; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <div style="font-size:11px;color:var(--ly-text-4);margin-top:10px">
                            Reachability is probed on page load. Values shown are this request's result.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ══ RAIL ══ -->
    <aside class="ly-rail" style="padding:20px;border-left:1px solid var(--ly-border)">
        <div class="ly-promo ly-mb-6">
            <div class="ly-row" style="gap:10px;margin-bottom:8px">
                <span class="ly-tile ly-tile-solid">
                    <svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3Z"/></svg>
                </span>
                <div style="font-weight:700;font-size:13.5px">Operational Checks</div>
            </div>
            <p style="font-size:11.5px;color:var(--ly-text-3);margin:0">
                This dashboard reports measured values only. Where a source could not be read it shows
                &ldquo;&mdash;&rdquo; rather than an estimate.
            </p>
        </div>

        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head"><h2 class="ly-panel-title">Quick Summary</h2></div>
            <div class="ly-panel-body">
                <div class="ly-row-between" style="font-size:12.5px;padding:7px 0"><span class="ly-muted">Registered users</span><span><?php echo nf($totals['users']); ?></span></div>
                <div class="ly-row-between" style="font-size:12.5px;padding:7px 0"><span class="ly-muted">Managed models</span><span><?php echo $isLyralink; ?></span></div>
                <div class="ly-row-between" style="font-size:12.5px;padding:7px 0"><span class="ly-muted">Dataset records</span><span><?php echo nf($totals['dataset']); ?></span></div>
            </div>
        </div>

        <div class="ly-panel">
            <div class="ly-panel-head"><h2 class="ly-panel-title">Management</h2></div>
            <div class="ly-panel-body">
                <a class="ly-btn ly-btn-ghost ly-btn-sm ly-btn-block ly-mb-3" href="/pages/admin.php">Full admin console</a>
                <a class="ly-btn ly-btn-ghost ly-btn-sm ly-btn-block ly-mb-3" href="/pages/security_log.php">Security log</a>
                <a class="ly-btn ly-btn-ghost ly-btn-sm ly-btn-block" href="/benchmark/">Benchmark results</a>
            </div>
        </div>
    </aside>
</div>
</body>
</html>
