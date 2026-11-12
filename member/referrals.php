<?php
/** Direct referrals listing with search + export. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\MemberService;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;

$search = trim((string) Request::query('q', ''));
$status = (string) Request::query('status', '');
$where = ['m.sponsor_id = :sid'];
$params = ['sid' => $currentMemberId];
if ($search !== '') {
    $where[] = '(m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
    $where[] = 'm.status = :st';
    $params['st'] = $status;
}
$whereSql = implode(' AND ', $where);
$total = (int) db()->value("SELECT COUNT(*) FROM members m WHERE $whereSql", $params);
$pager = new Pager($total, 20);
$rows = db()->select(
    "SELECT m.*, (SELECT COUNT(*) FROM orders o WHERE o.member_id = m.id AND o.payment_status = 'paid') AS orders,
            (SELECT COALESCE(SUM(o.grand_total),0) FROM orders o WHERE o.member_id = m.id AND o.payment_status = 'paid') AS purchase_total,
            (SELECT COALESCE(SUM(c.amount),0) FROM commission_transactions c WHERE c.member_id = :me AND c.buyer_member_id = m.id AND c.status IN ('available','approved')) AS earned
     FROM members m WHERE $whereSql ORDER BY m.id DESC" . $pager->limitSql(),
    $params + ['me' => $currentMemberId]
);
$referralLink = (new MemberService(db()))->referralLink($member);

$pageTitle = 'My Referrals';
$pageHeading = 'My Referrals';
$pageSub = 'Unlimited direct referrals · ' . $total . ' total';
$activeNav = 'referrals';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div class="input-group input-group-sm" style="max-width:340px">
      <input class="form-control" id="referralLink" value="<?= e($referralLink) ?>" readonly>
      <button class="btn btn-primary" type="button" data-copy="#referralLink">Copy</button>
    </div>
    <form class="d-flex gap-2" method="get">
      <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search name / ID / mobile">
      <select class="form-select form-select-sm" name="status" style="width:auto">
        <option value="">All status</option>
        <?php foreach (['active', 'inactive', 'suspended'] as $st): ?>
          <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-dark">Filter</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No referrals found<?= $search !== '' ? ' for your search' : ' yet' ?>.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead>
        <tr><th>Member</th><th>Sponsor join date</th><th>Status</th><th>KYC</th><th class="text-end">Purchases</th><th class="text-end">Commission earned</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Member">
            <div class="fw-semibold"><?= e((string) $row['name']) ?></div>
            <div class="small text-muted"><?= e((string) $row['member_code']) ?> · <?= e((string) $row['mobile']) ?></div>
          </td>
          <td data-label="Joined" class="small"><?= e(format_date((string) $row['joined_at'], 'd M Y')) ?></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td data-label="KYC"><?= status_badge((string) $row['kyc_status']) ?></td>
          <td data-label="Purchases" class="text-end small"><?= money((float) $row['purchase_total']) ?><div class="text-muted"><?= (int) $row['orders'] ?> orders</div></td>
          <td data-label="Earned" class="text-end fw-semibold"><?= money((float) $row['earned']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'status' => $status]) ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel p-3 p-md-4">
  <h2 class="h6 mb-2">How direct commission works</h2>
  <p class="small text-muted mb-0">
    When a member you personally referred buys a product, you may earn the direct commission configured on that product.
    Grand-direct commission is credited to your sponsor for the same purchase. Commission is released only on paid orders and is
    reversed automatically if that order is refunded or cancelled.
  </p>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
