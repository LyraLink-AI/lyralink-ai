<?php
require_once __DIR__ . '/../api/session_boot.php';
// PayPal redirects here after subscription approval
lyra_session_boot();

$plan           = $_GET['plan'] ?? '';
$subscriptionId = $_GET['subscription_id'] ?? '';

$plan = strtolower(trim((string)$plan));
$allowedPlans = ['basic', 'pro', 'enterprise'];
if (!in_array($plan, $allowedPlans, true)) {
    $plan = '';
}

$subscriptionId = trim((string)$subscriptionId);
if (!preg_match('/^[A-Za-z0-9\-_.]{6,80}$/', $subscriptionId)) {
    $subscriptionId = '';
}

if (!$plan || !$subscriptionId || empty($_SESSION['user_id'])) {
    header('Location: /?billing=error');
    exit;
}

// Activate via billing.php
$ch = curl_init('http://localhost/api/billing.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'action'          => 'activate_subscription',
    'subscription_id' => $subscriptionId,
    'plan'            => $plan
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: PHPSESSID=' . session_id()]);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if (!empty($result['success'])) {
    header('Location: /?billing=success&plan=' . rawurlencode($plan));
} else {
    header('Location: /?billing=error');
}
exit;
?>