<?php
require_once __DIR__ . '/_access.php';
require_once __DIR__ . '/_storage.php';
benchmark_require_access();

function bench_api_json(string $relative): array {
    $path = benchmark_storage_path($relative);
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function bench_api_text(string $relative): ?string {
    $path = benchmark_storage_path($relative);
    if (!is_readable($path)) {
        return null;
    }
    $value = (string)file_get_contents($path);
    return $value;
}

function bench_api_clamp_int($value, int $min, int $max, int $default): int {
    $n = filter_var($value, FILTER_VALIDATE_INT);
    if ($n === false) {
        return $default;
    }
    if ($n < $min) {
        return $min;
    }
    if ($n > $max) {
        return $max;
    }
    return $n;
}

function bench_api_bool($value, bool $default = false): bool {
    if ($value === null || $value === '') {
        return $default;
    }
    $raw = strtolower(trim((string)$value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function bench_api_artifact_link(string $path): string {
    return './file.php?path=' . rawurlencode($path);
}

function bench_api_task_record(array $entry, bool $includeArtifacts = false): array {
    $taskId = (string)($entry['task_id'] ?? '');
    $taskFile = (string)($entry['task_file'] ?? ('tasks/' . $taskId . '.json'));
    $lyraFile = (string)($entry['lyralink_file'] ?? ('lyralink/' . $taskId . '.json'));
    $scoringFile = (string)($entry['scoring_file'] ?? ('scoring/' . $taskId . '.json'));
    $externalTemplate = (string)($entry['external_template'] ?? ('external/' . $taskId . '.txt'));

    $task = bench_api_json($taskFile);
    $lyra = bench_api_json($lyraFile);
    $scoring = bench_api_json($scoringFile);

    $record = [
        'task_id' => $taskId,
        'category' => (string)($task['category'] ?? ''),
        'prompt_hash' => (string)($task['prompt_hash'] ?? ($entry['prompt_hash'] ?? '')),
        'run_status' => (string)($entry['run_status'] ?? ''),
        'output_status' => (string)($entry['output_status'] ?? ''),
        'score_status' => (string)($entry['score_status'] ?? ''),
        'failure_class' => (string)($entry['failure_class'] ?? ''),
        'latency_ms' => isset($entry['latency_ms']) ? (int)$entry['latency_ms'] : null,
        'lyralink_score' => $scoring['lyralink_score'] ?? null,
        'external_score' => $scoring['external_score'] ?? null,
        'winner' => $scoring['winner'] ?? null,
        'links' => [
            'task' => bench_api_artifact_link($taskFile),
            'lyralink' => bench_api_artifact_link($lyraFile),
            'scoring' => bench_api_artifact_link($scoringFile),
            'external_template' => bench_api_artifact_link($externalTemplate),
        ],
    ];

    if ($includeArtifacts) {
        $record['artifacts'] = [
            'task' => $task,
            'lyralink' => $lyra,
            'scoring' => $scoring,
            'external_template' => bench_api_text($externalTemplate),
        ];
    }

    return $record;
}

$manifest = bench_api_json('benchmark_manifest.json');
$summary = bench_api_json('scoring/BENCHMARK_SUMMARY_V2.json');
$tasks = is_array($manifest['tasks'] ?? null) ? $manifest['tasks'] : [];

$action = strtolower(trim((string)($_GET['action'] ?? 'overview')));
$limit = bench_api_clamp_int($_GET['limit'] ?? null, 1, 100, 25);
$offset = bench_api_clamp_int($_GET['offset'] ?? null, 0, 1000000, 0);
$includeArtifacts = bench_api_bool($_GET['include_artifacts'] ?? null, false);

$response = [
    'ok' => true,
    'action' => $action,
    'generated_at' => gmdate('c'),
    'benchmark' => [
        'name' => (string)($summary['benchmark_name'] ?? $manifest['benchmark_name'] ?? 'lyralink-blind-capability-benchmark-v2'),
        'run_id' => (string)($summary['run_id'] ?? $manifest['run_id'] ?? ''),
        'run_status' => (string)($summary['run_status'] ?? $manifest['run_status'] ?? ''),
        'benchmark_version' => (string)($summary['benchmark_version'] ?? $manifest['benchmark_version'] ?? ''),
        'generated_at' => (string)($summary['generated_at'] ?? $manifest['generated_at'] ?? ''),
        'updated_at' => (string)($manifest['updated_at'] ?? ''),
        'total_tasks' => count($tasks),
        'scored_tasks' => (int)($summary['lyralink_scored'] ?? $manifest['scored_tasks'] ?? 0),
        'external_outputs_available' => (int)($summary['external_outputs_available'] ?? $manifest['external_outputs_available'] ?? 0),
        'weighted_total' => $summary['weighted_total'] ?? null,
        'lyralink_average' => $summary['lyralink_average'] ?? null,
        'critical_failures' => $summary['critical_failures'] ?? null,
        'category_breakdown' => is_array($summary['category_breakdown'] ?? null) ? $summary['category_breakdown'] : [],
    ],
];

if ($action === 'overview') {
    $response['api'] = [
        'overview' => '?action=overview',
        'tasks' => '?action=tasks&limit=25&offset=0',
        'task' => '?action=task&task_id=T001&include_artifacts=1',
        'all' => '?action=all&limit=10&offset=0&include_artifacts=1',
    ];
} elseif ($action === 'tasks') {
    $slice = array_slice($tasks, $offset, $limit);
    $items = [];
    foreach ($slice as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $items[] = bench_api_task_record($entry, false);
    }
    $response['paging'] = [
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($items),
        'total' => count($tasks),
        'next_offset' => ($offset + $limit) < count($tasks) ? ($offset + $limit) : null,
    ];
    $response['tasks'] = $items;
} elseif ($action === 'task') {
    $taskId = strtoupper(trim((string)($_GET['task_id'] ?? '')));
    if ($taskId === '') {
        http_response_code(400);
        $response['ok'] = false;
        $response['error'] = 'missing_task_id';
    } else {
        $found = null;
        foreach ($tasks as $entry) {
            if (is_array($entry) && strtoupper((string)($entry['task_id'] ?? '')) === $taskId) {
                $found = $entry;
                break;
            }
        }
        if ($found === null) {
            http_response_code(404);
            $response['ok'] = false;
            $response['error'] = 'task_not_found';
        } else {
            $response['task'] = bench_api_task_record($found, true);
        }
    }
} elseif ($action === 'all') {
    $slice = array_slice($tasks, $offset, $limit);
    $items = [];
    foreach ($slice as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $items[] = bench_api_task_record($entry, $includeArtifacts);
    }
    $response['paging'] = [
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($items),
        'total' => count($tasks),
        'next_offset' => ($offset + $limit) < count($tasks) ? ($offset + $limit) : null,
    ];
    $response['tasks'] = $items;
} else {
    http_response_code(400);
    $response['ok'] = false;
    $response['error'] = 'unknown_action';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
