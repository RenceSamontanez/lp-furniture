/*
 * Customize studio browser walk (SCRATCH DATABASE ONLY). Screenshots the page at 1366x768, 1440x900, 1920x1080 and 390x844
 * into docs/customize/screenshots/, checks that the studio fits the screen below the shared nav without any inner or page-level
 * scrolling on the three desktop sizes, walks the five steps with the keyboard and mouse, the Other wood and Something else paths,
 * the photo upload checks, button contrast (tests/ButtonContrast/measure.js), and writes docs/customize/screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-customize.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'customize', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');

const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
const lum = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
const ratio = (a, b) => { const x = lum(...a), y = lum(...b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };

(async () => {
  const c = await lib.start(9371);
  const report = { checks: [], buttons: [] };
  const note = (name, ok, detail) => { report.checks.push({ name, ok, detail }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const click = (sel) => c.evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return false; e.click(); return true; })()`);
  const setVal = (sel, v) => c.evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.value = ${JSON.stringify(v)}; e.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);

  const waitText = async (sel, re, ms = 8000) => { const t0 = Date.now(); let t = ''; while (Date.now() - t0 < ms) { t = await c.evalJs(`document.querySelector(${JSON.stringify(sel)}).innerText`); if (re.test(t)) return t; await c.sleep(250); } return t; };
  const press = async (key, code, vk, mods = 0) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, modifiers: mods }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers: mods }); };

  // fit report: where the studio sits, whether anything scrolls
  const fit = () => c.evalJs(`(() => {
    const cz = document.getElementById('cz'), r = cz.getBoundingClientRect();
    const nav = document.getElementById('site-nav').getBoundingClientRect();
    const innerScrollers = [...cz.querySelectorAll('*')].filter((e) => { const s = getComputedStyle(e); return (s.overflowY === 'auto' || s.overflowY === 'scroll') && e.scrollHeight > e.clientHeight + 1; }).map((e) => e.className || e.tagName);
    const clipped = [...cz.querySelectorAll('.cz-step.on, .cz-body, .cz-panel')].filter((e) => e.scrollHeight > e.clientHeight + 1).map((e) => e.className + ' ' + (e.scrollHeight - e.clientHeight));
    const foot = document.querySelector('.cz-foot').getBoundingClientRect(); const st = document.getElementById('cz-stage').getBoundingClientRect(); const film = document.querySelector('.cz-film').getBoundingClientRect(); const brief = document.querySelector('.cz-brief');
    return { vh: innerHeight, navBottom: Math.round(nav.bottom), czTop: Math.round(r.top), czBottom: Math.round(r.bottom), czHeight: Math.round(r.height),
      footBottom: Math.round(foot.bottom), stageFilmInside: film.bottom <= st.bottom + 1, briefFits: brief.scrollHeight <= brief.clientHeight + 1, docHeight: document.documentElement.scrollHeight, hOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      innerScrollers, clipped }; })()`);

  try {
    const cookie = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php')], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(cookie);

    for (const [w, h, tag] of [[1366, 768, '1366x768'], [1440, 900, '1440x900'], [1920, 1080, '1920x1080']]) {
      await c.viewport(w, h);
      await c.go('/design');
      const errs = await c.evalJs(`window.__errs || 0`);
      let f = await fit();
      note(`${tag}: studio starts right under the nav and ends at the bottom of the screen`, Math.abs(f.czTop - f.navBottom) <= 14 && f.czBottom <= f.vh + 1 && f.czBottom >= f.vh - 1, f);
      note(`${tag}: no horizontal overflow`, f.hOverflow <= 0);

      // walk every step and measure each one (nothing may need scrolling inside the studio)
      await shot(`step1-empty-${tag}`);
      await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Sofa"]').click()`);
      f = await fit();
      note(`${tag} step 1: fits, nothing scrolls`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Other"]').click()`);
      await setVal('#cz-type-text', 'Staircase railing');
      await c.sleep(900);
      f = await fit();
      note(`${tag} step 1 (Something else open, note shown): fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step1-something-else-${tag}`);

      await click('#cz-next');
      await c.evalJs(`document.querySelector('input[name="preferred_material"][value="Mahogany"]').click()`);
      await c.sleep(300);
      f = await fit();
      note(`${tag} step 2: fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step2-wood-${tag}`);
      await c.evalJs(`document.querySelector('input[name="preferred_material"][value="Special request"]').click()`);
      await setVal('#cz-wood-name', 'Narra');
      await c.sleep(900);
      f = await fit();
      note(`${tag} step 2 (Other wood open, options and note shown): fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step2-other-wood-${tag}`);
      await c.evalJs(`document.querySelector('input[name="wood_supply"][value="customer"]').click()`);

      await click('#cz-next');
      await c.evalJs(`document.querySelector('input[name="style_preference"][value="Rustic"]').click()`);
      await c.sleep(300);
      f = await fit();
      note(`${tag} step 3: fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step3-style-${tag}`);

      await click('#cz-next');
      await c.sleep(300);
      f = await fit();
      note(`${tag} step 4 (size): fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step4-size-${tag}`);

      await click('#cz-next');
      await c.sleep(300);
      await setVal('#cz-desc', 'Six-seater dining table, rectangular, thick rounded edges, natural matte finish.');
      await c.sleep(300);
      f = await fit();
      note(`${tag} step 5 (describe): fits`, f.innerScrollers.length === 0 && f.clipped.length === 0 && f.footBottom <= f.vh && f.stageFilmInside && f.briefFits, f);
      await shot(`step5-describe-${tag}`);

      const b = await c.evalJs(`${BUTTONS}`);
      report.buttons.push({ tag, count: b.count, failures: b.failures });
      note(`${tag}: button contrast (measure.js)`, b.failures.length === 0, b.failures.slice(0, 5));
      note(`${tag}: studio buttons found by the audit`, b.count > 5, b.count);
    }

    // ───── phone ─────
    await c.viewport(390, 844);
    await c.go('/design');
    const ph = await c.evalJs(`({ hOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, czHeight: Math.round(document.getElementById('cz').getBoundingClientRect().height), vh: innerHeight })`);
    note('390: no horizontal overflow, page scrolls normally', ph.hOverflow <= 0, ph);
    await shot('step1-390x844');
    await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Bed"]').click()`);
    await click('#cz-next');
    await c.evalJs(`document.querySelector('input[name="preferred_material"][value="Acacia"]').click()`);
    await click('#cz-next'); await c.sleep(200);
    await shot('step3-390x844');
    await click('#cz-next'); await click('#cz-next'); await c.sleep(200);
    await setVal('#cz-desc', 'Queen size bed with a tall headboard and under-bed storage, natural finish.');
    await shot('step5-390x844');
    const small = await c.evalJs(`[...document.querySelectorAll('#cz a, #cz button, #cz label.cz-tile, #cz label.cz-wood')].filter((e) => e.getClientRects().length && e.offsetHeight > 0 && e.offsetHeight < 44 && !e.classList.contains('lp-btn--chip') && !e.classList.contains('cz-st')).map((e) => e.className + ':' + e.offsetHeight)`);
    note('390: buttons are at least 44px (chips excepted)', small.length === 0, small.slice(0, 6));
    const b390 = await c.evalJs(`${BUTTONS}`);
    note('390: button contrast', b390.failures.length === 0, b390.failures.slice(0, 5));

    // ───── behaviour at 1440x900 ─────
    await c.viewport(1440, 900);
    await c.go('/design');
    const start = await c.evalJs(`({ next: document.getElementById('cz-next').disabled, create: document.getElementById('cz-create').hidden, need: document.getElementById('cz-need').textContent })`);
    note('step 1 starts with Next disabled and a plain reason', start.next === true && start.create === true && /Pick a furniture type/.test(start.need), start);

    // Something else: empty blocks, link blocked, valid continues
    await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Other"]').click()`);
    let s2 = await c.evalJs(`({ next: document.getElementById('cz-next').disabled, open: !document.getElementById('cz-type-other').hidden })`);
    note('Something else opens the text box and blocks Next until typed', s2.next && s2.open, s2);
    await setVal('#cz-type-text', 'visit shop.com now');
    s2 = await c.evalJs(`({ next: document.getElementById('cz-next').disabled, msg: document.getElementById('cz-type-msg').textContent })`);
    note('a link in Something else is refused with a message', s2.next && /not allowed/.test(s2.msg), s2);
    await setVal('#cz-type-text', 'Gazebo');
    s2 = await c.evalJs(`document.getElementById('cz-next').disabled`);
    note('a plain name continues', s2 === false);

    // answered entry is shown back
    execFileSync('php', ['-r', `putenv('DB_DATABASE=furniture_db_test'); $_ENV['DB_DATABASE']='furniture_db_test'; require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!str_ends_with(config('database.connections.mysql.database'), '_test')) { exit(99); } $r = App\\Models\\UnansweredIdea::record('furniture_type', 'Staircase railing'); $r->update(['status' => 'answered', 'answer' => 'We build railings with solid wood posts. Our team will confirm the length and height by phone.']);`], { cwd: lib.ROOT });
    await setVal('#cz-type-text', 'Staircase railing');
    const kb = await waitText('#cz-type-note', /built this before/);
    note('an answered entry is shown to the customer', /built this before/.test(kb) && /solid wood posts/.test(kb), kb);
    await setVal('#cz-type-text', 'Pergola');
    const nw = await waitText('#cz-type-note', /New to us/);
    note('an unknown name shows the "New to us" note', /New to us/.test(nw), nw);

    // keyboard: radios by arrow keys inside the group, focus ring visible on a tile
    await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Other"]').focus()`);
    await press('ArrowUp', 'ArrowUp', 38); await press('Tab', 'Tab', 9, 8); await press('Tab', 'Tab', 9);
    await c.evalJs(`document.querySelector('input[name="furniture_type"]:checked') && 0`);
    const ring = await c.evalJs(`(() => { const a = document.activeElement; const f = a.nextElementSibling; const s = getComputedStyle(f); return { outline: s.outlineStyle + ' ' + s.outlineWidth, focused: a.name + '=' + a.value }; })()`);
    note('keyboard focus shows an outline on the tile', /solid 3px/.test(ring.outline), ring);
    await c.evalJs(`document.querySelector('input[name="furniture_type"][value="Sofa"]').click()`);

    // wood: Other wood needs name and supply
    await click('#cz-next');
    await c.evalJs(`document.querySelector('input[name="preferred_material"][value="Special request"]').click()`);
    let w = await c.evalJs(`({ next: document.getElementById('cz-next').disabled, open: !document.getElementById('cz-wood-special').hidden, need: document.getElementById('cz-need').textContent })`);
    note('Other wood opens its panel and blocks Next', w.next && w.open && /Type the wood/.test(w.need), w);
    await setVal('#cz-wood-name', 'Teak');
    w = await c.evalJs(`({ next: document.getElementById('cz-next').disabled, need: document.getElementById('cz-need').textContent })`);
    note('after the name it still asks who supplies the wood', w.next && /who gets the wood/.test(w.need), w);
    await c.evalJs(`document.querySelector('input[name="wood_supply"][value="shop"]').click()`);
    w = await c.evalJs(`document.getElementById('cz-next').disabled`);
    note('after choosing, Next works', w === false);
    const notes = await waitText('#cz-wood-note', /check this wood/);
    note('an unknown wood shows the "We will check this wood" note', /check this wood/.test(notes), notes);

    // style: pick, pick again to clear
    await click('#cz-next');
    await c.evalJs(`document.querySelector('input[name="style_preference"][value="Modern"]').click()`);
    const picked = await c.evalJs(`document.querySelector('input[name="style_preference"]:checked')?.value`);
    note('a style can be picked', picked === 'Modern', picked);
    const placeholders = await c.evalJs(`document.querySelectorAll('.cz-ph-tag').length`);
    note('style cards carry the PLACEHOLDER mark', placeholders === 5, placeholders);

    // photo checks: wrong type, then a real tiny PNG, via the page's own code path
    const rejected = await c.evalJs(`(async () => {
      const dt = new DataTransfer(); dt.items.add(new File([new Uint8Array([71,73,70,56,57,97,1,0,1,0,0,0,0,59])], 'a.jpg', { type: 'image/jpeg' }));
      const i = document.getElementById('cz-photo'); i.files = dt.files; i.dispatchEvent(new Event('change', { bubbles: true }));
      await new Promise((r) => setTimeout(r, 600));
      return { msg: document.getElementById('cz-up-msg').textContent, kept: i.files.length };
    })()`);
    note('a GIF renamed .jpg is refused by its content, with a message', /not a JPG or PNG/.test(rejected.msg) && rejected.kept === 0, rejected);
    const accepted = await c.evalJs(`(async () => {
      const cv = document.createElement('canvas'); cv.width = 3000; cv.height = 2000; const g = cv.getContext('2d');
      for (let i = 0; i < 400; i++) { g.fillStyle = 'rgb(' + (i * 7 % 255) + ',' + (i * 13 % 255) + ',' + (i * 29 % 255) + ')'; g.fillRect((i * 37) % 3000, (i * 53) % 2000, 400, 300); }
      const blob = await new Promise((r) => cv.toBlob(r, 'image/png'));
      const dt = new DataTransfer(); dt.items.add(new File([blob], 'big.png', { type: 'image/png' }));
      const i = document.getElementById('cz-photo'); i.files = dt.files; i.dispatchEvent(new Event('change', { bubbles: true }));
      await new Promise((r) => setTimeout(r, 1500));
      const f = i.files[0];
      return { before: blob.size, after: f ? f.size : null, type: f ? f.type : null, lab: document.getElementById('cz-up-lab').textContent, tag: [...document.querySelectorAll('.cz-tag')].map((e) => e.textContent) };
    })()`);
    note('a large PNG is resized to a JPEG under 5 MB and shown', accepted.after && accepted.after < 5 * 1024 * 1024 && accepted.type === 'image/jpeg' && accepted.tag.includes('Reference photo'), accepted);
    await shot('step3-photo-1440x900');
    await click('#cz-up-x');
    const cleared = await c.evalJs(`document.getElementById('cz-photo').files.length`);
    note('Remove photo clears the file', cleared === 0);

    // describe: guide, ideas adapt, meter
    await click('#cz-next'); await click('#cz-next');
    const ideas1 = await c.evalJs(`[...document.querySelectorAll('#cz-ideas button')].map((b) => b.textContent)`);
    note('quick ideas follow the type and the style (Sofa + Modern)', ideas1.includes('+ soft rounded cushions') && ideas1.includes('+ clean straight legs'), ideas1);
    await click('#cz-ideas button');
    const added = await c.evalJs(`document.getElementById('cz-desc').value`);
    note('tapping an idea adds it to the description', added === 'soft rounded cushions', added);
    await setVal('#cz-desc', 'A cozy sofa. Contact me at juan@example.com');
    const bad = await c.evalJs(`({ create: document.getElementById('cz-create').disabled, msg: document.getElementById('cz-desc-msg').textContent })`);
    note('an email in the description blocks Create with a message', bad.create && /not allowed/.test(bad.msg), bad);
    await setVal('#cz-desc', 'A cozy sofa with soft rounded cushions, solid wood legs and a natural matte finish.');
    const good = await c.evalJs(`document.getElementById('cz-create').disabled`);
    note('a good description enables Create my design', good === false);

    // How it works opens the shared modal
    await click('[data-lp-modal-open="cz-howitworks"]');
    await c.sleep(300);
    const modal = await c.evalJs(`({ open: !document.getElementById('cz-howitworks').hidden, role: document.querySelector('#cz-howitworks [role=dialog]').getAttribute('aria-modal') })`);
    note('How it works opens the shared .lp-modal', modal.open && modal.role === 'true', modal);
    await shot('how-it-works-1440x900');
    await c.evalJs(`document.querySelector('#cz-howitworks [data-lp-modal-close]').click()`);

    // reduced motion
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await c.go('/design');
    const rm = await c.evalJs(`[...document.querySelectorAll('#cz *')].every((e) => { const s = getComputedStyle(e); return s.animationName === 'none' || parseFloat(s.animationDuration) === 0; })`);
    note('prefers-reduced-motion: no animation inside the studio', rm);
    await c.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    // contrast of body text on the glass panel and the stage (flat sampling of computed colours over the page background)
    await c.go('/design');
    const px = await c.evalJs(`(() => { const out = {}; const cols = (el, p) => getComputedStyle(el)[p]; const q = (s) => document.querySelector(s);
      out.h1 = cols(q('.cz-panel h1'), 'color'); out.hint = cols(q('.cz-q'), 'color'); out.muted = cols(q('.cz-sub') || q('.cz-hint'), 'color'); out.panelBg = cols(q('.cz-panel'), 'backgroundColor');
      out.stageText = cols(q('.cz-bd'), 'color'); out.live = cols(q('.cz-live'), 'color'); return out; })()`);
    console.log('computed', JSON.stringify(px));
    report.computed = px;
  } finally {
    fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
