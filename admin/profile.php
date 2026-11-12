<?php
/** Admin's own account: profile + password change. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Validator;

$db = db();
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    if (Request::input('form') === 'password') {
        $current = (string) Request::raw('current_password', '');
        $new = (string) Request::raw('password', '');
        if (!Security::verifyPassword($current, (string) $adminUser['password_hash'])) {
            $errors['current_password'] = ['Your current password is incorrect.'];
        } else {
            $issues = Security::passwordIssues($new);
            if ($issues) {
                $errors['password'] = $issues;
            } elseif ($new !== (string) Request::raw('password_confirm', '')) {
                $errors['password_confirm'] = ['Passwords do not match.'];
            } else {
                $db->update('admin_users', [
                    'password_hash' => Security::hashPassword($new),
                    'must_change_password' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $adminUser['id']]);
                Audit::log('password_change', 'users', 'Admin changed own password', 'admin_users', (int) $adminUser['id']);
                Session::success('Password updated.');
                Request::redirect('profile.php');
            }
        }
    } else {
        $v = new Validator($_POST);
        $v->label('name', 'Name')->required('name')->min('name', 3);
        $v->label('email', 'Email')->required('email')->email('email', true);
        if ($v->passes()) {
            $db->update('admin_users', [
                'name' => (string) $v->value('name'),
                'email' => strtolower((string) $v->value('email')),
                'mobile' => (string) Request::input('mobile', ''),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $adminUser['id']]);
            Audit::log('update', 'users', 'Admin updated own profile', 'admin_users', (int) $adminUser['id']);
            Session::success('Profile updated.');
            Request::redirect('profile.php');
        }
        $errors = $v->errors();
    }
}
$pageTitle = 'My Account';
$pageHeading = 'My Account';
$pageSub = (string) $adminUser['username'] . ' · ' . (string) ($adminUser['role_name'] ?? 'Administrator');
$activeModule = 'profile';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Profile</h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please check the highlighted fields.</div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">
        <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm<?= invalid_class($errors, 'name') ?>" value="<?= e((string) $adminUser['name']) ?>" required><?= field_error($errors, 'name') ?></div>
        <div class="mb-2"><label class="form-label small">Email</label><input name="email" type="email" class="form-control form-control-sm<?= invalid_class($errors, 'email') ?>" value="<?= e((string) $adminUser['email']) ?>" required><?= field_error($errors, 'email') ?></div>
        <div class="mb-2"><label class="form-label small">Mobile</label><input name="mobile" class="form-control form-control-sm" value="<?= e((string) ($adminUser['mobile'] ?? '')) ?>"></div>
        <button class="btn btn-sm btn-primary">Save profile</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Change password</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="password">
        <div class="mb-2"><label class="form-label small">Current password</label><input type="password" name="current_password" class="form-control form-control-sm<?= invalid_class($errors, 'current_password') ?>" required><?= field_error($errors, 'current_password') ?></div>
        <div class="mb-2"><label class="form-label small">New password</label><input type="password" name="password" class="form-control form-control-sm<?= invalid_class($errors, 'password') ?>" required><?= field_error($errors, 'password') ?></div>
        <div class="mb-2"><label class="form-label small">Confirm new password</label><input type="password" name="password_confirm" class="form-control form-control-sm<?= invalid_class($errors, 'password_confirm') ?>" required><?= field_error($errors, 'password_confirm') ?></div>
        <button class="btn btn-sm btn-primary">Update password</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
