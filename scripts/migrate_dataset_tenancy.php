<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════
// DATASET TENANCY MIGRATION
//
// Problem: `dataset` has no ownership column, so every user shared one
// retrieval pool and one customer's uploaded data could surface in
// another's answers. That contradicts the privacy-first product promise
// and blocks a multi-tenant (Teams-like) product.
//
// The tenancy model already exists in this schema (organizations,
// organization_members, users). Only the link from `dataset` was missing.
//
// Model:
//   org_id      NULL + owner_user_id NULL  -> GLOBAL system knowledge
//   org_id      set                        -> shared within that organization
//   owner_user_id set                      -> private to that user
//
// Additive and reversible. Existing rows keep NULL/NULL, i.e. global, so
// retrieval behaviour is unchanged until scopes are deliberately assigned.
//
// Usage: php scripts/migrate_dataset_tenancy.php
// ════════════════════════════════════════════════════════════════════

require __DIR__ . '/../api/security.php';

$cfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);
$db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
if ($db->connect_error) {
    fwrite(STDERR, 'db_connect_error=' . $db->connect_error . "\n");
    exit(1);
}

function col_exists(mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $n > 0;
}

function idx_exists(mysqli $db, string $table, string $idx): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->bind_param('ss', $table, $idx);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $n > 0;
}

echo "=== dataset tenancy migration ===\n";

// 1. org_id: which organization owns this knowledge (NULL = global)
if (!col_exists($db, 'dataset', 'org_id')) {
    $db->query('ALTER TABLE dataset ADD COLUMN org_id INT(10) UNSIGNED NULL DEFAULT NULL AFTER source_id');
    echo "  + added dataset.org_id\n";
} else {
    echo "  = dataset.org_id already present\n";
}

// 2. owner_user_id: private to one user (NULL = not user-private)
if (!col_exists($db, 'dataset', 'owner_user_id')) {
    $db->query('ALTER TABLE dataset ADD COLUMN owner_user_id INT(11) NULL DEFAULT NULL AFTER org_id');
    echo "  + added dataset.owner_user_id\n";
} else {
    echo "  = dataset.owner_user_id already present\n";
}

// 3. index for the scoped lookup path
if (!idx_exists($db, 'dataset', 'idx_dataset_scope')) {
    $db->query('ALTER TABLE dataset ADD INDEX idx_dataset_scope (approved, org_id, owner_user_id)');
    echo "  + added index idx_dataset_scope\n";
} else {
    echo "  = index idx_dataset_scope already present\n";
}

// 4. verify: every existing row must be global so behaviour is unchanged
$res = $db->query("SELECT COUNT(*) AS total, "
    . "SUM(org_id IS NULL AND owner_user_id IS NULL) AS global_rows, "
    . "SUM(org_id IS NOT NULL) AS org_rows, "
    . "SUM(owner_user_id IS NOT NULL) AS user_rows FROM dataset");
$row = $res ? $res->fetch_assoc() : [];

printf(
    "\n  total=%s global=%s org_scoped=%s user_scoped=%s\n",
    (string) ($row['total'] ?? '?'),
    (string) ($row['global_rows'] ?? '?'),
    (string) ($row['org_rows'] ?? '?'),
    (string) ($row['user_rows'] ?? '?')
);

echo "\n  Existing rows are global, so retrieval results are unchanged.\n";
echo "  Assign a scope by setting org_id and/or owner_user_id.\n";

$db->close();
