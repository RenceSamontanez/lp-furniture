{{-- ══════════════════════════════════════════
     SHARED VALIDATION ERROR MODAL — same pattern as the admin
     product form's validation modal (icon + centered title +
     error list + OK, auto-dismiss after 5s). Generic: fires for
     ANY validate() failure that redirects back to whichever
     design page it's included on — the prompt form (index),
     "Order This Design" (index/gallery/show, via selectDesign's
     suggestion_id check), etc. Extracted into one shared partial
     (rather than copy-pasted per page) after Bug A showed what
     copy-pasting the same widget across pages leads to.
═══════════════════════════════════════════ --}}
<div class="cd-modal-overlay" id="cd-validation-error-overlay" onclick="closeCdValidationErrorOnBackdrop(event)">
  <div class="cd-modal cd-modal-centered">
    <div class="cd-modal-body">
      <div class="cd-modal-icon cd-modal-icon--danger">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <h3 class="cd-modal-title-centered">Please fix the following</h3>
      <ul class="cd-modal-error-list" id="cd-validation-error-list"></ul>
    </div>
    <div class="cd-modal-footer-plain">
      <button type="button" class="cd-modal-ok-btn" onclick="closeCdValidationError()">OK</button>
      <div class="cd-modal-countdown" id="cd-validation-error-countdown"></div>
    </div>
  </div>
</div>

<script>
if (!window.__cdValidationErrorJsBound) {
  window.__cdValidationErrorJsBound = true;

  let cdValidationErrorTimer = null;
  let cdValidationErrorCountdownInterval = null;

  window.openCdValidationError = function (messages) {
    const list = document.getElementById('cd-validation-error-list');
    list.innerHTML = '';
    messages.forEach(msg => {
      const li = document.createElement('li');
      li.textContent = msg;
      list.appendChild(li);
    });

    document.getElementById('cd-validation-error-overlay').classList.add('show');
    document.body.style.overflow = 'hidden';

    let secondsLeft = 5;
    const countdown = document.getElementById('cd-validation-error-countdown');
    countdown.textContent = `Auto-closing in ${secondsLeft}s`;

    cdValidationErrorCountdownInterval = setInterval(() => {
      secondsLeft -= 1;
      if (secondsLeft > 0) {
        countdown.textContent = `Auto-closing in ${secondsLeft}s`;
      }
    }, 1000);

    cdValidationErrorTimer = setTimeout(closeCdValidationError, 5000);
  };

  window.closeCdValidationError = function () {
    document.getElementById('cd-validation-error-overlay').classList.remove('show');
    document.body.style.overflow = '';
    clearTimeout(cdValidationErrorTimer);
    clearInterval(cdValidationErrorCountdownInterval);
  };

  window.closeCdValidationErrorOnBackdrop = function (e) {
    if (e.target.id === 'cd-validation-error-overlay') closeCdValidationError();
  };

  document.addEventListener('DOMContentLoaded', function () {
    @if($errors->any())
      openCdValidationError(@json($errors->all()));
    @endif
  });
}
</script>
