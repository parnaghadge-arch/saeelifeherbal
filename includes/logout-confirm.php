<?php
/**
 * Standalone "are you sure?" page shown before a session is destroyed.
 *
 * The panels guard Logout with a modal, but a modal cannot help someone who follows a stale
 * link, a bookmark, or a redirect straight to logout.php. This page is that second line: the
 * logout script only destroys the session when it is explicitly told to, so an unconfirmed
 * arrival lands here instead.
 *
 * Expects, from the including script:
 *   $confirmTitle    string  Heading shown to the user.
 *   $confirmMessage  string  What they are about to lose.
 *   $confirmHref     string  URL that performs the logout (must carry the confirm flag).
 *   $cancelHref      string  Where "Keep me signed in" returns to.
 */

declare(strict_types=1);

use Saee\Lib\Url;

$confirmTitle = (string) ($confirmTitle ?? 'Sign out?');
$confirmMessage = (string) ($confirmMessage ?? 'You will need your password to get back in.');
$confirmHref = (string) ($confirmHref ?? '#');
$cancelHref = (string) ($cancelHref ?? Url::admin('index.php'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($confirmTitle) ?></title>
  <link rel="stylesheet" href="<?= e(Url::asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(Url::asset('vendor/fontawesome/css/all.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(Url::asset('css/style.css')) ?>">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;background:#F4F8F4">
  <div class="panel p-4 text-center" style="max-width:420px;width:100%">
    <div class="mb-3">
      <i class="fa-solid fa-right-from-bracket fa-2x text-danger" aria-hidden="true"></i>
    </div>
    <h1 class="h5 mb-2"><?= e($confirmTitle) ?></h1>
    <p class="small text-muted mb-4"><?= e($confirmMessage) ?></p>
    <div class="d-grid gap-2">
      <a class="btn btn-danger" href="<?= e($confirmHref) ?>">
        <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>Yes, sign me out
      </a>
      <a class="btn btn-light" href="<?= e($cancelHref) ?>">Keep me signed in</a>
    </div>
  </div>
</body>
</html>
