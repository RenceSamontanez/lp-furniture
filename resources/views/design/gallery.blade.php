@extends('layouts.app')

@section('title', 'My Designs — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/my-designs.css') }}"/>
@endpush

@php
  $tabs = [
    'history'   => ['History',   'Every design request you made, including the ones that did not work.'],
    'gallery'   => ['Gallery',   'All your finished designs in one place.'],
    'favorites' => ['Favorites', 'Finished designs you saved with the bookmark.'],
  ];
  $bookmark = 'M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z';
@endphp

@section('content')
<div class="cd-wrap md-wrap">

  <div class="cd-top-bar">
    <a href="{{ route('design.index') }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back
    </a>
  </div>

  <div class="md-head">
    <div>
      <h1 class="md-title">My Designs</h1>
      <p class="md-sub">Everything you have asked our AI to design.</p>
    </div>
    <a href="{{ route('design.index') }}" class="lp-btn lp-btn--primary md-new">Design something new</a>
  </div>

  <div class="md-tabs" role="tablist" aria-label="My Designs">
    @foreach($tabs as $key => [$label, $help])
      <a href="{{ route('design.gallery', ['tab' => $key]) }}" role="tab" id="md-tab-{{ $key }}" class="md-tab"
         aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="md-panel" tabindex="{{ $tab === $key ? '0' : '-1' }}">
        {{ $label }}<span class="md-tab-n" data-count="{{ $key }}">{{ $counts[$key] }}</span>
      </a>
    @endforeach
  </div>

  <p class="md-help">{{ $tabs[$tab][1] }}</p>

  <div id="md-panel" role="tabpanel" aria-labelledby="md-tab-{{ $tab }}" tabindex="0">

  @if($tab === 'history')

    <div class="md-filters" role="group" aria-label="Filter history">
      @foreach(['all' => 'All', 'ready' => 'Ready', 'failed' => 'Failed'] as $key => $label)
        <a href="{{ route('design.gallery', ['tab' => 'history', 'filter' => $key]) }}" class="md-chip" @if($filter === $key) aria-current="true" @endif>{{ $label }}</a>
      @endforeach
    </div>

    @if($grouped->isEmpty())
      <div class="md-empty">
        @if($counts['history'] === 0)
          <h2>No designs yet</h2>
          <p>Your design requests will appear here.</p>
          <a href="{{ route('design.index') }}" class="lp-btn lp-btn--primary">Design something new</a>
        @else
          <h2>Nothing here</h2>
          <p>No designs match this filter.</p>
          <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="lp-btn lp-btn--secondary">Show all</a>
        @endif
      </div>
    @else
      @foreach($grouped as $label => $group)
        <h2 class="md-day">{{ $label }}</h2>
        @foreach($group as $req)
          @php
            $state = $req->designState();
            $s = $state === 'ready' ? $req->representativeSuggestion() : null;
            $payload = $s ? json_encode($req->previewPayload($s)) : null;
          @endphp
          <article class="md-row md-row--{{ $state }} @if($s) js-open-design @endif" @if($s) data-design="{{ $payload }}" @endif>
            @if($s)
              <button type="button" class="md-thumb md-thumb-btn js-open-design" data-design="{{ $payload }}" aria-label="Open preview: {{ $req->designName() }}">
                <img src="{{ $s->displayUrl() }}" alt="" loading="lazy" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              </button>
            @elseif($state === 'generating')
              <div class="md-thumb md-thumb--busy" aria-hidden="true"><span class="cd-plist-spinner"></span></div>
            @else
              <div class="md-thumb md-thumb--failed" aria-hidden="true">
                <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
              </div>
            @endif

            <div class="md-body">
              <div class="md-prompt">{{ $req->description }}</div>
              <div class="md-meta"><span>{{ $req->styleLabel() }}</span><span title="{{ $req->created_at->format('M j, Y g:i A') }}">{{ $req->whenLabel() }}</span></div>
              @if($state === 'failed')
                <div class="md-why">{{ $req->failureReasonLabel() }}</div>
              @endif
            </div>

            <div class="md-side">
              @if($state === 'ready')
                <span class="md-status md-status--ok">✓ Ready</span>
                <div class="md-acts">
                  @include('design._design_action', ['req' => $req, 'suggestion' => $s])
                  <button type="button" class="lp-btn lp-btn--secondary js-open-design" data-design="{{ $payload }}">View</button>
                </div>
              @elseif($state === 'generating')
                <span class="md-status md-status--busy">● Making your design</span>
                <div class="md-acts"><a href="{{ route('design.show', $req->id) }}" class="lp-btn lp-btn--secondary">See progress</a></div>
              @else
                <span class="md-status md-status--bad">✕ Failed</span>
                <div class="md-acts"><a href="{{ route('design.index', ['prompt' => $req->description]) }}" class="lp-btn lp-btn--primary">Try again</a></div>
              @endif
            </div>
          </article>
        @endforeach
      @endforeach

      <div class="cd-plist-pagination">{{ $paginator->links() }}</div>
    @endif

  @else

    @if($galleryItems->isEmpty())
      <div class="md-empty">
        @if($tab === 'gallery')
          <h2>No finished designs yet</h2>
          <p>Designs that finish successfully show up here.</p>
          <a href="{{ route('design.index') }}" class="lp-btn lp-btn--primary">Design something new</a>
        @else
          <h2>No favorites yet</h2>
          <p>Tap the bookmark on any finished design in your Gallery to keep it here.</p>
          <a href="{{ route('design.gallery', ['tab' => 'gallery']) }}" class="lp-btn lp-btn--primary">Open Gallery</a>
        @endif
      </div>
    @else
      <div class="md-grid" data-grid="{{ $tab }}">
        @foreach($galleryItems as $entry)
          @php $req = $entry['request']; $s = $entry['suggestion']; $payload = json_encode($req->previewPayload($s)); @endphp
          <article class="md-card">
            <div class="md-card-img">
              <button type="button" class="md-thumb-btn js-open-design" data-design="{{ $payload }}" aria-label="Open preview: {{ $req->designName() }}">
                <img src="{{ $s->displayUrl() }}" alt="" loading="lazy" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              </button>
              <span class="md-ai-tag">AI DESIGN</span>
              <button type="button" class="md-fav js-save-heart {{ $s->is_favorited ? 'saved' : '' }}" data-suggestion-id="{{ $s->id }}"
                      aria-pressed="{{ $s->is_favorited ? 'true' : 'false' }}" aria-label="{{ $s->is_favorited ? 'Remove from favorites' : 'Save to favorites' }}"
                      @if($tab === 'favorites') data-remove-card @endif>
                <svg viewBox="0 0 24 24" fill="{{ $s->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2"><path d="{{ $bookmark }}"/></svg>
              </button>
            </div>
            <div class="md-card-body">
              <div class="md-prompt">{{ $req->description }}</div>
              <div class="md-meta"><span>{{ $req->styleLabel() }}</span><span>{{ $req->whenLabel() }}</span></div>
              <div class="md-acts">
                @include('design._design_action', ['req' => $req, 'suggestion' => $s])
                <button type="button" class="lp-btn lp-btn--secondary js-open-design" data-design="{{ $payload }}">View</button>
              </div>
            </div>
          </article>
        @endforeach
      </div>
    @endif

  @endif

  </div>

  @include('design._preview_modal')
  @include('design._favorite_js')
  @include('design._validation_error_modal')

</div>
@endsection

@push('scripts')
<script>
// Tabs: arrow keys move between them (Home/End too); the page for that tab loads.
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.md-tab'));
  try { if (sessionStorage.getItem('mdTabFocus')) { sessionStorage.removeItem('mdTabFocus'); var sel = document.querySelector('.md-tab[aria-selected="true"]'); if (sel) sel.focus(); } } catch (e) {}
  tabs.forEach(function (tab, i) {
    tab.addEventListener('keydown', function (e) {
      var next = null;
      if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
      else if (e.key === 'ArrowLeft') next = tabs[(i + tabs.length - 1) % tabs.length];
      else if (e.key === 'Home') next = tabs[0];
      else if (e.key === 'End') next = tabs[tabs.length - 1];
      if (!next) return;
      e.preventDefault();
      try { sessionStorage.setItem('mdTabFocus', '1'); } catch (err) {}
      window.location.href = next.href;
    });
  });
})();
</script>
@endpush
