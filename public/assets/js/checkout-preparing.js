/* ============================================================
   CHECKOUT — "Preparing Your Order" loading screen controller
   ============================================================ */
   document.addEventListener('DOMContentLoaded', function () {

    const wrap = document.getElementById('ckn-preparing');
    if (!wrap) return;
  
    setTimeout(function () {
      wrap.classList.add('ckn-preparing-done');
    }, 1500);
  });
  
  /* ============================================================
     PUSH TRANSITION from Preparing → Review Your Order.
     Both screens are full-viewport fixed panels. The Review
     screen is first snapped to a starting position just off the
     right edge (no visible movement yet), then — once the browser
     has registered that starting position — both screens animate
     simultaneously: Preparing slides fully out to the left while
     Review slides in to fill the center. Since both panels always
     cover 100% of the viewport throughout, there is never a gap
     where the underlying page could show through.
     ============================================================ */
  function startCheckoutTransition() {
    const wrap = document.getElementById('ckn-preparing');
    const review = document.getElementById('screen-review');
    if (!wrap || !review) return;
  
    // Make Review visible and snap it off-screen to the right —
    // still invisible to the user, sitting directly beneath the
    // (still fully visible) Preparing screen.
    review.classList.add('open', 'ckn-push-target');
    document.body.style.overflow = 'hidden';
  
    // Force the browser to register that starting position before
    // we change it — without this, the browser would collapse the
    // "start" and "end" positions into a single instant jump
    // instead of animating between them.
    void review.offsetWidth;
  
    // Now trigger both animations at the same time.
    wrap.classList.add('ckn-preparing-push-out');
    review.classList.add('ckn-push-in');
  
    setTimeout(function () {
      wrap.style.display = 'none';
      wrap.classList.remove('ckn-preparing-push-out');
      review.classList.remove('ckn-push-target', 'ckn-push-in');
    }, 320); // slightly longer than the .3s CSS transition, to be safe
  }
  
  /* ============================================================
     GENERIC PUSH TRANSITION — used for every row-to-row "Next"
     step (Review→Delivery, Delivery→Payment Type, and so on).
     Same technique as startCheckoutTransition() above, just
     reusable for any pair of .checkout-screen panels. Desktop is
     completely unaffected — it never calls this function at all
     (its accordion uses toggleSection()/goNext() instead).
     ============================================================ */
  function pushToScreen(fromId, toId) {
    const from = document.getElementById('screen-' + fromId);
    const to   = document.getElementById('screen-' + toId);
    if (!to) return;
  
    to.classList.add('open', 'ckn-push-target');
    document.body.style.overflow = 'hidden';
  
    void to.offsetWidth;
  
    if (from) from.classList.add('ckn-push-out');
    to.classList.add('ckn-push-in');
  
    setTimeout(function () {
      if (from) {
        from.classList.remove('open', 'ckn-push-out');
      }
      to.classList.remove('ckn-push-target', 'ckn-push-in');
    }, 320);
  }
  
  /* ============================================================
     REVERSE PUSH — used for every "Back" button (Delivery→Review,
     Payment Type→Delivery, and so on). Mirror image of
     pushToScreen(): the current screen slides OUT to the right,
     the previous screen slides IN from the left.
     ============================================================ */
  function pushBackToScreen(fromId, toId) {
    const from = document.getElementById('screen-' + fromId);
    const to   = document.getElementById('screen-' + toId);
    if (!to) return;
  
    to.classList.add('open', 'ckn-push-target-back');
    document.body.style.overflow = 'hidden';
  
    void to.offsetWidth;
  
    if (from) from.classList.add('ckn-push-out-back');
    to.classList.add('ckn-push-in-back');
  
    setTimeout(function () {
      if (from) {
        from.classList.remove('open', 'ckn-push-out-back');
      }
      to.classList.remove('ckn-push-target-back', 'ckn-push-in-back');
    }, 320);
  }