<?php
/**
 * URL helper.
 *
 * Two things are handled here:
 *   1. the base path, so the platform works from a domain root or a sub-directory install
 *      (http://localhost/LIVEpro/SaeeLifeHerbal-1/), and
 *   2. the origin. Application URLs are emitted *origin-relative* ("/assets/css/app.css")
 *      by the web front end, so a page reached through a different host or scheme than the
 *      one PHP guessed can never break: no mixed content, no cross-host CDN lookups, and
 *      nothing to configure behind a proxy/CDN. Absolute URLs are used only where a URL
 *      leaves the HTML context (sitemap, JSON-LD, og:image, PWA manifest, share links).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Url
{
    /** Absolute base ("https://host/subdir"), resolved lazily. */
    private static ?string $base = null;

    /** Web base: "" on the domain root, "/subdir" in a sub-directory install. */
    private static ?string $pathBase = null;

    /**
     * Base used to build URLs for the browser: always origin-relative, so an http page
     * served through an https proxy still loads its CSS instead of being blocked.
     */
    public static function base(): string
    {
        if (self::$pathBase !== null) {
            return self::$pathBase;
        }
        // Requests made by code itself (integrations, cron, CLI) need an absolute origin.
        if (PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            $absolute = self::absoluteBase();
            $parts = parse_url($absolute);
            $path = rtrim((string) ($parts['path'] ?? ''), '/');
            return self::$pathBase = $path;
        }
        // Auto-detect from the application root so links stay correct whichever script
        // (front controller, admin, member) handled the request.
        if (function_exists('saee_base_path')) {
            return self::$pathBase = saee_base_path();
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(dirname($script), '/');
        // Strip known sub-app folders (admin, member, api, ajax) from the base path,
        // including nested API folders such as /api/orders.
        $dir = preg_replace('#/(admin|member|api|ajax|pwa|install)(/.*)?$#', '', $dir) ?? $dir;
        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }
        return self::$pathBase = $dir;
    }

    /** Absolute base ("https://host/subdir") for sitemaps, feeds, JSON-LD and share links. */
    public static function absoluteBase(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $configured = rtrim((string) Config::get('app.url', ''), '/');
        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return self::$base = $configured;
        }
        if (PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            // Cron/CLI has no request host: fall back to a neutral absolute origin.
            return self::$base = $configured !== '' ? $configured : 'http://localhost';
        }
        $dir = self::base();
        return self::$base = Request::scheme() . '://' . Request::host() . $dir;
    }

    public static function setBase(string $base): void
    {
        self::$base = rtrim($base, '/');
        self::$pathBase = null;
    }

    /** Scheme + host, without the sub-directory base ("https://example.com"). */
    public static function origin(): string
    {
        $absolute = self::absoluteBase();
        $base = self::base();
        if ($base !== '' && str_ends_with($absolute, $base)) {
            return substr($absolute, 0, -strlen($base));
        }
        return rtrim($absolute, '/');
    }

    /**
     * Force any application URL to be absolute. Used where the URL leaves the page context
     * (og:image, JSON-LD, sitemaps, share links). Origin-relative URLs that already carry
     * the sub-directory base are only given an origin, never a second base.
     */
    public static function absolute(string $url): string
    {
        if ($url === '') {
            return self::absoluteBase() . '/';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return (Request::isSecure() ? 'https:' : 'http:') . $url;
        }
        $base = self::base();
        if ($base !== '' && ($url === $base || str_starts_with($url, $base . '/'))) {
            return self::origin() . $url;
        }
        return self::absoluteBase() . '/' . ltrim($url, '/');
    }

    /** Turn an application-relative path into an origin-relative URL ("/subdir/x"). */
    private static function join(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * Folder of the running script relative to the install base: "" at the site root,
     * "/admin" for /sub/admin/x.php, "/api" for /sub/api/orders/list.php.
     *
     * Used by redirects so a target such as "categories.php?id=5&saved=1" becomes
     * "/sub/admin/categories.php?id=5&saved=1" instead of repeating the install prefix.
     */
    public static function currentDir(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(dirname($script), '/');
        if ($dir === '.' || $dir === '/' || $dir === '') {
            return '';
        }
        $base = self::base();
        if ($base !== '') {
            if ($dir === $base) {
                return '';
            }
            if (str_starts_with($dir, $base . '/')) {
                return substr($dir, strlen($base));
            }
            // The script folder does not sit below the detected base (unusual vhost setup):
            // use only its last segment so a sub-application folder is still honoured.
            if (preg_match('#/(admin|member|api|ajax|pwa)$#i', $dir, $m)) {
                return $m[0];
            }
        }
        return $dir;
    }

    public static function asset(string $path): string
    {
        return self::join('assets/' . ltrim($path, '/'));
    }

    public static function upload(string $path): string
    {
        if ($path === '' || $path === null) {
            return self::asset('images/placeholder.svg');
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::join('uploads/' . ltrim($path, '/'));
    }

    public static function media(?string $path, string $fallback = 'images/placeholder.svg'): string
    {
        if ($path === null || $path === '') {
            return self::asset($fallback);
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        // Stored path may be "assets/…", "uploads/…" or a bare upload file name.
        if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'assets/')) {
            return self::join($path);
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
        return self::join(self::pretty($path));
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
        return self::join('admin/' . ltrim($path, '/'));
    }

    public static function member(string $path = ''): string
    {
        return self::join('member/' . ltrim($path, '/'));
    }

    public static function api(string $path = ''): string
    {
        return self::join('api/' . ltrim($path, '/'));
    }

    /** Absolute URL of the current request (used for share links and redirects). */
    public static function current(): string
    {
        return self::absoluteBase() . Request::uri() . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    }

    /**
     * Canonical URL for <link rel="canonical"> / og:url. Absolute, and stripped of the
     * sub-directory base so the canonical never contains it twice.
     */
    public static function canonical(): string
    {
        // Use the same normalisation as the router, so a rewrite that exposes the physical
        // path (dev server, some FastCGI setups) still yields a clean canonical URL.
        if (function_exists('saee_request_path')) {
            $slug = saee_request_path();
        } else {
            $uri = Request::uri();
            $base = self::base();
            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            $slug = trim($uri, '/');
        }
        return self::absoluteBase() . '/' . ltrim($slug, '/');
    }

    public static function query(array $params, ?string $path = null): string
    {
        $path = $path ?? Request::uri();
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        $qs = http_build_query($params);
        return self::join(ltrim($path, '/')) . ($qs !== '' ? '?' . $qs : '');
    }
}
