<?php
/** Wallet summary + immutable ledger. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;
use Saee\Lib\WalletService;

$walletService = new WalletService(db());
$wallet = $walletService->account($currentMemberId);
$type = (string) Request::query('type', '');
$from = (string) Request::query('from', '');
$to = (string) Request::query('to', '');
$where = ['member_id = :m'];
$params = ['m' => $currentMemberId];
if (in_array($type, ['direct', 'grand_direct', 'royalty', 'incentive', 'payout', 'reversal', 'adjustment'], true)) {
    $where[] = 'wallet_type = :t';
    $params['t'] = $type;
}
if ($from !== '' && strtotime($from)) {
    $where[] = 'created_at >= :from';
    $params['from'] = date('Y-m-d 00:00:00', strtotime($from));
}
if ($to !== '' && strtotime($to)) {
    $where[] = 'created_at <= :to';
    $params['to'] = date('Y-m-d 23:59:59', strtotime($to));
}
$whereSql = implode(' AND ', $where);
$total = (int) db()->value("SELECT COUNT(*) FROM wallet_transactions WHERE $whereSql", $params);
$pager = new Pager($total, 25);
$rows = db()->select(
    "SELECT * FROM wallet_transactions WHERE $whereSql ORDER BY id DESC" . $pager->limitSql(),
    $params
);
$byType = db()->select(
    'SELECT wallet_type, SUM(CASE WHEN direction = :cr THEN amount ELSE 0 END) AS credit,
            SUM(CASE WHEN direction = :dr THEN amount ELSE 0 END) AS debit
     FROM wallet_transactions WHERE member_id = :m GROUP BY wallet_type',
    ['cr' => 'credit', 'dr' => 'debit', 'm' => $currentMemberId]
);
$breakdown = [];
foreach ($byType as $row) {
    $breakdown[$row['wallet_type']] = (float) $row['credit'] - (float) $row['debit'];
}

$pageTitle = 'My Wallet';
$pageHeading = 'My Wallet';
$pageSub = 'Every credit and debit is recorded in a permanent ledger';
$activeNav = 'wallet';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-12">
    <div class="wallet-hero">
      <div class="wallet-hero-label">Withdrawable balance</div>
      <div class="wallet-hero-value"><?= money((float) $wallet['available_balance']) ?></div>
      <div class="wallet-hero-grid">
        <div><span>Direct</span><strong><?= money((float) $wallet['direct_balance']) ?></strong></div>
        <div><span>Grand direct</span><strong><?= money((float) $wallet['grand_direct_balance']) ?></strong></div>
        <div><span>Royalty</span><strong><?= money((float) $wallet['royalty_balance']) ?></strong></div>
        <div><span>Incentive</span><strong><?= money((float) $wallet['incentive_balance']) ?></strong></div>
      </div>
      <a class="btn btn-light btn-sm mt-3" href="<?= e(Url::member('payout-request.php')) ?>"><i class="fa-solid fa-money-bill-transfer me-1"></i>Request payout</a>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Pending</span><strong><?= money((float) $wallet['pending_balance']) ?></strong><small>Awaiting hold / approval</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Approved</span><strong><?= money((float) $wallet['approved_balance']) ?></strong><small>Ready to release</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total earned</span><strong><?= money((float) $wallet['total_earned']) ?></strong><small>Lifetime credits</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Reversed</span><strong><?= money((float) $wallet['total_reversed']) ?></strong><small>Refunded / cancelled orders</small></div></div>
</div>

<div class="panel p-3 p-md-4 mt-3">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">Type</label>
      <select class="form-select form-select-sm" name="type">
        <option value="">All</option>
        <?php foreach (['direct', 'grand_direct', 'royalty', 'incentive', 'payout', 'reversal', 'adjustment'] as $t): ?>
          <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $t))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">From</label>
      <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">To</label>
      <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-6 col-md-3 d-flex gap-2">
      <button class="btn btn-sm btn-dark flex-grow-1">Filter</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('statements.php')) ?>">Export</a>
    </div>
  </form>

  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No wallet transactions for the selected filters.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Description</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Date" class="small"><?= e(format_date((string) $row['created_at'], 'd M Y H:i')) ?></td>
          <td data-label="Reference" class="small text-muted"><?= e((string) $row['transaction_code']) ?></td>
          <td data-label="Type" class="small"><?= e(ucwords(str_replace('_', ' ', (string) $row['wallet_type']))) ?></td>
          <td data-label="Description" class="small"><?= e((string) $row['description']) ?></td>
          <td data-label="Amount" class="text-end fw-semibold <?= $row['direction'] === 'credit' ? 'text-success' : 'text-danger' ?>">
            <?= $row['direction'] === 'credit' ? '+' : '−' ?><?= money((float) $row['amount']) ?>
          </td>
          <td data-label="Balance" class="text-end small text-muted"><?= money((float) $row['balance_after']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['type' => $type, 'from' => $from, 'to' => $to]) ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
