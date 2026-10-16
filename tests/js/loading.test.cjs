// Runs public/assets/js/co-loading.js against a small fake DOM.
// Exit code 0 = every check passed. Used by tests/Feature/GlobalLoadingTest.php.
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/co-loading.js'), 'utf8');

function el(tag, attrs = {}) {
  const e = {
    tagName: tag.toUpperCase(), attrs: { ...attrs }, children: [], classes: new Set(), style: {
      props: {}, setProperty(k, v) { this.props[k] = v; }, removeProperty(k) { delete this.props[k]; },
    },
    innerHTML: attrs.html || '', disabled: false, name: attrs.name, value: attrs.value, hidden: false,
    getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; },
    setAttribute(n, v) { this.attrs[n] = String(v); },
    removeAttribute(n) { delete this.attrs[n]; },
    hasAttribute(n) { return n in this.attrs; },
    classList: null,
    appendChild(c) { this.children.push(c); c.parent = this; },
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((x) => x !== this); },
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
    querySelectorAll(sel) {
      const all = []; const walk = (n) => n.children.forEach((c) => { all.push(c); walk(c); }); walk(this);
      return all.filter((c) => match(c, sel));
    },
    closest(sel) { let n = this; while (n) { if (match(n, sel)) return n; n = n.parent; } return null; },
  };
  e.classList = {
    add: (c) => e.classes.add(c), remove: (c) => e.classes.delete(c),
    contains: (c) => e.classes.has(c), toggle: (c, on) => (on ? e.classes.add(c) : e.classes.delete(c)),
  };
  return e;
}

function match(n, sel) {
  return sel.split(',').map((s) => s.trim()).some((s) => {
    if (s === 'a[href]') return n.tagName === 'A' && n.hasAttribute('href');
    if (s === '[data-co-original]') return n.hasAttribute('data-co-original');
    if (s === 'form[data-submitting]') return n.tagName === 'FORM' && n.hasAttribute('data-submitting');
    if (s === 'a[data-loading-active]') return n.tagName === 'A' && n.hasAttribute('data-loading-active');
    if (s === 'input[data-co-hidden]') return n.tagName === 'INPUT' && n.hasAttribute('data-co-hidden');
    if (s === 'button[type="submit"]') return n.tagName === 'BUTTON' && n.getAttribute('type') === 'submit';
    if (s === 'button:not([type])') return n.tagName === 'BUTTON' && !n.hasAttribute('type');
    if (s === 'input[type="submit"]') return n.tagName === 'INPUT' && n.getAttribute('type') === 'submit';
    return false;
  });
}

let timers = [];
global.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
global.clearTimeout = () => {};
const runTimers = (max = 1000) => { const t = timers.filter((x) => x.ms <= max); timers = timers.filter((x) => x.ms > max); t.forEach((x) => x.fn()); };

function setup() {
  timers = [];
  const listeners = { submit: [], click: [] };
  const bar = el('div'); bar.hidden = true;
  const overlayEl = el('div'); overlayEl.hidden = true;
  const html = el('html');
  const winListeners = {};
  global.window = {
    location: { origin: 'http://localhost', pathname: '/page', search: '' },
    getComputedStyle: () => ({ backgroundColor: 'rgb(1, 2, 3)', color: 'rgb(255, 255, 255)' }),
    addEventListener: (e, f) => { winListeners[e] = f; },
  };
  global.document = {
    documentElement: html,
    getElementById: (id) => ({ 'lp-progress': bar, 'lp-pay-overlay': overlayEl })[id] || null,
    createElement: (tag) => el(tag),
    querySelectorAll: () => [],
    addEventListener: (e, f, capture) => { listeners[e].push({ f, capture: !!capture }); },
  };
  new Function('window', 'document', 'setTimeout', 'clearTimeout', source)(global.window, global.document, global.setTimeout, global.clearTimeout);
  const all = [];
  global.document.querySelectorAll = (sel) => all.filter((n) => match(n, sel))
    .concat(all.flatMap((n) => n.querySelectorAll(sel)));
  const ctx = { bar, overlayEl, html, all, winListeners, page: null };
  // capture listeners, then the page's own handler (if any), then bubble listeners
  ctx.fire = (type, ev) => {
    const run = (cap) => listeners[type].filter((l) => l.capture === cap).forEach((l) => l.f(ev));
    run(true);
    if (ctx.page) ctx.page(ev);
    run(false);
  };
  return ctx;
}

function makeForm(action, method = 'post', extra = {}) {
  const f = el('form', { action, method, ...extra });
  const b = el('button', { type: 'submit', html: 'Pay now' });
  f.appendChild(b);
  return { f, b };
}

function submit(ctx, f, b, prevented = false) {
  const ev = { target: f, submitter: b, defaultPrevented: prevented, preventDefault() { this.defaultPrevented = true; } };
  ctx.fire('submit', ev);
  runTimers();
  return ev;
}

// 1. a normal POST form: spinner, label, disabled, bar running
{
  const ctx = setup();
  const { f, b } = makeForm('/admin/orders/5/notes');
  ctx.all.push(f);
  submit(ctx, f, b);
  assert(b.disabled, 'disabled');
  assert(b.innerHTML.includes('co-spinner'), 'spinner');
  assert(b.classList.contains('co-loading'));
  assert.strictEqual(ctx.bar.hidden, false, 'bar visible');
  assert.strictEqual(ctx.overlayEl.hidden, true, 'no overlay for a non-payment form');
  assert.strictEqual(b.style.props['--co-bg'], 'rgb(1, 2, 3)', 'colours kept for the loading state');
}

// 2. payment actions get the overlay and the payment label
for (const [action, label] of [
  ['/payment/stripe/7/checkout', 'Processing payment...'],
  ['/payment/gcash/paymongo/7/checkout', 'Processing payment...'],
  ['/order', 'Processing payment...'],
  ['/custom-orders/9/checkout', 'Processing payment...'],
  ['/design/3/quotes/4/accept', 'Processing...'],
  ['/admin/orders/5/payments', 'Recording payment...'],
]) {
  const ctx = setup();
  const { f, b } = makeForm(action);
  ctx.all.push(f);
  submit(ctx, f, b);
  assert.strictEqual(ctx.overlayEl.hidden, false, 'overlay for ' + action);
  assert(b.innerHTML.includes(label), action + ' label ' + b.innerHTML);
  assert(ctx.html.classes.has('lp-overlay-open'), 'page scroll locked');
}

// 3. other labels
{
  const ctx = setup();
  const { f, b } = makeForm('/admin/custom-orders/4/call-update');
  ctx.all.push(f);
  submit(ctx, f, b);
  assert(b.innerHTML.includes('Sending...'));
}

// 4. a second submit while one is in flight is blocked
{
  const ctx = setup();
  const { f, b } = makeForm('/payment/stripe/7/checkout');
  ctx.all.push(f);
  submit(ctx, f, b);
  const second = submit(ctx, f, b);
  assert(second.defaultPrevented, 'second submit prevented');
}

// 5. a cancelled submit (confirm() said no, AJAX) is left alone
{
  const ctx = setup();
  const { f, b } = makeForm('/design/3/quotes/4/decline');
  ctx.all.push(f);
  submit(ctx, f, b, true);
  assert(!b.disabled && !b.classList.contains('co-loading'));
  assert.strictEqual(b.innerHTML, 'Pay now');
  assert.strictEqual(b.getAttribute('data-co-original'), null, 'nothing left remembered');
  assert.strictEqual(ctx.overlayEl.hidden, true);
  assert.strictEqual(ctx.bar.hidden, true);
}

// 6. data-no-loading is respected
{
  const ctx = setup();
  const { f, b } = makeForm('/payment/stripe/7/checkout', 'post', { 'data-no-loading': '' });
  ctx.all.push(f);
  submit(ctx, f, b);
  assert(!b.disabled && ctx.overlayEl.hidden && ctx.bar.hidden);
}

// 7. GET forms: progress bar only, nothing disabled
{
  const ctx = setup();
  const { f, b } = makeForm('/catalog', 'get');
  ctx.all.push(f);
  submit(ctx, f, b);
  assert(!b.disabled, 'search button stays usable');
  assert.strictEqual(ctx.bar.hidden, false);
  assert.strictEqual(ctx.overlayEl.hidden, true);
}

// 8. a named submit button keeps its value
{
  const ctx = setup();
  const f = el('form', { action: '/x', method: 'post' });
  const b = el('button', { type: 'submit', name: 'action', value: 'approve', html: 'Approve' });
  f.appendChild(b); ctx.all.push(f);
  submit(ctx, f, b);
  assert(f.children.some((c) => c.tagName === 'INPUT' && c.name === 'action' && c.value === 'approve'));
}

// 9. the page's own handler rewrote and disabled the button first (checkout does):
//    the true original is what comes back
{
  const ctx = setup();
  const { f, b } = makeForm('/order');
  b.innerHTML = 'Place Order';
  ctx.all.push(f);
  ctx.page = () => { b.disabled = true; b.innerHTML = 'Placing Order...'; };
  submit(ctx, f, b);
  assert(b.innerHTML.includes('Processing payment...'), 'final label wins');
  ctx.winListeners.pageshow({ persisted: true });
  assert.strictEqual(b.innerHTML, 'Place Order', 'restored to the original');
  assert(!b.disabled && !b.classList.contains('co-loading'));
}

// 10. back/forward cache restore resets everything, a normal pageshow does nothing
{
  const ctx = setup();
  const { f, b } = makeForm('/payment/stripe/7/checkout');
  ctx.all.push(f);
  submit(ctx, f, b);
  ctx.winListeners.pageshow({ persisted: false });
  assert(b.disabled, 'ordinary pageshow leaves the loading state alone');
  ctx.winListeners.pageshow({ persisted: true });
  assert(!b.disabled && b.innerHTML === 'Pay now');
  assert.strictEqual(f.getAttribute('data-submitting'), null);
  assert.strictEqual(ctx.overlayEl.hidden, true);
  assert.strictEqual(ctx.bar.hidden, true);
  assert(!ctx.html.classes.has('lp-overlay-open'));
}

// 11. links: data-loading gives the spinner, the payment overlay is opt-in, others only the bar
{
  const ctx = setup();
  const a = el('a', { href: '/custom-orders/9/checkout', 'data-loading': 'Opening payment...', 'data-loading-overlay': 'payment', html: 'Proceed to payment' });
  a.origin = 'http://localhost'; a.pathname = '/custom-orders/9/checkout'; a.search = '';
  ctx.all.push(a);
  const ev = { target: a, defaultPrevented: false, button: 0, preventDefault() { this.defaultPrevented = true; } };
  ctx.fire('click', ev);
  assert(a.innerHTML.includes('co-spinner') && a.innerHTML.includes('Opening payment...'));
  assert.strictEqual(ctx.overlayEl.hidden, false);
  const again = { target: a, defaultPrevented: false, button: 0, preventDefault() { this.defaultPrevented = true; } };
  ctx.fire('click', again);
  assert(again.defaultPrevented, 'second click on the same link ignored');
  ctx.winListeners.pageshow({ persisted: true });
  assert.strictEqual(a.innerHTML, 'Proceed to payment');

  const plain = el('a', { href: '/catalog', html: 'Catalog' });
  plain.origin = 'http://localhost'; plain.pathname = '/catalog'; plain.search = '';
  ctx.all.push(plain);
  ctx.fire('click', { target: plain, defaultPrevented: false, button: 0, preventDefault() {} });
  assert.strictEqual(plain.innerHTML, 'Catalog', 'plain links are not rewritten');
  assert.strictEqual(ctx.bar.hidden, false, 'but the bar runs');
}

// 12. modified clicks, new tabs, anchors and external links do nothing
{
  const ctx = setup();
  const mk = (attrs, extra = {}) => { const a = el('a', { href: '/x', ...attrs }); a.origin = 'http://localhost'; a.pathname = '/x'; a.search = ''; Object.assign(a, extra); ctx.all.push(a); return a; };
  const cases = [
    [mk({}), { metaKey: true }], [mk({}), { ctrlKey: true }], [mk({ target: '_blank' }), {}],
    [mk({ href: '#top' }), {}], [mk({}, { origin: 'https://example.com' }), {}], [mk({ download: '' }), {}],
  ];
  for (const [a, mods] of cases) ctx.fire('click', { target: a, defaultPrevented: false, button: 0, preventDefault() {}, ...mods });
  assert.strictEqual(ctx.bar.hidden, true, 'no bar for any of those');
}

// 13. loaded twice, registers once
{
  const ctx = setup();
  new Function('window', 'document', 'setTimeout', 'clearTimeout', source)(global.window, global.document, global.setTimeout, global.clearTimeout);
  const { f, b } = makeForm('/x');
  ctx.all.push(f);
  const ev = submit(ctx, f, b);
  assert(!ev.defaultPrevented, 'a second copy of the script must not block the first submit');
}

console.log('co-loading.js ok');
