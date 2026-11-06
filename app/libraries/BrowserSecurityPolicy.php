<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class BrowserSecurityPolicy
{
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        self::configureSessionCookie();
        session_start();
    }

    public static function configureSessionCookie(array $server = null, $allowedApiOrigins = null)
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $server = $server ?? $_SERVER;
        $params = session_get_cookie_params();
        $sameSite = function_exists('config_item') ? config_item('cookie_samesite') : 'Strict';
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) $sameSite = 'Lax';
        $secure = self::isHttps($server)
            || (function_exists('config_item') && !empty(config_item('cookie_secure')));
        if ($secure && self::hasCrossOriginApiOrigin($server, $allowedApiOrigins)) {
            $sameSite = 'None';
        }

        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', $sameSite);
    }

    public static function isHttps(array $server = null)
    {
        $server = $server ?? $_SERVER;
        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || $forwardedProto === 'https';
    }

    public static function csrfToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) self::startSession();
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function validateStateChangingRequest(
        $requireToken,
        array $server = null,
        array $session = null,
        $allowedApiOrigins = null
    )
    {
        $server = $server ?? $_SERVER;
        if (!self::isUnsafeMethod($server['REQUEST_METHOD'] ?? 'GET')) return null;
        if (!self::requestOriginMatches($server)
            && !self::matchesTrustedApiOrigin($server, $allowedApiOrigins)) {
            return 'The request origin could not be verified.';
        }

        if ($requireToken) {
            $session = $session ?? $_SESSION;
            $expected = $session['_csrf_token'] ?? '';
            $provided = $server['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!is_string($expected) || $expected === '' || !is_string($provided)
                || $provided === '' || !hash_equals($expected, $provided)) {
                return 'The security token is missing or invalid. Refresh the page and try again.';
            }
        }

        return null;
    }

    public static function validateBrowserOriginIfPresent(array $server = null, $allowedApiOrigins = null)
    {
        $server = $server ?? $_SERVER;
        if (!self::isUnsafeMethod($server['REQUEST_METHOD'] ?? 'GET')) return null;
        $hasOrigin = isset($server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== '';
        $hasReferer = isset($server['HTTP_REFERER']) && $server['HTTP_REFERER'] !== '';
        if (!$hasOrigin && !$hasReferer) return null;
        if (self::requestOriginMatches($server)) return null;

        if (self::matchesTrustedApiOrigin($server, $allowedApiOrigins)) return null;

        return 'The request origin could not be verified.';
    }

    private static function matchesTrustedApiOrigin(array $server, $allowedApiOrigins = null)
    {
        if ($allowedApiOrigins === null && function_exists('config_item')) {
            $allowedApiOrigins = config_item('allow_origin');
        }
        if (!is_array($allowedApiOrigins)) $allowedApiOrigins = [$allowedApiOrigins];

        if (isset($server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== '') {
            $requestOrigin = self::normalizeOrigin($server['HTTP_ORIGIN'], false);
        } elseif (isset($server['HTTP_REFERER']) && $server['HTTP_REFERER'] !== '') {
            $requestOrigin = self::normalizeOrigin($server['HTTP_REFERER'], true);
        } else {
            return false;
        }
        if ($requestOrigin === null) return false;

        foreach ($allowedApiOrigins as $allowedOrigin) {
            if (!is_string($allowedOrigin) || $allowedOrigin === '' || $allowedOrigin === '*') continue;
            $normalizedAllowedOrigin = self::normalizeOrigin($allowedOrigin, false);
            if ($normalizedAllowedOrigin !== null && hash_equals($requestOrigin, $normalizedAllowedOrigin)) {
                return true;
            }
        }
        return false;
    }

    private static function hasCrossOriginApiOrigin(array $server, $allowedApiOrigins = null)
    {
        if ($allowedApiOrigins === null && function_exists('config_item')) {
            $allowedApiOrigins = config_item('allow_origin');
        }
        if (!is_array($allowedApiOrigins)) $allowedApiOrigins = [$allowedApiOrigins];

        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host === '' || preg_match('/[\s\/\\\\]/', $host)) return false;
        $apiOrigin = self::normalizeOrigin(self::requestScheme($server) . '://' . $host, false);
        if ($apiOrigin === null) return false;

        foreach ($allowedApiOrigins as $allowedOrigin) {
            if (!is_string($allowedOrigin) || $allowedOrigin === '' || $allowedOrigin === '*') continue;
            $normalizedAllowedOrigin = self::normalizeOrigin($allowedOrigin, false);
            if ($normalizedAllowedOrigin !== null && !hash_equals($apiOrigin, $normalizedAllowedOrigin)) {
                return true;
            }
        }
        return false;
    }

    public static function isUnsafeMethod($method)
    {
        return in_array(strtoupper((string) $method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public static function requestOriginMatches(array $server)
    {
        $scheme = self::requestScheme($server);
        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host === '' || preg_match('/[\s\/\\\\]/', $host)) return false;

        $expected = self::normalizeOrigin($scheme . '://' . $host, false);
        if ($expected === null) return false;

        if (isset($server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== '') {
            $actual = self::normalizeOrigin($server['HTTP_ORIGIN'], false);
        } elseif (isset($server['HTTP_REFERER']) && $server['HTTP_REFERER'] !== '') {
            $actual = self::normalizeOrigin($server['HTTP_REFERER'], true);
        } else {
            return false;
        }

        return $actual !== null && hash_equals($expected, $actual);
    }

    private static function requestScheme(array $server)
    {
        return self::isHttps($server) ? 'https' : 'http';
    }

    private static function normalizeOrigin($url, $allowPath)
    {
        if (!is_string($url) || $url === '' || strtolower($url) === 'null') return null;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (!$allowPath && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']))) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($host === '' || $port < 1 || $port > 65535) return null;
        return $scheme . '://' . $host . ':' . $port;
    }
}
