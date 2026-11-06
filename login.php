<?php
/** Customer login. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

if (CustomerAuth::check()) {
    Request::redirect('my-account.php');
}
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $identifier = (string) Request::input('identifier', '');
    $password = (string) Request::raw('password', '');
    if ($identifier === '' || $password === '') {
        $errors['identifier'] = ['Please enter your mobile/email and password.'];
    } else {
        $result = CustomerAuth::attempt($identifier, $password, Request::bool('remember'));
        if ($result['ok']) {
            Session::success($result['message']);
            $redirect = Session::get('customer_redirect', 'my-account.php');
            Session::remove('customer_redirect');
            Request::redirect($redirect);
        }
        $errors['identifier'] = [$result['message']];
    }
}
$pageTitle = 'Customer Login';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card">
      <div class="text-center mb-3">
        <img src="<?= e(Url::media(setting('logo_header'))) ?>" alt="<?= e(setting('site_name')) ?>" height="48">
        <h1 class="h4 mt-3 mb-1">Customer sign in</h1>
        <p class="small text-muted">Track orders, reorder faster and manage your addresses.</p>
      </div>
      <?= render_flash() ?>
      <?php if (!empty($errors['identifier'])): ?><div class="alert alert-danger"><?= e($errors['identifier'][0]) ?></div><?php endif; ?>
      <form method="post" action="<?= e(Url::to('login.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Mobile number or email</label>
          <input id="identifier" name="identifier" class="form-control" value="<?= old_input('identifier') ?>" required autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
            <label class="form-check-label small" for="remember">Remember me</label>
          </div>
          <a class="small" href="<?= e(Url::to('forgot-password.php')) ?>">Forgot password?</a>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
      </form>
      <hr class="my-3">
      <p class="small text-center mb-2">New here? <a href="<?= e(Url::to('register.php')) ?>">Create an account</a></p>
      <p class="small text-center mb-0 text-muted">Partner? <a href="<?= e(Url::member('login')) ?>">Sign in to the partner portal</a></p>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
