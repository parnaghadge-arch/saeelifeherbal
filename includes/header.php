<?php
/**
 * Public website header. Expects optional variables:
 *   $pageTitle, $pageDescription, $pageImage, $bodyClass, $canonical, $pageRobots
 */
declare(strict_types=1);

use Saee\Lib\CustomerAuth;
use Saee\Lib\MemberAuth;
use Saee\Lib\Settings;
use Saee\Lib\Url;

$siteName = (string) Settings::get('site_name', APP_NAME);
$logo = (string) Settings::get('logo_header', 'assets/images/logo.png');
$logoText = trim((string) Settings::get('logo_text', $siteName));
$logoSub = trim((string) Settings::get('logo_subtitle', ''));
$menu = get_menu('header');
$socials = get_social_links();
$cartCount = cart_count();
$wishCount = wishlist_count();
$customer = CustomerAuth::user();
$memberUser = MemberAuth::user();
$maintenance = (int) Settings::get('maintenance_mode', 0) === 1;
$primary = (string) Settings::get('theme_primary', '#247A3C');
$dark = (string) Settings::get('theme_dark', '#14532D');
$accent = (string) Settings::get('theme_accent', '#A5C94A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($primary) ?>">
<?= page_meta([
    'title' => $pageTitle ?? (string) Settings::get('site_name', APP_NAME),
    'description' => $pageDescription ?? (string) Settings::get('meta_description_default', ''),
    'image' => $pageImage ?? (string) Settings::get('og_default_image', 'assets/images/logo-square.png'),
    'canonical' => $canonical ?? Url::canonical(),
    'robots' => $pageRobots ?? 'index,follow',
]) ?>
<link rel="icon" href="<?= e(Url::media((string) Settings::get('favicon', 'assets/images/logo-square.png'))) ?>">
<link rel="apple-touch-icon" href="<?= e(Url::media((string) Settings::get('logo_pwa', 'assets/images/logo-square.png'))) ?>">
<link rel="manifest" href="<?= e(Url::to('manifest.json')) ?>">
<link rel="preload" href="<?= e(Url::asset('fonts/poppins/poppins-latin-600-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(Url::asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('vendor/fontawesome/css/all.min.css')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>?v=<?= APP_VERSION ?>">
<style>:root{--saee-primary:<?= e($primary) ?>;--saee-primary-rgb:<?= implode(',', array_map('intval', sscanf($primary, '#%02x%02x%02x') ?: [36,122,60])) ?>;--saee-dark:<?= e($dark) ?>;--saee-accent:<?= e($accent) ?>;}</style>
<?= (new \Saee\Lib\SeoService(db()))->organizationSchema() ?>
</head>
<body class="site-public <?= e($bodyClass ?? '') ?>">
<a href="#mainContent" class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white">Skip to content</a>
<header class="site-header" data-sticky="<?= (int) Settings::get('header_sticky', 1) ?>">
  <?php if ((int) Settings::get('header_show_topbar', 1) === 1): ?>
  <div class="topbar d-none d-lg-block">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center">
        <span><i class="fa-solid fa-phone-volume me-2"></i><a href="tel:<?= e(preg_replace('/\s+/', '', (string) Settings::get('contact_phone', ''))) ?>"><?= e(Settings::get('contact_phone', '')) ?></a></span>
        <span class="divider">|</span>
        <span><i class="fa-regular fa-envelope me-2"></i><a href="mailto:<?= e(Settings::get('contact_email', '')) ?>"><?= e(Settings::get('contact_email', '')) ?></a></span>
        <span class="divider">|</span>
        <span><i class="fa-solid fa-truck-fast me-2"></i>Pan-India delivery for farm inputs</span>
      </div>
      <div class="d-flex align-items-center gap-3">
        <a href="<?= e(Url::member('dashboard')) ?>" class="small"><i class="fa-solid fa-user-tie me-1"></i>Partner Login</a>
        <div class="topbar-social">
          <?php foreach ($socials as $social): ?>
            <a href="<?= e($social['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($social['display_name'] ?: $social['platform']) ?>"><i class="<?= e($social['icon'] ?: 'fa-solid fa-link') ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <nav class="navbar navbar-expand-xl py-1" aria-label="Main navigation">
    <div class="container header-inner">
      <a class="navbar-brand" href="<?= e(Url::to('')) ?>" aria-label="<?= e($siteName) ?> — home">
        <?php if ($logo !== ''): ?>
          <img src="<?= e(Url::media($logo)) ?>" alt="<?= e($siteName) ?> logo" class="brand-logo" width="160" height="52" decoding="async">
        <?php endif; ?>
        <?php if ($logoText !== '' || $logoSub !== ''): ?>
          <span class="brand-text">
            <?php if ($logoText !== ''): ?><span class="brand-name"><?= e($logoText) ?></span><?php endif; ?>
            <?php if ($logoSub !== ''): ?><span class="brand-sub"><?= e($logoSub) ?></span><?php endif; ?>
          </span>
        <?php endif; ?>
      </a>

      <div class="header-actions order-lg-3">
        <a class="header-icon-btn d-none d-sm-inline-flex" href="<?= e(Url::to('wishlist.php')) ?>" aria-label="Wishlist">
          <i class="fa-regular fa-heart" aria-hidden="true"></i>
          <?php if ($wishCount > 0): ?><span class="count-badge"><?= (int) $wishCount ?></span><?php endif; ?>
        </a>
        <a class="header-icon-btn" href="<?= e(Url::to('cart.php')) ?>" aria-label="Shopping cart">
          <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
          <span class="count-badge" data-cart-count><?= (int) $cartCount ?></span>
        </a>
        <?php if ($customer): ?>
          <div class="dropdown d-none d-sm-block">
            <button class="header-icon-btn" data-bs-toggle="dropdown" aria-label="My account"><i class="fa-solid fa-user" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
              <li><a class="dropdown-item" href="<?= e(Url::to('my-account.php')) ?>"><i class="fa-solid fa-gauge-high me-2"></i>My Account</a></li>
              <li><a class="dropdown-item" href="<?= e(Url::to('my-orders.php')) ?>"><i class="fa-solid fa-box me-2"></i>My Orders</a></li>
              <li><a class="dropdown-item" href="<?= e(Url::member('dashboard')) ?>"><i class="fa-solid fa-users me-2"></i>Partner Dashboard</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="<?= e(Url::to('logout.php')) ?>"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a></li>
            </ul>
          </div>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm header-cta d-none d-xl-inline-flex" href="<?= e(menu_url((string) Settings::get('header_cta_url', 'mlm-business-opportunity'))) ?>">
          <i class="fa-solid fa-seedling me-2" aria-hidden="true"></i><?= e(Settings::get('header_cta_text', 'Become a Distributor')) ?>
        </a>
        <button class="navbar-toggler d-xl-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open menu">
          <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
      </div>

      <div class="collapse navbar-collapse order-lg-2 site-nav" id="mainNav">
        <ul class="navbar-nav">
          <?php foreach ($menu as $item): ?>
            <?php $hasChildren = !empty($item['children']); ?>
            <li class="nav-item <?= $hasChildren ? 'dropdown' : '' ?>">
              <a class="nav-link <?= $item['url'] !== '#' ? active_class($item['url'], 'active') : '' ?><?= $hasChildren ? ' dropdown-toggle' : '' ?>"
                 href="<?= e(menu_url($item['url'])) ?>" <?= $hasChildren ? 'data-bs-toggle="dropdown"' : '' ?> target="<?= e($item['target'] ?? '_self') ?>" <?= $hasChildren ? 'role="button" aria-expanded="false"' : '' ?>>
                <?= e($item['label']) ?>
              </a>
              <?php if ($hasChildren): ?>
                <ul class="dropdown-menu">
                  <?php foreach ($item['children'] as $child): ?>
                    <li><a class="dropdown-item" href="<?= e(menu_url($child['url'])) ?>" target="<?= e($child['target'] ?? '_self') ?>"><?= e($child['label']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </nav>
</header>

<!-- Mobile offcanvas navigation -->
<div class="offcanvas offcanvas-start offcanvas-mobile" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel" style="max-width:86vw">
  <div class="offcanvas-header border-bottom">
    <img src="<?= e(Url::media($logo)) ?>" alt="<?= e($siteName) ?>" height="38">
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0">
    <div class="p-3 pb-2 border-bottom">
      <form action="<?= e(Url::to('our-products')) ?>" method="get" role="search">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
          <input class="form-control border-start-0 ps-0" type="search" name="q" placeholder="Search products" aria-label="Search products">
        </div>
      </form>
    </div>
    <ul class="list-unstyled mb-0 mnav-list">
      <?php foreach ($menu as $item): ?>
        <?php $hasChildren = !empty($item['children']); ?>
        <li class="border-bottom">
          <?php if ($hasChildren): ?>
            <?php // A parent with a submenu only expands/collapses the list: it never navigates
                  // and never closes the drawer, so the submenu stays visible until it is tapped
                  // again. The parent page itself is reachable from the first submenu entry. ?>
            <button type="button" class="mnav-toggle d-flex justify-content-between align-items-center w-100 px-3 py-3 text-start border-0 bg-transparent text-dark fw-medium"
                    data-bs-toggle="collapse" data-bs-target="#mnav<?= (int) $item['id'] ?>"
                    aria-expanded="false" aria-controls="mnav<?= (int) $item['id'] ?>">
              <span><?= e($item['label']) ?></span>
              <i class="fa-solid fa-chevron-down small text-muted" aria-hidden="true"></i>
            </button>
            <div class="collapse mnav-sub" id="mnav<?= (int) $item['id'] ?>">
              <ul class="list-unstyled ps-3 pb-2 mb-0">
                <?php foreach ($item['children'] as $child): ?>
                  <li><a class="d-block py-2 text-secondary small" href="<?= e(menu_url($child['url'])) ?>"><?= e($child['label']) ?></a></li>
                <?php endforeach; ?>
                <li class="mt-1"><a class="d-block py-2 small fw-semibold text-primary" href="<?= e(menu_url($item['url'])) ?>">All <?= e($item['label']) ?></a></li>
              </ul>
            </div>
          <?php else: ?>
            <a class="d-flex justify-content-between align-items-center px-3 py-3 text-decoration-none text-dark fw-medium" href="<?= e(menu_url($item['url'])) ?>">
              <span><?= e($item['label']) ?></span>
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="p-3">
      <a href="<?= e(Url::member('register')) ?>" class="btn btn-primary w-100 mb-2"><i class="fa-solid fa-user-plus me-2"></i>Register Free</a>
      <a href="<?= e(Url::member('login')) ?>" class="btn btn-outline-primary w-100 mb-2"><i class="fa-solid fa-right-to-bracket me-2"></i>Partner Login</a>
      <a href="<?= e(Url::to('my-account.php')) ?>" class="btn btn-light-green w-100"><i class="fa-solid fa-user me-2"></i>Customer Account</a>
      <hr>
      <p class="small text-muted mb-1"><i class="fa-solid fa-phone me-2"></i><?= e(Settings::get('contact_phone', '')) ?></p>
      <p class="small text-muted mb-0"><i class="fa-regular fa-envelope me-2"></i><?= e(Settings::get('contact_email', '')) ?></p>
    </div>
  </div>
</div>

<?php if ($maintenance): ?>
<div class="alert alert-warning mb-0 rounded-0 text-center small py-2">
  <i class="fa-solid fa-triangle-exclamation me-2"></i><?= e(Settings::get('maintenance_message', 'Scheduled maintenance in progress.')) ?>
</div>
<?php endif; ?>

<main id="mainContent">
