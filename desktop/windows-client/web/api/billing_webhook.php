<?php
require_once __DIR__ . '/security.php';

api_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

$paypalClientId = api_get_secret('PAYPAL_CLIENT_ID', '');
$paypalSecret = api_get_secret('PAYPAL_SECRET', '');
$paypalMode = api_get_secret('PAYPAL_MODE', 'live');
$paypalWebhookId = trim((string)api_get_secret('PAYPAL_WEBHOOK_ID', ''));
$verifySignature = api_get_secret('PAYPAL_WEBHOOK_VERIFY', '1') === '1';

$paypalPlanIds = [
    'basic' => api_get_secret('PAYPAL_PLAN_BASIC', ''),
    'pro' => api_get_secret('PAYPAL_PLAN_PRO', ''),
    'enterprise' => api_get_secret('PAYPAL_PLAN_ENTERPRISE', ''),
];

$db->query("CREATE TABLE IF NOT EXISTS paypal_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(96) NOT NULL,
    resource_id VARCHAR(128) DEFAULT NULL,
    status ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
    message VARCHAR(255) DEFAULT NULL,
    payload_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_id (event_id),
    KEY idx_event_type_created (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function bw_headers(): array {
    $headers = [];
    if (function_exists('getallheaders')) {
        $raw = getallheaders();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $headers[strtolower((string)$k)] = (string)$v;
            }
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (!is_string($v)) {
            continue;
        }
        if (str_starts_with($k, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = $v;
            }
        }
    }
    return $headers;
}

function bw_plan_from_paypal_plan_id(string $paypalPlanId, array $paypalPlanIds): ?string {
    $paypalPlanId = trim($paypalPlanId);
    if ($paypalPlanId === '') {
        return null;
    }
    foreach ($paypalPlanIds as $planCode => $configuredId) {
        if (is_string($configuredId) && $configuredId !== '' && hash_equals($configuredId, $paypalPlanId)) {
            return strtolower(trim((string)$planCode));
        }
    }
    return null;
}

function bw_paypal_token(string $clientId, string $secret, string $mode): ?string {
    if ($clientId === '' || $secret === '') {
        return null;
    }
    $url = $mode === 'live'
        ? 'https://api-m.paypal.com/v1/oauth2/token'
        : 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode((string)$response, true);
    return is_array($result) ? ($result['access_token'] ?? null) : null;
}

function bw_paypal_request(string $endpoint, string $method, ?array $body, string $token, string $mode): array {
    $base = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($base . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = [];
    if (is_string($response) && trim($response) !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    $data['_http_status'] = $httpCode;
    return $data;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$eventId = trim((string)($payload['id'] ?? ''));
$eventType = trim((string)($payload['event_type'] ?? ''));
$resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
$resourceId = trim((string)($resource['id'] ?? ''));

if ($eventId === '' || $eventType === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event id/type']);
    exit;
}

$storeStmt = $db->prepare("INSERT IGNORE INTO paypal_webhook_events (event_id, event_type, resource_id, payload_json) VALUES (?, ?, ?, ?)");
$payloadJson = json_encode($payload);
$storeStmt->bind_param('ssss', $eventId, $eventType, $resourceId, $payloadJson);
$storeStmt->execute();
$inserted = $storeStmt->affected_rows > 0;
$storeStmt->close();

if (!$inserted) {
    echo json_encode(['success' => true, 'deduped' => true]);
    exit;
}

if ($verifySignature) {
    if ($paypalWebhookId === '') {
        $db->query("UPDATE paypal_webhook_events SET status = 'failed', message = 'PAYPAL_WEBHOOK_ID missing', processed_at = NOW() WHERE event_id = '" . $db->real_escape_string($eventId) . "' LIMIT 1");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Webhook verification not configured']);
        exit;
    }

    $headers = bw_headers();
    $required = [
        'paypal-transmission-id',
        'paypal-transmission-time',
        'paypal-transmission-sig',
        'paypal-cert-url',
        'paypal-auth-algo',
    ];
    foreach ($required as $h) {
        if (empty($headers[$h])) {
            $db->query("UPDATE paypal_webhook_events SET status = 'failed', message = 'Missing header: " . $db->real_escape_string($h) . "', processed_at = NOW() WHERE event_id = '" . $db->real_escape_string($eventId) . "' LIMIT 1");
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing PayPal signature headers']);
            exit;
        }
    }

    $token = bw_paypal_token($paypalClientId, $paypalSecret, $paypalMode);
    if ($token === null) {
        $db->query("UPDATE paypal_webhook_events SET status = 'failed', message = 'PayPal auth failed', processed_at = NOW() WHERE event_id = '" . $db->real_escape_string($eventId) . "' LIMIT 1");
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'PayPal auth failed']);
        exit;
    }

    $verifyResult = bw_paypal_request('/v1/notifications/verify-webhook-signature', 'POST', [
        'auth_algo' => $headers['paypal-auth-algo'],
        'cert_url' => $headers['paypal-cert-url'],
        'transmission_id' => $headers['paypal-transmission-id'],
        'transmission_sig' => $headers['paypal-transmission-sig'],
        'transmission_time' => $headers['paypal-transmission-time'],
        'webhook_id' => $paypalWebhookId,
        'webhook_event' => $payload,
    ], $token, $paypalMode);

    if (strtoupper((string)($verifyResult['verification_status'] ?? '')) !== 'SUCCESS') {
        $db->query("UPDATE paypal_webhook_events SET status = 'failed', message = 'Signature verification failed', processed_at = NOW() WHERE event_id = '" . $db->real_escape_string($eventId) . "' LIMIT 1");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid webhook signature']);
        exit;
    }
}

$status = 'ignored';
$message = 'Event ignored';

$subId = '';
if (isset($resource['id']) && is_string($resource['id'])) {
    $subId = trim($resource['id']);
}
if ($subId === '') {
    $related = $resource['billing_agreement_id'] ?? ($resource['supplementary_data']['related_ids']['subscription_id'] ?? '');
    if (is_string($related)) {
        $subId = trim($related);
    }
}

if ($subId !== '') {
    $stmt = $db->prepare("SELECT id, plan, paypal_sub_id FROM users WHERE paypal_sub_id = ? LIMIT 1");
    $stmt->bind_param('s', $subId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        $uid = (int)$user['id'];
        $eventUpper = strtoupper($eventType);
        if (in_array($eventUpper, ['BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED'], true)) {
            $freePlan = 'free';
            $update = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = NULL WHERE id = ?");
            $update->bind_param('si', $freePlan, $uid);
            $update->execute();
            $update->close();
            $status = 'processed';
            $message = 'Downgraded user due to subscription status: ' . $eventUpper;
        } elseif (in_array($eventUpper, ['BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.RE-ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED'], true)) {
            $paypalPlanId = trim((string)($resource['plan_id'] ?? ''));
            $resolvedPlan = bw_plan_from_paypal_plan_id($paypalPlanId, $paypalPlanIds);
            if ($resolvedPlan !== null) {
                $update = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = ? WHERE id = ?");
                $update->bind_param('ssi', $resolvedPlan, $subId, $uid);
                $update->execute();
                $update->close();
                $status = 'processed';
                $message = 'Upgraded user from webhook event';
            } else {
                $status = 'failed';
                $message = 'Unknown plan_id mapping in webhook';
            }
        } elseif ($eventUpper === 'PAYMENT.SALE.COMPLETED') {
            $status = 'processed';
            $message = 'Recurring payment recorded';
        } elseif (in_array($eventUpper, ['PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED'], true)) {
            $status = 'processed';
            $message = 'Refund/reversal event recorded';
        }
    }
}

$updateEvent = $db->prepare("UPDATE paypal_webhook_events SET status = ?, message = ?, processed_at = NOW() WHERE event_id = ? LIMIT 1");
$updateEvent->bind_param('sss', $status, $message, $eventId);
$updateEvent->execute();
$updateEvent->close();

echo json_encode([
    'success' => true,
    'event_id' => $eventId,
    'status' => $status,
    'message' => $message,
]);
