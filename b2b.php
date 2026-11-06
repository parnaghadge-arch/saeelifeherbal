<?php
/**
 * B2B / bulk & dealership enquiry page.
 *
 * Leads are written to `b2b_leads`, which the admin panel exposes as the B2B CRM
 * (admin/b2b-leads.php): stage pipeline, assignment, notes and follow-up dates.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Notification;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$businessTypes = [
    'retailer' => 'Retail shop / agri store',
    'distributor' => 'Distributor',
    'dealer' => 'Dealer',
    'fpo' => 'FPO / farmer producer company',
    'cooperative' => 'Co-operative society',
    'exporter' => 'Exporter',
    'other' => 'Other',
];

$errors = [];
$sent = false;
$leadNo = '';

if (is_post()) {
    Security::verifyCsrf();

    Session::set('_old_input', array_map(static fn ($v) => is_array($v) ? '' : (string) $v, $_POST));

    $v = new Validator($_POST);
    $v->label('contact_name', 'Contact name')->required('contact_name')->min('contact_name', 3)->max('contact_name', 120);
    $v->label('company_name', 'Business name')->max('company_name', 191);
    $v->label('designation', 'Designation')->max('designation', 120);
    $v->label('phone', 'Mobile number')->required('phone')->phone('phone', true);
    $v->label('whatsapp', 'WhatsApp number')->phone('whatsapp');
    $v->label('email', 'Email')->email('email');
    $v->label('business_type', 'Business type')->in('business_type', array_keys($businessTypes), true);
    $v->label('city', 'City / town')->max('city', 120);
    $v->label('state', 'State')->max('state', 120);
    $v->label('pincode', 'PIN code')->regex('pincode', '/^[1-9][0-9]{5}$/', 'Enter a valid 6-digit PIN code.');
    $v->label('gstin', 'GSTIN')->regex('gstin', '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/', 'Enter a valid 15-character GSTIN, or leave it blank.');
    $v->label('annual_turnover', 'Annual turnover')->max('annual_turnover', 60);
    $v->label('product_interest', 'Product interest')->max('product_interest', 255);
    $v->label('monthly_volume', 'Monthly volume')->max('monthly_volume', 120);
    $v->label('message', 'Requirement details')->max('message', 3000);

    // Honeypot: a hidden field humans never fill in.
    if (trim((string) ($_POST['website_url'] ?? '')) !== '') {
        $errors['contact_name'] = ['Your submission could not be verified. Please try again.'];
    }

    if ($errors === [] && $v->passes()) {
        // Throttle: max 3 B2B leads per IP per 10 minutes.
        $recent = (int) db()->value(
            'SELECT COUNT(*) FROM b2b_leads WHERE ip_address = :ip AND created_at > :since',
            ['ip' => client_ip(), 'since' => date('Y-m-d H:i:s', time() - 600)]
        );

        if ($recent >= 3) {
            $errors['contact_name'] = ['Too many submissions from your network. Please try again in a few minutes.'];
        } else {
            $now = date('Y-m-d H:i:s');
            $phone = preg_replace('/\D/', '', (string) $v->value('phone'));
            $existing = db()->first('SELECT id, lead_no FROM b2b_leads WHERE phone = :p ORDER BY id DESC', ['p' => $phone]);

            $data = [
                'company_name' => $v->value('company_name') ?: null,
                'contact_name' => (string) $v->value('contact_name'),
                'designation' => $v->value('designation') ?: null,
                'email' => $v->value('email') ?: null,
                'phone' => $phone,
                'whatsapp' => preg_replace('/\D/', '', (string) $v->value('whatsapp')) ?: null,
                'business_type' => (string) $v->value('business_type'),
                'city' => $v->value('city') ?: null,
                'state' => $v->value('state') ?: null,
                'pincode' => $v->value('pincode') ?: null,
                'gstin' => strtoupper((string) $v->value('gstin')) ?: null,
                'annual_turnover' => $v->value('annual_turnover') ?: null,
                'product_interest' => $v->value('product_interest') ?: null,
                'monthly_volume' => $v->value('monthly_volume') ?: null,
                'message' => $v->value('message') ?: null,
                'source_page' => 'b2b',
                'priority' => trim((string) $v->value('monthly_volume')) !== '' ? 'high' : 'medium',
                'ip_address' => client_ip(),
                'updated_at' => $now,
            ];

            if ($existing) {
                // Repeat enquiry from the same number: keep one CRM record, refresh the details.
                db()->update('b2b_leads', $data, 'id = :id', ['id' => $existing['id']]);
                $leadId = (int) $existing['id'];
                $leadNo = (string) $existing['lead_no'];
                db()->insert('b2b_lead_activities', [
                    'lead_id' => $leadId,
                    'type' => 'note',
                    'body' => 'Enquiry re-submitted from the website B2B form.',
                    'created_at' => $now,
                ]);
            } else {
                $data['lead_no'] = 'B2B' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $data['status'] = 'new';
                $data['created_at'] = $now;
                $leadId = (int) db()->insert('b2b_leads', $data);
                $leadNo = (string) $data['lead_no'];
                db()->insert('b2b_lead_activities', [
                    'lead_id' => $leadId,
                    'type' => 'status',
                    'body' => 'Lead captured from the website B2B page.',
                    'new_status' => 'new',
                    'created_at' => $now,
                ]);
                Notification::sendToAdmins(
                    'New B2B lead: ' . ($data['company_name'] ?: $data['contact_name']),
                    $data['contact_name'] . ' (' . $phone . ') · ' . $businessTypes[$data['business_type']] . ' · ' . ($data['city'] ?: 'Location not given'),
                    ['template' => 'b2b_lead_received', 'link' => 'b2b-leads', 'variables' => [
                        'contact_name' => $data['contact_name'],
                        'business_type' => $businessTypes[$data['business_type']],
                    ]]
                );
            }

            $sent = true;
            Session::success('Thank you! Your B2B enquiry (' . $leadNo . ') has been received. Our partnership team will contact you within one working day.');
            if (Request::isAjax()) {
                \Saee\Lib\Response::success('Thank you! Your B2B enquiry has been received. Our partnership team will contact you within one working day.');
            }
        }
    } else {
        $errors = $v->errors();
        if (Request::isAjax()) {
            \Saee\Lib\Response::error($v->firstError(), $errors);
        }
    }
}

$pageTitle = 'B2B & Bulk Orders';
$pageDescription = 'Buy Saee Life Herbal products in bulk. Dealership, distributorship and institutional supply enquiries for agri-input retailers, FPOs and exporters.';
require INCLUDES_PATH . '/header.php';
echo page_hero('B2B & Bulk Orders', 'Dealership, distributorship and institutional supply — one form, one dedicated manager.', ['B2B & Bulk Orders' => '']);

$usp = [
    ['fa-solid fa-industry', 'Factory-direct pricing', 'Buy straight from our manufacturing unit — no middle layer, better margins.'],
    ['fa-solid fa-boxes-stacked', 'Bulk pack sizes', '5 L, 10 L, 20 L and case packs available for every product line.'],
    ['fa-solid fa-truck-fast', 'Pan-India dispatch', 'Depot despatch within 48 hours with transport support to your town.'],
    ['fa-solid fa-user-tie', 'Dedicated manager', 'A named partnership manager handles rates, schemes and after-sales.'],
];
?>

<section class="section">
  <div class="container">
    <div class="row g-3 g-md-4 mb-4 mb-lg-5">
      <?php foreach ($usp as [$icon, $title, $copy]): ?>
        <div class="col-6 col-lg-3">
          <div class="panel p-3 p-md-4 h-100">
            <span class="feature-icon mb-3" style="width:52px;height:52px;font-size:1.1rem;border-radius:14px"><i class="<?= e($icon) ?>" aria-hidden="true"></i></span>
            <h3 class="h6 mb-1"><?= e($title) ?></h3>
            <p class="small text-muted mb-0"><?= e($copy) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-4 g-lg-5">
      <div class="col-lg-5">
        <span class="eyebrow">Why partner with us</span>
        <h2 class="section-title">Built for trade buyers</h2>
        <p class="text-muted">We supply agri-input retailers, district distributors, FPOs and exporters across Maharashtra and central India. Share your requirement and our team will send a rate card and territory availability.</p>
        <div class="panel p-4 mb-3">
          <h3 class="h6 mb-3">What happens next</h3>
          <ol class="list-unstyled steps-list mb-0">
            <li><span>1</span><div><strong>We review your details</strong><br><span class="small text-muted">Within one working day.</span></div></li>
            <li><span>2</span><div><strong>A manager calls you</strong><br><span class="small text-muted">To confirm territory and volumes.</span></div></li>
            <li><span>3</span><div><strong>Rate card & samples</strong><br><span class="small text-muted">Sent by email or WhatsApp.</span></div></li>
            <li class="mb-0"><span>4</span><div><strong>Onboarding</strong><br><span class="small text-muted">KYC, first order and partner login.</span></div></li>
          </ol>
        </div>
        <div class="panel p-4">
          <h3 class="h6 mb-2">Prefer to talk first?</h3>
          <p class="small text-muted mb-2">Our partnership desk is open Mon–Sat, 9:30 AM – 6:30 PM IST.</p>
          <a class="btn btn-outline-primary btn-sm me-2 mb-2" href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('contact_phone'))) ?>">
            <i class="fa-solid fa-phone me-1"></i><?= e(setting('contact_phone')) ?>
          </a>
          <a class="btn btn-light-green btn-sm mb-2" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) setting('whatsapp_number'))) ?>" target="_blank" rel="noopener">
            <i class="fab fa-whatsapp me-1"></i>WhatsApp
          </a>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="panel p-4" id="b2b-form">
          <h2 class="h5 mb-1">Request a B2B quote</h2>
          <p class="small text-muted mb-3">Fields marked <span class="required-mark">*</span> are required.</p>

          <?php if ($sent): ?>
            <div class="alert alert-success d-flex gap-2">
              <i class="fa-solid fa-circle-check mt-1" aria-hidden="true"></i>
              <div>
                <strong>Enquiry received.</strong>
                <div class="small">Reference <strong><?= e($leadNo) ?></strong>. Our partnership team will contact you within one working day.</div>
              </div>
            </div>
          <?php elseif ($errors !== []): ?>
            <div class="alert alert-danger small">
              <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>Please correct the highlighted fields and submit again.
            </div>
          <?php endif; ?>

          <?php if (!$sent): ?>
          <form method="post" action="<?= e(Url::to('b2b.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="contact_name">Contact name <span class="required-mark">*</span></label>
                <input type="text" id="contact_name" name="contact_name" class="form-control<?= invalid_class($errors, 'contact_name') ?>" value="<?= old_input('contact_name') ?>" required autocomplete="name">
                <?= field_error($errors, 'contact_name') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="company_name">Business / firm name</label>
                <input type="text" id="company_name" name="company_name" class="form-control<?= invalid_class($errors, 'company_name') ?>" value="<?= old_input('company_name') ?>" autocomplete="organization">
                <?= field_error($errors, 'company_name') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number <span class="required-mark">*</span></label>
                <input type="tel" id="phone" name="phone" inputmode="numeric" class="form-control<?= invalid_class($errors, 'phone') ?>" value="<?= old_input('phone') ?>" required autocomplete="tel">
                <?= field_error($errors, 'phone') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="whatsapp">WhatsApp number</label>
                <input type="tel" id="whatsapp" name="whatsapp" inputmode="numeric" class="form-control<?= invalid_class($errors, 'whatsapp') ?>" value="<?= old_input('whatsapp') ?>">
                <?= field_error($errors, 'whatsapp') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= old_input('email') ?>" autocomplete="email">
                <?= field_error($errors, 'email') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="designation">Designation</label>
                <input type="text" id="designation" name="designation" class="form-control<?= invalid_class($errors, 'designation') ?>" value="<?= old_input('designation') ?>" placeholder="Owner, Purchase head…">
                <?= field_error($errors, 'designation') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="business_type">Business type <span class="required-mark">*</span></label>
                <select id="business_type" name="business_type" class="form-select<?= invalid_class($errors, 'business_type') ?>" required>
                  <?php foreach ($businessTypes as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= old_input('business_type', 'distributor') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'business_type') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="monthly_volume">Expected monthly volume</label>
                <input type="text" id="monthly_volume" name="monthly_volume" class="form-control<?= invalid_class($errors, 'monthly_volume') ?>" value="<?= old_input('monthly_volume') ?>" placeholder="e.g. 200 litre / 40 cases">
                <?= field_error($errors, 'monthly_volume') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="city">City / town</label>
                <input type="text" id="city" name="city" class="form-control<?= invalid_class($errors, 'city') ?>" value="<?= old_input('city') ?>" autocomplete="address-level2">
                <?= field_error($errors, 'city') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="state">State</label>
                <input type="text" id="state" name="state" class="form-control<?= invalid_class($errors, 'state') ?>" value="<?= old_input('state') ?>" autocomplete="address-level1">
                <?= field_error($errors, 'state') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="pincode">PIN code</label>
                <input type="text" id="pincode" name="pincode" inputmode="numeric" maxlength="6" class="form-control<?= invalid_class($errors, 'pincode') ?>" value="<?= old_input('pincode') ?>" autocomplete="postal-code">
                <?= field_error($errors, 'pincode') ?>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="gstin">GSTIN</label>
                <input type="text" id="gstin" name="gstin" maxlength="15" class="form-control text-uppercase<?= invalid_class($errors, 'gstin') ?>" value="<?= old_input('gstin') ?>">
                <?= field_error($errors, 'gstin') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="annual_turnover">Annual turnover</label>
                <input type="text" id="annual_turnover" name="annual_turnover" class="form-control<?= invalid_class($errors, 'annual_turnover') ?>" value="<?= old_input('annual_turnover') ?>" placeholder="e.g. ₹50 lakh – ₹1 crore">
                <?= field_error($errors, 'annual_turnover') ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="product_interest">Products of interest</label>
                <input type="text" id="product_interest" name="product_interest" class="form-control<?= invalid_class($errors, 'product_interest') ?>" value="<?= old_input('product_interest') ?>" placeholder="e.g. Neem Shield, NPK 19:19:19">
                <?= field_error($errors, 'product_interest') ?>
              </div>
              <div class="col-12">
                <label class="form-label" for="message">Requirement details</label>
                <textarea id="message" name="message" rows="4" class="form-control<?= invalid_class($errors, 'message') ?>" placeholder="Territory you cover, existing brands, delivery expectations…"><?= old_input('message') ?></textarea>
                <?= field_error($errors, 'message') ?>
              </div>
              <div class="d-none" aria-hidden="true">
                <label for="website_url">Website</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-primary px-4 py-2">
                  <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>Submit B2B enquiry
                </button>
                <p class="small text-muted mt-2 mb-0"><i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Your details are used only to respond to this enquiry.</p>
              </div>
            </div>
          </form>
          <?php else: ?>
            <a class="btn btn-outline-primary" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-seedling me-2" aria-hidden="true"></i>Browse the product range</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
