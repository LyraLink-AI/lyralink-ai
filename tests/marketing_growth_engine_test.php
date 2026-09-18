<?php
require_once __DIR__ . '/../api/marketing_lib.php';

function marketing_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$level = marketing_authorization_level('discord', ['MARKETING_ALLOW_PUBLIC_POSTS' => '0']);
marketing_test_assert($level['allow'] === false, 'Public posting must be blocked without explicit authorization');
marketing_test_assert($level['level'] === 0, 'Default external action level should be analysis-only');

$claim = marketing_validate_claim(
    'Lyralink benchmark reached 91% on internal quality checks.',
    [
        'evidence' => 'Benchmark file exists and methodology is described in the project benchmark documentation.',
        'source' => 'benchmark/README.md',
        'verified' => true,
    ]
);
marketing_test_assert($claim['supported'] === true, 'Supportable benchmark claims should pass validation');

$unsupported = marketing_validate_claim(
    'Lyralink has 15,000 paying customers and 98% retention.',
    ['evidence' => 'No customer data or revenue records were provided.']
);
marketing_test_assert($unsupported['supported'] === false, 'Unverified revenue and customer claims must be rejected');

$topicPool = marketing_parse_topic_list('ai safety, local ai, automation workflows, ai safety', ['fallback topic']);
marketing_test_assert(count($topicPool) === 3, 'Topic parser should deduplicate topics while preserving usable items');

$cycleTopics = marketing_select_cycle_topics($topicPool, 2, 'stable-seed');
marketing_test_assert(count($cycleTopics) === 2, 'Topic selector should return the configured number of topics');

$generatedOps = marketing_generate_opportunities_from_topics($cycleTopics, 'benchmark methodology documented');
marketing_test_assert(!empty($generatedOps), 'Topic discovery should generate opportunity candidates');
marketing_test_assert(!empty($generatedOps[0]['recommended_next_action']), 'Generated opportunities must include a next action recommendation');

$opportunity = marketing_score_opportunity([
    'problem' => 'AI agent actions fail without proving tool execution succeeded.',
    'audience' => 'AI developers',
    'evidence' => 'A benchmark showed tool-call verification is a repeated reliability issue.',
    'lyralink_relevance' => 'Lyralink already enforces verification and bounded analysis.',
    'frequency' => 4,
    'severity' => 5,
    'confidence' => 4,
    'fit' => 5,
    'conversion' => 3,
]);
marketing_test_assert($opportunity['score'] > 0, 'A real problem with evidence should score above zero');
marketing_test_assert($opportunity['priority'] !== 'IGNORE', 'A credible opportunity must not be discarded');

$report = marketing_build_growth_report([
    'product_context' => 'Lyralink is an AI platform and internal infrastructure toolchain.',
    'benchmark_status' => 'public benchmark is available and methodology is documented.',
    'audience_segments' => ['AI developers', 'operators', 'startup founders'],
    'opportunities' => array_merge([$opportunity], $generatedOps),
    'experiments' => marketing_generate_experiments_from_recommendations([
        ['priority' => 'P1', 'audience' => 'AI developers', 'recommendation' => 'Publish a problem-first reliability breakdown with evidence and a low-pressure CTA.'],
    ]),
]);
marketing_test_assert(isset($report['executive_summary']), 'Report must include executive summary');
marketing_test_assert(isset($report['recommendations']), 'Report must include recommendations');
marketing_test_assert(isset($report['audit']), 'Report must include audit metadata');
marketing_test_assert(!empty($report['experiments']), 'Automated recommendation conversion should generate experiment drafts');

echo "marketing growth engine tests passed\n";
