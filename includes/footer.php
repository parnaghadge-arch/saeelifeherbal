<?php
/**
 * Public website footer.
 */
declare(strict_types=1);

use Saee\Lib\Settings;
use Saee\Lib\Url;

$footerMenu = get_menu('footer');
$footerSocials = get_social_links();
$newsletterOn = (int) Settings::get('footer_show_newsletter', 1) === 1;
$whatsapp = preg_replace('/\D/', '', (string) Settings::get('whatsapp_number', ''));
?>
</main>

<footer class="site-footer">
  <div class="container position-relative">
    <div class="row g-4">
      <div class="col-lg-4">
        <img src="<?= e(Url::media((string) Settings::get('logo_footer', 'assets/images/logo.png'))) ?>" alt="<?= e(Settings::get('site_name', APP_NAME)) ?>" class="footer-logo" loading="lazy">
        <p class="small pe-lg-4"><?= e(Settings::get('footer_about', '')) ?></p>
        <div class="footer-social my-3">
          <?php foreach ($footerSocials as $social): ?>
            <a href="<?= e($social['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($social['display_name'] ?: $social['platform']) ?>"><i class="<?= e($social['icon'] ?: 'fa-solid fa-link') ?>"></i></a>
          <?php endforeach; ?>
        </div>
        <a href="#" data-pwa-install class="btn btn-sm btn-light-green d-none"><i class="fa-solid fa-mobile-screen me-2"></i>Install App</a>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Quick Links</h6>
        <ul>
          <?php foreach (array_slice($footerMenu, 0, 6) as $item): ?>
            <li><a href="<?= e(menu_url($item['url'])) ?>"><?= e($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-6 col-lg-3">
        <h6>Company &amp; Policies</h6>
        <ul>
          <?php foreach (array_slice($footerMenu, 6) as $item): ?>
            <li><a href="<?= e(menu_url($item['url'])) ?>"><?= e($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-lg-3">
        <h6>Get in Touch</h6>
        <ul class="footer-contact">
          <li><i class="fa-solid fa-location-dot"></i><span><?= nl2br(e(Settings::get('company_address', ''))) ?></span></li>
          <li><i class="fa-solid fa-phone"></i><a href="tel:<?= e(preg_replace('/\s+/', '', (string) Settings::get('contact_phone', ''))) ?>"><?= e(Settings::get('contact_phone', '')) ?></a></li>
          <li><i class="fa-regular fa-envelope"></i><a href="mailto:<?= e(Settings::get('contact_email', '')) ?>"><?= e(Settings::get('contact_email', '')) ?></a></li>
          <?php if (Settings::get('company_gst')): ?>
            <li><i class="fa-solid fa-file-invoice"></i><span>GSTIN: <?= e(Settings::get('company_gst')) ?></span></li>
          <?php endif; ?>
        </ul>
        <?php if ($newsletterOn): ?>
          <h6 class="mt-3">Agri Updates</h6>
          <form class="newsletter-form" data-ajax-form action="<?= e(Url::to('api/auth/newsletter.php')) ?>">
            <div class="input-group">
              <input type="email" name="email" class="form-control form-control-sm" placeholder="Your email" required aria-label="Email">
              <button class="btn btn-accent btn-sm px-3" type="submit"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <?= csrf_field() ?>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Settings::get('footer_note')): ?>
      <div class="row mt-4">
        <div class="col-12">
          <p class="small mb-0" style="color:rgba(255,255,255,.6);font-size:.78rem"><?= e(Settings::get('footer_note')) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="footer-bottom d-flex flex-wrap justify-content-between gap-2">
      <span><?= e(Settings::get('copyright_text', '© ' . date('Y') . ' ' . APP_NAME)) ?></span>
      <span class="d-flex flex-wrap gap-3">
        <a href="<?= e(Url::to('privacy-policy')) ?>">Privacy</a>
        <a href="<?= e(Url::to('terms-conditions')) ?>">Terms</a>
        <a href="<?= e(Url::to('refund-return-policy')) ?>">Refunds</a>
        <a href="<?= e(Url::to('shipping-policy')) ?>">Shipping</a>
        <a href="<?= e(Url::to('sitemap.xml')) ?>">Sitemap</a>
      </span>
    </div>
  </div>
</footer>

<!-- Mobile app-style bottom tab bar (phones / small tablets only) -->
<nav class="mobile-bottom-nav app-tabbar" aria-label="Quick navigation">
  <a href="<?= e(Url::to('')) ?>" class="<?= active_class('home', 'active') ?>">
    <i class="fa-solid fa-house" aria-hidden="true"></i><span>Home</span>
  </a>
  <a href="<?= e(Url::to('products.php')) ?>" class="<?= active_class('products', 'active') ?>">
    <i class="fa-solid fa-seedling" aria-hidden="true"></i><span>Shop</span>
  </a>
  <a href="<?= e(Url::to('product-categories')) ?>" class="<?= active_class('product-categories', 'active') ?>">
    <i class="fa-solid fa-layer-group" aria-hidden="true"></i><span>Explore</span>
  </a>
  <a href="<?= e(Url::to('cart.php')) ?>" class="<?= active_class('cart', 'active') ?>">
    <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><span>Cart</span>
    <span class="tab-badge" data-cart-count><?= (int) cart_count() ?></span>
  </a>
  <a href="<?= e(Url::to('my-account.php')) ?>" class="<?= active_class('my-account', 'active') ?>">
    <i class="fa-solid fa-user" aria-hidden="true"></i><span>Account</span>
  </a>
</nav>

<?php if ($whatsapp !== ''): ?>
<a class="float-wa" href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="fab fa-whatsapp"></i></a>
<?php endif; ?>
<button class="back-to-top" type="button" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>

<script>
window.SAEE = {
  base: <?= json_encode(Url::base()) ?>,
  csrf: <?= json_encode(\Saee\Lib\Security::csrfToken()) ?>,
  routes: {
    cartAdd: <?= json_encode(Url::to('api/orders/cart-add.php')) ?>,
    cartUpdate: <?= json_encode(Url::to('api/orders/cart-update.php')) ?>,
    cartRemove: <?= json_encode(Url::to('api/orders/cart-remove.php')) ?>,
    wishlistToggle: <?= json_encode(Url::to('api/products/wishlist.php')) ?>,
    sponsorLookup: <?= json_encode(Url::to('api/members/sponsor-lookup.php')) ?>,
    track: <?= json_encode(Url::to('api/members/track.php')) ?>
  }
};
</script>
<script src="<?= e(Url::asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(Url::asset('js/app.js')) ?>?v=<?= APP_VERSION ?>" defer></script>
<?php if (Settings::get('google_analytics_id')): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(Settings::get('google_analytics_id')) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= json_encode(Settings::get('google_analytics_id')) ?>);</script>
<?php endif; ?>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
