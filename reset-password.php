<?php
/** Complete password reset with a single-use token. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$token = (string) Request::query('token', '');
$customerId = Request::int('id');
if ($token === '' || $customerId === 0) {
    Session::error('Invalid or expired reset link.');
    Request::redirect('login.php');
}
$customer = db()->first('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
$valid = $customer && $customer['reset_token']
    && hash_equals((string) $customer['reset_token'], Security::hashToken($token))
    && $customer['reset_expires_at'] && strtotime((string) $customer['reset_expires_at']) > time();
if (!$valid) {
    Session::error('This reset link is invalid or has expired. Please request a new one.');
    Request::redirect('forgot-password.php');
}
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('password', 'Password')->required('password')->password('password');
    $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
    if ($v->passes()) {
        db()->update('customers', [
            'password_hash' => Security::passwordHash((string) Request::raw('password')),
            'reset_token' => null,
            'reset_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $customerId]);
        Session::success('Your password has been updated. Please sign in.');
        Request::redirect('login.php');
    }
    $errors = $v->errors();
}
$pageTitle = 'Set New Password';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card">
      <h1 class="h4 mb-3">Set a new password</h1>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="password">New password</label>
          <input id="password" name="password" type="password" class="form-control<?= invalid_class($errors, 'password') ?>" required>
          <?= field_error($errors, 'password') ?>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password_confirm">Confirm new password</label>
          <input id="password_confirm" name="password_confirm" type="password" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" required>
          <?= field_error($errors, 'password_confirm') ?>
        </div>
        <button class="btn btn-primary w-100" type="submit">Update password</button>
      </form>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
