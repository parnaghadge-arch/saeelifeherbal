<?php
/**
 * MLM member portal authentication.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class MemberAuth
{
    private const SESSION_KEY = 'member_id';
    private static ?array $member = null;

    public static function attempt(string $identifier, string $password, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $scope = 'member';
        [$allowed, , $retry] = Security::throttleStatus($scope, strtolower($identifier));
        if (!$allowed) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Try again in ' . ceil($retry / 60) . ' minute(s).'];
        }
        $db = Database::instance();
        $member = $db->first(
            'SELECT * FROM members WHERE LOWER(member_code) = :id OR mobile = :id OR LOWER(email) = :id LIMIT 1',
            ['id' => strtolower($identifier)]
        );
        if (!$member || !Security::verifyPassword($password, (string) $member['password_hash'])) {
            Security::recordFailure($scope, strtolower($identifier));
            return ['ok' => false, 'message' => 'Invalid member ID, mobile number or password.'];
        }
        if ($member['status'] === 'suspended') {
            return ['ok' => false, 'message' => 'This account is suspended. Please contact support.'];
        }
        Security::clearFailures($scope, strtolower($identifier));
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int) $member['id']);
        Session::set('member_login_at', time());
        $db->update('members', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $member['id']]);
        if ($remember) {
            $token = Security::randomToken(32);
            $db->update('members', ['remember_token' => Security::hashToken($token)], 'id = :id', ['id' => $member['id']]);
            setcookie('saee_member_remember', $member['id'] . ':' . $token, [
                'expires' => time() + REMEMBER_LIFETIME, 'path' => '/', 'httponly' => true,
                'samesite' => 'Lax', 'secure' => Request::isSecure(),
            ]);
        }
        self::$member = null;
        LoginActivity::record('member', (int) $member['id']);
        return ['ok' => true, 'message' => 'Welcome back, ' . $member['name'] . '!'];
    }

    public static function user(): ?array
    {
        if (self::$member !== null) {
            return self::$member;
        }
        $id = Session::get(self::SESSION_KEY);
        if (!$id) {
            $id = Session::get('customer_member_id'); // customers that are also members
        }
        if (!$id) {
            self::$member = self::viaRememberCookie();
            if (self::$member) {
                Session::set(self::SESSION_KEY, (int) self::$member['id']);
            }
            return self::$member;
        }
        $row = Database::instance()->first(
            'SELECT m.*, s.name AS sponsor_name, s.member_code AS sponsor_code
             FROM members m LEFT JOIN members s ON s.id = m.sponsor_id
             WHERE m.id = :id',
            ['id' => $id]
        );
        if (!$row) {
            Session::remove(self::SESSION_KEY);
            return null;
        }
        self::$member = $row;
        return self::$member;
    }

    private static function viaRememberCookie(): ?array
    {
        $cookie = $_COOKIE['saee_member_remember'] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }
        [$id, $token] = explode(':', $cookie, 2);
        $row = Database::instance()->first('SELECT * FROM members WHERE id = :id', ['id' => (int) $id]);
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

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (Request::isAjax()) {
                Response::error('Your session has expired. Please sign in again.', [], 401);
            }
            // Store the target as a plain file name, with no folder prefix.
            //
            // Request::uri() is the full web path, so storing it appended the install prefix a
            // second time on sub-directory installs. The previous fix over-corrected: it glued
            // Url::currentDir() (which returns "/member", leading slash) straight onto the
            // basename, producing "memberpayouts.php" — which Request::resolveRedirect() then
            // turned into /member/memberpayouts.php, a 404. The folder is already implied: the
            // redirect is resolved relative to the sub-application the request came from, and
            // resolveRedirect() re-adds it. This now matches AdminAuth::requireLogin().
            $target = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            if ($target === '' || $target === '/') {
                $target = 'dashboard.php';
            }
            Session::set('member_redirect', $target);
            Session::flash('warning', 'Please sign in to your partner account.');
            Request::redirect('login');
        }
        if (in_array(Request::method(), ['POST', 'PUT', 'DELETE'], true)) {
            Security::verifyCsrf();
        }
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id) {
            Database::instance()->update('members', ['remember_token' => null], 'id = :id', ['id' => $id]);
        }
        setcookie('saee_member_remember', '', ['expires' => time() - 3600, 'path' => '/']);
        Session::remove(self::SESSION_KEY);
        Session::remove(self::SESSION_KEY . '_2fa');
        self::$member = null;
    }
}
