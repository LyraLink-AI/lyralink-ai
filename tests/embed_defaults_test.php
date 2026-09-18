<?php

function api_get_secret(string $key, string $default = ''): string {
    return $default;
}

require __DIR__ . '/../api/dataset_search.php';

if (!function_exists('dataset_embed_model_default')) {
    fwrite(STDERR, "dataset_embed_model_default function missing\n");
    exit(1);
}

$embedDefault = dataset_embed_model_default();
if ($embedDefault !== 'lyralink-fast:latest') {
    fwrite(STDERR, "Expected embed default model to be lyralink-fast:latest, got {$embedDefault}\n");
    exit(1);
}

$envPath = __DIR__ . '/../.env';
$envContent = @file_get_contents($envPath);
if (!is_string($envContent) || $envContent === '') {
    fwrite(STDERR, "Unable to read .env for regression assertions\n");
    exit(1);
}
if (strpos($envContent, "MODEL_ROUTER_FALLBACK=lyralink-fast:latest") === false) {
    fwrite(STDERR, "Expected .env MODEL_ROUTER_FALLBACK to be lyralink-fast:latest\n");
    exit(1);
}
if (strpos($envContent, "LOCAL_LLM_EMBED_MODEL=lyralink-fast:latest") === false) {
    fwrite(STDERR, "Expected .env LOCAL_LLM_EMBED_MODEL to be lyralink-fast:latest\n");
    exit(1);
}

$desktopEnvPath = __DIR__ . '/../desktop/windows-client/web/.env';
$desktopEnvContent = @file_get_contents($desktopEnvPath);
if (!is_string($desktopEnvContent) || $desktopEnvContent === '') {
    fwrite(STDERR, "Unable to read desktop .env for regression assertions\n");
    exit(1);
}
if (strpos($desktopEnvContent, "LOCAL_LLM_EMBED_MODEL=lyralink-fast:latest") === false) {
    fwrite(STDERR, "Expected desktop .env LOCAL_LLM_EMBED_MODEL to be lyralink-fast:latest\n");
    exit(1);
}

$candidatePath = __DIR__ . '/../storage/model_training/artifacts/candidate_models.env';
$candidateContent = @file_get_contents($candidatePath);
if (!is_string($candidateContent) || $candidateContent === '') {
    fwrite(STDERR, "Unable to read candidate_models.env for regression assertions\n");
    exit(1);
}
if (strpos($candidateContent, "MODEL_ROUTER_FALLBACK=lyralink-fast:latest") === false) {
    fwrite(STDERR, "Expected candidate_models.env MODEL_ROUTER_FALLBACK to be lyralink-fast:latest\n");
    exit(1);
}

echo "embed default regression tests passed\n";
