<?php
/**
 * Assisted purchase desk (admin).
 *
 * An administrator can record an order on behalf of any member. The order is written against
 * the *member*, not the admin, so MlmCommissionEngine pays the member's sponsor line exactly
 * as it would for a storefront sale, and RoyaltyEngine is evaluated for that member. Nothing
 * about the compensation plan is re-implemented here.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('orders.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\MemberPurchaseService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$service = new MemberPurchaseService($db);

$memberId = Request::int('member_id');
if ($memberId < 1) {
    $memberId = (int) (Request::query('member_id') ?? 0);
}
$search = trim((string) Request::query('q', ''));
$memberSearch = trim((string) Request::query('m', ''));

if (is_post()) {
    Security::verifyCsrf();
    if ((string) Request::input('action') === 'purchase') {
        if (!AdminAuth::can('orders.create') && !AdminAuth::can('orders.update')) {
            Session::error('You do not have permission to record purchases.');
            Request::redirect('purchase.php');
        }
        $lines = [];
        foreach ((array) Request::input('qty', []) as $productId => $qty) {
            if ((int) $qty > 0) {
                $lines[(int) $productId] = (int) $qty;
            }
        }
        $targetMember = Request::int('member_id');
        $result = $service->create(0, 'admin', $targetMember, $lines, [
            'payment_mode' => (string) Request::input('payment_mode', ''),
            'notes' => (string) Request::input('notes', ''),
            'customer_name' => (string) Request::input('customer_name', ''),
            'customer_mobile' => (string) Request::input('customer_mobile', ''),
            'address_line1' => (string) Request::input('address_line1', ''),
            'city' => (string) Request::input('city', ''),
            'state' => (string) Request::input('state', ''),
            'pincode' => (string) Request::input('pincode', ''),
        ]);
        if (!empty($result['ok'])) {
            Audit::log('assisted_purchase', 'orders', $result['message'], 'orders', (int) ($result['order_id'] ?? 0));
            Session::success($result['message']);
            Request::redirect('order-details.php?id=' . (int) $result['order_id']);
        }
        Session::error($result['message']);
        Request::redirect('purchase.php?member_id=' . $targetMember);
    }
}

$members = $service->purchasableMembers('admin', 0, $memberSearch);
if ($memberId < 1 && $members !== []) {
    $memberId = (int) array_key_first($members);
}
$beneficiary = $memberId > 0 ? $db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]) : null;
$products = $service->products($search);
$walletBalance = $memberId > 0 ? $service->walletBalance($memberId) : 0.0;

$pageTitle = 'Record Purchase';
$pageHeading = 'Record Purchase for a Member';
$pageSub = 'Buy on behalf of any member — commissions and payout rules are applied automatically';
$activeModule = 'orders';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="orders.php">All orders</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label class="form-label small text-muted mb-1">Member</label>
      <select class="form-select form-select-sm" name="member_id" onchange="this.form.submit()">
        <?php foreach ($members as $id => $label): ?>
          <option value="<?= (int) $id ?>" <?= $memberId === (int) $id ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label small text-muted mb-1">Find member</label>
      <input class="form-control form-control-sm" name="m" value="<?= e($memberSearch) ?>" placeholder="Name, code or mobile">
    </div>
    <div class="col-12 col-md-3"><button class="btn btn-sm btn-outline-primary w-100">Search members</button></div>
  </form>
</div>

<?php if (!$members): ?>
  <div class="panel p-3 p-md-4"><p class="small text-muted mb-0">No members matched.</p></div>
<?php elseif ($beneficiary): ?>
  <div class="alert alert-light border small d-flex flex-wrap gap-3 justify-content-between">
    <span>Recording for <strong><?= e((string) $beneficiary['name']) ?></strong> (<?= e((string) $beneficiary['member_code']) ?>) · sponsor line gets the commission</span>
    <span>Wallet balance: <strong><?= money($walletBalance) ?></strong></span>
  </div>

  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="purchase">
    <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">

    <div class="panel p-3 p-md-4 mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h2 class="h6 mb-0">Products</h2>
        <div class="d-flex gap-2">
          <input class="form-control form-control-sm js-product-filter" value="<?= e($search) ?>" placeholder="Filter products" style="width:180px">
        </div>
      </div>
      <?php if (!$products): ?>
        <p class="small text-muted mb-0">No active products matched.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Price</th><th class="text-center" style="width:130px">Qty</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
              <?php $stock = (int) $product['stock_quantity']; ?>
              <tr>
                <td class="small fw-semibold js-product-row" data-name="<?= e(mb_strtolower((string) $product['name'] . ' ' . (string) ($product['sku'] ?? ''))) ?>"><?= e((string) $product['name']) ?>
                  <div class="small text-muted fw-normal"><?= $stock > 0 ? $stock . ' in stock' : 'Stock not tracked' ?><?= (float) ($product['tax_percent'] ?? 0) > 0 ? ' · GST ' . e((string) (float) $product['tax_percent']) . '%' : '' ?></div>
                </td>
                <td class="small text-muted"><?= e((string) ($product['sku'] ?? '—')) ?></td>
                <td class="text-end small fw-semibold"><?= money((float) $product['price']) ?></td>
                <td>
                  <input type="number" class="form-control form-control-sm text-center qty-input"
                         name="qty[<?= (int) $product['id'] ?>]" min="0" max="<?= $stock > 0 ? $stock : 999 ?>"
                         data-price="<?= e((string) (float) $product['price']) ?>"
                         data-tax="<?= e((string) (float) ($product['tax_percent'] ?? 0)) ?>" placeholder="0">
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3">Mode of payment</h2>
          <?php foreach (MemberPurchaseService::PAYMENT_MODES as $key => $label): ?>
            <?php $disabled = $key === 'wallet' && $walletBalance <= 0; ?>
            <div class="form-check mb-2 <?= $disabled ? 'opacity-50' : '' ?>">
              <input class="form-check-input" type="radio" name="payment_mode" id="pm_<?= e($key) ?>" value="<?= e($key) ?>"
                     <?= $disabled ? 'disabled' : '' ?> <?= $key === 'upi' ? 'checked' : '' ?> required>
              <label class="form-check-label small" for="pm_<?= e($key) ?>"><?= e($label) ?>
                <?php if ($key === 'wallet'): ?>
                  <span class="text-muted">(<?= money($walletBalance) ?> available)</span>
                <?php endif; ?>
              </label>
            </div>
          <?php endforeach; ?>
          <p class="small text-muted mb-0">Wallet, UPI and bank transfer are recorded as paid and run commissions immediately. Cash on delivery waits for payment/delivery confirmation.</p>
        </div>
      </div>
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3">Delivery details</h2>
          <div class="row g-2">
            <div class="col-12"><label class="form-label small mb-1">Name</label>
              <input class="form-control form-control-sm" name="customer_name" value="<?= e((string) $beneficiary['name']) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Mobile</label>
              <input class="form-control form-control-sm" name="customer_mobile" value="<?= e((string) $beneficiary['mobile']) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Address</label>
              <input class="form-control form-control-sm" name="address_line1" value="<?= e((string) ($beneficiary['address'] ?? '')) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">City</label>
              <input class="form-control form-control-sm" name="city" value=""></div>
            <div class="col-6"><label class="form-label small mb-1">Pincode</label>
              <input class="form-control form-control-sm" name="pincode" value=""></div>
            <div class="col-12"><label class="form-label small mb-1">State</label>
              <input class="form-control form-control-sm" name="state" value="<?= e((string) ($beneficiary['state'] ?? '')) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Internal note</label>
              <input class="form-control form-control-sm" name="notes" value=""></div>
          </div>
        </div>
      </div>
    </div>

    <div class="panel p-3 p-md-4 mt-3 d-flex flex-wrap gap-3 justify-content-between align-items-center">
      <div class="small">
        <div>Subtotal <strong data-summary="subtotal"><?= money(0) ?></strong></div>
        <div>Tax <strong data-summary="tax"><?= money(0) ?></strong></div>
        <div>Shipping <strong data-summary="shipping"><?= money(0) ?></strong></div>
        <div class="fs-6">Total <strong data-summary="total"><?= money(0) ?></strong></div>
      </div>
      <button class="btn btn-primary">Record purchase</button>
    </div>
  </form>
<?php endif; ?>

<script>
(function () {
  var currency = <?= json_encode(currency_symbol()) ?>;
  var fmt = function (n) { return currency + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
  var inputs = document.querySelectorAll('.qty-input');
  var out = {
    subtotal: document.querySelector('[data-summary="subtotal"]'),
    tax: document.querySelector('[data-summary="tax"]'),
    shipping: document.querySelector('[data-summary="shipping"]'),
    total: document.querySelector('[data-summary="total"]')
  };
  function recalc() {
    var subtotal = 0, tax = 0;
    inputs.forEach(function (input) {
      var qty = parseInt(input.value, 10) || 0;
      var line = qty * parseFloat(input.dataset.price || '0');
      subtotal += line;
      tax += line * (parseFloat(input.dataset.tax || '0') / 100);
    });
    var shipping = subtotal > 0 && subtotal < 999 ? 60 : 0;
    if (out.subtotal) out.subtotal.textContent = fmt(subtotal);
    if (out.tax) out.tax.textContent = fmt(tax);
    if (out.shipping) out.shipping.textContent = fmt(shipping);
    if (out.total) out.total.textContent = fmt(subtotal + tax + shipping);
  }
  inputs.forEach(function (input) { input.addEventListener('input', recalc); });
  var filter = document.querySelector('.js-product-filter');
  if (filter) {
    filter.addEventListener('input', function () {
      var term = filter.value.toLowerCase();
      document.querySelectorAll('.js-product-row').forEach(function (cell) {
        var row = cell.closest('tr');
        row.style.display = cell.dataset.name.indexOf(term) === -1 ? 'none' : '';
      });
    });
  }
  recalc();
})();
</script>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
