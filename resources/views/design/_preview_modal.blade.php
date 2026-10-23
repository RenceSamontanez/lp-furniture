{{-- ═══════════════════════════════════
     SHARED DESIGN PREVIEW BOX (glass modal)
     Opened by any element with .js-open-design + data-design='{json}'
     (see CustomDesignRequest::previewPayload). Needs my-designs.css.
═══════════════════════════════════ --}}
<div class="md-modal-scrim" id="md-modal" hidden>
  <div class="md-modal" role="dialog" aria-modal="true" aria-labelledby="md-modal-name">

    <div class="md-modal-img"><img src="" alt="" id="md-modal-img"/></div>

    <div class="md-modal-info">
      <div class="md-modal-top">
        <span class="md-status md-status--ok" id="md-modal-chip">✓ Ready</span>
        <button type="button" class="md-modal-x" id="md-modal-close" aria-label="Close preview">×</button>
      </div>

      <div>
        <div class="md-cap">AI DESIGN</div>
        <h2 class="md-modal-name" id="md-modal-name">Design</h2>
      </div>

      <div class="md-facts">
        <div class="md-fact"><span class="md-cap">STYLE</span><b id="md-modal-style">—</b></div>
        <div class="md-fact"><span class="md-cap">CREATED</span><b id="md-modal-created">—</b></div>
      </div>

      <div class="md-modal-desc" tabindex="0" role="region" aria-label="Your description">
        <span class="md-cap">YOUR DESCRIPTION</span>
        <p id="md-modal-desc"></p>
      </div>

      <div class="md-modal-acts">
        <form method="POST" action="" id="md-modal-order" class="md-act-form">
          @csrf
          <input type="hidden" name="suggestion_id" id="md-modal-suggestion" value=""/>
          <button type="submit" class="lp-btn lp-btn--primary md-act-primary">Order this design</button>
        </form>
        <a href="#" id="md-modal-concept" class="lp-btn lp-btn--primary md-act-primary" hidden>View concept</a>

        <button type="button" class="lp-btn lp-btn--secondary md-save js-save-heart" id="md-modal-save" aria-pressed="false">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="md-modal-save-icon"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          <span class="cd-save-btn-text">Save</span>
        </button>
      </div>

      <a href="#" id="md-modal-all" class="lp-link md-modal-all">See all concepts, or generate again</a>
    </div>
  </div>
</div>

<script>
(function () {
  var scrim = document.getElementById('md-modal');
  var FOCUSABLE = 'a[href]:not([hidden]),button:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var opener = null;

  function $(id) { return document.getElementById(id); }

  function setSaved(on) {
    var btn = $('md-modal-save');
    btn.classList.toggle('saved', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    $('md-modal-save-icon').setAttribute('fill', on ? 'currentColor' : 'none');
    btn.querySelector('.cd-save-btn-text').textContent = on ? 'Saved' : 'Save';
  }

  function openDesignModal(d, from) {
    opener = from || document.activeElement;
    $('md-modal-img').src = d.image;
    $('md-modal-img').alt = d.name;
    $('md-modal-name').textContent = d.name;
    $('md-modal-style').textContent = d.style;
    $('md-modal-created').textContent = d.created;
    $('md-modal-desc').textContent = d.description;
    $('md-modal-chip').textContent = d.ordered ? '✓ Ordered' : '✓ Ready';
    $('md-modal-suggestion').value = d.suggestionId;
    $('md-modal-order').action = d.orderUrl;
    $('md-modal-order').hidden = d.ordered;
    $('md-modal-concept').href = d.conceptUrl;
    $('md-modal-concept').hidden = !d.ordered;
    $('md-modal-all').href = d.conceptUrl;
    $('md-modal-all').hidden = d.ordered;
    $('md-modal-save').dataset.suggestionId = d.suggestionId;

    // The bookmark on the page behind is kept live by the favorite script; the
    // payload is only as fresh as the page render.
    var source = document.querySelector('.js-save-heart[data-suggestion-id="' + d.suggestionId + '"]:not(#md-modal-save)');
    setSaved(source ? source.classList.contains('saved') : !!d.favorited);

    scrim.hidden = false;
    document.body.style.overflow = 'hidden';
    $('md-modal-close').focus();
  }

  function closeDesignModal() {
    if (scrim.hidden) return;
    scrim.hidden = true;
    document.body.style.overflow = '';
    if (opener && document.contains(opener)) opener.focus();
    opener = null;
  }

  window.openDesignModal = openDesignModal;
  window.closeDesignModal = closeDesignModal;
  window.mdSyncModalSave = setSaved;

  document.addEventListener('click', function (e) {
    var el = e.target.closest('.js-open-design');
    if (el) {
      var inner = e.target.closest('a,button,form,input');
      if (inner && inner !== el) return; // a real button or link inside a clickable row
      var data;
      try { data = JSON.parse(el.getAttribute('data-design')); } catch (err) { return; }
      var from = el.matches('button,a') ? el : (el.querySelector('.md-thumb-btn') || el);
      openDesignModal(data, from);
      return;
    }
    if (e.target === scrim || e.target.closest('#md-modal-close')) closeDesignModal();
  });

  document.addEventListener('keydown', function (e) {
    var key = e.target.closest && e.target.closest('div.js-open-design[role="button"]');
    if (scrim.hidden && key && key === e.target && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      key.click();
      return;
    }
    if (scrim.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); closeDesignModal(); return; }
    if (e.key !== 'Tab') return;
    var items = Array.prototype.filter.call(scrim.querySelectorAll(FOCUSABLE), function (n) { return n.offsetWidth || n.offsetHeight || n.getClientRects().length; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (!scrim.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
})();
</script>
