<?php
/**
 * Member purchase desk.
 *
 * A member sees this page for three situations, decided by who is signed in and which member
 * is selected:
 *   - buying for themselves (the default);
 *   - a sponsor buying for somebody in their downline;
 *   - an administrator buying for any member (admin/purchase.php reuses the same form).
 *
 * The order is always recorded against the member it is *for*, so commissions, royalty and
 * payout rules run exactly as they do for a storefront sale.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\MemberPurchaseService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$service = new MemberPurchaseService(db());
$role = 'member';
$actorId = $currentMemberId;
$downline = $service->purchasableMembers('member', $actorId);

// Sponsor flow: ?for=<member id>. Defaults to the member themselves.
$forId = (int) (Request::query('for') ?? 0);
if ($forId < 1 || !$service->canPurchaseFor('member', $actorId, $forId)) {
    $forId = $actorId;
}
$beneficiary = db()->first('SELECT * FROM members WHERE id = :id', ['id' => $forId]);
$search = trim((string) (Request::query('q') ?? ''));
$products = $service->products($search);
$walletBalance = $service->walletBalance($forId);
$result = null;

if (Request::isPost()) {
    Security::verifyCsrf();
    if (Request::input('action') === 'purchase') {
        $lines = [];
        foreach ((array) Request::input('qty', []) as $productId => $qty) {
            if ((int) $qty > 0) {
                $lines[(int) $productId] = (int) $qty;
            }
        }
        $result = $service->create($actorId, $role, (int) Request::input('member_id', $forId), $lines, [
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
            Session::success($result['message']);
            Request::redirect(Url::to('member/buy-products.php?for=' . (int) Request::input('member_id', $forId)));
        }
        Session::error($result['message']);
        Request::redirect(Url::to('member/buy-products.php?for=' . (int) Request::input('member_id', $forId)));
    }
}

$pageTitle = 'Buy Products';
$pageHeading = 'Buy Products';
$pageSub = 'Purchase for yourself' . ($downline !== [] ? ' or for a member in your team' : '') . ' — MLM commission and payout rules are applied automatically';
$activeNav = 'buy-products';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <?php if ($downline !== []): ?>
      <div class="col-12 col-md-5">
        <label class="form-label small text-muted mb-1">Purchasing for</label>
        <select class="form-select form-select-sm" name="for" onchange="this.form.submit()">
          <option value="<?= (int) $actorId ?>">Myself — <?= e((string) $member['name']) ?></option>
          <?php foreach ($downline as $id => $label): ?>
            <option value="<?= (int) $id ?>"<?= $forId === (int) $id ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-12 col-md-5">
      <label class="form-label small text-muted mb-1">Find a product</label>
      <input type="search" class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Name or SKU">
    </div>
    <div class="col-12 col-md-2">
      <button class="btn btn-sm btn-outline-primary w-100">Search</button>
    </div>
  </form>
</div>

<?php if ($beneficiary && $forId !== $actorId): ?>
  <div class="alert alert-info small d-flex flex-wrap gap-2 justify-content-between">
    <span>Buying on behalf of <strong><?= e((string) $beneficiary['name']) ?></strong> (<?= e((string) $beneficiary['member_code']) ?>) — the order, commissions and royalty will be recorded under their account and upline.</span>
    <span>Wallet: <strong><?= money($walletBalance) ?></strong></span>
  </div>
<?php elseif ($beneficiary): ?>
  <div class="alert alert-light border small d-flex flex-wrap gap-2 justify-content-between">
    <span>Ordering for your own account. Commission is paid up your sponsor line automatically.</span>
    <span>Your wallet: <strong><?= money($walletBalance) ?></strong></span>
  </div>
<?php endif; ?>

<form method="post" id="purchaseForm">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="purchase">
  <input type="hidden" name="member_id" value="<?= (int) $forId ?>">

  <div class="panel p-3 p-md-4 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0">Select products</h2>
      <span class="small text-muted"><?= count($products) ?> available</span>
    </div>
    <?php if (!$products): ?>
      <p class="small text-muted mb-0">No products matched that search.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Product</th><th class="text-end">Price</th><th class="text-center" style="width:120px">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($products as $product): ?>
            <?php $stock = (int) $product['stock_quantity']; ?>
            <tr>
              <td>
                <div class="fw-semibold small"><?= e((string) $product['name']) ?></div>
                <div class="small text-muted">
                  <?= e((string) ($product['sku'] ?? '')) ?>
                  <?php if ($stock > 0): ?>· <?= $stock ?> in stock<?php endif; ?>
                  <?php if ((float) ($product['tax_percent'] ?? 0) > 0): ?>· GST <?= e((string) (float) $product['tax_percent']) ?>%<?php endif; ?>
                </div>
              </td>
              <td class="text-end small fw-semibold">
                <?= money((float) $product['price']) ?>
                <?php if ((float) $product['mrp'] > (float) $product['price']): ?>
                  <div class="text-muted text-decoration-line-through fw-normal"><?= money((float) $product['mrp']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <input type="number" class="form-control form-control-sm text-center qty-input"
                       name="qty[<?= (int) $product['id'] ?>]" value="" min="0" max="<?= $stock > 0 ? $stock : 999 ?>"
                       data-price="<?= e((string) (float) $product['price']) ?>"
                       data-tax="<?= e((string) (float) ($product['tax_percent'] ?? 0)) ?>"
                       placeholder="0">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel p-3 p-md-4 mb-3">
    <h2 class="h6 mb-3">Mode of payment</h2>
    <div class="row g-3">
      <?php foreach (MemberPurchaseService::PAYMENT_MODES as $key => $label): ?>
        <?php $disabled = $key === 'wallet' && $walletBalance <= 0; ?>
        <div class="col-12 col-md-6">
          <label class="d-flex gap-2 align-items-start border rounded p-2 <?= $disabled ? 'opacity-50' : '' ?>">
            <input class="form-check-input mt-1" type="radio" name="payment_mode" value="<?= e($key) ?>"
                   <?= $disabled ? 'disabled' : '' ?> <?= $key === 'upi' ? 'checked' : '' ?> required>
            <span>
              <span class="d-block small fw-semibold"><?= e($label) ?></span>
              <span class="d-block small text-muted">
                <?php if ($key === 'wallet'): ?>
                  Available: <?= money($walletBalance) ?> — debited immediately, commissions run at once.
                <?php elseif ($key === 'cod'): ?>
                  Marked unpaid; commission runs after delivery/payment confirmation.
                <?php else: ?>
                  Recorded as paid; commissions run immediately.
                <?php endif; ?>
              </span>
            </span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="panel p-3 p-md-4 mb-3">
    <h2 class="h6 mb-3">Delivery details</h2>
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label small">Name</label>
        <input class="form-control form-control-sm" name="customer_name" value="<?= e((string) ($beneficiary['name'] ?? '')) ?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label small">Mobile</label>
        <input class="form-control form-control-sm" name="customer_mobile" value="<?= e((string) ($beneficiary['mobile'] ?? '')) ?>">
      </div>
      <div class="col-12">
        <label class="form-label small">Address</label>
        <input class="form-control form-control-sm" name="address_line1" value="<?= e((string) ($beneficiary['address'] ?? '')) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small">City</label>
        <input class="form-control form-control-sm" name="city" value="">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small">State</label>
        <input class="form-control form-control-sm" name="state" value="<?= e((string) ($beneficiary['state'] ?? '')) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small">Pincode</label>
        <input class="form-control form-control-sm" name="pincode" value="">
      </div>
      <div class="col-12">
        <label class="form-label small">Note (optional)</label>
        <input class="form-control form-control-sm" name="notes" value="">
      </div>
    </div>
  </div>

  <div class="panel p-3 p-md-4 mb-3">
    <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
      <div class="small">
        <div>Subtotal: <strong data-summary="subtotal"><?= money(0) ?></strong></div>
        <div>Tax: <strong data-summary="tax"><?= money(0) ?></strong></div>
        <div>Shipping: <strong data-summary="shipping"><?= money(0) ?></strong></div>
        <div class="fs-6">Total: <strong data-summary="total"><?= money(0) ?></strong></div>
      </div>
      <button class="btn btn-primary" <?= $products ? '' : 'disabled' ?>>Record purchase</button>
    </div>
  </div>
</form>

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
    out.subtotal.textContent = fmt(subtotal);
    out.tax.textContent = fmt(tax);
    out.shipping.textContent = fmt(shipping);
    out.total.textContent = fmt(subtotal + tax + shipping);
  }
  inputs.forEach(function (input) { input.addEventListener('input', recalc); });
  recalc();
})();
</script>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
