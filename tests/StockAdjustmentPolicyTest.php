<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/StockAdjustmentPolicy.php';

$tests = [
    'positive and negative whole number adjustments preserve the reason' => function () {
        assertSame(
            ['quantity_change' => 5, 'reason' => 'Received count correction'],
            StockAdjustmentPolicy::validate('5', '  Received count correction  ')
        );
        assertSame(
            ['quantity_change' => -2, 'reason' => 'Damaged units'],
            StockAdjustmentPolicy::validate(-2, 'Damaged units')
        );
    },
    'zero, fractional, and oversized changes are rejected' => function () {
        assertHasError(StockAdjustmentPolicy::validate(0, 'Correction'));
        assertHasError(StockAdjustmentPolicy::validate('1.5', 'Correction'));
        assertHasError(StockAdjustmentPolicy::validate(1000001, 'Correction'));
    },
    'missing and oversized reasons are rejected' => function () {
        assertHasError(StockAdjustmentPolicy::validate(1, '  '));
        assertHasError(StockAdjustmentPolicy::validate(1, str_repeat('x', 256)));
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertHasError($result)
{
    if (!isset($result['error'])) {
        throw new RuntimeException('Expected a validation error, got ' . var_export($result, true));
    }
}

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}

if ($failures > 0) exit(1);
echo count($tests) . " tests passed.\n";
