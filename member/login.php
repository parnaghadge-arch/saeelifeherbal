<?php
/** Partner (MLM member) login. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Url;

if (MemberAuth::check()) {
    Request::redirect('dashboard.php');
}
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $identifier = (string) Request::input('identifier', '');
    $password = (string) Request::raw('password', '');
    if ($identifier === '' || $password === '') {
        $errors['identifier'] = ['Enter your member ID / mobile number and password.'];
    } else {
        $result = MemberAuth::attempt($identifier, $password, Request::bool('remember'));
        if ($result['ok']) {
            Session::success($result['message']);
            $redirect = Session::get('member_redirect', 'dashboard.php');
            Session::remove('member_redirect');
            Request::redirect($redirect);
        }
        $errors['identifier'] = [$result['message']];
    }
}
$pageTitle = 'Partner Login';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card">
      <div class="text-center mb-3">
        <img src="<?= e(Url::media((string) Settings::get('logo_header'))) ?>" alt="<?= e(Settings::get('site_name')) ?>" height="48">
        <h1 class="h4 mt-3 mb-1">Partner sign in</h1>
        <p class="small text-muted">Access your wallet, team and payouts.</p>
      </div>
      <?= render_flash() ?>
      <?php if (!empty($errors['identifier'])): ?><div class="alert alert-danger small"><?= e($errors['identifier'][0]) ?></div><?php endif; ?>
      <form method="post" action="<?= e(Url::member('login.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Member ID, mobile or email</label>
          <input id="identifier" name="identifier" class="form-control" value="<?= old_input('identifier') ?>" required autocomplete="username" autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
            <label class="form-check-label small" for="remember">Keep me signed in</label>
          </div>
          <a class="small" href="<?= e(Url::member('forgot-password.php')) ?>">Forgot password?</a>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
      </form>
      <hr class="my-3">
      <p class="small text-center mb-0">New partner? <a href="<?= e(Url::member('register.php')) ?>">Register free</a></p>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
