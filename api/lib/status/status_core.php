<?php
/**
 * api/lib/status/status_core.php
 *
 * Shared uptime arithmetic for the public status page.
 *
 * These three functions previously lived inline in api/status.php. They were
 * extracted so that cron/status_probe.php can reuse exactly the same maths
 * instead of keeping a second copy in sync. The AI/incident helpers remain in
 * api/status.php.
 *
 * Guarded with function_exists() so that requiring this file from more than one
 * entry point can never trigger a fatal redeclare.
 */

declare(strict_types=1);

if (!function_exists('status_live_uptime_pct')) {
    /**
     * Maps a service status onto the uptime percentage it represents today.
     */
    function status_live_uptime_pct(string $status): float {
        return match ($status) {
            'major_outage'   => 25.00,
            'partial_outage' => 72.50,
            'degraded'       => 97.00,
            'maintenance'    => 99.00,
            default          => 100.00,
        };
    }
}

if (!function_exists('status_db_curdate')) {
    /**
     * The database's current date as YYYY-MM-DD.
     *
     * Never let PHP and MySQL disagree about "today". On this box CLI PHP runs
     * in UTC while MySQL (and PHP-FPM, via the system zone) runs four hours
     * behind, so PHP's date() and MySQL's CURDATE() differ for four hours every
     * night. Deriving the date key from the database keeps every writer on the
     * same calendar day. Falls back to PHP only if the query itself fails.
     */
    function status_db_curdate(mysqli $db): string {
        try {
            $res = $db->query('SELECT CURDATE() AS d');
            if ($res instanceof mysqli_result) {
                $row = $res->fetch_assoc();
                $res->free();
                $d = (string)($row['d'] ?? '');
                if ($d !== '') {
                    return $d;
                }
            }
        } catch (Throwable $e) {
            // fall through to PHP's own date
        }
        return date('Y-m-d');
    }
}

if (!function_exists('status_sync_today_uptime')) {
    /**
     * Writes today's uptime row per service.
     *
     * Uses min(existing, live) so that a service which was down earlier today
     * keeps that lower figure even after it recovers -- i.e. the day is scored
     * on its worst observed state, not its most recent one.
     */
    function status_sync_today_uptime(mysqli $db): void {
        // Derived from MySQL, not PHP: PHP's date() here previously wrote uptime
        // rows onto a different calendar day than the application, splitting
        // every day in two.
        $today = status_db_curdate($db);
        $existing = [];
        $todayEsc = $db->real_escape_string($today);
        $current = $db->query("SELECT service_id, uptime_pct FROM status_uptime WHERE date = '{$todayEsc}'");
        if ($current) {
            while ($row = $current->fetch_assoc()) {
                $existing[(int)($row['service_id'] ?? 0)] = (float)($row['uptime_pct'] ?? 100.00);
            }
        }

        $rows = $db->query("SELECT id, status FROM status_services ORDER BY id ASC");
        if (!$rows) {
            return;
        }
        $stmt = $db->prepare("INSERT INTO status_uptime (service_id, date, uptime_pct) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE uptime_pct = VALUES(uptime_pct)");
        if (!$stmt) {
            return;
        }
        while ($row = $rows->fetch_assoc()) {
            $serviceId = (int)($row['id'] ?? 0);
            $livePct = status_live_uptime_pct((string)($row['status'] ?? 'operational'));
            $pct = array_key_exists($serviceId, $existing)
                ? min($existing[$serviceId], $livePct)
                : $livePct;
            $stmt->bind_param('isd', $serviceId, $today, $pct);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('status_backfill_uptime_history')) {
    /**
     * Ensures a 90-day history exists so the bars render on first visit.
     *
     * Historical days are seeded at 100.00 because there is no real record for
     * them; only today is derived from live status. This is presentation
     * scaffolding, not measured uptime -- see the note in api/status.php.
     */
    function status_backfill_uptime_history(mysqli $db, int $days = 90): void {
        // Disabled: this fabricated 100.00 uptime for the past N days for
        // every service. Never-measured uptime must not be published.
        return;
        $days = max(1, min($days, 365));
        $rows = $db->query("SELECT id, status FROM status_services ORDER BY id ASC");
        if (!$rows) {
            return;
        }
        $services = [];
        while ($row = $rows->fetch_assoc()) {
            $services[] = [
                'id' => (int)($row['id'] ?? 0),
                'status' => (string)($row['status'] ?? 'operational'),
            ];
        }
        if (!$services) {
            return;
        }

        $stmt = $db->prepare("INSERT IGNORE INTO status_uptime (service_id, date, uptime_pct) VALUES (?, ?, ?)");
        if (!$stmt) {
            return;
        }
        // Anchor the date maths to the database's "today", then step backwards
        // in UTC on a date-only value so a DST boundary cannot shift a day.
        $anchor = DateTimeImmutable::createFromFormat(
            '!Y-m-d', status_db_curdate($db), new DateTimeZone('UTC')
        );
        if ($anchor === false) {
            $anchor = new DateTimeImmutable(status_db_curdate($db), new DateTimeZone('UTC'));
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $anchor->modify("-{$i} days")->format('Y-m-d');
            foreach ($services as $svc) {
                $pct = $i === 0 ? status_live_uptime_pct($svc['status']) : 100.00;
                $serviceId = (int)$svc['id'];
                $stmt->bind_param('isd', $serviceId, $date, $pct);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
}
