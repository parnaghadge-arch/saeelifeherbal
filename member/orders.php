<?php
/**
 * Purchase History (member side).
 *
 * A summary list only: order number, date, status and total. The full line items, address,
 * payment trail and invoice live on the order's own page (member/order-details.php), opened by
 * "View details". Dumping every product of every order onto one screen is what made this page
 * impossible to scan.
 *
 * Shows two kinds of order, because a partner has a stake in both:
 *   - their own purchases (member_id), and
 *   - orders they placed on behalf of a teammate (created_by_member_id).
 * The beneficiary's name is printed on assisted rows so a partner can tell whose purchase a row
 * is. Amounts a partner never controls, such as another member's wallet, stay off this page.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

use Saee\Lib\OrderService;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Url;

// 'all' is the default so a partner opening the page sees everything they are involved in.
$view = (string) Request::input('view', 'all');
if (!in_array($view, ['all', 'mine', 'team'], true)) {
    $view = 'all';
}
// Own purchases are matched by beneficiary; assisted purchases by the operator who placed them.
$whereMine = '(o.member_id = :m OR o.referral_member_id = :m)';
$whereTeam = '(o.created_by_member_id = :m AND o.member_id <> :m)';
switch ($view) {
    case 'mine': $where = $whereMine; break;
    case 'team': $where = $whereTeam; break;
    default:     $where = '(' . $whereMine . ' OR ' . $whereTeam . ')';
}
$hasCreatedBy = db()->hasColumn('orders', 'created_by_member_id');
if (!$hasCreatedBy) {
    // Older database that has not been upgraded yet: fall back to own purchases rather than
    // erroring on an unknown column.
    $where = $view === 'team' ? '1 = 0' : $whereMine;
}
$orderParams = ['m' => $currentMemberId];

$total = (int) db()->value('SELECT COUNT(*) FROM orders o WHERE ' . $where, $orderParams);
$pager = new Pager($total, 10);
$orders = db()->select(
    'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity),0) FROM order_items i WHERE i.order_id = o.id) AS total_qty,
            bm.name AS beneficiary_name, bm.member_code AS beneficiary_code
     FROM orders o
     LEFT JOIN members bm ON bm.id = o.member_id
     WHERE ' . $where . '
     ORDER BY o.id DESC' . $pager->limitSql(),
    $orderParams
);

// How many rows sit in each bucket, so the tabs can show their counts.
$viewCounts = ['all' => 0, 'mine' => 0, 'team' => 0];
if ($hasCreatedBy) {
    $counts = db()->first(
        'SELECT
            SUM(CASE WHEN ' . $whereMine . ' OR ' . $whereTeam . ' THEN 1 ELSE 0 END) AS all_count,
            SUM(CASE WHEN ' . $whereMine . ' THEN 1 ELSE 0 END) AS mine_count,
            SUM(CASE WHEN ' . $whereTeam . ' THEN 1 ELSE 0 END) AS team_count
         FROM orders o',
        $orderParams
    ) ?? [];
    $viewCounts = [
        'all' => (int) ($counts['all_count'] ?? 0),
        'mine' => (int) ($counts['mine_count'] ?? 0),
        'team' => (int) ($counts['team_count'] ?? 0),
    ];
} else {
    $own = (int) db()->value('SELECT COUNT(*) FROM orders o WHERE ' . $whereMine, $orderParams);
    $viewCounts = ['all' => $own, 'mine' => $own, 'team' => 0];
}

// One thumbnail per order for the summary row — the first line item's product image.
$thumbsByOrder = [];
if ($orders !== []) {
    $params = [];
    $named = [];
    foreach ($orders as $i => $o) {
        $key = 'oid' . $i;
        $named[] = ':' . $key;
        $params[$key] = (int) $o['id'];
    }
    $rows = db()->select(
        'SELECT i.order_id, p.featured_image AS product_image
         FROM order_items i LEFT JOIN products p ON p.id = i.product_id
         WHERE i.order_id IN (' . implode(',', $named) . ')
         ORDER BY i.order_id, i.id',
        $params
    );
    foreach ($rows as $row) {
        $oid = (int) $row['order_id'];
        if (!isset($thumbsByOrder[$oid])) {
            $thumbsByOrder[$oid] = (string) ($row['product_image'] ?? '');
        }
    }
}

$summary = db()->first(
    "SELECT COUNT(*) AS order_count,
            COALESCE(SUM(o.grand_total),0) AS total_spent,
            COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.grand_total ELSE 0 END),0) AS paid_spent,
            COALESCE(SUM(CASE WHEN o.created_by_member_id = :m AND o.member_id <> :m THEN 1 ELSE 0 END),0) AS assisted_count
     FROM orders o WHERE " . ($hasCreatedBy ? '(' . $whereMine . ' OR ' . $whereTeam . ')' : $whereMine),
    $orderParams
) ?? [];

$pageTitle = 'Purchase History';
$pageHeading = 'Purchase History';
$pageSub = 'Your own purchases and the orders you placed for your team';
$activeNav = 'orders';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total orders</span><strong><?= (int) ($summary['order_count'] ?? 0) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Total spent</span><strong><?= money((float) ($summary['total_spent'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Paid orders</span><strong><?= money((float) ($summary['paid_spent'] ?? 0)) ?></strong></div></div>
  <div class="col-6 col-lg-3"><div class="mini-stat"><span>Placed for your team</span><strong><?= (int) ($summary['assisted_count'] ?? 0) ?></strong><small>Orders you entered for teammates</small></div></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Filter orders">
  <?php
  $orderViews = [
      'all' => ['All orders', 'fa-layer-group'],
      'mine' => ['My purchases', 'fa-user'],
      'team' => ['Placed for team', 'fa-people-group'],
  ];
  foreach ($orderViews as $viewKey => [$viewLabel, $viewIcon]): ?>
    <a class="btn btn-sm <?= $view === $viewKey ? 'btn-primary' : 'btn-light' ?>"
       href="<?= e(Url::member('orders.php') . ($viewKey === 'all' ? '' : '?view=' . $viewKey)) ?>">
      <i class="fa-solid <?= e($viewIcon) ?> me-1" aria-hidden="true"></i><?= e($viewLabel) ?>
      <span class="badge bg-white text-dark ms-1"><?= (int) $viewCounts[$viewKey] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$orders): ?>
<div class="panel p-3 p-md-4 text-center">
  <div class="feature-icon mx-auto mb-3"><i class="fa-solid fa-bag-shopping"></i></div>
  <h2 class="h6">No purchases yet</h2>
  <p class="small text-muted mb-3">Products you buy with your member ID, and orders you place for your team, will appear here with full details and invoices.</p>
  <a class="btn btn-primary btn-sm" href="<?= e(Url::to('our-products')) ?>"><i class="fa-solid fa-seedling me-1"></i>Browse products</a>
</div>
<?php else: ?>
<div class="panel p-3 p-md-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table purchase-summary">
      <thead>
        <tr>
          <th>Order</th>
          <th data-hide="sm">Placed on</th>
          <th>Status</th>
          <th data-hide="sm">Payment</th>
          <th class="text-end">Total</th>
          <th class="text-end">Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $order):
          $thumb = $thumbsByOrder[(int) $order['id']] ?? '';
          $detailUrl = 'order-details.php?id=' . (int) $order['id'];
      ?>
        <tr>
          <td data-label="Order">
            <div class="d-flex align-items-center gap-2">
              <?php if ($thumb !== ''): ?>
                <img class="purchase-thumb-sm" src="<?= e(Url::media($thumb)) ?>" alt="" loading="lazy" width="40" height="40">
              <?php else: ?>
                <span class="purchase-thumb-sm purchase-thumb-sm--fallback"><i class="fa-solid fa-seedling"></i></span>
              <?php endif; ?>
              <div>
                <a class="fw-semibold text-decoration-none" href="<?= e($detailUrl) ?>"><?= e((string) $order['order_number']) ?></a>
                <div class="small text-muted"><?= (int) $order['item_count'] ?> item(s) · <?= (int) $order['total_qty'] ?> unit(s)</div>
                <?php $isAssisted = (int) ($order['created_by_member_id'] ?? 0) === $currentMemberId && (int) $order['member_id'] !== $currentMemberId; ?>
                <?php if ($isAssisted): ?>
                  <span class="badge bg-light-green text-dark mt-1" title="You placed this order for a teammate">
                    <i class="fa-solid fa-people-group me-1" aria-hidden="true"></i>For <?= e((string) ($order['beneficiary_code'] ?: ($order['beneficiary_name'] ?? 'teammate'))) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td data-label="Placed on" class="small"><?= e(format_date((string) $order['created_at'], 'd M Y, h:i A')) ?></td>
          <td data-label="Status"><?= status_badge(OrderService::normalizeStatus($order['status'])) ?></td>
          <td data-label="Payment" class="small">
            <?= status_badge((string) $order['payment_status']) ?>
            <div class="small text-muted"><?= e(payment_label((string) $order['payment_method'])) ?></div>
          </td>
          <td data-label="Total" class="text-end fw-semibold"><?= money((float) $order['grand_total']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="<?= e($detailUrl) ?>">
              <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>View details
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
  <div class="small text-muted"><?= $pager->infoText() ?></div>
  <?= $pager->links($view === 'all' ? [] : ['view' => $view]) ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
