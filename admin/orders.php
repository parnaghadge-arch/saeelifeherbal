<?php
/** Order list with filters + CSV export. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('orders.view');

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Response;

$db = db();
$search = trim((string) Request::query('q', ''));
$status = (string) Request::query('status', '');
$payment = (string) Request::query('payment', '');
$from = (string) Request::query('from', '');
$to = (string) Request::query('to', '');
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(o.order_number LIKE :q OR o.customer_name LIKE :q OR o.customer_mobile LIKE :q OR o.customer_email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (array_key_exists($status, ORDER_STATUSES)) { $where[] = 'o.status = :st'; $params['st'] = $status; }
if (array_key_exists($payment, ORDER_PAYMENT_STATUSES)) { $where[] = 'o.payment_status = :ps'; $params['ps'] = $payment; }
if ($from !== '' && strtotime($from)) { $where[] = 'o.created_at >= :from'; $params['from'] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to)) { $where[] = 'o.created_at <= :to'; $params['to'] = date('Y-m-d 23:59:59', strtotime($to)); }
$whereSql = implode(' AND ', $where);

if (Request::query('export') === 'csv') {
    $rows = $db->select("SELECT o.order_number, o.customer_name, o.customer_mobile, o.grand_total, o.payment_method, o.payment_status, o.status, o.created_at
                         FROM orders o WHERE $whereSql ORDER BY o.id DESC LIMIT 5000", $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['order_number'], $r['customer_name'], $r['customer_mobile'], $r['grand_total'], $r['payment_method'], $r['payment_status'], $r['status'], $r['created_at']];
    }
    Response::csv('orders-' . date('Ymd') . '.csv', $out, ['Order', 'Customer', 'Mobile', 'Total', 'Payment method', 'Payment status', 'Status', 'Created']);
}

$total = (int) $db->value("SELECT COUNT(*) FROM orders o WHERE $whereSql", $params);
$totals = $db->first("SELECT COALESCE(SUM(o.grand_total),0) AS amount, COUNT(*) AS c FROM orders o WHERE $whereSql", $params) ?? [];
$pager = new Pager($total, 25);
$rows = $db->select("SELECT o.*, m.member_code AS member_code FROM orders o LEFT JOIN members m ON m.id = o.member_id
                     WHERE $whereSql ORDER BY o.id DESC" . $pager->limitSql(), $params);

$pageTitle = 'Orders';
$pageHeading = 'Orders';
$pageSub = $total . ' order(s) · ' . money((float) ($totals['amount'] ?? 0)) . ' total';
$activeModule = 'orders';
$pageActions = '<a class="btn btn-sm btn-primary" href="purchase.php"><i class="fa-solid fa-cart-plus me-1"></i>Record purchase</a>'
    . '<a class="btn btn-sm btn-outline-secondary ms-2" href="?export=csv"><i class="fa-solid fa-file-csv me-1"></i>Export</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-12 col-md-3"><label class="form-label small text-muted mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Order no / customer / mobile"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (ORDER_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Payment</label>
      <select class="form-select form-select-sm" name="payment"><option value="">All</option>
        <?php foreach (ORDER_PAYMENT_STATUSES as $s => $sLabel): ?><option value="<?= e($s) ?>" <?= $payment === $s ? 'selected' : '' ?>><?= e($sLabel) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-1"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Payment</th><th>Status</th><th>Commission</th><th class="text-end">View</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Order"><div class="small fw-semibold"><?= e((string) $row['order_number']) ?></div><div class="small text-muted"><?= e(format_date((string) $row['created_at'], 'd M Y H:i')) ?></div></td>
          <td data-label="Customer"><div class="small"><?= e((string) $row['customer_name']) ?></div><div class="small text-muted"><?= e((string) $row['customer_mobile']) ?><?= $row['member_code'] ? ' · ' . e((string) $row['member_code']) : '' ?></div></td>
          <td data-label="Amount" class="small fw-semibold"><?= money((float) $row['grand_total']) ?></td>
          <td data-label="Payment"><?= status_badge((string) $row['payment_status']) ?><div class="small text-muted"><?= e(payment_label((string) $row['payment_method'])) ?></div></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td data-label="Commission" class="small"><?= e(ucfirst((string) ($row['commission_status'] ?? 'pending'))) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light-green" href="order-details.php?id=<?= (int) $row['id'] ?>"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="small text-muted">No orders match the filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'status' => $status, 'payment' => $payment, 'from' => $from, 'to' => $to]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
