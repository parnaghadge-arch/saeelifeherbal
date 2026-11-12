<?php
/**
 * Member portal layout (mobile-first app shell).
 * Variables before include: $pageTitle, $pageHeading, $pageSub, $activeNav, $pageRobots
 */
declare(strict_types=1);

use Saee\Lib\MemberAuth;
use Saee\Lib\Notification;
use Saee\Lib\Settings;
use Saee\Lib\Url;

$member = $member ?? MemberAuth::user();
$siteName = (string) Settings::get('site_name', APP_NAME);
$unread = $member ? Notification::unreadCount('member', (int) $member['id']) : 0;
$activeNav = $activeNav ?? 'dashboard';
$kycStatus = $member['kyc_status'] ?? 'not_submitted';

$navGroups = [
    'Main' => [
        ['dashboard', 'Dashboard', 'fa-gauge-high', 'dashboard.php'],
        ['buy-products', 'Buy Products', 'fa-cart-plus', 'buy-products.php'],
        ['referrals', 'My Referrals', 'fa-user-group', 'referrals.php'],
        ['team', 'My Team', 'fa-sitemap', 'team.php'],
        ['genealogy', 'Genealogy Tree', 'fa-network-wired', 'genealogy.php'],
    ],
    'Earnings' => [
        ['wallet', 'My Wallet', 'fa-wallet', 'wallet.php'],
        ['commissions', 'Commission History', 'fa-receipt', 'commissions.php'],
        ['royalty', 'Royalty & Incentives', 'fa-trophy', 'royalty.php'],
    ],
    'Payouts' => [
        ['payout-request', 'Request Payout', 'fa-money-bill-transfer', 'payout-request.php'],
        ['payouts', 'Payout History', 'fa-clock-rotate-left', 'payouts.php'],
        ['statements', 'Download Statements', 'fa-file-arrow-down', 'statements.php'],
    ],
    'Account' => [
        ['profile', 'My Profile', 'fa-user', 'profile.php'],
        ['kyc', 'My KYC', 'fa-id-card', 'kyc.php'],
        ['bank', 'Bank Details', 'fa-building-columns', 'bank.php'],
        ['orders', 'Purchase History', 'fa-bag-shopping', 'orders.php'],
        ['notifications', 'Notifications', 'fa-bell', 'notifications.php'],
        ['support', 'Support', 'fa-headset', 'support.php'],
        ['password', 'Change Password', 'fa-key', 'password.php'],
    ],
];
$bottomNav = [
    ['dashboard', 'Home', 'fa-house', 'dashboard.php'],
    ['wallet', 'Wallet', 'fa-wallet', 'wallet.php'],
    ['referrals', 'Team', 'fa-user-group', 'referrals.php'],
    ['payout-request', 'Payout', 'fa-money-bill-transfer', 'payout-request.php'],
    ['profile', 'Profile', 'fa-user', 'profile.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e((string) Settings::get('theme_dark', '#14532D')) ?>">
<?= page_meta([
    'title' => ($pageTitle ?? 'Partner Portal') . ' | ' . $siteName . ' Partners',
    'robots' => $pageRobots ?? 'noindex,nofollow',
]) ?>
<link rel="icon" href="<?= e(Url::media((string) Settings::get('favicon', 'assets/images/logo-square.png'))) ?>">
<link rel="apple-touch-icon" href="<?= e(Url::media((string) Settings::get('logo_pwa', 'assets/images/logo-square.png'))) ?>">
<link rel="manifest" href="<?= e(Url::to('manifest.json')) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('vendor/fontawesome/css/all.min.css')) ?>">
<link rel="stylesheet" href="<?= e(\Saee\Lib\Url::asset('css/app.css')) ?>?v=<?= APP_VERSION ?>">
<style>:root{--saee-primary:<?= e((string) Settings::get('theme_primary', '#247A3C')) ?>;--saee-dark:<?= e((string) Settings::get('theme_dark', '#14532D')) ?>;--saee-accent:<?= e((string) Settings::get('theme_accent', '#A5C94A')) ?>;}</style>
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <img src="<?= e(Url::media((string) Settings::get('logo_admin', 'assets/images/logo.png'))) ?>" alt="<?= e($siteName) ?>">
      <span>Partner Portal<br><small style="opacity:.6;font-weight:400"><?= e($siteName) ?></small></span>
    </div>
    <div class="px-3 py-2 border-bottom" style="border-color:rgba(255,255,255,.09)!important">
      <div class="d-flex align-items-center gap-2">
        <div class="avatar-circle" style="width:36px;height:36px;font-size:.78rem;background:rgba(255,255,255,.12);color:#fff"><?= e(initials((string) ($member['name'] ?? 'U'))) ?></div>
        <div class="min-w-0">
          <div class="text-white small text-truncate" style="max-width:170px"><?= e($member['name'] ?? '') ?></div>
          <div style="font-size:.7rem;opacity:.65"><?= e($member['member_code'] ?? '') ?></div>
        </div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navGroups as $group => $items): ?>
        <div class="nav-section"><?= e($group) ?></div>
        <?php foreach ($items as [$key, $label, $icon, $href]): ?>
          <a href="<?= e(\Saee\Lib\Url::member($href)) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
            <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
            <?php if ($key === 'notifications' && $unread > 0): ?><span class="nav-badge"><?= (int) $unread ?></span><?php endif; ?>
            <?php if ($key === 'kyc' && $kycStatus !== 'approved'): ?><span class="nav-badge" style="background:#FFB020">!</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="nav-section">More</div>
      <a href="<?= e(Url::to('')) ?>"><i class="fa-solid fa-store"></i><span>Visit Website</span></a>
      <a href="<?= e(\Saee\Lib\Url::member('logout.php')) ?>"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </nav>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="app-main">
    <header class="app-topbar">
      <button class="btn btn-sm btn-light d-lg-none" id="sidebarToggle" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
      <div class="flex-grow-1 min-w-0">
        <h1 class="page-title text-truncate"><?= e($pageHeading ?? $pageTitle ?? 'Dashboard') ?></h1>
        <p class="page-sub text-truncate"><?= e($pageSub ?? '') ?></p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a class="header-icon-btn d-none d-sm-inline-flex" href="<?= e(\Saee\Lib\Url::member('notifications.php')) ?>" aria-label="Notifications">
          <i class="fa-regular fa-bell"></i>
          <?php if ($unread > 0): ?><span class="count-badge"><?= (int) $unread ?></span><?php endif; ?>
        </a>
        <div class="dropdown">
          <button class="btn btn-sm btn-light-green dropdown-toggle" data-bs-toggle="dropdown">
            <span class="d-none d-sm-inline"><?= e($member['member_code'] ?? '') ?></span><i class="fa-solid fa-user ms-2"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= e(\Saee\Lib\Url::member('profile.php')) ?>"><i class="fa-solid fa-user me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="<?= e(\Saee\Lib\Url::member('password.php')) ?>"><i class="fa-solid fa-key me-2"></i>Change Password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= e(\Saee\Lib\Url::member('logout.php')) ?>"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <div class="app-content">
      <?= render_flash() ?>
      <?php if ($kycStatus !== 'approved' && ($activeNav ?? '') !== 'kyc'): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 small">
          <i class="fa-solid fa-id-card mt-1"></i>
          <div class="flex-grow-1">
            <strong>Complete your KYC to enable withdrawals.</strong>
            You can keep earning and viewing your wallet balance, but payouts are released only after KYC and bank verification.
          </div>
          <a class="btn btn-sm btn-dark" href="<?= e(\Saee\Lib\Url::member('kyc.php')) ?>">Complete</a>
        </div>
      <?php endif; ?>
