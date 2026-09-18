<?php

require_once __DIR__ . '/security.php';

function marketing_ensure_schema(mysqli $db): void {
    $schema = [
        "CREATE TABLE IF NOT EXISTS marketing_youtube_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT DEFAULT NULL,
            expiry_at DATETIME DEFAULT NULL,
            channel_id VARCHAR(120) DEFAULT NULL,
            channel_title VARCHAR(220) DEFAULT NULL,
            scope TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_youtube (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_campaigns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(40) NOT NULL DEFAULT 'youtube',
            title VARCHAR(300) NOT NULL,
            description MEDIUMTEXT DEFAULT NULL,
            script MEDIUMTEXT DEFAULT NULL,
            status ENUM('queued','published','failed','archived') NOT NULL DEFAULT 'queued',
            source_url VARCHAR(500) DEFAULT NULL,
            youtube_video_id VARCHAR(120) DEFAULT NULL,
            youtube_url VARCHAR(500) DEFAULT NULL,
            metrics_json LONGTEXT DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_platform (user_id, platform),
            KEY idx_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_campaign_performance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(40) NOT NULL DEFAULT 'youtube',
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
            engagement_rate DECIMAL(10,4) DEFAULT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_campaign_recorded (campaign_id, recorded_at),
            CONSTRAINT fk_campaign_perf FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_render_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(64) NOT NULL,
            status ENUM('queued','processing','completed','failed','expired') NOT NULL DEFAULT 'queued',
            payload_json LONGTEXT NOT NULL,
            result_path VARCHAR(600) DEFAULT NULL,
            worker_name VARCHAR(140) DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            error_detail MEDIUMTEXT DEFAULT NULL,
            claimed_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_job_id (job_id),
            KEY idx_status_created (status, created_at),
            KEY idx_status_expires (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_audience_segments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            fit_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            evidence_json LONGTEXT DEFAULT NULL,
            status ENUM('active','monitoring','cold') NOT NULL DEFAULT 'monitoring',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_segment_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_opportunities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audience VARCHAR(200) NOT NULL,
            source VARCHAR(300) DEFAULT NULL,
            problem MEDIUMTEXT NOT NULL,
            evidence MEDIUMTEXT DEFAULT NULL,
            score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            priority VARCHAR(20) NOT NULL DEFAULT 'P3',
            status ENUM('open','monitoring','archived') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_priority_score (priority, score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_experiments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            hypothesis VARCHAR(500) NOT NULL,
            target_audience VARCHAR(200) NOT NULL,
            variable VARCHAR(200) NOT NULL,
            control_baseline VARCHAR(200) DEFAULT NULL,
            expected_outcome VARCHAR(500) DEFAULT NULL,
            status ENUM('draft','active','completed','failed') NOT NULL DEFAULT 'draft',
            result_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_claim_checks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            claim_text MEDIUMTEXT NOT NULL,
            source VARCHAR(300) DEFAULT NULL,
            evidence MEDIUMTEXT DEFAULT NULL,
            verified TINYINT(1) NOT NULL DEFAULT 0,
            supported TINYINT(1) NOT NULL DEFAULT 0,
            reason VARCHAR(300) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS marketing_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(80) NOT NULL,
            action VARCHAR(200) NOT NULL,
            account VARCHAR(200) DEFAULT NULL,
            reason VARCHAR(300) DEFAULT NULL,
            authorization_level TINYINT NOT NULL DEFAULT 0,
            result VARCHAR(80) NOT NULL DEFAULT 'blocked',
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_platform_created (platform, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($schema as $sql) {
        $db->query($sql);
    }
}

function marketing_authorization_level(string $action, array $context = []): array {
    $actionKey = strtolower(trim($action));
    $envKeyMap = [
        'discord' => 'MARKETING_ALLOW_DISCORD_POSTS',
        'reddit' => 'MARKETING_ALLOW_REDDIT_POSTS',
        'x' => 'MARKETING_ALLOW_X_POSTS',
        'publish' => 'MARKETING_ALLOW_PUBLIC_POSTS',
        'campaign' => 'MARKETING_ALLOW_CAMPAIGN_PUBLISHING',
        'youtube' => 'MARKETING_ALLOW_YOUTUBE_POSTS',
    ];

    $requestedKey = $envKeyMap[$actionKey] ?? 'MARKETING_ALLOW_PUBLIC_POSTS';
    $explicitValue = trim((string)($context[$requestedKey] ?? $context['authorization'] ?? $context['authorized'] ?? ''));
    $envValue = trim((string)api_get_secret($requestedKey, '0'));
    $sourceValue = $explicitValue !== '' ? $explicitValue : $envValue;
    $normalized = strtolower($sourceValue);

    $allow = in_array($normalized, ['1', 'true', 'yes', 'allow', 'authorized', 'approved'], true);
    $level = $allow ? 2 : 0;
    if ($actionKey === 'publish' || $actionKey === 'campaign' || $actionKey === 'reddit' || $actionKey === 'x' || $actionKey === 'youtube') {
        $level = $allow ? 3 : 0;
    }

    return [
        'allow' => $allow,
        'level' => $level,
        'key' => $requestedKey,
        'reason' => $allow ? 'explicit_authorization' : 'analysis_only_default',
    ];
}

function marketing_validate_claim(string $claim, array $context = []): array {
    $claimText = trim($claim);
    if ($claimText === '') {
        return ['supported' => false, 'reason' => 'empty_claim', 'source' => ''];
    }

    $evidence = trim((string)($context['evidence'] ?? ''));
    $source = trim((string)($context['source'] ?? ''));
    $verified = !empty($context['verified']);
    $hasMetricKeywords = preg_match('/\b(?:revenue|customers|users|retention|MRR|churn|benchmark|partnership|conversion|signups|impressions|roi)\b/i', $claimText) === 1;
    $hasNonNumericEvidence = $evidence !== '' && stripos($evidence, 'no ') === false && stripos($evidence, 'not available') === false && stripos($evidence, 'not verified') === false;

    if ($verified && ($source !== '' || $hasNonNumericEvidence)) {
        return ['supported' => true, 'reason' => 'verified_with_evidence', 'source' => $source];
    }

    if ($hasMetricKeywords && !$hasNonNumericEvidence) {
        return ['supported' => false, 'reason' => 'unsupported_or_unverified_metrics', 'source' => $source];
    }

    if ($source !== '' && $evidence !== '' && $verified) {
        return ['supported' => true, 'reason' => 'source-backed_claim', 'source' => $source];
    }

    return ['supported' => false, 'reason' => 'insufficient_evidence', 'source' => $source];
}

function marketing_score_opportunity(array $opportunity): array {
    $frequency = max(0, min(5, (int)($opportunity['frequency'] ?? 0)));
    $severity = max(0, min(5, (int)($opportunity['severity'] ?? 0)));
    $audienceFit = max(0, min(5, (int)($opportunity['fit'] ?? $opportunity['audience_fit'] ?? 0)));
    $confidence = max(0, min(5, (int)($opportunity['confidence'] ?? 0)));
    $conversion = max(0, min(5, (int)($opportunity['conversion'] ?? 0)));
    $lyralinkFit = max(0, min(5, (int)($opportunity['lyralink_fit'] ?? $opportunity['lyralink_relevance'] ?? 0)));

    $score = ($frequency * 1.5) + ($severity * 1.7) + ($audienceFit * 1.2) + ($confidence * 1.4) + ($conversion * 1.1) + ($lyralinkFit * 1.5);
    $score = round($score, 2);

    if ($score >= 30) {
        $priority = 'P0';
    } elseif ($score >= 22) {
        $priority = 'P1';
    } elseif ($score >= 14) {
        $priority = 'P2';
    } elseif ($score > 0) {
        $priority = 'P3';
    } else {
        $priority = 'IGNORE';
    }

    $recommendation = trim((string)($opportunity['recommended_next_action'] ?? 'Validate the problem with a small pilot, collect evidence, and test a focused message before expanding.'));

    return [
        'score' => $score,
        'priority' => $priority,
        'recommendation' => $recommendation,
        'audience' => trim((string)($opportunity['audience'] ?? 'general')),
        'problem' => trim((string)($opportunity['problem'] ?? '')),
    ];
}

function marketing_build_growth_report(array $input): array {
    $opportunities = [];
    foreach (array_values($input['opportunities'] ?? []) as $opportunity) {
        $opportunities[] = marketing_score_opportunity($opportunity);
    }
    usort($opportunities, static fn($a, $b) => $b['score'] <=> $a['score']);

    $experiments = array_values($input['experiments'] ?? []);
    $recommendations = [];
    foreach ($opportunities as $row) {
        if (($row['priority'] ?? 'IGNORE') === 'IGNORE') {
            continue;
        }
        $recommendations[] = [
            'priority' => $row['priority'],
            'audience' => $row['audience'],
            'recommendation' => $row['recommendation'],
        ];
    }
    if (empty($recommendations)) {
        $recommendations[] = ['priority' => 'P3', 'audience' => 'general', 'recommendation' => 'Collect more evidence before publishing; keep the marketing loop in analysis-only mode until the next validated opportunity is confirmed.'];
    }

    $segments = array_values($input['audience_segments'] ?? []);
    $topSegment = $segments[0] ?? 'AI developers';
    $executiveSummary = [
        'focus' => 'Find real problems, validate audience fit, and scale only after evidence is clear.',
        'top_segment' => $topSegment,
        'benchmark_status' => trim((string)($input['benchmark_status'] ?? 'Not yet validated')),
        'product_context' => trim((string)($input['product_context'] ?? 'Lyralink is operating as a real AI platform and growth system.')),
    ];

    return [
        'executive_summary' => $executiveSummary,
        'recommendations' => array_slice($recommendations, 0, 5),
        'opportunities' => $opportunities,
        'experiments' => $experiments,
        'audit' => [
            'generated_at' => gmdate('c'),
            'guardrails' => ['evidence_first', 'authorization_required', 'no_fabricated_metrics', 'no_spam'],
        ],
    ];
}

function marketing_parse_topic_list(string $raw, array $fallback = []): array {
    $topics = array_values(array_filter(array_map(
        static fn($v) => trim((string)$v),
        preg_split('/[,\n\r\t]+/', $raw) ?: []
    ), static fn($v) => $v !== ''));

    if (empty($topics)) {
        $topics = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $fallback), static fn($v) => $v !== ''));
    }

    $seen = [];
    $unique = [];
    foreach ($topics as $topic) {
        $key = strtolower($topic);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $topic;
    }

    return $unique;
}

function marketing_select_cycle_topics(array $topics, int $cycleSize = 6, string $seed = ''): array {
    if (empty($topics)) {
        return [];
    }

    $size = max(1, min($cycleSize, count($topics)));
    $seedBase = $seed !== '' ? $seed : gmdate('Y-m-d');
    $start = abs(crc32($seedBase)) % count($topics);

    $selected = [];
    for ($i = 0; $i < $size; $i++) {
        $idx = ($start + $i) % count($topics);
        $selected[] = (string)$topics[$idx];
    }

    return $selected;
}

function marketing_generate_opportunities_from_topics(array $topics, string $benchmarkStatus = ''): array {
    $out = [];
    foreach ($topics as $topic) {
        $t = strtolower(trim((string)$topic));
        if ($t === '') {
            continue;
        }

        $audience = 'AI developers';
        $problem = 'Teams report repetitive workflow pain around ' . $topic . ' and need reliable automation with human oversight.';
        $evidence = 'Observed recurring discussion around ' . $topic . ' across developer and operator communities.';
        $lyralinkFit = 4;
        $fit = 4;
        $frequency = 3;
        $severity = 3;
        $confidence = 3;
        $conversion = 2;

        if (str_contains($t, 'security') || str_contains($t, 'safety') || str_contains($t, 'hallucination')) {
            $audience = 'infrastructure engineers';
            $problem = 'Teams need trustworthy AI operations with bounded analysis, claim validation, and failure transparency for ' . $topic . '.';
            $evidence = 'Security and trust concerns repeatedly appear in AI reliability conversations involving ' . $topic . '.';
            $lyralinkFit = 5;
            $severity = 5;
            $confidence = 4;
            $conversion = 3;
        } elseif (str_contains($t, 'local ai') || str_contains($t, 'self-host') || str_contains($t, 'self host') || str_contains($t, 'infrastructure')) {
            $audience = 'startup founders';
            $problem = 'Operators want local model control and predictable cost while scaling ' . $topic . ' workflows.';
            $evidence = 'Local deployment and sovereignty requests are frequent in technical buyer discovery tied to ' . $topic . '.';
            $lyralinkFit = 5;
            $fit = 5;
            $severity = 4;
            $confidence = 4;
            $conversion = 3;
        } elseif (str_contains($t, 'automation') || str_contains($t, 'orchestration') || str_contains($t, 'workflow')) {
            $audience = 'DevOps engineers';
            $problem = 'Teams need durable orchestration for ' . $topic . ' without hidden execution failures.';
            $evidence = 'Repeated pain points mention brittle automations, missing observability, and weak failure recovery for ' . $topic . '.';
            $lyralinkFit = 5;
            $fit = 4;
            $frequency = 4;
            $severity = 4;
            $confidence = 4;
            $conversion = 3;
        }

        $recommendation = 'Run a focused discovery experiment around "' . $topic . '": publish a problem-first technical asset, collect qualified responses, and validate product fit before scaling.';
        if ($benchmarkStatus !== '') {
            $recommendation .= ' Include benchmark methodology and known limitations to preserve trust.';
        }

        $out[] = [
            'source' => 'topic_discovery',
            'audience' => $audience,
            'problem' => $problem,
            'evidence' => $evidence,
            'lyralink_fit' => $lyralinkFit,
            'fit' => $fit,
            'frequency' => $frequency,
            'severity' => $severity,
            'confidence' => $confidence,
            'conversion' => $conversion,
            'recommended_next_action' => $recommendation,
            'topic' => $topic,
        ];
    }

    return $out;
}

function marketing_generate_experiments_from_recommendations(array $recommendations): array {
    $experiments = [];
    foreach (array_slice($recommendations, 0, 5) as $item) {
        $audience = trim((string)($item['audience'] ?? 'general'));
        $rec = trim((string)($item['recommendation'] ?? ''));
        if ($rec === '') {
            continue;
        }

        $experiments[] = [
            'hypothesis' => 'Problem-first messaging for ' . $audience . ' will increase qualified product interest versus generic promotional posts.',
            'target_audience' => $audience,
            'variable' => 'content hook + technical depth + CTA',
            'control_baseline' => 'generic product-first messaging',
            'expected_outcome' => $rec,
            'status' => 'draft',
        ];
    }

    return $experiments;
}

function marketing_record_audit_event(mysqli $db, string $platform, string $action, string $result, array $payload = [], string $reason = '', int $authorization = 0, ?string $account = null): void {
    if (!$db instanceof mysqli) {
        return;
    }
    $stmt = $db->prepare('INSERT INTO marketing_audit_log (platform, action, account, reason, authorization_level, result, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $accountName = $account ?? '';
    $reasonText = $reason;
    $stmt->bind_param('ssssiss', $platform, $action, $accountName, $reasonText, $authorization, $result, $payloadJson);
    $stmt->execute();
    $stmt->close();
}

function marketing_admin_username(): string {
    return trim((string)api_get_secret('ADMIN_DEV_USERNAME', 'developer')) ?: 'developer';
}

function marketing_is_admin_session(): bool {
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    $username = trim((string)($_SESSION['username'] ?? ''));
    if ($username === '') {
        return false;
    }
    return hash_equals(marketing_admin_username(), $username);
}

function marketing_base_redirect_uri(): string {
    $base = trim((string)api_get_secret('APP_BASE_URL', 'https://lyralinkai.com'));
    $base = rtrim($base, '/');
    return $base . '/api/marketing/oauth/youtube/callback';
}

function marketing_google_oauth_url(): string {
    $clientId = trim((string)api_get_secret('YOUTUBE_CLIENT_ID', api_get_secret('GOOGLE_CLIENT_ID', '')));
    if ($clientId === '') {
        return '';
    }

    $redirect = marketing_base_redirect_uri();
    $state = bin2hex(random_bytes(16));
    $_SESSION['marketing_youtube_oauth_state'] = $state;

    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/userinfo.email',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function marketing_http_json(string $url, string $method = 'GET', array $payload = [], array $headers = [], ?string $authBearer = null): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
    }

    if ($authBearer !== null && $authBearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $authBearer;
    }
    if (!empty($headers)) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = $raw !== false ? json_decode((string)$raw, true) : null;
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return ['ok' => ($raw !== false && $httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode, 'error' => $err, 'body' => $decoded, 'raw' => $raw];
}

function marketing_exec_command(string $command): array {
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return ['ok' => $code === 0, 'code' => $code, 'output' => trim(implode("\n", $output))];
}

function marketing_delete_directory_tree(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($fullPath)) {
            marketing_delete_directory_tree($fullPath);
        } else {
            @unlink($fullPath);
        }
    }

    @rmdir($path);
}

function marketing_normalize_video_text(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return $text;
}

function marketing_wrap_video_text(string $text, int $width = 42): string {
    $text = marketing_normalize_video_text($text);
    if ($text === '') {
        return '';
    }
    return wordwrap($text, $width, "\n", true);
}

function marketing_split_video_script(string $script): array {
    $clean = marketing_normalize_video_text($script);
    if ($clean === '') {
        return ['hook' => '', 'body' => '', 'cta' => ''];
    }

    $sentences = preg_split('/(?<=[.!?])\s+/', $clean) ?: [$clean];
    $hook = trim((string)($sentences[0] ?? $clean));
    $body = trim(implode(' ', array_slice($sentences, 1, 2)));
    $cta = trim((string)($sentences[count($sentences) - 1] ?? ''));

    if ($body === '') {
        $body = trim(substr($clean, 0, 180));
    }
    if ($cta === '') {
        $cta = 'Watch, engage, and let the feedback loop guide the next campaign.';
    }

    return ['hook' => $hook, 'body' => $body, 'cta' => $cta];
}

function marketing_trim_video_words(string $text, int $maxWords): string {
    $text = marketing_normalize_video_text($text);
    if ($text === '') {
        return '';
    }

    $words = preg_split('/\s+/', $text) ?: [$text];
    if (count($words) <= $maxWords) {
        return $text;
    }

    return implode(' ', array_slice($words, 0, $maxWords));
}

function marketing_build_video_storyboard(string $title, string $description, string $script, array $tags = []): array {
    $copy = marketing_split_video_script($script);
    $titleLine = marketing_trim_video_words($title !== '' ? $title : $copy['hook'], 18);
    $hookLine = marketing_trim_video_words($copy['hook'] !== '' ? $copy['hook'] : $titleLine, 20);
    $bodyLine = marketing_trim_video_words($copy['body'] !== '' ? $copy['body'] : ($description !== '' ? $description : 'The loop keeps creating, posting, and learning.'), 34);
    $ctaLine = marketing_trim_video_words($copy['cta'] !== '' ? $copy['cta'] : 'Watch the feedback loop sharpen the next run.', 20);
    $descriptionLine = marketing_trim_video_words($description !== '' ? $description : $bodyLine, 40);
    $tagLine = trim('Signals: ' . implode(' · ', array_slice(array_filter(array_map('trim', $tags)), 0, 5)));
    $leadHook = marketing_trim_video_words('Stop scrolling.', 6);
    if (strcasecmp($leadHook, $hookLine) === 0) {
        $leadHook = '';
    }
    $captionSegments = [
        ['text' => $leadHook !== '' ? $leadHook : $hookLine, 'weight' => 0.8, 'emphasis' => 'hook'],
        ['text' => $hookLine, 'weight' => 1.1, 'emphasis' => 'hook'],
        ['text' => $bodyLine, 'weight' => 1.8, 'emphasis' => 'proof'],
        ['text' => $ctaLine, 'weight' => 1.0, 'emphasis' => 'cta'],
    ];
    $dedupedCaptionSegments = [];
    foreach ($captionSegments as $segment) {
        $last = $dedupedCaptionSegments[count($dedupedCaptionSegments) - 1] ?? null;
        if (is_array($last) && strcasecmp((string)($last['text'] ?? ''), (string)($segment['text'] ?? '')) === 0) {
            continue;
        }
        $dedupedCaptionSegments[] = $segment;
    }
    $narration = trim(implode(' ', array_filter([
        $leadHook,
        $hookLine,
        $bodyLine,
        $ctaLine,
    ])));

    return [
        'scene1' => [
            'eyebrow' => 'OPEN THE LOOP',
            'headline' => $titleLine,
            'body' => $hookLine,
            'footer' => 'Strategy, content, video, and feedback in one loop.',
            'scene_role' => 'hook',
            'prompt_focus' => 'A visually arresting opening frame that creates curiosity and urgency around the product story.',
            'caption' => trim(($leadHook !== '' ? $leadHook . ' ' : '') . $hookLine),
        ],
        'scene2' => [
            'eyebrow' => 'NARRATIVE MOTION',
            'headline' => marketing_trim_video_words('The system creates the story', 24),
            'body' => $bodyLine,
            'footer' => 'Six-hour cycles, constant iteration, real signal.',
            'scene_role' => 'proof',
            'prompt_focus' => 'Show the product in action with premium motion-design energy, tactical detail, and operator-grade confidence.',
            'caption' => $bodyLine,
        ],
        'scene3' => [
            'eyebrow' => 'READY TO PUBLISH',
            'headline' => marketing_trim_video_words($ctaLine, 18),
            'body' => trim($descriptionLine . "\n\n" . $tagLine),
            'footer' => 'Lyralink AI | Automated Marketing Department',
            'scene_role' => 'cta',
            'prompt_focus' => 'A polished payoff shot with brand confidence, authority, and a clear next step for the viewer.',
            'caption' => $ctaLine,
        ],
        'caption_segments' => $dedupedCaptionSegments,
        'music_mood' => trim((string)api_get_secret('RENDER_WORKER_MUSIC_MOOD', 'tech_cinematic')),
        'narration' => $narration,
    ];
}

function marketing_elevenlabs_find_voice(string $apiKey, ?string $excludeVoiceId = null): ?string {
    $ch = curl_init('https://api.elevenlabs.io/v1/voices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['voices']) || !is_array($decoded['voices'])) {
        return null;
    }

    $preferredCategories = ['cloned', 'generated', 'premade'];

    foreach ($preferredCategories as $category) {
        foreach ($decoded['voices'] as $voice) {
            if (!is_array($voice)) {
                continue;
            }
            $voiceId = (string)($voice['voice_id'] ?? '');
            $voiceCategory = strtolower((string)($voice['category'] ?? ''));
            if ($voiceId === '' || $voiceId === $excludeVoiceId) {
                continue;
            }
            if ($voiceCategory === 'library') {
                continue;
            }
            if ($voiceCategory === $category) {
                return $voiceId;
            }
        }
    }

    foreach ($decoded['voices'] as $voice) {
        if (!is_array($voice)) {
            continue;
        }
        $voiceId = (string)($voice['voice_id'] ?? '');
        $voiceCategory = strtolower((string)($voice['category'] ?? ''));
        if ($voiceId === '' || $voiceId === $excludeVoiceId || $voiceCategory === 'library') {
            continue;
        }
        return $voiceId;
    }

    return null;
}

function marketing_elevenlabs_tts_request(string $apiKey, string $voiceId, string $text): array {
    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128';
    $payload = json_encode([
        'text' => $text,
        'model_id' => 'eleven_flash_v2_5',
        'voice_settings' => [
            'stability' => 0.45,
            'similarity_boost' => 0.80,
            'style' => 0.25,
            'use_speaker_boost' => true,
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $audio = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => ($audio !== false && $httpCode === 200),
        'audio' => $audio !== false ? $audio : null,
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'response_preview' => is_string($audio) ? substr($audio, 0, 220) : null,
    ];
}

function marketing_render_narration_audio(string $text, string $outputPath): array {
    $text = marketing_normalize_video_text($text);
    if ($text === '') {
        return ['ok' => false, 'detail' => 'No narration text was available.'];
    }

    $elevenKey = api_get_secret('ELEVENLABS_API_KEY', '');
    $openAiKey = api_get_secret('OPENAI_API_KEY', '');

    if ($elevenKey !== '') {
        $voiceId = api_get_secret('ELEVENLABS_VOICE_ID', '');
        if ($voiceId === '') {
            $voiceId = marketing_elevenlabs_find_voice($elevenKey, null) ?? '21m00Tcm4TlvDq8ikWAM';
        }

        $try = marketing_elevenlabs_tts_request($elevenKey, $voiceId, $text);
        if ($try['ok']) {
            if (file_put_contents($outputPath, (string)$try['audio']) === false || !file_exists($outputPath) || filesize($outputPath) <= 0) {
                return ['ok' => false, 'detail' => 'ElevenLabs narration was returned but could not be written to disk.'];
            }

            return ['ok' => true, 'provider' => 'elevenlabs', 'voice_id' => $voiceId, 'file_path' => $outputPath, 'detail' => 'Narration generated with ElevenLabs.'];
        }

        $responsePreview = strtolower((string)($try['response_preview'] ?? ''));
        $isPaidLibraryVoice = ($try['http_code'] === 402)
            && (str_contains($responsePreview, 'paid_plan_required') || str_contains($responsePreview, 'library voices'));

        if ($isPaidLibraryVoice) {
            $fallbackVoiceId = marketing_elevenlabs_find_voice($elevenKey, $voiceId);
            if ($fallbackVoiceId !== null) {
                $fallbackTry = marketing_elevenlabs_tts_request($elevenKey, $fallbackVoiceId, $text);
                if ($fallbackTry['ok']) {
                    if (file_put_contents($outputPath, (string)$fallbackTry['audio']) === false || !file_exists($outputPath) || filesize($outputPath) <= 0) {
                        return ['ok' => false, 'detail' => 'ElevenLabs fallback narration was returned but could not be written to disk.'];
                    }

                    return ['ok' => true, 'provider' => 'elevenlabs', 'voice_id' => $fallbackVoiceId, 'file_path' => $outputPath, 'detail' => 'Narration generated with a non-library ElevenLabs voice.'];
                }
            }
        }
    }

    if ($openAiKey !== '') {
        $voice = api_get_secret('OPENAI_TTS_VOICE', 'nova');
        $payload = json_encode([
            'model' => 'tts-1',
            'input' => $text,
            'voice' => $voice,
        ]);

        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openAiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $audio = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($audio !== false && $httpCode === 200) {
            if (file_put_contents($outputPath, $audio) === false || !file_exists($outputPath) || filesize($outputPath) <= 0) {
                return ['ok' => false, 'detail' => 'OpenAI narration was returned but could not be written to disk.'];
            }

            return ['ok' => true, 'provider' => 'openai', 'voice_id' => $voice, 'file_path' => $outputPath, 'detail' => 'Narration generated with OpenAI TTS.'];
        }

        return ['ok' => false, 'detail' => 'OpenAI TTS failed: ' . ($curlError ?: 'HTTP ' . $httpCode)];
    }

    return ['ok' => false, 'detail' => 'No TTS provider configured for narration.'];
}

function marketing_probe_audio_duration(string $filePath): ?float {
    if (!is_file($filePath) || filesize($filePath) <= 0) {
        return null;
    }

    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath);
    $result = marketing_exec_command($cmd);
    if (!$result['ok']) {
        return null;
    }

    $duration = (float)trim((string)$result['output']);
    return $duration > 0 ? $duration : null;
}

function marketing_render_job_timeout_seconds(): int {
    $timeout = (int)api_get_secret('RENDER_WORKER_WAIT_TIMEOUT', '1500');
    return max(120, min($timeout, 10800));
}

function marketing_render_job_ttl_seconds(): int {
    $ttl = (int)api_get_secret('RENDER_WORKER_JOB_TTL', '7200');
    return max(300, min($ttl, 86400));
}

function marketing_render_result_dir(): string {
    $dir = dirname(__DIR__) . '/storage/render_jobs/results';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function marketing_expire_stale_render_jobs(mysqli $db): void {
    $db->query("UPDATE marketing_render_jobs SET status = 'expired', error_detail = CONCAT('Timed out at ', NOW()) WHERE status IN ('queued','processing') AND expires_at IS NOT NULL AND expires_at < NOW()");
}

function marketing_enqueue_render_job(mysqli $db, array $payload): array {
    $jobId = 'mrj_' . bin2hex(random_bytes(10));
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payloadJson) || $payloadJson === '') {
        return ['ok' => false, 'detail' => 'Could not encode render job payload.'];
    }

    $ttlSec = marketing_render_job_ttl_seconds();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSec);

    $stmt = $db->prepare('INSERT INTO marketing_render_jobs (job_id, status, payload_json, expires_at) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        return ['ok' => false, 'detail' => 'Could not prepare render job insert.'];
    }

    $status = 'queued';
    $stmt->bind_param('ssss', $jobId, $status, $payloadJson, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'detail' => 'Could not insert render job.'];
    }

    return ['ok' => true, 'job_id' => $jobId, 'expires_at' => $expiresAt];
}

function marketing_get_render_job(mysqli $db, string $jobId): ?array {
    $stmt = $db->prepare('SELECT * FROM marketing_render_jobs WHERE job_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function marketing_claim_render_job(mysqli $db, string $workerName): ?array {
    marketing_expire_stale_render_jobs($db);

    $safeWorker = trim($workerName);
    if ($safeWorker === '') {
        $safeWorker = 'worker-unknown';
    }
    $safeWorker = substr($safeWorker, 0, 140);

    for ($i = 0; $i < 3; $i++) {
        $row = $db->query("SELECT id, job_id, payload_json FROM marketing_render_jobs WHERE status = 'queued' AND (expires_at IS NULL OR expires_at >= NOW()) ORDER BY id ASC LIMIT 1")->fetch_assoc();
        if (!$row) {
            return null;
        }

        $id = (int)$row['id'];
        $upd = $db->prepare("UPDATE marketing_render_jobs SET status = 'processing', worker_name = ?, claimed_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status = 'queued'");
        if (!$upd) {
            return null;
        }
        $upd->bind_param('si', $safeWorker, $id);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        if ($affected === 1) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) {
                $payload = [];
            }
            return ['job_id' => (string)$row['job_id'], 'payload' => $payload];
        }
    }

    return null;
}

function marketing_store_render_job_result(mysqli $db, string $jobId, string $sourceFilePath, string $workerName = ''): array {
    $sourceFilePath = trim($sourceFilePath);
    if (!is_file($sourceFilePath) || filesize($sourceFilePath) <= 0) {
        return ['ok' => false, 'detail' => 'Render result file is missing or empty.'];
    }

    $dir = marketing_render_result_dir();
    $target = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) . '.mp4';

    if (!@rename($sourceFilePath, $target)) {
        if (!@copy($sourceFilePath, $target)) {
            return ['ok' => false, 'detail' => 'Could not move render result into storage.'];
        }
        @unlink($sourceFilePath);
    }

    $safeWorker = substr(trim($workerName), 0, 140);
    $stmt = $db->prepare("UPDATE marketing_render_jobs SET status = 'completed', worker_name = CASE WHEN ? = '' THEN worker_name ELSE ? END, result_path = ?, completed_at = NOW(), error_detail = NULL WHERE job_id = ? LIMIT 1");
    if (!$stmt) {
        error_log('[marketing_lib] prepare failed for completion update job_id=' . $jobId . ' error=' . $db->error);
        return ['ok' => false, 'detail' => 'Could not finalize render job result.'];
    }
    $stmt->bind_param('ssss', $safeWorker, $safeWorker, $target, $jobId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $errno = $stmt->errno;
    $error = $stmt->error;
    $stmt->close();

    error_log('[marketing_lib] completion_update job_id=' . $jobId . ' target=' . $target . ' ok=' . ($ok ? '1' : '0') . ' affected=' . (string)$affected . ' errno=' . (string)$errno . ' error=' . $error);

    if (!$ok) {
        return ['ok' => false, 'detail' => 'Failed to persist render result status.'];
    }

    if ($affected !== 1) {
        $row = marketing_get_render_job($db, $jobId);
        error_log('[marketing_lib] completion_update_no_effect job_id=' . $jobId . ' row=' . json_encode($row));
        return ['ok' => false, 'detail' => 'The job update affected zero rows; the target row may not exist or may already be stale.'];
    }

    return ['ok' => true, 'result_path' => $target];
}

function marketing_fail_render_job(mysqli $db, string $jobId, string $detail, string $workerName = ''): bool {
    $safeWorker = substr(trim($workerName), 0, 140);
    $detail = substr(trim($detail), 0, 8000);

    $stmt = $db->prepare("UPDATE marketing_render_jobs SET status = 'failed', worker_name = CASE WHEN ? = '' THEN worker_name ELSE ? END, completed_at = NOW(), error_detail = ? WHERE job_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssss', $safeWorker, $safeWorker, $detail, $jobId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function marketing_wait_for_render_job(mysqli $db, string $jobId, int $timeoutSeconds): array {
    $deadline = time() + max(60, $timeoutSeconds);

    while (time() < $deadline) {
        marketing_expire_stale_render_jobs($db);
        $row = marketing_get_render_job($db, $jobId);
        if (!$row) {
            return ['ok' => false, 'detail' => 'Render job disappeared before completion.'];
        }

        $status = (string)($row['status'] ?? '');
        if ($status === 'completed') {
            $path = trim((string)($row['result_path'] ?? ''));
            if ($path !== '' && is_file($path) && filesize($path) > 0) {
                return ['ok' => true, 'file_path' => $path, 'detail' => 'Remote GPU render finished successfully.'];
            }
            return ['ok' => false, 'detail' => 'Remote worker marked completed but no video file was found.'];
        }

        if ($status === 'failed' || $status === 'expired') {
            $detail = trim((string)($row['error_detail'] ?? 'Render worker failed the job.'));
            return ['ok' => false, 'detail' => $detail !== '' ? $detail : 'Render worker failed the job.'];
        }

        sleep(3);
    }

    return ['ok' => false, 'detail' => 'Timed out waiting for GPU render worker completion.'];
}

function marketing_generate_remote_video_asset(string $title, string $description, string $script, array $tags = []): array {
    $dbCfg = api_db_config(['host' => 'localhost', 'user' => 'app_user', 'pass' => '', 'name' => 'aicloud']);
    $db = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
    if ($db->connect_error) {
        return ['ok' => false, 'detail' => 'Could not connect to DB for GPU render queue.'];
    }
    $db->set_charset('utf8mb4');
    marketing_ensure_schema($db);

    $storyboard = marketing_build_video_storyboard($title, $description, $script, $tags);
    $payload = [
        'title' => $title,
        'description' => $description,
        'script' => $script,
        'tags' => array_values($tags),
        'storyboard' => $storyboard,
        'style_preset' => trim((string)api_get_secret('RENDER_WORKER_STYLE_PRESET', 'cinematic_marketing')),
        'style_variation' => api_get_secret('RENDER_WORKER_STYLE_VARIATION', '1') === '1',
        'enable_captions' => api_get_secret('RENDER_WORKER_ENABLE_CAPTIONS', '1') === '1',
        'enable_background_music' => api_get_secret('RENDER_WORKER_ENABLE_BACKGROUND_MUSIC', '1') === '1',
        'music_mood' => trim((string)api_get_secret('RENDER_WORKER_MUSIC_MOOD', (string)($storyboard['music_mood'] ?? 'tech_cinematic'))),
        'motion_provider' => trim((string)api_get_secret('RENDER_WORKER_MOTION_PROVIDER', 'auto')),
        'resolution' => trim((string)api_get_secret('RENDER_WORKER_RESOLUTION', '1280x720')),
        'fps' => (int)api_get_secret('RENDER_WORKER_FPS', '30'),
        'max_duration' => (int)api_get_secret('RENDER_WORKER_MAX_DURATION', '30'),
        'created_at' => gmdate('c'),
    ];

    $queued = marketing_enqueue_render_job($db, $payload);
    if (!$queued['ok']) {
        return ['ok' => false, 'detail' => (string)($queued['detail'] ?? 'Could not queue GPU render job.')];
    }

    $wait = marketing_wait_for_render_job($db, (string)$queued['job_id'], marketing_render_job_timeout_seconds());
    if (!$wait['ok']) {
        return ['ok' => false, 'detail' => (string)($wait['detail'] ?? 'GPU worker render failed.')];
    }

    return [
        'ok' => true,
        'file_path' => (string)$wait['file_path'],
        'work_dir' => dirname((string)$wait['file_path']),
        'detail' => (string)($wait['detail'] ?? 'Remote GPU render completed.'),
    ];
}

function marketing_generate_local_video_asset(string $title, string $description, string $script, array $tags = []): array {
    $provider = trim((string)api_get_secret('VIDEO_GEN_PROVIDER', 'local_ffmpeg'));
    if (in_array($provider, ['remote_worker', 'gpu_worker', 'remote_gpu'], true)) {
        return marketing_generate_remote_video_asset($title, $description, $script, $tags);
    }

    if ($provider !== '' && !in_array($provider, ['local_ffmpeg', 'local', 'ffmpeg'], true)) {
        return ['ok' => false, 'detail' => 'Local FFmpeg provider is disabled by VIDEO_GEN_PROVIDER=' . $provider];
    }

    if (trim($title) === '' && trim($script) === '') {
        return ['ok' => false, 'detail' => 'No title or script content was provided for local video generation.'];
    }

    $workDir = sys_get_temp_dir() . '/lyralink_marketing_video_' . bin2hex(random_bytes(6));
    if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        return ['ok' => false, 'detail' => 'Could not create a temp working directory for video generation.'];
    }

    $slide1 = $workDir . '/slide1.png';
    $slide2 = $workDir . '/slide2.png';
    $slide3 = $workDir . '/slide3.png';
    $audio = $workDir . '/narration.mp3';
    $output = $workDir . '/marketing_video.mp4';

    $storyboard = marketing_build_video_storyboard($title, $description, $script, $tags);
    $wrappedScene1Headline = marketing_wrap_video_text((string)$storyboard['scene1']['headline'], 28);
    $wrappedScene1Body = marketing_wrap_video_text((string)$storyboard['scene1']['body'], 44);
    $wrappedScene2Headline = marketing_wrap_video_text((string)$storyboard['scene2']['headline'], 28);
    $wrappedScene2Body = marketing_wrap_video_text((string)$storyboard['scene2']['body'], 44);
    $wrappedScene3Headline = marketing_wrap_video_text((string)$storyboard['scene3']['headline'], 28);
    $wrappedScene3Body = marketing_wrap_video_text((string)$storyboard['scene3']['body'], 44);

    $slideCommands = [
        marketing_render_branded_slide($slide1, 'gradient:#08111f-#111827', '#a78bfa', (string)$storyboard['scene1']['eyebrow'], $wrappedScene1Headline, $wrappedScene1Body, (string)$storyboard['scene1']['footer']),
        marketing_render_branded_slide($slide2, 'gradient:#111827-#0f172a', '#60a5fa', (string)$storyboard['scene2']['eyebrow'], $wrappedScene2Headline, $wrappedScene2Body, (string)$storyboard['scene2']['footer']),
        marketing_render_branded_slide($slide3, 'gradient:#0b1020-#1d1b31', '#34d399', (string)$storyboard['scene3']['eyebrow'], $wrappedScene3Headline, $wrappedScene3Body, (string)$storyboard['scene3']['footer']),
    ];

    foreach ($slideCommands as $command) {
        $result = is_string($command) ? marketing_exec_command($command) : $command;
        if (!$result['ok']) {
            return ['ok' => false, 'detail' => 'Slide render failed: ' . $result['output']];
        }
    }

    $narrationResult = marketing_render_narration_audio((string)$storyboard['narration'], $audio);
    if (!$narrationResult['ok']) {
        return ['ok' => false, 'detail' => 'Narration render failed: ' . ($narrationResult['detail'] ?? 'unknown error')];
    }

    $audioDuration = marketing_probe_audio_duration($audio) ?? 18.0;
    $sceneDurations = [
        max(5.5, min(8.5, round($audioDuration * 0.30, 2))),
        max(5.5, min(8.5, round($audioDuration * 0.40, 2))),
        max(5.5, min(8.5, round($audioDuration * 0.35, 2))),
    ];
    $totalVideoDuration = array_sum($sceneDurations);
    if ($totalVideoDuration < $audioDuration + 1.2) {
        $sceneDurations[2] += ($audioDuration + 1.2) - $totalVideoDuration;
    }

    $sceneFrameCounts = array_map(static function (float $duration): int {
        return (int)max(1, round($duration * 30));
    }, $sceneDurations);

    $filterComplex =
        '[0:v]scale=1280:720,zoompan=z=\'min(zoom+0.0015,1.11)\':d=' . $sceneFrameCounts[0] . ':s=1280x720:fps=30,format=yuv420p[v0];' .
        '[1:v]scale=1280:720,zoompan=z=\'min(zoom+0.0018,1.12)\':d=' . $sceneFrameCounts[1] . ':s=1280x720:fps=30,format=yuv420p[v1];' .
        '[2:v]scale=1280:720,zoompan=z=\'min(zoom+0.0014,1.10)\':d=' . $sceneFrameCounts[2] . ':s=1280x720:fps=30,format=yuv420p[v2];' .
        '[v0][v1][v2]concat=n=3:v=1:a=0[v]';

    $ffmpegCommand = sprintf(
        'ffmpeg -y -i %s -i %s -i %s -i %s -filter_complex %s -map %s -map %s -c:v libx264 -preset veryfast -crf 20 -c:a aac -b:a 128k -pix_fmt yuv420p -movflags +faststart -shortest %s',
        escapeshellarg($slide1),
        escapeshellarg($slide2),
        escapeshellarg($slide3),
        escapeshellarg($audio),
        escapeshellarg($filterComplex),
        escapeshellarg('[v]'),
        escapeshellarg('3:a'),
        escapeshellarg($output)
    );
    $renderResult = marketing_exec_command($ffmpegCommand);
    if (!$renderResult['ok'] || !file_exists($output) || filesize($output) <= 0) {
        return ['ok' => false, 'detail' => 'FFmpeg render failed: ' . ($renderResult['output'] ?: 'unknown error')];
    }

    return ['ok' => true, 'file_path' => $output, 'work_dir' => $workDir, 'detail' => 'Local FFmpeg video rendered successfully with narration.'];
}

function marketing_render_branded_slide(string $outputPath, string $background, string $accent, string $eyebrow, string $headline, string $body, string $footer): array {
    $headline = marketing_wrap_video_text($headline, 28);
    $body = marketing_wrap_video_text($body, 44);
    $footer = marketing_wrap_video_text($footer, 40);

    $cmd = sprintf(
        'convert -size 1280x720 %s -fill %s -draw %s -fill %s -font %s -pointsize 28 -gravity northwest -annotate +70+70 %s -fill %s -font %s -pointsize 58 -gravity northwest -annotate +70+160 %s -fill %s -font %s -pointsize 31 -gravity northwest -annotate +70+365 %s -fill %s -font %s -pointsize 24 -gravity northwest -annotate +70+638 %s %s',
        escapeshellarg($background),
        escapeshellarg($accent),
        escapeshellarg('rectangle 70,108 1210,114'),
        escapeshellarg($accent),
        escapeshellarg('DejaVu-Sans-Bold'),
        escapeshellarg($eyebrow),
        escapeshellarg('#ffffff'),
        escapeshellarg('DejaVu-Sans-Bold'),
        escapeshellarg($headline),
        escapeshellarg('#dbe4f0'),
        escapeshellarg('DejaVu-Sans'),
        escapeshellarg($body),
        escapeshellarg($accent),
        escapeshellarg('DejaVu-Sans-Bold'),
        escapeshellarg($footer),
        escapeshellarg($outputPath)
    );

    $result = marketing_exec_command($cmd);
    if (!$result['ok']) {
        return ['ok' => false, 'output' => $result['output']];
    }

    return ['ok' => true, 'output' => $outputPath, 'output_text' => $result['output']];
}

function marketing_generate_local_video_thumbnail(string $title, string $description, string $script, array $tags = []): array {
    $provider = trim((string)api_get_secret('VIDEO_GEN_PROVIDER', 'local_ffmpeg'));
    if ($provider !== '' && !in_array($provider, ['local_ffmpeg', 'local', 'ffmpeg'], true)) {
        return ['ok' => false, 'detail' => 'Thumbnail generation is disabled by VIDEO_GEN_PROVIDER=' . $provider];
    }

    $workDir = sys_get_temp_dir() . '/lyralink_marketing_thumb_' . bin2hex(random_bytes(6));
    if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        return ['ok' => false, 'detail' => 'Could not create a temp working directory for the thumbnail.'];
    }

    $thumb = $workDir . '/thumbnail.png';
    $copy = marketing_split_video_script($script);
    $headline = trim($title) !== '' ? $title : $copy['hook'];
    $body = trim($description) !== '' ? $description : $copy['body'];
    $footer = 'Lyralink AI Marketing Department';
    $tagLine = trim('Tags: ' . implode(', ', array_slice($tags, 0, 6)));

    $result = marketing_render_branded_slide(
        $thumb,
        'gradient:#050816-#111827',
        '#f59e0b',
        'YOUTUBE THUMBNAIL',
        marketing_wrap_video_text($headline, 24),
        marketing_wrap_video_text($body . "\n\n" . $tagLine, 38),
        $footer
    );

    if (!$result['ok'] || !file_exists($thumb) || filesize($thumb) <= 0) {
        return ['ok' => false, 'detail' => 'Thumbnail render failed: ' . ($result['output_text'] ?? $result['output'] ?? 'unknown error')];
    }

    return ['ok' => true, 'file_path' => $thumb, 'work_dir' => $workDir, 'detail' => 'Thumbnail generated successfully.'];
}

function marketing_resolve_video_source(string $sourceRef): ?string {
    $sourceRef = trim($sourceRef);
    if ($sourceRef === '') {
        return null;
    }

    if (str_starts_with($sourceRef, 'file://')) {
        $sourceRef = substr($sourceRef, 7);
    }

    if (is_file($sourceRef)) {
        return $sourceRef;
    }

    if (filter_var($sourceRef, FILTER_VALIDATE_URL)) {
        return marketing_download_remote_video($sourceRef);
    }

    return null;
}

function marketing_get_user_youtube_token_row(mysqli $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT * FROM marketing_youtube_tokens WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function marketing_store_youtube_tokens(mysqli $db, int $userId, array $tokenData, ?array $channelInfo = null): bool {
    $accessToken = trim((string)($tokenData['access_token'] ?? ''));
    $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
    $expiry = (int)($tokenData['expires_in'] ?? 3600);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $expiry));
    $channelId = trim((string)($channelInfo['id'] ?? ''));
    $channelTitle = trim((string)($channelInfo['title'] ?? ''));
    $scope = trim((string)($tokenData['scope'] ?? ''));

    $existing = marketing_get_user_youtube_token_row($db, $userId);
    if ($existing) {
        if ($refreshToken === '') {
            $refreshToken = (string)($existing['refresh_token'] ?? '');
        }
        $stmt = $db->prepare('UPDATE marketing_youtube_tokens SET access_token = ?, refresh_token = ?, expiry_at = ?, channel_id = ?, channel_title = ?, scope = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssssssi', $accessToken, $refreshToken, $expiresAt, $channelId, $channelTitle, $scope, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $db->prepare('INSERT INTO marketing_youtube_tokens (user_id, access_token, refresh_token, expiry_at, channel_id, channel_title, scope) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('issssss', $userId, $accessToken, $refreshToken, $expiresAt, $channelId, $channelTitle, $scope);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function marketing_disconnect_user_youtube(mysqli $db, int $userId): bool {
    $stmt = $db->prepare('DELETE FROM marketing_youtube_tokens WHERE user_id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function marketing_youtube_access_token(mysqli $db, int $userId): ?string {
    $row = marketing_get_user_youtube_token_row($db, $userId);
    if (!$row) {
        return null;
    }

    $refreshToken = trim((string)($row['refresh_token'] ?? ''));
    $expiryAt = (string)($row['expiry_at'] ?? '');
    $token = trim((string)($row['access_token'] ?? ''));

    if ($token !== '' && ($expiryAt === '' || strtotime($expiryAt) > time() + 60)) {
        return $token;
    }

    if ($refreshToken === '') {
        return null;
    }

    $clientId = trim((string)api_get_secret('YOUTUBE_CLIENT_ID', api_get_secret('GOOGLE_CLIENT_ID', '')));
    $clientSecret = trim((string)api_get_secret('YOUTUBE_CLIENT_SECRET', api_get_secret('GOOGLE_CLIENT_SECRET', '')));
    if ($clientId === '' || $clientSecret === '') {
        return $token !== '' ? $token : null;
    }

    $result = marketing_http_json(
        'https://oauth2.googleapis.com/token',
        'POST',
        [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ],
        ['Content-Type: application/x-www-form-urlencoded'],
    );

    if (!$result['ok']) {
        return $token !== '' ? $token : null;
    }

    $resp = $result['body'];
    $newToken = trim((string)($resp['access_token'] ?? ''));
    if ($newToken === '') {
        return $token !== '' ? $token : null;
    }

    $newExpiry = gmdate('Y-m-d H:i:s', time() + max(60, (int)($resp['expires_in'] ?? 3600)));
    $upd = $db->prepare('UPDATE marketing_youtube_tokens SET access_token = ?, expiry_at = ?, updated_at = NOW() WHERE user_id = ?');
    if ($upd) {
        $upd->bind_param('ssi', $newToken, $newExpiry, $userId);
        $upd->execute();
        $upd->close();
    }

    return $newToken;
}

function marketing_get_youtube_channel_summary(mysqli $db, int $userId): ?array {
    $token = marketing_youtube_access_token($db, $userId);
    if ($token === null || $token === '') {
        return null;
    }

    $res = marketing_http_json(
        'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true',
        'GET',
        [],
        ['Content-Type: application/json'],
        $token
    );

    if (!$res['ok']) {
        return null;
    }

    $items = $res['body']['items'] ?? [];
    if (empty($items)) {
        return null;
    }

    $item = $items[0];
    return [
        'id' => (string)($item['id'] ?? ''),
        'title' => (string)($item['snippet']['title'] ?? ''),
        'view_count' => (int)($item['statistics']['viewCount'] ?? 0),
        'subscriber_count' => (int)($item['statistics']['subscriberCount'] ?? 0),
    ];
}

function marketing_download_remote_video(string $sourceUrl): ?string {
    $tmpDir = sys_get_temp_dir();
    $target = $tmpDir . '/lyralink_marketing_' . bin2hex(random_bytes(6)) . '.mp4';
    $data = @file_get_contents($sourceUrl);
    if ($data === false || $data === '') {
        return null;
    }
    if (@file_put_contents($target, $data) === false) {
        return null;
    }
    if (!file_exists($target) || filesize($target) <= 0) {
        @unlink($target);
        return null;
    }
    return $target;
}

function marketing_publish_video_to_youtube(mysqli $db, int $userId, string $title, string $description, string $sourceUrl, array $tags = [], ?string $thumbnailPath = null): array {
    return marketing_publish_video_to_youtube_with_thumbnail($db, $userId, $title, $description, $sourceUrl, $tags, $thumbnailPath);
}

function marketing_upload_youtube_thumbnail(mysqli $db, int $userId, string $videoId, string $thumbnailPath): array {
    $token = marketing_youtube_access_token($db, $userId);
    if ($token === null || $token === '') {
        return ['ok' => false, 'detail' => 'No valid YouTube OAuth token found for thumbnail upload.'];
    }

    if (!is_file($thumbnailPath) || filesize($thumbnailPath) <= 0) {
        return ['ok' => false, 'detail' => 'Thumbnail file is missing or empty.'];
    }

    $mime = function_exists('mime_content_type') ? (string)(mime_content_type($thumbnailPath) ?: 'image/png') : 'image/png';
    $raw = file_get_contents($thumbnailPath);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'detail' => 'Could not read thumbnail file.'];
    }

    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=' . rawurlencode($videoId) . '&uploadType=media');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . $mime,
            'Content-Length: ' . strlen($raw),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'detail' => 'YouTube thumbnail upload failed http=' . $code . ' err=' . $err . ' body=' . substr((string)$resp, 0, 300)];
    }

    return ['ok' => true, 'detail' => 'Thumbnail uploaded successfully.'];
}

function marketing_publish_video_to_youtube_with_thumbnail(mysqli $db, int $userId, string $title, string $description, string $sourceUrl, array $tags = [], ?string $thumbnailPath = null): array {
    $token = marketing_youtube_access_token($db, $userId);
    if ($token === null || $token === '') {
        return ['ok' => false, 'detail' => 'No valid YouTube OAuth token found for this user. Connect a channel first.'];
    }

    $safeTitle = trim($title) !== '' ? substr($title, 0, 100) : 'Lyralink automation video';
    $safeDescription = substr(trim($description) !== '' ? $description : 'Generated by Lyralink marketing automation.', 0, 5000);
    $filePath = marketing_resolve_video_source($sourceUrl);
    if ($filePath === null) {
        return ['ok' => false, 'detail' => 'Could not resolve the source video file.'];
    }

    $metadata = [
        'snippet' => [
            'title' => $safeTitle,
            'description' => $safeDescription,
            'tags' => array_slice(array_values(array_filter(array_map('trim', $tags))), 0, 20),
            'categoryId' => '22',
        ],
        'status' => [
            'privacyStatus' => 'private',
            'selfDeclaredMadeForKids' => false,
        ],
    ];

    $rawMedia = file_get_contents($filePath);
    if ($rawMedia === false || $rawMedia === '') {
        return ['ok' => false, 'detail' => 'Could not read the rendered video file.'];
    }

    $boundary = '===============lyralink_' . bin2hex(random_bytes(12));
    $eol = "\r\n";
    $multipartBody = '';
    $multipartBody .= '--' . $boundary . $eol;
    $multipartBody .= 'Content-Type: application/json; charset=UTF-8' . $eol . $eol;
    $multipartBody .= json_encode($metadata, JSON_UNESCAPED_SLASHES) . $eol;
    $multipartBody .= '--' . $boundary . $eol;
    $multipartBody .= 'Content-Type: video/mp4' . $eol;
    $multipartBody .= 'Content-Transfer-Encoding: binary' . $eol . $eol;
    $multipartBody .= $rawMedia . $eol;
    $multipartBody .= '--' . $boundary . '--' . $eol;

    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?part=snippet,status&uploadType=multipart');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $multipartBody,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($multipartBody),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($filePath);
    $generatedPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lyralink_marketing_video_';
    $sourceDir = dirname($filePath);
    if (str_starts_with($sourceDir, $generatedPrefix)) {
        marketing_delete_directory_tree($sourceDir);
    }

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'detail' => 'YouTube upload failed http=' . $httpCode . ' err=' . $err . ' body=' . substr((string)$raw, 0, 300)];
    }

    $decoded = json_decode((string)$raw, true);
    $videoId = (string)($decoded['id'] ?? '');
    if ($videoId === '') {
        return ['ok' => false, 'detail' => 'YouTube accepted the request but did not return a video ID.'];
    }

    $thumbnailResult = null;
    if ($thumbnailPath !== null && is_file($thumbnailPath)) {
        $thumbnailResult = marketing_upload_youtube_thumbnail($db, $userId, $videoId, $thumbnailPath);
    }

    $detail = 'Video uploaded to YouTube successfully.';
    if (is_array($thumbnailResult) && !empty($thumbnailResult['ok'])) {
        $detail .= ' Thumbnail uploaded too.';
    }

    return ['ok' => true, 'video_id' => $videoId, 'youtube_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId), 'detail' => $detail, 'thumbnail' => $thumbnailResult];
}

function marketing_fetch_video_metrics_for_youtube(mysqli $db, int $userId, string $videoId): array {
    $token = marketing_youtube_access_token($db, $userId);
    if ($token === null || $token === '') {
        return ['views' => 0, 'likes' => 0, 'comments' => 0, 'engagement_rate' => 0.0];
    }

    $res = marketing_http_json(
        'https://www.googleapis.com/youtube/v3/videos?part=statistics&id=' . rawurlencode($videoId),
        'GET',
        [],
        ['Content-Type: application/json'],
        $token
    );

    if (!$res['ok']) {
        return ['views' => 0, 'likes' => 0, 'comments' => 0, 'engagement_rate' => 0.0];
    }

    $items = $res['body']['items'] ?? [];
    if (empty($items)) {
        return ['views' => 0, 'likes' => 0, 'comments' => 0, 'engagement_rate' => 0.0];
    }

    $stats = $items[0]['statistics'] ?? [];
    $views = (int)($stats['viewCount'] ?? 0);
    $likes = (int)($stats['likeCount'] ?? 0);
    $comments = (int)($stats['commentCount'] ?? 0);
    $engagement = $views > 0 ? round((($likes + $comments) / max(1, $views)) * 100, 4) : 0.0;

    return ['views' => $views, 'likes' => $likes, 'comments' => $comments, 'engagement_rate' => $engagement];
}

function marketing_latest_campaign_pattern(mysqli $db): array {
    $stmt = $db->prepare('SELECT * FROM marketing_campaigns WHERE status = "published" ORDER BY published_at DESC LIMIT 10');
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
