<?php
/** Database backups (create, download, list). */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('backups.manage');

use Saee\Lib\Audit;
use Saee\Lib\BackupService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$service = new BackupService($db);

if (is_post()) {
    Security::verifyCsrf();
    try {
        $file = $service->create((string) Request::input('note', 'Manual backup'));
        Audit::log('backup', 'system', 'Created database backup ' . $file, 'database_backups');
        Session::success('Backup created: ' . $file);
    } catch (Throwable $e) {
        Session::error('Backup failed: ' . $e->getMessage());
    }
    Request::redirect('backups.php');
}

if (Request::query('download')) {
    $name = basename((string) Request::query('download'));
    $path = STORAGE_PATH . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $name;
    if (is_file($path) && str_ends_with($name, '.sql')) {
        Audit::log('download', 'system', 'Downloaded backup ' . $name, 'database_backups');
        header('Content-Type: application/sql');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
    Session::error('Backup file not found.');
    Request::redirect('backups.php');
}

$backups = $service->listBackups();
$pageTitle = 'Backups';
$pageHeading = 'Database Backups';
$pageSub = count($backups) . ' backup file(s) in storage/backups';
$activeModule = 'backups';
$pageActions = '<form method="post" class="d-inline">' . csrf_field() . '<button class="btn btn-sm btn-primary"><i class="fa-solid fa-database me-1"></i>Create backup now</button></form>';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>File</th><th>Size</th><th>Created</th><th class="text-end">Download</th></tr></thead>
      <tbody>
      <?php foreach ($backups as $backup): ?>
        <tr>
          <td data-label="File" class="small"><?= e((string) $backup['filename']) ?></td>
          <td data-label="Size" class="small"><?= number_format((int) $backup['file_size'] / 1024, 1) ?> KB</td>
          <td data-label="Created" class="small"><?= e(format_date((string) $backup['created_at'], 'd M Y H:i')) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?download=<?= urlencode((string) $backup['filename']) ?>"><i class="fa-solid fa-download"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$backups): ?><tr><td colspan="4" class="small text-muted">No backups yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="alert alert-light border small mt-3 mb-0">
    Backups are stored outside the web root in <code>storage/backups</code>. Schedule <code>php tools/console.php backups:run</code> daily with cron and copy archives off-site.
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
