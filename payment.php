<?php
/** Razorpay payment page: creates a gateway order and renders the checkout. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\PaymentGateway;
use Saee\Lib\Request;
use Saee\Lib\Url;

$orderId = Request::int('order');
$order = db()->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
if ($order['payment_status'] === 'paid') {
    Request::redirect('order-confirmation.php?order=' . $orderId);
}

$provider = PaymentGateway::get('razorpay');
$intent = $provider ? $provider->createIntent($order) : ['ok' => false, 'message' => 'Payment gateway unavailable.'];

$pageTitle = 'Complete Payment';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<section class="section">
  <div class="container" style="max-width:640px">
    <div class="panel p-4 text-center">
      <h1 class="h4">Complete your payment</h1>
      <p class="text-muted small mb-1">Order <?= e($order['order_number']) ?></p>
      <div class="display-6 fw-bold text-primary mb-3"><?= money($order['grand_total']) ?></div>
      <?php if (!$intent['ok']): ?>
        <div class="alert alert-warning"><?= e($intent['message'] ?? 'Payment could not be initialised.') ?></div>
        <p class="small text-muted">If the amount was already debited, please contact support with your order number.</p>
        <a href="<?= e(Url::to('contact-us')) ?>" class="btn btn-outline-primary btn-sm">Contact support</a>
      <?php else: ?>
        <button id="rzpPay" class="btn btn-primary btn-lg w-100">Pay <?= money($order['grand_total']) ?> securely</button>
        <p class="small text-muted mt-3 mb-0"><i class="fa-solid fa-lock me-1"></i>You will be redirected to the Razorpay secure window.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if ($intent['ok']): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
var options = {
  key: <?= json_encode($intent['key_id']) ?>,
  amount: <?= (int) $intent['amount'] ?>,
  currency: <?= json_encode($intent['currency']) ?>,
  name: <?= json_encode(setting('site_name')) ?>,
  description: <?= json_encode('Order ' . $order['order_number']) ?>,
  order_id: <?= json_encode($intent['order_id']) ?>,
  prefill: { name: <?= json_encode($order['customer_name']) ?>, contact: <?= json_encode($order['customer_mobile']) ?>, email: <?= json_encode((string) $order['customer_email']) ?> },
  theme: { color: <?= json_encode(setting('theme_primary')) ?> },
  handler: function (response) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = <?= json_encode(Url::to('api/orders/razorpay-callback.php')) ?>;
    var fields = {
      _csrf: <?= json_encode(\Saee\Lib\Security::csrfToken()) ?>,
      order_id: <?= (int) $order['id'] ?>,
      razorpay_order_id: response.razorpay_order_id,
      razorpay_payment_id: response.razorpay_payment_id,
      razorpay_signature: response.razorpay_signature
    };
    Object.keys(fields).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; form.appendChild(i); });
    document.body.appendChild(form);
    form.submit();
  },
  modal: { ondismiss: function () { window.location.href = <?= json_encode(Url::to('my-orders.php')) ?>; } }
};
var rzp = new Razorpay(options);
document.getElementById('rzpPay').addEventListener('click', function () { rzp.open(); });
</script>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
