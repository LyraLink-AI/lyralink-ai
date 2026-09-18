<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lib/network_policy.php';
require_once __DIR__ . '/lib/chat/os_core.php';
session_start();
api_json_headers();

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
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'create_ticket',
    'user_reply',
    'agent_login',
    'agent_logout',
    'heartbeat',
    'agent_reply',
    'update_ticket',
    'create_agent',
    'update_agent',
    'set_discord_roles',
    'set_smtp',
    'delete_ticket',
    'delete_user_from_ticket',
    'update_user_account',
    'retry_notification_job',
    'set_email_templates',
    'send_test_email_template',
    'agent_ai_assist',
    'claim_ticket',
    'resolve_ticket',
    'reopen_ticket',
]);

const SUPPORT_EMAIL_TEMPLATES = [
    'new_ticket_admin' => [
        'label' => 'New Ticket Alert',
        'subject_key' => 'email_tpl_new_ticket_admin_subject',
        'body_key' => 'email_tpl_new_ticket_admin_body',
        'description' => 'Sent to the support inbox when a new ticket is created.',
        'placeholders' => ['ticket_ref', 'priority', 'category', 'from_name', 'from_email', 'subject', 'message', 'dashboard_url', 'ticket_url'],
        'default_subject' => '[{{ticket_ref}}] New {{priority_upper}} ticket - {{subject}}',
        'default_body' => "<p><strong>Ref:</strong> {{ticket_ref}}</p>\n<p><strong>From:</strong> {{from_name}} ({{from_email}})</p>\n<p><strong>Category:</strong> {{category}}</p>\n<p><strong>Priority:</strong> {{priority}}</p>\n<p><strong>Message:</strong></p>\n<blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>{{message_html}}</blockquote>\n<p><a href='{{dashboard_url}}' style='color:#a78bfa'>View in Support Dashboard →</a></p>",
    ],
    'ticket_confirmation' => [
        'label' => 'Ticket Confirmation',
        'subject_key' => 'email_tpl_ticket_confirmation_subject',
        'body_key' => 'email_tpl_ticket_confirmation_body',
        'description' => 'Sent to the user after a ticket is created.',
        'placeholders' => ['ticket_ref', 'subject', 'priority', 'ticket_url'],
        'default_subject' => '[{{ticket_ref}}] We received your ticket - {{subject}}',
        'default_body' => "<p>Thanks for reaching out. Your ticket has been created and our team will respond shortly.</p>\n<p><strong>Ticket ID:</strong> {{ticket_ref}}</p>\n<p><strong>Subject:</strong> {{subject}}</p>\n<p><strong>Priority:</strong> {{priority}}</p>\n<p>You can track your ticket at <a href='{{ticket_url}}' style='color:#a78bfa'>{{ticket_url}}</a></p>",
    ],
    'user_reply_admin' => [
        'label' => 'User Reply Alert',
        'subject_key' => 'email_tpl_user_reply_admin_subject',
        'body_key' => 'email_tpl_user_reply_admin_body',
        'description' => 'Sent to the support inbox when a user replies on a ticket.',
        'placeholders' => ['ticket_ref', 'subject', 'message', 'dashboard_url'],
        'default_subject' => '[{{ticket_ref}}] User replied - {{subject}}',
        'default_body' => "<p><strong>Ticket:</strong> {{ticket_ref}}</p>\n<blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>{{message_html}}</blockquote>\n<p><a href='{{dashboard_url}}' style='color:#a78bfa'>View Ticket →</a></p>",
    ],
    'agent_reply_user' => [
        'label' => 'Support Reply',
        'subject_key' => 'email_tpl_agent_reply_user_subject',
        'body_key' => 'email_tpl_agent_reply_user_body',
        'description' => 'Sent to the user when an agent responds.',
        'placeholders' => ['ticket_ref', 'subject', 'agent_name', 'message', 'ticket_url'],
        'default_subject' => '[{{ticket_ref}}] Support reply - {{subject}}',
        'default_body' => "<p><strong>{{agent_name}}</strong> replied to your ticket:</p>\n<blockquote style='border-left:3px solid #7c3aed;padding-left:12px;margin:8px 0'>{{message_html}}</blockquote>\n<p><a href='{{ticket_url}}' style='color:#a78bfa'>View &amp; Reply →</a></p>",
    ],
    'data_deletion_completed' => [
        'label' => 'Data Deletion Completed',
        'subject_key' => 'email_tpl_data_deletion_completed_subject',
        'body_key' => 'email_tpl_data_deletion_completed_body',
        'description' => 'Sent after an account deletion request is completed.',
        'placeholders' => ['ticket_ref', 'username', 'support_email'],
        'default_subject' => '[{{ticket_ref}}] Your data has been deleted',
        'default_body' => "<p>Hi {{username}},</p>\n<p>Your data deletion request has been completed by our support team.</p>\n<p><strong>Ticket:</strong> {{ticket_ref}}</p>\n<p>If you did not request this change, contact support immediately at {{support_email}}.</p>",
    ],
    'registration_welcome' => [
        'label' => 'Registration Welcome',
        'subject_key' => 'email_tpl_registration_welcome_subject',
        'body_key' => 'email_tpl_registration_welcome_body',
        'description' => 'Sent when a new user registers an account.',
        'placeholders' => ['username', 'email', 'login_url', 'support_email'],
        'default_subject' => 'Welcome to Lyralink, {{username}}',
        'default_body' => "<p>Hi {{username}},</p>\n<p>Your Lyralink account has been created for {{email}}.</p>\n<p>You can sign in at <a href='{{login_url}}' style='color:#a78bfa'>{{login_url}}</a> once your email is verified.</p>\n<p>If you need help, contact {{support_email}}.</p>",
    ],
    'email_verification' => [
        'label' => 'Email Verification Code',
        'subject_key' => 'email_tpl_email_verification_subject',
        'body_key' => 'email_tpl_email_verification_body',
        'description' => 'Sent when a user needs to verify their email address.',
        'placeholders' => ['username', 'code', 'expires_minutes', 'support_email'],
        'default_subject' => 'Verify your Lyralink account',
        'default_body' => "<p>Hi {{username}}, use this code to verify your account:</p>\n<div style='font-size:28px;letter-spacing:4px;font-weight:700;color:#ffffff;background:#1e293b;padding:12px 16px;border-radius:8px;display:inline-block'>{{code}}</div>\n<p style='margin:12px 0 0;color:#94a3b8'>This code expires in {{expires_minutes}} minutes.</p>\n<p style='margin:12px 0 0'>If you did not request this, contact {{support_email}}.</p>",
    ],
    'ticket_closed' => [
        'label' => 'Ticket Closed Or Resolved',
        'subject_key' => 'email_tpl_ticket_closed_subject',
        'body_key' => 'email_tpl_ticket_closed_body',
        'description' => 'Sent when support resolves or closes a ticket.',
        'placeholders' => ['ticket_ref', 'subject', 'status', 'status_label', 'agent_name', 'ticket_url'],
        'default_subject' => '[{{ticket_ref}}] Ticket {{status_label}} - {{subject}}',
        'default_body' => "<p>Your support ticket <strong>{{ticket_ref}}</strong> has been marked <strong>{{status_label}}</strong> by {{agent_name}}.</p>\n<p>If you still need help, you can reopen the conversation by replying here: <a href='{{ticket_url}}' style='color:#a78bfa'>{{ticket_url}}</a></p>",
    ],
];

function ensureSupportQueueTable($db) {
    static $ensured = false;
    if ($ensured) {
        return;
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

    $ensured = true;
}

function ensureDiscordTranscriptTable($db) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db->query("CREATE TABLE IF NOT EXISTS discord_ticket_transcripts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ticket_ref VARCHAR(40) NOT NULL,
        channel_id VARCHAR(64) NOT NULL,
        channel_name VARCHAR(120) DEFAULT NULL,
        guild_id VARCHAR(64) DEFAULT NULL,
        opened_by VARCHAR(120) DEFAULT NULL,
        opened_by_id VARCHAR(64) DEFAULT NULL,
        category VARCHAR(64) DEFAULT NULL,
        closed_by VARCHAR(120) DEFAULT NULL,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        transcript LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ticket_ref (ticket_ref),
        KEY idx_channel_id (channel_id),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

// ── HELPERS ──
function genTicketRef() {
    return 'TKT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

function support_ws_secret(): string {
    $secret = trim((string)api_get_secret('SUPPORT_WS_SECRET', ''));
    if ($secret !== '') {
        return $secret;
    }
    $fallback = trim((string)api_get_secret('BOT_SECRET_KEY', ''));
    return $fallback !== '' ? $fallback : 'lyralink-support-ws-secret';
}

function support_base64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function support_ws_token(array $payload): string {
    $encoded = support_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $encoded, support_ws_secret());
    return $encoded . '.' . $sig;
}

function support_public_ws_url(): string {
    $configured = trim((string)api_get_secret('SUPPORT_WS_URL', ''));
    if ($configured !== '') {
        return $configured;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'lyralinkai.com';
    if (netpolicy_is_local_host((string)$host)) {
        return 'ws://' . $host . '/ws/support';
    }
    return 'wss://' . $host . '/ws/support';
}

function support_ai_model(): string {
    $configured = trim((string)api_get_secret('SUPPORT_AI_MODEL', ''));
    if ($configured !== '') {
        return $configured;
    }
    return trim((string)api_get_secret('LLM_MODEL', 'llama-3.3-70b-versatile')) ?: 'llama-3.3-70b-versatile';
}

function support_ai_call(array $messages, int $maxTokens = 1400, float $temperature = 0.2): ?string {
    $apiKey = trim((string)api_get_secret('GROQ_API_KEY', ''));
    if ($apiKey === '') {
        return null;
    }

    $payload = [
        'model' => support_ai_model(),
        'messages' => $messages,
        'max_tokens' => max(256, min(3000, $maxTokens)),
        'temperature' => $temperature,
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 75);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded['choices'][0]['message']['content'] ?? null;
}

function support_pick_live_agent_id(mysqli $db): ?int {
    $sql = "SELECT a.id
            FROM support_agents a
            WHERE a.active = 1
              AND a.last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            ORDER BY (
                SELECT COUNT(*)
                FROM support_tickets t
                WHERE t.assigned_to = a.id
                  AND t.status NOT IN ('resolved','closed')
            ) ASC,
            a.last_seen DESC
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row || !isset($row['id'])) {
        return null;
    }
    return (int)$row['id'];
}

function getConfig($db, $key) {
    $stmt = $db->prepare("SELECT `value` FROM support_config WHERE `key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    return $r ? ($r->fetch_assoc()['value'] ?? '') : '';
}

function support_upsert_config(mysqli $db, string $key, string $value): bool {
    $stmt = $db->prepare("INSERT INTO support_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function support_base_url(): string {
    $configured = trim((string)api_get_secret('APP_BASE_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'lyralinkai.com'));
    if ($host === '') {
        $host = 'lyralinkai.com';
    }
    if (netpolicy_is_local_host($host)) {
        return 'http://' . $host;
    }
    return 'https://' . $host;
}

function support_template_catalog(): array {
    return SUPPORT_EMAIL_TEMPLATES;
}

function support_template_definition(string $templateId): ?array {
    $templates = support_template_catalog();
    return $templates[$templateId] ?? null;
}

function support_template_defaults(string $templateId): array {
    $def = support_template_definition($templateId);
    if (!$def) {
        return ['subject' => '', 'body' => ''];
    }
    return [
        'subject' => (string)($def['default_subject'] ?? ''),
        'body' => (string)($def['default_body'] ?? ''),
    ];
}

function support_render_template_markup(string $template, array $vars): string {
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function (array $matches) use ($vars): string {
        $key = strtolower((string)($matches[1] ?? ''));
        return array_key_exists($key, $vars) ? (string)$vars[$key] : '';
    }, $template) ?? $template;
}

function support_template_vars(array $vars): array {
    $normalized = [];
    foreach ($vars as $key => $value) {
        $normalized[strtolower((string)$key)] = (string)$value;
    }
    $normalized['dashboard_url'] = $normalized['dashboard_url'] ?? support_base_url() . '/pages/support_admin/';
    $normalized['ticket_url'] = $normalized['ticket_url'] ?? support_base_url() . '/pages/support/';
    $normalized['login_url'] = $normalized['login_url'] ?? support_base_url() . '/chat';
    $normalized['priority_upper'] = strtoupper($normalized['priority'] ?? '');
    $normalized['status_label'] = $normalized['status_label'] ?? ucwords(str_replace('_', ' ', $normalized['status'] ?? ''));
    $normalized['message_html'] = nl2br(htmlspecialchars($normalized['message'] ?? '', ENT_QUOTES, 'UTF-8'));
    return $normalized;
}

function support_render_email_content(string $subjectTemplate, string $bodyTemplate, array $vars = [], ?string $ticketRef = null, ?string $titleOverride = null): array {
    $renderVars = support_template_vars($vars);
    $subject = support_render_template_markup($subjectTemplate, $renderVars);
    $body = support_render_template_markup($bodyTemplate, $renderVars);
    return [
        'subject' => $subject,
        'html' => emailTemplate($titleOverride !== null && $titleOverride !== '' ? $titleOverride : $subject, $body, $ticketRef),
        'body' => $body,
    ];
}

function support_template_content(mysqli $db, string $templateId): array {
    $def = support_template_definition($templateId);
    if (!$def) {
        return ['subject' => '', 'body' => ''];
    }
    $defaults = support_template_defaults($templateId);
    $subject = trim((string)getConfig($db, (string)$def['subject_key']));
    $body = trim((string)getConfig($db, (string)$def['body_key']));
    return [
        'subject' => $subject !== '' ? $subject : $defaults['subject'],
        'body' => $body !== '' ? $body : $defaults['body'],
    ];
}

function support_email_templates_payload(mysqli $db): array {
    $payload = [];
    foreach (support_template_catalog() as $id => $def) {
        $content = support_template_content($db, $id);
        $payload[$id] = [
            'label' => $def['label'],
            'description' => $def['description'],
            'placeholders' => array_values($def['placeholders']),
            'subject' => $content['subject'],
            'body' => $content['body'],
            'default_subject' => $def['default_subject'],
            'default_body' => $def['default_body'],
        ];
    }
    return $payload;
}

function support_render_email_template(mysqli $db, string $templateId, array $vars = [], ?string $ticketRef = null, ?string $titleOverride = null): array {
    $content = support_template_content($db, $templateId);
    return support_render_email_content($content['subject'], $content['body'], $vars, $ticketRef, $titleOverride);
}

function support_config_payload(mysqli $db): array {
    $smtpFields = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','support_email'];
    $discordFields = ['discord_webhook_url'];
    $config = [];
    foreach (array_merge($smtpFields, $discordFields) as $field) {
        $config[$field] = getConfig($db, $field);
    }

    $roles = [
        'low' => ['role_id' => '', 'role_name' => ''],
        'medium' => ['role_id' => '', 'role_name' => ''],
        'high' => ['role_id' => '', 'role_name' => ''],
        'critical' => ['role_id' => '', 'role_name' => ''],
    ];
    $stmt = $db->prepare("SELECT priority, role_id, role_name FROM support_discord_roles");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $priority = (string)($row['priority'] ?? '');
            if (isset($roles[$priority])) {
                $roles[$priority] = [
                    'role_id' => (string)($row['role_id'] ?? ''),
                    'role_name' => (string)($row['role_name'] ?? ''),
                ];
            }
        }
        $stmt->close();
    }

    return [
        'smtp' => [
            'smtp_host' => $config['smtp_host'] ?? '',
            'smtp_port' => $config['smtp_port'] ?? '',
            'smtp_user' => $config['smtp_user'] ?? '',
            'smtp_pass' => $config['smtp_pass'] ?? '',
            'smtp_from' => $config['smtp_from'] ?? '',
            'support_email' => $config['support_email'] ?? '',
        ],
        'discord' => [
            'webhook_url' => $config['discord_webhook_url'] ?? '',
            'roles' => $roles,
        ],
        'email_templates' => support_email_templates_payload($db),
    ];
}

function finishJson(array $payload) {
    echo json_encode($payload);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) {
            @ob_end_flush();
        }
        flush();
    }
}

function queueNotification($db, $channel, array $payload) {
    ensureSupportQueueTable($db);

    $json = json_encode($payload);
    if ($json === false) {
        return false;
    }

    $stmt = $db->prepare("INSERT INTO support_notification_queue (channel, payload) VALUES (?, ?)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $channel, $json);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function triggerNotificationWorker() {
    static $triggered = false;
    if ($triggered) {
        return;
    }

    $script = escapeshellarg(__DIR__ . '/../cron/support_notifications.php');
    $candidateBins = [];
    if (defined('PHP_BINDIR')) {
        $candidateBins[] = PHP_BINDIR . '/php';
    }
    if (PHP_BINARY) {
        $candidateBins[] = PHP_BINARY;
    }
    $candidateBins[] = '/usr/bin/php';
    $candidateBins[] = 'php';

    $phpBin = 'php';
    foreach ($candidateBins as $candidate) {
        if ($candidate && @is_executable($candidate) && stripos(basename($candidate), 'php-fpm') === false) {
            $phpBin = $candidate;
            break;
        }
    }

    $phpBin = escapeshellcmd($phpBin);
    @exec("$phpBin $script > /dev/null 2>&1 &");
    $triggered = true;
}

function sendEmail($db, $to, $subject, $htmlBody) {
    $smtpHost = getConfig($db, 'smtp_host');
    $smtpPort = (int)getConfig($db, 'smtp_port');
    $smtpUser = getConfig($db, 'smtp_user');
    $smtpPass = getConfig($db, 'smtp_pass');
    $fromAddr = getConfig($db, 'smtp_from');

    if (!$smtpUser || !$smtpPass) {
        // Fallback to PHP mail()
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: Lyralink Support <$fromAddr>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit";
        return mail($to, $subject, $htmlBody, $headers);
    }

    // PHPMailer via composer — if available
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->Timeout    = 5;

        if ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpPort === 587) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddr, 'Lyralink Support');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

function sendDiscordWebhook($db, $ticket, $agentName = null) {
    $webhookUrl = getConfig($db, 'discord_webhook_url');
    if (!$webhookUrl) return;
    $webhookCheck = netpolicy_validate_outbound_url((string)$webhookUrl, false);
    if (!$webhookCheck['ok']) return;

    $priorityColors = ['low' => 3066993, 'medium' => 16776960, 'high' => 15105570, 'critical' => 15158332];
    $priorityEmoji  = ['low' => '🟢', 'medium' => '🟡', 'high' => '🟠', 'critical' => '🔴'];
    $categoryLabels = ['general' => 'General Support', 'billing' => 'Billing', 'bug_report' => 'Bug Report', 'account' => 'Account Issue', 'live_chat' => 'Live Chat'];

    $priority = $ticket['user_priority'];
    $color    = $priorityColors[$priority] ?? 3447003;
    $emoji    = $priorityEmoji[$priority]  ?? '⚪';
    $cat      = $categoryLabels[$ticket['category']] ?? $ticket['category'];

    // Get Discord role ping for this priority
    $stmt = $db->prepare("SELECT role_id FROM support_discord_roles WHERE priority = ?");
    $stmt->bind_param('s', $priority);
    $stmt->execute();
    $pRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ping = $pRow ? "<@&{$pRow['role_id']}>" : '';

    $payload = [
        'content' => $ping ? "$ping — New {$emoji} **" . strtoupper($priority) . "** ticket" : null,
        'embeds'  => [[
            'title'       => "🎫 [{$ticket['ticket_ref']}] " . substr($ticket['subject'], 0, 80),
            'description' => substr($ticket['body'], 0, 300) . (strlen($ticket['body']) > 300 ? '...' : ''),
            'color'       => $color,
            'fields'      => [
                ['name' => 'Category', 'value' => $cat,       'inline' => true],
                ['name' => 'Priority', 'value' => "$emoji " . ucfirst($priority), 'inline' => true],
                ['name' => 'Status',   'value' => '🟣 Open',  'inline' => true],
                ['name' => 'From',     'value' => $ticket['guest_name'] ?? $ticket['username'] ?? 'Guest', 'inline' => true],
            ],
            'footer'      => ['text' => 'Lyralink Support · ' . date('M j, Y g:i A')],
            'url'         => 'https://lyralinkai.com/pages/support_admin/',
        ]],
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function emailTemplate($title, $body, $ticketRef = null) {
    $ref = $ticketRef ? "<p style='color:#a78bfa;font-size:12px'>Ticket: $ticketRef</p>" : '';
    return "
    <div style='font-family:monospace;background:#0a0a0f;color:#e2e8f0;padding:32px;max-width:560px;margin:0 auto;border-radius:16px;border:1px solid #1e1e2e'>
        <div style='margin-bottom:24px'>
            <h2 style='font-family:sans-serif;color:#a78bfa;margin:0 0 4px'>Lyralink Support</h2>
            $ref
        </div>
        <h3 style='color:#e2e8f0;margin:0 0 16px'>$title</h3>
        <div style='color:#94a3b8;line-height:1.7;font-size:13px'>$body</div>
        <hr style='border:none;border-top:1px solid #1e1e2e;margin:24px 0'>
        <p style='color:#334155;font-size:11px'>Lyralink Support Team <a href='https://lyralinkai.com/pages/support/' style='color:#7c3aed'>View Ticket</a></p>
    </div>";
}
function support_generate_temp_password(): string {
        // 12-character password: letters + digits, easy to read (no ambiguous chars like 0/O/1/l)
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $pass = '';
        for ($i = 0; $i < 12; $i++) {
                $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
}

function support_build_password_reset_email(string $username, string $tempPassword): string {
        $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $p = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Password Reset</title></head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px">
    <tr><td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#111118;border:1px solid #1e1e2e;border-radius:16px;overflow:hidden;max-width:560px">

            <!-- Header -->
            <tr><td style="background:linear-gradient(135deg,#7c3aed 0%,#6d28d9 100%);padding:32px 40px;text-align:center">
                <div style="font-size:28px;font-weight:800;color:#fff;letter-spacing:-0.5px">Lyralink</div>
                <div style="font-size:13px;color:rgba(255,255,255,0.7);margin-top:4px">lyralinkai.com</div>
            </td></tr>

            <!-- Body -->
            <tr><td style="padding:36px 40px">
                <p style="margin:0 0 8px;font-size:22px;font-weight:700;color:#e2e8f0">Password Reset</p>
                <p style="margin:0 0 24px;font-size:14px;color:#64748b;line-height:1.6">
                    Hi <strong style="color:#a78bfa">{$u}</strong>, an administrator has reset your Lyralink account password.
                    Use the temporary password below to sign in, then you'll be asked to set a new password immediately.
                </p>

                <!-- Temp password box -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
                    <tr><td style="background:#0a0a0f;border:1px solid #7c3aed;border-radius:10px;padding:18px 24px;text-align:center">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Temporary Password</div>
                        <div style="font-size:22px;font-weight:700;color:#a78bfa;letter-spacing:3px;font-family:'Courier New',monospace">{$p}</div>
                    </td></tr>
                </table>

                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                    <tr><td align="center">
                        <a href="https://lyralinkai.com/chat" style="display:inline-block;background:#7c3aed;color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:14px;font-weight:600">Sign in to Lyralink →</a>
                    </td></tr>
                </table>

                <p style="margin:0 0 8px;font-size:13px;color:#64748b;line-height:1.6">
                    For your security, this temporary password will only work once — you'll be prompted to create a new password immediately after signing in.
                </p>
                <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6">
                    If you didn't expect this, please contact our support team immediately.
                </p>
            </td></tr>

            <!-- Footer -->
            <tr><td style="padding:20px 40px 28px;border-top:1px solid #1e1e2e;text-align:center">
                <p style="margin:0;font-size:11px;color:#475569">© 2026 LyralinkAI · Lyralink · <a href="https://lyralinkai.com/privacy" style="color:#7c3aed;text-decoration:none">Privacy Policy</a></p>
            </td></tr>

        </table>
    </td></tr>
</table>
</body>
</html>
HTML;
}

function support_delete_user_bound_rows(mysqli $db, string $table, int $userId): int {
    $allowed = [
        'api_keys',
        'chat_history',
        'email_verification_codes',
        'security_log',
        'transactions',
        'user_2fa_recovery_codes',
        'user_convs',
        'user_yubikeys',
        'user_data_deletion_requests',
    ];
    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $existsStmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$existsStmt) {
        return 0;
    }
    $existsStmt->bind_param('s', $table);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()->fetch_assoc();
    $existsStmt->close();
    if (!$exists) {
        return 0;
    }

    $sql = "DELETE FROM `{$table}` WHERE user_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $deleted = max(0, (int)$stmt->affected_rows);
    $stmt->close();
    return $deleted;
}

// ── AGENT AUTH CHECK ──
function requireAgent($db) {
    if (empty($_SESSION['agent_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated as agent']); exit;
    }
    $id = (int)$_SESSION['agent_id'];
    $stmt = $db->prepare("SELECT * FROM support_agents WHERE id = ? AND active = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $agent = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    if (!$agent) { echo json_encode(['success' => false, 'error' => 'Agent not found']); exit; }
    return $agent;
}

function requireRole($agent, $minRole) {
    $roles = ['trial_agent' => 0, 'agent' => 1, 'senior_agent' => 2, 'admin' => 3];
    $agentLevel = $roles[$agent['role']] ?? 0;
    $minLevel   = $roles[$minRole]       ?? 0;
    if ($agentLevel < $minLevel) {
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']); exit;
    }
}

function support_role_level(string $role): int {
    $roles = ['trial_agent' => 0, 'agent' => 1, 'senior_agent' => 2, 'admin' => 3];
    return $roles[$role] ?? 0;
}

function support_user_manager_permissions(array $agent): array {
    $level = support_role_level((string)($agent['role'] ?? 'trial_agent'));
    return [
        'role' => (string)($agent['role'] ?? 'trial_agent'),
        'can_view_users' => $level >= 1,
        'can_edit_profile' => $level >= 2,
        'can_edit_billing' => $level >= 2,
        'can_edit_security' => $level >= 3,
        'can_delete_users' => $level >= 3,
    ];
}

function support_plan_options(): array {
    return ['free', 'basic', 'pro', 'enterprise'];
}

function ensureSupportAuditTable(mysqli $db): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db->query("CREATE TABLE IF NOT EXISTS support_agent_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        agent_id INT UNSIGNED NOT NULL DEFAULT 0,
        target_user_id INT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(80) NOT NULL,
        details LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_target_user (target_user_id, created_at),
        KEY idx_agent (agent_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function support_audit_log(mysqli $db, array $agent, string $action, int $targetUserId = 0, array $details = []): void {
    ensureSupportAuditTable($db);
    $stmt = $db->prepare("INSERT INTO support_agent_audit_log (agent_id, target_user_id, action, details) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $agentId = (int)($agent['id'] ?? 0);
    $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $stmt->bind_param('iiss', $agentId, $targetUserId, $action, $json);
    $stmt->execute();
    $stmt->close();
}

// ════════════════════════════════
// PUBLIC ACTIONS
// ════════════════════════════════

// ── CREATE TICKET ──
if ($action === 'create_ticket') {
    $subject  = trim($_POST['subject']   ?? '');
    $body     = trim($_POST['body']      ?? '');
    $category = $_POST['category']       ?? 'general';
    $priority = $_POST['priority']       ?? 'medium';
    $name     = trim($_POST['name']      ?? '');
    $email    = trim($_POST['email']     ?? '');

    $validCats  = ['general','billing','bug_report','account','live_chat'];
    $validPris  = ['low','medium','high','critical'];
    if (!$subject || !$body)                  { echo json_encode(['success' => false, 'error' => 'Subject and message are required']); exit; }
    if (!in_array($category, $validCats))     { echo json_encode(['success' => false, 'error' => 'Invalid category']); exit; }
    if (!in_array($priority, $validPris))     { echo json_encode(['success' => false, 'error' => 'Invalid priority']); exit; }

    $userId    = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $guestName = $userId ? null : ($name ?: 'Guest');
    $guestEmail= $userId ? null : ($email ?: null);

    if (!$userId && !$guestEmail) { echo json_encode(['success' => false, 'error' => 'Email required for guest tickets']); exit; }

    // Get user email if logged in
    $userEmail = null;
    if ($userId) {
        $stmt = $db->prepare("SELECT email, username FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ud = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $userEmail = $ud['email'] ?? null;
    }

    $ref = genTicketRef();
    $stmt = $db->prepare("SELECT id FROM support_tickets WHERE ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $refResult = $stmt->get_result();
    while ($refResult->num_rows > 0) {
        $ref = genTicketRef();
        $stmt->close();
        $stmt = $db->prepare("SELECT id FROM support_tickets WHERE ticket_ref = ?");
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $refResult = $stmt->get_result();
    }
    $stmt->close();

    $assignedAgentId = support_pick_live_agent_id($db);
    $initialStatus = $assignedAgentId ? 'in_progress' : 'open';

    $stmt = $db->prepare("INSERT INTO support_tickets (ticket_ref, user_id, guest_email, guest_name, category, subject, body, user_priority, assigned_to, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('siisssssis', $ref, $userId, $guestEmail, $guestName, $category, $subject, $body, $priority, $assignedAgentId, $initialStatus);
    $stmt->execute();
    $ticketId = $db->insert_id;
    $stmt->close();

    if ($assignedAgentId) {
        $systemNote = 'Ticket auto-assigned to an available support agent for live chat.';
        $noteStmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 0)");
        if ($noteStmt) {
            $noteStmt->bind_param('is', $ticketId, $systemNote);
            $noteStmt->execute();
            $noteStmt->close();
        }
    }

    // Fetch full ticket for notifications
    $stmt = $db->prepare("SELECT t.*, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    finishJson(['success' => true, 'ticket_ref' => $ref, 'ticket_id' => $ticketId]);

    // Queue support email
    $supportEmail = getConfig($db, 'support_email');
    $fromName     = $ticket['guest_name'] ?? $ticket['username'] ?? 'Logged-in User';
    $fromEmail    = $guestEmail ?? $userEmail ?? 'unknown';
    $adminEmail = support_render_email_template($db, 'new_ticket_admin', [
        'ticket_ref' => $ref,
        'priority' => $priority,
        'category' => $category,
        'from_name' => $fromName,
        'from_email' => $fromEmail,
        'subject' => $subject,
        'message' => $body,
    ], $ref, 'New Support Ticket');
    queueNotification($db, 'email', [
        'to' => $supportEmail,
        'subject' => $adminEmail['subject'],
        'html' => $adminEmail['html'],
    ]);

    // Queue user confirmation email
    $confirmTo = $guestEmail ?? $userEmail;
    if ($confirmTo) {
        $confirmEmail = support_render_email_template($db, 'ticket_confirmation', [
            'ticket_ref' => $ref,
            'subject' => $subject,
            'priority' => $priority,
        ], $ref, "We've got your ticket!");
        queueNotification($db, 'email', [
            'to' => $confirmTo,
            'subject' => $confirmEmail['subject'],
            'html' => $confirmEmail['html'],
        ]);
    }

    queueNotification($db, 'discord', ['ticket' => $ticket]);
    triggerNotificationWorker();
    exit;
}

// ── GET USER'S TICKETS ──
if ($action === 'my_tickets') {
    $userId = $_SESSION['user_id'] ?? null;
    $email  = trim($_POST['email'] ?? '');

    if (!$userId && !$email) { echo json_encode(['success' => false, 'error' => 'Not identified']); exit; }

    if ($userId) {
        $stmt = $db->prepare("SELECT id, ticket_ref, category, subject, status, user_priority, agent_priority, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $db->prepare("SELECT id, ticket_ref, category, subject, status, user_priority, agent_priority, created_at, updated_at FROM support_tickets WHERE guest_email = ? ORDER BY updated_at DESC LIMIT 20");
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $tickets = [];
    while ($r = $result->fetch_assoc()) $tickets[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'tickets' => $tickets]);
    exit;
}

// ── GET ACTIVE LIVE TICKET (auto-connect) ──
if ($action === 'live_ticket') {
    $userId = $_SESSION['user_id'] ?? null;
    $email = trim((string)($_POST['email'] ?? $_GET['email'] ?? ''));

    if (!$userId && $email === '') {
        echo json_encode(['success' => false, 'error' => 'Not identified']);
        exit;
    }

    if ($userId) {
        $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN support_agents a ON a.id = t.assigned_to
            WHERE t.user_id = ?
              AND t.status NOT IN ('resolved','closed')
            ORDER BY t.updated_at DESC
            LIMIT 1");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN support_agents a ON a.id = t.assigned_to
            WHERE t.guest_email = ?
              AND t.status NOT IN ('resolved','closed')
            ORDER BY t.updated_at DESC
            LIMIT 1");
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$ticket) {
        echo json_encode(['success' => true, 'ticket' => null, 'replies' => []]);
        exit;
    }

    $ticketId = (int)($ticket['id'] ?? 0);
    $replies = [];
    $replyStmt = $db->prepare("SELECT r.*, a.username as agent_name
        FROM support_ticket_replies r
        LEFT JOIN support_agents a ON a.id = r.agent_id
        WHERE r.ticket_id = ?
          AND (r.internal = 0 OR r.internal IS NULL)
        ORDER BY r.created_at ASC");
    $replyStmt->bind_param('i', $ticketId);
    $replyStmt->execute();
    $rResult = $replyStmt->get_result();
    while ($row = $rResult->fetch_assoc()) {
        $replies[] = $row;
    }
    $replyStmt->close();

    $agentLive = false;
    if (!empty($ticket['assigned_to'])) {
        $assignedId = (int)$ticket['assigned_to'];
        $aliveStmt = $db->prepare("SELECT 1 FROM support_agents WHERE id = ? AND active = 1 AND last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1");
        if ($aliveStmt) {
            $aliveStmt->bind_param('i', $assignedId);
            $aliveStmt->execute();
            $agentLive = (bool)$aliveStmt->get_result()->fetch_assoc();
            $aliveStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'replies' => $replies,
        'live' => [
            'assigned_agent_online' => $agentLive,
            'assigned_agent_name' => (string)($ticket['agent_name'] ?? ''),
        ],
    ]);
    exit;
}

// ── GET SINGLE TICKET (user view) ──
if ($action === 'get_ticket') {
    $ref    = trim($_POST['ref'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$ref) { echo json_encode(['success' => false, 'error' => 'Ticket reference required']); exit; }

    $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN support_agents a ON a.id = t.assigned_to WHERE t.ticket_ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $owned = ($userId && (int)$ticket['user_id'] === (int)$userId) || ($email && strcasecmp((string)$ticket['guest_email'], $email) === 0);
    if (!$owned) { echo json_encode(['success' => false, 'error' => 'Access denied']); exit; }

    $replies = [];
    $replyStmt = $db->prepare("SELECT r.*, a.username as agent_name FROM support_ticket_replies r LEFT JOIN support_agents a ON a.id = r.agent_id WHERE r.ticket_id = ? AND (r.internal = 0 OR r.internal IS NULL) ORDER BY r.created_at ASC");
    $ticketId = (int)$ticket['id'];
    $replyStmt->bind_param('i', $ticketId);
    $replyStmt->execute();
    $rResult = $replyStmt->get_result();
    while ($row = $rResult->fetch_assoc()) {
        $replies[] = $row;
    }
    $replyStmt->close();

    echo json_encode(['success' => true, 'ticket' => $ticket, 'replies' => $replies]);
    exit;
}

if ($action === 'ws_auth_user') {
    $ref    = trim($_POST['ref'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if ($ref === '') {
        echo json_encode(['success' => false, 'error' => 'Ticket reference required']);
        exit;
    }

    $stmt = $db->prepare("SELECT t.id, t.ticket_ref, t.user_id, t.guest_email, t.status FROM support_tickets t WHERE t.ticket_ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $owned = ($userId && (int)$ticket['user_id'] === (int)$userId) || ($email !== '' && strcasecmp((string)$ticket['guest_email'], $email) === 0);
    if (!$owned) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    if (in_array((string)$ticket['status'], ['resolved', 'closed'], true)) {
        echo json_encode(['success' => false, 'error' => 'Ticket is closed']);
        exit;
    }

    $token = support_ws_token([
        'role' => 'user',
        'ticket_ref' => (string)$ticket['ticket_ref'],
        'uid' => (int)($ticket['user_id'] ?? 0),
        'exp' => time() + 900,
    ]);
    echo json_encode(['success' => true, 'url' => support_public_ws_url(), 'token' => $token, 'expires_in' => 900]);
    exit;
}

// ── USER REPLY ──
if ($action === 'user_reply') {
    $ref     = trim($_POST['ref'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $userId  = $_SESSION['user_id']   ?? null;
    $email   = trim($_POST['email']   ?? '');

    if (!$message) { echo json_encode(['success' => false, 'error' => 'Message required']); exit; }

    $stmt = $db->prepare("SELECT * FROM support_tickets WHERE ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $owned = ($userId && $ticket['user_id'] == $userId) || ($email && $ticket['guest_email'] === $email);
    if (!$owned) { echo json_encode(['success' => false, 'error' => 'Access denied']); exit; }
    if (in_array($ticket['status'], ['resolved','closed'])) { echo json_encode(['success' => false, 'error' => 'Ticket is closed']); exit; }

    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message) VALUES (?, 'user', ?)");
    $stmt->bind_param('is', $ticket['id'], $message);
    $stmt->execute();
    $stmt->close();

    $ticketId = (int)$ticket['id'];
    $assignedAgentId = isset($ticket['assigned_to']) ? (int)$ticket['assigned_to'] : 0;
    if ($assignedAgentId <= 0) {
        $picked = support_pick_live_agent_id($db);
        if ($picked !== null) {
            $assignedAgentId = $picked;
        }
    }

    $nextStatus = $assignedAgentId > 0 ? 'in_progress' : 'open';
    $stmt = $db->prepare("UPDATE support_tickets
        SET status = ?,
            assigned_to = CASE WHEN assigned_to IS NULL OR assigned_to = 0 THEN ? ELSE assigned_to END,
            updated_at = NOW()
        WHERE id = ?");
    $stmt->bind_param('sii', $nextStatus, $assignedAgentId, $ticketId);
    $stmt->execute();
    $stmt->close();

    finishJson(['success' => true]);

    // Queue support reply notification
    $supportEmail = getConfig($db, 'support_email');
    $replyEmail = support_render_email_template($db, 'user_reply_admin', [
        'ticket_ref' => $ref,
        'subject' => $ticket['subject'],
        'message' => $message,
    ], $ref, 'User Reply on Ticket');
    queueNotification($db, 'email', [
        'to' => $supportEmail,
        'subject' => $replyEmail['subject'],
        'html' => $replyEmail['html'],
    ]);

    triggerNotificationWorker();

    exit;
}

// ── AGENT ONLINE COUNT ──
if ($action === 'agent_count') {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_agents WHERE last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1");
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
    $stmt->close();
    echo json_encode(['success' => true, 'count' => (int)$count]);
    exit;
}

// ════════════════════════════════
// AGENT ACTIONS
// ════════════════════════════════

// ── AGENT LOGIN ──
if ($action === 'agent_login') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $stmt = $db->prepare("SELECT * FROM support_agents WHERE email = ? AND active = 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $agent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($agent && password_verify($password, $agent['password_hash'])) {
        $_SESSION['agent_id']   = $agent['id'];
        $_SESSION['agent_role'] = $agent['role'];
        $updateStmt = $db->prepare("UPDATE support_agents SET last_seen = NOW() WHERE id = ?");
        $agentId = (int)$agent['id'];
        $updateStmt->bind_param('i', $agentId);
        $updateStmt->execute();
        $updateStmt->close();
        echo json_encode(['success' => true, 'id' => $agentId, 'role' => $agent['role'], 'username' => $agent['username']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

// ── AGENT LOGOUT ──
if ($action === 'agent_logout') {
    unset($_SESSION['agent_id'], $_SESSION['agent_role']);
    echo json_encode(['success' => true]);
    exit;
}

// ── AGENT HEARTBEAT ──
if ($action === 'heartbeat') {
    if (empty($_SESSION['agent_id'])) { echo json_encode(['success' => false]); exit; }
    $id = (int)$_SESSION['agent_id'];
    $stmt = $db->prepare("UPDATE support_agents SET last_seen = NOW() WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── GET TICKET LIST (agents) ──
if ($action === 'ticket_list') {
    $agent  = requireAgent($db);
    $status = trim($_GET['status'] ?? 'open');
    $cat    = trim($_GET['category'] ?? '');
    $pri    = trim($_GET['priority'] ?? '');
    $search = trim($_GET['search'] ?? '');

    $sql = "
        SELECT t.*, u.username, a.username as agent_name,
               (SELECT COUNT(*) FROM support_ticket_replies WHERE ticket_id = t.id) as reply_count
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN support_agents a ON a.id = t.assigned_to
        WHERE 1 = 1";
    $types = '';
    $params = [];
    if ($status !== 'all') {
        $sql .= " AND t.status = ?";
        $types .= 's';
        $params[] = $status;
    }
    if ($cat !== '') {
        $sql .= " AND t.category = ?";
        $types .= 's';
        $params[] = $cat;
    }
    if ($pri !== '') {
        $sql .= " AND COALESCE(t.agent_priority, t.user_priority) = ?";
        $types .= 's';
        $params[] = $pri;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (t.ticket_ref LIKE ? OR t.subject LIKE ?)";
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
    if ($agent['role'] === 'trial_agent' || $agent['role'] === 'agent') {
        $agentId = (int)$agent['id'];
        $sql .= " AND (t.assigned_to = ? OR t.assigned_to IS NULL)";
        $types .= 'i';
        $params[] = $agentId;
    }
    $sql .= "
        ORDER BY
            FIELD(COALESCE(t.agent_priority, t.user_priority), 'critical','high','medium','low'),
            t.updated_at DESC
        LIMIT 100";

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $tickets = [];
    while ($r = $result->fetch_assoc()) $tickets[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'tickets' => $tickets, 'agent' => ['role' => $agent['role'], 'username' => $agent['username']]]);
    exit;
}

// ── GET SINGLE TICKET (agent view) ──
if ($action === 'get_ticket_admin') {
    $agent = requireAgent($db);
    $ref   = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
    $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN support_agents a ON a.id = t.assigned_to WHERE t.ticket_ref = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

    $replies = [];
    $stmt = $db->prepare("SELECT r.*, a.username as agent_name FROM support_ticket_replies r LEFT JOIN support_agents a ON a.id = r.agent_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC");
    $ticketId = (int)$ticket['id'];
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $rResult = $stmt->get_result();
    while ($row = $rResult->fetch_assoc()) $replies[] = $row;
    $stmt->close();

    $agents = [];
    $aStmt = $db->prepare("SELECT id, username, role FROM support_agents WHERE active = 1 ORDER BY role, username");
    $aStmt->execute();
    $aResult = $aStmt->get_result();
    while ($row = $aResult->fetch_assoc()) $agents[] = $row;
    $aStmt->close();

    echo json_encode(['success' => true, 'ticket' => $ticket, 'replies' => $replies, 'agents' => $agents, 'viewer' => ['role' => $agent['role'], 'id' => $agent['id'], 'username' => $agent['username']]]);
    exit;
}

if ($action === 'workflow_summary') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $summary = [
        'open' => 0,
        'in_progress' => 0,
        'waiting' => 0,
        'resolved' => 0,
        'closed' => 0,
        'unassigned_open' => 0,
        'critical_open' => 0,
    ];

    $sql = "SELECT
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting_count,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN status IN ('open','in_progress') AND (assigned_to IS NULL OR assigned_to = 0) THEN 1 ELSE 0 END) AS unassigned_open_count,
                SUM(CASE WHEN status IN ('open','in_progress') AND COALESCE(agent_priority, user_priority) = 'critical' THEN 1 ELSE 0 END) AS critical_open_count
            FROM support_tickets";

    if (in_array((string)$agent['role'], ['trial_agent', 'agent'], true)) {
        $agentId = (int)$agent['id'];
        $sql .= " WHERE assigned_to = $agentId OR assigned_to IS NULL";
    }

    $row = $db->query($sql)->fetch_assoc();
    if ($row) {
        $summary['open'] = (int)($row['open_count'] ?? 0);
        $summary['in_progress'] = (int)($row['in_progress_count'] ?? 0);
        $summary['waiting'] = (int)($row['waiting_count'] ?? 0);
        $summary['resolved'] = (int)($row['resolved_count'] ?? 0);
        $summary['closed'] = (int)($row['closed_count'] ?? 0);
        $summary['unassigned_open'] = (int)($row['unassigned_open_count'] ?? 0);
        $summary['critical_open'] = (int)($row['critical_open_count'] ?? 0);
    }

    echo json_encode(['success' => true, 'summary' => $summary]);
    exit;
}

if ($action === 'claim_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ticket_id required']);
        exit;
    }

    $lookup = $db->prepare("SELECT id, ticket_ref, assigned_to, status FROM support_tickets WHERE id = ? LIMIT 1");
    $lookup->bind_param('i', $ticketId);
    $lookup->execute();
    $ticket = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    if (in_array((string)$ticket['status'], ['resolved', 'closed'], true)) {
        echo json_encode(['success' => false, 'error' => 'Ticket is closed']);
        exit;
    }

    $assignedTo = (int)($ticket['assigned_to'] ?? 0);
    $agentId = (int)$agent['id'];
    $canTakeOver = support_role_level((string)$agent['role']) >= support_role_level('senior_agent');
    if ($assignedTo > 0 && $assignedTo !== $agentId && !$canTakeOver) {
        echo json_encode(['success' => false, 'error' => 'Ticket is assigned to another agent']);
        exit;
    }

    $nextStatus = (string)$ticket['status'] === 'open' ? 'in_progress' : (string)$ticket['status'];
    $stmt = $db->prepare("UPDATE support_tickets SET assigned_to = ?, status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('isi', $agentId, $nextStatus, $ticketId);
    $stmt->execute();
    $stmt->close();

    $note = 'Ticket claimed by ' . (string)$agent['username'];
    $noteStmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
    $noteStmt->bind_param('is', $ticketId, $note);
    $noteStmt->execute();
    $noteStmt->close();

    echo json_encode(['success' => true, 'status' => $nextStatus, 'assigned_to' => $agentId]);
    exit;
}

if ($action === 'resolve_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $resolution = trim((string)($_POST['resolution'] ?? ''));
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ticket_id required']);
        exit;
    }

    $lookup = $db->prepare("SELECT t.*, u.email AS user_email, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    $lookup->bind_param('i', $ticketId);
    $lookup->execute();
    $ticket = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $stmt = $db->prepare("UPDATE support_tickets SET status = 'resolved', assigned_to = ?, updated_at = NOW(), closed_at = NOW() WHERE id = ?");
    $agentId = (int)$agent['id'];
    $stmt->bind_param('ii', $agentId, $ticketId);
    $stmt->execute();
    $stmt->close();

    $note = 'Ticket resolved by ' . (string)$agent['username'];
    if ($resolution !== '') {
        $note .= '. Resolution: ' . $resolution;
    }
    $noteStmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
    $noteStmt->bind_param('is', $ticketId, $note);
    $noteStmt->execute();
    $noteStmt->close();

    $toEmail = trim((string)($ticket['guest_email'] ?? $ticket['user_email'] ?? ''));
    if ($toEmail !== '') {
        $closedEmail = support_render_email_template($db, 'ticket_closed', [
            'ticket_ref' => (string)$ticket['ticket_ref'],
            'subject' => (string)$ticket['subject'],
            'status' => 'resolved',
            'status_label' => 'Resolved',
            'agent_name' => (string)$agent['username'],
        ], (string)$ticket['ticket_ref'], 'Ticket resolved');
        queueNotification($db, 'email', [
            'to' => $toEmail,
            'subject' => $closedEmail['subject'],
            'html' => $closedEmail['html'],
        ]);
        triggerNotificationWorker();
    }

    echo json_encode(['success' => true, 'status' => 'resolved']);
    exit;
}

if ($action === 'reopen_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ticket_id required']);
        exit;
    }

    $stmt = $db->prepare("UPDATE support_tickets SET status = 'open', closed_at = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected <= 0) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $note = 'Ticket reopened by ' . (string)$agent['username'];
    $noteStmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
    $noteStmt->bind_param('is', $ticketId, $note);
    $noteStmt->execute();
    $noteStmt->close();

    echo json_encode(['success' => true, 'status' => 'open']);
    exit;
}

if ($action === 'agent_ai_assist') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $ref = trim((string)($_POST['ref'] ?? ''));
    $question = trim((string)($_POST['question'] ?? ''));
    if ($ref === '' || $question === '') {
        echo json_encode(['success' => false, 'error' => 'Ticket reference and question are required']);
        exit;
    }
    if (strlen($question) > 1800) {
        echo json_encode(['success' => false, 'error' => 'Question is too long']);
        exit;
    }

    $stmt = $db->prepare("SELECT t.*, u.username, u.email as user_email, a.username as agent_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN support_agents a ON a.id = t.assigned_to WHERE t.ticket_ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    if (in_array((string)$agent['role'], ['trial_agent', 'agent'], true)) {
        $assignedTo = (int)($ticket['assigned_to'] ?? 0);
        if ($assignedTo > 0 && $assignedTo !== (int)$agent['id']) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

    $replies = [];
    $ticketId = (int)$ticket['id'];
    $replyStmt = $db->prepare("SELECT r.author_type, r.message, r.internal, r.created_at, a.username as agent_name FROM support_ticket_replies r LEFT JOIN support_agents a ON a.id = r.agent_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC LIMIT 40");
    $replyStmt->bind_param('i', $ticketId);
    $replyStmt->execute();
    $replyResult = $replyStmt->get_result();
    while ($row = $replyResult->fetch_assoc()) {
        $replies[] = $row;
    }
    $replyStmt->close();

    $conversation = [];
    $conversation[] = 'Ticket: ' . (string)$ticket['ticket_ref'];
    $conversation[] = 'Subject: ' . (string)$ticket['subject'];
    $conversation[] = 'Category: ' . (string)$ticket['category'] . ' | Priority: ' . (string)($ticket['agent_priority'] ?: $ticket['user_priority']) . ' | Status: ' . (string)$ticket['status'];
    $conversation[] = 'Initial user message: ' . (string)$ticket['body'];

    foreach ($replies as $reply) {
        $authorType = (string)($reply['author_type'] ?? 'user');
        if ($authorType === 'agent') {
            $author = 'agent:' . (string)($reply['agent_name'] ?: 'support');
            if ((int)($reply['internal'] ?? 0) === 1) {
                $author .= ' [internal]';
            }
        } elseif ($authorType === 'system') {
            $author = 'system';
        } else {
            $author = 'user';
        }
        $conversation[] = '[' . (string)($reply['created_at'] ?? '') . '] ' . $author . ': ' . (string)($reply['message'] ?? '');
    }

    $systemPrompt = 'You are a senior support triage assistant helping a customer support agent diagnose an issue. Be practical, concise, and safe. Prioritize likely root causes, quick verification steps, and a suggested reply the agent can send.';
    $userPrompt = "Agent question:\n" . $question . "\n\nTicket conversation:\n" . implode("\n", $conversation) . "\n\nReturn sections with short headings: Likely Cause, Checks, Fix Plan, Suggested Reply.";

    $answer = support_ai_call([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ], 1300, 0.2);

    if (!$answer) {
        echo json_encode(['success' => false, 'error' => 'AI assistant is unavailable right now']);
        exit;
    }

    echo json_encode(['success' => true, 'answer' => trim((string)$answer)]);
    exit;
}

if ($action === 'ws_auth_agent') {
    $agent = requireAgent($db);
    $ref = trim($_POST['ref'] ?? '');
    if ($ref === '') {
        echo json_encode(['success' => false, 'error' => 'Ticket reference required']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, ticket_ref, assigned_to, status FROM support_tickets WHERE ticket_ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    if (in_array((string)$agent['role'], ['trial_agent', 'agent'], true)) {
        $assignedTo = (int)($ticket['assigned_to'] ?? 0);
        if ($assignedTo > 0 && $assignedTo !== (int)$agent['id']) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

    if (in_array((string)$ticket['status'], ['resolved', 'closed'], true)) {
        echo json_encode(['success' => false, 'error' => 'Ticket is closed']);
        exit;
    }

    $token = support_ws_token([
        'role' => 'agent',
        'ticket_ref' => (string)$ticket['ticket_ref'],
        'aid' => (int)$agent['id'],
        'exp' => time() + 900,
    ]);

    echo json_encode(['success' => true, 'url' => support_public_ws_url(), 'token' => $token, 'expires_in' => 900]);
    exit;
}

// ── AGENT REPLY ──
if ($action === 'agent_reply') {
    $agent    = requireAgent($db);
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim($_POST['message']    ?? '');
    $internalRaw = strtolower(trim((string)($_POST['internal'] ?? '0')));
    $internal = in_array($internalRaw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;

    if (!$message) { echo json_encode(['success' => false, 'error' => 'Message required']); exit; }

    $stmt = $db->prepare("SELECT t.*, u.email as user_email, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) { echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit; }

    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, agent_id, message, internal) VALUES (?, 'agent', ?, ?, ?)");
    $stmt->bind_param('iisi', $ticketId, $agent['id'], $message, $internal);
    $stmt->execute();
    $stmt->close();

    if (!$internal) {
        $updateStmt = $db->prepare("UPDATE support_tickets SET status = 'waiting', updated_at = NOW() WHERE id = ?");
        $updateStmt->bind_param('i', $ticketId);
        $updateStmt->execute();
        $updateStmt->close();

        finishJson(['success' => true]);

        // Queue user notification email
        $toEmail = $ticket['guest_email'] ?? $ticket['user_email'];
        if ($toEmail) {
            $agentReplyEmail = support_render_email_template($db, 'agent_reply_user', [
                'ticket_ref' => $ticket['ticket_ref'],
                'subject' => $ticket['subject'],
                'agent_name' => $agent['username'],
                'message' => $message,
            ], (string)$ticket['ticket_ref'], 'New reply from support');
            queueNotification($db, 'email', [
                'to' => $toEmail,
                'subject' => $agentReplyEmail['subject'],
                'html' => $agentReplyEmail['html'],
            ]);
        }

        triggerNotificationWorker();
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

ensureSupportQueueTable($db);

// ── UPDATE TICKET (status, priority, assign) ──
if ($action === 'update_ticket') {
    $agent    = requireAgent($db);
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $field    = $_POST['field']  ?? '';
    $value    = $db->real_escape_string($_POST['value'] ?? '');
    $ticketBefore = null;

    $ticketLookup = $db->prepare("SELECT t.*, u.email AS user_email, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    if ($ticketLookup) {
        $ticketLookup->bind_param('i', $ticketId);
        $ticketLookup->execute();
        $ticketBefore = $ticketLookup->get_result()->fetch_assoc();
        $ticketLookup->close();
    }
    if (!$ticketBefore) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']); exit;
    }

    $allowed = [];
    switch ($field) {
        case 'status':
            $allowed = ['open','in_progress','waiting','resolved','closed'];
            requireRole($agent, 'agent');
            break;
        case 'agent_priority':
            $allowed = ['low','medium','high','critical'];
            requireRole($agent, 'agent');
            break;
        case 'assigned_to':
            requireRole($agent, 'senior_agent');
            $allowed = null; // any agent id
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid field']); exit;
    }

    if ($allowed !== null && !in_array($value, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid value']); exit;
    }

    if ($field === 'assigned_to') {
        $assignedTo = $value === '' ? null : (int)$value;
        if ($assignedTo !== null) {
            $stmt = $db->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $assignedTo, $ticketId);
        } else {
            $stmt = $db->prepare("UPDATE support_tickets SET assigned_to = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $ticketId);
        }
    } elseif ($field === 'status' && in_array($value, ['resolved', 'closed'], true)) {
        $stmt = $db->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW(), closed_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $value, $ticketId);
    } else {
        $stmt = $db->prepare("UPDATE support_tickets SET `$field` = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $value, $ticketId);
    }
    $stmt->execute();
    $stmt->close();

    // Add system note
    $note = "Status changed to $value by {$agent['username']}";
    if ($field === 'agent_priority') $note = "Priority set to $value by {$agent['username']}";
    if ($field === 'assigned_to') {
        $assignedTo = (int)$value;
        $stmt = $db->prepare("SELECT username FROM support_agents WHERE id = ?");
        $stmt->bind_param('i', $assignedTo);
        $stmt->execute();
        $aRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $note = "Assigned to " . ($aRow['username'] ?? 'agent') . " by {$agent['username']}";
    }
    $stmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
    $stmt->bind_param('is', $ticketId, $note);
    $stmt->execute();
    $stmt->close();

    if ($field === 'status' && in_array($value, ['resolved', 'closed'], true) && (string)($ticketBefore['status'] ?? '') !== $value) {
        $toEmail = trim((string)($ticketBefore['guest_email'] ?? $ticketBefore['user_email'] ?? ''));
        if ($toEmail !== '') {
            $closedEmail = support_render_email_template($db, 'ticket_closed', [
                'ticket_ref' => (string)$ticketBefore['ticket_ref'],
                'subject' => (string)$ticketBefore['subject'],
                'status' => $value,
                'status_label' => ucwords(str_replace('_', ' ', $value)),
                'agent_name' => (string)($agent['username'] ?? 'Support'),
            ], (string)$ticketBefore['ticket_ref'], 'Ticket status updated');
            queueNotification($db, 'email', [
                'to' => $toEmail,
                'subject' => $closedEmail['subject'],
                'html' => $closedEmail['html'],
            ]);
            triggerNotificationWorker();
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ticket id']);
        exit;
    }

    $lookupStmt = $db->prepare("SELECT id, ticket_ref FROM support_tickets WHERE id = ?");
    $lookupStmt->bind_param('i', $ticketId);
    $lookupStmt->execute();
    $ticket = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $deleteReplies = $db->prepare("DELETE FROM support_ticket_replies WHERE ticket_id = ?");
    $deleteReplies->bind_param('i', $ticketId);
    $deleteReplies->execute();
    $deleteReplies->close();

    $deleteTicket = $db->prepare("DELETE FROM support_tickets WHERE id = ?");
    $deleteTicket->bind_param('i', $ticketId);
    $deleteTicket->execute();
    $ok = $deleteTicket->affected_rows > 0;
    $deleteTicket->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete ticket']);
        exit;
    }

    echo json_encode(['success' => true, 'ticket_ref' => $ticket['ticket_ref']]);
    exit;
}

if ($action === 'delete_user_from_ticket') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ticket id']);
        exit;
    }

    $ticketStmt = $db->prepare("SELECT t.id, t.ticket_ref, t.user_id, u.username, u.email FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    if (!$ticketStmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to load ticket']);
        exit;
    }
    $ticketStmt->bind_param('i', $ticketId);
    $ticketStmt->execute();
    $ticket = $ticketStmt->get_result()->fetch_assoc();
    $ticketStmt->close();
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $uid = (int)($ticket['user_id'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Ticket is not linked to a user account']);
        exit;
    }

    $username = (string)($ticket['username'] ?? '');
    $email = trim((string)($ticket['email'] ?? ''));

    $deletedRows = 0;
    $workspaceRemoved = false;

    try {
        $db->begin_transaction();

        $msgStmt = $db->prepare("DELETE m FROM user_conv_messages m INNER JOIN user_convs c ON c.conv_id = m.conv_id WHERE c.user_id = ?");
        if ($msgStmt) {
            $msgStmt->bind_param('i', $uid);
            $msgStmt->execute();
            $deletedRows += max(0, (int)$msgStmt->affected_rows);
            $msgStmt->close();
        }

        $deleteTables = [
            'api_keys',
            'chat_history',
            'email_verification_codes',
            'security_log',
            'transactions',
            'user_2fa_recovery_codes',
            'user_convs',
            'user_yubikeys',
        ];
        foreach ($deleteTables as $tbl) {
            $deletedRows += support_delete_user_bound_rows($db, $tbl, $uid);
        }

        if ($username !== '') {
            $convStmt = $db->prepare("DELETE FROM conversations WHERE user_id = ?");
            if ($convStmt) {
                $convStmt->bind_param('s', $username);
                $convStmt->execute();
                $deletedRows += max(0, (int)$convStmt->affected_rows);
                $convStmt->close();
            }
        }

        $reqStmt = $db->prepare("UPDATE user_data_deletion_requests SET status = 'completed', reviewed_at = NOW(), admin_note = ? WHERE user_id = ? AND status = 'pending'");
        if ($reqStmt) {
            $note = 'Completed by ' . ($agent['username'] ?? 'admin') . ' via ticket ' . ($ticket['ticket_ref'] ?? '');
            $reqStmt->bind_param('si', $note, $uid);
            $reqStmt->execute();
            $reqStmt->close();
        }

        $userDeleteStmt = $db->prepare("DELETE FROM users WHERE id = ?");
        if (!$userDeleteStmt) {
            throw new RuntimeException('Failed to prepare user deletion');
        }
        $userDeleteStmt->bind_param('i', $uid);
        $userDeleteStmt->execute();
        $userDeleted = $userDeleteStmt->affected_rows > 0;
        $userDeleteStmt->close();
        if (!$userDeleted) {
            throw new RuntimeException('User record was not deleted');
        }

        $closeStmt = $db->prepare("UPDATE support_tickets SET status = 'closed', updated_at = NOW(), closed_at = NOW() WHERE id = ?");
        if ($closeStmt) {
            $closeStmt->bind_param('i', $ticketId);
            $closeStmt->execute();
            $closeStmt->close();
        }

        $systemMsg = 'User data deletion completed by ' . ($agent['username'] ?? 'admin') . '. Account deleted and ticket auto-closed.';
        $noteStmt = $db->prepare("INSERT INTO support_ticket_replies (ticket_id, author_type, message, internal) VALUES (?, 'system', ?, 1)");
        if ($noteStmt) {
            $noteStmt->bind_param('is', $ticketId, $systemMsg);
            $noteStmt->execute();
            $noteStmt->close();
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to delete user data']);
        exit;
    }

    $workspaceRemoved = true;

    if ($email !== '') {
        $deletionEmail = support_render_email_template($db, 'data_deletion_completed', [
            'ticket_ref' => (string)$ticket['ticket_ref'],
            'username' => $username !== '' ? $username : 'there',
            'support_email' => getConfig($db, 'support_email'),
        ], (string)$ticket['ticket_ref'], 'Data deletion completed');
        queueNotification($db, 'email', [
            'to' => $email,
            'subject' => $deletionEmail['subject'],
            'html' => $deletionEmail['html'],
        ]);
        triggerNotificationWorker();
    }

    echo json_encode([
        'success' => true,
        'ticket_ref' => $ticket['ticket_ref'],
        'deleted_rows' => $deletedRows,
        'workspace_removed' => $workspaceRemoved,
    ]);
    exit;
}

if ($action === 'search_users') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $query = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    $permissions = support_user_manager_permissions($agent);

    $sql = "SELECT id, username, email, plan, credits, email_verified, two_factor_enabled, created_at FROM users";
    $types = '';
    $params = [];

    if ($query !== '') {
        $like = '%' . $query . '%';
        $sql .= " WHERE username LIKE ? OR email LIKE ?";
        $types = 'ss';
        $params = [$like, $like];
        if (ctype_digit($query)) {
            $sql .= " OR id = ?";
            $types .= 'i';
            $params[] = (int)$query;
        }
    }

    $sql .= " ORDER BY id DESC LIMIT 50";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to search users']);
        exit;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int)$row['id'],
            'username' => (string)($row['username'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'plan' => (string)($row['plan'] ?? 'free'),
            'credits' => (int)($row['credits'] ?? 0),
            'email_verified' => (int)($row['email_verified'] ?? 0) === 1,
            'two_factor_enabled' => (int)($row['two_factor_enabled'] ?? 0) === 1,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'users' => $users, 'permissions' => $permissions]);
    exit;
}

if ($action === 'get_user_account') {
    $agent = requireAgent($db);
    requireRole($agent, 'agent');

    $userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid user id']);
        exit;
    }

    $permissions = support_user_manager_permissions($agent);
    $stmt = $db->prepare("SELECT id, username, email, plan, credits, created_at, email_verified, email_verified_at, two_factor_enabled, two_factor_method, discord_id, discord_tag, paypal_sub_id, apple_product_id, apple_subscription_status, apple_subscription_expires_at FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to load user']);
        exit;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $recentTickets = [];
    $ticketStmt = $db->prepare("SELECT ticket_ref, subject, status, updated_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 8");
    if ($ticketStmt) {
        $ticketStmt->bind_param('i', $userId);
        $ticketStmt->execute();
        $ticketResult = $ticketStmt->get_result();
        while ($row = $ticketResult->fetch_assoc()) {
            $recentTickets[] = $row;
        }
        $ticketStmt->close();
    }

    ensureSupportAuditTable($db);
    $recentAudit = [];
    $auditStmt = $db->prepare("SELECT l.action, l.details, l.created_at, a.username AS agent_username FROM support_agent_audit_log l LEFT JOIN support_agents a ON a.id = l.agent_id WHERE l.target_user_id = ? ORDER BY l.id DESC LIMIT 10");
    if ($auditStmt) {
        $auditStmt->bind_param('i', $userId);
        $auditStmt->execute();
        $auditResult = $auditStmt->get_result();
        while ($row = $auditResult->fetch_assoc()) {
            $recentAudit[] = [
                'action' => (string)($row['action'] ?? ''),
                'details' => json_decode((string)($row['details'] ?? ''), true) ?: [],
                'created_at' => (string)($row['created_at'] ?? ''),
                'agent_username' => (string)($row['agent_username'] ?? 'system'),
            ];
        }
        $auditStmt->close();
    }

    $payload = [
        'id' => (int)$user['id'],
        'username' => (string)($user['username'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'plan' => (string)($user['plan'] ?? 'free'),
        'credits' => (int)($user['credits'] ?? 0),
        'created_at' => (string)($user['created_at'] ?? ''),
        'email_verified' => (int)($user['email_verified'] ?? 0) === 1,
        'email_verified_at' => (string)($user['email_verified_at'] ?? ''),
        'two_factor_enabled' => (int)($user['two_factor_enabled'] ?? 0) === 1,
        'two_factor_method' => (string)($user['two_factor_method'] ?? ''),
        'discord_connected' => !empty($user['discord_id']) || !empty($user['discord_tag']),
        'discord_tag' => (string)($user['discord_tag'] ?? ''),
        'has_linked_billing' => !empty($user['paypal_sub_id']) || !empty($user['apple_product_id']),
        'billing_provider' => !empty($user['apple_product_id']) ? 'apple_iap' : (!empty($user['paypal_sub_id']) ? 'paypal' : ''),
        'apple_subscription_status' => (string)($user['apple_subscription_status'] ?? ''),
        'apple_subscription_expires_at' => (string)($user['apple_subscription_expires_at'] ?? ''),
    ];

    if (!empty($permissions['can_edit_security'])) {
        $payload['paypal_sub_id'] = (string)($user['paypal_sub_id'] ?? '');
        $payload['apple_product_id'] = (string)($user['apple_product_id'] ?? '');
    }

    echo json_encode([
        'success' => true,
        'user' => $payload,
        'permissions' => $permissions,
        'plan_options' => support_plan_options(),
        'recent_tickets' => $recentTickets,
        'recent_audit' => $recentAudit,
    ]);
    exit;
}

if ($action === 'update_user_account') {
    $agent = requireAgent($db);
    requireRole($agent, 'senior_agent');

    $permissions = support_user_manager_permissions($agent);
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid user id']);
        exit;
    }

    $lookup = $db->prepare("SELECT id, username, email, plan, credits, email_verified, two_factor_enabled, discord_id, discord_tag, paypal_sub_id, apple_product_id FROM users WHERE id = ? LIMIT 1");
    if (!$lookup) {
        echo json_encode(['success' => false, 'error' => 'Failed to load user']);
        exit;
    }
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $current = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$current) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $plan = trim((string)($_POST['plan'] ?? ''));
    $creditsRaw = $_POST['credits'] ?? null;
    $emailVerifiedRaw = $_POST['email_verified'] ?? null;
    $clearTwoFactor = (int)($_POST['clear_two_factor'] ?? 0) === 1;
    $revokeMobileTokens = (int)($_POST['revoke_mobile_tokens'] ?? 0) === 1;
    $clearDiscordLink = (int)($_POST['clear_discord_link'] ?? 0) === 1;
    $clearBillingLinks = (int)($_POST['clear_billing_links'] ?? 0) === 1;
    $newPassword = trim((string)($_POST['reset_password'] ?? ''));

    if (($emailVerifiedRaw !== null || $clearTwoFactor || $revokeMobileTokens || $clearDiscordLink || $clearBillingLinks || $newPassword !== '') && empty($permissions['can_edit_security'])) {
        echo json_encode(['success' => false, 'error' => 'Only admins can perform that account action']);
        exit;
    }

    $changes = [];

    try {
        $db->begin_transaction();

        $set = [];
        $types = '';
        $params = [];

        if ($permissions['can_edit_profile']) {
            if ($username !== '' && $username !== (string)$current['username']) {
                $set[] = 'username = ?';
                $types .= 's';
                $params[] = $username;
                $changes[] = 'username';
            }
            if ($email !== '' && $email !== (string)$current['email']) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Invalid email address');
                }
                $set[] = 'email = ?';
                $types .= 's';
                $params[] = $email;
                $changes[] = 'email';
            }
        }

        if ($permissions['can_edit_billing']) {
            if ($plan !== '' && $plan !== (string)$current['plan']) {
                if (!in_array($plan, support_plan_options(), true)) {
                    throw new RuntimeException('Invalid plan selected');
                }
                $set[] = 'plan = ?';
                $types .= 's';
                $params[] = $plan;
                $changes[] = 'plan';
            }
            if ($creditsRaw !== null && $creditsRaw !== '' && (int)$creditsRaw !== (int)$current['credits']) {
                $credits = max(0, (int)$creditsRaw);
                $set[] = 'credits = ?';
                $types .= 'i';
                $params[] = $credits;
                $changes[] = 'credits';
            }
        }

        if ($set) {
            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
            $types .= 'i';
            $params[] = $userId;
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to update user');
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $msg = (int)$stmt->errno === 1062 ? 'Username or email already taken' : 'Failed to update user';
                $stmt->close();
                throw new RuntimeException($msg);
            }
            $stmt->close();
        }

        if ($permissions['can_edit_security'] && $emailVerifiedRaw !== null && $emailVerifiedRaw !== '') {
            $emailVerified = (int)$emailVerifiedRaw === 1 ? 1 : 0;
            if ($emailVerified !== (int)$current['email_verified']) {
                if ($emailVerified === 1) {
                    $verifyStmt = $db->prepare("UPDATE users SET email_verified = 1, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?");
                } else {
                    $verifyStmt = $db->prepare("UPDATE users SET email_verified = 0, email_verified_at = NULL WHERE id = ?");
                }
                if ($verifyStmt) {
                    $verifyStmt->bind_param('i', $userId);
                    $verifyStmt->execute();
                    $verifyStmt->close();
                    $changes[] = 'email_verified';
                }
            }
        }

        if ($permissions['can_edit_security'] && $clearTwoFactor) {
            $faStmt = $db->prepare("UPDATE users SET two_factor_enabled = 0, two_factor_method = NULL, totp_secret = NULL, totp_pending_secret = NULL WHERE id = ?");
            if ($faStmt) {
                $faStmt->bind_param('i', $userId);
                $faStmt->execute();
                $faStmt->close();
            }
            support_delete_user_bound_rows($db, 'user_2fa_recovery_codes', $userId);
            $changes[] = 'two_factor_reset';
        }

        if ($permissions['can_edit_security'] && $revokeMobileTokens) {
            support_delete_user_bound_rows($db, 'user_mobile_tokens', $userId);
            $changes[] = 'mobile_tokens_revoked';
        }

        if ($permissions['can_edit_security'] && $clearDiscordLink && (!empty($current['discord_id']) || !empty($current['discord_tag']))) {
            $discordStmt = $db->prepare("UPDATE users SET discord_id = NULL, discord_tag = NULL WHERE id = ?");
            if ($discordStmt) {
                $discordStmt->bind_param('i', $userId);
                $discordStmt->execute();
                $discordStmt->close();
            }
            $changes[] = 'discord_unlinked';
        }

        if ($permissions['can_edit_security'] && $clearBillingLinks && (!empty($current['paypal_sub_id']) || !empty($current['apple_product_id']))) {
            $billingStmt = $db->prepare("UPDATE users SET paypal_sub_id = NULL, apple_product_id = NULL, apple_subscription_status = NULL, apple_subscription_expires_at = NULL WHERE id = ?");
            if ($billingStmt) {
                $billingStmt->bind_param('i', $userId);
                $billingStmt->execute();
                $billingStmt->close();
            }
            $changes[] = 'billing_links_cleared';
        }

        if ($permissions['can_edit_security'] && $newPassword !== '') {
            // Generate a secure random temporary password (admin passes 'auto' or empty to trigger auto-gen)
            $tempPass = ($newPassword === 'auto' || $newPassword === '')
                ? support_generate_temp_password()
                : $newPassword;
            if (strlen($tempPass) < 8) {
                throw new RuntimeException('Password must be at least 8 characters');
            }
            $passHash = password_hash($tempPass, PASSWORD_DEFAULT);
            $passStmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
            if (!$passStmt) {
                throw new RuntimeException('Failed to reset password');
            }
            $passStmt->bind_param('si', $passHash, $userId);
            $passStmt->execute();
            $passStmt->close();
            // Revoke all mobile tokens so existing sessions are invalidated
            support_delete_user_bound_rows($db, 'user_mobile_tokens', $userId);
            $changes[] = 'password_reset';
            // Send email notification — best-effort, don't fail the transaction
            $sendTo = $current['email'] ?? '';
            if ($sendTo) {
                $html = support_build_password_reset_email($current['username'] ?? $sendTo, $tempPass);
                sendEmail($db, $sendTo, 'Your Lyralink password has been reset', $html);
            }
            // Expose temp password to the API response so admin can share it if needed
            $generatedPassword = $tempPass;
        }

        if (!$changes) {
            $db->rollback();
            echo json_encode(['success' => true, 'message' => 'No changes were needed']);
            exit;
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage() ?: 'Failed to update user']);
        exit;
    }

    support_audit_log($db, $agent, 'user_account_updated', $userId, [
        'fields' => array_values(array_unique($changes)),
        'by_role' => (string)($agent['role'] ?? ''),
    ]);

    $resp = ['success' => true, 'message' => 'User account updated', 'changed_fields' => array_values(array_unique($changes))];
    if (isset($generatedPassword)) {
        $resp['generated_password'] = $generatedPassword;
    }
    echo json_encode($resp);
    exit;
}

// ── AGENT MANAGEMENT (admin only) ──
if ($action === 'list_agents') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $stmt = $db->prepare("SELECT id, username, email, role, discord_tag, last_seen, active, created_at FROM support_agents ORDER BY role, username");
    $stmt->execute();
    $result = $stmt->get_result();
    $agents = [];
    while ($r = $result->fetch_assoc()) $agents[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'agents' => $agents]);
    exit;
}

if ($action === 'create_agent') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $role     = $_POST['role']          ?? 'trial_agent';
    $validRoles = ['admin','senior_agent','agent','trial_agent'];
    if (!$username || !$email || strlen($password) < 6 || !in_array($role, $validRoles)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']); exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO support_agents (username, email, password_hash, role) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $username, $email, $hash, $role);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
    }
    $stmt->close();
    exit;
}

if ($action === 'update_agent') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $targetId   = (int)($_POST['agent_id'] ?? 0);
    $role       = trim($_POST['role'] ?? '');
    $active     = (int)($_POST['active'] ?? 1);
    $discordId  = trim($_POST['discord_id'] ?? '');
    $discordTag = trim($_POST['discord_tag'] ?? '');
    $discordIdParam = $discordId !== '' ? $discordId : null;
    $discordTagParam = $discordTag !== '' ? $discordTag : null;
    $stmt = $db->prepare("UPDATE support_agents SET role = ?, active = ?, discord_id = ?, discord_tag = ? WHERE id = ?");
    $stmt->bind_param('sissi', $role, $active, $discordIdParam, $discordTagParam, $targetId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── DISCORD ROLE CONFIG (admin) ──
if ($action === 'set_discord_roles') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $priorities = ['low','medium','high','critical'];
    foreach ($priorities as $p) {
        $roleId   = trim($_POST[$p . '_role_id'] ?? '');
        $roleName = trim($_POST[$p . '_role_name'] ?? '');
        if ($roleId) {
            $stmt = $db->prepare("INSERT INTO support_discord_roles (priority, role_id, role_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), role_name = VALUES(role_name)");
            $stmt->bind_param('sss', $p, $roleId, $roleName);
            $stmt->execute();
            $stmt->close();
        }
    }
    $webhook = trim($_POST['webhook_url'] ?? '');
    if ($webhook) {
        $webhookCheck = netpolicy_validate_outbound_url($webhook, false);
        if (!$webhookCheck['ok']) {
            echo json_encode(['success' => false, 'error' => 'Webhook URL rejected: ' . $webhookCheck['error']]);
            exit;
        }
        $stmt = $db->prepare("UPDATE support_config SET `value` = ? WHERE `key` = 'discord_webhook_url'");
        $stmt->bind_param('s', $webhook);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── SMTP CONFIG (admin) ──
if ($action === 'set_smtp') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    $fields = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','support_email'];
    $stmt = $db->prepare("UPDATE support_config SET `value` = ? WHERE `key` = ?");
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $stmt->bind_param('ss', $v, $f);
        $stmt->execute();
    }
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'get_admin_config') {
    $agent = requireAgent($db);
    requireRole($agent, 'senior_agent');
    echo json_encode(['success' => true, 'config' => support_config_payload($db)]);
    exit;
}

if ($action === 'set_email_templates') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');

    $payloadRaw = $_POST['templates'] ?? '';
    $templates = json_decode((string)$payloadRaw, true);
    if (!is_array($templates)) {
        echo json_encode(['success' => false, 'error' => 'Invalid template payload']);
        exit;
    }

    foreach ($templates as $templateId => $templateData) {
        $def = support_template_definition((string)$templateId);
        if (!$def || !is_array($templateData)) {
            continue;
        }
        $subject = trim((string)($templateData['subject'] ?? ''));
        $body = trim((string)($templateData['body'] ?? ''));
        $defaults = support_template_defaults((string)$templateId);
        support_upsert_config($db, (string)$def['subject_key'], $subject !== '' ? $subject : $defaults['subject']);
        support_upsert_config($db, (string)$def['body_key'], $body !== '' ? $body : $defaults['body']);
    }

    echo json_encode(['success' => true, 'templates' => support_email_templates_payload($db)]);
    exit;
}

if ($action === 'send_test_email_template') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');

    $templateId = trim((string)($_POST['template_id'] ?? ''));
    $recipient = trim((string)($_POST['recipient'] ?? ''));
    $subjectTemplate = trim((string)($_POST['subject'] ?? ''));
    $bodyTemplate = trim((string)($_POST['body'] ?? ''));

    if ($templateId === '' || !support_template_definition($templateId)) {
        echo json_encode(['success' => false, 'error' => 'Invalid template']);
        exit;
    }
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid recipient email required']);
        exit;
    }

    $stored = support_template_content($db, $templateId);
    $subjectTemplate = $subjectTemplate !== '' ? $subjectTemplate : $stored['subject'];
    $bodyTemplate = $bodyTemplate !== '' ? $bodyTemplate : $stored['body'];
    $rendered = support_render_email_content($subjectTemplate, $bodyTemplate, [
        'ticket_ref' => 'TKT-TEST01',
        'priority' => 'high',
        'category' => 'account',
        'from_name' => 'Template Tester',
        'from_email' => $recipient,
        'subject' => 'Test message from support dashboard',
        'message' => "This is a test email generated from the support dashboard template editor.\nReview formatting, variables, and links before saving.",
        'agent_name' => (string)($agent['username'] ?? 'Support Agent'),
        'username' => 'TemplateTester',
        'email' => $recipient,
        'code' => '482931',
        'expires_minutes' => '15',
        'support_email' => getConfig($db, 'support_email') ?: 'support@lyralinkai.com',
        'status' => 'closed',
        'status_label' => 'Closed',
    ], 'TKT-TEST01', 'Template test email');

    $ok = sendEmail($db, $recipient, '[Test] ' . $rendered['subject'], $rendered['html']);
    echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Failed to send test email']);
    exit;
}

if ($action === 'notification_queue_status') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    ensureSupportQueueTable($db);

    $summary = [
        'pending' => 0,
        'processing' => 0,
        'failed' => 0,
        'sent_24h' => 0,
    ];

    $countsStmt = $db->prepare("SELECT status, COUNT(*) AS total FROM support_notification_queue GROUP BY status");
    $countsStmt->execute();
    $counts = $countsStmt->get_result();
    while ($row = $counts->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['total'];
    }
    $countsStmt->close();

    $sentStmt = $db->prepare("SELECT COUNT(*) AS total FROM support_notification_queue WHERE status = 'sent' AND processed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $sentStmt->execute();
    $sent24 = $sentStmt->get_result()->fetch_assoc();
    $sentStmt->close();
    $summary['sent_24h'] = (int)($sent24['total'] ?? 0);

    $jobs = [];
    $stmt = $db->prepare("SELECT id, channel, status, attempts, last_error, available_at, created_at, processed_at, payload FROM support_notification_queue WHERE status IN ('failed','pending','processing') ORDER BY FIELD(status,'failed','processing','pending'), created_at DESC LIMIT 50");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payload = json_decode($row['payload'], true);
        $target = '';
        if ($row['channel'] === 'email') {
            $target = $payload['to'] ?? '';
        } elseif ($row['channel'] === 'discord') {
            $target = $payload['ticket']['ticket_ref'] ?? 'Discord webhook';
        }
        unset($row['payload']);
        $row['target'] = $target;
        $jobs[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'summary' => $summary, 'jobs' => $jobs]);
    exit;
}

if ($action === 'retry_notification_job') {
    $agent = requireAgent($db);
    requireRole($agent, 'admin');
    ensureSupportQueueTable($db);

    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid job']);
        exit;
    }

    $supportDecision = chat_os_cron_job_context(
        'support_notification_retry',
        'Retry support notification job ' . $jobId,
        [
            'db' => $db,
            'risk_level' => 'MEDIUM',
            'task_domain' => 'support',
            'user_id' => (string)($agent['id'] ?? 'system'),
            'session_id' => 'support:notification_retry',
            'database_runtime_available' => true,
            'granted_permissions' => ['model.generate', 'filesystem.read', 'network.read'],
        ]
    );
    $supportTask = is_array($supportDecision['task'] ?? null) ? $supportDecision['task'] : [];

    $stmt = $db->prepare("UPDATE support_notification_queue SET status = 'pending', attempts = 0, last_error = NULL, available_at = NOW(), processed_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();
    triggerNotificationWorker();

    if ($supportTask !== []) {
        $supportTask = chat_os_mark_task_result($supportTask, 'SUCCEEDED', ['job_id' => $jobId, 'status' => 'pending_requeued'], null, null);
        chat_os_persist_task_record($supportTask, ['job_name' => 'support_notification_retry', 'job_id' => $jobId], $db);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── AGENT STATS ──
if ($action === 'agent_stats') {
    $agent  = requireAgent($db);
    $agentId = $agent['id'];
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE status NOT IN ('resolved','closed')");
    $stmt->execute();
    $open = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE COALESCE(agent_priority, user_priority) = 'critical' AND status NOT IN ('resolved','closed')");
    $stmt->execute();
    $critical = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_tickets WHERE assigned_to = ? AND status NOT IN ('resolved','closed')");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $mine = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM support_agents WHERE last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1");
    $stmt->execute();
    $online = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['success' => true, 'stats' => compact('open','critical','mine','online')]);
    exit;
}

// ── DISCORD TRANSCRIPT — SAVE (called by bot) ──
if ($action === 'save_transcript') {
    ensureDiscordTranscriptTable($db);

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode($rawBody, true);
    if (!is_array($jsonBody)) {
        $jsonBody = [];
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $headerToken = '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $headerToken = trim($m[1]);
    }

    $botKey = $_POST['bot_key'] ?? ($jsonBody['bot_key'] ?? $headerToken);
    $expectedBotKey = (string)getenv('BOT_SECRET_KEY');
    if ($expectedBotKey === '') {
        $expectedBotKey = getConfig($db, 'bot_secret_key');
    }
    if ($expectedBotKey === '') {
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: BOT_SECRET_KEY missing']); exit;
    }
    if (!hash_equals($expectedBotKey, (string)$botKey)) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }

    $ticketRef   = trim((string)($_POST['ticket_ref'] ?? ($jsonBody['ticket_ref'] ?? '')));
    $channelId   = trim((string)($_POST['channel_id'] ?? ($jsonBody['channel_id'] ?? ($jsonBody['channelId'] ?? ''))));
    $channelName = trim((string)($_POST['channel_name'] ?? ($jsonBody['channel_name'] ?? ($jsonBody['channelName'] ?? ''))));
    $guildId     = trim((string)($_POST['guild_id'] ?? ($jsonBody['guild_id'] ?? ($jsonBody['guildId'] ?? ''))));
    $openedBy    = trim((string)($_POST['opened_by'] ?? ($jsonBody['opened_by'] ?? ($jsonBody['openedBy'] ?? ''))));
    $openedById  = trim((string)($_POST['opened_by_id'] ?? ($jsonBody['opened_by_id'] ?? ($jsonBody['openedById'] ?? ''))));
    $category    = trim((string)($_POST['category'] ?? ($jsonBody['category'] ?? '')));
    $closedBy    = trim((string)($_POST['closed_by'] ?? ($jsonBody['closed_by'] ?? ($jsonBody['closedBy'] ?? ''))));
    $msgCount    = (int)($_POST['message_count'] ?? ($jsonBody['message_count'] ?? ($jsonBody['messageCount'] ?? 0)));
    $transcript  = (string)($_POST['transcript'] ?? ($jsonBody['transcript'] ?? ($jsonBody['web_view'] ?? ($jsonBody['webView'] ?? ''))));

    if ($ticketRef === '') {
        $ticketRef = 'DISCORD-' . strtoupper(substr(md5(($channelId ?: uniqid('', true)) . microtime(true)), 0, 8));
    }
    if ($channelId === '') {
        $channelId = 'unknown';
    }
    if ($transcript === '') {
        echo json_encode(['success' => false, 'error' => 'Transcript payload missing']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO discord_ticket_transcripts
        (ticket_ref, channel_id, channel_name, guild_id, opened_by, opened_by_id, category, closed_by, message_count, transcript)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'DB prepare failed']);
        exit;
    }
    $stmt->bind_param('ssssssssis', $ticketRef, $channelId, $channelName, $guildId, $openedBy, $openedById, $category, $closedBy, $msgCount, $transcript);
    $ok = $stmt->execute();
    $insertId = (int)$db->insert_id;
    $stmt->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Insert failed']);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $insertId]);
    exit;
}

// ── DISCORD TRANSCRIPT — LIST (agent view) ──
if ($action === 'list_transcripts') {
    $agent  = requireAgent($db);
    ensureDiscordTranscriptTable($db);
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $db->prepare("SELECT id, ticket_ref, channel_name, opened_by, category, closed_by, message_count, created_at FROM discord_ticket_transcripts WHERE opened_by LIKE ? OR ticket_ref LIKE ? OR channel_name LIKE ? ORDER BY created_at DESC LIMIT 100");
        $stmt->bind_param('sss', $like, $like, $like);
    } else {
        $stmt = $db->prepare("SELECT id, ticket_ref, channel_name, opened_by, category, closed_by, message_count, created_at FROM discord_ticket_transcripts ORDER BY created_at DESC LIMIT 100");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'transcripts' => $rows]);
    exit;
}

// ── DISCORD TRANSCRIPT — GET SINGLE ──
if ($action === 'get_transcript') {
    $agent = requireAgent($db);
    ensureDiscordTranscriptTable($db);
    $id    = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM discord_ticket_transcripts WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
    echo json_encode(['success' => true, 'transcript' => $row]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>