<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$token = (string) Request::query('token', '');
$memberId = Request::int('id');
$member = $memberId ? db()->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]) : null;
$valid = $member && $token !== '' && $member['reset_token']
    && hash_equals((string) $member['reset_token'], Security::hashToken($token))
    && $member['reset_expires_at'] && strtotime((string) $member['reset_expires_at']) > time();
if (!$valid) {
    Session::error('This reset link is invalid or has expired.');
    Request::redirect('login.php');
}
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('password', 'Password')->required('password')->password('password');
    $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
    if ($v->passes()) {
        db()->update('members', [
            'password_hash' => Security::passwordHash((string) Request::raw('password')),
            'reset_token' => null,
            'reset_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $memberId]);
        Session::success('Password updated. Please sign in.');
        Request::redirect('login.php');
    }
    $errors = $v->errors();
}
$pageTitle = 'Set New Password';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap"><div class="container"><div class="auth-card">
  <h1 class="h4 mb-3">Set a new password</h1>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">New password</label><input type="password" name="password" class="form-control<?= invalid_class($errors, 'password') ?>" required><?= field_error($errors, 'password') ?></div>
    <div class="mb-3"><label class="form-label">Confirm password</label><input type="password" name="password_confirm" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" required><?= field_error($errors, 'password_confirm') ?></div>
    <button class="btn btn-primary w-100">Update password</button>
  </form>
</div></div></div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
