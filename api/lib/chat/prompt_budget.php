<?php
/**
 * Prompt-budget telemetry for the chat path.
 *
 * WHY THIS EXISTS
 * ---------------
 * Measured on 2026-09-22: the assembled system prompt is dominated by a small
 * number of always-on instructional blocks, and Ollama reuses the KV cache only
 * for the byte-identical LEADING tokens of a request (verified: an identical
 * repeat cost 0.030s against 5.165s cold, while changing the start of the system
 * message returned it to 4.878s).
 *
 * That means the per-turn cost is set by how much of the prompt sits AFTER the
 * first request-varying block, not by the total size. Deciding whether to trim
 * therefore requires knowing where each block starts - which is what this records.
 *
 * Blocks are located by their opening sentence and sized from consecutive
 * markers, so no instrumentation is needed at each append site and the chat
 * prompt code is left untouched.
 *
 * This function is additive and best-effort. It reads state, writes one JSONL
 * line, and swallows every error: telemetry must never affect a reply.
 */

function chat_prompt_budget_log(
    string $systemPrompt,
    array $history,
    string $model,
    string $provider,
    array $flags = []
): void {
    try {
        $logPath = (function_exists('api_get_secret') ? (string)api_get_secret('LYRA_PROMPT_LOG', '') : '')
            ?: (getenv('LYRA_PROMPT_LOG') ?: '')
            ?: '/var/log/lyralink/prompt_budget.jsonl';

        // Never create directories at request time; if the path is not prepared,
        // stay silent rather than risk a permission error on the web path.
        if (!is_dir(dirname($logPath))) {
            return;
        }

        // Opening sentence of each block that can be present in the prompt.
        $markers = [
            'guardrails' => 'Evidence-first operating rules:',
            'degraded'   => 'Runtime mode: the local chat service is under load.',
            'multipart'  => 'Multi-part answer mode is active.',
            'prose'      => 'For ordinary conversation, default to natural prose',
            'latency'    => 'Latency mode: for short casual greetings',
            'auth'       => 'The user is authenticated as a logged-in account',
            'guest'      => 'The user is a guest (not logged in).',
            'task'       => 'Task mode is enabled.',
            'goals'      => 'Persistent goals to keep in mind across this conversation:',
            'workspace'  => 'Developer workspace context',
            'memory'     => 'Use the following memory snippets as optional context only.',
            'web'        => 'Use these current web notes when helpful',
            'benchmark'  => 'Benchmark response contract:',
        ];

        $positions = [];
        foreach ($markers as $name => $needle) {
            $at = strpos($systemPrompt, $needle);
            if ($at !== false) {
                $positions[$at] = $name;
            }
        }
        ksort($positions);

        // Each block runs from its own marker to the next marker, so sizes are
        // derived rather than measured at each append.
        $offsets = array_keys($positions);
        $blocks  = [];
        $last    = count($offsets) - 1;
        foreach ($offsets as $i => $at) {
            $end = $i === $last ? strlen($systemPrompt) : $offsets[$i + 1];
            $blocks[$positions[$at]] = $end - $at;
        }

        $historyChars = 0;
        foreach ($history as $message) {
            if (is_array($message)) {
                $historyChars += strlen((string)($message['content'] ?? ''));
            }
        }

        // The static prefix is everything before the first varying block; it is
        // the portion Ollama can serve from cache. Recorded explicitly because it
        // is the number that actually governs per-turn latency.
        $firstVarying = null;
        foreach (['degraded', 'multipart', 'latency', 'memory', 'web', 'benchmark'] as $volatile) {
            $needle = $markers[$volatile];
            $at     = strpos($systemPrompt, $needle);
            if ($at !== false && ($firstVarying === null || $at < $firstVarying)) {
                $firstVarying = $at;
            }
        }

        $row = [
            'ts'                 => gmdate('c'),
            'model'              => $model,
            'provider'           => $provider,
            'sys_chars'          => strlen($systemPrompt),
            'sys_tokens_est'     => intdiv(strlen($systemPrompt), 4),
            'static_prefix_chars' => $firstVarying ?? strlen($systemPrompt),
            'refill_chars'       => $firstVarying === null ? 0 : strlen($systemPrompt) - $firstVarying,
            'hist_chars'         => $historyChars,
            'hist_tokens_est'    => intdiv($historyChars, 4),
            'hist_msgs'          => count($history),
            'blocks'             => $blocks,
            'flags'              => $flags,
        ];

        // Bounded growth: keep one rotation so this can never fill the disk.
        if (is_file($logPath) && filesize($logPath) > 5_000_000) {
            @rename($logPath, $logPath . '.1');
        }

        @file_put_contents(
            $logPath,
            json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (\Throwable $e) {
        // Deliberately swallowed: telemetry must not affect a reply.
    }
}
