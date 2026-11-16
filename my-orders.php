<?php
/** Customer order history + detail with tracking timeline. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Url;

CustomerAuth::requireLogin('login.php');
$customer = CustomerAuth::user();
$orderId = Request::int('order');

$pageTitle = 'My Orders';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
echo page_hero('My Orders', 'Track deliveries and download invoices.', ['My Account' => 'my-account.php', 'Orders' => '']);

if ($orderId) {
    $order = db()->first('SELECT * FROM orders WHERE id = :id AND customer_id = :c', ['id' => $orderId, 'c' => $customer['id']]);
    if (!$order) {
        echo '<section class="section"><div class="container"><div class="alert alert-danger">Order not found.</div></div></section>';
        require INCLUDES_PATH . '/footer.php';
        exit;
    }
    $items = db()->select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
    $history = db()->select('SELECT * FROM order_status_history WHERE order_id = :o ORDER BY id ASC', ['o' => $orderId]);
    $shipping = json_decode((string) $order['shipping_address'], true) ?: [];
    ?>
    <section class="section"><div class="container"><div class="row g-4">
      <div class="col-lg-8">
        <div class="panel mb-3">
          <div class="panel-head"><h2><?= e($order['order_number']) ?></h2><div><?= status_badge($order['status']) ?> <?= status_badge($order['payment_status']) ?></div></div>
          <div class="panel-body p-0">
            <div class="table-responsive"><table class="table table-saee mb-0">
              <thead><tr><th>Product</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr></thead>
              <tbody>
                <?php foreach ($items as $item): ?>
                  <tr><td><?= e($item['product_name']) ?></td><td class="text-center"><?= (int) $item['quantity'] ?></td><td class="text-end"><?= money($item['line_total']) ?></td></tr>
                <?php endforeach; ?>
                <tr><td colspan="2" class="text-end">Shipping</td><td class="text-end"><?= money($order['shipping_total']) ?></td></tr>
                <tr><td colspan="2" class="text-end">Tax</td><td class="text-end"><?= money($order['tax_total']) ?></td></tr>
                <tr><td colspan="2" class="text-end fw-semibold">Grand total</td><td class="text-end fw-bold"><?= money($order['grand_total']) ?></td></tr>
              </tbody>
            </table></div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h2>Order timeline</h2></div>
          <div class="panel-body">
            <ul class="timeline">
              <?php foreach ($history as $h): ?>
                <li><strong><?= e(order_status_label((string) $h['status'])) ?></strong>
                  <div class="text-muted small"><?= e($h['note'] ?? '') ?></div>
                  <span class="t-time"><?= format_datetime($h['created_at']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="panel mb-3">
          <div class="panel-head"><h2>Delivery address</h2></div>
          <div class="panel-body small">
            <div class="fw-semibold"><?= e($shipping['full_name'] ?? '') ?></div>
            <div class="text-muted"><?= e($shipping['address_line1'] ?? '') ?><br><?= e($shipping['city'] ?? '') ?>, <?= e($shipping['state'] ?? '') ?> - <?= e($shipping['pincode'] ?? '') ?><br><?= e($shipping['phone'] ?? '') ?></div>
            <?php if ($order['tracking_number']): ?><hr><div><strong>Tracking:</strong> <?= e($order['tracking_number']) ?> <?= e($order['carrier'] ?? '') ?></div><?php endif; ?>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h2>Payment</h2></div>
          <div class="panel-body small">
            <p class="mb-1"><strong>Method:</strong> <?= e(payment_label($order['payment_method'])) ?></p>
            <p class="mb-1"><strong>Status:</strong> <?= e(ucfirst((string) $order['payment_status'])) ?></p>
            <?php if ($order['paid_at']): ?><p class="mb-1"><strong>Paid on:</strong> <?= format_datetime($order['paid_at']) ?></p><?php endif; ?>
            <a class="btn btn-outline-primary btn-sm w-100 mt-2" href="<?= e(Url::to('invoice.php?order=' . (int) $order['id'])) ?>" target="_blank"><i class="fa-solid fa-file-invoice me-1"></i>Download invoice</a>
          </div>
        </div>
      </div>
    </div></div></section>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$orders = db()->select('SELECT * FROM orders WHERE customer_id = :c ORDER BY id DESC LIMIT 100', ['c' => $customer['id']]);
?>
<section class="section">
  <div class="container">
    <?= render_flash() ?>
    <?php if ($orders === []): ?>
      <div class="panel p-5 text-center"><i class="fa-solid fa-box-open fs-1 text-muted mb-3"></i><h2 class="h5">No orders yet</h2><a class="btn btn-primary btn-sm" href="<?= e(Url::to('our-products')) ?>">Start shopping</a></div>
    <?php else: ?>
      <div class="panel">
        <div class="panel-body p-0">
          <div class="table-responsive">
            <table class="table table-saee table-responsive-cards mb-0">
              <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Payment</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($orders as $order):
                    $count = (int) db()->value('SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE order_id = :o', ['o' => $order['id']]); ?>
                  <tr>
                    <td data-label="Order"><span class="fw-medium"><?= e($order['order_number']) ?></span></td>
                    <td data-label="Date"><?= format_date($order['created_at']) ?></td>
                    <td data-label="Items"><?= $count ?></td>
                    <td data-label="Payment"><?= e(payment_label($order['payment_method'])) ?><br><?= status_badge($order['payment_status']) ?></td>
                    <td data-label="Status"><?= status_badge($order['status']) ?></td>
                    <td data-label="Total" class="text-end"><?= money($order['grand_total']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(Url::to('my-orders.php?order=' . (int) $order['id'])) ?>">View</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
