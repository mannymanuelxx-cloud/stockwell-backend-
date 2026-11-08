<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/StoreMailer.php';

class CapturingBrevoMailer extends StoreMailer
{
    public $payload;
    public $apiKey;

    protected function sendBrevoMessage(array $payload, $apiKey)
    {
        $this->payload = $payload;
        $this->apiKey = $apiKey;
    }
}

$tests = [
    'sends transactional email through Brevo API configuration' => function () {
        putenv('BREVO_API_KEY=test-brevo-key');
        putenv('BREVO_FROM_EMAIL=verified@example.com');
        putenv('BREVO_FROM_NAME=Stockwell Store');
        putenv('BREVO_REPLY_TO=support@example.com');
        putenv('BREVO_REPLY_TO_NAME=Stockwell Support');

        $mailer = new CapturingBrevoMailer();
        $mailer->send('customer@example.com', 'Order update', 'Plain text body', '<p>HTML body</p>');

        assertSame('test-brevo-key', $mailer->apiKey);
        assertSame([
            'sender' => ['email' => 'verified@example.com', 'name' => 'Stockwell Store'],
            'to' => [['email' => 'customer@example.com']],
            'subject' => 'Order update',
            'textContent' => 'Plain text body',
            'htmlContent' => '<p>HTML body</p>',
            'replyTo' => ['email' => 'support@example.com', 'name' => 'Stockwell Support']
        ], $mailer->payload);
    },
    'omits optional HTML and reply-to when they are not configured' => function () {
        putenv('BREVO_API_KEY=test-brevo-key');
        putenv('BREVO_FROM_EMAIL=verified@example.com');
        putenv('BREVO_FROM_NAME');
        putenv('BREVO_REPLY_TO');
        putenv('BREVO_REPLY_TO_NAME');

        $mailer = new CapturingBrevoMailer();
        $mailer->send('customer@example.com', 'Order update', 'Plain text body');

        assertSame('Stockwell', $mailer->payload['sender']['name']);
        assertSame('Plain text body', $mailer->payload['textContent']);
        assertFalse(array_key_exists('htmlContent', $mailer->payload));
        assertFalse(array_key_exists('replyTo', $mailer->payload));
    },
    'rejects invalid sender recipient and reply-to addresses' => function () {
        putenv('BREVO_API_KEY=test-brevo-key');
        putenv('BREVO_FROM_EMAIL=unverified-sender');
        $mailer = new CapturingBrevoMailer();
        assertThrows(function () use ($mailer) {
            $mailer->send('customer@example.com', 'Subject', 'Body');
        }, 'BREVO_FROM_EMAIL');

        putenv('BREVO_FROM_EMAIL=verified@example.com');
        assertThrows(function () use ($mailer) {
            $mailer->send('not-an-email', 'Subject', 'Body');
        }, 'recipient email');

        putenv('BREVO_REPLY_TO=not-an-email');
        assertThrows(function () use ($mailer) {
            $mailer->send('customer@example.com', 'Subject', 'Body');
        }, 'BREVO_REPLY_TO');
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertFalse($actual)
{
    if ($actual !== false) {
        throw new RuntimeException('Expected false, got ' . var_export($actual, true));
    }
}

function assertThrows($callback, $messagePart)
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), $messagePart) === false) {
            throw new RuntimeException('Expected error to contain ' . $messagePart . ', got ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected RuntimeException containing ' . $messagePart . '.');
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

putenv('BREVO_API_KEY');
putenv('BREVO_FROM_EMAIL');
putenv('BREVO_FROM_NAME');
putenv('BREVO_REPLY_TO');
putenv('BREVO_REPLY_TO_NAME');

if ($failures > 0) exit(1);
echo count($tests) . " tests passed.\n";
