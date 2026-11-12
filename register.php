<?php
/** Customer registration. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

if (CustomerAuth::check()) {
    Request::redirect('my-account.php');
}
$errors = [];
if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
    $v->label('mobile', 'Mobile number')->required('mobile')->phone('mobile', true);
    $v->label('email', 'Email')->email('email');
    $v->label('password', 'Password')->required('password')->password('password');
    $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
    $v->label('terms', 'the terms and conditions')->accepted('terms');

    $mobile = preg_replace('/\D/', '', (string) $v->value('mobile'));
    $mobile = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
    if (db()->exists('customers', 'mobile = :m', ['m' => $mobile])) {
        $v->addError('mobile', 'This mobile number is already registered. Please sign in.');
    }
    $email = strtolower((string) $v->value('email'));
    if ($email !== '' && db()->exists('customers', 'LOWER(email) = :e', ['e' => $email])) {
        $v->addError('email', 'This email is already registered.');
    }

    if ($v->passes()) {
        $referral = Session::get('referral_code');
        $referredBy = null;
        if ($referral) {
            $referredBy = db()->value('SELECT id FROM members WHERE referral_code = :r', ['r' => $referral]);
        }
        $customerId = db()->insert('customers', [
            'name' => $v->value('name'),
            'email' => $email ?: null,
            'mobile' => $mobile,
            'password_hash' => Security::passwordHash((string) Request::raw('password')),
            'referred_by_member_id' => $referredBy ? (int) $referredBy : null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $customer = db()->first('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
        CustomerAuth::loginSession($customer);
        Session::success('Your account has been created. Welcome to Saee Life Herbal!');
        Request::redirect('my-account.php');
    }
    $errors = $v->errors();
    Session::set('_old_input', array_map(static fn ($v) => is_string($v) ? $v : '', $_POST));
}
$pageTitle = 'Create Account';
$pageRobots = 'noindex,follow';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-wrap">
  <div class="container">
    <div class="auth-card" style="max-width:560px">
      <div class="text-center mb-3">
        <img src="<?= e(Url::media(setting('logo_header'))) ?>" alt="<?= e(setting('site_name')) ?>" height="46">
        <h1 class="h4 mt-3 mb-1">Create your account</h1>
        <p class="small text-muted">Shop crop care products and track every order.</p>
      </div>
      <?php if ($errors !== []): ?><div class="alert alert-danger small">Please correct the highlighted fields and try again.</div><?php endif; ?>
      <form method="post" action="<?= e(Url::to('register.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="name">Full name</label>
          <input id="name" name="name" class="form-control<?= invalid_class($errors, 'name') ?>" value="<?= old_input('name') ?>" required>
          <?= field_error($errors, 'name') ?>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="mobile">Mobile number</label>
            <input id="mobile" name="mobile" type="tel" class="form-control<?= invalid_class($errors, 'mobile') ?>" value="<?= old_input('mobile') ?>" required>
            <?= field_error($errors, 'mobile') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email') ?>">
            <?= field_error($errors, 'email') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password">Password</label>
            <input id="password" name="password" type="password" class="form-control<?= invalid_class($errors, 'password') ?>" required autocomplete="new-password">
            <div class="form-text">At least <?= PASSWORD_MIN_LENGTH ?> characters with a letter and a number.</div>
            <?= field_error($errors, 'password') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password_confirm">Confirm password</label>
            <input id="password_confirm" name="password_confirm" type="password" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" required autocomplete="new-password">
            <?= field_error($errors, 'password_confirm') ?>
          </div>
        </div>
        <div class="form-check my-3">
          <input class="form-check-input" type="checkbox" name="terms" id="terms" value="1" <?= old_input('terms') ? 'checked' : '' ?>>
          <label class="form-check-label small" for="terms">I agree to the <a href="<?= e(Url::to('terms-conditions')) ?>" target="_blank">Terms &amp; Conditions</a> and <a href="<?= e(Url::to('privacy-policy')) ?>" target="_blank">Privacy Policy</a>.</label>
          <?= field_error($errors, 'terms') ?>
        </div>
        <button class="btn btn-primary w-100" type="submit">Create account</button>
      </form>
      <p class="small text-center mt-3 mb-0">Already have an account? <a href="<?= e(Url::to('login.php')) ?>">Sign in</a></p>
      <p class="small text-center mt-2 mb-0 text-muted">Want to earn commissions? <a href="<?= e(Url::member('register')) ?>">Register as a partner</a></p>
    </div>
  </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
