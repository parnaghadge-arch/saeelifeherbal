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
            'district' => (string) Request::input('district', ''),
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
// Everything recorded about the member, so the operator can see who they are buying for before
// they commit the order. Address fields come from member_profiles and are used to pre-fill the
// delivery block below.
$beneficiaryProfile = $beneficiary
    ? ($db->first('SELECT * FROM member_profiles WHERE member_id = :id', ['id' => $memberId]) ?? [])
    : [];
$beneficiarySponsor = ($beneficiary && !empty($beneficiary['sponsor_id']))
    ? $db->first('SELECT name, member_code FROM members WHERE id = :id', ['id' => (int) $beneficiary['sponsor_id']])
    : null;
$beneficiaryKyc = $beneficiary
    ? $db->first('SELECT status FROM member_kyc WHERE member_id = :id', ['id' => $memberId])
    : null;
$beneficiaryBank = $beneficiary
    ? $db->first('SELECT account_number, ifsc_code, bank_name, status FROM member_bank_accounts WHERE member_id = :id ORDER BY is_primary DESC, id DESC LIMIT 1', ['id' => $memberId])
    : null;
// Fall back through profile -> member row for each address part.
$delivery = [
    'name' => (string) ($beneficiary['name'] ?? ''),
    'mobile' => (string) ($beneficiary['mobile'] ?? ''),
    'address_line1' => (string) ($beneficiaryProfile['address'] ?? $beneficiary['address'] ?? ''),
    'city' => (string) ($beneficiaryProfile['taluka'] ?? ''),
    'district' => (string) ($beneficiaryProfile['district'] ?? ''),
    'state' => (string) ($beneficiaryProfile['state'] ?? $beneficiary['state'] ?? ''),
    'pincode' => (string) ($beneficiaryProfile['pincode'] ?? $beneficiary['pincode'] ?? ''),
];
$products = $service->products($search);
$productTotal = $service->activeProductCount();
$walletBalance = $memberId > 0 ? $service->walletBalance($memberId) : 0.0;

$pageTitle = 'Record Purchase';
$pageHeading = 'Record Purchase for a Member';
$pageSub = 'Buy on behalf of any member — commissions and payout rules are applied automatically';
$activeModule = 'purchase';
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
  <div class="panel p-3 p-md-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h2 class="h6 mb-1">
          <?= e((string) $beneficiary['name']) ?>
          <span class="text-muted fw-normal">(<?= e((string) $beneficiary['member_code']) ?>)</span>
        </h2>
        <div class="small text-muted">Recording a purchase for this member — their sponsor line receives the commission.</div>
      </div>
      <div class="text-end">
        <div class="small text-muted">Wallet</div>
        <div class="fw-semibold"><?= money($walletBalance) ?></div>
      </div>
    </div>
    <div class="row g-3 small">
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Mobile</div>
        <div class="fw-semibold"><?= e((string) ($beneficiary['mobile'] ?? '—')) ?></div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Email</div>
        <div class="fw-semibold text-break"><?= e((string) ($beneficiary['email'] ?? '')) !== '' ? e((string) $beneficiary['email']) : '—' ?></div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Status</div>
        <div class="fw-semibold">
          <?= e(ucfirst((string) ($beneficiary['status'] ?? ''))) ?>
          <?php if ($beneficiaryKyc): ?>
            · KYC <?= e((string) ($beneficiaryKyc['status'] ?? '')) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Joined</div>
        <div class="fw-semibold"><?= e(format_date((string) ($beneficiary['joined_at'] ?? $beneficiary['created_at'] ?? ''), 'd M Y')) ?></div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Sponsor</div>
        <div class="fw-semibold">
          <?php if ($beneficiarySponsor): ?>
            <?= e((string) $beneficiarySponsor['name']) ?><br>
            <span class="text-muted fw-normal"><?= e((string) $beneficiarySponsor['member_code']) ?></span>
          <?php else: ?>
            <span class="text-muted">No sponsor (tree root)</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Role</div>
        <div class="fw-semibold"><?= e(ucfirst((string) ($beneficiary['role'] ?? '—'))) ?></div>
      </div>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="text-muted">Bank on file</div>
        <div class="fw-semibold">
          <?php if ($beneficiaryBank): ?>
            <?= e((string) ($beneficiaryBank['bank_name'] ?? '—')) ?>
            <span class="text-muted fw-normal"><?= e(Security::maskAccount((string) (Security::decrypt((string) ($beneficiaryBank['account_number'] ?? '')) ?? ''))) ?></span>
            <?= (string) ($beneficiaryBank['status'] ?? '') === 'verified' ? '<span class="badge bg-success">Verified</span>' : '<span class="badge bg-secondary">' . e(ucfirst((string) ($beneficiaryBank['status'] ?? 'pending'))) . '</span>' ?>
          <?php else: ?>
            <span class="text-muted">None</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="text-muted">Delivery address</div>
        <div class="fw-semibold">
          <?php
            $addressParts = array_filter([
                $beneficiaryProfile['address'] ?? '',
                $beneficiaryProfile['village'] ?? '',
                $beneficiaryProfile['taluka'] ?? '',
                $beneficiaryProfile['district'] ?? '',
                $beneficiaryProfile['state'] ?? '',
                $beneficiaryProfile['pincode'] ?? '',
            ], static fn ($v): bool => trim((string) $v) !== '');
          ?>
          <?= $addressParts !== [] ? e(implode(', ', array_map('strval', $addressParts))) : '<span class="text-muted fw-normal">Not on file — fill it in below</span>' ?>
        </div>
      </div>
    </div>
  </div>

  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="purchase">
    <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">

    <?php
      // Steps 1 and 2 (browse, then the chosen items) come from the shared picker, so both
      // desks stay usable when the catalogue grows.
      $ppProducts = $products;
      $ppSearch = $search;
      $ppTotalAll = $productTotal;
      require INCLUDES_PATH . '/purchase-picker.php';
    ?>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3"><span class="pp-step">3</span> Mode of payment</h2>
          <?php
          // Only the methods switched on under Payment Methods. The default used to be
          // hard-coded to UPI, which selected nothing at all once UPI was turned off.
          $ppModes = $service->availableModes();
          $ppDefault = array_key_exists(PAYMENT_METHOD_DEFAULT, $ppModes)
              ? PAYMENT_METHOD_DEFAULT
              : (string) (array_key_first($ppModes) ?? '');
          ?>
          <?php if ($ppModes === []): ?>
            <p class="small text-muted mb-0">No mode of payment is enabled. Turn one on under Payment Methods first.</p>
          <?php endif; ?>
          <?php foreach ($ppModes as $key => $label): ?>
            <?php $disabled = $key === 'wallet' && $walletBalance <= 0; ?>
            <div class="form-check mb-2 <?= $disabled ? 'opacity-50' : '' ?>">
              <input class="form-check-input" type="radio" name="payment_mode" id="pm_<?= e($key) ?>" value="<?= e($key) ?>"
                     <?= $disabled ? 'disabled' : '' ?> <?= ($key === $ppDefault && !$disabled) ? 'checked' : '' ?> required>
              <label class="form-check-label small" for="pm_<?= e($key) ?>"><?= e($label) ?>
                <?php if ($key === 'wallet'): ?>
                  <span class="text-muted">(<?= money($walletBalance) ?> available)</span>
                <?php endif; ?>
              </label>
            </div>
          <?php endforeach; ?>
          <p class="small text-muted mb-0">Wallet, UPI and bank transfer are recorded as paid <em>and approved</em> straight away, so commission runs. Cash on delivery waits until the order is approved or delivered.</p>
        </div>
      </div>
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3"><span class="pp-step">4</span> Delivery details</h2>
          <div class="row g-2">
            <div class="col-12"><label class="form-label small mb-1">Name</label>
              <input class="form-control form-control-sm" name="customer_name" value="<?= e($delivery['name']) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Mobile</label>
              <input class="form-control form-control-sm" name="customer_mobile" value="<?= e($delivery['mobile']) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Address</label>
              <input class="form-control form-control-sm" name="address_line1" value="<?= e($delivery['address_line1']) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">City / Taluka</label>
              <input class="form-control form-control-sm" name="city" value="<?= e($delivery['city']) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">Pincode</label>
              <input class="form-control form-control-sm" name="pincode" value="<?= e($delivery['pincode']) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">District</label>
              <input class="form-control form-control-sm" name="district" value="<?= e($delivery['district']) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">State</label>
              <input class="form-control form-control-sm" name="state" value="<?= e($delivery['state']) ?>"></div>
            <div class="col-12"><label class="form-label small mb-1">Internal note</label>
              <input class="form-control form-control-sm" name="notes" value=""></div>
          </div>
        </div>
      </div>
    </div>

    <?php $ppSubmitLabel = 'Record purchase'; require INCLUDES_PATH . '/purchase-totals.php'; ?>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-end.php'; ?>
