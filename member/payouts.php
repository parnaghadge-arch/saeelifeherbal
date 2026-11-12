<?php
/** Payout request history. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;
use Saee\Lib\Security;

$total = (int) db()->value('SELECT COUNT(*) FROM payout_requests WHERE member_id = :m', ['m' => $currentMemberId]);
$pager = new Pager($total, 20);
$rows = db()->select('SELECT * FROM payout_requests WHERE member_id = :m ORDER BY id DESC' . $pager->limitSql(), ['m' => $currentMemberId]);
$totals = db()->first(
    "SELECT SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END) AS paid,
            SUM(CASE WHEN status IN ('pending','approved') THEN amount ELSE 0 END) AS in_progress
     FROM payout_requests WHERE member_id = :m",
    ['m' => $currentMemberId]
) ?? [];

$pageTitle = 'Payout History';
$pageHeading = 'Payout History';
$pageSub = 'Track every withdrawal request';
$activeNav = 'payouts';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6"><div class="mini-stat"><span>Paid out</span><strong><?= money((float) ($totals['paid'] ?? 0)) ?></strong></div></div>
  <div class="col-6"><div class="mini-stat"><span>In progress</span><strong><?= money((float) ($totals['in_progress'] ?? 0)) ?></strong></div></div>
</div>

<div class="panel p-3 p-md-4">
  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">You have not requested a payout yet.</p>
    <a class="btn btn-sm btn-primary mt-2" href="payout-request.php">Request payout</a>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Request</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Net paid</th><th>Status</th><th>Reference</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Request" class="small fw-semibold"><?= e((string) $row['request_code']) ?></td>
          <td data-label="Date" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y')) ?></td>
          <td data-label="Amount" class="text-end small"><?= money((float) $row['amount']) ?><?php if ((float) $row['deduction_amount'] > 0): ?><div class="text-muted">−<?= money((float) $row['deduction_amount']) ?></div><?php endif; ?></td>
          <td data-label="Net" class="text-end fw-semibold small"><?= money((float) $row['net_amount']) ?></td>
          <td data-label="Status">
            <?= status_badge((string) $row['status']) ?>
            <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
              <div class="small text-danger mt-1"><?= e((string) $row['rejection_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Reference" class="small text-muted"><?= e((string) ($row['utr_number'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links() ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
