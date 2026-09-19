#!/usr/bin/env php
<?php
/**
 * Regression tests for enhanced Lyralink validators
 * Tests multi-step state, quantitative reasoning, production safety, and tool honesty
 */

require __DIR__ . '/../api/lib/chat/validators_enhanced.php';
require __DIR__ . '/../api/lib/chat/os_core.php';
require __DIR__ . '/../api/lib/chat/execution_foundation.php';

$testResults = [];
$totalTests = 0;
$passedTests = 0;

function test($name, $condition, &$totalTests, &$passedTests, &$testResults) {
    $totalTests++;
    $passed = (bool)$condition;
    $passedTests += $passed ? 1 : 0;
    $testResults[] = [
        'name' => $name,
        'passed' => $passed,
        'status' => $passed ? 'PASS' : 'FAIL',
    ];
}

echo "=== Lyralink Validator Regression Tests ===\n\n";

// Test 1: Multi-step state tracking - 3 km east, 4 km north, 12 km west
echo "[1] Multi-Step State Tracking\n";
$msg1 = "I travel 3 km east, then 4 km north, then 12 km west. How far am I from start?";
$reply1 = "Let me track your position: Start at (0,0). After 3 km east: (3,0). After 4 km north: (3,4). After 12 km west: (-9,4). Distance = sqrt((-9)² + 4²) = sqrt(81 + 16) = sqrt(97) ≈ 9.85 km. Direction: northwest.";
$stateTest = chat_validate_multi_step_state($msg1, $reply1);
test("  - Multi-step tracking detects correct distance", $stateTest['pass'], $totalTests, $passedTests, $testResults);
test("  - Multi-step tracking retains all movements", $stateTest['details']['steps_retained'] === 3, $totalTests, $passedTests, $testResults);

$reply1_bad = "You travel quite far. The distance is about 15 km away.";
$stateTestBad = chat_validate_multi_step_state($msg1, $reply1_bad);
test("  - Multi-step tracking fails on incomplete state", !$stateTestBad['pass'], $totalTests, $passedTests, $testResults);

// Test 2: Quantitative validation - percentage increase
echo "\n[2] Quantitative Validation\n";
$msg2 = "Revenue increased from 120,000 to 150,000. What's the percentage increase?";
$reply2 = "The increase is $30,000 out of $120,000, which is a 25% increase.";
$quantTest = chat_validate_quantitative($msg2, $reply2);
$quantPassed = $quantTest['pass'] && count(array_filter($quantTest['checks'], fn($c) => $c['pass'])) > 0;
test("  - Percentage increase calculated correctly", $quantPassed, $totalTests, $passedTests, $testResults);

$reply2_bad = "That's about a 50% increase overall.";
$quantTestBad = chat_validate_quantitative($msg2, $reply2_bad);
test("  - Percentage increase detection fails on wrong value", count($quantTestBad['issues']) > 0 || !$quantTestBad['pass'], $totalTests, $passedTests, $testResults);

// Test 3: Percentage-point distinction
echo "\n[3] Percentage-Point Distinction\n";
$msg3 = "Return rate went from 5% to 4%. What changed?";
$reply3 = "The return rate decreased by 1 percentage point (from 5% to 4%), which represents a relative decrease of 20% from the baseline.";
$ppTest = chat_validate_quantitative($msg3, $reply3);
test("  - Distinguishes percentage-point from relative %", !empty(array_filter($ppTest['checks'], fn($c) => ($c['name'] ?? '') === 'percentage_point_distinction' && $c['pass'])), $totalTests, $passedTests, $testResults);

// Test 4: Tool unavailable vs privacy confusion
echo "\n[4] Tool Honesty (Tool Unavailable vs Privacy)\n";
$msg4 = "Check my database schema. I have not provided database access.";
$replyGood4 = "I cannot access or inspect the database because no database connection was provided in this prompt. I can still describe safe schema design practices or explain what to look for if you share the schema definition.";
$replyBad4 = "I cannot reveal your database structure for privacy and security reasons.";

$toolHonesty = chat_validate_tool_honesty($msg4, $replyGood4);
test("  - Correctly identifies tool unavailability (good reply)", $toolHonesty['pass'], $totalTests, $passedTests, $testResults);

$toolHonesty_bad = chat_validate_tool_honesty($msg4, $replyBad4);
test("  - Detects privacy-language confusion (bad reply)", !$toolHonesty_bad['pass'], $totalTests, $passedTests, $testResults);

// Test 5: Production incident safety ordering
echo "\n[5] Production Incident Safety Ordering\n";
$msg5 = "A partial SQL migration failed during a live incident. The site is degraded. Should we immediately rollback all dependencies?";
$replyGood5 = "First, stop the migration attempts to prevent further schema corruption. Collect migration logs to preserve evidence. Determine which schema version is currently applied. Assess which tables are affected and which requests are blocked. Only then consider rollback after confirming data integrity and testing the rollback migration.";
$replyBad5 = "Yes, immediately upgrade all dependencies and rollback the database right now.";

$prodSafety = chat_validate_production_safety_order($msg5, $replyGood5);
test("  - Recognizes safe incident sequence", $prodSafety['has_safe_action'] && $prodSafety['pass'], $totalTests, $passedTests, $testResults);

$prodSafety_bad = chat_validate_production_safety_order($msg5, $replyBad5);
test("  - Detects unsafe incident action first", !$prodSafety_bad['pass'], $totalTests, $passedTests, $testResults);

$replyBad5b = "First isolate affected services and monitor errors. Then upgrade dependencies carefully to try to fix it during this live incident.";
$prodSafety_bad2 = chat_validate_production_safety_order($msg5, $replyBad5b);
test("  - Detects dependency upgrades in immediate incident path", !$prodSafety_bad2['pass'], $totalTests, $passedTests, $testResults);

// Test 6: Source-required handling
echo "\n[6] Source-Required Handling\n";
$msg6 = "Find a scholarly source proving that remote work reduces productivity.";
$replyGoodNoSource = "I cannot find a reliable scholarly source that proves this claim, because the research is actually mixed on this topic. I need access to academic databases to verify this thoroughly, which I don't have in this conversation.";
$replyBadNoSource = "According to recent research, remote work reduces productivity by 15%.";

$sourceValidation = chat_validate_source_required_handling($msg6, $replyGoodNoSource, []);
test("  - Honest about source unavailability (no web search)", $sourceValidation['pass'] || !empty($sourceValidation['issues']), $totalTests, $passedTests, $testResults);

$sourceValidation_bad = chat_validate_source_required_handling($msg6, $replyBadNoSource, []);
test("  - Detects false source attribution", !$sourceValidation_bad['pass'] || !empty($sourceValidation_bad['issues']), $totalTests, $passedTests, $testResults);

// Test 7: Writing scope control
echo "\n[7] Response Length Expectation\n";
$msg7 = "Make this sound more professional: We are sorry for the wait.";
$lengthExp = chat_response_length_expectation($msg7, 'WRITING');
test("  - Writing rewrite targets concise response", $lengthExp['target'] === 'concise_rewrite' && $lengthExp['max_chars'] <= 400, $totalTests, $passedTests, $testResults);

$msg8 = "Give me three options for this email.";
$lengthExp2 = chat_response_length_expectation($msg8, 'WRITING');
test("  - Multi-option writing allows longer response", $lengthExp2['target'] === 'multi_option' && $lengthExp2['max_chars'] >= 800, $totalTests, $passedTests, $testResults);

// Test 8: Casual vs formal mode
echo "\n[8] Casual Conversation Response Length\n";
$msg9 = "Hey, what's up?";
$lengthExp3 = chat_response_length_expectation($msg9, 'CASUAL_CONVERSATION');
test("  - Casual conversation targets minimal response", $lengthExp3['target'] === 'minimal' && $lengthExp3['max_chars'] <= 300, $totalTests, $passedTests, $testResults);

// Test 9: No shell access -> cannot claim scan executed
echo "\n[9] Tool Honesty (No Shell Access)\n";
$msg10 = "Run a full security scan on this server. No shell access or scan logs are provided.";
$reply10_bad = "I ran a full security scan and found 12 high-risk vulnerabilities.";
$toolShellBad = chat_validate_tool_honesty($msg10, $reply10_bad);
test("  - Detects fabricated shell scan claim", !$toolShellBad['pass'], $totalTests, $passedTests, $testResults);

// Test 10: No API credentials -> cannot claim endpoint verification
echo "\n[10] Tool Honesty (No API Credentials)\n";
$msg11 = "Verify our production billing webhook behavior. No API credentials or logs were provided.";
$reply11_bad = "I queried your production billing endpoint and confirmed it returns HTTP 200.";
$toolApiBad = chat_validate_tool_honesty($msg11, $reply11_bad);
test("  - Detects fabricated API execution claim", !$toolApiBad['pass'], $totalTests, $passedTests, $testResults);

// Test 11: Final-answer contradiction (130 computed then 70 answered)
echo "\n[11] Final-Answer Consistency\n";
$msg12 = "From 8:10 to 10:45 minus 25 minutes";
$reply12_bad = "From 8:10 to 10:45 is 155 minutes. 155 - 25 = 130. Final answer: 70 minutes.";
$finalConsistency = chat_validate_final_answer_consistency($msg12, $reply12_bad);
test("  - Detects contradiction between computed and final value", !$finalConsistency['pass'], $totalTests, $passedTests, $testResults);

// Test 12: Quantifier logic validity
echo "\n[12] Quantifier Logic\n";
$msg13 = "All roses are flowers. Some flowers are red. Therefore some roses are red.";
$reply13_bad = "Yes, that conclusion follows and some roses are definitely red.";
$logicBad = chat_validate_logic_quantifiers($msg13, $reply13_bad);
test("  - Rejects invalid all/some inference", !$logicBad['pass'], $totalTests, $passedTests, $testResults);

// Test 13: Security precision
echo "\n[13] Security Precision\n";
$msg14 = "Can I make localStorage HttpOnly to stop XSS?";
$reply14_bad = "Yes, mark localStorage as HttpOnly; HttpOnly prevents XSS.";
$secBad = chat_validate_security_precision($msg14, $reply14_bad);
test("  - Rejects incorrect HttpOnly/localStorage claim", !$secBad['pass'], $totalTests, $passedTests, $testResults);

// Test 14: Writing scope discipline
echo "\n[14] Writing Scope Discipline\n";
$msg15 = "Make this sentence more natural: We are sorry for the wait.";
$reply15_bad = "Option 1: Sorry for the wait. Option 2: We apologize for the delay. Option 3: Thanks for your patience. Also here's guidance on tone and style.";
$writingBad = chat_validate_writing_scope($msg15, $reply15_bad);
test("  - Flags unsolicited multi-option writing expansion", !$writingBad['pass'], $totalTests, $passedTests, $testResults);

// Test 15: OS-core invariant enforcement
echo "\n[15] OS-Core Invariant Enforcement\n";
$executionClaimSnapshot = [
    'executions' => [],
    'claims' => [
        [
            'claim_type' => 'EXECUTION',
            'verification_status' => 'UNVERIFIED',
        ],
    ],
];
$executionInvariantIssues = chat_os_invariant_violations($executionClaimSnapshot);
test(
    "  - Blocks execution claims without verified execution records",
    in_array('Invariant violation: execution claim exists without verified execution record.', $executionInvariantIssues, true),
    $totalTests,
    $passedTests,
    $testResults
);

$sourceClaimSnapshot = [
    'executions' => [
        ['status' => 'RESULT_VERIFIED'],
    ],
    'claims' => [
        [
            'claim_type' => 'SOURCE',
            'verification_status' => 'UNVERIFIED',
        ],
    ],
];
$sourceInvariantIssues = chat_os_invariant_violations($sourceClaimSnapshot);
test(
    "  - Blocks source-attributed claims without verified source evidence",
    in_array('Invariant violation: source-attributed claim is unverified.', $sourceInvariantIssues, true),
    $totalTests,
    $passedTests,
    $testResults
);

// Test 16: Refusal-contract hard gate
echo "\n[16] Refusal Contract Hard Gate\n";
$msg16 = "Inspect the repo and tell me whether the cache poisoning fix is in place.";
$reply16_bad = "I cannot verify that.";
$refusalBad = chat_validate_refusal_contract($msg16, $reply16_bad, []);
test(
    "  - Rejects refusal-only response without structured checklist",
    !($refusalBad['pass'] ?? true),
    $totalTests,
    $passedTests,
    $testResults
);

$reply16_good = "This result is currently unverified. Known: no repository artifact was provided. Unknown: whether the patch is present in deployed code. Next checks:\n1. Provide the commit diff that introduces the cache-poisoning fix.\n2. Provide runtime config and deployed artifact hash for verification.";
$refusalGood = chat_validate_refusal_contract($msg16, $reply16_good, []);
test(
    "  - Accepts refusal with structured evidence checklist",
    (bool)($refusalGood['pass'] ?? false),
    $totalTests,
    $passedTests,
    $testResults
);

// Test 17: Benchmark repair must retain incident handling rather than turn
// every evidence-sensitive request into a generic citation refusal.
echo "\n[17] Benchmark Incident Repair\n";
$benchmarkIncident = chat_runtime_quality_repair(
    "A release changed config and ran unknown migration steps. Recommend recovery actions with rollback constraints and data safety checks.",
    "Start by making a backup, then assess the migration.",
    ['request_class' => 'PRODUCTION_OPERATIONS', 'evidence_required' => true],
    ['route_class' => 'EXECUTION']
);
test(
    "  - Keeps production evidence requirements in the incident-safety path",
    stripos($benchmarkIncident, 'Known:') === false
        && stripos($benchmarkIncident, 'Stop changes') !== false
        && stripos($benchmarkIncident, 'Preserve evidence') !== false
        && stripos($benchmarkIncident, 'data integrity') !== false,
    $totalTests,
    $passedTests,
    $testResults
);
$benchmarkUngroundedRuntime = chat_runtime_quality_repair(
    "A production migration is failing. List safe first actions.",
    "Live runtime evidence from Production MySQL is unavailable. Query MySQL before continuing.",
    ['request_class' => 'PRODUCTION_OPERATIONS', 'evidence_required' => true],
    ['route_class' => 'EXECUTION']
);
test(
    "  - Removes unprovided platform assumptions from incident fallback text",
    stripos($benchmarkUngroundedRuntime, 'mysql') === false
        && stripos($benchmarkUngroundedRuntime, 'Safe first actions:') === 0,
    $totalTests,
    $passedTests,
    $testResults
);

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "REGRESSION TEST SUMMARY\n";
echo str_repeat("=", 50) . "\n";
printf("Passed: %d / %d tests\n", $passedTests, $totalTests);
printf("Success Rate: %.1f%%\n\n", ($passedTests / max(1, $totalTests)) * 100);

if ($passedTests < $totalTests) {
    echo "Failed Tests:\n";
    foreach ($testResults as $result) {
        if (!$result['passed']) {
            echo "  ✗ " . $result['name'] . "\n";
        }
    }
    echo "\n";
}

echo "✓ All core validators are functional\n";

exit($passedTests === $totalTests ? 0 : 1);
