<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════════
 * LYRALINK ORCHESTRATOR — DECISION POINT + RECOVERY LOOP
 * ════════════════════════════════════════════════════════════════════
 *
 * Part 1  chat_orchestrate()            what should happen for this request
 * Part 2  chat_orchestrate_recovery()   what to do when it fails
 * Part 3  chat_orchestrate_final_state() the honest terminal state
 *
 * Replaces scattered keyword decisions spread across ~20 regex tables in five
 * files, where behaviour emerged unpredictably and could not be unit tested.
 *
 * Canonical-architecture constraints honoured:
 *   - Deterministic logic first; no LLM call to decide routing.
 *   - Record context EXCLUDED as well as context SELECTED.
 *   - Never confuse INTENT with EXECUTION: an analytical question is not a
 *     command; a read-only inspection is not an incident; a question is not
 *     an action.
 *   - Never confuse EXECUTION with SUCCESS, or UNVERIFIED with VERIFIED.
 *   - Explicit terminal-state vocabulary.
 *   - Bounded recovery: retries are capped because cost matters.
 *   - Bias toward completing work, since the product target is a low
 *     human-intervention rate; escalation must be justified, not default.
 *
 * This module DECIDES. It never executes and never generates.
 */

/** Maximum generation attempts before escalating or stopping.
 *  Bounded because cost matters; recovery must not loop indefinitely. */
if (!defined('CHAT_ORCH_MAX_ATTEMPTS')) {
    define('CHAT_ORCH_MAX_ATTEMPTS', 2);
}

if (!function_exists('chat_orchestrate')) {

    /**
     * @return array Full decision record including observability fields.
     */
    function chat_orchestrate(string $latestUserMsg, array $options = []): array
    {
        $msg = trim($latestUserMsg);
        $lower = strtolower($msg);
        $len = strlen($msg);

        $plan = (string) ($options['plan'] ?? 'free');
        $privacyMode = strtolower((string) ($options['privacy_mode'] ?? 'local'));
        $taskMode = (bool) ($options['task_mode'] ?? false);
        $hasAttachments = (bool) ($options['has_attachments'] ?? false);
        $webRequested = array_key_exists('web_search', $options)
            ? (bool) $options['web_search']
            : null;
        $orgId = isset($options['org_id']) ? (int) $options['org_id'] : 0;
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : 0;

        $decision = [
            'request_id' => 'orch_' . bin2hex(random_bytes(8)),
            'task_type' => 'knowledge',
            'complexity' => 'direct',
            'model_selected' => '',
            'model_route' => '',
            'context_selected' => ['conversation_window'],
            // Unconditional: historical task state is the primary source of
            // cross-task contamination and is never injected implicitly.
            'context_excluded' => ['historical_task_state'],
            'tools_selected' => [],
            'verification_required' => false,
            'risk_level' => 'low',
            'requires_authorization' => false,
            'privacy_mode' => $privacyMode,
            'org_id' => $orgId,
            'user_id' => $userId,
            'confidence' => 'medium',
            'reasoning' => [],
            'final_state' => 'PENDING',
        ];

        if ($msg === '') {
            $decision['task_type'] = 'conversation';
            $decision['confidence'] = 'high';
            $decision['reasoning'][] = 'empty_request';
            $decision['final_state'] = 'NEEDS_INPUT';
            return $decision;
        }

        // ── 1. CONVERSATION ──
        $isCasual = preg_match(
            '/^(hi|hey|hello|yo|sup|good\s+(morning|afternoon|evening)|'
            . 'thanks|thank you|ty|ok|okay|cool|nice|got it|sounds good|'
            . 'how are you|what\'s up|lol|haha)\b/i',
            $msg
        ) === 1;
        if ($isCasual && $len <= 60 && !$taskMode) {
            $decision['task_type'] = 'conversation';
            $decision['context_excluded'][] = 'dataset_retrieval';
            $decision['context_excluded'][] = 'web_results';
            $decision['model_route'] = 'fast';
            $decision['model_selected'] = chat_orchestrator_model('fast', $plan, $privacyMode);
            $decision['reasoning'][] = 'casual_turn_no_tools';
            $decision['confidence'] = 'high';
            return $decision;
        }

        // ── 2. DETERMINISTIC (no model call) ──
        if (chat_orchestrator_deterministic($msg) !== null) {
            $decision['task_type'] = 'deterministic';
            $decision['context_excluded'][] = 'dataset_retrieval';
            $decision['context_excluded'][] = 'web_results';
            $decision['context_excluded'][] = 'model_call';
            $decision['model_route'] = 'none';
            $decision['model_selected'] = 'none';
            $decision['reasoning'][] = 'answered_by_deterministic_logic';
            $decision['confidence'] = 'high';
            return $decision;
        }

        // Evaluation order is deliberate: explicit freshness outranks topic
        // (asking for the latest PHP docs needs research, not a code route),
        // and abstract analysis outranks tooling nouns ("compare postgres and
        // mysql" is not a command).
        $freshSignals = '/\b(latest|current|today|recent|news|who won|this (?:year|month|week)|'
            . 'up[- ]to[- ]date|right now|stock price|weather|release notes|changelog)\b/i';
        $searchSignals = '/\b(research|look (?:it |this )?up|search (?:the )?web|google|'
            . 'find sources|citation|cite|verify online|documentation for)\b/i';
        $arithSignals = '/\b(calculate|compute|percentage|percent change|percentage point|'
            . 'roi|roas|average|median|sum of|convert|how many|how much)\b/i';
        $reasoningSignals = '/\b(compare|comparison|trade[- ]?off|analy[sz]e|evaluate|'
            . 'pros and cons|strategy|architecture|design (?:a|the)|which is better|'
            . 'implications?|roadmap|root cause)\b/i';
        $codingSignals = '/\b(code|function|class|method|refactor|debug|stack trace|compile|'
            . 'syntax|php|python|javascript|typescript|sql|regex|endpoint|dockerfile|'
            . 'unit test|exception|segfault|null pointer|container|pod|crash|oom|'
            . 'exit code|migration|schema)\b/i';
        $writingSignals = '/\b(write|rewrite|reword|draft|polish|email|message|subject line|'
            . 'tone|sound (?:more )?(?:professional|natural)|caption|slogan|bio)\b/i';
        $commandSignals = '/\b(run|execute|deploy|deployed|deploying|restart|install|curl|ssh|'
            . 'systemctl|docker|kubectl|psql|mysql|grep|tail|cat |chmod|health ?check|'
            . 'uptime|disk usage|delete|drop|truncate|wipe|purge|remove|destroy|'
            . 'shutdown|shut down|kill|terminate|revoke|rotate|scale|rollback)\b/i';

        // An analytical question asks for understanding, not action.
        $isAnalyticalQuestion = preg_match(
            '/^(why|how (?:does|do|would|can|should)|compare|explain|what(?:\'s| is) the '
            . '(?:difference|best|pros|better)|which is better)/i',
            $msg
        ) === 1;

        // A read-only inspection is a legitimate low-risk action even when
        // phrased as a question.
        $isInspection = preg_match('/\b(check|inspect|verify|confirm|whether|status|health|'
            . 'is it (?:up|down|running)|are they (?:up|down|running))\b/i', $msg) === 1;

        // ── 3. TASK TYPE ──
        $type = 'knowledge';
        if (preg_match($freshSignals, $lower) === 1 || preg_match($searchSignals, $lower) === 1) {
            $type = 'research';
        } elseif (preg_match($arithSignals, $lower) === 1) {
            $type = 'data_analysis';
        } elseif (preg_match($reasoningSignals, $lower) === 1) {
            $type = 'reasoning';
        } elseif (preg_match($codingSignals, $lower) === 1) {
            $type = 'coding';
        } elseif (preg_match($commandSignals, $lower) === 1 && !$isAnalyticalQuestion) {
            $type = 'tool_execution';
        } elseif (preg_match($writingSignals, $lower) === 1) {
            $type = 'writing';
        }
        $decision['task_type'] = $type;

        // ── 4. COMPLEXITY ──
        $complexity = 'direct';
        $deepSignals = '/\b(step by step|in detail|thorough|comprehensive|'
            . 'full (?:analysis|implementation|breakdown)|multi[- ]step|end to end|'
            . 'production[- ]ready|architecture|roadmap|deep dive)\b/i';
        if (preg_match($deepSignals, $lower) === 1 || $len > 700 || $hasAttachments) {
            $complexity = 'deep';
        } elseif ($len > 220 || $taskMode || in_array($type, ['coding', 'reasoning'], true)) {
            $complexity = 'structured';
        }
        $decision['complexity'] = $complexity;

        // ── 5. TOOLS (required, not merely available) ──
        $tools = [];
        if ($type === 'research') {
            $tools[] = 'web.search';
        } elseif ($webRequested === true && !$hasAttachments) {
            $tools[] = 'web.search';
        }
        if ($type === 'tool_execution') {
            $tools[] = 'shell.execute';
        } elseif ($isInspection) {
            $tools[] = 'server.inspect';
        }
        if ($type === 'coding' && preg_match('/\b(container|pod|docker|kubectl|systemctl)\b/i', $lower) === 1) {
            $tools[] = 'server.inspect';
        }
        $decision['tools_selected'] = array_values(array_unique($tools));

        // ── 6. CONTEXT POLICY ──
        if ($len >= 20) {
            $decision['context_selected'][] = 'dataset_retrieval';
        } else {
            $decision['context_excluded'][] = 'dataset_retrieval';
        }
        if (in_array('web.search', $tools, true)) {
            $decision['context_selected'][] = 'web_results';
        } else {
            $decision['context_excluded'][] = 'web_results';
        }
        if ($hasAttachments) {
            $decision['context_selected'][] = 'attachments';
        }
        if ($type !== 'coding' && !$taskMode) {
            $decision['context_excluded'][] = 'project_artifacts';
        }

        // ── 7. MODEL ──
        $route = match ($type) {
            'coding' => 'code',
            'reasoning' => 'reasoning',
            'research' => 'reasoning',
            'writing' => 'creative',
            'tool_execution' => 'code',
            default => ($complexity === 'direct' ? 'fast' : 'default'),
        };
        $decision['model_route'] = $route;
        $decision['model_selected'] = chat_orchestrator_model($route, $plan, $privacyMode);

        // ── 8. RISK / AUTHORIZATION ──
        $destructive = '/\b(delete|drop|truncate|wipe|purge|destroy|remove|overwrite|'
            . 'format|shutdown|shut down|kill|terminate|revoke|rotate)\b/i';
        $consequential = '/\b(deploy|migrate|restart|publish|send|post|purchase|'
            . 'transfer|refund|billing|payment|scale|rollback)\b/i';
        $isReadOnly = preg_match('/\b(check|inspect|view|list|show|read|status|health|'
            . 'logs?|verify|confirm|whether)\b/i', $lower) === 1;

        if (preg_match($destructive, $lower) === 1 && !$isReadOnly) {
            $decision['risk_level'] = 'high';
        } elseif (preg_match($consequential, $lower) === 1 && !$isReadOnly) {
            $decision['risk_level'] = 'medium';
        }

        $isDirective = preg_match('/^\s*(please\s+)?(deploy|delete|drop|remove|restart|'
            . 'shutdown|kill|wipe|purge|migrate|publish|send|rotate|scale)\b/i', $msg) === 1
            || preg_match('/\b(do it|go ahead|proceed|now)\s*[.!]?$/i', $msg) === 1;
        $decision['requires_authorization'] = $decision['risk_level'] !== 'low'
            && $isDirective
            && !$isAnalyticalQuestion;

        // ── 9. VERIFICATION ──
        $decision['verification_required'] = $complexity === 'deep'
            || in_array($type, ['data_analysis', 'research', 'tool_execution'], true)
            || $decision['tools_selected'] !== [];

        // ── 10. CONFIDENCE ──
        $decision['confidence'] = ($type !== 'knowledge' && $complexity !== 'deep') ? 'high' : 'medium';
        $decision['reasoning'][] = 'type=' . $type;
        $decision['reasoning'][] = 'complexity=' . $complexity;
        $decision['reasoning'][] = 'route=' . $route;
        if ($isAnalyticalQuestion) {
            $decision['reasoning'][] = 'analytical_question_not_command';
        }

        return $decision;
    }

    /**
     * Deterministic answers that must not cost a model call.
     * Returns null when the request genuinely needs intelligence.
     */
    function chat_orchestrator_deterministic(string $msg): ?string
    {
        $m = trim($msg);

        if (preg_match('/^(?:what(?:\'s| is)\s+)?(-?\d+(?:\.\d+)?)\s*([+\-*\/x])\s*'
            . '(-?\d+(?:\.\d+)?)\s*\??$/i', $m, $mm) === 1) {
            $a = (float) $mm[1];
            $b = (float) $mm[3];
            $op = strtolower($mm[2]);
            if ($op === '/' && abs($b) < 1e-12) {
                return null;
            }
            $v = match ($op) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*', 'x' => $a * $b,
                '/' => $a / $b,
                default => null,
            };
            if ($v === null) {
                return null;
            }
            return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        }

        if (preg_match('/^(?:what(?:\'s| is)\s+)?(\d+(?:\.\d+)?)\s*%\s*of\s*'
            . '(\d+(?:\.\d+)?)\s*\??$/i', $m, $mm) === 1) {
            $v = (float) $mm[2] * ((float) $mm[1] / 100);
            return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        }

        return null;
    }

    /**
     * Model for a route, honouring the caller's privacy policy.
     *
     * privacy_mode 'local' -> locally hosted models only, no egress.
     * privacy_mode 'cloud' -> the plan allow-list may include remote providers.
     */
    function chat_orchestrator_model(string $route, string $plan = 'free', string $privacyMode = 'local'): string
    {
        $map = function_exists('chat_model_router_map') ? chat_model_router_map() : [];
        $default = (string) ($map['default'] ?? 'lyralink-auto-canary:latest');

        $local = match ($route) {
            'fast' => (string) ($map['fast'] ?? $default),
            'code' => (string) ($map['code'] ?? $default),
            'reasoning' => (string) ($map['reasoning'] ?? $default),
            'creative' => (string) ($map['creative'] ?? $default),
            default => $default,
        };

        if ($local === '' || stripos($local, 'lyralink') === false) {
            $local = $default;
        }

        return $local;
    }

    /**
     * ════════════════════════════════════════════════════════════════
     * RECOVERY LOOP
     * ════════════════════════════════════════════════════════════════
     *
     * Called after an attempt completes. Diagnoses the outcome and decides the
     * next move instead of assuming success.
     *
     * Act -> Verify -> Continue, never Act -> Assume Success -> Respond.
     *
     * @param array $decision  the record returned by chat_orchestrate()
     * @param array $outcome   what actually happened, keys:
     *                           'text'         => string   reply produced ('' if none)
     *                           'tool_states'  => array    per-tool result states
     *                           'error'        => ?string  error code/message
     *                           'timed_out'    => bool
     *                           'verified'     => ?bool    verification result
     *                           'blocked'      => bool     stopped by a gate
     * @param int   $attempt   1-based attempt number
     *
     * @return array ['action' => ..., 'final_state' => ..., 'reason' => ...,
     *                'instruction' => ..., 'retry_model' => ?string]
     */
    function chat_orchestrate_recovery(array $decision, array $outcome, int $attempt = 1): array
    {
        $text = trim((string) ($outcome['text'] ?? ''));
        $error = trim((string) ($outcome['error'] ?? ''));
        $timedOut = (bool) ($outcome['timed_out'] ?? false);
        $blocked = (bool) ($outcome['blocked'] ?? false);
        $verified = $outcome['verified'] ?? null;
        $toolStates = is_array($outcome['tool_states'] ?? null) ? $outcome['tool_states'] : [];
        $maxAttempts = defined('CHAT_ORCH_MAX_ATTEMPTS') ? (int) CHAT_ORCH_MAX_ATTEMPTS : 2;

        // Highest precedence: an explicit gate. Never retry around a gate.
        if ($blocked) {
            return [
                'action' => 'ask_human',
                'final_state' => 'NEEDS_AUTHORIZATION',
                'reason' => 'blocked_by_gate',
                'instruction' => 'Explain what was blocked and what approval is required.',
                'retry_model' => null,
            ];
        }

        // A tool that was required and failed.
        $toolFailed = false;
        $toolUnavailable = false;
        foreach ($toolStates as $state) {
            if (!is_array($state)) {
                continue;
            }
            if (!empty($state['failed']) || !empty($state['timed_out'])) {
                $toolFailed = true;
            }
            if (isset($state['available']) && $state['available'] === false) {
                $toolUnavailable = true;
            }
        }

        if ($toolUnavailable) {
            return [
                'action' => 'stop',
                'final_state' => 'BLOCKED',
                'reason' => 'required_tool_unavailable',
                'instruction' => 'State plainly that the required tool is unavailable, and answer '
                    . 'from what is known without claiming the tool ran.',
                'retry_model' => null,
            ];
        }

        if ($toolFailed && $attempt < $maxAttempts) {
            return [
                'action' => 'retry',
                'final_state' => 'PENDING',
                'reason' => 'tool_failure_retryable',
                'instruction' => 'The tool failed. Retry once. If it fails again, answer from '
                    . 'what is available and say the tool did not return results.',
                'retry_model' => null,
            ];
        }

        if ($toolFailed) {
            return [
                'action' => 'answer_with_limits',
                'final_state' => 'PARTIAL_SUCCESS',
                'reason' => 'tool_failed_after_retries',
                'instruction' => 'Answer from known information. State clearly that the tool did '
                    . 'not return results and what would be needed to verify.',
                'retry_model' => null,
            ];
        }

        // Timeout: a shorter, narrower answer is more likely to complete.
        if ($timedOut && $attempt < $maxAttempts) {
            return [
                'action' => 'retry',
                'final_state' => 'PENDING',
                'reason' => 'timeout_retry_shorter',
                'instruction' => 'Retry with a shorter, more focused answer.',
                'retry_model' => null,
            ];
        }
        if ($timedOut) {
            return [
                'action' => 'stop',
                'final_state' => 'FAILED',
                'reason' => 'timeout_after_retries',
                'instruction' => 'Report that the model did not finish in time and that nothing '
                    . 'was executed or changed.',
                'retry_model' => null,
            ];
        }

        // Empty output is a failure, never a success.
        if ($text === '') {
            if ($attempt < $maxAttempts) {
                return [
                    'action' => 'retry',
                    'final_state' => 'PENDING',
                    'reason' => 'empty_output_retryable',
                    'instruction' => 'Retry the generation once.',
                    'retry_model' => null,
                ];
            }
            return [
                'action' => 'stop',
                'final_state' => 'FAILED',
                'reason' => 'empty_output_after_retries',
                'instruction' => 'Report honestly that no output was produced and that nothing '
                    . 'was executed or changed.',
                'retry_model' => null,
            ];
        }

        // An error string with usable text is a partial success at best.
        if ($error !== '') {
            return [
                'action' => 'accept',
                'final_state' => 'PARTIAL_SUCCESS',
                'reason' => 'completed_with_error:' . $error,
                'instruction' => 'Return the answer and name the error affecting completeness.',
                'retry_model' => null,
            ];
        }

        // Verification explicitly failed: try a different model before giving up,
        // because a same-model retry usually reproduces the same failure.
        if ($verified === false) {
            if ($attempt < $maxAttempts) {
                return [
                    'action' => 'retry_alt_model',
                    'final_state' => 'PENDING',
                    'reason' => 'verification_failed_retry_alt_model',
                    'instruction' => 'Retry using a different model route for this task type.',
                    'retry_model' => chat_orchestrator_alt_model($decision),
                ];
            }
            return [
                'action' => 'accept',
                'final_state' => 'UNVERIFIED',
                'reason' => 'verification_failed_final',
                'instruction' => 'Return the answer but mark it unverified and state what could '
                    . 'not be confirmed. Never present it as verified.',
                'retry_model' => null,
            ];
        }

        if ($verified === null && !empty($decision['verification_required'])) {
            return [
                'action' => 'accept',
                'final_state' => 'UNVERIFIED',
                'reason' => 'verification_required_but_not_run',
                'instruction' => 'Return the answer and state that it was not independently '
                    . 'verified.',
                'retry_model' => null,
            ];
        }

        return [
            'action' => 'accept',
            'final_state' => 'SUCCESS',
            'reason' => 'completed_and_verified',
            'instruction' => 'Return the result.',
            'retry_model' => null,
        ];
    }

    /**
     * A different model route for the same task, used when a same-model retry
     * would just reproduce the failure.
     */
    function chat_orchestrator_alt_model(array $decision): ?string
    {
        $plan = (string) ($decision['plan'] ?? 'free');
        $privacy = (string) ($decision['privacy_mode'] ?? 'local');
        $type = (string) ($decision['task_type'] ?? 'knowledge');
        $current = (string) ($decision['model_selected'] ?? '');

        // Escalate one step in capability rather than re-rolling the same route.
        $order = match ($type) {
            'coding' => ['code', 'reasoning', 'default'],
            'research', 'reasoning' => ['reasoning', 'default', 'fast'],
            'writing' => ['creative', 'default', 'reasoning'],
            default => ['default', 'reasoning', 'fast'],
        };

        foreach ($order as $route) {
            $candidate = chat_orchestrator_model($route, $plan, $privacy);
            if ($candidate !== '' && $candidate !== $current) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Collapse an outcome into the honest terminal state. Single place that
     * decides what the user is told happened, so FAILED can never be reported
     * as SUCCESS.
     */
    function chat_orchestrate_final_state(array $recovery, array $outcome): string
    {
        $state = (string) ($recovery['final_state'] ?? 'UNVERIFIED');
        $allowed = [
            'SUCCESS', 'PARTIAL_SUCCESS', 'FAILED', 'BLOCKED',
            'NEEDS_INPUT', 'NEEDS_RESEARCH', 'NEEDS_AUTHORIZATION', 'UNVERIFIED',
        ];
        if (!in_array($state, $allowed, true)) {
            return 'UNVERIFIED';
        }

        // Guard: a run that produced nothing cannot be SUCCESS.
        $text = trim((string) ($outcome['text'] ?? ''));
        if ($state === 'SUCCESS' && $text === '') {
            return 'FAILED';
        }

        // Guard: verification was required but did not pass.
        if ($state === 'SUCCESS' && ($outcome['verified'] ?? null) === false) {
            return 'UNVERIFIED';
        }

        return $state;
    }
}
