<?php
/** Analytics & reports: revenue, members, commissions with date filters + exports. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('reports.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\ReportService;
use Saee\Lib\Request;
use Saee\Lib\Url;

$db = db();
$from = (string) Request::query('from', date('Y-m-01'));
$to = (string) Request::query('to', date('Y-m-d'));
$type = (string) Request::query('type', 'commissions');
$format = (string) Request::query('format', '');
$service = new ReportService($db);

$rows = match ($type) {
    'members' => $service->members(['from' => $from, 'to' => $to]),
    'payouts' => $service->payouts(['from' => $from, 'to' => $to], AdminAuth::can('bank.view')),
    default => $service->commissions(['from' => $from, 'to' => $to]),
};

if ($format !== '') {
    $headers = $rows ? array_keys($rows[0]) : ['No data'];
    $data = array_map(static fn ($r) => array_values($r), $rows);
    $filename = $type . '-report-' . $from . '-' . $to;
    if ($format === 'csv') { \Saee\Lib\Response::csv($filename . '.csv', $data, $headers); }
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo ReportService::excelXml(ucfirst($type) . ' report', array_merge([$headers], $data));
        exit;
    }
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo ReportService::pdf(ucfirst($type) . ' report', array_merge([$headers], $data), ['Period' => $from . ' to ' . $to]);
        exit;
    }
}

$sales = $db->first(
    "SELECT COUNT(*) AS orders, COALESCE(SUM(grand_total),0) AS revenue,
            COALESCE(AVG(grand_total),0) AS aov
     FROM orders WHERE payment_status = 'paid' AND created_at BETWEEN :f AND :t",
    ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
) ?? [];
$commissionTotal = $db->first(
    "SELECT COALESCE(SUM(amount),0) AS credited, COUNT(*) AS entries FROM commission_transactions
     WHERE status IN ('available','approved','paid') AND created_at BETWEEN :f AND :t",
    ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
) ?? [];
$newMembers = (int) $db->value('SELECT COUNT(*) FROM members WHERE joined_at BETWEEN :f AND :t', ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
$topProducts = $db->select(
    "SELECT i.product_name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
     FROM order_items i JOIN orders o ON o.id = i.order_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN :f AND :t
     GROUP BY i.product_name ORDER BY revenue DESC LIMIT 8",
    ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
);
$monthly = $db->select(
    "SELECT " . $db->monthExpression('created_at') . " AS m, COALESCE(SUM(grand_total),0) AS revenue, COUNT(*) AS orders
     FROM orders WHERE payment_status = 'paid' GROUP BY " . $db->monthExpression('created_at') . " ORDER BY m DESC LIMIT 12"
);
$monthly = array_reverse($monthly);

$pageTitle = 'Reports';
$pageHeading = 'Reports & Analytics';
$pageSub = $from . ' → ' . $to;
$activeModule = 'reports';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Dataset</label>
      <select class="form-select form-select-sm" name="type">
        <?php foreach (['commissions' => 'Commissions', 'members' => 'Members', 'payouts' => 'Payouts'] as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><button class="btn btn-sm btn-dark w-100">Apply</button></div>
    <div class="col-12 col-md-4 d-flex gap-2">
      <?php foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF'] as $key => $label): ?>
        <a class="btn btn-sm btn-outline-secondary flex-grow-1" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&type=<?= e($type) ?>&format=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Revenue</div><div class="stat-value"><?= money((float) ($sales['revenue'] ?? 0)) ?></div><div class="stat-foot"><?= (int) ($sales['orders'] ?? 0) ?> paid orders</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Average order</div><div class="stat-value"><?= money((float) ($sales['aov'] ?? 0)) ?></div><div class="stat-foot">AOV in period</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Commission credited</div><div class="stat-value"><?= money((float) ($commissionTotal['credited'] ?? 0)) ?></div><div class="stat-foot"><?= (int) ($commissionTotal['entries'] ?? 0) ?> entries</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">New members</div><div class="stat-value"><?= number_format($newMembers) ?></div><div class="stat-foot">Joined in period</div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Monthly revenue</h2>
      <div style="height:280px"><canvas id="reportChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Top products</h2>
      <div class="list-stack">
        <?php foreach ($topProducts as $p): ?>
          <div class="list-item">
            <div class="flex-grow-1 min-w-0"><div class="small fw-semibold text-truncate"><?= e((string) $p['product_name']) ?></div><div class="small text-muted"><?= (int) $p['qty'] ?> units</div></div>
            <div class="small fw-semibold"><?= money((float) $p['revenue']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$topProducts): ?><p class="small text-muted mb-0">No paid orders in this period.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel p-3 p-md-4 mt-3">
  <h2 class="h6 mb-3"><?= e(ucfirst($type)) ?> dataset (<?= count($rows) ?> rows)</h2>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0 data-table">
      <?php if ($rows): ?>
        <thead><tr><?php foreach (array_keys($rows[0]) as $header): ?><th><?= e(ucwords(str_replace('_', ' ', (string) $header))) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach (array_slice($rows, 0, 100) as $row): ?>
          <tr><?php foreach ($row as $key => $value): ?><td data-label="<?= e(ucwords(str_replace('_', ' ', (string) $key))) ?>" class="small"><?= e(truncate_text((string) $value, 60)) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      <?php else: ?>
        <tbody><tr><td class="small text-muted">No data for this period.</td></tr></tbody>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php
$chart = ['labels' => array_map(static fn ($m) => (string) $m['m'], $monthly), 'revenue' => array_map(static fn ($m) => (float) $m['revenue'], $monthly)];
$extraScripts = '<script src="' . e(Url::asset('vendor/chartjs/chart.umd.js')) . '"></script>
<script>
window.addEventListener("load", function () {
  var ctx = document.getElementById("reportChart");
  if (!ctx || !window.Chart) { return; }
  var data = ' . json_encode($chart, JSON_THROW_ON_ERROR) . ';
  new Chart(ctx, {
    type: "bar",
    data: { labels: data.labels, datasets: [{ label: "Revenue", data: data.revenue, backgroundColor: "rgba(36,122,60,.75)", borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return "\u{20B9}" + v; } } }, x: { grid: { display: false } } } }
  });
});
</script>';
require __DIR__ . '/includes/layout-end.php';
