<?php
/**
 * Shared invoice popup.
 *
 * Included once per page by the member and admin layouts. Any control carrying
 * data-invoice-id opens it:
 *
 *   <button data-invoice-id="42" data-invoice-label="SLH-INV-00042 · 12 Mar 2026">Invoice</button>
 *
 * The popup loads the real invoice.php in an iframe, so what is on screen, what
 * prints and what downloads are the same document. Print drives the iframe's own
 * print dialog (which offers Save as PDF); Download fetches it as a file.
 */
declare(strict_types=1);

use Saee\Lib\Url;

$invoiceAssetBase = \Saee\Lib\Url::base();
?>
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-labelledby="invoiceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered invoice-modal-dialog">
    <div class="modal-content border-0 shadow-lg overflow-hidden">
      <div class="modal-header align-items-center py-2 px-3 border-bottom">
        <div class="me-auto">
          <h2 class="modal-title h6 mb-0" id="invoiceModalLabel">
            <i class="fa-solid fa-file-invoice me-1 text-success" aria-hidden="true"></i>Invoice
          </h2>
          <div class="small text-muted" id="invoiceModalSub">Loading…</div>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-2">
          <button type="button" class="btn btn-sm btn-primary" id="invoicePrintBtn">
            <i class="fa-solid fa-print me-1" aria-hidden="true"></i>Print
          </button>
          <a class="btn btn-sm btn-outline-primary" id="invoiceDownloadBtn" href="#" download>
            <i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download
          </a>
          <a class="btn btn-sm btn-outline-secondary" id="invoiceNewTabBtn" href="#" target="_blank" rel="noopener" title="Open in a new tab">
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
            <span class="visually-hidden">Open in a new tab</span>
          </a>
        </div>
        <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0 bg-light">
        <iframe id="invoiceFrame" title="Invoice" loading="lazy"
                style="display:block;width:100%;height:78vh;border:0;background:#fff"></iframe>
      </div>
      <div class="modal-footer py-2 px-3 border-top small text-muted">
        Tip: in the print dialog choose <strong>Save as PDF</strong> to keep a copy.
      </div>
    </div>
  </div>
</div>

<style>
  /* The invoice itself carries the print styles; the popup must never print its own chrome. */
  @media print { #invoiceModal { display: none !important; } }
  #invoiceModal .modal-content { border-radius: .6rem; }
  #invoiceFrame { min-height: 320px; }
  @media (max-width: 991.98px) {
    .invoice-modal-dialog { max-width: calc(100vw - 16px); margin: 8px auto; }
    #invoiceFrame { height: 72vh; }
  }
</style>

<script>
/* Invoice popup controller. Self-contained: no dependency beyond Bootstrap's Modal,
   which the layouts load with defer, so opening is retried until it is available. */
(function () {
  var BASE = <?= json_encode($invoiceAssetBase) ?>;

  function invoiceUrl(id, extra) {
    return BASE.replace(/\/+$/, '') + '/invoice.php?order=' + encodeURIComponent(id) + (extra || '');
  }

  var modalEl = document.getElementById('invoiceModal');
  if (!modalEl) { return; }
  var frame = document.getElementById('invoiceFrame');
  var subEl = document.getElementById('invoiceModalSub');
  var shown = null;
  var tries = 0;

  function show() {
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
      return;
    }
    // Bootstrap is deferred; give it a moment, then fall back to a plain tab.
    if (++tries > 50) {
      window.open(frame.src, '_blank', 'noopener');
      return;
    }
    setTimeout(show, 60);
  }

  function open(id, label) {
    subEl.textContent = label || 'Loading…';
    document.getElementById('invoiceDownloadBtn').setAttribute('href', invoiceUrl(id, '&download=1'));
    document.getElementById('invoiceNewTabBtn').setAttribute('href', invoiceUrl(id, ''));
    frame.setAttribute('src', invoiceUrl(id, '&embed=1'));
    shown = id;
    tries = 0;
    show();
  }

  // One delegated listener, so buttons added later (DataTables redraws, ajax lists) work too.
  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest ? event.target.closest('[data-invoice-id]') : null;
    if (!trigger) { return; }
    event.preventDefault();
    open(trigger.getAttribute('data-invoice-id'), trigger.getAttribute('data-invoice-label') || '');
  });

  document.getElementById('invoicePrintBtn').addEventListener('click', function () {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    } catch (err) {
      // Cross-origin or blocked frame: hand the print job to a real tab instead.
      window.open(frame.getAttribute('src'), '_blank', 'noopener');
    }
  });

  // Stop the iframe from holding the last invoice (and its session data) in memory.
  modalEl.addEventListener('hidden.bs.modal', function () {
    frame.setAttribute('src', 'about:blank');
    subEl.textContent = '';
    shown = null;
  });

  // Escape already closes the modal; this lets other page code open an invoice directly.
  window.saeeOpenInvoice = open;
})();
</script>
