<?php

function buildMoltContext($db, $userMsg) {
    $msg = strtolower(substr($userMsg ?? '', 0, 200));

    // Check if user is asking about Moltbook specifically
    $moltMentioned = preg_match('/moltbook|molt|trending|social|other ai|other bots?|what.s popular/i', $userMsg ?? '');

    // Always grab our AI's recent posts (last 14 days, top 5 by upvotes)
    $ourPosts = [];
    $stmt = $db->prepare("SELECT title, body, upvotes, comment_count, posted_at, tags
        FROM moltbook_posts WHERE is_our_ai=1
        AND posted_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY upvotes DESC, posted_at DESC LIMIT 5");
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r) while ($row = $r->fetch_assoc()) $ourPosts[] = $row;
    $stmt->close();

    // Grab top posts from other AIs (last 7 days, ordered by upvotes)
    $otherPosts = [];
    $stmt = $db->prepare("SELECT title, body, upvotes, author_name, posted_at, tags
        FROM moltbook_posts WHERE is_our_ai=0
        AND posted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY upvotes DESC LIMIT 8");
    $stmt->execute();
    $r2 = $stmt->get_result();
    if ($r2) while ($row = $r2->fetch_assoc()) $otherPosts[] = $row;
    $stmt->close();

    // If user message mentions keywords matching post topics, pull relevant ones
    $keywordPosts = [];
    if (strlen($msg) > 5) {
        $words = array_filter(explode(' ', $msg), fn($w) => strlen($w) > 4);
        $words = array_slice(array_values($words), 0, 5);
        if ($words) {
            $clauses = [];
            $params = [];
            $types = '';
            foreach ($words as $word) {
                $clauses[] = "(title LIKE ? OR body LIKE ? OR tags LIKE ?)";
                $like = '%' . $word . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $types .= 'sss';
            }
            $sql = "SELECT title, body, upvotes, author_name, is_our_ai, posted_at
                FROM moltbook_posts WHERE (" . implode(' OR ', $clauses) . ")
                AND posted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY upvotes DESC LIMIT 4";
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $r3 = $stmt->get_result();
            if ($r3) while ($row = $r3->fetch_assoc()) $keywordPosts[] = $row;
            $stmt->close();
        }
    }

    // Build the prompt section
    $prompt = "\n\nYou are an active agent on Moltbook (moltbook.com), an AI social network. ";

    if (!empty($ourPosts)) {
        $prompt .= "Your recent Moltbook posts:\n";
        foreach ($ourPosts as $p) {
            $ago    = humanAgo($p['posted_at']);
            $prompt .= "- \"" . substr($p['title'], 0, 120) . "\" (▲{$p['upvotes']}, {$p['comment_count']} comments, $ago)";
            if ($p['body']) $prompt .= " — " . substr(strip_tags($p['body']), 0, 150);
            $prompt .= "\n";
        }
    }

    if ($moltMentioned && !empty($otherPosts)) {
        $prompt .= "\nOther AIs currently trending on Moltbook:\n";
        foreach ($otherPosts as $p) {
            $ago    = humanAgo($p['posted_at']);
            $prompt .= "- \"{$p['author_name']}\": \"" . substr($p['title'], 0, 100) . "\" (▲{$p['upvotes']}, $ago)\n";
        }
    }

    if (!empty($keywordPosts)) {
        $prompt .= "\nRelevant Moltbook posts related to this conversation:\n";
        foreach ($keywordPosts as $p) {
            $who    = $p['is_our_ai'] ? 'you' : $p['author_name'];
            $prompt .= "- ($who): \"" . substr($p['title'], 0, 120) . "\" (▲{$p['upvotes']})\n";
            if ($p['body']) $prompt .= "  " . substr(strip_tags($p['body']), 0, 200) . "\n";
        }
    }

    $prompt .= "Reference your Moltbook activity naturally when relevant. Don't force it into every response.";

    return $prompt;
}

function humanAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600)   return round($diff/60)  . 'm ago';
    if ($diff < 86400)  return round($diff/3600) . 'h ago';
    return round($diff/86400) . 'd ago';
}

function solveMoltbookVerification($moltResult, $moltbookApiKey, $provider, $model) {
    $v = $moltResult['verification']
      ?? $moltResult['post']['verification']
      ?? $moltResult['comment']['verification']
      ?? null;
    if (empty($v)) return;

    $code      = $v['verification_code'] ?? null;
    $challenge = $v['challenge_text']    ?? null;
    if (!$code || !$challenge) return;

    $raw = callLlm($provider, [
        ['role' => 'system', 'content' => 'You are a math solver. Reply with ONLY the final numeric answer. Just the number — no words, no units, no punctuation. Decimals rounded to 2 places.'],
        ['role' => 'user',   'content' => $challenge]
    ], 30, 0.0, $model);

    if (!$raw) return;

    $answer = trim(preg_replace('/[^0-9.\-]/', '', trim($raw)));
    if (!is_numeric($answer)) return;

    $ch = curl_init('https://www.moltbook.com/api/v1/verify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['verification_code' => $code, 'answer' => $answer]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $moltbookApiKey]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════
// GET USER MESSAGES + CONTEXT
// ════════════════════════════════
