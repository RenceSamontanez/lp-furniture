/*
 * Button contrast (tests/ButtonContrast/measure.js, 4.5:1 per state) on My Account with each Personal info card open, the
 * name notice, the saving state and the Security tab. SCRATCH DATABASE ONLY (see browser-personal-cards.cjs for the setup).
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const seed = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'account-seed.json'), 'utf8').replace(/^﻿/, ''));
const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, 'web'], { cwd: lib.ROOT, encoding: 'utf8' }));

(async () => {
  const c = await lib.start(9900 + Math.floor(Math.random() * 90));
  const out = [];
  try {
    await c.viewport(1440, 900);
    await c.setCookie(cookieFor(seed.users.saved));
    for (const [label, js] of [
      ['read-only', ''],
      ['name+notice open', `document.querySelector('#s-name [data-edit]').click(); document.getElementById('fn').value='Zed'; document.querySelector('#s-name [data-save]').click();`],
      ['phone open', `document.querySelector('#s-phone [data-edit]').click();`],
      ['address open', `document.querySelector('#s-addr [data-edit]').click();`],
      ['saving', `window.fetch = () => new Promise(() => {}); document.querySelector('#s-addr [data-save]').click();`],
    ]) {
      await c.go('/profile?tab=personal');
      if (js) { await c.evalJs(`(() => { ${js} })()`); await c.sleep(500); }
      const r = await c.evalJs(BUTTONS);
      out.push({ state: label, buttons: r.count, failing: r.failures.length, failures: r.failures.map((f) => ({ text: f.text, cls: f.cls, min: f.min })) });
    }
    await c.go('/profile?tab=security');
    const r = await c.evalJs(BUTTONS);
    out.push({ state: 'security', buttons: r.count, failing: r.failures.length, failures: r.failures.map((f) => ({ text: f.text, cls: f.cls, min: f.min })) });
  } finally { c.close(); }
  console.log(JSON.stringify(out, null, 1));
  process.exit(out.some((o) => o.failing) ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
