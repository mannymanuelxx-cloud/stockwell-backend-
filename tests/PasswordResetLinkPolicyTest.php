<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/PasswordResetLinkPolicy.php';

$tests = [
    'production accepts a configured HTTPS frontend URL' => function () {
        assertSame('https://stockwell.example/frontend', PasswordResetLinkPolicy::frontendUrl(
            'https://stockwell.example/frontend/',
            'production'
        ));
    },
    'production rejects insecure HTTP frontend URLs' => function () {
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'http://stockwell.example/frontend',
            'production'
        ));
    },
    'development allows a local HTTP frontend URL' => function () {
        assertSame('http://localhost/LavaLust/frontend', PasswordResetLinkPolicy::frontendUrl(
            'http://localhost/LavaLust/frontend/',
            'development'
        ));
    },
    'missing and relative URLs are rejected' => function () {
        assertSame(null, PasswordResetLinkPolicy::frontendUrl('', 'development'));
        assertSame(null, PasswordResetLinkPolicy::frontendUrl('/frontend', 'development'));
    },
    'URLs containing credentials, query strings, or fragments are rejected' => function () {
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'https://user:pass@stockwell.example/frontend',
            'production'
        ));
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'https://stockwell.example/frontend?next=attacker.example',
            'production'
        ));
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'https://stockwell.example/frontend#attacker',
            'production'
        ));
    },
    'unsupported schemes and malformed hosts are rejected' => function () {
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'javascript://stockwell.example',
            'development'
        ));
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'https://bad host/frontend',
            'development'
        ));
    },
    'control characters and backslashes are rejected' => function () {
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            "https://stockwell.example/\r\nLocation: https://attacker.example",
            'development'
        ));
        assertSame(null, PasswordResetLinkPolicy::frontendUrl(
            'https://stockwell.example\@attacker.example',
            'development'
        ));
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
