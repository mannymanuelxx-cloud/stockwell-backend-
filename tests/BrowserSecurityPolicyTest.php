<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/BrowserSecurityPolicy.php';

$sameOriginServer = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'stockwell.example',
    'HTTPS' => 'on',
    'HTTP_ORIGIN' => 'https://stockwell.example'
];

$tests = [
    'same-origin unsafe requests pass with a matching session token' => function () use ($sameOriginServer) {
        $server = $sameOriginServer;
        $server['HTTP_X_CSRF_TOKEN'] = 'session-token';
        assertSame(null, BrowserSecurityPolicy::validateStateChangingRequest(
            true,
            $server,
            ['_csrf_token' => 'session-token']
        ));
    },
    'foreign Origin is rejected even when Referer is same-origin' => function () use ($sameOriginServer) {
        $server = $sameOriginServer;
        $server['HTTP_ORIGIN'] = 'https://attacker.example';
        $server['HTTP_REFERER'] = 'https://stockwell.example/account';
        assertSame(false, BrowserSecurityPolicy::requestOriginMatches($server));
    },
    'same-origin Referer is accepted as the Origin fallback' => function () use ($sameOriginServer) {
        $server = $sameOriginServer;
        unset($server['HTTP_ORIGIN']);
        $server['HTTP_REFERER'] = 'https://stockwell.example/account?tab=profile';
        assertSame(true, BrowserSecurityPolicy::requestOriginMatches($server));
    },
    'missing and opaque origins are rejected' => function () use ($sameOriginServer) {
        $server = $sameOriginServer;
        unset($server['HTTP_ORIGIN']);
        assertSame(false, BrowserSecurityPolicy::requestOriginMatches($server));
        $server['HTTP_ORIGIN'] = 'null';
        assertSame(false, BrowserSecurityPolicy::requestOriginMatches($server));
    },
    'scheme and port mismatches are rejected' => function () use ($sameOriginServer) {
        $server = $sameOriginServer;
        $server['HTTP_ORIGIN'] = 'http://stockwell.example';
        assertSame(false, BrowserSecurityPolicy::requestOriginMatches($server));
        $server['HTTP_ORIGIN'] = 'https://stockwell.example:444';
        assertSame(false, BrowserSecurityPolicy::requestOriginMatches($server));
    },
    'authenticated unsafe requests reject missing or incorrect CSRF tokens' => function () use ($sameOriginServer) {
        assertSame(
            'The security token is missing or invalid. Refresh the page and try again.',
            BrowserSecurityPolicy::validateStateChangingRequest(true, $sameOriginServer, ['_csrf_token' => 'expected'])
        );
        $server = $sameOriginServer;
        $server['HTTP_X_CSRF_TOKEN'] = 'incorrect';
        assertSame(
            'The security token is missing or invalid. Refresh the page and try again.',
            BrowserSecurityPolicy::validateStateChangingRequest(true, $server, ['_csrf_token' => 'expected'])
        );
    },
    'trusted storefront mutations require a matching CSRF token' => function () {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'api.example',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://store.example',
            'HTTP_X_CSRF_TOKEN' => 'session-token'
        ];
        assertSame(null, BrowserSecurityPolicy::validateStateChangingRequest(
            true,
            $server,
            ['_csrf_token' => 'session-token'],
            ['https://store.example']
        ));
        $server['HTTP_ORIGIN'] = 'https://attacker.example';
        assertSame('The request origin could not be verified.', BrowserSecurityPolicy::validateStateChangingRequest(
            true,
            $server,
            ['_csrf_token' => 'session-token'],
            ['https://store.example']
        ));
    },
    'safe requests do not require an Origin or CSRF token' => function () {
        assertSame(null, BrowserSecurityPolicy::validateStateChangingRequest(
            true,
            ['REQUEST_METHOD' => 'GET'],
            []
        ));
    },
    'public mutations accept explicitly trusted origins and reject untrusted cookie requests' => function () use ($sameOriginServer) {
        assertSame(null, BrowserSecurityPolicy::validateBrowserOriginIfPresent([
            'REQUEST_METHOD' => 'POST'
        ]));
        $server = $sameOriginServer;
        $server['HTTP_ORIGIN'] = 'https://attacker.example';
        assertSame(
            'The request origin could not be verified.',
            BrowserSecurityPolicy::validateBrowserOriginIfPresent($server)
        );
        assertSame(null, BrowserSecurityPolicy::validateBrowserOriginIfPresent(
            $server,
            ['https://attacker.example']
        ));
        $previousCookie = $_COOKIE[session_name()] ?? null;
        $_COOKIE[session_name()] = 'browser-session';
        $server['HTTP_ORIGIN'] = 'https://store.example';
        assertSame(null, BrowserSecurityPolicy::validateBrowserOriginIfPresent(
            $server,
            ['https://store.example']
        ));
        $server['HTTP_ORIGIN'] = 'https://attacker.example';
        assertSame(
            'The request origin could not be verified.',
            BrowserSecurityPolicy::validateBrowserOriginIfPresent($server, ['https://store.example'])
        );
        if ($previousCookie === null) {
            unset($_COOKIE[session_name()]);
        } else {
            $_COOKIE[session_name()] = $previousCookie;
        }
    },
    'HTTPS session cookies are Secure, HttpOnly, and SameSite configured' => function () {
        $previous = session_get_cookie_params();
        BrowserSecurityPolicy::configureSessionCookie(['HTTPS' => 'on']);
        $params = session_get_cookie_params();
        assertSame(true, $params['secure']);
        assertSame(true, $params['httponly']);
        assertSame('Strict', $params['samesite']);
        session_set_cookie_params($previous);
    },
    'cross-origin HTTPS storefront sessions use Secure SameSite=None cookies' => function () {
        $previous = session_get_cookie_params();
        BrowserSecurityPolicy::configureSessionCookie([
            'HTTP_HOST' => 'api.example',
            'HTTP_X_FORWARDED_PROTO' => 'https'
        ], ['https://store.example']);
        $params = session_get_cookie_params();
        assertSame(true, $params['secure']);
        assertSame(true, $params['httponly']);
        assertSame('None', $params['samesite']);
        session_set_cookie_params($previous);
    },
    'HTTP session cookies remain HttpOnly and SameSite configured' => function () {
        $previous = session_get_cookie_params();
        BrowserSecurityPolicy::configureSessionCookie([]);
        $params = session_get_cookie_params();
        assertSame(false, $params['secure']);
        assertSame(true, $params['httponly']);
        assertSame('Strict', $params['samesite']);
        session_set_cookie_params($previous);
    }
];

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

ob_start();
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

ob_end_flush();
if ($failures > 0) exit(1);
echo count($tests) . " tests passed.\n";
