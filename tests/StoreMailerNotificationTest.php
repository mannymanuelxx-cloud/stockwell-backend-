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

class CapturingRelayStoreMailer extends StoreMailer
{
    public $relayRequest;

    protected function sendViaGmailRelay(string $relayUrl, string $relaySecret, array $payload): void
    {
        $this->relayRequest = compact('relayUrl', 'relaySecret', 'payload');
    }
}

class MockHttpRelayStoreMailer extends StoreMailer
{
    public $requests = [];
    public $responses = [];

    public function sendForTest(string $relayUrl, string $relaySecret, array $payload): void
    {
        $this->sendViaGmailRelay($relayUrl, $relaySecret, $payload);
    }

    protected function requestGmailRelay(string $url, string $method, ?string $body = null): array
    {
        $this->requests[] = compact('url', 'method', 'body');
        return array_shift($this->responses);
    }
}

$tests = [
    'Gmail relay sends configured recipient and both message formats over HTTPS' => function () {
        $originalUrl = getenv('GMAIL_RELAY_URL');
        $originalSecret = getenv('GMAIL_RELAY_SECRET');
        putenv('GMAIL_RELAY_URL=https://script.google.com/macros/s/test-deployment/exec');
        putenv('GMAIL_RELAY_SECRET=' . str_repeat('a', 64));

        try {
            $mailer = new CapturingRelayStoreMailer();
            $mailer->send('customer@example.com', 'Test subject', 'Plain text', '<p>HTML</p>');
        } finally {
            restoreEnvironmentValue('GMAIL_RELAY_URL', $originalUrl);
            restoreEnvironmentValue('GMAIL_RELAY_SECRET', $originalSecret);
        }

        if ($mailer->relayRequest['payload'] !== [
            'secret' => str_repeat('a', 64),
            'to' => 'customer@example.com',
            'subject' => 'Test subject',
            'text' => 'Plain text',
            'html' => '<p>HTML</p>'
        ]) {
            throw new RuntimeException('Unexpected Google Apps Script email relay payload.');
        }
    },
    'Gmail relay rejects insecure or invalid configuration before sending' => function () {
        $originalUrl = getenv('GMAIL_RELAY_URL');
        $originalSecret = getenv('GMAIL_RELAY_SECRET');
        putenv('GMAIL_RELAY_URL=http://script.google.com/macros/s/test-deployment/exec');
        putenv('GMAIL_RELAY_SECRET=' . str_repeat('a', 64));

        try {
            $mailer = new CapturingRelayStoreMailer();
            try {
                $mailer->send('customer@example.com', 'Test subject', 'Plain text');
                throw new RuntimeException('Expected an insecure relay URL to be rejected.');
            } catch (RuntimeException $error) {
                assertContains('GMAIL_RELAY_URL', $error->getMessage());
            }
            if ($mailer->relayRequest !== null) {
                throw new RuntimeException('Email delivery must not run with an insecure relay URL.');
            }
        } finally {
            restoreEnvironmentValue('GMAIL_RELAY_URL', $originalUrl);
            restoreEnvironmentValue('GMAIL_RELAY_SECRET', $originalSecret);
        }
    },
    'Gmail relay rejects invalid recipient addresses' => function () {
        $originalUrl = getenv('GMAIL_RELAY_URL');
        $originalSecret = getenv('GMAIL_RELAY_SECRET');
        putenv('GMAIL_RELAY_URL=https://script.google.com/macros/s/test-deployment/exec');
        putenv('GMAIL_RELAY_SECRET=' . str_repeat('a', 64));

        try {
            $mailer = new CapturingRelayStoreMailer();
            try {
                $mailer->send('not-an-email', 'Test subject', 'Plain text');
                throw new RuntimeException('Expected an invalid recipient to be rejected.');
            } catch (InvalidArgumentException $error) {
                assertContains('recipient', $error->getMessage());
            }
            if ($mailer->relayRequest !== null) {
                throw new RuntimeException('Email delivery must not run for an invalid recipient.');
            }
        } finally {
            restoreEnvironmentValue('GMAIL_RELAY_URL', $originalUrl);
            restoreEnvironmentValue('GMAIL_RELAY_SECRET', $originalSecret);
        }
    },
    'Gmail relay safely follows the Apps Script content redirect' => function () {
        $mailer = new MockHttpRelayStoreMailer();
        $mailer->responses = [
            [
                'status' => 302,
                'location' => 'https://script.googleusercontent.com/macros/echo?key=one-time',
                'body' => ''
            ],
            ['status' => 200, 'location' => '', 'body' => '{"ok":true}']
        ];
        $mailer->sendForTest(
            'https://script.google.com/macros/s/test-deployment/exec',
            str_repeat('a', 64),
            ['to' => 'customer@example.com']
        );

        if (count($mailer->requests) !== 2
            || $mailer->requests[0]['method'] !== 'POST'
            || $mailer->requests[1]['method'] !== 'GET'
            || $mailer->requests[1]['url'] !== 'https://script.googleusercontent.com/macros/echo?key=one-time') {
            throw new RuntimeException('The relay must follow the trusted content redirect using GET.');
        }
    },
    'Gmail relay rejects redirects to untrusted hosts' => function () {
        $mailer = new MockHttpRelayStoreMailer();
        $mailer->responses = [[
            'status' => 302,
            'location' => 'https://example.com/collect',
            'body' => ''
        ]];

        try {
            $mailer->sendForTest(
                'https://script.google.com/macros/s/test-deployment/exec',
                str_repeat('a', 64),
                ['to' => 'customer@example.com']
            );
            throw new RuntimeException('Expected an untrusted relay redirect to be rejected.');
        } catch (RuntimeException $error) {
            assertContains('unexpected HTTPS redirect', $error->getMessage());
        }
        if (count($mailer->requests) !== 1) {
            throw new RuntimeException('The relay must not request an untrusted redirect URL.');
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
