/*
 * Admin notifications list: no overflow, 44px buttons, aligned rows, visible keyboard focus at 1440 and 390.
 * SCRATCH DATABASE ONLY (server on 127.0.0.1:8011 started with DB_DATABASE=furniture_db_test, admin + notifications seeded).
 * Screenshots go to docs/stage-admin-screenshots/.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

(async () => {
  const b = await start(9352);
  const OUT = path.join(ROOT, 'docs', 'stage-admin-screenshots');
  fs.mkdirSync(OUT, { recursive: true });
  const shot = async (name) => { const r = await b.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookie = JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.admin_email, 'admin'], { cwd: ROOT, encoding: 'utf8' }));
  const bad = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

  for (const w of [1440, 390]) {
    await b.viewport(w, 900);
    await b.setCookie(cookie);
    await b.go('/admin/notifications');
    const m = await b.evalJs(`(() => {
      const open = [...document.querySelectorAll('a[aria-label^="Open notification"]')];
      const mark = [...document.querySelectorAll('button[aria-label^="Mark read"]')];
      const r = (e) => { const x = e.getBoundingClientRect(); return { l: x.left, r: x.right, t: x.top, w: x.width, h: x.height }; };
      return {
        scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
        open: open.map(r), mark: mark.map(r),
        oldLink: document.body.innerText.includes('Open custom order'),
        footerLinks: !!document.querySelector('.lp-footer--admin a'),
      };
    })()`);
    note(m.scrollW <= m.innerW, `${w}: no horizontal overflow (${m.scrollW} <= ${m.innerW})`);
    note(m.open.length === 4, `${w}: 4 Open notification buttons (rows with a destination), got ${m.open.length}`);
    note(m.mark.length === 3, `${w}: 3 Mark read buttons (unread rows), got ${m.mark.length}`);
    note([...m.open, ...m.mark].every((x) => x.h >= 44), `${w}: all buttons >= 44px tall`);
    note([...m.open, ...m.mark].every((x) => x.l >= 0 && x.r <= m.innerW), `${w}: buttons inside the viewport`);
    note(new Set(m.open.map((x) => Math.round(x.l))).size === 1 && new Set(m.open.map((x) => Math.round(x.w))).size === 1, `${w}: Open buttons share left edge and width`);
    note(!m.oldLink && !m.footerLinks, `${w}: old text link and footer links gone`);
    await shot(`admin-notifications-${w}`);

    // keyboard focus: tab to the first Open notification link and read its outline
    await b.evalJs(`document.querySelector('a[aria-label^="Open notification"]').focus()`);
    await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Shift' });
    await b.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Shift' });
    const f = await b.evalJs(`(() => { const s = getComputedStyle(document.activeElement); return { o: s.outlineStyle + ' ' + s.outlineWidth, sh: s.boxShadow }; })()`);
    note(!/^none/.test(f.o) || f.sh !== 'none', `${w}: focus indicator present (${f.o}, shadow ${f.sh.slice(0, 40)})`);
    await shot(`admin-notifications-${w}-focus`);
  }
  b.close();
  console.log(bad.length ? `\n${bad.length} CHECK(S) FAILED` : '\nall ok');
  process.exit(bad.length ? 1 : 0);
})();
