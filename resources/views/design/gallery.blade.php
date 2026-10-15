@extends('layouts.app')

@section('title', 'My Designs — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap">

  <div class="cd-top-bar">
    <a href="{{ route('design.index') }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back
    </a>
  </div>

  <h1 class="cd-form-title" style="margin-bottom:20px;">My Designs</h1>

  <div class="cd-gallery-tabs">
    <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="cd-gallery-tab {{ $tab === 'history' ? 'active' : '' }}">History</a>
    <a href="{{ route('design.gallery', ['tab' => 'favorites']) }}" class="cd-gallery-tab {{ $tab === 'favorites' ? 'active' : '' }}">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $tab === 'favorites' ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
      Favorites
    </a>
  </div>

  @if($tab === 'favorites')

    @if($items->isEmpty())
      <div class="cd-gallery-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
        <p>No saved designs yet. Tap "Save" on any design to keep it here.</p>
      </div>
    @else
      <div class="cd-gallery-grid">
        @foreach($items as $suggestion)
          <div class="cd-history-card">
            <div class="cd-gallery-item"
                 onclick="openDesignModal({{ $suggestion->design_id }}, {{ $suggestion->id }}, '{{ $suggestion->displayUrl() }}', '{{ addslashes($suggestion->request->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($suggestion->request->description, 150)) }}', '{{ $suggestion->request->status }}', true)">
              <img src="{{ $suggestion->displayUrl() }}" alt="Saved design"
                   onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
            </div>

            <button type="button" class="cd-save-btn-icon js-save-heart saved"
                    data-suggestion-id="{{ $suggestion->id }}" title="Remove from favorites">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2">
                <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
              </svg>
            </button>
          </div>
        @endforeach
      </div>
    @endif

  @else

    @if($items->isEmpty())
      <div class="cd-gallery-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
        <p>Your generated designs will appear here.</p>
      </div>
    @else
      {{-- ═══════════════════════════════════
           PLAYLIST-STYLE HISTORY — every past request, not just
           successful ones. Brand-new "cd-plist-*" classes throughout
           so this redesign can't bleed into the dashboard strip
           (cd-history-grid/cd-history-thumb in design/index.blade.php)
           or the Favorites tab above, which both keep their original
           markup untouched.
      ═══════════════════════════════════ --}}
      @foreach($grouped as $label => $group)
        <div class="cd-plist-group-header">{{ $label }}</div>

        <div class="cd-plist">
          @foreach($group as $req)
            @php
              $isReady = $req->hasLoadableSuggestion();
              $img = $isReady ? ($req->loadableSuggestions()->firstWhere('is_selected', true) ?? $req->loadableSuggestions()->first()) : null;
              $isGenerating = !$isReady && $req->status === 'pending';
              $isFailed = !$isReady && !$isGenerating;
              $metaParts = array_filter([$req->furniture_type, $req->preferred_material, $req->dimensions]);
            @endphp

            <div class="cd-plist-row {{ $isReady ? 'cd-plist-row--ready' : ($isGenerating ? 'cd-plist-row--generating' : 'cd-plist-row--failed') }}"
                 @if($isReady)
                   role="button" tabindex="0"
                   onclick="openDesignModal({{ $req->id }}, {{ $img->id }}, '{{ $img->displayUrl() }}', '{{ addslashes($req->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($req->description, 150)) }}', '{{ $req->status }}', {{ $img->is_favorited ? 'true' : 'false' }})"
                 @endif
            >
              @if($isReady)
                <div class="cd-plist-thumb">
                  <img src="{{ $img->displayUrl() }}" alt="{{ $req->furniture_type ?: 'Custom design' }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
              @elseif($isGenerating)
                <div class="cd-plist-thumb cd-plist-thumb--generating">
                  <span class="cd-plist-spinner"></span>
                </div>
              @else
                <div class="cd-plist-thumb cd-plist-thumb--failed">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
              @endif

              <div class="cd-plist-body">
                <div class="cd-plist-title">{{ Str::limit($req->description, 110) }}</div>

                @if(count($metaParts) > 0)
                  <div class="cd-plist-submeta">{{ implode(' · ', $metaParts) }}</div>
                @endif

                <div class="cd-plist-meta">
                  <span class="cd-plist-time" title="{{ $req->created_at->format('M j, Y g:i A') }}">
                    {{ $req->created_at->diffForHumans() }}
                  </span>

                  {{-- Reserved for a future "negotiation status" chip
                       (Sent to team, Quote ready, etc.) — intentionally
                       left empty for now. --}}
                  <span class="cd-plist-negotiation"></span>
                </div>

                @if($isFailed)
                  <div class="cd-plist-failed-reason">{{ $req->failureReasonLabel() }}</div>
                @endif
              </div>

              <div class="cd-plist-side">
                @if($isReady)
                  <span class="cd-plist-chip cd-plist-chip--ready">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                    Ready
                  </span>
                @elseif($isGenerating)
                  <span class="cd-plist-chip cd-plist-chip--generating">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                    Generating…
                  </span>
                @else
                  <span class="cd-plist-chip cd-plist-chip--failed">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Failed
                  </span>
                  <a href="{{ route('design.index', ['prompt' => $req->description]) }}"
                     class="cd-plist-retry-btn" onclick="event.stopPropagation()">
                    Try again
                  </a>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      @endforeach

      <div class="cd-plist-pagination">
        {{-- bootstrap-5 view: plain semantic .pagination/.page-item/
             .page-link classes, styled to match below — the default
             tailwind view would render unstyled since this storefront
             never loads compiled Tailwind CSS. --}}
        {{ $items->appends(['tab' => 'history'])->links('pagination::bootstrap-5') }}
      </div>
    @endif

  @endif

  @include('design._preview_modal')
  @include('design._favorite_js')
  @include('design._validation_error_modal')

</div>
@endsection