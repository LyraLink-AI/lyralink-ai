<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

// ════════════════════════════════
// CONFIG — fill these in
// ════════════════════════════════
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
$paypalClientId  = api_get_secret('PAYPAL_CLIENT_ID', '');
$paypalSecret    = api_get_secret('PAYPAL_SECRET', '');
$paypalMode      = api_get_secret('PAYPAL_MODE', 'live'); // 'sandbox' for testing, 'live' for real payments

// ════════════════════════════════
// PLANS CONFIG
// ════════════════════════════════
$plans = [
    'free'       => ['name' => 'Free',       'price' => 0,  'messages' => 1500,  'model' => 'llama-3.1-8b-instant',       'unlimited' => false],
    'basic'      => ['name' => 'Basic',      'price' => 5,  'messages' => 2500,  'model' => 'llama-3.1-8b-instant',       'unlimited' => false],
    'pro'        => ['name' => 'Pro',        'price' => 15, 'messages' => 99999, 'model' => 'llama-3.1-8b-instant',       'unlimited' => true],
    'enterprise' => ['name' => 'Enterprise', 'price' => 30, 'messages' => 99999, 'model' => 'llama-3.3-70b-versatile',    'unlimited' => true],
];

$creditPacks = [
    'pack_100' => ['credits' => 100,  'price' => 3.00,  'label' => '100 Credits'],
    'pack_500' => ['credits' => 500,  'price' => 10.00, 'label' => '500 Credits'],
];

// PayPal plan IDs — create these in PayPal dashboard and paste the IDs here
$paypalPlanIds = [
    'basic'      => api_get_secret('PAYPAL_PLAN_BASIC', ''),
    'pro'        => api_get_secret('PAYPAL_PLAN_PRO', ''),
    'enterprise' => api_get_secret('PAYPAL_PLAN_ENTERPRISE', ''),
];

// ════════════════════════════════
// DB + AUTH CHECK
// ════════════════════════════════
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$action = api_action();

api_enforce_post_and_origin_for_actions([
    'create_order',
    'capture_order',
    'create_subscription',
    'activate_subscription',
    'cancel_subscription',
]);

// ════════════════════════════════
// HELPER: GET PAYPAL ACCESS TOKEN
// ════════════════════════════════
function getPaypalToken($clientId, $secret, $mode) {
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
    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
}

// ════════════════════════════════
// HELPER: PAYPAL API CALL
// ════════════════════════════════
function paypalRequest($endpoint, $method, $body, $token, $mode) {
    $base = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch   = curl_init($base . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// ════════════════════════════════
// GET USER STATUS
// ════════════════════════════════
if ($action === 'status') {
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => true, 'logged_in' => false, 'plan' => 'free', 'messages_used' => 0, 'messages_limit' => 1500, 'credits' => 0]);
        exit;
    }

    $stmt = $db->prepare("SELECT plan, credits, msg_count, msg_reset_at FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Reset monthly count if new month
    if ($user['msg_reset_at'] !== date('Y-m-01')) {
        $resetAt = date('Y-m-01');
        $stmt = $db->prepare("UPDATE users SET msg_count = 0, msg_reset_at = ? WHERE id = ?");
        $stmt->bind_param('si', $resetAt, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
        $user['msg_count'] = 0;
    }

    $plan  = $plans[$user['plan']] ?? $plans['free'];
    echo json_encode([
        'success'        => true,
        'logged_in'      => true,
        'plan'           => $user['plan'],
        'plan_name'      => $plan['name'],
        'messages_used'  => (int)$user['msg_count'],
        'messages_limit' => $plan['messages'],
        'unlimited'      => $plan['unlimited'],
        'credits'        => (int)$user['credits'],
        'model'          => $plan['model'],
    ]);
    exit;
}

// ════════════════════════════════
// CREATE PAYPAL ORDER (credit packs)
// ════════════════════════════════
if ($action === 'create_order') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $packId = $_POST['pack'] ?? '';
    $pack   = $creditPacks[$packId] ?? null;
    if (!$pack) { echo json_encode(['success' => false, 'error' => 'Invalid pack']); exit; }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) { echo json_encode(['success' => false, 'error' => 'PayPal auth failed']); exit; }

    $order = paypalRequest('/v2/checkout/orders', 'POST', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => ['currency_code' => 'USD', 'value' => number_format($pack['price'], 2)],
            'description' => 'Lyralink ' . $pack['label'],
            'custom_id'   => $_SESSION['user_id'] . ':' . $packId
        ]]
    ], $token, $paypalMode);

    if (!empty($order['id'])) {
        // Log pending transaction
        $stmt = $db->prepare("INSERT INTO transactions (user_id, paypal_order_id, type, credits_added, amount, status) VALUES (?, ?, 'credits', ?, ?, 'pending')");
        $stmt->bind_param('isid', $_SESSION['user_id'], $order['id'], $pack['credits'], $pack['price']);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'order_id' => $order['id']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Order creation failed', 'debug' => $order]);
    }
    exit;
}

// ════════════════════════════════
// CAPTURE PAYPAL ORDER (credit packs)
// ════════════════════════════════
if ($action === 'capture_order') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $orderId = $_POST['order_id'] ?? '';
    if (!$orderId) { echo json_encode(['success' => false, 'error' => 'No order ID']); exit; }

    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    $result = paypalRequest("/v2/checkout/orders/$orderId/capture", 'POST', [], $token, $paypalMode);

    if (($result['status'] ?? '') === 'COMPLETED') {
        // Find the transaction to get credits amount
        $stmt = $db->prepare("SELECT credits_added FROM transactions WHERE paypal_order_id = ? AND user_id = ?");
        $stmt->bind_param('si', $orderId, $_SESSION['user_id']);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($tx) {
            // Add credits to user
            $creditsAdded = (int)$tx['credits_added'];
            $stmt = $db->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
            $stmt->bind_param('ii', $creditsAdded, $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("UPDATE transactions SET status = 'completed' WHERE paypal_order_id = ?");
            $stmt->bind_param('s', $orderId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'credits_added' => $tx['credits_added']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Payment not completed', 'status' => $result['status'] ?? 'unknown']);
    }
    exit;
}

// ════════════════════════════════
// CREATE PAYPAL SUBSCRIPTION
// ════════════════════════════════
if ($action === 'create_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $planKey   = $_POST['plan'] ?? '';
    $paypalPlanId = $paypalPlanIds[$planKey] ?? null;
    if (!$paypalPlanId || $paypalPlanId === 'YOUR_PAYPAL_' . strtoupper($planKey) . '_PLAN_ID') {
        echo json_encode(['success' => false, 'error' => 'Plan not configured yet']);
        exit;
    }

    $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) { echo json_encode(['success' => false, 'error' => 'PayPal auth failed']); exit; }

    $returnUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/billing_return.php?plan=' . $planKey;
    $cancelUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/?billing=cancelled';

    $sub = paypalRequest('/v1/billing/subscriptions', 'POST', [
        'plan_id'       => $paypalPlanId,
        'subscriber'    => ['email_address' => $_SESSION['user_email'] ?? ''],
        'application_context' => [
            'return_url'  => $returnUrl,
            'cancel_url'  => $cancelUrl,
            'brand_name'  => 'Lyralink AI',
            'user_action' => 'SUBSCRIBE_NOW'
        ]
    ], $token, $paypalMode);

    if (!empty($sub['id'])) {
        // Find the approval link
        $approvalUrl = null;
        foreach ($sub['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') { $approvalUrl = $link['href']; break; }
        }
        echo json_encode(['success' => true, 'subscription_id' => $sub['id'], 'approval_url' => $approvalUrl]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Subscription creation failed', 'debug' => $sub]);
    }
    exit;
}

// ════════════════════════════════
// ACTIVATE SUBSCRIPTION (after PayPal redirect back)
// ════════════════════════════════
if ($action === 'activate_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $subId   = $_POST['subscription_id'] ?? '';
    $planKey = $_POST['plan'] ?? '';

    if (!$subId || !isset($plans[$planKey])) {
        echo json_encode(['success' => false, 'error' => 'Invalid subscription data']);
        exit;
    }

    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    $subInfo = paypalRequest("/v1/billing/subscriptions/$subId", 'GET', null, $token, $paypalMode);

    if (($subInfo['status'] ?? '') === 'ACTIVE') {
        $stmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = ? WHERE id = ?");
        $stmt->bind_param('ssi', $planKey, $subId, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();

        $amount = $plans[$planKey]['price'];
        $stmt = $db->prepare("INSERT INTO transactions (user_id, paypal_order_id, type, plan, amount, status) VALUES (?, ?, 'subscription', ?, ?, 'completed')");
        $stmt->bind_param('issd', $_SESSION['user_id'], $subId, $planKey, $amount);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'plan' => $planKey]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Subscription not active yet', 'status' => $subInfo['status'] ?? 'unknown']);
    }
    exit;
}

// ════════════════════════════════
// CANCEL SUBSCRIPTION
// ════════════════════════════════
if ($action === 'cancel_subscription') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }

    $stmt = $db->prepare("SELECT paypal_sub_id FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user['paypal_sub_id']) { echo json_encode(['success' => false, 'error' => 'No active subscription']); exit; }

    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    $result = paypalRequest("/v1/billing/subscriptions/{$user['paypal_sub_id']}/cancel", 'POST', ['reason' => 'User requested cancellation'], $token, $paypalMode);

    $freePlan = 'free';
    $stmt = $db->prepare("UPDATE users SET plan = ?, paypal_sub_id = NULL WHERE id = ?");
    $stmt->bind_param('si', $freePlan, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ════════════════════════════════
// GET PLANS INFO (for frontend)
// ════════════════════════════════
if ($action === 'plans') {
    echo json_encode(['success' => true, 'plans' => $plans, 'packs' => $creditPacks]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>