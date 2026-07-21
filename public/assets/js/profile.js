/* ============================================================
   LP Furniture — Profile Page JS
   Tab switching between Personal, Security, Verification, Account
   ============================================================ */
   document.addEventListener('DOMContentLoaded', function () {

    const btns   = document.querySelectorAll('[data-profile-tab]');
    const panels = document.querySelectorAll('[data-profile-panel]');
  
    function switchTab(tab) {
      btns.forEach(b => b.classList.toggle('is-active', b.dataset.profileTab === tab));
      panels.forEach(p => p.classList.toggle('is-active', p.dataset.profilePanel === tab));
    }
  
    btns.forEach(btn => {
      btn.addEventListener('click', () => switchTab(btn.dataset.profileTab));
    });
  
    // Auto-switch to security tab if password errors
    const defaultPanel = window.profileDefaultPanel;
    if (defaultPanel) switchTab(defaultPanel);
  });