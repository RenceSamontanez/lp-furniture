/*
 * JS twin of App\Support\TitleCase for text the page builds itself (notification titles, status labels).
 * Same rules: only an all-lowercase word is capitalised, small words stay lowercase unless first or last, words with digits or
 * symbols, brands, acronyms and units are left alone. Exceptions come from config/titlecase.php through window.LP_TITLECASE.
 */
(function () {
  'use strict';
  var cfg = window.LP_TITLECASE || {};
  var SMALL = cfg.small_words || ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'as', 'at', 'by', 'in', 'of', 'on', 'to', 'up', 'with', 'from', 'via', 'vs', 'vs.'];
  var KEEP = cfg.keep || ['cm', 'mm', 'kg', 'm', 'ft', 'pcs', 'x', 'stripe'];
  var KEEP_AS = cfg.keep_as || { gcash: 'GCash', paymongo: 'PayMongo', lp: 'LP', id: 'ID', pdf: 'PDF', faq: 'FAQ', ph: 'PH', otp: 'OTP' };

  function word(w, edge) {
    var up = w === w.toUpperCase() && w.length > 1, low = w.toLowerCase();
    if (!edge && SMALL.indexOf(low) !== -1 && !up) { return low; }
    if (w !== low) { return w; }
    return w.charAt(0).toUpperCase() + w.slice(1);
  }
  function token(t, edge) {
    var m = /^([^\p{L}\p{N}]*)([\s\S]*?)([^\p{L}\p{N}]*)$/u.exec(t);
    if (!m || m[2] === '' || (/&$/.test(m[1]) && /^;/.test(m[3]))) { return t; }
    var core = m[2];
    if (/[0-9@\/\:_#%&=+*]/.test(core)) { return t; }
    var low = core.toLowerCase();
    if (Object.prototype.hasOwnProperty.call(KEEP_AS, low)) { return m[1] + KEEP_AS[low] + m[3]; }
    if (KEEP.indexOf(low) !== -1) { return t; }
    var bits = core.split(/(-)/), n = bits.length;
    for (var k = 0; k < n; k++) {
      if (bits[k] !== '-' && bits[k] !== '') { bits[k] = word(bits[k], edge && (k === 0 || k === n - 1)); }
    }
    return m[1] + bits.join('') + m[3];
  }
  function apply(text) {
    text = text == null ? '' : String(text);
    if (!text.trim()) { return text; }
    var parts = text.split(/(\s+)/), idx = [];
    parts.forEach(function (p, i) { if (p && !/^\s+$/.test(p) && /\p{L}/u.test(p)) { idx.push(i); } });
    var afterColon = false;
    idx.forEach(function (i, n) {
      var edge = n === 0 || n === idx.length - 1 || afterColon;
      afterColon = /:$/.test(parts[i]);
      parts[i] = token(parts[i], edge);
    });
    return parts.join('');
  }
  window.LpTitleCase = { apply: apply };
})();
