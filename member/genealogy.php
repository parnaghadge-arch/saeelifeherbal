<?php
/** Lazy-loading genealogy tree with search. Each node fetches children on demand. */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

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
$pageSub = 'Interactive downline tree — click a node to expand';
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
  <div class="tree-wrap">
    <ul class="tree" id="genealogyTree" data-endpoint="<?= e(Url::member('genealogy-data.php')) ?>">
      <li class="tree-node" data-id="<?= (int) $root['id'] ?>">
        <div class="tree-card root">
          <div class="tree-avatar"><?= e(initials((string) $root['name'])) ?></div>
          <div class="min-w-0">
            <div class="fw-semibold text-truncate"><?= e((string) $root['name']) ?></div>
            <div class="tree-meta"><?= e((string) $root['member_code']) ?> · <?= (int) $root['direct_referral_count'] ?> directs</div>
          </div>
          <button class="tree-toggle" type="button" aria-label="Expand">+</button>
        </div>
        <ul class="tree-children" hidden></ul>
      </li>
    </ul>
  </div>
  <p class="small text-muted mb-0 mt-3">Levels load on demand, so the tree stays fast even with thousands of members.</p>
</div>
<?php
$extraScripts = '<script>
(function () {
  const endpoint = document.getElementById("genealogyTree").dataset.endpoint;
  const panels = {};

  async function loadChildren(node) {
    const id = node.dataset.id;
    const list = node.querySelector(":scope > .tree-children");
    if (list.dataset.loaded === "1") {
      list.hidden = !list.hidden;
      return;
    }
    const response = await fetch(endpoint + "?parent=" + encodeURIComponent(id), { headers: { "X-Requested-With": "XMLHttpRequest" } });
    const payload = (await response.json()).data || {};
    const members = payload.members || [];
    list.innerHTML = "";
    if (!members.length) {
      list.innerHTML = "<li class=\"tree-empty\">No direct referrals</li>";
    }
    members.forEach(function (m) {
      const li = document.createElement("li");
      li.className = "tree-node";
      li.dataset.id = m.id;
      li.innerHTML = "<div class=\"tree-card\">" +
        "<div class=\"tree-avatar\">" + m.initials + "</div>" +
        "<div class=\"min-w-0\"><div class=\"fw-semibold text-truncate\">" + m.name + "</div>" +
        "<div class=\"tree-meta\">" + m.member_code + " · " + m.directs + " directs · " + m.status_label + "</div></div>" +
        (m.has_children ? "<button class=\"tree-toggle\" type=\"button\">+</button>" : "") +
        "</div><ul class=\"tree-children\" hidden></ul>";
      list.appendChild(li);
      if (m.has_children) {
        li.querySelector(".tree-toggle").addEventListener("click", function (e) {
          e.stopPropagation();
          const expanded = this.textContent === "−";
          this.textContent = expanded ? "+" : "−";
          loadChildren(li);
        });
      }
    });
    list.dataset.loaded = "1";
    list.hidden = false;
  }

  document.getElementById("genealogyTree").addEventListener("click", function (event) {
    const toggle = event.target.closest(".tree-toggle");
    if (!toggle) { return; }
    const node = toggle.closest(".tree-node");
    toggle.textContent = toggle.textContent === "+" ? "−" : "+";
    loadChildren(node);
  });

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
