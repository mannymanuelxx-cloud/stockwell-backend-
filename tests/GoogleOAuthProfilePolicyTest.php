<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/GoogleOAuthProfilePolicy.php';

$tests = [
    'accepts verified Google customer identity and normalizes email' => function () {
        $profile = GoogleOAuthProfilePolicy::customerProfile([
            'sub' => 'google-subject-123',
            'email' => ' Customer@Example.com ',
            'email_verified' => true,
            'given_name' => ' Customer ',
            'family_name' => ' Example '
        ]);
        assertSame([
            'subject' => 'google-subject-123',
            'email' => 'customer@example.com',
            'firstname' => 'Customer',
            'lastname' => 'Example'
        ], $profile);
    },
    'uses safe name fallbacks for Google profiles without names' => function () {
        $profile = GoogleOAuthProfilePolicy::customerProfile([
            'sub' => 'google-subject-456',
            'email' => 'customer@example.com',
            'email_verified' => true
        ]);
        assertSame('Google', $profile['firstname']);
        assertSame('Customer', $profile['lastname']);
    },
    'rejects unverified and malformed Google identities' => function () {
        foreach ([
            ['sub' => 'subject', 'email' => 'customer@example.com', 'email_verified' => false],
            ['sub' => 'subject', 'email' => 'not-an-email', 'email_verified' => true],
            ['sub' => '', 'email' => 'customer@example.com', 'email_verified' => true],
            ['sub' => str_repeat('s', 256), 'email' => 'customer@example.com', 'email_verified' => true],
            ['sub' => 'subject', 'email' => str_repeat('a', 145) . '@example.com', 'email_verified' => true]
        ] as $invalidProfile) {
            try {
                GoogleOAuthProfilePolicy::customerProfile($invalidProfile);
                throw new RuntimeException('Expected an invalid Google identity to be rejected.');
            } catch (InvalidArgumentException $error) {
                assertContains('verified email', $error->getMessage());
            }
        }
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains($expected, $actual)
{
    if (strpos($actual, $expected) === false) {
        throw new RuntimeException('Expected text not found: ' . $expected);
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
