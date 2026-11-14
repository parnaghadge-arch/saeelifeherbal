<?php
/** Checkout: address capture, payment method selection and order placement. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Cart;
use Saee\Lib\CustomerAuth;
use Saee\Lib\OrderService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$cartService = new Cart(db());
$totals = $cartService->totals();
if ($totals['items'] === []) {
    \Saee\Lib\Session::flash('warning', 'Your cart is empty.');
    Request::redirect('cart.php');
}

$orderService = new OrderService(db());
$gateways = $orderService->enabledGateways();
$customer = CustomerAuth::user();
$defaultAddress = null;
if ($customer) {
    $defaultAddress = db()->first('SELECT * FROM customer_addresses WHERE customer_id = :c ORDER BY is_default DESC, id DESC LIMIT 1', ['c' => $customer['id']]);
}

$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('full_name', 'Full name')->required('full_name')->min('full_name', 3);
    $v->label('phone', 'Mobile number')->required('phone')->phone('phone', true);
    $v->label('email', 'Email')->email('email');
    $v->label('address_line1', 'Address')->required('address_line1')->min('address_line1', 6);
    $v->label('city', 'City / Town')->required('city');
    $v->label('state', 'State')->required('state');
    $v->label('pincode', 'PIN code')->required('pincode')->pincode('pincode', true);
    $v->label('payment_method', 'Payment method')->required('payment_method')->in('payment_method', array_keys($gateways));

    if ($v->passes()) {
        // Persist the customer account automatically so orders can be tracked.
        if (!$customer) {
            $mobile = preg_replace('/\D/', '', (string) $v->value('phone'));
            $mobile = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
            // Match on mobile OR email so repeat buyers never hit a duplicate key.
            $existing = db()->first('SELECT * FROM customers WHERE mobile = :m', ['m' => $mobile]);
            if (!$existing && $v->value('email')) {
                $existing = db()->first('SELECT * FROM customers WHERE LOWER(email) = :e', ['e' => strtolower((string) $v->value('email'))]);
            }
            if ($existing) {
                $customer = $existing;
                CustomerAuth::loginSession($existing);
                if (empty($existing['email']) && $v->value('email')) {
                    db()->update('customers', ['email' => strtolower((string) $v->value('email')), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $existing['id']]);
                }
            } else {
                $password = Security::randomToken(6);
                $customerId = db()->insert('customers', [
                    'name' => $v->value('full_name'),
                    'email' => $v->value('email') ?: null,
                    'mobile' => $mobile,
                    'password_hash' => Security::passwordHash($password),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $customer = db()->first('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
                CustomerAuth::loginSession($customer);
                \Saee\Lib\Session::info('An account was created for you. Temporary password: ' . $password . ' — please change it from My Account.');
            }
        }

        $shipping = [
            'full_name' => $v->value('full_name'),
            'phone' => preg_replace('/\D/', '', (string) $v->value('phone')),
            'email' => $v->value('email'),
            'address_line1' => $v->value('address_line1'),
            'address_line2' => $v->value('address_line2'),
            'city' => $v->value('city'),
            'district' => $v->value('district'),
            'state' => $v->value('state'),
            'pincode' => $v->value('pincode'),
            'landmark' => $v->value('landmark'),
        ];
        try {
            $result = $orderService->checkout([
            'payment_method' => $v->value('payment_method'),
            'shipping' => $shipping,
            'billing' => $shipping,
            'notes' => $v->value('notes'),
            ]);
        } catch (Throwable $e) {
            error_log('[checkout] ' . $e->getMessage());
            $result = ['ok' => false, 'message' => APP_DEBUG ? 'Checkout failed: ' . $e->getMessage() : 'We could not place your order. Please try again or call our helpline.'];
        }
        if ($result['ok']) {
            if ($result['payment_method'] === 'razorpay') {
                Request::redirect('payment.php?order=' . $result['order_id']);
            }
            Request::redirect('order-confirmation.php?order=' . $result['order_id']);
        }
        $errors['general'] = [$result['message']];
    } else {
        $errors = $v->errors();
        if (Request::isAjax()) {
            \Saee\Lib\Response::error($v->firstError(), $errors);
        }
    }
}

$pageTitle = 'Checkout';
$pageRobots = 'noindex,follow';
require INCLUDES_PATH . '/header.php';
echo page_hero('Checkout', 'Complete your order in a few steps.', ['Cart' => 'cart.php', 'Checkout' => '']);
?>
<section class="section">
  <div class="container">
    <?= render_flash() ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger"><?= e($errors['general'][0]) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(Url::to('checkout.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="panel mb-4">
            <div class="panel-head"><h2><i class="fa-solid fa-location-dot me-2 text-primary"></i>Delivery details</h2></div>
            <div class="panel-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="full_name">Full name <span class="required-mark">*</span></label>
                  <input id="full_name" name="full_name" class="form-control<?= invalid_class($errors, 'full_name') ?>" value="<?= old_input('full_name', $customer['name'] ?? $defaultAddress['full_name'] ?? '') ?>" required>
                  <?= field_error($errors, 'full_name') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="phone">Mobile number <span class="required-mark">*</span></label>
                  <input id="phone" name="phone" type="tel" class="form-control<?= invalid_class($errors, 'phone') ?>" value="<?= old_input('phone', $customer['mobile'] ?? $defaultAddress['phone'] ?? '') ?>" required>
                  <?= field_error($errors, 'phone') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="email">Email</label>
                  <input id="email" name="email" type="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email', $customer['email'] ?? '') ?>">
                  <?= field_error($errors, 'email') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="pincode">PIN code <span class="required-mark">*</span></label>
                  <input id="pincode" name="pincode" class="form-control<?= invalid_class($errors, 'pincode') ?>" value="<?= old_input('pincode', $defaultAddress['pincode'] ?? '') ?>" inputmode="numeric" required>
                  <?= field_error($errors, 'pincode') ?>
                </div>
                <div class="col-12">
                  <label class="form-label" for="address_line1">Address (house no, street, village) <span class="required-mark">*</span></label>
                  <input id="address_line1" name="address_line1" class="form-control<?= invalid_class($errors, 'address_line1') ?>" value="<?= old_input('address_line1', $defaultAddress['address_line1'] ?? '') ?>" required>
                  <?= field_error($errors, 'address_line1') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="address_line2">Landmark / area</label>
                  <input id="address_line2" name="address_line2" class="form-control" value="<?= old_input('address_line2') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="landmark">Nearby landmark</label>
                  <input id="landmark" name="landmark" class="form-control" value="<?= old_input('landmark') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="city">City / Town <span class="required-mark">*</span></label>
                  <input id="city" name="city" class="form-control<?= invalid_class($errors, 'city') ?>" value="<?= old_input('city', $defaultAddress['city'] ?? '') ?>" required>
                  <?= field_error($errors, 'city') ?>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="district">District</label>
                  <input id="district" name="district" class="form-control" value="<?= old_input('district', $defaultAddress['district'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="state">State <span class="required-mark">*</span></label>
                  <input id="state" name="state" class="form-control<?= invalid_class($errors, 'state') ?>" value="<?= old_input('state', $defaultAddress['state'] ?? '') ?>" required>
                  <?= field_error($errors, 'state') ?>
                </div>
                <div class="col-12">
                  <label class="form-label" for="notes">Order notes (optional)</label>
                  <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Delivery instructions, preferred time…"><?= old_input('notes') ?></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head"><h2><i class="fa-solid fa-credit-card me-2 text-primary"></i>Payment method</h2></div>
            <div class="panel-body">
              <?php if ($gateways === []): ?>
                <div class="alert alert-warning mb-0">No payment method is enabled. Please contact support.</div>
              <?php endif; ?>
              <div class="d-grid gap-2">
                <?php foreach ($gateways as $key => $label):
                    $desc = match ($key) {
                        'cod' => 'Pay in cash when your order is delivered.',
                        'razorpay' => 'Cards, UPI, netbanking and wallets via Razorpay.',
                        'upi' => 'Pay directly to our UPI ID and upload the reference.',
                        default => '',
                    }; ?>
                  <label class="pay-option <?= old_input('payment_method', 'cod') === $key ? 'selected' : '' ?>">
                    <input type="radio" name="payment_method" value="<?= e($key) ?>" class="form-check-input mt-1" <?= old_input('payment_method', 'cod') === $key ? 'checked' : '' ?> required>
                    <span>
                      <span class="fw-semibold d-block"><?= e($label) ?></span>
                      <span class="small text-muted"><?= e($desc) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php echo field_error($errors, 'payment_method'); ?>
              <p class="small text-muted mt-3 mb-0"><i class="fa-solid fa-shield-halved me-1"></i>Payments are processed through PCI-DSS compliant gateways. Saee Life Herbal never stores card details.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="summary-card sticky-lg-top" style="top:90px">
            <h2 class="h6 mb-3">Your order</h2>
            <?php foreach ($totals['items'] as $item): ?>
              <div class="d-flex justify-content-between small py-1">
                <span class="text-truncate-2"><?= e($item['name']) ?> <span class="text-muted">×<?= (int) $item['quantity'] ?></span></span>
                <span class="ms-2 text-nowrap"><?= money((float) $item['unit_price'] * (int) $item['quantity']) ?></span>
              </div>
            <?php endforeach; ?>
            <hr class="my-2">
            <div class="summary-row"><span>Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
            <div class="summary-row"><span>Tax</span><span><?= money($totals['tax']) ?></span></div>
            <div class="summary-row"><span>Shipping</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Free' ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?= money($totals['grand_total']) ?></span></div>
            <button type="submit" class="btn btn-primary w-100 mt-3" <?= $gateways === [] ? 'disabled' : '' ?>>Place order <i class="fa-solid fa-arrow-right ms-1"></i></button>
            <p class="small text-muted mt-3 mb-0"><?= e(setting('order_terms')) ?></p>
          </div>
        </div>
      </div>
    </form>
  </div>
</section>
<script>
document.querySelectorAll('.pay-option input').forEach(function (input) {
  input.addEventListener('change', function () {
    document.querySelectorAll('.pay-option').forEach(function (o) { o.classList.remove('selected'); });
    input.closest('.pay-option').classList.add('selected');
  });
});
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
