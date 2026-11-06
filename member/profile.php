<?php
/** Member profile: personal details + address (email/mobile protected). */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Validator;

$profile = db()->first('SELECT * FROM member_profiles WHERE member_id = :m', ['m' => $currentMemberId]);
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $v = new Validator($_POST);
    $v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
    $v->label('email', 'Email')->email('email');
    $v->label('address', 'Address')->max('address', 255);
    $v->label('state', 'State')->max('state', 80);
    $v->label('district', 'District')->max('district', 80);
    $v->label('taluka', 'Taluka')->max('taluka', 80);
    $v->label('village', 'Village')->max('village', 80);
    $v->label('pincode', 'PIN code')->pincode('pincode');
    $v->label('date_of_birth', 'Date of birth')->date('date_of_birth');
    $v->label('primary_crops', 'Primary crops')->max('primary_crops', 200);
    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        $email = strtolower(trim((string) $v->value('email')));
        if ($email !== '' && db()->exists('members', 'email = :e AND id <> :id', ['e' => $email, 'id' => $currentMemberId])) {
            $errors['email'] = ['This email is already used by another account.'];
        }
    }

    $photo = null;
    if (empty($errors) && !empty($_FILES['photo']['name'])) {
        $upload = Uploader::store($_FILES['photo'], 'members');
        if ($upload['ok']) {
            $photo = $upload['path'];
        } else {
            $errors['photo'] = [$upload['message']];
        }
    }

    if (empty($errors)) {
        db()->transaction(function ($db) use ($v, $photo, $profile) {
            $db->update('members', [
                'name' => (string) $v->value('name'),
                'email' => strtolower(trim((string) $v->value('email'))) ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $currentMemberId]);

            $data = [
                'date_of_birth' => $v->value('date_of_birth') ?: null,
                'gender' => in_array(Request::input('gender'), ['male', 'female', 'other'], true) ? Request::input('gender') : null,
                'address' => (string) $v->value('address'),
                'state' => (string) $v->value('state'),
                'district' => (string) $v->value('district'),
                'taluka' => (string) $v->value('taluka'),
                'village' => (string) $v->value('village'),
                'pincode' => (string) $v->value('pincode'),
                'occupation' => (string) Request::input('occupation', ''),
                'land_area' => (string) Request::input('land_area', ''),
                'primary_crops' => (string) $v->value('primary_crops'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($photo) {
                $data['photo'] = $photo;
            }
            if ($profile) {
                $db->update('member_profiles', $data, 'member_id = :m', ['m' => $currentMemberId]);
            } else {
                $data['member_id'] = $currentMemberId;
                $data['created_at'] = date('Y-m-d H:i:s');
                $db->insert('member_profiles', $data);
            }
        });
        Session::success('Profile updated successfully.');
        Request::redirect('profile.php');
    }
}
$profile = db()->first('SELECT * FROM member_profiles WHERE member_id = :m', ['m' => $currentMemberId]);
$photo = $profile['photo'] ?? null;

$pageTitle = 'My Profile';
$pageHeading = 'My Profile';
$pageSub = 'Member ID ' . $member['member_code'] . ' · joined ' . format_date((string) $member['joined_at'], 'd M Y');
$activeNav = 'profile';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4 text-center">
      <?php if ($photo): ?>
        <img class="profile-photo" src="<?= e(\Saee\Lib\Url::upload($photo)) ?>" alt="Profile photo">
      <?php else: ?>
        <div class="profile-photo profile-photo-initials"><?= e(initials((string) $member['name'])) ?></div>
      <?php endif; ?>
      <h2 class="h5 mt-3 mb-1"><?= e((string) $member['name']) ?></h2>
      <p class="small text-muted mb-2"><?= e((string) $member['member_code']) ?> · <?= e(ucfirst((string) $member['role'])) ?></p>
      <div class="d-flex justify-content-center gap-2">
        <?= status_badge((string) $member['status']) ?>
        <?= status_badge((string) $member['kyc_status']) ?>
      </div>
      <hr>
      <div class="small text-start">
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Sponsor</span><strong><?= e((string) ($member['sponsor_name'] ?? '—')) ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Sponsor ID</span><strong><?= e((string) ($member['sponsor_code'] ?? '—')) ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Mobile</span><strong><?= e((string) $member['mobile']) ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Email</span><strong class="text-truncate"><?= e((string) ($member['email'] ?? '—')) ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Referral code</span><strong><?= e((string) $member['referral_code']) ?></strong></div>
      </div>
      <p class="small text-muted mt-3 mb-0">Mobile number and sponsor cannot be changed from here. Contact support for an authorised correction.</p>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel p-3 p-md-4">
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <h2 class="h6 mb-3">Personal details</h2>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full name</label>
            <input name="name" class="form-control<?= invalid_class($errors, 'name') ?>" value="<?= e((string) $member['name']) ?>" required>
            <?= field_error($errors, 'name') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control<?= invalid_class($errors, 'email') ?>" value="<?= e((string) ($member['email'] ?? '')) ?>">
            <?= field_error($errors, 'email') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Date of birth</label>
            <input name="date_of_birth" type="date" class="form-control" value="<?= e((string) ($profile['date_of_birth'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="">Prefer not to say</option>
              <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($profile['gender'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Occupation</label>
            <input name="occupation" class="form-control" value="<?= e((string) ($profile['occupation'] ?? '')) ?>" placeholder="Farmer, retailer, distributor…">
          </div>
          <div class="col-md-6">
            <label class="form-label">Land area (acres)</label>
            <input name="land_area" class="form-control" value="<?= e((string) ($profile['land_area'] ?? '')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Primary crops</label>
            <input name="primary_crops" class="form-control" value="<?= e((string) ($profile['primary_crops'] ?? '')) ?>" placeholder="Cotton, soybean, orange…">
          </div>
          <div class="col-12">
            <label class="form-label">Profile photo</label>
            <input name="photo" type="file" accept="image/*" class="form-control<?= invalid_class($errors, 'photo') ?>">
            <?= field_error($errors, 'photo') ?>
          </div>
        </div>

        <h2 class="h6 mt-4 mb-3">Address</h2>
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Address</label><input name="address" class="form-control" value="<?= e((string) ($profile['address'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">State</label><input name="state" class="form-control" value="<?= e((string) ($profile['state'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">District</label><input name="district" class="form-control" value="<?= e((string) ($profile['district'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Taluka</label><input name="taluka" class="form-control" value="<?= e((string) ($profile['taluka'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Village</label><input name="village" class="form-control" value="<?= e((string) ($profile['village'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">PIN code</label><input name="pincode" class="form-control<?= invalid_class($errors, 'pincode') ?>" value="<?= e((string) ($profile['pincode'] ?? '')) ?>"><?= field_error($errors, 'pincode') ?></div>
        </div>
        <button class="btn btn-primary mt-4" type="submit">Save changes</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
