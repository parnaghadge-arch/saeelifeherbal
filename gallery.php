<?php
/** Photo gallery with category filter and lightbox. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Url;

$category = trim((string) Request::query('category', ''));
$items = get_gallery($category !== '' ? $category : null);
$categories = get_gallery_categories();
$pageTitle = 'Gallery';
$pageDescription = 'Photos from Saee Life Herbal field demonstrations, farmer meets and training programmes.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Gallery', 'Moments from our fields, farmer meets and training programmes.', ['Gallery' => '']);
?>
<section class="section">
  <div class="container">
    <div class="d-flex flex-wrap gap-2 justify-content-center mb-4">
      <a class="btn btn-sm <?= $category === '' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(Url::to('gallery')) ?>">All</a>
      <?php foreach ($categories as $cat): ?>
        <a class="btn btn-sm <?= $category === $cat ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(Url::to('gallery?category=' . urlencode((string) $cat))) ?>"><?= e($cat) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($items === []): ?>
      <p class="text-center text-muted py-5">No photos in this album yet.</p>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($items as $item): ?>
          <div class="col-6 col-md-4 col-lg-3 reveal">
            <a class="gallery-item" href="<?= e(Url::media($item['image'])) ?>" data-lightbox>
              <img src="<?= e(Url::media($item['image'])) ?>" alt="<?= e($item['title'] ?: 'Gallery photo') ?>" loading="lazy">
            </a>
            <p class="small text-muted mt-2 mb-0 text-truncate"><?= e($item['title']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<script>
document.addEventListener('click', function (e) {
  var link = e.target.closest('[data-lightbox]');
  if (!link) return;
  e.preventDefault();
  var overlay = document.createElement('div');
  overlay.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
  overlay.style.cssText += ';background:rgba(10,26,15,.92);z-index:2000;cursor:zoom-out';
  overlay.innerHTML = '<img src="' + link.href + '" style="max-width:92vw;max-height:92vh;border-radius:12px">';
  overlay.addEventListener('click', function () { overlay.remove(); });
  document.body.appendChild(overlay);
});
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
