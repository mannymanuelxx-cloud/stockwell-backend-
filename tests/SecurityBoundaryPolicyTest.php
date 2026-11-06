<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/ApiCorsPolicy.php';
require_once __DIR__ . '/../app/libraries/SessionAuthenticationPolicy.php';

$failures = 0;

function assertPolicySame($expected, $actual, $message)
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message}\n";
        return;
    }
    echo "PASS: {$message}\n";
}

assertPolicySame(
    [
        'Access-Control-Allow-Origin' => '*'
    ],
    ApiCorsPolicy::headers('https://untrusted.example', '*'),
    'wildcard CORS never enables credentialed cross-origin access'
);
assertPolicySame(
    [
        'Access-Control-Allow-Origin' => 'https://store.example',
        'Access-Control-Allow-Credentials' => 'true'
    ],
    ApiCorsPolicy::headers('https://store.example', ['https://store.example']),
    'explicitly trusted origins retain credentialed CORS'
);
assertPolicySame(
    [],
    ApiCorsPolicy::headers('https://untrusted.example', ['https://store.example']),
    'untrusted origins receive no CORS permission'
);
assertPolicySame(
    true,
    SessionAuthenticationPolicy::passwordHashMatches('$2y$hash', '$2y$hash'),
    'sessions remain valid while the account password hash is unchanged'
);
assertPolicySame(
    false,
    SessionAuthenticationPolicy::passwordHashMatches('$2y$old', '$2y$new'),
    'password changes invalidate sessions authenticated with an old hash'
);
assertPolicySame(
    false,
    SessionAuthenticationPolicy::passwordHashMatches(null, '$2y$current'),
    'sessions without an authentication hash are rejected'
);

if ($failures > 0) exit(1);
