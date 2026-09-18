<?php
require_once __DIR__ . '/../api/marketing_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function marketing_access_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SESSION = [];
marketing_access_assert(marketing_is_admin_session() === false, 'Empty session must not be admin');

$_SESSION['username'] = 'not-admin-user';
unset($_SESSION['is_admin']);
marketing_access_assert(marketing_is_admin_session() === false, 'Non-admin username must not pass admin gate');

$_SESSION['is_admin'] = 1;
marketing_access_assert(marketing_is_admin_session() === true, 'is_admin session flag should pass admin gate');

$authDefault = marketing_authorization_level('publish');
marketing_access_assert($authDefault['allow'] === false, 'Public publishing must default to blocked');

$authAllow = marketing_authorization_level('publish', ['MARKETING_ALLOW_PUBLIC_POSTS' => '1']);
marketing_access_assert($authAllow['allow'] === true, 'Explicit authorization must enable publishing');

echo "marketing access control tests passed\n";
