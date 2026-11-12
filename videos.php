<?php
/** Video library. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Url;

$category = trim((string) Request::query('category', ''));
$videos = get_videos($category !== '' ? $category : null);
$pageTitle = 'Videos';
$pageDescription = 'Watch Saee Life Herbal product demonstrations, farmer stories and training sessions.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Videos', 'Product demonstrations, farmer stories and training sessions.', ['Videos' => '']);
?>
<section class="section">
  <div class="container">
    <?php if ($videos === []): ?>
      <p class="text-center text-muted py-5">No videos published yet.</p>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($videos as $video): ?>
          <div class="col-md-6 col-lg-4 reveal">
            <div class="video-card h-100">
              <div class="video-frame">
                <?php if ($video['source_type'] === 'local' && $video['video_file']): ?>
                  <video controls preload="metadata" poster="<?= e(Url::media($video['thumbnail'] ?? '')) ?>">
                    <source src="<?= e(Url::media($video['video_file'])) ?>" type="video/mp4">
                  </video>
                <?php else: ?>
                  <iframe src="<?= e(video_embed_url($video)) ?>" title="<?= e($video['title']) ?>" loading="lazy" allowfullscreen></iframe>
                <?php endif; ?>
              </div>
              <div class="p-3">
                <h2 class="h6 mb-1"><?= e($video['title']) ?></h2>
                <p class="small text-muted mb-0"><?= e(truncate_text($video['description'], 110)) ?></p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
