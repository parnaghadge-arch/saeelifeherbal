<?php
/**
 * Request helpers: input collection, sanitisation, URL building and redirects.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool
    {
        return Security::wantsJson();
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public static function raw(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::input($key);
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::input($key, $default ? '1' : '0');
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? $default;
    }

    public static function array(string $key): array
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::input($key);
        }
        return $out;
    }

    /**
     * Read a JSON request body (used for payment webhooks and API calls).
     */
    public static function json(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $cache = is_array($decoded) ? $decoded : [];
        return $cache;
    }

    public static function rawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', (string) $_SERVER[$key]);
                $ip = trim($parts[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function uri(): string
    {
        return (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function currentUrl(): string
    {
        return Url::current();
    }

    /**
     * Resolve a target for redirects. A bare file name ("bank.php") is treated as a file in
     * the current sub-application folder (admin, member, api, pwa) so redirects stay inside it.
     */
    public static function resolveRedirect(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $base = Url::base();
        // Inside a sub-application the target may name its own folder ("member/login").
        if (preg_match('#^(admin|member|api|pwa|ajax)/#', $url)) {
            return $base . '/' . $url;
        }
        if (!str_contains($url, '/')) {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $dir = rtrim(dirname($script), '/');
            // Keep redirects inside the sub-application the request came from.
            if (preg_match('#/(admin|member|pwa|ajax)$#', $dir) && !str_ends_with($base, $dir)) {
                $base .= $dir;
            }
        }
        return $base . '/' . ltrim($url, '/');
    }

    public static function redirect(string $url, int $code = 302): never
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = self::resolveRedirect($url);
        }
        if (!headers_sent()) {
            header('Location: ' . $url, true, $code);
        }
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref && $host && str_contains((string) parse_url($ref, PHP_URL_HOST), (string) parse_url('http://' . $host, PHP_URL_HOST))) {
            self::redirect($ref);
        }
        self::redirect($fallback);
    }

    /**
     * True when the client used HTTPS. Behind a load balancer, proxy or tunnel the PHP
     * process itself only sees plain HTTP, so the standard forwarding headers are honoured
     * (X-Forwarded-Proto may hold a list: "https, http").
     */
    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
            || str_contains(strtolower((string) ($_SERVER['HTTP_CF_VISITOR'] ?? '')), 'https');
    }

    public static function host(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function scheme(): string
    {
        return self::isSecure() ? 'https' : 'http';
    }
}
