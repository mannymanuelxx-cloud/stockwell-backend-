<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiCorsPolicy
{
    public static function headers($origin, $allowedOrigins)
    {
        $headers = [];
        $wildcard = !is_array($allowedOrigins) && $allowedOrigins === '*';
        $allowed = is_array($allowedOrigins)
            ? in_array($origin, $allowedOrigins, true)
            : ($wildcard || $allowedOrigins === $origin);

        if ($wildcard) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif ($allowed && $origin !== '') {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }
}
