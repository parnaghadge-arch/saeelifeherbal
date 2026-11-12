<?php
/** Contact enquiries / support tickets. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('enquiries.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\Notification;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
if (is_post() && AdminAuth::can('enquiries.manage')) {
    Security::verifyCsrf();
    $id = Request::int('id');
    $enquiry = $db->first('SELECT * FROM contact_enquiries WHERE id = :id', ['id' => $id]);
    if ($enquiry) {
        if (Request::input('action') === 'reply') {
            $db->update('contact_enquiries', [
                'admin_reply' => (string) Request::input('reply', ''),
                'replied_at' => date('Y-m-d H:i:s'),
                'status' => 'resolved',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            Audit::log('reply', 'enquiries', 'Replied to enquiry #' . $id, 'contact_enquiries', $id);
            Session::success('Reply saved.');
        } else {
            $db->update('contact_enquiries', ['status' => (string) Request::input('status', 'read'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Session::success('Status updated.');
        }
    }
    Request::redirect('enquiries.php');
}

$status = (string) Request::query('status', '');
$where = '1=1';
$params = [];
if (in_array($status, ['new', 'read', 'resolved', 'spam'], true)) { $where = 'status = :s'; $params['s'] = $status; }
$total = (int) $db->value("SELECT COUNT(*) FROM contact_enquiries WHERE $where", $params);
$pager = new Pager($total, 20);
$rows = $db->select("SELECT * FROM contact_enquiries WHERE $where ORDER BY id DESC" . $pager->limitSql(), $params);
$counts = $db->first("SELECT SUM(CASE WHEN status='new' THEN 1 ELSE 0 END) AS new_count, COUNT(*) AS total FROM contact_enquiries") ?? [];

$pageTitle = 'Enquiries';
$pageHeading = 'Enquiries & Support';
$pageSub = (int) ($counts['new_count'] ?? 0) . ' new · ' . (int) ($counts['total'] ?? 0) . ' total';
$activeModule = 'enquiries';
require __DIR__ . '/includes/layout.php';
?>
<div class="filter-bar mb-3">
  <?php foreach (['' => 'All', 'new' => 'New', 'read' => 'Read', 'resolved' => 'Resolved', 'spam' => 'Spam'] as $key => $label): ?>
    <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="panel p-3 p-md-4">
  <?php if (!$rows): ?><p class="small text-muted mb-0">No enquiries.</p><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <div class="border rounded p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <div class="small fw-semibold"><?= e((string) $row['subject']) ?></div>
          <div class="small text-muted"><?= e((string) $row['name']) ?> · <?= e((string) ($row['phone'] ?? '')) ?> · <?= e((string) ($row['email'] ?? '')) ?> · <?= e(time_ago((string) $row['created_at'])) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2"><?= status_badge((string) $row['status']) ?><span class="chip"><?= e((string) ($row['source_page'] ?? 'website')) ?></span></div>
      </div>
      <p class="small mt-2 mb-2"><?= nl2br(e((string) $row['message'])) ?></p>
      <?php if (!empty($row['admin_reply'])): ?>
        <div class="alert alert-light border small mb-2"><strong>Reply:</strong> <?= nl2br(e((string) $row['admin_reply'])) ?><div class="text-muted"><?= e(time_ago((string) $row['replied_at'])) ?></div></div>
      <?php endif; ?>
      <?php if (AdminAuth::can('enquiries.manage')): ?>
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <div class="col-12 col-md-8"><input class="form-control form-control-sm" name="reply" placeholder="Reply (stored and shown to the team)" value="<?= e((string) ($row['admin_reply'] ?? '')) ?>"></div>
          <div class="col-6 col-md-2">
            <select class="form-select form-select-sm" name="status">
              <?php foreach (['new', 'read', 'resolved', 'spam'] as $s): ?><option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2 d-flex gap-1">
            <button class="btn btn-sm btn-primary flex-grow-1" name="action" value="reply">Save</button>
            <button class="btn btn-sm btn-outline-secondary" name="action" value="status">Set</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="d-flex justify-content-between align-items-center mt-2 server-pager">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['status' => $status]) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
