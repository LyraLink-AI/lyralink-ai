<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';
lyra_session_boot();
api_json_headers();

$dbCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('DB error', 500);
}
$db->set_charset('utf8mb4');
saas_bootstrap_schema($db);

$uid = 0;
$username = '';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $username = (string)($_SESSION['username'] ?? '');
} else {
    $mobileUser = api_try_mobile_token_auth($db);
    if ($mobileUser) {
        $uid = (int)$mobileUser['id'];
        $username = (string)($mobileUser['username'] ?? '');
    }
}
if (!$uid) {
    api_fail('Not logged in', 401);
}

$action = api_action();
api_enforce_post_and_origin_for_actions(['create_org', 'switch_org', 'add_member', 'set_role', 'remove_member']);

$ctx = saas_context($db, $uid, $username);
$orgId = (int)$ctx['org_id'];

if ($action === 'context') {
    echo json_encode(['success' => true, 'context' => $ctx]);
    exit;
}

if ($action === 'list_orgs') {
    $stmt = $db->prepare("SELECT o.id, o.name, o.slug, o.status, om.role
        FROM organization_members om
        INNER JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ?
        ORDER BY o.name ASC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $orgs = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $orgs[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'orgs' => $orgs, 'active_org_id' => $orgId]);
    exit;
}

if ($action === 'create_org') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) {
        api_fail('Organization name is required (max 160 chars)');
    }

    $slug = saas_slugify($name);
    $baseSlug = $slug;
    $n = 1;
    while (true) {
        $chk = $db->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
        if (!$chk) {
            api_fail('Could not verify slug uniqueness', 500);
        }
        $chk->bind_param('s', $slug);
        $chk->execute();
        $dupe = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$dupe) {
            break;
        }
        $n++;
        $slug = substr($baseSlug, 0, 150) . '-' . $n;
    }

    $db->begin_transaction();
    try {
        $insOrg = $db->prepare("INSERT INTO organizations (name, slug, owner_user_id) VALUES (?, ?, ?)");
        $insOrg->bind_param('ssi', $name, $slug, $uid);
        $insOrg->execute();
        $newOrgId = (int)$insOrg->insert_id;
        $insOrg->close();

        $insMember = $db->prepare("INSERT INTO organization_members (org_id, user_id, role, invited_by_user_id) VALUES (?, ?, 'owner', ?)");
        $insMember->bind_param('iii', $newOrgId, $uid, $uid);
        $insMember->execute();
        $insMember->close();

        $insSub = $db->prepare("INSERT INTO org_subscriptions (org_id, plan_code, billing_model, seat_count, status) VALUES (?, 'free', 'seat', 1, 'active')");
        if ($insSub) {
            $insSub->bind_param('i', $newOrgId);
            $insSub->execute();
            $insSub->close();
        }

        $switch = $db->prepare("UPDATE users SET active_org_id = ? WHERE id = ?");
        $switch->bind_param('ii', $newOrgId, $uid);
        $switch->execute();
        $switch->close();

        saas_record_audit($db, $newOrgId, $uid, 'org.create', 'organization', (string)$newOrgId, ['name' => $name]);
        $db->commit();

        echo json_encode(['success' => true, 'org_id' => $newOrgId, 'slug' => $slug]);
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        api_fail('Failed to create organization', 500);
    }
}

if ($action === 'switch_org') {
    $targetOrg = (int)($_POST['org_id'] ?? 0);
    if ($targetOrg <= 0) {
        api_fail('org_id is required');
    }

    $check = $db->prepare("SELECT role FROM organization_members WHERE org_id = ? AND user_id = ? LIMIT 1");
    $check->bind_param('ii', $targetOrg, $uid);
    $check->execute();
    $member = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$member) {
        api_fail('You are not a member of that organization', 403);
    }

    $up = $db->prepare("UPDATE users SET active_org_id = ? WHERE id = ?");
    $up->bind_param('ii', $targetOrg, $uid);
    $up->execute();
    $up->close();

    saas_record_audit($db, $targetOrg, $uid, 'org.switch', 'organization', (string)$targetOrg);
    echo json_encode(['success' => true, 'org_id' => $targetOrg]);
    exit;
}

if ($action === 'add_member') {
    saas_require_role($ctx, ['owner', 'admin']);

    $identifier = trim((string)($_POST['user'] ?? ''));
    $role = strtolower(trim((string)($_POST['role'] ?? 'member')));
    if (!in_array($role, ['admin', 'member'], true)) {
        $role = 'member';
    }
    if ($identifier === '') {
        api_fail('user is required');
    }

    if (ctype_digit($identifier)) {
        $find = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $userId = (int)$identifier;
        $find->bind_param('i', $userId);
    } else {
        $find = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $find->bind_param('ss', $identifier, $identifier);
    }
    $find->execute();
    $user = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$user) {
        api_fail('Target user not found');
    }

    $targetUserId = (int)$user['id'];
    $ins = $db->prepare("INSERT INTO organization_members (org_id, user_id, role, invited_by_user_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE role = VALUES(role), invited_by_user_id = VALUES(invited_by_user_id)");
    $ins->bind_param('iisi', $orgId, $targetUserId, $role, $uid);
    $ins->execute();
    $ins->close();

    saas_record_audit($db, $orgId, $uid, 'org.member.add', 'user', (string)$targetUserId, ['role' => $role]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'set_role') {
    saas_require_role($ctx, ['owner']);

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $role = strtolower(trim((string)($_POST['role'] ?? 'member')));
    if ($targetUserId <= 0 || !in_array($role, ['owner', 'admin', 'member'], true)) {
        api_fail('Invalid user_id or role');
    }

    if ($targetUserId === $uid && $role !== 'owner') {
        api_fail('Owner cannot demote themselves');
    }

    $up = $db->prepare("UPDATE organization_members SET role = ? WHERE org_id = ? AND user_id = ?");
    $up->bind_param('sii', $role, $orgId, $targetUserId);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if (!$ok) {
        api_fail('Membership not found');
    }

    saas_record_audit($db, $orgId, $uid, 'org.member.role', 'user', (string)$targetUserId, ['role' => $role]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'remove_member') {
    saas_require_role($ctx, ['owner']);

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        api_fail('user_id is required');
    }
    if ($targetUserId === $uid) {
        api_fail('Owner cannot remove themselves');
    }

    $del = $db->prepare("DELETE FROM organization_members WHERE org_id = ? AND user_id = ?");
    $del->bind_param('ii', $orgId, $targetUserId);
    $del->execute();
    $ok = $del->affected_rows > 0;
    $del->close();
    if (!$ok) {
        api_fail('Membership not found');
    }

    saas_record_audit($db, $orgId, $uid, 'org.member.remove', 'user', (string)$targetUserId);
    echo json_encode(['success' => true]);
    exit;
}

api_fail('Unknown action');
