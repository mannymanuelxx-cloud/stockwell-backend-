<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/ManualGcashCheckout.php';

$variables = ['GCASH_ACCOUNT_NAME', 'GCASH_ACCOUNT_NUMBER', 'GCASH_QR_IMAGE_URL'];
$originalValues = [];
foreach ($variables as $variable) $originalValues[$variable] = getenv($variable);

$checkout = new ManualGcashCheckout();
try {
    foreach ($variables as $variable) putenv($variable);
    assertGcashDetails(null, $checkout->publicDetails());

    putenv('GCASH_ACCOUNT_NAME=Stockwell Store');
    putenv('GCASH_ACCOUNT_NUMBER=09171234567');
    putenv('GCASH_QR_IMAGE_URL=https://cdn.example.com/gcash-qr.png');
    assertGcashDetails([
        'account_name' => 'Stockwell Store',
        'account_number' => '09171234567',
        'qr_image_url' => 'https://cdn.example.com/gcash-qr.png'
    ], $checkout->publicDetails());

    putenv('GCASH_QR_IMAGE_URL=http://cdn.example.com/gcash-qr.png');
    assertGcashDetails(null, $checkout->publicDetails());
    putenv('GCASH_QR_IMAGE_URL=https://cdn.example.com/gcash-qr.svg');
    assertGcashDetails(null, $checkout->publicDetails());
    putenv('GCASH_QR_IMAGE_URL=https://cdn.example.com/gcash-qr.png');
    putenv("GCASH_ACCOUNT_NAME=Stockwell\nStore");
    assertGcashDetails(null, $checkout->publicDetails());
} finally {
    foreach ($originalValues as $variable => $value) {
        putenv($value === false ? $variable : $variable . '=' . $value);
    }
}

function assertGcashDetails($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Unexpected manual GCash payment configuration.');
    }
}

echo "Manual GCash checkout configuration tests passed.\n";
