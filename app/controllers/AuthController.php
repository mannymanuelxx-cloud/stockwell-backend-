<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/BrowserSecurityPolicy.php';
require_once __DIR__ . '/../libraries/PasswordResetLinkPolicy.php';
require_once __DIR__ . '/../libraries/GoogleOAuthProfilePolicy.php';
require_once __DIR__ . '/../libraries/CloudflareTurnstilePolicy.php';

class AuthController extends Controller
{
    private $api;

    public function before_action()
    {
        $this->apiLibrary();
        $this->UsersModel->ensureAuthSchema();
        $this->BusinessModel->ensureSchema();
    }

    public function login()
    {
        $this->startSession();

        if (!empty($_SESSION['user_id'])) {
            redirect('frontend/');
            return;
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->validateBrowserOrigin()) return;
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $user = $this->UsersModel->findForAuthentication($username);

            if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
                $this->authenticate($user);
                redirect('frontend/');
                return;
            }

            $error = 'Invalid username or password.';
        }

        $this->call->view('login', ['error' => $error]);
    }

    public function sessionStatus()
    {
        $this->startSession();
        header('Cache-Control: no-store, private');
        $csrfToken = BrowserSecurityPolicy::csrfToken();

        if (empty($_SESSION['user_id'])) {
            $this->respond(200, ['authenticated' => false, 'csrf_token' => $csrfToken]);
            return;
        }
        if (!$this->refreshSessionRole()) {
            $csrfToken = BrowserSecurityPolicy::csrfToken();
            $this->respond(200, ['authenticated' => false, 'csrf_token' => $csrfToken]);
            return;
        }

        $this->respond(200, [
            'authenticated' => true,
            'user' => $this->sessionUser(),
            'csrf_token' => $csrfToken
        ]);
    }

    public function publicConfig()
    {
        $turnstileSiteKey = trim((string) getenv('TURNSTILE_SITE_KEY'));
        $turnstileSecretKey = trim((string) getenv('TURNSTILE_SECRET_KEY'));
        $frontendUrl = PasswordResetLinkPolicy::frontendUrl(
            getenv('APP_FRONTEND_URL') ?: '',
            config_item('environment')
        );
        $turnstileEnabled = $turnstileSiteKey !== ''
            && $turnstileSecretKey !== ''
            && $frontendUrl !== null;

        $measurementId = trim((string) getenv('GA4_MEASUREMENT_ID'));
        if ($measurementId !== '' && !preg_match('/^G-[A-Z0-9]+$/', $measurementId)) {
            error_log('Google Analytics configuration is invalid; page-view tracking is disabled.');
            $measurementId = '';
        }
        require_once ROOT_DIR . 'app/libraries/ManualGcashCheckout.php';
        $gcashDetails = (new ManualGcashCheckout())->publicDetails();

        header('Cache-Control: public, max-age=300');
        $this->respond(200, [
            'integrations' => [
                'turnstile_site_key' => $turnstileEnabled ? $turnstileSiteKey : '',
                'ga4_measurement_id' => $measurementId,
                'manual_gcash' => $gcashDetails
            ]
        ]);
    }

    public function apiLogin()
    {
        if (!$this->validateBrowserOrigin()) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }

        $username = $payload['username'] ?? '';
        $password = $payload['password'] ?? '';
        if (!is_string($username) || !is_string($password) || trim($username) === '' || $password === '') {
            $this->respond(422, ['error' => 'Enter your username and password.']);
            return;
        }

        $username = trim($username);
        $user = $this->UsersModel->findForAuthentication($username);
        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            $this->respond(401, ['error' => 'Incorrect username or password.']);
            return;
        }

        $this->startSession();
        $this->authenticate($user);
        $this->respond(200, [
            'user' => $this->sessionUser(),
            'csrf_token' => BrowserSecurityPolicy::csrfToken()
        ]);
    }

    public function googleStart()
    {
        $config = $this->googleOAuthConfig();
        if ($config === null) {
            $this->redirectGoogleLoginError();
            return;
        }

        $this->startSession();
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_verifier'] = $verifier;
        $_SESSION['google_oauth_started_at'] = time();

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account'
        ]);
        header('Cache-Control: no-store');
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
        exit;
    }

    public function googleCallback()
    {
        $this->startSession();
        $expectedState = $_SESSION['google_oauth_state'] ?? '';
        $verifier = $_SESSION['google_oauth_verifier'] ?? '';
        $startedAt = (int) ($_SESSION['google_oauth_started_at'] ?? 0);
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_verifier'], $_SESSION['google_oauth_started_at']);

        $state = $_GET['state'] ?? '';
        $code = $_GET['code'] ?? '';
        if (!is_string($expectedState) || $expectedState === ''
            || !is_string($state) || !hash_equals($expectedState, $state)
            || !is_string($verifier) || $verifier === ''
            || $startedAt < time() - 600
            || !is_string($code) || $code === ''
            || isset($_GET['error'])) {
            $this->redirectGoogleLoginError();
            return;
        }

        $config = $this->googleOAuthConfig();
        if ($config === null) {
            $this->redirectGoogleLoginError();
            return;
        }

        try {
            $tokenResponse = $this->googleRequest(
                'https://oauth2.googleapis.com/token',
                'POST',
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                http_build_query([
                    'code' => $code,
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri' => $config['redirect_uri'],
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $verifier
                ])
            );
            $accessToken = $tokenResponse['access_token'] ?? '';
            if (!is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Google OAuth did not return an access token.');
            }

            $profile = $this->googleRequest(
                'https://openidconnect.googleapis.com/v1/userinfo',
                'GET',
                ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
            );
            $googleProfile = GoogleOAuthProfilePolicy::customerProfile($profile);
            $googleSub = $googleProfile['subject'];
            $email = $googleProfile['email'];

            $user = $this->UsersModel->findCustomerForGoogleSubject($googleSub);
            if (!$user) {
                $user = $this->UsersModel->findCustomerForGoogleEmail($email);
                if ($user && !empty($user['google_sub']) && !hash_equals((string) $user['google_sub'], $googleSub)) {
                    throw new RuntimeException('This customer account is linked to a different Google account.');
                }
                if ($user) {
                    $user = $this->UsersModel->linkGoogleSubjectToCustomer((int) $user['id'], $googleSub);
                } elseif ($this->UsersModel->findForAuthentication($email)) {
                    throw new RuntimeException('Google sign-in is available for customer accounts only.');
                } else {
                    $user = $this->UsersModel->registerGoogleCustomer(
                        $googleProfile['firstname'],
                        $googleProfile['lastname'],
                        $email,
                        $googleSub
                    );
                }
            }
            if (!$user || ($user['role'] ?? '') !== 'customer') {
                throw new RuntimeException('Google sign-in is available for customer accounts only.');
            }

            $this->authenticate($user);
            $frontendUrl = PasswordResetLinkPolicy::frontendUrl(
                getenv('APP_FRONTEND_URL') ?: '',
                config_item('environment')
            );
            if ($frontendUrl === null) {
                throw new RuntimeException('APP_FRONTEND_URL is not configured as a trusted frontend URL.');
            }
            header('Cache-Control: no-store');
            header('Location: ' . rtrim($frontendUrl, '/') . '/#shop', true, 302);
            exit;
        } catch (Throwable $error) {
            error_log('Google sign-in failed. Error type: ' . get_class($error) . '.');
            $this->redirectGoogleLoginError();
        }
    }

    public function apiRegister()
    {
        if (!$this->validateBrowserOrigin()) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }

        $firstname = $payload['firstname'] ?? null;
        $lastname = $payload['lastname'] ?? null;
        $email = $payload['email'] ?? null;
        $username = $payload['username'] ?? null;
        $password = $payload['password'] ?? null;
        $address = $payload['address'] ?? null;

        if (!is_string($firstname) || trim($firstname) === '' || mb_strlen(trim($firstname)) > 100
            || !is_string($lastname) || trim($lastname) === '' || mb_strlen(trim($lastname)) > 100) {
            $this->respond(422, ['error' => 'Enter your first and last name (100 characters or fewer each).']);
            return;
        }
        if (!is_string($email) || strlen(trim($email)) > 150 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['error' => 'Enter a valid email address (150 characters or fewer).']);
            return;
        }
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9._-]{3,100}$/', trim($username))) {
            $this->respond(422, ['error' => 'Username must be 3-100 characters and use letters, numbers, dots, underscores, or hyphens.']);
            return;
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, ['error' => 'Password must be between 8 and 72 characters.']);
            return;
        }
        if (!is_string($address) || strlen(trim($address)) < 15 || strlen(trim($address)) > 500) {
            $this->respond(422, ['error' => 'Enter a delivery address of 15 to 500 characters.']);
            return;
        }
        $turnstileToken = $payload['turnstile_token'] ?? '';
        if (!is_string($turnstileToken) || !$this->verifyRegistrationTurnstile($turnstileToken)) {
            return;
        }

        try {
            $user = $this->UsersModel->registerCustomer(
                trim($firstname),
                trim($lastname),
                strtolower(trim($email)),
                trim($username),
                $password,
                trim($address)
            );
        } catch (InvalidArgumentException $error) {
            $this->respond(409, ['error' => $error->getMessage()]);
            return;
        }

        $user['address'] = trim($address);
        $this->authenticate($user);
        $this->respond(201, [
            'user' => $this->sessionUser(),
            'csrf_token' => BrowserSecurityPolicy::csrfToken()
        ]);
    }

    public function forgotPassword()
    {
        if (!$this->validateBrowserOrigin()) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $email = $payload['email'] ?? null;
        if (!is_string($email) || strlen(trim($email)) > 150 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['error' => 'Enter a valid email address.']);
            return;
        }

        $frontendUrl = PasswordResetLinkPolicy::frontendUrl(
            getenv('APP_FRONTEND_URL') ?: '',
            config_item('environment')
        );
        if ($frontendUrl === null) {
            error_log('Password recovery is unavailable: APP_FRONTEND_URL must be configured as a trusted absolute URL (HTTPS in production).');
            $this->respond(503, ['error' => 'Password recovery is temporarily unavailable. Please contact support.']);
            return;
        }

        try {
            $reset = $this->UsersModel->issuePasswordReset(strtolower(trim($email)));
        } catch (Throwable $error) {
            error_log('Password reset request could not be processed. Error type: ' . get_class($error));
            $reset = null;
        }
        if ($reset) {
            $link = $frontendUrl . '/#reset-password/' . rawurlencode($reset['token']);
            $name = trim((string) $reset['firstname']) ?: 'customer';
            $text = "Hi {$name},\n\nUse this link to reset your Stockwell password. It expires in 30 minutes and can only be used once:\n\n{$link}\n\nIf you did not request this change, you can ignore this email.";
            $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
                . '<p>Use this link to reset your Stockwell password. It expires in 30 minutes and can only be used once.</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Reset password</a></p>'
                . '<p>If you did not request this change, you can ignore this email.</p>';
            try {
                require_once ROOT_DIR . 'app/libraries/StoreMailer.php';
                (new StoreMailer())->send($reset['email'], 'Reset your Stockwell password', $text, $html);
            } catch (Throwable $error) {
                try {
                    $this->UsersModel->invalidatePasswordReset($reset['token']);
                    error_log('Password reset email delivery failed; its token was invalidated so the user can safely retry. Error type: ' . get_class($error));
                } catch (Throwable $invalidateError) {
                    error_log(
                        'Password reset email delivery failed and its token could not be invalidated. Delivery error type: '
                        . get_class($error)
                        . '; cleanup error type: '
                        . get_class($invalidateError)
                    );
                }
            }
        }

        $this->respond(200, ['message' => 'If an account matches that email, a password reset link will be sent shortly.']);
    }

    public function resetPassword()
    {
        if (!$this->validateBrowserOrigin()) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $token = $payload['token'] ?? null;
        $password = $payload['password'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->respond(422, ['error' => 'This password reset link is invalid or expired. Request a new one.']);
            return;
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, ['error' => 'Password must be between 8 and 72 characters.']);
            return;
        }
        if (!$this->UsersModel->resetPassword($token, $password)) {
            $this->respond(422, ['error' => 'This password reset link is invalid or expired. Request a new one.']);
            return;
        }
        $this->respond(200, ['success' => true, 'message' => 'Password updated. You can now sign in.']);
    }

    public function apiLogout()
    {
        if (!$this->validateBrowserOrigin()) return;
        $this->startSession();
        if (!empty($_SESSION['user_id'])) {
            $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
            if ($error !== null) {
                $this->respond(403, ['error' => $error]);
                return;
            }
        }
        $this->destroySession();
        $this->respond(200, ['success' => true]);
    }

    public function logout()
    {
        if (!$this->validateBrowserOrigin()) return;
        $this->startSession();
        if (!empty($_SESSION['user_id'])) {
            $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
            if ($error !== null) {
                $this->respond(403, ['error' => $error]);
                return;
            }
        }
        $this->destroySession();
        redirect('login');
    }

    private function startSession()
    {
        BrowserSecurityPolicy::startSession();
    }

    private function authenticate(array $user)
    {
        $role = $user['role'] ?? '';
        if (!in_array($role, ['admin', 'staff', 'customer'], true)) {
            throw new RuntimeException('This account has an unsupported role. Ask an administrator to review it.');
        }
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['firstname'] = $user['firstname'];
        $_SESSION['lastname'] = $user['lastname'];
        $_SESSION['role'] = $role;
        $_SESSION['address'] = $user['address'] ?? '';
        $_SESSION['auth_password_hash'] = $user['password'];
    }

    private function sessionUser()
    {
        $role = $_SESSION['role'];
        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'firstname' => $_SESSION['firstname'] ?? '',
            'lastname' => $_SESSION['lastname'] ?? '',
            'role' => $role,
            'permissions' => $this->UsersModel->permissionsForUser((int) $_SESSION['user_id'], $role),
            'address' => $_SESSION['address'] ?? ''
        ];
    }

    private function refreshSessionRole()
    {
        $role = $this->UsersModel->roleForSession(
            (int) $_SESSION['user_id'],
            $_SESSION['auth_password_hash'] ?? null
        );
        if (!in_array($role, ['admin', 'staff', 'customer'], true)) {
            $_SESSION = [];
            return false;
        }
        $_SESSION['role'] = $role;
        return true;
    }

    private function destroySession()
    {
        $this->startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        }

        session_destroy();
    }

    private function googleOAuthConfig()
    {
        $clientId = trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
        $clientSecret = trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
        $redirectUri = trim((string) getenv('GOOGLE_OAUTH_REDIRECT_URI'));
        $parts = $redirectUri !== '' ? parse_url($redirectUri) : false;
        if ($clientId === '' || $clientSecret === '' || $parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || ($parts['path'] ?? '') !== '/api/auth/google/callback') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri
        ];
    }

    private function googleRequest($url, $method, array $headers, $body = null)
    {
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1
        ];
        if ($body !== null) $options['content'] = $body;

        $context = stream_context_create([
            'http' => $options,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Google OAuth request failed with HTTP ' . $status . '.');
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Google OAuth returned an invalid response.');
        }
        return $payload;
    }

    private function redirectGoogleLoginError()
    {
        $frontendUrl = PasswordResetLinkPolicy::frontendUrl(
            getenv('APP_FRONTEND_URL') ?: '',
            config_item('environment')
        );
        if ($frontendUrl === null) {
            $this->respond(503, ['error' => 'Google sign-in is unavailable. Please use your Stockwell username and password.']);
            return;
        }
        header('Cache-Control: no-store');
        header('Location: ' . rtrim($frontendUrl, '/') . '/?auth_error=google', true, 302);
        exit;
    }

    private function verifyRegistrationTurnstile($token)
    {
        $siteKey = trim((string) getenv('TURNSTILE_SITE_KEY'));
        $secretKey = trim((string) getenv('TURNSTILE_SECRET_KEY'));
        if ($siteKey === '' && $secretKey === '') return true;
        if ($siteKey === '' || $secretKey === '') {
            error_log('Cloudflare Turnstile configuration is incomplete.');
            $this->respond(503, ['error' => 'Customer registration security check is temporarily unavailable. Please try again later.']);
            return false;
        }
        if ($token === '' || strlen($token) > 2048) {
            $this->respond(403, ['error' => 'Complete the security check before creating your account.']);
            return false;
        }

        $frontendUrl = PasswordResetLinkPolicy::frontendUrl(
            getenv('APP_FRONTEND_URL') ?: '',
            config_item('environment')
        );
        $frontendParts = $frontendUrl !== null ? parse_url($frontendUrl) : false;
        $expectedHostname = is_array($frontendParts) ? strtolower((string) ($frontendParts['host'] ?? '')) : '';
        if ($expectedHostname === '') {
            error_log('Cloudflare Turnstile cannot verify registration without a valid APP_FRONTEND_URL.');
            $this->respond(503, ['error' => 'Customer registration security check is temporarily unavailable. Please try again later.']);
            return false;
        }

        $options = [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
            'content' => http_build_query([
                'secret' => $secretKey,
                'response' => $token,
                'idempotency_key' => $this->turnstileIdempotencyKey()
            ]),
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1
        ];
        $context = stream_context_create([
            'http' => $options,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if (!is_string($response) || $status < 200 || $status >= 300) {
            error_log('Cloudflare Turnstile verification request failed with HTTP ' . $status . '.');
            $this->respond(503, ['error' => 'Customer registration security check is temporarily unavailable. Please try again later.']);
            return false;
        }

        $verification = json_decode($response, true);
        if (!is_array($verification)) {
            error_log('Cloudflare Turnstile returned an invalid verification response.');
            $this->respond(503, ['error' => 'Customer registration security check is temporarily unavailable. Please try again later.']);
            return false;
        }
        if (!CloudflareTurnstilePolicy::isValidRegistrationResponse($verification, $expectedHostname)) {
            $this->respond(403, ['error' => 'The security check could not be verified. Please try again.']);
            return false;
        }

        return true;
    }

    private function turnstileIdempotencyKey()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function readPayload()
    {
        $payload = $this->apiLibrary()->body();
        array_walk_recursive($payload, function (&$value) {
            if (is_string($value)) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });
        return is_array($payload) ? $payload : null;
    }

    private function respond($status, array $payload)
    {
        $this->apiLibrary()->respond($payload, $status);
    }

    private function validateBrowserOrigin()
    {
        $error = BrowserSecurityPolicy::validateBrowserOriginIfPresent();
        if ($error === null) return true;
        $this->respond(403, ['error' => $error]);
        return false;
    }

    private function apiLibrary()
    {
        if ($this->api === null) {
            $this->api = $this->call->library('api');
        }
        return $this->api;
    }
}
