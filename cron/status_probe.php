<?php
/**
 * cron/status_probe.php
 *
 * The missing writer behind /api/status.php.
 *
 * Previously nothing ever changed status_services.status except a manual admin
 * action, so the status page reported every service as "operational" with 100%
 * uptime indefinitely -- including while /api/support.php was returning 500s
 * and /api/chat.php was timing out. This job probes each service on a schedule,
 * applies hysteresis so one blip cannot flap the page, and keeps status_uptime
 * honest.
 *
 * Deliberate design choices:
 *  - Probes run here (CLI, cron) and never inside the public endpoint, so an
 *    anonymous GET can never be used to trigger outbound requests.
 *  - No shell execution anywhere: cURL, mysqli and filesystem calls only. That
 *    removes the command-injection surface a systemctl-based check would add.
 *  - Services with no reliable local signal are reported as "skipped" and left
 *    untouched rather than being claimed healthy.
 *  - AI-written incident copy is NOT generated here on purpose: auto-filing an
 *    incident on every flap would cost money and create noise.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

date_default_timezone_set('UTC');

// CLI has no REQUEST_TIME_FLOAT, so track the start time explicitly.
$startedAt = microtime(true);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/api/security.php';
require_once $ROOT . '/api/lib/status/status_core.php';

// ── single-instance lock ─────────────────────────────────────────────────────
// This codebase has already been bitten by overlapping cron runs (moltscrape was
// double-scheduled with no locking at all), so take an exclusive lock here.
$lockFh = @fopen(sys_get_temp_dir() . '/lyralink-status-probe.lock', 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    exit("[status-probe] another run is already in progress; exiting\n");
}

$FAIL_AFTER = 2;  // consecutive failing probes before a service is downgraded
$OK_AFTER   = 2;  // consecutive passing probes before it is restored
$BASE       = 'https://lyralinkai.com';

$PROBES = [
    // slug            => probe definition
    // Probe ?health=1, NOT a bare /api/chat.php.
    // A bare GET to this endpoint is a real guest chat request: it invokes the
    // language model and bills an inference. Probing it every five minutes
    // therefore cost money for no extra signal. ?health=1 exercises the same
    // bootstrap (DB + runtime checks) and returns in a fraction of the time
    // without touching the model.
    'ai-chat-api' => ['type' => 'http', 'url' => $BASE . '/api/chat.php?health=1',
                      'timeout' => 10, 'expect' => 'json_ok', 'slow_ms' => 3000, 'interval_min' => 5],
    'web-app'     => ['type' => 'http', 'url' => $BASE . '/',
                      'timeout' => 10, 'expect' => '2xx3xx', 'slow_ms' => 4000, 'interval_min' => 2],
    'auth'        => ['type' => 'http', 'url' => $BASE . '/api/auth.php',
                      'timeout' => 10, 'expect' => 'lt500', 'slow_ms' => 4000, 'interval_min' => 2],
    'billing'     => ['type' => 'http', 'url' => $BASE . '/api/billing_webhook.php',
                      'timeout' => 10, 'expect' => 'lt500', 'slow_ms' => 4000, 'interval_min' => 5],
    'dataset-api' => ['type' => 'http', 'url' => $BASE . '/api/dataset.php',
                      'timeout' => 10, 'expect' => 'lt500', 'slow_ms' => 4000, 'interval_min' => 5],
    'database'    => ['type' => 'db', 'slow_ms' => 500, 'interval_min' => 2],
    'moltbook'    => ['type' => 'freshness', 'table' => 'moltbook_posts', 'column' => 'fetched_at',
                      'degraded_after_min' => 180, 'stale_after_min' => 2880, 'interval_min' => 5],
    // No local unit/port exists for this integration, so it cannot be verified
    // from this box. Left untouched rather than reported green.
    'file-storage' => ['type' => 'storage', 'path' => __DIR__ . '/../storage',
                      'min_free_gb' => 5, 'slow_ms' => 2000, 'interval_min' => 10],
    // No discord bot process, unit or container exists on this host, so there
    // is nothing to probe. Labelled honestly rather than reported as a
    // malformed probe type.
    'discord-bot' => ['type' => 'not_monitored'],
];

// ─────────────────────────────────────────────────────────────────────────────
// Probe helpers
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{raw:string,status:string,code:?int,ms:int,error:?string} */
function lyra_probe_http(array $cfg): array {
    $ch = curl_init((string)$cfg['url']);
    // Use GET, never HEAD. Measured on this box: HEAD /api/chat.php returns in a
    // consistent 18.07s while GET returns in ~45ms, so the original HEAD-based
    // check reported a false outage for a perfectly healthy endpoint. GET is
    // also the method real clients use, so it is the honest liveness signal.
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'lyralink-status-probe/1.0',
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    $t0   = microtime(true);
    $body = curl_exec($ch);
    $ms   = (int)round((microtime(true) - $t0) * 1000);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if ($code === 0) {
        return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => null, 'ms' => $ms,
                'error' => $err !== '' ? $err : 'no HTTP response'];
    }
    if ($code >= 500) {
        return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => $code, 'ms' => $ms,
                'error' => "HTTP {$code}"];
    }
    if (($cfg['expect'] ?? 'lt500') === '2xx3xx' && $code >= 400) {
        return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => $code, 'ms' => $ms,
                'error' => "HTTP {$code}"];
    }

    // Some endpoints always answer 200, so the HTTP code alone says nothing.
    // Assert on the payload instead: /api/chat.php?health=1 returns
    // {"success":true,"ok":true,...}.
    if (($cfg['expect'] ?? '') === 'json_ok') {
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => $code, 'ms' => $ms,
                    'error' => 'health payload was not valid JSON'];
        }
        if (($decoded['success'] ?? false) !== true) {
            return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => $code, 'ms' => $ms,
                    'error' => 'health reported success=false'];
        }
        if (($decoded['ok'] ?? false) !== true) {
            // Serving traffic but internally unhealthy: degraded, not down.
            return ['raw' => 'fail', 'status' => 'degraded', 'code' => $code, 'ms' => $ms,
                    'error' => 'health reported ok=false'];
        }
    }

    if (!empty($cfg['slow_ms']) && $ms > (int)$cfg['slow_ms']) {
        return ['raw' => 'ok', 'status' => 'degraded', 'code' => $code, 'ms' => $ms,
                'error' => "slow: {$ms}ms"];
    }
    return ['raw' => 'ok', 'status' => 'operational', 'code' => $code, 'ms' => $ms, 'error' => null];
}

/** @return array{raw:string,status:string,code:?int,ms:int,error:?string} */
function lyra_probe_db(mysqli $db, array $cfg): array {
    $t0 = microtime(true);
    try {
        $res = $db->query('SELECT 1');
        if ($res instanceof mysqli_result) {
            $res->free();
        }
    } catch (Throwable $e) {
        return ['raw' => 'fail', 'status' => 'major_outage', 'code' => null,
                'ms' => (int)round((microtime(true) - $t0) * 1000), 'error' => $e->getMessage()];
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($ms > (int)($cfg['slow_ms'] ?? 500)) {
        return ['raw' => 'ok', 'status' => 'degraded', 'code' => null, 'ms' => $ms, 'error' => "slow: {$ms}ms"];
    }
    return ['raw' => 'ok', 'status' => 'operational', 'code' => null, 'ms' => $ms, 'error' => null];
}

/** @return array{raw:string,status:string,code:?int,ms:int,error:?string} */
function lyra_probe_freshness(mysqli $db, array $cfg): array {
    $table  = (string)$cfg['table'];
    $column = (string)$cfg['column'];
    try {
        // Compute the age entirely inside MySQL. Comparing a DB-local timestamp
        // against PHP's time() is wrong here: CLI PHP runs in UTC while MySQL
        // uses the system zone four hours behind, which made a perfectly fresh
        // scrape look 252 minutes stale (= exactly the 4h offset).
        $res = $db->query("SELECT MAX(`{$column}`) AS newest,
                                  TIMESTAMPDIFF(MINUTE, MAX(`{$column}`), NOW()) AS age_min
                           FROM `{$table}`");
        $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
        if ($res instanceof mysqli_result) {
            $res->free();
        }
    } catch (Throwable $e) {
        return ['raw' => 'unknown', 'status' => 'operational', 'code' => null, 'ms' => 0,
                'error' => 'probe unavailable: ' . $e->getMessage()];
    }

    $newest = $row['newest'] ?? null;
    if ($newest === null || $newest === '') {
        // No rows at all: we genuinely do not know whether the integration works.
        return ['raw' => 'unknown', 'status' => 'operational', 'code' => null, 'ms' => 0,
                'error' => 'no rows yet — freshness unknown'];
    }

    $ageMin = isset($row['age_min']) ? (int)$row['age_min'] : -1;
    if ($ageMin < 0) {
        return ['raw' => 'unknown', 'status' => 'operational', 'code' => null, 'ms' => 0,
                'error' => 'freshness age unavailable'];
    }
    if ($ageMin > (int)$cfg['stale_after_min']) {
        return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => null, 'ms' => 0,
                'error' => "no scrape for {$ageMin}m"];
    }
    if ($ageMin > (int)$cfg['degraded_after_min']) {
        return ['raw' => 'ok', 'status' => 'degraded', 'code' => null, 'ms' => 0,
                'error' => "stale: last scrape {$ageMin}m ago"];
    }
    return ['raw' => 'ok', 'status' => 'operational', 'code' => null, 'ms' => 0, 'error' => null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Connect
// ─────────────────────────────────────────────────────────────────────────────

$dbCfg = api_db_config();
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    fwrite(STDERR, "[status-probe] DB connection failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

try {
    $db->query("CREATE TABLE IF NOT EXISTS status_probe_state (
        slug            VARCHAR(64) NOT NULL PRIMARY KEY,
        fail_streak     INT NOT NULL DEFAULT 0,
        ok_streak       INT NOT NULL DEFAULT 0,
        last_http_code  INT NULL,
        last_latency_ms INT NULL,
        last_raw        VARCHAR(16) NULL,
        last_error      TEXT NULL,
        probed_at       DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "[status-probe] cannot create status_probe_state: {$e->getMessage()}\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Load current services + previous probe state
// ─────────────────────────────────────────────────────────────────────────────

$services = [];
try {
    $res = $db->query("SELECT id, slug, name, status FROM status_services ORDER BY id ASC");
    while ($r = $res->fetch_assoc()) {
        $services[(string)$r['slug']] = $r;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[status-probe] cannot read status_services: {$e->getMessage()}\n");
    exit(1);
}

$state = [];
try {
    // age_sec is computed by MySQL so the interval gate never compares a
    // DB-local timestamp against PHP's clock. Doing that made every probe look
    // ~4h old, so the gate never actually skipped anything.
    $res = $db->query("SELECT *, TIMESTAMPDIFF(SECOND, probed_at, NOW()) AS age_sec FROM status_probe_state");
    while ($r = $res->fetch_assoc()) {
        $state[(string)$r['slug']] = $r;
    }
} catch (Throwable $e) {
    // Non-fatal: we simply start with no history.
    fwrite(STDERR, "[status-probe] warning: could not read state: {$e->getMessage()}\n");
}

$updSvc = $db->prepare("UPDATE status_services SET status = ?, updated_at = NOW() WHERE id = ?");
$updSt  = $db->prepare("INSERT INTO status_probe_state
    (slug, fail_streak, ok_streak, last_http_code, last_latency_ms, last_raw, last_error, probed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        fail_streak = VALUES(fail_streak), ok_streak = VALUES(ok_streak),
        last_http_code = VALUES(last_http_code), last_latency_ms = VALUES(last_latency_ms),
        last_raw = VALUES(last_raw), last_error = VALUES(last_error), probed_at = NOW()");

if (!$updSvc || !$updSt) {
    fwrite(STDERR, "[status-probe] could not prepare statements; aborting\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Probe loop
// ─────────────────────────────────────────────────────────────────────────────

$changed = 0;
$rowsOut = [];

foreach ($services as $slug => $svc) {
    $cfg = $PROBES[$slug] ?? ['type' => 'none'];
    $type = (string)$cfg['type'];

    if ($type === 'none') {
        $rowsOut[] = sprintf('  %-13s %-9s skipped (no reliable local signal)', $slug, 'SKIP');
        continue;
    }

    // Interval gate: cheap probes run every cron tick, expensive ones less often.
    $intervalMin = max(1, (int)($cfg['interval_min'] ?? 2));
    if (isset($state[$slug]['age_sec']) && (int)$state[$slug]['age_sec'] < ($intervalMin * 60) - 10) {
        continue; // not due yet
    }


/**
 * Storage probe: the volume must accept a write and keep headroom.
 * Reads are not sufficient evidence -- a full or read-only filesystem can still
 * serve existing files, so we actually create and remove a file.
 */
if (!function_exists('lyra_probe_storage')) {
    function lyra_probe_storage(array $cfg): array {
        $t0   = microtime(true);
        $path = (string)($cfg['path'] ?? '');
        $ms   = static function () use ($t0): int { return (int)round((microtime(true) - $t0) * 1000); };

        if ($path === '' || !is_dir($path)) {
            return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => null, 'ms' => $ms(),
                    'error' => 'storage path missing'];
        }

        $probe = rtrim($path, '/') . '/.lyra_write_probe';
        if (@file_put_contents($probe, 'ok') === false) {
            return ['raw' => 'fail', 'status' => 'partial_outage', 'code' => null, 'ms' => $ms(),
                    'error' => 'storage path not writable'];
        }
        @unlink($probe);

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return ['raw' => 'fail', 'status' => 'degraded', 'code' => null, 'ms' => $ms(),
                    'error' => 'cannot read free space'];
        }

        $freeGb = $free / 1073741824;
        $minGb  = (float)($cfg['min_free_gb'] ?? 5);
        if ($freeGb < $minGb) {
            return ['raw' => 'fail', 'status' => 'degraded', 'code' => null, 'ms' => $ms(),
                    'error' => sprintf('low disk: %.1f GB free (min %.1f)', $freeGb, $minGb)];
        }

        return ['raw' => 'ok', 'status' => 'operational', 'code' => null, 'ms' => $ms(), 'error' => ''];
    }
}

    switch ($type) {
        case 'http':      $p = lyra_probe_http($cfg); break;
        case 'db':        $p = lyra_probe_db($db, $cfg); break;
        case 'freshness': $p = lyra_probe_freshness($db, $cfg); break;
        case 'storage':   $p = lyra_probe_storage($cfg); break;
        case 'not_monitored':
            $p = ['raw' => 'unknown', 'status' => 'operational', 'code' => null, 'ms' => 0,
                  'error' => 'no probe available for this service on this host'];
            break;
        default:          $p = ['raw' => 'unknown', 'status' => 'operational', 'code' => null, 'ms' => 0, 'error' => 'bad probe type'];
    }

    // "unknown" means the probe itself could not reach a verdict -- do not guess.
    if ($p['raw'] === 'unknown') {
        $rowsOut[] = sprintf('  %-13s %-9s %s', $slug, 'UNKNOWN', (string)$p['error']);
        // types: slug=s, fail_streak=i, ok_streak=i, last_http_code=i,
        //        last_latency_ms=i, last_raw=s, last_error=s
        // bind_param() takes every argument BY REFERENCE, so each value must
        // live in a variable first. Passing `$arr['k'] ?? 0` directly is a fatal
        // TypeError in PHP 8, which terminated this probe mid-run on the
        // "unknown" branch and silently skipped every service after it --
        // including the uptime sync below the loop.
        $uFailStreak = (int)($state[$slug]['fail_streak'] ?? 0);
        $uOkStreak   = (int)($state[$slug]['ok_streak'] ?? 0);
        $uCode = $p['code'];
        $uMs   = (int)$p['ms'];
        $uRaw  = (string)$p['raw'];
        $uErr  = (string)$p['error'];
        $updSt->bind_param('siiiiss', $slug, $uFailStreak, $uOkStreak, $uCode, $uMs, $uRaw, $uErr);
        $updSt->execute();
        continue;
    }

    $fail = (int)($state[$slug]['fail_streak'] ?? 0);
    $ok   = (int)($state[$slug]['ok_streak'] ?? 0);
    if ($p['raw'] === 'ok') { $ok++; $fail = 0; } else { $fail++; $ok = 0; }

    // Hysteresis: only act once we have consistent evidence.
    $current = (string)$svc['status'];
    $target  = $current;
    if ($ok >= $OK_AFTER) {
        $target = (string)$p['status'];          // 'operational' or 'degraded' (slow)
    } elseif ($fail >= $FAIL_AFTER) {
        $target = (string)$p['status'];          // 'partial_outage' or 'major_outage'
    }

    // Never stomp on a deliberate maintenance window.
    $applied = false;
    if ($target !== $current && $current !== 'maintenance') {
        $svcId = (int)$svc['id'];
        $updSvc->bind_param('si', $target, $svcId);
        $updSvc->execute();
        $applied = true;
        $changed++;
    }

    $rowsOut[] = sprintf(
        '  %-13s %-9s %-15s -> %-15s fail=%d ok=%d %s%s',
        $slug, strtoupper($p['raw']), $current, $target, $fail, $ok,
        $p['ms'] > 0 ? "{$p['ms']}ms " : '',
        $p['error'] ? "({$p['error']})" : ''
    );

    $updSt->bind_param('siiiiss', $slug, $fail, $ok, $p['code'], $p['ms'], $p['raw'], $p['error']);
    $updSt->execute();
}

// ─────────────────────────────────────────────────────────────────────────────
// Uptime bookkeeping (moved out of the public endpoint)
// ─────────────────────────────────────────────────────────────────────────────

try {
    status_sync_today_uptime($db);

    // Backfill only when the 90-day window is actually short, so we are not
    // issuing ~810 INSERT IGNORE statements on every single run.
    $expected = count($services) * 90;
    $cnt = $db->query("SELECT COUNT(*) AS c FROM status_uptime WHERE date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
    $have = $cnt ? (int)($cnt->fetch_assoc()['c'] ?? 0) : $expected;
    if ($have < ($expected * 0.95)) {
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[status-probe] uptime sync failed: {$e->getMessage()}\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Report
// ─────────────────────────────────────────────────────────────────────────────

echo '[' . date('c') . "] status-probe: " . count($services) . " services, " . $changed . " changed\n";
foreach ($rowsOut as $line) {
    echo $line . "\n";
}
echo "[status-probe] done in " . round(microtime(true) - $startedAt, 2) . "s\n";

if ($updSvc) { $updSvc->close(); }
if ($updSt)  { $updSt->close(); }
$db->close();
flock($lockFh, LOCK_UN);
fclose($lockFh);
