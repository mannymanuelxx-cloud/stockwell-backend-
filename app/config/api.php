<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 4
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
|--------------------------------------------------------------------------
| Enable/Disable Migrations
|--------------------------------------------------------------------------
|
| Migrations are disabled by default for security reasons.
| You should enable migrations whenever you intend to do a schema migration
| and disable it back when you're done.
|
*/
$config['api_helper_enabled'] = TRUE;

/*
|--------------------------------------------------------------------------
| Payload Token Expiration
|--------------------------------------------------------------------------
|
| Used for Payload Token Expiration
|
*/
$config['payload_token_expiration'] = 900;


/*
|--------------------------------------------------------------------------
| Refresh Token Expiration
|--------------------------------------------------------------------------
|
| Used for Refresh Token Expiration
|
*/
$config['refresh_token_expiration'] = 604800;

$read_api_environment = static function ($key) {
    foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
        if (is_string($value) && $value !== '') return $value;
    }
    return '';
};

/*
|--------------------------------------------------------------------------
| JWT Secret Token
|--------------------------------------------------------------------------
|
| Used for Securing endpoint
|
*/
$config['jwt_secret'] = $read_api_environment('API_JWT_SECRET');

/*
|--------------------------------------------------------------------------
| Refresh Token
|--------------------------------------------------------------------------
|
| Used for Securing endpoint
|
*/
$config['refresh_token_key'] = $read_api_environment('API_REFRESH_TOKEN_KEY');

/*
|--------------------------------------------------------------------------
| Access-Control-Allow-Origin
|--------------------------------------------------------------------------
|
| Use an explicit trusted origin for credentialed cross-origin requests.
| A wildcard origin is allowed without credentials.
|
*/
$config['allow_origin'] = $read_api_environment('API_ALLOW_ORIGIN');

/*
|--------------------------------------------------------------------------
| Refresh Token Table
|--------------------------------------------------------------------------
|
| This is the name of the table that will store the Refresh Token.
|
*/
$config['refresh_token_table'] = 'refresh_tokens';

/*
|--------------------------------------------------------------------------
| JWT Issuer and Audience
|--------------------------------------------------------------------------
| These are used for JWT Issuer and Audience claims.
|
*/
$config['jwt_issuer'] = 'your-app';

/*
|--------------------------------------------------------------------------
| JWT Issuer and Audience
|--------------------------------------------------------------------------
| These are used for JWT Issuer and Audience claims.
|
*/

$config['jwt_audience'] = 'your-app-clients';

/*
|--------------------------------------------------------------------------
| Rate Limiting
|--------------------------------------------------------------------------
| These settings are used for API rate limiting.
|
*/
$config['rate_limit_enabled'] = true;

/*
|--------------------------------------------------------------------------
| Rate Limiting Requests and Seconds
|--------------------------------------------------------------------------
| These settings define the number of requests allowed and the time 
| window in seconds.
|
*/
$config['rate_limit_requests'] = 60;

/*
|--------------------------------------------------------------------------
| Rate Limiting Seconds
|--------------------------------------------------------------------------
| This setting defines the time window in seconds for rate limiting.
|
*/
$config['rate_limit_seconds'] = 60;