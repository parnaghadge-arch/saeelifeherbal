    </div>
  </div>
</div>

<nav class="mobile-bottom-nav" aria-label="Member quick navigation">
  <?php foreach ($bottomNav as [$key, $label, $icon, $href]): ?>
    <?php $isActive = $activeNav === $key || ($key === 'payouts' && $activeNav === 'payout-request'); ?>
    <a href="<?= e(\Saee\Lib\Url::member($href)) ?>" class="<?= $isActive ? 'active' : '' ?>">
      <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<script>
window.SAEE = {
  base: <?= json_encode(\Saee\Lib\Url::base()) ?>,
  csrf: <?= json_encode(\Saee\Lib\Security::csrfToken()) ?>,
  routes: {}
};
document.getElementById('sidebarToggle')?.addEventListener('click', function () {
  document.getElementById('appSidebar').classList.add('open');
  document.getElementById('sidebarBackdrop').classList.add('show');
});
document.getElementById('sidebarBackdrop')?.addEventListener('click', function () {
  document.getElementById('appSidebar').classList.remove('open');
  this.classList.remove('show');
});
</script>
<?php require dirname(__DIR__, 2) . '/includes/invoice-modal.php'; ?>
<script src="<?= e(\Saee\Lib\Url::asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(\Saee\Lib\Url::asset('js/app.js')) ?>" defer></script>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
