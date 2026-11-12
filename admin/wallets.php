<?php
/** Wallet overview + member ledger with manual adjustment. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('wallets.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\WalletService;

$db = db();
$walletService = new WalletService($db);
$memberId = Request::int('member');

if (is_post() && AdminAuth::can('wallets.adjust')) {
    Security::verifyCsrf();
    $targetId = Request::int('member_id');
    $amount = round((float) Request::input('amount', 0), 2);
    $direction = Request::input('direction') === 'debit' ? 'debit' : 'credit';
    $type = (string) Request::input('wallet_type', 'adjustment');
    $reason = trim((string) Request::input('reason', ''));
    if ($amount <= 0) {
        Session::error('Enter an amount greater than zero.');
    } elseif ($reason === '') {
        Session::error('A reason is mandatory for manual wallet adjustments.');
    } else {
        try {
            $walletType = in_array($type, ['direct', 'grand_direct', 'royalty', 'incentive'], true) ? $type : 'incentive';
            $walletService->post($targetId, $walletType, $amount, [
                'direction' => $direction,
                'status' => 'available',
                'reference_type' => 'manual_adjustment',
                'description' => 'Adjustment by ' . $adminUser['name'] . ': ' . $reason,
            ]);
            // Refresh pending/approved/available projections from the ledger so the
            // adjustment immediately counts towards the member's withdrawable balance.
            $walletService->recalculate($targetId);
            Audit::log('wallet_adjustment', 'wallets', $direction . ' ' . money($amount) . ' (' . $type . ') for member #' . $targetId . ': ' . $reason, 'wallet_accounts', $targetId);
            Session::success('Wallet adjusted and logged.');
        } catch (Throwable $e) {
            Session::error('Adjustment failed: ' . $e->getMessage());
        }
    }
    Request::redirect('wallets.php?member=' . $targetId);
}

if ($memberId) {
    $member = $db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
    if (!$member) {
        Session::error('Member not found.');
        Request::redirect('wallets.php');
    }
    $account = $walletService->account($memberId);
    $total = (int) $db->value('SELECT COUNT(*) FROM wallet_transactions WHERE member_id = :m', ['m' => $memberId]);
    $pager = new Pager($total, 25);
    $ledger = $db->select('SELECT * FROM wallet_transactions WHERE member_id = :m ORDER BY id DESC' . $pager->limitSql(), ['m' => $memberId]);
}

$search = trim((string) Request::query('q', ''));
$where = '1=1';
$params = [];
if ($search !== '') {
    $where = '(m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$totalMembers = (int) $db->value("SELECT COUNT(*) FROM members m WHERE $where", $params);
$memberPager = new Pager($totalMembers, 20);
$members = $db->select(
    "SELECT m.id, m.name, m.member_code, m.status, w.available_balance, w.pending_balance, w.total_earned, w.total_withdrawn
     FROM members m LEFT JOIN wallet_accounts w ON w.member_id = m.id WHERE $where ORDER BY w.available_balance DESC, m.id DESC" . $memberPager->limitSql(),
    $params
);
$summary = $db->first('SELECT COALESCE(SUM(available_balance),0) AS available, COALESCE(SUM(pending_balance),0) AS pending, COALESCE(SUM(total_earned),0) AS earned, COALESCE(SUM(total_withdrawn),0) AS withdrawn FROM wallet_accounts') ?? [];

$pageTitle = 'Wallets';
$pageHeading = $memberId ? 'Wallet · ' . $member['name'] : 'Wallet Overview';
$pageSub = $memberId ? e((string) $member['member_code']) : 'Company-wide wallet liability';
$activeModule = 'wallets';
$pageActions = $memberId ? '<a class="btn btn-sm btn-outline-secondary" href="wallets.php">All wallets</a>' : '';
$formOpen = $memberId && AdminAuth::can('wallets.adjust') && (Request::query('new') !== null || !empty($errors));
if ($formOpen || ($memberId && AdminAuth::can('wallets.adjust'))) {
    $pageActions = '<a class="btn btn-sm btn-primary" href="wallets.php?member=' . (int) $memberId . '&new=1"><i class="fa-solid fa-plus me-1"></i>Adjustment</a>' . $pageActions;
}
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Available (liability)</span><strong><?= money((float) ($summary['available'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Pending</span><strong><?= money((float) ($summary['pending'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total earned</span><strong><?= money((float) ($summary['earned'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Withdrawn</span><strong><?= money((float) ($summary['withdrawn'] ?? 0)) ?></strong></div></div>
</div>

<?php if ($memberId): ?>
  <div class="crud-layout<?= $formOpen ? ' is-form-open' : '' ?>">
    <?php if (AdminAuth::can('wallets.adjust')): ?>
    <div class="crud-form-col<?= $formOpen ? '' : ' d-none' ?>" id="crudForm">
      <div class="panel p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">Manual adjustment</h2>
          <a class="btn btn-sm btn-outline-secondary" href="wallets.php?member=<?= (int) $memberId ?>">Cancel</a>
        </div>
        <div class="alert alert-warning small py-2">Every adjustment is written to the immutable ledger and the audit log.</div>
        <form method="post" data-confirm="Apply this wallet adjustment?">
          <?= csrf_field() ?>
          <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
          <div class="mb-2"><label class="form-label small">Direction</label>
            <select class="form-select form-select-sm" name="direction"><option value="credit">Credit</option><option value="debit">Debit</option></select>
          </div>
          <div class="mb-2"><label class="form-label small">Wallet</label>
            <select class="form-select form-select-sm" name="wallet_type">
              <option value="incentive">Adjustment / incentive</option>
              <option value="direct">Direct</option>
              <option value="grand_direct">Grand direct</option>
              <option value="royalty">Royalty</option>
            </select>
          </div>
          <div class="mb-2"><label class="form-label small">Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Reason (required)</label><textarea name="reason" class="form-control form-control-sm" rows="2" required></textarea></div>
          <button class="btn btn-sm btn-primary w-100">Post adjustment</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="crud-list-col">
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3">Ledger</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 data-table">
            <thead><tr><th>Date</th><th>Code</th><th>Type</th><th>Description</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $row): ?>
              <tr>
                <td class="small"><?= e(format_date((string) $row['created_at'], 'd M Y H:i')) ?></td>
                <td class="small text-muted"><?= e((string) $row['transaction_code']) ?></td>
                <td class="small"><?= e(ucwords(str_replace('_', ' ', (string) $row['wallet_type']))) ?></td>
                <td class="small"><?= e((string) $row['description']) ?></td>
                <td class="small text-end <?= $row['direction'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $row['direction'] === 'credit' ? '+' : '−' ?><?= money((float) $row['amount']) ?></td>
                <td class="small text-end text-muted"><?= money((float) $row['balance_after']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$ledger): ?><tr><td colspan="6" class="small text-muted">No transactions.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
          <div class="small text-muted"><?= $pager->infoText() ?></div>
          <?= $pager->links(['member' => $memberId]) ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3">Balance</h2>
        <div class="small">
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Available</span><strong><?= money((float) $account['available_balance']) ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Pending</span><strong><?= money((float) $account['pending_balance']) ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Direct</span><strong><?= money((float) $account['direct_balance']) ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Grand direct</span><strong><?= money((float) $account['grand_direct_balance']) ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Royalty</span><strong><?= money((float) $account['royalty_balance']) ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Incentive</span><strong><?= money((float) $account['incentive_balance']) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="panel p-3 p-md-4">
    <form class="d-flex gap-2 mb-3" method="get">
      <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search members">
      <button class="btn btn-sm btn-dark">Search</button>
    </form>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead><tr><th>Member</th><th class="text-end">Available</th><th class="text-end">Pending</th><th class="text-end">Earned</th><th class="text-end">Withdrawn</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $row): ?>
          <tr>
            <td data-label="Member"><div class="small fw-semibold"><?= e((string) $row['name']) ?></div><div class="small text-muted"><?= e((string) $row['member_code']) ?></div></td>
            <td data-label="Available" class="text-end small fw-semibold"><?= money((float) ($row['available_balance'] ?? 0)) ?></td>
            <td data-label="Pending" class="text-end small"><?= money((float) ($row['pending_balance'] ?? 0)) ?></td>
            <td data-label="Earned" class="text-end small"><?= money((float) ($row['total_earned'] ?? 0)) ?></td>
            <td data-label="Withdrawn" class="text-end small"><?= money((float) ($row['total_withdrawn'] ?? 0)) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light-green" href="?member=<?= (int) $row['id'] ?>"><i class="fa-solid fa-book"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-3">
      <div class="small text-muted"><?= $memberPager->infoText() ?></div>
      <?= $memberPager->links(['q' => $search]) ?>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
