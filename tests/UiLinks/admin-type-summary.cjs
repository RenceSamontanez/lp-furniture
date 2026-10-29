/*
 * Writes docs/admin-type-screenshots/summary.md from the before / after reports of browser-admin-type.cjs
 * (and the output of compare-customer-html.php saved to a text file).
 *
 *   php tests/UiLinks/compare-customer-html.php > /tmp/cust-compare.txt
 *   node tests/UiLinks/admin-type-summary.cjs /tmp/cust-compare.txt
 */
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', '..', 'docs', 'admin-type-screenshots');
const A = JSON.parse(fs.readFileSync(path.join(dir, 'after', 'report.json'), 'utf8'));
const B = JSON.parse(fs.readFileSync(path.join(dir, 'before', 'report.json'), 'utf8'));
const cust = process.argv[2] && fs.existsSync(process.argv[2]) ? fs.readFileSync(process.argv[2], 'utf8').trim() : '(not provided)';

let md = '# Admin typography: before / after proof\n\nGenerated from `docs/admin-type-screenshots/{before,after}/report.json` by `tests/UiLinks/browser-admin-type.cjs` (before = commit 13c54f6 served from a worktree on :8012, after = this tree on :8011, same scratch database, headless Chrome at 1440, 1001 and 390 px, 15 pages).\n\n';
md += '## Overflow, clipping, arrows, contrast (15 pages x 3 widths)\n\n| Measure | Before | After |\n|---|---|---|\n';
const sum = (R, f) => Object.values(R.pages).reduce((n, p) => n + (Array.isArray(p[f]) ? p[f].length : (p[f] > 0 ? 1 : 0)), 0);
for (const [lab, f] of [['Pages with page-level horizontal overflow', 'overflowX'], ['Clipped text boxes (overflow hidden / ellipsis)', 'clipped'], ['Off-screen elements (not inside a scroller)', 'offscreen'], ['Arrows on buttons and links (text or icon)', 'arrows'], ['Text below 4.5:1 (3:1 for large text)', 'lowContrast']]) {
  md += `| ${lab} | ${sum(B, f)} | ${sum(A, f)} |\n`;
}
md += '\nThe two clipped boxes after are the top-bar page title at 390 px (`Order LP-2026-00001`, `Cancellations & Refunds`), cut by the existing `max-width: 40vw` ellipsis; they are clipped in the before run too and were left alone.\n\n';
md += '## Computed styles (1440 px)\n\n| Page | Element | Font | Size | Weight | Numerals |\n|---|---|---|---|---|---|\n';
for (const [pg, els] of Object.entries(A.styles)) {
  for (const [el, s] of Object.entries(els)) { if (s) { md += `| ${pg} | ${el} ("${s.text}") | ${s.family} | ${s.size} | ${s.weight} | ${s.numeric} |\n`; } }
}
md += '\n## Customer pages (HTML compared before / after)\n\n```\n' + cust + '\n```\n';
fs.writeFileSync(path.join(dir, 'summary.md'), md);
console.log(md.split('\n').slice(0, 14).join('\n'));
