<?php
/**
 * Router for PHP's built-in server (development / preview only).
 *   php -S 0.0.0.0:8000 router.php
 *
 * Static files are served directly; everything else goes through the shared front
 * controller so pretty URLs behave exactly like Apache/nginx. The installed
 * sub-directory (e.g. /LIVEpro/SaeeLifeHerbal-1) is stripped first, so the same
 * router works whether the app sits at the domain root or a few folders deep.
 */
declare(strict_types=1);

require __DIR__ . '/includes/router.php';

$root = __DIR__;

// Normalised path inside the application ("/", "/admin", "/assets/css/app.css").
$uri = saee_normalized_path();

// Never expose application internals through the dev server.
if (preg_match('#^/(storage|config|lib|includes|database|tools|tests|docs)(/|$)#', $uri)) {
    http_response_code(404);
    exit('Not found');
}

// A real file (css, js, images, fonts, service-worker.js …) is served as-is. Static files are
// streamed from here instead of returning false, because the built-in server resolves the
// path against its document root — which is wrong when the app lives in a sub-directory.
if ($uri !== '/' && is_file($root . $uri) && !str_ends_with($uri, '.php')) {
    saee_serve_static($root . $uri);
    return true;
}
// PHP scripts are dispatched by the front controller as well: the built-in server resolves
// "return false" paths against its own document root, which breaks in a sub-directory install.
// saee_dispatch() requires the resolved script with the correct SCRIPT_NAME/query.

// Everything else — including directories such as /admin, /member, /api — is resolved by the
// front controller, which understands directory indexes and pretty URLs alike.

saee_run_front_controller();
