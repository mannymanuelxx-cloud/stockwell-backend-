<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StockAdjustmentPolicy
{
    public static function validate($delta, $reason)
    {
        $delta = filter_var($delta, FILTER_VALIDATE_INT);
        if ($delta === false || $delta === 0 || abs($delta) > 1000000) {
            return ['error' => 'Enter a stock change between -1,000,000 and 1,000,000, excluding zero.'];
        }
        if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 255) {
            return ['error' => 'A reason is required and must be 255 characters or fewer.'];
        }
        return ['quantity_change' => $delta, 'reason' => trim($reason)];
    }
}
