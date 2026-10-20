/*
 * Link audit + measured contrast (Stage 21h). Paste into the browser console (or the Browser pane's javascript tool).
 * Returns { pass, links, withoutStyle, contrast } for the current page:
 *   - withoutStyle: inline links inside page content that are neither .lp-link nor a button (.lp-btn / .admin-btn) nor a
 *     card-as-link or navigation element. pass is false when any remain. (tests/UiLinks/audit.php checks the Blade source.)
 *   - contrast: for every .lp-link on the page, the contrast of its text colour against the real background behind it, per
 *     state: default, visited, hover, focus. The state colours come from the stylesheet itself: the --link and --link-hover
 *     variables the .lp-link rules use (the script also confirms those rules exist). Required minimum 4.5:1.
 */
(function () {
  const root = getComputedStyle(document.documentElement);
  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const hex = (h) => { h = h.trim(); const n = parseInt(h.slice(1), 16); return { r: n >> 16, g: (n >> 8) & 255, b: n & 255, a: 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
  const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0.95) return c; } return { r: 255, g: 255, b: 255, a: 1 }; };

  const link = hex(root.getPropertyValue('--link') || '#6A3E1E');
  const linkHover = hex(root.getPropertyValue('--link-hover') || '#2C1810');

  // do the rules exist?
  let hasRules = { base: false, hover: false, focus: false, visited: false };
  for (const sheet of document.styleSheets) {
    let rules; try { rules = sheet.cssRules; } catch (e) { continue; }
    for (const r of rules) {
      const t = r.selectorText || '';
      if (t.includes('.lp-link')) {
        if (t.includes(':hover')) hasRules.hover = true;
        else if (t.includes(':focus-visible')) hasRules.focus = true;
        else if (t.includes(':visited')) hasRules.visited = true;
        else hasRules.base = true;
      }
    }
  }

  const content = document.querySelector('main, #notif-detail, .co-page, .si-wrap, .nd-wrap, .admin-main') || document.body;
  const skip = 'nav, header, footer, .site-nav, .sn-links, .admin-sidebar, .admin-sidebar *, .chatbot-window';
  const allowed = '.lp-btn, .admin-btn, .lp-link, .co-count, .si-invoice, .notif-action-cta, .notif-history-btn, .btn, .pagination a, .page-link, [role="button"], .logo, .nav-logo';
  const withoutStyle = [...content.querySelectorAll('a[href]')].filter((a) => !a.closest(skip) && !a.matches(allowed) && !a.closest('.lp-link'))
    .map((a) => ({ text: a.textContent.trim().slice(0, 60), href: a.getAttribute('href'), cls: a.className }));

  const contrast = [...document.querySelectorAll('a.lp-link, .lp-link a, button.lp-link')].map((a) => {
    const bg = bgOf(a);
    const states = { default: ratio(link, bg), visited: ratio(link, bg), hover: ratio(linkHover, bg), focus: ratio(link, bg) };
    const underline = getComputedStyle(a).textDecorationLine.includes('underline');
    return { text: a.textContent.trim().slice(0, 40), bg: `rgb(${bg.r},${bg.g},${bg.b})`, underline, ...Object.fromEntries(Object.entries(states).map(([k, v]) => [k, +v.toFixed(2)])), ok: Object.values(states).every((v) => v >= 4.5) && underline };
  });

  const pass = withoutStyle.length === 0 && contrast.every((c) => c.ok) && hasRules.base && hasRules.hover && hasRules.focus;
  return { pass, hasRules, links: document.querySelectorAll('a.lp-link, .lp-link a, button.lp-link').length, withoutStyle, contrast };
})();
