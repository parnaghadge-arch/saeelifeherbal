<?php
/**
 * Per-product commission rule versions.
 * Creating a new version closes the previous version (effective_to) so historical
 * calculations remain reproducible and nothing is overwritten.
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
$rule = null; // set by the "close" action; the form is a new version, not an edit

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'save');

    if ($action === 'close') {
        $ruleId = Request::int('rule_id');
        $rule = $db->first('SELECT * FROM mlm_commission_rules WHERE id = :id', ['id' => $ruleId]);
        if ($rule) {
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
        'is_active' => 1,
        'effective_from' => (string) (Request::input('effective_from') ?: date('Y-m-d H:i:s')),
        'effective_to' => null,
        'created_by' => (int) $adminUser['id'],
        'notes' => (string) Request::input('notes', ''),
    ];
    if ($data['direct_type'] === 'percent' && $data['direct_value'] > 100) {
        $errors['direct_value'] = ['A percentage commission cannot exceed 100%.'];
    }
    if ($data['grand_direct_type'] === 'percent' && $data['grand_direct_value'] > 100) {
        $errors['grand_direct_value'] = ['A percentage commission cannot exceed 100%.'];
    }
    if (empty($errors)) {
        $db->transaction(function ($db) use ($data, $productId) {
            $now = date('Y-m-d H:i:s');
            $db->execute(
                'UPDATE mlm_commission_rules SET is_active = 0, effective_to = :now, updated_at = :now WHERE product_id = :p AND is_active = 1',
                ['now' => $now, 'p' => $productId]
            );
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $newId = $db->insert('mlm_commission_rules', $data);
            // keep the product row as the "current" quick view
            $db->update('products', [
                'direct_commission_type' => $data['direct_type'],
                'direct_commission_value' => $data['direct_value'],
                'grand_direct_commission_type' => $data['grand_direct_type'],
                'grand_direct_commission_value' => $data['grand_direct_value'],
                'commission_basis' => $data['commission_basis'],
                'royalty_eligible' => $data['royalty_eligible'],
                'updated_at' => $now,
            ], 'id = :id', ['id' => $productId]);
            Audit::log('create', 'mlm', 'New commission rule version for product #' . $productId, 'mlm_commission_rules', $newId, $data);
        });
        Session::success('New commission rule version saved and activated.');
        Request::redirect('commission-rules.php');
    }
}

$productFilter = Request::int('product');
$where = '1=1';
$params = [];
if ($productFilter) {
    $where = 'r.product_id = :p';
    $params['p'] = $productFilter;
}
$total = (int) $db->value("SELECT COUNT(*) FROM mlm_commission_rules r WHERE $where", $params);
$pager = new Pager($total, 25);
$rows = $db->select(
    "SELECT r.*, p.name AS product_name, p.sku FROM mlm_commission_rules r
     LEFT JOIN products p ON p.id = r.product_id WHERE $where
     ORDER BY r.is_active DESC, r.id DESC" . $pager->limitSql(),
    $params
);

$pageTitle = 'Commission Rules';
$pageHeading = 'Commission Rules';
$pageSub = $total . ' rule versions · per product, effective-dated, never overwritten';
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
      <h2 class="h6 mb-3">New rule version</h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="mb-2">
          <label class="form-label small">Product</label>
          <select name="product_id" class="form-select form-select-sm<?= invalid_class($errors, 'product_id') ?>" required>
            <option value="">Select product…</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?><?= $p['sku'] ? ' · ' . e((string) $p['sku']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'product_id') ?>
        </div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">Direct type</label>
            <select name="direct_type" class="form-select form-select-sm"><option value="percent">Percent</option><option value="fixed">Fixed (\u{20B9})</option></select>
          </div>
          <div class="col-6">
            <label class="form-label small">Direct value</label>
            <input type="number" step="0.01" name="direct_value" class="form-control form-control-sm<?= invalid_class($errors, 'direct_value') ?>" value="5">
            <?= field_error($errors, 'direct_value') ?>
          </div>
          <div class="col-6"><label class="form-label small">Grand direct type</label>
            <select name="grand_direct_type" class="form-select form-select-sm"><option value="percent">Percent</option><option value="fixed">Fixed (\u{20B9})</option></select>
          </div>
          <div class="col-6">
            <label class="form-label small">Grand direct value</label>
            <input type="number" step="0.01" name="grand_direct_value" class="form-control form-control-sm<?= invalid_class($errors, 'grand_direct_value') ?>" value="1">
            <?= field_error($errors, 'grand_direct_value') ?>
          </div>
          <div class="col-12"><label class="form-label small">Commission basis</label>
            <select name="commission_basis" class="form-select form-select-sm">
              <?php foreach (commission_basis_options() as $k => $label): ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label small">Effective from</label>
            <input type="datetime-local" name="effective_from" class="form-control form-control-sm" value="<?= e(date('Y-m-d\TH:i')) ?>">
          </div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="royalty_eligible" value="1" id="roy" checked><label class="form-check-label small" for="roy">Counts towards royalty qualification</label></div>
          </div>
          <div class="col-12"><label class="form-label small">Internal notes</label><textarea name="notes" class="form-control form-control-sm" rows="2"></textarea></div>
        </div>
        <button class="btn btn-primary w-100 mt-3">Save &amp; activate version</button>
      </form>
      <div class="alert alert-warning small mt-3 mb-0">
        Activating a version closes the previous version. Commission already credited is never recalculated retroactively.
      </div>
    </div>
  </div>
  <div class="crud-list-col">
    <div class="panel p-3 p-md-4">
      <form class="d-flex gap-2 mb-3" method="get">
        <select class="form-select form-select-sm" name="product" onchange="this.form.submit()">
          <option value="">All products</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $productFilter === (int) $p['id'] ? 'selected' : '' ?>><?= e((string) $p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>Product / version</th><th>Direct</th><th>Grand direct</th><th>Window</th><th>Status</th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Product"><div class="small fw-semibold"><?= e((string) ($r['product_name'] ?? '—')) ?></div><div class="small text-muted">#<?= (int) $r['id'] ?> · basis <?= e(str_replace('_', ' ', (string) $r['commission_basis'])) ?></div></td>
              <td data-label="Direct" class="small"><?= e((string) $r['direct_value']) ?><?= $r['direct_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
              <td data-label="Grand direct" class="small"><?= e((string) $r['grand_direct_value']) ?><?= $r['grand_direct_type'] === 'percent' ? '%' : ' ' . currency_symbol() ?></td>
              <td data-label="Window" class="small"><?= e(format_date((string) $r['effective_from'], 'd M Y')) ?><?= $r['effective_to'] ? ' → ' . e(format_date((string) $r['effective_to'], 'd M Y')) : ' → open' ?></td>
              <td data-label="Status"><?= (int) $r['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Closed</span>' ?></td>
              <td class="text-end">
                <?php if ((int) $r['is_active'] === 1): ?>
                  <form method="post" class="d-inline" data-confirm="Close this rule version?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary">Close</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="6" class="small text-muted">No rule versions yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
        <div class="small text-muted"><?= $pager->infoText() ?></div>
        <?= $pager->links(['product' => $productFilter]) ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
