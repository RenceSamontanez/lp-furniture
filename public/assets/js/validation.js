/* ============================================================
   LP Furniture — Identity Validation Page JS
   ============================================================ */

const validationState = {
  idUploaded: false,
  selfieUploaded: false,
  termsAccepted: false,
};

const TERMS_STORAGE_KEY = 'lp_validation_terms_accepted';

function handleFileUpload(inputEl, type) {
  const file = inputEl.files[0];
  if (!file) return;

  const card = inputEl.closest('.val-upload-card');
  const preview = card.querySelector('.val-preview-thumb');
  const status = card.querySelector('.val-upload-status');
  const icon = card.querySelector('.val-upload-icon');

  const allowed = ['image/jpeg', 'image/png', 'image/jpg'];
  if (!allowed.includes(file.type)) {
    card.classList.add('error');
    status.textContent = 'Invalid file type. Use JPG or PNG.';
    return;
  }

  if (file.size > 5 * 1024 * 1024) {
    card.classList.add('error');
    status.textContent = 'File too large. Maximum 5MB.';
    return;
  }

  const reader = new FileReader();
  reader.onload = (e) => {
    if (preview) preview.src = e.target.result;
    card.classList.remove('error');
    card.classList.add('uploaded');
    status.textContent = '✓ ' + file.name;

    if (icon) {
      icon.innerHTML = `
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5">
          <path d="M20 6L9 17l-5-5"/>
        </svg>`;
    }

    if (type === 'id') validationState.idUploaded = true;
    if (type === 'selfie') validationState.selfieUploaded = true;
    checkSubmitReady();
  };
  reader.readAsDataURL(file);
}

function checkSubmitReady() {
  const btn = document.getElementById('val-submit-btn');
  if (!btn) return;

  btn.disabled = !(validationState.idUploaded
    && validationState.selfieUploaded
    && validationState.termsAccepted);
}

function handleModalTermsChange(checkbox) {
  const agreeBtn = document.getElementById('val-modal-agree-btn');
  if (agreeBtn) agreeBtn.disabled = !checkbox.checked;
}

function acceptValidationTerms() {
  const checkbox = document.getElementById('val-modal-terms-check');
  if (!checkbox || !checkbox.checked) return;

  validationState.termsAccepted = true;
  sessionStorage.setItem(TERMS_STORAGE_KEY, '1');

  const modal = document.getElementById('val-terms-modal');
  const formCard = document.getElementById('val-form-card');
  if (modal) modal.classList.add('is-hidden');
  if (formCard) formCard.classList.remove('is-locked');

  checkSubmitReady();
}

function showTermsModal() {
  const modal = document.getElementById('val-terms-modal');
  const formCard = document.getElementById('val-form-card');
  if (modal) modal.classList.remove('is-hidden');
  if (formCard) formCard.classList.add('is-locked');
}

function initValidationPage() {
  const form = document.getElementById('validation-form');
  if (!form) return;

  const btn = document.getElementById('val-submit-btn');
  if (btn) btn.disabled = true;

  if (sessionStorage.getItem(TERMS_STORAGE_KEY) === '1') {
    validationState.termsAccepted = true;
    const formCard = document.getElementById('val-form-card');
    if (formCard) formCard.classList.remove('is-locked');
    checkSubmitReady();
    return;
  }

  showTermsModal();
}

document.addEventListener('DOMContentLoaded', initValidationPage);
