<?php

/**
 * Compact a long conversation instead of silently discarding its older turns.
 *
 * Returns the same shape of array with older turns folded into a single digest
 * message, placed immediately after any leading system messages so the recent
 * turns stay in their original order and at the end.
 *
 * @return array{messages: array, compacted: int, applied: bool, digest_chars: int}
 */
function chat_compact_conversation(array $messages, array $options = []): array
{
    $unchanged = static fn(): array => [
        'messages' => $messages, 'digest' => '', 'compacted' => 0,
        'applied' => false, 'digest_chars' => 0,
    ];

    $enabled = $options['enabled'] ?? null;
    if ($enabled === null) {
        $enabled = api_get_secret('CHAT_CONTEXT_COMPACT', '1') === '1';
    }
    if (!$enabled || count($messages) === 0) {
        return $unchanged();
    }

    $keepRecent = (int)($options['keep_recent'] ?? api_get_secret('CHAT_CONTEXT_COMPACT_KEEP_RECENT', '8'));
    $digestChars = (int)($options['digest_chars'] ?? api_get_secret('CHAT_CONTEXT_COMPACT_DIGEST_CHARS', '3000'));
    $userChars = (int)($options['user_chars'] ?? api_get_secret('CHAT_CONTEXT_COMPACT_USER_CHARS', '220'));
    $asstChars = (int)($options['assistant_chars'] ?? api_get_secret('CHAT_CONTEXT_COMPACT_ASST_CHARS', '160'));

    $keepRecent = max(2, min(20, $keepRecent));
    $digestChars = max(200, min(20000, $digestChars));
    $userChars = max(40, min(4000, $userChars));
    $asstChars = max(40, min(4000, $asstChars));

    // Nothing to do: the conversation already fits.
    if (count($messages) <= $keepRecent + 1) {
        return $unchanged();
    }

    $head = array_slice($messages, 0, count($messages) - $keepRecent);
    $tail = array_slice($messages, -$keepRecent);

    // Leading system messages carry instructions, not history, so they are kept
    // verbatim and never folded into the digest.
    $leadingSystem = [];
    while ($head !== [] && is_array($head[0]) && strtolower((string)($head[0]['role'] ?? '')) === 'system') {
        $leadingSystem[] = array_shift($head);
    }

    $lines = [];
    foreach ($head as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = strtolower((string)($msg['role'] ?? ''));
        if ($role === 'system') {
            continue; // instructions handled above
        }
        $text = trim((string)preg_replace('/\s+/', ' ', llm_message_content_text($msg['content'] ?? '')) ?? '');
        if ($text === '') {
            continue;
        }
        $limit = $role === 'user' ? $userChars : $asstChars;
        if (mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit)) . '…';
        }
        $label = $role === 'user' ? 'User asked' : 'You replied';
        $lines[] = '- ' . $label . ': ' . $text;
    }

    if ($lines === []) {
        return $unchanged();
    }

    // Over budget: keep BOTH ends rather than only the newest turns. Measured on a
    // 20-turn conversation the previous drop-from-oldest approach discarded turns
    // 1-9, losing the foundational facts a conversation usually establishes first
    // (project name, deployment target, stated constraints) while keeping the
    // immediate continuity. Splitting the budget keeps both: ~40% to the earliest
    // turns, the remainder to the turns next to the verbatim window.
    $body = implode("\n", $lines);
    if (mb_strlen($body) > $digestChars) {
        $firstLines = [];
        $usedFirst = 0;
        $firstCap = (int)($digestChars * 0.4);
        foreach ($lines as $line) {
            $len = mb_strlen($line) + 1;
            if ($usedFirst + $len > $firstCap && $firstLines !== []) {
                break;
            }
            $firstLines[] = $line;
            $usedFirst += $len;
        }

        $lastLines = [];
        $usedLast = 0;
        $lastCap = max(0, $digestChars - $usedFirst);
        for ($i = count($lines) - 1; $i >= count($firstLines); $i--) {
            $len = mb_strlen($lines[$i]) + 1;
            if ($usedLast + $len > $lastCap && $lastLines !== []) {
                break;
            }
            array_unshift($lastLines, $lines[$i]);
            $usedLast += $len;
        }

        $omitted = count($lines) - count($firstLines) - count($lastLines);
        $body = implode("\n", $firstLines);
        if ($omitted > 0) {
            // Stated explicitly so the model knows the record is partial and can say
            // so rather than asserting something the omitted turns contradict.
            $body .= "\n- … ({$omitted} earlier turns omitted) …\n";
        } elseif ($body !== '') {
            $body .= "\n";
        }
        $body .= implode("\n", $lastLines);
    }
    if (mb_strlen($body) > $digestChars) {
        $body = mb_substr($body, 0, $digestChars) . '…';
    }

    $digest = "Earlier turns in this conversation, compacted to keep the context small.\n"
        . "These are summaries of what came before; the most recent turns follow verbatim.\n"
        . $body;

    // The digest is returned SEPARATELY rather than inserted into the message array.
    //
    // Measured: as a message at the front of the array it was discarded. trimMessages()
    // walks backwards keeping the newest messages and its effective cap depends on the
    // reply budget - with maxReplyTokens=60 the cap is 4, not 12 - so a front-positioned
    // digest is trimmed away first and the model never sees the folded context. The
    // caller appends it to the system prompt, which message trimming cannot touch.
    $compacted = array_merge($leadingSystem, $tail);

    // Never make the prompt bigger. Measured: with short messages the digest header
    // and per-turn prefixes outweighed the raw turns, growing the prompt from ~754
    // to ~916 tokens. Since the digest is lossy, a larger prompt would be strictly
    // worse - more latency for less information. Comparing before and after makes
    // "never increases the prompt" true by construction, so a future defaults
    // change cannot reintroduce it.
    $contentChars = static function (array $msgs): int {
        $total = 0;
        foreach ($msgs as $m) {
            if (is_array($m)) {
                $total += strlen((string)($m['content'] ?? ''));
            }
        }
        return $total;
    };
    // The digest counts towards the comparison: if digest+tail is not smaller than the
    // original history, compaction would enlarge the prompt and must not run.
    if (($contentChars($compacted) + strlen($digest)) >= $contentChars($messages)) {
        return $unchanged();
    }

    return [
        'messages' => $compacted,
        'digest' => $digest,
        'compacted' => count($head),
        'applied' => true,
        'digest_chars' => mb_strlen($digest),
    ];
}
function trimMessages($messages, $systemPrompt, $model, $maxReplyTokens = 1024) {
    $modelLower = strtolower((string)$model);
    if (str_contains($modelLower, '70b')) {
        $contextLimit = 28000;
    } elseif (str_contains($modelLower, '3b')) {
        $contextLimit = 3400;
    } elseif (str_contains($modelLower, '8b')) {
        $contextLimit = 5200;
    } else {
        $contextLimit = 6000;
    }
    $systemTokens = (int)(strlen($systemPrompt) / 4);
    $budget = max(256, $contextLimit - $systemTokens - $maxReplyTokens);
    $maxMessages = str_contains($modelLower, '3b') ? 8 : 12;
    if ($maxReplyTokens <= 512) {
        $maxMessages = min($maxMessages, 6);
    }
    if ($maxReplyTokens <= 192) {
        $maxMessages = min($maxMessages, 4);
    }

    $kept = [];
    $usedTokens = 0;
    $reversed = array_reverse($messages);

    foreach ($reversed as $msg) {
        if (count($kept) >= $maxMessages) {
            break;
        }
        $msgText = llm_message_content_text($msg['content'] ?? '');
        $msgTokens = (int)(strlen($msgText) / 4) + 4;
        if ($usedTokens + $msgTokens > $budget && !empty($kept)) {
            break;
        }
        $kept[] = $msg;
        $usedTokens += $msgTokens;
    }

    $trimmed = array_reverse($kept);
    $trimCount = count($messages) - count($trimmed);
    return ['messages' => $trimmed, 'trimmed' => $trimCount];
}

function getPostScore($reply, $messages) {
    $score = 0;
    $len = strlen($reply);
    if ($len > 800) $score += 3;
    elseif ($len > 400) $score += 2;
    elseif ($len > 200) $score += 1;
    if (preg_match('/```/', $reply)) $score += 2;
    if (preg_match('/\*\*/', $reply)) $score += 1;
    if (preg_match('/^\d+\./m', $reply)) $score += 1;
    if (preg_match('/how|why|what|explain/i', $reply)) $score += 1;
    if (preg_match('/I cannot|I\'m unable/i', $reply)) $score -= 5;
    if (preg_match('/How can I (help|assist) you today/i', $reply)) $score -= 2;
    if ($len < 150) $score -= 3;
    if (count($messages) >= 4) $score += 2;
    elseif (count($messages) >= 2) $score += 1;
    return $score;
}

function shouldPost($reply, $messages) {
    return getPostScore($reply, $messages) >= 4;
}