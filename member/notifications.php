<?php
/** Notification inbox. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Notification;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;

if (is_post()) {
    Security::verifyCsrf();
    if (Request::input('action') === 'read-all') {
        Notification::markRead('member', $currentMemberId);
    } else {
        Notification::markRead('member', $currentMemberId, Request::int('id'));
    }
    Request::redirect('notifications.php');
}

$total = Notification::unreadCount('member', $currentMemberId);
$count = (int) db()->value('SELECT COUNT(*) FROM notifications WHERE recipient_type = :t AND recipient_id = :i', ['t' => 'member', 'i' => $currentMemberId]);
$pager = new Pager($count, 20);
$rows = db()->select(
    'SELECT * FROM notifications WHERE recipient_type = :t AND recipient_id = :i ORDER BY id DESC' . $pager->limitSql(),
    ['t' => 'member', 'i' => $currentMemberId]
);

$pageTitle = 'Notifications';
$pageHeading = 'Notifications';
$pageSub = $total > 0 ? $total . ' unread' : 'All caught up';
$activeNav = 'notifications';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 mb-0">Inbox</h2>
    <?php if ($total > 0): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read-all"><button class="btn btn-sm btn-outline-secondary">Mark all read</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No notifications yet.</p>
  <?php else: ?>
    <div class="list-stack">
      <?php foreach ($rows as $row): ?>
        <div class="list-item <?= (int) $row['is_read'] === 0 ? 'unread' : '' ?>">
          <div class="list-icon"><i class="fa-solid <?= e((string) ($row['icon'] ?: 'fa-bell')) ?>"></i></div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold"><?= e((string) $row['title']) ?></div>
            <div class="small text-muted"><?= e((string) $row['message']) ?></div>
            <div class="small text-muted mt-1"><?= e(time_ago((string) $row['created_at'])) ?></div>
          </div>
          <?php if ((int) $row['is_read'] === 0): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-light-green">Mark read</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-3">
      <div class="small text-muted"><?= $pager->infoText() ?></div>
      <?= $pager->links() ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
