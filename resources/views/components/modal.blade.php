@props(['id', 'title', 'summaryForm' => null])
{{-- Shared confirmation modal (.lp-modal). Open with any element carrying data-lp-modal-open="{{ $id }}".
     Slots: default = body, footer = the buttons (use .lp-btn; the confirming button gets data-lp-confirm).
     Behaviour in public/assets/js/lp-modal.js: focus trap, ESC and click-outside close, focus returns to the opener. --}}
<div class="lp-modal" id="{{ $id }}" hidden @if($summaryForm) data-lp-summary-form="{{ $summaryForm }}" @endif>
  <div class="lp-modal__box" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
    <div class="lp-modal__head">
      <h2 class="lp-modal__title" id="{{ $id }}-title">{{ $title }}</h2>
      <button type="button" class="lp-modal__x" data-lp-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="lp-modal__body">{{ $slot }}</div>
    <div class="lp-modal__foot">{{ $footer ?? '' }}</div>
  </div>
</div>
