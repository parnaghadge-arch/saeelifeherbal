<?php
/** Agriculture solutions - crop-wise programme guidance. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Url;

$page = get_page_by_slug('agriculture-solutions');
$categories = get_categories(false, 10);
$featured = get_featured_products(4);
$pageTitle = 'Agriculture Solutions';
$pageDescription = 'Crop-wise nutrition and protection programmes from Saee Life Herbal agronomy desk.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Agriculture Solutions', 'Crop-wise programmes that combine nutrition, protection and soil health.', ['Agriculture Solutions' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-7">
        <span class="eyebrow">Programmes</span>
        <h2 class="section-title">Complete crop care, stage by stage</h2>
        <div class="prose"><?= \Saee\Lib\Security::cleanHtml($page['content'] ?? '') ?></div>
        <div class="row g-3 mt-2">
          <?php foreach ([
              ['Cotton', 'Sucking pest management, boll protection and potassium nutrition during boll development.'],
              ['Soybean', 'Seed treatment, micronutrient support and defoliator control at pod fill.'],
              ['Paddy', 'Nitrogen split application, blast prevention and stem borer management.'],
              ['Sugarcane', 'Early shoot borer control, sett treatment and ratoon vigour boosters.'],
              ['Vegetables', 'Foliar nutrition and bio-fungicide rotation for disease-free harvest windows.'],
              ['Horticulture', 'Fruit quality, calcium-boron balance and post-set protection.'],
          ] as [$crop, $desc]): ?>
            <div class="col-md-6">
              <div class="panel h-100 p-3">
                <h3 class="h6 mb-1"><i class="fa-solid fa-seedling text-primary me-2"></i><?= e($crop) ?></h3>
                <p class="small text-muted mb-0"><?= e($desc) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="panel p-4 mb-4">
          <h3 class="h5 mb-3">Our solution categories</h3>
          <ul class="list-unstyled mb-0">
            <?php foreach ($categories as $category): ?>
              <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <a href="<?= e(Url::to('products.php?category=' . urlencode((string) $category['slug']))) ?>"><?= e($category['name']) ?></a>
                <span class="small text-muted"><?= (int) $category['product_count'] ?> items</span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="panel p-4">
          <h3 class="h5 mb-2">Get a free crop advisory</h3>
          <p class="small text-muted">Share your crop, acreage and pest problem. Our agronomy desk responds within one working day.</p>
          <a class="btn btn-primary w-100" href="<?= e(Url::to('contact-us')) ?>"><i class="fa-solid fa-headset me-2"></i>Contact agronomy desk</a>
        </div>
      </div>
    </div>
  </div>
</section>
<section class="section bg-white">
  <div class="container">
    <div class="text-center reveal"><span class="eyebrow">Recommended</span><h2 class="section-title">Products that anchor our programmes</h2></div>
    <div class="row g-4">
      <?php foreach ($featured as $product): ?>
        <div class="col-6 col-lg-3 reveal"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
