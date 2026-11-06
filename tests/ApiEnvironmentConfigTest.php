<?php
define('PREVENT_DIRECT_ACCESS', true);

$failures = 0;

function assertConfigValue($expected, $actual, $message)
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message}\n";
        return;
    }
    echo "PASS: {$message}\n";
}

$originalEnv = $_ENV;
$originalServer = $_SERVER;
putenv('API_JWT_SECRET');
putenv('API_REFRESH_TOKEN_KEY');
putenv('API_ALLOW_ORIGIN');

$_ENV['API_JWT_SECRET'] = str_repeat('j', 48);
$_ENV['API_REFRESH_TOKEN_KEY'] = str_repeat('r', 48);
$_ENV['API_ALLOW_ORIGIN'] = 'https://store.example';
$_SERVER['API_JWT_SECRET'] = str_repeat('s', 48);

include __DIR__ . '/../app/config/api.php';

assertConfigValue(str_repeat('j', 48), $config['jwt_secret'], 'dotenv request values take precedence over process environment');
assertConfigValue(str_repeat('r', 48), $config['refresh_token_key'], 'refresh key reads from the request-local environment');
assertConfigValue('https://store.example', $config['allow_origin'], 'optional API origin reads from the request-local environment');

$_ENV = $originalEnv;
$_SERVER = $originalServer;

if ($failures > 0) exit(1);
