<?php

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'app_user';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'aicloud';

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
            'url' => 'https://ai.cloudhavenx.com/pages/support_admin/',
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

$jobs = $db->query("SELECT id, channel, payload, attempts FROM support_notification_queue WHERE status IN ('pending','failed') AND available_at <= NOW() AND attempts < 5 ORDER BY id ASC LIMIT 20");
if (!$jobs) {
    exit(1);
}

while ($job = $jobs->fetch_assoc()) {
    $id = (int)$job['id'];
    $attempts = (int)$job['attempts'];
    $payload = json_decode($job['payload'], true);

    $db->query("UPDATE support_notification_queue SET status = 'processing', attempts = attempts + 1, last_error = NULL WHERE id = $id");

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
    } catch (Throwable $e) {
        $delayMinutes = min(30, max(1, $attempts + 1));
        $error = $db->real_escape_string(substr($e->getMessage(), 0, 1000));
        $status = ($attempts + 1) >= 5 ? 'failed' : 'pending';
        $db->query("UPDATE support_notification_queue SET status = '$status', last_error = '$error', available_at = DATE_ADD(NOW(), INTERVAL $delayMinutes MINUTE) WHERE id = $id");
    }
}