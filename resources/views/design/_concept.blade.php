{{-- ═══════════════════════════════════
     CONCEPT PAGE for a design that was sent to the team ("View concept").
     One screen on desktop: header, next-step banner + timeline (left), the design image and
     its description (right), contact strip. Banner text comes from App\Support\ConceptBanner;
     contact details from FooterData (Contact Settings first, config/shop.php fallbacks second).
═══════════════════════════════════ --}}
@php
  $banner = \App\Support\ConceptBanner::for($designRequest, $quote ?? null, $agreement ?? null);
  $order = isset($agreement) && $agreement ? $agreement->order : null;
  $conceptImages = $designRequest->loadableSuggestions();
  $mainImage = $designRequest->selectedSuggestion?->isLoadable()
      ? $designRequest->selectedSuggestion
      : ($conceptImages->firstWhere('is_selected', true) ?? $conceptImages->first());
  $sentAt = $designRequest->submitted_at ?? $designRequest->created_at;
  $reference = $order
      ? 'CUSTOM ORDER ' . $order->order_number
      : ($agreement ?? null ? 'AGREEMENT ' . $agreement->agreement_number : 'CUSTOM REQUEST · SENT ' . strtoupper($sentAt->timezone('Asia/Manila')->format('M j, Y')));
  $contactData = \App\Support\FooterData::build();
  $facebook = $contactData['facebook'] ?? null;
  $messenger = $facebook ? (new \App\Models\BusinessSetting())->forceFill(['facebook_url' => $facebook])->messenger_url : null;
  $promptText = trim((string) $designRequest->ai_prompt);
  $descText = trim((string) $designRequest->description);
@endphp

<div class="cc-page">

  <x-back-link :href="route('design.gallery', ['tab' => 'history'])" label="My Designs" class="cc-back" />

  <div class="cc-head">
    <div class="cc-head-text">
      <div class="cc-cap">{{ $reference }}</div>
      <h1 class="cc-title">Your design concept</h1>
      <p class="cc-name">{{ $designRequest->designName() }}</p>
    </div>
    <span class="cc-chip cc-chip--{{ $banner['tone'] }}" data-concept-state="{{ $banner['key'] }}">{{ $banner['chip'] }}</span>
  </div>

  @if($errors->has('quote'))
    <div class="co-error" role="alert">{{ $errors->first('quote') }}</div>
  @endif

  <div class="co-layout co-layout--media cc-cols">

    <div class="cc-left">
      <x-notice :variant="$banner['variant']" :title="$banner['title']" class="cc-banner">
        {{ $banner['text'] }}
        @if(!empty($banner['actions']))
          <x-slot:actions>
            @foreach($banner['actions'] as $a)
              <a href="{{ $a['href'] }}" class="lp-btn {{ $a['primary'] ? 'lp-btn--primary' : 'lp-btn--secondary' }}"
                 @if(!empty($a['loading'])) data-loading="{{ $a['loading'] }}" data-loading-overlay="payment" @endif>{{ $a['label'] }}</a>
            @endforeach
          </x-slot:actions>
        @endif
      </x-notice>

      @if(isset($quote) && $quote)
        <details class="cc-quote">
          <summary>{{ $quote->status === 'sent' ? 'See the quote details' : 'See the expired quote' }}</summary>
          @include('design._quote-summary', ['quote' => $quote, 'compact' => true])
        </details>
      @endif

      @if(!empty($timeline))
        <section class="cc-card cc-glass cc-timeline" aria-labelledby="cc-tl-title">
          <h2 class="cc-cap" id="cc-tl-title">What Has Happened So Far</h2>
          <ol class="cc-tl">
            @foreach($timeline as $e)
              @php $at = $e['at']->timezone('Asia/Manila'); @endphp
              <li class="cc-tl-{{ $e['kind'] }}">
                <b class="cc-tl-title">{{ $e['title'] }}</b>
                <time class="cc-tl-when" datetime="{{ $at->toIso8601String() }}">{{ $at->format($at->isCurrentYear() ? 'M j, g:i A' : 'M j, Y, g:i A') }}</time>
                @if($e['detail'])<em class="cc-tl-detail">{{ $e['detail'] }}</em>@endif
              </li>
            @endforeach
          </ol>
        </section>
      @endif
    </div>

    <aside class="co-media cc-media cc-card cc-glass" aria-label="The design you sent to our team">
      <div class="cc-cap">THE DESIGN YOU SENT TO OUR TEAM</div>
      <div class="cc-design">
        <div class="cc-visual">
          @if($mainImage)
            <div class="cc-image"><img src="{{ $mainImage->displayUrl() }}" alt="The design you sent" id="cc-main-img"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/></div>
            <div class="cc-thumbs" role="group" aria-label="Images you sent">
              @foreach($conceptImages as $s)
                <div class="cc-thumb-wrap">
                  <button type="button" class="cc-thumb {{ $s->id === $mainImage->id ? 'selected' : '' }}" data-src="{{ $s->displayUrl() }}" aria-label="Show this image" aria-pressed="{{ $s->id === $mainImage->id ? 'true' : 'false' }}">
                    <img src="{{ $s->displayUrl() }}" alt="" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                  </button>
                  <button type="button" class="ai-concept-heart js-save-heart {{ $s->is_favorited ? 'favorited' : '' }}"
                          data-suggestion-id="{{ $s->id }}" data-state-class="favorited" aria-label="Save to favorites">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $s->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
                    </svg>
                  </button>
                </div>
              @endforeach
            </div>
          @elseif($designRequest->pipeline_status !== null && !$designRequest->selected_suggestion_id)
            <div class="cc-noimage"><h3>Sent Without an Image</h3><p>Your description was sent to our team as written. We'll talk through the design with you on the call.</p></div>
          @else
            <div class="cc-noimage"><h3>Design Image No Longer Available</h3><p>This design's reference image can no longer be displayed, but your selection is already confirmed. Contact us if you'd like the image again.</p></div>
          @endif
        </div>

        <div class="cc-prompt" tabindex="0" role="region" aria-label="Your description">
          <span class="cc-cap">YOUR DESCRIPTION</span>
          <p>{{ $descText !== '' ? $descText : 'Not available.' }}</p>
          @if($promptText !== '' && $promptText !== $descText)
            <span class="cc-cap cc-cap--gap">AI PROMPT</span>
            <p class="cc-prompt-ai">{{ $promptText }}</p>
          @endif
        </div>
      </div>
    </aside>
  </div>

  <section class="cc-contact cc-glass" aria-label="Contact our team">
    <b class="cc-contact-title">Questions about your design? Reach our team</b>
    <div class="cc-contact-list">
      @if($contactData['phone'])
        <a href="{{ $contactData['phone']['href'] }}" class="lp-link cc-contact-item"><span aria-hidden="true">☎</span> {{ $contactData['phone']['display'] }}</a>
      @endif
      @if($contactData['email'])
        <a href="mailto:{{ $contactData['email'] }}" class="lp-link cc-contact-item"><span aria-hidden="true">✉</span> {{ $contactData['email'] }}</a>
      @endif
    </div>
    @if($messenger)
      <a href="{{ $messenger }}" class="lp-btn lp-btn--secondary cc-contact-fb" target="_blank" rel="noopener noreferrer">Message Us on Facebook</a>
    @endif
  </section>
</div>

@push('scripts')
<script>
document.querySelectorAll('.cc-thumb').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('cc-main-img').src = btn.dataset.src;
    document.querySelectorAll('.cc-thumb').forEach(function (b) {
      b.classList.toggle('selected', b === btn);
      b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
    });
  });
});
</script>
@endpush
