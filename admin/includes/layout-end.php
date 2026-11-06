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
<script src="<?= e(\Saee\Lib\Url::asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(\Saee\Lib\Url::asset('js/app.js')) ?>"></script>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
