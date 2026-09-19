<?php
session_start();
require_once __DIR__ . '/../api/security.php';

if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}

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
    <script src="/assets/js/lyra-ui.js" defer></script>
    <style>
        .ds-hour { display:flex; align-items:flex-end; gap:5px; height:150px; }
        .ds-hour > div { flex:1; background:linear-gradient(180deg,#8B5CF6,#5028E0); border-radius:4px 4px 0 0; min-height:3px; opacity:.9; }
        .ds-modelrow { display:flex; align-items:center; gap:11px; padding:9px 0; }
        .ds-bar { flex:1; height:7px; border-radius:var(--ly-r-full); background:var(--ly-glass-strong); overflow:hidden; }
        .ds-bar > span { display:block; height:100%; border-radius:var(--ly-r-full); background:linear-gradient(90deg,#5028E0,#9B5CFF); }
        .ds-num { font-variant-numeric:tabular-nums; }
    </style>
</head>
<body class="ly">
<div class="ly-shell ly-shell-has-rail">

    <aside class="ly-sidebar">
        <a class="ly-sidebar-brand ly-logo" href="/">
            <img src="/images/lyralinklogobolt.png" alt="" class="ly-logo-mark" style="border-radius:8px">
            <span style="font-size:16px">Lyralink</span>
        </a>
        <div class="ly-sidebar-section">Main</div>
        <?php foreach ([['Dashboard','M3 10.5 12 3l9 7.5V21H3z'],['Conversations','M21 12a8 8 0 0 1-12 7l-5 1 1-5a8 8 0 1 1 16-3z'],['Projects','M3 7h7l2 2h9v10H3z'],['Automations','M13 2 4 14h7l-1 8 9-12h-7z'],['Files','M6 3h8l4 4v14H6z'],['Knowledge','M4 5h16v14H4z'],['Users','M16 20v-2a4 4 0 0 0-8 0v2M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8']] as $n): ?>
        <a class="ly-navitem" href="#">
            <svg class="ly-ico ly-ico-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $n[1]; ?>"/></svg>
            <?php echo $n[0]; ?>
        </a>
        <?php endforeach; ?>

        <div class="ly-sidebar-section">Developer</div>
        <?php foreach ([['API Docs','M6 3h8l4 4v14H6z',0],['Logs','M6 3h8l4 4v14H6zM9 12h6',0],['Statistics','M4 20V10M10 20V4M16 20v-7M22 20H2',1],['System Health','M3 12h4l3 8 4-16 3 8h4',0],['Deployments','M12 3v12M8 11l4 4 4-4',0]] as $n): ?>
        <a class="ly-navitem<?php echo $n[2] ? ' is-active' : ''; ?>" href="#">
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
            <span class="ly-avatar ly-avatar-sm">AW</span>
            <div>
                <div style="font-size:12.5px;font-weight:600">Alex West</div>
                <div style="font-size:11px;color:var(--ly-text-4)">Developer</div>
            </div>
        </div>

        <div style="padding:24px">
            <h1 style="font-size:24px;margin-bottom:4px">Developer Statistics</h1>
            <p class="ly-muted" style="font-size:13px;margin-bottom:22px">
                Real-time metrics from the orchestration audit log and local runtime.
                Aggregated from the most recent <?php echo number_format(LY_TAIL_LINES); ?> audit entries
                <?php if ($agg['last']): ?>(latest <?php echo htmlspecialchars(substr($agg['last'], 0, 19)); ?>Z)<?php endif; ?>.
            </p>

            <div class="ly-grid ly-grid-4 ly-mb-6">
                <?php
                $cards = [
                    ['Audit Entries', nf_safe($agg['requests']), 'M6 3h8l4 4v14H6z'],
                    ['Verified Pass',  nf_safe($agg['verified_pass']), 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z'],
                    ['Verified Fail',  nf_safe($agg['verified_fail']), 'M12 8v5M12 16h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z'],
                    ['Orch. Decisions',nf_safe($agg['orch']), 'M12 5a3 3 0 0 0-3 3 3 3 0 0 0-3 3 3 3 0 0 0 1 5 3 3 0 0 0 5 2V5Z'],
                ];
                foreach ($cards as $c): ?>
                <div class="ly-card">
                    <div class="ly-row-between ly-mb-3">
                        <span class="ly-tile"><svg class="ly-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo $c[2]; ?>"/></svg></span>
                    </div>
                    <div class="ds-num" style="font-size:24px;font-weight:800;letter-spacing:-.03em"><?php echo $c[1]; ?></div>
                    <div style="font-size:11.5px;color:var(--ly-text-4)"><?php echo $c[0]; ?></div>
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
                            <span class="ly-truncate" style="width:150px;font-size:12px"><?php echo htmlspecialchars($name); ?></span>
                            <div class="ds-bar"><span style="width:<?php echo $pct; ?>%"></span></div>
                            <span class="ds-num" style="font-size:11.5px;color:var(--ly-text-3);width:52px;text-align:right"><?php echo $pct; ?>%</span>
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
                        <div class="ly-row" style="gap:10px;padding:9px 0;border-bottom:1px solid var(--ly-border);font-size:12.5px">
                            <span class="ly-dot <?php echo $hc[1] ? 'ly-dot-online' : 'ly-dot-warn'; ?>"></span>
                            <span><?php echo $hc[0]; ?></span>
                            <span class="ly-spacer"></span>
                            <span class="ly-truncate ly-muted" style="max-width:190px;font-size:11.5px"><?php echo htmlspecialchars($hc[2]); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
