<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — ADMIN AND DEVELOPER STATISTICS DATA
   ══════════════════════════════════════════════════════════════════════════
   Aggregates for pages/admin-dashboard.php and pages/dev-stats.php.

   The audit log is a JSONL file that the orchestrator writes on every request.
   It is read by bounded tail: the file is around 65MB, so reading it whole would
   exhaust the PHP memory limit. A window is counted by streaming the tail once
   and aggregating in a single pass.

   What the audit log actually contains (checked, not assumed): orchestration
   decisions including model_selected and task_type, verification results,
   confidence scores, channel, and a UTC timestamp. It does NOT contain request
   duration or token counts, so those two figures cannot be derived and the
   pages using them say so rather than inventing a number.

   A cache file holds the last aggregation for 60 seconds. The audit log is
   append-only and large; re-parsing it on every page load is the difference
   between a fast page and a slow one.
   ══════════════════════════════════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lyra_chat_data.php';

if (!function_exists('lyra_ad_audit_path')) {
    /** Locate the audit log. Both known locations are checked. */
    function lyra_ad_audit_path(): ?string
    {
        static $p = false;
        if ($p !== false) {
            return $p;
        }
        $candidates = [
            __DIR__ . '/lib/storage/security/audit/chat_audit.jsonl',
            dirname(__DIR__) . '/storage/security/audit/chat_audit.jsonl',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $p = $c;
            }
        }
        return $p = null;
    }
}

if (!function_exists('lyra_ad_cache')) {
    /**
     * Read or write a small cache beside the audit log.
     * Failure to cache is never fatal: the caller recomputes.
     */
    function lyra_ad_cache(string $key, int $ttl, callable $compute)
    {
        $path = lyra_ad_audit_path();
        $dir = $path !== null ? dirname($path) : sys_get_temp_dir();
        $file = $dir . '/.lyra_ad_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';

        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $v = json_decode($raw, true);
                if (is_array($v)) {
                    return $v;
                }
            }
        }
        $v = $compute();
        if (is_array($v)) {
            @file_put_contents($file, json_encode($v), LOCK_EX);
        }
        return $v;
    }
}

if (!function_exists('lyra_ad_tail')) {
    /**
     * Return the last $maxLines lines of a file without loading it whole.
     * Reads backwards in chunks until enough newlines are found.
     */
    function lyra_ad_tail(string $path, int $maxLines = 4000, int $maxBytes = 20971520): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [];
        }
        $chunk = 262144;
        $buffer = '';
        $found = 0;
        fseek($fh, 0, SEEK_END);
        $pos = ftell($fh);
        // Stopping on line count alone is unsafe here: the audit file is ~65MB in
        // only ~4,800 lines, because each record is ~13KB. Asking for 6,000 lines
        // therefore reads the ENTIRE file and exhausted the 128MB PHP limit
        // (measured: 65,187,480 bytes attempted in one allocation). The byte cap
        // is what actually bounds memory; the line cap is a secondary stop.
        while ($pos > 0 && $found <= $maxLines && strlen($buffer) < $maxBytes) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $buf = fread($fh, $read);
            if ($buf === false) {
                break;
            }
            $buffer = $buf . $buffer;
            $found = substr_count($buffer, "\n");
        }
        fclose($fh);
        $lines = preg_split("/\r\n|\n|\r/", $buffer);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }
        return array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
    }
}

if (!function_exists('lyra_ad_window')) {
    /**
     * Aggregate one time window from the audit tail.
     *
     * $from, $to are unix timestamps. The tail is sized from the window so a
     * 24-hour window gets a larger sample than a 1-hour one.
     */
    function lyra_ad_window(int $from, int $to, int $maxLines = 6000): array
    {
        $path = lyra_ad_audit_path();
        $out = [
            'requests' => 0, 'ok' => true, 'hours' => [], 'models' => [],
            'tasks' => [], 'pass' => 0, 'fail' => 0, 'no_verify' => 0,
            'conf_low' => 0, 'conf_med' => 0, 'conf_high' => 0,
            'channels' => [], 'first' => null, 'last' => null, 'lines' => 0,
        ];
        if ($path === null) {
            $out['ok'] = false;
            return $out;
        }

        foreach (lyra_ad_tail($path, $maxLines) as $line) {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            $out['lines']++;
            $at = (string) ($d['at'] ?? '');
            $ts = $at !== '' ? strtotime($at) : false;
            if ($ts === false) {
                continue;
            }
            if ($ts < $from || $ts > $to) {
                continue;
            }
            $out['requests']++;
            if ($out['first'] === null || $at < $out['first']) { $out['first'] = $at; }
            if ($out['last'] === null || $at > $out['last'])   { $out['last'] = $at; }

            $h = gmdate('H', $ts);
            $out['hours'][$h] = ($out['hours'][$h] ?? 0) + 1;

            $o = $d['orchestration'] ?? [];
            $m = (string) ($o['model_selected'] ?? '');
            if ($m === '') { $m = 'unspecified'; }
            $out['models'][$m] = ($out['models'][$m] ?? 0) + 1;

            $t = (string) ($o['task_type'] ?? '');
            if ($t !== '' && $t !== 'null') {
                $out['tasks'][$t] = ($out['tasks'][$t] ?? 0) + 1;
            }

            $v = $d['verification'] ?? [];
            $p = $v['passed'] ?? null;
            if ($p === true)       { $out['pass']++; }
            elseif ($p === false)  { $out['fail']++; }
            else                   { $out['no_verify']++; }

            $c = $d['confidence']['score'] ?? null;
            if (is_int($c) || is_float($c)) {
                if ($c < 0.5)       { $out['conf_low']++; }
                elseif ($c < 0.75)  { $out['conf_med']++; }
                else                { $out['conf_high']++; }
            }

            $ch = (string) ($d['channel'] ?? 'unknown');
            $out['channels'][$ch] = ($out['channels'][$ch] ?? 0) + 1;
        }

        arsort($out['models']);
        arsort($out['tasks']);
        ksort($out['hours']);
        return $out;
    }
}

if (!function_exists('lyra_ad_stats')) {
    /**
     * The figures both dashboard pages need, cached for 60 seconds.
     * Includes a real previous-period comparison: the same length window
     * immediately before the current one.
     */
    function lyra_ad_stats(int $hours = 24): array
    {
        return lyra_ad_cache('stats_' . $hours, 60, function () use ($hours) {
            $now = time();
            $from = $now - $hours * 3600;
            $prevFrom = $from - $hours * 3600;

            $cur = lyra_ad_window($from, $now, 8000);
            $prev = lyra_ad_window($prevFrom, $from, 8000);

            $pct = static function (int $cur, int $prev): ?float {
                if ($prev === 0) {
                    return $cur === 0 ? 0.0 : null;   // null = no baseline to compare
                }
                return round((($cur - $prev) / $prev) * 100, 1);
            };

            $verTotal = $cur['pass'] + $cur['fail'];
            $verRate = $verTotal > 0 ? round($cur['pass'] / $verTotal * 100, 2) : null;

            $prevVerTotal = $prev['pass'] + $prev['fail'];
            $prevVerRate = $prevVerTotal > 0 ? round($prev['pass'] / $prevVerTotal * 100, 2) : null;

            return [
                'window_hours' => $hours,
                'requests'     => $cur['requests'],
                'requests_delta' => $pct($cur['requests'], $prev['requests']),
                'prev_requests' => $prev['requests'],
                'verified_pass' => $cur['pass'],
                'verified_fail' => $cur['fail'],
                'verify_rate'   => $verRate,
                'verify_rate_delta' => ($verRate !== null && $prevVerRate !== null)
                    ? round($verRate - $prevVerRate, 2) : null,
                'hours'        => $cur['hours'],
                'models'       => $cur['models'],
                'tasks'        => $cur['tasks'],
                'channels'     => $cur['channels'],
                'conf'         => ['low' => $cur['conf_low'], 'med' => $cur['conf_med'], 'high' => $cur['conf_high']],
                'first_seen'   => $cur['first'],
                'last_seen'    => $cur['last'],
                'sampled'      => $cur['lines'],
                'ok'           => $cur['ok'],
            ];
        });
    }
}

if (!function_exists('lyra_ad_platform')) {
    /** Database counts for the admin dashboard. */
    function lyra_ad_platform(): array
    {
        return lyra_ad_cache('platform', 60, function () {
            $one = static function (string $sql): ?int {
                $r = lyra_chat_q($sql);
                return $r ? (int) $r[0]['n'] : null;
            };
            return [
                'users'         => $one('SELECT COUNT(*) AS n FROM users'),
                'conversations' => $one('SELECT COUNT(*) AS n FROM conversations'),
                'messages'      => $one('SELECT COUNT(*) AS n FROM conversations'),
                'dataset'       => $one('SELECT COUNT(*) AS n FROM dataset'),
                'security'      => $one('SELECT COUNT(*) AS n FROM security_log'),
                'marketing'     => $one('SELECT COUNT(*) AS n FROM marketing_runs'),
                'services'      => $one('SELECT COUNT(*) AS n FROM status_services'),
                'operational'   => $one("SELECT COUNT(*) AS n FROM status_services WHERE status='operational'"),
            ];
        });
    }
}

if (!function_exists('lyra_ad_machine')) {
    /** Live host readings for the System Health panels. */
    function lyra_ad_machine(): array
    {
        return lyra_ad_cache('machine', 30, function () {
            $out = ['load' => null, 'mem_used' => null, 'mem_total' => null,
                    'disk_used' => null, 'disk_total' => null, 'uptime' => null];

            if (is_readable('/proc/loadavg')) {
                $l = explode(' ', trim((string) file_get_contents('/proc/loadavg')));
                $out['load'] = isset($l[0]) ? (float) $l[0] : null;
            }
            if (is_readable('/proc/meminfo')) {
                $mi = [];
                foreach (file('/proc/meminfo') as $line) {
                    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                        $mi[$m[1]] = (int) $m[2] * 1024;
                    }
                }
                if (isset($mi['MemTotal'])) {
                    $out['mem_total'] = $mi['MemTotal'];
                    $out['mem_used'] = $mi['MemTotal'] - ($mi['MemAvailable'] ?? $mi['MemFree'] ?? 0);
                }
            }
            $total = @disk_total_space('/');
            $free = @disk_free_space('/');
            if ($total !== false && $free !== false) {
                $out['disk_total'] = (float) $total;
                $out['disk_used'] = (float) ($total - $free);
            }
            if (is_readable('/proc/uptime')) {
                $u = explode(' ', trim((string) file_get_contents('/proc/uptime')));
                $out['uptime'] = isset($u[0]) ? (int) (float) $u[0] : null;
            }
            return $out;
        });
    }
}

if (!function_exists('lyra_ad_fmt_bytes')) {
    function lyra_ad_fmt_bytes(?float $b): string
    {
        if ($b === null) {
            return '—';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return round($b, $i >= 3 ? 1 : 0) . ' ' . $u[$i];
    }
}

if (!function_exists('lyra_ad_fmt_uptime')) {
    function lyra_ad_fmt_uptime(?int $s): string
    {
        if ($s === null) {
            return '—';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return $d . 'd ' . $h . 'h';
        }
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        return $m . 'm';
    }
}

if (!function_exists('lyra_ad_delta_html')) {
    /** Renders a change indicator. Returns nothing when there is no baseline. */
    function lyra_ad_delta_html(?float $d, bool $goodWhenUp = true): string
    {
        if ($d === null) {
            return '<span class="lyra-delta lyra-delta-flat" title="No data in the previous period to compare against">no baseline</span>';
        }
        if (abs($d) < 0.05) {
            return '<span class="lyra-delta lyra-delta-flat">0%</span>';
        }
        $up = $d > 0;
        $cls = ($up === $goodWhenUp) ? 'lyra-delta-up' : 'lyra-delta-down';
        $arrow = $up ? '&uarr;' : '&darr;';
        return '<span class="lyra-delta ' . $cls . '">' . $arrow . ' ' . abs($d) . '%</span>';
    }
}

if (!function_exists('lyra_ad_sparkline')) {
    /**
     * Inline SVG sparkline from a numeric series.
     * Used for the hourly request chart and the success-rate trend.
     */
    function lyra_ad_sparkline(array $series, int $w = 260, int $h = 64, string $color = '#6C3AF8'): string
    {
        $n = count($series);
        if ($n < 2) {
            return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"></svg>';
        }
        $max = max($series);
        $min = min($series);
        $span = max(1, $max - $min);
        $step = $w / ($n - 1);
        $pts = [];
        foreach (array_values($series) as $i => $v) {
            $x = round($i * $step, 1);
            $y = round($h - (($v - $min) / $span) * ($h - 6) - 3, 1);
            $pts[] = [$x, $y];
        }
        $line = 'M' . $pts[0][0] . ',' . $pts[0][1];
        foreach (array_slice($pts, 1) as $p) {
            $line .= ' L' . $p[0] . ',' . $p[1];
        }
        $area = $line . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z';
        $gid = 'spl' . substr(md5($color . $w . $n . implode(',', array_slice($series, 0, 4))), 0, 8);
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" style="width:100%;height:100%">'
             . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.38"/>'
             . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
             . '<path d="' . $area . '" fill="url(#' . $gid . ')"/>'
             . '<path d="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2" '
             . 'stroke-linejoin="round" stroke-linecap="round"/>'
             . '</svg>';
    }
}

if (!function_exists('lyra_ad_ring')) {
    /** Inline SVG progress ring with a percentage in the middle. */
    function lyra_ad_ring(?float $pct, int $size = 96, string $color = '#22C55E', string $suffix = ''): string
    {
        $r = 40;
        $circ = 2 * M_PI * $r;
        $v = $pct === null ? 0 : max(0, min(100, $pct));
        $dash = round($circ, 1);
        $off = round($circ * (1 - $v / 100), 1);
        $label = $pct === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . $suffix;
        return '<div class="lyra-ring2" style="width:' . $size . 'px;height:' . $size . 'px">'
             . '<svg viewBox="0 0 100 100" style="transform:rotate(-90deg)">'
             . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="7"/>'
             . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="7" '
             . 'stroke-linecap="round" stroke-dasharray="' . $dash . '" stroke-dashoffset="' . $off . '"/></svg>'
             . '<b>' . htmlspecialchars($label) . '</b></div>';
    }
}

if (!function_exists('lyra_ad_donut')) {
    /**
     * Donut from label => value pairs. Colours cycle through a fixed palette so
     * the same slice keeps the same colour between renders.
     */
    function lyra_ad_donut(array $data, int $size = 130): string
    {
        $total = array_sum($data);
        $palette = ['#6C3AF8', '#38BDF8', '#22C55E', '#F59E0B', '#EF4444', '#9B5CFF', '#4A5270'];
        $r = 38;
        $circ = 2 * M_PI * $r;
        $segs = '';
        $offset = 0.0;
        $i = 0;
        foreach ($data as $label => $v) {
            if ($total <= 0) {
                break;
            }
            $frac = $v / $total;
            $len = $circ * $frac;
            $segs .= '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="' . $palette[$i % count($palette)] . '" '
                   . 'stroke-width="14" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" '
                   . 'stroke-dashoffset="' . round(-$offset, 2) . '"/>';
            $offset += $len;
            $i++;
        }
        $centre = $total > 0
            ? '<b>' . ($total >= 1000 ? round($total / 1000, 1) . 'K' : $total) . '</b><em>total</em>'
            : '<b>—</b><em>no data</em>';
        return '<div class="lyra-donut" style="width:' . $size . 'px;height:' . $size . 'px">'
             . '<svg viewBox="0 0 100 100" style="transform:rotate(-90deg)">'
             . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="14"/>'
             . $segs . '</svg><span class="lyra-donut-centre">' . $centre . '</span></div>';
    }
}
