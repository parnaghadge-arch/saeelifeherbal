<?php
/** Audit log viewer. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('audit.view');

use Saee\Lib\Pager;
use Saee\Lib\Request;

$db = db();
$search = trim((string) Request::query('q', ''));
$module = (string) Request::query('module', '');
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (description LIKE :q OR admin_name LIKE :q OR action LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if ($module !== '') {
    $where .= ' AND module = :m';
    $params['m'] = $module;
}
$total = (int) $db->value("SELECT COUNT(*) FROM admin_activity_logs WHERE $where", $params);
$pager = new Pager($total, 40);
$rows = $db->select("SELECT * FROM admin_activity_logs WHERE $where ORDER BY id DESC" . $pager->limitSql(), $params);
$modules = $db->column('SELECT DISTINCT module FROM admin_activity_logs ORDER BY module ASC');

$pageTitle = 'Audit Logs';
$pageHeading = 'Audit Trail';
$pageSub = $total . ' recorded actions';
$activeModule = 'audit-logs';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-12 col-md-6"><label class="form-label small text-muted mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Description, admin, action"></div>
    <div class="col-8 col-md-4"><label class="form-label small text-muted mb-1">Module</label>
      <select class="form-select form-select-sm" name="module"><option value="">All modules</option>
        <?php foreach ($modules as $m): ?><option value="<?= e((string) $m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e((string) $m) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-4 col-md-2"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
  </form>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>When</th><th>Admin</th><th>Module</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="When" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y H:i:s')) ?></td>
          <td data-label="Admin" class="small"><?= e((string) $row['admin_name']) ?></td>
          <td data-label="Module" class="small"><?= e((string) $row['module']) ?></td>
          <td data-label="Action" class="small"><span class="chip"><?= e((string) $row['action']) ?></span></td>
          <td data-label="Description" class="small"><?= e((string) $row['description']) ?>
            <?php if (!empty($row['changes'])): ?>
              <details class="mt-1"><summary class="small text-muted">Changes</summary><pre class="small mb-0" style="white-space:pre-wrap;max-height:160px;overflow:auto"><?= e((string) $row['changes']) ?></pre></details>
            <?php endif; ?>
          </td>
          <td data-label="IP" class="small text-muted"><?= e((string) ($row['ip_address'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="small text-muted">No log entries.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'module' => $module]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
