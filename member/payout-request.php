<?php
/**
 * Early / manual payout (optional). Regular payouts are automatic — earnings at
 * the minimum are added to the report without any request. This page is only
 * for members who want a partial/early manual payout before the auto cycle.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\PayoutService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$payoutService = new PayoutService(db());
$eligibility = $payoutService->eligibility($currentMemberId);
$settings = $payoutService->settings();
$calc = null;
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('amount', 'Amount')->required('amount')->numeric('amount')->minValue('amount', 1);
    $v->label('note', 'Note')->max('note', 255);
    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        $amount = (float) $v->value('amount');
        $result = $payoutService->request($currentMemberId, $amount, (string) Request::input('note', ''));
        if ($result['ok']) {
            Session::success($result['message']);
            Request::redirect('payouts.php');
        }
        $errors['amount'] = [$result['message']];
    }
}
$previewAmount = (float) (Request::input('amount') ?: ($eligibility['available'] ?? 0));
if ($previewAmount > 0) {
    $calc = $payoutService->computeDeductions($previewAmount);
}
$pendingRequest = db()->first(
    "SELECT * FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved','processing') ORDER BY id DESC LIMIT 1",
    ['m' => $currentMemberId]
);

$pageTitle = 'Early Payout';
$pageHeading = 'Early Payout';
$pageSub = 'Regular payouts are automatic — this is only for a partial / early manual payout';
$activeNav = 'payout-request';
require __DIR__ . '/includes/layout.php';
?>
<div class="alert alert-success small d-flex align-items-start gap-2">
  <i class="fa-solid fa-wand-magic-sparkles mt-1"></i>
  <div>
    <strong>You do not need to request a payout.</strong> Earnings at/above <?= money((float) $settings['min_withdrawal_amount']) ?> are added to the payout report automatically.
    Use this form only if you want a <strong>partial or early</strong> manual payout. Track every payout in your
    <a href="<?= e(Url::member('payouts.php')) ?>">Payout Statement</a>.
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <div class="small text-muted">Available to withdraw</div>
          <div class="h3 mb-0"><?= money((float) $eligibility['available']) ?></div>
        </div>
        <span class="badge bg-light text-dark">Min <?= money((float) $settings['min_withdrawal_amount']) ?></span>
      </div>

      <?php if ($pendingRequest): ?>
        <div class="alert alert-info small mb-3">
          Your payout <strong><?= e((string) $pendingRequest['request_code']) ?></strong> for <?= money((float) $pendingRequest['amount']) ?> is <?= (string) $pendingRequest['status'] === 'processing' ? 'In Progress' : e((string) $pendingRequest['status']) ?>.
          You can submit a new manual request once it is settled. See <a href="<?= e(Url::member('payouts.php')) ?>">Payout Statement</a>.
        </div>
      <?php endif; ?>

      <?php if (!empty($eligibility['blockers'])): ?>
        <div class="alert alert-warning small">
          <strong class="d-block mb-1">Before payouts can be released:</strong>
          <ul class="mb-0 ps-3">
            <?php foreach ($eligibility['blockers'] as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?>
          </ul>
          <div class="mt-2 d-flex gap-2">
            <a class="btn btn-sm btn-dark" href="<?= e(Url::member('kyc.php')) ?>">My KYC</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('bank.php')) ?>">Bank details</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['amount'])): ?><div class="alert alert-danger small"><?= e($errors['amount'][0]) ?></div><?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="amount">Amount (<?= e(CURRENCY_SYMBOL) ?>)</label>
          <input id="amount" name="amount" type="number" step="0.01" min="<?= e((string) $settings['min_withdrawal_amount']) ?>" max="<?= e((string) $settings['max_withdrawal_amount']) ?>" class="form-control form-control-lg<?= invalid_class($errors, 'amount') ?>" value="<?= old_input('amount', (string) $eligibility['available']) ?>" data-payout-amount>
          <div class="form-text">Maximum <?= money((float) $settings['max_withdrawal_amount']) ?> per request.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="note">Note to accounts team (optional)</label>
          <textarea id="note" name="note" class="form-control" rows="2" maxlength="255"><?= old_input('note') ?></textarea>
        </div>
        <div class="deduction-box small mb-3">
          <div class="d-flex justify-content-between"><span>Gross amount</span><strong data-calc="gross"><?= money((float) ($calc['gross'] ?? 0)) ?></strong></div>
          <div class="d-flex justify-content-between"><span>Admin charges (<?= $settings['withdrawal_fee_type'] === 'percent' ? (float) $settings['withdrawal_fee_value'] . '%' : money((float) $settings['withdrawal_fee_value']) ?>)</span><strong data-calc="fee">−<?= money((float) ($calc['admin_charges'] ?? 0)) ?></strong></div>
          <div class="d-flex justify-content-between"><span>TDS (<?= (float) $settings['tds_percent'] ?>%)</span><strong data-calc="tds">−<?= money((float) ($calc['tds'] ?? 0)) ?></strong></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between"><span>Net payable</span><strong class="text-success" data-calc="net"><?= money((float) ($calc['net'] ?? 0)) ?></strong></div>
          <div class="text-muted mt-2">Processed within <?= (int) $settings['processing_days'] ?> working day(s) to your primary bank account.</div>
        </div>
        <button class="btn btn-primary w-100" type="submit" <?= !$eligibility['eligible'] ? 'disabled' : '' ?>>Submit early payout request</button>
        <div class="form-text text-center mt-2">Regular payouts do not need this form — they are automatic.</div>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Payout bank account</h2>
      <?php $bank = $eligibility['bank']; ?>
      <?php if (!$bank): ?>
        <p class="small text-muted">No bank account added yet.</p>
        <a class="btn btn-sm btn-primary" href="<?= e(Url::member('bank.php')) ?>">Add bank details</a>
      <?php else: ?>
        <div class="small">
          <div class="fw-semibold"><?= e((string) $bank['account_holder_name']) ?></div>
          <div class="text-muted"><?= e((string) $bank['bank_name']) ?> · <?= e(Security::maskAccount((string) $bank['account_number'])) ?></div>
          <div class="text-muted">IFSC <?= e((string) $bank['ifsc_code']) ?></div>
          <div class="mt-2">Verification <?= status_badge((string) $bank['status']) ?></div>
        </div>
        <a class="btn btn-sm btn-outline-secondary mt-3" href="<?= e(Url::member('bank.php')) ?>">Manage</a>
      <?php endif; ?>
    </div>
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Payout policy</h2>
      <ul class="small text-muted ps-3 mb-0">
        <li>Regular payouts are <strong>automatic</strong> — no request needed.</li>
        <li>Minimum payout <?= money((float) $settings['min_withdrawal_amount']) ?>.</li>
        <li>Deductions: admin charges and TDS as applicable.</li>
        <li>Only approved, matured commission can be withdrawn.</li>
        <li>Report download → <strong>In Progress</strong> → bank payment → <strong>Payment Completed</strong> with UTR.</li>
        <li>UTR / reference numbers are shown in your payout statement.</li>
      </ul>
    </div>
  </div>
</div>
<?php
$feeType = $settings['withdrawal_fee_type'];
$feeValue = (float) $settings['withdrawal_fee_value'];
$tdsPercent = (float) $settings['tds_percent'];
$extraScripts = '<script>
(function () {
  var settings = {feeType: ' . json_encode($feeType) . ', feeValue: ' . $feeValue . ', tds: ' . $tdsPercent . '};
  var input = document.querySelector("[data-payout-amount]");
  function fmt(n) { return "' . e(CURRENCY_SYMBOL) . '" + (n || 0).toFixed(2).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ","); }
  function recalc() {
    var gross = parseFloat(input.value || "0");
    var fee = settings.feeType === "percent" ? gross * settings.feeValue / 100 : settings.feeValue;
    fee = Math.min(fee, gross);
    var tds = gross * settings.tds / 100;
    document.querySelector("[data-calc=gross]").textContent = fmt(gross);
    document.querySelector("[data-calc=fee]").textContent = "−" + fmt(fee);
    document.querySelector("[data-calc=tds]").textContent = "−" + fmt(tds);
    document.querySelector("[data-calc=net]").textContent = fmt(Math.max(0, gross - fee - tds));
  }
  input?.addEventListener("input", recalc);
})();
</script>';
require __DIR__ . '/includes/layout-end.php';
