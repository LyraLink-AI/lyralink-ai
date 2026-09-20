<?php

require_once __DIR__ . '/../api/security.php';

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$dbHost = $dbCfg['host'];
$dbUser = $dbCfg['user'];
$dbPass = $dbCfg['pass'];
$dbName = $dbCfg['name'];

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) {
    exit(1);
}

$db->query("CREATE TABLE IF NOT EXISTS support_notification_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel VARCHAR(32) NOT NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function getConfig($db, $key) {
    $k = $db->real_escape_string($key);
    $r = $db->query("SELECT `value` FROM support_config WHERE `key` = '$k'");
    return $r ? ($r->fetch_assoc()['value'] ?? '') : '';
}

function sendEmail($db, array $job) {
    $to = $job['to'] ?? '';
    $subject = $job['subject'] ?? '';
    $htmlBody = $job['html'] ?? '';
    if (!$to || !$subject || !$htmlBody) {
        throw new RuntimeException('Invalid email payload');
    }

    $smtpHost = getConfig($db, 'smtp_host');
    $smtpPort = (int)getConfig($db, 'smtp_port');
    $smtpUser = getConfig($db, 'smtp_user');
    $smtpPass = getConfig($db, 'smtp_pass');
    $fromAddr = getConfig($db, 'smtp_from');

    if (!$smtpUser || !$smtpPass) {
        $headers = "From: Lyralink Support <$fromAddr>\r\nContent-Type: text/html; charset=UTF-8";
        if (!mail($to, $subject, $htmlBody, $headers)) {
            throw new RuntimeException('mail() failed');
        }
        return;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('Composer autoload missing');
    }

    require_once $autoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->Port = $smtpPort;
    $mail->Timeout = 5;

    if ($smtpPort === 465) {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($smtpPort === 587) {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($fromAddr, 'Lyralink Support');
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->send();
}

function sendDiscordWebhook($db, array $job) {
    $ticket = $job['ticket'] ?? null;
    if (!$ticket || !is_array($ticket)) {
        throw new RuntimeException('Invalid Discord payload');
    }

    $webhookUrl = getConfig($db, 'discord_webhook_url');
    if (!$webhookUrl) {
        return;
    }

    $priorityColors = ['low' => 3066993, 'medium' => 16776960, 'high' => 15105570, 'critical' => 15158332];
    $priorityEmoji  = ['low' => '🟢', 'medium' => '🟡', 'high' => '🟠', 'critical' => '🔴'];
    $categoryLabels = ['general' => 'General Support', 'billing' => 'Billing', 'bug_report' => 'Bug Report', 'account' => 'Account Issue', 'live_chat' => 'Live Chat'];

    $priority = $ticket['user_priority'] ?? 'medium';
    $color = $priorityColors[$priority] ?? 3447003;
    $emoji = $priorityEmoji[$priority] ?? '⚪';
    $cat = $categoryLabels[$ticket['category'] ?? 'general'] ?? ($ticket['category'] ?? 'general');

    $priorityEscaped = $db->real_escape_string($priority);
    $pRow = $db->query("SELECT role_id FROM support_discord_roles WHERE priority = '$priorityEscaped'")->fetch_assoc();
    $ping = $pRow ? "<@&{$pRow['role_id']}>" : '';

    $payload = [
        'content' => $ping ? "$ping — New {$emoji} **" . strtoupper($priority) . "** ticket" : null,
        'embeds' => [[
            'title' => "🎫 [{$ticket['ticket_ref']}] " . substr($ticket['subject'] ?? 'Support Ticket', 0, 80),
            'description' => substr($ticket['body'] ?? '', 0, 300) . ((strlen($ticket['body'] ?? '') > 300) ? '...' : ''),
            'color' => $color,
            'fields' => [
                ['name' => 'Category', 'value' => $cat, 'inline' => true],
                ['name' => 'Priority', 'value' => "$emoji " . ucfirst($priority), 'inline' => true],
                ['name' => 'Status', 'value' => '🟣 Open', 'inline' => true],
                ['name' => 'From', 'value' => $ticket['guest_name'] ?? $ticket['username'] ?? 'Guest', 'inline' => true],
            ],
            'footer' => ['text' => 'Lyralink Support · ' . date('M j, Y g:i A')],
            'url' => 'http://lyralinkai.com/pages/support_admin/',
        ]],
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $error) {
        throw new RuntimeException($error ?: 'Discord webhook failed');
    }

    if ($code >= 400) {
        throw new RuntimeException('Discord webhook HTTP ' . $code);
    }
}

// ── Single-run guard ────────────────────────────────────────────────────────
// This worker is reachable from two directions: cron, and the opportunistic
// triggerNotificationWorker() call inside api/support.php. Two copies running at
// the same time would both pick up the same rows and deliver them twice, so a
// second copy exits instead of duplicating notifications.
// GET_LOCK is used rather than a file lock because the two callers run as
// different users (root via cron, the site user via a web request), and a lock
// file in /tmp cannot be flock()ed dependably across both.
$lockRes = $db->query("SELECT GET_LOCK('lyra_support_notifications', 0) AS got");
$gotLock = $lockRes ? (int)($lockRes->fetch_assoc()['got'] ?? 0) : 0;
if ($gotLock !== 1) {
    echo date('c') . " another worker holds the lock; exiting without claiming work\n";
    exit(0);
}

// ── Reclaim rows orphaned by a worker that stopped mid-send ─────────────────
// A fatal error, OOM kill or hard timeout leaves a row in 'processing' forever,
// because the claim below only ever looks at pending/failed. 15 minutes is well
// past the 5s SMTP / 3s webhook timeouts, so anything older is genuinely stuck.
$db->query("UPDATE support_notification_queue
               SET status = 'pending',
                   last_error = 'reclaimed: a previous worker stopped mid-send'
             WHERE status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND attempts < 5");

// ── Atomic claim ────────────────────────────────────────────────────────────
// Claim and fetch in one statement. The previous SELECT-then-UPDATE-per-row
// pattern left a window in which a concurrent run could claim the same row.
$claimToken = 'claim:' . bin2hex(random_bytes(8));
$token = $db->real_escape_string($claimToken);
$claimed = $db->query("UPDATE support_notification_queue
                          SET status = 'processing',
                              attempts = attempts + 1,
                              last_error = '$token'
                        WHERE status IN ('pending','failed')
                          AND available_at <= NOW()
                          AND attempts < 5
                        ORDER BY id ASC
                        LIMIT 20");
if (!$claimed) {
    exit(1);
}

$jobs = $db->query("SELECT id, channel, payload, attempts FROM support_notification_queue WHERE last_error = '$token' ORDER BY id ASC");
if (!$jobs) {
    exit(1);
}

$sentCount     = 0;
$deferredCount = 0;

while ($job = $jobs->fetch_assoc()) {
    $id = (int)$job['id'];
    $attempts = (int)$job['attempts'];
    $payload = json_decode($job['payload'], true);

    try {
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON payload');
        }

        if ($job['channel'] === 'email') {
            sendEmail($db, $payload);
        } elseif ($job['channel'] === 'discord') {
            sendDiscordWebhook($db, $payload);
        } else {
            throw new RuntimeException('Unsupported channel: ' . $job['channel']);
        }

        $db->query("UPDATE support_notification_queue SET status = 'sent', processed_at = NOW(), last_error = NULL WHERE id = $id");
        $sentCount++;
        echo date('c') . " #$id {$job['channel']} sent\n";
    } catch (Throwable $e) {
        // $attempts is the attempt number we have just made, because the claim
        // increments it before the send. So 5 failed attempts is terminal, and
        // the backoff runs 1..30 minutes.
        $delayMinutes = min(30, max(1, $attempts));
        $error = $db->real_escape_string(substr($e->getMessage(), 0, 1000));
        $status = $attempts >= 5 ? 'failed' : 'pending';
        $db->query("UPDATE support_notification_queue SET status = '$status', last_error = '$error', available_at = DATE_ADD(NOW(), INTERVAL $delayMinutes MINUTE) WHERE id = $id");
        $deferredCount++;
        // Logged, not swallowed: a delivery failure that leaves no trace is how
        // this class of bug stayed invisible before.
        echo date('c') . " #$id {$job['channel']} $status: " . $e->getMessage() . "\n";
    }
}

echo date('c') . " done: sent=$sentCount deferred=$deferredCount\n";