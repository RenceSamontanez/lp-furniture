/* ============================================================
   LP FURNITURE — main.js
   All frontend JavaScript for the user-facing website.
   Sections:
   1. Mobile Nav Toggle (hamburger menu)
   2. Chatbot Widget
   3. Hero Slideshow
   4. Quick View Modal (product preview)
   5. Product Carousel (best sellers slider)
   6. Toast Notification
   7. Init on page load
   ============================================================ */


/* ════════════════════════════════════════════════════════
   1. MOBILE NAV TOGGLE
   Controls the hamburger menu that appears on small screens.
   Clicking the burger icon slides in a drawer from the right.
════════════════════════════════════════════════════════ */
function toggleMobileNav() {
  document.getElementById('nav-burger').classList.toggle('open');
  document.getElementById('nav-links').classList.toggle('open');
  document.getElementById('nav-overlay').classList.toggle('open');
}


/* ════════════════════════════════════════════════════════
   2. CHATBOT WIDGET
   The floating chat assistant in the bottom right corner.
   Talks to the Laravel ChatbotController via AJAX.
════════════════════════════════════════════════════════ */
let chatStarted = false;

/* Toggle chatbot open/close */
function toggleChatbot() {
  const win = document.getElementById('chatbot-window');
  win.classList.toggle('open');
  // Only send the greeting message once on first open
  if (win.classList.contains('open') && !chatStarted) {
    initChat();
    chatStarted = true;
  }
}

/* Open chatbot programmatically (e.g. from hero button) */
function openChatbot() {
  document.getElementById('chatbot-window').classList.add('open');
  if (!chatStarted) {
    initChat();
    chatStarted = true;
  }
}

/* Send the initial greeting when chatbot first opens */
function initChat() {
  addBotMessage(
    "Hi, I'm Luma, LP Furniture Shop's AI Assistant. Ask me about our furniture, prices, custom designs, delivery or your order. Kumusta po! Pwede rin po kayong magtanong sa Tagalog.",
    ['How to order', 'Custom design', 'Prices', 'Delivery']
  );
}

/* Escape text before it goes into HTML. Nothing from the server or the user is trusted as HTML. */
function chatEsc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* Only same-site paths and http(s) links are clickable */
function chatSafeUrl(url) {
  url = String(url || '');
  if (url.charAt(0) === '/' && url.charAt(1) !== '/') return url;
  return /^https?:\/\//i.test(url) ? url : '#';
}

/* ── Luma: the guided cancellation / refund form card ───────────────────────────────────────────────
   Drawn from a "form" reply. It posts to the form's post_url (CSRF protected, signed-in customers only) in two steps:
   "review" (the server validates with the same rules as the web form and returns field errors or a summary) and "confirm"
   (creates the request once). Everything the customer typed is set with .value / textContent, never innerHTML. The GCash
   number and name stay in the form fields and in the server's encrypted, single-use token: they are never put in a chat
   bubble, in storage or in the log. All styles live under .chatbot-window so the look can change later. */
let lumaFormSeq = 0;

function lumaEl(tag, attrs, children) {
  const el = document.createElement(tag);
  Object.keys(attrs || {}).forEach(k => {
    if (k === 'text') el.textContent = attrs[k];
    else el.setAttribute(k, attrs[k]);
  });
  (children || []).forEach(c => { if (c) el.appendChild(c); });
  return el;
}

/* The shared .lp-notice, built in script (same markup as resources/views/components/notice.blade.php) */
function lumaNotice(variant, title, text) {
  const icon = variant === 'problem'
    ? '<path d="M12 4 3 19h18L12 4Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>'
    : (variant === 'blocked' ? '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'
      : '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>');
  const box = lumaEl('div', { class: 'lp-notice lp-notice--' + variant, role: variant === 'problem' ? 'alert' : 'status' });
  box.innerHTML = '<svg class="lp-notice__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + icon + '</svg>';
  const body = lumaEl('div', { class: 'lp-notice__body' }, [lumaEl('p', { class: 'lp-notice__title', text: title })]);
  if (text) body.appendChild(lumaEl('div', { class: 'lp-notice__text', text: text }));
  box.appendChild(body);
  return box;
}

function lumaReferenceBlock(ref) {
  const tl = window.lumaLang === 'tl';
  return lumaEl('div', { class: 'chat-ref', role: 'status' }, [
    lumaEl('span', { class: 'chat-ref-label', text: tl ? 'Reference number ng request ninyo' : 'Your request reference number' }),
    lumaEl('strong', { class: 'chat-ref-number', text: ref }),
  ]);
}

/* label + control + a hidden error line the server's messages are written into */
function lumaField(n, name, labelText, control, hint) {
  control.id = 'cf' + n + '-' + name;
  control.setAttribute('aria-describedby', control.id + '-err');
  const err = lumaEl('p', { class: 'chat-field-error', id: control.id + '-err', role: 'alert' });
  err.hidden = true;
  const field = lumaEl('div', { class: 'chat-field' }, [lumaEl('label', { for: control.id, text: labelText }), control]);
  if (hint) field.appendChild(lumaEl('p', { class: 'chat-field-hint', text: hint }));
  field.appendChild(err);
  return field;
}

function lumaShowErrors(root, n, errors, generic) {
  root.querySelectorAll('.chat-field-error').forEach(e => { e.hidden = true; e.textContent = ''; });
  let first = null;
  Object.keys(errors || {}).forEach(key => {
    const el = root.querySelector('#cf' + n + '-' + key + '-err');
    if (el) {
      el.textContent = errors[key][0];
      el.hidden = false;
      const input = root.querySelector('#cf' + n + '-' + key);
      if (input) { input.setAttribute('aria-invalid', 'true'); if (!first) first = input; }
    } else if (generic) {
      generic.textContent = errors[key][0];
      generic.hidden = false;
    }
  });
  if (first) first.focus();
}

async function lumaPost(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': APP.csrfToken },
    body: JSON.stringify(payload),
  });
  let data = {};
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  return { status: res.status, data: data };
}

function lumaRefundForm(form) {
  const n = ++lumaFormSeq;
  const L = form.labels;
  const wrap = lumaEl('div', { class: 'chat-form-wrap' });
  const f = lumaEl('form', { class: 'chat-form', novalidate: 'novalidate', 'aria-label': form.title }, [lumaEl('h4', { class: 'chat-form-title', text: form.title })]);

  const reason = lumaEl('select', { name: 'reason_code', required: 'required' });
  reason.appendChild(lumaEl('option', { value: '', text: L.choose }));
  form.reasons.forEach(r => reason.appendChild(lumaEl('option', { value: r.value, text: r.label })));
  f.appendChild(lumaField(n, 'reason_code', L.reason, reason));

  const details = lumaEl('textarea', { name: 'reason_text', rows: '3', maxlength: '1000' });
  f.appendChild(lumaField(n, 'reason_text', L.details, details));

  const phone = lumaEl('input', { name: 'contact_phone', type: 'tel', inputmode: 'tel', autocomplete: 'tel', maxlength: '30' });
  phone.value = form.defaults.phone || '';
  f.appendChild(lumaField(n, 'contact_phone', L.phone, phone));

  const email = lumaEl('input', { name: 'contact_email', type: 'email', autocomplete: 'email', maxlength: '150' });
  email.value = form.defaults.email || '';
  f.appendChild(lumaField(n, 'contact_email', L.email, email));

  let gcashBox = null;
  if (form.paid) {
    const fs = lumaEl('fieldset', { class: 'chat-fieldset' }, [lumaEl('legend', { text: L.method })]);
    form.methods.forEach(m => {
      const id = 'cf' + n + '-method-' + m.value;
      const radio = lumaEl('input', { type: 'radio', name: 'refund_method', value: m.value, id: id });
      fs.appendChild(lumaEl('label', { class: 'chat-radio', for: id }, [radio, lumaEl('span', { text: m.label })]));
    });
    fs.appendChild(lumaEl('p', { class: 'chat-field-hint', text: L.hint_method }));
    const merr = lumaEl('p', { class: 'chat-field-error', id: 'cf' + n + '-refund_method-err', role: 'alert' });
    merr.hidden = true;
    fs.appendChild(merr);
    f.appendChild(fs);

    gcashBox = lumaEl('div', { class: 'chat-gcash' });
    gcashBox.hidden = true;
    gcashBox.appendChild(lumaField(n, 'gcash_number', L.gcash_number, lumaEl('input', { name: 'gcash_number', type: 'tel', inputmode: 'tel', autocomplete: 'off', maxlength: '30', placeholder: '09XX XXX XXXX' })));
    gcashBox.appendChild(lumaField(n, 'gcash_account_name', L.gcash_name, lumaEl('input', { name: 'gcash_account_name', type: 'text', autocomplete: 'off', maxlength: '120' })));
    f.appendChild(gcashBox);
    fs.addEventListener('change', () => { gcashBox.hidden = (f.elements.refund_method.value !== 'gcash'); });
  }

  const terms = lumaEl('input', { type: 'checkbox', name: 'terms', value: '1', id: 'cf' + n + '-terms' });
  const termsLink = lumaEl('a', { href: form.terms_url, target: '_blank', rel: 'noopener', class: 'lp-link', text: L.terms_link });
  const termsRow = lumaEl('div', { class: 'chat-field' }, [
    lumaEl('label', { class: 'chat-check', for: 'cf' + n + '-terms' }, [terms, lumaEl('span', { text: L.terms })]),
    termsLink,
  ]);
  const terr = lumaEl('p', { class: 'chat-field-error', id: 'cf' + n + '-terms-err', role: 'alert' });
  terr.hidden = true;
  termsRow.appendChild(terr);
  f.appendChild(termsRow);

  const generic = lumaEl('p', { class: 'chat-field-error chat-form-error', role: 'alert' });
  generic.hidden = true;
  f.appendChild(generic);

  const submit = lumaEl('button', { type: 'submit', class: 'lp-btn lp-btn--primary lp-btn--block', text: L.submit });
  f.appendChild(submit);
  wrap.appendChild(f);

  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (submit.disabled) return;
    generic.hidden = true;
    f.querySelectorAll('[aria-invalid]').forEach(e => e.removeAttribute('aria-invalid'));
    submit.disabled = true;
    submit.textContent = L.sending;
    const payload = { step: 'review', ticket_id: form.ticket_id, lang: window.lumaLang || 'en' };
    Array.from(f.elements).forEach(el => {
      if (!el.name) return;
      if ((el.type === 'radio') && !el.checked) return;
      if (el.type === 'checkbox') { if (el.checked) payload[el.name] = '1'; return; }
      payload[el.name] = el.value;
    });
    try {
      const r = await lumaPost(form.post_url, payload);
      if (r.status === 200 && r.data.summary) {
        f.hidden = true;
        const card = lumaRefundSummary(r.data.summary, n, () => { card.remove(); f.hidden = false; submit.disabled = false; submit.textContent = L.submit; reason.focus(); }, wrap);
        wrap.appendChild(card);
        card.querySelector('input[type=checkbox]').focus();
        document.getElementById('chat-body').scrollTop = document.getElementById('chat-body').scrollHeight;
        return;
      }
      if (r.data.errors) {
        lumaShowErrors(f, n, r.data.errors, generic);
      } else {
        generic.textContent = r.data.message || 'Something went wrong. Please try again.';
        generic.hidden = false;
      }
    } catch (e) {
      generic.textContent = 'Something went wrong. Please try again.';
      generic.hidden = false;
    }
    submit.disabled = false;
    submit.textContent = L.submit;
  });

  return wrap;
}

/* The confirm step: what will be sent, the warning, "I checked these details", then Confirm. */
function lumaRefundSummary(s, n, goBack, wrap) {
  const card = lumaEl('div', { class: 'chat-summary', role: 'group', 'aria-label': s.title }, [lumaEl('h4', { class: 'chat-form-title', text: s.title })]);
  const dl = lumaEl('dl', { class: 'chat-summary-rows' });
  s.rows.forEach(r => { dl.appendChild(lumaEl('dt', { text: r[0] })); dl.appendChild(lumaEl('dd', { text: r[1] })); });
  card.appendChild(dl);
  card.appendChild(lumaNotice(s.instant ? 'blocked' : 'problem', s.warning_title, s.warning));

  const check = lumaEl('input', { type: 'checkbox', id: 'cf' + n + '-checked' });
  card.appendChild(lumaEl('label', { class: 'chat-check', for: 'cf' + n + '-checked' }, [check, lumaEl('span', { text: s.check_label })]));
  const err = lumaEl('p', { class: 'chat-field-error', role: 'alert', text: s.check_error });
  err.hidden = true;
  card.appendChild(err);

  const confirm = lumaEl('button', { type: 'button', class: 'lp-btn lp-btn--primary lp-btn--block', text: s.confirm_label });
  const back = lumaEl('button', { type: 'button', class: 'lp-btn lp-btn--secondary lp-btn--block', text: s.back_label });
  card.appendChild(confirm);
  card.appendChild(back);
  back.addEventListener('click', goBack);
  check.addEventListener('change', () => { if (check.checked) err.hidden = true; });

  let busy = false;
  confirm.addEventListener('click', async () => {
    if (busy) return;
    if (!check.checked) { err.hidden = false; check.focus(); return; }
    busy = true;
    confirm.disabled = true;
    back.disabled = true;
    try {
      const r = await lumaPost(s.post_url, { step: 'confirm', token: s.token, checked: true, lang: window.lumaLang || 'en' });
      if (r.status === 200 && r.data.reply) {
        wrap.remove();
        addBotMessage(r.data.reply.text, [], [], { reference: r.data.reply.reference || r.data.reference, buttons: r.data.reply.buttons || [], lang: window.lumaLang });
        return;
      }
      err.textContent = (r.data.errors && r.data.errors.check && r.data.errors.check[0]) || r.data.message || 'Something went wrong. Please try again.';
      err.hidden = false;
    } catch (e) {
      err.textContent = 'Something went wrong. Please try again.';
      err.hidden = false;
    }
    busy = false;
    confirm.disabled = false;
    back.disabled = false;
  });
  return card;
}

/* Add a message bubble from the bot side.
   extra = { cards: [{name, price_label, old_price_label, image, url}], buttons: [{label, url}], contact: {phone, email} } */
function addBotMessage(msg, suggestions, products, extra) {
  const body = document.getElementById('chat-body');
  extra = extra || {};

  // Create the chat bubble (the text is escaped; only line breaks become <br>)
  const el = document.createElement('div');
  el.className = 'chat-msg bot';
  el.innerHTML = `
    <div class="chat-avatar">🪑</div>
    <div class="chat-bubble">${chatEsc(msg).replace(/\n/g, '<br/>')}</div>
  `;
  body.appendChild(el);

  // Product cards from the live catalog
  if (extra.cards && extra.cards.length) {
    const cardsEl = document.createElement('div');
    cardsEl.style.width = '100%';
    cardsEl.innerHTML = '<div class="chat-cards">' + extra.cards.map(c => `
      <a class="chat-card" href="${chatEsc(chatSafeUrl(c.url))}">
        ${c.image ? `<img src="${chatEsc(chatSafeUrl(c.image))}" alt="${chatEsc(c.name)}" loading="lazy">` : ''}
        <div class="chat-card-name">${chatEsc(c.name)}</div>
        <div class="chat-card-price">${chatEsc(c.price_label)}${c.old_price_label ? ` <s>${chatEsc(c.old_price_label)}</s>` : ''}</div>
      </a>`).join('') + '</div>';
    body.appendChild(cardsEl);
  }

  if (extra.lang) { window.lumaLang = extra.lang; }

  // The guided cancellation / refund form (a "form" reply) and the big reference number after it was sent
  if (extra.reference) { body.appendChild(lumaReferenceBlock(extra.reference)); }
  if (extra.form) { body.appendChild(lumaRefundForm(extra.form)); }

  // The shop's contact details, business-card style
  if (extra.contact && (extra.contact.phone || extra.contact.email)) {
    const c = extra.contact;
    const cardEl = document.createElement('div');
    cardEl.style.width = '100%';
    const tel = c.phone ? String(c.phone).replace(/[^\d+]/g, '') : '';
    cardEl.innerHTML = `<div class="chat-contact">
      <div class="chat-contact-name">LP Furniture Shop</div>
      ${c.phone ? `<div class="chat-contact-line">${chatEsc(c.phone)}</div>` : ''}
      ${c.email ? `<div class="chat-contact-line">${chatEsc(c.email)}</div>` : ''}
      <div class="chat-contact-actions">
        ${c.phone ? `<a class="chat-btn chat-btn--primary lp-btn lp-btn--primary" href="tel:${chatEsc(tel)}">Call us</a>` : ''}
        ${c.email ? `<a class="chat-btn lp-btn ${c.phone ? 'chat-btn--secondary lp-btn--secondary' : 'chat-btn--primary lp-btn--primary'}" href="mailto:${chatEsc(c.email)}">Send an email</a>` : ''}
      </div>
    </div>`;
    body.appendChild(cardEl);
  }

  // Link buttons ("View more products", "Send a request", ...)
  if (extra.buttons && extra.buttons.length) {
    const btnEl = document.createElement('div');
    btnEl.className = 'chat-buttons';
    // First button is the main action, the rest are secondary; three at most
    const arrow = '<svg class="chat-btn-arrow" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    btnEl.innerHTML = extra.buttons.slice(0, 3).map((b, i) =>
      `<a class="chat-btn lp-btn ${i === 0 ? 'chat-btn--primary lp-btn--primary' : 'chat-btn--secondary lp-btn--secondary'}" href="${chatEsc(chatSafeUrl(b.url))}"><span>${chatEsc(b.label)}</span>${arrow}</a>`).join('');
    body.appendChild(btnEl);
  }

  // Older replies: a plain list of products
  if (products && products.length) {
    const prodEl = document.createElement('div');
    prodEl.style.width = '100%';
    const cards = products.map(p => `
      <div class="chat-product-card" onclick="window.location='/product/${encodeURIComponent(p.id)}'">
        <div class="chat-product-name">${chatEsc(p.product_name)}</div>
        <div class="chat-product-price">₱${Number(p.price).toLocaleString()}</div>
      </div>
    `).join('');
    prodEl.innerHTML = `<div class="chat-products">${cards}</div>`;
    body.appendChild(prodEl);
  }

  // Set the quick-reply suggestion chips below the message
  setSuggestions(suggestions || []);

  // Scroll to the latest message
  body.scrollTop = body.scrollHeight;
}

/* Add a message bubble from the user side */
function addUserMessage(msg) {
  const body = document.getElementById('chat-body');
  const el = document.createElement('div');
  el.className = 'chat-msg user';
  el.innerHTML = `<div class="chat-bubble">${chatEsc(msg)}</div>`;
  body.appendChild(el);
  body.scrollTop = body.scrollHeight;
}

/* Render the quick-reply suggestion chips */
function setSuggestions(list) {
  const el = document.getElementById('chat-suggestions');
  el.innerHTML = list
    .map(s => `<button type="button" class="chat-chip lp-btn lp-btn--secondary lp-btn--chip" data-sugg="${chatEsc(s)}" onclick="handleSugg(this.dataset.sugg)">${chatEsc(s)}</button>`)
    .join('');
}

/* Map specific suggestion phrases to direct page navigation.
   Anything not in this list falls through to sending as a
   normal chat message instead. */
   const CHAT_NAV_MAP = {
    'Browse catalog':             () => APP.catalogUrl,
    'Browse full catalog':        () => APP.catalogUrl,
    'Go to my cart':               () => APP.cartUrl,
    'Continue shopping':           () => APP.catalogUrl,
    'How to checkout':             () => APP.cartUrl,
    'Go to my orders':             () => APP.ordersUrl,
    'Check my order status':       () => APP.ordersUrl,
    'Track my order':              () => APP.ordersUrl,
    'Track my delivery':           () => APP.ordersUrl,
    'Contact about my order':      () => APP.ordersUrl,
    'Start verification':          () => APP.validationUrl,
    'How to submit ID':            () => APP.validationUrl,
    'Start AI Design request':     () => APP.designUrl,
    'Try AI Design tool':          () => APP.designUrl,
    'Go to login':                 () => APP.loginUrl,
    'Sign up with Google':         () => APP.registerUrl,
    'Regular email signup instead':() => APP.registerUrl,
    'I forgot my password':        () => APP.forgotPasswordUrl,
    'I signed up with Google':     () => APP.loginUrl,
    'Contact support':             () => APP.loginUrl,
  };
  
  /* When user clicks a suggestion chip:
     - If it maps to a page, navigate there directly (only if
       it makes sense given login state)
     - Otherwise, treat it as a normal follow-up question */
  function handleSugg(text) {
    const navFn = CHAT_NAV_MAP[text];
  
    if (navFn) {
      const url = navFn();
  
      // Pages that require login — redirect to login first if guest
      const requiresAuth = [APP.cartUrl, APP.ordersUrl, APP.validationUrl, APP.designUrl];
      if (!APP.isLoggedIn && requiresAuth.includes(url)) {
        window.location.href = APP.loginUrl;
        return;
      }
  
      window.location.href = url;
      return;
    }
  
    // Not a navigation shortcut — send as a regular chat message
    document.getElementById('chat-input').value = text;
    sendChat();
  }

/* Send the user's typed message to the Laravel chatbot API */
async function sendChat() {
  const input = document.getElementById('chat-input');
  const msg   = input.value.trim();
  if (!msg) return;

  addUserMessage(msg);
  input.value = '';
  setSuggestions([]);

  try {
    const res = await fetch(APP.chatbotUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': APP.csrfToken,
      },
      body: JSON.stringify({ message: msg }),
    });
    const data = await res.json();
    addBotMessage(data.response, data.suggestions, data.products, data);
  } catch (e) {
    addBotMessage("Sorry, I'm having trouble right now. Please try again!", []);
  }
}


/* ════════════════════════════════════════════════════════
   3. HERO SLIDESHOW
   Cycles through multiple hero background images every 6s.
   Only runs if there are 2 or more slide elements on the page.
════════════════════════════════════════════════════════ */
(function initHeroSlideshow() {
  const slides = document.querySelectorAll('.hero-v2-slide');
  if (slides.length <= 1) return; // Only 1 image — no cycling needed

  let current = 0;
  setInterval(() => {
    slides[current].classList.remove('active');
    current = (current + 1) % slides.length;
    slides[current].classList.add('active');
  }, 6000); // Switch every 6 seconds
})();


/* ════════════════════════════════════════════════════════
   4. QUICK VIEW MODAL
   Opens a centered popup with product image carousel,
   specs, price, and action buttons — without a full
   page reload. Loads product data via AJAX from Laravel.
════════════════════════════════════════════════════════ */

// Track which image is currently shown and the full image list
let qvSlideIndex  = 0;
let qvImages      = [];
let qvAutoTimer   = null;

/* Open the quick view modal for a specific product */
async function openQuickView(productId) {
  const overlay = document.getElementById('quickViewModal');
  const content = document.getElementById('quickViewContent');

  // Show a spinner while loading
  content.innerHTML = `
    <div class="qv-loading">
      <div class="qv-spinner"></div>
    </div>
  `;
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden'; // Prevent background scroll

  try {
    // Fetch product data from the Laravel API
    const res = await fetch(`/product/${productId}/quick-view`);
    const p   = await res.json();

    // Use gallery images if available, otherwise fall back to single image
    qvImages     = (p.images && p.images.length) ? p.images : [{ url: p.image_url, label: 'Main' }];
    qvSlideIndex = 0;

    // Truncate description — quick view is a preview, not full details
    const shortDesc = p.description && p.description.length > 120
      ? p.description.substring(0, 120) + '...'
      : (p.description || '');

    // Build the modal HTML
    content.innerHTML = `
      <div class="qv-layout">

        <!-- LEFT SIDE: Product image with carousel controls -->
        <div class="qv-left">
          <div class="qv-img-wrap">
            <img
              id="qv-main-img"
              src="${qvImages[0].url}"
              alt="${p.name}"
              onerror="this.onerror=null;this.src='/images/products/placeholder.jpg';"
            />

            ${qvImages.length > 1 ? `
              <button class="qv-arrow qv-arrow-left"  onclick="qvSlide(-1)">&#8249;</button>
              <button class="qv-arrow qv-arrow-right" onclick="qvSlide(1)">&#8250;</button>
              <div class="qv-img-dots" id="qv-dots">
                ${qvImages.map((_, i) =>
                  `<span class="qv-dot ${i === 0 ? 'active' : ''}" onclick="qvGoTo(${i})"></span>`
                ).join('')}
              </div>
            ` : ''}
          </div>
        </div>

        <!-- RIGHT SIDE: Product info -->
        <div class="qv-right">

          <!-- Category label (small gold uppercase) -->
          <div class="qv-category">${p.category || ''}</div>

          <!-- Product name (large display font) -->
          <div class="qv-name">${p.name}</div>

          <!-- Price (large if no sale, or crossed out + sale price) -->
          <div class="qv-price-row">
            ${p.on_sale
              ? `<span class="qv-price-old">${p.price}</span>
                 <span class="qv-price-now">${p.sale_price}</span>
                 <span class="qv-discount-badge">-${p.discount_percent}%</span>`
              : `<span class="qv-price-now">${p.price}</span>`
            }
          </div>

          <!-- Short description ending in ... -->
          <p class="qv-desc">${shortDesc}</p>

          <!-- Specs as pill-style rows (Material, Finish, Size) -->
          <div class="qv-specs">
            ${p.material   ? `<div class="qv-spec"><span class="qv-spec-label">Material</span><span class="qv-spec-val">${p.material}</span></div>` : ''}
            ${p.finish     ? `<div class="qv-spec"><span class="qv-spec-label">Finish</span><span class="qv-spec-val">${p.finish}</span></div>` : ''}
            ${p.dimensions ? `<div class="qv-spec"><span class="qv-spec-label">Size (L x W x H)</span><span class="qv-spec-val">${p.dimensions}</span></div>` : ''}
          </div>

          <!-- Footer: Go Back closes modal, View Full Details goes to product page -->
          <div class="qv-footer">
            <button class="btn btn-outline" onclick="closeQuickView()">← Go Back</button>
            <a href="${p.detail_url}" class="btn btn-gold">View Full Details →</a>
          </div>

        </div>
      </div>
    `;

    // Start auto-sliding if there are multiple images
    if (qvImages.length > 1) {
      startQvAutoSlide();
    }

  } catch (e) {
    // Show a friendly error if the API call fails
    content.innerHTML = `
      <div class="qv-loading">
        <p style="color:var(--text-muted);margin-bottom:16px;">
          Failed to load product. Please try again.
        </p>
        <button class="btn btn-outline" onclick="closeQuickView()">← Go Back</button>
      </div>
    `;
  }
}

/* Slide the image carousel left (-1) or right (1) */
function qvSlide(direction) {
  qvGoTo((qvSlideIndex + direction + qvImages.length) % qvImages.length);
  resetQvAutoSlide(); // Reset the timer so it doesn't jump immediately after manual slide
}

/* Jump directly to a specific image by index */
function qvGoTo(index) {
  qvSlideIndex = index;

  const img  = document.getElementById('qv-main-img');
  const dots = document.querySelectorAll('.qv-dot');

  if (img) {
    // Fade out, swap image, fade back in
    img.style.opacity = '0';
    setTimeout(() => {
      img.src = qvImages[index].url;
      img.style.opacity = '1';
    }, 180);
  }

  // Update which dot is highlighted
  dots.forEach((d, i) => d.classList.toggle('active', i === index));
}

/* Auto-slide every 3.5 seconds */
function startQvAutoSlide() {
  clearInterval(qvAutoTimer);
  qvAutoTimer = setInterval(() => qvSlide(1), 3500);
}

/* Reset auto-slide timer (called after manual slide) */
function resetQvAutoSlide() {
  clearInterval(qvAutoTimer);
  startQvAutoSlide();
}

/* Close the quick view modal */
function closeQuickView() {
  clearInterval(qvAutoTimer); // Stop auto-sliding
  document.getElementById('quickViewModal').classList.remove('open');
  document.body.style.overflow = ''; // Re-enable background scroll
}

/* Close modal when clicking the dark overlay background */
function closeQuickViewOutside(e) {
  if (e.target.id === 'quickViewModal') closeQuickView();
}


/* ════════════════════════════════════════════════════════
   5. PRODUCT CAROUSEL (Best Sellers / Trending section)
   A horizontally scrollable card track with:
   - Prev/Next arrow buttons
   - Dot page indicators
   - Auto-slides every 5 seconds, pauses on hover
════════════════════════════════════════════════════════ */

/* Scroll the carousel track left or right by 2 card widths */
function carouselScroll(trackId, direction) {
  const track = document.getElementById(trackId);
  if (!track) return;

  const card  = track.querySelector('.carousel-card');
  const width = card ? card.offsetWidth + 22 : 280;
  track.scrollBy({ left: direction * width * 2, behavior: 'smooth' });

  // Update dots after the scroll animation finishes
  setTimeout(() => updateCarouselDots(trackId), 350);
}

/* Update which dot is active based on current scroll position */
function updateCarouselDots(trackId) {
  const track = document.getElementById(trackId);
  const dots  = document.getElementById(trackId + '-dots');
  if (!track || !dots) return;

  const cards   = track.querySelectorAll('.carousel-card');
  const cardW   = cards[0] ? cards[0].offsetWidth + 22 : 280;
  const visible = Math.round(track.offsetWidth / cardW);
  const current = Math.round(track.scrollLeft / (cardW * visible));

  dots.querySelectorAll('.carousel-dot').forEach((d, i) => {
    d.classList.toggle('active', i === current);
  });
}

/* Set up all carousels on the page */
function initCarousels() {
  // Add each carousel track ID here
  const carousels = [
    { track: 'bestsellers', dots: 'bestsellers-dots' },
  ];

  carousels.forEach(({ track, dots }) => {
    const trackEl = document.getElementById(track);
    const dotsEl  = document.getElementById(dots);
    if (!trackEl || !dotsEl) return;

    const cards   = trackEl.querySelectorAll('.carousel-card');
    const cardW   = 262; // Approximate card width + gap
    const visible = Math.max(1, Math.round(trackEl.offsetWidth / cardW));
    const pages   = Math.ceil(cards.length / visible);

    // Build the dot indicators
    dotsEl.innerHTML = '';
    for (let i = 0; i < pages; i++) {
      const btn = document.createElement('button');
      btn.className  = 'carousel-dot' + (i === 0 ? ' active' : '');
      btn.setAttribute('aria-label', `Page ${i + 1}`);
      btn.onclick = () => {
        trackEl.scrollTo({ left: i * cardW * visible, behavior: 'smooth' });
        setTimeout(() => updateCarouselDots(track), 350);
      };
      dotsEl.appendChild(btn);
    }

    // Listen for scroll events to keep dots in sync
    trackEl.addEventListener('scroll', () => updateCarouselDots(track), { passive: true });

    // Auto-slide every 5 seconds
    let autoSlide = setInterval(() => {
      const maxScroll = trackEl.scrollWidth - trackEl.offsetWidth;
      if (trackEl.scrollLeft >= maxScroll - 10) {
        // Reached the end — loop back to start
        trackEl.scrollTo({ left: 0, behavior: 'smooth' });
      } else {
        carouselScroll(track, 1);
      }
    }, 5000);

    // Pause auto-slide when user hovers or touches the carousel
    trackEl.addEventListener('mouseenter', () => clearInterval(autoSlide));
    trackEl.addEventListener('touchstart', () => clearInterval(autoSlide), { passive: true });
  });
}

/* ════════════════════════════════════════════════════════
   LIGHTBOX — Fullscreen gallery photo viewer
   Shows emotional story line below each image.
   Keyboard navigation: ArrowLeft, ArrowRight, Escape.
════════════════════════════════════════════════════════ */

let lightboxImages = [];
let lightboxIndex  = 0;

function openLightbox(index) {
  const items   = document.querySelectorAll('.gallery-item img');
  const stories = document.querySelectorAll('.gallery-story');
  const labels  = document.querySelectorAll('.gallery-label-row span');

  if (!items.length) return;

  // Build image data array from DOM
  lightboxImages = Array.from(items).map((img, i) => ({
    url:   img.src,
    label: labels[i]  ? labels[i].textContent.trim()  : '',
    story: stories[i] ? stories[i].textContent.trim().replace(/"/g, '') : '',
  }));

  lightboxIndex = index;

  document.getElementById('lightboxOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';

  buildLightboxDots();
  setLightboxImage(index);

  document.addEventListener('keydown', lightboxKeyHandler);
}

function setLightboxImage(index) {
  lightboxIndex = index;

  const img   = document.getElementById('lightbox-img');
  const label = document.getElementById('lightbox-label');
  const story = document.getElementById('lightbox-story');
  const dots  = document.querySelectorAll('.lightbox-dot');

  if (img) {
    img.style.opacity = '0';
    setTimeout(() => {
      img.src = lightboxImages[index].url;
      img.style.opacity = '1';
    }, 150);
  }

  if (label) label.textContent = lightboxImages[index].label;
  if (story) story.textContent = '"' + lightboxImages[index].story + '"';

  dots.forEach((d, i) => d.classList.toggle('active', i === index));
}

function lightboxSlide(direction) {
  const next = (lightboxIndex + direction + lightboxImages.length) % lightboxImages.length;
  setLightboxImage(next);
}

function buildLightboxDots() {
  const container = document.getElementById('lightbox-dots');
  if (!container) return;
  container.innerHTML = lightboxImages.map((_, i) => `
    <button
      class="lightbox-dot ${i === 0 ? 'active' : ''}"
      onclick="setLightboxImage(${i})"
      aria-label="Photo ${i + 1}">
    </button>
  `).join('');
}

function closeLightbox() {
  document.getElementById('lightboxOverlay').classList.remove('open');
  document.body.style.overflow = '';
  document.removeEventListener('keydown', lightboxKeyHandler);
}

function closeLightboxOutside(e) {
  if (e.target.id === 'lightboxOverlay') closeLightbox();
}

function lightboxKeyHandler(e) {
  if (e.key === 'ArrowLeft')  lightboxSlide(-1);
  if (e.key === 'ArrowRight') lightboxSlide(1);
  if (e.key === 'Escape')     closeLightbox();
}

/* ════════════════════════════════════════════════════════
   6. TOAST NOTIFICATION
   Shows a brief message at the bottom of the screen.
   Used for cart confirmations, errors, etc.
════════════════════════════════════════════════════════ */
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}


/* ════════════════════════════════════════════════════════
   7. INIT ON PAGE LOAD
   Everything that needs to run once the DOM is ready.
════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  initCarousels(); // Set up product carousels
});


// ── PRODUCT CAROUSEL ──
function carouselScroll(trackId, direction) {
  const track = document.getElementById(trackId);
  if (!track) return;
  const card   = track.querySelector('.carousel-card');
  const width  = card ? card.offsetWidth + 22 : 280;
  track.scrollBy({ left: direction * width * 2, behavior: 'smooth' });
  setTimeout(() => updateCarouselDots(trackId), 350);
}

function updateCarouselDots(trackId) {
  const track = document.getElementById(trackId);
  const dots  = document.getElementById(trackId + '-dots');
  if (!track || !dots) return;

  const cards    = track.querySelectorAll('.carousel-card');
  const cardW    = cards[0] ? cards[0].offsetWidth + 22 : 280;
  const visible  = Math.round(track.offsetWidth / cardW);
  const pages    = Math.ceil(cards.length / visible);
  const current  = Math.round(track.scrollLeft / (cardW * visible));

  dots.querySelectorAll('.carousel-dot').forEach((d, i) => {
    d.classList.toggle('active', i === current);
  });
}

function initCarousels() {
  const carousels = [
    { track: 'bestsellers', dots: 'bestsellers-dots' },
  ];

  carousels.forEach(({ track, dots }) => {
    const trackEl = document.getElementById(track);
    const dotsEl  = document.getElementById(dots);
    if (!trackEl || !dotsEl) return;

    const cards   = trackEl.querySelectorAll('.carousel-card');
    const cardW   = 262;
    const visible = Math.max(1, Math.round(trackEl.offsetWidth / cardW));
    const pages   = Math.ceil(cards.length / visible);

    // Build dots
    dotsEl.innerHTML = '';
    for (let i = 0; i < pages; i++) {
      const btn = document.createElement('button');
      btn.className = 'carousel-dot' + (i === 0 ? ' active' : '');
      btn.setAttribute('aria-label', `Page ${i + 1}`);
      btn.onclick = () => {
        trackEl.scrollTo({ left: i * cardW * visible, behavior: 'smooth' });
        setTimeout(() => updateCarouselDots(track), 350);
      };
      dotsEl.appendChild(btn);
    }

    // Listen for scroll to update dots
    trackEl.addEventListener('scroll', () => updateCarouselDots(track), { passive: true });

    // Auto-slide every 5 seconds
    let autoSlide = setInterval(() => {
      const maxScroll = trackEl.scrollWidth - trackEl.offsetWidth;
      if (trackEl.scrollLeft >= maxScroll - 10) {
        trackEl.scrollTo({ left: 0, behavior: 'smooth' });
      } else {
        carouselScroll(track, 1);
      }
    }, 5000);

    // Pause auto-slide when user touches/hovers
    trackEl.addEventListener('mouseenter', () => clearInterval(autoSlide));
    trackEl.addEventListener('touchstart', () => clearInterval(autoSlide), { passive: true });
  });
}


/* ════════════════════════════════════════════════════════
   PARALLAX — Gallery section subtle depth on scroll
   Each tile shifts slightly at different speeds as
   the user scrolls, creating a layered 3D depth effect.
   Disabled on mobile for performance.
════════════════════════════════════════════════════════ */
function initParallax() {
  const isMobile = window.innerWidth < 768;
  if (isMobile) return; // Skip on mobile — not needed and saves battery

  const items = document.querySelectorAll('.gallery-item[data-parallax]');
  if (!items.length) return;

  function applyParallax() {
    items.forEach(item => {
      const rect   = item.getBoundingClientRect();
      const center = rect.top + rect.height / 2;
      const viewH  = window.innerHeight;
      const offset = (center - viewH / 2) / viewH;
      const depth  = parseFloat(item.dataset.parallax) || 0;
      const shift  = offset * depth;

      item.querySelector('img').style.transform =
        `scale(1.08) translateY(${shift}px)`;
    });
  }

  window.addEventListener('scroll', applyParallax, { passive: true });
  applyParallax(); // Run once on load
}


/* ════════════════════════════════════════════════════════
   PROCESS SECTION — Scroll-triggered step animations
   Each step fades up and in with a staggered delay
   when it enters the viewport for the first time.
════════════════════════════════════════════════════════ */
function initProcessAnimations() {
  const steps = document.querySelectorAll('.process-step');
  if (!steps.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target); // Only animate once
      }
    });
  }, {
    threshold: 0.2, // Trigger when 20% of step is visible
    rootMargin: '0px 0px -40px 0px'
  });

  steps.forEach(step => observer.observe(step));
}

/* ════════════════════════════════════════════════════════
   PROCESS SECTION — Step-by-step glowing guide
   Cycles an .active class through each step so users
   can visually understand the order from 1 → 5.
════════════════════════════════════════════════════════ */
function initProcessStepperGlow() {
  const steps = Array.from(document.querySelectorAll('.process-step'));
  const track = document.querySelector('.process-track');
  if (!steps.length || !track) return;

  let idx = 0;
  let timer = null;

  function setActive(i) {
    steps.forEach(s => s.classList.remove('active'));
    steps[i].classList.add('active');
  }

  function start() {
    if (timer) return;
    setActive(idx);
    timer = setInterval(() => {
      idx = (idx + 1) % steps.length;
      setActive(idx);
    }, 2200);
  }

  function stop() {
    if (!timer) return;
    clearInterval(timer);
    timer = null;
    steps.forEach(s => s.classList.remove('active'));
  }

  // Only run when the process track is in view
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) start();
      else stop();
    });
  }, { threshold: 0.25 });

  obs.observe(track);
}


/* ════════════════════════════════════════════════════════
   ABOUT SECTION — Image thumbnail switcher
   Clicking a thumbnail fades the main image to the
   selected photo and highlights the active thumbnail.
════════════════════════════════════════════════════════ */
function switchAboutPhoto(url, thumbEl) {
  const mainImg = document.getElementById('about-main-img');
  const allThumbs = document.querySelectorAll('.about-thumb');

  if (mainImg) {
    mainImg.style.opacity = '0';
    setTimeout(() => {
      mainImg.src = url;
      mainImg.style.opacity = '1';
    }, 200);
  }

  allThumbs.forEach(t => t.classList.remove('active'));
  thumbEl.classList.add('active');
}

document.addEventListener('DOMContentLoaded', () => {
  initCarousels();
  initParallax();
  initProcessAnimations();
  initProcessStepperGlow();
});

/* ============================================================
   DRAGGABLE CHATBOT BUBBLE — with dismiss-to-X (Messenger-style)
   Drag the bubble down to the X zone to dismiss it. Reactivate
   anytime from the small chat icon in the navbar.
   ============================================================ */
   (function () {
    const fab         = document.getElementById('chatbot-fab');
    const dismissZone = document.getElementById('chatbot-dismiss-zone');
    const navReactivate = document.getElementById('nav-chatbot-reactivate');
    if (!fab) return;
  
    let isDragging = false;
    let hasMoved   = false;
    let isOverDismissZone = false;
    let startX, startY, initialLeft, initialTop;
  
    function localGet(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
    function localSet(key, val) { try { localStorage.setItem(key, val); } catch (e) {} }
  
    /* ── On page load: check if the chatbot was dismissed ── */
    if (localGet('lp_chat_dismissed') === 'true') {
      fab.style.display = 'none';
      if (navReactivate) navReactivate.style.display = 'inline-flex';
    }
  
    function startDrag(clientX, clientY) {
      isDragging = true;
      hasMoved = false;
      const rect = fab.getBoundingClientRect();
      startX = clientX;
      startY = clientY;
      initialLeft = rect.left;
      initialTop = rect.top;
      fab.style.transition = 'none';
      dismissZone?.classList.add('visible');
    }
  
    function moveDrag(clientX, clientY) {
      if (!isDragging) return;
      const dx = clientX - startX;
      const dy = clientY - startY;
  
      if (Math.abs(dx) > 5 || Math.abs(dy) > 5) hasMoved = true;
  
      let newLeft = initialLeft + dx;
      let newTop  = initialTop + dy;
  
      const fabSize = fab.offsetWidth;
      newLeft = Math.max(-fabSize / 2, Math.min(window.innerWidth - fabSize / 2, newLeft));
      newTop  = Math.max(-fabSize / 2, Math.min(window.innerHeight - fabSize / 2, newTop));
  
      fab.style.left   = newLeft + 'px';
      fab.style.top    = newTop + 'px';
      fab.style.right  = 'auto';
      fab.style.bottom = 'auto';
  
      // Check proximity to the dismiss zone
      if (dismissZone) {
        const zoneRect = dismissZone.getBoundingClientRect();
        const fabCenterX = newLeft + fabSize / 2;
        const fabCenterY = newTop + fabSize / 2;
        const zoneCenterX = zoneRect.left + zoneRect.width / 2;
        const zoneCenterY = zoneRect.top + zoneRect.height / 2;
  
        const distance = Math.hypot(fabCenterX - zoneCenterX, fabCenterY - zoneCenterY);
        const isNear = distance < 60;
  
        if (isNear !== isOverDismissZone) {
          isOverDismissZone = isNear;
          dismissZone.classList.toggle('active', isNear);
          fab.classList.toggle('near-dismiss', isNear);
        }
      }
    }
  
    function endDrag() {
      if (!isDragging) return;
      isDragging = false;
      fab.style.transition = '';
      dismissZone?.classList.remove('visible', 'active');
  
      if (isOverDismissZone) {
        // Dismiss the chatbot entirely
        dismissChatbot();
        isOverDismissZone = false;
        fab.classList.remove('near-dismiss');
        return;
      }
  
      // Normal snap-to-edge behavior
      const fabSize = fab.offsetWidth;
      const currentLeft = fab.getBoundingClientRect().left;
      const midpoint = window.innerWidth / 2;
      const snapLeft = currentLeft + fabSize / 2 < midpoint ? 16 : window.innerWidth - fabSize - 16;
      fab.style.left = snapLeft + 'px';
  
      if (hasMoved) {
        setTimeout(() => { hasMoved = false; }, 50);
      }
    }
  
    function dismissChatbot() {
      fab.style.display = 'none';
      localSet('lp_chat_dismissed', 'true');
      if (navReactivate) {
        navReactivate.style.display = 'inline-flex';
        navReactivate.classList.add('pulse-once');
        setTimeout(() => navReactivate.classList.remove('pulse-once'), 1200);
      }
    }
  
    // Mouse events
    fab.addEventListener('mousedown', (e) => { startDrag(e.clientX, e.clientY); e.preventDefault(); });
    document.addEventListener('mousemove', (e) => moveDrag(e.clientX, e.clientY));
    document.addEventListener('mouseup', endDrag);
  
    // Touch events
    fab.addEventListener('touchstart', (e) => { const t = e.touches[0]; startDrag(t.clientX, t.clientY); }, { passive: true });
    document.addEventListener('touchmove', (e) => { if (!isDragging) return; const t = e.touches[0]; moveDrag(t.clientX, t.clientY); }, { passive: true });
    document.addEventListener('touchend', endDrag);
  
    fab.onclick = function (e) {
      if (hasMoved) {
        e.stopImmediatePropagation();
        e.preventDefault();
        return false;
      }
      toggleChatbot();
    };
  })();
  
  /* Reactivate from navbar — resets to default position and clears the dismissed flag */
  function reactivateChatbot() {
    const fab = document.getElementById('chatbot-fab');
    const navReactivate = document.getElementById('nav-chatbot-reactivate');
  
    try { localStorage.removeItem('lp_chat_dismissed'); } catch (e) {}
  
    if (fab) {
      fab.style.display = 'flex';
      fab.style.left = '';
      fab.style.top = '';
      fab.style.right = '24px';
      fab.style.bottom = '24px';
    }
    if (navReactivate) navReactivate.style.display = 'none';
  }

// Save/favorite (.js-save-heart) click handling used to be duplicated
// here as a second, unguarded, site-wide handler — every real click
// fired both this one AND design/_favorite_js.blade.php's handler,
// sending two overlapping toggle requests whose responses could
// resolve out of order (favorited: true, then false ~1s later, with
// zero further clicks). _favorite_js.blade.php is the single source
// of truth now — it's included on every page that has a .js-save-heart
// button, and has the in-flight/double-bind guards this handler lacked.