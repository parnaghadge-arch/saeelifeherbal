<?php
/** Single blog article. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Url;

$slug = (string) Request::query('slug', '');
$blog = $slug !== '' ? get_blog_by_slug($slug) : null;
if (!$blog) {
    http_response_code(404);
    $pageTitle = 'Article not found';
    require INCLUDES_PATH . '/header.php';
    echo '<section class="section"><div class="container text-center py-5"><h1 class="h2">Article not found</h1><a class="btn btn-primary" href="' . e(Url::to('blogs')) . '">Back to articles</a></div></section>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}
db()->execute('UPDATE blogs SET views = views + 1 WHERE id = :id', ['id' => $blog['id']]);
$related = get_blogs(['limit' => 3]);
$pageTitle = $blog['meta_title'] ?: $blog['title'];
$pageDescription = $blog['meta_description'] ?: truncate_text($blog['excerpt'], 160);
$pageImage = $blog['featured_image'];
$extraScripts = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org', '@type' => 'BlogPosting',
    'headline' => $blog['title'], 'datePublished' => $blog['published_at'],
    'author' => ['@type' => 'Organization', 'name' => $blog['author_name'] ?: setting('site_name')],
    'image' => Url::media($blog['featured_image']),
], JSON_UNESCAPED_SLASHES) . '</script>';
require INCLUDES_PATH . '/header.php';
echo page_hero($blog['title'], '', ['Knowledge Hub' => 'blogs', $blog['category_name'] ?? 'Article' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-8">
        <?php if ($blog['featured_image']): ?>
          <img src="<?= e(Url::media($blog['featured_image'])) ?>" class="img-fluid rounded-4 mb-4 shadow-sm w-100" alt="<?= e($blog['title']) ?>">
        <?php endif; ?>
        <div class="blog-meta mb-3">
          <span><i class="fa-regular fa-calendar me-1"></i><?= format_date($blog['published_at']) ?></span>
          <span><i class="fa-regular fa-user me-1"></i><?= e($blog['author_name'] ?: 'Saee Agronomy Desk') ?></span>
          <span><i class="fa-regular fa-eye me-1"></i><?= (int) $blog['views'] ?> views</span>
        </div>
        <div class="prose"><?= Security::cleanHtml($blog['content']) ?></div>

        <?php $share = share_links(Url::to('blog-details.php?slug=' . $blog['slug']), $blog['title']); ?>
        <div class="d-flex align-items-center gap-2 mt-4 pt-3 border-top">
          <span class="small text-muted">Share this article:</span>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['whatsapp']) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i></a>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['facebook']) ?>" target="_blank" rel="noopener"><i class="fab fa-facebook-f"></i></a>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['x']) ?>" target="_blank" rel="noopener"><i class="fab fa-x-twitter"></i></a>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['linkedin']) ?>" target="_blank" rel="noopener"><i class="fab fa-linkedin-in"></i></a>
        </div>
      </div>
      <aside class="col-lg-4">
        <div class="panel mb-4">
          <div class="panel-head"><h3>More articles</h3></div>
          <div class="panel-body">
            <?php foreach ($related as $item): if ((int) $item['id'] === (int) $blog['id']) { continue; } ?>
              <div class="d-flex gap-2 py-2 border-bottom">
                <img src="<?= e(Url::media($item['featured_image'])) ?>" alt="" width="64" height="48" style="object-fit:cover;border-radius:8px" loading="lazy">
                <div>
                  <a class="small fw-medium d-block text-dark-green" href="<?= e(Url::to('blog-details.php?slug=' . urlencode((string) $item['slug']))) ?>"><?= e(truncate_text($item['title'], 62)) ?></a>
                  <span class="text-muted" style="font-size:.72rem"><?= format_date($item['published_at']) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="cta-band p-4">
          <h3 class="h5 text-white">Talk to an agronomist</h3>
          <p class="small">Get a crop-specific recommendation from our field team.</p>
          <a class="btn btn-accent btn-sm" href="<?= e(Url::to('contact-us')) ?>">Contact us</a>
        </div>
      </aside>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
