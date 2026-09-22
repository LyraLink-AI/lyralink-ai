<?php
/**
 * Model-driven tool calls.
 *
 * Until now the runtime never let the model choose a tool: capabilities were
 * selected by heuristics on the user's message (a regex decided whether to
 * search the web). This adds a real request/execute/observe loop, with two
 * deliberate limits:
 *
 *   - The loop is bounded. A model that keeps calling tools is cut off rather
 *     than allowed to spend without limit.
 *   - A call that needs confirmation stops the loop immediately. Continuing
 *     would let the model narrate a consequential action that has not happened.
 *
 * Parsing is strict and the marker is stripped from what the user sees, so a
 * malformed call degrades to ordinary text instead of leaking protocol noise.
 */

if (!function_exists('chat_tool_marker_open')) {
    function chat_tool_marker_open(): string { return '<lyra_tool_call>'; }
    function chat_tool_marker_close(): string { return '</lyra_tool_call>'; }
}

if (!function_exists('chat_tool_calls_parse')) {
    /**
     * Extract tool calls from a model reply.
     * Returns ['calls' => [['capability'=>string,'args'=>array,'raw'=>string]], 'malformed' => int]
     */
    function chat_tool_calls_parse(string $text): array {
        $out = ['calls' => [], 'malformed' => 0];
        if ($text === '' || strpos($text, chat_tool_marker_open()) === false) {
            return $out;
        }

        $pattern = '/' . preg_quote(chat_tool_marker_open(), '/') . '(.*?)'
            . preg_quote(chat_tool_marker_close(), '/') . '/s';
        if (preg_match_all($pattern, $text, $matches) === false) {
            return $out;
        }

        foreach ((array)$matches[1] as $payload) {
            $payload = trim((string)$payload);
            // Tolerate a fenced body, which models produce habitually.
            $payload = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $payload);
            $decoded = json_decode((string)$payload, true);
            if (!is_array($decoded)) {
                $out['malformed']++;
                continue;
            }
            $capability = trim((string)($decoded['capability'] ?? $decoded['tool'] ?? ''));
            if ($capability === '' || !preg_match('/^[a-z][a-z0-9_.]{1,63}$/', $capability)) {
                $out['malformed']++;
                continue;
            }
            $args = $decoded['args'] ?? $decoded['arguments'] ?? [];
            if (!is_array($args)) {
                $out['malformed']++;
                continue;
            }
            $out['calls'][] = ['capability' => $capability, 'args' => $args, 'raw' => $payload];
            // Cap per response so one reply cannot trigger a burst of actions.
            if (count($out['calls']) >= 3) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('chat_tool_calls_strip')) {
    /** Remove tool markers so protocol noise never reaches the user. */
    function chat_tool_calls_strip(string $text): string {
        if ($text === '' || strpos($text, chat_tool_marker_open()) === false) {
            return $text;
        }
        $pattern = '/' . preg_quote(chat_tool_marker_open(), '/') . '.*?'
            . preg_quote(chat_tool_marker_close(), '/') . '/s';
        $clean = preg_replace($pattern, '', $text);
        $clean = preg_replace("/\n{3,}/", "\n\n", (string)$clean);
        return trim((string)$clean);
    }
}

if (!function_exists('chat_tool_manifest_prompt')) {
    /**
     * Instruction block describing the tools that are genuinely usable now.
     * The phrasing is deliberately restrictive: the runtime's existing honesty
     * checks fail a reply that claims an action the runtime did not perform.
     */
    function chat_tool_manifest_prompt(array $manifest): string {
        // LYRA_CAPABILITY_HONESTY
        // The registry lists what EXISTS; $manifest lists what is CALLABLE for this
        // request. Those differ constantly (a guest has no granted permissions, so
        // the manifest is legitimately empty). Without stating that difference the
        // model conflates "not enabled right now" with "I have no such ability" and
        // answers a capability question with a flat denial that contradicts the
        // registry - observed live 2026-09-22 when it answered "Can you read
        // /etc/nginx/nginx.conf?" with "I don't have direct access to read files",
        // while the same deployment reported filesystem.read as registered.
        $knownCapabilities = [];
        if (function_exists('chat_os_capability_registry')) {
            foreach (chat_os_capability_registry([]) as $knownId => $knownCap) {
                $knownId = (string)$knownId;
                // model.* is internal routing, never user-callable.
                if (strpos($knownId, 'model.') === 0 || empty($knownCap['enabled'])) {
                    continue;
                }
                $knownCapabilities[] = '- ' . $knownId . ' - ' . (string)($knownCap['description'] ?? '');
            }
        }

        $capabilityHonesty = '';
        if ($knownCapabilities !== []) {
            $capabilityHonesty = "\n\nCAPABILITY HONESTY\n"
                . "These capabilities are registered in Lyralink. Some may not be enabled for this "
                . "specific session because of permissions or runtime availability.\n"
                . implode("\n", $knownCapabilities) . "\n"
                . "When the user asks whether you can do something covered by one of the capabilities "
                . "listed above, tell them the capability exists and whether it is enabled for this "
                . "session. Do not answer with a blanket denial of ability when a matching capability is "
                . "listed - that is inaccurate. Equally, never claim you performed anything: nothing has "
                . "been performed unless the runtime returned a result in this conversation.";
        }

        if ($manifest === []) {
            return "\n\nTOOLS\nNo tool is enabled for this request, so nothing can be executed in this turn. "
                . "Do not claim that any tool, command, file write, database query or search was performed."
                . $capabilityHonesty;
        }

        $lines = [];
        foreach ($manifest as $tool) {
            $schema = (array)($tool['input_schema'] ?? []);
            $pairs = [];
            foreach ($schema as $name => $type) {
                $pairs[] = $name . ':' . rtrim((string)$type, '?');
            }
            $signature = implode(', ', $pairs);
            $note = !empty($tool['requires_confirmation'])
                ? ' Requires explicit user confirmation: request it, then wait. It has NOT run until the runtime reports success.'
                : '';
            $lines[] = '- ' . $tool['capability_id'] . '(' . $signature . ') - '
                . (string)$tool['description'] . $note;
        }

        return "\n\nTOOLS\n"
            . "You may request one tool at a time by emitting exactly one line of this form:\n"
            . chat_tool_marker_open() . '{"capability":"<id>","args":{...}}' . chat_tool_marker_close() . "\n\n"
            . "Available now:\n" . implode("\n", $lines) . "\n\n"
            . "Rules:\n"
            . "- Only use the ids listed above. Requests for anything else are rejected.\n"
            . "- Never state or imply that a tool ran unless the runtime returned a result for it.\n"
            . "- If a tool requires confirmation, ask the user plainly and wait; do not describe the outcome in advance.\n"
            . "- If a result reports a failure, report the failure as given. Do not repair it by inventing success."
            . $capabilityHonesty;
    }
}

if (!function_exists('chat_tool_result_block')) {
    /** Format dispatch outcomes for the model as an observation message. */
    function chat_tool_result_block(array $dispatches): string {
        $parts = [];
        foreach ($dispatches as $d) {
            $capability = (string)($d['capability_id'] ?? 'unknown');
            $status = (string)($d['status'] ?? 'UNKNOWN');
            $reason = (string)($d['reason'] ?? '');
            $body = trim((string)($d['stdout'] ?? ''));
            if ($body === '' && !empty($d['error_text'])) {
                $body = trim((string)$d['error_text']);
            }
            $parts[] = '[tool:' . $capability . ' status=' . $status . ($reason !== '' ? ' reason=' . $reason : '') . ']'
                . ($body !== '' ? "\n" . $body : "\n(no output)");
        }
        return "Tool results follow. These are the only tool outcomes that occurred. "
            . "Do not add tools or results that are not listed here.\n\n" . implode("\n\n", $parts);
    }
}

if (!function_exists('chat_tool_loop_run')) {
    /**
     * Request -> execute -> observe -> answer, bounded.
     *
     * $llmCall receives a message array and returns the model's text, which
     * keeps this independent of the provider/routing signature in chat.php.
     * Returns the final visible reply plus every execution record, so the
     * caller can persist real provenance rather than a summary.
     */
    function chat_tool_loop_run(
        string $reply,
        array $fullMessages,
        callable $llmCall,
        array $context = [],
        int $maxIterations = 2
    ): array {
        $result = [
            'reply' => $reply,
            'records' => [],
            'calls' => [],
            'pending' => null,
            'iterations' => 0,
            'malformed' => 0,
        ];

        $maxIterations = max(0, min(4, $maxIterations));

        for ($i = 0; $i < $maxIterations; $i++) {
            $parsed = chat_tool_calls_parse($result['reply']);
            $result['malformed'] += (int)$parsed['malformed'];
            if ($parsed['calls'] === []) {
                break;
            }

            $result['iterations'] = $i + 1;
            $visible = chat_tool_calls_strip($result['reply']);
            $dispatches = [];

            foreach ($parsed['calls'] as $call) {
                $dispatch = chat_capability_dispatch($call['capability'], $call['args'], $context);
                $dispatches[] = $dispatch;
                $result['calls'][] = [
                    'capability' => $call['capability'],
                    'status' => (string)($dispatch['status'] ?? ''),
                    'reason' => (string)($dispatch['reason'] ?? ''),
                ];
                if (is_array($dispatch['record'] ?? null)) {
                    $result['records'][] = $dispatch['record'];
                }
                if (($dispatch['status'] ?? '') === 'PENDING_CONFIRMATION') {
                    $result['pending'] = $dispatch;
                }
            }

            // A pending confirmation must reach the user before anything else.
            if ($result['pending'] !== null) {
                $pending = $result['pending'];
                $actionId = (string)($pending['pending_action_id'] ?? '');
                $what = (string)($pending['capability_id'] ?? 'an action');
                $result['reply'] = ($visible !== '' ? $visible . "\n\n" : '')
                    . 'I need your confirmation before running ' . $what . '.'
                    . ($actionId !== '' ? ' Reply "approve ' . $actionId . '" to proceed, or "cancel".' : '')
                    . "\n\nNothing has been executed yet.";
                break;
            }

            $result['reply'] = $visible;
            $nextMessages = $fullMessages;
            $nextMessages[] = ['role' => 'assistant', 'content' => $visible !== '' ? $visible : '(tool request)'];
            $nextMessages[] = ['role' => 'user', 'content' => chat_tool_result_block($dispatches)];

            $follow = $llmCall($nextMessages);
            if (!is_string($follow) || trim($follow) === '') {
                // Keep the pre-tool text rather than emitting an empty answer.
                $result['reply'] = $visible;
                break;
            }
            $result['reply'] = $follow;
        }

        $result['reply'] = chat_tool_calls_strip((string)$result['reply']);
        return $result;
    }
}
