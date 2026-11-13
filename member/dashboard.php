<?php
/** Partner dashboard: overview of wallet, team, earnings and quick actions. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Database;
use Saee\Lib\MemberService;
use Saee\Lib\MlmCommissionEngine;
use Saee\Lib\PayoutService;
use Saee\Lib\RoyaltyEngine;
use Saee\Lib\Settings;
use Saee\Lib\TeamService;
use Saee\Lib\Url;
use Saee\Lib\WalletService;

$db = db();
$walletService = new WalletService($db);
$teamService = new TeamService($db);
$wallet = $walletService->account($currentMemberId);
$direct = $teamService->directStats($currentMemberId);
$downline = $teamService->downlineCount($currentMemberId);
$sales = $teamService->teamSales($currentMemberId);
$personal = $teamService->personalSales($currentMemberId);
$referralLink = (new MemberService($db))->referralLink($member);

$recentCommissions = $db->select(
    'SELECT * FROM commission_transactions WHERE member_id = :m ORDER BY id DESC LIMIT 6',
    ['m' => $currentMemberId]
);
$recentReferrals = $db->select(
    "SELECT m.*, (SELECT COUNT(*) FROM orders o WHERE o.member_id = m.id AND o.payment_status = 'paid') AS paid_orders
     FROM members m WHERE m.sponsor_id = :s ORDER BY m.id DESC LIMIT 5",
    ['s' => $currentMemberId]
);
// Every order placed with this member ID (or referred by them), newest first, each with an
// invoice button. The full card view with line items lives on the Purchase History page.
$memberOrders = $db->select(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity),0) FROM order_items i WHERE i.order_id = o.id) AS total_qty
     FROM orders o WHERE o.member_id = :m OR o.referral_member_id = :m ORDER BY o.id DESC',
    ['m' => $currentMemberId]
);
try {
    (new PayoutService($db))->syncAutoPayoutForMember($currentMemberId);
} catch (\Throwable $e) {
    error_log('member auto payout sync failed: ' . $e->getMessage());
}
$pendingPayout = $db->first(
    "SELECT * FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved','processing') ORDER BY id DESC LIMIT 1",
    ['m' => $currentMemberId]
);
$pendingRoyalty = $db->first(
    "SELECT COUNT(*) AS c FROM royalty_achievements WHERE member_id = :m AND status = 'pending'",
    ['m' => $currentMemberId]
);

// 6 month cumulative referral series for the Chart.js area chart
$chartLabels = [];
$chartDirect = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = strtotime(date('Y-m-01', strtotime("-$i months")));
    $monthEnd = date('Y-m-t 23:59:59', $monthStart);
    $chartLabels[] = date('M', $monthStart);
    $chartDirect[] = (int) $db->value(
        'SELECT COUNT(*) FROM members WHERE sponsor_id = :s AND joined_at <= :e',
        ['s' => $currentMemberId, 'e' => $monthEnd]
    );
}
$chartSeries = ['labels' => $chartLabels, 'direct' => $chartDirect];

$pageTitle = 'Dashboard';
$pageHeading = 'Namaskar, ' . explode(' ', (string) $member['name'])[0];
$pageSub = 'Member ID ' . $member['member_code'] . ' · ' . ucfirst((string) $member['status']);
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-card-primary">
      <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
      <div class="stat-label">Available balance</div>
      <div class="stat-value"><?= money((float) $wallet['available_balance']) ?></div>
      <div class="stat-foot">Pending <?= money((float) ($wallet['pending_balance'] ?? 0)) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
      <div class="stat-label">Total earned</div>
      <div class="stat-value"><?= money((float) $wallet['total_earned']) ?></div>
      <div class="stat-foot">Withdrawn <?= money((float) $wallet['total_withdrawn']) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-user-group"></i></div>
      <div class="stat-label">Direct &amp; team</div>
      <div class="stat-value"><?= (int) $direct['total'] ?> <span style="font-size:.9rem;color:var(--saee-muted)">/ <?= (int) $downline['total'] ?></span></div>
      <div class="stat-foot"><?= (int) $downline['active'] ?> active in team</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-cart-shopping"></i></div>
      <div class="stat-label">Team sales</div>
      <div class="stat-value"><?= money((float) $sales['amount']) ?></div>
      <div class="stat-foot">Your purchases <?= money((float) $personal['amount']) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="h6 mb-0">Team growth</h2>
          <p class="small text-muted mb-0">Cumulative direct referrals over the last 6 months</p>
        </div>
        <span class="badge bg-light text-dark">Live data</span>
      </div>
      <div style="height:250px"><canvas id="growthChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4 h-100">
      <h2 class="h6 mb-3">Share &amp; grow</h2>
      <label class="form-label small text-muted mb-1">Your referral link</label>
      <div class="input-group input-group-sm mb-2">
        <input class="form-control" id="referralLink" value="<?= e($referralLink) ?>" readonly>
        <button class="btn btn-primary" type="button" data-copy="#referralLink"><i class="fa-regular fa-copy"></i></button>
      </div>
      <div class="small text-muted mb-3">Member ID <strong><?= e($member['member_code']) ?></strong> · Referral code <strong><?= e((string) $member['referral_code']) ?></strong></div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Join Saee Life Herbal with my link: ' . $referralLink) ?>"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-share="<?= e($referralLink) ?>"><i class="fa-solid fa-share-nodes me-1"></i>Share</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" id="qrToggle"><i class="fa-solid fa-qrcode me-1"></i>QR code</button>
      </div>
      <div id="qrBox" class="text-center mt-3 d-none">
        <img class="img-fluid border rounded p-2 bg-white" style="max-width:170px" src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= rawurlencode($referralLink) ?>" alt="Referral QR code">
        <div class="small text-muted mt-1">Scan to register</div>
      </div>
    </div>
  </div>
</div>

<?php if ($pendingPayout || ($pendingRoyalty && (int) $pendingRoyalty['c'] > 0)): ?>
<div class="panel p-3 p-md-4 mt-3">
  <h2 class="h6 mb-3">Needs your attention</h2>
  <div class="list-stack">
    <?php if ($pendingPayout): ?>
      <div class="list-item">
        <div class="list-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>
        <div class="flex-grow-1">
          <div class="fw-semibold">Auto payout <?= e((string) $pendingPayout['request_code']) ?> · <?= (string) $pendingPayout['status'] === 'processing' ? 'In Progress' : ucfirst((string) $pendingPayout['status']) ?></div>
          <div class="small text-muted"><?= money((float) $pendingPayout['net_amount']) ?> net · added <?= e(format_date((string) $pendingPayout['created_at'], 'd M Y')) ?> · no request needed</div>
        </div>
        <a class="btn btn-sm btn-light-green" href="<?= e(Url::member('payouts.php')) ?>">View</a>
      </div>
    <?php endif; ?>
    <?php if ($pendingRoyalty && (int) $pendingRoyalty['c'] > 0): ?>
      <div class="list-item">
        <div class="list-icon"><i class="fa-solid fa-trophy"></i></div>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= (int) $pendingRoyalty['c'] ?> royalty achievement awaiting approval</div>
          <div class="small text-muted">Rewards are credited after admin verification.</div>
        </div>
        <a class="btn btn-sm btn-light-green" href="<?= e(Url::member('royalty.php')) ?>">View</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Recent commissions</h2>
        <a class="small" href="<?= e(Url::member('commissions.php')) ?>">View all</a>
      </div>
      <?php if (!$recentCommissions): ?>
        <p class="small text-muted mb-0">No commissions yet. Commission is credited when your referral places a paid product order.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentCommissions as $row): ?>
              <tr>
                <td class="small"><?= e(format_date((string) $row['created_at'], 'd M y')) ?></td>
                <td class="small"><?= e(ucwords(str_replace('_', ' ', (string) $row['commission_type']))) ?></td>
                <td class="text-end small fw-semibold"><?= money((float) $row['amount']) ?></td>
                <td><?= status_badge((string) $row['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Newest referrals</h2>
        <a class="small" href="<?= e(Url::member('referrals.php')) ?>">View all</a>
      </div>
      <?php if (!$recentReferrals): ?>
        <p class="small text-muted mb-0">No referrals yet. Share your link to invite farmers and retailers.</p>
      <?php else: ?>
        <div class="list-stack">
          <?php foreach ($recentReferrals as $ref): ?>
            <div class="list-item">
              <div class="avatar-circle"><?= e(initials((string) $ref['name'])) ?></div>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-truncate"><?= e((string) $ref['name']) ?></div>
                <div class="small text-muted"><?= e((string) $ref['member_code']) ?> · <?= e(format_date((string) $ref['joined_at'], 'd M Y')) ?></div>
              </div>
              <?= status_badge((string) $ref['status']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel p-3 p-md-4 mt-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h2 class="h6 mb-0">Your orders</h2>
      <div class="small text-muted"><?= count($memberOrders) ?> order(s) placed with your member ID</div>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(Url::member('orders.php')) ?>">
      <i class="fa-solid fa-bag-shopping me-1" aria-hidden="true"></i>Purchase history
    </a>
  </div>

  <?php if (!$memberOrders): ?>
    <p class="small text-muted mb-0">No orders yet. Products you buy with your member ID appear here with a printable invoice.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th class="text-end">Items</th>
            <th class="text-end">Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th class="text-end no-sort">Invoice</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($memberOrders as $o): ?>
          <tr>
            <td class="small fw-semibold"><?= e((string) $o['order_number']) ?></td>
            <td class="small text-nowrap"><?= e(format_date((string) $o['created_at'], 'd M Y')) ?></td>
            <td class="small text-end"><?= (int) $o['item_count'] ?> (<?= (int) $o['total_qty'] ?>)</td>
            <td class="small text-end fw-semibold text-nowrap"><?= money((float) $o['grand_total']) ?></td>
            <td><?= status_badge((string) $o['payment_status']) ?></td>
            <td><?= status_badge((string) $o['status']) ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary"
                      data-invoice-id="<?= (int) $o['id'] ?>"
                      data-invoice-label="<?= e((string) ($o['invoice_number'] ?: $o['order_number'])) ?> · <?= e(format_date((string) $o['created_at'], 'd M Y')) ?>">
                <i class="fa-solid fa-file-invoice me-1" aria-hidden="true"></i>Invoice
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="quick-grid mt-3">
  <a class="quick-tile" href="<?= e(Url::member('payouts.php')) ?>"><i class="fa-solid fa-money-bill-transfer"></i><span>Payout statement</span></a>
  <a class="quick-tile" href="<?= e(Url::member('genealogy.php')) ?>"><i class="fa-solid fa-network-wired"></i><span>Genealogy</span></a>
  <a class="quick-tile" href="<?= e(Url::member('statements.php')) ?>"><i class="fa-solid fa-file-arrow-down"></i><span>Statements</span></a>
  <a class="quick-tile" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-seedling"></i><span>Buy products</span></a>
</div>
<?php
$extraScripts = '<script src="' . e(Url::asset('vendor/chart.js/chart.umd.js')) . '" defer></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  var el = document.getElementById("growthChart");
  if (!el || !window.Chart) { return; }
  var series = ' . json_encode($chartSeries, JSON_THROW_ON_ERROR) . ';
  new Chart(el, {
    type: "line",
    data: {
      labels: series.labels,
      datasets: [{
        label: "Direct referrals",
        data: series.direct,
        borderColor: "#247A3C",
        backgroundColor: "rgba(36,122,60,.14)",
        fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: "#247A3C"
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "rgba(0,0,0,.05)" } }, x: { grid: { display: false } } }
    }
  });
  document.getElementById("qrToggle")?.addEventListener("click", function () {
    document.getElementById("qrBox").classList.toggle("d-none");
  });
});
</script>';
require __DIR__ . '/includes/layout-end.php';
