<?php
/** Admin genealogy: the MLM tree with users-icon nodes, click for details. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('mlm.tree');

use Saee\Lib\MemberService;
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
$search = trim((string) Request::query('q', ''));
$results = $search !== '' ? $team->searchMembers($search, 25) : [];

$pageTitle = 'Genealogy';
$pageHeading = 'Genealogy Tree';
$pageSub = $root ? $root['name'] . ' · ' . (int) $root['direct_referral_count'] . ' direct · ' . (int) $root['downline_count'] . ' total team' : 'No members yet';
$activeModule = 'genealogy';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-12 col-md-5">
      <label class="form-label small text-muted mb-1">Search member (root or filter)</label>
      <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Name, member ID or mobile">
    </div>
    <div class="col-12 col-md-5">
      <label class="form-label small text-muted mb-1">Root member</label>
      <select class="form-select form-select-sm" name="root">
        <?php
        $rootOptions = $search !== '' ? $results : $db->select('SELECT id, name, member_code FROM members ORDER BY id ASC LIMIT 200');
        foreach ($rootOptions as $option): ?>
          <option value="<?= (int) $option['id'] ?>" <?= $rootId === (int) $option['id'] ? 'selected' : '' ?>><?= e((string) $option['name']) ?> (<?= e((string) $option['member_code']) ?>)</option>
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
  <?php else: ?>
    <?php
      $gtEndpoint = Url::admin('genealogy-data.php');
      $gtRoot = [
        'id' => (int) $root['id'],
        'name' => (string) $root['name'],
        'member_code' => (string) $root['member_code'],
        'directs' => (int) $root['direct_referral_count'],
        'active' => MemberService::hasPurchase($db, (int) $root['id']),
      ];
      require INCLUDES_PATH . '/genealogy-tree.php';
    ?>
    <div class="mt-3 d-flex gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-secondary" href="member-details.php?id=<?= (int) $rootId ?>">Member 360°</a>
    </div>
    <p class="small text-muted mb-0 mt-3">Click a node icon to open the member's MLM details beside it. Red nodes are registered members who have not purchased yet — their downline commission is calculated but payouts stay blocked until their first purchase.</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
