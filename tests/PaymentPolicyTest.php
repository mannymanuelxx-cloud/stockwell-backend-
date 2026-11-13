<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/PaymentPolicy.php';

$tests = [
    'paid transactions record the full total' => function () {
        assertSame([
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'amount_paid' => 1299.5
        ], PaymentPolicy::normalize('paid', 'cash', 1299.5));
    },
    'unpaid transactions record no amount received' => function () {
        assertSame([
            'payment_status' => 'unpaid',
            'payment_method' => 'gcash',
            'amount_paid' => 0
        ], PaymentPolicy::normalize('unpaid', 'gcash', 1299.5));
    },
    'partial payment status is rejected' => function () {
        assertThrows(fn () => PaymentPolicy::normalize('partial', 'cash', 100), 'paid in full or unpaid');
    },
    'invalid payment methods and totals are rejected' => function () {
        assertThrows(fn () => PaymentPolicy::normalize('paid', 'cheque', 100), 'valid payment method');
        assertThrows(fn () => PaymentPolicy::normalize('paid', 'cash', 0), 'greater than zero');
    },
    'orders cannot be marked paid before delivery' => function () {
        assertThrows(
            fn () => PaymentPolicy::assertOrderPaymentTiming('paid', 'shipped'),
            'only be marked paid after it has been delivered'
        );
        PaymentPolicy::assertOrderPaymentTiming('unpaid', 'pending');
        PaymentPolicy::assertOrderPaymentTiming('paid', 'delivered');
        PaymentPolicy::assertOrderPaymentTiming('paid', 'completed');
        PaymentPolicy::assertOrderPaymentTiming('paid', 'pending', true);
        PaymentPolicy::assertOrderPaymentTiming('paid', 'shipped', true);
        assertThrows(
            fn () => PaymentPolicy::assertOrderPaymentTiming('paid', 'cancelled', true),
            'only be marked paid after it has been delivered'
        );
    },
    'cash-on-delivery orders are automatically paid when delivered' => function () {
        assertSame(
            ['payment_status' => 'paid', 'amount_paid' => 1299.5],
            PaymentPolicy::cashOnDeliveryPayment('unpaid', 'cash', 'delivered', 1299.5)
        );
        assertSame(
            ['payment_status' => 'paid', 'amount_paid' => 1299.5],
            PaymentPolicy::cashOnDeliveryPayment('partial', 'cash', 'delivered', 1299.5)
        );
    },
    'non-cash and already refunded orders are not auto-settled' => function () {
        assertSame(null, PaymentPolicy::cashOnDeliveryPayment('unpaid', 'gcash', 'delivered', 100));
        assertSame(null, PaymentPolicy::cashOnDeliveryPayment('refunded', 'cash', 'delivered', 100));
        assertSame(null, PaymentPolicy::cashOnDeliveryPayment('unpaid', 'cash', 'shipped', 100));
    },
    'cash-on-delivery auto-settlement rejects invalid totals' => function () {
        assertThrows(
            fn () => PaymentPolicy::cashOnDeliveryPayment('unpaid', 'cash', 'delivered', 0),
            'greater than zero'
        );
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows($callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), $message) !== false) return;
        throw new RuntimeException('Unexpected validation message: ' . $error->getMessage());
    }
    throw new RuntimeException('Invalid payment data was accepted.');
}

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}

echo count($tests) . " tests passed.\n";
