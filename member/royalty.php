<?php
/** Royalty / incentive milestone progress and achievements. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\RoyaltyEngine;

$engine = new RoyaltyEngine(db());
$progress = $engine->progress($currentMemberId);
$achievements = db()->select(
    'SELECT ra.*, rr.name AS rule_name, rr.level_no FROM royalty_achievements ra
     LEFT JOIN royalty_rules rr ON rr.id = ra.royalty_rule_id
     WHERE ra.member_id = :m ORDER BY ra.id DESC',
    ['m' => $currentMemberId]
);

$pageTitle = 'Royalty & Incentives';
$pageHeading = 'Royalty & Incentives';
$pageSub = 'Milestone rewards based on real team performance';
$activeNav = 'royalty';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <h2 class="h6 mb-3">Milestone progress</h2>
  <?php if (!$progress): ?>
    <p class="small text-muted mb-0">No royalty rules are active right now.</p>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($progress as $rule): ?>
        <div class="col-md-6">
          <div class="milestone <?= $rule['achieved'] ? 'achieved' : '' ?>">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold"><?= e((string) $rule['name']) ?></div>
                <div class="small text-muted">Level <?= (int) $rule['level_no'] ?> · Reward <?= e((string) $rule['reward']) ?></div>
              </div>
              <?php if ($rule['achieved']): ?><span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Achieved</span><?php endif; ?>
            </div>
            <div class="progress mt-3" style="height:8px">
              <div class="progress-bar" role="progressbar" style="width:<?= (int) $rule['progress'] ?>%" aria-valuenow="<?= (int) $rule['progress'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small text-muted mt-1">Team <?= (int) $rule['current_team'] ?> of <?= (int) $rule['required_downline'] ?> · Direct <?= (int) $rule['required_direct'] ?> required</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel p-3 p-md-4">
  <h2 class="h6 mb-3">Achievements</h2>
  <?php if (!$achievements): ?>
    <p class="small text-muted mb-0">No achievements yet. Rewards appear here when you qualify.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 data-table">
      <thead><tr><th>Achieved</th><th>Milestone</th><th class="text-end">Reward</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($achievements as $row): ?>
        <tr>
          <td data-label="Achieved" class="small"><?= e(format_date((string) $row['achieved_at'], 'd M Y')) ?></td>
          <td data-label="Milestone" class="small"><?= e((string) ($row['rule_name'] ?? 'Royalty milestone')) ?></td>
          <td data-label="Reward" class="text-end fw-semibold"><?= money((float) $row['reward_amount']) ?></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p class="small text-muted mb-0 mt-3">Royalty rewards are credited after admin verification and are subject to company policy and applicable taxes.</p>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
