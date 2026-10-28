/*
 * Checkout: the early layer in front of the server's own checks (App\Support\OrderContact stays the source of truth).
 *  - Place Order pressed with details missing: nothing is sent. One warning notice (the shared LpToast), the first incomplete
 *    step opens, its first missing field is focused and outlined with its message, and every incomplete step title shows
 *    "Needs Attention". Fixing a field clears its outline, message and tag; fixing everything dismisses the notice.
 *  - "Continue to Place Order" (last step): when everything is complete the summary scrolls into view and Place Order gets
 *    the "ready" state (gold glow + green tag). Going to an earlier step or changing required data clears it.
 * Works on desktop (accordion) and on the mobile wizard (it opens the incomplete screen instead).
 */
(function () {
  'use strict';

  var form = document.getElementById('checkout-form');
  if (!form) { return; }
  var summary = document.getElementById('checkout-summary');
  var readyTag = document.getElementById('ckn-ready-tag');
  var placeBtn = document.getElementById('place-order-btn');
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var STEPS = { delivery: { screen: 'screen-delivery', name: 'Delivery Option' } };
  var WHAT = { name: 'the recipient name', phone: 'your phone number', address: 'your delivery address' };

  var showing = false;      // the person already tried: keep tags and notice in step with the fields
  var notice = null;

  function problems() {
    var list = (window.MAOrder && window.MAOrder.problems) ? window.MAOrder.problems() : [];
    return list.map(function (p) { return { key: p.key, step: 'delivery' }; });
  }

  function setTags() {
    var bad = {};
    problems().forEach(function (p) { bad[p.step] = true; });
    Object.keys(STEPS).forEach(function (k) {
      var screen = document.getElementById(STEPS[k].screen);
      if (!screen) { return; }
      var titles = screen.querySelectorAll('.ckn-section-title, .checkout-screen-title');
      for (var i = 0; i < titles.length; i++) {
        var t = titles[i], tag = t.querySelector('.ckn-need');
        if (showing && bad[k]) {
          if (!tag) {
            tag = document.createElement('span');
            tag.className = 'ckn-need';
            tag.textContent = 'Needs Attention';
            t.appendChild(tag);
          }
        } else if (tag) { tag.parentNode.removeChild(tag); }
      }
    });
  }

  function dismissNotice() {
    if (notice && window.LpToast) { window.LpToast.dismiss(notice); }
    notice = null;
  }

  function line(list) {
    var first = list[0];
    var text = 'Add ' + WHAT[first.key] + ' in ' + STEPS[first.step].name + '.';
    if (list.length > 1) {
      var more = list.length - 1;
      text = text.slice(0, -1) + ' and ' + more + ' more thing' + (more === 1 ? '' : 's') + ' to check.';
    }
    return text;
  }

  function openStep(k) {
    var id = STEPS[k].screen;
    if (window.isDesktop && window.isDesktop()) {
      if (window.openSection) { window.openSection('delivery'); }
    } else {
      var open = document.querySelector('.checkout-screen.open');
      if (!open || open.id !== id) {
        if (open) { open.classList.remove('open'); }
        var el = document.getElementById(id);
        if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
      }
    }
  }

  function setReady(on) {
    if (!summary) { return; }
    summary.classList.toggle('is-ready', on);
    if (readyTag) { readyTag.textContent = on ? 'Your order is ready to be placed.' : ''; }
  }

  // what Place Order does when something is missing; returns true when it stopped the order
  function blockEarly() {
    var list = problems();
    if (!list.length) { return false; }
    showing = true;
    setReady(false);
    openStep(list[0].step);
    if (window.MAOrder) { window.MAOrder.showErrors(); }
    setTags();
    dismissNotice();
    if (window.LpToast) { notice = window.LpToast.show('warning', line(list), "You can't place your order yet"); }
    setTimeout(function () { if (window.MAOrder) { window.MAOrder.focusFirst(); } }, 0);
    return true;
  }

  function refresh() {
    var list = problems();
    if (list.length) { setReady(false); }
    if (showing) {
      setTags();
      if (!list.length) { dismissNotice(); showing = false; }
    }
  }

  window.readyToPlace = function () {
    if (blockEarly()) { return; }
    showing = false; setTags();
    setReady(true);
    if (summary) { summary.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' }); }
    if (placeBtn) { try { placeBtn.focus({ preventScroll: true }); } catch (e) { placeBtn.focus(); } }
  };

  // Back on the desktop steps: same as the old top button, one step earlier
  window.goBackTo = function (name) {
    setReady(false);
    if (window.openSection) { window.openSection(name); }
    var el = document.getElementById('screen-' + name);
    if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' }); }
  };

  // Place Order from either button: capture phase so it runs before the details block's own submit handler
  form.addEventListener('submit', function (e) {
    if (blockEarly()) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  // required data changed, or a step was opened/closed: the ready state no longer holds
  ['input', 'change'].forEach(function (ev) {
    form.addEventListener(ev, function () { setTimeout(refresh, 0); });
  });
  form.addEventListener('click', function (e) {
    if (e.target.closest('.ckn-row-header, .review-edit-btn, .checkout-screen-backrow button')) { setReady(false); }
  });
})();
