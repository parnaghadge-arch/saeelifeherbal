<?php
/** Member directory with filters, export and status actions. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('members.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\MemberService;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$service = new MemberService($db);

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    $id = Request::int('id');
    if ($action === 'status' && AdminAuth::can('members.manage')) {
        $status = (string) Request::input('status', 'active');
        $result = $service->setStatus($id, $status, (string) Request::input('reason', ''));
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
        Request::redirect('members.php');
    }
}

$search = trim((string) Request::query('q', ''));
$status = (string) Request::query('status', '');
$kyc = (string) Request::query('kyc', '');
$from = (string) Request::query('from', '');
$to = (string) Request::query('to', '');
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q OR m.email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (in_array($status, ['active', 'inactive', 'suspended', 'pending'], true)) {
    $where[] = 'm.status = :status';
    $params['status'] = $status;
}
if ($kyc !== '') {
    $where[] = 'm.kyc_status = :kyc';
    $params['kyc'] = $kyc;
}
if ($from !== '' && strtotime($from)) { $where[] = 'm.joined_at >= :from'; $params['from'] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to)) { $where[] = 'm.joined_at <= :to'; $params['to'] = date('Y-m-d 23:59:59', strtotime($to)); }
$whereSql = implode(' AND ', $where);

if (Request::query('export') === 'csv' && AdminAuth::can('members.export')) {
    $rows = $db->select("SELECT m.member_code, m.name, m.mobile, m.email, m.status, m.kyc_status, m.role,
                                m.direct_referral_count, m.downline_count, m.total_earned, m.joined_at, s.member_code AS sponsor_code
                         FROM members m LEFT JOIN members s ON s.id = m.sponsor_id WHERE $whereSql ORDER BY m.id DESC LIMIT 5000", $params);
    $out = [['Member ID', 'Name', 'Mobile', 'Email', 'Status', 'KYC', 'Role', 'Directs', 'Team', 'Total earned', 'Joined', 'Sponsor']];
    foreach ($rows as $r) {
        $out[] = [$r['member_code'], $r['name'], $r['mobile'], $r['email'], $r['status'], $r['kyc_status'], $r['role'], $r['direct_referral_count'], $r['downline_count'], $r['total_earned'], $r['joined_at'], (string) ($r['sponsor_code'] ?? '')];
    }
    Response::csv('members-' . date('Ymd') . '.csv', array_slice($out, 1), $out[0]);
}

$total = (int) $db->value("SELECT COUNT(*) FROM members m WHERE $whereSql", $params);
$pager = new Pager($total, 25);
$rows = $db->select(
    "SELECT m.*, s.member_code AS sponsor_code, s.name AS sponsor_name,
            w.available_balance, w.total_earned AS wallet_earned
     FROM members m
     LEFT JOIN members s ON s.id = m.sponsor_id
     LEFT JOIN wallet_accounts w ON w.member_id = m.id
     WHERE $whereSql ORDER BY m.id DESC" . $pager->limitSql(),
    $params
);
$stats = $service->stats();

$pageTitle = 'Members';
$pageHeading = 'Members';
$pageSub = $total . ' member(s) · ' . (int) ($stats['active'] ?? 0) . ' active';
$activeModule = 'members';
$pageActions = AdminAuth::can('members.export')
    ? '<a class="btn btn-sm btn-outline-secondary" href="?export=csv"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>' : '';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Total members</span><strong><?= number_format((int) ($stats['total'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>Active</span><strong><?= number_format((int) ($stats['active'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>KYC approved</span><strong><?= number_format((int) ($stats['kyc_approved'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-md-3"><div class="mini-stat"><span>New this month</span><strong><?= number_format((int) ($stats['new_this_month'] ?? 0)) ?></strong><small>Joined this month</small></div></div>
</div>

<div class="panel p-3 p-md-4">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-12 col-md-3"><label class="form-label small text-muted mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Name / ID / mobile / email"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (['active', 'inactive', 'suspended', 'pending'] as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">KYC</label>
      <select class="form-select form-select-sm" name="kyc"><option value="">All</option>
        <?php foreach (['not_submitted', 'submitted', 'under_verification', 'approved', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $kyc === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Joined from</label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-1"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Member</th><th>Sponsor</th><th>Status</th><th>KYC</th><th class="text-end">Team</th><th class="text-end">Wallet</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Member">
            <div class="fw-semibold small"><?= e((string) $row['name']) ?><?= (int) $row['is_company'] === 1 ? ' <span class="chip">company</span>' : '' ?></div>
            <div class="small text-muted"><?= e((string) $row['member_code']) ?> · <?= e((string) $row['mobile']) ?></div>
          </td>
          <td data-label="Sponsor" class="small"><?= e((string) ($row['sponsor_name'] ?? '—')) ?><div class="text-muted"><?= e((string) ($row['sponsor_code'] ?? '')) ?></div></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td data-label="KYC"><?= status_badge((string) $row['kyc_status']) ?></td>
          <td data-label="Team" class="text-end small"><?= (int) $row['direct_referral_count'] ?> / <?= (int) $row['downline_count'] ?></td>
          <td data-label="Wallet" class="text-end small"><?= money((float) ($row['available_balance'] ?? 0)) ?><div class="text-muted">earned <?= money((float) ($row['wallet_earned'] ?? 0)) ?></div></td>
          <td class="text-end">
            <a class="btn btn-sm btn-light-green" href="member-details.php?id=<?= (int) $row['id'] ?>"><i class="fa-solid fa-eye"></i></a>
            <?php if (AdminAuth::can('members.manage')): ?>
              <form method="post" class="d-inline" data-confirm="Toggle this member's status?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'suspended' : 'active' ?>">
                <input type="hidden" name="reason" value="Status changed from admin directory">
                <button class="btn btn-sm btn-outline-secondary" title="Toggle status"><i class="fa-solid fa-power-off"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'status' => $status, 'kyc' => $kyc, 'from' => $from, 'to' => $to]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
