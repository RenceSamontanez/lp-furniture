/* ============================================================
   LP Furniture — Auth Pages JS
   Login, Register, Forgot Password, Reset Password
   ============================================================ */

/* ── PASSWORD STRENGTH BAR ── */
function checkPasswordStrength(value) {
  const bar   = document.getElementById('strength-bar');
  const label = document.getElementById('strength-label');
  if (!bar || !label) return;

  let score = 0;
  if (value.length >= 8)                    score++;
  if (/[A-Z]/.test(value))                  score++;
  if (/[a-z]/.test(value))                  score++;
  if (/[0-9]/.test(value))                  score++;
  if (/[\W_]/.test(value))                  score++;

  const levels = [
    { width: '0%',    color: 'var(--border)',   text: '' },
    { width: '20%',   color: '#E53E3E',         text: 'Very Weak' },
    { width: '40%',   color: '#DD6B20',         text: 'Weak' },
    { width: '60%',   color: '#D69E2E',         text: 'Fair' },
    { width: '80%',   color: '#38A169',         text: 'Good' },
    { width: '100%',  color: 'var(--success)',  text: 'Strong' },
  ];

  const level = levels[score] || levels[0];
  bar.style.width      = level.width;
  bar.style.background = level.color;
  label.textContent    = level.text;
  label.style.color    = level.color;
}

/* ── PASSWORD REQUIREMENTS CHECKLIST ── */
function checkRequirements(value) {
  const req = document.getElementById('pw-requirements');
  if (!req) return;

  if (value.length > 0) {
    req.classList.add('is-visible');
  } else {
    req.classList.remove('is-visible');
    return;
  }

  const rules = [
    { id: 'req-length',  test: value.length >= 8 },
    { id: 'req-upper',   test: /[A-Z]/.test(value) },
    { id: 'req-lower',   test: /[a-z]/.test(value) },
    { id: 'req-number',  test: /[0-9]/.test(value) },
    { id: 'req-special', test: /[\W_]/.test(value) },
  ];

  rules.forEach(({ id, test }) => {
    const el  = document.getElementById(id);
    const dot = el?.querySelector('.req-dot');
    if (!el) return;
    el.classList.toggle('met', test);
    if (dot) dot.style.background = test ? 'var(--success)' : 'var(--border)';
  });
}

/* ── CONFIRM PASSWORD MATCH ── */
function checkPasswordMatch() {
  const pw      = document.getElementById('password');
  const confirm = document.getElementById('password_confirmation');
  const hint    = document.getElementById('password-match-hint');
  if (!pw || !confirm || !hint) return;

  if (!confirm.value) {
    hint.textContent = '';
    return;
  }

  if (pw.value === confirm.value) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = 'var(--success)';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = 'var(--danger)';
  }
}

/* ── PHONE NUMBER: NUMBERS ONLY ── */
function enforcePhoneNumber(input) {
  input.value = input.value.replace(/[^\d\+]/g, '');
}

/* ── reCAPTCHA v3 (login) ── */
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('login-form');
  const tokenField = document.getElementById('recaptcha-token');
  const siteKey = window.recaptchaSiteKey;
  const action = window.recaptchaAction || 'login';

  if (!form || !tokenField || !siteKey) return;

  form.addEventListener('submit', function (e) {
    if (tokenField.value) return;

    e.preventDefault();
    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    grecaptcha.ready(function () {
      grecaptcha.execute(siteKey, { action })
        .then(function (token) {
          tokenField.value = token;
          form.submit();
        })
        .catch(function () {
          if (submitBtn) submitBtn.disabled = false;
          alert('Security verification failed. Please refresh and try again.');
        });
    });
  });
});