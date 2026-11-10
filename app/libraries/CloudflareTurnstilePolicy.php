<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CloudflareTurnstilePolicy
{
    public static function isValidRegistrationResponse(array $response, $expectedHostname)
    {
        $hostname = strtolower(trim((string) ($response['hostname'] ?? '')));
        $expectedHostname = strtolower(trim((string) $expectedHostname));
        return ($response['success'] ?? false) === true
            && ($response['action'] ?? '') === 'customer_registration'
            && $hostname !== ''
            && $expectedHostname !== ''
            && hash_equals($expectedHostname, $hostname);
    }
}
