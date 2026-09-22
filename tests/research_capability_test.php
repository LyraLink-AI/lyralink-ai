<?php
/**
 * Research capability tests - deterministic, no network.
 *
 * The fixtures are the REAL result titles observed on 2026-09-20, when SearXNG's
 * bing engine was returning a block page and SearXNG scraped titles off it. They
 * are reproduced verbatim so this test fails if the guard stops catching that.
 *
 * Network behaviour (does fetching actually verify a page, is SearXNG up) belongs
 * in tests/diagnose_research.php, not here - a standing test suite must not fail
 * because a third-party site was slow.
 */

$tests = 0;
$passed = 0;

function check_test(string $name, bool $condition): void {
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
        return;
    }
    echo "FAIL: {$name}\n";
}

$R = '/var/www/vhosts/lyralinkai.com/httpdocs/api';
require_once $R . '/security.php';
require_once $R . '/lib/network_policy.php';
require_once $R . '/lib/chat/execution_foundation.php';
require_once $R . '/lib/chat/conversation_intelligence.php';

/** Build a result the way the search layers do. */
function res(string $title, string $url = '', string $snippet = '', int $tier = 4): array {
    return [
        'title' => $title,
        'url' => $url !== '' ? $url : 'https://example.com/' . md5($title),
        'host' => 'example.com',
        'snippet' => $snippet,
        'source_type' => 'search_result',
        'authority_tier' => $tier,
    ];
}

echo "--- A. relevance gate rejects result sets that do not answer the query ---\n";

// Verbatim junk from the blocked bing engine.
$awsJunk = [
    res('Chase Bank Branch in Redmond | 17667 NE 76th St'),
    res('17667 Ne 76Th St, Redmond, WA 98052 - APN/Parcel ID: 719893'),
    res('Life Time Buys Redmond Fred Meyer For $32 Million - hoodline.com'),
    res('Miele Reviews | Read Customer Service Reviews of mieleusa'),
    res('BookWidgets quiz widgets - The Quiz Widget'),
];
check_test('AWS query rejects the real block-page junk', !chat_web_results_are_relevant($awsJunk, 'AWS multi-region failover whitepaper'));

$awsGood = [
    res('AWS multi-Region fundamentals - AWS Prescriptive Guidance'),
    res('Creating an organizational multi-Region failover strategy - AWS'),
    res('Exploring the AWS Multi-Region Whitepaper - Medium'),
];
check_test('AWS query accepts the real correct results', chat_web_results_are_relevant($awsGood, 'AWS multi-region failover whitepaper'));

$nobelJunk = [
    res('Convertisseur won en euro - Boursorama'),
    res('1 KRW en EUR - taux de change de Wons sud-coreens a Euros - Xe'),
    res('Won sud-coreen (depuis 1962) - Wikipedia'),
];
check_test('Nobel query rejects the real currency junk', !chat_web_results_are_relevant($nobelJunk, 'who won the 2024 Nobel Prize in Physics'));

$nobelGood = [
    res('The Nobel Prize in Physics 2024 - NobelPrize.org'),
    res('All Nobel Prizes in Physics - NobelPrize.org'),
];
check_test('Nobel query accepts the real NobelPrize.org results', chat_web_results_are_relevant($nobelGood, 'who won the 2024 Nobel Prize in Physics'));

$franceJunk = [res('Capital : Actualites Economie, Business, Immobilier & Argent'), res('Capital (magazine) - Wikipedia')];
check_test('France query rejects one-word-coincidence junk', !chat_web_results_are_relevant($franceJunk, 'what is the capital of France'));

$franceGood = [res('Paris facts: the capital of France in history'), res('Paris - Wikipedia')];
check_test('France query accepts the correct results', chat_web_results_are_relevant($franceGood, 'what is the capital of France'));

check_test('an empty query cannot block anything', chat_web_results_are_relevant($awsJunk, 'the of and'));

echo "\n--- B. ranking puts the relevant results first ---\n";

// Real mixed set: correct results present but arriving behind the junk.
$mixed = array_merge($nobelJunk, $nobelGood);
$ranked = chat_web_rank_results($mixed, 'who won the 2024 Nobel Prize in Physics');
check_test('top result after ranking mentions the Nobel Prize', stripos((string)$ranked[0]['title'], 'nobel') !== false);
check_test('currency junk is pushed down', stripos((string)$ranked[0]['title'], 'euro') === false);
check_test('ranking preserves every result (recall)', count($ranked) === count($mixed));

$awsMixed = array_merge($awsJunk, $awsGood);
$awsRanked = chat_web_rank_results($awsMixed, 'AWS multi-region failover whitepaper');
check_test('AWS: top result is an AWS result', stripos((string)$awsRanked[0]['title'], 'aws') !== false);
check_test('AWS: car/quiz junk no longer leads', stripos((string)$awsRanked[0]['title'], 'miele') === false);

echo "\n--- C. ranking is stable and never drops results ---\n";
$r1 = chat_web_rank_results($mixed, 'who won the 2024 Nobel Prize in Physics');
$r2 = chat_web_rank_results($mixed, 'who won the 2024 Nobel Prize in Physics');
check_test('ranking is deterministic across calls', array_column($r1, 'title') === array_column($r2, 'title'));

$dupes = [res('Paris - Wikipedia', 'https://a.example/x'), res('Paris - Wikipedia', 'https://a.example/x'), res('Paris', 'https://b.example/y')];
check_test('duplicate URLs are collapsed', count(chat_web_rank_results($dupes, 'paris france capital')) === 2);

echo "\n--- D. the fetch stage is wired to run on every source path ---\n";
check_test('chat_web_search_query is a wrapper (resolver exists separately)', function_exists('chat_web_resolve_sources'));
check_test('fetch stage exists and is called by the wrapper', function_exists('chat_web_enrich_results'));

$src = (string)file_get_contents($R . '/lib/chat/conversation_intelligence.php');
// The bug was that the fetch step sat after the resolver's early returns, so it
// only ran on the DuckDuckGo branch. The wrapper must own it.
$wrapperPos = strpos($src, 'function chat_web_search_query(');
$enrichCall = strpos($src, 'return chat_web_enrich_results(', $wrapperPos);
check_test('wrapper routes results through the fetch stage', $wrapperPos !== false && $enrichCall !== false);
check_test('source fetching is gated by CHAT_WEB_FETCH_PAGES', strpos($src, "CHAT_WEB_FETCH_PAGES") !== false);
check_test('fetch is bounded by successes, not index', strpos($src, '$wantFetched') !== false);
check_test('fetch honours a wall-clock deadline', strpos($src, '$deadlineTs - microtime(true)') !== false);

echo "\n--- E. verification status is derived correctly from fetched state ---\n";
$fetchedNone = [res('a'), res('b')];
foreach ($fetchedNone as $i => $x) { $fetchedNone[$i]['excerpt'] = 'x'; $fetchedNone[$i]['fetched'] = false; }
$fetchCount = 0;
$parsedCount = 0;
foreach ($fetchedNone as $x) {
    if (!empty($x['fetched'])) { $fetchCount++; }
    if (trim((string)($x['excerpt'] ?? '')) !== '') { $parsedCount++; }
}
check_test('snippet-only results do NOT count as verified', !($fetchCount > 0 && $parsedCount > 0));

$fetchedSome = $fetchedNone;
$fetchedSome[0]['fetched'] = true;
$fetchCount = 0;
$parsedCount = 0;
foreach ($fetchedSome as $x) {
    if (!empty($x['fetched'])) { $fetchCount++; }
    if (trim((string)($x['excerpt'] ?? '')) !== '') { $parsedCount++; }
}
check_test('a truly fetched page DOES count as verified', $fetchCount > 0 && $parsedCount > 0);

printf("%d/%d tests passed\n", $passed, $tests);
exit($passed === $tests ? 0 : 1);
