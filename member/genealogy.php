<?php
/** MLM genealogy tree: users-icon nodes with member IDs, click for details. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\MemberService;
use Saee\Lib\Request;
use Saee\Lib\TeamService;
use Saee\Lib\Url;

$team = new TeamService(db());
$focusId = Request::int('member');
$ancestry = [];
if ($focusId && $focusId !== $currentMemberId) {
    // Only allow viewing members inside the logged in member's downline.
    $downline = $team->downlineIds($currentMemberId);
    if (!in_array($focusId, array_map('intval', $downline['all'] ?? []), true)) {
        $focusId = $currentMemberId;
    }
}
$rootId = $focusId ?: $currentMemberId;
$root = db()->first('SELECT * FROM members WHERE id = :id', ['id' => $rootId]);
$ancestry = $team->ancestry($rootId);

$pageTitle = 'Genealogy Tree';
$pageHeading = 'Genealogy';
$pageSub = 'MLM tree — green icons have purchased, red icons are waiting for their first purchase';
$activeNav = 'genealogy';
require __DIR__ . '/includes/layout.php';
?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-6">
      <label class="form-label small text-muted mb-1">Search your team</label>
      <div class="position-relative">
        <input class="form-control form-control-sm" id="genealogySearch" placeholder="Type a name or member ID…" autocomplete="off">
        <div class="search-results d-none" id="genealogyResults"></div>
      </div>
    </div>
    <div class="col-12 col-md-6 text-md-end">
      <?php if ($rootId !== $currentMemberId): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Url::member('genealogy.php')) ?>"><i class="fa-solid fa-rotate-left me-1"></i>Back to my tree</a>
      <?php endif; ?>
      <?= $ancestry ? '<div class="small text-muted mt-2">' . e(implode(' → ', array_map(static fn ($a) => $a['member_code'], array_reverse($ancestry)))) . '</div>' : '' ?>
    </div>
  </div>
</div>

<div class="panel p-3 p-md-4">
  <?php
    $gtEndpoint = Url::member('genealogy-data.php');
    $gtRoot = [
      'id' => (int) $root['id'],
      'name' => (string) $root['name'],
      'member_code' => (string) $root['member_code'],
      'directs' => (int) $root['direct_referral_count'],
      'active' => MemberService::hasPurchase(db(), (int) $root['id']),
    ];
    require INCLUDES_PATH . '/genealogy-tree.php';
  ?>
  <p class="small text-muted mb-0 mt-3">Levels load on demand, so the tree stays fast even with thousands of members. Click any icon for the member's MLM details.</p>
</div>
<?php
$extraScripts = ($extraScripts ?? '') . '<script>
(function () {
  const endpoint = ' . json_encode(Url::member('genealogy-data.php')) . ';
  const search = document.getElementById("genealogySearch");
  const results = document.getElementById("genealogyResults");
  let timer;
  search?.addEventListener("input", function () {
    clearTimeout(timer);
    const term = this.value.trim();
    if (term.length < 2) { results.classList.add("d-none"); return; }
    timer = setTimeout(async function () {
      const response = await fetch(endpoint + "?search=" + encodeURIComponent(term), { headers: { "X-Requested-With": "XMLHttpRequest" } });
      const payload = (await response.json()).data || {};
      results.innerHTML = (payload.members || []).map(function (m) {
        return "<a class=\"search-result\" href=\"genealogy.php?member=" + m.id + "\"><span>" + m.name + "</span><span class=\"text-muted small\">" + m.member_code + "</span></a>";
      }).join("") || "<div class=\"p-2 small text-muted\">No members found</div>";
      results.classList.remove("d-none");
    }, 250);
  });
  document.addEventListener("click", function (e) {
    if (!e.target.closest("#genealogySearch") && !e.target.closest("#genealogyResults")) {
      results?.classList.add("d-none");
    }
  });
})();
</script>';
require __DIR__ . '/includes/layout-end.php';
