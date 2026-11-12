<?php
/** KYC submission (optional at registration, required for payouts). */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;
use Saee\Lib\Validator;

$kyc = db()->first('SELECT * FROM member_kyc WHERE member_id = :m', ['m' => $currentMemberId]);
$errors = [];
$locked = $kyc && in_array($kyc['status'], ['submitted', 'under_verification', 'approved'], true);

if (is_post()) {
    Security::verifyCsrf();
    if ($locked) {
        Session::error('Your KYC is already ' . str_replace('_', ' ', (string) $kyc['status']) . ' and cannot be edited.');
        Request::redirect('kyc.php');
    }
    $v = new Validator($_POST);
    $v->label('pan_number', 'PAN number')->required('pan_number')->pan('pan_number', true);
    $v->label('pan_name', 'Name as per PAN')->required('pan_name')->min('pan_name', 3);
    $v->label('date_of_birth', 'Date of birth')->required('date_of_birth')->date('date_of_birth');
    $v->label('aadhaar_number', 'Aadhaar number')->required('aadhaar_number')->aadhaar('aadhaar_number', true);
    $v->label('address', 'Address')->required('address')->max('address', 255);
    if ($v->fails()) {
        $errors = $v->errors();
    }
    $files = [];
    foreach (['pan_document' => 'PAN document', 'identity_document' => 'Aadhaar / ID document', 'address_document' => 'Address proof'] as $field => $label) {
        $existing = $kyc[$field] ?? null;
        if (!empty($_FILES[$field]['name'])) {
            $upload = Uploader::store($_FILES[$field], 'kyc', Uploader::DOCUMENT, false);
            if ($upload['ok']) {
                $files[$field] = $upload['path'];
            } else {
                $errors[$field] = [$label . ': ' . $upload['message']];
            }
        } elseif (!$existing) {
            $errors[$field] = [$label . ' is required.'];
        }
    }
    if (empty($errors)) {
        $data = [
            'member_id' => $currentMemberId,
            'pan_number' => Security::encrypt(strtoupper((string) $v->value('pan_number'))),
            'pan_name' => (string) $v->value('pan_name'),
            'aadhaar_number' => Security::encrypt((string) $v->value('aadhaar_number')),
            'date_of_birth' => (string) $v->value('date_of_birth'),
            'address' => (string) $v->value('address'),
            'status' => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ] + $files;
        if (!empty($_FILES['photo']['name'])) {
            $upload = Uploader::store($_FILES['photo'], 'kyc', Uploader::IMAGE, false);
            if ($upload['ok']) { $data['photo'] = $upload['path']; }
        }
        if ($kyc) {
            db()->update('member_kyc', $data, 'member_id = :m', ['m' => $currentMemberId]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            db()->insert('member_kyc', $data);
        }
        db()->update('members', ['kyc_status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $currentMemberId]);
        Session::success('KYC submitted. Our team will verify it within 1–2 working days.');
        Request::redirect('kyc.php');
    }
}

$pageTitle = 'My KYC';
$pageHeading = 'KYC Verification';
$pageSub = 'Required only for payouts — never for using your dashboard';
$activeNav = 'kyc';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h2 class="h6 mb-0">KYC status</h2><p class="small text-muted mb-0">Documents are stored privately and never cached publicly.</p></div>
        <?= status_badge((string) ($kyc['status'] ?? 'not_submitted')) ?>
      </div>
      <?php if ($kyc && $kyc['status'] === 'rejected' && $kyc['rejection_reason']): ?>
        <div class="alert alert-danger small"><strong>Rejected:</strong> <?= e((string) $kyc['rejection_reason']) ?> Please correct and resubmit.</div>
      <?php endif; ?>
      <?php if ($locked): ?>
        <div class="alert alert-success small mb-0">
          Your KYC was submitted on <?= e(format_date((string) $kyc['submitted_at'], 'd M Y H:i')) ?>. Verification is in progress; you will be notified once approved.
        </div>
      <?php else: ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">PAN number</label>
              <input name="pan_number" class="form-control text-uppercase<?= invalid_class($errors, 'pan_number') ?>" maxlength="10" placeholder="ABCDE1234F" required>
              <?= field_error($errors, 'pan_number') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Name as per PAN</label>
              <input name="pan_name" class="form-control<?= invalid_class($errors, 'pan_name') ?>" required>
              <?= field_error($errors, 'pan_name') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Aadhaar number</label>
              <input name="aadhaar_number" class="form-control<?= invalid_class($errors, 'aadhaar_number') ?>" maxlength="14" placeholder="1234 5678 9012" required>
              <?= field_error($errors, 'aadhaar_number') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date of birth</label>
              <input name="date_of_birth" type="date" class="form-control<?= invalid_class($errors, 'date_of_birth') ?>" required>
              <?= field_error($errors, 'date_of_birth') ?>
            </div>
            <div class="col-12">
              <label class="form-label">Address as per proof</label>
              <textarea name="address" class="form-control<?= invalid_class($errors, 'address') ?>" rows="2" required></textarea>
              <?= field_error($errors, 'address') ?>
            </div>
            <?php foreach (['pan_document' => 'PAN card copy', 'identity_document' => 'Aadhaar / ID copy', 'address_document' => 'Address proof'] as $field => $label): ?>
              <div class="col-md-6">
                <label class="form-label"><?= e($label) ?></label>
                <input type="file" name="<?= e($field) ?>" class="form-control<?= invalid_class($errors, $field) ?>" accept="image/*,application/pdf">
                <?= field_error($errors, $field) ?>
              </div>
            <?php endforeach; ?>
            <div class="col-md-6">
              <label class="form-label">Passport photo (optional)</label>
              <input type="file" name="photo" accept="image/*" class="form-control">
            </div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">Submit KYC</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">What you need</h2>
      <ul class="list-check small mb-3">
        <li>PAN card (10 characters)</li>
        <li>Aadhaar number (12 digits)</li>
        <li>Address proof</li>
        <li>Clear scans under 5 MB</li>
      </ul>
      <p class="small text-muted mb-0">KYC is optional while using the dashboard. It becomes mandatory only when you request a payout.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
