<?php
/** Payout reports with CSV / Excel / PDF bulk export and bank detail visibility rules. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('payouts.reports');

use Saee\Lib\AdminAuth;
use Saee\Lib\ReportService;
use Saee\Lib\Request;

$db = db();
$status = (string) Request::query('status', '');
$from = (string) Request::query('from', date('Y-m-01'));
$to = (string) Request::query('to', date('Y-m-d'));
$format = (string) Request::query('format', '');
$fullBank = AdminAuth::can('bank.view');

$service = new ReportService($db);
$rows = $service->payouts(['status' => $status, 'from' => $from, 'to' => $to], $fullBank);

if ($format !== '') {
    $headers = ['Request', 'Member', 'Member ID', 'Status', 'Gross', 'Deductions', 'Net', 'UTR', 'Requested', 'Paid'];
    $data = array_map(static fn ($r) => [
        $r['request_code'], $r['name'], $r['member_code'], $r['status'], $r['amount'], $r['deduction_amount'], $r['net_amount'],
        (string) ($r['utr_number'] ?? ''), $r['requested_at'], (string) ($r['paid_at'] ?? ''),
    ], $rows);
    $filename = 'payout-report-' . $from . '-' . $to;
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

$totalNet = array_sum(array_map(static fn ($r) => (float) $r['net_amount'], $rows));

$pageTitle = 'Payout Reports';
$pageHeading = 'Payout Reports';
$pageSub = count($rows) . ' records · ' . money((float) $totalNet) . ' net';
$activeModule = 'payout-reports';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (['pending', 'approved', 'paid', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><button class="btn btn-sm btn-dark w-100">Apply</button></div>
    <div class="col-12 col-md-4 d-flex gap-2">
      <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'] as $key => $label): ?>
        <a class="btn btn-sm btn-outline-secondary flex-grow-1" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&status=<?= e($status) ?>&format=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </form>
</div>

<div class="panel p-3 p-md-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Request</th><th>Member</th><th>Status</th><th class="text-end">Gross</th><th class="text-end">Net</th><th>UTR</th><th>Paid</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Request" class="small fw-semibold"><?= e((string) $row['request_code']) ?><div class="text-muted"><?= e(format_date((string) $row['requested_at'], 'd M Y')) ?></div></td>
          <td data-label="Member" class="small"><?= e((string) $row['name']) ?><div class="text-muted"><?= e((string) $row['member_code']) ?></div></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td data-label="Gross" class="text-end small"><?= money((float) $row['amount']) ?></td>
          <td data-label="Net" class="text-end small fw-semibold"><?= money((float) $row['net_amount']) ?></td>
          <td data-label="UTR" class="small"><?= e((string) ($row['utr_number'] ?? '—')) ?></td>
          <td data-label="Paid" class="small"><?= $row['paid_at'] ? e(format_date((string) $row['paid_at'], 'd M Y')) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="small text-muted">No payouts in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
