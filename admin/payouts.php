<?php
/**
 * Payout processing: approve / reject / mark paid with UTR + proof.
 * Auto payouts appear here without any member request; downloading the report
 * moves them to "in progress" (processing) and marking paid completes them.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('payouts.approve');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\PayoutService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
$service = new PayoutService($db);

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    $requestId = Request::int('request_id');
    if ($action === 'approve') {
        $result = $service->approve($requestId, (string) Request::input('note', ''));
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    } elseif ($action === 'reject') {
        $result = $service->reject($requestId, (string) Request::input('reason', 'Rejected by admin'));
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    } elseif ($action === 'paid' && AdminAuth::can('payouts.pay')) {
        $proof = null;
        if (!empty($_FILES['proof']['name'])) {
            $up = Uploader::store($_FILES['proof'], 'payouts', Uploader::DOCUMENT, false);
            if ($up['ok']) { $proof = $up['path']; }
        }
        $result = $service->markPaid($requestId, (string) Request::input('utr', ''), $proof, (string) Request::input('mode', 'bank_transfer'));
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    }
    Request::redirect('payouts.php?' . http_build_query(Request::only(['status'])));
}

// Keep the register current: eligible earnings become pending auto payouts.
try {
    $service->syncAutoPayouts();
} catch (\Throwable $e) {
    error_log('payout auto sync failed: ' . $e->getMessage());
}

$status = (string) Request::query('status', 'pending');
$where = '1=1';
$params = [];
if (in_array($status, ['pending', 'approved', 'processing', 'rejected', 'paid', 'cancelled'], true)) {
    $where = 'p.status = :s';
    $params['s'] = $status;
}
$total = (int) $db->value("SELECT COUNT(*) FROM payout_requests p WHERE $where", $params);
$pager = new Pager($total, 20);
$rows = $db->select(
    "SELECT p.*, m.name, m.member_code, m.mobile FROM payout_requests p JOIN members m ON m.id = p.member_id
     WHERE $where ORDER BY p.id DESC" . $pager->limitSql(),
    $params
);
$stats = $service->stats();

$pageTitle = 'Payouts';
$pageHeading = 'Payout Requests';
$pageSub = $total . ' ' . e($status) . ' request(s)';
$activeModule = 'payouts';
$pageActions = AdminAuth::can('payouts.reports') ? '<a class="btn btn-sm btn-outline-secondary" href="payout-reports.php">Reports &amp; bulk export</a>' : '';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Pending (in report)</span><strong><?= number_format((int) ($stats['pending'] ?? 0)) ?></strong><small><?= money((float) ($stats['pending_amount'] ?? 0)) ?></small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>In progress</span><strong><?= number_format((int) ($stats['processing'] ?? 0)) ?></strong><small><?= money((float) ($stats['processing_amount'] ?? 0)) ?> · report downloaded</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Paid total</span><strong><?= money((float) ($stats['paid_amount'] ?? 0)) ?></strong><small>Lifetime · <?= (int) ($stats['auto_count'] ?? 0) ?> auto</small></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Rejected</span><strong><?= number_format((int) ($stats['rejected'] ?? 0)) ?></strong><small>Approved <?= number_format((int) ($stats['approved'] ?? 0)) ?></small></div></div>
</div>

<div class="filter-bar mb-3">
  <?php foreach (['pending' => 'Pending', 'processing' => 'In Progress', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
    <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="panel p-3 p-md-4">
  <?php if (!$rows): ?><p class="small text-muted mb-0">No payout requests in this view.</p><?php endif; ?>
  <?php foreach ($rows as $row):
      $bank = $row['bank_snapshot'] ? json_decode((string) $row['bank_snapshot'], true) : null;
      $isAuto = (string) ($row['source'] ?? 'manual') === 'auto';
      ?>
    <div class="border rounded p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <div class="small fw-semibold"><?= e((string) $row['request_code']) ?> · <?= e((string) $row['name']) ?> (<?= e((string) $row['member_code']) ?>)
            <span class="badge <?= $isAuto ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-muted border' ?> ms-1"><?= $isAuto ? 'Auto' : 'Manual' ?></span>
          </div>
          <div class="small text-muted">Requested <?= e(format_date((string) $row['created_at'], 'd M Y H:i')) ?> · <?= e((string) $row['mobile']) ?></div>
        </div>
        <div class="text-end">
          <div class="fw-semibold"><?= money((float) $row['net_amount']) ?> <span class="small text-muted fw-normal">net</span></div>
          <div class="small text-muted">Gross <?= money((float) $row['amount']) ?> · TDS <?= money((float) $row['tds_amount']) ?> · Fee <?= money((float) $row['admin_charges']) ?></div>
        </div>
      </div>
      <?php if ($bank): ?>
        <div class="small text-muted mt-2">
          <i class="fa-solid fa-building-columns me-1"></i>
          <?= e((string) ($bank['account_holder_name'] ?? '')) ?> · <?= e((string) ($bank['bank_name'] ?? '')) ?> ·
          <?= e(Security::maskAccount((string) ($bank['account_number'] ?? ''))) ?> · <?= e((string) ($bank['ifsc_code'] ?? '')) ?>
          <?php if (AdminAuth::can('bank.view') && !empty($row['bank_account_id'])): ?>
            <a href="document.php?type=bank&id=<?= (int) $row['bank_account_id'] ?>&field=cheque_image" target="_blank" rel="noopener">(cheque)</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="mt-2"><?= status_badge((string) $row['status']) ?>
        <?php if (!empty($row['report_downloaded_at'])): ?><span class="small text-muted ms-2">Report downloaded <?= e(format_date((string) $row['report_downloaded_at'], 'd M Y H:i')) ?></span><?php endif; ?>
        <?php if ($row['utr_number']): ?><span class="small text-muted ms-2">UTR <?= e((string) $row['utr_number']) ?></span><?php endif; ?>
        <?php if ($row['rejection_reason']): ?><span class="small text-danger ms-2"><?= e((string) $row['rejection_reason']) ?></span><?php endif; ?>
      </div>
      <?php if ($row['status'] === 'pending'): ?>
        <div class="d-flex flex-column flex-sm-row gap-2 mt-2">
          <form method="post" class="d-flex gap-2 flex-grow-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
            <input class="form-control form-control-sm" name="note" placeholder="Approval note">
            <button class="btn btn-sm btn-success text-nowrap">Approve</button>
          </form>
          <form method="post" class="d-flex gap-2" data-confirm="Reject this payout request?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
            <input class="form-control form-control-sm" name="reason" placeholder="Rejection reason" required>
            <button class="btn btn-sm btn-outline-danger text-nowrap">Reject</button>
          </form>
        </div>
      <?php elseif (in_array((string) $row['status'], ['approved', 'processing'], true) && AdminAuth::can('payouts.pay')): ?>
        <form method="post" enctype="multipart/form-data" class="row g-2 mt-2" data-confirm="Mark this payout as paid? The member will see Payment Completed with this UTR.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="paid">
          <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
          <div class="col-6 col-md-4"><input class="form-control form-control-sm" name="utr" placeholder="UTR / reference" required></div>
          <div class="col-6 col-md-3">
            <select class="form-select form-select-sm" name="mode">
              <option value="bank_transfer">NEFT / IMPS</option>
              <option value="upi">UPI</option>
              <option value="cash">Cash</option>
              <option value="cheque">Cheque</option>
            </select>
          </div>
          <div class="col-md-3"><input type="file" name="proof" class="form-control form-control-sm" accept="image/*,application/pdf"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Mark paid</button></div>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['status' => $status]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
