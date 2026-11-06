<?php
/** KYC + bank verification queue. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('kyc.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    $kycId = Request::int('kyc_id');
    $kyc = $kycId ? $db->first('SELECT * FROM member_kyc WHERE id = :id', ['id' => $kycId]) : null;

    if ($action === 'kyc-decide' && $kyc && AdminAuth::can('kyc.verify')) {
        $decision = Request::input('decision') === 'approve' ? 'approved' : 'rejected';
        $data = [
            'status' => $decision,
            'admin_note' => (string) Request::input('admin_note', ''),
            'rejection_reason' => $decision === 'rejected' ? (string) Request::input('admin_note', '') : null,
            'verified_at' => $decision === 'approved' ? date('Y-m-d H:i:s') : null,
            'verified_by' => (int) $adminUser['id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->update('member_kyc', $data, 'id = :id', ['id' => $kycId]);
        $db->update('members', ['kyc_status' => $decision, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $kyc['member_id']]);
        \Saee\Lib\Notification::send('member', (int) $kyc['member_id'], 'kyc_' . $decision,
            'KYC ' . $decision,
            $decision === 'approved' ? 'Your KYC has been verified. Payouts are now enabled once your bank account is verified.' : 'Your KYC was rejected: ' . (string) Request::input('admin_note', ''),
            ['status' => $decision], ['link' => 'kyc', 'type' => $decision === 'approved' ? 'success' : 'danger']);
        \Saee\Lib\Audit::log($decision === 'approved' ? 'kyc_approved' : 'kyc_rejected', 'kyc', 'KYC #' . $kycId . ' ' . $decision, 'member_kyc', $kycId);
        Session::success('KYC ' . $decision . '.');
    } elseif ($action === 'bank-decide' && AdminAuth::can('bank.verify')) {
        $bankId = Request::int('bank_id');
        $bank = $db->first('SELECT * FROM member_bank_accounts WHERE id = :id', ['id' => $bankId]);
        if ($bank) {
            $decision = Request::input('decision') === 'approve' ? 'verified' : 'rejected';
            $db->update('member_bank_accounts', [
                'status' => $decision,
                'rejection_reason' => $decision === 'rejected' ? (string) Request::input('admin_note', '') : null,
                'verified_at' => $decision === 'verified' ? date('Y-m-d H:i:s') : null,
                'verified_by' => (int) $adminUser['id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $bankId]);
            \Saee\Lib\Audit::log('bank_' . $decision, 'kyc', 'Bank account #' . $bankId . ' ' . $decision, 'member_bank_accounts', $bankId);
            Session::success('Bank account ' . $decision . '.');
        }
    }
    Request::redirect('kyc.php');
}

$status = (string) Request::query('status', 'submitted');
$where = "k.status IN ('submitted','under_verification')";
$params = [];
if (in_array($status, ['approved', 'rejected', 'all'], true)) {
    $where = $status === 'all' ? '1=1' : 'k.status = :s';
    if ($status !== 'all') { $params['s'] = $status; }
}
$rows = $db->select(
    "SELECT k.*, m.name, m.member_code, m.mobile, m.sponsor_id FROM member_kyc k
     JOIN members m ON m.id = k.member_id WHERE $where ORDER BY k.id DESC LIMIT 200",
    $params
);
$banks = $db->select(
    "SELECT b.*, m.name, m.member_code FROM member_bank_accounts b JOIN members m ON m.id = b.member_id
     WHERE b.status = 'pending' ORDER BY b.id DESC LIMIT 100"
);

$pageTitle = 'KYC Verification';
$pageHeading = 'KYC & Bank Verification';
$pageSub = count($rows) . ' pending KYC · ' . count($banks) . ' bank accounts awaiting verification';
$activeModule = 'kyc';
require __DIR__ . '/includes/layout.php';
?>
<div class="filter-bar mb-3">
  <?php foreach (['submitted' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
    <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">KYC submissions</h2>
      <?php if (!$rows): ?><p class="small text-muted mb-0">Nothing to review.</p><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <div class="border rounded p-3 mb-3">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <div class="fw-semibold small"><?= e((string) $row['name']) ?> · <?= e((string) $row['member_code']) ?></div>
              <div class="small text-muted"><?= e((string) $row['mobile']) ?> · submitted <?= e(format_date((string) $row['submitted_at'], 'd M Y H:i')) ?></div>
            </div>
            <?= status_badge((string) $row['status']) ?>
          </div>
          <div class="row g-2 small mb-2">
            <div class="col-md-6">PAN: <strong><?= e(Security::maskPan(Security::decrypt((string) $row['pan_number']) ?? '')) ?></strong></div>
            <div class="col-md-6">Aadhaar: <strong><?= e(Security::maskAadhaar(Security::decrypt((string) $row['aadhaar_number']) ?? '')) ?></strong></div>
            <div class="col-md-6">Name on PAN: <?= e((string) $row['pan_name']) ?></div>
            <div class="col-md-6">DOB: <?= e((string) $row['date_of_birth']) ?></div>
            <div class="col-12">Address: <?= e((string) $row['address']) ?></div>
          </div>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <?php foreach (['pan_document' => 'PAN', 'identity_document' => 'ID', 'address_document' => 'Address proof', 'photo' => 'Photo'] as $field => $label): ?>
              <?php if (!empty($row[$field])): ?>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="document.php?type=kyc&id=<?= (int) $row['id'] ?>&field=<?= e($field) ?>"><?= e($label) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if (AdminAuth::can('bank.view')): ?>
              <a class="btn btn-sm btn-light-green" href="member-details.php?id=<?= (int) $row['member_id'] ?>">Member 360°</a>
            <?php endif; ?>
          </div>
          <?php if (AdminAuth::can('kyc.verify') && in_array($row['status'], ['submitted', 'under_verification'], true)): ?>
            <form method="post" class="d-flex flex-column flex-sm-row gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="kyc-decide">
              <input type="hidden" name="kyc_id" value="<?= (int) $row['id'] ?>">
              <input class="form-control form-control-sm" name="admin_note" placeholder="Note / rejection reason">
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button>
                <button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Bank accounts pending</h2>
      <?php if (!$banks): ?><p class="small text-muted mb-0">No pending bank accounts.</p><?php endif; ?>
      <?php foreach ($banks as $bank): ?>
        <div class="border rounded p-2 mb-2">
          <div class="small fw-semibold"><?= e((string) $bank['name']) ?> · <?= e((string) $bank['member_code']) ?></div>
          <div class="small text-muted"><?= e((string) $bank['account_holder_name']) ?> · <?= e((string) $bank['bank_name']) ?></div>
          <div class="small text-muted mb-2"><?= e(Security::maskAccount((string) $bank['account_number'])) ?> · <?= e((string) $bank['ifsc_code']) ?></div>
          <?php if (AdminAuth::can('bank.verify')): ?>
            <form method="post" class="d-flex gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="bank-decide">
              <input type="hidden" name="bank_id" value="<?= (int) $bank['id'] ?>">
              <input class="form-control form-control-sm" name="admin_note" placeholder="Note">
              <button class="btn btn-sm btn-success" name="decision" value="approve">Verify</button>
              <button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
