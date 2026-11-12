<?php
/**
 * Partner registration with real-time sponsor validation.
 * Stores ?ref= referral codes in session so the sponsor is pre-filled.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\MemberService;
use Saee\Lib\MlmSettings;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Url;
use Saee\Lib\Validator;

if (MemberAuth::check()) {
    Request::redirect('dashboard.php');
}

$memberService = new MemberService(db());
$ref = trim((string) Request::query('ref', ''));
$prefillSponsor = '';
if ($ref !== '') {
    Session::set('referral_code', $ref);
    $sponsor = db()->first('SELECT member_code FROM members WHERE referral_code = :r', ['r' => $ref]);
    if ($sponsor) {
        $prefillSponsor = $sponsor['member_code'];
        // record the click for referral analytics
        try {
            db()->insert('member_referral_clicks', [
                'member_id' => (int) db()->value('SELECT id FROM members WHERE referral_code = :r', ['r' => $ref]),
                'referral_code' => $ref,
                'ip_address' => client_ip(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
        }
    }
}
$companySponsor = $memberService->companySponsor();
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
    $v->label('mobile', 'Mobile number')->required('mobile')->phone('mobile', true);
    $v->label('email', 'Email')->email('email');
    $v->label('password', 'Password')->required('password')->password('password');
    $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
    $v->label('state', 'State')->required('state');
    $v->label('district', 'District')->required('district');
    $v->label('taluka', 'Taluka')->required('taluka');
    $v->label('village', 'Village / Town')->required('village');
    $v->label('pincode', 'PIN code')->required('pincode')->pincode('pincode', true);
    $v->label('address', 'Address')->required('address');
    $v->label('terms', 'the terms and conditions')->accepted('terms');
    $v->label('sponsor_code', 'Sponsor ID')->max('sponsor_code', 20);

    if ($v->passes()) {
        $result = $memberService->register([
            'name' => $v->value('name'),
            'mobile' => $v->value('mobile'),
            'email' => $v->value('email'),
            'password' => (string) Request::raw('password'),
            'role' => (string) Request::input('role', 'farmer'),
            'sponsor_code' => (string) Request::input('sponsor_code', ''),
            'address' => $v->value('address'),
            'state' => $v->value('state'),
            'district' => $v->value('district'),
            'taluka' => $v->value('taluka'),
            'village' => $v->value('village'),
            'pincode' => $v->value('pincode'),
            'date_of_birth' => (string) Request::input('date_of_birth', ''),
            'gender' => (string) Request::input('gender', ''),
        ], ['ip' => client_ip(), 'source' => $ref !== '' ? 'referral' : 'direct']);

        if ($result['ok']) {
            // Sign the member in, then keep them on this page for one render so the credentials
            // card can be shown. The password is not recoverable afterwards, so redirecting
            // straight to the dashboard would lose it for good. The card offers a continue link.
            MemberAuth::attempt((string) $v->value('mobile'), (string) Request::raw('password'));
            $welcome = (array) ($result['welcome'] ?? []);
            $welcomeContext = 'self';
            $registeredCode = (string) ($result['member']['member_code'] ?? '');
            Session::success('Welcome aboard! Your member ID is ' . $registeredCode . '.');
        } else {
            $errors['general'] = [$result['message']];
            foreach ($result['errors'] ?? [] as $field => $messages) {
                $errors[$field] = $messages;
            }
        }
    } else {
        $errors = $v->errors();
    }
    Session::set('_old_input', array_map(static fn ($v) => is_string($v) ? $v : '', $_POST));
}

$pageTitle = 'Partner Registration';
$pageDescription = 'Register free as a Saee Life Herbal partner and start earning product-sale based commissions.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Register as a Partner', 'Free registration. No joining fee. No recruitment-only income.', ['Partner Registration' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="panel p-4">
          <?php if (!empty($welcome)): ?>
            <div class="text-center py-4">
              <div class="welcome-tick mb-3"><i class="fa-solid fa-check"></i></div>
              <h2 class="h5 mb-2">You are registered</h2>
              <p class="text-muted small mb-3">
                Your partner ID is <strong><?= e((string) ($welcome['member_code'] ?? '')) ?></strong>.
                Your login details are shown in the window &mdash; save them before continuing.
              </p>
              <div class="d-flex flex-wrap gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('memberWelcomeCard')).show()">
                  <i class="fa-solid fa-id-card me-1"></i>Show my details again
                </button>
                <a class="btn btn-primary" href="<?= e(Url::member('dashboard.php')) ?>">
                  Go to dashboard <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
              </div>
            </div>
          <?php elseif ((int) MlmSettings::get('registration_enabled', 1) !== 1): ?>
            <div class="alert alert-warning mb-0">Registrations are temporarily paused. Please check back soon.</div>
          <?php else: ?>
          <?php if (!empty($errors['general'])): ?><div class="alert alert-danger"><?= e($errors['general'][0]) ?></div><?php endif; ?>
          <form method="post" action="<?= e(Url::member('register.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <h2 class="h6 text-uppercase text-muted mb-3" style="letter-spacing:.1em">1 · Personal details</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="name">Full name <span class="required-mark">*</span></label>
                <input id="name" name="name" class="form-control<?= invalid_class($errors, 'name') ?>" value="<?= old_input('name') ?>" required>
                <?= field_error($errors, 'name') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="mobile">Mobile number <span class="required-mark">*</span></label>
                <input id="mobile" name="mobile" type="tel" inputmode="numeric" class="form-control<?= invalid_class($errors, 'mobile') ?>" value="<?= old_input('mobile') ?>" required>
                <?= field_error($errors, 'mobile') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email address</label>
                <input id="email" name="email" type="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email') ?>">
                <?= field_error($errors, 'email') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="role">I am registering as</label>
                <select id="role" name="role" class="form-select">
                  <?php foreach (['farmer' => 'Farmer', 'distributor' => 'Distributor', 'retailer' => 'Retailer'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= old_input('role', 'farmer') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="date_of_birth">Date of birth</label>
                <input id="date_of_birth" name="date_of_birth" type="date" class="form-control" value="<?= old_input('date_of_birth') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="gender">Gender</label>
                <select id="gender" name="gender" class="form-select">
                  <option value="">Prefer not to say</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                </select>
              </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.1em">2 · Address</h2>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label" for="address">Address <span class="required-mark">*</span></label>
                <input id="address" name="address" class="form-control<?= invalid_class($errors, 'address') ?>" value="<?= old_input('address') ?>" required>
                <?= field_error($errors, 'address') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="state">State <span class="required-mark">*</span></label>
                <input id="state" name="state" class="form-control<?= invalid_class($errors, 'state') ?>" value="<?= old_input('state', 'Maharashtra') ?>" required>
                <?= field_error($errors, 'state') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="district">District <span class="required-mark">*</span></label>
                <input id="district" name="district" class="form-control<?= invalid_class($errors, 'district') ?>" value="<?= old_input('district') ?>" required>
                <?= field_error($errors, 'district') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="taluka">Taluka <span class="required-mark">*</span></label>
                <input id="taluka" name="taluka" class="form-control<?= invalid_class($errors, 'taluka') ?>" value="<?= old_input('taluka') ?>" required>
                <?= field_error($errors, 'taluka') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="village">Village / Town <span class="required-mark">*</span></label>
                <input id="village" name="village" class="form-control<?= invalid_class($errors, 'village') ?>" value="<?= old_input('village') ?>" required>
                <?= field_error($errors, 'village') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pincode">PIN code <span class="required-mark">*</span></label>
                <input id="pincode" name="pincode" inputmode="numeric" class="form-control<?= invalid_class($errors, 'pincode') ?>" value="<?= old_input('pincode') ?>" required>
                <?= field_error($errors, 'pincode') ?>
              </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.1em">3 · Sponsor</h2>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label" for="sponsor_code">Sponsor member ID</label>
                <input id="sponsor_code" name="sponsor_code" class="form-control<?= invalid_class($errors, 'sponsor_code') ?>" value="<?= old_input('sponsor_code', $prefillSponsor) ?>" data-sponsor-input autocomplete="off">
                <div class="form-text" data-sponsor-feedback>
                  Leave blank to join under the company sponsor<?= $companySponsor ? ' (' . e($companySponsor['member_code']) . ')' : '' ?>.
                </div>
                <?= field_error($errors, 'sponsor_code') ?>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="small text-muted">Your sponsor relationship cannot be changed after registration except by an authorised admin correction.</div>
              </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.1em">4 · Security</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="password">Password <span class="required-mark">*</span></label>
                <input id="password" name="password" type="password" class="form-control<?= invalid_class($errors, 'password') ?>" required autocomplete="new-password">
                <div class="form-text">Minimum <?= PASSWORD_MIN_LENGTH ?> characters with a letter and a number.</div>
                <?= field_error($errors, 'password') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="password_confirm">Confirm password <span class="required-mark">*</span></label>
                <input id="password_confirm" name="password_confirm" type="password" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" required autocomplete="new-password">
                <?= field_error($errors, 'password_confirm') ?>
              </div>
            </div>

            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" name="terms" id="terms" value="1" <?= old_input('terms') ? 'checked' : '' ?>>
              <label class="form-check-label small" for="terms">
                I have read and accept the <a href="<?= e(Url::to('terms-conditions')) ?>" target="_blank">Terms &amp; Conditions</a>,
                <a href="<?= e(Url::to('disclaimer')) ?>" target="_blank">Disclaimer</a> and
                <a href="<?= e(Url::to('privacy-policy')) ?>" target="_blank">Privacy Policy</a>. I understand that income depends on genuine product sales and is not guaranteed.
              </label>
              <?= field_error($errors, 'terms') ?>
            </div>
            <button type="submit" class="btn btn-primary btn-lg mt-3 w-100">Create my free account</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="panel p-4 mb-3">
          <h2 class="h6 mb-3">Why register?</h2>
          <ul class="list-check small mb-0">
            <li>Unique member ID and referral link</li>
            <li>Unlimited direct referrals</li>
            <li>Product-wise direct &amp; grand-direct commissions</li>
            <li>Live wallet with full ledger</li>
            <li>Royalty milestones</li>
          </ul>
        </div>
        <div class="alert alert-warning small mb-0">
          <strong>Please note:</strong> there is no joining fee, no deposit and no assured income. Commission is paid only on genuine paid product orders.
        </div>
      </div>
    </div>
  </div>
</section>
<?php if (!empty($welcome)): ?>
  <?php require INCLUDES_PATH . '/member-welcome-card.php'; ?>
  <style>
    .welcome-tick{width:64px;height:64px;border-radius:50%;background:#E8F5E9;color:#14532D;display:inline-flex;align-items:center;justify-content:center;font-size:26px}
  </style>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
