<?php
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/lib/chat/execution_foundation.php';

$base = chat_agent_economy_default_state();
$update = chat_agent_economy_update($base, [
    'reply' => 'Status\n- Checked the issue\n- Verified the fix\nNext step: deploy to staging.',
    'deep_thinking_requested' => true,
    'verification' => [
        'passed' => true,
        'checks' => [
            ['name' => 'has_structured_steps', 'pass' => true],
        ],
    ],
    'confidence' => ['label' => 'high'],
    'hallucination' => ['risk' => 'low'],
    'reply_safety' => ['redactions' => []],
    'retrieval_used' => true,
    'task_mode' => true,
    'task_focus' => 'debug',
    'agent_payload' => ['next_step' => 'Deploy to staging'],
    'code_test_result' => [
        'tested_blocks' => 1,
        'results' => [
            ['ok' => true, 'status' => 'passed'],
        ],
    ],
    'project_progress' => ['completion_pct' => 30],
    'provider_error' => false,
]);

$state = $update['state'];
if (($state['balance'] ?? 0) <= 0) {
    fwrite(STDERR, "Expected positive balance after a good turn\n");
    exit(1);
}
if (($state['streak'] ?? 0) !== 1) {
    fwrite(STDERR, "Expected streak to increment\n");
    exit(1);
}
if (($state['jobs_completed'] ?? 0) !== 1) {
    fwrite(STDERR, "Expected jobs completed to increment on progress gain\n");
    exit(1);
}

$update2 = chat_agent_economy_update($state, [
    'reply' => 'Maybe try something.',
    'deep_thinking_requested' => false,
    'verification' => ['passed' => false, 'checks' => []],
    'confidence' => ['label' => 'low'],
    'hallucination' => ['risk' => 'high'],
    'reply_safety' => ['redactions' => ['x']],
    'retrieval_used' => false,
    'task_mode' => false,
    'task_focus' => 'general',
    'agent_payload' => [],
    'code_test_result' => ['tested_blocks' => 0, 'results' => []],
    'project_progress' => ['completion_pct' => 30],
    'provider_error' => true,
]);

$state2 = $update2['state'];
if (($state2['last_delta'] ?? 0) >= 0) {
    fwrite(STDERR, "Expected negative reward after a weak turn\n");
    exit(1);
}
if (($state2['streak'] ?? 1) !== 0) {
    fwrite(STDERR, "Expected streak reset after negative reward\n");
    exit(1);
}
if (($state2['failures'] ?? 0) < 1) {
    fwrite(STDERR, "Expected failure count to increment\n");
    exit(1);
}

echo "agent economy tests passed\n";
