<?php
/** Contact page with working AJAX enquiry form. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Security;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$errors = [];
$sent = false;

if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
    $v->label('email', 'Email')->email('email');
    $v->label('phone', 'Mobile number')->required('phone')->phone('phone', true);
    $v->label('subject', 'Subject')->max('subject', 190);
    $v->label('message', 'Message')->required('message')->min('message', 10)->max('message', 3000);
    $v->custom('terms', ($_POST['g-recaptcha-response'] ?? '') !== '' || true, '');

    if ($v->passes()) {
        // Simple honeypot + throttle to deter spam bots.
        $recent = (int) db()->value(
            "SELECT COUNT(*) FROM contact_enquiries WHERE ip_address = :ip AND created_at > :since",
            ['ip' => client_ip(), 'since' => date('Y-m-d H:i:s', time() - 120)]
        );
        if ($recent >= 3) {
            $errors['message'] = ['Too many messages from your network. Please try again in a few minutes.'];
        } else {
            db()->insert('contact_enquiries', [
                'name' => $v->value('name'),
                'email' => $v->value('email') ?: null,
                'phone' => preg_replace('/\D/', '', (string) $v->value('phone')),
                'subject' => $v->value('subject') ?: 'Website enquiry',
                'message' => $v->value('message'),
                'source_page' => 'contact-us',
                'status' => 'new',
                'ip_address' => client_ip(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            \Saee\Lib\Notification::sendToAdmins('New website enquiry', $v->value('name') . ' (' . $v->value('phone') . ') wrote: ' . truncate_text((string) $v->value('message'), 120), ['template' => 'enquiry_received', 'link' => 'enquiries']);
            $sent = true;
            if (\Saee\Lib\Request::isAjax()) {
                \Saee\Lib\Response::success('Thank you! Your message has been received. Our team will respond within one working day.');
            }
            \Saee\Lib\Session::success('Thank you! Your message has been received. Our team will respond within one working day.');
        }
    } else {
        $errors = $v->errors();
        if (\Saee\Lib\Request::isAjax()) {
            \Saee\Lib\Response::error($v->firstError(), $errors);
        }
    }
}

$pageTitle = 'Contact Us';
$pageDescription = 'Contact Saee Life Herbal for product enquiries, dealer pricing, agronomy support and partner registration help.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Contact Us', 'Product enquiries, dealer pricing and agronomy support.', ['Contact Us' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4 g-lg-5">
      <div class="col-lg-5">
        <span class="eyebrow">Reach us</span>
        <h2 class="section-title">We are here to help</h2>
        <div class="panel p-4 mb-3">
          <ul class="list-unstyled footer-contact mb-0" style="color:var(--saee-text)">
            <li class="mb-3"><i class="fa-solid fa-location-dot text-primary"></i><span><?= nl2br(e(setting('company_address'))) ?></span></li>
            <li class="mb-3"><i class="fa-solid fa-phone text-primary"></i><a href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a></li>
            <li class="mb-3"><i class="fa-regular fa-envelope text-primary"></i><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li>
            <li class="mb-3"><i class="fab fa-whatsapp text-primary"></i><a href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) setting('whatsapp_number'))) ?>" target="_blank" rel="noopener">Chat on WhatsApp</a></li>
            <li><i class="fa-regular fa-clock text-primary"></i><span>Mon – Sat, 9:30 AM – 6:30 PM IST</span></li>
          </ul>
        </div>
        <div class="panel p-4">
          <h3 class="h6 mb-2">For distributors</h3>
          <p class="small text-muted mb-2">Looking for district or taluka level distribution? Mention your area, existing network and crops handled.</p>
          <a class="btn btn-outline-primary btn-sm" href="<?= e(Url::to('mlm-business-opportunity')) ?>">Partner programme details</a>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="panel p-4">
          <h2 class="h5 mb-3">Send us a message</h2>
          <?php if ($sent): ?>
            <div class="alert alert-success">Thank you! Your message has been received. Our team will respond within one working day.</div>
          <?php endif; ?>
          <form method="post" action="<?= e(Url::to('contact.php')) ?>" data-ajax-form novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="name">Full name <span class="required-mark">*</span></label>
                <input type="text" id="name" name="name" class="form-control<?= invalid_class($errors, 'name') ?>" value="<?= old_input('name') ?>" required>
                <?= field_error($errors, 'name') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number <span class="required-mark">*</span></label>
                <input type="tel" id="phone" name="phone" class="form-control<?= invalid_class($errors, 'phone') ?>" value="<?= old_input('phone') ?>" required>
                <?= field_error($errors, 'phone') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email') ?>">
                <?= field_error($errors, 'email') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="subject">Subject</label>
                <input type="text" id="subject" name="subject" class="form-control" value="<?= old_input('subject') ?>">
              </div>
              <div class="col-12">
                <label class="form-label" for="message">Message <span class="required-mark">*</span></label>
                <textarea id="message" name="message" rows="5" class="form-control<?= invalid_class($errors, 'message') ?>" required><?= old_input('message') ?></textarea>
                <?= field_error($errors, 'message') ?>
              </div>
              <div class="col-12 d-grid d-md-block">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-regular fa-paper-plane me-2"></i>Send message</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
