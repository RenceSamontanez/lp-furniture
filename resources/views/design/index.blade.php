@extends('layouts.app')

@section('title', 'Custom Design — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap">

  <div class="cd-top-bar">
    <a href="{{ route('home') }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back
    </a>

    <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="cd-history-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
      History
    </a>
  </div>

  @if(session('success'))
    <div class="auth-success" style="margin-bottom:20px;">{{ session('success') }}</div>
  @endif

  @if($errors->any())
    <div class="auth-errors" style="margin-bottom:20px;">
      @foreach($errors->all() as $error)
        <div class="auth-error-item">{{ $error }}</div>
      @endforeach
    </div>
  @endif

  <div class="cd-layout">

    {{-- ═══════════════════════════════════
         LEFT: Form panel
    ═══════════════════════════════════ --}}
    <div class="cd-form-panel">
      <h1 class="cd-form-title">Custom Design</h1>

      <form method="POST" action="{{ route('design.submit') }}" enctype="multipart/form-data" id="cd-form">
        @csrf

        <textarea
          name="prompt"
          id="cd-prompt-input"
          class="cd-prompt-textarea"
          required
          minlength="15"
          maxlength="1000"
          placeholder="e.g. Highly detailed six-seater dining table made of solid Narra wood, modern minimalist style with rounded edges, natural finish, warm golden-brown tone..."
          oninput="updateCdPromptCount()"
        >{{ old('prompt') }}</textarea>
        <div class="cd-prompt-footer">
          <span class="cd-prompt-hint">Describe the furniture you want, in your own words.</span>
          <span class="cd-prompt-count" id="cd-prompt-count">0 / 1000</span>
        </div>

        <button type="button" class="cd-optional-pill" onclick="openCdOptionalPanel()">
          <span class="cd-optional-pill-left">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
            <span id="cd-optional-summary">Furniture Type &amp; Details</span>
          </span>
          <span class="cd-optional-pill-add">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          </span>
        </button>

        <button type="submit" class="cd-generate-btn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>
          Generate
        </button>

        <input type="hidden" name="furniture_type" id="cd-hidden-furniture-type" value="{{ old('furniture_type') }}"/>
        <input type="hidden" name="preferred_material" id="cd-hidden-material" value="{{ old('preferred_material', 'No Preference') }}"/>
        <input type="hidden" name="dimensions" id="cd-hidden-dimensions" value="{{ old('dimensions') }}"/>
        <input type="hidden" name="additional_info" id="cd-hidden-additional" value="{{ old('additional_info') }}"/>
      </form>
    </div>

    {{-- ═══════════════════════════════════
         RIGHT: Hero + history + collections

         Each card now has TWO separate parts,
         stacked vertically (not overlapping):
         1. The image itself (clickable → opens modal)
         2. A footer row below it with a plain
            "Save" bookmark button — completely
            outside the image, in normal document
            flow, so it can never be intercepted
            by the image's click.
    ═══════════════════════════════════ --}}
    <div class="cd-preview-panel">

      @if($latestImage)
        <div class="cd-hero-card">
          <div class="cd-hero-img-wrap"
               onclick="openDesignModal({{ $latestWithImages->id }}, {{ $latestImage->id }}, '{{ $latestImage->image_path }}', '{{ addslashes($latestWithImages->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($latestWithImages->description, 150)) }}', '{{ $latestWithImages->status }}', {{ $latestImage->is_favorited ? 'true' : 'false' }})">
            <img src="{{ $latestImage->image_path }}" alt="Latest design" class="cd-hero-img"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
            <span class="cd-hero-label">Latest Design</span>
          </div>

          <div class="cd-card-footer">
            <button type="button" class="cd-save-btn js-save-heart {{ $latestImage->is_favorited ? 'saved' : '' }}"
                    data-suggestion-id="{{ $latestImage->id }}">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="{{ $latestImage->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
              </svg>
              <span class="cd-save-btn-text">{{ $latestImage->is_favorited ? 'Saved' : 'Save' }}</span>
            </button>
          </div>
        </div>
      @else
        <div class="cd-hero-empty">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
          <p>Your first AI design will appear here</p>
        </div>
      @endif

      @if($historyThumbs->count() > 0)
        <div class="cd-history-grid">
          @foreach($historyThumbs as $entry)
            <div class="cd-history-card">
              <div class="cd-history-thumb"
                   onclick="openDesignModal({{ $entry['request']->id }}, {{ $entry['image']->id }}, '{{ $entry['image']->image_path }}', '{{ addslashes($entry['request']->furniture_type ?? 'Custom Furniture') }}', '{{ addslashes(Str::limit($entry['request']->description, 150)) }}', '{{ $entry['request']->status }}', {{ $entry['image']->is_favorited ? 'true' : 'false' }})">
                <img src="{{ $entry['image']->image_path }}" alt="Past design"
                     onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              </div>

              <button type="button" class="cd-save-btn-icon js-save-heart {{ $entry['image']->is_favorited ? 'saved' : '' }}"
                      data-suggestion-id="{{ $entry['image']->id }}" title="Save to favorites">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $entry['image']->is_favorited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                  <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
                </svg>
              </button>
            </div>
          @endforeach
        </div>
      @endif

      <a href="{{ route('design.gallery') }}" class="cd-collections-card">
        <div class="cd-collections-text">
          <span class="cd-collections-label">Collections</span>
          <span class="cd-collections-count">{{ $totalWithImages }} Designs</span>
        </div>
        <div class="cd-collections-arrow">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </div>
      </a>

    </div>

  </div>

  {{-- Optional details modal --}}
  <div class="cd-modal-overlay" id="cd-optional-overlay" onclick="closeCdOptionalOnBackdrop(event)">
    <div class="cd-modal">
      <div class="cd-modal-header">
        <span class="cd-modal-title">Furniture Details</span>
        <button type="button" class="cd-modal-close" onclick="closeCdOptionalPanel()">✕</button>
      </div>

      <div class="cd-modal-body">
        <div class="ai-field-group">
          <label class="form-label">Furniture Type</label>
          <select id="cd-modal-furniture-type-select" class="form-input">
            <option value="">Select a category (optional)</option>
            @foreach(['Dining Table','Coffee Table','Office Desk','Bed Frame','Wardrobe','TV Cabinet','Kitchen Cabinet','Bookshelf','Chair','Custom'] as $type)
              <option value="{{ $type }}">{{ $type }}</option>
            @endforeach
          </select>
        </div>

        <div class="ai-field-group">
          <label class="form-label">Preferred Wood Type</label>
          <div class="ai-radio-grid">
            @foreach(['Narra','Mahogany','Acacia','Gemelina','Oak','Pine','No Preference'] as $wood)
              <label class="ai-radio-chip">
                <input type="radio" name="cd_modal_material" value="{{ $wood }}" {{ $wood === 'No Preference' ? 'checked' : '' }}/>
                <span>{{ $wood }}</span>
              </label>
            @endforeach
          </div>
        </div>

        <div class="ai-field-group">
          <label class="form-label">Dimensions <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
          <input type="text" id="cd-modal-dimensions" class="form-input" placeholder='e.g. 180 × 80 × 75 cm'/>
        </div>

        <div class="ai-field-group">
          <label class="form-label">Additional Information <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
          <textarea id="cd-modal-additional" class="form-input" rows="3"
                    placeholder="Storage drawers, foldable mechanism, hidden compartments, special finish, etc."></textarea>
        </div>
      </div>

      <div class="cd-modal-footer">
        <button type="button" class="cd-modal-save-btn" onclick="saveCdOptionalPanel()">Save Details</button>
      </div>
    </div>
  </div>

  @include('design._preview_modal')

</div>
@endsection

@push('scripts')
<script>
function updateCdPromptCount() {
  const input = document.getElementById('cd-prompt-input');
  document.getElementById('cd-prompt-count').textContent = input.value.length + ' / 1000';
}

function openCdOptionalPanel() {
  document.getElementById('cd-optional-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeCdOptionalPanel() {
  document.getElementById('cd-optional-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeCdOptionalOnBackdrop(e) {
  if (e.target.id === 'cd-optional-overlay') closeCdOptionalPanel();
}

function saveCdOptionalPanel() {
  const type = document.getElementById('cd-modal-furniture-type-select').value;
  const material = document.querySelector('input[name="cd_modal_material"]:checked')?.value || 'No Preference';
  const dimensions = document.getElementById('cd-modal-dimensions').value;
  const additional = document.getElementById('cd-modal-additional').value;

  document.getElementById('cd-hidden-furniture-type').value = type;
  document.getElementById('cd-hidden-material').value = material;
  document.getElementById('cd-hidden-dimensions').value = dimensions;
  document.getElementById('cd-hidden-additional').value = additional;

  const parts = [];
  if (type) parts.push(type);
  if (material && material !== 'No Preference') parts.push(material);
  document.getElementById('cd-optional-summary').textContent = parts.length ? parts.join(' · ') : 'Furniture Type & Details';

  closeCdOptionalPanel();
}

document.addEventListener('DOMContentLoaded', updateCdPromptCount);
</script>
@endpush