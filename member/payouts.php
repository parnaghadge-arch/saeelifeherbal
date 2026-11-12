<?php
/**
 * Payout Statement (member side).
 *
 * Payouts are automatic: earnings at/above the minimum are added to the admin
 * payout report without any member request. This page shows the current payout
 * status (Pending -> In Progress -> Payment Completed), lifetime totals and the
 * full statement of all previous payments with downloads.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;
use Saee\Lib\PayoutService;
use Saee\Lib\Url;

$payoutService = new PayoutService(db());
// Make sure this member's own auto payout exists before rendering.
try {
    $payoutService->syncAutoPayoutForMember($currentMemberId);
} catch (\Throwable $e) {
    error_log('member auto payout sync failed: ' . $e->getMessage());
}
$summary = $payoutService->memberSummary($currentMemberId);
$settings = $summary['settings'];
$open = $summary['open'];
$wallet = $summary['wallet'];

$total = (int) db()->value('SELECT COUNT(*) FROM payout_requests WHERE member_id = :m', ['m' => $currentMemberId]);
$pager = new Pager($total, 20);
$rows = db()->select('SELECT * FROM payout_requests WHERE member_id = :m ORDER BY id DESC' . $pager->limitSql(), ['m' => $currentMemberId]);

$pageTitle = 'Payout Statement';
$pageHeading = 'Payout Statement';
$pageSub = 'Automatic payouts — no request needed. Track every payment here.';
$activeNav = 'payouts';
require __DIR__ . '/includes/layout.php';

$openStatus = $open ? (string) $open['status'] : '';
$stepIndex = 0;
if ($openStatus === 'pending' || $openStatus === 'approved') {
    $stepIndex = 1;
} elseif ($openStatus === 'processing') {
    $stepIndex = 2;
}
$minAmount = (float) $summary['min_amount'];
$progress = $minAmount > 0 ? min(100, (int) round(((float) $summary['available'] / $minAmount) * 100)) : 0;
?>
<div class="alert alert-success small d-flex align-items-start gap-2">
  <i class="fa-solid fa-wand-magic-sparkles mt-1"></i>
  <div>
    <strong>Payouts are automatic.</strong> You do not need to request a payout.
    When your available earnings reach <strong><?= money($minAmount) ?></strong>, they are added to the payout report automatically.
    After the report is downloaded your payout shows <strong>In Progress</strong>, and once paid it shows <strong>Payment Completed</strong> with the UTR.
  </div>
</div>

<?php if ($open): ?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <div class="small text-muted">Current payout · <?= e((string) $open['request_code']) ?> · <?= (string) (($open['source'] ?? 'manual') === 'auto' ? 'Auto' : 'Manual') ?></div>
      <div class="h4 mb-0"><?= money((float) $open['net_amount']) ?> <span class="small text-muted fw-normal">net payable</span></div>
      <div class="small text-muted">Gross <?= money((float) $open['amount']) ?> · Deductions <?= money((float) $open['deduction_amount']) ?> (TDS <?= money((float) $open['tds_amount']) ?> · Fee <?= money((float) $open['admin_charges']) ?>)</div>
    </div>
    <div><?= status_badge($openStatus) ?></div>
  </div>

  <div class="payout-steps" aria-label="Payout progress">
    <div class="payout-step is-done">
      <span class="payout-step-dot"><i class="fa-solid fa-check"></i></span>
      <span class="payout-step-label">Earned</span>
      <span class="payout-step-date"><?= e(format_date((string) $open['created_at'], 'd M Y')) ?></span>
    </div>
    <div class="payout-step <?= $stepIndex >= 1 ? 'is-done' : '' ?>">
      <span class="payout-step-dot"><i class="fa-solid fa-file-invoice-dollar"></i></span>
      <span class="payout-step-label">In report</span>
      <span class="payout-step-date">Pending</span>
    </div>
    <div class="payout-step <?= $stepIndex >= 2 ? 'is-current' : '' ?>">
      <span class="payout-step-dot"><i class="fa-solid fa-hourglass-half"></i></span>
      <span class="payout-step-label">In progress</span>
      <span class="payout-step-date"><?= !empty($open['report_downloaded_at']) ? e(format_date((string) $open['report_downloaded_at'], 'd M Y')) : 'Awaiting download' ?></span>
    </div>
    <div class="payout-step">
      <span class="payout-step-dot"><i class="fa-solid fa-check-double"></i></span>
      <span class="payout-step-label">Completed</span>
      <span class="payout-step-date">UTR after payment</span>
    </div>
  </div>

  <?php if ($openStatus === 'processing'): ?>
    <div class="alert alert-info small mb-0 mt-3">
      <i class="fa-solid fa-hourglass-half me-1"></i>
      Your payout is <strong>in progress</strong> — the accounts team has downloaded the payout report and payment to your bank account is being processed.
    </div>
  <?php elseif ($openStatus === 'pending' || $openStatus === 'approved'): ?>
    <div class="alert alert-light border small mb-0 mt-3">
      <i class="fa-solid fa-file-invoice-dollar me-1"></i>
      Your payout is in the report and <strong>pending</strong>. It will move to <strong>In Progress</strong> once the report is downloaded for payment.
    </div>
  <?php endif; ?>
</div>
<?php elseif ($summary['preview']): ?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="small text-muted">Next automatic payout</div>
  <div class="h4 mb-1"><?= money((float) $summary['preview']['net']) ?> <span class="small text-muted fw-normal">net expected</span></div>
  <div class="small text-muted">Gross <?= money((float) $summary['preview']['gross']) ?> · Deductions <?= money((float) $summary['preview']['deduction']) ?>. It will appear in the payout report automatically.</div>
</div>
<?php else: ?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="small text-muted">Available balance</div>
      <div class="h4 mb-0"><?= money((float) $summary['available']) ?></div>
    </div>
    <div class="text-end">
      <div class="small text-muted">Minimum for auto payout</div>
      <div class="fw-semibold"><?= money($minAmount) ?></div>
    </div>
  </div>
  <div class="progress mt-3" style="height:10px" role="progressbar" aria-valuenow="<?= (int) $progress ?>" aria-valuemin="0" aria-valuemax="100">
    <div class="progress-bar" style="width:<?= (int) $progress ?>%"></div>
  </div>
  <div class="small text-muted mt-2"><?= money((float) $summary['available']) ?> of <?= money($minAmount) ?> (<?= (int) $progress ?>%). Keep earning — your payout will be added automatically once you reach the minimum. No request needed.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total paid out</span><strong><?= money((float) $summary['paid_net']) ?></strong><small><?= (int) $summary['paid_count'] ?> payment(s) completed</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>In progress / pending</span><strong><?= money((float) $summary['open_net']) ?></strong><small>Current payout</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total earned</span><strong><?= money((float) ($wallet['total_earned'] ?? 0)) ?></strong><small>Lifetime credits</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Available now</span><strong><?= money((float) $summary['available']) ?></strong><small>Next auto payout</small></div></div>
</div>

<div class="panel p-3 p-md-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h6 mb-0">All previous payments</h2>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'] as $key => $label): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('statements.php')) ?>?type=payouts&from=<?= e(date('Y-m-d', strtotime('-5 years'))) ?>&to=<?= e(date('Y-m-d')) ?>&format=<?= e($key) ?>"><i class="fa-solid fa-download me-1"></i><?= e($label) ?></a>
      <?php endforeach; ?>
      <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
    </div>
  </div>

  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No payouts yet. Your first automatic payout will appear here once your available earnings reach <?= money($minAmount) ?>.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Request</th><th>Date</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th><th>Status</th><th>UTR / Paid date</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Request" class="small fw-semibold"><?= e((string) $row['request_code']) ?><div class="text-muted fw-normal"><?= (string) (($row['source'] ?? 'manual') === 'auto' ? 'Auto' : 'Manual') ?></div></td>
          <td data-label="Date" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y')) ?></td>
          <td data-label="Gross" class="text-end small"><?= money((float) $row['amount']) ?></td>
          <td data-label="Deductions" class="text-end small"><?= (float) $row['deduction_amount'] > 0 ? '−' . money((float) $row['deduction_amount']) : '—' ?></td>
          <td data-label="Net" class="text-end fw-semibold small"><?= money((float) $row['net_amount']) ?></td>
          <td data-label="Status">
            <?= status_badge((string) $row['status']) ?>
            <?php if ((string) $row['status'] === 'paid'): ?><div class="small text-success mt-1"><i class="fa-solid fa-check-double me-1"></i>Payment Completed</div><?php endif; ?>
            <?php if ((string) $row['status'] === 'processing'): ?><div class="small text-muted mt-1">In progress</div><?php endif; ?>
            <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
              <div class="small text-danger mt-1"><?= e((string) $row['rejection_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Reference" class="small">
            <?php if (!empty($row['utr_number'])): ?>
              <div class="fw-semibold"><?= e((string) $row['utr_number']) ?></div>
              <div class="text-muted"><?= !empty($row['paid_at']) ? e(format_date((string) $row['paid_at'], 'd M Y')) : '' ?></div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links() ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel p-3 p-md-4 mt-3">
  <h2 class="h6 mb-2">How payouts work</h2>
  <ol class="small text-muted mb-0 ps-3">
    <li>Earnings are credited to your wallet when orders are paid. Available balance above <?= money($minAmount) ?> is added to the payout report <strong>automatically</strong>.</li>
    <li>When the report is downloaded for payment, your payout shows <strong>In Progress</strong>.</li>
    <li>After the bank payment, it shows <strong>Payment Completed</strong> with the UTR / reference number.</li>
    <li>Download your full payout statement above (CSV / Excel / PDF) any time for your records.</li>
  </ol>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
