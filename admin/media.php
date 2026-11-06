<?php
/** Media library: upload, search, copy path, delete. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('cms.media');

use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
if (is_post()) {
    Security::verifyCsrf();
    if (Request::input('action') === 'delete') {
        $media = $db->first('SELECT * FROM media_library WHERE id = :id', ['id' => Request::int('id')]);
        if ($media) {
            Uploader::delete((string) $media['file_path']);
            $db->delete('media_library', 'id = :id', ['id' => (int) $media['id']]);
            Audit::log('delete', 'cms.media', 'Deleted media #' . $media['id'], 'media_library', (int) $media['id']);
            Session::success('Media deleted.');
        }
    } else {
        $folder = slugify((string) Request::input('folder', 'media')) ?: 'media';
        $saved = 0;
        $errors = [];
        if (!empty($_FILES['files']['name'][0])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < min($count, 10); $i++) {
                $file = [
                    'name' => $_FILES['files']['name'][$i], 'type' => $_FILES['files']['type'][$i],
                    'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => $_FILES['files']['error'][$i],
                    'size' => $_FILES['files']['size'][$i],
                ];
                $allow = array_merge(Uploader::IMAGE, Uploader::DOCUMENT, ['video/mp4' => 'mp4']);
                $up = Uploader::store($file, $folder, $allow);
                if ($up['ok']) {
                    $db->insert('media_library', [
                        'file_name' => mb_substr((string) $file['name'], 0, 190),
                        'file_path' => $up['path'],
                        'file_type' => strtok((string) $up['mime'], '/') ?: 'file',
                        'mime_type' => $up['mime'],
                        'file_size' => $up['size'],
                        'width' => $up['width'],
                        'height' => $up['height'],
                        'folder' => $folder,
                        'uploaded_by' => (int) $adminUser['id'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $saved++;
                } else {
                    $errors[] = $up['message'];
                }
            }
        }
        Audit::log('upload', 'cms.media', 'Uploaded ' . $saved . ' media file(s)', 'media_library');
        $saved > 0 ? Session::success($saved . ' file(s) uploaded.') : Session::error($errors ? implode(' ', array_slice($errors, 0, 3)) : 'No file selected.');
    }
    Request::redirect('media.php' . (Request::query('q') ? '?q=' . urlencode((string) Request::query('q')) : ''));
}

$search = trim((string) Request::query('q', ''));
$where = '1=1';
$params = [];
if ($search !== '') {
    $where = '(file_name LIKE :q OR folder LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$total = (int) $db->value("SELECT COUNT(*) FROM media_library WHERE $where", $params);
$pager = new Pager($total, 24);
$rows = $db->select("SELECT * FROM media_library WHERE $where ORDER BY id DESC" . $pager->limitSql(), $params);
$folders = $db->column('SELECT DISTINCT folder FROM media_library ORDER BY folder ASC');

$pageTitle = 'Media Library';
$pageHeading = 'Media Library';
$pageSub = $total . ' files';
$activeModule = 'media';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Upload</h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label small">Folder</label><input name="folder" class="form-control form-control-sm" list="folders" value="media"><datalist id="folders"><?php foreach ($folders as $folder): ?><option value="<?= e((string) $folder) ?>"><?php endforeach; ?></datalist></div>
        <div class="mb-2"><label class="form-label small">Files (max 10)</label><input type="file" name="files[]" class="form-control form-control-sm" multiple required></div>
        <button class="btn btn-primary w-100">Upload</button>
      </form>
      <div class="form-text mt-2">Images, PDFs and MP4 up to <?= MAX_UPLOAD_MB ?> MB each. MIME type is verified server side.</div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <form class="d-flex gap-2 mb-3" method="get">
        <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search file or folder">
        <button class="btn btn-sm btn-dark">Search</button>
      </form>
      <div class="row g-3">
        <?php foreach ($rows as $row): ?>
          <div class="col-6 col-md-4">
            <div class="border rounded p-2 h-100">
              <?php if (str_starts_with((string) $row['mime_type'], 'image/')): ?>
                <img src="<?= e(Url::media((string) $row['file_path'])) ?>" class="w-100 mb-2 rounded" style="height:100px;object-fit:cover" alt="<?= e((string) $row['alt_text']) ?>">
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center bg-light rounded mb-2" style="height:100px"><i class="fa-solid fa-file fa-2x text-muted"></i></div>
              <?php endif; ?>
              <div class="small text-truncate" title="<?= e((string) $row['file_name']) ?>"><?= e((string) $row['file_name']) ?></div>
              <div class="small text-muted"><?= e((string) $row['folder']) ?> · <?= number_format((int) $row['file_size'] / 1024, 0) ?> KB</div>
              <div class="d-flex gap-1 mt-1">
                <button class="btn btn-sm btn-outline-secondary flex-grow-1" type="button" data-copy-text="<?= e((string) $row['file_path']) ?>">Copy path</button>
                <form method="post" data-confirm="Delete this file?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-regular fa-trash-can"></i></button></form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><p class="small text-muted mb-0">No media files yet.</p><?php endif; ?>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="small text-muted"><?= $pager->infoText() ?></div>
        <?= $pager->links(['q' => $search]) ?>
      </div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    navigator.clipboard.writeText(btn.dataset.copyText).then(function () {
      var original = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = original; }, 1400);
    });
  });
});
</script>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
