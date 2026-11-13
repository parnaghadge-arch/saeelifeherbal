<?php
/**
 * Per-product commission rule versions.
 *
 * Every rule version is listed in one data table (active and closed) with the usual
 * add / update / delete actions. Creating a new version closes the previous version
 * (effective_to) so historical calculations stay reproducible and nothing is overwritten;
 * "Update" edits a version in place for corrections, and "Delete" removes a version
 * outright — after which the product falls back to the commission values on its own form.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('mlm.commission_rules');

use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$products = $db->select('SELECT id, name, sku, mlm_enabled, direct_commission_type, direct_commission_value, grand_direct_commission_type, grand_direct_commission_value, commission_basis FROM products ORDER BY name ASC');
$errors = [];
$rule = null; // non-null opens the form: "close" (new version) or "update" (edit in place)
$editing = false;

/** Mirror a rule onto the product row, which is the "current" quick view used by exports. */
$syncProductFromRule = static function (array $ruleData) use ($db): void {
    $productId = (int) ($ruleData['product_id'] ?? 0);
    if ($productId <= 0) {
        return;
    }
    $db->update('products', [
        'direct_commission_type' => $ruleData['direct_type'],
        'direct_commission_value' => $ruleData['direct_value'],
        'grand_direct_commission_type' => $ruleData['grand_direct_type'],
        'grand_direct_commission_value' => $ruleData['grand_direct_value'],
        'commission_basis' => $ruleData['commission_basis'],
        'royalty_eligible' => $ruleData['royalty_eligible'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $productId]);
};

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'save');

    // ---- close a version (stops it applying from now on; history is untouched)
    if ($action === 'close') {
        $ruleId = Request::int('rule_id');
        $target = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]);
        if ($target) {
            $db->update('mlm_commission_rules', [
                'is_active' => 0,
                'effective_to' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $ruleId]);
            Audit::log('close', 'mlm', 'Closed commission rule #' . $ruleId, 'mlm_commission_rules', $ruleId);
            Session::success('Rule version closed. Existing commissions were not modified.');
        }
        Request::redirect('commission-rules.php');
    }

    // ---- reactivate a closed version (re-opens it and closes the product's other version)
    if ($action === 'reactivate') {
        $ruleId = Request::int('rule_id');
        $target = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]);
        if ($target) {
            $db->transaction(function ($db) use ($target, $ruleId) {
                $db->execute(
                    'UPDATE mlm_commission_rules SET is_active = 0, effective_to = :now, updated_at = :now WHERE product_id = :p AND is_active = 1 AND id <> :id',
                    ['now' => date('Y-m-d H:i:s'), 'p' => (int) $target['product_id'], 'id' => $ruleId]
                );
                $db->update('mlm_commission_rules', [
                    'is_active' => 1,
                    'effective_to' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $ruleId]);
            });
            $syncProductFromRule($db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]));
            Audit::log('reactivate', 'mlm', 'Reactivated commission rule #' . $ruleId, 'mlm_commission_rules', $ruleId);
            Session::success('Rule version reactivated and is now the current one for that product.');
        }
        Request::redirect('commission-rules.php');
    }

    // ---- delete a version permanently
    if ($action === 'delete') {
        $ruleId = Request::int('rule_id');
        $target = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]);
        if (!$target) {
            Session::error('That rule version no longer exists.');
            Request::redirect('commission-rules.php');
        }
        try {
            $db->delete('mlm_commission_rules', 'id = :id', ['id' => $ruleId]);
            $remaining = (int) $db->value(
                'SELECT COUNT(*) FROM mlm_commission_rules WHERE product_id = :p',
                ['p' => (int) $target['product_id']]
            );
            Audit::log('delete', 'mlm', 'Deleted commission rule #' . $ruleId, 'mlm_commission_rules', $ruleId, $target);
            Session::success($remaining === 0
                ? 'Rule version deleted. That product now pays the commission set on its own product form.'
                : 'Rule version deleted.');
        } catch (Throwable $e) {
            Session::error('Could not delete that rule version: ' . $e->getMessage());
        }
        Request::redirect('commission-rules.php');
    }

    // ---- edit in place (a correction), or save a new version
    $editing = $action === 'update';
    $ruleId = Request::int('rule_id');
    if ($editing) {
        $rule = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]);
        if (!$rule) {
            Session::error('That rule version no longer exists.');
            Request::redirect('commission-rules.php');
        }
    }

    $productId = Request::int('product_id');
    $product = $productId ? $db->first('SELECT * FROM products WHERE id = :id', ['id' => $productId]) : null;
    if (!$product) {
        $errors['product_id'] = ['Select a product.'];
    }
    $data = [
        'product_id' => $productId,
        'direct_type' => Request::input('direct_type') === 'fixed' ? 'fixed' : 'percent',
        'direct_value' => round((float) Request::input('direct_value', 0), 2),
        'grand_direct_type' => Request::input('grand_direct_type') === 'fixed' ? 'fixed' : 'percent',
        'grand_direct_value' => round((float) Request::input('grand_direct_value', 0), 2),
        'commission_basis' => normalize_commission_basis((string) Request::input('commission_basis', '')),
        'royalty_eligible' => Request::bool('royalty_eligible') ? 1 : 0,
        'is_active' => $editing ? (int) ($rule['is_active'] ?? 0) : 1,
        'effective_from' => (string) (Request::input('effective_from') ?: date('Y-m-d H:i:s')),
        'effective_to' => $editing ? ($rule['effective_to'] ?? null) : null,
        'created_by' => (int) $adminUser['id'],
        'notes' => (string) Request::input('notes', ''),
    ];
    if ($data['direct_value'] < 0) {
        $errors['direct_value'] = ['Direct commission cannot be negative.'];
    }
    if ($data['grand_direct_value'] < 0) {
        $errors['grand_direct_value'] = ['Grand direct commission cannot be negative.'];
    }
    if ($data['direct_type'] === 'percent' && $data['direct_value'] > 100) {
        $errors['direct_value'] = ['A percentage commission cannot exceed 100%.'];
    }
    if ($data['grand_direct_type'] === 'percent' && $data['grand_direct_value'] > 100) {
        $errors['grand_direct_value'] = ['A percentage commission cannot exceed 100%.'];
    }

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');
        if ($editing) {
            // Correction in place: the version keeps its window and its active flag.
            $db->transaction(function ($db) use ($data, $rule, $ruleId, $syncProductFromRule, $now) {
                $update = $data;
                unset($update['is_active'], $update['effective_to'], $update['created_by']);
                $update['updated_at'] = $now;
                $db->update('mlm_commission_rules', $update, 'id = :id', ['id' => $ruleId]);
                if ((int) ($rule['is_active'] ?? 0) === 1) {
                    $syncProductFromRule($data + ['product_id' => $data['product_id']]);
                }
            });
            Audit::log('update', 'mlm', 'Updated commission rule #' . $ruleId, 'mlm_commission_rules', $ruleId, $data);
            Session::success('Rule version updated.' . ((int) ($rule['is_active'] ?? 0) === 1
                ? ' It is the active version, so the product now pays these values.'
                : ''));
            Request::redirect('commission-rules.php');
        }

        // New version: close the product's previous version first, then activate this one.
        $db->transaction(function ($db) use ($data, $productId, $syncProductFromRule, $now) {
            $db->execute(
                'UPDATE mlm_commission_rules SET is_active = 0, effective_to = :now, updated_at = :now WHERE product_id = :p AND is_active = 1',
                ['now' => $now, 'p' => $productId]
            );
            $payload = $data;
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            $newId = $db->insert('mlm_commission_rules', $payload);
            $syncProductFromRule($data);
            Audit::log('create', 'mlm', 'New commission rule version for product #' . $productId, 'mlm_commission_rules', $newId, $data);
        });
        Session::success('New commission rule version saved and activated.');
        Request::redirect('commission-rules.php');
    }

    // Validation failed: keep the form open with what was typed.
    $rule = ($rule ?? []) + $data;
}

// ---- open the form for editing (?id=N) -----------------------------------------
if (!is_post()) {
    $editId = Request::int('id');
    if ($editId) {
        $rule = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $editId]);
        $editing = $rule !== null;
        if (!$rule) {
            Session::error('That rule version no longer exists.');
            Request::redirect('commission-rules.php');
        }
    }
}

// ---- filters --------------------------------------------------------------------
$productFilter = Request::int('product');
$statusFilter = (string) Request::query('status', '');
$where = '1=1';
$params = [];
if ($productFilter) {
    $where .= ' AND r.product_id = :p';
    $params['p'] = $productFilter;
}
if ($statusFilter === 'active' || $statusFilter === 'closed') {
    $where .= ' AND r.is_active = :s';
    $params['s'] = $statusFilter === 'active' ? 1 : 0;
}
$total = (int) $db->value("SELECT COUNT(*) FROM mlm_commission_rules r WHERE $where", $params);
$pager = new Pager($total, 25);
$rows = $db->select(
    "SELECT r.*, p.name AS product_name, p.sku, p.mlm_enabled FROM mlm_commission_rules r
     LEFT JOIN products p ON p.id = r.product_id WHERE $where
     ORDER BY r.is_active DESC, r.id DESC" . $pager->limitSql(),
    $params
);

// Products that carry commission but have no rule version yet (added before product saves
// started snapshotting a rule, or imported). They still pay commission — the engine falls back to
// the product's own columns — so they must be visible here rather than silently missing.
$unversioned = $db->select(
    "SELECT p.id, p.name, p.sku, p.direct_commission_type, p.direct_commission_value,
            p.grand_direct_commission_type, p.grand_direct_commission_value, p.commission_basis
       FROM products p
      WHERE p.mlm_enabled = 1
        AND NOT EXISTS (SELECT 1 FROM mlm_commission_rules r WHERE r.product_id = p.id AND r.is_active = 1)
      ORDER BY p.name ASC"
);
if ($productFilter) {
    $unversioned = array_values(array_filter($unversioned, static fn (array $p): bool => (int) $p['id'] === $productFilter));
}
$activeCount = (int) $db->value('SELECT COUNT(*) FROM mlm_commission_rules WHERE is_active = 1');
// The heading always reports the whole register, even while the list is filtered, so
// "9 rule version(s) · 8 active" stays true; the pager shows the filtered count.
$totalAll = (int) $db->value('SELECT COUNT(*) FROM mlm_commission_rules');

$pageTitle = 'Commission Rules';
$pageHeading = 'Commission Rules';
$pageSub = $totalAll . ' rule version(s) · ' . $activeCount . ' active · '
    . count($unversioned) . ' product(s) using the default from the product form';
$activeModule = 'commission-rules';
$formOpen = admin_form_open($rule, $errors);
$pageActions = $formOpen
    ? '<a class="btn btn-sm btn-outline-secondary" href="commission-rules.php"><i class="fa-solid fa-list me-1"></i>Back to list</a>'
    : admin_new_button('commission-rules.php', 'rule version');
require __DIR__ . '/includes/layout.php';
?>
<div class="crud-layout<?= $formOpen ? ' is-form-open' : '' ?>">
  <div class="crud-form-col<?= $formOpen ? '' : ' d-none' ?>" id="crudForm">
    <div class="col-lg-12 p-0">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3"><?= $editing ? 'Edit rule version #' . (int) ($rule['id'] ?? 0) : 'New rule version' ?></h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'save' ?>">
        <?php if ($editing): ?><input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>"><?php endif; ?>
        <div class="mb-2">
          <label class="form-label small">Product</label>
          <?php // "Pin to a rule" arrives as ?new=1&product=N; an edit keeps its own product.
          $preselectProduct = (int) ($rule['product_id'] ?? 0) ?: (int) Request::query('product', 0); ?>
          <select name="product_id" class="form-select form-select-sm<?= invalid_class($errors, 'product_id') ?>" required<?= $editing ? ' disabled' : '' ?>>
            <option value="">Select product…</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $preselectProduct === (int) $p['id'] ? 'selected' : '' ?>><?= e((string) $p['name']) ?><?= $p['sku'] ? ' · ' . e((string) $p['sku']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($editing): ?>
            <input type="hidden" name="product_id" value="<?= (int) ($rule['product_id'] ?? 0) ?>">
            <div class="form-text small">A version stays with the product it was created for.</div>
          <?php endif; ?>
          <?= field_error($errors, 'product_id') ?>
        </div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">Direct type</label>
            <select name="direct_type" class="form-select form-select-sm">
              <option value="percent" <?= ($rule['direct_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent</option>
              <option value="fixed" <?= ($rule['direct_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed (&#8377;)</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">Direct value</label>
            <input type="number" step="0.01" min="0" name="direct_value" class="form-control form-control-sm<?= invalid_class($errors, 'direct_value') ?>" value="<?= e((string) ($rule['direct_value'] ?? '5')) ?>">
            <?= field_error($errors, 'direct_value') ?>
          </div>
          <div class="col-6"><label class="form-label small">Grand direct type</label>
            <select name="grand_direct_type" class="form-select form-select-sm">
              <option value="percent" <?= ($rule['grand_direct_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent</option>
              <option value="fixed" <?= ($rule['grand_direct_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed (&#8377;)</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">Grand direct value</label>
            <input type="number" step="0.01" min="0" name="grand_direct_value" class="form-control form-control-sm<?= invalid_class($errors, 'grand_direct_value') ?>" value="<?= e((string) ($rule['grand_direct_value'] ?? '1')) ?>">
            <?= field_error($errors, 'grand_direct_value') ?>
          </div>
          <div class="col-12"><label class="form-label small">Commission basis</label>
            <select name="commission_basis" class="form-select form-select-sm">
              <?php foreach (commission_basis_options() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($rule['commission_basis'] ?? 'selling_price') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label small">Effective from</label>
            <input type="datetime-local" name="effective_from" class="form-control form-control-sm"
                   value="<?= e(!empty($rule['effective_from']) ? date('Y-m-d\TH:i', strtotime((string) $rule['effective_from'])) : date('Y-m-d\TH:i')) ?>">
          </div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="royalty_eligible" value="1" id="roy" <?= (int) ($rule['royalty_eligible'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="roy">Counts towards royalty qualification</label></div>
          </div>
          <div class="col-12"><label class="form-label small">Internal notes</label><textarea name="notes" class="form-control form-control-sm" rows="2"><?= e((string) ($rule['notes'] ?? '')) ?></textarea></div>
        </div>
        <button class="btn btn-primary w-100 mt-3"><?= $editing ? 'Save changes' : 'Save &amp; activate version' ?></button>
      </form>
      <div class="alert alert-warning small mt-3 mb-0">
        <?php if ($editing): ?>
          Editing changes this version in place. If it is the active version the product starts paying
          these values immediately; commission already credited is never recalculated retroactively.
          To keep the old values on the record, close this version and add a new one instead.
        <?php else: ?>
          Activating a version closes the previous version. Commission already credited is never
          recalculated retroactively.
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="crud-list-col">
    <div class="panel p-3 p-md-4">
      <form class="d-flex gap-2 mb-3 flex-wrap" method="get">
        <select class="form-select form-select-sm" name="product" style="max-width:260px" onchange="this.form.submit()">
          <option value="">All products</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $productFilter === (int) $p['id'] ? 'selected' : '' ?>><?= e((string) $p['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="status" style="max-width:180px" onchange="this.form.submit()">
          <option value="">All versions</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active only</option>
          <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed only</option>
        </select>
        <?php if ($productFilter || $statusFilter !== ''): ?>
          <a class="btn btn-sm btn-outline-secondary" href="commission-rules.php">Clear</a>
        <?php endif; ?>
      </form>
      <?php if ($unversioned !== []): ?>
        <div class="alert alert-light border small">
          <strong><?= count($unversioned) ?> product(s)</strong> have commission switched on but no versioned
          rule yet. They are paying the defaults set on the product form. Saving the product, or adding a
          version below, pins them to a dated rule.
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Product</th><th>Direct</th><th>Grand direct</th><th>Basis</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($unversioned as $p): ?>
              <tr>
                <td class="small">
                  <div class="fw-semibold"><?= e((string) $p['name']) ?></div>
                  <div class="text-muted"><?= e((string) ($p['sku'] ?? '')) ?></div>
                </td>
                <td class="small"><?= e((string) $p['direct_commission_value']) ?><?= $p['direct_commission_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
                <td class="small"><?= e((string) $p['grand_direct_commission_value']) ?><?= $p['grand_direct_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
                <td class="small text-muted"><?= e(str_replace('_', ' ', (string) $p['commission_basis'])) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="commission-rules.php?new=1&amp;product=<?= (int) $p['id'] ?>">Pin to a rule</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Version</th>
              <th>Direct</th>
              <th>Grand direct</th>
              <th>Basis</th>
              <th>Effective window</th>
              <th>Status</th>
              <th class="text-end no-sort">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Product">
                <div class="small fw-semibold"><?= e((string) ($r['product_name'] ?? 'Product deleted')) ?></div>
                <div class="small text-muted"><?= e((string) ($r['sku'] ?? '')) ?></div>
              </td>
              <td data-label="Version" class="small text-muted">#<?= (int) $r['id'] ?></td>
              <td data-label="Direct" class="small"><?= e((string) $r['direct_value']) ?><?= $r['direct_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
              <td data-label="Grand direct" class="small"><?= e((string) $r['grand_direct_value']) ?><?= $r['grand_direct_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
              <td data-label="Basis" class="small text-muted"><?= e(str_replace('_', ' ', (string) $r['commission_basis'])) ?><?= (int) ($r['royalty_eligible'] ?? 0) === 1 ? '' : ' · no royalty' ?></td>
              <td data-label="Window" class="small"><?= e(format_date((string) $r['effective_from'], 'd M Y')) ?><?= $r['effective_to'] ? ' → ' . e(format_date((string) $r['effective_to'], 'd M Y')) : ' → open' ?></td>
              <td data-label="Status"><?= (int) $r['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Closed</span>' ?></td>
              <td class="text-end text-nowrap" data-label="Actions">
                <a class="btn btn-sm btn-outline-primary" href="commission-rules.php?id=<?= (int) $r['id'] ?>" title="Edit this version"><i class="fa-solid fa-pen"></i><span class="d-inline d-md-none ms-1">Edit</span></a>
                <?php if ((int) $r['is_active'] === 1): ?>
                  <form method="post" class="d-inline" data-confirm="Close this rule version? The product will fall back to the commission set on its own form until another version is active.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" title="Close this version"><i class="fa-solid fa-lock"></i><span class="d-inline d-md-none ms-1">Close</span></button>
                  </form>
                <?php else: ?>
                  <form method="post" class="d-inline" data-confirm="Reactivate this version? It becomes the current rule for that product.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-outline-success" title="Reactivate this version"><i class="fa-solid fa-rotate-left"></i><span class="d-inline d-md-none ms-1">Reopen</span></button>
                  </form>
                <?php endif; ?>
                <form method="post" class="d-inline" data-confirm="Delete rule version #<?= (int) $r['id'] ?> permanently? This cannot be undone.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete this version"><i class="fa-solid fa-trash"></i><span class="d-inline d-md-none ms-1">Delete</span></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="8" class="small text-muted">No rule versions yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
        <div class="small text-muted"><?= $pager->infoText() ?></div>
        <?= $pager->links(['product' => $productFilter, 'status' => $statusFilter]) ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
