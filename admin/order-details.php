<?php
/** Order detail: items, payments, status timeline, refunds and commission reversal. */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('orders.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;

$db = db();
$id = Request::int('id');
$order = $db->first('SELECT * FROM orders WHERE id = :id', ['id' => $id]);
if (!$order) {
    Session::error('Order not found.');
    Request::redirect('orders.php');
}

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');
    $service = new \Saee\Lib\OrderService($db);

    if ($action === 'status' && AdminAuth::can('orders.update')) {
        $result = $service->updateStatus($id, (string) Request::input('status', ''), (string) Request::input('note', ''), 'admin', (int) $adminUser['id']);
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    } elseif ($action === 'mark-paid' && AdminAuth::can('orders.update')) {
        $result = $service->markPaid($id, ['gateway' => (string) $order['payment_method'], 'payment_id' => 'manual-' . time(), 'note' => 'Marked paid by admin']);
        Session::{$result['ok'] ? 'success' : 'error'}($result['message']);
    } elseif ($action === 'tracking' && AdminAuth::can('orders.update')) {
        $db->update('orders', [
            'tracking_number' => (string) Request::input('tracking_number', ''),
            'carrier' => (string) Request::input('carrier', ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        \Saee\Lib\Audit::log('update', 'orders', 'Tracking updated for ' . $order['order_number'], 'orders', $id);
        Session::success('Tracking details saved.');
    } elseif ($action === 'reverse' && AdminAuth::can('mlm.reverse')) {
        $reason = (string) Request::input('reason', 'Manual reversal by admin');
        $result = (new \Saee\Lib\MlmCommissionEngine($db))->reverseOrder($id, $reason);
        Session::{$result['reversed'] > 0 ? 'success' : 'error'}($result['reversed'] > 0
            ? $result['reversed'] . ' commission entr(ies) reversed (' . money((float) $result['total']) . ').'
            : 'No reversible commission entries were found.');
    }
    Request::redirect('order-details.php?id=' . $id);
}

// Order lines with catalogue fallbacks, normalised so every display key exists whatever the
// local database columns look like. When includes/functions.php predates order_items_for(),
// a compact inline normaliser takes over: this page must never fatal or warn even on a
// half-updated deployment.
if (function_exists('order_items_for')) {
    $items = order_items_for($id);
} else {
    if (!function_exists('normalize_order_item')) {
        // Compact copy of the normaliser in includes/functions.php - kept in sync with it.
        function normalize_order_item(array $row): array
        {
            $pick = static function (array $r, array $names) {
                foreach ($names as $n) {
                    if (isset($r[$n]) && $r[$n] !== '') {
                        return $r[$n];
                    }
                }
                foreach ($r as $k => $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    foreach ($names as $n) {
                        if (strcasecmp((string) $k, $n) === 0) {
                            return $v;
                        }
                    }
                }
                return null;
            };

            $skip = ['product_sku', 'sku', 'pack_size', 'product_image', 'product_slug', 'product_id', 'variant_id', 'order_id', 'commissionable_amount'];

            $name = $pick($row, ['product_name', 'product_title', 'title', 'name', 'item_name']);
            if ($name === null || $name === '') {
                foreach ($row as $key => $value) {
                    $k = strtolower((string) $key);
                    if ($k === '' || $k[0] === '_' || in_array($k, $skip, true)) {
                        continue;
                    }
                    if (!is_string($value) || trim($value) === '' || strlen($value) > 191 || is_numeric($value)) {
                        continue;
                    }
                    if (preg_match('/name|title|item|product|desc/i', $k)) {
                        $name = $value;
                        break;
                    }
                }
            }
            if ($name === null || $name === '') {
                $name = $row['_fb2_product_name'] ?? '';
            }
            if ($name === null || $name === '') {
                $name = $row['_fb_product_name'] ?? '';
            }
            if ($name === null || $name === '') {
                $pid = (int) ($pick($row, ['product_id']) ?? 0);
                $name = $pid > 0 ? 'Product #' . $pid : 'Untitled item';
            }
            $row['product_name'] = (string) $name;

            $row['product_sku'] = (string) ($pick($row, ['product_sku', 'sku']) ?? ($row['product_sku'] ?? ''));

            $price = $pick($row, ['unit_price', 'price', 'mrp']);
            if ($price === null) {
                foreach ($row as $key => $value) {
                    $k = strtolower((string) $key);
                    if ($k === '' || $k[0] === '_' || !is_numeric($value)) {
                        continue;
                    }
                    if (preg_match('/price|mrp|rate|cost/i', $k) && !preg_match('/tax|commission|shipping/i', $k)) {
                        $price = $value;
                        break;
                    }
                }
            }
            $row['unit_price'] = (float) ($price ?? ($row['_fb_product_price'] ?? 0));

            $qty = $pick($row, ['quantity', 'qty', 'pcs']);
            if ($qty === null) {
                foreach ($row as $key => $value) {
                    $k = strtolower((string) $key);
                    if ($k === '' || $k[0] === '_' || !is_numeric($value)) {
                        continue;
                    }
                    if (preg_match('/qty|quantity|pcs|count/i', $k) && !preg_match('/country|discount/i', $k)) {
                        $qty = $value;
                        break;
                    }
                }
            }
            $row['quantity'] = (int) ($qty ?? 1);

            $line = $pick($row, ['line_total', 'total', 'net_amount']);
            if ($line === null) {
                foreach ($row as $key => $value) {
                    $k = strtolower((string) $key);
                    if ($k === '' || $k[0] === '_' || !is_numeric($value)) {
                        continue;
                    }
                    if (preg_match('/total|amount/i', $k) && !preg_match('/tax|commission|grand|payable|paid/i', $k)) {
                        $line = $value;
                        break;
                    }
                }
            }
            $row['line_total'] = $line !== null && $line !== ''
                ? (float) $line
                : round($row['unit_price'] * $row['quantity'], 2);

            return $row;
        }
    }
    $ctName = $db->hasColumn('commission_transactions', 'product_name')
        && $db->hasColumn('commission_transactions', 'order_item_id')
        ? ", (SELECT ct.product_name FROM commission_transactions ct
               WHERE ct.order_item_id = i.id AND ct.product_name IS NOT NULL AND ct.product_name <> ''
               LIMIT 1) AS _fb2_product_name"
        : '';
    $items = $db->select(
        'SELECT i.*, p.name AS _fb_product_name, p.price AS _fb_product_price' . $ctName . '
           FROM order_items i LEFT JOIN products p ON p.id = i.product_id
          WHERE i.order_id = :o ORDER BY i.id ASC',
        ['o' => $id]
    );
    foreach ($items as &$itemRow) {
        $itemRow = normalize_order_item($itemRow);
    }
    unset($itemRow);
}
$payments = $db->select('SELECT * FROM order_payments WHERE order_id = :o ORDER BY id DESC', ['o' => $id]);

// Line-item diagnostics: open as order-details.php?id=N&debug=1 and share the panel when
// products display wrongly - it shows the raw rows exactly as this database returns them.
$debugItems = null;
$debugProducts = [];
if (Request::query('debug') !== null) {
    $debugItems = $db->select('SELECT * FROM order_items WHERE order_id = :o ORDER BY id ASC', ['o' => $id]);
    foreach ($debugItems as $debugRow) {
        $debugPid = (int) ($debugRow['product_id'] ?? 0);
        if ($debugPid > 0 && !array_key_exists($debugPid, $debugProducts)) {
            try {
                $debugProducts[$debugPid] = $db->first('SELECT * FROM products WHERE id = :id', ['id' => $debugPid]);
            } catch (\Throwable $e) {
                $debugProducts[$debugPid] = ['error' => $e->getMessage()];
            }
        }
    }
}
$history = $db->select('SELECT * FROM order_status_history WHERE order_id = :o ORDER BY id DESC', ['o' => $id]);
$commissions = $db->select('SELECT c.*, m.member_code, m.name FROM commission_transactions c LEFT JOIN members m ON m.id = c.member_id WHERE c.order_id = :o ORDER BY c.id ASC', ['o' => $id]);

// Future commission: what this order will pay once it is approved or delivered. It comes from
// MlmCommissionEngine::projectOrder(), which runs the very same plan processOrder() credits, so
// the figure shown here is the figure that will actually land - not a separate estimate that
// could drift from the real calculation.
$futureCommission = (new \Saee\Lib\MlmCommissionEngine($db))->projectOrder($id);

$pageTitle = 'Order ' . $order['order_number'];
$pageHeading = 'Order ' . $order['order_number'];
$pageSub = format_date((string) $order['created_at'], 'd M Y H:i') . ' · ' . money((float) $order['grand_total']);
$activeModule = 'orders';
$pageActions = '<a class="btn btn-sm btn-outline-secondary" href="orders.php">Back to orders</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <?php if ($debugItems !== null): ?>
    <div class="panel p-3 p-md-4 mb-3 border border-warning">
      <h2 class="h6 mb-2">Order line diagnostics (debug)</h2>
      <p class="small text-muted mb-2">
        The raw rows exactly as this database returned them, before any normalisation,
        plus what the page resolved them to. Share this panel when reporting product display problems.
      </p>
      <pre class="small bg-light p-2" style="max-height:420px;overflow:auto"><?= e((string) json_encode([
          'order_items_columns' => $debugItems !== [] ? array_keys($debugItems[0]) : [],
          'raw_rows' => $debugItems,
          'normalized_rows' => $items,
          'product_lookup' => $debugProducts,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) ?></pre>
    </div>
    <?php endif; ?>
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Items</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 data-table">
          <thead><tr><th>Product</th><th class="text-end">Price</th><th>Qty</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td class="small"><?= e((string) ($item['product_name'] ?? '')) ?><div class="text-muted"><?= e((string) ($item['product_sku'] ?? '')) ?> <?= e((string) ($item['pack_size'] ?? '')) ?></div></td>
              <td class="small text-end"><?= money((float) ($item['unit_price'] ?? 0)) ?></td>
              <td class="small"><?= (int) ($item['quantity'] ?? 0) ?></td>
              <td class="small text-end fw-semibold"><?= money((float) ($item['line_total'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?>
            <tr><td colspan="4" class="small text-muted">
              No line items were found for this order. If it should have products,
              reopen it with &amp;debug=1 and share the diagnostics panel above.
            </td></tr>
          <?php endif; ?>
          </tbody>
          <tfoot class="small">
            <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end"><?= money((float) $order['subtotal']) ?></td></tr>
            <tr><td colspan="3" class="text-end">Discount</td><td class="text-end">−<?= money((float) $order['discount_total']) ?></td></tr>
            <tr><td colspan="3" class="text-end">Shipping</td><td class="text-end"><?= money((float) $order['shipping_total']) ?></td></tr>
            <tr><td colspan="3" class="text-end">Tax</td><td class="text-end"><?= money((float) $order['tax_total']) ?></td></tr>
            <tr class="fw-bold"><td colspan="3" class="text-end">Grand total</td><td class="text-end"><?= money((float) $order['grand_total']) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Commission on this order</h2>
      <?php if (!$commissions): ?>
        <?php if (!empty($futureCommission['future']) && ($futureCommission['total'] ?? 0) > 0): ?>
          <div class="alert alert-info small py-2 mb-2">
            <i class="fa-solid fa-clock me-1"></i>
            <strong>Future commission &mdash; <?= money((float) $futureCommission['total']) ?></strong><br>
            <?= e((string) $futureCommission['reason']) ?> Nothing is credited until then.
          </div>
          <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle data-table">
              <thead><tr><th>Will be paid to</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($futureCommission['entries'] as $fcRow): ?>
                <tr class="table-light">
                  <td class="small"><?= e((string) $fcRow['beneficiary_name']) ?><div class="text-muted"><?= e((string) $fcRow['beneficiary_code']) ?></div></td>
                  <td class="small"><?= e(ucwords(str_replace('_', ' ', (string) $fcRow['type']))) ?></td>
                  <td class="small text-end"><?= money((float) $fcRow['amount']) ?></td>
                  <td><span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Future</span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="small text-muted mb-0">
            No commission applies to this order. Commission is calculated from each product's own
            MLM commission settings as soon as the purchase is recorded, so an empty table here
            means these products carry no MLM commission, or the buyer has no sponsor above them.
            <?php if (!empty($futureCommission['reason'])): ?>
              <br><span class="fst-italic"><?= e((string) $futureCommission['reason']) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-3 align-middle data-table">
            <thead><tr><th>Beneficiary</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($commissions as $c): ?>
              <tr>
                <td class="small"><?= e((string) ($c['name'] ?? '—')) ?><div class="text-muted"><?= e((string) ($c['member_code'] ?? '')) ?></div></td>
                <td class="small"><?= e(ucwords(str_replace('_', ' ', (string) $c['commission_type']))) ?></td>
                <td class="small text-end"><?= money((float) $c['amount']) ?></td>
                <td><?= status_badge((string) $c['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (AdminAuth::can('mlm.reverse')): ?>
          <form method="post" class="d-flex gap-2" data-confirm="Reverse all commission for this order? Ledger entries are preserved.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reverse">
            <input class="form-control form-control-sm" name="reason" placeholder="Reason for reversal" required>
            <button class="btn btn-sm btn-outline-danger">Reverse</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Status history</h2>
      <div class="list-stack">
        <?php foreach ($history as $h): ?>
          <div class="list-item">
            <div class="list-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="flex-grow-1"><div class="small fw-semibold"><?= e(order_status_label((string) $h['status'])) ?></div><div class="small text-muted"><?= e((string) ($h['note'] ?? '')) ?></div></div>
            <div class="small text-muted"><?= e(format_date((string) $h['created_at'], 'd M Y H:i')) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$history): ?><p class="small text-muted mb-0">No history entries.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel p-3 p-md-4 mb-3">
      <h2 class="h6 mb-3">Customer</h2>
      <div class="small">
        <div class="fw-semibold"><?= e((string) $order['customer_name']) ?></div>
        <div class="text-muted"><?= e((string) $order['customer_mobile']) ?></div>
        <div class="text-muted"><?= e((string) ($order['customer_email'] ?? '')) ?></div>
        <hr>
        <div class="text-muted mb-1">Shipping address</div>
        <?= nl2br(e((string) $order['shipping_address'])) ?>
      </div>
      <div class="mt-3 d-flex gap-2"><?= status_badge((string) $order['status']) ?> <?= status_badge((string) $order['payment_status']) ?></div>
      <div class="small text-muted mt-2">Payment: <?= e(payment_label((string) $order['payment_method'])) ?></div>
    </div>

    <?php if (AdminAuth::can('orders.update')): ?>
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3">Update status</h2>
        <?php
          $currentStatus = \Saee\Lib\OrderService::normalizeStatus($order['status']);
          // Offer only the moves OrderService will actually accept. The dropdown used to list every
          // status, so picking "Delivered" on a new order was refused by the transition table and
          // the order quietly stayed new. Cancel is left out of the dropdown; it has its own button.
          $allowedMoves = \Saee\Lib\OrderService::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
          $statusChoices = array_values(array_filter(
              $allowedMoves,
              static fn (string $candidate): bool => $candidate !== 'cancelled'
          ));
        ?>
        <?php if ($statusChoices === []): ?>
          <p class="small text-muted mb-0">
            This order is <strong><?= e(ORDER_STATUSES[$currentStatus] ?? $currentStatus) ?></strong>,
            which is final — it can no longer be moved.
          </p>
        <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <div class="mb-2">
            <select class="form-select form-select-sm" name="status">
              <?php foreach ($statusChoices as $key): ?>
                <option value="<?= e($key) ?>"><?= e(ORDER_STATUSES[$key]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-text mb-2">
            <?php if (in_array('delivered', $statusChoices, true)): ?>
              Marking the order delivered also records its payment as paid.
            <?php endif; ?>
          </div>
          <div class="mb-2"><input class="form-control form-control-sm" name="note" placeholder="Note (optional)"></div>
          <button class="btn btn-sm btn-primary w-100">Update status</button>
        </form>
        <?php endif; ?>
        <?php if (in_array('cancelled', $allowedMoves, true)): ?>
          <form method="post" class="mt-2" data-confirm="Cancel this order? Any commission already generated is reversed.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="status" value="cancelled">
            <input type="hidden" name="note" value="Cancelled by admin">
            <button class="btn btn-sm btn-outline-danger w-100">Cancel order</button>
          </form>
        <?php endif; ?>
        <?php if ($order['payment_status'] !== 'paid'): ?>
          <form method="post" class="mt-2" data-confirm="Record that payment for this order has been received?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark-paid">
            <button class="btn btn-sm btn-outline-success w-100">Mark as paid</button>
          </form>
        <?php endif; ?>
        <hr>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="tracking">
          <div class="mb-2"><input class="form-control form-control-sm" name="carrier" value="<?= e((string) ($order['carrier'] ?? '')) ?>" placeholder="Courier"></div>
          <div class="mb-2"><input class="form-control form-control-sm" name="tracking_number" value="<?= e((string) ($order['tracking_number'] ?? '')) ?>" placeholder="Tracking number"></div>
          <button class="btn btn-sm btn-outline-secondary w-100">Save tracking</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="panel p-3 p-md-4">
      <h2 class="h6 mb-3">Payments</h2>
      <?php foreach ($payments as $p): ?>
        <div class="list-item mb-2">
          <div class="flex-grow-1 min-w-0">
            <div class="small fw-semibold"><?= e(ucfirst((string) $p['gateway'])) ?> · <?= money((float) $p['amount']) ?></div>
            <div class="small text-muted text-truncate"><?= e((string) ($p['gateway_payment_id'] ?? 'no reference')) ?></div>
          </div>
          <?= status_badge((string) $p['status']) ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$payments): ?><p class="small text-muted mb-0">No payment records.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
