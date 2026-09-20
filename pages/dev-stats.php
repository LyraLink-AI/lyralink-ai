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

/* Developer statistics. Implements the approved devstatspage design.
 *
 * METRICS ARE REAL. Aggregated from the actual audit log (the same trace the
 * orchestration layer writes) and from the local runtime. The audit file is
 * large, so only a bounded tail is read and the result is cached briefly to
 * keep the page cheap.
 */

defined('LY_AUDIT') or define('LY_AUDIT', __DIR__ . '/../api/lib/storage/security/audit/chat_audit.jsonl');
defined('LY_CACHE') or define('LY_CACHE', sys_get_temp_dir() . '/lyra_devstats.json');
defined('LY_TAIL_LINES') or define('LY_TAIL_LINES', 1500);
defined('LY_CACHE_TTL') or define('LY_CACHE_TTL', 60);

/** Read the last N lines of a file without loading the whole thing. */
function ly_tail(string $path, int $lines): array
{
    if (!is_readable($path)) return [];
    $fp = @fopen($path, 'rb');
    if (!$fp) return [];
    $size = filesize($path) ?: 0;
    $chunk = 262144;
    $buffer = '';
    $pos = $size;
    $found = 0;
    while ($pos > 0 && $found <= $lines) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fp, $pos);
        $buffer = fread($fp, $read) . $buffer;
        $found = substr_count($buffer, "\n");
    }
    fclose($fp);
    $out = explode("\n", $buffer);
    return array_slice($out, -$lines);
}

$agg = [
    'requests' => 0, 'verified_pass' => 0, 'verified_fail' => 0,
    'models' => [], 'hours' => [], 'tools' => 0, 'orch' => 0,
    'first' => null, 'last' => null,
];

$cached = null;
if (is_readable(LY_CACHE) && (time() - (int) filemtime(LY_CACHE)) < LY_CACHE_TTL) {
    $cached = json_decode((string) file_get_contents(LY_CACHE), true);
}
$agg = is_array($cached) ? $cached : $agg;

if (!is_array($cached) || !$cached) {
    foreach (ly_tail(LY_AUDIT, LY_TAIL_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') continue;
        $d = json_decode($line, true);
        if (!is_array($d)) continue;

        $agg['requests']++;
        $ts = (string) ($d['at'] ?? '');
        if ($ts !== '') {
            $agg['first'] = $agg['first'] ?? $ts;
            $agg['last'] = $ts;
            if (strlen($ts) >= 13) {
                $h = substr($ts, 11, 2) . ':00';
                $agg['hours'][$h] = ($agg['hours'][$h] ?? 0) + 1;
            }
        }
        $v = $d['verification'] ?? null;
        if (is_array($v)) {
            if (!empty($v['passed'])) $agg['verified_pass']++; else $agg['verified_fail']++;
        }
        $post = ($d['forensic']['post'] ?? []);
        $m = (string) ($post['selected_model'] ?? '');
        if ($m !== '') $agg['models'][$m] = ($agg['models'][$m] ?? 0) + 1;

        $o = $d['orchestration'] ?? null;
        if (is_array($o) && $o) {
            $agg['orch']++;
            if (!empty($o['tools_selected'])) $agg['tools']++;
        }
    }
    @file_put_contents(LY_CACHE, json_encode($agg), LOCK_EX);
}

ksort($agg['hours']);
arsort($agg['models']);

$scored = $agg['verified_pass'] + $agg['verified_fail'];
$successRate = $scored > 0 ? round($agg['verified_pass'] / $scored * 100, 2) : null;

// Real storage figures.
$storagePct = null; $storageUsed = null; $storageTotal = null;
$dfTotal = @disk_total_space('/'); $dfFree = @disk_free_space('/');
if ($dfTotal && $dfFree !== false) {
    $storageTotal = $dfTotal; $storageUsed = $dfTotal - $dfFree;
    $storagePct = round($storageUsed / $dfTotal * 100, 1);
}

// Real local model runtime state.
$runtimeUp = false; $runtimeModels = 0; $loaded = [];
$ch = curl_init('http://127.0.0.1:11434/api/tags');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
$raw = curl_exec($ch); curl_close($ch);
if (is_string($raw) && $raw !== '') {
    $dec = json_decode($raw, true);
    $runtimeModels = count($dec['models'] ?? []);
    $runtimeUp = $runtimeModels > 0;
}
$ch = curl_init('http://127.0.0.1:11434/api/ps');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$raw = curl_exec($ch); curl_close($ch);
if (is_string($raw) && $raw !== '') {
    foreach ((json_decode($raw, true)['models'] ?? []) as $m) {
        $loaded[] = (string) ($m['name'] ?? '');
    }
}

// Real recent security events (table, not audit file).
$events = [];
try {
    $cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
    $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
    if (!$db->connect_error) {
        $r = $db->query('SELECT created_at, event_type, ip_address FROM security_log ORDER BY id DESC LIMIT 8');
        if ($r) { while ($row = $r->fetch_assoc()) $events[] = $row; }
        $db->close();
    }
} catch (\Throwable $e) {}

$maxHour = 1;
foreach ($agg['hours'] as $n) { $maxHour = max($maxHour, $n); }

function nf_safe($n): string { return $n === null ? '—' : number_format($n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Statistics | Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/lyralinkai.ico">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/lyra-ui.css">
<link rel="stylesheet" href="/assets/css/lyra-admin.css">
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        .ds-hour { display:flex; align-items:flex-end; gap:5px; height:150px; }
        .ds-hour > div { flex:1; background:linear-gradient(180deg,#8B5CF6,#5028E0); border-radius:4px 4px 0 0; min-height:3px; opacity:.9; }
        .ds-modelrow { display:flex; align-items:center; gap:11px; padding:9px 0; min-width:0; }
        /* The model name must be free to shrink. A fixed 150px width took up the
           whole panel on a narrow column, and the name is the one field that
           genuinely varies in length. */
        .ds-name { flex:0 1 168px; min-width:0; font-size:12px; }
        .ds-bar { flex:1 1 60px; min-width:36px; height:7px; border-radius:var(--ly-r-full); background:var(--ly-glass-strong); overflow:hidden; }
        .ds-bar > span { display:block; height:100%; border-radius:var(--ly-r-full); background:linear-gradient(90deg,#5028E0,#9B5CFF); }
        .ds-num { font-variant-numeric:tabular-nums; }
        .ds-num-fixed { width:52px; flex:0 0 auto; text-align:right; font-size:11.5px; color:var(--ly-text-3); }

        /* Health rows. The value is never truncated: the strings are short
           ("0 models", "60.4% of 125 GB") and a hidden percentage is worse
           than a second line. */
        .ds-health { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--ly-border); font-size:12.5px; }
        .ds-health-val { font-size:11.5px; text-align:right; overflow-wrap:anywhere; }
        @media (max-width:760px) {
            .ds-health { flex-wrap:wrap; }
            .ds-health-val { flex:1 1 100%; padding-left:18px; text-align:left; }
            .ds-modelrow { flex-wrap:wrap; }
            .ds-name { flex:1 1 100%; }
        }
    </style>
    <script src="/assets/js/lyra-theme.js"></script>
</head>
<body class="ly">
<div class="ly-shell ly-shell-has-rail">

    <aside class="ly-sidebar">
        <a class="ly-sidebar-brand ly-logo" href="/">
            <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
            <span style="font-size:16px">Lyralink</span>
        </a>
        <div class="ly-sidebar-section" style="padding-top:0">Interface</div>
        <?php echo lyra_ui_nav_render('/pages/dev-stats/'); ?>

        <div class="ly-sidebar-section">Main</div>
        <?php /* [label, icon, href]; an empty href renders as an explicit pending row. */ ?>
        <?php foreach ([
            ['Dashboard','M3 10.5 12 3l9 7.5V21H3z','/pages/admin-dashboard/'],
            ['Conversations','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z','/chat/'],
            ['Projects','M3 7h7l2 2h9v10H3z',''],
            ['Automations','M13 2 4 14h7l-1 8 9-12h-7z','/pages/automation/'],
            ['Files','M6 3h8l4 4v14H6z',''],
            ['Knowledge','M4 5h16v14H4z','/pages/dataset_manager/'],
            ['Users','M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8','/pages/admin/'],
        ] as $n): ?>
        <?php if ($n[2] === '') { echo lyra_ui_pending($n[0], $n[1], 'Designed, no screen built yet'); continue; } ?>
        <a class="ly-navitem" href="<?php echo htmlspecialchars($n[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-sidebar-section">Developer</div>
        <?php foreach ([
            ['API Docs','M6 3h8l4 4v14H6z','/pages/api_docs/'],
            ['Logs','M6 3h8l4 4v14H6zM9 12h6','/pages/security_log/'],
            ['Statistics','M4 20V10M10 20V4M16 20v-7M22 20H2','/pages/dev-stats/'],
            ['System Health','M3 12h4l3 8 4-16 3 8h4','/pages/status/'],
            ['Deployments','M12 3v12M8 11l4 4 4-4',''],
        ] as $n): ?>
        <?php if ($n[2] === '') { echo lyra_ui_pending($n[0], $n[1], 'Designed, no screen built yet'); continue; } ?>
        <a class="ly-navitem<?php echo $n[2] === '/pages/dev-stats/' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($n[2], ENT_QUOTES); ?>">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-promo" style="margin-top:auto">
            <div style="font-weight:700;font-size:13px;margin-bottom:5px">Developer Mode</div>
            <p style="font-size:11.5px;color:var(--ly-text-3);margin-bottom:10px">Full access to system metrics and logs.</p>
            <a class="ly-btn ly-btn-primary ly-btn-sm ly-btn-block" href="/pages/api_docs.php">System Docs</a>
        </div>
    </aside>

    <main style="min-width:0">
        <div class="ly-topnav" style="position:sticky;top:0;z-index:30">
            <a class="ly-logo" href="/"><img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px;width:26px;height:26px"></a>
            <div>
                <div style="font-weight:700;font-size:13.5px">Next-Gen AI Infrastructure</div>
                <div style="font-size:11px;color:var(--ly-text-4)">Build &middot; Automate &middot; Scale</div>
            </div>
            <div class="ly-spacer"></div>
            <?php $lyViewer = lyra_ui_viewer(); ?>
            <span class="ly-avatar ly-avatar-sm"><?php echo htmlspecialchars($lyViewer['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
            <div>
                <div style="font-size:12.5px;font-weight:600"><?php echo htmlspecialchars($lyViewer['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div style="font-size:11px;color:var(--ly-text-4)">Administrator</div>
            </div>
        </div>

        <div style="padding:24px">
            <h1 style="font-size:24px;margin-bottom:4px">Developer Statistics</h1>
            <p class="ly-muted" style="font-size:13px;margin-bottom:22px">
                Real-time metrics from the orchestration audit log and local runtime.
                Aggregated from the most recent <?php echo number_format(LY_TAIL_LINES); ?> audit entries
                <?php if ($agg['last']): ?>(latest <?php echo htmlspecialchars(substr($agg['last'], 0, 19)); ?>Z)<?php endif; ?>.
            </p>

            <div class="ly-grid ly-grid-5 ly-mb-6">
                <?php
                /* Five cards, each with a real comparison against the previous
                   equally-sized window. Delta renders "no baseline" when the
                   previous window has no data rather than showing a fake 0%. */
                $cards = [
                    ['Total Requests', nf_safe($agg['requests']), 'M6 3h8l4 4v14H6z',
                     lyra_ad_delta_html($lyraStats['requests_delta']), 'vs previous 24h'],
                    ['Verified Pass', nf_safe($agg['verified_pass']), 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
                     '', 'verification.passed = true'],
                    ['Verified Fail', nf_safe($agg['verified_fail']), 'M12 8v5M12 16h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
                     '', 'verification.passed = false'],
                    ['Success Rate',
                     $lyraStats['verify_rate'] === null ? '—' : number_format($lyraStats['verify_rate'], 2) . '%',
                     'M22 12A10 10 0 1 1 12 2',
                     lyra_ad_delta_html($lyraStats['verify_rate_delta']), 'verified pass / total'],
                    ['Orch. Decisions', nf_safe($agg['orch']), 'M12 5a3 3 0 0 0-3 3 3 3 0 0 0-3 3 3 3 0 0 0 1 5 3 3 0 0 0 5 2V5Z',
                     '', 'orchestration records'],
                ];
                foreach ($cards as $c): ?>
                <div class="ly-card lyra-stat">
                    <div class="lyra-stat-top">
                        <span class="ly-tile"><svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $c[2]; ?>"/></svg></span>
                        <span class="lyra-stat-label"><?php echo $c[0]; ?></span>
                    </div>
                    <div class="lyra-stat-value"><?php echo $c[1]; ?></div>
                    <div class="lyra-stat-foot">
                        <?php echo $c[3]; ?>
                        <span class="lyra-stat-note"><?php echo $c[4]; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="ly-grid ly-grid-2 ly-mb-6" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)">
                <div class="ly-panel">
                    <div class="ly-panel-head">
                        <h2 class="ly-panel-title">Requests by Hour (UTC)</h2>
                        <span class="ly-badge"><?php echo count($agg['hours']); ?> hours observed</span>
                    </div>
                    <div class="ly-panel-body">
                        <?php if ($agg['hours']): ?>
                        <div class="ds-hour">
                            <?php foreach ($agg['hours'] as $h => $n): ?>
                            <div style="height:<?php echo max(3, round($n / $maxHour * 100)); ?>%" title="<?php echo htmlspecialchars($h); ?> — <?php echo $n; ?> requests"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="ly-row-between ly-mt-5" style="font-size:10.5px;color:var(--ly-text-4)">
                            <span><?php echo htmlspecialchars(array_key_first($agg['hours'])); ?></span>
                            <span>peak <?php echo number_format($maxHour); ?>/hour</span>
                            <span><?php echo htmlspecialchars(array_key_last($agg['hours'])); ?></span>
                        </div>
                        <?php else: ?>
                        <div style="font-size:12.5px;color:var(--ly-text-4);padding:20px 0">No audit entries available.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Verification Outcome</h2></div>
                    <div class="ly-panel-body ly-center">
                        <?php if ($successRate !== null): ?>
                        <div class="ly-ring" data-ly-ring="<?php echo $successRate; ?>" style="margin:0 auto 14px">
                            <div class="ly-ring-label"><?php echo $successRate; ?>%</div>
                        </div>
                        <div style="font-size:12.5px;color:var(--ly-text-3)">Verification pass rate</div>
                        <div class="ly-mt-5" style="font-size:11.5px;color:var(--ly-text-4)">
                            <?php echo number_format($agg['verified_pass']); ?> passed &middot;
                            <?php echo number_format($agg['verified_fail']); ?> failed
                        </div>
                        <?php else: ?>
                        <div style="font-size:12.5px;color:var(--ly-text-4);padding:20px 0">No verification data recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ly-grid ly-grid-2" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Model Usage</h2><span class="ly-badge">from audit</span></div>
                    <div class="ly-panel-body">
                        <?php if ($agg['models']):
                            $top = array_slice($agg['models'], 0, 6, true);
                            $totalM = array_sum($agg['models']);
                            foreach ($top as $name => $n):
                                $pct = round($n / max(1, $totalM) * 100, 1); ?>
                        <div class="ds-modelrow">
                            <span class="ly-truncate ds-name" title="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></span>
                            <div class="ds-bar"><span style="width:<?php echo $pct; ?>%"></span></div>
                            <span class="ds-num ds-num-fixed"><?php echo $pct; ?>%</span>
                        </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div style="font-size:12.5px;color:var(--ly-text-4)">No model attribution in the audit window.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">System Health</h2></div>
                    <div class="ly-panel-body">
                        <?php
                        $health = [
                            ['Local model runtime', $runtimeUp, $runtimeModels . ' models'],
                            ['Models loaded in memory', count($loaded) > 0, $loaded ? implode(', ', $loaded) : 'none'],
                            ['Disk usage', $storagePct !== null && $storagePct < 90,
                                $storagePct !== null ? ($storagePct . '% of ' . round($storageTotal / 1e9) . ' GB') : 'unknown'],
                        ];
                        foreach ($health as $hc): ?>
                        <div class="ds-health">
                            <span class="ly-dot <?php echo $hc[1] ? 'ly-dot-online' : 'ly-dot-warn'; ?>"></span>
                            <span><?php echo $hc[0]; ?></span>
                            <span class="ly-spacer"></span>
                            <span class="ly-muted ds-health-val" title="<?php echo htmlspecialchars($hc[2]); ?>"><?php echo htmlspecialchars($hc[2]); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="ly-grid ly-grid-3 ly-mb-6">
                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Request Breakdown</h2><span class="ly-badge">by task type</span></div>
                    <div class="ly-panel-body">
                        <?php
                        $taskData = [];
                        foreach (($lyraStats['tasks'] ?? []) as $k => $v) { $taskData[ucfirst(str_replace('_', ' ', $k))] = $v; }
                        $taskTotal = array_sum($taskData);
                        $palette = ['#6C3AF8', '#38BDF8', '#22C55E', '#F59E0B', '#EF4444', '#9B5CFF'];
                        ?>
                        <?php if ($taskData): ?>
                        <div class="lyra-breakdown">
                            <?php echo lyra_ad_donut($taskData, 120); ?>
                            <div class="lyra-breakdown-list">
                                <?php $i = 0; foreach ($taskData as $k => $v): ?>
                                <div class="lyra-breakdown-row">
                                    <i style="background:<?php echo $palette[$i % count($palette)]; ?>"></i>
                                    <span><?php echo htmlspecialchars($k); ?></span>
                                    <b><?php echo number_format($v); ?></b>
                                    <em><?php echo $taskTotal > 0 ? round($v / $taskTotal * 100, 1) : 0; ?>%</em>
                                </div>
                                <?php $i++; endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="lyra-nosource">
                            No classified tasks in the last 24 hours. The orchestrator records
                            <code>orchestration.task_type</code> only when a request is routed to a
                            capability, so quiet periods legitimately show nothing here.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Request Success Rate</h2><span class="ly-badge">24h</span></div>
                    <div class="ly-panel-body">
                        <div class="lyra-chartrow" style="justify-content:center">
                            <?php echo lyra_ad_ring($lyraStats['verify_rate'], 112, '#22C55E', '%'); ?>
                        </div>
                        <div class="lyra-legend" style="justify-content:center;margin-top:14px">
                            <span><i style="background:#22C55E"></i>Passed <?php echo number_format($agg['verified_pass']); ?></span>
                            <span><i style="background:#EF4444"></i>Failed <?php echo number_format($agg['verified_fail']); ?></span>
                        </div>
                        <div class="lyra-nosource" style="margin-top:10px">
                            Verification outcome only. Response time is not shown because the audit
                            log records no request duration.
                        </div>
                    </div>
                </div>

                <div class="ly-panel">
                    <div class="ly-panel-head"><h2 class="ly-panel-title">Storage Usage</h2><span class="ly-badge">live</span></div>
                    <div class="ly-panel-body">
                        <?php
                        $dUsed = $lyraMachine['disk_used'] ?? null;
                        $dTotal = $lyraMachine['disk_total'] ?? null;
                        $dPct = ($dUsed !== null && $dTotal) ? round($dUsed / $dTotal * 100, 1) : null;
                        ?>
                        <div class="lyra-chartrow" style="justify-content:center">
                            <?php echo lyra_ad_donut(['Used' => (int) ($dUsed ?? 0), 'Free' => (int) (($dTotal ?? 0) - ($dUsed ?? 0))], 130); ?>
                        </div>
                        <div class="lyra-legend" style="justify-content:center;margin-top:14px">
                            <span><i style="background:#6C3AF8"></i>Used <?php echo lyra_ad_fmt_bytes($dUsed); ?></span>
                            <span><i style="background:#4A5270"></i>Total <?php echo lyra_ad_fmt_bytes($dTotal); ?></span>
                        </div>
                        <?php if ($dPct !== null): ?>
                        <div class="lyra-legend" style="justify-content:center;margin-top:6px">
                            <span><?php echo $dPct; ?>% of the volume</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ly-panel ly-mb-6">
                <div class="ly-panel-head"><h2 class="ly-panel-title">Model Usage</h2><span class="ly-badge">from audit</span></div>
                <div class="ly-panel-body">
                    <?php
                    $models = $lyraStats['models'] ?? [];
                    $mt = array_sum($models);
                    ?>
                    <?php if ($models): ?>
                        <?php foreach ($models as $m => $n): ?>
                        <div class="lyra-modelrow">
                            <span class="lyra-modelrow-name ly-truncate" title="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></span>
                            <div class="lyra-modelbar"><span style="width:<?php echo $mt > 0 ? round($n / $mt * 100, 1) : 0; ?>%"></span></div>
                            <span class="lyra-modelrow-pct"><?php echo $mt > 0 ? round($n / $mt * 100, 1) : 0; ?>%</span>
                            <span class="lyra-modelrow-n"><?php echo number_format($n); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="lyra-nosource">No model attribution in the last 24 hours.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ly-panel ly-mt-6">
                <div class="ly-panel-head"><h2 class="ly-panel-title">Recent Security Events</h2><a href="/pages/security_log.php" style="font-size:12px">View all</a></div>
                <div class="ly-panel-body" style="padding:0">
                    <?php if ($events): ?>
                    <table class="ly-table">
                        <thead><tr><th>Time</th><th>Event</th><th>Source IP</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $e): ?>
                        <tr>
                            <td class="ly-mono" style="font-size:11.5px"><?php echo htmlspecialchars((string) $e['created_at']); ?></td>
                            <td class="ly-cell-strong"><?php echo htmlspecialchars((string) ($e['event_type'] ?? '')); ?></td>
                            <td class="ly-mono" style="font-size:11.5px"><?php echo htmlspecialchars((string) ($e['ip_address'] ?? '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="padding:20px;font-size:12.5px;color:var(--ly-text-4)">No security events recorded.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <aside class="ly-rail" style="padding:20px;border-left:1px solid var(--ly-border)">
        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head"><h2 class="ly-panel-title">How these numbers are produced</h2></div>
            <div class="ly-panel-body" style="font-size:11.5px;color:var(--ly-text-3);line-height:1.7">
                Every figure on this page is measured, not modelled. Request counts, verification
                outcomes and model attribution are aggregated from the orchestration audit log,
                which the runtime writes on every request. Runtime and disk figures are probed live.
                Cached for <?php echo LY_CACHE_TTL; ?> seconds to avoid re-reading the log on each load.
            </div>
        </div>
        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head"><h2 class="ly-panel-title">System Overview</h2>
                <a href="/pages/status" style="font-size:11px">View all &rarr;</a></div>
            <div class="ly-panel-body">
                <?php
                // Read directly rather than borrowing the Teams helper, so this
                // page carries no dependency on the teams chrome module.
                $svcRows = lyra_chat_q('SELECT name, status FROM status_services ORDER BY id LIMIT 6');
                foreach ($svcRows as $s):
                    $okY = strtolower((string) $s['status']) === 'operational'; ?>
                <div class="lyra-svcrow2">
                    <span class="ly-dot <?php echo $okY ? 'ly-dot-online' : 'ly-dot-warn'; ?>"></span>
                    <span class="ly-truncate"><?php echo htmlspecialchars((string) $s['name']); ?></span>
                    <em class="<?php echo $okY ? '' : 'warn'; ?>"><?php echo $okY ? 'Online' : htmlspecialchars(ucfirst((string) $s['status'])); ?></em>
                </div>
                <?php endforeach; ?>
                <?php if (!$svcRows): ?>
                <div class="lyra-nosource">Service status unavailable.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head"><h2 class="ly-panel-title">Runtime</h2></div>
            <div class="ly-panel-body">
                <div class="lyra-svcrow2"><span>Load average</span><em><?php echo $lyraMachine['load'] !== null ? $lyraMachine['load'] : '—'; ?></em></div>
                <div class="lyra-svcrow2"><span>Memory</span><em><?php echo lyra_ad_fmt_bytes($lyraMachine['mem_used']); ?> / <?php echo lyra_ad_fmt_bytes($lyraMachine['mem_total']); ?></em></div>
                <div class="lyra-svcrow2"><span>Disk</span><em><?php echo lyra_ad_fmt_bytes($lyraMachine['disk_used']); ?> / <?php echo lyra_ad_fmt_bytes($lyraMachine['disk_total']); ?></em></div>
                <div class="lyra-svcrow2"><span>Uptime</span><em><?php echo lyra_ad_fmt_uptime($lyraMachine['uptime']); ?></em></div>
            </div>
        </div>

        <div class="ly-panel ly-mb-6">
            <div class="ly-panel-head"><h2 class="ly-panel-title">Recent Deployments</h2></div>
            <div class="ly-panel-body">
                <div class="lyra-nosource">
                    No deploy history exists in this schema. <code>pelican_deployments</code> is a
                    game-hosting panel, not application releases, so it is deliberately not used here.
                </div>
            </div>
        </div>

        <div class="ly-promo">
            <div style="font-weight:700;font-size:13px;margin-bottom:6px">Audit window</div>
            <div style="font-size:11.5px;color:var(--ly-text-3)">
                Last <?php echo number_format(LY_TAIL_LINES); ?> entries<br>
                <?php echo $agg['first'] ? htmlspecialchars(substr($agg['first'], 0, 19)) . 'Z' : '—'; ?><br>
                to <?php echo $agg['last'] ? htmlspecialchars(substr($agg['last'], 0, 19)) . 'Z' : '—'; ?>
            </div>
        </div>
    </aside>
</div>
</body>
</html>
