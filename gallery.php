<?php
/**
 * Media gallery — photos and videos in one combined, filterable grid.
 *
 * The page owns the "Gallery" header menu item, so visitors see both media types in a
 * single place. `videos.php` still exists for direct links but is no longer part of the menu.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Url;

$filter = trim((string) Request::query('filter', 'all'));   // all | photos | videos
$category = trim((string) Request::query('category', ''));

$photos = in_array($filter, ['all', 'photos'], true) ? get_gallery($category !== '' ? $category : null) : [];
$videos = in_array($filter, ['all', 'videos'], true) ? get_videos($category !== '' ? $category : null) : [];

// Photos first, then videos, so the same album reads consistently.
$media = [];
foreach ($photos as $photo) {
    $media[] = [
        'kind' => 'photo',
        'title' => (string) ($photo['title'] ?? ''),
        'category' => (string) ($photo['category'] ?? ''),
        'src' => Url::media($photo['image']),
        'thumb' => Url::media($photo['image']),
    ];
}
foreach ($videos as $video) {
    $isLocal = $video['source_type'] === 'local' && !empty($video['video_file']);
    $media[] = [
        'kind' => 'video',
        'title' => (string) ($video['title'] ?? ''),
        'category' => (string) ($video['category'] ?? ''),
        'src' => $isLocal ? Url::media($video['video_file']) : video_embed_url($video),
        'thumb' => Url::media($video['thumbnail'] ?? ''),
        'is_local' => $isLocal,
    ];
}

$categories = get_gallery_categories();
$pageTitle = 'Gallery';
$pageDescription = 'Photos and videos from Saee Life Herbal field demonstrations, farmer meets and training programmes.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Gallery', 'Photos and videos from our fields, farmer meets and training programmes.', ['Gallery' => '']);
?>
<section class="section">
  <div class="container">
    <div class="media-filters mb-4">
      <?php
      $tabs = ['all' => ['All media', count($photos) + count($videos)], 'photos' => ['Photos', count($photos)], 'videos' => ['Videos', count($videos)]];
      if ($filter === 'photos') { $tabs['all'][1] = count($photos); }
      foreach ($tabs as $key => [$label, $count]): ?>
        <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(Url::to('gallery?filter=' . $key . ($category !== '' ? '&category=' . urlencode($category) : ''))) ?>">
          <i class="fa-solid <?= $key === 'videos' ? 'fa-video' : ($key === 'photos' ? 'fa-image' : 'fa-layer-group') ?> me-1" aria-hidden="true"></i>
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
      <?php if ($categories !== []): ?>
        <span class="media-filter-divider" aria-hidden="true"></span>
        <a class="btn btn-sm <?= $category === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="<?= e(Url::to('gallery?filter=' . $filter)) ?>">Every album</a>
        <?php foreach ($categories as $cat): ?>
          <a class="btn btn-sm <?= $category === $cat ? 'btn-primary' : 'btn-outline-secondary' ?>"
             href="<?= e(Url::to('gallery?filter=' . $filter . '&category=' . urlencode((string) $cat))) ?>"><?= e($cat) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($media === []): ?>
      <p class="text-center text-muted py-5">No media in this album yet.</p>
    <?php else: ?>
      <div class="media-grid">
        <?php foreach ($media as $item): ?>
          <?php if ($item['kind'] === 'photo'): ?>
            <figure class="media-tile reveal">
              <a class="media-tile-link" href="<?= e($item['src']) ?>" data-lightbox data-title="<?= e($item['title']) ?>">
                <img src="<?= e($item['thumb']) ?>" alt="<?= e($item['title'] ?: 'Gallery photo') ?>" loading="lazy">
                <span class="media-kind" aria-hidden="true"><i class="fa-solid fa-expand"></i></span>
              </a>
              <?php if ($item['title'] !== ''): ?><figcaption><?= e($item['title']) ?></figcaption><?php endif; ?>
            </figure>
          <?php else: ?>
            <figure class="media-tile reveal">
              <div class="media-tile-link is-video">
                <?php if ($item['is_local']): ?>
                  <video controls preload="metadata" poster="<?= e($item['thumb']) ?>">
                    <source src="<?= e($item['src']) ?>" type="video/mp4">
                  </video>
                <?php else: ?>
                  <div class="media-embed" data-embed="<?= e($item['src']) ?>">
                    <img src="<?= e($item['thumb']) ?>" alt="<?= e($item['title'] ?: 'Video') ?>" loading="lazy">
                    <button type="button" class="media-play" data-play aria-label="Play video: <?= e($item['title']) ?>">
                      <i class="fa-solid fa-play" aria-hidden="true"></i>
                    </button>
                  </div>
                <?php endif; ?>
                <span class="media-kind is-video-kind" aria-hidden="true"><i class="fa-solid fa-video"></i></span>
              </div>
              <?php if ($item['title'] !== ''): ?><figcaption><?= e($item['title']) ?></figcaption><?php endif; ?>
            </figure>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<script>
document.addEventListener('click', function (e) {
  // photo lightbox
  var link = e.target.closest('[data-lightbox]');
  if (link) {
    e.preventDefault();
    var overlay = document.createElement('div');
    overlay.className = 'media-lightbox';
    var cap = link.getAttribute('data-title') || '';
    overlay.innerHTML = '<img src="' + link.getAttribute('href') + '" alt="">'
      + (cap ? '<p class="media-lightbox-caption">' + cap.replace(/[<>&]/g, '') + '</p>' : '');
    overlay.addEventListener('click', function () { overlay.remove(); });
    document.addEventListener('keydown', function esc(ev) {
      if (ev.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', esc); }
    });
    document.body.appendChild(overlay);
    return;
  }
  // inline video: swap the poster for the real player on demand
  var play = e.target.closest('[data-play]');
  if (play) {
    var wrap = play.closest('[data-embed]');
    if (!wrap) { return; }
    var frame = document.createElement('iframe');
    frame.src = wrap.getAttribute('data-embed') + (wrap.getAttribute('data-embed').indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
    frame.title = play.getAttribute('aria-label') || 'Video';
    frame.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
    frame.setAttribute('allowfullscreen', '');
    frame.setAttribute('loading', 'lazy');
    wrap.innerHTML = '';
    wrap.appendChild(frame);
  }
});
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
