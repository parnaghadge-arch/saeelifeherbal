<?php
/** Bank account management for payouts (masked display, admin verified). */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Validator;

$accounts = db()->select('SELECT * FROM member_bank_accounts WHERE member_id = :m ORDER BY is_primary DESC, id DESC', ['m' => $currentMemberId]);
$errors = [];
$action = (string) Request::input('action', 'add');

if (is_post()) {
    Security::verifyCsrf();
    if ($action === 'delete') {
        $id = Request::int('id');
        $row = db()->first('SELECT * FROM member_bank_accounts WHERE id = :id AND member_id = :m', ['id' => $id, 'm' => $currentMemberId]);
        if (!$row) {
            Session::error('Bank account not found.');
        } elseif ($row['status'] === 'verified') {
            Session::error('Verified bank accounts cannot be removed. Contact support.');
        } else {
            db()->delete('member_bank_accounts', 'id = :id AND member_id = :m', ['id' => $id, 'm' => $currentMemberId]);
            Session::success('Bank account removed.');
        }
        Request::redirect('bank.php');
    }

    $v = new Validator($_POST);
    $v->label('account_holder_name', 'Account holder name')->required('account_holder_name')->min('account_holder_name', 3)->max('account_holder_name', 120);
    $v->label('bank_name', 'Bank name')->required('bank_name')->max('bank_name', 120);
    $v->label('account_number', 'Account number')->required('account_number')->regex('account_number', '/^[0-9]{9,18}$/', 'Enter a valid account number (9–18 digits).');
    $v->label('ifsc_code', 'IFSC code')->required('ifsc_code')->ifsc('ifsc_code', true);
    $v->label('branch_name', 'Branch name')->max('branch_name', 120);
    $v->label('account_type', 'Account type')->in('account_type', ['savings', 'current'], false);
    if ($v->fails()) {
        $errors = $v->errors();
    }
    $cheque = null;
    if (empty($errors) && !empty($_FILES['cheque_image']['name'])) {
        $upload = Uploader::store($_FILES['cheque_image'], 'bank', Uploader::IMAGE, false);
        if ($upload['ok']) { $cheque = $upload['path']; } else { $errors['cheque_image'] = [$upload['message']]; }
    }
    if (empty($errors)) {
        $accountNumber = preg_replace('/\s+/', '', (string) $v->value('account_number'));
        $duplicate = db()->exists('member_bank_accounts', 'member_id = :m AND account_number = :a AND ifsc_code = :i',
            ['m' => $currentMemberId, 'a' => Security::encrypt($accountNumber), 'i' => strtoupper((string) $v->value('ifsc_code'))]);
        if ($duplicate) {
            Session::error('This bank account is already added.');
            Request::redirect('bank.php');
        }
        $hasPrimary = (bool) db()->value('SELECT COUNT(*) FROM member_bank_accounts WHERE member_id = :m AND is_primary = 1', ['m' => $currentMemberId]);
        db()->insert('member_bank_accounts', [
            'member_id' => $currentMemberId,
            'account_holder_name' => (string) $v->value('account_holder_name'),
            'bank_name' => (string) $v->value('bank_name'),
            'account_number' => Security::encrypt($accountNumber),
            'ifsc_code' => strtoupper((string) $v->value('ifsc_code')),
            'branch_name' => (string) $v->value('branch_name'),
            'account_type' => (string) ($v->value('account_type') ?: 'savings'),
            'cheque_image' => $cheque,
            'status' => 'pending',
            'is_primary' => $hasPrimary ? 0 : 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Session::success('Bank account added. It will be verified by our accounts team before payouts.');
        Request::redirect('bank.php');
    }
}

$pageTitle = 'Bank Details';
$pageHeading = 'Bank Details';
$pageSub = 'Payout account — masked for your security';
$activeNav = 'bank';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Saved accounts</h2>
      <?php if (!$accounts): ?>
        <p class="small text-muted mb-0">No bank account added yet.</p>
      <?php else: ?>
        <div class="list-stack">
          <?php foreach ($accounts as $account): ?>
            <div class="list-item">
              <div class="list-icon"><i class="fa-solid fa-building-columns"></i></div>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold text-truncate"><?= e((string) $account['bank_name']) ?></div>
                <div class="small text-muted"><?= e(Security::maskAccount((string) $account['account_number'])) ?> · <?= e((string) $account['ifsc_code']) ?></div>
                <div class="mt-1 d-flex gap-1 flex-wrap">
                  <?= status_badge((string) $account['status']) ?>
                  <?php if ((int) $account['is_primary'] === 1): ?><span class="badge bg-dark">Primary</span><?php endif; ?>
                </div>
                <?php if ($account['status'] === 'rejected' && $account['rejection_reason']): ?>
                  <div class="small text-danger mt-1"><?= e((string) $account['rejection_reason']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($account['status'] !== 'verified'): ?>
                <form method="post" onsubmit="return confirm('Remove this bank account?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="alert alert-light border small mt-3 mb-0">
        Account numbers are stored encrypted and shown masked. Only the accounts team can view full details.
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Add bank account</h2>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Account holder name</label>
            <input name="account_holder_name" class="form-control<?= invalid_class($errors, 'account_holder_name') ?>" value="<?= e((string) $member['name']) ?>" required>
            <?= field_error($errors, 'account_holder_name') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Bank name</label>
            <input name="bank_name" class="form-control<?= invalid_class($errors, 'bank_name') ?>" required>
            <?= field_error($errors, 'bank_name') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Account number</label>
            <input name="account_number" inputmode="numeric" class="form-control<?= invalid_class($errors, 'account_number') ?>" required>
            <?= field_error($errors, 'account_number') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">IFSC code</label>
            <input name="ifsc_code" class="form-control text-uppercase<?= invalid_class($errors, 'ifsc_code') ?>" maxlength="11" required>
            <?= field_error($errors, 'ifsc_code') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Branch</label>
            <input name="branch_name" class="form-control" value="">
          </div>
          <div class="col-md-6">
            <label class="form-label">Account type</label>
            <select name="account_type" class="form-select">
              <option value="savings">Savings</option>
              <option value="current">Current</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Cancelled cheque / passbook copy (optional)</label>
            <input type="file" name="cheque_image" class="form-control" accept="image/*">
          </div>
        </div>
        <button class="btn btn-primary mt-4" type="submit">Add account</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
