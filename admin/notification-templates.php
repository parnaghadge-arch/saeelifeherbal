<?php
/** Notification templates (all lifecycle events, editable, per channel). */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('settings.notifications');

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
if (is_post()) {
    Security::verifyCsrf();
    $id = Request::int('id');
    $template = $id ? $db->first('SELECT * FROM notification_templates WHERE id = :id', ['id' => $id]) : null;
    $data = [
        'name' => (string) Request::input('name', ''),
        'channel' => in_array(Request::input('channel'), ['app', 'email', 'sms', 'whatsapp'], true) ? (string) Request::input('channel') : 'app',
        'subject' => (string) Request::input('subject', ''),
        'body' => (string) Request::raw('body', ''),
        'variables' => (string) Request::input('variables', ''),
        'status' => Request::bool('status') ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($template) {
        $db->update('notification_templates', $data, 'id = :id', ['id' => $id]);
        Audit::log('update', 'settings', 'Updated notification template ' . $template['code'], 'notification_templates', $id);
        Session::success('Template updated.');
    }
    Request::redirect('notification-templates.php' . ($id ? '?id=' . $id : ''));
}

$edit = Request::int('id') ? $db->first('SELECT * FROM notification_templates WHERE id = :id', ['id' => Request::int('id')]) : null;
$channel = (string) Request::query('channel', '');
$where = '1=1';
$params = [];
if (in_array($channel, ['app', 'email', 'sms', 'whatsapp'], true)) { $where = 'channel = :c'; $params['c'] = $channel; }
$templates = $db->select("SELECT * FROM notification_templates WHERE $where ORDER BY code ASC", $params);
$counts = $db->first("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active FROM notification_templates") ?? [];

$pageTitle = 'Notification Templates';
$pageHeading = 'Notification Templates';
$pageSub = (int) ($counts['total'] ?? 0) . ' templates · ' . (int) ($counts['active'] ?? 0) . ' active';
$activeModule = 'notification-templates';
require __DIR__ . '/includes/layout.php';
?>
<div class="filter-bar mb-3">
  <?php foreach (['' => 'All channels', 'app' => 'In-app', 'email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $key => $label): ?>
    <a class="btn btn-sm <?= $channel === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?channel=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Templates</h2>
      <div class="list-stack" style="max-height:70vh;overflow:auto">
        <?php foreach ($templates as $template): ?>
          <a class="list-item <?= $edit && (int) $edit['id'] === (int) $template['id'] ? 'unread' : '' ?>" href="?id=<?= (int) $template['id'] ?>">
            <div class="flex-grow-1 min-w-0">
              <div class="small fw-semibold text-truncate"><?= e((string) $template['name']) ?></div>
              <div class="small text-muted text-truncate"><?= e((string) $template['code']) ?> · <?= e((string) $template['channel']) ?></div>
            </div>
            <?= status_badge((int) $template['status'] === 1 ? 'active' : 'inactive') ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$templates): ?><p class="small text-muted mb-0">No templates in this channel.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <?php if (!$edit): ?>
        <h2 class="h6 mb-2">Select a template</h2>
        <p class="small text-muted mb-0">Templates are rendered with <code>{variable}</code> placeholders. Email/SMS/WhatsApp delivery is pluggable from settings.</p>
      <?php else: ?>
        <h2 class="h6 mb-3"><?= e((string) $edit['name']) ?> <span class="chip"><?= e((string) $edit['code']) ?></span></h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" value="<?= e((string) $edit['name']) ?>" required></div>
            <div class="col-md-3"><label class="form-label small">Channel</label>
              <select name="channel" class="form-select form-select-sm">
                <?php foreach (['app', 'email', 'sms', 'whatsapp'] as $ch): ?><option value="<?= e($ch) ?>" <?= $edit['channel'] === $ch ? 'selected' : '' ?>><?= e($ch) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label small">Status</label>
              <select name="status" class="form-select form-select-sm"><option value="1" <?= (int) $edit['status'] === 1 ? 'selected' : '' ?>>Active</option><option value="0" <?= (int) $edit['status'] === 0 ? 'selected' : '' ?>>Inactive</option></select>
            </div>
            <div class="col-12"><label class="form-label small">Subject</label><input name="subject" class="form-control form-control-sm" value="<?= e((string) ($edit['subject'] ?? '')) ?>"></div>
            <div class="col-12"><label class="form-label small">Body</label><textarea name="body" class="form-control form-control-sm" rows="8"><?= e((string) $edit['body']) ?></textarea></div>
            <div class="col-12"><label class="form-label small">Variables</label><input name="variables" class="form-control form-control-sm" value="<?= e((string) ($edit['variables'] ?? '')) ?>" placeholder="member_name, order_number, amount"></div>
          </div>
          <button class="btn btn-primary mt-3">Save template</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
