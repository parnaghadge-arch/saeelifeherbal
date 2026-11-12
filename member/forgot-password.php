<?php
/** Member password reset request. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Config;
use Saee\Lib\MailChannel;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Url;

$message = '';
if (is_post()) {
    Security::verifyCsrf();
    $identifier = trim((string) Request::input('identifier', ''));
    $member = db()->first(
        'SELECT * FROM members WHERE member_code = :i OR mobile = :i OR LOWER(email) = :i LIMIT 1',
        ['i' => strtolower($identifier)]
    );
    if ($member) {
        $token = Security::randomToken(32);
        db()->update('members', [
            'reset_token' => Security::hashToken($token),
            'reset_expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ], 'id = :id', ['id' => $member['id']]);
        $link = Url::member('reset-password.php?token=' . $token . '&id=' . $member['id']);
        if (!empty($member['email'])) {
            try {
                (new MailChannel(Config::get('mail')))->send(
                    ['email' => $member['email'], 'name' => $member['name']],
                    'Reset your Saee Life Herbal partner password',
                    "Hello {$member['name']},\n\nReset your password using this link (valid 60 minutes):\n$link"
                );
            } catch (Throwable) {
            }
        }
        if (APP_DEBUG) {
            $message = 'Development reset link: <a href="' . e($link) . '">Open reset link</a>';
        }
    }
    if ($message === '') {
        $message = 'If the details match an account, a reset link has been sent.';
    }
}
$pageTitle = 'Partner Password Reset';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card">
      <h1 class="h4 mb-1">Reset your password</h1>
      <p class="small text-muted">Enter your member ID, mobile number or email.</p>
      <?php if ($message): ?><div class="alert alert-success small"><?= $message ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Member ID / mobile / email</label>
          <input id="identifier" name="identifier" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Send reset link</button>
      </form>
      <p class="small text-center mt-3 mb-0"><a href="<?= e(Url::member('login.php')) ?>">Back to sign in</a></p>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
