/*
 * Concept page browser walk (SCRATCH DATABASE ONLY). Screenshots a request just sent, a quote ready and a paid order at 1366x768,
 * 1440x900 and 390x844 into docs/stage-designs-b-screenshots/, checks that everything fits one screen on desktop (content ends
 * inside the viewport), horizontal overflow, button contrast (tests/ButtonContrast/measure.js), keyboard focus and flat text contrast.
 * Writes docs/stage-designs-b-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_concept.php                              (prints the ids)
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-concept.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'stage-designs-b-screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
const lum = (h) => { const n = parseInt(h.slice(1), 16); return 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255); };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };

(async () => {
  const ids = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'seed_concept.php')], { cwd: lib.ROOT, encoding: 'utf8' }).trim().split('\n').pop());
  const c = await lib.start(9371);
  const report = { checks: [], buttons: [], textContrast: [] };
  const note = (name, ok, detail) => { report.checks.push({ name, ok, detail }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  try {
    await c.setCookie(JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php')], { cwd: lib.ROOT, encoding: 'utf8' })));
    for (const [w, h, tag] of [[1366, 768, '1366x768'], [1440, 900, '1440x900'], [390, 844, '390x844']]) {
      await c.viewport(w, h);
      for (const [name, id] of Object.entries(ids)) {
        await c.go('/design/' + id);
        const m = await c.evalJs(`(() => { const r = (s) => document.querySelector(s).getBoundingClientRect(); const strip = r('.cc-contact'); const cols = document.querySelector('.cc-cols');
          const tl = document.querySelector('.cc-timeline'); const pr = document.querySelector('.cc-prompt');
          return { overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth, stripBottom: Math.round(strip.bottom), vh: innerHeight, chip: document.querySelector('.cc-chip').innerText,
            banner: document.querySelector('.lp-notice__title').innerText, colsH: Math.round(cols.getBoundingClientRect().height),
            timelineScrolls: tl ? tl.scrollHeight > tl.clientHeight + 1 : false, promptScrolls: pr.scrollHeight > pr.clientHeight + 1,
            dropdown: !!document.querySelector('.ai-prompt-toggle') }; })()`);
        note(`${tag} ${name}: no horizontal overflow`, m.overflowX <= 0, m);
        if (w >= 981) {
          note(`${tag} ${name}: header to contact strip fit one screen`, m.stripBottom <= m.vh, { stripBottom: m.stripBottom, vh: m.vh });
          note(`${tag} ${name}: timeline needs no inner scroll`, !m.timelineScrolls);
        }
        note(`${tag} ${name}: old prompt dropdown gone`, !m.dropdown);
        await shot(`${name}-${tag}`);
        const b = await c.evalJs(`${BUTTONS}`);
        report.buttons.push({ tag, name, count: b.count, failures: b.failures });
        note(`${tag} ${name}: button contrast`, b.failures.length === 0, b.failures.slice(0, 4));
      }
    }
    // keyboard: first Tab stops reach the back button, banner buttons, thumbs, description, contact links with a visible focus ring
    await c.viewport(1366, 768);
    await c.go('/design/' + ids.paid);
    const stops = [];
    for (let i = 0; i < 40; i++) {
      await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      stops.push(await c.evalJs(`(() => { const e = document.activeElement; const cs = getComputedStyle(e); return (e.className || e.tagName).toString().split(' ')[0] + '|' + (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 || cs.boxShadow !== 'none'); })()`));
    }
    const has = (k) => stops.some((s) => s.startsWith(k));
    note('keyboard reaches back, banner buttons, thumbs, description and contact links', ['lp-btn', 'cc-thumb', 'cc-prompt', 'lp-link'].every(has), stops.slice(0, 18));
    note('every focused control shows a focus ring', stops.filter((s) => s.startsWith('cc-') || s.startsWith('lp-')).every((s) => s.endsWith('|true')), stops.filter((s) => s.endsWith('|false')));
    // reduced motion
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await c.go('/design/' + ids.paid);
    note('reduced motion: no animation or long transition', await c.evalJs(`[...document.querySelectorAll('.cc-wrap *')].every((e) => { const s = getComputedStyle(e); return (s.animationName === 'none' || s.animationDuration === '0s') && parseFloat(s.transitionDuration) === 0 || s.transitionDuration === '0s'; })`));
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    for (const [name, fg, bg] of [
      ['Ready/paid chip', '#1d6b45', '#dff1e6'], ['Gold chip', '#7a5210', '#f6e7c6'], ['Bad chip', '#a1262b', '#fbdede'], ['Muted chip', '#4f4238', '#ebe4da'],
      ['Timeline date', '#5f4c3f', '#fdfaf5'], ['Timeline detail', '#5f4c3f', '#fdfaf5'], ['Caption text', '#5f4c3f', '#f5f0e8'], ['Description text', '#1a1208', '#ffffff'],
      ['Success banner title', '#17301a', '#e7f0e2'], ['Success banner text', '#29432a', '#e7f0e2'], ['Waiting banner text', '#4a3426', '#fbf1da'],
    ]) { const r = ratio(fg, bg); report.textContrast.push({ name, fg, bg, ratio: r }); note('text contrast ' + name, r >= 4.5, r); }
  } finally {
    fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
