<?php
/**
 * Session wrapper with idle timeout, fingerprint binding and flash messages.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            self::enforceTimeout();
            return;
        }
        Security::initSessionSecurity();
        self::$started = true;
        self::enforceTimeout();
    }

    private static function fingerprint(): string
    {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . self::ipPrefix());
    }

    private static function ipPrefix(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return implode('.', array_slice(explode('.', $ip), 0, 2));
    }

    private static function enforceTimeout(): void
    {
        if (!isset($_SESSION)) {
            return;
        }
        $fp = self::fingerprint();
        if (isset($_SESSION['_fingerprint']) && $_SESSION['_fingerprint'] !== $fp) {
            // Client fingerprint changed drastically - start a clean session.
            $_SESSION = [];
        }
        $_SESSION['_fingerprint'] = $fp;

        $last = (int) ($_SESSION['_last_activity'] ?? time());
        if (time() - $last > SESSION_LIFETIME && !empty($_SESSION)) {
            $flash = $_SESSION['_flash'] ?? [];
            $_SESSION = ['_flash' => $flash];
            $_SESSION['_timeout_notice'] = true;
        }
        $_SESSION['_last_activity'] = time();
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
            @session_destroy();
        }
        self::$started = false;
    }

    // ---------------------------------------------------------------- flash
    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::flash('success', $message);
    }

    public static function error(string $message): void
    {
        self::flash('danger', $message);
    }

    public static function warning(string $message): void
    {
        self::flash('warning', $message);
    }

    public static function info(string $message): void
    {
        self::flash('info', $message);
    }

    public static function pullFlash(): array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }
}
