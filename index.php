<?php
/**
 * Public front controller + home page.
 *
 * Apache/nginx rewrite every extension-less URL here, so this file first resolves the
 * request to the right script (via includes/router.php) and only renders the homepage
 * when the request really is for "/". That keeps /product-categories,
 * /product-categories.php and / serving the same page instead of silently falling
 * through to the CMS page renderer.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/router.php';
// Only act as a front controller on a direct hit (/index.php or "/"). When the router has
// already resolved this request (SAEE_ROUTED, e.g. /home -> index.php) just render the page.
if (!defined('SAEE_ROUTED')) {
    $__slug = saee_request_path();
    if ($__slug !== '' && !in_array($__slug, ['index', 'index.php'], true)) {
        saee_dispatch(saee_resolve($__slug), $__slug);
        exit;
    }
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Settings;
use Saee\Lib\Url;

$sliders = get_sliders();
$categories = get_categories(true, 12);
$featured = get_featured_products(8);
$newProducts = get_products(['limit' => 4, 'sort' => 'newest']);
$testimonials = get_testimonials(6);
$blogs = get_blogs(['limit' => 3]);
$stats = site_stats();
$homePage = get_page_by_slug('home');

$pageTitle = (string) Settings::get('site_name', APP_NAME) . ' | ' . (string) Settings::get('site_tagline', '');
$pageDescription = (string) Settings::get('meta_description_default', '');
require INCLUDES_PATH . '/header.php';
?>

<!-- -------------------------------------------------------------- hero -->
<?php if ($sliders !== []): ?>
<section class="hero-slider" data-hero-slider aria-roledescription="carousel" aria-label="Featured highlights">
  <?php foreach ($sliders as $i => $slide):
      $desktop = Url::media($slide['image_desktop'] ?: 'assets/images/demo/hero-1.jpg');
      $mobile = Url::media($slide['image_mobile'] ?: ($slide['image_desktop'] ?: 'assets/images/demo/hero-1.jpg'));
      $align = $slide['text_align'] ?: 'left';
      $textAnim = 'anim-' . ($slide['text_animation'] ?: 'fade-up');
      ?>
    <div class="hero-slide text-<?= e($align) ?>" data-duration="<?= (float) $slide['duration'] ?>" data-image-anim="<?= e($slide['image_animation']) ?>">
      <div class="hero-media">
        <picture>
          <source media="(max-width: 767px)" srcset="<?= e($mobile) ?>">
          <img src="<?= e($desktop) ?>" alt="<?= e($slide['title'] ?: 'Saee Life Herbal') ?>"
               class="<?= $slide['image_animation'] !== 'none' ? 'img-' . e($slide['image_animation']) : '' ?>"
               <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
        </picture>
      </div>
      <div class="hero-overlay" style="background:<?= e($slide['overlay_color'] ?: '#14532D') ?>;opacity:<?= (float) $slide['overlay_opacity'] ?>"></div>
      <div class="container position-relative">
        <div class="hero-content <?= $align === 'center' ? 'mx-auto' : ($align === 'right' ? 'ms-auto' : '') ?>">
          <?php if ($slide['subtitle']): ?>
            <span class="hero-eyebrow <?= $textAnim ?>"><?= e($slide['subtitle']) ?></span>
          <?php endif; ?>
          <?php if ((int) $slide['show_heading'] === 1 && $slide['title']): ?>
            <h1 class="<?= $textAnim ?>"><?= e($slide['title']) ?></h1>
          <?php endif; ?>
          <?php if ((int) $slide['show_description'] === 1 && $slide['description']): ?>
            <p class="<?= $textAnim ?>"><?= e($slide['description']) ?></p>
          <?php endif; ?>
          <?php if ((int) $slide['show_buttons'] === 1): ?>
            <div class="d-flex flex-wrap gap-2 <?= $align === 'center' ? 'justify-content-center' : '' ?> <?= $textAnim ?>">
              <?php if ($slide['button_text']): ?>
                <a class="btn btn-primary btn-lg" href="<?= e(menu_url((string) $slide['button_url'])) ?>"><?= e($slide['button_text']) ?> <i class="fa-solid fa-arrow-right ms-1"></i></a>
              <?php endif; ?>
              <?php if ($slide['button2_text']): ?>
                <a class="btn btn-ghost-light btn-lg" href="<?= e(menu_url((string) $slide['button2_url'])) ?>"><?= e($slide['button2_text']) ?></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (count($sliders) > 1): ?>
    <button class="hero-arrow prev" type="button" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>
    <button class="hero-arrow next" type="button" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>
    <div class="hero-dots"></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ------------------------------------------------------ value strip -->
<section class="section-sm bg-white border-bottom">
  <div class="container">
    <div class="row g-3 g-md-4">
      <?php
      $usps = [
          ['fa-flask-vial', 'Herbal formulations', 'Botanical-first crop care developed for Indian soils.'],
          ['fa-truck-fast', 'Pan-India delivery', 'Reliable dispatch through registered agri logistics partners.'],
          ['fa-headset', 'Agronomy support', 'Field officers and a helpline in local languages.'],
          ['fa-handshake-angle', 'Transparent partner plan', 'Product-sale based commissions, no joining fee.'],
      ];
      foreach ($usps as [$icon, $title, $text]): ?>
        <div class="col-6 col-lg-3">
          <div class="d-flex gap-3 align-items-start">
            <span class="feature-icon" style="width:46px;height:46px;font-size:1rem;border-radius:12px;margin:0"><i class="fa-solid <?= e($icon) ?>"></i></span>
            <div>
              <h3 class="h6 mb-1"><?= e($title) ?></h3>
              <p class="small text-muted mb-0"><?= e($text) ?></p>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ------------------------------------------------------- categories -->
<?php if ($categories !== []): ?>
<section class="section">
  <div class="container">
    <div class="text-center reveal">
      <span class="eyebrow">Our Range</span>
      <h2 class="section-title">Solutions for every crop stage</h2>
      <p class="section-subtitle">From soil preparation to harvest protection, Saee Life Herbal covers the complete crop nutrition and protection cycle.</p>
    </div>
    <?php // Carousel: 3 tiles on desktop, 2 on tablet, 1 on phones. Swipeable on touch, arrow
          // buttons and dots elsewhere. ?>
    <div class="category-carousel" data-cat-carousel tabindex="0" role="group"
         aria-roledescription="carousel" aria-label="Product categories">
      <div class="cat-track" data-cat-track>
        <?php foreach ($categories as $i => $category): ?>
          <div class="cat-slide" role="group" aria-roledescription="slide" aria-label="<?= (int) $i + 1 ?> of <?= count($categories) ?>">
            <a class="category-tile" href="<?= e(Url::to('products.php?category=' . urlencode((string) $category['slug']))) ?>">
              <img src="<?= e(Url::media($category['image'])) ?>" alt="<?= e($category['name']) ?>" loading="lazy">
              <div class="tile-body">
                <h3><?= e($category['name']) ?></h3>
                <p><?= (int) $category['product_count'] ?> products</p>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($categories) > 1): ?>
        <div class="cat-controls">
          <button type="button" class="t-nav cat-prev" data-cat-prev aria-label="Previous categories"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
          <div class="t-dots" data-cat-dots role="tablist" aria-label="Choose categories"></div>
          <button type="button" class="t-nav cat-next" data-cat-next aria-label="Next categories"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
        </div>
      <?php endif; ?>
    </div>
    <div class="text-center mt-4">
      <a href="<?= e(Url::to('product-categories.php')) ?>" class="btn btn-outline-primary">View all categories <i class="fa-solid fa-arrow-right ms-1"></i></a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- -------------------------------------------------------- featured -->
<?php if ($featured !== []): ?>
<section class="section bg-white">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
      <div class="reveal">
        <span class="eyebrow">Bestsellers</span>
        <h2 class="section-title mb-1">Featured products</h2>
        <p class="text-muted mb-0">Field-proven favourites among our farmer and distributor network.</p>
      </div>
      <a href="<?= e(Url::to('our-products')) ?>" class="btn btn-outline-primary btn-sm">Browse all products <i class="fa-solid fa-arrow-right ms-1"></i></a>
    </div>
    <div class="row g-4">
      <?php foreach ($featured as $product): ?>
        <div class="col-6 col-lg-3 reveal"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- --------------------------------------------------------- counters -->
<section class="section-sm counter-band">
  <div class="container">
    <div class="row g-4 text-center">
      <div class="col-6 col-lg-3 counter-item">
        <div class="counter-icon"><i class="fa-solid fa-people-group"></i></div>
        <div class="counter-value"><span data-count-to="<?= (int) $stats['members'] ?>" data-suffix="+">0</span></div>
        <div class="counter-label">Farmer partners</div>
      </div>
      <div class="col-6 col-lg-3 counter-item">
        <div class="counter-icon"><i class="fa-solid fa-box-open"></i></div>
        <div class="counter-value"><span data-count-to="<?= (int) $stats['products'] ?>" data-suffix="+">0</span></div>
        <div class="counter-label">Agri products</div>
      </div>
      <div class="col-6 col-lg-3 counter-item">
        <div class="counter-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <div class="counter-value"><span data-count-to="<?= (int) $stats['orders'] ?>" data-suffix="+">0</span></div>
        <div class="counter-label">Orders fulfilled</div>
      </div>
      <div class="col-6 col-lg-3 counter-item">
        <div class="counter-icon"><i class="fa-solid fa-map-location-dot"></i></div>
        <div class="counter-value"><span data-count-to="<?= max(1, (int) $stats['districts']) ?>" data-suffix="+">0</span></div>
        <div class="counter-label">Districts served</div>
      </div>
    </div>
  </div>
</section>

<!-- -------------------------------------------------------- why choose -->
<section class="section">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 reveal">
        <div class="ratio-cover shadow-saee">
          <img src="<?= e(Url::media('assets/images/demo/hero-2.jpg')) ?>" alt="Healthy soil and seedlings" loading="lazy" class="w-100" style="aspect-ratio:4/3;object-fit:cover">
        </div>
      </div>
      <div class="col-lg-6 reveal">
        <span class="eyebrow">Why Saee Life Herbal</span>
        <h2 class="section-title">Built around the farmer, not just the product</h2>
        <p class="text-muted">We combine herbal-first formulations with practical agronomy support, honest pricing and a partner programme that pays only on genuine product sales.</p>
        <ul class="list-check mt-3">
          <li><strong>Label-first guidance.</strong> Clear dosage, crop suitability and safety information on every product page.</li>
          <li><strong>Quality you can trace.</strong> Batch tested inputs with documented storage and handling guidance.</li>
          <li><strong>Fair partner economics.</strong> Product-wise direct and grand-direct commissions set by the company.</li>
          <li><strong>No recruitment-only income.</strong> Commission is tied to real, paid and completed sales.</li>
        </ul>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a href="<?= e(Url::to('about-us')) ?>" class="btn btn-primary">About us</a>
          <a href="<?= e(Url::to('mlm-business-opportunity')) ?>" class="btn btn-outline-primary">Business opportunity</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ------------------------------------------------------- how it works -->
<section class="section bg-white">
  <div class="container">
    <div class="text-center reveal">
      <span class="eyebrow">Simple &amp; transparent</span>
      <h2 class="section-title">How the Saee Life Herbal partner plan works</h2>
      <p class="section-subtitle">Register free, share genuine product value with farmers around you, and earn eligible commissions when referred customers buy.</p>
    </div>
    <div class="row g-4">
      <?php
      $steps = [
          ['Register free', 'Create your partner account in minutes. No joining fee and no kit purchase requirement.', 'fa-user-plus'],
          ['Get your member ID', 'Receive a unique member ID, referral link and QR code from your dashboard.', 'fa-id-card'],
          ['Share product value', 'Recommend the right product for the crop stage using our agronomy resources.', 'fa-share-nodes'],
          ['Earn on real sales', 'Direct and grand-direct commissions are credited when orders are paid and completed.', 'fa-wallet'],
      ];
      foreach ($steps as $i => [$title, $desc, $icon]): ?>
        <div class="col-md-6 col-lg-3 reveal">
          <div class="step-card">
            <div class="step-num"><?= $i + 1 ?></div>
            <h3><?= e($title) ?></h3>
            <p class="text-muted small mb-0"><?= e($desc) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="alert alert-warning mt-4 small mb-0">
      <i class="fa-solid fa-circle-info me-2"></i>Income depends entirely on genuine product sales and is not guaranteed. There is no joining fee and no commission for recruitment alone.
      <a href="<?= e(Url::to('disclaimer')) ?>" class="alert-link">Read the full disclosure</a>.
    </div>
  </div>
</section>

<!-- ------------------------------------------------------- new arrivals -->
<?php if ($newProducts !== []): ?>
<section class="section">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
      <div class="reveal">
        <span class="eyebrow">Just added</span>
        <h2 class="section-title mb-1">New in the store</h2>
      </div>
      <a href="<?= e(Url::to('our-products')) ?>" class="btn btn-outline-primary btn-sm">Shop all</a>
    </div>
    <div class="row g-4">
      <?php foreach ($newProducts as $product): ?>
        <div class="col-6 col-lg-3 reveal"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ------------------------------------------------------ testimonials -->
<?php if ($testimonials !== []): ?>
<section class="section bg-white testimonial-section">
  <div class="container">
    <div class="text-center reveal">
      <span class="eyebrow">Farmer voices</span>
      <h2 class="section-title">Trusted in the field</h2>
      <p class="section-subtitle">Real feedback from farmers and partners using our products.</p>
    </div>

    <?php // One card per view on phones, three on desktop — the rest stay off-canvas instead of
          // stacking into a long column that buries the page. ?>
    <div class="testimonial-carousel" data-testimonial-carousel tabindex="0" role="group"
         aria-roledescription="carousel" aria-label="Farmer testimonials">
      <div class="testimonial-track" data-track>
        <?php foreach ($testimonials as $i => $t): ?>
          <div class="testimonial-slide" role="group" aria-roledescription="slide"
               aria-label="<?= (int) $i + 1 ?> of <?= count($testimonials) ?>">
            <div class="testimonial-card">
              <i class="fa-solid fa-quote-right quote-mark" aria-hidden="true"></i>
              <div class="stars mb-2" aria-label="<?= (int) $t['rating'] ?> out of 5"><?= str_repeat('<i class="fa-solid fa-star" aria-hidden="true"></i>', (int) $t['rating']) ?></div>
              <p class="mb-0"><?= e($t['message']) ?></p>
              <div class="testimonial-author">
                <div class="avatar-circle"><?= e(initials((string) $t['name'])) ?></div>
                <div>
                  <div class="fw-semibold small"><?= e($t['name']) ?></div>
                  <div class="text-muted" style="font-size:.78rem"><?= e(trim(($t['designation'] ?? '') . ($t['location'] ? ' · ' . $t['location'] : ''), ' ·')) ?></div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($testimonials) > 1): ?>
        <div class="t-controls">
          <button type="button" class="t-nav t-prev" data-t-prev aria-label="Previous testimonial">
            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
          </button>
          <div class="t-dots" data-t-dots role="tablist" aria-label="Choose testimonial"></div>
          <button type="button" class="t-nav t-next" data-t-next aria-label="Next testimonial">
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
          </button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ------------------------------------------------------------- blogs -->
<?php if ($blogs !== []): ?>
<section class="section">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
      <div class="reveal">
        <span class="eyebrow">Knowledge hub</span>
        <h2 class="section-title mb-1">Agriculture insights</h2>
        <p class="text-muted mb-0">Practical guidance from our agronomy desk.</p>
      </div>
      <a href="<?= e(Url::to('blogs')) ?>" class="btn btn-outline-primary btn-sm">All articles</a>
    </div>
    <div class="row g-4">
      <?php foreach ($blogs as $blog): ?>
        <div class="col-md-4 reveal">
          <article class="blog-card">
            <a href="<?= e(blog_url((string) $blog['slug'])) ?>" class="blog-thumb d-block">
              <img src="<?= e(Url::media($blog['featured_image'])) ?>" alt="<?= e($blog['title']) ?>" loading="lazy">
            </a>
            <div class="p-3 d-flex flex-column flex-grow-1">
              <div class="blog-meta">
                <span><i class="fa-regular fa-calendar me-1"></i><?= format_date($blog['published_at']) ?></span>
                <?php if ($blog['category_name']): ?><span><i class="fa-solid fa-tag me-1"></i><?= e($blog['category_name']) ?></span><?php endif; ?>
              </div>
              <h3 class="h6"><a class="text-dark-green" href="<?= e(blog_url((string) $blog['slug'])) ?>"><?= e($blog['title']) ?></a></h3>
              <p class="small text-muted text-truncate-2 mb-3"><?= e(truncate_text($blog['excerpt'], 110)) ?></p>
              <a class="small fw-medium mt-auto" href="<?= e(blog_url((string) $blog['slug'])) ?>">Read article <i class="fa-solid fa-arrow-right ms-1"></i></a>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php
// Founder & team — populated from Admin → Founder & Team (skipped when empty).
$teamRole = null;
$teamHome = true;
$teamEyebrow = 'Leadership';
$teamTitle = 'Meet the founder and our team';
$teamLead = 'The people who formulate, field-test and stand behind every Saee Life Herbal product.';
require INCLUDES_PATH . '/team-section.php';
?>

<!-- --------------------------------------------------------------- CTA -->
<section class="section-sm">
  <div class="container">
    <div class="cta-band reveal">
      <div class="row align-items-center g-4 position-relative" style="z-index:2">
        <div class="col-lg-8">
          <h2 class="h3 mb-2">Ready to grow with Saee Life Herbal?</h2>
          <p>Register as a farmer partner or shop crop care products with doorstep delivery.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <a href="<?= e(Url::member('register')) ?>" class="btn btn-accent btn-lg me-2 mb-2"><i class="fa-solid fa-user-plus me-2"></i>Register free</a>
          <a href="<?= e(Url::to('our-products')) ?>" class="btn btn-ghost-light btn-lg mb-2">Shop products</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
