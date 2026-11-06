<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/StaffPermissionPolicy.php';
require_once __DIR__ . '/../libraries/SessionAuthenticationPolicy.php';

/**
 * UsersModel
 *
 * Model for managing users data
 */
class UsersModel extends Model
{
    /**
     * Table associated with the model
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Primary key of the table
     *
     * @var string
     */
    protected $primary_key = 'id';

    /**
     * Fillable attributes for mass assignment
     *
     * @var array
     */
    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'username',
        'password',
        'role'
    ];

    public function ensureAuthSchema()
    {
        $this->db->raw("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$this->db->raw("SHOW COLUMNS FROM users LIKE 'firstname'")->fetch()) {
            $this->db->raw("ALTER TABLE users ADD COLUMN firstname VARCHAR(100) NOT NULL DEFAULT '' AFTER id");
        }
        if (!$this->db->raw("SHOW COLUMNS FROM users LIKE 'lastname'")->fetch()) {
            $this->db->raw("ALTER TABLE users ADD COLUMN lastname VARCHAR(100) NOT NULL DEFAULT '' AFTER firstname");
        }
        $roleColumn = $this->db->raw("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if (!$roleColumn) {
            $this->db->raw("ALTER TABLE users ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'customer'");
            $roleColumn = ['Type' => 'varchar(32)'];
        }
        $roleType = strtolower($roleColumn['Type'] ?? $roleColumn['type'] ?? '');
        if ($roleType !== "enum('admin','staff','customer')") {
            $this->db->raw("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'customer'");
            $this->db->raw("UPDATE users SET role = CASE
                WHEN role = 'admin' THEN 'admin'
                WHEN role = 'moderator' THEN 'staff'
                ELSE 'customer'
            END");
            $this->db->raw("ALTER TABLE users MODIFY role ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer'");
        }
        if (!$this->db->raw("SHOW COLUMNS FROM users LIKE 'permission_overrides'")->fetch()) {
            $this->db->raw("ALTER TABLE users ADD COLUMN permission_overrides TEXT NULL AFTER role");
        }

        if (!$this->db->raw("SHOW INDEX FROM users WHERE Key_name = 'users_username_unique'")->fetch()) {
            $duplicate = $this->db->raw('SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1 LIMIT 1')->fetch();
            if ($duplicate) {
                throw new RuntimeException('Duplicate usernames must be resolved before enabling customer registration.');
            }
            $this->db->raw('CREATE UNIQUE INDEX users_username_unique ON users (username)');
        }

        if (!$this->db->raw("SHOW INDEX FROM users WHERE Key_name = 'users_email_unique'")->fetch()) {
            $duplicate = $this->db->raw('SELECT email FROM users GROUP BY email HAVING COUNT(*) > 1 LIMIT 1')->fetch();
            if ($duplicate) {
                throw new RuntimeException('Duplicate email addresses must be resolved before enabling customer registration.');
            }
            $this->db->raw('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        }
    }

    public function permissionsForRole($role)
    {
        $permissions = [
            'admin' => [
                'inventory.manage',
                'sales.manage',
                'orders.manage',
                'purchases.manage',
                'customers.manage',
                'suppliers.manage',
                'reports.view',
                'users.manage',
                'activity.view',
                'backup.manage'
            ],
            'staff' => [
                'inventory.manage',
                'sales.manage',
                'orders.manage',
                'purchases.manage',
                'customers.manage',
                'suppliers.manage',
                'reports.view'
            ],
            'customer' => ['shop.use', 'orders.view_own']
        ];
        return $permissions[$role] ?? [];
    }

    public function staffPermissions()
    {
        return StaffPermissionPolicy::allowed();
    }

    public function permissionsForUser($userId, $role)
    {
        $rolePermissions = $this->permissionsForRole($role);
        if ($role !== 'staff' || !$userId) return $rolePermissions;
        $user = $this->db->raw(
            'SELECT permission_overrides FROM users WHERE id = ?',
            [$userId]
        )->fetch();
        if (!$user || $user['permission_overrides'] === null) return $rolePermissions;
        $permissions = json_decode($user['permission_overrides'], true);
        try {
            if (!is_array($permissions)) throw new InvalidArgumentException('Invalid permissions.');
            return StaffPermissionPolicy::validate($permissions);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Staff permission settings are invalid. Ask an administrator to review them.');
        }
    }

    public function hasPermission($role, $permission, $userId = null)
    {
        return in_array($permission, $this->permissionsForUser($userId, $role), true);
    }

    public function roleForSession($userId, $sessionPasswordHash)
    {
        $user = $this->db->raw(
            'SELECT role, password FROM users WHERE id = ?',
            [$userId]
        )->fetch();
        if (!$user || !SessionAuthenticationPolicy::passwordHashMatches(
            $sessionPasswordHash,
            $user['password'] ?? null
        )) {
            return null;
        }
        return $user['role'] ?? null;
    }

    public function issuePasswordReset($email)
    {
        $this->db->raw(
            'DELETE FROM password_reset_tokens WHERE expires_at < NOW()
             OR used_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $user = $this->db->raw(
            'SELECT id, email, firstname FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1',
            [$email]
        )->fetch();
        if (!$user) return null;

        $recent = $this->db->raw(
            'SELECT id FROM password_reset_tokens
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
             ORDER BY id DESC LIMIT 1',
            [(int) $user['id']]
        )->fetch();
        if ($recent) return null;

        $token = bin2hex(random_bytes(32));
        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $user['id']]
            );
            $this->db->table('password_reset_tokens')->insert([
                'user_id' => (int) $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 1800)
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return [
            'email' => $user['email'],
            'firstname' => $user['firstname'] ?? '',
            'token' => $token
        ];
    }

    public function invalidatePasswordReset($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('A valid password reset token is required.');
        }
        $this->db->raw(
            'UPDATE password_reset_tokens SET used_at = NOW()
             WHERE token_hash = ? AND used_at IS NULL',
            [hash('sha256', $token)]
        );
    }

    public function resetPassword($token, $password)
    {
        $this->db->transaction();
        try {
            $reset = $this->db->raw(
                'SELECT t.id, t.user_id FROM password_reset_tokens t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
                 LIMIT 1 FOR UPDATE',
                [hash('sha256', $token)]
            )->fetch();
            if (!$reset) {
                $this->db->roll_back();
                return false;
            }

            $this->db->table($this->table)->where('id', (int) $reset['user_id'])->update([
                'password' => password_hash($password, PASSWORD_DEFAULT)
            ]);
            $this->db->raw(
                'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $reset['user_id']]
            );
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function listManagedUsers()
    {
        $users = $this->db->raw(
            'SELECT id, firstname, lastname, email, username, role, permission_overrides
             FROM users ORDER BY firstname, lastname, username'
        )->fetchAll();
        foreach ($users as &$user) {
            $user['permissions'] = $this->permissionsForUser((int) $user['id'], $user['role']);
            unset($user['permission_overrides']);
        }
        unset($user);
        return $users;
    }

    public function createManagedUser(array $user)
    {
        $existing = $this->db->raw(
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$user['username'], $user['email']]
        )->fetch();
        if ($existing) {
            throw new InvalidArgumentException('That username or email is already registered.');
        }

        $this->db->table($this->table)->insert([
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'email' => $user['email'],
            'username' => $user['username'],
            'password' => password_hash($user['password'], PASSWORD_DEFAULT),
            'role' => $user['role']
        ]);
        return (int) $this->db->last_id();
    }

    public function updateManagedUserRole($userId, $role, $currentUserId)
    {
        $this->db->transaction();
        try {
            $admins = $this->db->raw("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetchAll();
            $user = $this->db->raw('SELECT id, role FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user) {
                throw new InvalidArgumentException('User not found.');
            }
            if ((int) $userId === (int) $currentUserId && $role !== 'admin') {
                throw new InvalidArgumentException('You cannot change your own administrator role.');
            }
            if ($user['role'] === 'admin' && $role !== 'admin' && count($admins) < 2) {
                throw new InvalidArgumentException('At least one administrator account must remain.');
            }

            $updates = ['role' => $role];
            if ($user['role'] !== $role) $updates['permission_overrides'] = null;
            $this->db->table($this->table)->where('id', $userId)->update($updates);
            if ($role === 'customer') {
                $profile = $this->db->raw('SELECT id FROM customers WHERE user_id = ? LIMIT 1', [$userId])->fetch();
                if (!$profile) {
                    $account = $this->db->raw(
                        'SELECT firstname, lastname, email FROM users WHERE id = ?',
                        [$userId]
                    )->fetch();
                    $this->db->table('customers')->insert([
                        'user_id' => $userId,
                        'name' => trim(($account['firstname'] ?? '') . ' ' . ($account['lastname'] ?? '')),
                        'email' => $account['email'] ?? null
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function updateManagedUserPermissions($userId, array $permissions)
    {
        $permissions = StaffPermissionPolicy::validate($permissions);
        $this->db->transaction();
        try {
            $user = $this->db->raw('SELECT id, role FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user) throw new InvalidArgumentException('User not found.');
            if ($user['role'] !== 'staff') {
                throw new InvalidArgumentException('Permissions can only be customized for staff accounts.');
            }
            $this->db->table($this->table)->where('id', $userId)->update([
                'permission_overrides' => json_encode(array_values($permissions), JSON_THROW_ON_ERROR)
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function deleteManagedUser($userId, $currentUserId)
    {
        $this->db->transaction();
        try {
            $admins = $this->db->raw("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetchAll();
            $user = $this->db->raw(
                'SELECT id, username, role FROM users WHERE id = ? FOR UPDATE',
                [$userId]
            )->fetch();
            if (!$user) {
                throw new InvalidArgumentException('User not found.');
            }
            if ((int) $userId === (int) $currentUserId) {
                throw new InvalidArgumentException('You cannot delete the account you are currently signed in with.');
            }
            if ($user['role'] === 'admin' && count($admins) < 2) {
                throw new InvalidArgumentException('The last administrator account cannot be deleted.');
            }

            $profile = $this->db->raw(
                'SELECT id FROM customers WHERE user_id = ? FOR UPDATE',
                [$userId]
            )->fetch();
            if ($profile) {
                $hasActivity = $this->db->raw(
                    'SELECT
                        EXISTS(SELECT 1 FROM sales WHERE customer_id = ?) AS has_sales,
                        EXISTS(SELECT 1 FROM customer_orders WHERE customer_id = ?) AS has_orders',
                    [$profile['id'], $profile['id']]
                )->fetch();
                if (!empty($hasActivity['has_sales']) || !empty($hasActivity['has_orders'])) {
                    throw new InvalidArgumentException(
                        'This customer account has sales or orders on record and cannot be deleted.'
                    );
                }
                $this->db->table('customers')->where('id', $profile['id'])->delete();
            }

            if ($this->db->raw("SHOW TABLES LIKE 'refresh_tokens'")->fetch()) {
                $this->db->table('refresh_tokens')->where('user_id', $userId)->delete();
            }
            if ($this->db->raw("SHOW TABLES LIKE 'password_reset_tokens'")->fetch()) {
                $this->db->table('password_reset_tokens')->where('user_id', $userId)->delete();
            }
            $this->db->table($this->table)->where('id', $userId)->delete();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function findForAuthentication($username)
    {
        $statement = $this->db->raw(
            'SELECT id, firstname, lastname, email, username, password, role
             FROM users
             WHERE username = ? OR email = ?
             LIMIT 1',
            [$username, $username]
        );

        return $statement->fetch();
    }

    public function registerCustomer($firstname, $lastname, $email, $username, $password, $address)
    {
        $existing = $this->db->raw(
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email]
        )->fetch();
        if ($existing) {
            throw new InvalidArgumentException('That username or email is already registered.');
        }

        $this->db->transaction();
        try {
            $this->db->table($this->table)->insert([
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'customer'
            ]);
            $userId = (int) $this->db->last_id();
            $this->db->table('customers')->insert([
                'user_id' => $userId,
                'name' => trim($firstname . ' ' . $lastname),
                'email' => $email,
                'address' => $address
            ]);
            $this->db->commit();
            return $this->findForAuthentication($username);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

}
