<?php
/**
 * Tax invoice — printable, downloadable and embeddable.
 *
 * Three modes, all from the same markup so what you print is what you saw:
 *   invoice.php?order=N              standalone page with its own toolbar
 *   invoice.php?order=N&embed=1      chrome-free, for the in-page popup (iframe)
 *   invoice.php?order=N&download=1   same document served as a file download
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Request;
use Saee\Lib\Url;

$orderId = Request::int('order');
$embed = Request::int('embed') === 1;
$download = Request::int('download') === 1;

$order = db()->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
if (!$order || !can_view_order($order)) {
    http_response_code(404);
    exit('Invoice not available.');
}
// HSN/SAC is what a GST invoice wants in that column, but the catalogue does not carry
// it yet — so join it only where the column exists and fall back to the SKU otherwise.
$hasHsn = db()->hasColumn('products', 'hsn_code');
$items = db()->select(
    'SELECT i.*' . ($hasHsn ? ', p.hsn_code' : '') . '
       FROM order_items i LEFT JOIN products p ON p.id = i.product_id
      WHERE i.order_id = :o ORDER BY i.id',
    ['o' => $orderId]
);

$shipping = json_decode((string) $order['shipping_address'], true) ?: [];
$billing = json_decode((string) ($order['billing_address'] ?? ''), true) ?: [];
if ($billing === []) {
    $billing = $shipping;
}

// ---------------------------------------------------------------- money maths
// The order stores tax-exclusive line totals: subtotal is the sum of line_total,
// tax_total the sum of the per-line tax, and grand_total = subtotal + tax + shipping.
$subtotal = (float) $order['subtotal'];
$discount = (float) $order['discount_total'];
$shippingTotal = (float) $order['shipping_total'];
$taxTotal = (float) $order['tax_total'];
$taxableValue = round($subtotal - $discount, 2);
$grandTotal = (float) $order['grand_total'];
$computed = round($taxableValue + $taxTotal + $shippingTotal, 2);
// Keep the printed total honest: if a discount or manual adjustment moved the stored
// grand total off the arithmetic, show the difference as a round-off line rather
// than letting the columns disagree with the total.
$roundOff = round($grandTotal - $computed, 2);

// ------------------------------------------------------- GST split (CGST/IGST)
// Intra-state supply splits the tax into CGST + SGST; anything else is IGST. The
// company state is read off the registered address, which is what the GSTIN encodes.
$companyAddress = (string) setting('company_address', '');
$shipState = trim((string) ($shipping['state'] ?? ''));
$intraState = $shipState !== '' && stripos($companyAddress, $shipState) !== false;
$halfTax = round($taxTotal / 2, 2);

$invoiceNo = (string) ($order['invoice_number'] ?: $order['order_number']);
$invoiceDate = (string) ($order['invoice_date'] ?: $order['created_at']);
$placeOfSupply = $shipState !== '' ? $shipState : '—';
$brand = (string) setting('theme_primary', '#247A3C');
$brandDark = (string) setting('theme_dark', '#14532D');

if ($download) {
    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $invoiceNo) ?: ('invoice-' . $orderId);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Invoice <?= e($invoiceNo) ?></title>
<style>
  :root { --brand: <?= e($brand) ?>; --brand-dark: <?= e($brandDark) ?>; --ink: #1F2A24; --muted: #5F6B65; --line: #DCE8DE; }
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; color: var(--ink); font-size: 12px; margin: 0; padding: 24px; background: #F4F7F5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .sheet { max-width: 860px; margin: 0 auto; background: #fff; padding: 34px 38px 28px; box-shadow: 0 6px 28px rgba(20, 83, 45, .10); }
  h1, h2, h3 { margin: 0; }

  /* ------------------------------------------------------------- toolbar */
  .toolbar { max-width: 860px; margin: 0 auto 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .toolbar .btn { border: 1px solid var(--brand); background: var(--brand); color: #fff; padding: 8px 16px; border-radius: 22px; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .toolbar .btn.ghost { background: #fff; color: var(--brand); }
  .toolbar .spacer { flex: 1; }

  /* -------------------------------------------------------------- header */
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; padding-bottom: 14px; border-bottom: 3px solid var(--brand); }
  .brand { display: flex; gap: 14px; align-items: flex-start; min-width: 0; }
  .brand img { height: 62px; width: auto; max-width: 190px; object-fit: contain; }
  .brand h1 { font-size: 21px; color: var(--brand-dark); letter-spacing: -.2px; }
  .brand .tagline { color: var(--brand); font-weight: 600; font-size: 11px; margin-bottom: 4px; }
  .brand .addr { color: var(--muted); line-height: 1.5; white-space: pre-line; }
  .doc { text-align: right; flex-shrink: 0; }
  .doc h1 { font-size: 22px; color: var(--brand-dark); letter-spacing: 1.5px; }
  .doc .no { font-size: 15px; font-weight: 700; margin: 2px 0 8px; }
  .pill { display: inline-block; background: #E8F5E9; color: var(--brand-dark); border: 1px solid #CDE7D4; padding: 3px 12px; border-radius: 20px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
  .pill.unpaid { background: #FFF4E5; color: #8A5200; border-color: #F2DDB8; }
  .pill.failed, .pill.cancelled, .pill.refunded { background: #FDECEC; color: #9B1C1C; border-color: #F6CFCF; }

  /* ----------------------------------------------------------- meta grid */
  .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; margin-top: 16px; }
  .meta div { padding: 8px 12px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
  .meta div:nth-child(3n) { border-right: none; }
  .meta div:nth-last-child(-n+3) { border-bottom: none; }
  .meta span { display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
  .meta strong { font-size: 12px; font-weight: 600; }

  /* ---------------------------------------------------------- party boxes */
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 16px; }
  .box { border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; }
  .box h3 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--brand); margin-bottom: 6px; }
  .box .name { font-weight: 700; font-size: 13px; margin-bottom: 3px; }
  .box .lines { color: var(--muted); line-height: 1.55; }

  /* ---------------------------------------------------------------- table */
  table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
  table.items th { background: var(--brand-dark); color: #fff; text-align: left; padding: 9px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
  table.items td { padding: 9px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.items tbody tr:nth-child(even) { background: #FAFCFA; }
  table.items .right { text-align: right; }
  table.items .desc { font-weight: 600; }
  table.items .sub { color: var(--muted); font-size: 10.5px; margin-top: 2px; }
  tfoot td { padding: 9px 8px; font-weight: 700; background: #F2F8F3; border-top: 2px solid var(--brand); }

  .split { display: grid; grid-template-columns: 1fr 320px; gap: 20px; margin-top: 16px; align-items: start; }
  table.totals { width: 100%; border-collapse: collapse; }
  table.totals td { padding: 6px 10px; border-bottom: 1px solid #EDF3EE; }
  table.totals td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
  table.totals .grand td { border: none; background: var(--brand-dark); color: #fff; font-size: 14px; font-weight: 700; padding: 10px; }
  table.totals .grand td:first-child { border-radius: 6px 0 0 6px; }
  table.totals .grand td:last-child { border-radius: 0 6px 6px 0; }
  .words { margin-top: 10px; padding: 9px 12px; background: #F7FAF7; border: 1px dashed #C9DECF; border-radius: 6px; font-size: 11.5px; }

  .lower { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 22px; }
  .lower .box h3 { color: var(--brand-dark); }
  .lower .lines { white-space: pre-line; }
  ol.terms { margin: 0; padding-left: 16px; color: var(--muted); line-height: 1.6; }

  .sign { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-top: 26px; }
  .sign .decl { color: var(--muted); max-width: 60%; line-height: 1.55; }
  .sign .block { text-align: center; min-width: 200px; }
  .sign .rule { border-top: 1px solid #9BB0A2; margin-bottom: 5px; padding-top: 4px; }

  .foot { margin-top: 24px; padding-top: 10px; border-top: 1px solid var(--line); color: var(--muted); font-size: 10px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

  @media (max-width: 700px) {
    body { padding: 10px; }
    .sheet { padding: 20px 16px; }
    .head, .parties, .split, .lower, .sign { grid-template-columns: 1fr; display: block; }
    .doc { text-align: left; margin-top: 12px; }
    .meta { grid-template-columns: 1fr 1fr; }
    .meta div:nth-child(3n) { border-right: 1px solid var(--line); }
    .meta div:nth-child(2n) { border-right: none; }
    .sign .decl { max-width: 100%; margin-bottom: 18px; }
    table.items { font-size: 11px; }
  }
  @media print {
    @page { size: A4; margin: 12mm; }
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; max-width: none; padding: 0; }
    .toolbar { display: none !important; }
    table.items thead { display: table-header-group; }
    tr, .box, .sign { break-inside: avoid; }
    a { color: inherit; text-decoration: none; }
  }
</style>
</head>
<body>
<?php if (!$embed && !$download): ?>
<div class="toolbar">
  <button class="btn" onclick="window.print()"><i aria-hidden="true">🖨</i> Print / Save as PDF</button>
  <a class="btn ghost" href="<?= e(Url::to('invoice.php?order=' . $orderId . '&download=1')) ?>" download><i aria-hidden="true">⬇</i> Download</a>
  <span class="spacer"></span>
  <a class="btn ghost" href="javascript:history.back()">← Back</a>
</div>
<?php endif; ?>

<div class="sheet">
  <!-- ------------------------------------------------------- letterhead -->
  <div class="head">
    <div class="brand">
      <?php $logo = (string) setting('logo_header', ''); if ($logo !== ''): ?>
        <img src="<?= e(Url::media($logo)) ?>" alt="<?= e((string) setting('site_name')) ?>">
      <?php endif; ?>
      <div>
        <h1><?= e((string) setting('site_name')) ?></h1>
        <?php if ((string) setting('site_tagline') !== ''): ?>
          <div class="tagline"><?= e((string) setting('site_tagline')) ?></div>
        <?php endif; ?>
        <div class="addr"><?= e($companyAddress) ?></div>
        <div class="addr">
          <?php if ((string) setting('company_gst') !== ''): ?>GSTIN: <strong><?= e((string) setting('company_gst')) ?></strong><?php endif; ?>
          <?php if ((string) setting('company_cin') !== ''): ?> · CIN: <?= e((string) setting('company_cin')) ?><?php endif; ?>
          <br>
          <?= e((string) setting('contact_phone')) ?><?php if ((string) setting('contact_phone_alt') !== ''): ?>, <?= e((string) setting('contact_phone_alt')) ?><?php endif; ?>
          <?php if ((string) setting('contact_email') !== ''): ?> · <?= e((string) setting('contact_email')) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="doc">
      <h1>TAX INVOICE</h1>
      <div class="no"><?= e($invoiceNo) ?></div>
      <?php $payClass = strtolower((string) $order['payment_status']); ?>
      <span class="pill <?= e(in_array($payClass, ['unpaid', 'pending', 'failed', 'refunded'], true) ? $payClass : '') ?>">
        <?= e(strtoupper((string) $order['payment_status'])) ?>
      </span>
    </div>
  </div>

  <!-- ---------------------------------------------------------- metadata -->
  <div class="meta">
    <div><span>Invoice date</span><strong><?= e(format_date($invoiceDate, 'd M Y')) ?></strong></div>
    <div><span>Order number</span><strong><?= e((string) $order['order_number']) ?></strong></div>
    <div><span>Order date</span><strong><?= e(format_date((string) $order['created_at'], 'd M Y')) ?></strong></div>
    <div><span>Place of supply</span><strong><?= e($placeOfSupply) ?></strong></div>
    <div><span>Payment method</span><strong><?= e(payment_label((string) $order['payment_method'])) ?></strong></div>
    <div><span><?= $intraState ? 'Supply type' : 'Supply type' ?></span><strong><?= $intraState ? 'Intra-state (CGST + SGST)' : 'Inter-state (IGST)' ?></strong></div>
    <?php if (!empty($order['paid_at'])): ?>
      <div><span>Paid on</span><strong><?= e(format_date((string) $order['paid_at'], 'd M Y, h:i A')) ?></strong></div>
    <?php endif; ?>
    <?php if (!empty($order['tracking_number'])): ?>
      <div><span>Tracking</span><strong><?= e((string) $order['tracking_number']) ?> <?= e((string) ($order['carrier'] ?? '')) ?></strong></div>
    <?php endif; ?>
    <?php if (!empty($order['coupon_code'])): ?>
      <div><span>Coupon</span><strong><?= e((string) $order['coupon_code']) ?></strong></div>
    <?php endif; ?>
  </div>

  <!-- ------------------------------------------------------- bill / ship -->
  <div class="parties">
    <div class="box">
      <h3>Billed to</h3>
      <div class="name"><?= e((string) ($billing['full_name'] ?? $order['customer_name'])) ?></div>
      <div class="lines"><?= e(trim((string) ($billing['address_line1'] ?? '') . (!empty($billing['address_line2']) ? ', ' . $billing['address_line2'] : ''))) ?>
<?= e(trim((string) ($billing['city'] ?? '') . (!empty($billing['state']) ? ', ' . $billing['state'] : '') . (!empty($billing['pincode']) ? ' - ' . $billing['pincode'] : ''), ", -")) ?>
Mobile: <?= e((string) ($billing['mobile'] ?? $order['customer_mobile'])) ?>
<?php if (!empty($billing['email'] ?? $order['customer_email'])): ?>Email: <?= e((string) ($billing['email'] ?? $order['customer_email'])) ?><?php endif; ?>
<?php if (!empty($billing['gst_number'])): ?>GSTIN: <?= e((string) $billing['gst_number']) ?><?php endif; ?></div>
    </div>
    <div class="box">
      <h3>Shipped to</h3>
      <div class="name"><?= e((string) ($shipping['full_name'] ?? $order['customer_name'])) ?></div>
      <div class="lines"><?= e(trim((string) ($shipping['address_line1'] ?? '') . (!empty($shipping['address_line2']) ? ', ' . $shipping['address_line2'] : ''))) ?>
<?= e(trim((string) ($shipping['city'] ?? '') . (!empty($shipping['state']) ? ', ' . $shipping['state'] : '') . (!empty($shipping['pincode']) ? ' - ' . $shipping['pincode'] : ''), ", -")) ?>
Mobile: <?= e((string) ($shipping['mobile'] ?? $order['customer_mobile'])) ?></div>
    </div>
  </div>

  <!-- ------------------------------------------------------------- items -->
  <table class="items">
    <thead>
      <tr>
        <th style="width:26px">#</th>
        <th>Description</th>
        <th style="width:78px">HSN/SKU</th>
        <th class="right" style="width:44px">Qty</th>
        <th class="right" style="width:78px">Rate</th>
        <th class="right" style="width:86px">Taxable</th>
        <th class="right" style="width:48px">Tax %</th>
        <?php if ($intraState): ?>
          <th class="right" style="width:70px">CGST</th>
          <th class="right" style="width:70px">SGST</th>
        <?php else: ?>
          <th class="right" style="width:80px">IGST</th>
        <?php endif; ?>
        <th class="right" style="width:90px">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $sumTaxable = 0.0; $sumTax = 0.0; $sumQty = 0;
    foreach ($items as $i => $item):
        $lineTaxable = (float) $item['line_total'];
        $lineTax = (float) ($item['tax_amount'] ?? 0);
        $rate = $lineTaxable > 0 ? round($lineTax / $lineTaxable * 100, 2) : 0.0;
        $sumTaxable += $lineTaxable; $sumTax += $lineTax; $sumQty += (int) $item['quantity'];
        $hsn = (string) ($item['hsn_code'] ?? '');
    ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td>
          <div class="desc"><?= e((string) $item['product_name']) ?></div>
          <?php if (!empty($item['pack_size'])): ?><div class="sub">Pack: <?= e((string) $item['pack_size']) ?></div><?php endif; ?>
        </td>
        <td><?= $hsn !== '' ? e($hsn) : e((string) ($item['product_sku'] ?? '—')) ?></td>
        <td class="right"><?= (int) $item['quantity'] ?></td>
        <td class="right"><?= money((float) $item['unit_price']) ?></td>
        <td class="right"><?= money($lineTaxable) ?></td>
        <td class="right"><?= rtrim(rtrim(number_format($rate, 2), '0'), '.') ?>%</td>
        <?php if ($intraState): ?>
          <td class="right"><?= money(round($lineTax / 2, 2)) ?></td>
          <td class="right"><?= money(round($lineTax / 2, 2)) ?></td>
        <?php else: ?>
          <td class="right"><?= money($lineTax) ?></td>
        <?php endif; ?>
        <td class="right"><?= money(round($lineTaxable + $lineTax, 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
      <tr><td colspan="<?= $intraState ? 10 : 9 ?>" style="text-align:center;color:var(--muted);padding:18px">No line items recorded for this order.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">Total (<?= count($items) ?> line(s))</td>
        <td class="right"><?= $sumQty ?></td>
        <td></td>
        <td class="right"><?= money(round($sumTaxable, 2)) ?></td>
        <td></td>
        <?php if ($intraState): ?>
          <td class="right"><?= money(round($sumTax / 2, 2)) ?></td>
          <td class="right"><?= money(round($sumTax / 2, 2)) ?></td>
        <?php else: ?>
          <td class="right"><?= money(round($sumTax, 2)) ?></td>
        <?php endif; ?>
        <td class="right"><?= money(round($sumTaxable + $sumTax, 2)) ?></td>
      </tr>
    </tfoot>
  </table>

  <!-- ----------------------------------------------------------- summary -->
  <div class="split">
    <div>
      <div class="box">
        <h3>Payment details</h3>
        <div class="lines"><?= e((string) setting('bank_transfer_details', 'Bank details not configured.')) ?></div>
      </div>
    </div>
    <div>
      <table class="totals">
        <tr><td>Taxable value</td><td><?= money($taxableValue) ?></td></tr>
        <?php if ($discount > 0): ?><tr><td>Discount</td><td>− <?= money($discount) ?></td></tr><?php endif; ?>
        <?php if ($intraState): ?>
          <tr><td>CGST</td><td><?= money($halfTax) ?></td></tr>
          <tr><td>SGST</td><td><?= money(round($taxTotal - $halfTax, 2)) ?></td></tr>
        <?php else: ?>
          <tr><td>IGST</td><td><?= money($taxTotal) ?></td></tr>
        <?php endif; ?>
        <tr><td>Shipping</td><td><?= $shippingTotal > 0 ? money($shippingTotal) : 'Free' ?></td></tr>
        <?php if (abs($roundOff) >= 0.01): ?><tr><td>Round off</td><td><?= ($roundOff < 0 ? '− ' : '') . money(abs($roundOff)) ?></td></tr><?php endif; ?>
        <tr class="grand"><td>Grand total</td><td><?= money($grandTotal) ?></td></tr>
      </table>
      <div class="words"><strong>Amount in words:</strong> <?= e(amount_in_words($grandTotal)) ?></div>
    </div>
  </div>

  <!-- ------------------------------------------------- terms & signature -->
  <div class="lower">
    <div class="box">
      <h3>Terms &amp; conditions</h3>
      <ol class="terms">
        <li><?= e((string) setting('order_terms', 'Goods once sold are subject to our refund and return policy.')) ?></li>
        <li>Agro-chemical products must be stored and applied strictly as per the label instructions.</li>
        <li>Goods remain the property of <?= e((string) setting('site_name')) ?> until paid for in full.</li>
        <li>Subject to Nagpur, Maharashtra jurisdiction.</li>
      </ol>
    </div>
    <div class="box">
      <h3>Declaration</h3>
      <div class="lines">We declare that this invoice shows the actual price of the goods described and that all
particulars are true and correct.</div>
      <div class="sign" style="margin-top:34px">
        <div class="block" style="width:100%">
          <div class="rule"></div>
          <div class="lines">for <strong><?= e((string) setting('site_name')) ?></strong><br>Authorised signatory</div>
        </div>
      </div>
    </div>
  </div>

  <div class="foot">
    <span>This is a computer generated invoice and does not require a physical signature.</span>
    <span>Generated <?= e(format_date(date('Y-m-d H:i:s'), 'd M Y, h:i A')) ?></span>
  </div>
</div>
</body>
</html>
