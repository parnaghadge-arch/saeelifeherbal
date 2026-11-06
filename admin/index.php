<?php
/** Admin dashboard with live KPIs and Chart.js analytics. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('dashboard.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\MemberService;
use Saee\Lib\PayoutService;
use Saee\Lib\Request;
use Saee\Lib\Settings;
use Saee\Lib\Url;

$range = max(7, min(365, Request::int('range', 30)));
$from = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));
$db = db();

$kpi = $db->first(
    "SELECT
        (SELECT COUNT(*) FROM members) AS members,
        (SELECT COUNT(*) FROM members WHERE status = 'active') AS active_members,
        (SELECT COUNT(*) FROM members WHERE joined_at >= :from) AS new_members,
        (SELECT COUNT(*) FROM orders) AS orders,
        (SELECT COUNT(*) FROM orders WHERE payment_status = 'paid') AS paid_orders,
        (SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status = 'paid' AND created_at >= :from) AS revenue,
        (SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status = 'paid') AS revenue_total,
        (SELECT COUNT(*) FROM products WHERE status = 'active') AS products,
        (SELECT COUNT(*) FROM contact_enquiries WHERE status = 'new') AS new_enquiries,
        (SELECT COUNT(*) FROM payout_requests WHERE status = 'pending') AS pending_payouts,
        (SELECT COUNT(*) FROM member_kyc WHERE status IN ('submitted','under_verification')) AS kyc_pending,
        (SELECT COALESCE(SUM(amount),0) FROM commission_transactions WHERE status IN ('available','approved','paid')) AS commission_liability,
        (SELECT COUNT(*) FROM page_views WHERE created_at >= :from) AS visits",
    ['from' => $from . ' 00:00:00']
);

$salesSeries = $db->select(
    "SELECT DATE(created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(grand_total),0) AS revenue
     FROM orders WHERE created_at >= :from GROUP BY DATE(created_at) ORDER BY d",
    ['from' => $from . ' 00:00:00']
);
$byDay = [];
foreach ($salesSeries as $row) {
    $byDay[$row['d']] = $row;
}
$labels = [];
$revenue = [];
$ordersSeries = [];
for ($i = 0; $i < $range; $i++) {
    $day = date('Y-m-d', strtotime('-' . ($range - 1 - $i) . ' days'));
    $labels[] = date('d M', strtotime($day));
    $revenue[] = (float) ($byDay[$day]['revenue'] ?? 0);
    $ordersSeries[] = (int) ($byDay[$day]['orders'] ?? 0);
}
$topProducts = $db->select(
    "SELECT i.product_name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
     FROM order_items i JOIN orders o ON o.id = i.order_id
     WHERE o.payment_status = 'paid' GROUP BY i.product_name ORDER BY revenue DESC LIMIT 5"
);
$recentOrders = $db->select('SELECT * FROM orders ORDER BY id DESC LIMIT 6');
$recentMembers = $db->select('SELECT * FROM members ORDER BY id DESC LIMIT 6');
$recentActivity = AdminAuth::can('audit.view')
    ? $db->select('SELECT * FROM admin_activity_logs ORDER BY id DESC LIMIT 8')
    : [];

$pageTitle = 'Dashboard';
$pageHeading = 'Dashboard';
$pageSub = 'Live business overview · last ' . $range . ' days';
$activeModule = 'dashboard';
require __DIR__ . '/includes/layout.php';
?>
<div class="filter-bar mb-3">
  <span class="small text-muted me-2">Period:</span>
  <?php foreach ([7 => '7 days', 30 => '30 days', 90 => 'Quarter', 365 => 'Year'] as $days => $label): ?>
    <a class="btn btn-sm <?= $range === $days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?range=<?= (int) $days ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <?php
  $cards = [
      ['Revenue (paid)', money((float) $kpi['revenue']), 'fa-indian-rupee-sign', 'Lifetime ' . money((float) $kpi['revenue_total'])],
      ['Orders', number_format((int) $kpi['orders']), 'fa-bag-shopping', (int) $kpi['paid_orders'] . ' paid'],
      ['Members', number_format((int) $kpi['members']), 'fa-user-group', (int) $kpi['new_members'] . ' joined in period'],
      ['Commission credited', money((float) $kpi['commission_liability']), 'fa-receipt', 'Liability to date'],
  ];
  foreach ($cards as [$label, $value, $icon, $foot]): ?>
    <div class="col-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
        <div class="stat-label"><?= e($label) ?></div>
        <div class="stat-value"><?= $value ?></div>
        <div class="stat-foot"><?= e($foot) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Page views</span><strong><?= number_format((int) $kpi['visits']) ?></strong><small>In selected period</small></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Pending KYC</span><strong><?= (int) $kpi['kyc_pending'] ?></strong><small><a href="<?= e(Url::admin('kyc.php')) ?>">Review</a></small></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Pending payouts</span><strong><?= (int) $kpi['pending_payouts'] ?></strong><small><a href="<?= e(Url::admin('payouts.php')) ?>">Process</a></small></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>New enquiries</span><strong><?= (int) $kpi['new_enquiries'] ?></strong><small><a href="<?= e(Url::admin('enquiries.php')) ?>">Respond</a></small></div></div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2 class="h6 mb-0">Sales trend</h2><p class="small text-muted mb-0">Paid revenue by day</p></div>
      </div>
      <div style="height:280px"><canvas id="salesChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4 h-100">
      <h2 class="h6 mb-3">Top products</h2>
      <?php if (!$topProducts): ?>
        <p class="small text-muted mb-0">No paid orders yet.</p>
      <?php else: ?>
        <div class="list-stack">
          <?php foreach ($topProducts as $i => $product): ?>
            <div class="list-item">
              <div class="list-icon"><?= (int) $i + 1 ?></div>
              <div class="flex-grow-1 min-w-0">
                <div class="small fw-semibold text-truncate"><?= e((string) $product['product_name']) ?></div>
                <div class="small text-muted"><?= (int) $product['qty'] ?> units · <?= money((float) $product['revenue']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Latest orders</h2><a class="small" href="<?= e(Url::admin('orders.php')) ?>">All orders</a></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Order</th><th>Customer</th><th class="text-end">Total</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentOrders as $order): ?>
            <tr>
              <td class="small"><a href="<?= e(Url::admin('order-details.php?id=' . (int) $order['id'])) ?>"><?= e((string) $order['order_number']) ?></a></td>
              <td class="small"><?= e((string) $order['customer_name']) ?></td>
              <td class="small text-end"><?= money((float) $order['grand_total']) ?></td>
              <td><?= status_badge((string) $order['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Newest members</h2><a class="small" href="<?= e(Url::admin('members.php')) ?>">All members</a></div>
      <div class="list-stack">
        <?php foreach ($recentMembers as $m): ?>
          <a class="list-item" href="<?= e(Url::admin('member-details.php?id=' . (int) $m['id'])) ?>">
            <div class="avatar-circle"><?= e(initials((string) $m['name'])) ?></div>
            <div class="flex-grow-1 min-w-0">
              <div class="small fw-semibold text-truncate"><?= e((string) $m['name']) ?></div>
              <div class="small text-muted"><?= e((string) $m['member_code']) ?> · <?= e(format_date((string) $m['joined_at'], 'd M Y')) ?></div>
            </div>
            <?= status_badge((string) $m['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($recentActivity): ?>
<div class="panel p-3 p-md-4 mt-3">
  <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Recent admin activity</h2><a class="small" href="<?= e(Url::admin('audit-logs.php')) ?>">Audit log</a></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>When</th><th>Admin</th><th>Module</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($recentActivity as $log): ?>
        <tr>
          <td class="small text-muted"><?= e(time_ago((string) $log['created_at'])) ?></td>
          <td class="small"><?= e((string) $log['admin_name']) ?></td>
          <td class="small"><?= e((string) $log['module']) ?></td>
          <td class="small"><?= e(truncate_text((string) $log['description'], 90)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php
$chartData = ['labels' => $labels, 'revenue' => $revenue, 'orders' => $ordersSeries];
$extraScripts = '<script src="' . e(Url::asset('vendor/chartjs/chart.umd.js')) . '"></script>
<script>
window.addEventListener("load", function () {
  var data = ' . json_encode($chartData, JSON_THROW_ON_ERROR) . ';
  var ctx = document.getElementById("salesChart");
  if (!ctx || !window.Chart) { return; }
  new Chart(ctx, {
    type: "bar",
    data: {
      labels: data.labels,
      datasets: [{
        type: "line", label: "Revenue", data: data.revenue, borderColor: "#247A3C",
        backgroundColor: "rgba(36,122,60,.12)", fill: true, tension: .35, pointRadius: 0, yAxisID: "y"
      }, {
        type: "bar", label: "Orders", data: data.orders, backgroundColor: "rgba(165,201,74,.65)", borderRadius: 4, yAxisID: "y1"
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: "index", intersect: false },
      plugins: { legend: { position: "bottom", labels: { boxWidth: 12, font: { size: 11 } } } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: function (v) { return "₹" + v; }, font: { size: 10 } }, grid: { color: "rgba(0,0,0,.05)" } },
        y1: { beginAtZero: true, position: "right", grid: { display: false }, ticks: { precision: 0, font: { size: 10 } } },
        x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 10 } } }
      }
    }
  });
});
</script>';
require __DIR__ . '/includes/layout-end.php';
