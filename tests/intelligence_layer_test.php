<?php

function api_get_secret(string $key, string $default = ''): string {
    $map = [
        'CHAT_INTELLIGENCE_LAYER_ENABLED' => '1',
        'CONTINUOUS_FINETUNE_ENABLED' => '1',
    ];
    return $map[$key] ?? $default;
}

require __DIR__ . '/../api/lib/chat/conversation_intelligence.php';
require __DIR__ . '/../api/lib/chat/intelligence_layer.php';

$normalConversation = 'why are you putting things in numbered? can you just converse normally?';
if (chat_explicit_numbered_request_detected($normalConversation)) {
    fwrite(STDERR, "Normal conversation should not trigger numbered multipart mode\n");
    exit(1);
}

$explicitRequest = 'Give me 3 reasons and 2 examples';
if (!chat_explicit_numbered_request_detected($explicitRequest)) {
    fwrite(STDERR, "Explicit numbered requests should trigger multipart mode\n");
    exit(1);
}

$projectId = 'proj_test';
$state = chat_intelligence_default_state($projectId);
$plan = chat_intelligence_capability_plan([
    'latest_user_message' => 'Debug the current production API outage and gather latest status updates',
    'task_mode' => true,
    'task_focus' => 'debug',
    'needs_fresh_web' => true,
    'dataset_matches' => [['question' => 'foo', 'answer' => 'bar']],
    'training_enabled' => true,
]);

$capabilities = array_map(static fn($item) => $item['capability'] ?? '', $plan);
foreach (['brain', 'web', 'memory', 'code', 'ml_lab', 'orchestrator'] as $required) {
    if (!in_array($required, $capabilities, true)) {
        fwrite(STDERR, "Missing capability plan item: {$required}\n");
        exit(1);
    }
}

$context = [
    'project_id' => $projectId,
    'latest_user_message' => 'Debug the current production API outage and gather latest status updates',
    'task_mode' => true,
    'task_focus' => 'debug',
    'needs_fresh_web' => true,
    'dataset_matches' => [['question' => 'foo', 'answer' => 'bar']],
    'web_results' => 1,
    'provider' => 'local',
    'model' => 'lyralink-reasoning:latest',
    'project_progress' => ['active_tasks' => 2, 'completion_pct' => 35],
    'persistent_goals' => [
        ['title' => 'Restore API stability', 'status' => 'active'],
        ['title' => 'Verify customer impact', 'status' => 'active'],
    ],
    'capability_plan' => $plan,
    'verification_passed' => false,
    'confidence_score' => 0.42,
    'confidence_label' => 'low',
    'request_ms' => 14000,
    'active_task_count' => 2,
    'reply' => 'Working through the outage with live context.',
];

$loop = chat_intelligence_scientific_loop($context);
if (($loop['verify'] ?? '') !== 'needs_followup') {
    fwrite(STDERR, "Expected scientific loop to require followup\n");
    exit(1);
}

$state = chat_intelligence_update_state($state, $context);
$payload = chat_intelligence_payload($state, $plan, $loop, $state['last_improvements'] ?? []);

if (($payload['world_state']['projects'] ?? 0) < 1) {
    fwrite(STDERR, "Expected at least one project in world state\n");
    exit(1);
}
if (count($payload['improvement_signals'] ?? []) < 2) {
    fwrite(STDERR, "Expected multiple improvement signals\n");
    exit(1);
}
if (empty($payload['timeline'])) {
    fwrite(STDERR, "Expected timeline observations\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
$allowed = chat_workspace_read_file($root . '/README.md', 2000);
if (!is_array($allowed) || empty($allowed['content']) || !str_contains($allowed['content'], 'LyraLink')) {
    fwrite(STDERR, "Expected README content to be readable through workspace helper\n");
    exit(1);
}
if (chat_workspace_read_file($root . '/.env', 2000) !== null) {
    fwrite(STDERR, "Sensitive environment files must remain blocked\n");
    exit(1);
}

$context = chat_workspace_context_for_query('self-review the architecture and explain how the intelligence runtime and developer tools work', $root, true);
if (!str_contains($context, 'LyraLink') && !str_contains($context, 'api/chat.php')) {
    fwrite(STDERR, "Expected self-analysis context to include project architecture details\n");
    exit(1);
}

echo "intelligence layer tests passed\n";
