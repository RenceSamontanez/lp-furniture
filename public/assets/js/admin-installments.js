/* Admin Installments: fills the shared modals (Simulate, Mark Paid, Details) from data attributes on the clicked button. */
(function () {
  'use strict';

  function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

  function info(box, raw) {
    var p = String(raw || '').split('|');
    box.innerHTML = '<b>' + esc(p[0]) + '</b><br>' + esc(p[1] || '');
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }

    if (b.hasAttribute('data-ia-sim')) {
      var m = document.getElementById('ia-sim');
      m.querySelector('form').setAttribute('action', b.getAttribute('data-ia-sim'));
      m.querySelector('.lp-modal__title').textContent = b.getAttribute('data-ia-title') || 'Simulate Due Date';
      info(m.querySelector('[data-ia-info-box]'), b.getAttribute('data-ia-info'));
      var oc = m.querySelector('[data-ia-outcome-box]');
      if (oc) {
        // The choice only matters when a simulated saved card is about to be charged.
        var on = b.getAttribute('data-ia-outcome') === '1';
        oc.hidden = !on;
        oc.querySelectorAll('input').forEach(function (r, n) { r.disabled = !on; r.checked = (n === 0); });
      }
    }

    if (b.hasAttribute('data-ia-mark') && !b.hasAttribute('data-ia-detail')) {
      var mm = document.getElementById('ia-mark');
      mm.querySelector('form').setAttribute('action', b.getAttribute('data-ia-mark'));
      info(mm.querySelector('[data-ia-info-box]'), b.getAttribute('data-ia-info'));
    }

    if (b.hasAttribute('data-ia-detail')) {
      var d;
      try { d = JSON.parse(b.getAttribute('data-ia-detail')); } catch (err) { return; }
      var html = '<div class="ia-ex"><b>' + esc(d.title) + '</b><br>' + esc(d.line) + '<br>' + esc(d.method) +
        ' &middot; <span class="ist ' + esc(d.statusClass) + '">' + esc(d.status) + '</span></div>' +
        '<div class="ia-sh">History</div><div class="ia-step"><b>Scheduled</b><span>Due ' + esc(d.due) + '</span></div>';
      (d.steps || []).forEach(function (s) {
        html += '<div class="ia-step ' + esc(s.k) + '"><b>' + esc(s.t) + '</b><span>' + esc(s.w) + '</span></div>';
      });
      document.getElementById('ia-det-body').innerHTML = html;

      var mk = document.getElementById('ia-det-mark');
      mk.hidden = !d.canMark;
      mk.setAttribute('data-ia-mark', b.getAttribute('data-ia-mark'));
      mk.setAttribute('data-ia-info', d.title + '|' + d.line);
    }
  }, true);
})();
