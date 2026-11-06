<?php
/** Change password. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Validator;

$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('current_password', 'Current password')->required('current_password');
    $v->label('password', 'New password')->required('password')->password('password');
    $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
    if ($v->passes() && !Security::verifyPassword((string) Request::raw('current_password'), (string) $member['password_hash'])) {
        $errors['current_password'] = ['Your current password is incorrect.'];
    } elseif ($v->fails()) {
        $errors = $v->errors();
    }
    if (empty($errors)) {
        if (Security::verifyPassword((string) Request::raw('password'), (string) $member['password_hash'])) {
            $errors['password'] = ['The new password must be different from the current password.'];
        } else {
            db()->update('members', [
                'password_hash' => Security::hashPassword((string) Request::raw('password')),
                'remember_token' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $currentMemberId]);
            Session::success('Password changed successfully.');
            Request::redirect('password.php');
        }
    }
}

$pageTitle = 'Change Password';
$pageHeading = 'Change Password';
$pageSub = 'Keep your account secure';
$activeNav = 'password';
require __DIR__ . '/includes/layout.php';
?>
<div class="row">
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4">
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Current password</label>
          <input type="password" name="current_password" class="form-control<?= invalid_class($errors, 'current_password') ?>" required autocomplete="current-password">
          <?= field_error($errors, 'current_password') ?>
        </div>
        <div class="mb-3">
          <label class="form-label">New password</label>
          <input type="password" name="password" class="form-control<?= invalid_class($errors, 'password') ?>" required autocomplete="new-password">
          <div class="form-text">Minimum <?= PASSWORD_MIN_LENGTH ?> characters with a letter and a number.</div>
          <?= field_error($errors, 'password') ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm new password</label>
          <input type="password" name="password_confirm" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" required autocomplete="new-password">
          <?= field_error($errors, 'password_confirm') ?>
        </div>
        <button class="btn btn-primary">Update password</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
