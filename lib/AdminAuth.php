<?php
/**
 * Admin panel authentication with role based permissions and login throttling.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class AdminAuth
{
    private const SESSION_KEY = 'admin_user';
    private static ?array $user = null;
    private static ?array $permissions = null;

    public static function attempt(string $identifier, string $password, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $scope = 'admin';
        [$allowed, , $retry] = Security::throttleStatus($scope, strtolower($identifier));
        if (!$allowed) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Please try again in ' . ceil($retry / 60) . ' minute(s).'];
        }

        $db = Database::instance();
        $admin = $db->first(
            'SELECT * FROM admin_users WHERE LOWER(email) = :id OR LOWER(username) = :id LIMIT 1',
            ['id' => strtolower($identifier)]
        );
        if (!$admin || !Security::verifyPassword($password, (string) $admin['password_hash'])) {
            Security::recordFailure($scope, strtolower($identifier));
            return ['ok' => false, 'message' => 'Invalid credentials. Please check your username or password.'];
        }
        if (($admin['status'] ?? 'inactive') !== 'active') {
            return ['ok' => false, 'message' => 'This account is inactive. Contact a super administrator.'];
        }

        Security::clearFailures($scope, strtolower($identifier));
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int) $admin['id']);
        Session::set('admin_login_at', time());
        $db->update('admin_users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => Request::ip(),
        ], 'id = :id', ['id' => $admin['id']]);

        // Re-hash if the algorithm cost changed.
        if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('admin_users', ['password_hash' => Security::hashPassword($password)], 'id = :id', ['id' => $admin['id']]);
        }

        if ($remember) {
            $token = Security::randomToken(32);
            $db->update('admin_users', ['remember_token' => Security::hashToken($token)], 'id = :id', ['id' => $admin['id']]);
            setcookie('saee_admin_remember', $admin['id'] . ':' . $token, [
                'expires' => time() + REMEMBER_LIFETIME, 'path' => '/', 'httponly' => true,
                'samesite' => 'Lax', 'secure' => Request::isSecure(),
            ]);
        }

        Audit::log('login', 'auth', 'Administrator signed in');
        self::$user = null;
        self::$permissions = null;
        return ['ok' => true, 'message' => 'Welcome back, ' . $admin['name'] . '!'];
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get(self::SESSION_KEY);
        if (!$id) {
            self::$user = self::viaRememberCookie();
            if (self::$user !== null) {
                Session::set(self::SESSION_KEY, (int) self::$user['id']);
            }
            return self::$user;
        }
        $row = Database::instance()->first(
            'SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.is_super FROM admin_users u LEFT JOIN admin_roles r ON r.id = u.role_id WHERE u.id = :id AND u.status = :s',
            ['id' => $id, 's' => 'active']
        );
        if (!$row) {
            Session::remove(self::SESSION_KEY);
            return null;
        }
        self::$user = $row;
        return self::$user;
    }

    private static function viaRememberCookie(): ?array
    {
        $cookie = $_COOKIE['saee_admin_remember'] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }
        [$id, $token] = explode(':', $cookie, 2);
        $row = Database::instance()->first(
            'SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.is_super FROM admin_users u LEFT JOIN admin_roles r ON r.id = u.role_id WHERE u.id = :id AND u.status = :s',
            ['id' => (int) $id, 's' => 'active']
        );
        if ($row && !empty($row['remember_token']) && hash_equals((string) $row['remember_token'], Security::hashToken($token))) {
            return $row;
        }
        return null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user() !== null ? (int) self::user()['id'] : null;
    }

    public static function isSuper(): bool
    {
        $user = self::user();
        return $user !== null && (int) ($user['is_super'] ?? 0) === 1;
    }

    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        if ($user === null) {
            return [];
        }
        if ((int) ($user['is_super'] ?? 0) === 1) {
            self::$permissions = Database::instance()->column('SELECT slug FROM admin_permissions');
            return self::$permissions;
        }
        self::$permissions = Database::instance()->column(
            'SELECT p.slug FROM admin_permissions p
             INNER JOIN admin_role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role',
            ['role' => $user['role_id']]
        );
        return self::$permissions;
    }

    public static function can(string $permission): bool
    {
        if (self::isSuper()) {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (Request::isAjax()) {
                Response::error('Your session has expired. Please sign in again.', [], 401);
            }
            $target = Request::uri() . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
            Session::set('admin_redirect', $target);
            Session::flash('warning', 'Please sign in to continue.');
            Request::redirect('login');
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            Audit::log('permission_denied', 'security', 'Denied: ' . $permission);
            if (Request::isAjax()) {
                Response::error('You do not have permission to perform this action.', [], 403);
            }
            http_response_code(403);
            Session::flash('danger', 'You do not have permission to access that module.');
            Request::redirect('dashboard');
        }
        if (in_array(Request::method(), ['POST', 'PUT', 'DELETE'], true)) {
            Security::verifyCsrf();
        }
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Database::instance()->update('admin_users', ['remember_token' => null], 'id = :id', ['id' => $user['id']]);
            Audit::log('logout', 'auth', 'Administrator signed out');
        }
        setcookie('saee_admin_remember', '', ['expires' => time() - 3600, 'path' => '/']);
        Session::destroy();
        self::$user = null;
        self::$permissions = null;
    }
}
