/*
 * Button contrast audit (Stage 21e standing UI rule). Paste into the browser console (or the
 * Browser pane's javascript tool) on any page; returns every button and link-as-button with its
 * measured WCAG contrast for each state: default, visited, hover, focus, active, disabled.
 *
 * Browsers cannot be asked "what is the colour while hovered" from script, so each state is
 * resolved by replaying the CSS cascade: every stylesheet rule whose selector (with that pseudo-class
 * removed) matches the element is collected, the highest specificity (then latest) wins per property,
 * and "inherit" is followed up the tree. Minimum required: 4.5 (text), 3 for disabled.
 */
(function () {
  const STATES = ['default', 'visited', 'hover', 'focus', 'active', 'disabled'];
  const PSEUDO = { visited: ':visited', hover: ':hover', focus: ':focus', active: ':active' };
  const ALL_PSEUDO = /:(visited|hover|focus|active|focus-visible|focus-within|disabled)/g;

  function spec(sel) {
    let s = sel.replace(/:not\(([^)]*)\)/g, ' $1 ');
    const ids = (s.match(/#[\w-]+/g) || []).length;
    const cls = (s.match(/\.[\w-]+|\[[^\]]+\]|:(?!:)[\w-]+/g) || []).length;
    const el = (s.replace(/#[\w-]+|\.[\w-]+|\[[^\]]+\]|::?[\w-]+(\([^)]*\))?/g, ' ').match(/(^|[\s>+~])[a-zA-Z][\w-]*/g) || []).length;
    return ids * 10000 + cls * 100 + el;
  }

  const rules = [];
  let order = 0;
  (function walk(list) {
    for (const r of list) {
      if (r.cssRules && !r.selectorText) {
        if (r.type === 4 && r.media && !window.matchMedia(r.media.mediaText).matches) continue;
        walk(r.cssRules);
      } else if (r.selectorText) {
        for (const sel of r.selectorText.split(',')) rules.push({ sel: sel.trim(), style: r.style, order: order++ });
      }
    }
  })([...document.styleSheets].flatMap(s => { try { return [...s.cssRules]; } catch (e) { return []; } }));

  function resolve(el, prop, state) {
    const key = prop;
    let best = null;
    for (const r of rules) {
      const v = r.style.getPropertyValue(key) || (key === 'background-color' ? r.style.getPropertyValue('background') : '');
      if (!v) continue;
      const hasState = Object.values(PSEUDO).concat([':focus-visible', ':disabled']).some(p => r.sel.includes(p));
      let test = r.sel;
      if (hasState) {
        const wanted = state === 'disabled' ? [':disabled'] : state === 'focus' ? [':focus'] : state === 'default' ? [] : [PSEUDO[state]];
        if (!wanted.some(p => r.sel.includes(p))) {
          // a rule that mentions a different state never applies here
          if (!(state === 'disabled' && r.sel.includes('[aria-disabled'))) continue;
        }
        test = r.sel.replace(ALL_PSEUDO, '');
      }
      if (state === 'disabled' && r.sel.includes('[aria-disabled')) test = r.sel.replace(ALL_PSEUDO, '');
      let ok = false;
      try { ok = el.matches(test); } catch (e) { continue; }
      if (state === 'disabled' && (el.disabled || el.getAttribute('aria-disabled') === 'true') === false) ok = false;
      if (!ok) continue;
      const score = spec(r.sel.replace(ALL_PSEUDO, m => m)); // pseudo-classes count toward specificity
      if (!best || score > best.score || (score === best.score && r.order > best.order)) best = { score, order: r.order, v };
    }
    return best ? best.v : null;
  }

  function parse(c) { const m = c.match(/[\d.]+/g).map(Number); return { r: m[0], g: m[1], b: m[2], a: m[3] === undefined ? 1 : m[3] }; }
  function lum(c) { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); }
  function ratio(a, b) { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
  function toRGB(v, el, prop, state) {
    if (!v || v === 'inherit') { return el.parentElement ? effective(el.parentElement, prop, 'default') : parse('rgb(255,255,255)'); }
    const probe = document.createElement('i'); probe.style.color = v; document.body.appendChild(probe);
    const c = getComputedStyle(probe).color; probe.remove(); return parse(c);
  }
  // A translucent background is blended over whatever is behind it.
  function over(c, cur) {
    if (c.a >= 1) return c;
    const b = cur.parentElement ? effective(cur.parentElement, 'background-color', 'default') : { r: 255, g: 255, b: 255, a: 1 };
    return { r: c.r * c.a + b.r * (1 - c.a), g: c.g * c.a + b.g * (1 - c.a), b: c.b * c.a + b.b * (1 - c.a), a: 1 };
  }
  function effective(el, prop, state) {
    let cur = el;
    while (cur) {
      const raw = resolve(cur, prop, cur === el ? state : 'default');
      if (prop === 'color') {
        if (raw && raw !== 'inherit') return toRGB(raw, cur, prop, state);
        if (!raw) { return parse(getComputedStyle(cur).color); }
      } else if (raw && raw !== 'transparent' && raw !== 'none' && raw !== 'initial' && raw !== 'unset' && raw !== 'inherit') {
        const c = toRGB(raw, cur, prop, state);
        if (c.a > 0) return over(c, cur);
      } else if (!raw) {
        const c = parse(getComputedStyle(cur).backgroundColor);
        if (c.a > 0) return over(c, cur);
      }
      cur = cur.parentElement;
    }
    return parse('rgb(255,255,255)');
  }

  const sel = 'button, input[type=submit], input[type=button], a[class*="btn"], a[class*="button"], [role=button], .lp-btn';
  const out = [];
  for (const el of document.querySelectorAll(sel)) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || el.offsetParent === null && cs.position !== 'fixed') continue;
    const h = Math.round(el.getBoundingClientRect().height);
    const row = { text: (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 40), cls: el.className.toString().slice(0, 50), height: h };
    const isDis = el.disabled || el.getAttribute('aria-disabled') === 'true';
    for (const st of STATES) {
      if (st === 'disabled' && !isDis) continue;
      if (st !== 'disabled' && isDis && st !== 'default') continue;
      const stateForResolve = (st === 'default' && isDis) ? 'disabled' : st;
      const fg = effective(el, 'color', stateForResolve);
      const bg = effective(el, 'background-color', stateForResolve);
      row[st] = +ratio(fg, bg).toFixed(2);
    }
    // Icon-only buttons (a hamburger drawn with <span> bars) have no text: measure the bars against the button's background.
    const bars = [...el.querySelectorAll('span')].filter(x => parse(getComputedStyle(x).backgroundColor).a > 0);
    if (!row.text.replace(/menu/i, '').trim() && bars.length) {
      const barBg = parse(getComputedStyle(bars[0]).backgroundColor);
      const bg = effective(el, 'background-color', 'default');
      const r = +ratio(barBg, bg).toFixed(2);
      row.icon = true;
      for (const st of STATES) { if (row[st] !== undefined) row[st] = r; }
    }
    const min = Math.min(...STATES.filter(s => row[s] !== undefined).map(s => row[s]));
    row.min = min;
    row.pass = min >= (isDis ? 3 : 4.5);
    out.push(row);
  }
  return { count: out.length, failures: out.filter(r => !r.pass), rows: out };
})();
