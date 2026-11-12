<?php
/** Offline fallback page cached by the service worker. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Settings;
use Saee\Lib\Url;

$pageTitle = 'You are offline';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<section class="section">
  <div class="container text-center" style="max-width:560px">
    <div class="feature-icon mx-auto mb-3" style="width:74px;height:74px;font-size:1.6rem"><i class="fa-solid fa-wifi"></i></div>
    <h1 class="h3">You appear to be offline</h1>
    <p class="text-muted">Your connection dropped. Pages you already visited may still be available. Reconnect and try again.</p>
    <button class="btn btn-primary" onclick="location.reload()"><i class="fa-solid fa-rotate-right me-2"></i>Retry</button>
    <a href="<?= e(Url::to('')) ?>" class="btn btn-outline-primary">Home</a>
    <p class="small text-muted mt-4 mb-0">Support: <?= e(Settings::get('contact_phone')) ?></p>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
