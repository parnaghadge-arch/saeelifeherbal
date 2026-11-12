<?php
/** Roles & permissions matrix. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('roles.manage');

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$roles = $db->select('SELECT * FROM admin_roles ORDER BY id ASC');
$permissions = $db->select('SELECT * FROM admin_permissions ORDER BY group_name ASC, slug ASC');
$roleId = Request::int('role', (int) ($roles[0]['id'] ?? 0));
$currentRole = $roleId ? $db->first('SELECT * FROM admin_roles WHERE id = :id', ['id' => $roleId]) : null;
$assigned = [];
if ($currentRole) {
    $assigned = array_map('intval', $db->column('SELECT permission_id FROM admin_role_permissions WHERE role_id = :r', ['r' => $roleId]));
}
$grouped = [];
foreach ($permissions as $perm) {
    $grouped[$perm['group_name']][] = $perm;
}

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    if ($action === 'save-permissions' && $currentRole) {
        if ((int) $currentRole['is_super'] === 1) {
            Session::error('The super administrator role always has every permission.');
            Request::redirect('roles.php?role=' . $roleId);
        }
        $ids = array_map('intval', Request::array('permissions'));
        $db->transaction(function ($db) use ($roleId, $ids) {
            $db->delete('admin_role_permissions', 'role_id = :r', ['r' => $roleId]);
            foreach ($ids as $permissionId) {
                $db->insertIgnore('admin_role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => date('Y-m-d H:i:s')]);
            }
        });
        Audit::log('update', 'roles', 'Updated permissions for role ' . $currentRole['name'], 'admin_roles', $roleId);
        Session::success('Permissions updated.');
        Request::redirect('roles.php?role=' . $roleId);
    }
    if ($action === 'role-save') {
        $data = [
            'name' => trim((string) Request::input('name', '')),
            'slug' => slugify((string) Request::input('slug', '')) ?: slugify((string) Request::input('name', '')),
            'description' => (string) Request::input('description', ''),
            'status' => Request::bool('status') ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($data['name'] === '') {
            Session::error('Role name is required.');
            Request::redirect('roles.php');
        }
        $existing = $db->first('SELECT * FROM admin_roles WHERE id = :id', ['id' => Request::int('role_id')]);
        if ($existing) {
            if ((int) $existing['is_super'] === 1) {
                Session::error('The super administrator role cannot be modified.');
                Request::redirect('roles.php?role=' . (int) $existing['id']);
            }
            $db->update('admin_roles', $data, 'id = :id', ['id' => (int) $existing['id']]);
            Audit::log('update', 'roles', 'Updated role ' . $data['name'], 'admin_roles', (int) $existing['id']);
            Session::success('Role updated.');
            Request::redirect('roles.php?role=' . (int) $existing['id']);
        }
        $data['is_super'] = 0;
        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = $db->insert('admin_roles', $data);
        Audit::log('create', 'roles', 'Created role ' . $data['name'], 'admin_roles', $newId);
        Session::success('Role created.');
        Request::redirect('roles.php?role=' . $newId);
    }
}

$pageTitle = 'Roles & Permissions';
$pageHeading = 'Roles & Permissions';
$pageSub = count($roles) . ' roles · ' . count($permissions) . ' permissions';
$activeModule = 'roles';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Roles</h2>
      <div class="list-stack">
        <?php foreach ($roles as $role): ?>
          <a class="list-item <?= $roleId === (int) $role['id'] ? 'unread' : '' ?>" href="?role=<?= (int) $role['id'] ?>">
            <div class="flex-grow-1 min-w-0">
              <div class="small fw-semibold"><?= e((string) $role['name']) ?></div>
              <div class="small text-muted text-truncate"><?= e((string) $role['description']) ?></div>
            </div>
            <?php if ((int) $role['is_super'] === 1): ?><span class="chip">super</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3"><?= $currentRole && (int) $currentRole['is_super'] === 0 ? 'Edit role' : 'Create role' ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="role-save">
        <?php if ($currentRole && (int) $currentRole['is_super'] === 0): ?><input type="hidden" name="role_id" value="<?= (int) $currentRole['id'] ?>"><?php endif; ?>
        <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" value="<?= e((string) ($currentRole['name'] ?? '')) ?>" required></div>
        <div class="mb-2"><label class="form-label small">Slug</label><input name="slug" class="form-control form-control-sm" value="<?= e((string) ($currentRole['slug'] ?? '')) ?>"></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea name="description" class="form-control form-control-sm" rows="2"><?= e((string) ($currentRole['description'] ?? '')) ?></textarea></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="status" value="1" id="rs" <?= (int) ($currentRole['status'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="rs">Active</label></div>
        <button class="btn btn-sm btn-primary w-100">Save role</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Permissions <?= $currentRole ? '· ' . e((string) $currentRole['name']) : '' ?></h2>
      <?php if (!$currentRole): ?>
        <p class="small text-muted mb-0">Select a role.</p>
      <?php elseif ((int) $currentRole['is_super'] === 1): ?>
        <div class="alert alert-success small mb-0">The super administrator role has unrestricted access to every module and cannot be limited.</div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save-permissions">
          <div class="row g-3">
            <?php foreach ($grouped as $group => $perms): ?>
              <div class="col-md-6">
                <div class="border rounded p-2 h-100">
                  <div class="chip mb-2"><?= e(ucwords(str_replace('_', ' ', (string) $group))) ?></div>
                  <?php foreach ($perms as $perm): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int) $perm['id'] ?>" id="p<?= (int) $perm['id'] ?>" <?= in_array((int) $perm['id'], $assigned, true) ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="p<?= (int) $perm['id'] ?>"><?= e((string) $perm['name']) ?> <span class="text-muted">(<?= e((string) $perm['slug']) ?>)</span></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-primary mt-3">Save permissions</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
