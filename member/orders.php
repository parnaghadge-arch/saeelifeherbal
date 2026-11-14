<?php
/**
 * Purchase History (member side).
 *
 * A summary list only: order number, date, status and total. The full line items, address,
 * payment trail and invoice live on the order's own page (member/order-details.php), opened by
 * "View details". Dumping every product of every order onto one screen is what made this page
 * impossible to scan.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\OrderService;
use Saee\Lib\Pager;
use Saee\Lib\Url;

$total = (int) db()->value('SELECT COUNT(*) FROM orders WHERE member_id = :m OR referral_member_id = :m', ['m' => $currentMemberId]);
$pager = new Pager($total, 10);
$orders = db()->select(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity),0) FROM order_items i WHERE i.order_id = o.id) AS total_qty
     FROM orders o WHERE o.member_id = :m OR o.referral_member_id = :m
     ORDER BY o.id DESC' . $pager->limitSql(),
    ['m' => $currentMemberId]
);

// One thumbnail per order for the summary row — the first line item's product image.
$thumbsByOrder = [];
if ($orders !== []) {
    $params = [];
    $named = [];
    foreach ($orders as $i => $o) {
        $key = 'oid' . $i;
        $named[] = ':' . $key;
        $params[$key] = (int) $o['id'];
    }
    $rows = db()->select(
        'SELECT i.order_id, p.featured_image AS product_image
         FROM order_items i LEFT JOIN products p ON p.id = i.product_id
         WHERE i.order_id IN (' . implode(',', $named) . ')
         ORDER BY i.order_id, i.id',
        $params
    );
    foreach ($rows as $row) {
        $oid = (int) $row['order_id'];
        if (!isset($thumbsByOrder[$oid])) {
            $thumbsByOrder[$oid] = (string) ($row['product_image'] ?? '');
        }
    }
}

$summary = db()->first(
    "SELECT COUNT(*) AS order_count,
            COALESCE(SUM(grand_total),0) AS total_spent,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE 0 END),0) AS paid_spent
     FROM orders WHERE member_id = :m OR referral_member_id = :m",
    ['m' => $currentMemberId]
) ?? [];

$pageTitle = 'Purchase History';
$pageHeading = 'Purchase History';
$pageSub = 'Every product you have purchased with your member ID';
$activeNav = 'orders';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total orders</span><strong><?= (int) ($summary['order_count'] ?? 0) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total spent</span><strong><?= money((float) ($summary['total_spent'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Paid orders</span><strong><?= money((float) ($summary['paid_spent'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Member ID</span><strong><?= e((string) $member['member_code']) ?></strong><small>Use this ID when ordering</small></div></div>
</div>

<?php if (!$orders): ?>
<div class="panel p-3 p-md-4 text-center">
  <div class="feature-icon mx-auto mb-3"><i class="fa-solid fa-bag-shopping"></i></div>
  <h2 class="h6">No purchases yet</h2>
  <p class="small text-muted mb-3">Products you buy with your member ID will appear here with full details and invoices.</p>
  <a class="btn btn-primary btn-sm" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-seedling me-1"></i>Browse products</a>
</div>
<?php else: ?>
<div class="panel p-3 p-md-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table purchase-summary">
      <thead>
        <tr>
          <th>Order</th>
          <th data-hide="sm">Placed on</th>
          <th>Status</th>
          <th data-hide="sm">Payment</th>
          <th class="text-end">Total</th>
          <th class="text-end">Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $order):
          $thumb = $thumbsByOrder[(int) $order['id']] ?? '';
          $detailUrl = 'order-details.php?id=' . (int) $order['id'];
      ?>
        <tr>
          <td data-label="Order">
            <div class="d-flex align-items-center gap-2">
              <?php if ($thumb !== ''): ?>
                <img class="purchase-thumb-sm" src="<?= e(Url::media($thumb)) ?>" alt="" loading="lazy" width="40" height="40">
              <?php else: ?>
                <span class="purchase-thumb-sm purchase-thumb-sm--fallback"><i class="fa-solid fa-seedling"></i></span>
              <?php endif; ?>
              <div>
                <a class="fw-semibold text-decoration-none" href="<?= e($detailUrl) ?>"><?= e((string) $order['order_number']) ?></a>
                <div class="small text-muted"><?= (int) $order['item_count'] ?> item(s) · <?= (int) $order['total_qty'] ?> unit(s)</div>
              </div>
            </div>
          </td>
          <td data-label="Placed on" class="small"><?= e(format_date((string) $order['created_at'], 'd M Y, h:i A')) ?></td>
          <td data-label="Status"><?= status_badge(OrderService::normalizeStatus($order['status'])) ?></td>
          <td data-label="Payment" class="small">
            <?= status_badge((string) $order['payment_status']) ?>
            <div class="small text-muted"><?= e(payment_label((string) $order['payment_method'])) ?></div>
          </td>
          <td data-label="Total" class="text-end fw-semibold"><?= money((float) $order['grand_total']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="<?= e($detailUrl) ?>">
              <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>View details
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
  <div class="small text-muted"><?= $pager->infoText() ?></div>
  <?= $pager->links() ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
