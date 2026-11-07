<?php
/** Product listing with a category dropdown, sorting and pagination. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;

$categorySlug = trim((string) Request::query('category', ''));
$sort = (string) Request::query('sort', 'newest');

// Four products per page: a 4-up row on desktop, 2-up on phones, so a page is exactly one
// or two tidy rows instead of a long scroll.
$perPage = 4;

$options = ['category' => $categorySlug, 'sort' => $sort];
$total = count_products($options);
$pager = new Pager($total, $perPage);
$options['limit'] = $perPage;
$options['offset'] = $pager->offset;
$products = get_products($options);

$categories = get_categories(false, 40);
$activeCategory = null;
foreach ($categories as $c) {
    if ($c['slug'] === $categorySlug) {
        $activeCategory = $c;
    }
}
$allProductsCount = count_products([]);

$titleParts = ['Our Products'];
if ($activeCategory) {
    $titleParts[] = $activeCategory['name'];
}
$pageTitle = implode(' - ', $titleParts);
$pageDescription = 'Shop herbal agricultural products - fertilizers, pesticides, insecticides and crop protection from Saee Life Herbal.';
$pageRobots = $pager->page > 1 ? 'noindex,follow' : 'index,follow';

require INCLUDES_PATH . '/header.php';
echo page_hero($activeCategory ? $activeCategory['name'] : 'Our Products', $activeCategory['short_description'] ?? 'Premium crop nutrition and protection products, delivered to your farm.', ['Our Products' => 'our-products']);
?>
<section class="section">
  <div class="container">
    <?php // One full-width column: a categories + sort toolbar on top (side by side at every
          // width), then the products grid, then the agronomy help banner. ?>
    <div class="catalog-toolbar">
      <div class="catalog-toolbar-field">
        <label class="catalog-toolbar-label" for="catalogCategory">
          <i class="fa-solid fa-layer-group" aria-hidden="true"></i><span>Category</span>
        </label>
        <form method="get" action="<?= e(Url::to('products.php')) ?>" class="catalog-toolbar-form">
          <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
          <select class="form-select form-select-sm" id="catalogCategory" name="category" onchange="this.form.submit()">
            <option value="" <?= $categorySlug === '' ? 'selected' : '' ?>>All products (<?= $allProductsCount ?>)</option>
            <?php foreach ($categories as $category): ?>
              <?php $children = get_subcategories((int) $category['id']); ?>
              <option value="<?= e((string) $category['slug']) ?>" <?= $categorySlug === $category['slug'] ? 'selected' : '' ?>>
                <?= e((string) $category['name']) ?> (<?= (int) $category['product_count'] ?>)
              </option>
              <?php foreach ($children as $sub): ?>
                <option value="<?= e((string) $sub['slug']) ?>" <?= $categorySlug === $sub['slug'] ? 'selected' : '' ?>>
                  &nbsp;&nbsp;&mdash; <?= e((string) $sub['name']) ?>
                </option>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-primary btn-sm" type="submit">Apply</button></noscript>
        </form>
      </div>

      <div class="catalog-toolbar-field">
        <label class="catalog-toolbar-label" for="catalogSort">
          <i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i><span>Sort by</span>
        </label>
        <form method="get" action="<?= e(Url::to('products.php')) ?>" class="catalog-toolbar-form">
          <?php if ($categorySlug !== ''): ?><input type="hidden" name="category" value="<?= e($categorySlug) ?>"><?php endif; ?>
          <select id="catalogSort" name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach (['newest' => 'Newest first', 'price_low' => 'Price: low to high', 'price_high' => 'Price: high to low', 'name' => 'Name A-Z', 'popular' => 'Most viewed'] as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-primary btn-sm" type="submit">Apply</button></noscript>
        </form>
      </div>
    </div>

    <div class="catalog-summary">
      <span class="small text-muted"><?= $pager->infoText() ?> products</span>
      <?php if ($categorySlug !== ''): ?>
        <a class="catalog-clear small" href="<?= e(Url::to('products.php' . ($sort !== '' ? '?sort=' . urlencode($sort) : ''))) ?>">
          <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Clear category
        </a>
      <?php endif; ?>
    </div>

    <?php if ($products === []): ?>
      <div class="panel p-5 text-center">
        <i class="fa-solid fa-box-open fs-1 text-muted mb-3"></i>
        <h2 class="h5">No products found</h2>
        <p class="text-muted small">No products are listed in this category yet.</p>
        <a href="<?= e(Url::to('products.php')) ?>" class="btn btn-primary btn-sm">Show all products</a>
      </div>
    <?php else: ?>
      <?php // Full page width: 4 across on desktop, 2 on phones. ?>
      <div class="row g-3 g-lg-4 catalog-grid">
        <?php foreach ($products as $product): ?>
          <div class="col-6 col-lg-3 reveal"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex justify-content-center mt-4"><?= $pager->links(['category' => $categorySlug, 'sort' => $sort]) ?></div>
    <?php endif; ?>

    <?php // Agronomy help sits after the products, not above them. ?>
    <div class="advice-banner mt-4">
      <div class="advice-icon" aria-hidden="true"><i class="fa-solid fa-headset"></i></div>
      <div class="advice-copy">
        <h3 class="h6 mb-1">Need advice?</h3>
        <p class="small mb-0">Not sure which product fits your crop stage? Our agronomy team helps you pick the right input, dose and spray schedule for your soil, crop and district.</p>
      </div>
      <a class="btn btn-primary btn-sm" href="<?= e(Url::to('contact-us')) ?>">
        <i class="fa-solid fa-comments me-1" aria-hidden="true"></i>Ask an agronomist
      </a>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
