<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/CloudflareTurnstilePolicy.php';

$tests = [
    'accepts successful customer-registration response from the configured host' => function () {
        assertSame(true, CloudflareTurnstilePolicy::isValidRegistrationResponse([
            'success' => true,
            'action' => 'customer_registration',
            'hostname' => 'stockwell-storefront.onrender.com'
        ], 'stockwell-storefront.onrender.com'));
    },
    'rejects unsuccessful response, wrong action, and wrong hostname' => function () {
        $response = [
            'success' => true,
            'action' => 'customer_registration',
            'hostname' => 'stockwell-storefront.onrender.com'
        ];
        $response['success'] = false;
        assertSame(false, CloudflareTurnstilePolicy::isValidRegistrationResponse($response, 'stockwell-storefront.onrender.com'));
        $response['success'] = true;
        $response['action'] = 'login';
        assertSame(false, CloudflareTurnstilePolicy::isValidRegistrationResponse($response, 'stockwell-storefront.onrender.com'));
        $response['action'] = 'customer_registration';
        $response['hostname'] = 'attacker.example';
        assertSame(false, CloudflareTurnstilePolicy::isValidRegistrationResponse($response, 'stockwell-storefront.onrender.com'));
    },
    'rejects missing hostname or expected hostname' => function () {
        assertSame(false, CloudflareTurnstilePolicy::isValidRegistrationResponse([
            'success' => true,
            'action' => 'customer_registration'
        ], 'stockwell-storefront.onrender.com'));
        assertSame(false, CloudflareTurnstilePolicy::isValidRegistrationResponse([
            'success' => true,
            'action' => 'customer_registration',
            'hostname' => 'stockwell-storefront.onrender.com'
        ], ''));
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
