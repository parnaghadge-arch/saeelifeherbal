<?php
/**
 * Payment methods.
 *
 * A dedicated screen for the one commercial decision the shop owner actually makes here: which
 * ways of paying are open today. Cash on Delivery is the only one live at launch; UPI and
 * Razorpay are wired end to end and stay dark until they are switched on from this page.
 *
 * Bank Transfer was retired as a checkout option (money that arrives by NEFT cannot be verified
 * by the system, so it silently stalled orders). Its details remain printable on the invoice.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('settings.manage');

use Saee\Lib\Audit;
use Saee\Lib\OrderService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;

$db = db();

/**
 * Everything this screen can turn on, in the order it should be read.
 * `flag` is the settings key OrderService::gatewayEnabled() already reads, so there is one
 * source of truth: flip the switch here and checkout offers the method immediately.
 */
$methods = [
    'cod' => [
        'flag' => 'cod_enabled',
        'name' => 'Cash on Delivery',
        'icon' => 'fa-money-bill-wave',
        'blurb' => 'The customer pays the delivery agent in cash. Nothing to configure, and it is the only method live right now.',
        'extra' => 'cod_max_amount',
    ],
    'upi' => [
        'flag' => 'upi_enabled',
        'name' => 'UPI',
        'icon' => 'fa-mobile-screen-button',
        'blurb' => 'The customer pays straight to your UPI ID and enters the reference. Best for small, instant payments.',
        'extra' => 'upi_id',
    ],
    'razorpay' => [
        'flag' => 'razorpay_enabled',
        'name' => 'Razorpay (cards, UPI, netbanking)',
        'icon' => 'fa-credit-card',
        'blurb' => 'Hosted checkout through Razorpay. Needs your API keys, and payment is confirmed automatically by webhook.',
        'extra' => 'razorpay_key_id',
    ],
];

$service = new OrderService($db);

if (is_post()) {
    Security::verifyCsrf();

    // An unticked switch is simply absent from $_POST, so read each flag off the list above
    // rather than off the submitted keys.
    $requested = [];
    foreach ($methods as $key => $meta) {
        $requested[$key] = Request::input($meta['flag']) === '1';
    }

    if (!in_array(true, $requested, true)) {
        Session::error('At least one payment method has to stay open, otherwise nobody can check out.');
        Request::redirect('payment-methods.php');
    }

    foreach ($methods as $key => $meta) {
        Settings::set($meta['flag'], $requested[$key] ? '1' : '0', 'bool');
    }

    // Supporting details travel with their switch, but only when the field was on the form.
    $details = [
        'cod_max_amount' => 'string',
        'upi_id' => 'string',
        'upi_name' => 'string',
        'razorpay_key_id' => 'string',
        'razorpay_key_secret' => 'string',
        'razorpay_webhook_secret' => 'string',
        'razorpay_mode' => 'string',
    ];
    foreach ($details as $key => $type) {
        if (array_key_exists($key, $_POST)) {
            Settings::set($key, (string) Request::input($key, ''), $type);
        }
    }

    Settings::flush();
    Audit::log('update', 'settings', 'Payment methods updated: ' . implode(', ', array_keys(array_filter($requested))), 'settings');
    Session::success('Payment methods saved.');
    Request::redirect('payment-methods.php');
}

$enabledCount = 0;
foreach ($methods as $key => $meta) {
    $enabledCount += $service->gatewayEnabled($key) ? 1 : 0;
}

$pageTitle = 'Payment Methods';
$pageHeading = 'Payment Methods';
$pageSub = $enabledCount === 1
    ? 'One method is open at checkout today'
    : $enabledCount . ' methods are open at checkout today';
$activeModule = 'payment-methods';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="settings.php?group=payments"><i class="fa-solid fa-sliders me-1"></i>All payment settings</a>';
require __DIR__ . '/includes/layout.php';
?>

<div class="row g-3">
  <div class="col-12">
    <div class="alert alert-info small d-flex gap-2 mb-0">
      <i class="fa-solid fa-circle-info mt-1"></i>
      <div>
        Switch a method on and it appears at checkout straight away — no code change and no deploy.
        UPI and Razorpay need their details filled in below before customers can actually pay with them.
      </div>
    </div>
  </div>
</div>

<form method="post" class="row g-3 mt-1">
  <?= csrf_field() ?>

  <?php foreach ($methods as $key => $meta):
      $on = $service->gatewayEnabled($key);
      $extraValue = (string) Settings::get((string) $meta['extra'], '');
  ?>
    <div class="col-12">
      <div class="panel p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="d-flex gap-3 align-items-start">
            <span class="feature-icon"><i class="fa-solid <?= e((string) $meta['icon']) ?>"></i></span>
            <div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="h6 mb-0"><?= e((string) $meta['name']) ?></h2>
                <span class="badge badge-status <?= $on ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                  <?= $on ? 'Open at checkout' : 'Closed' ?>
                </span>
              </div>
              <p class="small text-muted mb-2 mt-1"><?= e((string) $meta['blurb']) ?></p>

              <?php if ($key === 'cod'): ?>
                <label class="form-label small text-muted mb-1" for="cod_max_amount">Maximum order value for COD (0 = no limit)</label>
                <input class="form-control form-control-sm" style="max-width: 220px" type="number" step="1" min="0"
                       id="cod_max_amount" name="cod_max_amount" value="<?= e((string) Settings::get('cod_max_amount', '0')) ?>">
                <div class="form-text">Orders above this value cannot be paid in cash.</div>

              <?php elseif ($key === 'upi'): ?>
                <div class="row g-2">
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="upi_id">UPI ID</label>
                    <input class="form-control form-control-sm" id="upi_id" name="upi_id"
                           value="<?= e((string) Settings::get('upi_id', '')) ?>" placeholder="saeelifeherbal@upi">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="upi_name">Payee name shown to the customer</label>
                    <input class="form-control form-control-sm" id="upi_name" name="upi_name"
                           value="<?= e((string) Settings::get('upi_name', '')) ?>" placeholder="Saee Life Herbal">
                  </div>
                </div>

              <?php elseif ($key === 'razorpay'): ?>
                <div class="row g-2">
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="razorpay_key_id">Key ID</label>
                    <input class="form-control form-control-sm" id="razorpay_key_id" name="razorpay_key_id"
                           value="<?= e((string) Settings::get('razorpay_key_id', '')) ?>" placeholder="rzp_test_…">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="razorpay_key_secret">Key secret</label>
                    <input class="form-control form-control-sm" id="razorpay_key_secret" name="razorpay_key_secret" type="password"
                           autocomplete="new-password" placeholder="<?= $extraValue !== '' ? 'Saved — leave blank to keep it' : '' ?>">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="razorpay_webhook_secret">Webhook secret</label>
                    <input class="form-control form-control-sm" id="razorpay_webhook_secret" name="razorpay_webhook_secret" type="password"
                           autocomplete="new-password" placeholder="<?= (string) Settings::get('razorpay_webhook_secret', '') !== '' ? 'Saved — leave blank to keep it' : '' ?>">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label small text-muted mb-1" for="razorpay_mode">Mode</label>
                    <select class="form-select form-select-sm" id="razorpay_mode" name="razorpay_mode">
                      <?php foreach (['test' => 'Test', 'live' => 'Live'] as $mode => $modeLabel): ?>
                        <option value="<?= e($mode) ?>" <?= Settings::get('razorpay_mode', 'test') === $mode ? 'selected' : '' ?>><?= e($modeLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-check form-switch ms-auto">
            <input class="form-check-input" type="checkbox" role="switch" id="sw_<?= e($key) ?>"
                   name="<?= e((string) $meta['flag']) ?>" value="1" <?= $on ? 'checked' : '' ?>>
            <label class="form-check-label small" for="sw_<?= e($key) ?>"><?= $on ? 'On' : 'Off' ?></label>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="col-12">
    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save payment methods</button>
    <a class="btn btn-outline-secondary" href="payment-methods.php">Reset</a>
  </div>
</form>

<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-2">Retired: Bank Transfer</h2>
      <p class="small text-muted mb-2">
        Bank Transfer is no longer offered at checkout. A customer paying by NEFT could not be
        confirmed by the system, so those orders sat waiting for someone to check the bank
        statement. If you still take transfers offline, record the money on the order with
        <strong>Mark as paid</strong> from the order screen.
      </p>
      <p class="small text-muted mb-0">
        Your bank details are still printed on invoices — edit them under
        <a href="settings.php?group=payments">Settings &rsaquo; Payment Gateways</a>.
      </p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-end.php'; ?>
