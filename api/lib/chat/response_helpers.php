<?php

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