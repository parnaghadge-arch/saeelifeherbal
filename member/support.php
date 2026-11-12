<?php
/** Member support: raise a ticket that lands in admin contact enquiries. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Notification;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Validator;

$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('subject', 'Subject')->required('subject')->max('subject', 150);
    $v->label('message', 'Message')->required('message')->min('message', 10)->max('message', 2000);
    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        $id = db()->insert('contact_enquiries', [
            'name' => (string) $member['name'],
            'email' => (string) ($member['email'] ?? ''),
            'phone' => (string) $member['mobile'],
            'subject' => (string) $v->value('subject'),
            'message' => (string) $v->value('message'),
            'source_page' => 'member_portal',
            'status' => 'new',
            'ip_address' => client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Notification::sendToAdmins('Partner support request', $member['name'] . ' (' . $member['member_code'] . '): ' . $v->value('subject'), ['link' => 'enquiries', 'template' => 'support_request']);
        Session::success('Your request has been submitted. Our team will contact you shortly.');
        Request::redirect('support.php');
    }
}
$tickets = db()->select(
    "SELECT * FROM contact_enquiries WHERE source_page = 'member_portal' AND phone = :p ORDER BY id DESC LIMIT 10",
    ['p' => (string) $member['mobile']]
);
$support = [
    'phone' => Settings::get('contact_phone', ''),
    'email' => Settings::get('contact_email', ''),
    'whatsapp' => Settings::get('whatsapp_number', ''),
];

$pageTitle = 'Support';
$pageHeading = 'Support';
$pageSub = 'We are here to help';
$activeNav = 'support';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Raise a request</h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Subject</label>
          <input name="subject" class="form-control<?= invalid_class($errors, 'subject') ?>" value="<?= old_input('subject') ?>" required>
          <?= field_error($errors, 'subject') ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Message</label>
          <textarea name="message" rows="5" class="form-control<?= invalid_class($errors, 'message') ?>" required><?= old_input('message') ?></textarea>
          <?= field_error($errors, 'message') ?>
        </div>
        <button class="btn btn-primary">Submit request</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Contact us</h2>
      <div class="list-stack small">
        <?php if ($support['phone']): ?><a class="list-item" href="tel:<?= e((string) $support['phone']) ?>"><i class="fa-solid fa-phone"></i><span><?= e((string) $support['phone']) ?></span></a><?php endif; ?>
        <?php if ($support['email']): ?><a class="list-item" href="mailto:<?= e((string) $support['email']) ?>"><i class="fa-regular fa-envelope"></i><span><?= e((string) $support['email']) ?></span></a><?php endif; ?>
        <?php if ($support['whatsapp']): ?><a class="list-item" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $support['whatsapp'])) ?>"><i class="fa-brands fa-whatsapp"></i><span>WhatsApp us</span></a><?php endif; ?>
      </div>
    </div>
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Recent requests</h2>
      <?php if (!$tickets): ?>
        <p class="small text-muted mb-0">No requests raised yet.</p>
      <?php else: ?>
        <div class="list-stack">
          <?php foreach ($tickets as $ticket): ?>
            <div class="list-item">
              <div class="flex-grow-1 min-w-0">
                <div class="small fw-semibold text-truncate"><?= e((string) $ticket['subject']) ?></div>
                <div class="small text-muted"><?= e(time_ago((string) $ticket['created_at'])) ?></div>
              </div>
              <?= status_badge((string) $ticket['status']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
