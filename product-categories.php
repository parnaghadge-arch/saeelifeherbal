<?php
/** All product categories with subcategory listing. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Url;

$categories = get_categories(false, 60);
$pageTitle = 'Product Categories';
$pageDescription = 'Browse all Saee Life Herbal product categories - fertilizers, crop protection, pesticides, insecticides and bio stimulants.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Product Categories', 'Find the exact input for your crop, soil type and pest pressure.', ['Product Categories' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4">
      <?php foreach ($categories as $category):
          $subs = get_subcategories((int) $category['id']); ?>
        <div class="col-md-6 col-lg-4 reveal">
          <div class="card-saee h-100">
            <a href="<?= e(Url::to('products.php?category=' . urlencode((string) $category['slug']))) ?>">
              <img src="<?= e(Url::media($category['image'])) ?>" alt="<?= e($category['name']) ?>" class="w-100" style="height:200px;object-fit:cover" loading="lazy">
            </a>
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h2 class="h5 mb-0"><?= e($category['name']) ?></h2>
                <span class="badge bg-soft-primary text-primary"><?= (int) $category['product_count'] ?></span>
              </div>
              <p class="small text-muted"><?= e(truncate_text($category['short_description'], 110)) ?></p>
              <?php if ($subs !== []): ?>
                <div class="d-flex flex-wrap gap-1 mb-3">
                  <?php foreach ($subs as $sub): ?>
                    <a class="badge text-decoration-none bg-soft text-dark" href="<?= e(Url::to('products.php?category=' . urlencode((string) $sub['slug']))) ?>"><?= e($sub['name']) ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <a class="btn btn-outline-primary btn-sm" href="<?= e(Url::to('products.php?category=' . urlencode((string) $category['slug']))) ?>">View products <i class="fa-solid fa-arrow-right ms-1"></i></a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
