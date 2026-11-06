<?php
/**
 * Human readable URL => script map.
 *
 * Used by includes/router.php (Apache/nginx/dev server front controller), by Url::to()
 * when building links, and by the sitemap. Keep it as pure data: no side effects.
 *
 * A target may carry a query string ("/page.php?slug=privacy-policy").
 */
declare(strict_types=1);

return [
    // Marketing pages
    'home' => '/index.php',
    'about-us' => '/about.php',
    'our-products' => '/products.php',
    'product-categories' => '/product-categories.php',
    'agriculture-solutions' => '/agriculture-solutions.php',
    'gallery' => '/gallery.php',
    'videos' => '/videos.php',
    'blogs' => '/blogs.php',
    'blog-categories' => '/blogs.php',
    'faqs' => '/faqs.php',
    'contact-us' => '/contact.php',
    'business-opportunity' => '/business-opportunity.php',
    'mlm-business-opportunity' => '/business-opportunity.php',

    // Category landing pages
    'crop-protection' => '/products.php?category=crop-protection',
    'fertilizers' => '/products.php?category=fertilizers',
    'pesticides' => '/products.php?category=pesticides',
    'insecticides' => '/products.php?category=insecticides',
    'bio-stimulants' => '/products.php?category=bio-stimulants',

    // Shop & account
    'cart' => '/cart.php',
    'checkout' => '/checkout.php',
    'wishlist' => '/wishlist.php',
    'login' => '/login.php',
    'register' => '/register.php',
    'forgot-password' => '/forgot-password.php',
    'my-account' => '/my-account.php',
    'my-orders' => '/my-orders.php',

    // Partner portal
    'farmer-registration' => '/member/register.php',
    'partner-login' => '/member/login.php',
    'partner-registration' => '/member/register.php',
    'partner' => '/member/dashboard.php',

    // Legal / policy pages (rendered by the CMS page renderer)
    'privacy-policy' => '/page.php?slug=privacy-policy',
    'terms-conditions' => '/page.php?slug=terms-conditions',
    'refund-return-policy' => '/page.php?slug=refund-return-policy',
    'refund-policy' => '/page.php?slug=refund-return-policy',
    'shipping-policy' => '/page.php?slug=shipping-policy',
    'disclaimer' => '/page.php?slug=disclaimer',

    // Technical
    'search' => '/products.php',
    'sitemap.xml' => '/sitemap.php',
    'robots.txt' => '/robots.php',
    'manifest.json' => '/pwa/manifest.php',
    'offline' => '/pwa/offline.php',
    'service-worker.js' => '/service-worker.js',
];
