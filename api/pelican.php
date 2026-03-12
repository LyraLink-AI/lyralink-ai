<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

// Run extra diagnostics only when explicitly enabled via env in addition to dev cookie.
$isDevMode = isset($_COOKIE['lyralink_dev']) && $_COOKIE['lyralink_dev'] === 'bypass';
$isDebugEnabled = api_get_secret('APP_DEBUG', '0') === '1';
if ($isDevMode && $isDebugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    // Convert PHP warnings/notices into exceptions so we can report them cleanly.
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(function($e) {
        http_response_code(500);
        error_log("[pelican.php] Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        echo json_encode(['success'=>false,'error'=>'Internal server error','detail'=>$e->getMessage()]);
        exit;
    });
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

// ════════════════════════════════════════════
// CONFIG
// ════════════════════════════════════════════
$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$dbHost        = $dbCfg['host'];
$dbUser        = $dbCfg['user'];
$dbPass        = $dbCfg['pass'];
$dbName        = $dbCfg['name'];

$paypalClientId = api_get_secret('PAYPAL_CLIENT_ID', '');
$paypalSecret   = api_get_secret('PAYPAL_SECRET', '');
$paypalMode     = api_get_secret('PAYPAL_MODE', 'live'); // 'sandbox' or 'live'

$pelicanUrl     = api_get_secret('PELICAN_URL', 'https://panel.cloudhavenx.com/');
$pelicanApiKey  = api_get_secret('PELICAN_API_KEY', '');
$pelicanNodeId  = 1;
$pelicanEggId   = 312;

// Allow overriding Pelican panel URL / API key via environment variables.
// This is useful if the DNS name (panel.cloudhavenx.com) points to a different
// host (e.g., a Plesk server) while the Pelican panel lives elsewhere.
if (!empty($_ENV['PELICAN_URL'])) {
    $pelicanUrl = $_ENV['PELICAN_URL'];
}
if (!empty($_ENV['PELICAN_API_KEY'])) {
    $pelicanApiKey = $_ENV['PELICAN_API_KEY'];
}

// When the Pelican panel is accessed via a hostname that does not match its TLS cert,
// curl will fail with SSL verification errors. Enable this to bypass certificate validation
// and allow API calls to succeed.
$pelicanSkipSslVerify = true;

// ── PLESK CONFIG
// Plesk integration can create a subdomain for each Pelican deployment and
// configure a reverse proxy to the Pelican node/port.
// Set ENABLE_PLESK=0 in the environment to disable this behavior.
$enablePleskIntegration = true;
if (isset($_ENV['ENABLE_PLESK']) && $_ENV['ENABLE_PLESK'] === '0') {
    $enablePleskIntegration = false;
}

$pleskUrl     = api_get_secret('PLESK_URL', 'https://vmi3010514.contaboserver.net:8443');
$pleskApiKey  = api_get_secret('PLESK_API_KEY', '');
$pleskDomain  = api_get_secret('PLESK_DOMAIN', 'cloudhavenx.com');

// Allow overriding Plesk connection details via environment variables.
if (!empty($_ENV['PLESK_URL'])) {
    $pleskUrl = $_ENV['PLESK_URL'];
}
if (!empty($_ENV['PLESK_API_KEY'])) {
    $pleskApiKey = $_ENV['PLESK_API_KEY'];
}
if (!empty($_ENV['PLESK_DOMAIN'])) {
    $pleskDomain = $_ENV['PLESK_DOMAIN'];
}

// PayPal subscription plan IDs — create in PayPal dashboard and paste here
$paypalPlanIds = [
    'small'  => 'P-78S905777L946224GNGXVTQQ',
    'medium' => 'P-56V028669D935731TNGXVUHA',
    'large'  => 'P-1HV79792D9907412UNGXVUUQ',
];

// Hosting tier definitions
$tiers = [
    'small' => [
        'name'    => 'Small',
        'price'   => 5,
        'ram'     => 1024,   // MB
        'cpu'     => 50,     // %
        'disk'    => 5120,   // MB
        'label'   => '1 GB RAM · 1 vCPU · 5 GB Disk',
        'color'   => '#22c55e',
        'popular' => false,
    ],
    'medium' => [
        'name'    => 'Medium',
        'price'   => 12,
        'ram'     => 2048,
        'cpu'     => 100,
        'disk'    => 10240,
        'label'   => '2 GB RAM · 2 vCPU · 10 GB Disk',
        'color'   => '#7c3aed',
        'popular' => true,
    ],
    'large' => [
        'name'    => 'Large',
        'price'   => 25,
        'ram'     => 4096,
        'cpu'     => 200,
        'disk'    => 20480,
        'label'   => '4 GB RAM · 4 vCPU · 20 GB Disk',
        'color'   => '#f97316',
        'popular' => false,
    ],
];

// ════════════════════════════════════════════
// DB
// ════════════════════════════════════════════
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) { echo json_encode(['success'=>false,'error'=>'DB error']); exit; }
$db->set_charset('utf8mb4');

// Auto-create tables
$db->query("CREATE TABLE IF NOT EXISTS pelican_deployments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    tier             ENUM('small','medium','large') NOT NULL,
    status           ENUM('pending','active','suspended','cancelled') NOT NULL DEFAULT 'pending',
    pelican_user_id  INT DEFAULT NULL,
    pelican_server_id INT DEFAULT NULL,
    server_uuid      VARCHAR(64) DEFAULT NULL,
    server_port      INT DEFAULT NULL,
    node_ip          VARCHAR(64) DEFAULT NULL,
    subdomain        VARCHAR(100) DEFAULT NULL,
    paypal_sub_id    VARCHAR(100) DEFAULT NULL,
    paypal_order_id  VARCHAR(100) DEFAULT NULL,
    next_billing     DATE DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
)");
// Ensure schema is up-to-date for existing installations
$db->query("ALTER TABLE pelican_deployments ADD COLUMN IF NOT EXISTS server_port INT DEFAULT NULL");
$db->query("ALTER TABLE pelican_deployments ADD COLUMN IF NOT EXISTS node_ip VARCHAR(64) DEFAULT NULL");

$db->query("CREATE TABLE IF NOT EXISTS pelican_billing_events (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    event_type    VARCHAR(50) NOT NULL,
    amount        DECIMAL(8,2) DEFAULT NULL,
    paypal_txn    VARCHAR(100) DEFAULT NULL,
    payload       TEXT DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deployment (deployment_id)
)");

// ════════════════════════════════════════════
// AUTH
// ════════════════════════════════════════════
function getUser($db) {
    if (empty($_SESSION['user_id'])) return null;
    $id = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id, username, email, plan FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $user;
}
function requireUser($db) {
    $u = getUser($db);
    if (!$u) { echo json_encode(['success'=>false,'error'=>'Login required']); exit; }
    return $u;
}

// ════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════
function pelicanRequest($url, $method, $data, $pelicanApiKey) {
    global $pelicanSkipSslVerify;

    $pelicanBase = rtrim($url, '/');
    $path        = isset($data['_path']) ? $data['_path'] : '';
    $fullUrl     = $pelicanBase . '/api/application' . $path;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $pelicanApiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    // Allow overriding DNS resolution (useful when the public DNS points to the wrong
    // machine but the desired Pelican panel is reachable at a different IP).
    if (!empty($_ENV['PELICAN_IP'])) {
        $host = parse_url($pelicanBase, PHP_URL_HOST);
        $port = parse_url($pelicanBase, PHP_URL_PORT) ?: 443;
        curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$_ENV['PELICAN_IP']}"]);
    }

    if (!empty($pelicanSkipSslVerify)) {
        // The Pelican panel may currently be using a TLS cert that doesn't match the
        // hostname used by the UI/API (e.g. panel.cloudhavenx.com pointing at a host
        // with a cert for vmi3010514.contaboserver.net).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    unset($data['_path']);
    if ($method !== 'GET' && !empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = null;
    if ($res === false) {
        $curlErr = curl_error($ch);
    }
    curl_close($ch);

    $decoded = null;
    if ($res !== false) {
        $decoded = json_decode($res, true);
    }

    return ['code' => $code, 'body' => $decoded, 'raw' => $res, 'curl_error' => $curlErr, 'url' => $fullUrl];
}

function getPaypalToken($clientId, $secret, $mode) {
    $url = $mode === 'live'
        ? 'https://api-m.paypal.com/v1/oauth2/token'
        : 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => "$clientId:$secret",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? null;
}

function paypalRequest($endpoint, $method, $data, $token, $mode) {
    $base = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch   = curl_init("$base$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    if ($method !== 'GET' && !empty($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $res  = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

function pleskApiRequest($pleskUrl, $pleskApiKey, $method, $path, $data = null) {
    $base = rtrim($pleskUrl, '/');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $pleskApiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_URL            => $base . $path,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = json_decode($res, true);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'raw' => $res];
}

function pleskGetDomainId($pleskUrl, $pleskApiKey, $domainName) {
    $resp = pleskApiRequest($pleskUrl, $pleskApiKey, 'GET', '/api/v2/domains?name=' . urlencode($domainName));
    if ($resp['code'] !== 200) {
        return null;
    }

    $body = $resp['body'];
    // Plesk may return an array of domains (v2 API) or an object with `data`.
    if (is_array($body) && isset($body[0]['id'])) {
        return $body[0]['id'];
    }
    if (isset($body['data'][0]['id'])) {
        return $body['data'][0]['id'];
    }

    return null;
}

function pleskCreateSubdomain($pleskUrl, $pleskApiKey, $fullSubdomain, $targetIp, $targetPort) {
    // Creates a subdomain + nginx reverse proxy rule via Plesk CLI
    // fullSubdomain should be like "foo.cloudhavenx.com"

    $parts = explode('.', $fullSubdomain);
    if (count($parts) < 2) {
        return ['success' => false, 'step' => 'validate', 'error' => 'Invalid subdomain'];
    }

    $subLabel  = array_shift($parts);
    $rootDomain = implode('.', $parts);

    // Create subdomain via CLI
    $createCmd = "plesk bin subdomain --create $subLabel -domain $rootDomain";
    exec($createCmd . " 2>&1", $output, $returnVar);
    if ($returnVar !== 0) {
        return ['success' => false, 'step' => 'create_subdomain', 'error' => implode("\n", $output)];
    }

    // Issue Let's Encrypt certificate
    $certCmd = "plesk bin extension --exec letsencrypt cli.php --domain $fullSubdomain --email admin@cloudhavenx.com";
    exec($certCmd . " 2>&1", $certOutput, $certReturn);
    // Ignore errors for now, as it might already exist

    // Configure nginx proxy by editing the vhost config
    $nginxFile = "/etc/nginx/plesk.conf.d/vhosts/$fullSubdomain.conf";
    if (file_exists($nginxFile)) {
        $content = file_get_contents($nginxFile);
        // Replace the default location / proxy to Apache with proxy to target
        $oldLocation = '	location / {
		proxy_pass "https://127.0.0.1:7081";
		proxy_hide_header upgrade;
		proxy_ssl_server_name on;
		proxy_ssl_name $host;
		proxy_ssl_session_reuse off;
		proxy_set_header Host             $host;
		proxy_set_header X-Real-IP        $remote_addr;
		proxy_set_header X-Forwarded-For  $proxy_add_x_forwarded_for;
		proxy_set_header X-Accel-Internal /internal-nginx-static-location;
		access_log off;

	}';
        $newLocation = '	location / {
		proxy_pass http://' . $targetIp . ':' . $targetPort . ';
		proxy_set_header Host $host;
		proxy_set_header X-Real-IP $remote_addr;
		proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header X-Forwarded-Proto $scheme;
		proxy_read_timeout 90;
	}';
        $content = str_replace($oldLocation, $newLocation, $content);
        file_put_contents($nginxFile, $content);
    }

    // Reload nginx
    exec("plesk sbin nginx_control -r 2>&1", $reloadOutput, $reloadReturn);

    return ['success' => true, 'subdomain' => $fullSubdomain];
}

function generateSubdomain($username) {
    $clean = preg_replace('/[^a-z0-9]/', '', strtolower($username));
    return $clean . '-ai-' . substr(md5(uniqid()), 0, 5);
}

// ════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════
$action = api_action();

api_enforce_post_and_origin_for_actions([
    'create_subscription',
    'confirm_subscription',
    'cancel',
    'admin_retry_plesk',
]);

// ── GET TIERS (public) ──
if ($action === 'get_tiers') {
    echo json_encode(['success'=>true,'tiers'=>$tiers]);
    exit;
}

// ── GET MY DEPLOYMENT ──
if ($action === 'get_deployment') {
    $user = requireUser($db);
    $uid  = (int)$user['id'];
    $stmt = $db->prepare("SELECT * FROM pelican_deployments WHERE user_id = ? AND status != 'cancelled' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $dep = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['success'=>true,'deployment'=>$dep]);
    exit;
}

// ── CREATE PAYPAL SUBSCRIPTION ──
if ($action === 'create_subscription') {
    $user = requireUser($db);
    $tier = trim($_POST['tier'] ?? '');
    if (!isset($tiers[$tier])) { echo json_encode(['success'=>false,'error'=>'Invalid tier']); exit; }

    $uid = (int)$user['id'];
    $stmt = $db->prepare("SELECT id FROM pelican_deployments WHERE user_id = ? AND status IN ('pending','active')");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) { echo json_encode(['success'=>false,'error'=>'You already have an active deployment']); exit; }

    // ── DEV BYPASS: skip PayPal entirely ──
    if ($isDevMode) {
        $fakeSubId = 'DEV-' . strtoupper(substr(md5(uniqid()), 0, 12));
        $status = 'pending';
        $stmt = $db->prepare("INSERT INTO pelican_deployments (user_id, tier, status, paypal_sub_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $uid, $tier, $status, $fakeSubId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success'=>true,'subscription_id'=>$fakeSubId,'dev_mode'=>true]);
        exit;
    }

    $planId = $paypalPlanIds[$tier];
    $token  = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
    if (!$token) { echo json_encode(['success'=>false,'error'=>'PayPal auth failed']); exit; }

    $baseUrl = 'https://ai.cloudhavenx.com';
    $sub = paypalRequest('/v1/billing/subscriptions', 'POST', [
        'plan_id'             => $planId,
        'application_context' => [
            'return_url'  => "$baseUrl/pages/deploy/?success=1&tier=$tier",
            'cancel_url'  => "$baseUrl/pages/deploy/?cancelled=1",
            'brand_name'  => 'Lyralink AI Hosting',
            'user_action' => 'SUBSCRIBE_NOW',
        ],
    ], $token, $paypalMode);

    if (empty($sub['id'])) { echo json_encode(['success'=>false,'error'=>'Failed to create subscription','detail'=>$sub]); exit; }

    $subId = $sub['id'];
    $status = 'pending';
    $stmt = $db->prepare("INSERT INTO pelican_deployments (user_id, tier, status, paypal_sub_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $uid, $tier, $status, $subId);
    $stmt->execute();
    $stmt->close();

    $approveUrl = '';
    foreach ($sub['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') { $approveUrl = $link['href']; break; }
    }

    echo json_encode(['success'=>true,'subscription_id'=>$sub['id'],'approve_url'=>$approveUrl]);
    exit;
}

// ── CONFIRM SUBSCRIPTION + PROVISION SERVER ──
if ($action === 'confirm_subscription') {
    $user  = requireUser($db);
    $subId = trim($_POST['subscription_id'] ?? '');
    $uid   = (int)$user['id'];

    // Verify subscription — skip for dev mode
    if (!$isDevMode) {
        $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
        $sub   = paypalRequest("/v1/billing/subscriptions/$subId", 'GET', [], $token, $paypalMode);
        if (($sub['status'] ?? '') !== 'ACTIVE') {
            echo json_encode(['success'=>false,'error'=>'Subscription not active yet','paypal_status'=>$sub['status']??'unknown']);
            exit;
        }
    }

    // Get deployment record
    $stmt = $db->prepare("SELECT * FROM pelican_deployments WHERE user_id = ? AND paypal_sub_id = ? LIMIT 1");
    $stmt->bind_param('is', $uid, $subId);
    $stmt->execute();
    $dep = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$dep) { echo json_encode(['success'=>false,'error'=>'Deployment record not found']); exit; }
    if ($dep['status'] === 'active') { echo json_encode(['success'=>true,'deployment'=>$dep,'already_active'=>true]); exit; }

    $tier     = $dep['tier'];
    $tierConf = $tiers[$tier];
    $depId    = (int)$dep['id'];

    // ── Step 1: Create Pelican user ──
    $email    = $user['email'] ?? $user['username'] . '@lyralink.ai';
    $username = 'lyra_' . $user['username'] . '_' . $depId;
    $password = bin2hex(random_bytes(12));

    $puRes = pelicanRequest($pelicanUrl, 'POST', [
        '_path'      => '/users',
        'email'      => $email,
        'username'   => $username,
        'first_name' => $user['username'],
        'last_name'  => 'AI',
        'password'   => $password,
    ], $pelicanApiKey);

    if ($puRes['code'] !== 201 && $puRes['code'] !== 200) {
        // User may already exist — try to find them
        $findRes = pelicanRequest($pelicanUrl, 'GET', ['_path'=>'/users?filter[email]='.$email], $pelicanApiKey);
        $pelUserId = $findRes['body']['data'][0]['attributes']['id'] ?? null;
    } else {
        $pelUserId = $puRes['body']['attributes']['id'] ?? null;
    }

    if (!$pelUserId) {
        echo json_encode(['success'=>false,'error'=>'Failed to create Pelican user','detail'=>$puRes]);
        exit;
    }

    // ── Step 2: Get allocation on node ──
    $allocRes = pelicanRequest($pelicanUrl, 'GET', ['_path'=>"/nodes/$pelicanNodeId/allocations"], $pelicanApiKey);
    $allocation = null;
    foreach ($allocRes['body']['data'] ?? [] as $alloc) {
        if (empty($alloc['attributes']['assigned'])) {
            $allocation = $alloc['attributes']['id'];
            break;
        }
    }
    if (!$allocation) { echo json_encode(['success'=>false,'error'=>'No free allocations on node']); exit; }

    // ── Step 3: Create server ──
    // Use a predictable, DNS-safe subdomain based on the user and deployment id.
    $serverName = preg_replace('/[^a-z0-9-]/', '', strtolower($user['username'] . '-ai-' . $depId));
    $subdomain  = $serverName;

    $srvRes = pelicanRequest($pelicanUrl, 'POST', [
        '_path'       => '/servers',
        'name'        => $serverName,
        'user'        => $pelUserId,
        'egg'         => $pelicanEggId,
        'docker_image'=> 'ghcr.io/david1117dev/lumenweb:1.0-beta',
        'startup'     => 'default',
        'environment' => [
            'DOMAIN' => $subdomain . '.cloudhavenx.com',
            'WEBROOT' => '/home/container/',
            'DOMAIN_MODE' => 'multi',
            'SOFTWARE' => "php 8.3",     
        ],
        'limits'      => [
            'memory'  => $tierConf['ram'],
            'swap'    => 0,
            'disk'    => $tierConf['disk'],
            'io'      => 500,
            'cpu'     => $tierConf['cpu'],
        ],
        'feature_limits' => [
            'databases'   => 1,
            'backups'     => 2,
            'allocations' => 1,
        ],
        'allocation'  => ['default' => $allocation],
    ], $pelicanApiKey);

    if ($srvRes['code'] !== 201 && $srvRes['code'] !== 200) {
        echo json_encode(['success'=>false,'error'=>'Failed to create server','detail'=>$srvRes]);
        exit;
    }

    $srvId   = $srvRes['body']['attributes']['id']   ?? null;
    $srvUuid = $srvRes['body']['attributes']['uuid']  ?? null;

    // ── Step 4: Get server port + node IP ──
    // Fetch the server detail to get the assigned port
    $srvDetail = pelicanRequest($pelicanUrl, 'GET', ['_path'=>"/servers/$srvId?include=allocations"], $pelicanApiKey);
    $srvPort   = null;
    $nodeIp    = null;
    foreach ($srvDetail['body']['relationships']['allocations']['data'] ?? [] as $alloc) {
        // Pelican uses `assigned` rather than `is_default` to indicate an active allocation.
        if (!empty($alloc['attributes']['assigned'])) {
            $srvPort = $alloc['attributes']['port'] ?? null;
            $nodeIp  = $alloc['attributes']['ip']   ?? null;
            break;
        }
    }
    // If no allocated entry was marked as assigned, fall back to first allocation.
    if (!$srvPort && !$nodeIp) {
        $firstAlloc = $srvDetail['body']['relationships']['allocations']['data'][0] ?? null;
        if ($firstAlloc) {
            $srvPort = $firstAlloc['attributes']['port'] ?? null;
            $nodeIp  = $firstAlloc['attributes']['ip']   ?? null;
        }
    }
    // Fallback: get node IP from node details
    if (!$nodeIp) {
        $nodeDetail = pelicanRequest($pelicanUrl, 'GET', ['_path'=>"/nodes/$pelicanNodeId"], $pelicanApiKey);
        $nodeIp     = $nodeDetail['body']['attributes']['fqdn'] ?? null;
    }

    // ── Step 5: Plesk subdomain + reverse-proxy (optional) ──
    // If enabled, create a subdomain in Plesk and configure it to proxy to the
    // Pelican node+port for this deployment.
    $pleskResult = ['enabled' => false];
    if ($enablePleskIntegration) {
        $pleskResult = ['enabled' => true];

        if (empty($nodeIp) || empty($srvPort)) {
            $pleskResult['success'] = false;
            $pleskResult['error']   = 'Missing node_ip or server_port for Plesk proxy';
        } else {
            $fullSubdomain = $subdomain . '.cloudhavenx.com';
            $pleskResult = pleskCreateSubdomain($pleskUrl, $pleskApiKey, $fullSubdomain, $nodeIp, $srvPort);
        }
    }

    // ── Step 6: Update DB ──
    $sub2        = $subdomain;
    $nodeIpSafe  = $nodeIp ?? null;
    $nextBilling = date('Y-m-d', strtotime('+1 month'));
    $activeStatus = 'active';
    $serverUuid = $srvUuid;
    $serverPort = $srvPort !== null ? (int)$srvPort : null;
    $stmt = $db->prepare("UPDATE pelican_deployments SET
        status = ?,
        pelican_user_id = ?,
        pelican_server_id = ?,
        server_uuid = ?,
        server_port = ?,
        node_ip = ?,
        subdomain = ?,
        next_billing = ?
        WHERE id = ?");
    $stmt->bind_param('siisisssi', $activeStatus, $pelUserId, $srvId, $serverUuid, $serverPort, $nodeIpSafe, $sub2, $nextBilling, $depId);
    $stmt->execute();
    $stmt->close();

    // Log billing event
    $eventType = 'subscription_start';
    $amount = (float)$tierConf['price'];
    $stmt = $db->prepare("INSERT INTO pelican_billing_events (deployment_id, event_type, amount, paypal_txn)
        VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isds', $depId, $eventType, $amount, $subId);
    $stmt->execute();
    $stmt->close();

    // Fetch updated record
    $stmt = $db->prepare("SELECT * FROM pelican_deployments WHERE id = ?");
    $stmt->bind_param('i', $depId);
    $stmt->execute();
    $dep = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'success'        => true,
        'deployment'     => $dep,
        'panel_url'      => $pelicanUrl,
        'subdomain_url'  => 'https://' . $subdomain . '.cloudhavenx.com',
        'credentials'    => ['username' => $username, 'password' => $password],
        'plesk'          => $pleskResult,
        'server_port'    => $srvPort,
        'node_ip'        => $nodeIp,
    ]);
    exit;
}

// ── CANCEL SUBSCRIPTION ──
if ($action === 'cancel') {
    $user  = requireUser($db);
    $uid   = (int)$user['id'];
    $activeStatus = 'active';
    $stmt = $db->prepare("SELECT * FROM pelican_deployments WHERE user_id = ? AND status = ? LIMIT 1");
    $stmt->bind_param('is', $uid, $activeStatus);
    $stmt->execute();
    $dep = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$dep) { echo json_encode(['success'=>false,'error'=>'No active deployment']); exit; }

    // Cancel PayPal subscription (skip for dev deployments)
    $subId = $dep['paypal_sub_id'];
    if (!$isDevMode && strpos($subId, 'DEV-') !== 0) {
        $token = getPaypalToken($paypalClientId, $paypalSecret, $paypalMode);
        paypalRequest("/v1/billing/subscriptions/$subId/cancel", 'POST', ['reason'=>'User requested cancellation'], $token, $paypalMode);
    }

    // Suspend server in Pelican
    $srvId = (int)$dep['pelican_server_id'];
    pelicanRequest($pelicanUrl, 'POST', ['_path'=>"/servers/$srvId/suspend"], $pelicanApiKey);

    $depId = (int)$dep['id'];
    $cancelledStatus = 'cancelled';
    $stmt = $db->prepare("UPDATE pelican_deployments SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $cancelledStatus, $depId);
    $stmt->execute();
    $stmt->close();

    $eventType = 'cancelled';
    $stmt = $db->prepare("INSERT INTO pelican_billing_events (deployment_id, event_type) VALUES (?, ?)");
    $stmt->bind_param('is', $depId, $eventType);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success'=>true]);
    exit;
}

// ── ADMIN: LIST ALL DEPLOYMENTS ──
if ($action === 'admin_list') {
    if (empty($_SESSION['is_admin'])) { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
    $deps = [];
    $stmt = $db->prepare("SELECT d.*, u.username, u.email FROM pelican_deployments d
        LEFT JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC LIMIT 200");
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r) { while ($row=$r->fetch_assoc()) $deps[] = $row; }
    $stmt->close();
    echo json_encode(['success'=>true,'deployments'=>$deps,'plesk_domain'=>$pleskDomain,'panel_url'=>$pelicanUrl]);
    exit;
}

// ── ADMIN: RETRY PLESK SUBDOMAIN ──
// This endpoint can be used to retry creating a Plesk reverse-proxy subdomain.
if ($action === 'admin_retry_plesk') {
    if (empty($_SESSION['is_admin'])) { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }

    if (!$enablePleskIntegration) {
        echo json_encode(['success'=>false,'error'=>'Plesk integration is disabled']);
        exit;
    }

    $depId = (int)($_POST['deployment_id'] ?? 0);
    if (!$depId) { echo json_encode(['success'=>false,'error'=>'deployment_id required']); exit; }

    $stmt = $db->prepare("SELECT * FROM pelican_deployments WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $depId);
    $stmt->execute();
    $dep = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$dep) { echo json_encode(['success'=>false,'error'=>'Deployment not found']); exit; }

    $nodeIp   = $dep['node_ip'];
    $srvPort  = $dep['server_port'];
    $subdomain = $dep['subdomain'];
    if (empty($nodeIp) || empty($srvPort) || empty($subdomain)) {
        echo json_encode(['success'=>false,'error'=>'Missing node_ip/server_port/subdomain on deployment']);
        exit;
    }

    $fullSubdomain = $subdomain . '.cloudhavenx.com';
    $pleskResult = pleskCreateSubdomain($pleskUrl, $pleskApiKey, $fullSubdomain, $nodeIp, $srvPort);

    echo json_encode(['success'=>true,'plesk'=>$pleskResult]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);