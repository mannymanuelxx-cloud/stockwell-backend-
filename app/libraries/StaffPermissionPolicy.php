<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StaffPermissionPolicy
{
    public static function allowed()
    {
        return [
            'inventory.manage',
            'sales.manage',
            'orders.manage',
            'purchases.manage',
            'customers.manage',
            'suppliers.manage',
            'reports.view'
        ];
    }

    public static function validate(array $permissions)
    {
        if (array_values($permissions) !== $permissions
            || array_filter($permissions, 'is_string') !== $permissions
            || array_diff($permissions, self::allowed())
            || count($permissions) !== count(array_unique($permissions))) {
            throw new InvalidArgumentException('Choose only supported staff permissions.');
        }
        return $permissions;
    }
}
