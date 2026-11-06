<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/StaffPermissionPolicy.php';

$tests = [
    'accepts a supported subset and an empty set' => function () {
        assertPermissionSet(['sales.manage', 'reports.view'], StaffPermissionPolicy::validate(['sales.manage', 'reports.view']));
        assertPermissionSet([], StaffPermissionPolicy::validate([]));
    },
    'rejects elevated, unknown, and non-string permissions' => function () {
        assertPermissionRejected(['users.manage']);
        assertPermissionRejected(['not-a-permission']);
        assertPermissionRejected([['orders.manage']]);
    },
    'rejects duplicate permissions and non-list input' => function () {
        assertPermissionRejected(['sales.manage', 'sales.manage']);
        assertPermissionRejected(['module' => 'sales.manage']);
    }
];

function assertPermissionSet($expected, $actual)
{
    if ($expected !== $actual) throw new RuntimeException('Permission set did not match.');
}

function assertPermissionRejected($permissions)
{
    try {
        StaffPermissionPolicy::validate($permissions);
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Expected invalid staff permissions to be rejected.');
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
