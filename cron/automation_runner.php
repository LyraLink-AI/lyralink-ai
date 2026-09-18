<?php
/**
 * Lyralink Automation Runner
 * Run via cron: * * * * * php /path/to/cron/automation_runner.php >> /tmp/lyralink-auto.log 2>&1
 */

require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/saas.php';

$dbCfg = api_db_config(['host'=>'localhost','user'=>'app_user','pass'=>'','name'=>'aicloud']);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) { echo "[automation_runner] DB connect failed\n"; exit(1); }
$db->set_charset('utf8mb4');
saas_bootstrap_schema($db);

$db->query("CREATE TABLE IF NOT EXISTS automation_job_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id INT UNSIGNED NOT NULL,
    automation_id INT UNSIGNED NOT NULL,
    run_slot CHAR(16) NOT NULL,
    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_slot (org_id, automation_id, run_slot),
    KEY idx_acquired_at (acquired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->query("DELETE FROM automation_job_locks WHERE acquired_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

define('LYRALINK_AUTOMATION_LIBRARY_MODE', true);
require_once __DIR__ . '/../api/automation.php';

$batchSize = 20;
$now = date('Y-m-d H:i:s');

$rows = $db->query(
    "SELECT a.*, u.plan FROM automations a
     JOIN users u ON u.id = a.user_id
     WHERE a.enabled = 1 AND a.next_run_at <= '$now'
     ORDER BY a.next_run_at ASC
     LIMIT $batchSize"
);

if (!$rows || $rows->num_rows === 0) {
    echo "[automation_runner] No automations due at $now\n";
    exit(0);
}

$count = 0;
while ($auto = $rows->fetch_assoc()) {
    $orgId = (int)($auto['org_id'] ?? 0);
    $runSlot = date('Y-m-d H:i');
    $lockStmt = $db->prepare("INSERT INTO automation_job_locks (org_id, automation_id, run_slot) VALUES (?, ?, ?)");
    $locked = false;
    if ($lockStmt) {
        $aid = (int)$auto['id'];
        $lockStmt->bind_param('iis', $orgId, $aid, $runSlot);
        $locked = $lockStmt->execute();
        $lockStmt->close();
    }

    if (!$locked) {
        echo "[automation_runner] Skipping duplicate run lock for #{$auto['id']} in slot {$runSlot}\n";
        continue;
    }

    echo "[automation_runner] Running #{$auto['id']} '{$auto['name']}' for user {$auto['user_id']}\n";
    $result = automation_execute($db, $auto, 'cron:' . $runSlot . ':' . (string)$auto['id']);
    if ($result['success']) {
        echo "[automation_runner] ✓ Done. Next run: {$result['next_run_at']}\n";
    } else {
        echo "[automation_runner] ✗ Error: {$result['error']}\n";
    }
    $count++;
}

echo "[automation_runner] Processed $count automation(s) at $now\n";
