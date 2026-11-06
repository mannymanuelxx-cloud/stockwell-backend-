<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SessionAuthenticationPolicy
{
    public static function passwordHashMatches($sessionHash, $currentHash)
    {
        return is_string($sessionHash)
            && $sessionHash !== ''
            && is_string($currentHash)
            && $currentHash !== ''
            && hash_equals($currentHash, $sessionHash);
    }
}
