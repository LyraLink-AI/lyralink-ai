<?php
require_once __DIR__ . '/../api/lib/chat/execution_foundation.php';
require_once __DIR__ . '/../api/lib/chat/conversation_intelligence.php';

function assert_conversation(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$lowRiskCases = [
    ['msg' => 'Hey', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION']],
    ['msg' => 'How are you?', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION']],
    ['msg' => 'You\'re being weird.', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION']],
    ['msg' => 'lol', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION']],
    ['msg' => 'What do you think about Lyralink?', 'allowed' => ['OPINION', 'GENERAL_INFORMATION']],
    ['msg' => 'What should we build next?', 'allowed' => ['OPINION', 'GENERAL_INFORMATION']],
    ['msg' => 'Tell me something interesting.', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'GENERAL_INFORMATION']],
    ['msg' => 'Still wrong. I am your developer/creator, I wanted to converse with you about you and your behavior.', 'allowed' => ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION', 'GENERAL_INFORMATION']],
    ['msg' => 'Can you explain that again?', 'allowed' => ['GENERAL_INFORMATION', 'FACTUAL_INFORMATION']],
];

foreach ($lowRiskCases as $case) {
    $control = chat_infer_task_control($case['msg'], false, 'general');
    $profile = chat_request_trust_profile($case['msg'], false, 'general', $control);
    assert_conversation(in_array($profile['request_class'] ?? '', $case['allowed'], true), 'unexpected request class for low-risk input: ' . $case['msg']);
    if (in_array('CASUAL_CONVERSATION', $case['allowed'], true) || in_array('SOCIAL_CONVERSATION', $case['allowed'], true) || in_array('OPINION', $case['allowed'], true)) {
        assert_conversation(($profile['evidence_required'] ?? true) === false, 'low-risk message should not require evidence: ' . $case['msg']);
    }
}

$staleTaskModeMsg = 'Still wrong. I am your developer/creator. I wanted to converse with you about what you\'ve been up to recently.';
$staleTaskControl = chat_infer_task_control($staleTaskModeMsg, true, 'general');
$staleTaskProfile = chat_request_trust_profile($staleTaskModeMsg, true, 'general', $staleTaskControl);
assert_conversation(($staleTaskControl['requires_execution'] ?? true) === false, 'stale task mode must not force execution on a casual correction');
assert_conversation(in_array($staleTaskProfile['request_class'] ?? '', ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true), 'stale task mode must not reclassify a casual correction as mission work');

$casualFallback = chat_trust_safe_fallback('You\'re being weird', ['mode' => 'DIRECTLY_ANSWERABLE'], ['Expected a structured plan or checklist.']);
assert_conversation(stripos($casualFallback, "can't verify") === false, 'casual fallback should not use global evidence refusal language');

$productionControl = chat_infer_task_control('Run this production rollback and tell me whether the database is healthy.', false, 'general');
$productionProfile = chat_request_trust_profile('Run this production rollback and tell me whether the database is healthy.', false, 'general', $productionControl);
assert_conversation(($productionProfile['risk_level'] ?? '') === 'very_high', 'production rollback request must remain very high risk');
assert_conversation(($productionProfile['tool_required'] ?? false) === true, 'production rollback execution must require tool/capability path');

$toolHardStop = chat_verification_hard_stop(
    'Check my server logs. I did not provide logs and you do not have shell access.',
    'I checked the logs and confirmed the issue.',
    [
        'passed' => false,
        'issues' => ['Reply claims tool execution or inspection without actual access or evidence.'],
        'answerability' => ['mode' => 'TOOL_LIMITATION', 'answerable' => false],
    ]
);
assert_conversation(($toolHardStop['blocked'] ?? false) === true, 'tool execution fabrication must still be blocked');

$searchLength = chat_response_length_expectation('Find local coffee shops in Detroit.', 'GENERAL_INFORMATION');
assert_conversation(($searchLength['target'] ?? '') === 'standard', 'short substantive search requests must not be forced into minimal length mode');

echo "conversational quality tests passed\n";
