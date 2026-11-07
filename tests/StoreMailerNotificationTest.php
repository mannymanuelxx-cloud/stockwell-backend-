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

class CapturingResendStoreMailer extends StoreMailer
{
    public $delivery;

    protected function sendViaResend(string $apiKey, array $payload): void
    {
        $this->delivery = compact('apiKey', 'payload');
    }
}

$tests = [
    'Resend delivery uses configured sender and both message formats' => function () {
        $originalApiKey = getenv('RESEND_API_KEY');
        $originalFromEmail = getenv('RESEND_FROM_EMAIL');
        $originalFromName = getenv('RESEND_FROM_NAME');
        putenv('RESEND_API_KEY=re_test_key');
        putenv('RESEND_FROM_EMAIL=notifications@example.com');
        putenv('RESEND_FROM_NAME=Stockwell');

        try {
            $mailer = new CapturingResendStoreMailer();
            $mailer->send('customer@example.com', 'Test subject', 'Plain text', '<p>HTML</p>');
        } finally {
            restoreEnvironmentValue('RESEND_API_KEY', $originalApiKey);
            restoreEnvironmentValue('RESEND_FROM_EMAIL', $originalFromEmail);
            restoreEnvironmentValue('RESEND_FROM_NAME', $originalFromName);
        }

        if ($mailer->delivery['apiKey'] !== 're_test_key') {
            throw new RuntimeException('Expected the Resend API key to be passed to the delivery client.');
        }
        if ($mailer->delivery['payload'] !== [
            'from' => 'Stockwell <notifications@example.com>',
            'to' => ['customer@example.com'],
            'subject' => 'Test subject',
            'text' => 'Plain text',
            'html' => '<p>HTML</p>'
        ]) {
            throw new RuntimeException('Unexpected Resend email payload.');
        }
    },
    'Resend delivery rejects missing configuration before making a request' => function () {
        $originalApiKey = getenv('RESEND_API_KEY');
        $originalFromEmail = getenv('RESEND_FROM_EMAIL');
        putenv('RESEND_API_KEY');
        putenv('RESEND_FROM_EMAIL=notifications@example.com');

        try {
            $mailer = new CapturingResendStoreMailer();
            try {
                $mailer->send('customer@example.com', 'Test subject', 'Plain text');
                throw new RuntimeException('Expected missing Resend API key to be rejected.');
            } catch (RuntimeException $error) {
                assertContains('RESEND_API_KEY', $error->getMessage());
            }
            if ($mailer->delivery !== null) {
                throw new RuntimeException('Email delivery must not run with missing configuration.');
            }
        } finally {
            restoreEnvironmentValue('RESEND_API_KEY', $originalApiKey);
            restoreEnvironmentValue('RESEND_FROM_EMAIL', $originalFromEmail);
        }
    },
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

function restoreEnvironmentValue($name, $value)
{
    if ($value === false) {
        putenv($name);
        return;
    }
    putenv($name . '=' . $value);
}

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
