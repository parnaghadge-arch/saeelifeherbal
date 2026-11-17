<?php
/**
 * Create a member, optionally with an opening product purchase.
 *
 * Sponsor is optional: leaving it blank attaches the member to the configured company sponsor
 * (SAEE00003 by default) rather than starting a separate tree, which is the same rule the public
 * registration form follows. Nobody ends up parentless, so commission always has an upline to
 * walk.
 *
 * The one thing this screen does that public registration must never do is record an opening
 * purchase in the new member's name, so commissions, the wallet and royalty are calculated by the
 * same engine as any other order.
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

// Resolved before the POST handler: the audit line names the fallback sponsor when the admin
// leaves the field blank, so it has to exist on the very first request.
$companySponsor = $service->companySponsor();
$fallbackSponsorCode = (string) ($companySponsor['member_code'] ?? 'SAEE00003');

// Only the methods switched on under Payment Methods, so the opening purchase cannot be
// recorded against a method the business has turned off. Resolved BEFORE the POST handler,
// which validates the posted payment mode against this list.
$modeLabels = $purchaseService->availableModes();

$errors = [];
$old = [
    'name' => '', 'mobile' => '', 'email' => '', 'role' => 'distributor',
    'sponsor_code' => '', 'state' => '', 'district' => '', 'taluka' => '',
    'village' => '', 'pincode' => '', 'address' => '', 'admin_note' => '',
    'payment_mode' => '',
];
$welcome = [];
$orderResult = null;
$postedQty = [];

if (is_post()) {
    Security::verifyCsrf();
    $old = array_map(static fn ($v): string => is_string($v) ? trim($v) : '', array_merge($old, (array) $_POST));
    $postedQty = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $sponsorCode = trim((string) Request::input('sponsor_code', ''));

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
    if (!in_array($old['role'], ['farmer', 'distributor', 'retailer'], true)) {
        $old['role'] = 'distributor';
    }
    // A blank sponsor is not an error: MemberService falls back to the company sponsor.
    if ($sponsorCode !== '' && $service->resolveSponsor($sponsorCode) === null) {
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
    if ($wantsPurchase && !array_key_exists($old['payment_mode'], $modeLabels)) {
        $errors['payment_mode'] = $old['payment_mode'] === ''
            ? ['Choose how the opening purchase was paid for.']
            : ['That mode of payment is not enabled. Choose another, or turn it on under Payment Methods.'];
    }

    if ($errors === []) {
        $result = $service->createWithPurchase(
            [
                'name' => $old['name'],
                'mobile' => $mobile,
                'email' => $old['email'],
                'role' => $old['role'],
                'sponsor_code' => $sponsorCode,
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
                    . ' under ' . ($sponsorCode !== '' ? $sponsorCode : $fallbackSponsorCode)
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

// Same catalogue rows as the Record Purchase desk, so the shared picker can show prices,
// stock, GST and category exactly the same way on both screens.
$products = $purchaseService->products();
$productTotal = $purchaseService->activeProductCount();

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
          <?php if (($welcome['sponsor_code'] ?? '') !== ''): ?>
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
        <label class="form-label">Password</label>
        <div class="form-control bg-light border-0 small text-muted">
          Generated automatically — it is the same as the Member ID.
        </div>
        <div class="form-text">Shown once, on the details card after saving.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="sponsor_code">Sponsor ID <span class="text-muted fw-normal">(optional)</span></label>
        <input id="sponsor_code" name="sponsor_code" class="form-control<?= isset($errors['sponsor_code']) ? ' is-invalid' : '' ?>" value="<?= e($old['sponsor_code']) ?>" placeholder="e.g. <?= e((string) ($companySponsor['member_code'] ?? 'SAEE00003')) ?>">
        <div class="form-text">
          Member ID, referral code, mobile or email of the upline.
          <strong>Leave it blank and the member is placed under <?= e((string) ($companySponsor['member_code'] ?? 'SAEE00003')) ?></strong>
          &mdash; the company sponsor &mdash; so commission still has an upline to pay.
        </div>
        <?php if (isset($errors['sponsor_code'])): ?><div class="invalid-feedback d-block"><?= e($errors['sponsor_code'][0]) ?></div><?php endif; ?>
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
      the same rules as any other order. Leave the basket empty to create the member only.
    </p>
    <?php
      // Steps 1 and 2 (browse, then the chosen items) come from the shared picker - the same
      // "add products" experience as the Record Purchase desk. It fills the same qty[<id>]
      // inputs, so the backend contract is unchanged.
      $ppProducts = $products;
      $ppSearch = '';
      $ppTotalAll = $productTotal;
      require INCLUDES_PATH . '/purchase-picker.php';
    ?>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3"><span class="pp-step">3</span> Mode of payment</h2>
          <?php if ($modeLabels === []): ?>
            <p class="small text-muted mb-0">No mode of payment is enabled. Turn one on under Payment Methods first.</p>
          <?php endif; ?>
          <?php foreach ($modeLabels as $value => $label): ?>
            <?php $walletUnavailable = $value === 'wallet'; ?>
            <div class="form-check mb-2 <?= $walletUnavailable ? 'opacity-50' : '' ?>">
              <input class="form-check-input" type="radio" name="payment_mode" id="pm_<?= e($value) ?>" value="<?= e($value) ?>"
                     <?= $walletUnavailable ? 'disabled' : '' ?> <?= ($old['payment_mode'] === $value && !$walletUnavailable) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="pm_<?= e($value) ?>"><?= e($label) ?>
                <?php if ($walletUnavailable): ?><span class="text-muted">(a new member has no wallet balance)</span><?php endif; ?>
              </label>
            </div>
          <?php endforeach; ?>
          <?php if (isset($errors['payment_mode'])): ?><div class="invalid-feedback d-block"><?= e($errors['payment_mode'][0]) ?></div><?php endif; ?>
          <p class="small text-muted mb-0">Required only when products are added. Wallet, UPI and bank transfer are recorded as paid <em>and approved</em> straight away, so commission runs. Cash on delivery waits until the order is approved or delivered.</p>
        </div>
      </div>
      <div class="col-12 col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3"><span class="pp-step">4</span> Internal note</h2>
          <label class="form-label small mb-1" for="admin_note">Note (optional)</label>
          <input id="admin_note" name="admin_note" class="form-control" value="<?= e($old['admin_note']) ?>">
          <p class="small text-muted mt-2 mb-0">Kept on the member record for admins only.</p>
        </div>
      </div>
    </div>

    <?php // Totals bar with the same live figures as Record Purchase. Unlike that desk, the
      // submit button stays enabled with an empty basket: the opening purchase is optional.
    ?>
    <div class="panel p-3 p-md-4 mb-3 pp-totalbar">
      <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div class="small pp-totals">
          <div>Subtotal <strong data-summary="subtotal"><?= money(0) ?></strong></div>
          <div>Tax <strong data-summary="tax"><?= money(0) ?></strong></div>
          <div>Shipping <strong data-summary="shipping"><?= money(0) ?></strong></div>
          <div class="fs-6">Total <strong data-summary="total"><?= money(0) ?></strong></div>
        </div>
        <div class="text-end">
          <div class="small text-muted mb-1">With an empty basket the member is created without an opening purchase</div>
          <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a class="btn btn-outline-secondary" href="members.php">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg px-4"><i class="fa-solid fa-user-plus me-1"></i>Create member</button>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
// Restore the basket after a validation-error re-render, and stop Enter inside the picker's
// filter box from submitting the form (the create button is always enabled, unlike the Record
// Purchase desk whose submit stays disabled until items are chosen).
window.ppInitialLines = <?= json_encode((object) array_map('intval', array_filter($postedQty, static fn ($q): bool => (int) $q > 0))) ?>;
document.addEventListener('DOMContentLoaded', function () {
  var filter = document.getElementById('ppFilter');
  if (filter) {
    filter.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); }
    });
  }
});
</script>

<?php if ($welcome !== []): ?>
  <?php require INCLUDES_PATH . '/member-welcome-card.php'; ?>
  <style>
    .welcome-tick{width:56px;height:56px;border-radius:50%;background:#E8F5E9;color:#14532D;display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 auto}
  </style>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
