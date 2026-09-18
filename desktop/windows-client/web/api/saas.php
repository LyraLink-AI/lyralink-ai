<?php
require_once __DIR__ . '/security.php';

function saas_bootstrap_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS organizations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(160) NOT NULL,
        slug VARCHAR(180) NOT NULL,
        owner_user_id INT NOT NULL,
        status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
        billing_email VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slug (slug),
        KEY idx_owner (owner_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS organization_members (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
        invited_by_user_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_org_user (org_id, user_id),
        KEY idx_user (user_id, org_id),
        KEY idx_org_role (org_id, role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS org_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL DEFAULT 'internal',
        provider_customer_id VARCHAR(128) DEFAULT NULL,
        provider_subscription_id VARCHAR(128) DEFAULT NULL,
        plan_code VARCHAR(32) NOT NULL DEFAULT 'free',
        billing_model ENUM('seat','usage','hybrid') NOT NULL DEFAULT 'seat',
        seat_count INT UNSIGNED NOT NULL DEFAULT 1,
        status ENUM('trialing','active','past_due','paused','canceled') NOT NULL DEFAULT 'active',
        trial_ends_at DATETIME DEFAULT NULL,
        current_period_start DATETIME DEFAULT NULL,
        current_period_end DATETIME DEFAULT NULL,
        cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
        tax_country CHAR(2) DEFAULT NULL,
        vat_id VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_org_subscription (org_id),
        KEY idx_provider_sub (provider, provider_subscription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS org_usage_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        user_id INT DEFAULT NULL,
        event_type VARCHAR(64) NOT NULL,
        quantity DECIMAL(16,4) NOT NULL DEFAULT 1,
        metadata JSON DEFAULT NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_org_type_time (org_id, event_type, occurred_at),
        KEY idx_org_time (org_id, occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS org_audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        actor_user_id INT DEFAULT NULL,
        action VARCHAR(80) NOT NULL,
        target_type VARCHAR(40) DEFAULT NULL,
        target_id VARCHAR(64) DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        details JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_org_action_time (org_id, action, created_at),
        KEY idx_org_time (org_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS org_webhook_secrets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        secret_hash CHAR(64) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rotated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_org_active_secret (org_id, active),
        KEY idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS saas_idempotency_keys (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        org_id INT UNSIGNED NOT NULL,
        scope VARCHAR(80) NOT NULL,
        idem_key VARCHAR(120) NOT NULL,
        response_hash CHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_org_scope_key (org_id, scope, idem_key),
        KEY idx_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS saas_rate_limits (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        bucket VARCHAR(40) NOT NULL,
        identifier VARCHAR(255) NOT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        blocked_until DATETIME DEFAULT NULL,
        last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_bucket_identifier (bucket, identifier),
        KEY idx_blocked_until (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS active_org_id INT NULL");
}

function saas_slugify(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'org';
    }
    return substr($slug, 0, 160);
}

function saas_ensure_personal_org(mysqli $db, int $userId, string $username): int {
    $q = $db->prepare("SELECT org_id, role FROM organization_members WHERE user_id = ? ORDER BY role='owner' DESC, id ASC LIMIT 1");
    if ($q) {
        $q->bind_param('i', $userId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row && !empty($row['org_id'])) {
            return (int)$row['org_id'];
        }
    }

    $baseName = trim($username) !== '' ? ($username . "'s Workspace") : ('Workspace ' . $userId);
    $baseSlug = saas_slugify($baseName) . '-' . $userId;
    $slug = $baseSlug;
    $i = 1;
    while (true) {
        $exists = $db->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
        if (!$exists) {
            break;
        }
        $exists->bind_param('s', $slug);
        $exists->execute();
        $dupe = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$dupe) {
            break;
        }
        $i++;
        $slug = substr($baseSlug, 0, 150) . '-' . $i;
    }

    $db->begin_transaction();
    try {
        $insOrg = $db->prepare("INSERT INTO organizations (name, slug, owner_user_id) VALUES (?, ?, ?)");
        if (!$insOrg) {
            throw new RuntimeException('Failed to create organization');
        }
        $insOrg->bind_param('ssi', $baseName, $slug, $userId);
        $insOrg->execute();
        $orgId = (int)$insOrg->insert_id;
        $insOrg->close();

        $insMem = $db->prepare("INSERT INTO organization_members (org_id, user_id, role, invited_by_user_id) VALUES (?, ?, 'owner', ?)");
        if (!$insMem) {
            throw new RuntimeException('Failed to create organization membership');
        }
        $insMem->bind_param('iii', $orgId, $userId, $userId);
        $insMem->execute();
        $insMem->close();

        $insSub = $db->prepare("INSERT INTO org_subscriptions (org_id, plan_code, billing_model, seat_count, status) VALUES (?, 'free', 'seat', 1, 'active')");
        if ($insSub) {
            $insSub->bind_param('i', $orgId);
            $insSub->execute();
            $insSub->close();
        }

        $upUser = $db->prepare("UPDATE users SET active_org_id = ? WHERE id = ?");
        if ($upUser) {
            $upUser->bind_param('ii', $orgId, $userId);
            $upUser->execute();
            $upUser->close();
        }

        $db->commit();
        return $orgId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function saas_context(mysqli $db, int $userId, string $username = ''): array {
    $orgId = 0;
    $activeStmt = $db->prepare("SELECT active_org_id FROM users WHERE id = ? LIMIT 1");
    if ($activeStmt) {
        $activeStmt->bind_param('i', $userId);
        $activeStmt->execute();
        $row = $activeStmt->get_result()->fetch_assoc();
        $activeStmt->close();
        $orgId = (int)($row['active_org_id'] ?? 0);
    }

    if ($orgId > 0) {
        $mem = $db->prepare("SELECT om.org_id, om.role, o.name AS org_name
            FROM organization_members om
            INNER JOIN organizations o ON o.id = om.org_id
            WHERE om.user_id = ? AND om.org_id = ? AND o.status = 'active'
            LIMIT 1");
        if ($mem) {
            $mem->bind_param('ii', $userId, $orgId);
            $mem->execute();
            $found = $mem->get_result()->fetch_assoc();
            $mem->close();
            if ($found) {
                return [
                    'org_id' => (int)$found['org_id'],
                    'org_name' => (string)$found['org_name'],
                    'role' => (string)$found['role'],
                ];
            }
        }
    }

    $orgId = saas_ensure_personal_org($db, $userId, $username);
    $lookup = $db->prepare("SELECT o.name AS org_name, om.role
        FROM organization_members om
        INNER JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ? AND om.org_id = ? LIMIT 1");
    $orgName = 'Workspace';
    $role = 'owner';
    if ($lookup) {
        $lookup->bind_param('ii', $userId, $orgId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if ($row) {
            $orgName = (string)$row['org_name'];
            $role = (string)$row['role'];
        }
    }

    return ['org_id' => $orgId, 'org_name' => $orgName, 'role' => $role];
}

function saas_require_role(array $ctx, array $allowed): void {
    if (!in_array($ctx['role'] ?? '', $allowed, true)) {
        api_fail('Forbidden: insufficient role', 403);
    }
}

function saas_get_org_plan(mysqli $db, int $orgId, string $fallbackPlan = 'free'): string {
    $stmt = $db->prepare("SELECT plan_code, status FROM org_subscriptions WHERE org_id = ? LIMIT 1");
    if (!$stmt) {
        return $fallbackPlan;
    }
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $fallbackPlan;
    }
    $status = (string)($row['status'] ?? 'active');
    if (!in_array($status, ['active', 'trialing'], true)) {
        return 'free';
    }
    $plan = trim((string)($row['plan_code'] ?? ''));
    return $plan !== '' ? $plan : $fallbackPlan;
}

function saas_record_usage(mysqli $db, int $orgId, int $userId, string $eventType, float $quantity = 1.0, ?array $metadata = null): void {
    $meta = $metadata ? json_encode($metadata) : null;
    $stmt = $db->prepare("INSERT INTO org_usage_events (org_id, user_id, event_type, quantity, metadata) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iisds', $orgId, $userId, $eventType, $quantity, $meta);
    $stmt->execute();
    $stmt->close();
}

function saas_record_audit(mysqli $db, int $orgId, ?int $actorUserId, string $action, ?string $targetType = null, ?string $targetId = null, ?array $details = null): void {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255);
    $detailsJson = $details ? json_encode($details) : null;

    $stmt = $db->prepare("INSERT INTO org_audit_logs (org_id, actor_user_id, action, target_type, target_id, ip, user_agent, details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iissssss', $orgId, $actorUserId, $action, $targetType, $targetId, $ip, $ua, $detailsJson);
    $stmt->execute();
    $stmt->close();
}

function saas_is_idempotent_request(mysqli $db, int $orgId, string $scope, string $idemKey, int $ttlSeconds = 900): bool {
    $idemKey = trim($idemKey);
    if ($idemKey === '' || strlen($idemKey) > 120) {
        return false;
    }

    $db->query("DELETE FROM saas_idempotency_keys WHERE expires_at < NOW() LIMIT 500");

    $expiresAt = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
    $stmt = $db->prepare("INSERT INTO saas_idempotency_keys (org_id, scope, idem_key, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isss', $orgId, $scope, $idemKey, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();
    return !$ok;
}

function saas_rate_limit_check(mysqli $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds, int $lockoutSeconds): array {
    $stmt = $db->prepare("SELECT id, attempts, window_start, blocked_until FROM saas_rate_limits WHERE bucket = ? AND identifier = ? LIMIT 1");
    if (!$stmt) {
        return ['blocked' => false, 'retry_after' => 0, 'attempts' => 0];
    }
    $stmt->bind_param('ss', $bucket, $identifier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = time();
    if (!$row) {
        return ['blocked' => false, 'retry_after' => 0, 'attempts' => 0];
    }

    $windowStart = strtotime((string)$row['window_start']) ?: $now;
    if (($now - $windowStart) >= $windowSeconds) {
        $reset = $db->prepare("UPDATE saas_rate_limits SET attempts = 0, window_start = NOW(), blocked_until = NULL, last_attempt_at = NOW() WHERE id = ?");
        if ($reset) {
            $id = (int)$row['id'];
            $reset->bind_param('i', $id);
            $reset->execute();
            $reset->close();
        }
        return ['blocked' => false, 'retry_after' => 0, 'attempts' => 0];
    }

    $blockedUntil = !empty($row['blocked_until']) ? (strtotime((string)$row['blocked_until']) ?: 0) : 0;
    if ($blockedUntil > $now) {
        return ['blocked' => true, 'retry_after' => ($blockedUntil - $now), 'attempts' => (int)$row['attempts']];
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        $block = $db->prepare("UPDATE saas_rate_limits SET blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), last_attempt_at = NOW() WHERE id = ?");
        if ($block) {
            $id = (int)$row['id'];
            $lock = $lockoutSeconds;
            $block->bind_param('ii', $lock, $id);
            $block->execute();
            $block->close();
        }
        return ['blocked' => true, 'retry_after' => $lockoutSeconds, 'attempts' => (int)$row['attempts']];
    }

    return ['blocked' => false, 'retry_after' => 0, 'attempts' => (int)$row['attempts']];
}

function saas_rate_limit_fail(mysqli $db, string $bucket, string $identifier, int $windowSeconds, int $maxAttempts, int $lockoutSeconds): void {
    $stmt = $db->prepare("INSERT INTO saas_rate_limits (bucket, identifier, attempts, window_start, last_attempt_at) VALUES (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            attempts = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1),
            window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, NOW(), window_start),
            blocked_until = IF(IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1) >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), blocked_until),
            last_attempt_at = NOW()");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ssiiiii', $bucket, $identifier, $windowSeconds, $windowSeconds, $windowSeconds, $maxAttempts, $lockoutSeconds);
    $stmt->execute();
    $stmt->close();
}

function saas_webhook_signature_secret(mysqli $db, int $orgId): ?string {
    $stmt = $db->prepare("SELECT secret_hash FROM org_webhook_secrets WHERE org_id = ? AND active = 1 LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['secret_hash'])) {
        return null;
    }
    return (string)$row['secret_hash'];
}

function saas_webhook_signature_header(string $payload, string $secret): string {
    return 'sha256=' . hash_hmac('sha256', $payload, $secret);
}
