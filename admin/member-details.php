<?php
/** Member 360° view: profile, sponsor, wallet, KYC, bank, referrals, commissions. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('members.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\MemberService;
use Saee\Lib\Pager;
use Saee\Lib\PayoutService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\TeamService;
use Saee\Lib\Url;
use Saee\Lib\WalletService;

$db = db();
$id = Request::int('id');
$member = $id ? $db->first('SELECT * FROM members WHERE id = :id', ['id' => $id]) : null;
if (!$member) {
    Session::error('Member not found.');
    Request::redirect('members.php');
}

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    if ($action === 'change-sponsor' && AdminAuth::can('members.manage')) {
        $sponsorInput = trim((string) Request::input('sponsor_id', ''));
        $newSponsorId = null;
        if ($sponsorInput !== '') {
            $found = $db->first(
                'SELECT id FROM members WHERE member_code = :c OR id = :i LIMIT 1',
                ['c' => strtoupper($sponsorInput), 'i' => ctype_digit($sponsorInput) ? (int) $sponsorInput : -1]
            );
            $newSponsorId = $found ? (int) $found['id'] : null;
            if (!$found) {
                Session::error('Sponsor member ID not found.');
                Request::redirect('member-details.php?id=' . $id);
            }
        }
        $result = (new MemberService($db))->changeSponsor($id, $newSponsorId, (string) Request::input('reason', ''));
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    } elseif ($action === 'note' && AdminAuth::can('members.manage')) {
        $db->update('members', ['admin_note' => (string) Request::input('admin_note', ''), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        \Saee\Lib\Audit::log('update', 'members', 'Updated admin note for ' . $member['member_code'], 'members', $id);
        Session::success('Internal note saved.');
    } elseif ($action === 'reset-password' && AdminAuth::can('members.manage')) {
        $temp = Security::randomToken(4) . rand(1000, 9999);
        $db->update('members', [
            'password_hash' => Security::hashPassword($temp),
            'remember_token' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        \Saee\Lib\Audit::log('reset_password', 'members', 'Reset password for ' . $member['member_code'], 'members', $id);
        Session::success('Temporary password generated: ' . $temp . ' — share it securely; the member should change it immediately.');
    }
    Request::redirect('member-details.php?id=' . $id);
}

$wallet = (new WalletService($db))->account($id);
$team = new TeamService($db);
$direct = $team->directStats($id);
$downline = $team->downlineCount($id);
$profile = $db->first('SELECT * FROM member_profiles WHERE member_id = :m', ['m' => $id]);
$kyc = $db->first('SELECT * FROM member_kyc WHERE member_id = :m', ['m' => $id]);
$banks = AdminAuth::can('bank.view') ? $db->select('SELECT * FROM member_bank_accounts WHERE member_id = :m ORDER BY is_primary DESC', ['m' => $id]) : [];
$referrals = $db->select('SELECT member_code, name, status, joined_at FROM members WHERE sponsor_id = :s ORDER BY id DESC LIMIT 10', ['s' => $id]);
$commissions = $db->select('SELECT * FROM commission_transactions WHERE member_id = :m ORDER BY id DESC LIMIT 10', ['m' => $id]);
$payouts = $db->select('SELECT * FROM payout_requests WHERE member_id = :m ORDER BY id DESC LIMIT 10', ['m' => $id]);
// Every order tied to this member (placed by them or referred by them), newest first.
// Paged rather than capped: a capped list reads as "this member has no other orders".
$orderTotal = (int) $db->value('SELECT COUNT(*) FROM orders WHERE member_id = :m OR referral_member_id = :m', ['m' => $id]);
$orderPager = new Pager($orderTotal, 15);
$orders = $db->select(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity),0) FROM order_items i WHERE i.order_id = o.id) AS total_qty
     FROM orders o WHERE o.member_id = :m OR o.referral_member_id = :m
     ORDER BY o.id DESC' . $orderPager->limitSql(),
    ['m' => $id]
);
$sponsor = $member['sponsor_id'] ? $db->first('SELECT id, member_code, name FROM members WHERE id = :id', ['id' => (int) $member['sponsor_id']]) : null;

$pageTitle = $member['name'];
$pageHeading = $member['name'];
$pageSub = $member['member_code'] . ' · ' . ucfirst((string) $member['role']) . ' · joined ' . format_date((string) $member['joined_at'], 'd M Y');
$activeModule = 'members';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="members.php">Back to members</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel p-3 p-md-4 mb-3">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="avatar-circle" style="width:56px;height:56px;font-size:1.1rem"><?= e(initials((string) $member['name'])) ?></div>
        <div class="min-w-0">
          <div class="fw-semibold"><?= e((string) $member['name']) ?></div>
          <div class="small text-muted"><?= e((string) $member['mobile']) ?> · <?= e((string) ($member['email'] ?? 'no email')) ?></div>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-1 mb-3"><?= status_badge((string) $member['status']) ?> <?= status_badge((string) $member['kyc_status']) ?><?= (int) $member['is_payout_eligible'] === 1 ? ' <span class="badge bg-success">Payout ready</span>' : '' ?></div>
      <div class="small">
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Referral code</span><strong><?= e((string) $member['referral_code']) ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Sponsor</span>
          <strong><?= $sponsor ? '<a href="member-details.php?id=' . (int) $sponsor['id'] . '">' . e((string) $sponsor['name']) . '</a>' : '—' ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Direct / Team</span><strong><?= (int) $direct['total'] ?> / <?= (int) $downline['total'] ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Last login</span><strong><?= $member['last_login_at'] ? e(time_ago((string) $member['last_login_at'])) : 'never' ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Terms accepted</span><strong><?= $member['terms_accepted_at'] ? 'Yes' : 'No' ?></strong></div>
      </div>
      <?php if ($profile): ?>
        <hr>
        <div class="small">
          <div class="text-muted mb-1">Address</div>
          <?= e(implode(', ', array_filter([$profile['address'], $profile['village'], $profile['taluka'], $profile['district'], $profile['state'], $profile['pincode']]))) ?>
          <?php if ($profile['primary_crops']): ?><div class="text-muted mt-1">Crops: <?= e((string) $profile['primary_crops']) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Wallet</h2>
      <div class="row g-2 small">
        <div class="col-6"><span class="text-muted">Available</span><div class="fw-semibold"><?= money((float) $wallet['available_balance']) ?></div></div>
        <div class="col-6"><span class="text-muted">Pending</span><div class="fw-semibold"><?= money((float) $wallet['pending_balance']) ?></div></div>
        <div class="col-6"><span class="text-muted">Total earned</span><div class="fw-semibold"><?= money((float) $wallet['total_earned']) ?></div></div>
        <div class="col-6"><span class="text-muted">Withdrawn</span><div class="fw-semibold"><?= money((float) $wallet['total_withdrawn']) ?></div></div>
      </div>
      <a class="btn btn-sm btn-outline-secondary mt-3 w-100" href="wallets.php?member=<?= (int) $id ?>">View ledger</a>
    </div>

    <?php if (AdminAuth::can('members.manage')): ?>
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3">Authorised sponsor correction</h2>
        <p class="small text-muted">Sponsor changes are permanent and fully audited. Use only for verified corrections.</p>
        <form method="post" data-confirm="Change this member's sponsor? This is recorded in the audit trail.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change-sponsor">
          <div class="mb-2"><label class="form-label small">New sponsor member ID</label><input name="sponsor_id" class="form-control form-control-sm" placeholder="Sponsor member ID e.g. SLH00002" required></div>
          <div class="mb-2"><label class="form-label small">Reason</label><textarea name="reason" class="form-control form-control-sm" rows="2" required></textarea></div>
          <button class="btn btn-sm btn-outline-danger w-100">Change sponsor</button>
        </form>
      </div>

      <div class="panel p-3 p-md-4">
        <h2 class="h6 mb-3">Admin actions</h2>
        <form method="post" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="note">
          <label class="form-label small">Internal note</label>
          <textarea name="admin_note" class="form-control form-control-sm" rows="3"><?= e((string) ($member['admin_note'] ?? '')) ?></textarea>
          <button class="btn btn-sm btn-outline-secondary w-100 mt-2">Save note</button>
        </form>
        <form method="post" data-confirm="Generate a new temporary password? The member's current password will stop working.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset-password">
          <button class="btn btn-sm btn-outline-danger w-100">Reset password</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Recent commissions</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 data-table">
          <thead><tr><th>Date</th><th>Type</th><th>Product</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($commissions as $c): ?>
            <tr>
              <td class="small"><?= e(format_date((string) $c['created_at'], 'd M Y')) ?></td>
              <td class="small"><?= e(ucwords(str_replace('_', ' ', (string) $c['commission_type']))) ?></td>
              <td class="small"><?= e((string) $c['product_name']) ?></td>
              <td class="small text-end"><?= money((float) $c['amount']) ?></td>
              <td><?= status_badge((string) $c['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$commissions): ?><tr><td colspan="5" class="small text-muted">No commissions yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3">Direct referrals</h2>
          <div class="list-stack">
            <?php foreach ($referrals as $r): ?>
              <div class="list-item">
                <div class="flex-grow-1 min-w-0"><div class="small fw-semibold text-truncate"><?= e((string) $r['name']) ?></div><div class="small text-muted"><?= e((string) $r['member_code']) ?> · <?= e(format_date((string) $r['joined_at'], 'd M Y')) ?></div></div>
                <?= status_badge((string) $r['status']) ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$referrals): ?><p class="small text-muted mb-0">No direct referrals.</p><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="panel p-3 p-md-4 h-100">
          <h2 class="h6 mb-3">Payouts</h2>
          <div class="list-stack">
            <?php foreach ($payouts as $p): ?>
              <div class="list-item">
                <div class="flex-grow-1 min-w-0"><div class="small fw-semibold"><?= e((string) $p['request_code']) ?></div><div class="small text-muted"><?= money((float) $p['net_amount']) ?> · <?= e(format_date((string) $p['created_at'], 'd M Y')) ?></div></div>
                <?= status_badge((string) $p['status']) ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$payouts): ?><p class="small text-muted mb-0">No payout requests.</p><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if (AdminAuth::can('kyc.view')): ?>
      <div class="panel p-3 p-md-4 mt-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0">KYC &amp; bank</h2>
          <a class="small" href="kyc.php?member=<?= (int) $id ?>">Open verification</a>
        </div>
        <?php if ($kyc): ?>
          <div class="small mb-2">PAN: <strong><?= e(Security::maskPan(Security::decrypt((string) $kyc['pan_number']) ?? '')) ?></strong> · Aadhaar: <strong><?= e(Security::maskAadhaar(Security::decrypt((string) $kyc['aadhaar_number']) ?? '')) ?></strong></div>
          <div class="mb-2"><?= status_badge((string) $kyc['status']) ?></div>
        <?php else: ?>
          <p class="small text-muted">No KYC submitted.</p>
        <?php endif; ?>
        <?php if ($banks): ?>
          <?php foreach ($banks as $bank): ?>
            <div class="small border-top py-2">
              <?= e((string) $bank['bank_name']) ?> · <?= e(Security::maskAccount((string) $bank['account_number'])) ?> · <?= e((string) $bank['ifsc_code']) ?> <?= status_badge((string) $bank['status']) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="panel p-3 p-md-4 mt-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h2 class="h6 mb-0">Orders</h2>
          <div class="small text-muted"><?= (int) $orderTotal ?> order(s) placed or referred by this member</div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle data-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Date</th>
              <th class="text-end">Items</th>
              <th class="text-end">Total</th>
              <th>Payment</th>
              <th>Status</th>
              <th class="text-end no-sort">Invoice</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="small"><a href="order-details.php?id=<?= (int) $o['id'] ?>"><?= e((string) $o['order_number']) ?></a></td>
              <td class="small text-nowrap"><?= e(format_date((string) $o['created_at'], 'd M Y')) ?></td>
              <td class="small text-end"><?= (int) $o['item_count'] ?> (<?= (int) $o['total_qty'] ?>)</td>
              <td class="small text-end fw-semibold text-nowrap"><?= money((float) $o['grand_total']) ?></td>
              <td><?= status_badge((string) $o['payment_status']) ?></td>
              <td><?= status_badge((string) $o['status']) ?></td>
              <td class="text-end text-nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-invoice-id="<?= (int) $o['id'] ?>"
                        data-invoice-label="<?= e((string) ($o['invoice_number'] ?: $o['order_number'])) ?> · <?= e((string) $o['order_number']) ?>">
                  <i class="fa-solid fa-file-invoice me-1" aria-hidden="true"></i>Invoice
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="7" class="small text-muted">No orders.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($orderPager->pages > 1): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 server-pager">
          <div class="small text-muted"><?= $orderPager->infoText() ?></div>
          <?= $orderPager->links() ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
