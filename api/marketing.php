<?php
require_once __DIR__ . '/../api/session_boot.php';
require_once __DIR__ . '/marketing_lib.php';

lyra_session_boot();
api_json_headers();

api_enforce_post_and_origin_for_actions([
    'youtube_disconnect',
    'publish_video',
    'admin_opportunity_status',
    'admin_save_experiment',
    'admin_promote_recommendation',
]);

if (empty($_SESSION['user_id'])) {
    api_fail('Not logged in', 401);
}

$dbCfg = api_db_config([
    'host' => 'localhost',
    'user' => 'app_user',
    'pass' => '',
    'name' => 'aicloud',
]);

$db = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($db->connect_error) {
    api_fail('DB connection failed', 500);
}
$db->set_charset('utf8mb4');
marketing_ensure_schema($db);

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? 'youtube_status'));
$uid = (int)$_SESSION['user_id'];

if (!marketing_is_admin_session()) {
    api_fail('Forbidden', 403);
}

if ($action === 'admin_growth_snapshot') {
    $latestRunRow = null;
    $latestRunResult = $db->query('SELECT id, started_at, finished_at, duration_seconds, report_json, created_at FROM marketing_runs ORDER BY created_at DESC LIMIT 1');
    if ($latestRunResult) {
        $latestRunRow = $latestRunResult->fetch_assoc() ?: null;
    }

    $latestReport = null;
    if ($latestRunRow && !empty($latestRunRow['report_json'])) {
        $decoded = json_decode((string)$latestRunRow['report_json'], true);
        if (is_array($decoded)) {
            $latestReport = $decoded;
        }
    }

    $opportunities = [];
    $opRows = $db->query("SELECT id, audience, source, problem, evidence, score, priority, status, created_at, updated_at FROM marketing_opportunities ORDER BY score DESC, id DESC LIMIT 100");
    if ($opRows) {
        while ($row = $opRows->fetch_assoc()) {
            $opportunities[] = $row;
        }
    }

    $experiments = [];
    $expRows = $db->query("SELECT id, hypothesis, target_audience, variable, control_baseline, expected_outcome, status, result_json, created_at, updated_at FROM marketing_experiments ORDER BY updated_at DESC, id DESC LIMIT 100");
    if ($expRows) {
        while ($row = $expRows->fetch_assoc()) {
            $experiments[] = $row;
        }
    }

    $auth = [
        'public' => marketing_authorization_level('publish'),
        'discord' => marketing_authorization_level('discord'),
        'reddit' => marketing_authorization_level('reddit'),
        'youtube' => marketing_authorization_level('youtube'),
        'x' => marketing_authorization_level('x'),
    ];

    echo json_encode([
        'success' => true,
        'latest_run' => $latestRunRow,
        'latest_report' => $latestReport,
        'opportunities' => $opportunities,
        'experiments' => $experiments,
        'authorization' => $auth,
    ]);
    exit;
}

if ($action === 'admin_opportunity_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    if ($id <= 0 || !in_array($status, ['open', 'monitoring', 'archived'], true)) {
        api_fail('Invalid opportunity status payload');
    }

    $stmt = $db->prepare('UPDATE marketing_opportunities SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    if (!$stmt) {
        api_fail('Failed to prepare update', 500);
    }
    $stmt->bind_param('si', $status, $id);
    $ok = $stmt->execute();
    $stmt->close();

    marketing_record_audit_event(
        $db,
        'internal',
        'opportunity.status_update',
        $ok ? 'processed' : 'failed',
        ['id' => $id, 'status' => $status],
        'Opportunity status changed by admin',
        1,
        (string)($_SESSION['username'] ?? '')
    );

    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'admin_save_experiment') {
    $id = (int)($_POST['id'] ?? 0);
    $hypothesis = trim((string)($_POST['hypothesis'] ?? ''));
    $targetAudience = trim((string)($_POST['target_audience'] ?? ''));
    $variable = trim((string)($_POST['variable'] ?? ''));
    $control = trim((string)($_POST['control_baseline'] ?? ''));
    $expected = trim((string)($_POST['expected_outcome'] ?? ''));
    $status = strtolower(trim((string)($_POST['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'active', 'completed', 'failed'], true)) {
        $status = 'draft';
    }
    if ($hypothesis === '' || $targetAudience === '' || $variable === '') {
        api_fail('hypothesis, target_audience and variable are required');
    }

    if ($id > 0) {
        $stmt = $db->prepare('UPDATE marketing_experiments SET hypothesis = ?, target_audience = ?, variable = ?, control_baseline = ?, expected_outcome = ?, status = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
        if (!$stmt) {
            api_fail('Failed to prepare experiment update', 500);
        }
        $stmt->bind_param('ssssssi', $hypothesis, $targetAudience, $variable, $control, $expected, $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        $experimentId = $id;
    } else {
        $stmt = $db->prepare('INSERT INTO marketing_experiments (hypothesis, target_audience, variable, control_baseline, expected_outcome, status) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            api_fail('Failed to prepare experiment insert', 500);
        }
        $stmt->bind_param('ssssss', $hypothesis, $targetAudience, $variable, $control, $expected, $status);
        $ok = $stmt->execute();
        $experimentId = (int)$stmt->insert_id;
        $stmt->close();
    }

    marketing_record_audit_event(
        $db,
        'internal',
        'experiment.save',
        $ok ? 'processed' : 'failed',
        ['id' => $experimentId, 'status' => $status, 'target_audience' => $targetAudience],
        'Experiment saved by admin',
        1,
        (string)($_SESSION['username'] ?? '')
    );

    echo json_encode(['success' => $ok, 'id' => $experimentId]);
    exit;
}

if ($action === 'admin_promote_recommendation') {
    $recommendation = trim((string)($_POST['recommendation'] ?? ''));
    $audience = trim((string)($_POST['audience'] ?? 'general'));
    $priority = strtoupper(trim((string)($_POST['priority'] ?? 'P3')));
    if ($recommendation === '') {
        api_fail('recommendation is required');
    }
    if (!in_array($priority, ['P0', 'P1', 'P2', 'P3', 'IGNORE'], true)) {
        $priority = 'P3';
    }

    $scoreMap = ['P0' => 36.0, 'P1' => 28.0, 'P2' => 20.0, 'P3' => 12.0, 'IGNORE' => 0.0];
    $score = $scoreMap[$priority] ?? 12.0;

    $opStmt = $db->prepare('INSERT INTO marketing_opportunities (audience, source, problem, evidence, score, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$opStmt) {
        api_fail('Failed to prepare opportunity insert', 500);
    }
    $source = 'admin_recommendation';
    $evidence = 'Promoted from growth report recommendation';
    $status = 'open';
    $opStmt->bind_param('ssssdss', $audience, $source, $recommendation, $evidence, $score, $priority, $status);
    $okOpportunity = $opStmt->execute();
    $opportunityId = (int)$opStmt->insert_id;
    $opStmt->close();

    $expStmt = $db->prepare('INSERT INTO marketing_experiments (hypothesis, target_audience, variable, control_baseline, expected_outcome, status) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$expStmt) {
        api_fail('Failed to prepare experiment insert', 500);
    }
    $hypothesis = 'If we execute this recommendation, qualified attention and product interest should improve.';
    $variable = 'message + channel format';
    $baseline = 'previous generic messaging';
    $expected = $recommendation;
    $expStatus = 'draft';
    $expStmt->bind_param('ssssss', $hypothesis, $audience, $variable, $baseline, $expected, $expStatus);
    $okExperiment = $expStmt->execute();
    $experimentId = (int)$expStmt->insert_id;
    $expStmt->close();

    $ok = $okOpportunity && $okExperiment;
    marketing_record_audit_event(
        $db,
        'internal',
        'recommendation.promote',
        $ok ? 'processed' : 'failed',
        ['opportunity_id' => $opportunityId, 'experiment_id' => $experimentId, 'priority' => $priority],
        'Recommendation promoted to opportunity and experiment',
        1,
        (string)($_SESSION['username'] ?? '')
    );

    echo json_encode(['success' => $ok, 'opportunity_id' => $opportunityId, 'experiment_id' => $experimentId]);
    exit;
}

if ($action === 'marketing_report') {
    $latestRunRow = null;
    $latestRunResult = $db->query('SELECT id, started_at, finished_at, duration_seconds, report_json, created_at FROM marketing_runs ORDER BY created_at DESC LIMIT 1');
    if ($latestRunResult) {
        $latestRunRow = $latestRunResult->fetch_assoc() ?: null;
    }

    $latestReport = null;
    if ($latestRunRow && !empty($latestRunRow['report_json'])) {
        $decoded = json_decode((string)$latestRunRow['report_json'], true);
        if (is_array($decoded)) {
            $latestReport = $decoded;
        }
    }

    $campaigns = [];
    $campaignResult = $db->query('SELECT id, platform, title, status, youtube_url, source_url, published_at, created_at FROM marketing_campaigns WHERE user_id = ' . (int)$uid . ' ORDER BY created_at DESC LIMIT 10');
    if ($campaignResult) {
        while ($row = $campaignResult->fetch_assoc()) {
            $campaigns[] = $row;
        }
    }

    $youtubeRow = marketing_get_user_youtube_token_row($db, $uid);
    echo json_encode([
        'success' => true,
        'latest_run' => $latestRunRow,
        'latest_report' => $latestReport,
        'campaigns' => $campaigns,
        'youtube_connected' => !empty($youtubeRow),
        'youtube_channel_title' => $youtubeRow['channel_title'] ?? null,
    ]);
    exit;
}

if ($action === 'youtube_connect') {
    $authUrl = marketing_google_oauth_url();
    if ($authUrl === '') {
        echo json_encode(['success' => false, 'error' => 'YOUTUBE_CLIENT_ID is not configured yet.']);
        exit;
    }
    echo json_encode(['success' => true, 'auth_url' => $authUrl]);
    exit;
}

if ($action === 'youtube_status') {
    $row = marketing_get_user_youtube_token_row($db, $uid);
    $channelData = $row ? marketing_get_youtube_channel_summary($db, $uid) : null;
    echo json_encode([
        'success' => true,
        'connected' => !empty($row),
        'channel_id' => $row['channel_id'] ?? ($channelData['id'] ?? null),
        'channel_title' => $row['channel_title'] ?? ($channelData['title'] ?? null),
        'updated_at' => $row['updated_at'] ?? null,
        'has_refresh_token' => !empty($row['refresh_token'] ?? ''),
    ]);
    exit;
}

if ($action === 'youtube_disconnect') {
    $ok = marketing_disconnect_user_youtube($db, $uid);
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'publish_video') {
    $title = trim((string)($_POST['title'] ?? 'Lyralink marketing video'));
    $description = trim((string)($_POST['description'] ?? 'Generated by Lyralink automation.'));
    $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? 'ai,automation,marketing')))));

    if ($sourceUrl === '') {
        api_fail('source_url is required. The video must be a direct downloadable MP4 URL.');
    }

    $result = marketing_publish_video_to_youtube($db, $uid, $title, $description, $sourceUrl, $tags);

    $campaign = [
        'user_id' => $uid,
        'platform' => 'youtube',
        'title' => $title,
        'description' => $description,
        'source_url' => $sourceUrl,
        'status' => $result['ok'] ? 'published' : 'failed',
        'youtube_video_id' => $result['video_id'] ?? null,
        'youtube_url' => $result['youtube_url'] ?? null,
        'metrics_json' => json_encode(['detail' => $result['detail'] ?? '']) ?: null,
    ];

    $stmt = $db->prepare('INSERT INTO marketing_campaigns (user_id, platform, title, description, source_url, status, youtube_video_id, youtube_url, metrics_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        $s = (string)$campaign['status'];
        $titleStr = $campaign['title'];
        $descStr = $campaign['description'];
        $src = $campaign['source_url'];
        $videoId = $campaign['youtube_video_id'];
        $youtubeUrl = $campaign['youtube_url'];
        $metrics = $campaign['metrics_json'];
        $stmt->bind_param('issssssss', $uid, $campaign['platform'], $titleStr, $descStr, $src, $s, $videoId, $youtubeUrl, $metrics);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => $result['ok'], 'detail' => $result['detail'] ?? '', 'video_id' => $result['video_id'] ?? null, 'youtube_url' => $result['youtube_url'] ?? null]);
    exit;
}

if ($action === 'campaign_summary') {
    $rows = $db->query('SELECT id, platform, title, status, youtube_url, published_at, created_at FROM marketing_campaigns WHERE user_id = ' . (int)$uid . ' ORDER BY created_at DESC LIMIT 20');
    $campaigns = [];
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $campaigns[] = $row;
        }
    }

    echo json_encode(['success' => true, 'campaigns' => $campaigns]);
    exit;
}

api_fail('Unknown action', 404);
