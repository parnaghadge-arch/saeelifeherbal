<?php
/**
 * URL helper. Automatically detects the application base path so the platform works
 * from a domain root or a sub-directory on shared hosting.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Url
{
    private static ?string $base = null;

    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $configured = (string) Config::get('app.url', '');
        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            self::$base = rtrim($configured, '/');
            return self::$base;
        }
        // Auto-detect from the application root so links stay correct whichever script
        // (front controller, admin, member) handled the request.
        if (function_exists('saee_base_path')) {
            $dir = saee_base_path();
        } else {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $dir = rtrim(dirname($script), '/');
            // Strip known sub-app folders (admin, member, api, ajax) from the base path,
            // including nested API folders such as /api/orders.
            $dir = preg_replace('#/(admin|member|api|ajax|pwa|install)(/.*)?$#', '', $dir) ?? $dir;
            if ($dir === '.' || $dir === '/') {
                $dir = '';
            }
        }
        self::$base = Request::scheme() . '://' . Request::host() . $dir;
        return self::$base;
    }

    public static function setBase(string $base): void
    {
        self::$base = rtrim($base, '/');
    }

    public static function asset(string $path): string
    {
        return self::base() . '/assets/' . ltrim($path, '/');
    }

    public static function upload(string $path): string
    {
        if ($path === '' || $path === null) {
            return self::asset('images/placeholder.svg');
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::base() . '/uploads/' . ltrim($path, '/');
    }

    public static function media(?string $path, string $fallback = 'images/placeholder.svg'): string
    {
        if ($path === null || $path === '') {
            return self::asset($fallback);
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (str_starts_with($path, 'uploads/')) {
            return self::base() . '/' . $path;
        }
        if (str_starts_with($path, 'assets/')) {
            return self::base() . '/' . $path;
        }
        return self::upload($path);
    }

    /**
     * Public URL for a path. Known routes are emitted without the ".php" extension so the
     * site always links to clean URLs (/about-us, /our-products) even when a template still
     * passes an internal script name.
     */
    public static function to(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return self::base() . '/' . self::pretty($path);
    }

    /** Map an internal script name to its public slug when one exists. */
    public static function pretty(string $path): string
    {
        if ($path === '' || $path === 'index.php' || $path === 'index') {
            return '';
        }
        $map = self::routeMap();
        if (isset($map[$path])) {
            return $map[$path];
        }
        // "products.php?category=fertilizers" -> "fertilizers"
        if (str_contains($path, '?')) {
            [$file, $qs] = explode('?', $path, 2);
            parse_str($qs, $params);
            ksort($params);
            $key = $file . '?' . http_build_query($params);
            if (isset($map[$key])) {
                return $map[$key];
            }
        }
        return $path;
    }

    /**
     * Reverse of config/routes.php: internal target ("about.php", "products.php?category=x")
     * => public slug. Built once per request.
     *
     * @return array<string,string>
     */
    private static function routeMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $file = dirname(__DIR__) . '/config/routes.php';
        if (is_file($file)) {
            /** @var array<string,string> $routes */
            $routes = require $file;
            foreach ($routes as $slug => $target) {
                $target = ltrim($target, '/');
                if ($slug === 'home') {
                    continue;
                }
                if (str_contains($target, '?')) {
                    [$tFile, $qs] = explode('?', $target, 2);
                    parse_str($qs, $params);
                    ksort($params);
                    $target = $tFile . '?' . http_build_query($params);
                }
                if (!isset($map[$target])) {
                    $map[$target] = $slug;
                }
            }
            $map['index.php'] = '';
            $map['index'] = '';
        }
        return $map;
    }

    public static function admin(string $path = ''): string
    {
        return self::base() . '/admin/' . ltrim($path, '/');
    }

    public static function member(string $path = ''): string
    {
        return self::base() . '/member/' . ltrim($path, '/');
    }

    public static function api(string $path = ''): string
    {
        return self::base() . '/api/' . ltrim($path, '/');
    }

    public static function current(): string
    {
        return self::base() . Request::uri() . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    }

    public static function canonical(): string
    {
        return self::base() . Request::uri();
    }

    public static function query(array $params, ?string $path = null): string
    {
        $path = $path ?? Request::uri();
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        $qs = http_build_query($params);
        return self::base() . $path . ($qs !== '' ? '?' . $qs : '');
    }
}
