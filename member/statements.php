<?php
/** Statements: on-screen summary + CSV/Excel/PDF export of commissions and payouts. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\ReportService;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Url;

$from = (string) Request::query('from', date('Y-m-01'));
$to = (string) Request::query('to', date('Y-m-d'));
$format = (string) Request::query('format', '');
$service = new ReportService(db());

$commissionRows = db()->select(
    'SELECT created_at, transaction_code, commission_type, product_name, buyer_name, basis_amount, rate_value, commission_type_rate, amount, status
     FROM commission_transactions WHERE member_id = :m AND created_at BETWEEN :f AND :t ORDER BY id DESC',
    ['m' => $currentMemberId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
);
$payoutRows = db()->select(
    'SELECT created_at, request_code, amount, deduction_amount, net_amount, status, utr_number FROM payout_requests
     WHERE member_id = :m AND created_at BETWEEN :f AND :t ORDER BY id DESC',
    ['m' => $currentMemberId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
);

if ($format !== '') {
    $type = (string) Request::query('type', 'commissions');
    $rows = [];
    if ($type === 'payouts') {
        $head = ['Date', 'Request', 'Amount', 'Deduction', 'Net', 'Status', 'UTR'];
        foreach ($payoutRows as $r) {
            $rows[] = [format_date((string) $r['created_at'], 'd-m-Y'), $r['request_code'], $r['amount'], $r['deduction_amount'], $r['net_amount'], ucfirst((string) $r['status']), (string) ($r['utr_number'] ?? '')];
        }
        $title = 'Payout statement';
    } else {
        $head = ['Date', 'Reference', 'Type', 'Product', 'Buyer', 'Basis', 'Rate', 'Amount', 'Status'];
        foreach ($commissionRows as $r) {
            $rows[] = [
                format_date((string) $r['created_at'], 'd-m-Y'), $r['transaction_code'], $r['commission_type'], $r['product_name'], $r['buyer_name'],
                $r['basis_amount'], $r['rate_value'] . ($r['commission_type_rate'] === 'percent' ? '%' : ''), $r['amount'], ucfirst((string) $r['status']),
            ];
        }
        $title = 'Commission statement';
    }
    $meta = ['Member' => $member['name'] . ' (' . $member['member_code'] . ')', 'Period' => $from . ' to ' . $to];
    $filename = strtolower(str_replace(' ', '-', $title)) . '-' . $from . '-' . $to;
    if ($format === 'csv') {
        Response::csv($filename . '.csv', $rows, $head);
    }
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo ReportService::excelXml($title, array_merge([$head], $rows));
        exit;
    }
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo ReportService::pdf($title, array_merge([$head], $rows), $meta);
        exit;
    }
}

$commissionTotal = array_sum(array_map(static fn ($r) => (float) $r['amount'], $commissionRows));
$payoutTotal = array_sum(array_map(static fn ($r) => (float) $r['net_amount'], $payoutRows));

$pageTitle = 'Download Statements';
$pageHeading = 'Statements';
$pageSub = 'Commission and payout statements for any period';
$activeNav = 'statements';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-dark w-100">Apply period</button></div>
    <div class="col-md-4">
      <div class="small text-muted">
        <?php foreach (['This month' => [date('Y-m-01'), date('Y-m-d')], 'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))], 'Last 6 months' => [date('Y-m-d', strtotime('-6 months')), date('Y-m-d')]] as $label => [$f, $t]): ?>
          <a class="me-3" href="?from=<?= e($f) ?>&to=<?= e($t) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </form>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Commissions</h2>
        <div class="table-tools">
          <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'] as $key => $label): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('statements.php')) ?>?type=commissions&from=<?= e($from) ?>&to=<?= e($to) ?>&format=<?= e($key) ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="h4"><?= money((float) $commissionTotal) ?></div>
      <p class="small text-muted mb-0"><?= count($commissionRows) ?> records in this period.</p>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Payouts</h2>
        <div class="table-tools">
          <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'] as $key => $label): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('statements.php')) ?>?type=payouts&from=<?= e($from) ?>&to=<?= e($to) ?>&format=<?= $key ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="h4"><?= money((float) $payoutTotal) ?></div>
      <p class="small text-muted mb-0"><?= count($payoutRows) ?> records in this period.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
