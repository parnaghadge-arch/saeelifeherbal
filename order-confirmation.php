<?php
/** Order confirmation / thank you page. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Url;

$orderId = Request::int('order');
$order = db()->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require INCLUDES_PATH . '/header.php';
    echo '<section class="section"><div class="container text-center py-5"><h1 class="h2">Order not found</h1><a class="btn btn-primary" href="' . e(Url::to('our-products')) . '">Shop products</a></div></section>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}
$items = db()->select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
$pageTitle = 'Order Confirmed';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<section class="section">
  <div class="container" style="max-width:860px">
    <?= render_flash() ?>
    <div class="panel p-4 p-md-5 text-center mb-4">
      <div class="feature-icon mx-auto" style="width:70px;height:70px;font-size:1.8rem"><i class="fa-solid fa-circle-check"></i></div>
      <h1 class="h3 mt-2">Thank you, <?= e(explode(' ', (string) $order['customer_name'])[0]) ?>!</h1>
      <p class="text-muted mb-1">Your order <strong><?= e($order['order_number']) ?></strong> has been placed successfully.</p>
      <p class="small text-muted">
        Payment: <?= e(payment_label($order['payment_method'])) ?> ·
        Status: <?= status_badge($order['status']) ?>
      </p>
      <div class="d-flex gap-2 justify-content-center flex-wrap">
        <a href="<?= e(Url::to('invoice.php?order=' . $orderId)) ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="fa-solid fa-file-invoice me-1"></i>Invoice</a>
        <a href="<?= e(Url::to('my-orders.php')) ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-box me-1"></i>My orders</a>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>Order summary</h2><span class="small text-muted"><?= format_datetime($order['created_at']) ?></span></div>
      <div class="panel-body p-0">
        <div class="table-responsive">
          <table class="table table-saee mb-0">
            <thead><tr><th>Product</th><th class="text-center">Qty</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr><td><?= e($item['product_name']) ?></td><td class="text-center"><?= (int) $item['quantity'] ?></td><td class="text-end"><?= money($item['line_total']) ?></td></tr>
              <?php endforeach; ?>
              <tr><td colspan="2" class="text-end text-muted">Subtotal</td><td class="text-end"><?= money($order['subtotal']) ?></td></tr>
              <tr><td colspan="2" class="text-end text-muted">Tax</td><td class="text-end"><?= money($order['tax_total']) ?></td></tr>
              <tr><td colspan="2" class="text-end text-muted">Shipping</td><td class="text-end"><?= money($order['shipping_total']) ?></td></tr>
              <tr><td colspan="2" class="text-end fw-semibold">Total</td><td class="text-end fw-bold"><?= money($order['grand_total']) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <p class="small text-muted mt-3 text-center">A confirmation has been recorded in your account. Delivery usually takes 3-8 working days depending on your PIN code.</p>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
