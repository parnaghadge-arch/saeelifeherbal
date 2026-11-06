<?php
/**
 * Settings manager — grouped, typed, with image uploads for branding.
 * All public content is DB driven, so nothing here is hardcoded in the front end.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('settings.manage');

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
$groups = [
    'general' => ['label' => 'General', 'icon' => 'fa-gear'],
    'branding' => ['label' => 'Logos & Branding', 'icon' => 'fa-palette'],
    'header' => ['label' => 'Header', 'icon' => 'fa-window-maximize'],
    'footer' => ['label' => 'Footer', 'icon' => 'fa-window-restore'],
    'seo' => ['label' => 'SEO', 'icon' => 'fa-magnifying-glass'],
    'commerce' => ['label' => 'Commerce', 'icon' => 'fa-cart-shopping'],
    'payments' => ['label' => 'Payment Gateways', 'icon' => 'fa-credit-card'],
    'mlm' => ['label' => 'MLM', 'icon' => 'fa-sitemap'],
    'notifications' => ['label' => 'Notifications', 'icon' => 'fa-bell'],
    'pwa' => ['label' => 'PWA', 'icon' => 'fa-mobile-screen'],
    'security' => ['label' => 'Security', 'icon' => 'fa-shield-halved'],
];
$active = (string) Request::query('group', 'general');
if (!isset($groups[$active])) { $active = 'general'; }

if (is_post()) {
    Security::verifyCsrf();
    $group = (string) Request::input('group', 'general');
    $rows = $db->select('SELECT * FROM settings WHERE setting_group = :g', ['g' => $group]);
    $saved = 0;
    $imageKeys = ['logo_header', 'logo_footer', 'logo_admin', 'logo_pwa', 'favicon', 'og_default_image'];
    foreach ($rows as $row) {
        $key = (string) $row['setting_key'];
        $type = (string) $row['setting_type'];
        if (!array_key_exists($key, $_POST) && !isset($_FILES[$key])) {
            continue;
        }
        $isImageSetting = in_array($type, ['image', 'file'], true) || in_array($key, $imageKeys, true);
        if ($isImageSetting) {
            $fileError = $_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($fileError === UPLOAD_ERR_NO_FILE) {
                // Nothing chosen in the picker: keep the logo/favicon already stored instead of
                // overwriting it with an empty path (file inputs never post a value).
                continue;
            }
            if ($fileError !== UPLOAD_ERR_OK) {
                Session::error($key . ': the image could not be uploaded (error code ' . (int) $fileError . '). The previous file was kept.');
                continue;
            }
            $up = Uploader::store($_FILES[$key], 'branding');
            if ($up['ok']) {
                Settings::set($key, $up['path'], $type);
                $saved++;
            } else {
                Session::error($key . ': ' . $up['message'] . ' The previous file was kept.');
            }
            continue;
        }
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $value = $_POST[$key];
        if ($type === 'bool' || $type === 'boolean') {
            $value = $value === '1' ? '1' : '0';
        }
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        Settings::set($key, (string) $value, $type);
        $saved++;
    }
    Audit::log('update', 'settings', 'Updated ' . $saved . ' setting(s) in group ' . $group, 'settings');
    Settings::flush();
    if ($saved > 0) {
        Session::success($saved . ' setting(s) updated successfully.');
    }
    Request::redirect('settings.php?group=' . $group);
}

$items = $db->select('SELECT * FROM settings WHERE setting_group = :g ORDER BY setting_key ASC', ['g' => $active]);

$pageTitle = 'Settings';
$pageHeading = 'Website Settings';
$pageSub = 'Configuration for ' . $groups[$active]['label'];
$activeModule = 'settings';
require __DIR__ . '/includes/layout.php';
?>
<div class="filter-bar mb-3 flex-wrap">
  <?php foreach ($groups as $key => $meta): ?>
    <a class="btn btn-sm <?= $active === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?group=<?= e($key) ?>">
      <i class="fa-solid <?= e($meta['icon']) ?> me-1"></i><?= e($meta['label']) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="panel p-3 p-md-4">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="group" value="<?= e($active) ?>">
    <div class="row g-3">
      <?php foreach ($items as $item):
          $key = (string) $item['setting_key'];
          $value = (string) ($item['setting_value'] ?? '');
          $type = (string) $item['setting_type'];
          $label = ucwords(str_replace(['_', '-'], ' ', preg_replace('/^(site|theme|contact|social|pwa|seo|mail|smtp)_/', '', $key) ?? $key));
          $isImage = in_array($type, ['image', 'file'], true) || in_array($key, ['logo_header', 'logo_footer', 'logo_admin', 'logo_pwa', 'favicon', 'og_default_image'], true);
          ?>
        <div class="col-md-6">
          <label class="form-label small" for="s_<?= e($key) ?>"><?= e($label) ?> <span class="text-muted">(<?= e($key) ?>)</span></label>
          <?php if ($isImage): ?>
            <?php if ($value !== ''): ?><div class="mb-1"><img class="crud-thumb" src="<?= e(Url::media($value)) ?>" alt=""></div><?php endif; ?>
            <input type="file" class="form-control form-control-sm" id="s_<?= e($key) ?>" name="<?= e($key) ?>" accept="image/*">
          <?php elseif ($type === 'bool' || $type === 'boolean'): ?>
            <select class="form-select form-select-sm" id="s_<?= e($key) ?>" name="<?= e($key) ?>">
              <option value="1" <?= $value === '1' ? 'selected' : '' ?>>Enabled</option>
              <option value="0" <?= $value === '0' ? 'selected' : '' ?>>Disabled</option>
            </select>
          <?php elseif (mb_strlen($value) > 90 || str_contains($key, 'description') || str_contains($key, 'address') || str_contains($key, 'terms')): ?>
            <textarea class="form-control form-control-sm" id="s_<?= e($key) ?>" name="<?= e($key) ?>" rows="3"><?= e($value) ?></textarea>
          <?php elseif ($type === 'password' || str_contains($key, 'secret') || str_contains($key, 'key_id')): ?>
            <input type="password" class="form-control form-control-sm" id="s_<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>" autocomplete="new-password">
          <?php else: ?>
            <input type="text" class="form-control form-control-sm" id="s_<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$items): ?><p class="small text-muted">No settings in this group.</p><?php endif; ?>
    </div>
    <button class="btn btn-primary mt-4">Save <?= e($groups[$active]['label']) ?></button>
  </form>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
