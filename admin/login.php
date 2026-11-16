<?php
/** Admin login (public). */
declare(strict_types=1);

$adminPublic = true;
require __DIR__ . '/includes/_guard.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Url;

if (AdminAuth::check()) {
    Request::redirect('index.php');
}
$error = '';
if (is_post()) {
    Security::verifyCsrf();
    [$allowed, , $retryAfter] = Security::throttleStatus('admin_login', Request::ip());
    if (!$allowed) {
        $error = 'Too many failed attempts. Please try again in ' . ceil($retryAfter / 60) . ' minute(s).';
    } else {
        $result = AdminAuth::attempt((string) Request::input('identifier', ''), (string) Request::raw('password', ''), Request::bool('remember'));
        if ($result['ok']) {
            Security::clearFailures('admin_login', Request::ip());
            Session::success($result['message']);
            Request::redirect('index.php');
        }
        Security::recordFailure('admin_login', Request::ip());
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Admin Sign In | <?= e((string) Settings::get('site_name', APP_NAME)) ?></title>
<link rel="stylesheet" href="<?= e(Url::asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('vendor/fontawesome/css/all.min.css')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body class="admin-login-body">
<div class="admin-login-card">
  <div class="text-center mb-3">
    <img src="<?= e(Url::media((string) Settings::get('logo_admin', 'assets/images/logo.png'))) ?>" alt="Logo" height="52">
  </div>
  <h1 class="h5 text-center mb-1">Administrator sign in</h1>
  <p class="small text-muted text-center mb-3"><?= e((string) Settings::get('site_name', APP_NAME)) ?> control panel</p>
  <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="identifier">Username or email</label>
      <input id="identifier" name="identifier" class="form-control" required autofocus autocomplete="username">
    </div>
    <div class="mb-3">
      <label class="form-label" for="password">Password</label>
      <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
      <label class="form-check-label small" for="remember">Keep me signed in on this device</label>
    </div>
    <button class="btn btn-primary w-100" type="submit">Sign in securely</button>
  </form>
  <p class="small text-muted text-center mt-3 mb-0"><a href="<?= e(Url::to('')) ?>">← Back to website</a></p>
</div>
</body>
</html>
