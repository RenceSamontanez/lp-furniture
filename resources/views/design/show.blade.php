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
         GENERATING SCREEN
    ═══════════════════════════════════ --}}
    <div class="ai-generating-card">
      <div class="ai-progress-circle">
        <svg viewBox="0 0 100 100" class="ai-progress-svg">
          <circle cx="50" cy="50" r="44" class="ai-progress-track"/>
          <circle cx="50" cy="50" r="44" class="ai-progress-fill"/>
        </svg>
        <div class="ai-progress-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>
        </div>
      </div>
      <div class="ai-generating-status" id="ai-generating-status">Reading Prompt</div>
      <p class="ai-generating-hint">This usually takes about a minute. Please don't close this page.</p>
    </div>

    <script>
      const statusMessages = ['Reading Prompt', 'Thinking', 'Creating Concept', 'Almost Done'];
      let statusIndex = 0;
      setInterval(() => {
        statusIndex = (statusIndex + 1) % statusMessages.length;
        document.getElementById('ai-generating-status').textContent = statusMessages[statusIndex];
      }, 3500);
      setTimeout(() => window.location.reload(), 8000);
    </script>

  @elseif($designRequest->status === 'rejected')

    {{-- ═══════════════════════════════════
         PROFESSIONAL FAILURE SCREEN
         No emoji. Clear explanation. Two
         distinct recovery actions.
    ═══════════════════════════════════ --}}
    <div class="ai-results-card cd-failure-card">
      <div class="cd-failure-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>

      <h3 class="cd-failure-title">We Couldn't Generate Your Design</h3>

      <p class="cd-failure-reason">
        Our AI providers were temporarily unavailable or rate-limited when processing your request.
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
    </div>

  @elseif($designRequest->suggestions->count() > 0)

    {{-- ═══════════════════════════════════
         RESULTS: hero + concept gallery
    ═══════════════════════════════════ --}}
    @php
      $suggestions = $designRequest->suggestions;
      $selected = $suggestions->firstWhere('is_selected', true) ?? $suggestions->first();
      $isFinal = in_array($designRequest->status, ['approved', 'converted_to_order']);
    @endphp

    <div class="ai-results-card">

      <div class="ai-preview-main">
        <img src="{{ $selected->image_path }}" alt="Selected design" class="ai-preview-img" id="ai-preview-img"
             onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
      </div>

      <div class="ai-concepts-label">Choose Your Favorite Concept</div>

      <div class="ai-concepts-gallery">
        @foreach($suggestions as $suggestion)
          <div class="ai-concept-thumb {{ $suggestion->id === $selected->id ? 'selected' : '' }}"
               data-id="{{ $suggestion->id }}"
               data-src="{{ $suggestion->image_path }}">
            <img src="{{ $suggestion->image_path }}" alt="Concept" onclick="selectConcept(this.closest('.ai-concept-thumb'))"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
            <button type="button" class="ai-concept-heart js-favorite-heart {{ $suggestion->is_favorited ? 'favorited' : '' }}"
                    data-suggestion-id="{{ $suggestion->id }}">
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

  @else
    <div class="ai-results-card" style="text-align:center;padding:40px;">
      <p style="color:#5C4C3E;">No design concepts generated yet.</p>
    </div>
  @endif

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