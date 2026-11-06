<?php
/** Product listing with search, category filters and sorting. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;

$search = trim((string) Request::query('q', ''));
$categorySlug = trim((string) Request::query('category', ''));
$sort = (string) Request::query('sort', 'newest');
$perPage = 12;

$options = ['search' => $search, 'category' => $categorySlug, 'sort' => $sort];
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

$titleParts = ['Our Products'];
if ($activeCategory) {
    $titleParts[] = $activeCategory['name'];
} elseif ($search !== '') {
    $titleParts[] = 'Search: ' . $search;
}
$pageTitle = implode(' - ', $titleParts);
$pageDescription = 'Shop herbal agricultural products - fertilizers, pesticides, insecticides and crop protection from Saee Life Herbal.';
$pageRobots = $pager->page > 1 ? 'noindex,follow' : 'index,follow';

require INCLUDES_PATH . '/header.php';
echo page_hero($activeCategory ? $activeCategory['name'] : 'Our Products', $activeCategory['short_description'] ?? 'Premium crop nutrition and protection products, delivered to your farm.', ['Our Products' => 'our-products']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4">
      <aside class="col-lg-3">
        <div class="filter-card mb-3">
          <h6><i class="fa-solid fa-magnifying-glass me-2"></i>Search</h6>
          <form method="get" action="<?= e(Url::to('products.php')) ?>">
            <div class="input-group">
              <input type="search" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Product or SKU">
              <?php if ($categorySlug): ?><input type="hidden" name="category" value="<?= e($categorySlug) ?>"><?php endif; ?>
              <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-arrow-right"></i></button>
            </div>
          </form>
        </div>
        <div class="filter-card mb-3">
          <h6>Categories</h6>
          <a class="d-flex justify-content-between py-1 small <?= $categorySlug === '' ? 'fw-bold text-primary' : 'text-dark' ?>" href="<?= e(Url::to('products.php')) ?>">All products <span class="text-muted"><?= count_products([]) ?></span></a>
          <?php foreach ($categories as $category): ?>
            <a class="d-flex justify-content-between py-1 small <?= $categorySlug === $category['slug'] ? 'fw-bold text-primary' : 'text-dark' ?>"
               href="<?= e(Url::to('products.php?category=' . urlencode((string) $category['slug']))) ?>">
              <?= e($category['name']) ?> <span class="text-muted"><?= (int) $category['product_count'] ?></span>
            </a>
            <?php foreach (get_subcategories((int) $category['id']) as $sub): ?>
              <a class="d-block ps-3 py-1 small text-muted <?= $categorySlug === $sub['slug'] ? 'fw-bold text-primary' : '' ?>" href="<?= e(Url::to('products.php?category=' . urlencode((string) $sub['slug']))) ?>">– <?= e($sub['name']) ?></a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
        <div class="filter-card">
          <h6>Need advice?</h6>
          <p class="small text-muted mb-2">Not sure which product fits your crop stage?</p>
          <a href="<?= e(Url::to('contact-us')) ?>" class="btn btn-outline-primary btn-sm w-100">Ask an agronomist</a>
        </div>
      </aside>

      <div class="col-lg-9">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div class="small text-muted"><?= $pager->infoText() ?> products</div>
          <form method="get" class="d-flex align-items-center gap-2">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
            <?php if ($categorySlug !== ''): ?><input type="hidden" name="category" value="<?= e($categorySlug) ?>"><?php endif; ?>
            <label class="small text-muted mb-0">Sort</label>
            <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:170px">
              <?php foreach (['newest' => 'Newest first', 'price_low' => 'Price: low to high', 'price_high' => 'Price: high to low', 'name' => 'Name A-Z', 'popular' => 'Most viewed'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <?php if ($products === []): ?>
          <div class="panel p-5 text-center">
            <i class="fa-solid fa-box-open fs-1 text-muted mb-3"></i>
            <h2 class="h5">No products found</h2>
            <p class="text-muted small">Try a different search term or category.</p>
            <a href="<?= e(Url::to('products.php')) ?>" class="btn btn-primary btn-sm">Reset filters</a>
          </div>
        <?php else: ?>
          <div class="row g-4">
            <?php foreach ($products as $product): ?>
              <div class="col-6 col-xl-4 reveal"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex justify-content-center mt-4"><?= $pager->links(['q' => $search, 'category' => $categorySlug, 'sort' => $sort]) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
