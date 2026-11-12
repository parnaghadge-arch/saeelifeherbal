<?php
/**
 * Purchase History (member side): every product bought with the member ID,
 * with line items, totals, payment/status and invoice access.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

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

// Load all line items for the visible orders in one query (no N+1).
$itemsByOrder = [];
if ($orders !== []) {
    $ids = array_map(static fn (array $o): int => (int) $o['id'], $orders);
    // Database::select binds named params; build named placeholders for the IN list.
    $params = [];
    $named = [];
    foreach ($ids as $i => $oid) {
        $key = 'oid' . $i;
        $named[] = ':' . $key;
        $params[$key] = $oid;
    }
    $items = db()->select(
        'SELECT i.*, p.featured_image AS product_image FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id IN (' . implode(',', $named) . ') ORDER BY i.order_id, i.id',
        $params
    );
    foreach ($items as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
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
<?php foreach ($orders as $order):
    $orderItems = $itemsByOrder[(int) $order['id']] ?? [];
    $shipping = json_decode((string) ($order['shipping_address'] ?? ''), true) ?: [];
?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <div class="fw-semibold"><?= e((string) $order['order_number']) ?></div>
      <div class="small text-muted"><?= e(format_date((string) $order['created_at'], 'd M Y, h:i A')) ?> · <?= (int) $order['item_count'] ?> item(s) · <?= (int) $order['total_qty'] ?> unit(s)</div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?= status_badge((string) $order['status']) ?>
      <?= status_badge((string) $order['payment_status']) ?>
    </div>
  </div>

  <div class="purchase-items">
    <?php foreach ($orderItems as $item): ?>
      <div class="purchase-item">
        <?php if (!empty($item['product_image'])): ?>
          <img src="<?= e(Url::media((string) $item['product_image'])) ?>" alt="<?= e((string) $item['product_name']) ?>" loading="lazy">
        <?php else: ?>
          <span class="purchase-thumb-fallback"><i class="fa-solid fa-seedling"></i></span>
        <?php endif; ?>
        <div class="purchase-item-info">
          <div class="fw-semibold small"><?= e((string) $item['product_name']) ?></div>
          <div class="small text-muted">
            <?= e((string) ($item['product_sku'] ?? '')) ?>
            <?php if (!empty($item['pack_size'])): ?> · <?= e((string) $item['pack_size']) ?><?php endif; ?>
          </div>
          <div class="small text-muted"><?= money((float) $item['unit_price']) ?> × <?= (int) $item['quantity'] ?></div>
        </div>
        <div class="purchase-item-total"><?= money((float) $item['line_total']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-md-6">
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><span><?= money((float) $order['subtotal']) ?></span></div>
        <?php if ((float) $order['discount_total'] > 0): ?><div class="d-flex justify-content-between"><span class="text-muted">Discount</span><span>−<?= money((float) $order['discount_total']) ?></span></div><?php endif; ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Shipping</span><span><?= (float) $order['shipping_total'] > 0 ? money((float) $order['shipping_total']) : 'Free' ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Tax</span><span><?= money((float) $order['tax_total']) ?></span></div>
        <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-2"><span>Grand total</span><span><?= money((float) $order['grand_total']) ?></span></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="small text-muted">
        <div><strong class="text-dark">Payment:</strong> <?= e(payment_label((string) $order['payment_method'])) ?></div>
        <?php if (!empty($order['paid_at'])): ?><div>Paid on <?= e(format_date((string) $order['paid_at'], 'd M Y')) ?></div><?php endif; ?>
        <?php if (!empty($order['tracking_number'])): ?><div>Tracking: <?= e((string) $order['tracking_number']) ?> <?= e((string) ($order['carrier'] ?? '')) ?></div><?php endif; ?>
        <?php if (!empty($shipping['city'])): ?><div>Delivered to <?= e((string) ($shipping['city'] ?? '')) ?>, <?= e((string) ($shipping['state'] ?? '')) ?></div><?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-2">
        <a class="btn btn-sm btn-outline-primary" href="<?= e(Url::to('invoice.php?order=' . (int) $order['id'])) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-invoice me-1"></i>Invoice</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-rotate-right me-1"></i>Buy again</a>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div class="small text-muted"><?= $pager->infoText() ?></div>
  <?= $pager->links() ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
