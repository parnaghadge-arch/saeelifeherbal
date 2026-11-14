<?php
/**
 * Admin layout. Vars: $pageTitle, $pageHeading, $pageSub, $activeModule, $pageActions (html)
 */
declare(strict_types=1);

use Saee\Lib\AdminAuth;
use Saee\Lib\Settings;
use Saee\Lib\Url;

$siteName = (string) Settings::get('site_name', APP_NAME);
$activeModule = $activeModule ?? 'dashboard';
$adminName = (string) (AdminAuth::user()['name'] ?? 'Admin');
$can = static fn (string $perm): bool => AdminAuth::can($perm);

$menu = [
    // Ordered by how often the panel is actually used: the day-to-day operational screens
    // first, the set-up-once website content and configuration last.
    'Overview' => [
        ['label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'href' => 'index.php', 'key' => 'dashboard', 'perm' => 'dashboard.view'],
        ['label' => 'Reports', 'icon' => 'fa-chart-line', 'href' => 'reports.php', 'key' => 'reports', 'perm' => 'reports.view'],
    ],
    'People' => [
        ['label' => 'Members', 'icon' => 'fa-user-group', 'href' => 'members.php', 'key' => 'members', 'perm' => 'members.view'],
        ['label' => 'KYC Verification', 'icon' => 'fa-id-card', 'href' => 'kyc.php', 'key' => 'kyc', 'perm' => 'kyc.view'],
        ['label' => 'Customers', 'icon' => 'fa-users', 'href' => 'customers.php', 'key' => 'customers', 'perm' => 'members.view'],
        ['label' => 'Genealogy Tree', 'icon' => 'fa-network-wired', 'href' => 'genealogy.php', 'key' => 'genealogy', 'perm' => 'mlm.tree'],
    ],
    'Sales' => [
        ['label' => 'Orders', 'icon' => 'fa-bag-shopping', 'href' => 'orders.php', 'key' => 'orders', 'perm' => 'orders.view'],
        ['label' => 'Record Purchase', 'icon' => 'fa-cart-plus', 'href' => 'purchase.php', 'key' => 'purchase', 'perm' => 'orders.view'],
        ['label' => 'Products', 'icon' => 'fa-seedling', 'href' => 'products.php', 'key' => 'products', 'perm' => 'products.manage'],
        ['label' => 'Categories', 'icon' => 'fa-layer-group', 'href' => 'categories.php', 'key' => 'categories', 'perm' => 'categories.manage'],
    ],
    'Money' => [
        ['label' => 'Commissions', 'icon' => 'fa-receipt', 'href' => 'commissions.php', 'key' => 'commissions', 'perm' => 'wallets.view'],
        ['label' => 'Wallets', 'icon' => 'fa-wallet', 'href' => 'wallets.php', 'key' => 'wallets', 'perm' => 'wallets.view'],
        ['label' => 'Payouts', 'icon' => 'fa-money-bill-transfer', 'href' => 'payouts.php', 'key' => 'payouts', 'perm' => 'payouts.approve'],
        ['label' => 'Payout Reports', 'icon' => 'fa-file-invoice-dollar', 'href' => 'payout-reports.php', 'key' => 'payout-reports', 'perm' => 'payouts.reports'],
        ['label' => 'Royalty', 'icon' => 'fa-trophy', 'href' => 'royalty.php', 'key' => 'royalty', 'perm' => 'royalty.manage'],
    ],
    'Leads' => [
        ['label' => 'Enquiries', 'icon' => 'fa-envelope-open-text', 'href' => 'enquiries.php', 'key' => 'enquiries', 'perm' => 'enquiries.view'],
        ['label' => 'B2B Leads', 'icon' => 'fa-handshake', 'href' => 'b2b-leads.php', 'key' => 'b2b', 'perm' => 'b2b.view'],
    ],
    'Website Content' => [
        ['label' => 'Pages', 'icon' => 'fa-file-lines', 'href' => 'pages.php', 'key' => 'pages', 'perm' => 'cms.pages'],
        ['label' => 'Sliders', 'icon' => 'fa-images', 'href' => 'sliders.php', 'key' => 'sliders', 'perm' => 'cms.sliders'],
        ['label' => 'Blogs', 'icon' => 'fa-newspaper', 'href' => 'blogs.php', 'key' => 'blogs', 'perm' => 'cms.blogs'],
        ['label' => 'Blog Categories', 'icon' => 'fa-tags', 'href' => 'blog-categories.php', 'key' => 'blog-categories', 'perm' => 'cms.blogs'],
        ['label' => 'Gallery & Videos', 'icon' => 'fa-photo-film', 'href' => 'gallery.php', 'key' => 'gallery', 'perm' => 'cms.gallery'],
        ['label' => 'FAQs & Testimonials', 'icon' => 'fa-circle-question', 'href' => 'faqs.php', 'key' => 'content', 'perm' => 'cms.content'],
        ['label' => 'Founder & Team', 'icon' => 'fa-people-group', 'href' => 'team.php', 'key' => 'team', 'perm' => 'cms.content'],
        ['label' => 'Navigation', 'icon' => 'fa-bars-staggered', 'href' => 'navigation.php', 'key' => 'navigation', 'perm' => 'cms.navigation'],
        ['label' => 'Media Library', 'icon' => 'fa-folder-open', 'href' => 'media.php', 'key' => 'media', 'perm' => 'cms.media'],
    ],
    'Configuration' => [
        ['label' => 'Payment Methods', 'icon' => 'fa-credit-card', 'href' => 'payment-methods.php', 'key' => 'payment-methods', 'perm' => 'settings.manage'],
        ['label' => 'MLM Settings', 'icon' => 'fa-sitemap', 'href' => 'mlm-settings.php', 'key' => 'mlm-settings', 'perm' => 'settings.manage'],
        ['label' => 'Settings', 'icon' => 'fa-gear', 'href' => 'settings.php', 'key' => 'settings', 'perm' => 'settings.manage'],
        ['label' => 'Social Links', 'icon' => 'fa-share-nodes', 'href' => 'social.php', 'key' => 'social', 'perm' => 'settings.manage'],
        ['label' => 'Notification Templates', 'icon' => 'fa-bell', 'href' => 'notification-templates.php', 'key' => 'notification-templates', 'perm' => 'settings.notifications'],
        ['label' => 'Admin Users', 'icon' => 'fa-user-shield', 'href' => 'admin-users.php', 'key' => 'admin-users', 'perm' => 'users.manage'],
        ['label' => 'Roles & Permissions', 'icon' => 'fa-lock', 'href' => 'roles.php', 'key' => 'roles', 'perm' => 'roles.manage'],
        ['label' => 'Audit Logs', 'icon' => 'fa-clipboard-list', 'href' => 'audit-logs.php', 'key' => 'audit-logs', 'perm' => 'audit.view'],
        ['label' => 'Backups', 'icon' => 'fa-database', 'href' => 'backups.php', 'key' => 'backups', 'perm' => 'backups.manage'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="<?= e((string) Settings::get('theme_dark', '#14532D')) ?>">
<title><?= e(($pageTitle ?? 'Dashboard') . ' | Admin · ' . $siteName) ?></title>
<link rel="icon" href="<?= e(Url::media((string) Settings::get('favicon', 'assets/images/logo-square.png'))) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('vendor/fontawesome/css/all.min.css')) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('css/app.css')) ?>?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('vendor/datatables/dataTables.bootstrap5.min.css')) ?>">
<style>:root{--saee-primary:<?= e((string) Settings::get('theme_primary', '#247A3C')) ?>;}</style>
</head>
<body class="app-body admin-body">
<div class="app-shell">
  <aside class="app-sidebar admin-sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <img src="<?= e(Url::media((string) Settings::get('logo_admin', 'assets/images/logo.png'))) ?>" alt="<?= e($siteName) ?>">
      <span>Admin Panel<br><small style="opacity:.6;font-weight:400"><?= e($siteName) ?></small></span>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($menu as $group => $items): ?>
        <?php
        // Hide the heading when the role cannot see anything in the group, otherwise the
        // sidebar collects orphan labels with nothing under them.
        $visible = array_values(array_filter($items, static fn ($i): bool => $can($i['perm'])));
        if ($visible === []) { continue; }
        ?>
        <div class="nav-section"><?= e($group) ?></div>
        <?php foreach ($visible as $item): ?>
          <a href="<?= e(\Saee\Lib\Url::admin($item['href'])) ?>" class="<?= $activeModule === $item['key'] ? 'active' : '' ?>">
            <i class="fa-solid <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="app-main">
    <header class="app-topbar">
      <button class="btn btn-sm btn-light d-lg-none" id="sidebarToggle" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
      <div class="flex-grow-1 min-w-0">
        <h1 class="page-title text-truncate"><?= e($pageHeading ?? $pageTitle ?? 'Dashboard') ?></h1>
        <p class="page-sub text-truncate"><?= e($pageSub ?? '') ?></p>
      </div>
      <?php // Rendered once: on phones the actions wrap onto their own line under the title
            // instead of being duplicated into the content area. ?>
      <?php if (!empty($pageActions)): ?><div class="page-actions d-none d-md-flex gap-2"><?= $pageActions ?></div><?php endif; ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-light-green dropdown-toggle" data-bs-toggle="dropdown">
          <i class="fa-solid fa-user-shield me-1"></i><span class="d-none d-sm-inline"><?= e($adminName) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted"><?= e((string) (AdminAuth::user()['role_name'] ?? 'Administrator')) ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= e(\Saee\Lib\Url::admin('profile.php')) ?>"><i class="fa-solid fa-user me-2"></i>My Account</a></li>
          <li><a class="dropdown-item" href="<?= e(Url::to('')) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-globe me-2"></i>View Website</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= e(\Saee\Lib\Url::admin('logout.php?confirm=1')) ?>" data-confirm-link="Sign out of the admin panel?"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
        </ul>
      </div>
    </header>

    <div class="app-content">
      <?php if (!empty($pageActions)): ?><div class="page-actions d-md-none mb-2 d-flex gap-2 flex-wrap"><?= $pageActions ?></div><?php endif; ?>
      <?= render_flash() ?>
