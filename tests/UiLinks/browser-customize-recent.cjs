/*
 * Customize page: History button and Recent strip walk (SCRATCH DATABASE ONLY). A customer with designs and one without, at
 * 1366x768, 1440x900, 1920x1080 and 390x844: the strip stays on one line, the one-screen fit is unchanged, a thumbnail opens the
 * shared preview box, the links point to the right tabs, button contrast (tests/ButtonContrast/measure.js).
 * Screenshots go to docs/customize/screenshots/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_my_designs.php ; php tests/UiLinks/seed_customize_empty.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-customize-recent.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'customize', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');

(async () => {
  const c = await lib.start(9381);
  const report = { checks: [] };
  const note = (name, ok, detail) => { report.checks.push({ name, ok, detail }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), ...(email ? [email] : [])], { cwd: lib.ROOT, encoding: 'utf8' }));
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };

  const probe = () => c.evalJs(`(() => {
    const cz = document.getElementById('cz').getBoundingClientRect();
    const film = document.querySelector('.cz-film'), st = document.getElementById('cz-stage').getBoundingClientRect();
    const items = [...film.querySelectorAll('.cz-cap, .cz-f, .cz-film-empty, .cz-viewall')];
    const tops = items.map((e) => Math.round(e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2));
    const foot = document.querySelector('.cz-foot').getBoundingClientRect();
    const hist = document.querySelector('.cz-history');
    return { vh: innerHeight, czBottom: Math.round(cz.bottom), footBottom: Math.round(foot.bottom), filmInside: film.getBoundingClientRect().bottom <= st.bottom + 1,
      oneLine: Math.max(...tops) - Math.min(...tops) <= 8, thumbs: film.querySelectorAll('.cz-f').length, viewAll: !!film.querySelector('.cz-viewall'),
      empty: film.querySelector('.cz-film-empty') ? film.querySelector('.cz-film-empty').textContent : null,
      thumbSize: film.querySelector('.cz-f') ? Math.round(film.querySelector('.cz-f').getBoundingClientRect().height) : null,
      historyHref: hist ? hist.getAttribute('href') : null, historyW: hist ? Math.round(hist.getBoundingClientRect().width) : null, historyH: hist ? Math.round(hist.getBoundingClientRect().height) : null,
      historyText: hist ? hist.innerText.trim() : null, historyHasIcon: !!(hist && hist.querySelector('svg')),
      viewAllHref: film.querySelector('.cz-viewall') ? film.querySelector('.cz-viewall').getAttribute('href') : null,
      hOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()`);

  try {
    for (const [who, email] of [['with-designs', null], ['no-designs', 'no-designs-customer@example.test']]) {
      await c.setCookie(cookieFor(email));
      for (const [w, h, tag] of [[1366, 768, '1366x768'], [1440, 900, '1440x900'], [1920, 1080, '1920x1080'], [390, 844, '390x844']]) {
        await c.viewport(w, h);
        await c.go('/design');
        const p = await probe();
        const desktop = w >= 1000;
        note(`${who} ${tag}: strip on one line, inside the stage`, p.oneLine && p.filmInside, p);
        if (desktop) note(`${who} ${tag}: one-screen fit unchanged`, p.czBottom === p.vh && p.footBottom <= p.vh, { czBottom: p.czBottom, vh: p.vh, footBottom: p.footBottom });
        note(`${who} ${tag}: no horizontal overflow`, p.hOverflow <= 0);
        note(`${who} ${tag}: History button, clock icon, Customer-only, right tab`, /tab=history/.test(p.historyHref || '') && p.historyHasIcon, { href: p.historyHref, text: p.historyText, w: p.historyW, h: p.historyH });
        if (who === 'with-designs') {
          note(`${who} ${tag}: three thumbnails and View all -> Gallery tab`, p.thumbs === 3 && p.viewAll && /tab=gallery/.test(p.viewAllHref), { thumbs: p.thumbs, href: p.viewAllHref });
          if (!desktop) note(`${who} ${tag}: phone thumbnails are 44px`, p.thumbSize >= 44, p.thumbSize);
        } else {
          note(`${who} ${tag}: quiet empty line, no View all`, p.thumbs === 0 && !p.viewAll && p.empty === 'Your first designs will appear here', p);
        }
        if (!desktop) note(`${who} ${tag}: History collapses to the icon with an accessible label`, p.historyW <= 48 && p.historyText.includes('History'), p);
        await shot(`recent-${who}-${tag}`);
        const b = await c.evalJs(`${BUTTONS}`);
        note(`${who} ${tag}: button contrast (measure.js)`, b.failures.length === 0, b.failures.slice(0, 4));
      }
    }

    // thumbnail opens the shared preview box, keyboard works, focus returns
    await c.setCookie(cookieFor(null));
    await c.viewport(1440, 900);
    await c.go('/design');
    await c.evalJs(`document.querySelector('.cz-f').focus()`);
    const press = async (key, code, vk) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, text: key === 'Enter' ? '\r' : undefined }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk }); };
    await press('Enter', 'Enter', 13);
    await c.sleep(500);
    const m = await c.evalJs(`({ open: !document.getElementById('md-modal').hidden, img: document.getElementById('md-modal-img').getAttribute('src'), name: document.getElementById('md-modal-name').textContent, chip: document.getElementById('md-modal-chip').textContent,
      primary: [...document.querySelectorAll('.md-modal-acts .md-act-primary')].filter((e) => e.getClientRects().length).map((e) => e.innerText.trim()), navHidden: getComputedStyle(document.getElementById('site-nav')).visibility })`);
    note('thumbnail opens the shared glass preview box with image, name and one primary action', m.open && /ai-designs/.test(m.img) && m.primary.length === 1, m);
    await shot('recent-preview-open-1440x900');
    await press('Escape', 'Escape', 27);
    await c.sleep(300);
    note('Escape closes it and focus returns to the thumbnail', await c.evalJs(`document.getElementById('md-modal').hidden && document.activeElement.classList.contains('cz-f')`));
    const ring = await c.evalJs(`getComputedStyle(document.activeElement).outlineStyle + ' ' + getComputedStyle(document.activeElement).outlineWidth`);
    note('thumbnail focus ring is visible after keyboard use', /solid 3px/.test(ring), ring);

    // the ordered one shows the concept action, using isSentToTeam
    const ord = await c.evalJs(`(() => { const b = [...document.querySelectorAll('.cz-f')].find((e) => JSON.parse(e.dataset.design).ordered); if (!b) return null; b.click(); return [...document.querySelectorAll('.md-modal-acts .md-act-primary')].filter((e) => e.getClientRects().length).map((e) => e.innerText.trim()); })()`);
    note('a design already sent to the team shows the concept action instead of Order', ord === null || (ord.length === 1 && /concept/i.test(ord[0])), ord);

  } finally {
    fs.writeFileSync(path.join(OUT, '_recent-checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
