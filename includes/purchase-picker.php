<?php
/**
 * Shared "add products" picker for the two assisted-purchase desks
 * (admin/purchase.php and member/buy-products.php).
 *
 * WHY THIS EXISTS
 * ---------------
 * Both desks used to print one table row — with its own quantity box — for every active product.
 * With a handful of products that is fine; with a real catalogue it turns the page into an
 * endless list of empty "0" boxes, the payment and delivery panels get pushed far below the
 * fold, and the operator cannot see what they have actually chosen. The fix is to separate the
 * two jobs:
 *
 *   1. BROWSE — a fixed-height, scrollable, instantly-filtered list. Its height never grows with
 *      the catalogue, so the rest of the form stays on screen.
 *   2. ORDER  — a short list of only the chosen items, which is where quantities live.
 *
 * The picker is presentational. It fills `qty[<product_id>]` inputs and never touches the
 * service, so both desks keep posting exactly what MemberPurchaseService::create() already
 * accepts.
 *
 * EXPECTED VARIABLES (all optional, all pp-prefixed)
 *   $ppProducts      array   rows from MemberPurchaseService::products()
 *   $ppSearch        string  the term already applied server-side
 *   $ppTotalAll      int     total active products, to detect a truncated result set
 *
 * Every local is pp-prefixed on purpose: admin/includes/layout.php and member/includes/layout.php
 * leak their loop variables into page scope, and a collision there renders the nav menu instead
 * of the data with no warning at all.
 */

$ppProducts = array_values(array_filter((array) ($ppProducts ?? []), 'is_array'));
$ppSearch = trim((string) ($ppSearch ?? ''));
$ppTotalAll = (int) ($ppTotalAll ?? count($ppProducts));
$ppCapped = $ppTotalAll > count($ppProducts);

// Compact payload for the client: the grid is filtered in the browser so it reacts on every
// keystroke without a round trip.
$ppCatalog = [];
$ppCategories = [];
foreach ($ppProducts as $ppRow) {
    $ppId = (int) ($ppRow['id'] ?? 0);
    if ($ppId < 1) {
        continue;
    }
    $ppCategory = trim((string) ($ppRow['category_name'] ?? ''));
    if ($ppCategory !== '') {
        $ppCategories[$ppCategory] = true;
    }
    $ppStock = (int) ($ppRow['stock_quantity'] ?? 0);
    $ppCatalog[] = [
        'id' => $ppId,
        'name' => (string) ($ppRow['name'] ?? 'Product ' . $ppId),
        'sku' => (string) ($ppRow['sku'] ?? ''),
        'price' => (float) ($ppRow['price'] ?? 0),
        'mrp' => (float) ($ppRow['mrp'] ?? 0),
        'tax' => (float) ($ppRow['tax_percent'] ?? 0),
        'stock' => $ppStock,
        'category' => $ppCategory,
        'image' => !empty($ppRow['featured_image']) ? \Saee\Lib\Url::media((string) $ppRow['featured_image']) : '',
    ];
}
$ppCategories = array_keys($ppCategories);
sort($ppCategories, SORT_STRING | SORT_FLAG_CASE);
?>
<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h2 class="h6 mb-0"><span class="pp-step">1</span> Add products</h2>
      <div class="small text-muted">Pick the items to order. Quantities are set in step 2.</div>
    </div>
    <div class="small text-muted text-end">
      <span data-pp-count><?= count($ppCatalog) ?></span> shown
      <?php if ($ppCapped): ?>
        &middot; <span class="text-warning-emphasis">of <?= (int) $ppTotalAll ?> total</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($ppCatalog === []): ?>
    <p class="small text-muted mb-0">No active products<?= $ppSearch !== '' ? ' matched "' . e($ppSearch) . '"' : '' ?>.</p>
  <?php else: ?>
    <div class="pp-toolbar">
      <div class="input-group input-group-sm pp-search">
        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" class="form-control" id="ppFilter" data-pp-search
               value="<?= e($ppSearch) ?>" placeholder="Filter by name, SKU or category" autocomplete="off">
        <button class="btn btn-outline-secondary" type="button" data-pp-clear hidden>Clear</button>
      </div>
      <?php if ($ppCategories !== []): ?>
        <div class="pp-cats" role="group" aria-label="Filter by category">
          <button type="button" class="btn btn-sm btn-outline-secondary pp-cat is-on" data-pp-cat="">All</button>
          <?php foreach ($ppCategories as $ppCategoryName): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary pp-cat" data-pp-cat="<?= e($ppCategoryName) ?>"><?= e($ppCategoryName) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($ppCapped): ?>
      <div class="alert alert-warning small py-2 mb-2">
        Showing the first <?= count($ppCatalog) ?> of <?= (int) $ppTotalAll ?> products. Press
        <strong>Enter</strong> in the search box to search the whole catalogue.
      </div>
    <?php endif; ?>

    <div class="pp-grid" data-pp-grid role="list" aria-label="Products"></div>
    <p class="small text-muted mt-2 mb-0" data-pp-empty hidden>No product matches that filter.</p>
  <?php endif; ?>
</div>

<div class="panel p-3 p-md-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
      <h2 class="h6 mb-0"><span class="pp-step">2</span> This order <span class="badge bg-light text-dark border" data-pp-linecount>0</span></h2>
      <div class="small text-muted">Only the items you have picked. Change quantities or remove here.</div>
    </div>
    <button type="button" class="btn btn-sm btn-outline-danger" data-pp-clearall hidden>Clear all</button>
  </div>
  <div data-pp-lines></div>
  <p class="small text-muted mb-0" data-pp-nolines>No products selected yet &mdash; use step 1 to add some.</p>
  <!-- JS writes one hidden qty input per selected product into here. -->
  <div data-pp-hidden></div>
</div>

<script>
// Runs on DOMContentLoaded rather than inline: the totals bar it updates is rendered by
// includes/purchase-totals.php, further down the page, after the payment and delivery panels.
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var CATALOG = <?= json_encode($ppCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var CURRENCY = <?= json_encode(currency_symbol()) ?>;
  var FREE_SHIPPING_OVER = 999;
  var SHIPPING_FLAT = 60;

  var byId = {};
  // The lowercase haystack is derived here rather than shipped in the payload: with a few
  // hundred products that string would nearly double the inline JSON on every page load.
  CATALOG.forEach(function (p) {
    p.hay = (p.name + ' ' + p.sku + ' ' + p.category).toLowerCase();
    byId[p.id] = p;
  });

  var grid = document.querySelector('[data-pp-grid]');
  var lineBox = document.querySelector('[data-pp-lines]');
  var hiddenBox = document.querySelector('[data-pp-hidden]');
  var filter = document.querySelector('[data-pp-search]');
  var clearBtn = document.querySelector('[data-pp-clear]');
  var noLines = document.querySelector('[data-pp-nolines]');
  var noMatch = document.querySelector('[data-pp-empty]');
  var clearAll = document.querySelector('[data-pp-clearall]');
  var lineCount = document.querySelector('[data-pp-linecount]');
  var countShown = document.querySelector('[data-pp-count]');
  var submitBtn = document.querySelector('[data-pp-submit]');
  var summaryHint = document.querySelector('[data-pp-summaryhint]');
  var out = {
    subtotal: document.querySelector('[data-summary="subtotal"]'),
    tax: document.querySelector('[data-summary="tax"]'),
    shipping: document.querySelector('[data-summary="shipping"]'),
    total: document.querySelector('[data-summary="total"]')
  };

  var lines = {};      // productId -> qty
  var activeCat = '';

  function money(n) {
    return CURRENCY + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ---- browse ---------------------------------------------------------------
  function matches(p, term) {
    return term === '' || p.hay.indexOf(term) !== -1;
  }
  function renderGrid() {
    if (!grid) { return; }
    var term = (filter ? filter.value : '').trim().toLowerCase();
    var html = '';
    var shown = 0;
    CATALOG.forEach(function (p) {
      if (activeCat !== '' && p.category !== activeCat) { return; }
      if (!matches(p, term)) { return; }
      shown++;
      var picked = lines[p.id] ? ' is-picked' : '';
      var stockTxt = p.stock > 0 ? p.stock + ' in stock' : 'Stock not tracked';
      html += '<div class="pp-card' + picked + '" role="listitem" data-pp-pick="' + p.id + '">'
        + '<div class="pp-thumb">'
        + (p.image ? '<img src="' + esc(p.image) + '" alt="" width="44" height="44" loading="lazy">'
                   : '<span class="pp-thumb-ph"><i class="fa-solid fa-leaf"></i></span>')
        + '</div>'
        + '<div class="pp-body">'
        + '<div class="pp-name">' + esc(p.name) + '</div>'
        + '<div class="pp-meta">'
        + (p.sku ? esc(p.sku) + ' &middot; ' : '')
        + esc(stockTxt)
        + (p.tax > 0 ? ' &middot; GST ' + esc(p.tax) + '%' : '')
        + '</div>'
        + '<div class="pp-price">' + money(p.price)
        + (p.mrp > p.price ? ' <s class="pp-mrp">' + money(p.mrp) + '</s>' : '')
        + '</div>'
        + '</div>'
        + '<button type="button" class="btn btn-sm btn-outline-success pp-add" data-pp-add="' + p.id + '">'
        + '<i class="fa-solid fa-plus"></i><span> Add</span></button>'
        + '</div>';
    });
    grid.innerHTML = html;
    if (countShown) { countShown.textContent = shown; }
    if (noMatch) { noMatch.hidden = shown !== 0; }
    if (clearBtn) { clearBtn.hidden = term === ''; }
  }

  // ---- order ----------------------------------------------------------------
  function syncHidden() {
    if (!hiddenBox) { return; }
    var html = '';
    Object.keys(lines).forEach(function (id) {
      if (lines[id] > 0) {
        html += '<input type="hidden" name="qty[' + id + ']" value="' + lines[id] + '">';
      }
    });
    hiddenBox.innerHTML = html;
  }
  function renderLines() {
    if (!lineBox) { return; }
    var ids = Object.keys(lines).filter(function (id) { return lines[id] > 0; });
    var html = '';
    ids.forEach(function (id) {
      var p = byId[id];
      if (!p) { return; }
      var qty = lines[id];
      html += '<div class="pp-line">'
        + '<div class="pp-thumb pp-thumb-sm">'
        + (p.image ? '<img src="' + esc(p.image) + '" alt="" width="34" height="34" loading="lazy">'
                   : '<span class="pp-thumb-ph"><i class="fa-solid fa-leaf"></i></span>')
        + '</div>'
        + '<div class="pp-line-main">'
        + '<div class="pp-line-name">' + esc(p.name) + '</div>'
        + '<div class="pp-meta">' + (p.sku ? esc(p.sku) + ' &middot; ' : '') + money(p.price) + ' each</div>'
        + '</div>'
        + '<div class="pp-stepper">'
        + '<button type="button" class="btn btn-sm btn-outline-secondary" data-pp-dec="' + p.id + '" aria-label="Decrease">&minus;</button>'
        + '<input type="number" class="form-control form-control-sm text-center" value="' + qty + '" min="1"'
        + ' max="' + (p.stock > 0 ? p.stock : 999) + '" data-pp-qty="' + p.id + '" aria-label="Quantity">'
        + '<button type="button" class="btn btn-sm btn-outline-secondary" data-pp-inc="' + p.id + '" aria-label="Increase">+</button>'
        + '</div>'
        + '<div class="pp-line-total">' + money(p.price * qty) + '</div>'
        + '<button type="button" class="btn btn-sm btn-link text-danger px-1" data-pp-rm="' + p.id + '" aria-label="Remove">'
        + '<i class="fa-solid fa-trash-can"></i></button>'
        + '</div>';
    });
    lineBox.innerHTML = html;
    if (noLines) { noLines.hidden = ids.length !== 0; }
    if (clearAll) { clearAll.hidden = ids.length === 0; }
    if (lineCount) { lineCount.textContent = ids.length; }
    if (submitBtn) { submitBtn.disabled = ids.length === 0; }
    if (summaryHint) {
      summaryHint.textContent = ids.length === 0
        ? 'Add at least one product to continue'
        : ids.length + (ids.length === 1 ? ' item selected' : ' items selected');
    }
    syncHidden();
  }
  function recalcTotals() {
    var subtotal = 0, tax = 0;
    Object.keys(lines).forEach(function (id) {
      var p = byId[id];
      if (!p) { return; }
      var line = lines[id] * p.price;
      subtotal += line;
      tax += line * (p.tax / 100);
    });
    var shipping = (subtotal > 0 && subtotal < FREE_SHIPPING_OVER) ? SHIPPING_FLAT : 0;
    if (out.subtotal) { out.subtotal.textContent = money(subtotal); }
    if (out.tax) { out.tax.textContent = money(tax); }
    if (out.shipping) { out.shipping.textContent = money(shipping); }
    if (out.total) { out.total.textContent = money(subtotal + tax + shipping); }
  }
  function redraw() { renderGrid(); renderLines(); recalcTotals(); }

  function setQty(id, qty) {
    var p = byId[id];
    if (!p) { return; }
    var max = p.stock > 0 ? p.stock : 999;
    qty = Math.max(0, Math.min(max, parseInt(qty, 10) || 0));
    if (qty === 0) { delete lines[id]; } else { lines[id] = qty; }
    redraw();
  }

  // ---- wiring ---------------------------------------------------------------
  if (grid) {
    grid.addEventListener('click', function (ev) {
      var add = ev.target.closest('[data-pp-add]');
      if (add) {
        setQty(add.getAttribute('data-pp-add'), (lines[add.getAttribute('data-pp-add')] || 0) + 1);
        return;
      }
      var card = ev.target.closest('[data-pp-pick]');
      if (card) {
        var cid = card.getAttribute('data-pp-pick');
        setQty(cid, (lines[cid] || 0) + 1);
      }
    });
  }
  if (lineBox) {
    lineBox.addEventListener('click', function (ev) {
      var inc = ev.target.closest('[data-pp-inc]');
      if (inc) { setQty(inc.getAttribute('data-pp-inc'), (lines[inc.getAttribute('data-pp-inc')] || 0) + 1); return; }
      var dec = ev.target.closest('[data-pp-dec]');
      if (dec) { setQty(dec.getAttribute('data-pp-dec'), (lines[dec.getAttribute('data-pp-dec')] || 0) - 1); return; }
      var rm = ev.target.closest('[data-pp-rm]');
      if (rm) { setQty(rm.getAttribute('data-pp-rm'), 0); }
    });
    lineBox.addEventListener('change', function (ev) {
      var box = ev.target.closest('[data-pp-qty]');
      if (box) { setQty(box.getAttribute('data-pp-qty'), box.value); }
    });
  }
  if (filter) {
    var tick = null;
    filter.addEventListener('input', function () {
      if (tick) { clearTimeout(tick); }
      tick = setTimeout(renderGrid, 90);
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (filter) { filter.value = ''; filter.focus(); }
      renderGrid();
    });
  }
  if (clearAll) {
    clearAll.addEventListener('click', function () { lines = {}; redraw(); });
  }
  document.querySelectorAll('[data-pp-cat]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      activeCat = btn.getAttribute('data-pp-cat') || '';
      document.querySelectorAll('[data-pp-cat]').forEach(function (b) { b.classList.remove('is-on'); });
      btn.classList.add('is-on');
      renderGrid();
    });
  });

  redraw();
});
</script>
