<?php
/** Blog / agriculture knowledge hub listing. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;

$category = trim((string) Request::query('category', ''));
$search = trim((string) Request::query('q', ''));
$total = count_blogs(['category' => $category, 'search' => $search]);
$pager = new Pager($total, 9);
$blogs = get_blogs(['category' => $category, 'search' => $search, 'limit' => 9, 'offset' => $pager->offset]);
$categories = get_blog_categories();
$pageTitle = 'Agriculture Knowledge Hub';
$pageDescription = 'Crop advisory, soil health and agri-business articles from the Saee Life Herbal agronomy desk.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Agriculture Knowledge Hub', 'Practical guidance for better crop decisions.', ['Knowledge Hub' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <form method="get" class="mb-4">
          <div class="input-group">
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search articles…">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
          </div>
        </form>
        <?php if ($blogs === []): ?>
          <p class="text-muted py-4">No articles found.</p>
        <?php else: ?>
          <div class="row g-4">
            <?php foreach ($blogs as $blog): ?>
              <div class="col-md-6 reveal">
                <article class="blog-card">
                  <a href="<?= e(Url::to('blog-details.php?slug=' . urlencode((string) $blog['slug']))) ?>" class="blog-thumb d-block">
                    <img src="<?= e(Url::media($blog['featured_image'])) ?>" alt="<?= e($blog['title']) ?>" loading="lazy">
                  </a>
                  <div class="p-3 d-flex flex-column flex-grow-1">
                    <div class="blog-meta">
                      <span><i class="fa-regular fa-calendar me-1"></i><?= format_date($blog['published_at']) ?></span>
                      <?php if ($blog['category_name']): ?><span><i class="fa-solid fa-tag me-1"></i><?= e($blog['category_name']) ?></span><?php endif; ?>
                    </div>
                    <h2 class="h6"><a class="text-dark-green" href="<?= e(Url::to('blog-details.php?slug=' . urlencode((string) $blog['slug']))) ?>"><?= e($blog['title']) ?></a></h2>
                    <p class="small text-muted text-truncate-3 mb-0"><?= e(truncate_text($blog['excerpt'], 140)) ?></p>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex justify-content-center mt-4"><?= $pager->links(['q' => $search, 'category' => $category]) ?></div>
        <?php endif; ?>
      </div>
      <aside class="col-lg-4">
        <div class="filter-card mb-3">
          <h6>Categories</h6>
          <a class="d-block py-1 small <?= $category === '' ? 'fw-bold text-primary' : 'text-dark' ?>" href="<?= e(Url::to('blogs')) ?>">All articles</a>
          <?php foreach ($categories as $cat): ?>
            <a class="d-block py-1 small <?= $category === $cat['slug'] ? 'fw-bold text-primary' : 'text-dark' ?>" href="<?= e(Url::to('blogs?category=' . urlencode((string) $cat['slug']))) ?>"><?= e($cat['name']) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="panel p-4">
          <h3 class="h6">Need a crop advisory?</h3>
          <p class="small text-muted mb-2">Our agronomy desk answers within one working day.</p>
          <a class="btn btn-primary btn-sm w-100" href="<?= e(Url::to('contact-us')) ?>">Contact us</a>
        </div>
      </aside>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
