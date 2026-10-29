/* Writes docs/admin-modals-screenshots/summary.md from report.json of browser-admin-modals.cjs (proof mode). */
const fs = require('fs'); const path = require('path');
const dir = path.join(__dirname, '..', '..', 'docs', 'admin-modals-screenshots');
const R = JSON.parse(fs.readFileSync(path.join(dir, 'report.json'), 'utf8')).pages;
let md = '# Admin modal pages: proof\n\nFrom `browser-admin-modals.cjs` (proof mode): 12 states x 3 widths (1440, 1001, 390), the page scrolled before each modal opens.\n\n| State @ width | Overflow px | Clipped | Low contrast | Modal visible | Above sidebar + top bar | Above toasts | Footer buttons visible | Bottom sheet |\n|---|---|---|---|---|---|---|---|---|\n';
for (const [k, v] of Object.entries(R)) {
  const m = v.modal;
  md += `| ${k} | ${v.layout.overflowX} | ${v.layout.clipped.length} | ${v.layout.lowContrast.length} | ${m ? m.boxInViewport : '-'} | ${m ? m.aboveSidebar && m.aboveTopbar : '-'} | ${m ? m.aboveToasts : '-'} | ${m ? m.footerVisible : '-'} | ${m && k.endsWith('@390') ? m.sheet : '-'} |\n`;
}
md += '\n## Computed styles (1440 px)\n\n| State | Element | Font | Size | Weight |\n|---|---|---|---|---|\n';
for (const [k, v] of Object.entries(R)) { if (!k.endsWith('@1440') || !v.styles) continue; for (const [el, s] of Object.entries(v.styles)) { if (s) md += `| ${k} | ${el} ("${s.text}") | ${s.family} | ${s.size} | ${s.weight} |\n`; } }
fs.writeFileSync(path.join(dir, 'summary.md'), md); console.log(md.split('\n').slice(0, 8).join('\n'));
