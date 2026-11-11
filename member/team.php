<?php
/** Full downline listing grouped by level. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\TeamService;
use Saee\Lib\Url;

$team = new TeamService(db());
$search = trim((string) Request::query('q', ''));
$level = max(0, Request::int('level'));
$counts = $team->downlineCount($currentMemberId);

$tree = $team->downlineIds($currentMemberId);
$levels = $tree['levels'] ?? [];
$allIds = array_values(array_filter(array_map('intval', $tree['all'] ?? []), static fn (int $id) => $id !== $currentMemberId));

$levelOptions = [];
foreach ($levels as $lvl => $ids) {
    $levelOptions[(int) $lvl] = count($ids);
}

$rows = [];
$total = 0;
if ($level > 0) {
    $ids = array_map('intval', $levels[$level] ?? []);
} else {
    $ids = $allIds;
}
if ($ids !== []) {
    $in = implode(',', $ids);
    $sql = "SELECT m.* FROM members m WHERE m.id IN ($in)";
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q)';
        $params['q'] = '%' . $search . '%';
    }
    $total = (int) db()->value(preg_replace('/SELECT m\.\* FROM/', 'SELECT COUNT(*) FROM', $sql), $params);
    $pager = new Pager($total, 20);
    $rows = db()->select($sql . ' ORDER BY m.id DESC' . $pager->limitSql(), $params);
} else {
    $pager = new Pager(0, 20);
}

$pageTitle = 'My Team';
$pageHeading = 'My Team';
$pageSub = 'Total ' . (int) $counts['total'] . ' members · ' . (int) $counts['active'] . ' active';
$activeNav = 'team';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-12 col-md-5">
      <label class="form-label small text-muted mb-1">Search</label>
      <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Name, member ID or mobile">
    </div>
    <div class="col-7 col-md-4">
      <label class="form-label small text-muted mb-1">Level</label>
      <select class="form-select form-select-sm" name="level">
        <option value="0">All levels</option>
        <?php foreach ($levelOptions as $lvl => $count): ?>
          <option value="<?= (int) $lvl ?>"<?= $level === (int) $lvl ? ' selected' : '' ?>>Level <?= (int) $lvl ?> (<?= (int) $count ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-5 col-md-3"><button class="btn btn-sm btn-dark w-100">Apply</button></div>
  </form>
</div>

<div class="panel p-3 p-md-4">
  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">No team members found<?= $search !== '' ? ' for your search' : ' yet' ?>.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead><tr><th>Member</th><th>Joined</th><th>Status</th><th>Directs</th><th class="text-end">Team size</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Member">
            <div class="fw-semibold"><?= e((string) $row['name']) ?></div>
            <div class="small text-muted"><?= e((string) $row['member_code']) ?></div>
          </td>
          <td data-label="Joined" class="small"><?= e(format_date((string) $row['joined_at'], 'd M Y')) ?></td>
          <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
          <td data-label="Directs" class="small"><?= (int) $row['direct_referral_count'] ?></td>
          <td data-label="Team" class="text-end small"><?= (int) $row['downline_count'] ?></td>
          <td data-label="Action" class="text-end">
            <?php if ((string) $row['status'] === 'active'): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(Url::to('member/buy-products.php?for=' . (int) $row['id'])) ?>">Buy for</a>
            <?php else: ?>
              <span class="small text-muted"><?= e(ucfirst((string) $row['status'])) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted"><?= $pager->infoText() ?></div>
    <?= $pager->links(['q' => $search, 'level' => $level]) ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
