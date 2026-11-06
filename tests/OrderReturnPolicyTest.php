<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/OrderReturnPolicy.php';
require_once __DIR__ . '/../app/libraries/DeliveryPricingPolicy.php';
require_once __DIR__ . '/../app/libraries/ProductReviewPolicy.php';

$tests = [
    'returns are limited to delivered and legacy completed orders' => function () {
        assertSame(true, OrderReturnPolicy::isEligibleOrderStatus('delivered'));
        assertSame(true, OrderReturnPolicy::isEligibleOrderStatus('completed'));
        assertSame(false, OrderReturnPolicy::isEligibleOrderStatus('shipped'));
    },
    'remaining quantity never exceeds the unreturned units' => function () {
        assertSame(3, OrderReturnPolicy::remainingQuantity(5, 2));
        assertSame(0, OrderReturnPolicy::remainingQuantity(5, 7));
    },
    'return requests expire after 14 days and reject invalid delivery dates' => function () {
        $now = 2000000000;
        assertSame(true, OrderReturnPolicy::isWithinReturnWindow(date('Y-m-d H:i:s', $now - 14 * 86400), $now));
        assertSame(false, OrderReturnPolicy::isWithinReturnWindow(date('Y-m-d H:i:s', $now - 14 * 86400 - 1), $now));
        assertSame(false, OrderReturnPolicy::isWithinReturnWindow('not a date', $now));
    },
    'return statuses follow the allowed workflow' => function () {
        assertSame(true, OrderReturnPolicy::canTransition('requested', 'approved'));
        assertSame(true, OrderReturnPolicy::canTransition('approved', 'received'));
        assertSame(true, OrderReturnPolicy::canTransition('received', 'refunded'));
        assertSame(false, OrderReturnPolicy::canTransition('requested', 'refunded'));
        assertSame(false, OrderReturnPolicy::canTransition('refunded', 'received'));
    },
    'refund amounts are capped by the amount actually recorded as paid' => function () {
        assertSame(30.25, OrderReturnPolicy::refundAmount(50, 30.25));
        assertSame(12.5, OrderReturnPolicy::refundAmount(12.5, 30));
        assertSame(0.0, OrderReturnPolicy::refundAmount(12.5, 0));
    },
    'payment state reflects full and partial refunds' => function () {
        assertSame('refunded', OrderReturnPolicy::paymentStatusAfterRefund(30, 30, 'paid'));
        assertSame('partial', OrderReturnPolicy::paymentStatusAfterRefund(30, 10, 'paid'));
        assertSame('unpaid', OrderReturnPolicy::paymentStatusAfterRefund(0, 0, 'unpaid'));
    },
    'delivery quote adds fee and applies configured threshold discount' => function () {
        assertSame([
            'subtotal' => 500.0,
            'delivery_fee' => 199.0,
            'delivery_discount' => 0.0,
            'total' => 699.0,
            'free_delivery_applied' => false
        ], DeliveryPricingPolicy::quote(500, 199, true, 1000));
        assertSame([
            'subtotal' => 1000.0,
            'delivery_fee' => 199.0,
            'delivery_discount' => 199.0,
            'total' => 1000.0,
            'free_delivery_applied' => true
        ], DeliveryPricingPolicy::quote(1000, 199, true, 1000));
        assertSame(0.0, DeliveryPricingPolicy::quote(1000, 199, false, 1)['delivery_discount']);
    },
    'negative delivery amounts are rejected' => function () {
        try {
            DeliveryPricingPolicy::quote(100, -1, false, 0);
        } catch (InvalidArgumentException $error) {
            return;
        }
        throw new RuntimeException('Negative delivery fee was accepted.');
    },
    'product reviews require completed purchases and valid bounded content' => function () {
        assertSame(true, ProductReviewPolicy::isEligibleOrderStatus('delivered'));
        assertSame(true, ProductReviewPolicy::isEligibleOrderStatus('completed'));
        assertSame(false, ProductReviewPolicy::isEligibleOrderStatus('pending'));
        assertSame(false, ProductReviewPolicy::isEligibleOrderStatus('shipped'));
        assertSame(
            ['rating' => 5, 'title' => 'Great item', 'review' => 'Works well'],
            ProductReviewPolicy::normalize(5, ' Great item ', 'Works well')
        );
        foreach ([[0, 'Title', 'Text'], [6, 'Title', 'Text'], [4, '', 'Text'], [4, 'Title', '']] as $invalid) {
            try {
                ProductReviewPolicy::normalize($invalid[0], $invalid[1], $invalid[2]);
            } catch (InvalidArgumentException $error) {
                continue;
            }
            throw new RuntimeException('Invalid review data was accepted.');
        }
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
