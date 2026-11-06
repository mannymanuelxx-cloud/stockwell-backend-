<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/BrowserSecurityPolicy.php';

class UsersController extends Controller
{
    private $api;

    public function before_action()
    {
        $this->api = $this->call->library('api');
        $this->UsersModel->ensureAuthSchema();
        $this->BusinessModel->ensureSchema();
    }

    public function index()
    {
        if (!$this->requireAdministrator()) return;
        $this->respond(200, [
            'data' => $this->UsersModel->listManagedUsers(),
            'roles' => $this->roleOptions()
        ]);
    }

    public function store()
    {
        if (!$this->requireAdministrator()) return;
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
        $role = $payload['role'] ?? 'staff';

        if (!is_string($firstname) || trim($firstname) === '' || mb_strlen(trim($firstname)) > 100
            || !is_string($lastname) || trim($lastname) === '' || mb_strlen(trim($lastname)) > 100) {
            $this->respond(422, ['error' => 'Enter first and last names (100 characters or fewer each).']);
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
        if (!is_string($role) || !in_array($role, ['admin', 'staff'], true)) {
            $this->respond(422, ['error' => 'New accounts can only be assigned the Administrator or Staff role.']);
            return;
        }

        try {
            $userId = $this->UsersModel->createManagedUser([
                'firstname' => trim($firstname),
                'lastname' => trim($lastname),
                'email' => strtolower(trim($email)),
                'username' => trim($username),
                'password' => $password,
                'role' => $role
            ]);
        } catch (InvalidArgumentException $error) {
            $this->respond(409, ['error' => $error->getMessage()]);
            return;
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $error;
            $this->respond(409, ['error' => 'That username or email is already registered.']);
            return;
        }

        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Admin', 'created', 'user', $userId, trim($username) . ' (' . $role . ')');
        $this->respond(201, ['data' => $this->UsersModel->listManagedUsers()]);
    }

    public function updateRole($id = null)
    {
        if (!$this->requireAdministrator()) return;
        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            $this->respond(400, ['error' => 'A valid user ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null || !isset($payload['role']) || !is_string($payload['role'])
            || !in_array($payload['role'], ['admin', 'staff', 'customer'], true)) {
            $this->respond(422, ['error' => 'Choose a valid user role.']);
            return;
        }

        try {
            $this->UsersModel->updateManagedUserRole(
                $userId,
                $payload['role'],
                (int) $_SESSION['user_id']
            );
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'User not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
            return;
        }

        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Admin', 'changed role', 'user', $userId, $payload['role']);
        $this->respond(200, ['data' => $this->UsersModel->listManagedUsers()]);
    }

    public function updatePermissions($id = null)
    {
        if (!$this->requireAdministrator()) return;
        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            $this->respond(400, ['error' => 'A valid user ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null || !isset($payload['permissions']) || !is_array($payload['permissions'])
            || array_values($payload['permissions']) !== $payload['permissions']
            || array_filter($payload['permissions'], 'is_string') !== $payload['permissions']) {
            $this->respond(422, ['error' => 'Choose a valid list of staff permissions.']);
            return;
        }
        try {
            $this->UsersModel->updateManagedUserPermissions($userId, $payload['permissions']);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'User not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
            return;
        }
        $this->BusinessModel->logActivity(
            (int) $_SESSION['user_id'],
            $_SESSION['username'] ?? 'Admin',
            'updated permissions',
            'user',
            $userId,
            implode(', ', $payload['permissions'])
        );
        $this->respond(200, ['data' => $this->UsersModel->listManagedUsers()]);
    }

    public function destroy($id = null)
    {
        if (!$this->requireAdministrator()) return;
        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            $this->respond(400, ['error' => 'A valid user ID is required.']);
            return;
        }

        $managedUser = null;
        foreach ($this->UsersModel->listManagedUsers() as $user) {
            if ((int) $user['id'] === $userId) {
                $managedUser = $user;
                break;
            }
        }
        try {
            $this->UsersModel->deleteManagedUser($userId, (int) $_SESSION['user_id']);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'User not found.' ? 404 : 409;
            $this->respond($status, ['error' => $error->getMessage()]);
            return;
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $error;
            $this->respond(409, ['error' => 'This account is linked to existing records and cannot be deleted.']);
            return;
        }

        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Admin', 'deleted', 'user', $userId, $managedUser['username'] ?? null);
        $this->respond(200, ['success' => true]);
    }

    private function roleOptions()
    {
        return [
            'admin' => [
                'label' => 'Administrator',
                'description' => 'Full access, including user management, activity history, and database backups.',
                'permissions' => $this->UsersModel->permissionsForRole('admin')
            ],
            'staff' => [
                'label' => 'Staff',
                'description' => 'Operational modules are enabled by default and can be restricted per staff account.',
                'permissions' => $this->UsersModel->permissionsForRole('staff'),
                'customizable_permissions' => $this->UsersModel->staffPermissions()
            ],
            'customer' => [
                'label' => 'Customer',
                'description' => 'Shop and view their own orders only.',
                'permissions' => $this->UsersModel->permissionsForRole('customer')
            ]
        ];
    }

    private function requireAdministrator()
    {
        BrowserSecurityPolicy::startSession();
        if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
            $this->respond(401, ['error' => 'Please sign in to manage users.']);
            return false;
        }
        $role = $this->UsersModel->roleForSession(
            (int) $_SESSION['user_id'],
            $_SESSION['auth_password_hash'] ?? null
        );
        if ($role === null) {
            $_SESSION = [];
            $this->respond(401, ['error' => 'Your session is no longer valid. Please sign in again.']);
            return false;
        }
        $_SESSION['role'] = $role;
        if ($this->UsersModel->hasPermission($role, 'users.manage', (int) $_SESSION['user_id'])) {
            $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
            if ($error !== null) {
                $this->respond(403, ['error' => $error]);
                return false;
            }
            return true;
        }
        $this->respond(403, ['error' => 'Administrator access is required to manage users.']);
        return false;
    }

    private function readPayload()
    {
        $payload = $this->api->body();
        array_walk_recursive($payload, function (&$value) {
            if (is_string($value)) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });
        return is_array($payload) ? $payload : null;
    }

    private function respond($status, array $payload)
    {
        $this->api->respond($payload, $status);
    }
}
