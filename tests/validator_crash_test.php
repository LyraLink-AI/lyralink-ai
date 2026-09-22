<?php
/**
 * Regression tests for two production bugs found on 2026-09-20.
 *
 * Both were pre-existing and both failed SILENTLY, which is why they survived:
 * one returned an empty body with exit status 0, the other crashed before writing
 * anything. Neither produced a useful log line.
 *
 * BUG 1 - DivisionByZeroError on a time range.
 *   chat_validate_quantitative() matched "00 to 04" in
 *   "a maintenance window tomorrow from 02:00 to 04:00 UTC" and divided by zero.
 *
 * BUG 2 - json_encode failure produced an empty response.
 *   chat.php echoed json_encode($errorPayload); when that returned false (invalid
 *   UTF-8), echo printed nothing and the caller saw an empty reply with exit 0.
 */

$tests = 0;
$passed = 0;

function t(string $name, bool $ok): void {
    global $tests, $passed;
    $tests++;
    if ($ok) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        echo "FAIL: {$name}\n";
    }
}

$R = '/var/www/vhosts/lyralinkai.com/httpdocs/api';
require_once $R . '/security.php';
require_once $R . '/lib/chat/validators_enhanced.php';

echo "--- BUG 1: division by zero on time ranges ---\n";

$timeRangePrompts = [
    'maintenance window 02:00 to 04:00' =>
        'Write a short Slack message announcing a maintenance window tomorrow from 02:00 to 04:00 UTC, and say what users should expect.',
    'backup window 01:30 to 03:30' =>
        'Schedule a backup window from 01:30 to 03:30 and explain the steps.',
    'opening hours 09:00 to 17:00' =>
        'Our office opening hours are 09:00 to 17:00. Draft the sign.',
    'shift 00:00 to 08:00' =>
        'Plan a support rota covering 00:00 to 08:00 every day.',
];

foreach ($timeRangePrompts as $label => $prompt) {
    $ok = true;
    $detail = '';
    try {
        chat_validate_quantitative($prompt, 'Acknowledged, scheduled as requested.');
    } catch (Throwable $e) {
        $ok = false;
        $detail = ' (' . get_class($e) . ': ' . $e->getMessage() . ')';
    }
    t('no crash for ' . $label . $detail, $ok);
}

// A genuine percentage increase must still be computed.
$r = chat_validate_quantitative(
    'The service cost 200 and increased to 260. What is the percentage increase?',
    'That is a 30% increase.'
);
$ok = true;
try {
    chat_validate_quantitative(
        'The service cost 200 and increased to 260. What is the percentage increase?',
        'That is a 30% increase.'
    );
} catch (Throwable $e) {
    $ok = false;
}
t('real percentage increase still handled without crash', $ok);

// Zero base must not throw (percentage increase from zero is undefined).
$ok = true;
try {
    chat_validate_quantitative('Revenue went from 0 to 500 this quarter.', 'Revenue grew.');
} catch (Throwable $e) {
    $ok = false;
}
t('zero starting value does not throw', $ok);

echo "\n--- BUG 2: response encoding cannot come back empty ---\n";

$chatSrc = (string)file_get_contents('/var/www/vhosts/lyralinkai.com/httpdocs/api/chat.php');
t('error payload uses JSON_INVALID_UTF8_SUBSTITUTE',
    str_contains($chatSrc, 'echo json_encode($errorPayload, JSON_INVALID_UTF8_SUBSTITUTE);'));
t('final payload uses JSON_INVALID_UTF8_SUBSTITUTE',
    str_contains($chatSrc, 'echo json_encode($finalPayload, JSON_INVALID_UTF8_SUBSTITUTE);'));

// Demonstrate the failure mode itself, so the regression has a live witness.
$bad = ['message' => "provider error: \xB1\xB2 invalid bytes"];
$silent = json_encode($bad);
$substituted = json_encode($bad, JSON_INVALID_UTF8_SUBSTITUTE |
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
t('plain json_encode of invalid UTF-8 really does return false', $silent === false);
t('JSON_INVALID_UTF8_SUBSTITUTE returns a usable payload instead', is_string($substituted) && $substituted !== '');
t('substituted payload still decodes', is_array(json_decode((string)$substituted, true)));

printf("\n%d/%d tests passed\n", $passed, $tests);
exit($passed === $tests ? 0 : 1);
