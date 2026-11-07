<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/StoreMailer.php';

class CapturingStoreMailer extends StoreMailer
{
    public $message;

    public function send($recipient, $subject, $text, $html = '')
    {
        $this->message = compact('recipient', 'subject', 'text', 'html');
    }
}

$tests = [
    'order updates include readable status payment balance and delivery estimate' => function () {
        $mailer = new CapturingStoreMailer();
        $mailer->sendOrderUpdate([
            'id' => 42,
            'name' => 'A Customer',
            'delivery_name' => 'A Customer',
            'email' => 'customer@example.com',
            'status' => 'ready_to_ship',
            'payment_status' => 'partial',
            'amount_paid' => 25,
            'total' => 100,
            'courier' => 'Courier',
            'tracking_number' => 'TRACK-42',
            'estimated_delivery_at' => '2026-11-06',
            'tracking_url' => 'https://courier.example/track/42'
        ], 'status updated');

        assertContains('Ready to ship', $mailer->message['text']);
        assertContains('Previously recorded partial payment', $mailer->message['text']);
        assertContains('Balance due: PHP 75.00', $mailer->message['text']);
        assertContains('Estimated delivery: 2026-11-06', $mailer->message['text']);
        assertContains('https://courier.example/track/42', $mailer->message['html']);
    },
    'return updates clearly distinguish received items from issued refunds' => function () {
        $mailer = new CapturingStoreMailer();
        $request = [
            'id' => 8,
            'order_id' => 42,
            'name' => '<Customer>',
            'email' => 'customer@example.com',
            'refund_amount' => 75,
            'status' => 'received'
        ];
        $mailer->sendReturnUpdate($request, 'return request status updated');
        assertContains('refund review in progress', $mailer->message['text']);
        assertNotContains('marked this refund as issued', $mailer->message['text']);
        assertContains('&lt;Customer&gt;', $mailer->message['html']);

        $request['status'] = 'refunded';
        $mailer->sendReturnUpdate($request, 'return request status updated');
        assertContains('Refund marked issued', $mailer->message['text']);
        assertContains('marked this refund as issued outside Stockwell', $mailer->message['text']);
    }
];

function assertContains($expected, $actual)
{
    if (strpos($actual, $expected) === false) {
        throw new RuntimeException('Expected text not found: ' . $expected);
    }
}

function assertNotContains($unexpected, $actual)
{
    if (strpos($actual, $unexpected) !== false) {
        throw new RuntimeException('Unexpected text found: ' . $unexpected);
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
