<?php

function chat_self_training_enabled(): bool {
    return api_get_secret('MODEL_SELF_TRAIN_ENABLED', '1') === '1';
}

function chat_self_training_default_file(string $workspaceRoot): string {
    $configured = trim((string)api_get_secret('MODEL_SELF_TRAIN_RUNTIME_FILE', ''));
    if ($configured !== '') {
        return $configured;
    }
    return rtrim($workspaceRoot, '/') . '/storage/model_training/runtime_feedback.jsonl';
}

function chat_self_training_clamp(float $value, float $min, float $max): float {
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function chat_self_training_compute_weight(array $signals): float {
    $verificationPassed = (bool)($signals['verification_passed'] ?? false);
    $confidenceScore = (float)($signals['confidence_score'] ?? 0.0);
    $rewardDelta = (int)($signals['reward_delta'] ?? 0);
    $hallucinationRisk = strtolower(trim((string)($signals['hallucination_risk'] ?? 'low')));
    $hasSafetyFlags = (bool)($signals['has_safety_flags'] ?? false);
    $providerError = (bool)($signals['provider_error'] ?? false);

    $weight = 1.0;
    if ($verificationPassed) {
        $weight += 0.45;
    }
    $weight += chat_self_training_clamp(($confidenceScore - 0.5) * 0.8, -0.25, 0.35);
    $weight += chat_self_training_clamp($rewardDelta * 0.04, -0.4, 0.6);

    if ($hallucinationRisk === 'high') {
        $weight -= 0.4;
    } elseif ($hallucinationRisk === 'medium') {
        $weight -= 0.2;
    }

    if ($hasSafetyFlags) {
        $weight -= 0.2;
    }
    if ($providerError) {
        $weight -= 0.35;
    }

    return chat_self_training_clamp($weight, 0.1, 3.0);
}

function chat_self_training_should_capture_row(array $row): bool {
    $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
    if (count($messages) < 2) {
        return false;
    }

    $assistantMessage = $messages[count($messages) - 1] ?? [];
    $assistantText = trim((string)($assistantMessage['content'] ?? ''));
    if ($assistantText === '') {
        return false;
    }

    $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    if (!empty($metadata['provider_error']) || !empty($metadata['reply_truncated'])) {
        return false;
    }

    if (($metadata['verification_passed'] ?? null) === false) {
        return false;
    }

    $hallucinationRisk = strtolower(trim((string)($metadata['hallucination_risk'] ?? 'low')));
    if ($hallucinationRisk === 'high') {
        return false;
    }

    $finishReason = strtolower(trim((string)($metadata['finish_reason'] ?? '')));
    if (in_array($finishReason, ['length', 'content_filter'], true)) {
        return false;
    }

    if ((float)($metadata['confidence_score'] ?? 1.0) < 0.35) {
        return false;
    }

    if (preg_match('/\b(?:and|or|because|with|to|then|if)\s*$/i', $assistantText) === 1) {
        return false;
    }

    return true;
}

function chat_self_training_build_row(string $userText, string $assistantText, array $metadata = []): array {
    $userText = trim($userText);
    $assistantText = trim($assistantText);
    $sampleWeight = (float)($metadata['sample_weight'] ?? 1.0);
    $metadata['sample_weight'] = chat_self_training_clamp($sampleWeight, 0.1, 3.0);
    $metadata['captured_at'] = $metadata['captured_at'] ?? gmdate('c');

    return [
        'messages' => [
            ['role' => 'user', 'content' => $userText],
            ['role' => 'assistant', 'content' => $assistantText],
        ],
        'metadata' => $metadata,
    ];
}

function chat_self_training_append_row(array $row, string $workspaceRoot): bool {
    if (!chat_self_training_should_capture_row($row)) {
        return false;
    }

    $targetPath = chat_self_training_default_file($workspaceRoot);
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }

    $handle = @fopen($targetPath, 'ab');
    if (!$handle) {
        return false;
    }

    $ok = false;
    if (@flock($handle, LOCK_EX)) {
        $ok = (@fwrite($handle, $json . "\n") !== false);
        @fflush($handle);
        @flock($handle, LOCK_UN);
    }

    @fclose($handle);
    return $ok;
}
