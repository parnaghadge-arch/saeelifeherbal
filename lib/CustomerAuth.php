<?php
/**
 * Shop customer authentication (separate from the MLM member portal).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class CustomerAuth
{
    private const SESSION_KEY = 'customer_id';
    private static ?array $customer = null;
    private static bool $creatingMember = false;

    public static function attempt(string $identifier, string $password, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $scope = 'customer';
        [$allowed, , $retry] = Security::throttleStatus($scope, strtolower($identifier));
        if (!$allowed) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Try again in ' . ceil($retry / 60) . ' minute(s).'];
        }
        $customer = Database::instance()->first(
            'SELECT * FROM customers WHERE mobile = :id OR LOWER(email) = :id LIMIT 1',
            ['id' => strtolower($identifier)]
        );
        if (!$customer || !Security::verifyPassword($password, (string) $customer['password_hash'])) {
            Security::recordFailure($scope, strtolower($identifier));
            return ['ok' => false, 'message' => 'Invalid mobile/email or password.'];
        }
        if ($customer['status'] !== 'active') {
            return ['ok' => false, 'message' => 'This account is inactive.'];
        }
        Security::clearFailures($scope, strtolower($identifier));
        self::loginSession($customer, $remember);
        return ['ok' => true, 'message' => 'Welcome back, ' . $customer['name'] . '!'];
    }

    public static function loginSession(array $customer, bool $remember = false): void
    {
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int) $customer['id']);
        if (!empty($customer['member_id'])) {
            Session::set('customer_member_id', (int) $customer['member_id']);
        }
        Database::instance()->update('customers', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $customer['id']]);
        if ($remember) {
            $token = Security::randomToken(32);
            Database::instance()->update('customers', ['remember_token' => Security::hashToken($token)], 'id = :id', ['id' => $customer['id']]);
            setcookie('saee_customer_remember', $customer['id'] . ':' . $token, [
                'expires' => time() + REMEMBER_LIFETIME, 'path' => '/', 'httponly' => true,
                'samesite' => 'Lax', 'secure' => Request::isSecure(),
            ]);
        }
        self::$customer = null;
        LoginActivity::record('customer', (int) $customer['id']);
    }

    public static function user(): ?array
    {
        if (self::$customer !== null) {
            return self::$customer;
        }
        $id = Session::get(self::SESSION_KEY);
        if (!$id) {
            self::$customer = self::viaRememberCookie();
            if (self::$customer) {
                Session::set(self::SESSION_KEY, (int) self::$customer['id']);
            }
            return self::$customer;
        }
        $row = Database::instance()->first('SELECT * FROM customers WHERE id = :id AND status = :s', ['id' => $id, 's' => 'active']);
        if (!$row) {
            Session::remove(self::SESSION_KEY);
            return null;
        }
        self::$customer = $row;
        return self::$customer;
    }

    private static function viaRememberCookie(): ?array
    {
        $cookie = $_COOKIE['saee_customer_remember'] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }
        [$id, $token] = explode(':', $cookie, 2);
        $row = Database::instance()->first('SELECT * FROM customers WHERE id = :id', ['id' => (int) $id]);
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

    /** A customer session always exposes an MLM member context for commissions. */
    public static function memberId(): ?int
    {
        $customer = self::user();
        return $customer !== null && !empty($customer['member_id']) ? (int) $customer['member_id'] : null;
    }

    public static function requireLogin(string $redirect = 'login'): void
    {
        if (!self::check()) {
            if (Request::isAjax()) {
                Response::error('Please sign in to continue.', [], 401);
            }
            Session::set('customer_redirect', Request::uri());
            Session::flash('warning', 'Please sign in to continue.');
            Request::redirect($redirect);
        }
        if (in_array(Request::method(), ['POST'], true)) {
            Security::verifyCsrf();
        }
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id) {
            Database::instance()->update('customers', ['remember_token' => null], 'id = :id', ['id' => $id]);
        }
        setcookie('saee_customer_remember', '', ['expires' => time() - 3600, 'path' => '/']);
        Session::remove(self::SESSION_KEY);
        self::$customer = null;
    }
}
