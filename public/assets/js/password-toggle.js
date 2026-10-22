/* Show/Hide for every password field (<x-password-toggle for="field-id"/>). One delegated listener; it only flips the
   input's type and the button's label, and never reads, stores or sends the typed value. */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-password-toggle]') : null;
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-password-toggle'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
})();
