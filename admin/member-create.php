<?php
/**
 * Create a member, optionally with an opening product purchase, optionally with no sponsor.
 *
 * Two things this screen does that the public registration form must never do:
 *   1. leave the sponsor blank, which starts a brand-new tree (the member becomes a root);
 *   2. record an opening purchase in the new member's name, so commissions, the wallet and
 *      royalty are calculated by the same engine as any other order.
 */
declare(strict_types=1);

require __DIR__ . '/includes/_guard.php';
admin_require('members.manage');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\MemberPurchaseService;
use Saee\Lib\MemberService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$service = new MemberService($db);
$purchaseService = new MemberPurchaseService($db);

$errors = [];
$old = [
    'name' => '', 'mobile' => '', 'email' => '', 'role' => 'distributor',
    'sponsor_code' => '', 'state' => '', 'district' => '', 'taluka' => '',
    'village' => '', 'pincode' => '', 'address' => '', 'admin_note' => '',
    'payment_mode' => '', 'no_sponsor' => '',
];
$welcome = [];
$orderResult = null;
$postedQty = [];

if (is_post()) {
    Security::verifyCsrf();
    $old = array_map(static fn ($v): string => is_string($v) ? trim($v) : '', array_merge($old, (array) $_POST));
    $postedQty = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $noSponsor = !empty($_POST['no_sponsor']);
    $sponsorCode = $noSponsor ? '' : trim((string) Request::input('sponsor_code', ''));
    $password = (string) Request::raw('password');

    // ---- validation ----------------------------------------------------------------
    if (mb_strlen($old['name']) < 3) {
        $errors['name'] = ['Enter the member\'s full name (at least 3 characters).'];
    }
    $mobile = preg_replace('/\D/', '', $old['mobile']) ?? '';
    $mobile = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
    if (strlen($mobile) !== 10) {
        $errors['mobile'] = ['Enter a valid 10-digit mobile number.'];
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = ['Enter a valid email address, or leave it blank.'];
    }
    if (strlen($password) < 8) {
        $errors['password'] = ['Set a password of at least 8 characters.'];
    }
    if (!in_array($old['role'], ['farmer', 'distributor', 'retailer'], true)) {
        $old['role'] = 'distributor';
    }
    if (!$noSponsor && $sponsorCode === '') {
        $errors['sponsor_code'] = ['Enter a sponsor ID, or tick "no sponsor" to start a new tree.'];
    }
    if (!$noSponsor && $sponsorCode !== '' && $service->resolveSponsor($sponsorCode) === null) {
        $errors['sponsor_code'] = ['No member exists with that ID, referral code, mobile or email.'];
    }

    // ---- optional opening purchase -------------------------------------------------
    $lines = [];
    foreach ((array) Request::input('qty', []) as $productId => $qty) {
        if ((int) $qty > 0) {
            $lines[(int) $productId] = (int) $qty;
        }
    }
    $wantsPurchase = $lines !== [];
    if ($wantsPurchase && !array_key_exists($old['payment_mode'], MemberPurchaseService::PAYMENT_MODES)) {
        $errors['payment_mode'] = ['Choose how the opening purchase was paid for.'];
    }

    if ($errors === []) {
        $result = $service->createWithPurchase(
            [
                'name' => $old['name'],
                'mobile' => $mobile,
                'email' => $old['email'],
                'password' => $password,
                'role' => $old['role'],
                'sponsor_code' => $sponsorCode,
                'allow_root' => $noSponsor,
                'address' => $old['address'],
                'state' => $old['state'],
                'district' => $old['district'],
                'taluka' => $old['taluka'],
                'village' => $old['village'],
                'pincode' => $old['pincode'],
            ],
            $lines,
            [
                'payment_mode' => $old['payment_mode'],
                'customer_name' => $old['name'],
                'customer_mobile' => $mobile,
                'address_line1' => $old['address'],
                'state' => $old['state'],
                'pincode' => $old['pincode'],
                'notes' => $old['admin_note'],
            ],
            ['source' => 'admin', 'ip' => client_ip()]
        );

        if (!empty($result['ok'])) {
            $member = (array) $result['member'];
            $welcome = (array) ($result['welcome'] ?? []);
            $welcomeContext = 'admin';
            $orderResult = $result['purchase'] ?? null;
            if ($old['admin_note'] !== '') {
                $db->update('members', ['admin_note' => $old['admin_note']], 'id = :id', ['id' => (int) $member['id']]);
            }
            Audit::log(
                'member_create',
                'members',
                'Created member ' . $member['member_code']
                    . ($noSponsor ? ' with no sponsor (new tree root)' : ' under ' . $sponsorCode)
                    . ($orderResult ? ' with an opening purchase' : ''),
                'members',
                (int) $member['id']
            );
            Session::success((string) $result['message']);
            // Re-render with the credentials card instead of redirecting, so the plain password
            // and share link are visible once. $old is cleared so the form is empty for the next.
            $old = array_map(static fn (): string => '', $old);
            $postedQty = [];
        } else {
            $errors['general'] = [(string) ($result['message'] ?? 'The member could not be created.')];
            foreach ((array) ($result['errors'] ?? []) as $field => $messages) {
                $errors[$field] = (array) $messages;
            }
        }
    } else {
        $errors['general'] = ['Please correct the highlighted fields.'];
    }
}

$products = $db->select(
    "SELECT id, name, sku, price, stock_quantity FROM products
      WHERE status = 'active' ORDER BY name ASC LIMIT 300"
);
$companySponsor = $service->companySponsor();
$modeLabels = MemberPurchaseService::PAYMENT_MODES;

$pageTitle = 'Add Member';
$pageHeading = 'Add Member';
$pageSub = 'Create a partner account, with or without a sponsor, and optionally record their first purchase.';
$activeModule = 'members';
require __DIR__ . '/includes/layout.php';
?>
<?php if (!empty($errors['general'])): ?>
  <div class="alert alert-danger"><?= e((string) $errors['general'][0]) ?></div>
<?php endif; ?>

<?php if ($welcome !== []): ?>
  <div class="panel p-4 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="welcome-tick"><i class="fa-solid fa-check"></i></div>
      <div class="flex-grow-1">
        <h2 class="h6 mb-1">Member created &mdash; <?= e((string) ($welcome['member_code'] ?? '')) ?></h2>
        <p class="small text-muted mb-0">
          <?= e((string) ($welcome['name'] ?? '')) ?>
          <?php if (!empty($welcome['root_of_own_tree'])): ?>
            &middot; <span class="text-success-emphasis">starts a new tree (no sponsor)</span>
          <?php elseif (($welcome['sponsor_code'] ?? '') !== ''): ?>
            &middot; sponsor <?= e((string) $welcome['sponsor_name']) ?> (<?= e((string) $welcome['sponsor_code']) ?>)
          <?php endif; ?>
          <?php if ($orderResult): ?>
            &middot; opening purchase: <?= e((string) ($orderResult['message'] ?? 'recorded')) ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('memberWelcomeCard')).show()">
          <i class="fa-solid fa-id-card me-1"></i>Show login details
        </button>
        <a class="btn btn-sm btn-outline-secondary" href="member-details.php?id=<?= (int) ($welcome['member_id'] ?? 0) ?>">
          Open member <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
        <a class="btn btn-sm btn-primary" href="members.php">All members</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="panel p-3 p-md-4">
  <form method="post" novalidate>
    <?= csrf_field() ?>

    <h2 class="h6 text-uppercase text-muted mb-3" style="letter-spacing:.08em">1 · Member details</h2>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="name">Full name <span class="required-mark">*</span></label>
        <input id="name" name="name" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" value="<?= e($old['name']) ?>" required>
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name'][0]) ?></div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="mobile">Mobile number <span class="required-mark">*</span></label>
        <input id="mobile" name="mobile" inputmode="numeric" maxlength="10" class="form-control<?= isset($errors['mobile']) ? ' is-invalid' : '' ?>" value="<?= e($old['mobile']) ?>" required>
        <?php if (isset($errors['mobile'])): ?><div class="invalid-feedback d-block"><?= e($errors['mobile'][0]) ?></div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="email">Email (optional)</label>
        <input id="email" name="email" type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" value="<?= e($old['email']) ?>">
        <?php if (isset($errors['email'])): ?><div class="invalid-feedback d-block"><?= e($errors['email'][0]) ?></div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="role">Member type</label>
        <select id="role" name="role" class="form-select">
          <?php foreach (['farmer' => 'Farmer', 'distributor' => 'Distributor', 'retailer' => 'Retailer'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $old['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="password">Password to give the member <span class="required-mark">*</span></label>
        <input id="password" name="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" value="<?= e((string) Request::raw('password')) ?>" autocomplete="new-password" required>
        <div class="form-text">At least 8 characters. It is shown once, on the details card.</div>
        <?php if (isset($errors['password'])): ?><div class="invalid-feedback d-block"><?= e($errors['password'][0]) ?></div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="sponsor_code">Sponsor ID <span class="required-mark sponsor-required">*</span></label>
        <input id="sponsor_code" name="sponsor_code" class="form-control<?= isset($errors['sponsor_code']) ? ' is-invalid' : '' ?>" value="<?= e($old['sponsor_code']) ?>" placeholder="e.g. <?= e((string) ($companySponsor['member_code'] ?? 'SAEE00001')) ?>">
        <div class="form-text">Member ID, referral code, mobile or email of the upline.</div>
        <?php if (isset($errors['sponsor_code'])): ?><div class="invalid-feedback d-block"><?= e($errors['sponsor_code'][0]) ?></div><?php endif; ?>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="no_sponsor" id="no_sponsor" value="1" <?= $old['no_sponsor'] !== '' ? 'checked' : '' ?>>
          <label class="form-check-label small" for="no_sponsor">
            <strong>No sponsor</strong> &mdash; start a new tree with this member at the top
          </label>
        </div>
      </div>
    </div>

    <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.08em">2 · Address (optional)</h2>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="address">Address</label><input id="address" name="address" class="form-control" value="<?= e($old['address']) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="village">Village / Town</label><input id="village" name="village" class="form-control" value="<?= e($old['village']) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="taluka">Taluka</label><input id="taluka" name="taluka" class="form-control" value="<?= e($old['taluka']) ?>"></div>
      <div class="col-md-4"><label class="form-label" for="district">District</label><input id="district" name="district" class="form-control" value="<?= e($old['district']) ?>"></div>
      <div class="col-md-4"><label class="form-label" for="state">State</label><input id="state" name="state" class="form-control" value="<?= e($old['state']) ?>"></div>
      <div class="col-md-4"><label class="form-label" for="pincode">PIN code</label><input id="pincode" name="pincode" inputmode="numeric" maxlength="6" class="form-control" value="<?= e($old['pincode']) ?>"></div>
    </div>

    <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.08em">3 · Opening purchase (optional)</h2>
    <p class="small text-muted">
      Add products to buy in this member's name. Commissions, wallet and royalty are calculated by
      the same rules as any other order. Leave every quantity empty to create the member only.
    </p>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>Product</th><th class="text-end">Price</th><th class="text-end">Stock</th><th style="width:110px">Qty</th><th class="text-end">Line total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $product): ?>
          <?php $pid = (int) $product['id']; ?>
          <tr>
            <td class="small">
              <div class="fw-semibold"><?= e((string) $product['name']) ?></div>
              <div class="text-muted"><?= e((string) ($product['sku'] ?? '')) ?></div>
            </td>
            <td class="text-end small"><?= money((float) $product['price']) ?></td>
            <td class="text-end small <?= (int) $product['stock_quantity'] <= 0 ? 'text-danger' : 'text-muted' ?>"><?= (int) $product['stock_quantity'] ?></td>
            <td>
              <input type="number" min="0" step="1" class="form-control form-control-sm purchase-qty"
                     name="qty[<?= $pid ?>]" value="<?= e((string) ($postedQty[$pid] ?? '')) ?>"
                     data-price="<?= e((string) (float) $product['price']) ?>" inputmode="numeric">
            </td>
            <td class="text-end small line-total">&mdash;</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?>
          <tr><td colspan="5" class="small text-muted">No active products yet.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="4" class="text-end small">Basket subtotal (excl. tax &amp; shipping)</th>
            <th class="text-end" id="basketTotal">&mdash;</th>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-6">
        <label class="form-label" for="payment_mode">Mode of payment <span class="required-mark payment-required">*</span></label>
        <select id="payment_mode" name="payment_mode" class="form-select<?= isset($errors['payment_mode']) ? ' is-invalid' : '' ?>">
          <option value="">Choose&hellip;</option>
          <?php foreach ($modeLabels as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $old['payment_mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['payment_mode'])): ?><div class="invalid-feedback d-block"><?= e($errors['payment_mode'][0]) ?></div><?php endif; ?>
        <div class="form-text">Required when a product quantity is entered.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="admin_note">Internal note (optional)</label>
        <input id="admin_note" name="admin_note" class="form-control" value="<?= e($old['admin_note']) ?>">
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus me-1"></i>Create member</button>
      <a class="btn btn-outline-secondary" href="members.php">Cancel</a>
    </div>
  </form>
</div>

<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.purchase-qty'));
  var totalEl = document.getElementById('basketTotal');

  function recalc() {
    var subtotal = 0;
    rows.forEach(function (input) {
      var qty = parseInt(input.value, 10) || 0;
      var price = parseFloat(input.getAttribute('data-price')) || 0;
      var cell = input.closest('tr').querySelector('.line-total');
      if (qty > 0) {
        subtotal += qty * price;
        if (cell) { cell.textContent = '₹' + (qty * price).toFixed(2); }
      } else if (cell) {
        cell.innerHTML = '&mdash;';
      }
    });
    if (totalEl) { totalEl.textContent = subtotal > 0 ? '₹' + subtotal.toFixed(2) : '—'; }
    // Mode of payment is only mandatory when something is being bought.
    var required = subtotal > 0;
    document.querySelectorAll('.payment-required').forEach(function (el) {
      el.style.visibility = required ? 'visible' : 'hidden';
    });
  }

  rows.forEach(function (input) { input.addEventListener('input', recalc); });
  recalc();

  var noSponsor = document.getElementById('no_sponsor');
  var sponsor = document.getElementById('sponsor_code');
  if (noSponsor && sponsor) {
    var sync = function () {
      sponsor.disabled = noSponsor.checked;
      if (noSponsor.checked) { sponsor.value = ''; }
      document.querySelectorAll('.sponsor-required').forEach(function (el) {
        el.style.visibility = noSponsor.checked ? 'hidden' : 'visible';
      });
    };
    noSponsor.addEventListener('change', sync);
    sync();
  }
})();
</script>

<?php if ($welcome !== []): ?>
  <?php require INCLUDES_PATH . '/member-welcome-card.php'; ?>
  <style>
    .welcome-tick{width:56px;height:56px;border-radius:50%;background:#E8F5E9;color:#14532D;display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 auto}
  </style>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
