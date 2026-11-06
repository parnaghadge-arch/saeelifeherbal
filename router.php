<?php
/**
 * Router for PHP's built-in server (development / preview only).
 * - Serves static files directly.
 * - Rewrites extension-less SEO URLs (e.g. /about-us) to the matching *.php file.
 * - Blocks access to private storage, config and lib folders.
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = urldecode($uri);
$root = __DIR__;

// Basic protection for sensitive paths when using the built-in server.
if (preg_match('#^/(storage|config|lib|includes|database|tools|tests)(/|$)#', $uri)) {
    http_response_code(404);
    exit('Not found');
}

$path = $root . $uri;
if ($uri !== '/' && is_file($path)) {
    if (str_ends_with($path, '.php')) {
        return false; // let PHP execute it
    }
    return false; // static file served by the built-in server
}
if ($uri !== '/' && is_dir($path) && is_file($path . '/index.php')) {
    require $path . '/index.php';
    return true;
}

// SEO friendly routes
$slug = trim($uri, '/');
if ($slug === '') {
    require $root . '/index.php';
    return true;
}

$map = [
    'about-us' => '/about.php',
    'our-products' => '/products.php',
    'product-categories' => '/product-categories.php',
    'agriculture-solutions' => '/agriculture-solutions.php',
    'crop-protection' => '/products.php?category=crop-protection',
    'fertilizers' => '/products.php?category=fertilizers',
    'pesticides' => '/products.php?category=pesticides',
    'insecticides' => '/products.php?category=insecticides',
    'gallery' => '/gallery.php',
    'videos' => '/videos.php',
    'blogs' => '/blogs.php',
    'blog-categories' => '/blogs.php',
    'faqs' => '/faqs.php',
    'contact-us' => '/contact.php',
    'farmer-registration' => '/member/register.php',
    'mlm-business-opportunity' => '/business-opportunity.php',
    'business-opportunity' => '/business-opportunity.php',
    'privacy-policy' => '/page.php?slug=privacy-policy',
    'terms-conditions' => '/page.php?slug=terms-conditions',
    'refund-return-policy' => '/page.php?slug=refund-return-policy',
    'shipping-policy' => '/page.php?slug=shipping-policy',
    'disclaimer' => '/page.php?slug=disclaimer',
    'cart' => '/cart.php',
    'checkout' => '/checkout.php',
    'wishlist' => '/wishlist.php',
    'login' => '/login.php',
    'register' => '/register.php',
    'my-account' => '/my-account.php',
    'my-orders' => '/my-orders.php',
    'logout' => '/logout.php',
    'order-confirmation' => '/order-confirmation.php',
    'blog-details' => '/blog-details.php',
    'blog' => '/blogs.php',
    'invoice' => '/invoice.php',
    'forgot-password' => '/forgot-password.php',
    'reset-password' => '/reset-password.php',
    'offline' => '/pwa/offline.php',
    'partner-login' => '/member/login.php',
    'partner-registration' => '/member/register.php',
    'partner' => '/member/dashboard.php',
    'search' => '/products.php',
    'sitemap.xml' => '/sitemap.php',
    'robots.txt' => '/robots.php',
    'manifest.json' => '/pwa/manifest.php',
    'service-worker.js' => '/service-worker.js',
];

if (isset($map[$slug])) {
    $target = $map[$slug];
    if (str_contains($target, '?')) {
        [$file, $query] = explode('?', $target, 2);
        parse_str($query, $params);
        $_GET = array_merge($_GET, $params);
        $_SERVER['QUERY_STRING'] = $query . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '&' . $_SERVER['QUERY_STRING'] : '');
        $targetFile = $root . $file;
    } else {
        $targetFile = $root . $target;
    }
    if (is_file($targetFile)) {
        require $targetFile;
        return true;
    }
}

// Dynamic CMS page fallback: /{slug} handled by page.php
$pageFile = $root . '/page.php';
$_GET['slug'] = $slug;
$_SERVER['QUERY_STRING'] = 'slug=' . urlencode($slug);
require $pageFile;
return true;
