<?php
/**
 * Continuous model learning pipeline.
 *
 * Safe default behavior:
 * - exports approved dataset rows into JSONL corpora for future tuning
 * - tracks incremental learning state from dataset rows promoted by dataset_auto_learn
 * - optionally runs an operator-provided fine-tune command when enabled
 *
 * This does not mutate the live model unless CONTINUOUS_FINETUNE_ENABLED=1
 * and CONTINUOUS_FINETUNE_COMMAND is explicitly configured.
 */

require __DIR__ . '/../api/security.php';

date_default_timezone_set('UTC');

function learning_log(string $message): void {
    echo '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function learning_sanitize_output(string $output): string {
    if ($output === '') {
        return '';
    }

    $patterns = [
        "/This server is powered by Plesk\\.\\s*\n\nRun the 'plesk login' command and log in by browsing either of the links received in the output\\.\\s*\nUse the 'plesk' command to manage the server\\. Run 'plesk help' for more info\\.\\s*/i",
        "/^This server is powered by Plesk\\.\\s*$/mi",
        "/^Run the 'plesk login' command and log in by browsing either of the links received in the output\\.\\s*$/mi",
        "/^Use the 'plesk' command to manage the server\\. Run 'plesk help' for more info\\.\\s*$/mi",
    ];

    $clean = preg_replace($patterns, "", $output);
    if (!is_string($clean)) {
        return trim($output);
    }

    $clean = preg_replace("/\n{3,}/", "\n\n", $clean);
    return trim((string)$clean);
}

function learning_exec(string $command, int $timeoutSec = 3600): array {
    $wrapped = 'timeout ' . max(1, $timeoutSec) . 's bash --noprofile --norc -lc ' . escapeshellarg($command) . ' 2>&1';
    $output = [];
    $code = 0;
    @exec($wrapped, $output, $code);
    $joinedOutput = trim(implode("\n", $output));
    $sanitizedOutput = learning_sanitize_output($joinedOutput);
    return [
        'command' => $command,
        'exit_code' => $code,
        'output' => $sanitizedOutput,
    ];
}

function learning_ensure_tables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS model_learning_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NOT NULL,
        dataset_rows INT NOT NULL DEFAULT 0,
        new_rows INT NOT NULL DEFAULT 0,
        last_dataset_id INT NOT NULL DEFAULT 0,
        full_export_path VARCHAR(500) DEFAULT NULL,
        incremental_export_path VARCHAR(500) DEFAULT NULL,
        train_enabled TINYINT(1) NOT NULL DEFAULT 0,
        train_attempted TINYINT(1) NOT NULL DEFAULT 0,
        train_ok TINYINT(1) NOT NULL DEFAULT 0,
        train_command TEXT DEFAULT NULL,
        train_output LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_started_at (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS model_learning_state (
        state_key VARCHAR(100) PRIMARY KEY,
        state_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function learning_state_get(mysqli $db, string $key, string $default = ''): string {
    $stmt = $db->prepare("SELECT state_value FROM model_learning_state WHERE state_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ? (string)($row['state_value'] ?? $default) : $default;
}

function learning_state_set(mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT INTO model_learning_state (state_key, state_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function learning_training_row(array $row): array {
    $question = trim((string)($row['question'] ?? ''));
    $answer = trim((string)($row['answer'] ?? ''));
    return [
        'messages' => [
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => $answer],
        ],
        'metadata' => [
            'dataset_id' => (int)($row['id'] ?? 0),
            'source_id' => (int)($row['source_id'] ?? 0),
            'keywords' => (string)($row['keywords'] ?? ''),
            'sample_weight' => 1.0,
            'training_source' => 'dataset',
        ],
    ];
}

function learning_normalize_sample_weight($value): float {
    $weight = is_numeric($value) ? (float)$value : 1.0;
    if ($weight < 0.1) {
        return 0.1;
    }
    if ($weight > 3.0) {
        return 3.0;
    }
    return $weight;
}

function learning_runtime_training_row(array $row): ?array {
    $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
    $user = '';
    $assistant = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $role = strtolower(trim((string)($messages[$i]['role'] ?? '')));
        $content = trim((string)($messages[$i]['content'] ?? ''));
        if ($assistant === '' && $role === 'assistant' && $content !== '') {
            $assistant = $content;
        }
        if ($user === '' && $role === 'user' && $content !== '') {
            $user = $content;
        }
        if ($user !== '' && $assistant !== '') {
            break;
        }
    }

    if ($user === '' || $assistant === '') {
        return null;
    }

    $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $metadata['sample_weight'] = learning_normalize_sample_weight($metadata['sample_weight'] ?? 1.0);
    $metadata['training_source'] = 'runtime_feedback';

    return [
        'messages' => [
            ['role' => 'user', 'content' => $user],
            ['role' => 'assistant', 'content' => $assistant],
        ],
        'metadata' => $metadata,
    ];
}

function learning_hf_training_row(array $row): ?array {
    $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
    if (!empty($messages)) {
        return [
            'messages' => $messages,
            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : ['training_source' => 'hf_load_dataset'],
        ];
    }

    $question = trim((string)($row['question'] ?? ''));
    $answer = trim((string)($row['answer'] ?? ''));
    if ($question === '' || $answer === '') {
        return null;
    }

    return [
        'messages' => [
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => $answer],
        ],
        'metadata' => ['training_source' => 'hf_load_dataset'],
    ];
}

function learning_read_jsonl_rows(string $path, int $fromLine = 1): array {
    if (!is_file($path)) {
        return ['rows' => [], 'total_lines' => 0];
    }

    $rows = [];
    $lineNo = 0;
    $start = max(1, $fromLine);
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return ['rows' => [], 'total_lines' => 0];
    }

    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        if ($lineNo < $start) {
            continue;
        }
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    @fclose($handle);

    return ['rows' => $rows, 'total_lines' => $lineNo];
}

function learning_write_jsonl(string $path, array $rows): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $handle = @fopen($path, 'wb');
    if (!$handle) {
        return false;
    }

    foreach ($rows as $row) {
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line) || @fwrite($handle, $line . "\n") === false) {
            @fclose($handle);
            return false;
        }
    }

    @fclose($handle);
    return true;
}

function learning_path_debug(string $path): string {
    $dir = dirname($path);
    $dirExists = is_dir($dir) ? 'yes' : 'no';
    $dirWritable = is_writable($dir) ? 'yes' : 'no';
    $pathExists = file_exists($path) ? 'yes' : 'no';
    $pathWritable = file_exists($path) ? (is_writable($path) ? 'yes' : 'no') : 'n/a';
    $owner = @fileowner($dir);
    $group = @filegroup($dir);
    $perms = @fileperms($dir);
    $ownerVal = $owner === false ? 'n/a' : (string)$owner;
    $groupVal = $group === false ? 'n/a' : (string)$group;
    $permsVal = $perms === false ? 'n/a' : substr(sprintf('%o', $perms), -4);
    $lastErr = error_get_last();
    $lastErrMsg = is_array($lastErr) ? (string)($lastErr['message'] ?? '') : '';

    return 'path=' . $path
        . ' dir=' . $dir
        . ' dir_exists=' . $dirExists
        . ' dir_writable=' . $dirWritable
        . ' path_exists=' . $pathExists
        . ' path_writable=' . $pathWritable
        . ' dir_owner=' . $ownerVal
        . ' dir_group=' . $groupVal
        . ' dir_perms=' . $permsVal
        . ($lastErrMsg !== '' ? ' last_error=' . $lastErrMsg : '');
}

$startedAt = microtime(true);
$timestamp = gmdate('Ymd_His');
$workspaceRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$exportRoot = $workspaceRoot . '/storage/model_training';
$fullExportPath = $exportRoot . '/latest_dataset.jsonl';
$incrementalExportPath = $exportRoot . '/latest_incremental.jsonl';
$runtimeFeedbackPath = trim((string)api_get_secret('MODEL_SELF_TRAIN_RUNTIME_FILE', $exportRoot . '/runtime_feedback.jsonl'));
$hfExtraPath = trim((string)getenv('LYRALINK_HF_EXTRA_JSONL'));
$snapshotFullPath = $exportRoot . '/snapshots/dataset_' . $timestamp . '.jsonl';
$snapshotIncrementalPath = $exportRoot . '/snapshots/incremental_' . $timestamp . '.jsonl';

// Snapshot retention. These snapshots are write-only traceability artifacts:
// no code path reads them back (the training run consumes latest_dataset.jsonl
// / latest_incremental.jsonl). Without a bound the directory grows forever at
// roughly 0.4 GB per day. Retention is configurable; default 72 hours.
$snapshotRetentionHours = max(1, (int) (getenv('LYRALINK_SNAPSHOT_RETENTION_HOURS') ?: 72));
$snapshotDir = $exportRoot . '/snapshots';
if (is_dir($snapshotDir)) {
    $snapshotCutoff = time() - ($snapshotRetentionHours * 3600);
    foreach ((array) glob($snapshotDir . '/*.jsonl') as $snapshotCandidate) {
        $snapshotMtime = @filemtime($snapshotCandidate);
        if ($snapshotMtime !== false && $snapshotMtime < $snapshotCutoff) {
            @unlink($snapshotCandidate);
        }
    }
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    learning_log('continuous_learning db_connect_error=' . $db->connect_error);
    exit(1);
}

learning_ensure_tables($db);

$lastDatasetId = max(0, (int)learning_state_get($db, 'last_dataset_id', '0'));
$trainEnabled = api_get_secret('CONTINUOUS_FINETUNE_ENABLED', '0') === '1';
$trainCommand = trim((string)api_get_secret('CONTINUOUS_FINETUNE_COMMAND', ''));
$trainTimeout = max(60, min((int)api_get_secret('CONTINUOUS_FINETUNE_TIMEOUT', '3600'), 86400));
$minNewSamples = max(1, min((int)api_get_secret('CONTINUOUS_FINETUNE_MIN_NEW_SAMPLES', '25'), 5000));
$forceTrainEachRun = api_get_secret('CONTINUOUS_FINETUNE_FORCE_EACH_RUN', '0') === '1';
$lastRuntimeLine = max(0, (int)learning_state_get($db, 'runtime_feedback_line', '0'));

$allRows = [];
$allRes = $db->query("SELECT id, source_id, question, answer, keywords FROM dataset ORDER BY id ASC");
if ($allRes) {
    while ($row = $allRes->fetch_assoc()) {
        $allRows[] = $row;
    }
}

$newRows = [];
$stmt = $db->prepare("SELECT id, source_id, question, answer, keywords FROM dataset WHERE id > ? ORDER BY id ASC");
if ($stmt) {
    $stmt->bind_param('i', $lastDatasetId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $newRows[] = $row;
    }
    $stmt->close();
}

$latestDatasetId = 0;
if (!empty($allRows)) {
    $lastRow = end($allRows);
    $latestDatasetId = (int)($lastRow['id'] ?? 0);
}

$datasetTrainingRows = array_map('learning_training_row', $allRows);
$newDatasetTrainingRows = array_map('learning_training_row', $newRows);

$runtimeAllRead = learning_read_jsonl_rows($runtimeFeedbackPath, 1);
$runtimeAllRows = [];
foreach (($runtimeAllRead['rows'] ?? []) as $runtimeRow) {
    $normalized = learning_runtime_training_row($runtimeRow);
    if ($normalized !== null) {
        $runtimeAllRows[] = $normalized;
    }
}

$runtimeNewRead = learning_read_jsonl_rows($runtimeFeedbackPath, $lastRuntimeLine + 1);
$runtimeNewRows = [];
foreach (($runtimeNewRead['rows'] ?? []) as $runtimeRow) {
    $normalized = learning_runtime_training_row($runtimeRow);
    if ($normalized !== null) {
        $runtimeNewRows[] = $normalized;
    }
}

$hfExtraRows = [];
if ($hfExtraPath !== '' && is_file($hfExtraPath)) {
    $hfRead = learning_read_jsonl_rows($hfExtraPath, 1);
    foreach (($hfRead['rows'] ?? []) as $hfRow) {
        if (!is_array($hfRow)) {
            continue;
        }
        $normalized = learning_hf_training_row($hfRow);
        if ($normalized !== null) {
            $hfExtraRows[] = $normalized;
        }
    }
}

$allTrainingRows = array_merge($datasetTrainingRows, $runtimeAllRows, $hfExtraRows);
$newTrainingRows = array_merge($newDatasetTrainingRows, $runtimeNewRows);
$effectiveNewRows = count($newRows) + count($runtimeNewRows) + count($hfExtraRows);

$fullOk = learning_write_jsonl($fullExportPath, $allTrainingRows);
$fullDebug = $fullOk ? '' : learning_path_debug($fullExportPath);
$snapshotFullOk = $fullOk ? learning_write_jsonl($snapshotFullPath, $allTrainingRows) : false;
$snapshotFullDebug = (!$fullOk || $snapshotFullOk) ? '' : learning_path_debug($snapshotFullPath);

$incrementalOk = true;
$snapshotIncrementalOk = true;
$incrementalDebug = '';
$snapshotIncrementalDebug = '';
if (!empty($newTrainingRows)) {
    $incrementalOk = learning_write_jsonl($incrementalExportPath, $newTrainingRows);
    if (!$incrementalOk) {
        $incrementalDebug = learning_path_debug($incrementalExportPath);
    }
    $snapshotIncrementalOk = $incrementalOk ? learning_write_jsonl($snapshotIncrementalPath, $newTrainingRows) : false;
    if ($incrementalOk && !$snapshotIncrementalOk) {
        $snapshotIncrementalDebug = learning_path_debug($snapshotIncrementalPath);
    }
}

$trainAttempted = false;
$trainOk = false;
$trainOutput = 'training_disabled';
if ($trainEnabled) {
    if ($trainCommand === '') {
        $trainOutput = 'training_enabled_but_no_command_configured';
    } elseif (!$forceTrainEachRun && $effectiveNewRows < $minNewSamples) {
        $trainOutput = 'waiting_for_more_samples current=' . $effectiveNewRows . ' required=' . $minNewSamples;
    } elseif (!$fullOk) {
        $trainOutput = 'full_export_failed ' . $fullDebug;
    } else {
        $trainAttempted = true;
        putenv('LYRALINK_TRAIN_FILE=' . $fullExportPath);
        putenv('LYRALINK_INCREMENTAL_FILE=' . $incrementalExportPath);
        putenv('LYRALINK_OUTPUT_DIR=' . $exportRoot . '/artifacts');
        putenv('LYRALINK_DATASET_ROWS=' . (string)count($allRows));
        putenv('LYRALINK_NEW_ROWS=' . (string)count($newRows));
        putenv('LYRALINK_WORKSPACE_ROOT=' . $workspaceRoot);
        $trainRes = learning_exec($trainCommand, $trainTimeout);
        $trainOk = ((int)($trainRes['exit_code'] ?? 1) === 0);
        $trainOutput = substr((string)($trainRes['output'] ?? ''), 0, 20000);
    }
}

if ($latestDatasetId > 0 && $fullOk) {
    learning_state_set($db, 'last_dataset_id', (string)$latestDatasetId);
}
if ($fullOk) {
    learning_state_set($db, 'runtime_feedback_line', (string)($runtimeAllRead['total_lines'] ?? 0));
}

$finishedAt = gmdate('Y-m-d H:i:s');
$startedAtSql = gmdate('Y-m-d H:i:s', (int)$startedAt);
$duration = round(microtime(true) - $startedAt, 3);

$runStmt = $db->prepare("INSERT INTO model_learning_runs (started_at, finished_at, dataset_rows, new_rows, last_dataset_id, full_export_path, incremental_export_path, train_enabled, train_attempted, train_ok, train_command, train_output) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if ($runStmt) {
    $datasetRows = count($allRows);
    $newRowCount = count($newRows);
    $lastIdValue = $latestDatasetId;
    $fullPathValue = $fullOk ? $fullExportPath : null;
    $incrementalPathValue = (!empty($newRows) && $incrementalOk) ? $incrementalExportPath : null;
    $trainEnabledInt = $trainEnabled ? 1 : 0;
    $trainAttemptedInt = $trainAttempted ? 1 : 0;
    $trainOkInt = $trainOk ? 1 : 0;
    $commandValue = $trainCommand !== '' ? $trainCommand : null;
    $outputValue = $trainOutput;
    $runStmt->bind_param('ssiiissiiiss', $startedAtSql, $finishedAt, $datasetRows, $newRowCount, $lastIdValue, $fullPathValue, $incrementalPathValue, $trainEnabledInt, $trainAttemptedInt, $trainOkInt, $commandValue, $outputValue);
    $runStmt->execute();
    $runStmt->close();
}

$db->close();

learning_log('continuous_learning dataset_rows=' . count($allRows)
    . ' new_rows=' . count($newRows)
    . ' runtime_rows=' . count($runtimeAllRows)
    . ' runtime_new_rows=' . count($runtimeNewRows)
    . ' hf_extra_rows=' . count($hfExtraRows)
    . ' hf_extra_path=' . ($hfExtraPath !== '' ? $hfExtraPath : 'n/a')
    . ' effective_new_rows=' . $effectiveNewRows
    . ' total_train_rows=' . count($allTrainingRows)
    . ' full_export=' . ($fullOk ? 'yes' : 'no')
    . ' incremental_export=' . ((!empty($newTrainingRows) && $incrementalOk) ? 'yes' : 'n/a')
    . ' snapshot_full=' . ($snapshotFullOk ? 'yes' : 'no')
    . ' snapshot_incremental=' . ((!empty($newTrainingRows) && $snapshotIncrementalOk) ? 'yes' : 'n/a')
    . ' train_enabled=' . ($trainEnabled ? 'yes' : 'no')
    . ' train_attempted=' . ($trainAttempted ? 'yes' : 'no')
    . ' train_ok=' . ($trainOk ? 'yes' : 'no')
    . ' duration=' . $duration . 's');

if ($trainOutput !== '') {
    learning_log('continuous_learning train_output=' . substr($trainOutput, 0, 600));
}
if (!$snapshotFullOk && $snapshotFullDebug !== '') {
    learning_log('continuous_learning snapshot_full_error=' . substr($snapshotFullDebug, 0, 600));
}
if (!$incrementalOk && $incrementalDebug !== '') {
    learning_log('continuous_learning incremental_export_error=' . substr($incrementalDebug, 0, 600));
}
if (!$snapshotIncrementalOk && $snapshotIncrementalDebug !== '') {
    learning_log('continuous_learning snapshot_incremental_error=' . substr($snapshotIncrementalDebug, 0, 600));
}

exit(($fullOk && $incrementalOk) ? 0 : 1);