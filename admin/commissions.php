<?php
/** Commission ledger with filters, manual processing and reversals. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('wallets.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\MlmCommissionEngine;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$engine = new MlmCommissionEngine($db);

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    if ($action === 'reverse' && AdminAuth::can('mlm.reverse')) {
        $commissionId = Request::int('commission_id');
        $reason = trim((string) Request::input('reason', 'Reversed by admin'));
        $ok = $engine->reverseCommission($commissionId, $reason);
        Audit::log('commission_reversed', 'mlm', 'Commission #' . $commissionId . ' reversed: ' . $reason, 'commission_transactions', $commissionId);
        Session::{$ok ? 'success' : 'error'}($ok ? 'Commission reversed and ledger updated.' : 'This commission cannot be reversed.');
    } elseif ($action === 'process' && AdminAuth::can('mlm.process')) {
        $count = $engine->processEligibleOrders();
        Audit::log('process', 'mlm', 'Commission processing run processed ' . $count . ' order(s)', 'commission_transactions');
        Session::success('Processed ' . $count . ' eligible order(s).');
    } elseif ($action === 'release' && AdminAuth::can('mlm.process')) {
        $released = $engine->releaseMaturedCommissions();
        Audit::log('release', 'mlm', 'Released ' . $released . ' matured commission(s)', 'commission_transactions');
        Session::success('Released ' . $released . ' matured commission(s).');
    }
    Request::redirect('commissions.php');
}

$search = trim((string) Request::query('q', ''));
$type = (string) Request::query('type', '');
$status = (string) Request::query('status', '');
$from = (string) Request::query('from', '');
$to = (string) Request::query('to', '');
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(c.transaction_code LIKE :q OR c.product_name LIKE :q OR c.buyer_name LIKE :q OR m.member_code LIKE :q OR m.name LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (in_array($type, ['direct', 'grand_direct', 'royalty', 'incentive'], true)) { $where[] = 'c.commission_type = :t'; $params['t'] = $type; }
if (in_array($status, ['pending', 'approved', 'available', 'paid', 'reversed', 'cancelled'], true)) { $where[] = 'c.status = :s'; $params['s'] = $status; }
if ($from !== '' && strtotime($from)) { $where[] = 'c.created_at >= :f'; $params['f'] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to)) { $where[] = 'c.created_at <= :t2'; $params['t2'] = date('Y-m-d 23:59:59', strtotime($to)); }
$whereSql = implode(' AND ', $where);

$total = (int) $db->value("SELECT COUNT(*) FROM commission_transactions c LEFT JOIN members m ON m.id = c.member_id WHERE $whereSql", $params);
$summary = $db->first(
    "SELECT COALESCE(SUM(CASE WHEN c.status IN ('available','approved','paid') THEN c.amount ELSE 0 END),0) AS credited,
            COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END),0) AS pending,
            COALESCE(SUM(c.reversed_amount),0) AS reversed
     FROM commission_transactions c LEFT JOIN members m ON m.id = c.member_id WHERE $whereSql",
    $params
) ?? [];
$pager = new Pager($total, 25);
$rows = $db->select(
    "SELECT c.*, m.name AS member_name, m.member_code FROM commission_transactions c
     LEFT JOIN members m ON m.id = c.member_id WHERE $whereSql ORDER BY c.id DESC" . $pager->limitSql(),
    $params
);

$pageTitle = 'Commissions';
$pageHeading = 'Commission Ledger';
$pageSub = $total . ' entries · immutable, append-only history';
$activeModule = 'commissions';
$actions = '';
if (AdminAuth::can('mlm.process')) {
    $actions .= '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="process"><button class="btn btn-sm btn-primary">Run processing</button></form>';
    $actions .= ' <form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="release"><button class="btn btn-sm btn-outline-secondary">Release matured</button></form>';
}
$pageActions = $actions;
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-4"><div class="mini-stat"><span>Credited</span><strong><?= money((float) ($summary['credited'] ?? 0)) ?></strong></div></div>
  <div class="col-4"><div class="mini-stat"><span>Pending</span><strong><?= money((float) ($summary['pending'] ?? 0)) ?></strong></div></div>
  <div class="col-4"><div class="mini-stat"><span>Reversed</span><strong><?= money((float) ($summary['reversed'] ?? 0)) ?></strong></div></div>
</div>

<div class="panel p-3 p-md-4">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-12 col-md-3"><label class="form-label small text-muted mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Code / member / product"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Type</label>
      <select class="form-select form-select-sm" name="type"><option value="">All</option>
        <?php foreach (['direct' => 'Direct', 'grand_direct' => 'Grand direct', 'royalty' => 'Royalty', 'incentive' => 'Incentive'] as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (['pending', 'approved', 'available', 'paid', 'reversed'] as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-1"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Entry</th><th>Beneficiary</th><th>Type</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Entry"><div class="small fw-semibold"><?= e((string) $row['transaction_code']) ?></div><div class="small text-muted"><?= e(format_date((string) $row['created_at'], 'd M Y')) ?> · <?= e((string) $row['product_name']) ?></div></td>
          <td data-label="Beneficiary" class="small"><?= e((string) ($row['member_name'] ?? '—')) ?><div class="text-muted"><?= e((string) ($row['member_code'] ?? '')) ?></div></td>
          <td data-label="Type" class="small"><?= e(ucwords(str_replace('_', ' ', (string) $row['commission_type']))) ?><div class="text-muted">L<?= (int) $row['level'] ?> · <?= e((string) $row['rate_value']) ?><?= $row['commission_type_rate'] === 'percent' ? '%' : '' ?></div></td>
          <td data-label="Amount" class="text-end small fw-semibold"><?= money((float) $row['amount']) ?><?php if ((float) $row['reversed_amount'] > 0): ?><div class="text-danger">−<?= money((float) $row['reversed_amount']) ?></div><?php endif; ?></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td class="text-end">
            <?php if (AdminAuth::can('mlm.reverse') && (float) $row['reversed_amount'] <= 0 && !in_array($row['status'], ['reversed', 'cancelled'], true)): ?>
              <form method="post" class="d-flex gap-1" data-confirm="Reverse this commission?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reverse">
                <input type="hidden" name="commission_id" value="<?= (int) $row['id'] ?>">
                <input class="form-control form-control-sm" name="reason" placeholder="Reason" required style="max-width:150px">
                <button class="btn btn-sm btn-outline-danger">Reverse</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="small text-muted">No commission entries for these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'type' => $type, 'status' => $status, 'from' => $from, 'to' => $to]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
