<?php
/**
 * Reusable admin CRUD screen.
 *
 * Builds a complete list + add/edit form + delete/toggle flow for simple tables so
 * every CMS module shares identical validation, uploads, pagination and audit logging.
 *
 * Usage: crud_page(['table' => ..., 'fields' => [...], 'list' => [...]], $adminUser);
 */
declare(strict_types=1);

use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

if (!function_exists('crud_page')) {
    function crud_page(array $config): void
    {
        $db = \Saee\Lib\Database::instance();
        $table = $config['table'];
        $fields = $config['fields'];
        $title = $config['title'];
        $singular = $config['singular'] ?? rtrim($title, 's');
        $module = $config['module'] ?? $table;
        $order = $config['order'] ?? 'id DESC';
        $searchColumns = $config['search'] ?? [];
        $perPage = $config['per_page'] ?? 20;
        $listColumns = $config['list'] ?? array_slice(array_keys($fields), 0, 5);
        $listRoute = $config['route'] ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $intro = $config['intro'] ?? '';

        $editId = Request::int('id');
        $record = $editId ? $db->first("SELECT * FROM $table WHERE id = :id", ['id' => $editId]) : null;
        $errors = [];

        if (is_post()) {
            Security::verifyCsrf();
            $action = (string) Request::input('action', 'save');

            if ($action === 'delete') {
                $id = Request::int('id');
                $row = $db->first("SELECT * FROM $table WHERE id = :id", ['id' => $id]);
                if ($row) {
                    foreach ($fields as $name => $field) {
                        if (in_array($field['type'] ?? 'text', ['image', 'file'], true) && !empty($row[$name])) {
                            Uploader::delete((string) $row[$name], !str_starts_with((string) $row[$name], 'private:'));
                        }
                    }
                    $db->delete($table, 'id = :id', ['id' => $id]);
                    Audit::log('delete', $module, 'Deleted ' . $singular . ' #' . $id, $table, $id);
                    Session::success($singular . ' deleted.');
                }
                Request::redirect($listRoute);
            }

            if ($action === 'toggle') {
                $id = Request::int('id');
                $row = $db->first("SELECT * FROM $table WHERE id = :id", ['id' => $id]);
                if ($row && array_key_exists('status', $row)) {
                    $new = (int) $row['status'] === 1 ? 0 : 1;
                    $db->update($table, ['status' => $new, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                    Audit::log('toggle', $module, 'Set status of ' . $singular . ' #' . $id . ' to ' . $new, $table, $id);
                    Session::success('Status updated.');
                }
                Request::redirect($listRoute . ($editId ? '?id=' . $editId : ''));
            }

            // ---- save
            $data = [];
            foreach ($fields as $name => $field) {
                $type = $field['type'] ?? 'text';
                $label = $field['label'] ?? ucfirst($name);
                $value = Request::input($name);

                if ($type === 'password') {
                    $raw = (string) Request::raw($name, '');
                    if ($raw !== '') {
                        $issues = Security::passwordIssues($raw);
                        if ($issues) {
                            $errors[$name] = $issues;
                        } else {
                            $data[$name] = $field['hash'] ?? true ? Security::hashPassword($raw) : $raw;
                        }
                    } elseif (!empty($field['required']) && !$record) {
                        $errors[$name] = ['This field is required.'];
                    }
                    continue;
                }

                if ($type === 'checkbox') {
                    $data[$name] = Request::bool($name) ? 1 : 0;
                    continue;
                }

                if (in_array($type, ['image', 'file'], true)) {
                    $file = Request::file($name);
                    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $allow = $type === 'image' ? Uploader::IMAGE : Uploader::DOCUMENT;
                        $upload = Uploader::store($file, $field['folder'] ?? $module, $allow);
                        if ($upload['ok']) {
                            $data[$name] = $upload['path'];
                        } else {
                            $errors[$name] = [$label . ': ' . $upload['message']];
                        }
                    } elseif (!empty($field['required']) && !$record) {
                        $errors[$name] = [$label . ' is required.'];
                    }
                    continue;
                }

                if (is_array($value)) {
                    $value = implode(',', array_map('strval', $value));
                }
                $value = is_string($value) ? trim($value) : $value;

                if (!empty($field['required']) && ($value === null || $value === '')) {
                    $errors[$name] = [$label . ' is required.'];
                }
                if (isset($field['max']) && is_string($value) && mb_strlen($value) > (int) $field['max']) {
                    $errors[$name] = [$label . ' must be at most ' . (int) $field['max'] . ' characters.'];
                }
                if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = ['Enter a valid email address.'];
                }
                if (($field['type'] ?? '') === 'number' || ($field['type'] ?? '') === 'money') {
                    $value = $value === '' || $value === null ? ($field['default'] ?? 0) : (float) $value;
                }
                if (($field['type'] ?? '') === 'json' && is_string($value) && $value !== '') {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errors[$name] = ['Invalid JSON.'];
                    }
                }
                if (!empty($field['zero_null']) && ($value === '' || $value === null || (is_numeric($value) && (float) $value === 0.0))) {
                    $data[$name] = null;
                    continue;
                }
                if ($value === null && !empty($field['nullable'])) {
                    $data[$name] = null;
                    continue;
                }
                $data[$name] = $value;
            }

            // Auto slug
            if (isset($fields['slug']) && empty($data['slug']) && !empty($data['title'] ?? $data['name'] ?? '')) {
                $data['slug'] = unique_slug($table, 'slug', (string) ($data['title'] ?? $data['name']), isset($record['id']) ? (int) $record['id'] : null);
            }

            if (empty($errors)) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                try {
                    if ($record) {
                        $db->update($table, $data, 'id = :id', ['id' => $record['id']]);
                        Audit::log('update', $module, 'Updated ' . $singular . ' #' . $record['id'], $table, (int) $record['id'], Audit::diff($record, $data));
                        Session::success($singular . ' updated.');
                        $newId = (int) $record['id'];
                    } else {
                        $data['created_at'] = date('Y-m-d H:i:s');
                        $newId = $db->insert($table, $data);
                        Audit::log('create', $module, 'Created ' . $singular . ' #' . $newId, $table, $newId);
                        Session::success($singular . ' created.');
                    }
                    Request::redirect($listRoute . '?id=' . $newId . '&saved=1');
                } catch (\Throwable $e) {
                    $errors['_general'] = ['Could not save: ' . $e->getMessage()];
                }
            } else {
                Session::set('_old_input', array_map(static fn ($v) => is_array($v) ? '' : (string) $v, $_POST));
                if ($editId) {
                    $record = $db->first("SELECT * FROM $table WHERE id = :id", ['id' => $editId]);
                }
            }
        }

        $search = trim((string) Request::query('q', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '' && $searchColumns) {
            $parts = [];
            foreach ($searchColumns as $i => $col) {
                $parts[] = "$col LIKE :q$i";
                $params["q$i"] = '%' . $search . '%';
            }
            $where = '(' . implode(' OR ', $parts) . ')';
        }
        $total = (int) $db->value("SELECT COUNT(*) FROM $table WHERE $where", $params);
        $pager = new Pager($total, $perPage);
        $rows = $db->select("SELECT * FROM $table WHERE $where ORDER BY $order" . $pager->limitSql(), $params);

        $pageTitle = $title;
        $pageHeading = $title;
        $pageSub = $total . ' record(s)' . ($intro !== '' ? ' · ' . $intro : '');
        $activeModule = $module;
        $pageActions = '<a class="btn btn-sm btn-primary" href="' . e($listRoute) . '"><i class="fa-solid fa-plus me-1"></i>New ' . e($singular) . '</a>';
        require dirname(__DIR__) . '/includes/layout.php';
        ?>
        <div class="row g-3">
          <div class="col-lg-5 order-lg-2">
            <div class="panel p-3 p-md-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0"><?= $record ? 'Edit ' . e($singular) : 'Add ' . e($singular) ?></h2>
                <?php if ($record): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($listRoute) ?>">Cancel</a><?php endif; ?>
              </div>
              <?php if (!empty($errors['_general'])): ?><div class="alert alert-danger small"><?= e($errors['_general'][0]) ?></div><?php endif; ?>
              <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
              <form method="post" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <?php if ($record): ?><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><?php endif; ?>
                <?php foreach ($fields as $name => $field):
                    $type = $field['type'] ?? 'text';
                    $value = $record[$name] ?? ($field['default'] ?? '');
                    $label = $field['label'] ?? ucfirst($name);
                    if (in_array($type, ['id', 'created_at', 'updated_at'], true)) { continue; }
                    ?>
                  <div class="mb-3">
                    <label class="form-label" for="f_<?= e($name) ?>">
                      <?= e($label) ?><?= !empty($field['required']) ? ' <span class="required-mark">*</span>' : '' ?>
                    </label>
                    <?php if ($type === 'textarea'): ?>
                      <textarea id="f_<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int) ($field['rows'] ?? 3) ?>" class="form-control<?= invalid_class($errors, $name) ?>" maxlength="<?= (int) ($field['max'] ?? 5000) ?>"><?= e((string) $value) ?></textarea>
                    <?php elseif ($type === 'richtext'): ?>
                      <textarea id="f_<?= e($name) ?>" name="<?= e($name) ?>" rows="10" class="form-control richtext<?= invalid_class($errors, $name) ?>"><?= e((string) $value) ?></textarea>
                    <?php elseif ($type === 'select'): ?>
                      <select id="f_<?= e($name) ?>" name="<?= e($name) ?>" class="form-select<?= invalid_class($errors, $name) ?>">
                        <?php foreach (($field['options'] ?? []) as $optValue => $optLabel): ?>
                          <option value="<?= e((string) $optValue) ?>" <?= (string) $value === (string) $optValue ? 'selected' : '' ?>><?= e((string) $optLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($type === 'checkbox'): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="f_<?= e($name) ?>" name="<?= e($name) ?>" value="1" <?= (int) $value === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="f_<?= e($name) ?>"><?= e($field['help'] ?? 'Enabled') ?></label>
                      </div>
                    <?php elseif ($type === 'image'): ?>
                      <?php if ($value): ?><div class="mb-2"><img class="crud-thumb" src="<?= e(str_starts_with((string) $value, 'private:') ? Url::asset('images/placeholder.svg') : Url::media((string) $value)) ?>" alt=""></div><?php endif; ?>
                      <input id="f_<?= e($name) ?>" type="file" name="<?= e($name) ?>" accept="image/*" class="form-control<?= invalid_class($errors, $name) ?>">
                    <?php elseif ($type === 'file'): ?>
                      <?php if ($value): ?><div class="small mb-1"><a href="<?= e(Url::media((string) $value)) ?>" target="_blank" rel="noopener">Current file</a></div><?php endif; ?>
                      <input id="f_<?= e($name) ?>" type="file" name="<?= e($name) ?>" class="form-control<?= invalid_class($errors, $name) ?>">
                    <?php elseif ($type === 'number' || $type === 'money'): ?>
                      <input id="f_<?= e($name) ?>" type="number" step="<?= $type === 'money' ? '0.01' : '1' ?>" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" class="form-control<?= invalid_class($errors, $name) ?>">
                    <?php elseif ($type === 'date'): ?>
                      <input id="f_<?= e($name) ?>" type="date" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" class="form-control<?= invalid_class($errors, $name) ?>">
                    <?php elseif ($type === 'datetime'): ?>
                      <input id="f_<?= e($name) ?>" type="datetime-local" name="<?= e($name) ?>" value="<?= e($value ? date('Y-m-d\TH:i', strtotime((string) $value)) : '') ?>" class="form-control<?= invalid_class($errors, $name) ?>">
                    <?php elseif ($type === 'password'): ?>
                      <input id="f_<?= e($name) ?>" type="password" name="<?= e($name) ?>" class="form-control<?= invalid_class($errors, $name) ?>" autocomplete="new-password">
                    <?php else: ?>
                      <input id="f_<?= e($name) ?>" type="<?= $type === 'email' ? 'email' : 'text' ?>" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" class="form-control<?= invalid_class($errors, $name) ?>" maxlength="<?= (int) ($field['max'] ?? 255) ?>">
                    <?php endif; ?>
                    <?php if (!empty($field['help']) && $type !== 'checkbox'): ?><div class="form-text"><?= e($field['help']) ?></div><?php endif; ?>
                    <?= field_error($errors, $name) ?>
                  </div>
                <?php endforeach; ?>
                <button class="btn btn-primary w-100" type="submit"><?= $record ? 'Update' : 'Create' ?> <?= e($singular) ?></button>
              </form>
              <?php if ($record): ?>
                <div class="d-flex gap-2 mt-2">
                  <?php if (array_key_exists('status', $record)): ?>
                    <form method="post" class="flex-grow-1">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary w-100"><?= (int) $record['status'] === 1 ? 'Disable' : 'Enable' ?></button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="flex-grow-1" data-confirm="Delete this <?= e(strtolower($singular)) ?> permanently?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-lg-7 order-lg-1">
            <div class="panel p-3 p-md-4">
              <?php if ($searchColumns): ?>
                <form class="d-flex gap-2 mb-3" method="get">
                  <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search <?= e(strtolower($title)) ?>">
                  <button class="btn btn-sm btn-dark">Search</button>
                  <?php if ($search !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($listRoute) ?>">Reset</a><?php endif; ?>
                </form>
              <?php endif; ?>
              <?php if (!$rows): ?>
                <p class="small text-muted mb-0">No records found.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 data-table">
                    <thead><tr>
                      <?php foreach ($listColumns as $col): ?><th><?= e($fields[$col]['label'] ?? ucfirst($col)) ?></th><?php endforeach; ?>
                      <th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <?php foreach ($listColumns as $col):
                            $field = $fields[$col] ?? ['type' => 'text'];
                            $val = $row[$col] ?? '';
                            ?>
                          <td data-label="<?= e($field['label'] ?? ucfirst($col)) ?>">
                            <?php if (($field['type'] ?? '') === 'image' && $val): ?>
                              <img class="crud-thumb-sm" src="<?= e(Url::media((string) $val)) ?>" alt="">
                            <?php elseif ($col === 'status'): ?>
                              <?= status_badge((int) $val === 1 ? 'active' : 'inactive') ?>
                            <?php elseif (in_array(($field['type'] ?? ''), ['richtext', 'textarea'], true)): ?>
                              <span class="small"><?= e(truncate_text(strip_tags((string) $val), 70)) ?></span>
                            <?php else: ?>
                              <span class="small"><?= e(truncate_text((string) $val, 70)) ?></span>
                            <?php endif; ?>
                          </td>
                        <?php endforeach; ?>
                        <td class="text-end">
                          <a class="btn btn-sm btn-light-green" href="<?= e($listRoute) ?>?id=<?= (int) $row['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                          <form method="post" class="d-inline" data-confirm="Delete this record?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fa-regular fa-trash-can"></i></button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                  <div class="small text-muted"><?= $pager->infoText() ?></div>
                  <?= $pager->links(['q' => $search]) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
        $needsEditor = (bool) array_filter($fields, static fn ($f) => ($f['type'] ?? '') === 'richtext');
        $extraScripts = $needsEditor ? '<script src="' . e(Url::asset('vendor/tinymce/tinymce/tinymce.min.js')) . '"></script><script>
        if (window.tinymce) {
          tinymce.init({
            selector: "textarea.richtext", height: 380, menubar: false, promotion: false,
            plugins: "lists link table code image autoresize", toolbar: "undo redo | bold italic | bullist numlist | link image table | code",
            skin_url: "' . e(Url::asset('vendor/tinymce/tinymce/skins/ui/oxide')) . '",
            content_css: "' . e(Url::asset('vendor/tinymce/tinymce/skins/content/default/content.min.css')) . '"
          });
        }
        </script>' : '';
        require dirname(__DIR__) . '/includes/layout-end.php';
    }
}
