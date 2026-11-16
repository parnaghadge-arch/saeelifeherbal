<?php
/**
 * Order totals + submit button for the assisted-purchase desks.
 *
 * Rendered *after* the payment and delivery panels, so the operator reads the whole form top to
 * bottom and the money lands last, next to the button. The figures are filled in by the script in
 * includes/purchase-picker.php (which is why that script waits for DOMContentLoaded).
 *
 * Expects:
 *   $ppSubmitLabel  string  button text, defaults to "Record purchase"
 */
$ppSubmitLabel = (string) ($ppSubmitLabel ?? 'Record purchase');
?>
<div class="panel p-3 p-md-4 mb-3 pp-totalbar">
  <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
    <div class="small pp-totals">
      <div>Subtotal <strong data-summary="subtotal"><?= money(0) ?></strong></div>
      <div>Tax <strong data-summary="tax"><?= money(0) ?></strong></div>
      <div>Shipping <strong data-summary="shipping"><?= money(0) ?></strong></div>
      <div class="fs-6">Total <strong data-summary="total"><?= money(0) ?></strong></div>
    </div>
    <div class="text-end">
      <div class="small text-muted mb-1" data-pp-summaryhint>Add at least one product to continue</div>
      <button type="submit" class="btn btn-primary btn-lg px-4" data-pp-submit disabled><?= e($ppSubmitLabel) ?></button>
    </div>
  </div>
</div>
