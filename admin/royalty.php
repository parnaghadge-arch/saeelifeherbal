<?php
/** Royalty rules + achievement approvals. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('royalty.manage');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\RoyaltyEngine;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$engine = new RoyaltyEngine($db);
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');

    if ($action === 'approve' && AdminAuth::can('royalty.approve')) {
        $ok = $engine->approveAchievement(Request::int('achievement_id'), (string) $adminUser['name']);
        Session::{$ok ? 'success' : 'error'}($ok ? 'Achievement approved and reward credited.' : 'Unable to approve this achievement.');
    } elseif ($action === 'reject' && AdminAuth::can('royalty.approve')) {
        $ok = $engine->rejectAchievement(Request::int('achievement_id'), (string) Request::input('reason', 'Rejected by admin'));
        Session::{$ok ? 'success' : 'error'}($ok ? 'Achievement rejected.' : 'Unable to reject this achievement.');
    } elseif ($action === 'run') {
        $count = $engine->processAll();
        Audit::log('royalty_run', 'royalty', 'Royalty evaluation created ' . $count . ' achievement(s)', 'royalty_achievements');
        Session::success('Evaluation complete: ' . $count . ' new achievement(s).');
    } elseif ($action === 'rule-save') {
        $data = [
            'name' => trim((string) Request::input('name', '')),
            'level_no' => max(1, Request::int('level_no', 1)),
            'required_direct_referrals' => Request::int('required_direct_referrals'),
            'required_downline' => Request::int('required_downline'),
            'required_active_members' => Request::int('required_active_members'),
            'required_purchase_count' => Request::int('required_purchase_count'),
            'min_qualifying_sales' => round((float) Request::input('min_qualifying_sales', 0), 2),
            'qualification_period_days' => Request::int('qualification_period_days'),
            'reward_type' => Request::input('reward_type') === 'percent' ? 'percent' : 'fixed',
            'reward_amount' => round((float) Request::input('reward_amount', 0), 2),
            'reward_percent' => round((float) Request::input('reward_percent', 0), 2),
            'percent_basis' => in_array(Request::input('percent_basis'), ['team_sales', 'personal_sales', 'none'], true) ? (string) Request::input('percent_basis') : 'none',
            'frequency' => in_array(Request::input('frequency'), ['once', 'monthly', 'recurring'], true) ? (string) Request::input('frequency') : 'once',
            'recurring_months' => Request::int('recurring_months'),
            'requires_admin_approval' => Request::bool('requires_admin_approval') ? 1 : 0,
            'description' => (string) Request::input('description', ''),
            'status' => Request::input('status') === '1' ? 1 : 0,
            'sort_order' => Request::int('sort_order'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($data['name'] === '') { $errors['name'] = ['Rule name is required.']; }
        if (empty($errors)) {
            $ruleId = Request::int('rule_id');
            if ($ruleId) {
                $db->update('royalty_rules', $data, 'id = :id', ['id' => $ruleId]);
                Audit::log('update', 'royalty', 'Updated royalty rule ' . $data['name'], 'royalty_rules', $ruleId);
                Session::success('Royalty rule updated.');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $newId = $db->insert('royalty_rules', $data);
                Audit::log('create', 'royalty', 'Created royalty rule ' . $data['name'], 'royalty_rules', $newId);
                Session::success('Royalty rule created.');
            }
            Request::redirect('royalty.php');
        }
    }
}

$rules = $db->select('SELECT * FROM royalty_rules ORDER BY level_no ASC, id ASC');
$achievements = $db->select(
    "SELECT ra.*, rr.name AS rule_name, m.name, m.member_code FROM royalty_achievements ra
     LEFT JOIN royalty_rules rr ON rr.id = ra.royalty_rule_id
     LEFT JOIN members m ON m.id = ra.member_id
     ORDER BY CASE ra.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, ra.id DESC LIMIT 100"
);
$editRule = Request::int('rule') ? $db->first('SELECT * FROM royalty_rules WHERE id = :id', ['id' => Request::int('rule')]) : null;

$pageTitle = 'Royalty';
$pageHeading = 'Royalty & Incentives';
$pageSub = count($rules) . ' rules · ' . count(array_filter($achievements, static fn ($a) => $a['status'] === 'pending')) . ' pending approvals';
$activeModule = 'royalty';
$pageActions = AdminAuth::can('royalty.approve') ? '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="run"><button class="btn btn-sm btn-primary">Run evaluation</button></form>' : '';
$formOpen = $editRule !== null || !empty($errors) || Request::query('new') !== null;
if (AdminAuth::can('royalty.approve')) {
    $pageActions = '<a class="btn btn-sm btn-primary" href="royalty.php?new=1"><i class="fa-solid fa-plus me-1"></i>New rule</a>'
        . ($pageActions !== '' ? ' ' . $pageActions : '');
}
require __DIR__ . '/includes/layout.php';
?>
<div class="crud-layout<?= $formOpen ? ' is-form-open' : '' ?>">
  <div class="crud-form-col<?= $formOpen ? '' : ' d-none' ?>" id="crudForm">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0"><?= $editRule ? 'Edit rule' : 'New royalty rule' ?></h2>
        <a class="btn btn-sm btn-outline-secondary" href="royalty.php">Cancel</a>
      </div>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Check the highlighted fields.</div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rule-save">
        <?php if ($editRule): ?><input type="hidden" name="rule_id" value="<?= (int) $editRule['id'] ?>"><?php endif; ?>
        <div class="row g-2">
          <div class="col-8"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" value="<?= e((string) ($editRule['name'] ?? '')) ?>" required></div>
          <div class="col-4"><label class="form-label small">Level</label><input type="number" name="level_no" class="form-control form-control-sm" value="<?= (int) ($editRule['level_no'] ?? 1) ?>"></div>
          <div class="col-4"><label class="form-label small">Direct referrals</label><input type="number" name="required_direct_referrals" class="form-control form-control-sm" value="<?= (int) ($editRule['required_direct_referrals'] ?? 0) ?>"></div>
          <div class="col-4"><label class="form-label small">Team size</label><input type="number" name="required_downline" class="form-control form-control-sm" value="<?= (int) ($editRule['required_downline'] ?? 0) ?>"></div>
          <div class="col-4"><label class="form-label small">Active members</label><input type="number" name="required_active_members" class="form-control form-control-sm" value="<?= (int) ($editRule['required_active_members'] ?? 0) ?>"></div>
          <div class="col-6"><label class="form-label small">Purchase count</label><input type="number" name="required_purchase_count" class="form-control form-control-sm" value="<?= (int) ($editRule['required_purchase_count'] ?? 0) ?>"></div>
          <div class="col-6"><label class="form-label small">Min team sales</label><input type="number" step="0.01" name="min_qualifying_sales" class="form-control form-control-sm" value="<?= e((string) ($editRule['min_qualifying_sales'] ?? 0)) ?>"></div>
          <div class="col-6"><label class="form-label small">Qualification window (days)</label><input type="number" name="qualification_period_days" class="form-control form-control-sm" value="<?= (int) ($editRule['qualification_period_days'] ?? 0) ?>"></div>
          <div class="col-6"><label class="form-label small">Frequency</label>
            <select name="frequency" class="form-select form-select-sm">
              <?php foreach (['once' => 'One time', 'monthly' => 'Monthly', 'recurring' => 'Recurring'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($editRule['frequency'] ?? 'once') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small">Reward type</label>
            <select name="reward_type" class="form-select form-select-sm">
              <option value="fixed" <?= ($editRule['reward_type'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
              <option value="percent" <?= ($editRule['reward_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percentage</option>
            </select>
          </div>
          <div class="col-3"><label class="form-label small">Amount (<?= e(currency_symbol()) ?>)</label><input type="number" step="0.01" name="reward_amount" class="form-control form-control-sm" value="<?= e((string) ($editRule['reward_amount'] ?? 0)) ?>"></div>
          <div class="col-3"><label class="form-label small">Percent %</label><input type="number" step="0.01" name="reward_percent" class="form-control form-control-sm" value="<?= e((string) ($editRule['reward_percent'] ?? 0)) ?>"></div>
          <div class="col-12"><label class="form-label small">Description</label><textarea name="description" class="form-control form-control-sm" rows="2"><?= e((string) ($editRule['description'] ?? '')) ?></textarea></div>
          <div class="col-6"><label class="form-label small">Sort order</label><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) ($editRule['sort_order'] ?? 0) ?>"></div>
          <div class="col-6"><label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="1" <?= (int) ($editRule['status'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option><option value="0" <?= (int) ($editRule['status'] ?? 1) === 0 ? 'selected' : '' ?>>Inactive</option></select>
          </div>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="requires_admin_approval" value="1" id="appr" <?= (int) ($editRule['requires_admin_approval'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="appr">Require admin approval before crediting</label></div></div>
        </div>
        <button class="btn btn-primary w-100 mt-3"><?= $editRule ? 'Update rule' : 'Create rule' ?></button>
      </form>
    </div>
  </div>

  <div class="crud-list-col">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Achievements</h2>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>Member</th><th>Milestone</th><th class="text-end">Reward</th><th>Status</th><th class="text-end">Action</th></tr></thead>
          <tbody>
          <?php foreach ($achievements as $a): ?>
            <tr>
              <td data-label="Member" class="small"><?= e((string) ($a['name'] ?? '—')) ?><div class="text-muted"><?= e((string) ($a['member_code'] ?? '')) ?></div></td>
              <td data-label="Milestone" class="small"><?= e((string) ($a['rule_name'] ?? '—')) ?><div class="text-muted"><?= e(format_date((string) $a['achieved_at'], 'd M Y')) ?></div></td>
              <td data-label="Reward" class="text-end small fw-semibold"><?= money((float) $a['reward_amount']) ?></td>
              <td data-label="Status"><?= status_badge((string) $a['status']) ?></td>
              <td class="text-end">
                <?php if ($a['status'] === 'pending' && AdminAuth::can('royalty.approve')): ?>
                  <div class="d-flex gap-1 justify-content-end">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="achievement_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-sm btn-success">Approve</button></form>
                    <form method="post" data-confirm="Reject this achievement?"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="achievement_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="reason" value="Rejected by admin"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$achievements): ?><tr><td colspan="5" class="small text-muted">No achievements yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Rules</h2>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>Rule</th><th>Criteria</th><th>Reward</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rules as $r): ?>
            <tr>
              <td data-label="Rule" class="small fw-semibold"><?= e((string) $r['name']) ?><div class="text-muted">Level <?= (int) $r['level_no'] ?> · <?= e((string) $r['frequency']) ?></div></td>
              <td data-label="Criteria" class="small">Direct <?= (int) $r['required_direct_referrals'] ?> · Team <?= (int) $r['required_downline'] ?> · Sales <?= money((float) $r['min_qualifying_sales']) ?></td>
              <td data-label="Reward" class="small"><?= $r['reward_type'] === 'percent' ? e((string) $r['reward_percent']) . '%' : money((float) $r['reward_amount']) ?></td>
              <td data-label="Status"><?= (int) $r['status'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
              <td class="text-end"><a class="btn btn-sm btn-light-green" href="?rule=<?= (int) $r['id'] ?>"><i class="fa-solid fa-pen"></i></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
