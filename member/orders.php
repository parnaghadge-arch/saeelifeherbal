<?php
/** Member purchase history (orders placed with their member ID). */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;

$total = (int) db()->value('SELECT COUNT(*) FROM orders WHERE member_id = :m OR referral_member_id = :m', ['m' => $currentMemberId]);
$pager = new Pager($total, 15);
$orders = db()->select(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
     FROM orders o WHERE o.member_id = :m OR o.referral_member_id = :m
     ORDER BY o.id DESC' . $pager->limitSql(),
    ['m' => $currentMemberId]
);

$pageTitle = 'Purchase History';
$pageHeading = 'Purchase History';
$pageSub = 'Orders linked to your member account';
$activeNav = 'orders';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <?php if (!$orders): ?>
    <p class="small text-muted mb-0">No purchases yet.</p>
    <a class="btn btn-sm btn-primary mt-2" href="<?= e(\Saee\Lib\Url::to('our-products')) ?>">Browse products</a>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Order</th><th>Date</th><th>Items</th><th class="text-end">Total</th><th>Payment</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td data-label="Order" class="small fw-semibold"><?= e((string) $order['order_number']) ?></td>
          <td data-label="Date" class="small"><?= e(format_date((string) $order['created_at'], 'd M Y')) ?></td>
          <td data-label="Items" class="small"><?= (int) $order['item_count'] ?></td>
          <td data-label="Total" class="text-end fw-semibold small"><?= money((float) $order['grand_total']) ?></td>
          <td data-label="Payment"><?= status_badge((string) $order['payment_status']) ?><div class="small text-muted"><?= e(payment_label((string) $order['payment_method'])) ?></div></td>
          <td data-label="Status"><?= status_badge((string) $order['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links() ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
