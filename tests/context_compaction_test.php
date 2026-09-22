<?php
/**
 * Automatic context compaction tests.
 *
 * Replaces a silent failure: trimMessages() keeps the newest N messages and drops
 * the rest, so a fact stated 20 turns ago vanishes and the model then denies
 * knowing it. Nothing errors, the answer just gets worse.
 *
 * ── The regression this file now guards, which shipped once ──
 * The digest was originally inserted as a MESSAGE at the front of the array. It was
 * then discarded by trimMessages(), measured in the live path:
 *     context_compaction applied=True compacted=31 digest_chars=3106
 *     trimmed_count=6  sent_message_count=5  digest_present_in_sent=False
 * trimMessages() walks BACKWARDS keeping the newest messages, and its effective cap
 * depends on the reply budget - only 4 messages at maxReplyTokens=60. A digest at
 * the front is therefore discarded first, and an instrumented request confirmed the
 * model answered with a hallucinated codename instead of the real one.
 *
 * The contract is now: the digest is returned SEPARATELY and appended to the system
 * prompt, which message-level trimming cannot touch. The assertion
 * "digest is NOT present in the message array" exists specifically to catch a
 * regression back to the broken placement, because that version passed every other
 * test in this file.
 *
 * Fixture caution, also learned the hard way: use unambiguous markers
 * ("[[detail-7]]"). An earlier version asserted on "detail number 1", a substring of
 * "detail number 17", so it passed while the content it claimed to verify had been
 * dropped. Loose matchers give false confidence in exactly the case being tested.
 */

$R = '/var/www/vhosts/lyralinkai.com/httpdocs/api';
require_once $R . '/security.php';
require_once $R . '/lib/chat/conversation_intelligence.php';
require_once $R . '/lib/chat/response_helpers.php';

$tests = 0;
$passed = 0;
$failures = [];

function t(string $name, bool $ok, string $note = ''): void {
    global $tests, $passed, $failures;
    $tests++;
    if ($ok) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failures[] = $name;
        echo "FAIL: {$name}" . ($note !== '' ? "  ({$note})" : '') . "\n";
    }
}

function build_verbose_conversation(int $turns, bool $withSystem = true): array {
    $msgs = $withSystem ? [['role' => 'system', 'content' => 'You are Lyralink. Be precise.']] : [];
    for ($i = 1; $i <= $turns; $i++) {
        $msgs[] = ['role' => 'user', 'content' => "Turn {$i}: I need a detailed explanation of topic {$i}, "
            . 'including the tradeoffs, the operational implications, and the concrete steps I should take. '
            . "Please keep [[detail-{$i}]] in mind for later questions."];
        $msgs[] = ['role' => 'assistant', 'content' => "Turn {$i}: Here is a thorough answer about topic {$i}. "
            . 'There are several tradeoffs worth weighing, an operational dimension that matters for production, '
            . "and a sequence of concrete steps that follow. I have noted [[detail-{$i}]] for later."];
        if ($i === $turns) {
            $msgs[] = ['role' => 'user', 'content' => 'Thanks - summarise what we decided.'];
        }
    }
    return $msgs;
}

function build_terse_conversation(int $turns): array {
    $msgs = [['role' => 'system', 'content' => 'You are Lyralink.']];
    for ($i = 1; $i <= $turns; $i++) {
        $msgs[] = ['role' => 'user', 'content' => "q{$i}"];
        $msgs[] = ['role' => 'assistant', 'content' => "a{$i}"];
    }
    return $msgs;
}

function all_content(array $messages): string {
    $out = '';
    foreach ($messages as $m) {
        if (is_array($m)) {
            $out .= (string)($m['content'] ?? '') . "\n";
        }
    }
    return $out;
}

/** The digest is delivered separately, so checks must include it. */
function digest_of(array $result): string {
    return (string)($result['digest'] ?? '');
}

function has_marker_in(string $haystack, int $n): bool {
    return str_contains($haystack, "[[detail-{$n}]]");
}

function approx_tokens_of(string $text): int {
    return (int)ceil(strlen($text) / 4);
}

$long = build_verbose_conversation(20);
$res = chat_compact_conversation($long);
$digest = digest_of($res);
$limit = 8;

echo "=== A. long conversation is compacted ===\n";
t('compaction applied', ($res['applied'] ?? false) === true, 'messages=' . count($long));
t('reports how many turns were folded', ($res['compacted'] ?? 0) > 0, 'compacted=' . ($res['compacted'] ?? 'null'));
t('message array is smaller than the input', count($res['messages']) < count($long),
    count($res['messages']) . ' vs ' . count($long));
t('a digest is returned', $digest !== '');

echo "\n=== B. RETENTION: foundational early turns survive ===\n";
t('earliest turn survives ([[detail-1]])', has_marker_in($digest, 1));
t('second turn survives ([[detail-2]])', has_marker_in($digest, 2));
$firstTurnLine = strpos($digest, '[[detail-1]]');
$secondTurnLine = strpos($digest, '[[detail-2]]');
t('retained in chronological order', $firstTurnLine !== false
    && ($secondTurnLine === false || $firstTurnLine < $secondTurnLine));
t('the digest identifies itself', str_contains($digest, 'compacted'));

echo "\n=== C. RETENTION: turns nearest the verbatim window survive ===\n";
// Turn 18 lies inside the 8-message verbatim window, so it is carried by the
// message array, not the digest. Look at the combined view for it, and check the
// digest for the newest FOLDED turn specifically - that is what "the turns nearest
// the verbatim window are kept" actually asserts.
$combinedView = all_content($res['messages']) . $digest;
t('a turn inside the verbatim window is present ([[detail-18]])', has_marker_in($combinedView, 18));
t('the newest folded turn survives in the digest ([[detail-16]])', has_marker_in($digest, 16));
t('the digest does not duplicate the verbatim window', !has_marker_in($digest, 19));

echo "\n=== D. REGRESSION GUARD: the digest must NOT be a trimmable message ===\n";
$messagesText = all_content($res['messages']);
t('digest is absent from the message array', !str_contains($messagesText, 'Earlier turns in this conversation, compacted'),
    'a digest placed in the array gets trimmed away first (measured: digest_present_in_sent=False)');
t('system prompt channel is the delivery mechanism',
    str_contains((string)file_get_contents('/var/www/vhosts/lyralinkai.com/httpdocs/api/chat.php'),
        "\$systemPrompt .= \"\\n\\n\" . \$contextCompaction['digest'];"));
// Demonstrate why: trimming the array must not be able to reach the digest.
$trimmedProbe = trimMessages($res['messages'], 'x', 'lyralink-reasoning:latest', 60);
t('the digest is not in the array even after trimming',
    !str_contains(all_content($trimmedProbe['messages']), 'Earlier turns in this conversation, compacted'));

echo "\n=== E. recent turns are verbatim in the message array ===\n";
$tailOriginal = array_slice($long, -$limit);
$tailCompacted = array_slice($res['messages'], -$limit);
$same = count($tailOriginal) === count($tailCompacted);
if ($same) {
    foreach ($tailOriginal as $i => $orig) {
        if (($orig['content'] ?? '') !== ($tailCompacted[$i]['content'] ?? '')) {
            $same = false;
            break;
        }
    }
}
t("last {$limit} messages are byte-identical", $same);
t('final message preserved exactly',
    ($long[count($long) - 1]['content'] ?? '') === ($res['messages'][count($res['messages']) - 1]['content'] ?? ''));
t('leading system instruction preserved verbatim',
    ($res['messages'][0]['content'] ?? '') === 'You are Lyralink. Be precise.');

echo "\n=== F. prompt actually shrinks ===\n";
$beforeChars = strlen(all_content($long));
$afterChars = strlen(all_content($res['messages'])) + strlen($digest);
t('total prompt content is smaller', $afterChars < $beforeChars, "{$beforeChars} -> {$afterChars} chars");
printf("      approx tokens: %d -> %d  (%.0f%% reduction)\n",
    approx_tokens_of(all_content($long)), approx_tokens_of(all_content($res['messages']) . $digest),
    (1 - $afterChars / max(1, $beforeChars)) * 100);

echo "\n=== G. size guard: compaction never grows the prompt ===\n";
$terse = build_terse_conversation(30);
$resTerse = chat_compact_conversation($terse);
t('terse conversation is left alone', ($resTerse['applied'] ?? true) === false);
t('terse conversation returned unchanged', $resTerse['messages'] === $terse);
t('never grows the prompt',
    (strlen(all_content($resTerse['messages'])) + strlen(digest_of($resTerse))) <= strlen(all_content($terse)));

echo "\n=== H. short conversations are untouched ===\n";
$short = build_verbose_conversation(2);
$resShort = chat_compact_conversation($short);
t('short conversation not compacted', ($resShort['applied'] ?? true) === false);
t('short conversation unchanged', $resShort['messages'] === $short);

echo "\n=== I. can be disabled ===\n";
$resOff = chat_compact_conversation($long, ['enabled' => false]);
t('enabled=false bypasses compaction', ($resOff['applied'] ?? true) === false);
t('enabled=false returns the original array', $resOff['messages'] === $long);
t('enabled=false returns no digest', digest_of($resOff) === '');

echo "\n=== J. budget pressure is honest about what it dropped ===\n";
$tight = chat_compact_conversation($long, ['digest_chars' => 600]);
$tightDigest = digest_of($tight);
t('tight budget still applied', ($tight['applied'] ?? false) === true);
t('tight budget states that turns were omitted', str_contains($tightDigest, 'omitted'));
t('tight budget still keeps the first turn', has_marker_in($tightDigest, 1));
t('tight budget stays small', ($tight['digest_chars'] ?? 0) <= 600 + 400, 'digest_chars=' . ($tight['digest_chars'] ?? '?'));

echo "\n=== K. role handling ===\n";
$systemCount = 0;
foreach ($res['messages'] as $m) {
    if (is_array($m) && strtolower((string)($m['role'] ?? '')) === 'system') {
        $systemCount++;
    }
}
t('only the original system message remains in the array', $systemCount === 1, 'found ' . $systemCount);
$lastMsg = $res['messages'][count($res['messages']) - 1];
$origLast = $long[count($long) - 1];
t('final message role is preserved', ($lastMsg['role'] ?? '') === ($origLast['role'] ?? ''));
t('message order is preserved', ($lastMsg['content'] ?? '') === ($origLast['content'] ?? ''));

echo "\n=== L. degenerate and system-less inputs ===\n";
t('empty array is safe', chat_compact_conversation([])['messages'] === []);
t('single message is safe', count(chat_compact_conversation([['role' => 'user', 'content' => 'hi']])['messages']) === 1);
$noSystem = build_verbose_conversation(20, false);
$resNoSystem = chat_compact_conversation($noSystem);
t('conversation with no system message is compacted', ($resNoSystem['applied'] ?? false) === true);
t('digest present without a leading system message', has_marker_in(digest_of($resNoSystem), 1));

printf("\n%d/%d tests passed\n", $passed, $tests);
if ($failures !== []) {
    echo "FAILED: " . implode(', ', $failures) . "\n";
}
exit($passed === $tests ? 0 : 1);
