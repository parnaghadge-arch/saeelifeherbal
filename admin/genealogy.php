<?php
/** Admin genealogy explorer: any member's downline, level by level. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('mlm.tree');

use Saee\Lib\Request;
use Saee\Lib\TeamService;
use Saee\Lib\Url;

$db = db();
$team = new TeamService($db);
$rootId = Request::int('root');
if (!$rootId) {
    $rootId = (int) ($db->value('SELECT id FROM members WHERE is_company = 1 ORDER BY id ASC LIMIT 1') ?: 0);
}
if (!$rootId) {
    $rootId = (int) ($db->value('SELECT id FROM members ORDER BY id ASC LIMIT 1') ?: 0);
}
$root = $rootId ? $db->first('SELECT * FROM members WHERE id = :id', ['id' => $rootId]) : null;
$level = max(0, Request::int('level'));
$search = trim((string) Request::query('q', ''));
$results = $search !== '' ? $team->searchMembers($search, 25) : [];
$searchIds = array_map(static fn ($r) => (int) $r['id'], $results);

$tree = $root ? $team->downlineIds($rootId) : ['levels' => [], 'all' => []];
$levels = $tree['levels'] ?? [];
$rows = [];
if ($root) {
    if ($level > 0) {
        $ids = array_map('intval', $levels[$level] ?? []);
    } else {
        $ids = array_map('intval', array_filter($tree['all'] ?? [], static fn ($id) => (int) $id !== $rootId));
        if ($search !== '') {
            $ids = array_values(array_intersect($ids, $searchIds));
        }
    }
    if ($ids !== [] && $level > 0 && $search !== '') {
        $ids = array_values(array_intersect($ids, $searchIds));
    }
    if ($ids !== []) {
        $in = implode(',', array_slice($ids, 0, 300));
        $rows = $db->select(
            "SELECT m.*, s.member_code AS sponsor_code FROM members m LEFT JOIN members s ON s.id = m.sponsor_id
             WHERE m.id IN ($in) ORDER BY m.id ASC"
        );
    }
}
$levelOptions = [];
foreach ($levels as $lvl => $ids) {
    $levelOptions[(int) $lvl] = count($ids);
}

$pageTitle = 'Genealogy';
$pageHeading = 'Genealogy Explorer';
$pageSub = $root ? $root['name'] . ' · ' . (int) $root['direct_referral_count'] . ' direct · ' . (int) $root['downline_count'] . ' total team' : 'No members yet';
$activeModule = 'genealogy';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-12 col-md-4">
      <label class="form-label small text-muted mb-1">Search member (root or filter)</label>
      <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Name, member ID or mobile">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">Root member</label>
      <select class="form-select form-select-sm" name="root">
        <?php
        $rootOptions = $search !== '' ? $results : $db->select('SELECT id, name, member_code FROM members ORDER BY id ASC LIMIT 200');
        foreach ($rootOptions as $option): ?>
          <option value="<?= (int) $option['id'] ?>" <?= $rootId === (int) $option['id'] ? 'selected' : '' ?>><?= e((string) $option['name']) ?> (<?= e((string) $option['member_code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small text-muted mb-1">Level</label>
      <select class="form-select form-select-sm" name="level">
        <option value="0">All levels</option>
        <?php foreach ($levelOptions as $lvl => $count): ?>
          <option value="<?= (int) $lvl ?>" <?= $level === $lvl ? 'selected' : '' ?>>Level <?= (int) $lvl ?> (<?= (int) $count ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-dark w-100">Load tree</button></div>
  </form>
</div>

<?php if ($search !== '' && $results): ?>
  <div class="panel p-3 p-md-4 mb-3">
    <h2 class="h6 mb-2">Matching members</h2>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($results as $r): ?>
        <a class="btn btn-sm btn-light-green" href="?root=<?= (int) $r['id'] ?>"><?= e((string) $r['name']) ?> · <?= e((string) $r['member_code']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel p-3 p-md-4">
  <?php if (!$root): ?>
    <p class="small text-muted mb-0">No members registered yet.</p>
  <?php elseif (!$rows): ?>
    <p class="small text-muted mb-0">This member has no downline at the selected level.</p>
  <?php else: ?>
    <h2 class="h6 mb-2"><?= count($rows) ?> member(s) <?= $level > 0 ? 'at level ' . (int) $level : 'in the downline' ?></h2>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead><tr><th>Member</th><th>Sponsor</th><th>Joined</th><th>Status</th><th class="text-end">Directs</th><th class="text-end">Team</th><th class="text-end">Earned</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td data-label="Member"><div class="small fw-semibold"><?= e((string) $row['name']) ?></div><div class="text-muted small"><?= e((string) $row['member_code']) ?></div></td>
            <td data-label="Sponsor" class="small"><?= e((string) ($row['sponsor_code'] ?? '—')) ?></td>
            <td data-label="Joined" class="small"><?= e(format_date((string) $row['joined_at'], 'd M Y')) ?></td>
            <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
            <td data-label="Directs" class="text-end small"><?= (int) $row['direct_referral_count'] ?></td>
            <td data-label="Team" class="text-end small"><?= (int) $row['downline_count'] ?></td>
            <td data-label="Earned" class="text-end small"><?= money((float) $row['total_earned']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-3 d-flex gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-secondary" href="member-details.php?id=<?= (int) $rootId ?>">Member 360°</a>
      <a class="btn btn-sm btn-outline-secondary" href="?root=<?= (int) $rootId ?>&level=<?= max(1, $level) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">Level <?= max(1, $level) ?> only</a>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
