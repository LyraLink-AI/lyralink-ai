<?php

if (!function_exists('finance_normalize_text')) {
    function finance_normalize_text(string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return $text;
    }
}

if (!function_exists('finance_last_user_message')) {
    function finance_last_user_message(array $messages): string {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $content = $messages[$i]['content'] ?? '';
                if (function_exists('llm_message_content_text')) {
                    return trim((string)llm_message_content_text($content));
                }
                if (is_array($content)) {
                    return trim((string)json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                return trim((string)$content);
            }
        }
        return '';
    }
}

if (!function_exists('finance_detect_request')) {
    function finance_detect_request(string $message): array {
        $normalized = finance_normalize_text($message);
        if ($normalized === '') {
            return ['matched' => false, 'intent' => null, 'confidence' => 'low', 'signals' => []];
        }

        $signals = [];
        $intent = null;
        $confidence = 'low';

        $calculatorPatterns = [
            'loan payment' => '/\b(monthly payment|loan payment|mortgage payment|amortization|amortisation|compound interest|cagr|apr|apy|npv|irr|future value|present value|break-even|breakeven|runway|burn rate|roi|roe|roa|debt-to-income|debt to income)\b/i',
            'equation request' => '/\bcalculate\b/i',
            'money with rate and term' => '/[$€£]\s?\d[\d,]*(?:\.\d+)?|\b\d+(?:\.\d+)?\s?%\b|\b\d+\s?(?:months|month|years|year)\b/i',
        ];
        $marketPatterns = [
            'quote' => '/\b(current price|stock price|quote|trading at|share price|price of|market cap|pe ratio|p\/e|p\/b|p\/s|ev\/ebitda|dividend yield|earnings yield)\b/i',
            'ticker' => '/\b(?:[A-Z]{1,5}|bitcoin|btc|ethereum|eth|nasdaq|s&p|dow|gold|silver|eurusd|gbpusd)\b/',
        ];
        $portfolioPatterns = [
            'portfolio' => '/\bportfolio|allocation|holdings|positions|sector exposure|diversification|drawdown|volatility|correlation|concentration risk\b/i',
        ];
        $economicsPatterns = [
            'economics' => '/\b(inflation|cpi|gdp|interest rates|federal reserve|fed|treasury yield|unemployment|money supply|consumer confidence|housing data)\b/i',
        ];
        $businessPatterns = [
            'business' => '/\b(mrr|arr|churn|ltv|cac|cash flow|gross margin|net margin|operating margin|profit margin|runway|burn rate|unit economics|revenue projection|forecast)\b/i',
        ];

        foreach ($calculatorPatterns as $label => $pattern) {
            if (preg_match($pattern, $message)) {
                $signals[] = $label;
            }
        }
        if (count($signals) >= 2) {
            $intent = 'calculation';
            $confidence = 'high';
        }

        if ($intent === null) {
            foreach ($marketPatterns as $label => $pattern) {
                if (preg_match($pattern, $message)) {
                    $signals[] = $label;
                }
            }
            if (in_array('quote', $signals, true)) {
                $intent = 'market_data';
                $confidence = 'medium';
            }
        }

        if ($intent === null) {
            foreach ($portfolioPatterns as $label => $pattern) {
                if (preg_match($pattern, $message)) {
                    $signals[] = $label;
                }
            }
            if (in_array('portfolio', $signals, true)) {
                $intent = 'portfolio';
                $confidence = 'medium';
            }
        }

        if ($intent === null) {
            foreach ($economicsPatterns as $label => $pattern) {
                if (preg_match($pattern, $message)) {
                    $signals[] = $label;
                }
            }
            if (in_array('economics', $signals, true)) {
                $intent = 'economics';
                $confidence = 'medium';
            }
        }

        if ($intent === null) {
            foreach ($businessPatterns as $label => $pattern) {
                if (preg_match($pattern, $message)) {
                    $signals[] = $label;
                }
            }
            if (in_array('business', $signals, true)) {
                $intent = 'business';
                $confidence = 'medium';
            }
        }

        return [
            'matched' => $intent !== null,
            'intent' => $intent,
            'confidence' => $confidence,
            'signals' => array_values(array_unique($signals)),
        ];
    }
}

if (!function_exists('finance_confidence_for_tool')) {
    function finance_confidence_for_tool(string $intent, bool $completeInputs, bool $hasLiveData): array {
        if ($intent === 'calculation' && $completeInputs) {
            return ['label' => 'high', 'reason' => 'Deterministic calculation with complete inputs.'];
        }
        if (($intent === 'market_data' || $intent === 'economics') && $hasLiveData) {
            return ['label' => 'medium', 'reason' => 'Data-backed answer with provider metadata.'];
        }
        return ['label' => 'low', 'reason' => 'Analysis depends on incomplete inputs or missing live data.'];
    }
}

if (!function_exists('finance_parse_number')) {
    function finance_parse_number(string $raw): ?float {
        $raw = trim(str_replace([',', '$', '€', '£'], '', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float)$raw;
    }
}

if (!function_exists('finance_extract_named_value')) {
    function finance_extract_named_value(string $message, array $labels): ?float {
        foreach ($labels as $label) {
            $pattern = '/(?:' . preg_quote($label, '/') . ')[^\d$€£-]*([$€£]?\s?-?\d[\d,]*(?:\.\d+)?)/i';
            if (preg_match($pattern, $message, $matches)) {
                return finance_parse_number((string)$matches[1]);
            }
        }
        return null;
    }
}

if (!function_exists('finance_extract_percent_after_labels')) {
    function finance_extract_percent_after_labels(string $message, array $labels): ?float {
        foreach ($labels as $label) {
            $pattern = '/(?:' . preg_quote($label, '/') . ')[^\d-]*(-?\d+(?:\.\d+)?)\s?%/i';
            if (preg_match($pattern, $message, $matches)) {
                return (float)$matches[1];
            }
        }
        return null;
    }
}

if (!function_exists('finance_extract_term_months')) {
    function finance_extract_term_months(string $message): ?int {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(months|month|mos|mo)\b/i', $message, $matches)) {
            return (int)round((float)$matches[1]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(years|year|yrs|yr)\b/i', $message, $matches)) {
            return (int)round((float)$matches[1] * 12);
        }
        return null;
    }
}

if (!function_exists('finance_extract_first_currency_amount')) {
    function finance_extract_first_currency_amount(string $message): ?float {
        if (preg_match('/([$€£]?\s?\d[\d,]*(?:\.\d+)?)/', $message, $matches)) {
            return finance_parse_number((string)$matches[1]);
        }
        return null;
    }
}

if (!function_exists('finance_extract_first_percent')) {
    function finance_extract_first_percent(string $message): ?float {
        if (preg_match('/(-?\d+(?:\.\d+)?)\s?%/', $message, $matches)) {
            return (float)$matches[1];
        }
        return null;
    }
}

if (!function_exists('finance_extract_ticker')) {
    function finance_extract_ticker(string $message): ?string {
        $map = [
            'bitcoin' => 'BTC-USD',
            'btc' => 'BTC-USD',
            'ethereum' => 'ETH-USD',
            'eth' => 'ETH-USD',
            'apple' => 'AAPL',
            'microsoft' => 'MSFT',
            'tesla' => 'TSLA',
            'amazon' => 'AMZN',
            'google' => 'GOOGL',
            'meta' => 'META',
            'nvidia' => 'NVDA',
        ];
        $normalized = finance_normalize_text($message);
        foreach ($map as $needle => $ticker) {
            if (str_contains($normalized, $needle)) {
                return $ticker;
            }
        }
        if (preg_match('/\b([A-Z]{1,5}(?:-[A-Z]{3})?)\b/', $message, $matches)) {
            return strtoupper((string)$matches[1]);
        }
        return null;
    }
}

if (!function_exists('finance_extract_loan_principal')) {
    function finance_extract_loan_principal(string $message): ?float {
        $patterns = [
            '/\b(?:loan|mortgage|principal|amount)\s+(?:of\s+)?([$€£]?\s?\d[\d,]*(?:\.\d+)?)/i',
            '/([$€£]\s?\d[\d,]*(?:\.\d+)?)\s+(?:loan|mortgage)\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return finance_parse_number((string)$matches[1]);
            }
        }
        return finance_extract_first_currency_amount($message);
    }
}

if (!function_exists('finance_calculate_loan_payment')) {
    function finance_calculate_loan_payment(float $principal, float $annualRatePercent, int $termMonths): array {
        $monthlyRate = $annualRatePercent / 100 / 12;
        if ($termMonths <= 0) {
            return ['ok' => false, 'error' => 'Term must be greater than zero months.'];
        }
        if (abs($monthlyRate) < 0.0000001) {
            $payment = $principal / $termMonths;
        } else {
            $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) / (pow(1 + $monthlyRate, $termMonths) - 1);
        }
        $totalPaid = $payment * $termMonths;
        $totalInterest = $totalPaid - $principal;
        return [
            'ok' => true,
            'formula' => 'PMT = P * [r(1+r)^n] / [(1+r)^n - 1]',
            'inputs' => [
                'principal' => round($principal, 2),
                'annual_rate_percent' => round($annualRatePercent, 6),
                'term_months' => $termMonths,
            ],
            'outputs' => [
                'monthly_payment' => round($payment, 2),
                'total_paid' => round($totalPaid, 2),
                'total_interest' => round($totalInterest, 2),
            ],
            'assumptions' => [
                'fixed_rate' => true,
                'payment_frequency' => 'monthly',
            ],
        ];
    }
}

if (!function_exists('finance_calculate_compound_interest')) {
    function finance_calculate_compound_interest(float $principal, float $annualRatePercent, float $years, int $timesPerYear = 12): array {
        if ($years < 0 || $timesPerYear <= 0) {
            return ['ok' => false, 'error' => 'Years and compounding frequency must be positive.'];
        }
        $rate = $annualRatePercent / 100;
        $futureValue = $principal * pow(1 + ($rate / $timesPerYear), $timesPerYear * $years);
        return [
            'ok' => true,
            'formula' => 'FV = P * (1 + r / n)^(n * t)',
            'inputs' => [
                'principal' => round($principal, 2),
                'annual_rate_percent' => round($annualRatePercent, 6),
                'years' => round($years, 4),
                'compounds_per_year' => $timesPerYear,
            ],
            'outputs' => [
                'future_value' => round($futureValue, 2),
                'interest_earned' => round($futureValue - $principal, 2),
            ],
            'assumptions' => [
                'additional_contributions' => 0,
            ],
        ];
    }
}

if (!function_exists('finance_calculate_cagr')) {
    function finance_calculate_cagr(float $beginValue, float $endValue, float $years): array {
        if ($beginValue <= 0 || $endValue <= 0 || $years <= 0) {
            return ['ok' => false, 'error' => 'Begin value, end value, and years must all be positive.'];
        }
        $cagr = (pow($endValue / $beginValue, 1 / $years) - 1) * 100;
        return [
            'ok' => true,
            'formula' => 'CAGR = (Ending / Beginning)^(1 / Years) - 1',
            'inputs' => [
                'begin_value' => round($beginValue, 2),
                'end_value' => round($endValue, 2),
                'years' => round($years, 4),
            ],
            'outputs' => [
                'cagr_percent' => round($cagr, 4),
            ],
            'assumptions' => [],
        ];
    }
}

if (!function_exists('finance_calculate_break_even')) {
    function finance_calculate_break_even(float $fixedCosts, float $pricePerUnit, float $variableCostPerUnit): array {
        $contributionMargin = $pricePerUnit - $variableCostPerUnit;
        if ($contributionMargin <= 0) {
            return ['ok' => false, 'error' => 'Price per unit must exceed variable cost per unit to break even.'];
        }
        $units = $fixedCosts / $contributionMargin;
        $revenue = $units * $pricePerUnit;
        return [
            'ok' => true,
            'formula' => 'Break-even units = Fixed Costs / (Price per Unit - Variable Cost per Unit)',
            'inputs' => [
                'fixed_costs' => round($fixedCosts, 2),
                'price_per_unit' => round($pricePerUnit, 2),
                'variable_cost_per_unit' => round($variableCostPerUnit, 2),
            ],
            'outputs' => [
                'break_even_units' => round($units, 2),
                'break_even_revenue' => round($revenue, 2),
                'contribution_margin_per_unit' => round($contributionMargin, 2),
            ],
            'assumptions' => [],
        ];
    }
}

if (!function_exists('finance_calculate_runway')) {
    function finance_calculate_runway(float $cash, float $monthlyBurn): array {
        if ($monthlyBurn <= 0) {
            return ['ok' => false, 'error' => 'Monthly burn must be greater than zero.'];
        }
        $months = $cash / $monthlyBurn;
        return [
            'ok' => true,
            'formula' => 'Runway months = Cash / Monthly Burn',
            'inputs' => [
                'cash' => round($cash, 2),
                'monthly_burn' => round($monthlyBurn, 2),
            ],
            'outputs' => [
                'runway_months' => round($months, 2),
                'runway_years' => round($months / 12, 2),
            ],
            'assumptions' => [
                'burn_rate_constant' => true,
            ],
        ];
    }
}

if (!function_exists('finance_calculate_margin')) {
    function finance_calculate_margin(float $revenue, float $profit): array {
        if (abs($revenue) < 0.0000001) {
            return ['ok' => false, 'error' => 'Revenue must be non-zero to calculate margin.'];
        }
        return [
            'ok' => true,
            'formula' => 'Margin = Profit / Revenue',
            'inputs' => [
                'revenue' => round($revenue, 2),
                'profit' => round($profit, 2),
            ],
            'outputs' => [
                'margin_percent' => round(($profit / $revenue) * 100, 4),
            ],
            'assumptions' => [],
        ];
    }
}

if (!function_exists('finance_parse_allocation_positions')) {
    function finance_parse_allocation_positions(string $message): array {
        preg_match_all('/(?:^|,|;|\n)\s*([A-Za-z][A-Za-z0-9\-_. ]{0,24}?)\s*(?:=|:|-)?\s*(\d+(?:\.\d+)?)\s?%/m', $message, $matches, PREG_SET_ORDER);
        $positions = [];
        foreach ($matches as $match) {
            $label = trim((string)$match[1]);
            $weight = (float)$match[2];
            if ($label === '') {
                continue;
            }
            $positions[] = ['label' => $label, 'weight_percent' => $weight];
        }
        return $positions;
    }
}

if (!function_exists('finance_portfolio_risks')) {
    function finance_portfolio_risks(array $positions, float $largestWeight): array {
        $risks = [];
        if ($largestWeight >= 35) {
            $risks[] = 'High concentration risk: one holding exceeds 35% of the portfolio.';
        } elseif ($largestWeight >= 20) {
            $risks[] = 'Moderate concentration risk: the largest holding exceeds 20%.';
        }
        if (count($positions) <= 4) {
            $risks[] = 'Low diversification: fewer than five positions were supplied.';
        }
        if (!$risks) {
            $risks[] = 'No immediate concentration flag from the supplied weights, but correlation and volatility data were not provided.';
        }
        return $risks;
    }
}

if (!function_exists('finance_analyze_portfolio')) {
    function finance_analyze_portfolio(array $positions): array {
        if (!$positions) {
            return ['ok' => false, 'error' => 'No portfolio positions were provided.'];
        }
        $total = 0.0;
        foreach ($positions as $position) {
            $total += (float)($position['weight_percent'] ?? 0);
        }
        if ($total <= 0) {
            return ['ok' => false, 'error' => 'Portfolio weights must total more than zero.'];
        }
        $normalized = [];
        $max = 0.0;
        foreach ($positions as $position) {
            $weight = ((float)($position['weight_percent'] ?? 0) / $total) * 100;
            $normalized[] = ['label' => (string)$position['label'], 'weight_percent' => round($weight, 2)];
            $max = max($max, $weight);
        }
        usort($normalized, static fn($a, $b) => ($b['weight_percent'] <=> $a['weight_percent']));
        $topThree = array_slice($normalized, 0, 3);
        $hhi = 0.0;
        foreach ($normalized as $position) {
            $fraction = ((float)$position['weight_percent']) / 100;
            $hhi += $fraction * $fraction;
        }
        $diversification = count($normalized) >= 8 && $max < 20 ? 'broad' : ($max >= 35 ? 'concentrated' : 'moderate');
        return [
            'ok' => true,
            'inputs' => ['positions' => $normalized],
            'outputs' => [
                'position_count' => count($normalized),
                'largest_position_percent' => round($max, 2),
                'top_three_percent' => round(array_sum(array_map(static fn($p) => (float)$p['weight_percent'], $topThree)), 2),
                'concentration_index' => round($hhi, 4),
                'diversification' => $diversification,
            ],
            'assumptions' => ['weights_normalized_to_100' => true],
            'risks' => finance_portfolio_risks($normalized, $max),
        ];
    }
}

if (!function_exists('finance_mock_provider_quote')) {
    function finance_mock_provider_quote(string $symbol): array {
        $upper = strtoupper(trim($symbol));
        $quotes = [
            'AAPL' => ['price' => 228.14, 'currency' => 'USD', 'name' => 'Apple Inc.', 'pe_ratio' => 34.12],
            'MSFT' => ['price' => 512.31, 'currency' => 'USD', 'name' => 'Microsoft Corp.', 'pe_ratio' => 38.44],
            'NVDA' => ['price' => 133.52, 'currency' => 'USD', 'name' => 'NVIDIA Corp.', 'pe_ratio' => 61.77],
            'TSLA' => ['price' => 248.90, 'currency' => 'USD', 'name' => 'Tesla Inc.', 'pe_ratio' => 72.31],
            'BTC-USD' => ['price' => 64750.00, 'currency' => 'USD', 'name' => 'Bitcoin', 'pe_ratio' => null],
            'ETH-USD' => ['price' => 3125.00, 'currency' => 'USD', 'name' => 'Ethereum', 'pe_ratio' => null],
        ];
        $quote = $quotes[$upper] ?? null;
        if ($quote === null) {
            return ['status' => 'unavailable', 'reason' => 'No provider is configured for this symbol yet.', 'retryable' => true];
        }
        return [
            'status' => 'ok',
            'provider' => 'mock',
            'retrieved_at' => gmdate('c'),
            'data_timestamp' => gmdate('c'),
            'realtime' => false,
            'symbol' => $upper,
            'quote' => $quote,
        ];
    }
}

if (!function_exists('finance_yahoo_provider_quote')) {
    function finance_yahoo_provider_quote(string $symbol): array {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return ['status' => 'unavailable', 'reason' => 'No symbol was supplied.', 'retryable' => false];
        }

        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1d&range=1d';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (compatible; LyralinkFinance/1.0; +https://lyralinkai.com)',
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            return [
                'status' => 'unavailable',
                'reason' => $curlError !== '' ? $curlError : ('Yahoo Finance HTTP ' . $httpCode),
                'retryable' => true,
            ];
        }

        $decoded = json_decode($raw, true);
        $result = $decoded['chart']['result'][0] ?? null;
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : null;
        if (!is_array($meta)) {
            return ['status' => 'unavailable', 'reason' => 'Yahoo Finance returned no chart metadata.', 'retryable' => true];
        }

        $price = $meta['regularMarketPrice'] ?? $meta['previousClose'] ?? null;
        if (!is_numeric($price)) {
            return ['status' => 'unavailable', 'reason' => 'Yahoo Finance returned no usable price.', 'retryable' => true];
        }

        $timestamp = isset($meta['regularMarketTime']) && is_numeric($meta['regularMarketTime'])
            ? gmdate('c', (int)$meta['regularMarketTime'])
            : gmdate('c');
        return [
            'status' => 'ok',
            'provider' => 'yahoo',
            'retrieved_at' => gmdate('c'),
            'data_timestamp' => $timestamp,
            'realtime' => true,
            'symbol' => (string)($meta['symbol'] ?? $symbol),
            'quote' => [
                'price' => round((float)$price, 4),
                'currency' => (string)($meta['currency'] ?? 'USD'),
                'name' => (string)($meta['shortName'] ?? $meta['longName'] ?? $symbol),
                'exchange' => (string)($meta['exchangeName'] ?? ''),
                'market_state' => (string)($meta['marketState'] ?? ''),
            ],
        ];
    }
}

if (!function_exists('finance_mock_provider_economic')) {
    function finance_mock_provider_economic(string $indicator): array {
        $key = finance_normalize_text($indicator);
        $data = [
            'inflation' => ['value' => 3.1, 'unit' => '%', 'label' => 'Inflation rate'],
            'cpi' => ['value' => 312.4, 'unit' => 'index', 'label' => 'Consumer Price Index'],
            'gdp' => ['value' => 2.4, 'unit' => '%', 'label' => 'GDP growth'],
            'unemployment' => ['value' => 4.2, 'unit' => '%', 'label' => 'Unemployment rate'],
            'interest rates' => ['value' => 5.25, 'unit' => '%', 'label' => 'Policy rate'],
        ];
        $item = $data[$key] ?? null;
        if ($item === null) {
            return ['status' => 'unavailable', 'reason' => 'No economic provider data is configured for this indicator yet.', 'retryable' => true];
        }
        return [
            'status' => 'ok',
            'provider' => 'mock',
            'retrieved_at' => gmdate('c'),
            'data_timestamp' => gmdate('Y-m-d'),
            'realtime' => false,
            'indicator' => $key,
            'data' => $item,
        ];
    }
}

if (!function_exists('finance_provider_quote')) {
    function finance_provider_quote(string $symbol): array {
        $preferred = strtolower(trim((string)api_get_secret('FINANCE_QUOTE_PROVIDER', 'yahoo')));
        if ($preferred === 'yahoo') {
            $live = finance_yahoo_provider_quote($symbol);
            if (($live['status'] ?? '') === 'ok') {
                return $live;
            }
        }
        return finance_mock_provider_quote($symbol);
    }
}

if (!function_exists('finance_provider_economic')) {
    function finance_provider_economic(string $indicator): array {
        return finance_mock_provider_economic($indicator);
    }
}

if (!function_exists('finance_handle_calculation')) {
    function finance_handle_calculation(string $message): array {
        if (preg_match('/\b(loan|mortgage|monthly payment|amortization|amortisation)\b/i', $message)) {
            $principal = finance_extract_loan_principal($message);
            $rate = finance_extract_percent_after_labels($message, ['at', 'rate', 'apr', 'interest']) ?? finance_extract_first_percent($message);
            $termMonths = finance_extract_term_months($message);
            if ($principal === null || $rate === null || $termMonths === null) {
                return ['status' => 'needs_input', 'reason' => 'Loan calculations need principal, annual rate, and term.', 'missing' => array_values(array_filter([
                    $principal === null ? 'principal' : null,
                    $rate === null ? 'annual_rate_percent' : null,
                    $termMonths === null ? 'term_months' : null,
                ]))];
            }
            $calc = finance_calculate_loan_payment($principal, $rate, $termMonths);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.loan_payment', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        if (preg_match('/\bcompound interest\b/i', $message)) {
            $principal = finance_extract_named_value($message, ['principal', 'deposit', 'investment', 'amount']) ?? finance_extract_first_currency_amount($message);
            $rate = finance_extract_percent_after_labels($message, ['at', 'rate', 'apr', 'apy', 'interest']) ?? finance_extract_first_percent($message);
            $months = finance_extract_term_months($message);
            $years = $months !== null ? ($months / 12) : null;
            if ($principal === null || $rate === null || $years === null) {
                return ['status' => 'needs_input', 'reason' => 'Compound interest calculations need principal, annual rate, and time horizon.', 'missing' => array_values(array_filter([
                    $principal === null ? 'principal' : null,
                    $rate === null ? 'annual_rate_percent' : null,
                    $years === null ? 'years' : null,
                ]))];
            }
            $calc = finance_calculate_compound_interest($principal, $rate, $years);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.compound_interest', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        if (preg_match('/\bcagr\b/i', $message)) {
            preg_match_all('/[$€£]?\s?-?\d[\d,]*(?:\.\d+)?/', $message, $matches);
            $values = array_values(array_filter(array_map(static fn($raw) => finance_parse_number((string)$raw), $matches[0] ?? []), static fn($v) => $v !== null));
            $months = finance_extract_term_months($message);
            $years = $months !== null ? ($months / 12) : null;
            if (count($values) < 2 || $years === null) {
                return ['status' => 'needs_input', 'reason' => 'CAGR needs beginning value, ending value, and period length.', 'missing' => ['begin_value', 'end_value', 'years']];
            }
            $calc = finance_calculate_cagr((float)$values[0], (float)$values[1], (float)$years);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.cagr', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        if (preg_match('/\bbreak[- ]?even\b/i', $message)) {
            $fixed = finance_extract_named_value($message, ['fixed costs', 'fixed cost', 'fixed']) ?? null;
            $price = finance_extract_named_value($message, ['price per unit', 'price', 'sell price']) ?? null;
            $variable = finance_extract_named_value($message, ['variable cost per unit', 'variable cost', 'unit cost']) ?? null;
            if ($fixed === null || $price === null || $variable === null) {
                return ['status' => 'needs_input', 'reason' => 'Break-even needs fixed costs, price per unit, and variable cost per unit.', 'missing' => ['fixed_costs', 'price_per_unit', 'variable_cost_per_unit']];
            }
            $calc = finance_calculate_break_even($fixed, $price, $variable);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.break_even', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        if (preg_match('/\b(runway|burn rate)\b/i', $message)) {
            $cash = finance_extract_named_value($message, ['cash', 'cash balance']) ?? finance_extract_first_currency_amount($message);
            $burn = finance_extract_named_value($message, ['burn rate', 'monthly burn']) ?? null;
            if ($cash === null || $burn === null) {
                return ['status' => 'needs_input', 'reason' => 'Runway needs cash and monthly burn.', 'missing' => ['cash', 'monthly_burn']];
            }
            $calc = finance_calculate_runway($cash, $burn);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.runway', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        if (preg_match('/\b(gross margin|net margin|operating margin|profit margin|margin)\b/i', $message)) {
            $revenue = finance_extract_named_value($message, ['revenue', 'sales']) ?? null;
            $profit = finance_extract_named_value($message, ['profit', 'net income', 'gross profit', 'operating income']) ?? null;
            if ($revenue === null || $profit === null) {
                return ['status' => 'needs_input', 'reason' => 'Margin calculations need revenue and profit.', 'missing' => ['revenue', 'profit']];
            }
            $calc = finance_calculate_margin($revenue, $profit);
            return $calc['ok'] ? ['status' => 'ok', 'tool' => 'financial.calculate.margin', 'result' => $calc] : ['status' => 'error', 'reason' => $calc['error'] ?? 'Calculation failed'];
        }

        return ['status' => 'unavailable', 'reason' => 'This finance calculation is not yet implemented in the deterministic tool layer.'];
    }
}

if (!function_exists('finance_handle_market_data')) {
    function finance_handle_market_data(string $message): array {
        $symbol = finance_extract_ticker($message);
        if ($symbol === null) {
            return ['status' => 'needs_input', 'reason' => 'A symbol or company name is needed for market data.', 'missing' => ['symbol']];
        }
        $requestedMetric = null;
        if (preg_match('/\b(p\/e|pe ratio|price to earnings|price-to-earnings)\b/i', $message)) {
            $requestedMetric = 'pe_ratio';
        }
        $quote = finance_provider_quote($symbol);
        if (($quote['status'] ?? '') !== 'ok') {
            return $quote;
        }
        $quoteData = $quote['quote'] ?? [];
        $analysis = [];
        $assumptions = [];
        $risks = [];
        if (isset($quoteData['pe_ratio']) && is_numeric($quoteData['pe_ratio'])) {
            $analysis[] = 'The price-to-earnings ratio is ' . round((float)$quoteData['pe_ratio'], 2) . ', which should be compared against peers and growth expectations rather than read in isolation.';
        }
        if ($requestedMetric === 'pe_ratio' && !isset($quoteData['pe_ratio'])) {
            $risks[] = 'The current provider returned a quote but not a live P/E ratio for this symbol.';
            $analysis[] = 'I can show the latest quote metadata, but the requested valuation field was not returned by the active provider.';
            $assumptions['requested_metric_unavailable'] = 'pe_ratio';
        }
        $analysis[] = (($quote['realtime'] ?? false) ? 'This appears to be real-time data.' : 'This quote is marked as delayed or mock data, so it should not be treated as live trading information.');
        return [
            'status' => 'ok',
            'tool' => 'financial.quote',
            'result' => [
                'source' => [
                    'provider' => $quote['provider'] ?? 'unknown',
                    'retrieved_at' => $quote['retrieved_at'] ?? null,
                    'data_timestamp' => $quote['data_timestamp'] ?? null,
                    'realtime' => (bool)($quote['realtime'] ?? false),
                    'symbol' => $quote['symbol'] ?? $symbol,
                ],
                'inputs' => ['symbol' => $symbol],
                'outputs' => $quoteData,
                'analysis' => $analysis,
                'assumptions' => $assumptions,
                'risks' => $risks,
            ],
        ];
    }
}

if (!function_exists('finance_handle_economics')) {
    function finance_handle_economics(string $message): array {
        $indicator = null;
        foreach (['inflation', 'cpi', 'gdp', 'unemployment', 'interest rates'] as $candidate) {
            if (str_contains(finance_normalize_text($message), $candidate)) {
                $indicator = $candidate;
                break;
            }
        }
        if ($indicator === null) {
            return ['status' => 'needs_input', 'reason' => 'An economic indicator is needed, such as inflation, CPI, GDP, unemployment, or interest rates.', 'missing' => ['indicator']];
        }
        $data = finance_provider_economic($indicator);
        if (($data['status'] ?? '') !== 'ok') {
            return $data;
        }
        $note = match ($indicator) {
            'inflation', 'cpi' => 'Higher inflation tends to pressure central banks toward tighter policy, which can raise borrowing costs and slow demand.',
            'interest rates' => 'Higher policy rates usually raise borrowing costs, pressure valuations, and reduce demand-sensitive activity, but the magnitude depends on broader conditions.',
            'gdp' => 'GDP growth is a broad measure of output and should be interpreted alongside inflation, labor data, and policy.',
            'unemployment' => 'Unemployment often lags turning points, so it is more useful when combined with hiring, wage, and participation trends.',
            default => 'This indicator should be interpreted in context with other macro signals.',
        };
        return [
            'status' => 'ok',
            'tool' => 'financial.economic',
            'result' => [
                'source' => [
                    'provider' => $data['provider'] ?? 'unknown',
                    'retrieved_at' => $data['retrieved_at'] ?? null,
                    'data_timestamp' => $data['data_timestamp'] ?? null,
                    'realtime' => (bool)($data['realtime'] ?? false),
                ],
                'inputs' => ['indicator' => $indicator],
                'outputs' => $data['data'] ?? [],
                'analysis' => [$note],
                'assumptions' => [],
            ],
        ];
    }
}

if (!function_exists('finance_handle_portfolio')) {
    function finance_handle_portfolio(string $message): array {
        $positions = finance_parse_allocation_positions($message);
        if (!$positions) {
            return ['status' => 'needs_input', 'reason' => 'Provide holdings or allocations such as Tech 40%, Index 35%, Bonds 25%.', 'missing' => ['positions']];
        }
        $analysis = finance_analyze_portfolio($positions);
        return $analysis['ok'] ? ['status' => 'ok', 'tool' => 'financial.portfolio', 'result' => $analysis] : ['status' => 'error', 'reason' => $analysis['error'] ?? 'Portfolio analysis failed'];
    }
}

if (!function_exists('finance_handle_business')) {
    function finance_handle_business(string $message): array {
        if (preg_match('/\b(runway|burn rate)\b/i', $message)) {
            return finance_handle_calculation($message);
        }
        if (preg_match('/\b(margin|profitability|gross margin|net margin|operating margin)\b/i', $message)) {
            return finance_handle_calculation($message);
        }
        return ['status' => 'needs_input', 'reason' => 'Business finance analysis needs actual business metrics such as revenue, profit, cash, burn, or unit economics.', 'missing' => ['metrics']];
    }
}

if (!function_exists('finance_build_markdown_table')) {
    function finance_build_markdown_table(array $rows): string {
        if (!$rows) {
            return '';
        }
        $headers = array_keys($rows[0]);
        $lines = [];
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = str_replace('|', '\\|', (string)($row[$header] ?? ''));
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('finance_format_value')) {
    function finance_format_value($value): string {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
        }
        return (string)$value;
    }
}

if (!function_exists('finance_render_reply')) {
    function finance_render_reply(array $finance): string {
        $intent = (string)($finance['intent'] ?? 'finance');
        $status = (string)($finance['status'] ?? 'unavailable');
        if ($status !== 'ok') {
            $lines = ['I could not complete that financial request yet.'];
            if (!empty($finance['reason'])) {
                $lines[] = '';
                $lines[] = 'Reason: ' . (string)$finance['reason'];
            }
            if (!empty($finance['missing']) && is_array($finance['missing'])) {
                $lines[] = 'Missing inputs: ' . implode(', ', $finance['missing']);
            }
            return implode("\n", $lines);
        }

        $result = is_array($finance['result'] ?? null) ? $finance['result'] : [];
        $outputs = is_array($result['outputs'] ?? null) ? $result['outputs'] : [];
        $inputs = is_array($result['inputs'] ?? null) ? $result['inputs'] : [];
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $assumptions = is_array($result['assumptions'] ?? null) ? $result['assumptions'] : [];
        $risks = is_array($result['risks'] ?? null) ? $result['risks'] : [];
        $confidence = is_array($finance['confidence'] ?? null) ? $finance['confidence'] : [];

        $title = match ($intent) {
            'calculation' => 'Financial calculation completed.',
            'market_data' => 'Market data lookup completed.',
            'economics' => 'Economic data lookup completed.',
            'portfolio' => 'Portfolio analysis completed.',
            'business' => 'Business finance analysis completed.',
            default => 'Financial analysis completed.',
        };
        $lines = [$title];

        if ($outputs) {
            $rows = [];
            foreach ($outputs as $key => $value) {
                $rows[] = ['Metric' => ucwords(str_replace('_', ' ', (string)$key)), 'Value' => finance_format_value($value)];
            }
            $lines[] = '';
            $lines[] = 'Key numbers:';
            $lines[] = '';
            $lines[] = finance_build_markdown_table($rows);
        }

        if ($analysis) {
            $lines[] = '';
            $lines[] = 'Analysis:';
            foreach ($analysis as $item) {
                $lines[] = '- ' . (string)$item;
            }
        }

        if ($inputs) {
            $rows = [];
            foreach ($inputs as $key => $value) {
                if ($key === 'positions' && is_array($value)) {
                    continue;
                }
                $rows[] = ['Input' => ucwords(str_replace('_', ' ', (string)$key)), 'Value' => finance_format_value($value)];
            }
            if ($rows) {
                $lines[] = '';
                $lines[] = 'Inputs used:';
                $lines[] = '';
                $lines[] = finance_build_markdown_table($rows);
            }
            if (!empty($inputs['positions']) && is_array($inputs['positions'])) {
                $rows = [];
                foreach ($inputs['positions'] as $position) {
                    if (!is_array($position)) {
                        continue;
                    }
                    $rows[] = [
                        'Position' => (string)($position['label'] ?? ''),
                        'Weight %' => finance_format_value($position['weight_percent'] ?? ''),
                    ];
                }
                if ($rows) {
                    $lines[] = '';
                    $lines[] = 'Holdings supplied:';
                    $lines[] = '';
                    $lines[] = finance_build_markdown_table($rows);
                }
            }
        }

        if (!empty($result['formula'])) {
            $lines[] = '';
            $lines[] = 'Method: ' . (string)$result['formula'];
        }

        if ($assumptions) {
            $lines[] = '';
            $lines[] = 'Assumptions:';
            foreach ($assumptions as $key => $value) {
                $label = is_string($key) ? ucwords(str_replace('_', ' ', $key)) : 'Assumption';
                $lines[] = '- ' . $label . ': ' . finance_format_value($value);
            }
        }

        if ($risks) {
            $lines[] = '';
            $lines[] = 'Risks and limitations:';
            foreach ($risks as $risk) {
                $lines[] = '- ' . (string)$risk;
            }
        }

        if ($confidence) {
            $lines[] = '';
            $lines[] = 'Confidence: ' . strtoupper((string)($confidence['label'] ?? 'low')) . ' - ' . (string)($confidence['reason'] ?? '');
        }

        if ($source) {
            $lines[] = '';
            $sourceRows = [];
            foreach ($source as $key => $value) {
                $sourceRows[] = ['Source' => ucwords(str_replace('_', ' ', (string)$key)), 'Value' => finance_format_value($value)];
            }
            $lines[] = 'Source metadata:';
            $lines[] = '';
            $lines[] = finance_build_markdown_table($sourceRows);
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('finance_process_request')) {
    function finance_process_request(array $messages): ?array {
        $message = finance_last_user_message($messages);
        if ($message === '') {
            return null;
        }
        $detected = finance_detect_request($message);
        if (empty($detected['matched'])) {
            return null;
        }

        $intent = (string)($detected['intent'] ?? '');
        $result = match ($intent) {
            'calculation' => finance_handle_calculation($message),
            'market_data' => finance_handle_market_data($message),
            'economics' => finance_handle_economics($message),
            'portfolio' => finance_handle_portfolio($message),
            'business' => finance_handle_business($message),
            default => ['status' => 'unavailable', 'reason' => 'No financial handler matched this request.'],
        };

        $completeInputs = (($result['status'] ?? '') === 'ok');
        $hasLiveData = isset($result['result']['source']);
        $confidence = finance_confidence_for_tool($intent, $completeInputs, $hasLiveData);

        $finance = [
            'matched' => true,
            'intent' => $intent,
            'confidence' => $confidence,
            'signals' => $detected['signals'] ?? [],
            'status' => $result['status'] ?? 'unavailable',
            'tool' => $result['tool'] ?? null,
            'reason' => $result['reason'] ?? null,
            'missing' => $result['missing'] ?? [],
            'result' => $result['result'] ?? null,
            'audit' => [
                'message' => $message,
                'intent' => $intent,
                'signals' => $detected['signals'] ?? [],
                'tool' => $result['tool'] ?? null,
                'processed_at' => gmdate('c'),
            ],
        ];
        $finance['reply'] = finance_render_reply($finance);
        return $finance;
    }
}
