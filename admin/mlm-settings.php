<?php
/** MLM plan settings: sponsor fallback, hold period, credit mode, plan visibility. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('settings.manage');

use Saee\Lib\Audit;
use Saee\Lib\MlmSettings;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Settings;
use Saee\Lib\Url;

$db = db();
if (is_post()) {
    Security::verifyCsrf();
    $fields = [
        'mlm_plan_enabled', 'mlm_company_sponsor_code', 'mlm_commission_hold_days', 'mlm_credit_status',
        'mlm_plan_public', 'mlm_registration_enabled', 'mlm_max_commission_levels', 'mlm_show_earnings_public',
        'mlm_compliance_note',
    ];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $_POST)) {
            continue;
        }
        Settings::set($field, (string) Request::input($field, ''));
    }
    foreach (['mlm_plan_enabled', 'mlm_plan_public', 'mlm_registration_enabled', 'mlm_show_earnings_public'] as $flag) {
        Settings::set($flag, Request::bool($flag) ? '1' : '0', 'boolean');
    }
    MlmSettings::flush();
    Settings::flush();
    Audit::log('update', 'mlm', 'MLM settings updated', 'settings');
    Session::success('MLM settings saved.');
    Request::redirect('mlm-settings.php');
}

$current = [
    'mlm_plan_enabled' => (string) Settings::get('mlm_plan_enabled', '0'),
    'mlm_plan_public' => (string) Settings::get('mlm_plan_public', '0'),
    'mlm_registration_enabled' => (string) Settings::get('mlm_registration_enabled', '1'),
    'mlm_show_earnings_public' => (string) Settings::get('mlm_show_earnings_public', '1'),
    'mlm_company_sponsor_code' => (string) Settings::get('mlm_company_sponsor_code', 'SAEE00001'),
    'mlm_commission_hold_days' => (string) Settings::get('mlm_commission_hold_days', '0'),
    'mlm_credit_status' => (string) Settings::get('mlm_credit_status', 'auto'),
    'mlm_max_commission_levels' => (string) Settings::get('mlm_max_commission_levels', '2'),
    'mlm_compliance_note' => (string) Settings::get('mlm_compliance_note', 'The compensation plan is subject to legal and tax review before public activation. Income depends on genuine product sales and is not guaranteed.'),
];
$payoutSettings = $db->first('SELECT * FROM payout_settings ORDER BY id ASC LIMIT 1');

if (is_post() && Request::input('form') === 'payout') {
    Security::verifyCsrf();
    $db->update('payout_settings', [
        'min_withdrawal_amount' => round((float) Request::input('min_withdrawal_amount', 0), 2),
        'max_withdrawal_amount' => round((float) Request::input('max_withdrawal_amount', 0), 2),
        'withdrawal_fee_type' => Request::input('withdrawal_fee_type') === 'fixed' ? 'fixed' : 'percent',
        'withdrawal_fee_value' => round((float) Request::input('withdrawal_fee_value', 0), 2),
        'tds_percent' => round((float) Request::input('tds_percent', 0), 2),
        'processing_days' => Request::int('processing_days', 3),
        'require_kyc' => Request::bool('require_kyc') ? 1 : 0,
        'require_verified_bank' => Request::bool('require_verified_bank') ? 1 : 0,
        'auto_approve' => Request::bool('auto_approve') ? 1 : 0,
        'payouts_enabled' => Request::bool('payouts_enabled') ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => (int) $payoutSettings['id']]);
    Audit::log('update', 'mlm', 'Payout settings updated', 'payout_settings', (int) $payoutSettings['id']);
    Session::success('Payout settings saved.');
    Request::redirect('mlm-settings.php');
}

$pageTitle = 'MLM Settings';
$pageHeading = 'MLM & Payout Settings';
$pageSub = 'Plan visibility, commission timing and withdrawal policy';
$activeModule = 'mlm-settings';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Compensation plan</h2>
      <form method="post">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-12">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mlm_plan_enabled" value="1" id="pe" <?= $current['mlm_plan_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="pe">Compensation plan active (commission engine runs on paid orders)</label></div>
          </div>
          <div class="col-12">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mlm_plan_public" value="1" id="pp" <?= $current['mlm_plan_public'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="pp">Show the business opportunity publicly (keep off until legal/tax review is complete)</label></div>
          </div>
          <div class="col-12">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mlm_registration_enabled" value="1" id="re" <?= $current['mlm_registration_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="re">Accept new partner registrations</label></div>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Company sponsor fallback code</label>
            <input name="mlm_company_sponsor_code" class="form-control form-control-sm" value="<?= e($current['mlm_company_sponsor_code']) ?>">
            <div class="form-text">Used when a member registers without a valid sponsor.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Hold period (days)</label>
            <input type="number" name="mlm_commission_hold_days" class="form-control form-control-sm" value="<?= e($current['mlm_commission_hold_days']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Credit mode</label>
            <select name="mlm_credit_status" class="form-select form-select-sm">
              <option value="auto" <?= $current['mlm_credit_status'] === 'auto' ? 'selected' : '' ?>>Auto release</option>
              <option value="manual" <?= $current['mlm_credit_status'] === 'manual' ? 'selected' : '' ?>>Admin approval</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Commission levels</label>
            <input type="number" name="mlm_max_commission_levels" class="form-control form-control-sm" value="<?= e($current['mlm_max_commission_levels']) ?>">
            <div class="form-text">Direct (level 1) and grand direct (level 2) are configured per product.</div>
          </div>
          <div class="col-12">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mlm_show_earnings_public" value="1" id="se" <?= $current['mlm_show_earnings_public'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="se">Display illustrative commission examples on the opportunity page</label></div>
          </div>
          <div class="col-12">
            <label class="form-label small">Compliance note (shown on the opportunity page)</label>
            <textarea name="mlm_compliance_note" class="form-control form-control-sm" rows="3"><?= e($current['mlm_compliance_note']) ?></textarea>
          </div>
        </div>
        <button class="btn btn-primary mt-3">Save plan settings</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Payout policy</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="payout">
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">Min withdrawal</label><input type="number" step="0.01" name="min_withdrawal_amount" class="form-control form-control-sm" value="<?= e((string) $payoutSettings['min_withdrawal_amount']) ?>"></div>
          <div class="col-6"><label class="form-label small">Max per request</label><input type="number" step="0.01" name="max_withdrawal_amount" class="form-control form-control-sm" value="<?= e((string) $payoutSettings['max_withdrawal_amount']) ?>"></div>
          <div class="col-6"><label class="form-label small">Fee type</label>
            <select name="withdrawal_fee_type" class="form-select form-select-sm"><option value="percent" <?= $payoutSettings['withdrawal_fee_type'] === 'percent' ? 'selected' : '' ?>>Percent</option><option value="fixed" <?= $payoutSettings['withdrawal_fee_type'] === 'fixed' ? 'selected' : '' ?>>Fixed</option></select>
          </div>
          <div class="col-6"><label class="form-label small">Fee value</label><input type="number" step="0.01" name="withdrawal_fee_value" class="form-control form-control-sm" value="<?= e((string) $payoutSettings['withdrawal_fee_value']) ?>"></div>
          <div class="col-6"><label class="form-label small">TDS %</label><input type="number" step="0.01" name="tds_percent" class="form-control form-control-sm" value="<?= e((string) $payoutSettings['tds_percent']) ?>"></div>
          <div class="col-6"><label class="form-label small">Processing days</label><input type="number" name="processing_days" class="form-control form-control-sm" value="<?= (int) $payoutSettings['processing_days'] ?>"></div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="payouts_enabled" value="1" id="pe2" <?= (int) $payoutSettings['payouts_enabled'] === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="pe2">Payouts enabled</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="require_kyc" value="1" id="rk" <?= (int) $payoutSettings['require_kyc'] === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="rk">Require approved KYC</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="require_verified_bank" value="1" id="rb" <?= (int) $payoutSettings['require_verified_bank'] === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="rb">Require verified bank account</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="auto_approve" value="1" id="aa" <?= (int) $payoutSettings['auto_approve'] === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="aa">Auto approve requests</label></div>
          </div>
        </div>
        <button class="btn btn-primary w-100 mt-3">Save payout policy</button>
      </form>
      <div class="alert alert-warning small mt-3 mb-0">
        KYC and bank details are never mandatory for using the member dashboard — they only gate payouts.
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
