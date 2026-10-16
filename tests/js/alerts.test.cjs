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

function makeToast(timeoutMs) {
  const listeners = {};
  const closeListeners = {};
  const closeBtn = { addEventListener: (e, f) => { closeListeners[e] = f; } };
  const host = { id: 'lp-toasts', children: [], removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; }, remove() { host.removed = true; } };
  const toast = {
    parentNode: host,
    classes: new Set(),
    classList: { add: (c) => toast.classes.add(c) },
    getAttribute: (n) => (n === 'data-lp-toast-timeout' && timeoutMs !== undefined ? String(timeoutMs) : null),
    addEventListener: (e, f) => { listeners[e] = f; },
    querySelector: () => closeBtn,
    fire: (e) => listeners[e] && listeners[e](),
    click: () => closeListeners.click(),
  };
  host.children.push(toast);
  return { toast, host };
}

function load(toasts) {
  global.document = { readyState: 'complete', querySelectorAll: () => toasts, addEventListener() {} };
  // run the file fresh each time (a plain require would only execute it once)
  new Function(fs.readFileSync(path.join(__dirname, '../../public/assets/js/alerts.js'), 'utf8'))();
}

// 1. closes by itself after 6 seconds (leaving class first, removed shortly after)
{
  const { toast, host } = makeToast(6000);
  load([toast]);
  advance(5900);
  assert.strictEqual(toast.parentNode, host, 'still there at 5.9s');
  advance(150);
  assert(toast.classes.has('is-leaving'), 'starts leaving at 6s');
  advance(300);
  assert.strictEqual(toast.parentNode, null, 'removed after the fade');
  assert(host.removed, 'empty container removed');
}

// 2. hover pauses the countdown, leaving resumes it
{
  const { toast } = makeToast(6000);
  load([toast]);
  advance(4000);
  toast.fire('mouseenter');
  advance(60000);
  assert(!toast.classes.has('is-leaving'), 'paused while hovered');
  toast.fire('mouseleave');
  advance(1900);
  assert(!toast.classes.has('is-leaving'), 'resumes with the remaining ~2s');
  advance(300);
  assert(toast.classes.has('is-leaving'), 'closes after the remaining time');
}

// 3. keyboard focus pauses too
{
  const { toast } = makeToast(6000);
  load([toast]);
  toast.fire('focusin');
  advance(20000);
  assert(!toast.classes.has('is-leaving'), 'paused while focused');
  toast.fire('focusout');
  advance(6100);
  assert(toast.classes.has('is-leaving'));
}

// 4. close button dismisses immediately and the timer does not fire again
{
  const { toast, host } = makeToast(6000);
  load([toast]);
  advance(1000);
  toast.click();
  assert(toast.classes.has('is-leaving'));
  advance(300);
  assert.strictEqual(toast.parentNode, null);
  advance(10000);
  assert.strictEqual(host.children.length, 0);
}

// 5. missing timeout attribute falls back to 6 seconds
{
  const { toast } = makeToast(undefined);
  load([toast]);
  advance(5900);
  assert(!toast.classes.has('is-leaving'));
  advance(200);
  assert(toast.classes.has('is-leaving'));
}

console.log('alerts.js ok');
