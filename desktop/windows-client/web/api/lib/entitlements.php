<?php

if (!function_exists('entitlement_default_model_for_provider')) {
    function entitlement_default_model_for_provider(string $provider): string {
        $provider = strtolower(trim($provider));
        $localModel = trim((string)api_get_secret('LOCAL_LLM_MODEL', 'lyralink-auto-canary:latest'));
        $openRouterModel = trim((string)api_get_secret('OPENROUTER_MODEL', 'openclaw/openclaw-7b'));
        $openAiModel = trim((string)api_get_secret('OPENAI_MODEL', 'gpt-4o-mini'));

        if ($provider === 'local' || $provider === 'hermes') {
            return $localModel !== '' ? $localModel : 'lyralink-auto-canary:latest';
        }

        if ($provider === 'openrouter') {
            return $openRouterModel !== '' ? $openRouterModel : 'openclaw/openclaw-7b';
        }
        if ($provider === 'openai') {
            return $openAiModel !== '' ? $openAiModel : 'gpt-4o-mini';
        }
        return 'llama-3.1-8b-instant';
    }
}

if (!function_exists('entitlement_chat_plan_limits')) {
    function entitlement_chat_plan_limits(string $providerEnv, string $modelEnv = ''): array {
        $providerEnv = strtolower(trim($providerEnv));
        if ($providerEnv === 'hermes') {
            $providerEnv = 'local';
        }
        if (!in_array($providerEnv, ['local', 'groq', 'openrouter', 'openai'], true)) {
            $providerEnv = 'local';
        }

        $basePlanModel = trim($modelEnv) !== '' ? trim($modelEnv) : entitlement_default_model_for_provider($providerEnv);
        $enterpriseModel = ($providerEnv === 'groq' && trim($modelEnv) === '')
            ? 'llama-3.3-70b-versatile'
            : $basePlanModel;
        $freeTokens = max(1, (int)api_get_secret('CHAT_TOKENS_FREE', '1200000'));
        $basicTokens = max($freeTokens, (int)api_get_secret('CHAT_TOKENS_BASIC', '2200000'));
        $proTokens = max($basicTokens, (int)api_get_secret('CHAT_TOKENS_PRO', '99999999'));
        $enterpriseTokens = max($proTokens, (int)api_get_secret('CHAT_TOKENS_ENTERPRISE', '99999999'));

        return [
            'free' => ['tokens' => $freeTokens, 'provider' => $providerEnv, 'model' => $basePlanModel, 'unlimited' => false],
            'basic' => ['tokens' => $basicTokens, 'provider' => $providerEnv, 'model' => $basePlanModel, 'unlimited' => false],
            'pro' => ['tokens' => $proTokens, 'provider' => $providerEnv, 'model' => $basePlanModel, 'unlimited' => true],
            'enterprise' => ['tokens' => $enterpriseTokens, 'provider' => $providerEnv, 'model' => $enterpriseModel, 'unlimited' => true],
        ];
    }
}

if (!function_exists('entitlement_chat_token_limit_for_plan')) {
    function entitlement_chat_token_limit_for_plan(array $planConfig): int {
        $tokens = isset($planConfig['tokens']) ? (int)$planConfig['tokens'] : 0;
        if ($tokens > 0) {
            return $tokens;
        }
        // Backward compatibility if an older plan config still uses "messages".
        $messages = isset($planConfig['messages']) ? (int)$planConfig['messages'] : 0;
        if ($messages <= 0) {
            return 1200000;
        }
        return $messages * 800;
    }
}

if (!function_exists('entitlement_prepare_chat_usage')) {
    function entitlement_prepare_chat_usage(mysqli $db, int $userId, array $planLimits): array {
        $db->query('ALTER TABLE users ADD COLUMN IF NOT EXISTS token_count INT UNSIGNED NOT NULL DEFAULT 0');
        $db->query('ALTER TABLE users ADD COLUMN IF NOT EXISTS token_reset_at DATE NULL');

        $result = [
            'ok' => true,
            'error' => null,
            'plan' => 'free',
            'provider' => $planLimits['free']['provider'] ?? 'local',
            'model' => $planLimits['free']['model'] ?? entitlement_default_model_for_provider('local'),
            'credits' => 0,
            'token_count' => 0,
            'token_limit' => entitlement_chat_token_limit_for_plan($planLimits['free'] ?? []),
            'usage_token' => null,
        ];

        if ($userId <= 0) {
            return $result;
        }

        $stmt = $db->prepare('SELECT plan, credits, token_count, token_reset_at FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return $result;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$userData) {
            return $result;
        }

        if (($userData['token_reset_at'] ?? null) !== date('Y-m-01')) {
            $resetAt = date('Y-m-01');
            $stmtReset = $db->prepare('UPDATE users SET token_count = 0, token_reset_at = ? WHERE id = ?');
            if ($stmtReset) {
                $stmtReset->bind_param('si', $resetAt, $userId);
                $stmtReset->execute();
                $stmtReset->close();
                $userData['token_count'] = 0;
            }
        }

        $plan = (string)($userData['plan'] ?? 'free');
        $credits = (int)($userData['credits'] ?? 0);
        $tokenCount = (int)($userData['token_count'] ?? 0);
        $planConfig = $planLimits[$plan] ?? $planLimits['free'];
        $tokenLimit = entitlement_chat_token_limit_for_plan($planConfig);
        $unlimited = !empty($planConfig['unlimited']);
        $overLimit = !$unlimited && $tokenCount >= $tokenLimit;

        $usageToken = [
            'user_id' => $userId,
            'record' => false,
            'charge_credit' => false,
        ];

        if ($overLimit && $credits > 0) {
            $usageToken['record'] = true;
            $usageToken['charge_credit'] = true;
            $overLimit = false;
        } elseif (!$overLimit) {
            $usageToken['record'] = true;
        }

        if ($overLimit) {
            $result['ok'] = false;
            $result['error'] = [
                'reply' => null,
                'error' => 'limit_reached',
                'plan' => $plan,
                'used' => $tokenCount,
                'limit' => $tokenLimit,
                'credits' => $credits,
                'message' => "You've used all {$tokenLimit} tokens for this month on the " . ucfirst($plan) . ' plan. Upgrade your plan or top up with credits to keep chatting!',
            ];
            $result['plan'] = $plan;
            $result['provider'] = (string)($planConfig['provider'] ?? $result['provider']);
            $result['model'] = (string)($planConfig['model'] ?? $result['model']);
            $result['credits'] = $credits;
            $result['token_count'] = $tokenCount;
            $result['token_limit'] = $tokenLimit;
            return $result;
        }

        $result['plan'] = $plan;
        $result['provider'] = (string)($planConfig['provider'] ?? $result['provider']);
        $result['model'] = (string)($planConfig['model'] ?? $result['model']);
        $result['credits'] = $credits;
        $result['token_count'] = $tokenCount;
        $result['token_limit'] = $tokenLimit;
        $result['usage_token'] = $usageToken;
        return $result;
    }
}

if (!function_exists('entitlement_apply_chat_usage')) {
    function entitlement_chat_usage_pricing_config(): array {
        $inputTokensPerBlock = max(1, (int)api_get_secret('CHAT_USAGE_INPUT_TOKENS_PER_BLOCK', '1'));
        $usageUnitsPerBlock = max(1, (int)api_get_secret('CHAT_USAGE_UNITS_PER_BLOCK', '1'));
        $minUnitsPerRequest = max(0, (int)api_get_secret('CHAT_USAGE_MIN_UNITS_PER_REQUEST', '1'));
        $defaultMultiplierBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_DEFAULT_BPS', '10000'));
        $multiplier3bBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_3B_BPS', '7500'));
        $multiplier8bBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_8B_BPS', '10000'));
        $multiplier70bBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_70B_BPS', '18500'));
        $multiplierReasoningBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_REASONING_BPS', '21000'));
        $multiplierCodeBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CODE_BPS', '11500'));
        $multiplierCreativeBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CREATIVE_BPS', '10500'));
        $multiplierCloudBps = max(100, (int)api_get_secret('CHAT_USAGE_MULTIPLIER_CLOUD_BPS', '14000'));
        $autoDiscountBps = max(100, min(10000, (int)api_get_secret('CHAT_USAGE_AUTO_DISCOUNT_BPS', '9000')));

        return [
            'input_tokens_per_block' => $inputTokensPerBlock,
            'usage_units_per_block' => $usageUnitsPerBlock,
            'min_units_per_request' => $minUnitsPerRequest,
            'multiplier_default_bps' => $defaultMultiplierBps,
            'multiplier_3b_bps' => $multiplier3bBps,
            'multiplier_8b_bps' => $multiplier8bBps,
            'multiplier_70b_bps' => $multiplier70bBps,
            'multiplier_reasoning_bps' => $multiplierReasoningBps,
            'multiplier_code_bps' => $multiplierCodeBps,
            'multiplier_creative_bps' => $multiplierCreativeBps,
            'multiplier_cloud_bps' => $multiplierCloudBps,
            'auto_discount_bps' => $autoDiscountBps,
        ];
    }

    function entitlement_usage_multiplier_bps(string $provider, string $model, bool $autoRouted, array $pricing): int {
        $providerLower = strtolower(trim($provider));
        $modelLower = strtolower(trim($model));
        $multiplier = (int)($pricing['multiplier_default_bps'] ?? 10000);

        if (str_contains($modelLower, '70b')) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_70b_bps'] ?? 18500));
        } elseif (str_contains($modelLower, '8b')) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_8b_bps'] ?? 10000));
        } elseif (str_contains($modelLower, '3b')) {
            $multiplier = min($multiplier, (int)($pricing['multiplier_3b_bps'] ?? 7500));
        }

        if (str_contains($modelLower, 'reasoning')) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_reasoning_bps'] ?? 21000));
        }
        if (str_contains($modelLower, 'code')) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_code_bps'] ?? 11500));
        }
        if (str_contains($modelLower, 'creative')) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_creative_bps'] ?? 10500));
        }

        if (in_array($providerLower, ['openrouter', 'openai', 'groq'], true)) {
            $multiplier = max($multiplier, (int)($pricing['multiplier_cloud_bps'] ?? 14000));
        }

        if ($autoRouted) {
            $multiplier = (int)ceil(($multiplier * (int)($pricing['auto_discount_bps'] ?? 9000)) / 10000);
        }

        return max(100, $multiplier);
    }

    function entitlement_billable_chat_usage_units(int $modelTokenDelta, string $provider = 'local', string $model = '', bool $autoRouted = false): int {
        $modelTokenDelta = max(0, $modelTokenDelta);
        $pricing = entitlement_chat_usage_pricing_config();
        $inputTokensPerBlock = (int)$pricing['input_tokens_per_block'];
        $usageUnitsPerBlock = (int)$pricing['usage_units_per_block'];
        $minUnitsPerRequest = (int)$pricing['min_units_per_request'];
        $multiplierBps = entitlement_usage_multiplier_bps($provider, $model, $autoRouted, $pricing);

        if ($modelTokenDelta <= 0) {
            return $minUnitsPerRequest;
        }

        $scaled = (int)ceil(($modelTokenDelta * $usageUnitsPerBlock) / $inputTokensPerBlock);
        $scaledWithMultiplier = (int)ceil(($scaled * $multiplierBps) / 10000);
        return max($minUnitsPerRequest, $scaledWithMultiplier);
    }

    function entitlement_apply_chat_usage(mysqli $db, ?array $usageToken, int $tokenDelta = 0): int {
        if (!$usageToken || empty($usageToken['record'])) {
            return 0;
        }
        $userId = (int)($usageToken['user_id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        if (!empty($usageToken['charge_credit'])) {
            $stmtCredit = $db->prepare('UPDATE users SET credits = credits - 1 WHERE id = ? AND credits > 0');
            if ($stmtCredit) {
                $stmtCredit->bind_param('i', $userId);
                $stmtCredit->execute();
                $stmtCredit->close();
            }
        }

        $provider = (string)($usageToken['provider'] ?? 'local');
        $model = (string)($usageToken['model'] ?? '');
        $autoRouted = !empty($usageToken['auto_routed']);
        $tokenDelta = entitlement_billable_chat_usage_units($tokenDelta, $provider, $model, $autoRouted);
        $stmtInc = $db->prepare('UPDATE users SET token_count = token_count + ? WHERE id = ?');
        if ($stmtInc) {
            $stmtInc->bind_param('ii', $tokenDelta, $userId);
            $stmtInc->execute();
            $stmtInc->close();
        }

        return $tokenDelta;
    }
}
