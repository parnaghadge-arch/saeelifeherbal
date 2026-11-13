    </div>
  </div>
</div>

<nav class="admin-bottom-nav" aria-label="Admin quick navigation">
  <a href="<?= e(\Saee\Lib\Url::admin('index.php')) ?>" class="<?= $activeModule === 'dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i><span>Home</span></a>
  <a href="<?= e(\Saee\Lib\Url::admin('members.php')) ?>" class="<?= $activeModule === 'members' ? 'active' : '' ?>"><i class="fa-solid fa-user-group" aria-hidden="true"></i><span>Members</span></a>
  <a href="<?= e(\Saee\Lib\Url::admin('payout-reports.php')) ?>" class="<?= in_array($activeModule, ['payouts', 'payout-reports'], true) ? 'active' : '' ?>"><i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i><span>Payouts</span></a>
  <a href="<?= e(\Saee\Lib\Url::admin('orders.php')) ?>" class="<?= $activeModule === 'orders' ? 'active' : '' ?>"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><span>Orders</span></a>
  <button type="button" id="adminMenuToggle" aria-label="Open menu"><i class="fa-solid fa-bars" aria-hidden="true"></i><span>Menu</span></button>
</nav>

<script>
function saeeOpenSidebar() {
  document.getElementById('appSidebar')?.classList.add('open');
  document.getElementById('sidebarBackdrop')?.classList.add('show');
}
document.getElementById('sidebarToggle')?.addEventListener('click', saeeOpenSidebar);
document.getElementById('adminMenuToggle')?.addEventListener('click', saeeOpenSidebar);
document.getElementById('sidebarBackdrop')?.addEventListener('click', function () {
  document.getElementById('appSidebar').classList.remove('open');
  this.classList.remove('show');
});
document.querySelectorAll('[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!window.confirm(form.dataset.confirm)) { e.preventDefault(); }
  });
});
</script>
<script src="<?= e(\Saee\Lib\Url::asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(\Saee\Lib\Url::asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(\Saee\Lib\Url::asset('vendor/datatables/dataTables.min.js')) ?>"></script>
<script src="<?= e(\Saee\Lib\Url::asset('vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(\Saee\Lib\Url::asset('js/app.js')) ?>"></script>
<script>
/* Every admin data table gets sorting, search and paging with zero per-page config.
   Tables opt out with class "data-table-plain" (printable reports, exports, etc.). */
(function () {
  if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) { return; }
  // matchMedia is missing in a few embedded webviews; without this guard the whole
  // initialiser would throw and no table on the page would get sorting or paging.
  var isNarrow = (typeof window.matchMedia === 'function')
    ? window.matchMedia('(max-width: 767.98px)').matches
    : (window.innerWidth || 1200) < 768;
  jQuery('table.data-table:not(.data-table-plain)').each(function () {
    var $t = jQuery(this);
    if (jQuery.fn.DataTable.isDataTable(this)) { return; }

    // A lone "nothing here yet" row whose cell spans every column makes DataTables 2 spin
    // forever while it tries to map cells to columns (the whole main thread locks up).
    // Drop those rows first — DataTables' own "No records yet" message covers the state.
    var colCount = $t.find('thead th').length;
    if (colCount > 0) {
      $t.find('tbody tr').each(function () {
        var cells = this.children;
        if (cells.length === 1 && parseInt(cells[0].getAttribute('colspan') || '1', 10) >= colCount) {
          jQuery(this).remove();
        }
      });
    }

    var noSort = [];
    $t.find('thead th').each(function (i) {
      var label = jQuery.trim(jQuery(this).text()).toLowerCase();
      // the row action buttons and empty spacer columns never need sorting
      if (label === '' || label === 'actions' || label === 'action' || jQuery(this).is('.no-sort')) { noSort.push(i); }
    });
    // Is there already a GET search form on this page? (CRUD screens and several bespoke
    // screens ship their own server-side search — do not stack a second one on top.)
    var serverSearch = !!document.querySelector('form[method="get"] input[name="q"]');
    try {
      $t.DataTable({
        order: [],
        pageLength: isNarrow ? 10 : 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        columnDefs: noSort.map(function (i) { return { orderable: false, targets: i }; }),
        // The CRUD screens already ship a server-side search above the table; hide DataTables'
        // own box there so there are not two search inputs. Everywhere else it is the only one.
        searching: !serverSearch,
        autoWidth: false,
        stateSave: false,
        language: {
          search: '',
          searchPlaceholder: 'Search this table…',
          lengthMenu: '_MENU_ per page',
          info: 'Showing _START_–_END_ of _TOTAL_',
          infoEmpty: 'No records',
          infoFiltered: '(filtered from _MAX_)',
          zeroRecords: 'No matching records found',
          emptyTable: 'No records yet',
          paginate: { previous: '‹', next: '›' }
        }
      });
      // The PHP pager under the table is a no-JS fallback; once DataTables owns paging a
      // second "showing x of y" line is just noise.
      $t.closest('.panel, .card').find('.server-pager').hide();
    } catch (err) {
      // DataTables takes the table over as it initialises, so a throw half way through can
      // leave the server-rendered rows on the page but out of sight — the page then reads
      // "9 rule version(s)" with an empty list underneath. The rows are the real content, so
      // put the plain table back and keep the PHP pager; sorting and search are the only loss.
      try { $t.css({ display: '', visibility: '' }).show(); } catch (e2) {}
      if (window.console && window.console.error) {
        window.console.error('DataTables could not initialise this table:', err);
      }
    }
  });
})();
</script>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
