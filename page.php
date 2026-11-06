<?php
/**
 * Dynamic CMS page renderer. Serves every page created in the page builder.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Security;
use Saee\Lib\Url;

$slug = (string) (\Saee\Lib\Request::query('slug') ?? '');
$page = $slug !== '' ? get_page_by_slug($slug) : null;

if (!$page) {
    http_response_code(404);
    $pageTitle = 'Page not found';
    require INCLUDES_PATH . '/header.php';
    echo '<section class="section"><div class="container text-center py-5"><h1 class="display-5">404</h1>'
        . '<p class="text-muted">The page you are looking for does not exist or has been unpublished.</p>'
        . '<a class="btn btn-primary" href="' . e(Url::base()) . '/">Back to home</a></div></section>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}

if ($page['visibility'] === 'members' && !\Saee\Lib\MemberAuth::check()) {
    \Saee\Lib\Session::flash('warning', 'Please sign in to view that page.');
    \Saee\Lib\Request::redirect('member/login');
}

$sections = db()->select(
    "SELECT * FROM page_sections WHERE page_id = :p AND status = 'active' ORDER BY sort_order ASC, id ASC",
    ['p' => $page['id']]
);

$pageTitle = (string) ($page['meta_title'] ?: $page['title']);
$pageDescription = (string) ($page['meta_description'] ?: truncate_text($page['content'], 160));
$pageImage = $page['og_image'] ?: $page['featured_image'];
$canonical = $page['canonical_url'] ?: Url::to($page['slug']);
$pageRobots = (string) ($page['robots'] ?: 'index,follow');
$extraScripts = $page['custom_css'] ? '<style>' . Security::cleanHtml($page['custom_css']) . '</style>' : '';

require INCLUDES_PATH . '/header.php';

echo '<section class="page-hero"' . ($page['banner_image'] ? ' style="background-image:linear-gradient(120deg, rgba(20,83,45,.9), rgba(36,122,60,.75)), url(' . e(Url::media($page['banner_image'])) . ');background-size:cover;background-position:center"' : '') . '>';
echo '<div class="container position-relative"><h1>' . e($page['title']) . '</h1>';
if ($page['meta_description']) {
    echo '<p>' . e($page['meta_description']) . '</p>';
}
echo breadcrumbs([$page['title'] => '']);
echo '</div></section>';
?>
<section class="section">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <?php if ($page['featured_image']): ?>
          <img src="<?= e(Url::media($page['featured_image'])) ?>" class="img-fluid rounded-4 mb-4 shadow-sm" alt="<?= e($page['title']) ?>" loading="lazy">
        <?php endif; ?>
        <div class="prose">
          <?= Security::cleanHtml($page['content']) ?>
        </div>

        <?php foreach ($sections as $section): ?>
          <div class="mt-5">
            <?php if ($section['title']): ?><h2 class="h3"><?= e($section['title']) ?></h2><?php endif; ?>
            <?php if ($section['subtitle']): ?><p class="text-muted"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <?php if ($section['image']): ?><img src="<?= e(Url::media($section['image'])) ?>" class="img-fluid rounded-4 my-3" alt="<?= e($section['title'] ?: '') ?>" loading="lazy"><?php endif; ?>
            <div class="prose"><?= Security::cleanHtml($section['content']) ?></div>
          </div>
        <?php endforeach; ?>

        <?php if ($page['template'] === 'legal'): ?>
          <div class="alert alert-info mt-4 small">
            <i class="fa-solid fa-circle-info me-2"></i>Last updated <?= format_date($page['updated_at']) ?>. For clarifications write to
            <a href="mailto:<?= e(setting('contact_email')) ?>" class="alert-link"><?= e(setting('contact_email')) ?></a>.
          </div>
        <?php endif; ?>
      </div>
      <aside class="col-lg-4">
        <div class="panel mb-3">
          <div class="panel-head"><h3>Company &amp; Policies</h3></div>
          <div class="panel-body pt-2">
            <ul class="list-unstyled small mb-0">
              <?php foreach (['about-us' => 'About Us', 'terms-conditions' => 'Terms & Conditions', 'privacy-policy' => 'Privacy Policy', 'refund-return-policy' => 'Refund & Return Policy', 'shipping-policy' => 'Shipping Policy', 'disclaimer' => 'Disclaimer', 'faqs' => 'FAQs'] as $s => $label): ?>
                <li class="py-2 border-bottom"><a href="<?= e(Url::to($s)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Need help?</h3></div>
          <div class="panel-body small">
            <p class="mb-2"><i class="fa-solid fa-phone me-2 text-primary"></i><?= e(setting('contact_phone')) ?></p>
            <p class="mb-2"><i class="fa-regular fa-envelope me-2 text-primary"></i><?= e(setting('contact_email')) ?></p>
            <a href="<?= e(Url::to('contact-us')) ?>" class="btn btn-primary btn-sm w-100 mt-2">Contact us</a>
          </div>
        </div>
      </aside>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
