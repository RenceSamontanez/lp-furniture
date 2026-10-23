/*
 * My Designs browser walk (SCRATCH DATABASE ONLY). Screenshots History / Gallery / Favorites and the preview box (ordered and not
 * ordered) at 1366x768, 1440x900 and 390x844 into docs/stage-designs-a-screenshots/, checks that the box fits one screen, keyboard
 * (tab arrows, focus trap, Escape returns focus), overflow, button contrast (tests/ButtonContrast/measure.js) and the contrast of the
 * chips / tabs / tags. Writes docs/stage-designs-a-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_my_designs.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-my-designs.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'stage-designs-a-screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');

const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
const lum = (h) => { const n = parseInt(h.slice(1), 16); return 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255); };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };

(async () => {
  const c = await lib.start(9361);
  const report = { checks: [], buttons: [], textContrast: [] };
  const note = (name, ok, detail) => { report.checks.push({ name, ok, detail }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  try {
    const cookie = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php')], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(cookie);

    const openModal = (ordered) => c.evalJs(`(() => { const b = [...document.querySelectorAll('.js-open-design[data-design]')].find((e) => e.matches('button') && JSON.parse(e.dataset.design).ordered === ${ordered}); if (!b) return false; b.focus(); b.click(); return true; })()`);
    const closeModal = () => c.evalJs(`document.getElementById('md-modal-close').click()`);

    for (const [w, h, tag] of [[1366, 768, '1366x768'], [1440, 900, '1440x900'], [390, 844, '390x844']]) {
      await c.viewport(w, h);
      for (const tab of ['history', 'gallery', 'favorites']) {
        await c.go('/design-gallery?tab=' + tab);
        const m = await c.evalJs(`({ overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, count: document.querySelector('[data-count="${tab}"]').textContent })`);
        note(`${tag} ${tab}: no horizontal overflow`, m.overflow <= 0, m);
        await shot(`${tab}-${tag}`);
        const b = await c.evalJs(`${BUTTONS}`);
        report.buttons.push({ tag, tab, count: b.count, failures: b.failures });
        note(`${tag} ${tab}: button contrast`, b.failures.length === 0, b.failures.slice(0, 4));
      }
      await c.go('/design-gallery?tab=gallery');
      for (const ordered of [false, true]) {
        const opened = await openModal(ordered);
        await c.sleep(300);
        const f = await c.evalJs(`(() => { const m = document.querySelector('.md-modal').getBoundingClientRect(); const d = document.querySelector('.md-modal-desc');
          const prim = [...document.querySelectorAll('.md-modal-acts .md-act-primary')].filter((e) => e.getClientRects().length).map((e) => e.innerText.trim());
          return { top: Math.round(m.top), bottom: Math.round(m.bottom), vh: innerHeight, modalScroll: document.querySelector('.md-modal').scrollHeight - document.querySelector('.md-modal').clientHeight,
            pageScroll: document.documentElement.scrollHeight - document.documentElement.clientHeight, bodyOverflow: getComputedStyle(document.body).overflow, chip: document.getElementById('md-modal-chip').innerText, prim,
            descScrolls: d.scrollHeight > d.clientHeight, focus: document.activeElement.id }; })()`);
        note(`${tag} modal (${ordered ? 'ordered' : 'not ordered'}): opens`, opened && f.focus === 'md-modal-close', f);
        if (w >= 981) note(`${tag} modal (${ordered ? 'ordered' : 'not ordered'}): fits one screen, box does not scroll`, f.top >= 0 && f.bottom <= f.vh && f.modalScroll <= 0, f);
        note(`${tag} modal (${ordered ? 'ordered' : 'not ordered'}): one primary action`, f.prim.length === 1 && f.prim[0] === (ordered ? 'View concept' : 'Order this design'), f.prim);
        await shot(`preview-${ordered ? 'ordered' : 'not-ordered'}-${tag}`);
        const b = await c.evalJs(`${BUTTONS}`);
        note(`${tag} modal (${ordered ? 'ordered' : 'not ordered'}): button contrast`, b.failures.length === 0, b.failures.slice(0, 4));
        await closeModal();
      }
    }

    // keyboard: tab arrows, trap, Escape + focus return
    await c.viewport(1366, 768);
    await c.go('/design-gallery?tab=history');
    await c.evalJs(`document.getElementById('md-tab-history').focus()`);
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 });
    await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 });
    await c.sleep(1800);
    note('ArrowRight moves History -> Gallery and keeps focus on the tab', await c.evalJs(`location.search.includes('tab=gallery') && document.activeElement.id === 'md-tab-gallery'`));

    await openModal(false);
    await c.sleep(200);
    const press = async (key, code, vk, mods = 0) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, modifiers: mods }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers: mods }); };
    const seen = new Set();
    for (let i = 0; i < 12; i++) { await press('Tab', 'Tab', 9); seen.add(await c.evalJs(`document.querySelector('#md-modal').contains(document.activeElement)`)); }
    note('Tab stays inside the open box (12 presses)', seen.size === 1 && seen.has(true), [...seen]);
    const seen2 = new Set();
    for (let i = 0; i < 6; i++) { await press('Tab', 'Tab', 9, 8); seen2.add(await c.evalJs(`document.querySelector('#md-modal').contains(document.activeElement)`)); }
    note('Shift+Tab stays inside the open box', seen2.size === 1 && seen2.has(true), [...seen2]);
    const opener = await c.evalJs(`(document.querySelector('.md-card .md-thumb-btn') ? 'ok' : 'none')`);
    await press('Escape', 'Escape', 27);
    await c.sleep(200);
    note('Escape closes the box and focus returns to the control that opened it', await c.evalJs(`document.getElementById('md-modal').hidden && document.activeElement.classList.contains('js-open-design') && document.body.style.overflow === ''`) && opener === 'ok');

    // reduced motion
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await c.go('/design-gallery?tab=history');
    note('reduced motion: no animation on the page', await c.evalJs(`[...document.querySelectorAll('.md-wrap *')].every((e) => getComputedStyle(e).animationName === 'none' || getComputedStyle(e).animationDuration === '0s')`));
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    // text contrast of chips, tabs and tags (flat colours from the stylesheet)
    for (const [name, fg, bg] of [
      ['Ready chip', '#1d6b45', '#dff1e6'], ['Failed chip', '#a1262b', '#fbdede'], ['Generating chip', '#7a5a12', '#f6ecc9'],
      ['Failed reason on card', '#a1262b', '#fdfaf5'], ['Muted text on card', '#5f4c3f', '#fdfaf5'], ['Prompt text', '#2c1810', '#fdfaf5'],
      ['Selected tab', '#fdfaf5', '#2c1810'], ['Tab count (selected)', '#ffffff', '#5a4b45'], ['Tab count (idle)', '#2c1810', '#e6dccd'],
      ['Idle tab', '#5f4c3f', '#fdfaf5'], ['Filter chip idle', '#5f4c3f', '#fdfaf5'], ['Day heading', '#5f4c3f', '#f5f0e8'],
      ['CUSTOM DESIGN tag', '#ffffff', '#2a1812'], ['Saved bookmark icon on dark', '#e9b75a', '#2c1810'],
    ]) { const r = ratio(fg, bg); report.textContrast.push({ name, fg, bg, ratio: r }); note('text contrast ' + name, r >= 4.5, r); }
  } finally {
    fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
