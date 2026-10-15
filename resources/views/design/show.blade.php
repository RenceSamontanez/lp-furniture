@extends('layouts.app')

@section('title', 'Your AI Design — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap">

  <div class="cd-top-bar">
    <a href="{{ route('design.index') }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to Custom Design
    </a>

    <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="cd-history-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
      History
    </a>
  </div>

  @if(session('success'))
    <div class="auth-success" style="max-width:680px;margin:0 auto 20px;">{{ session('success') }}</div>
  @endif

  @if($designRequest->status === 'pending')

    {{-- ═══════════════════════════════════
         GENERATING SCREEN — spinner style matches the checkout
         "Preparing Your Order" screen (warm cream card, gold
         ring spinner) for a consistent loading feel site-wide.
    ═══════════════════════════════════ --}}
    <div class="ai-generating-card">
      <div class="ai-generating-spinner"></div>
      <div class="ai-generating-status" id="ai-generating-status">Analyzing your request...</div>
      <p class="ai-generating-hint">This can take a minute or two. Please don't close this page.</p>
    </div>

    <script>
      // 6 messages at a 2.5s cycle = 15s for a full pass — the
      // reload interval below is set to let one full pass finish
      // before refreshing, so every message actually gets seen
      // instead of the page reloading (and resetting back to
      // message 0) before later messages ever get a turn.
      const statusMessages = [
        'Analyzing your request...',
        'Sketching initial concepts...',
        'Selecting materials & finishes...',
        'Rendering details...',
        'Adding finishing touches...',
        'Almost done...',
      ];
      let statusIndex = 0;
      setInterval(() => {
        statusIndex = (statusIndex + 1) % statusMessages.length;
        document.getElementById('ai-generating-status').textContent = statusMessages[statusIndex];
      }, 2500);
      setTimeout(() => window.location.reload(), 15000);
    </script>

  @elseif($designRequest->status === 'rejected')

    {{-- ═══════════════════════════════════
         PROFESSIONAL FAILURE SCREEN
         No emoji. Clear explanation. Three
         recovery actions — the third (send without
         an image) is PART 3 of the provider-chain
         rescue: keeps the prompt/details exactly as
         submitted and pushes the request straight
         into the admin's normal follow-up queue.
    ═══════════════════════════════════ --}}
    <div class="ai-results-card cd-failure-card">
      <div class="cd-failure-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>

      <h3 class="cd-failure-title">We Couldn't Generate Your Design</h3>

      <p class="cd-failure-reason">
        {{ $designRequest->failureReasonLabel() }}.
        This is usually a short-term issue on the provider's side, not a problem with your description.
      </p>

      <div class="cd-failure-actions">
        <form method="POST" action="{{ route('design.regenerate', $designRequest->id) }}" style="flex:1;">
          @csrf
          <button type="submit" class="ai-action-btn ai-action-secondary" style="width:100%;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
            Retry Same Prompt
          </button>
        </form>

        <a href="{{ route('design.index') }}" class="ai-action-btn ai-action-primary" style="flex:1;text-decoration:none;">
          Start New Prompt
        </a>
      </div>

      <form method="POST" action="{{ route('design.sendWithoutImage', $designRequest->id) }}" style="margin-top:10px;">
        @csrf
        <button type="submit" class="ai-action-btn ai-action-secondary" style="width:100%;" onclick="return confirm('Send this request to our team without a design image? We\'ll reach out to discuss it directly.')">
          Send my request to the team without an image
        </button>
      </form>
    </div>

  @elseif($designRequest->status === 'declined')

    {{-- ═══════════════════════════════════
         ADMIN DECLINED — distinct from the AI-generation-failed
         screen above. This was a deliberate decision on a design
         that generated successfully, not a technical hiccup, so
         it shouldn't suggest retrying the same prompt will help.
    ═══════════════════════════════════ --}}
    <div class="ai-results-card cd-failure-card">
      <div class="cd-failure-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>

      <h3 class="cd-failure-title">We're Not Able to Take This On</h3>

      <p class="cd-failure-reason">
        @if($designRequest->admin_notes)
          {{ $designRequest->admin_notes }}
        @else
          After review, our team isn't able to produce this particular design. Feel free to try a
          different idea, or reach out if you'd like to discuss it further.
        @endif
      </p>

      <div class="cd-failure-actions">
        <a href="{{ route('design.index') }}" class="ai-action-btn ai-action-primary" style="flex:1;text-decoration:none;">
          Start New Prompt
        </a>
      </div>
    </div>

  @elseif($designRequest->hasLoadableSuggestion())

    {{-- ═══════════════════════════════════
         RESULTS: hero + concept gallery
         Only ever built from LOADABLE suggestions — see PART 1 of the
         image-reliability fix. A row whose image_path is a legacy
         external URL (from before every provider saved locally) is
         left out here entirely rather than shown and left to fail
         via onerror.
    ═══════════════════════════════════ --}}
    @php
      $suggestions = $designRequest->loadableSuggestions();
      $selected = $suggestions->firstWhere('is_selected', true) ?? $suggestions->first();
      $isFinal = in_array($designRequest->status, ['approved', 'converted_to_order']);
    @endphp

    <div class="ai-results-card">

      <div class="ai-preview-main">
        <img src="{{ $selected->displayUrl() }}" alt="Selected design" class="ai-preview-img" id="ai-preview-img"
             onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
      </div>

      <div class="ai-concepts-label">Choose Your Favorite Concept</div>

      <div class="ai-concepts-gallery">
        @foreach($suggestions as $suggestion)
          <div class="ai-concept-thumb {{ $suggestion->id === $selected->id ? 'selected' : '' }}"
               data-id="{{ $suggestion->id }}"
               data-src="{{ $suggestion->displayUrl() }}">
            <img src="{{ $suggestion->displayUrl() }}" alt="Concept" onclick="selectConcept(this.closest('.ai-concept-thumb'))"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
            <button type="button" class="ai-concept-heart js-save-heart {{ $suggestion->is_favorited ? 'favorited' : '' }}"
                    data-suggestion-id="{{ $suggestion->id }}" data-state-class="favorited">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $suggestion->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
              </svg>
            </button>
          </div>
        @endforeach
      </div>

      @if(!$isFinal)
        <div class="ai-actions-row">
          <form method="POST" action="{{ route('design.regenerate', $designRequest->id) }}">
            @csrf
            <button type="submit" class="ai-action-btn ai-action-secondary" onclick="return confirm('Generate a new set of concepts? This replaces the current ones.')">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
              Regenerate
            </button>
          </form>

          <form method="POST" action="{{ route('design.select', $designRequest->id) }}" id="continue-form">
            @csrf
            <input type="hidden" name="suggestion_id" id="selected-suggestion-id" value="{{ $selected->id }}"/>
            <button type="submit" class="ai-action-btn ai-action-primary">
              Continue →
            </button>
          </form>
        </div>
      @else
        <div class="ai-final-notice">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
          Design selected! Our team will contact you shortly to discuss pricing and production.
        </div>
      @endif

      <button type="button" class="ai-prompt-toggle" onclick="togglePromptView()">
        View AI Prompt <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
      </button>
      <div class="ai-prompt-view" id="ai-prompt-view">
        {{ $designRequest->ai_prompt ?? 'Not available.' }}
      </div>

    </div>

  @elseif(in_array($designRequest->status, ['approved', 'converted_to_order']))

    {{-- ═══════════════════════════════════
         FINALIZED, BUT THE IMAGE IS GONE — a decision was already
         made on this design (it's approved or already an order), so
         no regenerate/retry action makes sense here even though the
         image itself can no longer be shown.
    ═══════════════════════════════════ --}}
    <div class="ai-results-card cd-failure-card">
      <div class="cd-failure-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <h3 class="cd-failure-title">Design Image No Longer Available</h3>
      <p class="cd-failure-reason">
        This design's reference image can no longer be displayed, but your selection is already confirmed — no action needed. Contact us if you'd like the image again.
      </p>
    </div>

  @else

    {{-- ═══════════════════════════════════
         NO LOADABLE IMAGE — either nothing ever generated, or every
         suggestion row came from a provider URL with no durability
         guarantee (see AiDesignSuggestion::isLoadable()). Treated as
         Failed either way, matching the 'rejected' screen above.
    ═══════════════════════════════════ --}}
    <div class="ai-results-card cd-failure-card">
      <div class="cd-failure-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>

      <h3 class="cd-failure-title">We Couldn't Generate Your Design</h3>

      <p class="cd-failure-reason">{{ $designRequest->failureReasonLabel() }}.</p>

      <div class="cd-failure-actions">
        <form method="POST" action="{{ route('design.regenerate', $designRequest->id) }}" style="flex:1;">
          @csrf
          <button type="submit" class="ai-action-btn ai-action-secondary" style="width:100%;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
            Retry Same Prompt
          </button>
        </form>

        <a href="{{ route('design.index') }}" class="ai-action-btn ai-action-primary" style="flex:1;text-decoration:none;">
          Start New Prompt
        </a>
      </div>
    </div>
  @endif

  @include('design._favorite_js')
  @include('design._validation_error_modal')

</div>
@endsection

@push('scripts')
<script>
function selectConcept(el) {
  document.querySelectorAll('.ai-concept-thumb').forEach(t => t.classList.remove('selected'));
  el.classList.add('selected');

  document.getElementById('ai-preview-img').src = el.dataset.src;

  const hiddenInput = document.getElementById('selected-suggestion-id');
  if (hiddenInput) hiddenInput.value = el.dataset.id;
}

function togglePromptView() {
  document.getElementById('ai-prompt-view').classList.toggle('open');
}
</script>
@endpush