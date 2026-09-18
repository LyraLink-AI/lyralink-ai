<?php
require_once __DIR__ . '/../_access.php';
require_once __DIR__ . '/../_storage.php';
benchmark_require_access();

$accessKey = trim((string)($_GET['k'] ?? ''));
$accessSuffix = $accessKey !== '' ? ('&k=' . rawurlencode($accessKey)) : '';

$files = glob(benchmark_storage_path('external/T*.txt')) ?: [];
natsort($files);
$records = [];
foreach ($files as $path) {
    $name = basename($path);
    $taskId = pathinfo($name, PATHINFO_FILENAME);
    $content = trim((string)@file_get_contents($path));
    $records[] = [
        'task_id' => $taskId,
        'file' => $name,
        'content' => $content,
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LyraLink Benchmark External Prompts</title>
  <style>
    :root {
      --bg: #f4f1ea;
      --card: #fffdfa;
      --ink: #17202a;
      --muted: #5e6a74;
      --line: #d8cfc2;
      --accent: #b84a1b;
      --accent-2: #0f766e;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      color: var(--ink);
      background:
        radial-gradient(circle at 20% 10%, #f8efe0 0, #f4f1ea 45%),
        radial-gradient(circle at 80% 100%, #e3efe9 0, #f4f1ea 40%);
      min-height: 100vh;
    }
    .wrap {
      width: min(1080px, 92vw);
      margin: 32px auto 48px;
    }
    h1 {
      margin: 0 0 8px;
      font-size: clamp(1.5rem, 2.3vw, 2.1rem);
      line-height: 1.2;
    }
    .sub {
      margin: 0 0 22px;
      color: var(--muted);
      font-size: 1rem;
    }
    .tools {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      margin-bottom: 18px;
    }
    .search {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 1rem;
      background: #fff;
      color: var(--ink);
    }
    .btn {
      border: 1px solid var(--line);
      background: #fff;
      color: var(--ink);
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 0.95rem;
      cursor: pointer;
    }
    .btn:hover { border-color: #b8aa95; }
    .btn.primary {
      border-color: var(--accent);
      color: #fff;
      background: var(--accent);
    }
    .count {
      color: var(--muted);
      margin: 10px 0 14px;
      font-size: 0.95rem;
    }
    .grid {
      display: grid;
      gap: 12px;
    }
    .card {
      border: 1px solid var(--line);
      border-radius: 14px;
      background: var(--card);
      padding: 14px;
      box-shadow: 0 4px 16px rgba(20, 20, 20, 0.04);
    }
    .head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 8px;
    }
    .task {
      font-weight: 700;
      font-size: 1rem;
      letter-spacing: 0.2px;
    }
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .preview {
      margin: 0;
      color: var(--ink);
      white-space: pre-wrap;
      line-height: 1.45;
      max-height: 9.5em;
      overflow: hidden;
      position: relative;
    }
    .preview::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 2.2em;
      background: linear-gradient(to bottom, rgba(255,253,250,0), rgba(255,253,250,1));
      pointer-events: none;
    }
    details { margin-top: 10px; }
    summary {
      cursor: pointer;
      color: var(--accent-2);
      font-weight: 600;
      user-select: none;
    }
    pre {
      white-space: pre-wrap;
      word-break: break-word;
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px;
      margin-top: 8px;
      font-size: 0.93rem;
      line-height: 1.45;
    }
    .note {
      margin-top: 20px;
      padding: 10px 12px;
      border: 1px dashed var(--line);
      border-radius: 10px;
      color: var(--muted);
      background: #fffcf7;
      font-size: 0.92rem;
    }
    .hidden { display: none; }
    @media (max-width: 760px) {
      .tools { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="wrap">
    <h1>LyraLink Benchmark External Prompts</h1>
    <p class="sub">Open, search, and copy exact task prompts for external model testing.</p>

    <section class="tools">
      <input id="search" class="search" type="search" placeholder="Search by task ID or prompt text (example: deadlock, architecture, T14)">
      <button id="copyAll" class="btn primary" type="button">Copy All Prompts</button>
    </section>

    <p id="count" class="count"></p>

    <section id="cards" class="grid">
      <?php foreach ($records as $r):
          $safeContent = htmlspecialchars($r['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $flat = preg_replace('/\s+/', ' ', trim($r['content']));
            $preview = substr($flat, 0, 280);
            if (strlen($flat) > 280) {
              $preview .= '...';
          }
          $safePreview = htmlspecialchars($preview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      ?>
        <article class="card" data-file="<?= htmlspecialchars($r['file'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-task="<?= htmlspecialchars($r['task_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-content="<?= $safeContent ?>">
          <header class="head">
            <div class="task"><?= htmlspecialchars($r['task_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> - <?= htmlspecialchars($r['file'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="actions">
              <a class="btn" href="../file.php?path=external/<?= rawurlencode($r['file']) ?><?= $accessSuffix ?>" target="_blank" rel="noopener">Open Raw</a>
              <button class="btn copy-one" type="button">Copy Prompt</button>
            </div>
          </header>
          <p class="preview"><?= $safePreview ?></p>
          <details>
            <summary>Show full prompt</summary>
            <pre><?= $safeContent ?></pre>
          </details>
        </article>
      <?php endforeach; ?>
    </section>

    <p class="note">Tip: You can send each raw prompt as-is to ChatGPT, then save each response into matching files under benchmark/external_results for blind scoring.</p>
  </main>

  <script>
    (function () {
      const cards = Array.from(document.querySelectorAll('.card'));
      const searchInput = document.getElementById('search');
      const count = document.getElementById('count');
      const copyAllBtn = document.getElementById('copyAll');

      function visibleCards() {
        return cards.filter((c) => !c.classList.contains('hidden'));
      }

      function renderCount() {
        const visible = visibleCards().length;
        count.textContent = 'Showing ' + visible + ' of ' + cards.length + ' prompts';
      }

      function applyFilter() {
        const q = (searchInput.value || '').toLowerCase().trim();
        cards.forEach((card) => {
          const hay = (card.dataset.task + ' ' + card.dataset.file + ' ' + card.dataset.content).toLowerCase();
          const match = q === '' || hay.includes(q);
          card.classList.toggle('hidden', !match);
        });
        renderCount();
      }

      async function copyText(text) {
        try {
          await navigator.clipboard.writeText(text);
          return true;
        } catch (_) {
          const ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          ta.remove();
          return true;
        }
      }

      document.querySelectorAll('.copy-one').forEach((btn) => {
        btn.addEventListener('click', async function () {
          const card = this.closest('.card');
          const prompt = card ? card.dataset.content : '';
          const ok = await copyText(prompt || '');
          if (!ok) return;
          const prev = this.textContent;
          this.textContent = 'Copied';
          setTimeout(() => { this.textContent = prev; }, 900);
        });
      });

      copyAllBtn.addEventListener('click', async function () {
        const pack = visibleCards().map((card) => {
          return card.dataset.task + '\n' + card.dataset.content;
        }).join('\n\n-----\n\n');
        await copyText(pack);
        const prev = this.textContent;
        this.textContent = 'Copied All';
        setTimeout(() => { this.textContent = prev; }, 1200);
      });

      searchInput.addEventListener('input', applyFilter);
      renderCount();
    })();
  </script>
</body>
</html>
