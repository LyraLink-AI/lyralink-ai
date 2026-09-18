<?php
require_once __DIR__ . '/security.php';
session_start();
api_json_headers();

api_enforce_post_and_origin_for_actions([
    'create_mailbox',
    'delete_mailbox',
    'reset_mailbox_password',
    'create_sso_ticket',
    'my_sso_ticket',
]);

function mail_admin_dev_username(): string {
    return (string)(api_get_secret('ADMIN_DEV_USERNAME', 'developer') ?? 'developer');
}

function mail_admin_require_dev(): void {
    $username = (string)($_SESSION['username'] ?? '');
    if ($username === '' || $username !== mail_admin_dev_username()) {
        api_fail('Forbidden', 403);
    }
}

function mail_admin_require_authenticated(): void {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        api_fail('Not authenticated', 401);
    }
}

function mail_admin_session_email(): string {
    return strtolower(trim((string)($_SESSION['user_email'] ?? '')));
}

function mail_admin_mailbox_exists(string $email): bool {
    $list = mail_admin_list_mailboxes();
    return $list['success'] && in_array($email, $list['mailboxes'], true);
}

function mail_admin_domain(): string {
    $domain = trim((string)(api_get_secret('MAIL_ADMIN_DOMAIN', '')));
    if ($domain !== '') {
        return $domain;
    }
    return '';
}

function mail_admin_webmail_url(): string {
    $url = trim((string)(api_get_secret('MAIL_WEBMAIL_URL', '')));
    if ($url !== '') {
        return $url;
    }
    return '/webmail/?_task=login';
}

function mail_admin_run(string $command): array {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => 1, 'out' => '', 'err' => 'Failed to start process'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'out' => trim((string)$stdout),
        'err' => trim((string)$stderr),
    ];
}

function mail_admin_runtime_identity(): array {
    $uid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
    $name = 'unknown';
    if ($uid >= 0 && function_exists('posix_getpwuid')) {
        $row = @posix_getpwuid($uid);
        if (is_array($row) && !empty($row['name'])) {
            $name = (string)$row['name'];
        }
    }
    return ['uid' => $uid, 'user' => $name];
}

function mail_admin_run_privileged(string $command): array {
    $id = mail_admin_runtime_identity();
    $asRoot = $id['uid'] === 0;
    if ($asRoot) {
        return mail_admin_run($command);
    }

    $allowSudo = api_get_secret('MAIL_ADMIN_USE_SUDO', '1') === '1';
    if ($allowSudo) {
        $sudoCmd = 'sudo -n ' . $command;
        $r = mail_admin_run($sudoCmd);
        if ($r['ok']) {
            return $r;
        }

        $err = strtolower(trim(($r['err'] !== '' ? $r['err'] : $r['out'])));
        $sudoAuthFailure = str_contains($err, 'a password is required')
            || str_contains($err, 'is not in the sudoers file')
            || str_contains($err, 'no tty present')
            || str_contains($err, 'a terminal is required');

        if (!$sudoAuthFailure) {
            return $r;
        }

        return [
            'ok' => false,
            'code' => 1,
            'out' => '',
            'err' => 'Mail admin requires sudo access for runtime user "' . $id['user'] . '" (uid ' . $id['uid'] . '). sudo error: ' . ($r['err'] !== '' ? $r['err'] : $r['out']),
        ];
    }

    return [
        'ok' => false,
        'code' => 1,
        'out' => '',
        'err' => 'Mail admin requires root privileges. Runtime user is "' . $id['user'] . '" (uid ' . $id['uid'] . '). Configure sudo for this user to run "plesk bin mail" without password.',
    ];
}

function mail_admin_plesk_available(): bool {
    $r = mail_admin_run('command -v plesk');
    return $r['ok'] && $r['out'] !== '';
}

function mail_admin_valid_mailbox(string $email): bool {
    if (strlen($email) > 190) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return true;
}

function mail_admin_extract_email(string $line): ?string {
    if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $line, $m)) {
        $email = strtolower(trim((string)$m[1]));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
    return null;
}

function mail_admin_list_mailboxes(string $domain = ''): array {
    $domain = strtolower(trim($domain));
    $cmd = $domain !== ''
        ? ('plesk bin mail --list ' . escapeshellarg($domain) . ' 2>&1')
        : 'plesk bin mail --list 2>&1';

    $r = mail_admin_run_privileged($cmd);
    if (!$r['ok']) {
        return ['success' => false, 'error' => $r['err'] !== '' ? $r['err'] : $r['out']];
    }

    $items = [];
    foreach (preg_split('/\r?\n/', (string)$r['out']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $email = mail_admin_extract_email($line);
        if ($email === null && str_contains($line, '@')) {
            $email = strtolower(trim($line));
        }
        if ($email === null) {
            continue;
        }

        if ($domain !== '') {
            $suffix = '@' . $domain;
            if (!str_ends_with($email, $suffix)) {
                continue;
            }
        }

        if (!mail_admin_valid_mailbox($email)) {
            continue;
        }

        $items[] = $email;
    }

    $items = array_values(array_unique($items));
    sort($items);
    return ['success' => true, 'mailboxes' => $items];
}

function mail_admin_password_valid(string $password): bool {
    $len = strlen($password);
    return $len >= 10 && $len <= 128;
}

$action = api_action();

// Staff-accessible actions do not require dev; all others do.
$_staffActions = ['my_mail_status', 'my_sso_ticket'];
if (!in_array($action, $_staffActions, true)) {
    mail_admin_require_dev();
}

if (!mail_admin_plesk_available()) {
    api_fail('Plesk CLI is not available', 500);
}

if ($action === 'status') {
    $domain = mail_admin_domain();
    $webmailUrl = mail_admin_webmail_url();
    $list = mail_admin_list_mailboxes($domain);
    if (!$list['success']) {
        api_fail('Could not list mailboxes: ' . $list['error'], 500);
    }

    echo json_encode([
        'success' => true,
        'domain' => $domain,
        'scope' => $domain !== '' ? ('domain:' . $domain) : 'all-domains',
        'webmail_url' => $webmailUrl,
        'mailboxes' => $list['mailboxes'],
        'runtime' => mail_admin_runtime_identity(),
    ]);
    exit;
}

if ($action === 'diagnostics') {
    $id = mail_admin_runtime_identity();
    $test = mail_admin_run_privileged('plesk bin mail --list 2>&1');
    echo json_encode([
        'success' => true,
        'runtime' => $id,
        'sudo_enabled' => api_get_secret('MAIL_ADMIN_USE_SUDO', '1') === '1',
        'test_ok' => (bool)$test['ok'],
        'test_code' => (int)$test['code'],
        'test_error' => (string)$test['err'],
        'test_out_head' => substr((string)$test['out'], 0, 300),
    ]);
    exit;
}

if ($action === 'create_mailbox') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!mail_admin_valid_mailbox($email)) {
        api_fail('Invalid email address');
    }
    if (!mail_admin_password_valid($password)) {
        api_fail('Password must be between 10 and 128 characters');
    }

    $cmd = 'plesk bin mail --create ' . escapeshellarg($email)
        . ' -passwd ' . escapeshellarg($password)
        . ' -mailbox true 2>&1';
    $r = mail_admin_run_privileged($cmd);
    if (!$r['ok']) {
        api_fail('Create mailbox failed: ' . ($r['err'] !== '' ? $r['err'] : $r['out']), 500);
    }

    echo json_encode(['success' => true, 'message' => 'Mailbox created']);
    exit;
}

if ($action === 'delete_mailbox') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!mail_admin_valid_mailbox($email)) {
        api_fail('Invalid email address');
    }

    $cmd = 'plesk bin mail --remove ' . escapeshellarg($email) . ' 2>&1';
    $r = mail_admin_run_privileged($cmd);
    if (!$r['ok']) {
        api_fail('Delete mailbox failed: ' . ($r['err'] !== '' ? $r['err'] : $r['out']), 500);
    }

    echo json_encode(['success' => true, 'message' => 'Mailbox deleted']);
    exit;
}

if ($action === 'reset_mailbox_password') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!mail_admin_valid_mailbox($email)) {
        api_fail('Invalid email address');
    }
    if (!mail_admin_password_valid($password)) {
        api_fail('Password must be between 10 and 128 characters');
    }

    $cmd = 'plesk bin mail --update ' . escapeshellarg($email)
        . ' -passwd ' . escapeshellarg($password)
        . ' 2>&1';
    $r = mail_admin_run_privileged($cmd);
    if (!$r['ok']) {
        api_fail('Password reset failed: ' . ($r['err'] !== '' ? $r['err'] : $r['out']), 500);
    }

    echo json_encode(['success' => true, 'message' => 'Mailbox password updated']);
    exit;
}

if ($action === 'create_sso_ticket') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!mail_admin_valid_mailbox($email)) {
        api_fail('Invalid email address');
    }
    if (!mail_admin_password_valid($password)) {
        api_fail('Password must be between 10 and 128 characters');
    }

    $ticket = bin2hex(random_bytes(18));
    if (!isset($_SESSION['mail_sso_tickets']) || !is_array($_SESSION['mail_sso_tickets'])) {
        $_SESSION['mail_sso_tickets'] = [];
    }

    $_SESSION['mail_sso_tickets'][$ticket] = [
        'email' => $email,
        'password' => $password,
        'webmail_url' => mail_admin_webmail_url(),
        'created_at' => time(),
        'expires_at' => time() + 90,
    ];

    echo json_encode([
        'success' => true,
        'launch_url' => '/pages/mail_sso.php?t=' . rawurlencode($ticket),
        'expires_in' => 90,
    ]);
    exit;
}

if ($action === 'my_mail_status') {
    mail_admin_require_authenticated();
    $email = mail_admin_session_email();
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => true, 'has_mailbox' => false, 'reason' => 'no_email']);
        exit;
    }
    $exists = mail_admin_mailbox_exists($email);
    echo json_encode([
        'success' => true,
        'email' => $email,
        'has_mailbox' => $exists,
    ]);
    exit;
}

if ($action === 'my_sso_ticket') {
    mail_admin_require_authenticated();
    $email = mail_admin_session_email();
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail('No valid email address on your account', 400);
    }
    $password = (string)($_POST['password'] ?? '');
    if (!mail_admin_password_valid($password)) {
        api_fail('Password must be between 10 and 128 characters', 400);
    }
    // Verify the mailbox belongs to this user's account email and exists in Plesk
    if (!mail_admin_mailbox_exists($email)) {
        api_fail('No Plesk mailbox found for your account email', 404);
    }
    $ticket = bin2hex(random_bytes(18));
    if (!isset($_SESSION['mail_sso_tickets']) || !is_array($_SESSION['mail_sso_tickets'])) {
        $_SESSION['mail_sso_tickets'] = [];
    }
    $_SESSION['mail_sso_tickets'][$ticket] = [
        'email'       => $email,
        'password'    => $password,
        'webmail_url' => mail_admin_webmail_url(),
        'created_at'  => time(),
        'expires_at'  => time() + 90,
    ];
    echo json_encode([
        'success'    => true,
        'launch_url' => '/pages/mail_sso.php?t=' . rawurlencode($ticket),
        'expires_in' => 90,
    ]);
    exit;
}

api_fail('Unknown action', 404);
