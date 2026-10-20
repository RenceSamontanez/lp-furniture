// Runs public/assets/js/alerts.js against a tiny fake DOM and a fake clock.
// Exit code 0 = every check passed. Used by tests/Feature/AlertToastTest.php.
const fs = require('fs');
const path = require('path');
const assert = require('assert');

let now = 0;
let nextId = 1;
const timers = new Map();
global.setTimeout = (fn, ms) => { const id = nextId++; timers.set(id, { fn, at: now + ms }); return id; };
global.clearTimeout = (id) => { timers.delete(id); };
const realDate = Date;
global.Date = class extends realDate { static now() { return now; } };
function advance(ms) {
  const end = now + ms;
  for (;;) {
    const due = [...timers.entries()].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
    if (!due) break;
    now = due[1].at; timers.delete(due[0]); due[1].fn();
  }
  now = end;
}

function makeToast(host, timeoutMs, cls = 'lp-toast lp-toast--success') {
  const listeners = {};
  const closeListeners = {};
  const closeBtn = { addEventListener: (e, f) => { closeListeners[e] = f; } };
  const toast = {
    parentNode: host,
    classes: new Set(cls.split(' ')),
    classList: {
      add: (c) => toast.classes.add(c),
      contains: (c) => toast.classes.has(c),
      toggle: (c, on) => { on ? toast.classes.add(c) : toast.classes.delete(c); },
    },
    getAttribute: (n) => (n === 'data-lp-toast-timeout' && timeoutMs !== undefined ? String(timeoutMs) : null),
    addEventListener: (e, f) => { listeners[e] = f; },
    querySelector: () => closeBtn,
    contains: () => false,
    matches: () => false,
    getBoundingClientRect: () => ({ width: 0 }),
    fire: (e) => listeners[e] && listeners[e](),
    click: () => closeListeners.click(),
  };
  host.children.push(toast);
  return toast;
}

function makeHost() {
  const host = {
    id: 'lp-toasts',
    children: [],
    removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; },
    querySelectorAll(sel) {
      if (sel === '[data-lp-toast]') return this.children;
      if (sel === '.lp-toast') return this.children;
      if (sel === '.lp-toast:not(.is-leaving)') return this.children.filter((c) => !c.classes.has('is-leaving'));
      return [];
    },
  };
  return host;
}

function load(host) {
  global.window = { matchMedia: () => ({ matches: false }), addEventListener() {} };
  global.requestAnimationFrame = (f) => f();
  global.cancelAnimationFrame = () => {};
  global.document = { readyState: 'complete', getElementById: (id) => (id === 'lp-toasts' ? host : null), addEventListener() {}, elementsFromPoint: () => [], activeElement: null };
  new Function(fs.readFileSync(path.join(__dirname, '../../public/assets/js/alerts.js'), 'utf8'))();
}

// 1. closes by itself after 5 seconds (leaving class first, removed shortly after); the container stays
{
  const host = makeHost(); const toast = makeToast(host, 5000);
  load(host);
  advance(4900);
  assert.strictEqual(toast.parentNode, host, 'still there at 4.9s');
  advance(150);
  assert(toast.classes.has('is-leaving'), 'starts leaving at 5s');
  advance(300);
  assert.strictEqual(toast.parentNode, null, 'removed after the slide out');
}

// 2. hover pauses the countdown, leaving resumes it with the time that was left
{
  const host = makeHost(); const toast = makeToast(host, 5000);
  load(host);
  advance(4000);
  toast.fire('mouseenter');
  advance(60000);
  assert(!toast.classes.has('is-leaving'), 'paused while hovered');
  toast.fire('mouseleave');
  advance(900);
  assert(!toast.classes.has('is-leaving'), 'resumes with the remaining ~1s');
  advance(200);
  assert(toast.classes.has('is-leaving'), 'closes after the remaining time');
}

// 3. keyboard focus pauses too
{
  const host = makeHost(); const toast = makeToast(host, 5000);
  load(host);
  toast.fire('focusin');
  advance(20000);
  assert(!toast.classes.has('is-leaving'), 'paused while focused');
  toast.fire('focusout');
  advance(5100);
  assert(toast.classes.has('is-leaving'));
}

// 4. close button dismisses immediately and the timer does not fire again
{
  const host = makeHost(); const toast = makeToast(host, 5000);
  load(host);
  advance(1000);
  toast.click();
  assert(toast.classes.has('is-leaving'));
  advance(300);
  assert.strictEqual(toast.parentNode, null);
  advance(10000);
  assert.strictEqual(host.children.length, 0);
}

// 5. missing timeout attribute falls back to 5 seconds
{
  const host = makeHost(); const toast = makeToast(host, undefined);
  load(host);
  advance(4900);
  assert(!toast.classes.has('is-leaving'));
  advance(200);
  assert(toast.classes.has('is-leaving'));
}

// 6. errors keep their own (longer) timeout from the markup
{
  const host = makeHost(); const toast = makeToast(host, 8000, 'lp-toast lp-toast--error');
  load(host);
  advance(7900);
  assert(!toast.classes.has('is-leaving'), 'error still up at 7.9s');
  advance(200);
  assert(toast.classes.has('is-leaving'), 'error leaves at 8s');
}

// 7. never more than 4 at once: extra (oldest, at the end) ones are dropped on load
{
  const host = makeHost();
  const list = [1, 2, 3, 4, 5, 6].map(() => makeToast(host, 5000));
  load(host);
  assert.strictEqual(host.children.length, 4, 'only 4 stay');
  assert.strictEqual(host.children[0], list[0], 'newest (first) kept');
}

console.log('alerts.js ok');
