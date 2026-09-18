<?php
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lib/chat/os_core.php';

$cfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
if ($db->connect_error) {
    fwrite(STDERR, "DB_CONNECT_ERROR:" . $db->connect_error . "\n");
    exit(2);
}
$db->set_charset('utf8mb4');

if (!chat_os_ensure_schema($db)) {
    fwrite(STDERR, "SCHEMA_CREATE_FAILED\n");
    exit(3);
}

$taskId = 'test-ai-os-' . uniqid('', true);
$task = [
    'task_id' => $taskId,
    'request_id' => 'db-roundtrip-request',
    'user_id' => 'db-test-user',
    'session_id' => 'db-test-session',
    'intent' => 'integration_db_test',
    'priority' => 'normal',
    'risk_level' => 'LOW',
    'status' => 'QUEUED',
    'authorization_state' => 'NOT_REQUIRED',
    'required_capabilities' => ['model.fast'],
    'required_resources' => ['model_runtime'],
    'metadata' => ['source' => 'db_roundtrip_test'],
    'created_at' => gmdate('c'),
];

$executionRecord = [
    'execution_id' => 'exec-' . uniqid('', true),
    'task_id' => $taskId,
    'request_id' => 'db-roundtrip-request',
    'action_id' => 'action-' . uniqid('', true),
    'capability_id' => 'model.fast',
    'resource_id' => 'model_runtime',
    'status' => 'SUCCEEDED',
    'authorization_state' => 'NOT_REQUIRED',
    'input_summary' => 'db roundtrip test',
    'stdout' => 'ok',
    'error' => '',
    'result_verified' => true,
    'provenance' => 'TOOL_OBSERVED',
    'evidence_level' => 'HIGH',
    'started_at' => gmdate('c'),
    'completed_at' => gmdate('c'),
    'duration_ms' => 25,
];

if (!chat_os_persist_task_record_db($db, $task, ['route_class' => 'FAST'])) {
    fwrite(STDERR, "TASK_PERSIST_FAILED\n");
    exit(4);
}

if (!chat_os_persist_execution_records_db($db, $taskId, [$executionRecord], 'db-roundtrip-request')) {
    fwrite(STDERR, "EXECUTION_PERSIST_FAILED\n");
    exit(5);
}

$taskRow = $db->query("SELECT task_id, status, authorization_state FROM ai_os_tasks WHERE task_id = '" . $db->real_escape_string($taskId) . "' LIMIT 1")->fetch_assoc();
if (!$taskRow || (string)$taskRow['task_id'] !== $taskId) {
    fwrite(STDERR, "TASK_NOT_FOUND\n");
    exit(6);
}

$execRow = $db->query("SELECT task_id, status, capability_id FROM ai_os_executions WHERE task_id = '" . $db->real_escape_string($taskId) . "' LIMIT 1")->fetch_assoc();
if (!$execRow || (string)$execRow['task_id'] !== $taskId) {
    fwrite(STDERR, "EXECUTION_NOT_FOUND\n");
    exit(7);
}

echo "AI-OS DB roundtrip passed.\n";
