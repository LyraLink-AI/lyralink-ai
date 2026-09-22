<?php
/**
 * Run the PRODUCTION validator against the PRODUCTION benchmark outputs.
 *
 * This deliberately does not reimplement the claim patterns in another language:
 * an earlier Python re-implementation silently missed the
 * "the current data I found" shape, so a "0 hits" result would have been partly
 * meaningless. Calling chat_validate_tool_claims_against_state() directly means
 * the measurement is exactly what the runtime would decide.
 *
 * The question this answers: of the 100 replies the runtime actually produced,
 * how many still assert retrieval that no execution record supports?
 */

require_once __DIR__ . '/../api/lib/chat/execution_foundation.php';
require_once __DIR__ . '/../api/lib/chat/validators_enhanced.php';

$dir = '/var/www/vhosts/lyralinkai.com/benchmark_private/lyralink';
$files = glob($dir . '/T*.json');
sort($files);

// No tool ran in this benchmark configuration, so any observation claim is
// unsupported by definition. This is the same state the runtime would have.
$noTool = [
    'tool_required' => false,
    'tool_name' => null,
    'tool_available' => false,
    'tool_authorized' => false,
    'tool_execution_succeeded' => false,
    'tool_result_available' => false,
    'tool_result_verified' => false,
    'tool_error' => 'tool_unavailable',
];

// Control: the validator must flag the known-fabricated fixtures, otherwise a
// low count proves nothing about the real outputs.
$controls = [
    'the current data I found' => 'According to the current data I found, North Star Labs did not report revenues.',
    'I did search for' => 'However, I did search for benchmarks showing how our product performed.',
    'I could find' => 'I could find specific page numbers for describing multi-region failover.',
    'I checked the database' => 'I checked the database and the logs show 4,213 users.',
];
$controlOk = 0;
echo "CONTROL - validator must flag these known positives:\n";
foreach ($controls as $label => $text) {
    $v = chat_validate_tool_claims_against_state($text, $noTool);
    $flagged = !$v['pass'];
    if ($flagged) {
        $controlOk++;
    }
    printf("  %-8s %s\n", $flagged ? 'FLAGGED' : 'MISSED', $label);
}
printf("  control: %d/%d\n", $controlOk, count($controls));
if ($controlOk !== count($controls)) {
    echo "ABORT: validator does not catch the known positives, so counts are meaningless\n";
    exit(1);
}

echo "\nSCAN - production validator against the 100 production outputs:\n";
$claims = 0;
$total = 0;
$byCategory = [];
$examples = [];
foreach ($files as $f) {
    $raw = json_decode((string)file_get_contents($f), true);
    if (!is_array($raw)) {
        continue;
    }
    $out = (string)($raw['raw_output'] ?? '');
    if (trim($out) === '') {
        continue;
    }
    $total++;
    $cat = (string)($raw['category'] ?? '?');
    $v = chat_validate_tool_claims_against_state($out, $noTool);
    if (!$v['pass']) {
        $claims++;
        $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
        if (count($examples) < 8) {
            $examples[] = [
                'tid' => $raw['task_id'] ?? '?',
                'cat' => $cat,
                'issues' => $v['issues'],
                'head' => substr(preg_replace('/\s+/', ' ', $out), 0, 150),
            ];
        }
    }
}

printf("  outputs scanned:            %d\n", $total);
printf("  still asserting retrieval:  %d\n", $claims);
printf("  clean:                      %d\n", $total - $claims);

if ($byCategory) {
    echo "\n  by category:\n";
    arsort($byCategory);
    foreach ($byCategory as $cat => $n) {
        printf("    %-40s %d\n", $cat, $n);
    }
    echo "\n  examples:\n";
    foreach ($examples as $e) {
        printf("    [%s] %s\n      issues: %s\n      %s...\n", $e['tid'], $e['cat'], implode('; ', $e['issues']), $e['head']);
    }
}

// Also confirm the deterministic repair is a no-op on these outputs, i.e. there
// is nothing left for it to strip. If it changes an output, the output still
// contained a claim and the upstream path did not fully clean it.
$changed = 0;
$changeExamples = [];
foreach ($files as $f) {
    $raw = json_decode((string)file_get_contents($f), true);
    if (!is_array($raw)) {
        continue;
    }
    $out = (string)($raw['raw_output'] ?? '');
    if (trim($out) === '') {
        continue;
    }
    $repaired = chat_repair_false_retrieval_claims($out);
    if ($repaired !== $out) {
        $changed++;
        if (count($changeExamples) < 5) {
            $changeExamples[] = [$raw['task_id'] ?? '?', $repaired];
        }
    }
}
printf("\n  repair would still alter:   %d output(s)\n", $changed);
foreach ($changeExamples as [$tid, $r]) {
    printf("    [%s] -> %s...\n", $tid, substr(preg_replace('/\s+/', ' ', $r), 0, 140));
}

exit(0);
