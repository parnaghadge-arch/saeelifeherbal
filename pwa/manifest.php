<?php
/**
 * Web app manifest (PWA). Served from /manifest.json by the router.
 * Icon and colour choices come from admin settings.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use Saee\Lib\Settings;
use Saee\Lib\Url;

$icon = Url::media((string) Settings::get('logo_pwa', 'assets/images/logo-square.png'));
$name = (string) Settings::get('site_name', APP_NAME);
$manifest = [
    'name' => $name,
    'short_name' => (string) Settings::get('pwa_short_name', 'Saee Life'),
    'description' => (string) Settings::get('site_description', ''),
    'start_url' => Url::absoluteBase() . '/?source=pwa',
    'scope' => Url::absoluteBase() . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => (string) Settings::get('pwa_background_color', '#F7FAF6'),
    'theme_color' => (string) Settings::get('pwa_theme_color', '#247A3C'),
    'lang' => 'en-IN',
    'dir' => 'ltr',
    'categories' => ['business', 'shopping', 'agriculture'],
    'icons' => [
        ['src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Shop Products', 'url' => Url::absoluteBase() . '/our-products', 'icons' => [['src' => $icon, 'sizes' => '192x192']]],
        ['name' => 'Partner Dashboard', 'url' => Url::absoluteBase() . '/member/dashboard', 'icons' => [['src' => $icon, 'sizes' => '192x192']]],
        ['name' => 'My Orders', 'url' => Url::absoluteBase() . '/my-orders', 'icons' => [['src' => $icon, 'sizes' => '192x192']]],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
