<?php
/**
 * Payout reports with CSV / Excel / PDF bulk export and bank detail visibility rules.
 *
 * Auto-payout flow: eligible members' earnings appear here automatically (no member
 * request needed). Downloading any report (bank schedule / CSV / Excel / PDF) moves
 * every included payable row to "in progress" (processing); the admin then pays
 * through the bank and marks it paid here, which debits the wallet and shows
 * "Payment Completed" to the member.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('payouts.reports');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\PayoutService;
use Saee\Lib\ReportService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$status = (string) Request::query('status', '');
$from = (string) Request::query('from', date('Y-m-01'));
$to = (string) Request::query('to', date('Y-m-d'));
$format = (string) Request::query('format', '');
$fullBank = AdminAuth::can('bank.view');

$service = new ReportService($db);
$payoutService = new PayoutService($db);
// Auto payouts: earnings at/above the minimum become pending rows without any
// member request. Syncing on every report load keeps the register current.
$autoCreated = 0;
try {
    $autoCreated = $payoutService->syncAutoPayouts();
} catch (\Throwable $e) {
    error_log('payout auto sync failed: ' . $e->getMessage());
}
$blockedMembers = [];
try {
    $blockedMembers = $payoutService->blockedMembers();
} catch (\Throwable $e) {
    $blockedMembers = [];
}
// Raw rows: the page renders columns, it does not consume export labels.
$rows = $service->payoutRows(['status' => $status, 'from' => $from, 'to' => $to]);

/**
 * Bank details for a payout row, from the member's primary account.
 *
 * A payout is keyed to bank_transfer, so the export has to carry the account the money is going
 * to; the snapshot stored on the request wins because that is what the member requested against.
 */
$bankOf = static function (array $row) use ($db): array {
    $snapshot = json_decode((string) ($row['bank_snapshot'] ?? ''), true);
    $bank = (is_array($snapshot) && trim((string) ($snapshot['account_number'] ?? '')) !== '')
        ? $snapshot
        : ($db->first(
            'SELECT * FROM member_bank_accounts WHERE member_id = :m ORDER BY is_primary DESC, id DESC LIMIT 1',
            ['m' => (int) ($row['member_id'] ?? 0)]
        ) ?? []);
    // Account numbers are encrypted at rest (Security::encrypt in member/bank.php), so anything
    // shown or exported has to come back through decrypt() first.
    if (isset($bank['account_number'])) {
        $bank['account_number'] = (string) (Security::decrypt((string) $bank['account_number']) ?? '');
    }
    return $bank;
};

// ---- mark paid -------------------------------
// Paying through the bank and then recording it here is what debits the wallet. Bulk uses the
// same PayoutService::markPaid() as the single-row action, so a paid request can never be
// debited twice: markPaid only accepts pending/approved/processing rows.
if (is_post()) {
    Security::verifyCsrf();
    if (!AdminAuth::can('payouts.pay')) {
        Session::error('You do not have permission to record payout payments.');
        Request::redirect('payout-reports.php');
    }
    $ids = array_values(array_filter(array_map('intval', (array) Request::input('ids', [])), static fn (int $i): bool => $i > 0));
    $singleUtr = trim((string) Request::input('utr', ''));
    $mode = (string) Request::input('mode', 'bank_transfer');
    if ($ids === []) {
        Session::error('Select at least one payout to mark as paid.');
        Request::redirect('payout-reports.php');
    }
    $payoutService = new PayoutService($db);
    $paid = 0;
    $failed = [];
    foreach ($ids as $id) {
        // A UTR identifies one bank transfer, so a bulk run only reuses it for one row; otherwise
        // each request gets its own reference taken from the per-row field.
        $utr = count($ids) === 1 && $singleUtr !== ''
            ? $singleUtr
            : trim((string) (Request::input('utr_row', [])[$id] ?? ''));
        if ($utr === '') {
            $failed[] = '#' . $id . ': no UTR';
            continue;
        }
        // A request still waiting for approval has to be approved first; money is paid to the
        // bank after the admin has vetted the request either way. Approval is idempotent for the
        // purposes of this loop because only 'pending' rows are approved. Rows already
        // 'processing' (report downloaded) skip approval and go straight to paid.
        $rowStatus = (string) $db->value('SELECT status FROM payout_requests WHERE id = :id', ['id' => $id]);
        if ($rowStatus === 'pending') {
            $approved = $payoutService->approve($id, 'Auto-approved while recording bank payment');
            if (empty($approved['ok'])) {
                $failed[] = '#' . $id . ': ' . (string) ($approved['message'] ?? 'could not be approved');
                continue;
            }
        }
        $result = $payoutService->markPaid($id, $utr, null, $mode);
        if (!empty($result['ok'])) {
            $paid++;
        } else {
            $failed[] = '#' . $id . ': ' . (string) ($result['message'] ?? 'failed');
        }
    }
    if ($paid > 0) {
        Audit::log('payout_paid', 'payouts', $paid . ' payout(s) marked paid from the payout report', 'payout_request', 0, ['ids' => $ids]);
        Session::success($paid . ' payout(s) marked as paid. Amounts have been debited from the members\' available balance.');
    }
    if ($failed !== []) {
        Session::error('Not recorded — ' . implode('; ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' and ' . (count($failed) - 5) . ' more' : ''));
    }
    Request::redirect('payout-reports.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . '&status=' . rawurlencode($status));
}

if ($format !== '') {
    $headers = ['Request', 'Member', 'Member ID', 'Status', 'Gross', 'Deductions', 'Net', 'UTR', 'Requested', 'Paid'];
    $data = array_map(static fn ($r) => [
        (string) ($r['request_code'] ?? ''),
        (string) ($r['name'] ?? ''),
        (string) ($r['member_code'] ?? ''),
        (string) ($r['status'] ?? ''),
        (string) ($r['amount'] ?? ''),
        (string) ($r['deduction_amount'] ?? ''),
        (string) ($r['net_amount'] ?? ''),
        (string) ($r['utr_number'] ?? ''),
        (string) ($r['requested_at'] ?? ''),
        (string) ($r['paid_at'] ?? ''),
    ], $rows);
    $filename = 'payout-report-' . $from . '-' . $to;

    // Downloading the report means the admin is about to pay through the bank:
    // every included payable row becomes "in progress" and the member is notified.
    // This must run before Response::csv()/exit below, which terminate the request.
    $downloadIds = array_values(array_map(
        static fn (array $r): int => (int) ($r['id'] ?? 0),
        array_filter($rows, static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), ['pending', 'approved'], true))
    ));
    if ($downloadIds !== []) {
        try {
            $payoutService->markReportDownloaded($downloadIds, $format);
        } catch (\Throwable $e) {
            error_log('payout report download marking failed: ' . $e->getMessage());
        }
    }

    // Bank payment schedule: exactly the columns a bulk transfer needs. Only unpaid requests,
    // because a paid row would otherwise be paid twice.
    if ($format === 'bank') {
        $schedule = [];
        foreach ($rows as $r) {
            if (in_array((string) ($r['status'] ?? ''), ['paid', 'rejected'], true)) {
                continue;
            }
            $bank = $bankOf($r);
            $schedule[] = [
                (string) ($r['request_code'] ?? ''),
                (string) ($r['name'] ?? ''),
                (string) ($r['member_code'] ?? ''),
                (string) ($bank['account_holder_name'] ?? $r['name'] ?? ''),
                (string) ($bank['account_number'] ?? ''),
                (string) ($bank['ifsc_code'] ?? ''),
                (string) ($bank['bank_name'] ?? ''),
                (string) ($bank['branch_name'] ?? ''),
                number_format((float) ($r['net_amount'] ?? 0), 2, '.', ''),
                'PAYOUT ' . (string) ($r['request_code'] ?? ''),
            ];
        }
        \Saee\Lib\Response::csv(
            $filename . '-bank-schedule.csv',
            $schedule,
            ['Request', 'Beneficiary', 'Member ID', 'Account holder', 'Account number', 'IFSC', 'Bank', 'Branch', 'Amount', 'Narration']
        );
    }

    if ($format === 'csv') {
        \Saee\Lib\Response::csv($filename . '.csv', $data, $headers);
    }
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo ReportService::excelXml('Payout report', array_merge([$headers], $data));
        exit;
    }
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo ReportService::pdf('Payout report', array_merge([$headers], $data), ['Period' => $from . ' to ' . $to]);
        exit;
    }
}

$totalNet = array_sum(array_map(static fn ($r) => (float) ($r['net_amount'] ?? 0), $rows));
$payable = array_values(array_filter($rows, static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), ['pending', 'approved', 'processing'], true)));
$payableTotal = array_sum(array_map(static fn ($r) => (float) ($r['net_amount'] ?? 0), $payable));
$inProgress = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === 'processing'));
$inProgressTotal = array_sum(array_map(static fn ($r) => (float) ($r['net_amount'] ?? 0), $inProgress));
$paidTotal = array_sum(array_map(static fn ($r) => (float) ($r['net_amount'] ?? 0), array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === 'paid')));
$autoCount = count(array_filter($rows, static fn (array $r): bool => (string) ($r['source'] ?? 'manual') === 'auto'));
$canPay = AdminAuth::can('payouts.pay');

$pageTitle = 'Payout Reports';
$pageHeading = 'Payout Reports';
$pageSub = count($rows) . ' records · ' . money((float) $totalNet) . ' net · ' . money((float) $payableTotal) . ' awaiting payment';
$activeModule = 'payout-reports';
require __DIR__ . '/includes/layout.php';
?>
<?php if ($autoCreated > 0): ?>
  <div class="alert alert-success small d-flex align-items-center gap-2">
    <i class="fa-solid fa-circle-check"></i>
    <span><strong><?= (int) $autoCreated ?> auto payout(s)</strong> added from member earnings on this load — no member request was needed.</span>
  </div>
<?php endif; ?>

<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (['pending', 'approved', 'processing', 'paid', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s === 'processing' ? 'In Progress' : ucfirst($s)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><button class="btn btn-sm btn-dark w-100">Apply</button></div>
    <div class="col-12 col-md-4 d-flex gap-2">
      <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF', 'bank' => 'Bank schedule'] as $key => $label): ?>
        <a class="btn btn-sm btn-outline-secondary flex-grow-1" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&status=<?= e($status) ?>&format=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </form>
  <div class="form-text mt-2"><i class="fa-solid fa-download me-1"></i>Downloading any report above moves the included payouts to <strong>In Progress</strong> and notifies the members.</div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Requests (<?= (int) $autoCount ?> auto)</span><strong><?= count($rows) ?></strong></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Awaiting payment</span><strong><?= money((float) $payableTotal) ?></strong><small><?= count($payable) ?> payable</small></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>In progress</span><strong><?= money((float) $inProgressTotal) ?></strong><small><?= count($inProgress) ?> downloaded</small></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Already paid</span><strong><?= money((float) $paidTotal) ?></strong><small>Total net <?= money((float) $totalNet) ?></small></div></div>
</div>

<div class="alert alert-light border small">
  <strong>How auto payout works:</strong>
  <ol class="mb-0 ps-3">
    <li>Member earnings at/above the minimum appear here <strong>automatically</strong> — members do not request.</li>
    <li>Download the <strong>Bank schedule</strong> and pay through your bank's bulk transfer. Downloading marks those payouts <strong>In Progress</strong>.</li>
    <li>Tick the rows you have paid and record the UTR below. Marking paid debits the wallet and the member sees <strong>Payment Completed</strong>.</li>
  </ol>
  <?= AdminAuth::can('bank.view') ? '' : 'Account numbers are masked because your role cannot view bank details.' ?>
</div>

<?php if ($blockedMembers !== []): ?>
<div class="panel p-3 p-md-4 mb-3">
  <h2 class="h6 mb-2"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Held — earnings available but not payable (<?= count($blockedMembers) ?>)</h2>
  <p class="small text-muted mb-2">These members have reached the minimum but are blocked by KYC / bank / status. They will join the report automatically once fixed.</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 data-table">
      <thead><tr><th>Member</th><th class="text-end">Available</th><th>Blocked by</th></tr></thead>
      <tbody>
      <?php foreach ($blockedMembers as $b): ?>
        <tr>
          <td data-label="Member" class="small"><?= e((string) ($b['name'] ?? '')) ?><div class="text-muted"><?= e((string) ($b['member_code'] ?? '')) ?></div></td>
          <td data-label="Available" class="text-end small fw-semibold"><?= money((float) ($b['available_balance'] ?? 0)) ?></td>
          <td data-label="Blocked by" class="small"><?= e(implode(', ', (array) ($b['blockers'] ?? []))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="panel p-3 p-md-4">
  <form method="post" id="payoutPayForm">
    <?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= e($from) ?>">
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead>
          <tr>
            <?php if ($canPay): ?><th style="width:34px"><input type="checkbox" class="form-check-input" id="payAll" aria-label="Select all unpaid"></th><?php endif; ?>
            <th>Request</th><th>Member</th><th>Bank account</th><th class="text-end">Net payable</th><th>Status</th><th style="min-width:170px">UTR / reference</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $rowStatus = (string) ($row['status'] ?? 'pending');
            $isPayable = in_array($rowStatus, ['pending', 'approved', 'processing'], true);
            $bank = $bankOf($row);
            $account = (string) ($bank['account_number'] ?? '');
            $shown = $account === '' ? '' : ($fullBank ? $account : Security::maskAccount($account));
            $isAuto = (string) ($row['source'] ?? 'manual') === 'auto';
          ?>
          <tr>
            <?php if ($canPay): ?>
              <td data-label="Select">
                <?php if ($isPayable): ?>
                  <input type="checkbox" class="form-check-input pay-row" name="ids[]" value="<?= (int) $row['id'] ?>" aria-label="Select <?= e((string) ($row['request_code'] ?? '')) ?>">
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td data-label="Request" class="small fw-semibold"><?= e((string) ($row['request_code'] ?? '—')) ?><div class="text-muted"><?= e(($row['requested_at'] ?? '') !== '' ? format_date((string) $row['requested_at'], 'd M Y') : '—') ?></div><span class="badge <?= $isAuto ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-muted border' ?> mt-1"><?= $isAuto ? 'Auto' : 'Manual' ?></span></td>
            <td data-label="Member" class="small"><?= e((string) ($row['name'] ?? '—')) ?><div class="text-muted"><?= e((string) ($row['member_code'] ?? '')) ?></div></td>
            <td data-label="Bank" class="small">
              <?php if ($shown !== ''): ?>
                <div class="fw-semibold"><?= e($shown) ?></div>
                <div class="text-muted"><?= e((string) ($bank['bank_name'] ?? '')) ?> <?= e((string) ($bank['ifsc_code'] ?? '')) ?></div>
              <?php else: ?>
                <span class="text-danger">No bank account on file</span>
              <?php endif; ?>
            </td>
            <td data-label="Net" class="text-end small fw-semibold"><?= money((float) ($row['net_amount'] ?? 0)) ?></td>
            <td data-label="Status"><?= status_badge($rowStatus) ?><?php if (!empty($row['report_downloaded_at'])): ?><div class="small text-muted mt-1">Report <?= e(format_date((string) $row['report_downloaded_at'], 'd M Y')) ?></div><?php endif; ?></td>
            <td data-label="UTR" class="small">
              <?php if ($isPayable && $canPay): ?>
                <input class="form-control form-control-sm" name="utr_row[<?= (int) $row['id'] ?>]" placeholder="UTR for this row" aria-label="UTR for <?= e((string) ($row['request_code'] ?? '')) ?>">
              <?php elseif (($row['utr_number'] ?? '') !== ''): ?>
                <?= e((string) $row['utr_number']) ?>
                <div class="text-muted"><?= !empty($row['paid_at']) ? e(format_date((string) $row['paid_at'], 'd M Y')) : '' ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $canPay ? 7 : 6 ?>" class="small text-muted">No payouts in this period.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canPay && $payable !== []): ?>
      <div class="panel p-3 mt-3 border">
        <h2 class="h6 mb-2">Record payment for the selected rows</h2>
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Single payment mode</label>
            <select class="form-select form-select-sm" name="mode">
              <option value="bank_transfer">Bank transfer (NEFT/RTGS/IMPS)</option>
              <option value="upi">UPI</option>
              <option value="cheque">Cheque</option>
              <option value="cash">Cash</option>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">One UTR for a single row (optional)</label>
            <input class="form-control form-control-sm" name="utr" placeholder="Fill when paying one request">
          </div>
          <div class="col-12 col-md-4">
            <button class="btn btn-primary w-100" type="submit"
                    data-confirm="Mark the selected payouts as paid? This debits each member's available balance and cannot be undone.">
              <i class="fa-solid fa-check-double me-1"></i>Mark selected as paid
            </button>
          </div>
        </div>
        <div class="form-text mt-2">
          For several rows, enter each UTR in its own row above. A member already moved to
          <strong>paid</strong> is skipped, so nothing is debited twice.
        </div>
      </div>
    <?php endif; ?>
  </form>
</div>

<script>
(function () {
  var all = document.getElementById('payAll');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.pay-row').forEach(function (cb) { cb.checked = all.checked; });
    });
  }
})();
</script>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
