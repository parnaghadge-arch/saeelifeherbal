<?php
/** Per-product videos. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('products.manage');

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
$productId = Request::int('id');
$product = $productId ? $db->first('SELECT * FROM products WHERE id = :id', ['id' => $productId]) : null;
if (!$product) {
    Session::error('Product not found.');
    Request::redirect('products.php');
}
if (is_post()) {
    Security::verifyCsrf();
    if (Request::input('action') === 'delete') {
        $video = $db->first('SELECT * FROM product_videos WHERE id = :id AND product_id = :p', ['id' => Request::int('video_id'), 'p' => $productId]);
        if ($video) {
            $db->delete('product_videos', 'id = :id', ['id' => (int) $video['id']]);
            Audit::log('delete', 'products', 'Removed product video', 'product_videos', (int) $video['id']);
            Session::success('Video removed.');
        }
        Request::redirect('product-media.php?id=' . $productId);
    }
    $data = [
        'product_id' => $productId,
        'title' => (string) Request::input('title', ''),
        'video_url' => (string) Request::input('video_url', ''),
        'source_type' => Request::input('source_type') === 'local' ? 'local' : 'embed',
        'sort_order' => Request::int('sort_order'),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if (!empty($_FILES['video_file']['name'])) {
        $up = Uploader::store($_FILES['video_file'], 'videos', ['video/mp4' => 'mp4', 'video/webm' => 'webm'], true);
        if ($up['ok']) {
            $data['video_file'] = $up['path'];
            $data['source_type'] = 'local';
        } else {
            Session::error($up['message']);
            Request::redirect('product-media.php?id=' . $productId);
        }
    }
    $newId = $db->insert('product_videos', $data);
    Audit::log('create', 'products', 'Added product video', 'product_videos', $newId);
    Session::success('Video added.');
    Request::redirect('product-media.php?id=' . $productId);
}
$videos = $db->select('SELECT * FROM product_videos WHERE product_id = :p ORDER BY sort_order ASC, id DESC', ['p' => $productId]);
$pageTitle = 'Product media';
$pageHeading = 'Videos · ' . $product['name'];
$pageSub = 'Attach demo / usage videos to this product';
$activeModule = 'products';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="products.php?id=' . (int) $productId . '">Back to product</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Add video</h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Title</label><input name="title" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Embed URL (YouTube)</label><input name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=…"></div>
        <div class="mb-3"><label class="form-label">Or upload MP4</label><input type="file" name="video_file" class="form-control" accept="video/mp4,video/webm"></div>
        <div class="mb-3"><label class="form-label">Order</label><input type="number" name="sort_order" class="form-control" value="0"></div>
        <button class="btn btn-primary w-100">Add video</button>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Videos (<?= count($videos) ?>)</h2>
      <?php if (!$videos): ?><p class="small text-muted mb-0">No videos yet.</p><?php endif; ?>
      <div class="list-stack">
        <?php foreach ($videos as $video): ?>
          <div class="list-item">
            <div class="flex-grow-1 min-w-0">
              <div class="small fw-semibold text-truncate"><?= e((string) ($video['title'] ?: 'Untitled')) ?></div>
              <div class="small text-muted text-truncate"><?= e((string) ($video['video_url'] ?: $video['video_file'])) ?></div>
            </div>
            <form method="post" data-confirm="Remove video?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="video_id" value="<?= (int) $video['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fa-regular fa-trash-can"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
