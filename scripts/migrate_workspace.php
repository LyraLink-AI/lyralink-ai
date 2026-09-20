<?php
/* ══════════════════════════════════════════════════════════════════════════
   LYRALINK — WORKSPACE SCHEMA (projects + files)

   Additive only. Every statement is CREATE TABLE IF NOT EXISTS, so running it
   twice is a no-op and it cannot touch existing data. No ALTER on any existing
   table - the link between a conversation and a project lives in its own table
   rather than as a new column on user_convs.

   Design notes, each tied to something that already exists rather than invented:

     projects / project_conversations
        The chat rail has had a "Projects" row with nothing behind it. A project
        groups conversations behind one objective. project_conversations is a
        link table because a conversation belongs to at most one project but a
        project has many, and adding a column to user_convs would mean altering a
        table with live rows.

     user_files
        Mirrors social_attachments column for column - uploader, storage_key,
        disk_path, original_name, mime_type, size_bytes - because that shape is
        already proven here and the same upload path can be reused. Adds
        project_id (nullable) so a file can belong to a project, and sha256 so a
        duplicate can be recognised later.

     Charset: utf8mb4. user_convs is still utf8mb3, but utf8mb4 is the correct
     choice for new tables and social_attachments already uses it.

   Run:  php scripts/migrate_workspace.php
   ══════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../api/security.php';

$cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db  = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
if ($db->connect_error) {
    fwrite(STDERR, "connect failed: {$db->connect_error}\n");
    exit(1);
}

$tables = [
    'projects' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    'project_conversations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `project_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `conv_id` varchar(40) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_conv` (`project_id`,`conv_id`),
  KEY `idx_conv` (`conv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    'user_files' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `storage_key` varchar(64) NOT NULL,
  `disk_path` varchar(255) NOT NULL,
  `original_name` varchar(180) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`storage_key`),
  KEY `idx_user` (`user_id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];

echo "=== LyraLink workspace schema ===\n";
$ok = true;
foreach ($tables as $name => $sql) {
    $existed = (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->real_escape_string($name) . "' LIMIT 1")->num_rows;
    if (!$db->query($sql)) {
        echo "  FAILED   $name :: {$db->error}\n";
        $ok = false;
        continue;
    }
    echo "  " . ($existed ? 'present  ' : 'created  ') . $name . "\n";
}

echo "\n=== verification ===\n";
foreach (array_keys($tables) as $name) {
    $r = $db->query("SHOW COLUMNS FROM `$name`");
    if (!$r) { echo "  $name MISSING\n"; $ok = false; continue; }
    $cols = [];
    while ($row = $r->fetch_assoc()) { $cols[] = $row['Field']; }
    printf("  %-24s %d cols: %s\n", $name, count($cols), implode(', ', $cols));
}

/* The upload directory. storage/ is denied over HTTP by .htaccess (verified
   live: 403), so bytes live here and are only ever served by api/workspace.php
   after an ownership check.

   OWNERSHIP MATTERS HERE. This script is normally run as root, and a directory
   created by root is not writable by PHP, which runs as the vhost user. That is
   a real failure, not a theoretical one: the first run of this migration created
   storage/user_files owned by root:root and every upload then failed with
   "Failed to create upload directory". The fix is not a one-off chown but to
   inherit ownership from storage/ itself, so this is correct on any install
   regardless of which user ran the script. */
$dir = dirname(__DIR__) . '/storage/user_files';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    echo "\n  WARNING: could not create storage/user_files\n";
    $ok = false;
} else {
    $parent   = dirname(__DIR__) . '/storage';
    $wantUser = fileowner($parent);
    $wantGrp  = filegroup($parent);
    $haveUser = fileowner($dir);
    $haveGrp  = filegroup($dir);
    if ($wantUser !== false && $wantGrp !== false && ($haveUser !== $wantUser || $haveGrp !== $wantGrp)) {
        if (@chown($dir, $wantUser) && @chgrp($dir, $wantGrp)) {
            echo "\n  storage/user_files ownership set from storage/ (uid $wantUser, gid $wantGrp)\n";
        } else {
            echo "\n  WARNING: could not set ownership on storage/user_files; PHP may not be able to write\n";
            $ok = false;
        }
    }
    @chmod($dir, 0755);
    /* Without this the report below reads a cached stat and can print the OLD
       owner, which is actively misleading right after a chown. */
    clearstatcache(true, $dir);
    $nowUid = fileowner($dir);
    $nowName = (function_exists('posix_getpwuid') && ($pw = @posix_getpwuid($nowUid))) ? $pw['name'] : (string) $nowUid;
    echo "  storage/user_files ready (owner: $nowName)\n";
}

echo $ok ? "\nOK\n" : "\nCOMPLETED WITH ERRORS\n";
$db->close();
exit($ok ? 0 : 1);
