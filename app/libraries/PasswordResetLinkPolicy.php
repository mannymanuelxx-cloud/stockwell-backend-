<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PasswordResetLinkPolicy
{
    public static function frontendUrl($url, $environment)
    {
        if (!is_string($url) || trim($url) === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }

        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (strtolower((string) $environment) === 'production' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host !== 'localhost'
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return rtrim($url, '/');
    }
}
