<?php
/** Checkout: address capture, payment method selection and order placement. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Cart;
use Saee\Lib\CustomerAuth;
use Saee\Lib\MemberAuth;
use Saee\Lib\MemberService;
use Saee\Lib\MlmSettings;
use Saee\Lib\OrderService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$cartService = new Cart(db());
$totals = $cartService->totals();
if ($totals['items'] === []) {
    \Saee\Lib\Session::flash('warning', 'Your cart is empty.');
    Request::redirect('cart.php');
}

$orderService = new OrderService(db());
$gateways = $orderService->enabledGateways();
$customer = CustomerAuth::user();
$isGuest = $customer === null;

// A just-registered partner is redirected back here (?registered=1) to review and pay. The
// credentials card payload is stashed in the session and shown exactly once - the generated
// password is not recoverable afterwards.
$welcome = [];
if (Request::query('registered') !== null) {
    $welcome = (array) (Session::get('checkout_welcome') ?? []);
    Session::remove('checkout_welcome');
}

$defaultAddress = null;
$memberDefaults = null;
if ($customer) {
    $defaultAddress = db()->first('SELECT * FROM customer_addresses WHERE customer_id = :c ORDER BY is_default DESC, id DESC LIMIT 1', ['c' => $customer['id']]);
    if (!empty($customer['member_id'])) {
        // Partners review their registered address as the delivery address.
        $memberDefaults = db()->first(
            'SELECT address, state, district, taluka, village, pincode
               FROM member_profiles WHERE member_id = :m',
            ['m' => (int) $customer['member_id']]
        );
    }
}

$errors = [];
$authMode = in_array((string) Request::query('mode', ''), ['login', 'register'], true)
    ? (string) Request::query('mode')
    : 'choose';
$memberService = new MemberService(db());
$companySponsor = $memberService->companySponsor();
$registrationOpen = (int) MlmSettings::get('registration_enabled', 1) === 1;
// Sponsor pre-fill: mirror member/register.php - a ?ref= link wins and is remembered in the
// session, so the referral is attributed to this member later as well.
$ref = trim((string) Request::query('ref', ''));
if ($ref !== '') {
    Session::set('referral_code', $ref);
}
$prefillSponsor = '';
$referralCode = $ref !== '' ? $ref : trim((string) (Session::get('referral_code') ?? ''));
if ($referralCode !== '') {
    $sponsoredBy = db()->first('SELECT member_code FROM members WHERE referral_code = :r', ['r' => $referralCode]);
    if ($sponsoredBy) {
        $prefillSponsor = (string) $sponsoredBy['member_code'];
    }
}

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) (Request::input('action') ?? '');

    if ($isGuest) {
        // ---- guests must sign in or register before the order form is available --------
        if ($action === 'login') {
            $authMode = 'login';
            $identifier = (string) Request::input('identifier', '');
            $password = (string) Request::raw('password', '');
            if ($identifier === '' || $password === '') {
                $errors['identifier'] = ['Please enter your mobile, email or Member ID and password.'];
            } else {
                // Customer credentials AND partner credentials are both accepted here.
                $result = CustomerAuth::attemptWithPartner($identifier, $password, Request::bool('remember'));
                if ($result['ok']) {
                    Session::success($result['message']);
                    Request::redirect('checkout.php');
                }
                $errors['identifier'] = [$result['message']];
            }
            Session::set('_old_input', ['identifier' => $identifier]);
        } elseif ($action === 'register') {
            $authMode = 'register';
            if (!$registrationOpen) {
                $errors['general'] = ['Registration is temporarily disabled. Please check back soon.'];
            } else {
                $v = new Validator($_POST);
                $v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
                $v->label('mobile', 'Mobile number')->required('mobile')->phone('mobile', true);
                $v->label('email', 'Email')->email('email');
                $v->label('state', 'State')->required('state');
                $v->label('district', 'District')->required('district');
                $v->label('taluka', 'Taluka')->required('taluka');
                $v->label('village', 'Village / Town')->required('village');
                $v->label('pincode', 'PIN code')->required('pincode')->pincode('pincode', true);
                $v->label('address', 'Address')->required('address');
                $v->label('terms', 'the terms and conditions')->accepted('terms');
                $v->label('sponsor_code', 'Sponsor ID')->max('sponsor_code', 20);

                if ($v->passes()) {
                    // The same partner registration as member/register.php: one member account
                    // with an auto-linked customer record, so both logins work from now on.
                    $result = $memberService->register([
                        'name' => $v->value('name'),
                        'mobile' => $v->value('mobile'),
                        'email' => $v->value('email'),
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
                    ], ['ip' => client_ip(), 'source' => 'checkout']);

                    if ($result['ok']) {
                        $welcome = (array) ($result['welcome'] ?? []);
                        // Sign in as partner AND customer so the order form below is unlocked
                        // and the order is attributed to the new member - the member flow.
                        MemberAuth::attempt((string) ($welcome['username'] ?? (string) $v->value('mobile')), (string) ($welcome['password'] ?? ''));
                        $memberRow = db()->first('SELECT * FROM members WHERE id = :id', ['id' => (int) ($result['member']['id'] ?? 0)]);
                        if ($memberRow) {
                            $memberService->ensureCustomerRecord($memberRow);
                            $customerRow = db()->first('SELECT * FROM customers WHERE member_id = :m OR mobile = :mob LIMIT 1', ['m' => (int) $memberRow['id'], 'mob' => $memberRow['mobile']]);
                            if ($customerRow) {
                                CustomerAuth::loginSession($customerRow);
                            }
                        }
                        Session::set('checkout_welcome', $welcome);
                        Session::success('Welcome aboard! Your member ID is ' . (string) ($welcome['member_code'] ?? '') . '. Save your login details, then complete your order.');
                        Request::redirect('checkout.php?registered=1');
                    }
                    $errors['general'] = [(string) ($result['message'] ?? 'Registration could not be completed. Please try again.')];
                    foreach ((array) ($result['errors'] ?? []) as $field => $messages) {
                        $errors[$field] = (array) $messages;
                    }
                } else {
                    $errors = $v->errors();
                }
                Session::set('_old_input', array_map(static fn ($v): string => is_string($v) ? $v : '', $_POST));
            }
        } else {
            Session::flash('warning', 'Please sign in or create an account to place your order.');
            Request::redirect('checkout.php');
        }
    } else {
        // ---- signed in: place the order (the original checkout) -----------------------
        $v = new Validator($_POST);
    $v->label('full_name', 'Full name')->required('full_name')->min('full_name', 3);
    $v->label('phone', 'Mobile number')->required('phone')->phone('phone', true);
    $v->label('email', 'Email')->email('email');
    $v->label('address_line1', 'Address')->required('address_line1')->min('address_line1', 6);
    $v->label('city', 'City / Town')->required('city');
    $v->label('state', 'State')->required('state');
    $v->label('pincode', 'PIN code')->required('pincode')->pincode('pincode', true);
    $v->label('payment_method', 'Payment method')->required('payment_method')->in('payment_method', array_keys($gateways));

    if ($v->passes()) {
        // Guest checkout is intentionally closed: this branch only runs for signed-in
        // customers/members, so $customer is always set here (no silent account creation).
        $shipping = [
            'full_name' => $v->value('full_name'),
            'phone' => preg_replace('/\D/', '', (string) $v->value('phone')),
            'email' => $v->value('email'),
            'address_line1' => $v->value('address_line1'),
            'address_line2' => $v->value('address_line2'),
            'city' => $v->value('city'),
            'district' => $v->value('district'),
            'state' => $v->value('state'),
            'pincode' => $v->value('pincode'),
            'landmark' => $v->value('landmark'),
        ];
        try {
            $result = $orderService->checkout([
            'payment_method' => $v->value('payment_method'),
            'shipping' => $shipping,
            'billing' => $shipping,
            'notes' => $v->value('notes'),
            ]);
        } catch (Throwable $e) {
            error_log('[checkout] ' . $e->getMessage());
            $result = ['ok' => false, 'message' => APP_DEBUG ? 'Checkout failed: ' . $e->getMessage() : 'We could not place your order. Please try again or call our helpline.'];
        }
        if ($result['ok']) {
            if ($result['payment_method'] === 'razorpay') {
                Request::redirect('payment.php?order=' . $result['order_id']);
            }
            Request::redirect('order-confirmation.php?order=' . $result['order_id']);
        }
        $errors['general'] = [$result['message']];
    } else {
        $errors = $v->errors();
        if (Request::isAjax()) {
            \Saee\Lib\Response::error($v->firstError(), $errors);
        }
    }
    } // end signed-in branch
}

$pageTitle = 'Checkout';
$pageRobots = 'noindex,follow';
require INCLUDES_PATH . '/header.php';
echo page_hero('Checkout', 'Complete your order in a few steps.', ['Cart' => 'cart.php', 'Checkout' => '']);
?>
<section class="section">
  <div class="container">
    <?= render_flash() ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger"><?= e($errors['general'][0]) ?></div>
    <?php endif; ?>

    <?php if ($isGuest): ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <?php if ($authMode === 'choose'): ?>
            <div class="panel border rounded-4 p-4 p-lg-5 text-center">
              <h2 class="h3">Welcome! How would you like to continue?</h2>
              <p class="text-muted">Track your order and keep your details safe - choose an option below to finish checkout.</p>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <div class="border rounded-4 p-4 h-100">
                    <div class="display-6 text-primary"><i class="fa-solid fa-right-to-bracket"></i></div>
                    <h3 class="h5 mt-2">I'm already a user</h3>
                    <p class="small text-muted">Sign in with your customer account - or with your Member ID / partner credentials.</p>
                    <a class="btn btn-primary w-100" href="<?= e(Url::to('checkout.php?mode=login')) ?>">Sign in</a>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="border rounded-4 p-4 h-100">
                    <div class="display-6 text-success"><i class="fa-solid fa-user-plus"></i></div>
                    <h3 class="h5 mt-2">I'm a new user</h3>
                    <p class="small text-muted">Register like a member (with address) - it takes a minute and unlocks the partner plan.</p>
                    <a class="btn btn-success w-100" href="<?= e(Url::to('checkout.php?mode=register')) ?>">Create account</a>
                  </div>
                </div>
              </div>
              <p class="small text-muted mt-4 mb-0">For security, orders are placed after you sign in or register.</p>
            </div>
          <?php elseif ($authMode === 'login'): ?>
            <div class="panel border rounded-4 p-4 p-lg-5">
              <div class="text-center mb-4">
                <h2 class="h3">Sign in to complete your order</h2>
                <p class="small text-muted">Customers sign in with mobile/email - partners can use the same credentials as the partner portal (Member ID, mobile or email).</p>
              </div>
              <?php if (!empty($errors['identifier'])): ?><div class="alert alert-danger"><?= e($errors['identifier'][0]) ?></div><?php endif; ?>
              <form method="post" action="<?= e(Url::to('checkout.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                  <label class="form-label" for="identifier">Mobile number, email or Member ID</label>
                  <input id="identifier" name="identifier" class="form-control" value="<?= old_input('identifier') ?>" required autocomplete="username">
                </div>
                <div class="mb-3">
                  <label class="form-label" for="password">Password</label>
                  <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                  <label class="form-check-label small" for="remember">Keep me signed in</label>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in &amp; continue <i class="fa-solid fa-arrow-right ms-1"></i></button>
              </form>
              <div class="text-center small mt-3">
                <a href="<?= e(Url::to('checkout.php?mode=register')) ?>">New here? Create an account</a>
                <span class="text-muted mx-1">·</span>
                <a href="<?= e(Url::to('forgot-password.php')) ?>">Forgot password?</a>
                <span class="text-muted mx-1">·</span>
                <a href="<?= e(Url::to('checkout.php')) ?>">Back</a>
              </div>
            </div>
          <?php else: ?>
            <?php if (!$registrationOpen): ?>
              <div class="alert alert-warning">Registration is temporarily disabled. Please <a href="<?= e(Url::to('checkout.php?mode=login')) ?>">sign in</a> or check back soon.</div>
            <?php else: ?>
              <div class="panel border rounded-4 p-4 p-lg-5">
                <div class="text-center mb-4">
                  <h2 class="h3">Create your account</h2>
                  <p class="small text-muted">Register like a member with your address - you'll get your Member ID and login credentials, then come straight back here to place your order.</p>
                </div>
                <form method="post" action="<?= e(Url::to('checkout.php')) ?>" novalidate>
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="register">
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
                        <?php foreach (['farmer' => 'Farmer', 'distributor' => 'Distributor', 'retailer' => 'Retailer'] as $roleKey => $roleLabel): ?>
                          <option value="<?= e($roleKey) ?>" <?= old_input('role', 'farmer') === $roleKey ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
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

                  <h2 class="h6 text-uppercase text-muted mt-4 mb-3" style="letter-spacing:.1em">4 · Your login</h2>
                  <div class="alert alert-success small mb-0">
                    <i class="fa-solid fa-key me-1"></i>
                    <strong>You do not need to choose a password.</strong>
                    Your password is generated automatically and is exactly the same as your
                    Member ID — for example <code>SLH000001</code>. You will see both right after
                    registering, so please save them. You can change the password later from
                    <em>My Account &rsaquo; Password</em>.
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
                  <button type="submit" class="btn btn-success btn-lg mt-3 w-100">Register &amp; continue to order <i class="fa-solid fa-arrow-right ms-1"></i></button>
                  <div class="text-center small mt-3">
                    <a href="<?= e(Url::to('checkout.php?mode=login')) ?>">Already registered? Sign in</a>
                    <span class="text-muted mx-1">·</span>
                    <a href="<?= e(Url::to('checkout.php')) ?>">Back</a>
                  </div>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="col-lg-4">
          <div class="summary-card sticky-lg-top" style="top:90px">
            <h2 class="h6 mb-3">Your order</h2>
            <?php foreach ($totals['items'] as $item): ?>
              <div class="d-flex justify-content-between small py-1">
                <span class="text-truncate-2"><?= e($item['name']) ?> <span class="text-muted">×<?= (int) $item['quantity'] ?></span></span>
                <span class="ms-2 text-nowrap"><?= money((float) $item['unit_price'] * (int) $item['quantity']) ?></span>
              </div>
            <?php endforeach; ?>
            <hr class="my-2">
            <div class="summary-row"><span>Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
            <div class="summary-row"><span>Tax</span><span><?= money($totals['tax']) ?></span></div>
            <div class="summary-row"><span>Shipping</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Free' ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?= money($totals['grand_total']) ?></span></div>
            <p class="small text-muted mt-3 mb-0">Sign in or register to place the order.</p>
          </div>
        </div>
      </div>
    <?php else: ?>
      <?php if ($welcome !== []): ?>
        <div class="alert alert-success d-flex flex-column flex-md-row align-items-md-center gap-3">
          <div class="welcome-tick"><i class="fa-solid fa-check"></i></div>
          <div class="flex-grow-1">
            <strong>You are registered!</strong><br>
            Your partner ID is <strong><?= e((string) ($welcome['member_code'] ?? '')) ?></strong>. Your login details are shown in the window &mdash; save them before continuing, then place your order below.
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success" id="showCredentials">View login credentials</button>
            <a href="#deliveryPanel" class="btn btn-success">Continue to order</a>
          </div>
        </div>
      <?php endif; ?>
    <form method="post" action="<?= e(Url::to('checkout.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="panel mb-4" id="deliveryPanel">
            <div class="panel-head"><h2><i class="fa-solid fa-location-dot me-2 text-primary"></i>Delivery details</h2></div>
            <div class="panel-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="full_name">Full name <span class="required-mark">*</span></label>
                  <input id="full_name" name="full_name" class="form-control<?= invalid_class($errors, 'full_name') ?>" value="<?= old_input('full_name', $customer['name'] ?? $defaultAddress['full_name'] ?? '') ?>" required>
                  <?= field_error($errors, 'full_name') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="phone">Mobile number <span class="required-mark">*</span></label>
                  <input id="phone" name="phone" type="tel" class="form-control<?= invalid_class($errors, 'phone') ?>" value="<?= old_input('phone', $customer['mobile'] ?? $defaultAddress['phone'] ?? '') ?>" required>
                  <?= field_error($errors, 'phone') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="email">Email</label>
                  <input id="email" name="email" type="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email', $customer['email'] ?? '') ?>">
                  <?= field_error($errors, 'email') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="pincode">PIN code <span class="required-mark">*</span></label>
                  <input id="pincode" name="pincode" class="form-control<?= invalid_class($errors, 'pincode') ?>" value="<?= old_input('pincode', $defaultAddress['pincode'] ?? $memberDefaults['pincode'] ?? '') ?>" inputmode="numeric" required>
                  <?= field_error($errors, 'pincode') ?>
                </div>
                <div class="col-12">
                  <label class="form-label" for="address_line1">Address (house no, street, village) <span class="required-mark">*</span></label>
                  <input id="address_line1" name="address_line1" class="form-control<?= invalid_class($errors, 'address_line1') ?>" value="<?= old_input('address_line1', $defaultAddress['address_line1'] ?? $memberDefaults['address'] ?? '') ?>" required>
                  <?= field_error($errors, 'address_line1') ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="address_line2">Landmark / area</label>
                  <input id="address_line2" name="address_line2" class="form-control" value="<?= old_input('address_line2', $memberDefaults['taluka'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="landmark">Nearby landmark</label>
                  <input id="landmark" name="landmark" class="form-control" value="<?= old_input('landmark') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="city">City / Town <span class="required-mark">*</span></label>
                  <input id="city" name="city" class="form-control<?= invalid_class($errors, 'city') ?>" value="<?= old_input('city', $defaultAddress['city'] ?? $memberDefaults['village'] ?? '') ?>" required>
                  <?= field_error($errors, 'city') ?>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="district">District</label>
                  <input id="district" name="district" class="form-control" value="<?= old_input('district', $defaultAddress['district'] ?? $memberDefaults['district'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="state">State <span class="required-mark">*</span></label>
                  <input id="state" name="state" class="form-control<?= invalid_class($errors, 'state') ?>" value="<?= old_input('state', $defaultAddress['state'] ?? $memberDefaults['state'] ?? '') ?>" required>
                  <?= field_error($errors, 'state') ?>
                </div>
                <div class="col-12">
                  <label class="form-label" for="notes">Order notes (optional)</label>
                  <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Delivery instructions, preferred time…"><?= old_input('notes') ?></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head"><h2><i class="fa-solid fa-credit-card me-2 text-primary"></i>Payment method</h2></div>
            <div class="panel-body">
              <?php if ($gateways === []): ?>
                <div class="alert alert-warning mb-0">No payment method is enabled. Please contact support.</div>
              <?php endif; ?>
              <div class="d-grid gap-2">
                <?php foreach ($gateways as $key => $label):
                    $desc = match ($key) {
                        'cod' => 'Pay in cash when your order is delivered.',
                        'razorpay' => 'Cards, UPI, netbanking and wallets via Razorpay.',
                        'upi' => 'Pay directly to our UPI ID and upload the reference.',
                        default => '',
                    }; ?>
                  <label class="pay-option <?= old_input('payment_method', 'cod') === $key ? 'selected' : '' ?>">
                    <input type="radio" name="payment_method" value="<?= e($key) ?>" class="form-check-input mt-1" <?= old_input('payment_method', 'cod') === $key ? 'checked' : '' ?> required>
                    <span>
                      <span class="fw-semibold d-block"><?= e($label) ?></span>
                      <span class="small text-muted"><?= e($desc) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php echo field_error($errors, 'payment_method'); ?>
              <p class="small text-muted mt-3 mb-0"><i class="fa-solid fa-shield-halved me-1"></i>Payments are processed through PCI-DSS compliant gateways. Saee Life Herbal never stores card details.</p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="summary-card sticky-lg-top" style="top:90px">
            <h2 class="h6 mb-3">Your order</h2>
            <?php foreach ($totals['items'] as $item): ?>
              <div class="d-flex justify-content-between small py-1">
                <span class="text-truncate-2"><?= e($item['name']) ?> <span class="text-muted">×<?= (int) $item['quantity'] ?></span></span>
                <span class="ms-2 text-nowrap"><?= money((float) $item['unit_price'] * (int) $item['quantity']) ?></span>
              </div>
            <?php endforeach; ?>
            <hr class="my-2">
            <div class="summary-row"><span>Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
            <div class="summary-row"><span>Tax</span><span><?= money($totals['tax']) ?></span></div>
            <div class="summary-row"><span>Shipping</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Free' ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?= money($totals['grand_total']) ?></span></div>
            <button type="submit" class="btn btn-primary w-100 mt-3" <?= $gateways === [] ? 'disabled' : '' ?>>Place order <i class="fa-solid fa-arrow-right ms-1"></i></button>
            <p class="small text-muted mt-3 mb-0"><?= e(setting('order_terms')) ?></p>
          </div>
        </div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php
if ($welcome !== []) {
    $welcomeContext = 'self';
    require INCLUDES_PATH . '/member-welcome-card.php';
    echo '<style>.welcome-tick{width:64px;height:64px;border-radius:50%;background:#E8F5E9;color:#14532D;display:inline-flex;align-items:center;justify-content:center;font-size:26px}</style>';
}
?>
<script>
document.querySelectorAll('.pay-option input').forEach(function (input) {
  input.addEventListener('change', function () {
    document.querySelectorAll('.pay-option').forEach(function (o) { o.classList.remove('selected'); });
    input.closest('.pay-option').classList.add('selected');
  });
});
</script>
<?php if ($welcome !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var showWelcomeCard = function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('memberWelcomeCard')).show();
    };
    var trigger = document.getElementById('showCredentials');
    if (trigger) { trigger.addEventListener('click', showWelcomeCard); }
    showWelcomeCard();
});
</script>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
