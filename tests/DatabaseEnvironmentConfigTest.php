<?php
define('PREVENT_DIRECT_ACCESS', true);

$failures = 0;

function assertDatabaseConfigValue($expected, $actual, $message)
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
$keys = ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_NAME', 'DB_SSL_CA'];
foreach ($keys as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

$_ENV['DB_DRIVER'] = 'mysql';
$_SERVER['DB_HOST'] = 'database.example';
$_ENV['DB_PORT'] = '3307';
$_ENV['DB_USERNAME'] = 'stockwell_test';
$_ENV['DB_PASSWORD'] = 'test-password';
$_ENV['DB_NAME'] = 'stockwell_test';
$_SERVER['DB_SSL_CA'] = '/etc/secrets/aiven-ca.pem';

include __DIR__ . '/../app/config/database.php';

assertDatabaseConfigValue('mysql', $database['main']['driver'], 'database driver reads from request-local environment');
assertDatabaseConfigValue('database.example', $database['main']['hostname'], 'database host reads from server environment');
assertDatabaseConfigValue('3307', $database['main']['port'], 'database port reads from request-local environment');
assertDatabaseConfigValue('stockwell_test', $database['main']['username'], 'database username reads from request-local environment');
assertDatabaseConfigValue('test-password', $database['main']['password'], 'database password reads from request-local environment');
assertDatabaseConfigValue('stockwell_test', $database['main']['database'], 'database name reads from request-local environment');
assertDatabaseConfigValue('/etc/secrets/aiven-ca.pem', $database['main']['ssl_ca'], 'database SSL CA path reads from server environment');

$_ENV = $originalEnv;
$_SERVER = $originalServer;

if ($failures > 0) exit(1);
