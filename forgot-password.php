<?php
/** Password reset request (email/mobile token flow, logs mail in development). */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$message = '';
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $identifier = trim((string) Request::input('identifier', ''));
    if ($identifier === '') {
        $errors['identifier'] = ['Enter your registered mobile number or email.'];
    } else {
        $customer = db()->first(
            'SELECT * FROM customers WHERE mobile = :i OR LOWER(email) = :i LIMIT 1',
            ['i' => strtolower($identifier)]
        );
        if ($customer) {
            $token = Security::randomToken(32);
            db()->update('customers', [
                'reset_token' => Security::hashToken($token),
                'reset_expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ], 'id = :id', ['id' => $customer['id']]);
            $link = Url::to('reset-password.php?token=' . $token . '&id=' . $customer['id']);
            try {
                (new \Saee\Lib\MailChannel(\Saee\Lib\Config::get('mail')))->send(
                    ['email' => $customer['email'], 'name' => $customer['name']],
                    'Reset your Saee Life Herbal password',
                    "Hello {$customer['name']},\n\nUse the link below to reset your password (valid for 60 minutes):\n$link\n\nIf you did not request this, you can ignore this email."
                );
            } catch (Throwable) {
            }
            if (APP_DEBUG) {
                $message = 'Reset link generated (development): <a href="' . e($link) . '">Open reset link</a>';
            }
        }
        if ($message === '') {
            $message = 'If an account matches those details, a reset link has been sent. Please check your inbox or SMS.';
        }
    }
}
$pageTitle = 'Forgot Password';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card">
      <h1 class="h4 mb-1">Reset your password</h1>
      <p class="small text-muted">Enter your registered mobile number or email address.</p>
      <?php if ($message): ?><div class="alert alert-success small"><?= $message ?></div><?php endif; ?>
      <?php if (!empty($errors['identifier'])): ?><div class="alert alert-danger small"><?= e($errors['identifier'][0]) ?></div><?php endif; ?>
      <form method="post" action="<?= e(Url::to('forgot-password.php')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Mobile or email</label>
          <input id="identifier" name="identifier" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Send reset link</button>
      </form>
      <p class="small text-center mt-3 mb-0"><a href="<?= e(Url::to('login.php')) ?>">Back to sign in</a></p>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
