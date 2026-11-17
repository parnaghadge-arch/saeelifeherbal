<?php
/**
 * Shared MLM genealogy tree widget (admin + member).
 *
 * Expects:
 *   $gtEndpoint  URL returning JSON: ?parent=<id> -> data.members[] and
 *                ?detail=<id> -> data.detail{} (TeamService::treeChildren / ::mlmDetail)
 *   $gtRoot      ['id','name','member_code','active','directs'] for the root node
 *
 * Renders the classic MLM tree: every member is a users icon with their member ID
 * under it. Green icon = active (has purchased at least one product); red icon =
 * inactive (registered but no purchase yet). Clicking the icon opens the MLM
 * details window beside the node. Children load lazily per node.
 *
 * The widget appends its script to $extraScripts — require the layout end AFTER
 * including this file. Values from the JSON feed are put into DOM text nodes, so
 * the feed must carry raw strings (no e()).
 */
$gtRootActive = !empty($gtRoot['active']);
$gtRootDirects = (int) ($gtRoot['directs'] ?? $gtRoot['direct_referral_count'] ?? 0);
?>
<div class="mlm-legend small text-muted mb-3 d-flex flex-wrap gap-3">
  <span class="d-inline-flex align-items-center gap-1">
    <span class="mlm-legend-dot is-active"><i class="fa-solid fa-user"></i></span> Active — purchased
  </span>
  <span class="d-inline-flex align-items-center gap-1">
    <span class="mlm-legend-dot is-inactive"><i class="fa-solid fa-user"></i></span> Inactive — no purchase yet (commission calculated, payouts blocked)
  </span>
  <span class="d-inline-flex align-items-center gap-1">
    <i class="fa-solid fa-circle-info"></i> Click an icon for MLM details
  </span>
</div>
<div class="tree-wrap">
  <ul class="mlm-tree" id="mlmTree" data-endpoint="<?= e($gtEndpoint) ?>">
    <li class="mlm-node" data-id="<?= (int) $gtRoot['id'] ?>">
      <div class="mlm-unit">
        <button type="button" class="mlm-dot <?= $gtRootActive ? 'is-active' : 'is-inactive' ?>"
                data-mlm-detail="<?= (int) $gtRoot['id'] ?>" title="MLM details">
          <i class="fa-solid fa-user"></i>
        </button>
        <div class="mlm-id"><?= e((string) $gtRoot['member_code']) ?></div>
        <?php if ($gtRootDirects > 0): ?>
          <button type="button" class="mlm-expand" title="Expand downline">+<?= $gtRootDirects ?></button>
        <?php endif; ?>
      </div>
      <ul class="mlm-kids"></ul>
    </li>
  </ul>
</div>
<?php
$extraScripts = ($extraScripts ?? '') . <<<HTML
<script>
(function () {
  var tree = document.getElementById('mlmTree');
  if (!tree) { return; }
  var endpoint = tree.dataset.endpoint;
  var currency = <?= json_encode(currency_symbol()) ?>;

  /* ---- the MLM details window ("window besides icon") ---- */
  var pop = document.createElement('div');
  pop.className = 'mlm-pop d-none';
  pop.innerHTML = '<div class="mlm-pop-head"><strong class="mlm-pop-title"></strong>' +
    '<button type="button" class="mlm-pop-close" aria-label="Close">&times;</button></div>' +
    '<div class="mlm-pop-body"></div>';
  document.body.appendChild(pop);

  function fetchJson(url) {
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); });
  }
  function fmtMoney(n) {
    var v = Math.round((Number(n) || 0) * 100) / 100;
    return currency + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function row(label, value) {
    var d = document.createElement('div');
    d.className = 'mlm-pop-row';
    var l = document.createElement('span');
    l.className = 'text-muted';
    l.textContent = label;
    var v = document.createElement('strong');
    v.textContent = value;
    d.appendChild(l);
    d.appendChild(v);
    return d;
  }
  function positionPop(dot) {
    var r = dot.getBoundingClientRect();
    pop.classList.remove('d-none');
    var pw = pop.offsetWidth;
    var ph = pop.offsetHeight;
    var left = r.right + 12;
    if (left + pw > window.innerWidth - 8) {
      left = Math.max(8, r.left - pw - 12);
    }
    var top = r.top + r.height / 2 - ph / 2;
    top = Math.min(Math.max(8, top), window.innerHeight - ph - 8);
    pop.style.left = (left + window.scrollX) + 'px';
    pop.style.top = (top + window.scrollY) + 'px';
  }
  function openDetail(dot) {
    fetchJson(endpoint + '?detail=' + encodeURIComponent(dot.dataset.mlmDetail))
      .then(function (payload) {
        var d = (payload.data || {}).detail;
        if (!d) { return; }
        pop.querySelector('.mlm-pop-title').textContent = d.name + ' · ' + d.member_code;
        var body = pop.querySelector('.mlm-pop-body');
        body.innerHTML = '';
        var pill = document.createElement('div');
        pill.className = 'mlm-pill ' + (d.active ? 'is-active' : 'is-inactive');
        pill.textContent = d.active
          ? 'Active — purchases made'
          : 'Inactive — registered, no purchase yet';
        body.appendChild(pill);
        body.appendChild(row('Member ID', d.member_code));
        body.appendChild(row('Sponsor', d.sponsor_code || '—'));
        body.appendChild(row('Joined', d.joined_at || '—'));
        body.appendChild(row('Account', d.status + (d.kyc_status ? ' · KYC ' + d.kyc_status : '')));
        body.appendChild(row('Directs', String(d.directs)));
        body.appendChild(row('Team size', String(d.team)));
        body.appendChild(row('Purchases', d.purchases + ' order(s) · ' + fmtMoney(d.spent)));
        body.appendChild(row('Total earned', fmtMoney(d.total_earned)));
        body.appendChild(row('Wallet available', fmtMoney(d.available_balance)));
        body.appendChild(row('Pending / hold', fmtMoney(d.pending_balance)));
        if (!d.active) {
          var note = document.createElement('div');
          note.className = 'mlm-pop-note';
          note.textContent = 'Downline commission is calculated and credited as usual, ' +
            'but payouts stay blocked until this member makes a first purchase.';
          body.appendChild(note);
        }
        positionPop(dot);
      });
  }
  function closePop() { pop.classList.add('d-none'); }
  pop.querySelector('.mlm-pop-close').addEventListener('click', closePop);
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.mlm-dot') && !e.target.closest('.mlm-pop')) { closePop(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closePop(); }
  });

  /* ---- nodes ---- */
  function makeNode(m) {
    var li = document.createElement('li');
    li.className = 'mlm-node';
    li.dataset.id = m.id;
    var unit = document.createElement('div');
    unit.className = 'mlm-unit';

    var dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'mlm-dot ' + (m.active ? 'is-active' : 'is-inactive');
    dot.title = 'MLM details';
    dot.dataset.mlmDetail = String(m.id);
    dot.innerHTML = '<i class="fa-solid fa-user"></i>';

    var idLabel = document.createElement('div');
    idLabel.className = 'mlm-id';
    idLabel.textContent = m.member_code;
    idLabel.title = m.name;

    unit.appendChild(dot);
    unit.appendChild(idLabel);

    var kids = document.createElement('ul');
    kids.className = 'mlm-kids';
    kids.hidden = true;

    if (m.has_children) {
      var exp = document.createElement('button');
      exp.type = 'button';
      exp.className = 'mlm-expand';
      exp.title = 'Expand downline';
      exp.textContent = '+' + (m.directs || '');
      exp.addEventListener('click', function (ev) {
        ev.stopPropagation();
        loadKids(li, kids, exp);
      });
      unit.appendChild(exp);
    }

    li.appendChild(unit);
    li.appendChild(kids);
    return li;
  }

  function loadKids(li, kids, exp) {
    if (kids.dataset.loaded === '1') {
      kids.hidden = !kids.hidden;
      if (exp) {
        exp.textContent = (kids.hidden ? '+' : '−') + (exp.textContent.replace(/^[+−]/, '') || '');
      }
      return;
    }
    fetchJson(endpoint + '?parent=' + encodeURIComponent(li.dataset.id))
      .then(function (payload) {
        var members = (payload.data || {}).members || [];
        kids.innerHTML = '';
        if (!members.length) {
          kids.innerHTML = '<li class="mlm-empty">No direct referrals</li>';
        }
        members.forEach(function (m) { kids.appendChild(makeNode(m)); });
        kids.dataset.loaded = '1';
        kids.hidden = false;
        if (exp) {
          exp.textContent = '−' + (exp.textContent.replace(/^[+−]/, '') || '');
        }
      });
  }

  tree.addEventListener('click', function (e) {
    var dot = e.target.closest('.mlm-dot');
    if (dot) { openDetail(dot); }
  });

  var rootLi = tree.querySelector(':scope > .mlm-node');
  if (rootLi) {
    var rootExp = rootLi.querySelector(':scope > .mlm-unit > .mlm-expand');
    var rootKids = rootLi.querySelector(':scope > .mlm-kids');
    if (rootExp && rootKids) {
      rootExp.addEventListener('click', function (ev) {
        ev.stopPropagation();
        loadKids(rootLi, rootKids, rootExp);
      });
    }
  }
})();
</script>
HTML;
