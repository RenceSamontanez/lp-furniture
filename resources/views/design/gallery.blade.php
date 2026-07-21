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
                 onclick="openDesignModal({{ $suggestion->design_id }}, {{ $suggestion->id }}, '{{ $suggestion->image_path }}', '{{ addslashes($suggestion->request->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($suggestion->request->description, 150)) }}', '{{ $suggestion->request->status }}', true)">
              <img src="{{ $suggestion->image_path }}" alt="Saved design"
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
      <div class="cd-gallery-grid">
        @foreach($items as $req)
          @php $img = $req->suggestions->firstWhere('is_selected', true) ?? $req->suggestions->first(); @endphp
          <div class="cd-history-card">
            <div class="cd-gallery-item"
                 onclick="openDesignModal({{ $req->id }}, {{ $img->id }}, '{{ $img->image_path }}', '{{ addslashes($req->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($req->description, 150)) }}', '{{ $req->status }}', {{ $img->is_favorited ? 'true' : 'false' }})">
              <img src="{{ $img->image_path }}" alt="{{ $req->furniture_type }}"
                   onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              <span class="cd-gallery-item-label">{{ $req->furniture_type ?: 'Custom' }}</span>
            </div>

            <button type="button" class="cd-save-btn-icon js-save-heart {{ $img->is_favorited ? 'saved' : '' }}"
                    data-suggestion-id="{{ $img->id }}" title="Save to favorites">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $img->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
              </svg>
            </button>
          </div>
        @endforeach
      </div>
    @endif

  @endif

  @include('design._preview_modal')

</div>
@endsection