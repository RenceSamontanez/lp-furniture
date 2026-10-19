@extends('layouts.app')

@section('title', 'Your Customized Design — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap cd-wrap--wide">

  <div class="cd-top-bar">
    <a href="{{ route('design.index') }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to Customize
    </a>

    <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="cd-history-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
      History
    </a>
  </div>

  @php
    // The design image sits beside the details on wide screens (and on top
    // on phones) once there is a loadable design to show.
    $showMedia = !in_array($designRequest->status, ['pending', 'rejected', 'declined'], true)
        && $designRequest->hasLoadableSuggestion();
  @endphp
  <div class="co-layout {{ $showMedia ? 'co-layout--media' : '' }}">
  <div class="co-main">


  @if($errors->has('quote'))
    <div class="co-error" role="alert">{{ $errors->first('quote') }}</div>
  @endif

  @if(isset($quote) && $quote)
    @include('design._quote-summary', ['quote' => $quote])
  @endif

  @if(in_array($designRequest->pipeline_status, ['accepted', 'ordered'], true) && isset($agreement) && $agreement)
    @php $agreementOrder = $agreement->order; @endphp
    <div class="co-status">
      <svg class="co-status-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
      <div>
        <div class="co-status-title">Agreed on {{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}, next step is payment.</div>
        <div class="co-status-text">
          Agreement {{ $agreement->agreement_number }}.
          @if(!$agreementOrder && $designRequest->payment_due_at)
            Start your payment by {{ $designRequest->payment_due_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
          @endif
          @if($agreementOrder)
            Order {{ $agreementOrder->order_number }}
            @if($agreementOrder->order_status === 'completed') is completed. @else has been created. @endif
          @endif
        </div>
        <div class="co-status-actions">
          @if($agreementOrder)
            <a href="{{ route('orders.show', $agreementOrder->id) }}" class="ai-action-btn ai-action-primary" style="text-decoration:none;">View order</a>
          @elseif($designRequest->pipeline_status === 'accepted')
            <a href="{{ route('custom.checkout', $agreement->id) }}" class="ai-action-btn ai-action-primary" style="text-decoration:none;" data-loading="Opening payment..." data-loading-overlay="payment">Proceed to payment</a>
          @endif
          <a href="{{ route('design.agreement', $agreement->id) }}" class="ai-action-btn ai-action-secondary" style="text-decoration:none;">View agreement</a>
        </div>
      </div>
    </div>
  @elseif($designRequest->pipeline_status === 'declined_by_customer')
    <div class="co-status">
      <svg class="co-status-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      <div>
        <div class="co-status-title">You declined this quote.</div>
        <div class="co-status-text">Nothing is due. Contact us if you would like a new quote.</div>
      </div>
    </div>
  @endif

  @if($designRequest->pipeline_status === 'submitted' && $designRequest->status === 'approved')
    <div class="co-status">
      <svg class="co-status-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
      <div>
        <div class="co-status-title">Request sent, we will call you at {{ $designRequest->contact_phone }} during {{ $designRequest->callWindowLabel() }}.</div>
        <div class="co-status-text">This is a request, not an order. No payment is due yet. We'll discuss the price and details on the call.</div>
        @if($designRequest->isEditableByCustomer())
          <div class="co-status-actions">
            <a href="{{ route('design.edit', $designRequest->id) }}" class="ai-action-btn ai-action-secondary" style="text-decoration:none;">Edit my request</a>
          </div>
        @endif
      </div>
    </div>
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
        <button type="submit" class="ai-action-btn ai-action-secondary" style="width:100%;">
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

  @elseif($showMedia)
    {{-- The design and its concepts are shown beside these details (see the media column below). --}}

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
      @if($designRequest->pipeline_status !== null && !$designRequest->selected_suggestion_id)
        <h3 class="cd-failure-title">Sent Without an Image</h3>
        <p class="cd-failure-reason">
          Your description was sent to our team as written. We'll talk through the design with you on the call.
        </p>
      @else
        <h3 class="cd-failure-title">Design Image No Longer Available</h3>
        <p class="cd-failure-reason">
          This design's reference image can no longer be displayed, but your selection is already confirmed — no action needed. Contact us if you'd like the image again.
        </p>
      @endif
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

  @if(!empty($timeline))
    <div class="co-card" style="margin-top:20px;">
      <div class="co-action-title" style="margin-bottom:14px;">What has happened so far</div>
      @include('agreements._timeline', ['entries' => $timeline])
    </div>
  @endif

  </div>{{-- /.co-main --}}

  @if($showMedia)
  <aside class="co-media">

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
      $isFinal = $designRequest->isSentToTeam();
    @endphp

    <div class="ai-results-card">

      <div class="ai-preview-main">
        <img src="{{ $selected->displayUrl() }}" alt="Selected design" class="ai-preview-img" id="ai-preview-img"
             onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
      </div>

      <div class="ai-concepts-label">{{ $isFinal ? "The design you sent to our team" : "Choose Your Favorite Concept" }}</div>

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

      @if(!$isFinal && filled($designRequest->furniture_type))
        {{-- The customer confirms the type: the AI is asked for it, but it can still get it wrong. --}}
        <div class="cd-type-check" role="group" aria-labelledby="cd-type-check-q">
          <div class="cd-type-check-text">
            <p class="cd-type-check-q" id="cd-type-check-q">Is this {{ \App\Support\FurnitureTypes::withArticle($designRequest->furniture_type, $designRequest->furniture_type_other) ?? 'the piece you asked for' }}?</p>
            <p class="cd-type-check-note">We ask the AI for this type, but it can get it wrong, so please check before you continue.</p>
          </div>
          <form method="POST" action="{{ route('design.regenerate', $designRequest->id) }}">
            @csrf
            <button type="submit" class="ai-action-btn ai-action-secondary cd-type-check-btn">No, try again</button>
          </form>
        </div>
      @endif

      @if(!$isFinal)
        <div class="ai-actions-row">
          @unless($designRequest->isLegacyUnsent())
          <form method="POST" action="{{ route('design.regenerate', $designRequest->id) }}">
            @csrf
            <button type="submit" class="ai-action-btn ai-action-secondary" onclick="return confirm('Generate a new set of concepts? This replaces the current ones.')">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
              Regenerate
            </button>
          </form>
          @endunless

          <form method="POST" action="{{ route('design.select', $designRequest->id) }}" id="continue-form">
            @csrf
            <input type="hidden" name="suggestion_id" id="selected-suggestion-id" value="{{ $selected->id }}"/>
            <button type="submit" class="ai-action-btn ai-action-primary">
              Continue →
            </button>
          </form>
        </div>
      @elseif($designRequest->pipeline_status === null)
        {{-- Sent through the old one-click flow; newer requests show the status line above. --}}
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

  </aside>
  @endif
  </div>{{-- /.co-layout --}}

  {{-- Contact fallback — shown in every state so the customer can always
       reach the team about this request. Values come from Admin → Contact Settings. --}}
  @php $contact = \App\Models\BusinessSetting::current(); @endphp
  <div class="cd-contact-strip">
    <div class="cd-contact-title">Questions about your design? Reach our team</div>
    <div class="cd-contact-actions">
      <a href="{{ $contact->phone_href }}" class="cd-contact-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        {{ $contact->phone }}
      </a>
      <a href="mailto:{{ $contact->email }}" class="cd-contact-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>
        {{ $contact->email }}
      </a>
      <a href="{{ $contact->messenger_url }}" class="cd-contact-messenger" target="_blank" rel="noopener noreferrer">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.15.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm6 7.46l-2.94 4.66a1.5 1.5 0 01-2.17.4l-2.34-1.75a.6.6 0 00-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.66a1.5 1.5 0 012.17-.4l2.34 1.75a.6.6 0 00.72 0l3.16-2.4c.42-.32.97.18.69.63z"/></svg>
        Message us on Facebook
      </a>
    </div>
  </div>

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