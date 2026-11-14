<?php
/**
 * Shared confirmation dialog.
 *
 * Used for actions that end a session or move money, where a misclick is expensive. Any link
 * carrying `data-confirm-link="Question here?"` opens this modal instead of navigating.
 *
 * Deliberately self-contained and dependency-free: Bootstrap's bundle is loaded with `defer`
 * on some panels, so the click handler checks for it at click time and falls back to the
 * native confirm() if it is not ready. Either way the action asks before it runs.
 *
 * Included once per rendered page, after the closing app markup.
 */

use Saee\Lib\Url;

if (!defined('SAEE_CONFIRM_MODAL_RENDERED')) {
    define('SAEE_CONFIRM_MODAL_RENDERED', true);
?>
<div class="modal fade" id="saeeConfirmModal" tabindex="-1" aria-labelledby="saeeConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h6 mb-0" id="saeeConfirmTitle">
          <i class="fa-solid fa-triangle-exclamation text-warning me-2" aria-hidden="true"></i>Please confirm
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body small" id="saeeConfirmBody">Are you sure?</div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-danger" id="saeeConfirmYes">Yes, continue</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('saeeConfirmModal');
  var bodyEl = document.getElementById('saeeConfirmBody');
  var yesEl = document.getElementById('saeeConfirmYes');
  if (!modalEl || !bodyEl || !yesEl) { return; }

  var modal = null;
  var pending = null;

  function ask(question, onYes) {
    // Bootstrap may still be loading (it is deferred on some panels). Native confirm() keeps
    // the guard working in that window rather than letting the action through unconfirmed.
    if (!window.bootstrap || !window.bootstrap.Modal) {
      if (window.confirm(question)) { onYes(); }
      return;
    }
    bodyEl.textContent = question;
    pending = onYes;
    modal = modal || new window.bootstrap.Modal(modalEl);
    modal.show();
  }

  yesEl.addEventListener('click', function () {
    var action = pending;
    pending = null;
    if (modal) { modal.hide(); }
    if (action) { action(); }
  });

  modalEl.addEventListener('hidden.bs.modal', function () { pending = null; });

  // Links that must ask first (Logout, and any other data-confirm-link).
  document.addEventListener('click', function (ev) {
    var link = ev.target && ev.target.closest ? ev.target.closest('[data-confirm-link]') : null;
    if (!link) { return; }
    ev.preventDefault();
    ask(link.getAttribute('data-confirm-link') || 'Are you sure?', function () {
      window.location.href = link.getAttribute('href');
    });
  });

  // Forms that must ask first, replacing the older window.confirm() behaviour so the two
  // kinds of destructive action look the same.
  document.addEventListener('submit', function (ev) {
    var form = ev.target && ev.target.closest ? ev.target.closest('form[data-confirm]') : null;
    if (!form || form.dataset.saeeConfirmed === '1') { return; }
    ev.preventDefault();
    ask(form.getAttribute('data-confirm') || 'Are you sure?', function () {
      // The flag lets this one re-submission through. requestSubmit() dispatches its submit
      // event synchronously, so clearing it straight after still leaves the form asking again
      // if the user submits it a second time.
      form.dataset.saeeConfirmed = '1';
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      delete form.dataset.saeeConfirmed;
    });
  });
})();
</script>
<?php } ?>
