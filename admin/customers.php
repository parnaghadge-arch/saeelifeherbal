<?php
/** Storefront customer accounts (site shoppers, distinct from MLM members). */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('members.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Response;

$db = db();
if (is_post() && AdminAuth::can('members.manage')) {
    Security::verifyCsrf();
    $id = Request::int('id');
    $customer = $db->first('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    if ($customer) {
        $db->update('customers', [
            'status' => $customer['status'] === 'active' ? 'inactive' : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        \Saee\Lib\Audit::log('status', 'members', 'Toggled customer status #' . $id, 'customers', $id);
        \Saee\Lib\Session::success('Customer status updated.');
    }
    Request::redirect('customers.php');
}

$search = trim((string) Request::query('q', ''));
$where = '1=1';
$params = [];
if ($search !== '') {
    $where = '(c.name LIKE :q OR c.mobile LIKE :q OR c.email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (Request::query('export') === 'csv') {
    $all = $db->select("SELECT c.name, c.mobile, c.email, c.status, c.created_at FROM customers c WHERE $where ORDER BY c.id DESC LIMIT 5000", $params);
    $out = array_map(static fn ($r) => [$r['name'], $r['mobile'], $r['email'], $r['status'], $r['created_at']], $all);
    Response::csv('customers-' . date('Ymd') . '.csv', $out, ['Name', 'Mobile', 'Email', 'Status', 'Registered']);
}
$total = (int) $db->value("SELECT COUNT(*) FROM customers c WHERE $where", $params);
$pager = new Pager($total, 25);
$rows = $db->select(
    "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
            (SELECT COALESCE(SUM(o.grand_total),0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status = 'paid') AS spent
     FROM customers c WHERE $where ORDER BY c.id DESC" . $pager->limitSql(),
    $params
);
$pageTitle = 'Customers';
$pageHeading = 'Customers';
$pageSub = $total . ' storefront customer(s)';
$activeModule = 'customers';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="?export=csv">Export CSV</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <form class="d-flex gap-2 mb-3" method="get">
    <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search name / mobile / email">
    <button class="btn btn-sm btn-dark">Search</button>
  </form>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Customer</th><th>Registered</th><th class="text-end">Orders</th><th class="text-end">Spent</th><th>Status</th><th class="text-end"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Customer"><div class="small fw-semibold"><?= e((string) $row['name']) ?></div><div class="small text-muted"><?= e((string) $row['mobile']) ?> · <?= e((string) ($row['email'] ?? '')) ?></div></td>
          <td data-label="Registered" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y')) ?></td>
          <td data-label="Orders" class="text-end small"><?= (int) $row['order_count'] ?></td>
          <td data-label="Spent" class="text-end small fw-semibold"><?= money((float) $row['spent']) ?></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td class="text-end">
            <?php if (AdminAuth::can('members.manage')): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Toggle status"><i class="fa-solid fa-power-off"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="small text-muted">No customers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
