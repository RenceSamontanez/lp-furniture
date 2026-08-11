{{-- ══════════════════════════════════════════
     SHARED FAVORITE/SAVE TOGGLE — one handler for every
     .js-save-heart button on any design page (hero, history
     strip, gallery, concept thumbnails, preview modal). Event
     delegation means it works for buttons added to the DOM
     later too (e.g. the preview modal's heart, whose
     suggestion id is only set when it's opened).

     Two visual "families" share this hook: the pill-style
     Save buttons (.cd-save-btn / .cd-save-btn-icon, state
     class "saved") and the small heart-icon overlay on show.
     blade.php's concept thumbnails (.ai-concept-heart, state
     class "favorited", set via data-state-class).

     Guarded against firing twice per click two different ways:
     - window.__cdFavoriteJsBound stops this listener from ever
       being attached more than once, in case this partial (or
       a bfcache-restored page) ends up executing its <script>
       more than once.
     - a per-button "pending" flag ignores further clicks on the
       SAME button while its request is still in flight, so a
       genuine double-click can't fire two overlapping toggles
       either. Two overlapping toggles is exactly what makes the
       button look like it flips and immediately reverts — the
       backend toggles true→false→true, and whichever response
       resolves last wins, which is not reliably the last click.
═══════════════════════════════════════════ --}}
<script>
if (!window.__cdFavoriteJsBound) {
  window.__cdFavoriteJsBound = true;

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-save-heart');
    if (!btn) return;

    if (btn.dataset.pending === 'true') return;

    const suggestionId = btn.dataset.suggestionId;
    if (!suggestionId) return;

    btn.dataset.pending = 'true';

    fetch('/design/' + suggestionId + '/favorite', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': APP.csrfToken,
        'Accept': 'application/json',
      },
    })
      .then(res => {
        if (!res.ok) throw new Error('Request failed with status ' + res.status);
        return res.json();
      })
      .then(data => {
        const isFavorited = !!data.favorited;
        const stateClass = btn.dataset.stateClass || 'saved';

        btn.classList.toggle(stateClass, isFavorited);

        const svg = btn.querySelector('svg');
        if (svg) svg.setAttribute('fill', isFavorited ? 'currentColor' : 'none');

        const textEl = btn.querySelector('.cd-save-btn-text');
        if (textEl) textEl.textContent = isFavorited ? 'Saved' : 'Save';

        if (btn.hasAttribute('title')) {
          btn.title = isFavorited ? 'Remove from favorites' : 'Save to favorites';
        }
      })
      .catch(err => {
        console.error('Failed to toggle favorite:', err);
      })
      .finally(() => {
        btn.dataset.pending = 'false';
      });
  });
}
</script>
