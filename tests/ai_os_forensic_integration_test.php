<?php

declare(strict_types=1);

function assert_forensic(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function run_chat_forensic(array $payload): array {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../benchmark/chat_request_cli.php');
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptor, $pipes, dirname(__DIR__));
    assert_forensic(is_resource($proc), 'failed to start chat runtime process');

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($proc);
    assert_forensic($code === 0, 'chat runtime failed: ' . trim((string)$stderr));

    $decoded = json_decode(trim((string)$stdout), true);
    assert_forensic(is_array($decoded), 'runtime output must be valid JSON');
    return $decoded;
}

function payload_forensic(string $message, array $extra = []): array {
    return array_merge([
        'messages' => [
            ['role' => 'user', 'content' => $message],
        ],
        'benchmark_mode' => true,
        'disable_cache' => true,
    ], $extra);
}

function get_forensic(array $response): array {
    $forensic = $response['execution']['forensic']['post'] ?? null;
    assert_forensic(is_array($forensic), 'forensic post snapshot missing from runtime payload');

    $requiredFields = [
        'request_id',
        'intent',
        'desired_outcome',
        'external_state_required',
        'execution_required',
        'research_required',
        'evidence_required',
        'capabilities_required',
        'resources_required',
        'authorization_state',
        'risk_level',
        'routing_class',
        'selected_model',
        'selected_tools',
        'execution_id',
        'verification_state',
        'validation_result',
    ];
    foreach ($requiredFields as $field) {
        assert_forensic(array_key_exists($field, $forensic), 'missing forensic field: ' . $field);
    }

    return $forensic;
}

function print_forensic(string $label, array $forensic): void {
    echo "[{$label}]\n";
    echo 'intent=' . (string)$forensic['intent'] . "\n";
    echo 'desired_outcome=' . (string)$forensic['desired_outcome'] . "\n";
    echo 'external_state_required=' . ((bool)$forensic['external_state_required'] ? 'true' : 'false') . "\n";
    echo 'execution_required=' . ((bool)$forensic['execution_required'] ? 'true' : 'false') . "\n";
    echo 'research_required=' . ((bool)$forensic['research_required'] ? 'true' : 'false') . "\n";
    echo 'capabilities_required=' . json_encode($forensic['capabilities_required'], JSON_UNESCAPED_SLASHES) . "\n";
    echo 'resources_required=' . json_encode($forensic['resources_required'], JSON_UNESCAPED_SLASHES) . "\n";
    echo 'routing_class=' . (string)$forensic['routing_class'] . "\n\n";
}

echo "=== AI-OS Forensic Runtime Integration ===\n\n";

$t097 = run_chat_forensic(payload_forensic('How should the team safely handle a failed production database migration?'));
$t097Forensic = get_forensic($t097);
print_forensic('T097 guidance', $t097Forensic);
assert_forensic($t097Forensic['intent'] === 'EXPLANATION', 'T097 intent should be EXPLANATION');
assert_forensic($t097Forensic['desired_outcome'] === 'GUIDANCE', 'T097 desired_outcome should be GUIDANCE');
assert_forensic($t097Forensic['external_state_required'] === false, 'T097 must not require external state');
assert_forensic($t097Forensic['execution_required'] === false, 'T097 must not require execution');
assert_forensic($t097Forensic['research_required'] === false, 'T097 must not require research');
assert_forensic(is_array($t097Forensic['capabilities_required']) && in_array('model.reason', $t097Forensic['capabilities_required'], true), 'T097 should require model.reason capability');
assert_forensic((array)$t097Forensic['resources_required'] === [], 'T097 should not require external resources');
assert_forensic($t097Forensic['routing_class'] === 'STANDARD', 'T097 should route as STANDARD');
assert_forensic((array)$t097Forensic['selected_tools'] === [], 'T097 should not select tools');
assert_forensic((string)$t097Forensic['execution_id'] === '', 'T097 should not carry execution id');

$inspect = run_chat_forensic(payload_forensic('Inspect our failed production database migration and tell me what happened.'));
$inspectForensic = get_forensic($inspect);
print_forensic('Inspection', $inspectForensic);
assert_forensic($inspectForensic['execution_required'] === true, 'inspection request should require execution');
assert_forensic($inspectForensic['external_state_required'] === true, 'inspection request should require external state');

$execute = run_chat_forensic(payload_forensic('Rollback the failed production migration.'));
$executeForensic = get_forensic($execute);
print_forensic('Execution', $executeForensic);
assert_forensic($executeForensic['intent'] === 'EXECUTION', 'execution request should classify as EXECUTION');
assert_forensic($executeForensic['execution_required'] === true, 'execution request should require execution');

$research = run_chat_forensic(payload_forensic('Research the latest PostgreSQL documentation about safe schema migrations.', ['web_search' => true]));
$researchForensic = get_forensic($research);
print_forensic('Research', $researchForensic);
assert_forensic($researchForensic['research_required'] === true, 'research request should require research');
assert_forensic(in_array('web_search', (array)$researchForensic['selected_tools'], true), 'research request should select web_search tool when available');
assert_forensic((string)$researchForensic['execution_id'] !== '', 'research request should carry execution id when research executes');

$rewrite = run_chat_forensic(payload_forensic('Rewrite this professionally: we should avoid burnout.'));
$rewriteForensic = get_forensic($rewrite);
print_forensic('Rewrite', $rewriteForensic);
assert_forensic($rewriteForensic['intent'] === 'TRANSFORMATION', 'rewrite request should classify as TRANSFORMATION');
assert_forensic($rewriteForensic['execution_required'] === false, 'rewrite request should not require execution');
assert_forensic($rewriteForensic['routing_class'] === 'FAST', 'rewrite request should route as FAST');

echo "AI-OS forensic runtime integration checks passed.\n";
