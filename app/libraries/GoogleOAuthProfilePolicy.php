<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class GoogleOAuthProfilePolicy
{
    public static function customerProfile(array $profile)
    {
        $subject = $profile['sub'] ?? null;
        $email = $profile['email'] ?? null;
        if (!is_string($subject) || trim($subject) === '' || strlen($subject) > 255
            || !is_string($email) || strlen(trim($email)) > 150
            || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || ($profile['email_verified'] ?? false) !== true) {
            throw new InvalidArgumentException('Google must provide a verified email address.');
        }

        $firstname = $profile['given_name'] ?? '';
        $lastname = $profile['family_name'] ?? '';
        if (!is_string($firstname)) $firstname = '';
        if (!is_string($lastname)) $lastname = '';

        return [
            'subject' => trim($subject),
            'email' => strtolower(trim($email)),
            'firstname' => mb_substr(trim($firstname) ?: 'Google', 0, 100),
            'lastname' => mb_substr(trim($lastname) ?: 'Customer', 0, 100)
        ];
    }
}
