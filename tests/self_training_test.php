<?php

function api_get_secret(string $key, string $default = ''): string {
    return $default;
}

require __DIR__ . '/../api/lib/chat/llm_routing.php';
require __DIR__ . '/../api/lib/chat/self_training.php';

$defaultLocalModel = llm_default_model('local');
if ($defaultLocalModel !== 'lyralink-auto-canary:latest') {
    fwrite(STDERR, "Expected local default model to prefer Lyra model, got {$defaultLocalModel}\n");
    exit(1);
}

$routerMap = chat_model_router_map();
if (($routerMap['default'] ?? '') !== 'lyralink-auto-canary:latest' || ($routerMap['fallback'] ?? '') !== 'lyralink-fast:latest') {
    fwrite(STDERR, "Expected router defaults to stay Lyra-first\n");
    exit(1);
}

$high = chat_self_training_compute_weight([
    'verification_passed' => true,
    'confidence_score' => 0.92,
    'reward_delta' => 6,
    'hallucination_risk' => 'low',
    'has_safety_flags' => false,
    'provider_error' => false,
]);

$low = chat_self_training_compute_weight([
    'verification_passed' => false,
    'confidence_score' => 0.2,
    'reward_delta' => -8,
    'hallucination_risk' => 'high',
    'has_safety_flags' => true,
    'provider_error' => true,
]);

if ($high <= $low) {
    fwrite(STDERR, "Expected high quality sample weight to exceed low quality sample weight\n");
    exit(1);
}

$tmpRoot = sys_get_temp_dir() . '/lyralink_self_train_test_' . bin2hex(random_bytes(6));
@mkdir($tmpRoot . '/storage/model_training', 0775, true);

$row = chat_self_training_build_row('How do I center a div?', 'Use flexbox with justify-content and align-items.', [
    'sample_weight' => 1.7,
    'trace_id' => 'trace_test',
]);

$ok = chat_self_training_append_row($row, $tmpRoot);
if (!$ok) {
    fwrite(STDERR, "Expected append_row to write runtime training example\n");
    exit(1);
}

$target = $tmpRoot . '/storage/model_training/runtime_feedback.jsonl';
if (!is_file($target) || filesize($target) <= 0) {
    fwrite(STDERR, "Expected runtime feedback file to contain data\n");
    exit(1);
}

$badRoot = sys_get_temp_dir() . '/lyralink_self_train_test_bad_' . bin2hex(random_bytes(6));
@mkdir($badRoot . '/storage/model_training', 0775, true);
$badRow = chat_self_training_build_row('Why is the site broken?', 'It seems like maybe it is probably because of issues with things and', [
    'sample_weight' => 1.0,
    'verification_passed' => false,
    'confidence_score' => 0.12,
    'hallucination_risk' => 'high',
    'finish_reason' => 'length',
    'provider_error' => false,
]);
$badOk = chat_self_training_append_row($badRow, $badRoot);
if ($badOk) {
    fwrite(STDERR, "Expected low-quality runtime sample to be skipped\n");
    exit(1);
}

$badTarget = $badRoot . '/storage/model_training/runtime_feedback.jsonl';
if (is_file($badTarget) && filesize($badTarget) > 0) {
    fwrite(STDERR, "Expected skipped runtime sample not to be written\n");
    exit(1);
}

echo "self training tests passed\n";
