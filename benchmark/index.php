<?php
require_once __DIR__ . '/_access.php';
require_once __DIR__ . '/_storage.php';
benchmark_require_access();

$accessKey = trim((string)($_GET['k'] ?? ''));
$accessSuffix = $accessKey !== '' ? ('&k=' . rawurlencode($accessKey)) : '';

$storageRoot = benchmark_storage_root();
$manifestPath = benchmark_storage_path('benchmark_manifest.json');
$scoringSummaryPath = benchmark_storage_path('scoring/BENCHMARK_SUMMARY_V2.json');
$manifest = [
    'benchmark_name' => 'lyralink-blind-capability-benchmark',
    'generated_at' => null,
    'tasks' => [],
    'total_tasks' => 0,
];
if (is_readable($manifestPath)) {
    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (is_array($decoded)) {
        $manifest = array_merge($manifest, $decoded);
    }
}

$scoringSummary = [];
if (is_readable($scoringSummaryPath)) {
  $decodedSummary = json_decode((string)file_get_contents($scoringSummaryPath), true);
  if (is_array($decodedSummary)) {
    $scoringSummary = $decodedSummary;
  }
}

$records = [];
foreach ((array)($manifest['tasks'] ?? []) as $entry) {
    $taskFile = $storageRoot . '/' . ($entry['task_file'] ?? '');
    $lyraFile = $storageRoot . '/' . ($entry['lyralink_file'] ?? '');
    $externalFile = $storageRoot . '/' . ($entry['external_template'] ?? '');
    $scoringFile = $storageRoot . '/' . ($entry['scoring_file'] ?? '');

    $task = is_readable($taskFile) ? json_decode((string)file_get_contents($taskFile), true) : [];
    $lyra = is_readable($lyraFile) ? json_decode((string)file_get_contents($lyraFile), true) : [];
    $scoring = is_readable($scoringFile) ? json_decode((string)file_get_contents($scoringFile), true) : [];
    $externalPrompt = is_readable($externalFile) ? (string)file_get_contents($externalFile) : '';

    $records[] = [
        'task_id' => (string)($entry['task_id'] ?? ($task['task_id'] ?? 'unknown')),
        'category' => (string)($task['category'] ?? 'unknown'),
        'prompt' => (string)($task['prompt'] ?? ''),
        'prompt_hash' => (string)($task['prompt_hash'] ?? ($lyra['prompt_hash'] ?? '')),
        'lyralink_raw_output' => (string)($lyra['raw_output'] ?? ''),
        'lyralink_model' => (string)($lyra['model'] ?? 'unknown'),
        'lyralink_provider' => (string)($lyra['provider'] ?? 'unknown'),
        'latency_ms' => isset($lyra['latency_ms']) ? (int)$lyra['latency_ms'] : null,
        'timestamp' => (string)($lyra['timestamp'] ?? ''),
        'external_prompt_template' => $externalPrompt,
        'external_raw_output' => null,
        'objective_criteria' => (array)($scoring['objective_criteria'] ?? []),
        'lyralink_score' => $scoring['lyralink_score'] ?? null,
        'external_score' => $scoring['external_score'] ?? null,
        'winner' => $scoring['winner'] ?? null,
        'scoring_notes' => (string)($scoring['notes'] ?? ''),
        'files' => [
            'task_file' => (string)($entry['task_file'] ?? ''),
            'lyralink_file' => (string)($entry['lyralink_file'] ?? ''),
            'external_template' => (string)($entry['external_template'] ?? ''),
            'scoring_file' => (string)($entry['scoring_file'] ?? ''),
        ],
    ];
}

$summary = [
  'benchmark_name' => (string)($scoringSummary['benchmark_name'] ?? $manifest['benchmark_name'] ?? 'lyralink-blind-capability-benchmark'),
  'generated_at' => (string)($scoringSummary['generated_at'] ?? $manifest['generated_at'] ?? ''),
  'run_id' => (string)($scoringSummary['run_id'] ?? $manifest['run_id'] ?? ''),
  'run_status' => (string)($scoringSummary['run_status'] ?? $manifest['run_status'] ?? ''),
    'total_tasks' => (int)count($records),
    'lyralink_outputs_available' => count(array_filter($records, static fn(array $r): bool => trim($r['lyralink_raw_output']) !== '')),
  'external_outputs_available' => (int)($scoringSummary['external_outputs_available'] ?? 0),
    'scored_tasks' => count(array_filter($records, static fn(array $r): bool => $r['lyralink_score'] !== null || $r['external_score'] !== null)),
  'weighted_total' => isset($scoringSummary['weighted_total']) ? (float)$scoringSummary['weighted_total'] : null,
  'lyralink_average' => isset($scoringSummary['lyralink_average']) ? (float)$scoringSummary['lyralink_average'] : null,
  'critical_failures' => isset($scoringSummary['critical_failures']) ? (int)$scoringSummary['critical_failures'] : null,
  'category_breakdown' => is_array($scoringSummary['category_breakdown'] ?? null) ? $scoringSummary['category_breakdown'] : [],
    'records' => $records,
];

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($format === 'json') {
  header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LyraLink Benchmark Data Portal</title>
  <style>
    :root {
      --bg: #f7f4ef;
      --panel: #fffdf9;
      --ink: #1a2430;
      --muted: #5c6873;
      --line: #ddd2c1;
      --primary: #a5481f;
      --ok: #116149;
      --warn: #825000;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Cambria, Cochin, Georgia, Times, "Times New Roman", serif;
      color: var(--ink);
      background:
        linear-gradient(145deg, #f7f4ef 0%, #f4efe6 45%, #eef6f2 100%);
      min-height: 100vh;
    }
    .wrap {
      width: min(1180px, 94vw);
      margin: 30px auto 60px;
    }
    h1 {
      margin: 0;
      font-size: clamp(1.5rem, 2.4vw, 2.2rem);
    }
    .sub {
      margin: 8px 0 18px;
      color: var(--muted);
    }
    .meta {
      display: grid;
      grid-template-columns: repeat(4, minmax(140px, 1fr));
      gap: 10px;
      margin-bottom: 18px;
    }
    .chip {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 10px 12px;
      box-shadow: 0 3px 10px rgba(10, 10, 10, 0.04);
    }
    .chip .k { color: var(--muted); font-size: 0.85rem; }
    .chip .v { font-size: 1.1rem; font-weight: 700; margin-top: 2px; }

    .toolbar {
      display: grid;
      grid-template-columns: 1fr auto auto auto;
      gap: 10px;
      margin-bottom: 12px;
    }
    .search {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 1rem;
      background: #fff;
    }
    .btn {
      border: 1px solid var(--line);
      background: #fff;
      color: var(--ink);
      border-radius: 10px;
      padding: 10px 12px;
      cursor: pointer;
      text-decoration: none;
      font-size: 0.95rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn.primary {
      border-color: var(--primary);
      background: var(--primary);
      color: #fff;
    }
    .btn:hover { border-color: #b7ab98; }

    .status {
      margin: 8px 0 14px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .table-wrap {
      overflow: auto;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: var(--panel);
      margin-bottom: 14px;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      min-width: 980px;
    }
    th, td {
      padding: 10px 10px;
      border-bottom: 1px solid #ece4d7;
      vertical-align: top;
      text-align: left;
      font-size: 0.92rem;
    }
    th {
      position: sticky;
      top: 0;
      background: #f9f6ef;
      z-index: 1;
      font-size: 0.85rem;
      letter-spacing: 0.2px;
      color: #3f4b56;
    }
    .mono { font-family: "Courier New", Courier, monospace; font-size: 0.86rem; }
    .ok { color: var(--ok); font-weight: 700; }
    .warn { color: var(--warn); font-weight: 700; }
    .small { color: var(--muted); font-size: 0.84rem; }

    .cards { display: grid; gap: 10px; }
    .card {
      border: 1px solid var(--line);
      border-radius: 12px;
      background: var(--panel);
      padding: 12px;
    }
    .head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 8px;
    }
    .title { font-weight: 700; }
    details { margin-top: 8px; }
    summary { cursor: pointer; color: #0f6a63; font-weight: 600; }
    pre {
      white-space: pre-wrap;
      word-break: break-word;
      border: 1px solid #e7decf;
      border-radius: 10px;
      background: #fff;
      padding: 10px;
      margin-top: 8px;
      max-height: 320px;
      overflow: auto;
      font-size: 0.9rem;
      line-height: 1.45;
    }
    .footer {
      margin-top: 16px;
      color: var(--muted);
      font-size: 0.9rem;
      border-top: 1px dashed var(--line);
      padding-top: 12px;
    }

    @media (max-width: 900px) {
      .meta { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
      .toolbar { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="wrap">
    <h1>LyraLink Benchmark Data Portal</h1>
    <p class="sub">Searchable, exportable benchmark data: tasks, raw LyraLink outputs, scoring sheets, and file links.</p>

    <section class="meta">
      <div class="chip"><div class="k">Total Tasks</div><div class="v"><?= (int)$summary['total_tasks'] ?></div></div>
      <div class="chip"><div class="k">LyraLink Outputs</div><div class="v"><?= (int)$summary['lyralink_outputs_available'] ?></div></div>
      <div class="chip"><div class="k">External Outputs</div><div class="v"><?= (int)$summary['external_outputs_available'] ?></div></div>
      <div class="chip"><div class="k">Scored Tasks</div><div class="v"><?= (int)$summary['scored_tasks'] ?></div></div>
      <div class="chip"><div class="k">Weighted Total</div><div class="v"><?= $summary['weighted_total'] === null ? 'n/a' : htmlspecialchars((string)$summary['weighted_total'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div></div>
      <div class="chip"><div class="k">Critical Failures</div><div class="v"><?= $summary['critical_failures'] === null ? 'n/a' : (int)$summary['critical_failures'] ?></div></div>
      <div class="chip"><div class="k">Run Status</div><div class="v"><?= htmlspecialchars((string)($summary['run_status'] ?: 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div></div>
      <div class="chip"><div class="k">Run ID</div><div class="v mono"><?= htmlspecialchars((string)($summary['run_id'] ?: 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div></div>
    </section>

    <?php if (!empty($summary['category_breakdown']) && is_array($summary['category_breakdown'])): ?>
    <section class="table-wrap" style="margin-bottom:12px;">
      <table>
        <thead>
          <tr>
            <th>Category</th>
            <th>Weight</th>
            <th>LyraLink Avg</th>
            <th>External Avg</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['category_breakdown'] as $categoryName => $categoryData):
            $catWeight = isset($categoryData['weight']) ? (string)$categoryData['weight'] : 'n/a';
            $catLyra = isset($categoryData['lyralink_avg']) && $categoryData['lyralink_avg'] !== null ? (string)$categoryData['lyralink_avg'] : 'n/a';
            $catExternal = isset($categoryData['external_avg']) && $categoryData['external_avg'] !== null ? (string)$categoryData['external_avg'] : 'n/a';
          ?>
          <tr>
            <td><?= htmlspecialchars((string)$categoryName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($catWeight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($catLyra, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($catExternal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>

    <section class="toolbar">
      <input id="search" class="search" type="search" placeholder="Search task ID, category, prompt text, model, notes...">
      <a class="btn" href="./external/?k=<?= rawurlencode($accessKey) ?>">External Prompt Browser</a>
      <a class="btn" href="/api/public_api.php?action=benchmark_overview" target="_blank" rel="noopener">Open Public API</a>
      <a class="btn primary" href="?format=json<?= $accessKey !== '' ? '&k=' . rawurlencode($accessKey) : '' ?>" target="_blank" rel="noopener">Open Full JSON Export</a>
    </section>

    <p id="status" class="status"></p>

    <section class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Task</th>
            <th>Category</th>
            <th>Model</th>
            <th>Latency</th>
            <th>Scores</th>
            <th>Files</th>
          </tr>
        </thead>
        <tbody id="rows">
          <?php foreach ($records as $record):
            $taskId = htmlspecialchars($record['task_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $category = htmlspecialchars($record['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $model = htmlspecialchars($record['lyralink_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $latency = $record['latency_ms'] === null ? 'n/a' : ((string)$record['latency_ms'] . ' ms');
            $lyraScore = $record['lyralink_score'] === null ? 'n/a' : (string)$record['lyralink_score'];
            $extScore = $record['external_score'] === null ? 'n/a' : (string)$record['external_score'];
            $winner = $record['winner'] === null ? 'unscored' : (string)$record['winner'];

            $searchBlob = strtolower(
                $record['task_id'] . ' ' .
                $record['category'] . ' ' .
                $record['prompt'] . ' ' .
                $record['lyralink_raw_output'] . ' ' .
                $record['lyralink_model'] . ' ' .
                $record['scoring_notes']
            );
          ?>
          <tr class="row" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <td>
              <div><strong><?= $taskId ?></strong></div>
              <div class="small">hash: <span class="mono"><?= htmlspecialchars(substr($record['prompt_hash'], 0, 12), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
            </td>
            <td><?= $category ?></td>
            <td class="mono"><?= $model ?></td>
            <td><?= htmlspecialchars($latency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <div>LyraLink: <?= htmlspecialchars($lyraScore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div>External: <?= htmlspecialchars($extScore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="<?= $winner === 'unscored' ? 'warn' : 'ok' ?>"><?= htmlspecialchars($winner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </td>
            <td>
              <div><a href="file.php?path=<?= rawurlencode($record['files']['task_file']) ?><?= $accessSuffix ?>" target="_blank" rel="noopener">task</a></div>
              <div><a href="file.php?path=<?= rawurlencode($record['files']['lyralink_file']) ?><?= $accessSuffix ?>" target="_blank" rel="noopener">lyralink</a></div>
              <div><a href="file.php?path=<?= rawurlencode($record['files']['external_template']) ?><?= $accessSuffix ?>" target="_blank" rel="noopener">external</a></div>
              <div><a href="file.php?path=<?= rawurlencode($record['files']['scoring_file']) ?><?= $accessSuffix ?>" target="_blank" rel="noopener">scoring</a></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section id="cards" class="cards">
      <?php foreach ($records as $record):
        $taskId = htmlspecialchars($record['task_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $category = htmlspecialchars($record['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $prompt = htmlspecialchars($record['prompt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $raw = htmlspecialchars($record['lyralink_raw_output'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $notes = htmlspecialchars($record['scoring_notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $criteria = htmlspecialchars(implode("\n", (array)$record['objective_criteria']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $searchBlob = strtolower(
            $record['task_id'] . ' ' .
            $record['category'] . ' ' .
            $record['prompt'] . ' ' .
            $record['lyralink_raw_output'] . ' ' .
            $record['lyralink_model'] . ' ' .
            $record['scoring_notes']
        );
      ?>
      <article class="card row" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <header class="head">
          <div class="title"><?= $taskId ?> - <?= $category ?></div>
          <div class="small mono"><?= htmlspecialchars($record['lyralink_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | <?= htmlspecialchars((string)$record['latency_ms'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> ms</div>
        </header>

        <details>
          <summary>Exact prompt</summary>
          <pre><?= $prompt ?></pre>
        </details>

        <details>
          <summary>LyraLink raw output</summary>
          <pre><?= $raw ?></pre>
        </details>

        <details>
          <summary>Scoring data</summary>
          <pre>LyraLink score: <?= htmlspecialchars((string)($record['lyralink_score'] ?? 'null'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
External score: <?= htmlspecialchars((string)($record['external_score'] ?? 'null'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
Winner: <?= htmlspecialchars((string)($record['winner'] ?? 'null'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

Objective criteria:
<?= $criteria ?>

Notes:
<?= $notes ?></pre>
        </details>
      </article>
      <?php endforeach; ?>
    </section>

    <p class="footer">
      Generated at: <?= htmlspecialchars((string)$summary['generated_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      <br>
      For machine-readable ingestion (ChatGPT or scripts), use <a href="/api/public_api.php?action=benchmark_overview" target="_blank" rel="noopener">/api/public_api.php?action=benchmark_overview</a>.
    </p>
  </main>

  <script>
    (function () {
      const search = document.getElementById('search');
      const rows = Array.from(document.querySelectorAll('.row'));
      const status = document.getElementById('status');

      function update() {
        const q = (search.value || '').toLowerCase().trim();
        let shown = 0;
        rows.forEach((row) => {
          const hay = (row.getAttribute('data-search') || '').toLowerCase();
          const match = !q || hay.indexOf(q) !== -1;
          row.style.display = match ? '' : 'none';
          if (match) shown += 1;
        });
        status.textContent = 'Visible entries: ' + shown;
      }

      search.addEventListener('input', update);
      update();
    })();
  </script>
</body>
</html>
