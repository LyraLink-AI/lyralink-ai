<?php
require_once __DIR__ . '/../api/session_boot.php';
/**
 * Text-to-Speech proxy endpoint.
 *
 * Tries providers in order:
 *   1. ElevenLabs  (ELEVENLABS_API_KEY)   — free tier 10 K chars/month
 *   2. OpenAI TTS  (OPENAI_API_KEY)       — $0.015 / 1 K chars, uses existing key
 *
 * Falls back to JSON {"fallback": true} so the browser can use SpeechSynthesis.
 *
 * POST /api/tts.php
 * Body (JSON): { "text": "..." }
 * Success: audio/mpeg binary
 * Failure: application/json { "error": "...", "fallback": true }
 */
require_once __DIR__ . '/security.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

lyra_session_boot();

$providerErrors = [];

function tts_env_float(string $key, float $default, float $min, float $max): float {
    $raw = trim((string)api_get_secret($key, ''));
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $value = (float)$raw;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function tts_env_bool(string $key, bool $default = true): bool {
    $raw = strtolower(trim((string)api_get_secret($key, $default ? '1' : '0')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function tts_humanize_text(string $text): string {
    $text = preg_replace('/https?:\/\/\S+/i', ' link ', $text);
    $text = preg_replace('/\b([A-Z]{2,})(\d{0,3})\b/', '$1 $2', $text);
    $text = preg_replace('/\s+([,;:])/', '$1', $text);
    $text = preg_replace('/\s{2,}/', ' ', $text);
    return trim((string)$text);
}

function tts_detect_edge_tts_bin(): ?string {
    $configured = trim((string)api_get_secret('EDGE_TTS_BIN', ''));
    if ($configured !== '' && is_executable($configured)) {
        return $configured;
    }

    $workspaceRoot = dirname(__DIR__);
    $candidate = $workspaceRoot . '/.venv/bin/edge-tts';
    if (is_executable($candidate)) {
        return $candidate;
    }

    $which = trim((string)shell_exec('command -v edge-tts 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    return null;
}

function edge_tts_request(string $edgeTtsBin, string $text, string $voice): array {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lyra_tts_');
    if ($tmpFile === false) {
        return ['ok' => false, 'error' => 'temp_file_failed'];
    }

    $targetFile = $tmpFile . '.mp3';
    @rename($tmpFile, $targetFile);
    $rate = trim((string)api_get_secret('EDGE_TTS_RATE', '+0%'));
    $pitch = trim((string)api_get_secret('EDGE_TTS_PITCH', '+0Hz'));
    $volume = trim((string)api_get_secret('EDGE_TTS_VOLUME', '+0%'));

    $cmd = escapeshellarg($edgeTtsBin)
        . ' --voice ' . escapeshellarg($voice)
        . ' --text ' . escapeshellarg($text)
        . ' --rate ' . escapeshellarg($rate)
        . ' --pitch ' . escapeshellarg($pitch)
        . ' --volume ' . escapeshellarg($volume)
        . ' --write-media ' . escapeshellarg($targetFile)
        . ' 2>&1';

    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);

    if ($code !== 0 || !is_file($targetFile) || filesize($targetFile) <= 0) {
        @unlink($targetFile);
        return [
            'ok' => false,
            'exit_code' => $code,
            'response_preview' => substr(trim(implode("\n", $output)), 0, 220),
        ];
    }

    $audio = @file_get_contents($targetFile);
    @unlink($targetFile);
    if ($audio === false || $audio === '') {
        return ['ok' => false, 'error' => 'empty_audio'];
    }

    return ['ok' => true, 'audio' => $audio];
}

function remote_tts_request(string $url, string $apiKey, string $text, string $voice): array {
    $payload = json_encode([
        'text' => $text,
        'voice' => $voice,
        'format' => 'mp3',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Accept: audio/mpeg, audio/wav, audio/*, application/octet-stream',
    ];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $audio = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $isAudio = $contentType !== '' && str_starts_with(strtolower($contentType), 'audio/');

    return [
        'ok' => ($audio !== false && $httpCode >= 200 && $httpCode < 300 && $isAudio),
        'audio' => $audio !== false ? $audio : null,
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'content_type' => $contentType !== '' ? $contentType : null,
        'response_preview' => is_string($audio) ? substr($audio, 0, 220) : null,
    ];
}

/**
 * Execute an ElevenLabs TTS request for a specific voice.
 *
 * @return array{ok:bool,audio:?string,http_code:int,curl_error:?string,response_preview:?string}
 */
function elevenlabs_tts_request(string $apiKey, string $voiceId, string $text): array {
    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128';
    $modelId = trim((string)api_get_secret('ELEVENLABS_MODEL_ID', 'eleven_turbo_v2_5'));
    if ($modelId === '') {
        $modelId = 'eleven_turbo_v2_5';
    }

    $stability = tts_env_float('ELEVENLABS_STABILITY', 0.38, 0.0, 1.0);
    $similarity = tts_env_float('ELEVENLABS_SIMILARITY_BOOST', 0.88, 0.0, 1.0);
    $style = tts_env_float('ELEVENLABS_STYLE', 0.18, 0.0, 1.0);
    $speakerBoost = tts_env_bool('ELEVENLABS_SPEAKER_BOOST', true);

    $payload = json_encode([
        'text' => $text,
        'model_id' => $modelId,
        'voice_settings' => [
            'stability' => $stability,
            'similarity_boost' => $similarity,
            'style' => $style,
            'use_speaker_boost' => $speakerBoost,
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $audio = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => ($audio !== false && $httpCode === 200),
        'audio' => $audio !== false ? $audio : null,
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'response_preview' => is_string($audio) ? substr($audio, 0, 220) : null,
    ];
}

/**
 * Find a free-compatible voice ID from the account voice list.
 * Prefers non-library voices because library voices require paid plans.
 */
function elevenlabs_find_non_library_voice(string $apiKey, ?string $excludeVoiceId = null): ?string {
    $ch = curl_init('https://api.elevenlabs.io/v1/voices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['voices']) || !is_array($decoded['voices'])) {
        return null;
    }

    $preferredCategories = ['cloned', 'generated', 'premade'];

    foreach ($preferredCategories as $category) {
        foreach ($decoded['voices'] as $voice) {
            if (!is_array($voice)) {
                continue;
            }
            $voiceId = (string)($voice['voice_id'] ?? '');
            $voiceCategory = strtolower((string)($voice['category'] ?? ''));
            if ($voiceId === '' || $voiceId === $excludeVoiceId) {
                continue;
            }
            if ($voiceCategory === 'library') {
                continue;
            }
            if ($voiceCategory === $category) {
                return $voiceId;
            }
        }
    }

    foreach ($decoded['voices'] as $voice) {
        if (!is_array($voice)) {
            continue;
        }
        $voiceId = (string)($voice['voice_id'] ?? '');
        $voiceCategory = strtolower((string)($voice['category'] ?? ''));
        if ($voiceId === '' || $voiceId === $excludeVoiceId || $voiceCategory === 'library') {
            continue;
        }
        return $voiceId;
    }

    return null;
}

// ── Input ────────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$probe = (bool)($body['probe'] ?? false);

$remoteTtsUrl = trim((string)api_get_secret('REMOTE_TTS_URL', ''));
$remoteTtsKey = trim((string)api_get_secret('REMOTE_TTS_API_KEY', ''));
$remoteTtsVoice = trim((string)api_get_secret('REMOTE_TTS_VOICE', 'lyralink'));
$ttsEnableElevenlabs = tts_env_bool('TTS_ENABLE_ELEVENLABS', false);
$elevenKey = $ttsEnableElevenlabs ? api_get_secret('ELEVENLABS_API_KEY', '') : '';
$openAiKey = api_get_secret('OPENAI_API_KEY', '');
$edgeTtsBin = tts_detect_edge_tts_bin();
$edgeTtsVoice = trim((string)api_get_secret('EDGE_TTS_VOICE', 'en-US-AriaNeural'));

if ($probe) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'configured' => ($remoteTtsUrl !== '' || $elevenKey !== '' || $openAiKey !== '' || $edgeTtsBin !== null),
        'human_voice' => ($remoteTtsUrl !== '' || $elevenKey !== '' || $openAiKey !== '' || $edgeTtsBin !== null),
        'providers' => [
            'remote' => $remoteTtsUrl !== '',
            'elevenlabs' => ($ttsEnableElevenlabs && $elevenKey !== ''),
            'openai' => $openAiKey !== '',
            'edge_tts' => $edgeTtsBin !== null,
        ],
    ]);
    exit;
}

$text = is_string($body['text'] ?? null) ? trim($body['text']) : '';

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No text provided', 'fallback' => true]);
    exit;
}

// Hard cap — prevent abuse even for logged-in users
$text = mb_substr($text, 0, 2500);

// ── Strip markdown to improve pronunciation ───────────────────────────────────
$text = preg_replace('/```[\s\S]*?```/', ' code block ', $text);
$text = preg_replace('/`([^`]+)`/', '$1', $text);
$text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
$text = preg_replace('/\*([^*]+)\*/', '$1', $text);
$text = preg_replace('/#+\s/', '', $text);
$text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
$text = preg_replace('/\n{2,}/', '. ', $text);
$text = str_replace("\n", ' ', $text);
$text = trim($text);
$text = tts_humanize_text($text);

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Empty text after processing', 'fallback' => true]);
    exit;
}

if ($remoteTtsUrl !== '') {
    $remoteTry = remote_tts_request($remoteTtsUrl, $remoteTtsKey, $text, $remoteTtsVoice);
    if ($remoteTry['ok']) {
        header('Content-Type: ' . ($remoteTry['content_type'] ?: 'audio/mpeg'));
        header('Cache-Control: no-store');
        header('X-TTS-Provider: remote');
        echo (string)$remoteTry['audio'];
        exit;
    }

    $providerErrors['remote'] = [
        'http_code' => $remoteTry['http_code'],
        'curl_error' => $remoteTry['curl_error'],
        'content_type' => $remoteTry['content_type'],
        'response_preview' => $remoteTry['response_preview'],
    ];
} else {
    $providerErrors['remote'] = ['error' => 'missing REMOTE_TTS_URL'];
}

// ── ElevenLabs ───────────────────────────────────────────────────────────────

if ($elevenKey !== '') {
    $voiceId = api_get_secret('ELEVENLABS_VOICE_ID', '');
    if ($voiceId === '') {
        $voiceId = elevenlabs_find_non_library_voice($elevenKey, null) ?? '21m00Tcm4TlvDq8ikWAM';
    }

    $primaryTry = elevenlabs_tts_request($elevenKey, $voiceId, $text);
    if ($primaryTry['ok']) {
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-store');
        header('X-TTS-Provider: elevenlabs');
        header('X-TTS-Voice: ' . $voiceId);
        echo (string)$primaryTry['audio'];
        exit;
    }

    $responsePreview = strtolower((string)($primaryTry['response_preview'] ?? ''));
    $isPaidLibraryVoice = ($primaryTry['http_code'] === 402)
        && (str_contains($responsePreview, 'paid_plan_required') || str_contains($responsePreview, 'library voices'));

    if ($isPaidLibraryVoice) {
        $fallbackVoiceId = elevenlabs_find_non_library_voice($elevenKey, $voiceId);
        if ($fallbackVoiceId !== null) {
            $fallbackTry = elevenlabs_tts_request($elevenKey, $fallbackVoiceId, $text);
            if ($fallbackTry['ok']) {
                header('Content-Type: audio/mpeg');
                header('Cache-Control: no-store');
                header('X-TTS-Provider: elevenlabs');
                header('X-TTS-Voice: ' . $fallbackVoiceId);
                echo (string)$fallbackTry['audio'];
                exit;
            }
            $providerErrors['elevenlabs'] = [
                'http_code' => $fallbackTry['http_code'],
                'curl_error' => $fallbackTry['curl_error'],
                'response_preview' => $fallbackTry['response_preview'],
                'note' => 'Primary voice appears paid-only. Retried with non-library voice but retry failed.',
            ];
        } else {
            $providerErrors['elevenlabs'] = [
                'http_code' => $primaryTry['http_code'],
                'curl_error' => $primaryTry['curl_error'],
                'response_preview' => $primaryTry['response_preview'],
                'note' => 'Primary voice appears paid-only and no non-library voice was found on this account.',
            ];
        }
    } else {
        $providerErrors['elevenlabs'] = [
            'http_code' => $primaryTry['http_code'],
            'curl_error' => $primaryTry['curl_error'],
            'response_preview' => $primaryTry['response_preview'],
        ];
    }
    // ElevenLabs failed — fall through to OpenAI
} else {
    $providerErrors['elevenlabs'] = $ttsEnableElevenlabs
        ? ['error' => 'missing ELEVENLABS_API_KEY']
        : ['error' => 'disabled_by_policy'];
}

// ── OpenAI TTS ───────────────────────────────────────────────────────────────

if ($openAiKey !== '') {
    $voice   = api_get_secret('OPENAI_TTS_VOICE', 'nova');   // nova is clear & natural  
    $openAiTtsModel = trim((string)api_get_secret('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'));
    if ($openAiTtsModel === '') {
        $openAiTtsModel = 'gpt-4o-mini-tts';
    }
    $payload = json_encode([
        'model' => $openAiTtsModel,
        'input' => $text,
        'voice' => $voice,
        'format' => 'mp3',
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $openAiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $audio    = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($audio !== false && $httpCode === 200) {
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-store');
        header('X-TTS-Provider: openai');
        header('X-TTS-Voice: ' . $voice);
        echo $audio;
        exit;
    }
    $providerErrors['openai'] = [
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: null,
        'response_preview' => is_string($audio) ? substr($audio, 0, 220) : null,
    ];
} else {
    $providerErrors['openai'] = ['error' => 'missing OPENAI_API_KEY'];
}

// ── Edge TTS (no API key) ───────────────────────────────────────────────────
if ($edgeTtsBin !== null) {
    $edgeTry = edge_tts_request($edgeTtsBin, $text, $edgeTtsVoice !== '' ? $edgeTtsVoice : 'en-US-AriaNeural');
    if (!empty($edgeTry['ok'])) {
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-store');
        header('X-TTS-Provider: edge_tts');
        header('X-TTS-Voice: ' . ($edgeTtsVoice !== '' ? $edgeTtsVoice : 'en-US-AriaNeural'));
        echo (string)$edgeTry['audio'];
        exit;
    }

    $providerErrors['edge_tts'] = [
        'error' => $edgeTry['error'] ?? null,
        'exit_code' => $edgeTry['exit_code'] ?? null,
        'response_preview' => $edgeTry['response_preview'] ?? null,
    ];
} else {
    $providerErrors['edge_tts'] = ['error' => 'edge_tts_not_installed'];
}

// ── Provider unavailable or provider request failed ──────────────────────────
header('Content-Type: application/json');
$hasAnyProviderKey = ($remoteTtsUrl !== '' || $elevenKey !== '' || $openAiKey !== '' || $edgeTtsBin !== null);

if (!$hasAnyProviderKey) {
    http_response_code(503);
    echo json_encode([
        'error' => 'No TTS provider configured',
        'fallback' => true,
        'provider_errors' => $providerErrors,
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'error' => 'TTS provider request failed',
    'fallback' => true,
    'provider_errors' => $providerErrors,
]);
