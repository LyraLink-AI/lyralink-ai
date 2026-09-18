<?php

function chat_attachment_human_size(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

function chat_attachment_clean_text(string $text, int $maxChars = 12000): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars) . "\n…[truncated]";
    }
    return $text;
}

function chat_attachment_extract_docx_text(string $path): string {
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();
    if ($xml === '') {
        return '';
    }
    $xml = str_replace(['</w:p>', '</w:tr>', '</w:tc>', '<w:tab/>'], ["\n", "\n", "\t", "\t"], $xml);
    return chat_attachment_clean_text(strip_tags($xml));
}

function chat_attachment_extract_pdf_text(string $path): string {
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }
    $text = '';
    if (preg_match_all('/\(([^()]*)\)/s', $raw, $matches)) {
        $text = implode("\n", $matches[1]);
    }
    if (mb_strlen(trim($text)) < 80 && preg_match_all('/[A-Za-z0-9][A-Za-z0-9\s,.;:\-_\/%()]{12,}/', $raw, $matches2)) {
        $text .= "\n" . implode("\n", array_slice(array_unique($matches2[0]), 0, 120));
    }
    return chat_attachment_clean_text($text);
}

function chat_attachment_extract_text(string $path, string $mime, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $raw = @file_get_contents($path);

    if (in_array($ext, ['txt', 'md', 'markdown', 'csv', 'json', 'log', 'js', 'ts', 'py', 'php', 'html', 'css', 'xml', 'svg'], true)
        || str_starts_with($mime, 'text/')
        || $mime === 'application/json') {
        if ($raw === false) {
            return '';
        }
        if ($ext === 'json' || $mime === 'application/json') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $raw;
            }
        }
        if ($ext === 'svg') {
            $raw = strip_tags($raw);
        }
        return chat_attachment_clean_text($raw);
    }

    if ($ext === 'docx') {
        return chat_attachment_extract_docx_text($path);
    }

    if ($ext === 'pdf' || $mime === 'application/pdf') {
        return chat_attachment_extract_pdf_text($path);
    }

    return '';
}

function chat_attachment_build_data_url(string $path, string $mime, int $maxBytes = 10485760): string {
    $size = @filesize($path);
    if (!$size || $size > $maxBytes) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function chat_reply_indicates_no_vision(?string $reply): bool {
    if (!is_string($reply) || trim($reply) === '') {
        return false;
    }
    $haystack = strtolower($reply);
    $signals = [
        "can't see the image",
        'cannot see the image',
        "can't view images",
        'cannot view images',
        'unable to view images',
        'describe the image to me',
        'if you describe the image',
        'i cannot directly view',
        'i can\'t directly view',
        'i can’t directly view',
    ];
    foreach ($signals as $signal) {
        if (str_contains($haystack, $signal)) {
            return true;
        }
    }
    return false;
}

function chat_user_requested_image_generation(string $message): bool {
    $text = strtolower(trim($message));
    if ($text === '') {
        return false;
    }
    $patterns = [
        '/\b(generate|create|make|render|draw|design|illustrate)\b.{0,24}\b(image|picture|photo|art|artwork|logo|poster|icon|wallpaper|portrait|scene)\b/i',
        '/\b(image|picture|photo|art|artwork|logo|poster|icon|wallpaper|portrait|scene)\b.{0,24}\b(generate|create|make|render|draw|design|illustrate)\b/i',
        '/^\s*\/image\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    return false;
}

function chat_extract_image_prompt(string $message): string {
    $prompt = trim($message);
    $prompt = preg_replace('/^\s*\/image\s*/i', '', $prompt) ?? $prompt;
    $prompt = preg_replace('/^(please\s+)?(generate|create|make|render|draw|design|illustrate)\s+(me\s+)?(an?\s+)?/i', '', $prompt) ?? $prompt;
    $prompt = preg_replace('/\b(image|picture|photo|art|artwork|logo|poster|icon|wallpaper)\b\s*(of|for)?\s*/i', '', $prompt) ?? $prompt;
    $prompt = trim($prompt, " \t\n\r\0\x0B:.-");
    if ($prompt === '') {
        $prompt = 'A polished cinematic illustration with vivid lighting and high detail';
    }
    return $prompt;
}

function chat_is_safe_image_prompt(string $prompt, array $analysis): bool {
    $blockedFlags = ['dangerous_illegal', 'self_harm', 'minors_sexual', 'fraud_impersonation', 'prompt_injection', 'cross_user_data'];
    foreach ($blockedFlags as $flag) {
        if (in_array($flag, $analysis['flags'] ?? [], true)) {
            return false;
        }
    }

    return true;
}

function chat_generated_image_url(string $prompt): string {
    $seed = random_int(10000, 99999999);
    return 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
        . '?width=1024&height=1024&seed=' . $seed . '&nologo=true&private=true&safe=true';
}

function chat_generated_image_reply(string $prompt): array {
    $url = chat_generated_image_url($prompt);
    $safePrompt = htmlspecialchars(mb_substr($prompt, 0, 140), ENT_QUOTES, 'UTF-8');
    $downloadUrl = '/api/image_download.php?url=' . rawurlencode($url) . '&prompt=' . rawurlencode($prompt);
    $reply = '<figure class="generated-image-card">'
        . '<img class="generated-image-preview" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $safePrompt . '" loading="lazy" referrerpolicy="no-referrer">'
        . '<figcaption class="generated-image-caption">'
        . '<div class="generated-image-title">' . $safePrompt . '</div>'
        . '<div class="generated-image-meta">Made by Lyralink</div>'
        . '<div class="generated-image-actions">'
        . '<a class="generated-image-download" href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Download image</a>'
        . '</div>'
        . '</figcaption>'
        . '</figure>'
        . "\n\nIf you want a different style, ask for a revision like more realistic, more colorful, or logo version.";
    return [
        'reply' => $reply,
        'generated_image_url' => $url,
        'generated_image_download_url' => $downloadUrl,
        'image_prompt' => $prompt,
        'made_by' => 'Lyralink',
    ];
}

function chat_attachment_payload(array $file): array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('No valid attachment uploaded.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachment must be smaller than 10 MB.');
    }

    $name = basename((string)($file['name'] ?? 'attachment'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $file['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    $allowedExts = ['txt','md','markdown','csv','json','pdf','docx','png','jpg','jpeg','gif','webp','svg','js','ts','py','php','html','css','xml','log'];
    $allowedMimes = ['application/pdf','application/json','text/plain','text/csv','text/markdown'];
    $isImage = str_starts_with($mime, 'image/') && !in_array($ext, ['svg'], true);

    if (!$isImage && !in_array($ext, $allowedExts, true) && !in_array($mime, $allowedMimes, true) && !str_starts_with($mime, 'text/')) {
        throw new RuntimeException('Unsupported attachment type. Use an image, text, PDF, JSON, CSV, DOCX, or code file.');
    }

    $text = $isImage ? '' : chat_attachment_extract_text($file['tmp_name'], $mime, $name);
    return [
        'name' => $name,
        'mime' => $mime,
        'size_bytes' => $size,
        'type' => $isImage ? 'image' : 'document',
        'text' => $text,
        'data_url' => $isImage ? chat_attachment_build_data_url($file['tmp_name'], $mime) : '',
        'summary' => $name . ' (' . $mime . ', ' . chat_attachment_human_size($size) . ')',
    ];
}

function chat_apply_attachment_to_messages(array $messages, array $attachment, string $provider): array {
    $lastUserIndex = null;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') {
            $lastUserIndex = $i;
            break;
        }
    }
    if ($lastUserIndex === null) {
        $messages[] = ['role' => 'user', 'content' => 'Please analyze this attachment.'];
        $lastUserIndex = count($messages) - 1;
    }

    $baseText = trim(llm_message_content_text($messages[$lastUserIndex]['content'] ?? ''));
    if ($baseText === '') {
        $baseText = 'Please analyze this attachment.';
    }

    $attachmentNote = "Attached file details:\n- Name: " . ($attachment['name'] ?? 'attachment')
        . "\n- MIME type: " . ($attachment['mime'] ?? 'unknown')
        . "\n- Size: " . chat_attachment_human_size((int)($attachment['size_bytes'] ?? 0));

    if (($attachment['type'] ?? '') === 'image') {
        $instruction = "\n\nScan the uploaded image carefully and answer using what is visible in it. Mention any important objects, text, layout, or colors that matter.";
        if (in_array($provider, ['local', 'openai', 'openrouter'], true) && !empty($attachment['data_url'])) {
            $messages[$lastUserIndex]['content'] = [
                ['type' => 'text', 'text' => trim($baseText . "\n\n" . $attachmentNote . $instruction)],
                ['type' => 'image_url', 'image_url' => ['url' => $attachment['data_url']]],
            ];
            return $messages;
        }
        $messages[$lastUserIndex]['content'] = trim($baseText . "\n\n" . $attachmentNote . $instruction . "\n\nNote: visual analysis is limited on the current model, so rely on any visible metadata only.");
        return $messages;
    }

    $docText = trim((string)($attachment['text'] ?? ''));
    if ($docText === '') {
        $docText = '[No extractable text was found in the uploaded file.]';
    }
    $messages[$lastUserIndex]['content'] = trim($baseText . "\n\n" . $attachmentNote . "\n\nUse the extracted document contents below when answering:\n" . $docText);
    return $messages;
}

function chat_attachment_model_for_provider(string $provider, string $attachmentType, string $plan): string {
    $provider = strtolower($provider);
    if ($attachmentType !== 'image') {
        return llm_first_valid_model_for_plan($provider, $plan);
    }

    if ($provider === 'local') {
        $explicitLocalImageModel = trim((string)api_get_secret('LOCAL_LLM_IMAGE_MODEL', ''));
        if ($explicitLocalImageModel !== '') {
            return $explicitLocalImageModel;
        }

        $remoteImageOffloadEnabled = api_get_secret('REMOTE_LLM_IMAGE_OFFLOAD', '1') === '1'
            && llm_provider_available('remote');
        if ($remoteImageOffloadEnabled) {
            return llm_first_valid_model_for_plan('local', $plan);
        }

        return '';
    }

    return match ($provider) {
        'openrouter' => trim(api_get_secret('OPENROUTER_IMAGE_MODEL', 'openai/gpt-4o-mini')) ?: llm_first_valid_model_for_plan('openrouter', $plan),
        'openai' => trim(api_get_secret('OPENAI_IMAGE_MODEL', api_get_secret('OPENAI_MODEL', 'gpt-4o-mini'))) ?: 'gpt-4o-mini',
        'groq' => trim(api_get_secret('GROQ_IMAGE_MODEL', '')) ?: llm_first_valid_model_for_plan('groq', $plan),
        default => llm_first_valid_model_for_plan($provider, $plan),
    };
}

function chat_attachment_select_image_route(string $plan, string $preferredProvider = 'local'): array {
    $preferred = strtolower(trim($preferredProvider));
    if ($preferred === 'hermes') {
        $preferred = 'local';
    }

    $remoteImageOffloadEnabled = api_get_secret('REMOTE_LLM_IMAGE_OFFLOAD', '1') === '1'
        && llm_provider_available('remote');

    $orderedProviders = array_values(array_unique(array_filter([
        $preferred,
        'local',
        'openrouter',
        'openai',
    ])));

    foreach ($orderedProviders as $provider) {
        if (!llm_provider_available($provider)) {
            continue;
        }

        $model = chat_attachment_model_for_provider($provider, 'image', $plan);
        if ($model === '') {
            continue;
        }

        if ($provider === 'local') {
            $modelLower = strtolower($model);
            $explicitLocalVisionModel = trim((string)api_get_secret('LOCAL_LLM_IMAGE_MODEL', ''));
            $looksVisionCapable = $explicitLocalVisionModel !== ''
                || str_contains($modelLower, 'vision')
                || str_contains($modelLower, 'vl')
                || str_contains($modelLower, 'llava')
                || str_contains($modelLower, 'bakllava')
                || str_contains($modelLower, 'minicpm-v');
            if (!$looksVisionCapable && !$remoteImageOffloadEnabled) {
                continue;
            }

            return [
                'provider' => 'local',
                'model' => $model,
                'supports_vision' => ($looksVisionCapable || $remoteImageOffloadEnabled),
            ];
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'supports_vision' => true,
        ];
    }

    return [
        'provider' => 'local',
        'model' => chat_attachment_model_for_provider('local', 'image', $plan),
        'supports_vision' => false,
    ];
}

