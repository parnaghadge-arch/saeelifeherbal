<?php
/** Admin users and their role assignment. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('users.manage');

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$id = Request::int('id');
$user = $id ? $db->first('SELECT * FROM admin_users WHERE id = :id', ['id' => $id]) : null;
$roles = $db->select('SELECT * FROM admin_roles WHERE status = 1 ORDER BY id ASC');
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'save');
    if ($action === 'delete') {
        $target = Request::int('id');
        if ($target === (int) $adminUser['id']) {
            Session::error('You cannot delete your own account.');
        } else {
            $db->delete('admin_users', 'id = :id', ['id' => $target]);
            Audit::log('delete', 'users', 'Deleted admin user #' . $target, 'admin_users', $target);
            Session::success('Admin user deleted.');
        }
        Request::redirect('admin-users.php');
    }
    if ($action === 'status') {
        $target = Request::int('id');
        if ($target === (int) $adminUser['id']) {
            Session::error('You cannot disable your own account.');
        } else {
            $row = $db->first('SELECT * FROM admin_users WHERE id = :id', ['id' => $target]);
            if ($row) {
                $db->update('admin_users', ['status' => $row['status'] === 'active' ? 'inactive' : 'active', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $target]);
                Audit::log('status', 'users', 'Toggled admin status for #' . $target, 'admin_users', $target);
                Session::success('Status updated.');
            }
        }
        Request::redirect('admin-users.php');
    }

    $data = [
        'name' => trim((string) Request::input('name', '')),
        'username' => strtolower(trim((string) Request::input('username', ''))),
        'email' => strtolower(trim((string) Request::input('email', ''))),
        'mobile' => (string) Request::input('mobile', ''),
        'role_id' => Request::int('role_id'),
        'status' => Request::input('status') === 'active' ? 'active' : 'inactive',
        'must_change_password' => Request::bool('must_change_password') ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($data['name'] === '') { $errors['name'] = ['Name is required.']; }
    if ($data['username'] === '' || mb_strlen($data['username']) < 3) { $errors['username'] = ['Username must be at least 3 characters.']; }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { $errors['email'] = ['Enter a valid email address.']; }
    if (!$data['role_id']) { $errors['role_id'] = ['Select a role.']; }
    if ($db->exists('admin_users', 'username = :u AND id <> :id', ['u' => $data['username'], 'id' => $id ?: 0])) { $errors['username'] = ['This username is already taken.']; }
    if ($db->exists('admin_users', 'email = :e AND id <> :id', ['e' => $data['email'], 'id' => $id ?: 0])) { $errors['email'] = ['This email is already used.']; }

    $password = (string) Request::raw('password', '');
    if (!$user && $password === '') { $errors['password'] = ['A password is required for a new admin.']; }
    if ($password !== '') {
        $issues = Security::passwordIssues($password);
        if ($issues) { $errors['password'] = $issues; }
    }
    if (empty($errors)) {
        if ($password !== '') {
            $data['password_hash'] = Security::hashPassword($password);
        } elseif ($user) {
            $data['password_hash'] = $user['password_hash'];
        }
        if ($user) {
            $db->update('admin_users', $data, 'id = :id', ['id' => (int) $user['id']]);
            Audit::log('update', 'users', 'Updated admin user ' . $data['username'], 'admin_users', (int) $user['id'], Audit::diff($user, $data));
            Session::success('Admin user updated.');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $newId = $db->insert('admin_users', $data);
            Audit::log('create', 'users', 'Created admin user ' . $data['username'], 'admin_users', $newId);
            Session::success('Admin user created.');
        }
        Request::redirect('admin-users.php');
    }
}

$users = $db->select('SELECT u.*, r.name AS role_name FROM admin_users u LEFT JOIN admin_roles r ON r.id = u.role_id ORDER BY u.id ASC');
$pageTitle = 'Admin Users';
$pageHeading = 'Admin Users';
$pageSub = count($users) . ' user(s)';
$activeModule = 'admin-users';
$formOpen = admin_form_open($user, $errors);
$pageActions = $formOpen
    ? '<a class="btn btn-sm btn-outline-secondary" href="admin-users.php"><i class="fa-solid fa-list me-1"></i>Back to list</a>'
    : admin_new_button('admin-users.php', 'admin user');
require __DIR__ . '/includes/layout.php';
?>
<div class="crud-layout<?= $formOpen ? ' is-form-open' : '' ?>">
  <div class="crud-form-col<?= $formOpen ? '' : ' d-none' ?>" id="crudForm">
    <div class="col-lg-12 p-0">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3"><?= $user ? 'Edit user' : 'Add admin user' ?></h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($user): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>
        <div class="row g-2">
          <div class="col-12"><label class="form-label small">Full name</label><input name="name" class="form-control form-control-sm<?= invalid_class($errors, 'name') ?>" value="<?= e((string) ($user['name'] ?? '')) ?>" required><?= field_error($errors, 'name') ?></div>
          <div class="col-6"><label class="form-label small">Username</label><input name="username" class="form-control form-control-sm<?= invalid_class($errors, 'username') ?>" value="<?= e((string) ($user['username'] ?? '')) ?>" required><?= field_error($errors, 'username') ?></div>
          <div class="col-6"><label class="form-label small">Mobile</label><input name="mobile" class="form-control form-control-sm" value="<?= e((string) ($user['mobile'] ?? '')) ?>"></div>
          <div class="col-12"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm<?= invalid_class($errors, 'email') ?>" value="<?= e((string) ($user['email'] ?? '')) ?>" required><?= field_error($errors, 'email') ?></div>
          <div class="col-6"><label class="form-label small">Role</label>
            <select name="role_id" class="form-select form-select-sm<?= invalid_class($errors, 'role_id') ?>">
              <option value="">Select…</option>
              <?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) ($user['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e((string) $role['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error($errors, 'role_id') ?>
          </div>
          <div class="col-6"><label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="active" <?= ($user['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($user['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
          </div>
          <div class="col-12"><label class="form-label small">Password <?= $user ? '(leave blank to keep)' : '' ?></label><input type="password" name="password" class="form-control form-control-sm<?= invalid_class($errors, 'password') ?>" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mcp"><label class="form-check-label small" for="mcp">Force password change at next login</label></div></div>
        </div>
        <button class="btn btn-primary w-100 mt-3"><?= $user ? 'Update user' : 'Create user' ?></button>
      </form>
    </div>
  </div>
  <div class="crud-list-col">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Administrators</h2>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>User</th><th>Role</th><th>Last login</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($users as $row): ?>
            <tr>
              <td data-label="User"><div class="small fw-semibold"><?= e((string) $row['name']) ?></div><div class="small text-muted"><?= e((string) $row['username']) ?> · <?= e((string) $row['email']) ?></div></td>
              <td data-label="Role" class="small"><?= e((string) ($row['role_name'] ?? '—')) ?></td>
              <td data-label="Last login" class="small"><?= $row['last_login_at'] ? e(time_ago((string) $row['last_login_at'])) : 'never' ?></td>
              <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-light-green" href="?id=<?= (int) $row['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                <?php if ((int) $row['id'] !== (int) $adminUser['id']): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Toggle status"><i class="fa-solid fa-power-off"></i></button></form>
                  <form method="post" class="d-inline" data-confirm="Delete this admin user?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash-can"></i></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
