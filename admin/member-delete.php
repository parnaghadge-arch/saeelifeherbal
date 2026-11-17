<?php
/**
 * Delete a member: first show every record the deletion will delete or alter (orders,
 * commissions, wallet, payouts, team moves, sponsor recalculation), then cascade the
 * deletion and rebuild the sponsor and grand sponsor commission totals.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('members.manage');

use Saee\Lib\MemberService;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$db = db();
$id = Request::int('id');
$service = new MemberService($db);
$member = $id ? $db->first('SELECT * FROM members WHERE id = :id', ['id' => $id]) : null;
if (!$member) {
    Session::error('Member not found.');
    Request::redirect('members.php');
}

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    if ($action === 'delete') {
        $reason = trim((string) Request::input('reason', ''));
        if ($reason === '') {
            Session::error('A reason is required for deletions.');
        } elseif (Request::input('confirm') !== '1') {
            Session::error('Please confirm that you understand this deletion cannot be undone.');
        } else {
            $result = $service->deleteMember((int) $member['id'], $reason);
            Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
            if ($result['ok']) {
                Request::redirect('members.php');
            }
        }
        Request::redirect('member-delete.php?id=' . (int) $member['id']);
    }
    Request::redirect('member-details.php?id=' . (int) $member['id']);
}

$preview = $service->deletePreview((int) $member['id']);
$own = $preview['own'];
$orders = $preview['orders'];
$team = $preview['team'];
$customer = $preview['customer'];
$misc = $preview['misc'];
$alterations = $preview['alterations'];

$pageTitle = 'Delete ' . $member['name'];
$pageHeading = 'Delete member: ' . $member['name'];
$pageSub = $member['member_code'] . ' — review everything this deletion will change, then confirm.';
$activeModule = 'members';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="member-details.php?id=' . (int) $member['id'] . '">Cancel, back to member</a>';
require __DIR__ . '/includes/layout.php';

/** One line of a "what goes away" list. */
$row = static function (string $label, string $value): void {
    if ($value === '' || $value === '0' || $value === 'No') {
        return;
    }
    echo '<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">' . e($label) . '</span><strong>' . e($value) . '</strong></div>';
};
?>
<div class="alert alert-danger">
  <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
  <strong>This cannot be undone.</strong> Deleting <strong><?= e((string) $member['name']) ?> (<?= e((string) $member['member_code']) ?>)</strong>
  permanently removes the records listed below. Please review the full impact before confirming.
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3"><i class="fa-solid fa-trash me-2 text-danger" aria-hidden="true"></i>Data that will be DELETED</h2>

      <div class="small fw-semibold text-uppercase text-muted mb-1">Member records</div>
      <?php
      $row('Profile (address, crops)', $own['profile'] ? '1 row' : '');
      $row('KYC record', $own['kyc'] ? '1 row' : '');
      $row('Bank accounts', $own['bank_accounts'] ? $own['bank_accounts'] . ' row(s)' : '');
      $row('Genealogy / sponsor edges', $own['sponsor_edges'] ? $own['sponsor_edges'] . ' row(s)' : '');
      $row('Referral records', $own['referral_records'] ? $own['referral_records'] . ' row(s)' : '');
      $row('Sponsor change history', $own['sponsor_history'] ? $own['sponsor_history'] . ' row(s)' : '');
      $row('Referral link clicks', $own['referral_clicks'] ? $own['referral_clicks'] . ' row(s)' : '');
      ?>

      <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">Money records</div>
      <?php
      $row('Wallet transactions', $own['wallet_transactions'] ? $own['wallet_transactions'] . ' row(s)' : '');
      $row('Wallet balance (removed)', $own['wallet_balance'] > 0 ? money((float) $own['wallet_balance']) : '');
      $row('Lifetime earnings (removed)', $own['wallet_earned'] > 0 ? money((float) $own['wallet_earned']) : '');
      $row('Commission entries earned', $own['commissions'] ? $own['commissions'] . ' (' . money((float) $own['commissions_total']) . ')' : '');
      $row('Payout requests', $own['payouts'] ? $own['payouts'] . ' (' . money((float) $own['payouts_requested']) . ')' : '');
      $row('Already paid to this member', $own['payouts_paid'] > 0 ? money((float) $own['payouts_paid']) : '');
      $row('Royalty achievements', $own['royalty'] ? $own['royalty'] . ' row(s)' : '');
      if ($own['payouts_open'] > 0) {
          echo '<div class="alert alert-warning small py-2 mt-2 mb-0"><i class="fa-solid fa-clock me-1"></i><strong>' . (int) $own['payouts_open'] . ' payout request(s) are still open</strong> (' . money((float) $own['payouts_requested'] - (float) $own['payouts_paid']) . ' requested and not yet paid). They are deleted with the member.</div>';
      }
      if ($own['payouts_paid'] > 0) {
          echo '<div class="alert alert-warning small py-2 mt-2 mb-0"><i class="fa-solid fa-money-bill-transfer me-1"></i><strong>' . money((float) $own['payouts_paid']) . ' was already paid out</strong> to this member. If it came from commission that is now being removed, the sponsor wallets may show a negative drift after recalculation.</div>';
      }
      ?>

      <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">Orders and their commissions</div>
      <?php
      $row('Orders (placed by / attributed to)', $orders['count'] ? $orders['count'] . ' order(s) · ' . money((float) $orders['grand_total']) : '');
      $row('Order line items', $orders['items'] ? $orders['items'] . ' row(s)' : '');
      $row('Commissions paid from those orders', $orders['commissions'] ? $orders['commissions'] . ' (' . money((float) $orders['commissions_total']) . ')' : '');
      if ($orders['count'] > 0) {
          echo '<div class="alert alert-warning small py-2 mt-2 mb-0"><i class="fa-solid fa-box-open me-1"></i>These orders are permanently deleted together with their items, payments and status history.</div>';
      }
      ?>

      <?php if ($customer): ?>
        <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">Linked customer login</div>
        <?php
        $row('Customer account', e((string) $customer['row']['name']) . ' · ' . e((string) $customer['row']['mobile']));
        $row('Saved addresses', $customer['addresses'] ? $customer['addresses'] . ' row(s)' : '');
        $row('Cart / wishlist rows', ((int) $customer['carts'] + (int) $customer['wishlist']) ? ((int) $customer['carts'] + (int) $customer['wishlist']) . ' row(s)' : '');
        ?>
      <?php endif; ?>

      <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">System rows</div>
      <?php
      $row('Notifications', $misc['notifications'] ? $misc['notifications'] . ' row(s)' : '');
      $row('Login throttle records', $misc['login_attempts'] ? $misc['login_attempts'] . ' row(s)' : '');
      ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3"><i class="fa-solid fa-pen-to-square me-2 text-warning" aria-hidden="true"></i>Data that will be ALTERED</h2>

      <div class="small fw-semibold text-uppercase text-muted mb-1">Team move</div>
      <?php if ($team['count'] > 0): ?>
        <p class="small mb-2">
          <strong><?= (int) $team['count'] ?> direct team member(s)</strong> move up to
          <strong><?= $preview['target'] ? e((string) $preview['target']['name']) . ' (' . e((string) $preview['target']['member_code']) . ')' : 'company placement (no sponsor)' ?></strong>.
          The tree stays intact and no other member loses their account.
        </p>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($team['sample'] as $child): ?>
            <li class="d-flex justify-content-between py-1 border-bottom">
              <span><?= e((string) $child['name']) ?></span>
              <span class="text-muted"><?= e((string) $child['member_code']) ?> · <?= e((string) $child['status']) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if ($team['count'] > count($team['sample'])): ?>
            <li class="text-muted py-1">…and <?= (int) ($team['count'] - count($team['sample'])) ?> more</li>
          <?php endif; ?>
        </ul>
      <?php else: ?>
        <p class="small text-muted mb-0">No team members — nobody moves.</p>
      <?php endif; ?>

      <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">Cleared references</div>
      <?php
      $row('Customers losing "referred by"', $alterations['clear_referred_by'] ? $alterations['clear_referred_by'] . ' row(s)' : '');
      $row('Sponsor history pointers cleared', $alterations['clear_history_refs'] ? $alterations['clear_history_refs'] . ' row(s)' : '');
      ?>
    </div>

    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3"><i class="fa-solid fa-calculator me-2 text-primary" aria-hidden="true"></i>Commission recalculation after deletion</h2>
      <p class="small text-muted">Every purchase pays commission to exactly two people: the sponsor (direct) and the grand sponsor (grand direct). Removing this member's orders removes those earnings, so both wallets are recalculated from the remaining ledger.</p>
      <?php if ($preview['recalculate'] === []): ?>
        <p class="small text-muted mb-0">No sponsor or grand sponsor — nothing to recalculate.</p>
      <?php else: ?>
        <?php foreach ($preview['recalculate'] as $recalc): ?>
          <div class="border rounded-3 p-3 mb-2 small">
            <div class="d-flex justify-content-between">
              <strong><?= e($recalc['role']) ?>: <?= e((string) $recalc['member']['name']) ?> (<?= e((string) $recalc['member']['member_code']) ?>)</strong>
              <span class="text-danger">− <?= money((float) $recalc['lost_total']) ?></span>
            </div>
            <div class="text-muted">
              <?= (int) $recalc['lost_count'] ?> commission entrie(s) from this member's purchases are removed.<br>
              Current wallet: <strong><?= money((float) $recalc['available_balance']) ?></strong> available · <?= money((float) $recalc['total_earned']) ?> lifetime earned → totals recalculated after deletion.
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="panel p-3 p-md-4 border border-danger">
      <h2 class="h6 mb-3 text-danger"><i class="fa-solid fa-skull-crossbones me-2" aria-hidden="true"></i>Confirm deletion</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <div class="mb-3">
          <label class="form-label" for="reason">Reason for deletion (kept in the audit log) *</label>
          <textarea id="reason" name="reason" class="form-control" rows="2" required placeholder="e.g. duplicate registration by the same person"><?= e((string) Request::input('reason', '')) ?></textarea>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="confirm" id="confirm" value="1">
          <label class="form-check-label small" for="confirm">I have reviewed the data above and understand this deletion cannot be undone.</label>
        </div>
        <button type="submit" class="btn btn-danger w-100">
          <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete <?= e((string) $member['member_code']) ?> permanently
        </button>
      </form>
    </div>
  </div>
</div>
