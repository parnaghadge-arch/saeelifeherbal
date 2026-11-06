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
$base = saee_base_path();

// Normalised path inside the application ("/", "/admin", "/assets/css/app.css").
$uri = str_replace('\\', '/', rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
if ($uri === '') {
    $uri = '/';
}

// Never expose application internals through the dev server.
if (preg_match('#^/(storage|config|lib|includes|database|tools|tests|docs)(/|$)#', $uri)) {
    http_response_code(404);
    exit('Not found');
}

// A real file (css, js, images, fonts, service-worker.js …) is served as-is.
if ($uri !== '/' && is_file($root . $uri)) {
    return false;
}
// A real directory that owns an index (admin/, member/, api/, pwa/) is served by the
// server's own directory index. Directories without one (empty placeholder folders such as
// member/wallet/) fall through to the front controller so member/wallet.php still answers.
if ($uri !== '/' && is_dir($root . $uri)
    && (is_file($root . $uri . '/index.php') || is_file($root . $uri . '/index.html'))) {
    return false;
}

saee_run_front_controller();
