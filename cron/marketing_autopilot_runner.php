<?php
/**
 * Lyralink Marketing Growth Engine
 *
 * Default posture: analysis-only, evidence-first, approval-gated for all external actions.
 * The system may generate strategy, research, and opportunity scoring without publishing to
 * any external platform unless the required authorization flags are explicitly enabled.
 */

require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/marketing_lib.php';
require_once __DIR__ . '/../api/lib/chat/os_core.php';

date_default_timezone_set('UTC');

const MARKETING_LOCK_FILE = '/tmp/lyralink-marketing-autopilot.lock';
const MARKETING_RUN_LOG = '/tmp/lyralink-marketing-autopilot.log';

$startedAt = microtime(true);

$lockHandle = @fopen(MARKETING_LOCK_FILE, 'c+');
if (!$lockHandle) {
    fwrite(STDERR, "[marketing_autopilot] could not open lock file\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[marketing_autopilot] already running, skipping\n");
    exit(0);
}
register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

function marketing_log(string $message): void {
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents(MARKETING_RUN_LOG, $line, FILE_APPEND);
    echo $line;
}

function marketing_add_action(array &$report, string $title, bool $ok, string $detail): void {
    $report['actions'][] = ['title' => $title, 'ok' => $ok, 'detail' => $detail];
}

function marketing_add_finding(array &$report, string $severity, string $title, string $detail): void {
    $report['findings'][] = ['severity' => $severity, 'title' => $title, 'detail' => $detail];
}

$report = [
    'started_at' => gmdate('c'),
    'actions' => [],
    'findings' => [],
    'stats' => [
        'analysis_only' => 1,
        'public_posts_allowed' => 0,
        'campaigns_evaluated' => 0,
        'opportunities_identified' => 0,
        'topics_processed' => 0,
        'opportunities_persisted' => 0,
        'experiments_auto_drafted' => 0,
    ],
];

$db = null;
$dbOk = false;
$dbCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
$db = @new mysqli((string)$dbCfg['host'], (string)$dbCfg['user'], (string)$dbCfg['pass'], (string)$dbCfg['name']);
if ($db && !$db->connect_error) {
    $dbOk = true;
    $db->set_charset('utf8mb4');
    marketing_ensure_schema($db);

    $campaignEvalWindowDays = max(1, (int)api_get_secret('MARKETING_CAMPAIGN_EVAL_WINDOW_DAYS', '30'));
    $campaignCountRes = $db->query('SELECT COUNT(*) AS c FROM marketing_campaigns WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $campaignEvalWindowDays . ' DAY)');
    $report['stats']['campaigns_evaluated'] = (int)($campaignCountRes ? ($campaignCountRes->fetch_assoc()['c'] ?? 0) : 0);
} else {
    marketing_add_finding($report, 'high', 'Marketing DB unavailable', 'Discovery loop will run in-memory only for this cycle because database connectivity failed.');
}

$marketingOsDecision = chat_os_cron_job_context(
    'marketing_autopilot_runner',
    'Run scheduled marketing discovery, opportunity scoring, and approval-gated external publishing review.',
    [
        'db' => $dbOk ? $db : null,
        'risk_level' => 'MEDIUM',
        'task_domain' => 'marketing',
        'workspace_available' => true,
        'database_runtime_available' => $dbOk,
        'web_search_requested' => true,
        'web_runtime_available' => function_exists('chat_web_search_query_with_status') || function_exists('chat_web_search_query'),
        'granted_permissions' => ['model.generate', 'filesystem.read', 'network.read'],
    ]
);
$marketingTask = is_array($marketingOsDecision['task'] ?? null) ? $marketingOsDecision['task'] : [];
if ($marketingTask !== []) {
    $report['ai_os'] = [
        'task_id' => $marketingTask['task_id'] ?? null,
        'request_id' => $marketingOsDecision['request_id'] ?? null,
        'route_class' => $marketingOsDecision['route_class'] ?? null,
        'capability_id' => $marketingOsDecision['capability']['capability_id'] ?? null,
        'resource_state' => $marketingOsDecision['resource']['state'] ?? null,
        'authorization_state' => $marketingOsDecision['authorization']['state'] ?? null,
    ];
}

$authContext = [
    'MARKETING_ALLOW_PUBLIC_POSTS' => trim((string)api_get_secret('MARKETING_ALLOW_PUBLIC_POSTS', '0')),
    'MARKETING_ALLOW_DISCORD_POSTS' => trim((string)api_get_secret('MARKETING_ALLOW_DISCORD_POSTS', '0')),
    'MARKETING_ALLOW_REDDIT_POSTS' => trim((string)api_get_secret('MARKETING_ALLOW_REDDIT_POSTS', '0')),
    'MARKETING_ALLOW_YOUTUBE_POSTS' => trim((string)api_get_secret('MARKETING_ALLOW_YOUTUBE_POSTS', '0')),
];

$publicAuth = marketing_authorization_level('publish', $authContext);
$report['authorization'] = $publicAuth;
$report['stats']['public_posts_allowed'] = $publicAuth['allow'] ? 1 : 0;

$claims = [
    'Lyralink operates as an evidence-first AI platform with public benchmark visibility and bounded analysis by default.',
    'The marketing system must not fabricate customers, revenue, or benchmark outcomes.',
    'External publishing requires explicit authorization before any public-facing message is sent.',
];

foreach ($claims as $claim) {
    $validated = marketing_validate_claim($claim, ['evidence' => 'Core product and benchmark evidence are present in the repository and operational docs.', 'verified' => true]);
    if ($validated['supported'] !== true) {
        marketing_add_finding($report, 'medium', 'Evidence gate', 'Claim validation rejected a marketing statement before it reached publication.');
    }
}

$benchmarkStatus = 'public benchmark is available and methodology is documented';
$productContext = 'Lyralink is an AI platform with benchmark visibility, local/self-hosted capabilities, automation workflows, dataset tooling, and a status/security-transparent infrastructure.';

$fallbackTopics = [
    'ai reliability',
    'agent orchestration',
    'local ai',
    'developer tooling',
    'ai safety',
    'automation workflows',
    'context memory systems',
    'infrastructure observability',
];
$topicSourceRaw = (string)api_get_secret('MARKETING_DISCOVERY_TOPICS', '');
$seedTopicsRaw = (string)api_get_secret('PUBLIC_WEB_SEED_TOPICS', '');
$topicPool = marketing_parse_topic_list($topicSourceRaw, marketing_parse_topic_list($seedTopicsRaw, $fallbackTopics));
$topicsPerCycle = max(2, min(12, (int)api_get_secret('MARKETING_DISCOVERY_TOPICS_PER_CYCLE', '6')));
$cycleSeed = gmdate('Y-m-d-H') . '|' . (string)$report['stats']['campaigns_evaluated'];
$cycleTopics = marketing_select_cycle_topics($topicPool, $topicsPerCycle, $cycleSeed);
$report['stats']['topics_processed'] = count($cycleTopics);
$report['cycle_topics'] = $cycleTopics;

if (empty($cycleTopics)) {
    marketing_add_finding($report, 'high', 'No discovery topics', 'No discovery topics were available. Set MARKETING_DISCOVERY_TOPICS or PUBLIC_WEB_SEED_TOPICS.');
}

$baselineOpportunities = [
    [
        'problem' => 'AI agents fail without proving tool execution succeeded.',
        'audience' => 'AI developers',
        'evidence' => 'Operational reliability issues repeat around tool success, verification, and bounded analysis.',
        'lyralink_fit' => 5,
        'fit' => 5,
        'frequency' => 4,
        'severity' => 5,
        'confidence' => 4,
        'conversion' => 3,
        'recommended_next_action' => 'Publish a build-in-public post that explains verification gaps, invite feedback, and capture interested engineer signups without making unsupported claims.',
    ],
    [
        'problem' => 'Teams want transparent, locally controllable AI infrastructure without vendor lock-in.',
        'audience' => 'startup founders',
        'evidence' => 'The platform already supports local/self-hosted models and status transparency, which addresses a frequent product concern.',
        'lyralink_fit' => 5,
        'fit' => 4,
        'frequency' => 3,
        'severity' => 4,
        'confidence' => 4,
        'conversion' => 3,
        'recommended_next_action' => 'Create a narrow founder-focused content series around sovereignty, reliability, and deployment control.',
    ],
    [
        'problem' => 'Operators need a visible engineering loop showing how a product learns from failures and improves.',
        'audience' => 'operators',
        'evidence' => 'Public benchmark and failure transparency are part of the product story and create trust.',
        'lyralink_fit' => 4,
        'fit' => 4,
        'frequency' => 3,
        'severity' => 3,
        'confidence' => 4,
        'conversion' => 2,
        'recommended_next_action' => 'Feature benchmark updates, failure analysis, and product learning as an explicit operating model in customer communications.',
    ],
];

$discoveredOpportunities = marketing_generate_opportunities_from_topics($cycleTopics, $benchmarkStatus);
$opportunities = array_merge($baselineOpportunities, $discoveredOpportunities);

$segments = [];
foreach ($opportunities as $opportunity) {
    $aud = trim((string)($opportunity['audience'] ?? ''));
    if ($aud !== '') {
        $segments[strtolower($aud)] = $aud;
    }
}

$draftExperiments = marketing_generate_experiments_from_recommendations(array_map(static function (array $op): array {
    $scored = marketing_score_opportunity($op);
    return [
        'priority' => $scored['priority'] ?? 'P3',
        'audience' => $scored['audience'] ?? 'general',
        'recommendation' => $scored['recommendation'] ?? '',
    ];
}, $opportunities));

$growthReport = marketing_build_growth_report([
    'product_context' => $productContext,
    'benchmark_status' => $benchmarkStatus,
    'audience_segments' => array_values($segments),
    'opportunities' => $opportunities,
    'experiments' => $draftExperiments,
]);

$report['stats']['opportunities_identified'] = count($growthReport['opportunities'] ?? []);
$report['growth_report'] = $growthReport;

if ($dbOk && $db instanceof mysqli) {
    $persistedOpportunities = 0;
    foreach ($opportunities as $opportunity) {
        $scored = marketing_score_opportunity($opportunity);
        $audience = (string)($scored['audience'] ?? 'general');
        $problem = (string)($scored['problem'] ?? '');
        if ($problem === '') {
            continue;
        }
        $source = (string)($opportunity['source'] ?? 'autopilot');
        $evidence = (string)($opportunity['evidence'] ?? 'Autopilot discovery');
        $score = (float)($scored['score'] ?? 0.0);
        $priority = (string)($scored['priority'] ?? 'P3');

        $existsStmt = $db->prepare('SELECT id FROM marketing_opportunities WHERE audience = ? AND problem = ? ORDER BY id DESC LIMIT 1');
        if (!$existsStmt) {
            continue;
        }
        $existsStmt->bind_param('ss', $audience, $problem);
        $existsStmt->execute();
        $existing = $existsStmt->get_result()->fetch_assoc() ?: null;
        $existsStmt->close();

        if ($existing) {
            $id = (int)$existing['id'];
            $upd = $db->prepare('UPDATE marketing_opportunities SET source = ?, evidence = ?, score = ?, priority = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
            if ($upd) {
                $upd->bind_param('ssdsi', $source, $evidence, $score, $priority, $id);
                if ($upd->execute()) {
                    $persistedOpportunities++;
                }
                $upd->close();
            }
            continue;
        }

        $status = 'open';
        $ins = $db->prepare('INSERT INTO marketing_opportunities (audience, source, problem, evidence, score, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if ($ins) {
            $ins->bind_param('ssssdss', $audience, $source, $problem, $evidence, $score, $priority, $status);
            if ($ins->execute()) {
                $persistedOpportunities++;
            }
            $ins->close();
        }
    }
    $report['stats']['opportunities_persisted'] = $persistedOpportunities;

    $autoDrafted = 0;
    foreach (array_values($growthReport['recommendations'] ?? []) as $recommendation) {
        $audience = trim((string)($recommendation['audience'] ?? 'general'));
        $expected = trim((string)($recommendation['recommendation'] ?? ''));
        if ($expected === '') {
            continue;
        }

        $existsStmt = $db->prepare('SELECT id FROM marketing_experiments WHERE target_audience = ? AND expected_outcome = ? ORDER BY id DESC LIMIT 1');
        if (!$existsStmt) {
            continue;
        }
        $existsStmt->bind_param('ss', $audience, $expected);
        $existsStmt->execute();
        $existing = $existsStmt->get_result()->fetch_assoc() ?: null;
        $existsStmt->close();
        if ($existing) {
            continue;
        }

        $hypothesis = 'Problem-first messaging for ' . $audience . ' will increase qualified product interest versus generic promotional posts.';
        $variable = 'content hook + technical depth + CTA';
        $baseline = 'generic product-first messaging';
        $status = 'draft';

        $ins = $db->prepare('INSERT INTO marketing_experiments (hypothesis, target_audience, variable, control_baseline, expected_outcome, status) VALUES (?, ?, ?, ?, ?, ?)');
        if ($ins) {
            $ins->bind_param('ssssss', $hypothesis, $audience, $variable, $baseline, $expected, $status);
            if ($ins->execute()) {
                $autoDrafted++;
            }
            $ins->close();
        }
    }
    $report['stats']['experiments_auto_drafted'] = $autoDrafted;

    marketing_record_audit_event(
        $db,
        'internal',
        'autopilot.marketing_cycle',
        'processed',
        [
            'topics_processed' => $report['stats']['topics_processed'],
            'opportunities_identified' => $report['stats']['opportunities_identified'],
            'opportunities_persisted' => $report['stats']['opportunities_persisted'],
            'experiments_auto_drafted' => $report['stats']['experiments_auto_drafted'],
        ],
        'Autonomous marketing discovery and experiment drafting cycle',
        1,
        'autopilot'
    );

    marketing_add_action(
        $report,
        'Autonomous discovery cycle',
        true,
        'topics=' . $report['stats']['topics_processed']
            . ', opportunities=' . $report['stats']['opportunities_identified']
            . ', persisted=' . $report['stats']['opportunities_persisted']
            . ', experiments=' . $report['stats']['experiments_auto_drafted']
    );
}

if ($publicAuth['allow']) {
    marketing_add_action($report, 'External publication approval', true, 'Authorization is explicitly enabled for public-facing actions.');
} else {
    marketing_add_action($report, 'External publication approval', false, 'Publishing remains blocked by default. The system is in analysis-only mode until an operator approves an action level.');
}

$report['finished_at'] = gmdate('c');
$report['duration_seconds'] = round(microtime(true) - $startedAt, 3);
$marketingStatus = !empty($report['findings']) ? 'FAILED' : 'SUCCEEDED';

if ($marketingTask !== []) {
    $marketingTask = chat_os_mark_task_result(
        $marketingTask,
        $marketingStatus,
        ['report' => $report],
        $marketingStatus === 'FAILED' ? 'MARKETING_DISCOVERY_REVIEW_REQUIRED' : null,
        $marketingStatus === 'FAILED' ? 'One or more marketing findings require review.' : null
    );
    chat_os_persist_task_record($marketingTask, ['job_name' => 'marketing_autopilot_runner'], $dbOk ? $db : null);
}

if ($dbOk && $db instanceof mysqli) {
    $jsonRaw = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO marketing_runs (started_at, finished_at, duration_seconds, report_json) VALUES (?, ?, ?, ?)');
    if ($stmt) {
        $startedAtSql = gmdate('Y-m-d H:i:s', (int)$startedAt);
        $finishedAtSql = gmdate('Y-m-d H:i:s');
        $duration = (float)$report['duration_seconds'];
        $stmt->bind_param('ssds', $startedAtSql, $finishedAtSql, $duration, $jsonRaw);
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

marketing_log('[marketing_autopilot] completed. topics=' . $report['stats']['topics_processed'] . ' opportunities=' . $report['stats']['opportunities_identified'] . ' experiments=' . $report['stats']['experiments_auto_drafted'] . ' external_publishing=' . ($publicAuth['allow'] ? 'enabled' : 'blocked'));
exit(0);
