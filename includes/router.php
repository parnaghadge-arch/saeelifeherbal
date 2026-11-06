<?php
/**
 * Front controller & URL resolver.
 *
 * One resolver serves Apache (.htaccess -> index.php), nginx (try_files -> index.php) and the
 * PHP development router, so /product-categories, /product-categories.php and /our-products
 * all land on exactly the same script. Requested directories that hold their own index
 * (admin/, member/, api/, pwa/) are bounced into that index instead of being swallowed by
 * the public site, and a real ".php" file is never treated as a CMS slug.
 *
 * Every function is guarded with function_exists() so the file can be required from more than
 * one entry point in the same request (dev router -> front controller -> script) and from
 * long-running workers without a "cannot redeclare" fatal.
 */
declare(strict_types=1);

if (!function_exists('saee_app_root')) {
    /** Absolute path of the application root (the folder that holds index.php). */
    function saee_app_root(): string
    {
        return str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    }

    /**
     * URL prefix the application is installed under, without a trailing slash: "" for
     * http://localhost/, "/LIVEpro/SaeeLifeHerbal-1" for a sub-directory install.
     *
     * The document root is authoritative because the same folder may be reached through
     * different scripts (index.php, /admin/index.php, router.php). SCRIPT_NAME is only a
     * fallback for hosts that do not expose DOCUMENT_ROOT.
     */
    function saee_base_path(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        // CLI (tools/console.php, cron) has no web root; links come from APP_URL instead.
        if (PHP_SAPI === 'cli') {
            return $base = '';
        }
        $root = saee_app_root();
        $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docRoot !== '') {
            $len = strlen($docRoot);
            $matches = PHP_OS_FAMILY === 'Windows'
                ? strncasecmp($root, $docRoot, $len) === 0
                : strncmp($root, $docRoot, $len) === 0;
            if ($matches && ($len === strlen($root) || $root[$len] === '/')) {
                $relative = trim(substr($root, $len), '/');
                if ($relative === '' || !str_contains($relative, '..')) {
                    return $base = ($relative === '' ? '' : '/' . $relative);
                }
            }
        }

        // Fallback: the directory of the running script, minus a sub-application folder.
        // The dev router (router.php) sits in the application root, so it reports "".
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#/(admin|member|api|ajax|pwa|install)$#i', $dir)) {
            $dir = rtrim((string) preg_replace('#/[^/]+$#', '', $dir), '/');
        }
        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }
        return $base = $dir;
    }

    /**
     * Normalise the request path: strip the sub-directory the app is installed in and the
     * leading/trailing slashes. Works for http://localhost/LIVEpro/SaeeLifeHerbal-1/... too.
     */
    function saee_request_path(): string
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = str_replace('\\', '/', rawurldecode($uri));

        // Some setups expose the physical script location instead of a URL (rewrites that
        // rebuild REQUEST_URI, embedded/dev servers). Reduce it to a URL path first.
        $root = saee_app_root();
        if ($root !== '' && str_starts_with($uri, $root)) {
            $uri = substr($uri, strlen($root));
        }
        if ($uri === '') {
            $uri = '/';
        }

        $base = saee_base_path();
        $len = strlen($base);
        if ($base !== '') {
            $matches = PHP_OS_FAMILY === 'Windows'
                ? strncasecmp($uri, $base, $len) === 0
                : strncmp($uri, $base, $len) === 0;
            if ($matches && ($len === strlen($uri) || $uri[$len] === '/')) {
                $uri = substr($uri, $len);
            }
        }
        return trim($uri, '/');
    }
}

if (!function_exists('saee_resolve')) {
    /**
     * Map a normalised path to a script.
     *
     * @return array{file:string,query:array<string,string>}|null  null = "nothing here"
     */
    function saee_resolve(string $slug): ?array
    {
        $root = dirname(__DIR__);
        /** @var array<string,string> $aliases */
        $aliases = require $root . '/config/routes.php';

        if ($slug === '' || $slug === 'index.php' || $slug === 'index') {
            return ['file' => $root . '/index.php', 'query' => []];
        }

        $direct = $root . '/' . $slug;

        // 1. A real directory with its own entry point: /admin, /member, /api, /pwa.
        //    A directory without one (an empty placeholder folder) is not a route, so keep
        //    resolving and let member/wallet still find member/wallet.php.
        if (is_dir($direct) && is_file($direct . '/index.php')) {
            return ['file' => $direct . '/index.php', 'query' => []];
        }

        // 2. An explicit script request: /about.php, /product-categories.php.
        //    When that file does not exist, fall back to the alias table so /our-products.php
        //    still finds products.php (the "real" file name is not always the public one).
        if (str_ends_with($slug, '.php')) {
            if (is_file($direct)) {
                return ['file' => $direct, 'query' => []];
            }
            $trimmed = substr($slug, 0, -4);
            if (isset($aliases[$trimmed])) {
                return saee_alias_target($root, $aliases[$trimmed]);
            }
            return null;
        }

        // 3. Pretty alias (/about-us -> /about.php)
        if (isset($aliases[$slug])) {
            return saee_alias_target($root, $aliases[$slug]);
        }

        // 4. "Slug" that is really a script without its extension (/about -> /about.php)
        if (is_file($direct . '.php')) {
            return ['file' => $direct . '.php', 'query' => []];
        }

        // 5. Pretty content URLs: /product/<slug>, /blog/<slug>
        if (preg_match('#^blog/([a-z0-9-]+)$#', $slug, $m)) {
            return ['file' => $root . '/blog-details.php', 'query' => ['slug' => $m[1]]];
        }
        // Pretty product URLs (/product/<slug> -> product-details.php?slug=<slug>)
        if (preg_match('#^product/([a-z0-9-]+)$#', $slug, $m)) {
            return ['file' => $root . '/product-details.php', 'query' => ['slug' => $m[1]]];
        }

        // 6. Member portal pretty URLs (/member/wallet -> member/wallet.php)
        if (preg_match('#^member/([a-z0-9-]+)$#', $slug, $m)) {
            $map = ['index' => 'dashboard', 'wallets' => 'wallet', 'commission' => 'commissions', 'payout' => 'payouts'];
            $candidates = [$m[1], str_replace('-', '_', $m[1]), rtrim($m[1], 's'), $map[$m[1]] ?? ''];
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && is_file($root . '/member/' . $candidate . '.php')) {
                    return ['file' => $root . '/member/' . $candidate . '.php', 'query' => []];
                }
            }
        }

        // 7. Anything else may be a CMS page (page.php renders a branded 404 when unknown).
        return ['file' => $root . '/page.php', 'query' => ['slug' => $slug]];
    }
}

if (!function_exists('saee_alias_target')) {
    /** @return array{file:string,query:array<string,string>} */
    function saee_alias_target(string $root, string $target): array
    {
        $query = [];
        if (str_contains($target, '?')) {
            [$target, $qs] = explode('?', $target, 2);
            parse_str($qs, $query);
            $query = array_map('strval', $query);
        }
        return ['file' => $root . '/' . ltrim($target, '/'), 'query' => $query];
    }
}

if (!function_exists('saee_dispatch')) {
    /** Run the resolved route, keeping the request query string intact. */
    function saee_dispatch(?array $route, string $slug): void
    {
        if ($route === null || !is_file($route['file'])) {
            http_response_code(404);
            require dirname(__DIR__) . '/errors/404.php';
            return;
        }
        if ($route['query'] !== []) {
            $_GET = array_merge($_GET, $route['query']);
            $qs = http_build_query($route['query']);
            $existing = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $_SERVER['QUERY_STRING'] = $qs . ($existing !== '' ? '&' . $existing : '');
        }
        // Guard against an alias that points back at the front controller (/home -> index.php):
        // index.php checks this constant so it renders the homepage instead of re-dispatching.
        if (!defined('SAEE_ROUTED')) {
            define('SAEE_ROUTED', true);
        }
        $root = dirname(__DIR__);
        $_SERVER['SCRIPT_NAME'] = '/' . ltrim(str_replace('\\', '/', $route['file']), '/');
        $_SERVER['SCRIPT_NAME'] = str_replace(str_replace('\\', '/', $root), '', $_SERVER['SCRIPT_NAME']);
        $_SERVER['SCRIPT_FILENAME'] = $route['file'];
        require $route['file'];
    }
}

if (!function_exists('saee_run_front_controller')) {
    /** Convenience for entry points: resolve the current request and run it. */
    function saee_run_front_controller(): void
    {
        $slug = saee_request_path();
        if ($slug === '') {
            require dirname(__DIR__) . '/index.php';
            return;
        }
        // Real static assets are handled by the web server before we get here.
        saee_dispatch(saee_resolve($slug), $slug);
    }
}
