<?php
/**
 * Purchase History - one order in full.
 *
 * Reached from the summary list on member/orders.php. Everything the list leaves out lives
 * here: line items, totals, delivery address, payment trail and the status timeline.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\OrderService;
use Saee\Lib\Request;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$orderId = Request::int('id');
$order = $db->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);

// Only the member this order belongs to may see it. The referral member is included because
// the summary list counts those orders as part of their purchase history too.
$ownsIt = $order && ((int) ($order['member_id'] ?? 0) === $currentMemberId
    || (int) ($order['referral_member_id'] ?? 0) === $currentMemberId);
if (!$ownsIt) {
    Session::error('That order could not be found.');
    Request::redirect('orders.php');
}

$items = $db->select(
    'SELECT i.*, p.featured_image AS product_image, p.slug AS product_slug
     FROM order_items i LEFT JOIN products p ON p.id = i.product_id
     WHERE i.order_id = :o ORDER BY i.id ASC',
    ['o' => $orderId]
);
$history = $db->select(
    'SELECT * FROM order_status_history WHERE order_id = :o ORDER BY id ASC',
    ['o' => $orderId]
);
$payments = $db->select(
    'SELECT * FROM order_payments WHERE order_id = :o ORDER BY id DESC',
    ['o' => $orderId]
);

$shipping = json_decode((string) ($order['shipping_address'] ?? ''), true) ?: [];
$status = OrderService::normalizeStatus($order['status']);

$pageTitle = 'Order ' . $order['order_number'];
$pageHeading = 'Order ' . $order['order_number'];
$pageSub = format_date((string) $order['created_at'], 'd M Y, h:i A') . ' · ' . money((float) $order['grand_total']);
$activeNav = 'orders';
require __DIR__ . '/includes/layout.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="orders.php"><i class="fa-solid fa-arrow-left me-1"></i>Back to Purchase History</a>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?= status_badge($status) ?>
    <?= status_badge((string) $order['payment_status']) ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Products in this order</h2>
      <?php if (!$items): ?>
        <p class="small text-muted mb-0">No items recorded on this order.</p>
      <?php else: ?>
        <div class="purchase-items">
          <?php foreach ($items as $item): ?>
            <div class="purchase-item">
              <?php if (!empty($item['product_image'])): ?>
                <img src="<?= e(Url::media((string) $item['product_image'])) ?>"
                     alt="<?= e((string) $item['product_name']) ?>" loading="lazy" width="56" height="56">
              <?php else: ?>
                <span class="purchase-thumb-fallback"><i class="fa-solid fa-seedling"></i></span>
              <?php endif; ?>
              <div class="purchase-item-info">
                <?php if (!empty($item['product_slug'])): ?>
                  <a class="fw-semibold small text-decoration-none" href="<?= e(product_url((string) $item['product_slug'])) ?>"><?= e((string) $item['product_name']) ?></a>
                <?php else: ?>
                  <div class="fw-semibold small"><?= e((string) $item['product_name']) ?></div>
                <?php endif; ?>
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
      <?php endif; ?>

      <div class="small mt-3 pt-3 border-top">
        <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><span><?= money((float) $order['subtotal']) ?></span></div>
        <?php if ((float) $order['discount_total'] > 0): ?>
          <div class="d-flex justify-content-between"><span class="text-muted">Discount</span><span>−<?= money((float) $order['discount_total']) ?></span></div>
        <?php endif; ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Shipping</span><span><?= (float) $order['shipping_total'] > 0 ? money((float) $order['shipping_total']) : 'Free' ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Tax</span><span><?= money((float) $order['tax_total']) ?></span></div>
        <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-2"><span>Grand total</span><span><?= money((float) $order['grand_total']) ?></span></div>
      </div>
    </div>

    <?php if ($history): ?>
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3">Progress</h2>
        <ol class="list-unstyled mb-0 small">
          <?php foreach ($history as $step): ?>
            <li class="d-flex gap-2 pb-2">
              <i class="fa-solid fa-circle-check text-primary mt-1" aria-hidden="true"></i>
              <div>
                <div class="fw-semibold"><?= e(order_status_label((string) $step['status'])) ?></div>
                <?php if (!empty($step['note'])): ?><div class="text-muted"><?= e((string) $step['note']) ?></div><?php endif; ?>
                <div class="text-muted"><?= e(format_date((string) $step['created_at'], 'd M Y, h:i A')) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-lg-4">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-2">Payment</h2>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Method</span><span><?= e(payment_label((string) $order['payment_method'])) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Status</span><span><?= e(ucfirst((string) $order['payment_status'])) ?></span></div>
        <?php if (!empty($order['paid_at'])): ?>
          <div class="d-flex justify-content-between"><span class="text-muted">Paid on</span><span><?= e(format_date((string) $order['paid_at'], 'd M Y')) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['invoice_number'])): ?>
          <div class="d-flex justify-content-between"><span class="text-muted">Invoice</span><span><?= e((string) $order['invoice_number']) ?></span></div>
        <?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-invoice-id="<?= (int) $order['id'] ?>"
                data-invoice-label="<?= e((string) ($order['invoice_number'] ?: $order['order_number'])) ?> · <?= e(format_date((string) $order['created_at'], 'd M Y')) ?>">
          <i class="fa-solid fa-file-invoice me-1" aria-hidden="true"></i>Invoice
        </button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-rotate-right me-1"></i>Buy again</a>
      </div>
    </div>

    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-2">Delivery address</h2>
      <?php if ($shipping): ?>
        <div class="small">
          <?php if (!empty($shipping['full_name'])): ?><div class="fw-semibold"><?= e((string) $shipping['full_name']) ?></div><?php endif; ?>
          <?php if (!empty($shipping['phone'])): ?><div class="text-muted"><?= e((string) $shipping['phone']) ?></div><?php endif; ?>
          <div class="text-muted">
            <?= e(trim(implode(', ', array_filter([
                (string) ($shipping['address_line1'] ?? ''),
                (string) ($shipping['address_line2'] ?? ''),
                (string) ($shipping['city'] ?? ''),
                (string) ($shipping['state'] ?? ''),
                (string) ($shipping['pincode'] ?? ''),
            ])))) ?>
          </div>
        </div>
      <?php else: ?>
        <p class="small text-muted mb-0">No delivery address recorded.</p>
      <?php endif; ?>

      <?php if (!empty($order['tracking_number'])): ?>
        <hr>
        <div class="small">
          <div class="fw-semibold">Tracking</div>
          <div class="text-muted"><?= e((string) $order['tracking_number']) ?><?= !empty($order['carrier']) ? ' · ' . e((string) $order['carrier']) : '' ?></div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($payments): ?>
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-2">Payment attempts</h2>
        <div class="small">
          <?php foreach ($payments as $pay): ?>
            <div class="d-flex justify-content-between border-bottom py-1">
              <span class="text-muted"><?= e(strtoupper((string) $pay['gateway'])) ?> · <?= money((float) $pay['amount']) ?></span>
              <span><?= status_badge((string) $pay['status']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
