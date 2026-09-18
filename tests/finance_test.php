<?php
require_once __DIR__ . '/../api/lib/chat/runtime_core.php';
require_once __DIR__ . '/../api/lib/finance.php';

$cases = [];

$loan = finance_calculate_loan_payment(25000, 7.5, 60);
$cases[] = ['loan_payment', $loan['ok'] ?? false, abs(($loan['outputs']['monthly_payment'] ?? 0) - 500.95) < 0.05];

$compound = finance_calculate_compound_interest(10000, 5, 10, 12);
$cases[] = ['compound_interest', $compound['ok'] ?? false, abs(($compound['outputs']['future_value'] ?? 0) - 16470.09) < 0.2];

$cagr = finance_calculate_cagr(100, 150, 3);
$cases[] = ['cagr', $cagr['ok'] ?? false, abs(($cagr['outputs']['cagr_percent'] ?? 0) - 14.4714) < 0.01];

$breakEven = finance_calculate_break_even(10000, 50, 20);
$cases[] = ['break_even', $breakEven['ok'] ?? false, abs(($breakEven['outputs']['break_even_units'] ?? 0) - 333.33) < 0.05];

$runway = finance_calculate_runway(120000, 10000);
$cases[] = ['runway', $runway['ok'] ?? false, abs(($runway['outputs']['runway_months'] ?? 0) - 12) < 0.01];

$portfolio = finance_analyze_portfolio([
    ['label' => 'Tech', 'weight_percent' => 60],
    ['label' => 'Index', 'weight_percent' => 25],
    ['label' => 'Bonds', 'weight_percent' => 15],
]);
$cases[] = ['portfolio', $portfolio['ok'] ?? false, ($portfolio['outputs']['largest_position_percent'] ?? 0) === 60.0];

$detect = finance_process_request([
    ['role' => 'user', 'content' => 'Calculate the monthly payment on a $25,000 loan at 7.5% for 60 months.'],
]);
$cases[] = ['route_detection', is_array($detect), ($detect['tool'] ?? '') === 'financial.calculate.loan_payment'];

$failed = array_filter($cases, static fn($case) => empty($case[1]) || empty($case[2]));
if ($failed) {
    foreach ($failed as $case) {
        fwrite(STDERR, 'FAIL ' . $case[0] . PHP_EOL);
    }
    exit(1);
}

echo "finance tests passed\n";
