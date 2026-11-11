<?php
/**
 * "Save your login details" card, shown once immediately after a member is created.
 *
 * Expects:
 *   $welcome  array from MemberService::welcomeDetails()
 *   $welcomeContext string  'self' after self-registration, 'admin' when staff created the account
 *
 * The plain-text password exists only in this render: it is never stored, so the card cannot be
 * shown again later. That is why it is presented as something to save or share right now, with a
 * copy button per field and a printable / shareable summary.
 */
declare(strict_types=1);

$welcome = $welcome ?? [];
$welcomeContext = $welcomeContext ?? 'self';
if ($welcome === []) {
    return;
}

$memberCode = (string) ($welcome['member_code'] ?? '');
$shareLink = (string) ($welcome['share_link'] ?? '');
$loginUrl = (string) ($welcome['login_url'] ?? '');
$isRoot = !empty($welcome['root_of_own_tree']);

// One block of text that can be pasted into WhatsApp/SMS or saved as a note.
$summaryLines = [
    'Saee Life Herbal — partner login details',
    'Name: ' . ($welcome['name'] ?? ''),
    'Member ID / Username: ' . $memberCode,
    'Password: ' . ($welcome['password'] ?? ''),
    'Login: ' . $loginUrl,
];
if (!$isRoot && ($welcome['sponsor_code'] ?? '') !== '') {
    $summaryLines[] = 'Sponsor: ' . ($welcome['sponsor_name'] ?? '') . ' (' . $welcome['sponsor_code'] . ')';
}
if ($shareLink !== '') {
    $summaryLines[] = 'Join my team: ' . $shareLink;
}
$summary = implode("\n", $summaryLines);
?>
<div class="modal fade" id="memberWelcomeCard" tabindex="-1" aria-labelledby="memberWelcomeTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <div>
          <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle mb-2">
            <i class="fa-solid fa-circle-check me-1"></i><?= $welcomeContext === 'admin' ? 'Member created' : 'Registration complete' ?>
          </span>
          <h2 class="modal-title h5 mb-1" id="memberWelcomeTitle">Save these details now</h2>
          <p class="text-muted small mb-0">
            This is the only time the password is shown. Save or share it before closing.
          </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label">Member ID</span>
              <span class="welcome-value" id="welcomeCode"><?= e($memberCode) ?></span>
              <button type="button" class="btn btn-sm btn-outline-secondary welcome-copy" data-copy="<?= e($memberCode) ?>" title="Copy member ID">
                <i class="fa-regular fa-copy"></i><span class="visually-hidden">Copy member ID</span>
              </button>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label">Name</span>
              <span class="welcome-value"><?= e((string) ($welcome['name'] ?? '')) ?></span>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label">Username</span>
              <span class="welcome-value"><?= e((string) ($welcome['username'] ?? $memberCode)) ?></span>
              <button type="button" class="btn btn-sm btn-outline-secondary welcome-copy" data-copy="<?= e((string) ($welcome['username'] ?? $memberCode)) ?>" title="Copy username">
                <i class="fa-regular fa-copy"></i><span class="visually-hidden">Copy username</span>
              </button>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label">Password</span>
              <span class="welcome-value welcome-password" id="welcomePassword"><?= e((string) ($welcome['password'] ?? '')) ?></span>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="welcomeToggle" title="Hide password">
                <i class="fa-regular fa-eye-slash"></i><span class="visually-hidden">Hide password</span>
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary welcome-copy" data-copy="<?= e((string) ($welcome['password'] ?? '')) ?>" title="Copy password">
                <i class="fa-regular fa-copy"></i><span class="visually-hidden">Copy password</span>
              </button>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label">Mobile</span>
              <span class="welcome-value"><?= e((string) ($welcome['mobile'] ?? '')) ?></span>
            </div>
          </div>
          <div class="col-12 col-sm-6">
            <div class="welcome-field">
              <span class="welcome-label"><?= $isRoot ? 'Team position' : 'Sponsor' ?></span>
              <span class="welcome-value">
                <?php if ($isRoot): ?>
                  <span class="text-success-emphasis"><i class="fa-solid fa-seedling me-1"></i>Start of a new tree</span>
                <?php else: ?>
                  <?= e((string) ($welcome['sponsor_name'] ?? '—')) ?>
                  <span class="text-muted small">(<?= e((string) ($welcome['sponsor_code'] ?? '')) ?>)</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
          <?php if ($shareLink !== ''): ?>
          <div class="col-12">
            <div class="welcome-field welcome-field-wide">
              <span class="welcome-label">Your referral / share link</span>
              <span class="welcome-value welcome-link" id="welcomeShare"><?= e($shareLink) ?></span>
              <button type="button" class="btn btn-sm btn-outline-secondary welcome-copy" data-copy="<?= e($shareLink) ?>" title="Copy share link">
                <i class="fa-regular fa-copy"></i><span class="visually-hidden">Copy share link</span>
              </button>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="alert alert-light border small mt-3 mb-0">
          <i class="fa-solid fa-circle-info me-1"></i>
          You can sign in with your <strong>Member ID</strong>, your <strong>mobile number</strong> or your <strong>email</strong>.
        </div>
      </div>
      <div class="modal-footer border-0 flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary flex-grow-1"
                data-welcome-share
                data-share-text="<?= e($summary) ?>"
                data-share-title="Saee Life Herbal partner details">
          <i class="fa-brands fa-whatsapp me-1"></i>Share
        </button>
        <button type="button" class="btn btn-outline-secondary flex-grow-1" data-welcome-save
                data-save-text="<?= e($summary) ?>" data-save-name="saee-partner-<?= e($memberCode) ?>.txt">
          <i class="fa-solid fa-download me-1"></i>Save details
        </button>
        <button type="button" class="btn btn-outline-secondary flex-grow-1" data-welcome-print>
          <i class="fa-solid fa-print me-1"></i>Print
        </button>
        <button type="button" class="btn btn-primary flex-grow-1" data-bs-dismiss="modal">
          I have saved them
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  .welcome-field{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;background:#F6F8F6;border:1px solid #DFE8E1;border-radius:10px;padding:.6rem .75rem;min-height:3.1rem}
  .welcome-label{display:block;width:100%;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;color:#5A6B60}
  .welcome-value{font-weight:600;color:#14532D;flex:1 1 auto;min-width:0;word-break:break-word}
  .welcome-password{font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.02em}
  .welcome-link{font-weight:500;font-size:.86rem;font-family:ui-monospace,Menlo,Consolas,monospace}
  .welcome-field .btn{padding:.2rem .5rem}
  @media print{
    body *{visibility:hidden}
    #memberWelcomeCard, #memberWelcomeCard *{visibility:visible}
    #memberWelcomeCard{position:absolute;inset:0;overflow:visible}
    #memberWelcomeCard .modal-footer, #memberWelcomeCard .btn-close{display:none!important}
    #memberWelcomeCard .modal-content{border:0!important;box-shadow:none!important}
  }
</style>

<script>
(function () {
  var modalEl = document.getElementById('memberWelcomeCard');
  if (!modalEl || !window.bootstrap) { return; }

  // Show immediately: the credentials are only in memory for this render.
  window.bootstrap.Modal.getOrCreateInstance(modalEl, {backdrop: 'static', keyboard: false}).show();

  modalEl.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy') || '';
      var done = function () {
        var html = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        btn.classList.add('btn-success');
        setTimeout(function () { btn.innerHTML = html; btn.classList.remove('btn-success'); }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text); done(); });
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text); done(); });
      } else {
        fallback(text); done();
      }
    });
  });

  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  // The password is visible straight away, because the card exists to be read and saved.
  var toggle = document.getElementById('welcomeToggle');
  var pwd = document.getElementById('welcomePassword');
  if (toggle && pwd) {
    var plain = pwd.textContent;
    toggle.addEventListener('click', function () {
      var hidden = pwd.getAttribute('data-hidden') === '1';
      pwd.textContent = hidden ? plain : '••••••••';
      pwd.setAttribute('data-hidden', hidden ? '0' : '1');
      toggle.innerHTML = hidden
        ? '<i class="fa-regular fa-eye-slash"></i><span class="visually-hidden">Hide password</span>'
        : '<i class="fa-regular fa-eye"></i><span class="visually-hidden">Show password</span>';
    });
  }

  // Share: the Web Share API where it exists (mobile), WhatsApp otherwise.
  var shareBtn = modalEl.querySelector('[data-welcome-share]');
  if (shareBtn) {
    shareBtn.addEventListener('click', function () {
      var text = shareBtn.getAttribute('data-share-text') || '';
      var title = shareBtn.getAttribute('data-share-title') || 'Saee Life Herbal';
      if (navigator.share) {
        navigator.share({title: title, text: text}).catch(function () {});
      } else {
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
      }
    });
  }

  // Save: a plain text file the member can keep, which works even with the popup closed.
  var saveBtn = modalEl.querySelector('[data-welcome-save]');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var text = saveBtn.getAttribute('data-save-text') || '';
      var name = saveBtn.getAttribute('data-save-name') || 'partner-details.txt';
      var blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
  }

  var printBtn = modalEl.querySelector('[data-welcome-print]');
  if (printBtn) { printBtn.addEventListener('click', function () { window.print(); }); }
})();
</script>
