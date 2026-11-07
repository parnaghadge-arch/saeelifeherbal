    </div>
  </div>
</div>
<script>
document.getElementById('sidebarToggle')?.addEventListener('click', function () {
  document.getElementById('appSidebar').classList.add('open');
  document.getElementById('sidebarBackdrop').classList.add('show');
});
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
  var isNarrow = window.matchMedia('(max-width: 767.98px)').matches;
  jQuery('table.data-table:not(.data-table-plain)').each(function () {
    var $t = jQuery(this);
    if (jQuery.fn.DataTable.isDataTable(this)) { return; }
    var noSort = [];
    $t.find('thead th').each(function (i) {
      var label = jQuery.trim(jQuery(this).text()).toLowerCase();
      // the row action buttons and empty spacer columns never need sorting
      if (label === '' || label === 'actions' || label === 'action' || jQuery(this).is('.no-sort')) { noSort.push(i); }
    });
    // Is there already a GET search form on this page? (CRUD screens and several bespoke
    // screens ship their own server-side search — do not stack a second one on top.)
    var serverSearch = !!document.querySelector('form[method="get"] input[name="q"]');
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
  });
})();
</script>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
