// Pixel-compares two folders of jpg/png screenshots in headless Chrome (canvas): node --experimental-websocket tests/UiLinks/image-diff.cjs <before-dir> <after-dir>
const fs = require('fs'); const path = require('path');
const { start } = require('./cdp-lib.cjs');
(async () => {
  const [A, B] = process.argv.slice(2);
  const c = await start(9381); await c.send('Page.navigate', { url: 'about:blank' });
  for (const f of fs.readdirSync(A).filter((x) => /\.(jpg|png)$/.test(x)).sort()) {
    if (!fs.existsSync(path.join(B, f))) { console.log(f, 'missing in after'); continue; }
    const mime = f.endsWith('png') ? 'png' : 'jpeg';
    const a = fs.readFileSync(path.join(A, f)).toString('base64'), b = fs.readFileSync(path.join(B, f)).toString('base64');
    const r = await c.evalJs(`(async () => { const load = (s) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = 'data:image/${mime};base64,' + s; }); const [x, y] = [await load('${a}'), await load('${b}')]; if (x.width !== y.width || x.height !== y.height) return 'size ' + x.width + 'x' + x.height + ' vs ' + y.width + 'x' + y.height; const cv = (i) => { const k = document.createElement('canvas'); k.width = i.width; k.height = i.height; const g = k.getContext('2d'); g.drawImage(i, 0, 0); return g.getImageData(0, 0, i.width, i.height).data; }; const p = cv(x), q = cv(y); let n = 0, minX = 1e9, maxX = -1, minY = 1e9, maxY = -1; for (let o = 0; o < p.length; o += 4) { if (Math.abs(p[o] - q[o]) + Math.abs(p[o + 1] - q[o + 1]) + Math.abs(p[o + 2] - q[o + 2]) > 60) { n++; const px = (o / 4) % x.width, py = Math.floor(o / 4 / x.width); minX = Math.min(minX, px); maxX = Math.max(maxX, px); minY = Math.min(minY, py); maxY = Math.max(maxY, py); } } return n ? n + ' px differ, box x ' + minX + '-' + maxX + ' y ' + minY + '-' + maxY : 'identical'; })()`);
    console.log(f.padEnd(44), r);
  }
  c.close();
})();
