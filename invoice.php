<?php
/** Printable GST style invoice. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Url;

$orderId = Request::int('order');
$order = db()->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
if (!$order || !can_view_order($order)) {
    http_response_code(404);
    exit('Invoice not available.');
}
$items = db()->select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
$shipping = json_decode((string) $order['shipping_address'], true) ?: [];
$taxable = (float) $order['subtotal'];
$taxTotal = (float) $order['tax_total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice <?= e($order['invoice_number'] ?: $order['order_number']) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #263238; font-size: 12px; margin: 0; padding: 24px; background: #fff; }
  .wrap { max-width: 820px; margin: 0 auto; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #247A3C; padding-bottom: 14px; }
  .brand { display: flex; gap: 12px; align-items: center; }
  .brand img { height: 56px; }
  h1 { font-size: 20px; margin: 0; color: #14532D; }
  .muted { color: #5F6B65; }
  .badge { display: inline-block; background: #E8F5E9; color: #14532D; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
  table { width: 100%; border-collapse: collapse; margin-top: 18px; }
  th { background: #E8F5E9; text-align: left; padding: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
  td { padding: 8px; border-bottom: 1px solid #E4EBE5; }
  .right { text-align: right; }
  .totals td { border: none; padding: 4px 8px; }
  .grand { font-size: 15px; font-weight: bold; color: #14532D; border-top: 1px solid #DCE8DE; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 18px; }
  .box { border: 1px solid #DCE8DE; border-radius: 8px; padding: 10px; }
  .foot { margin-top: 26px; font-size: 10px; color: #5F6B65; border-top: 1px solid #DCE8DE; padding-top: 10px; }
  .no-print { margin-bottom: 14px; }
  @media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="no-print">
    <button onclick="window.print()" style="background:#247A3C;color:#fff;border:none;padding:8px 16px;border-radius:20px;cursor:pointer">Print / Save as PDF</button>
    <a href="<?= e(Url::to('my-orders.php')) ?>" style="margin-left:8px;color:#247A3C">Back to orders</a>
  </div>
  <div class="head">
    <div class="brand">
      <img src="<?= e(Url::media(setting('logo_header'))) ?>" alt="<?= e(setting('site_name')) ?>">
      <div>
        <h1><?= e(setting('site_name')) ?></h1>
        <div class="muted"><?= nl2br(e(setting('company_address'))) ?></div>
        <div class="muted">GSTIN: <?= e(setting('company_gst')) ?> · <?= e(setting('contact_phone')) ?></div>
      </div>
    </div>
    <div style="text-align:right">
      <h1>TAX INVOICE</h1>
      <div><strong><?= e($order['invoice_number'] ?: $order['order_number']) ?></strong></div>
      <div class="muted">Date: <?= format_date($order['invoice_date'] ?: $order['created_at']) ?></div>
      <div class="muted">Order: <?= e($order['order_number']) ?></div>
      <div class="badge"><?= e(ucfirst((string) $order['payment_status'])) ?></div>
    </div>
  </div>

  <div class="grid">
    <div class="box">
      <strong>Billed / Shipped to</strong><br>
      <?= e($shipping['full_name'] ?? $order['customer_name']) ?><br>
      <?= e($shipping['address_line1'] ?? '') ?><?= !empty($shipping['address_line2']) ? ', ' . e($shipping['address_line2']) : '' ?><br>
      <?= e($shipping['city'] ?? '') ?>, <?= e($shipping['state'] ?? '') ?> - <?= e($shipping['pincode'] ?? '') ?><br>
      Mobile: <?= e($order['customer_mobile']) ?>
    </div>
    <div class="box">
      <strong>Payment</strong><br>
      Method: <?= e(payment_label($order['payment_method'])) ?><br>
      Status: <?= e(ucfirst((string) $order['payment_status'])) ?><br>
      <?php if ($order['paid_at']): ?>Paid on: <?= format_date($order['paid_at'], 'd M Y h:i A') ?><?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr><th>#</th><th>Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($item['product_name']) ?><?= $item['product_sku'] ? '<br><span class="muted">SKU: ' . e($item['product_sku']) . '</span>' : '' ?></td>
          <td class="right"><?= (int) $item['quantity'] ?></td>
          <td class="right"><?= money($item['unit_price']) ?></td>
          <td class="right"><?= money($item['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="right">Taxable value</td><td class="right" style="width:140px"><?= money($taxable) ?></td></tr>
    <tr><td class="right">Tax</td><td class="right"><?= money($taxTotal) ?></td></tr>
    <tr><td class="right">Shipping</td><td class="right"><?= money($order['shipping_total']) ?></td></tr>
    <?php if ((float) $order['discount_total'] > 0): ?><tr><td class="right">Discount</td><td class="right">- <?= money($order['discount_total']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td class="right">Grand total</td><td class="right"><?= money($order['grand_total']) ?></td></tr>
  </table>
  <p><strong>Amount in words:</strong> <?= e(amount_in_words((float) $order['grand_total'])) ?></p>

  <div class="foot">
    This is a computer generated invoice. Goods once sold are subject to our refund and return policy.
    Agro-chemical products must be stored as per label instructions. For support write to <?= e(setting('contact_email')) ?>.
  </div>
</div>
</body>
</html>
