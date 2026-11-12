<?php
/** Commission history with drill-down detail. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;

$type = (string) Request::query('type', '');
$status = (string) Request::query('status', '');
$from = (string) Request::query('from', '');
$to = (string) Request::query('to', '');
$where = ['member_id = :m'];
$params = ['m' => $currentMemberId];
if (in_array($type, ['direct', 'grand_direct', 'royalty', 'incentive'], true)) {
    $where[] = 'commission_type = :t';
    $params['t'] = $type;
}
if (in_array($status, ['pending', 'approved', 'available', 'paid', 'reversed', 'cancelled'], true)) {
    $where[] = 'status = :s';
    $params['s'] = $status;
}
if ($from !== '' && strtotime($from)) { $where[] = 'created_at >= :f'; $params['f'] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to)) { $where[] = 'created_at <= :t2'; $params['t2'] = date('Y-m-d 23:59:59', strtotime($to)); }
$whereSql = implode(' AND ', $where);
$total = (int) db()->value("SELECT COUNT(*) FROM commission_transactions WHERE $whereSql", $params);
$pager = new Pager($total, 25);
$rows = db()->select("SELECT * FROM commission_transactions WHERE $whereSql ORDER BY id DESC" . $pager->limitSql(), $params);
$summary = db()->first(
    "SELECT SUM(CASE WHEN status IN ('available','approved','paid') THEN amount ELSE 0 END) AS credited,
            SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending,
            SUM(reversed_amount) AS reversed,
            COUNT(*) AS total_rows
     FROM commission_transactions WHERE member_id = :m",
    ['m' => $currentMemberId]
) ?? [];

$pageTitle = 'Commission History';
$pageHeading = 'Commission History';
$pageSub = 'Transparent, immutable record of every commission';
$activeNav = 'commissions';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-4"><div class="mini-stat"><span>Credited</span><strong><?= money((float) ($summary['credited'] ?? 0)) ?></strong></div></div>
  <div class="col-4"><div class="mini-stat"><span>Pending</span><strong><?= money((float) ($summary['pending'] ?? 0)) ?></strong></div></div>
  <div class="col-4"><div class="mini-stat"><span>Reversed</span><strong><?= money((float) ($summary['reversed'] ?? 0)) ?></strong></div></div>
</div>

<div class="panel p-3 p-md-4">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Type</label>
      <select class="form-select form-select-sm" name="type">
        <option value="">All</option>
        <?php foreach (['direct' => 'Direct', 'grand_direct' => 'Grand direct', 'royalty' => 'Royalty', 'incentive' => 'Incentive'] as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status">
        <option value="">All</option>
        <?php foreach (['pending', 'approved', 'available', 'paid', 'reversed'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
  </form>

  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No commission records for the selected filters.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Date</th><th>Type</th><th>Product / buyer</th><th>Order</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Date" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y')) ?><div class="text-muted"><?= e((string) $row['transaction_code']) ?></div></td>
          <td data-label="Type" class="small"><?= e(ucwords(str_replace('_', ' ', (string) $row['commission_type']))) ?><div class="text-muted">L<?= (int) $row['level'] ?></div></td>
          <td data-label="Product" class="small"><?= e((string) $row['product_name']) ?><div class="text-muted"><?= e((string) $row['buyer_name']) ?></div></td>
          <td data-label="Order" class="small text-muted"><?= e((string) ($row['payment_reference'] ?? '')) ?></td>
          <td data-label="Amount" class="text-end fw-semibold">
            <?= money((float) $row['amount']) ?>
            <?php if ((float) $row['reversed_amount'] > 0): ?><div class="small text-danger">−<?= money((float) $row['reversed_amount']) ?></div><?php endif; ?>
          </td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['type' => $type, 'status' => $status, 'from' => $from, 'to' => $to]) ?>
  </div>
  <?php endif; ?>
</div>

<div class="alert alert-light border mt-3 small mb-0">
  <i class="fa-solid fa-circle-info me-1"></i>
  Commission is calculated on the commissionable value of each product, at the rate configured by the company at the time of sale.
  Reversals (refund, cancellation or return) are recorded as separate ledger entries; earlier records are never deleted or overwritten.
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
