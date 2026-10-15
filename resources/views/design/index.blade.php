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

  <div class="cd-layout">

    {{-- ═══════════════════════════════════
         LEFT: Form panel
    ═══════════════════════════════════ --}}
    <div class="cd-form-panel">
      <div class="cd-form-title-row">
        <h1 class="cd-form-title">Custom Design</h1>
        <button type="button" class="cd-howitworks-btn" onclick="openCdHowItWorks()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          How it works
        </button>
      </div>

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

        <p class="cd-daily-limit-note">
          @if($dailyUsed >= $dailyLimit)
            You've used all {{ $dailyLimit }} designs for today — your limit resets at midnight.
          @else
            {{ $dailyLimit - $dailyUsed }} of {{ $dailyLimit }} designs left today.
          @endif
        </p>

        <input type="hidden" name="furniture_type" id="cd-hidden-furniture-type" value="{{ old('furniture_type') }}"/>
        <input type="hidden" name="preferred_material" id="cd-hidden-material" value="{{ old('preferred_material', 'No Preference') }}"/>
        <input type="hidden" name="dimensions_length" id="cd-hidden-dim-length" value="{{ old('dimensions_length') }}"/>
        <input type="hidden" name="dimensions_width" id="cd-hidden-dim-width" value="{{ old('dimensions_width') }}"/>
        <input type="hidden" name="dimensions_height" id="cd-hidden-dim-height" value="{{ old('dimensions_height') }}"/>
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
          <label class="form-label">Dimensions <span style="font-weight:400;color:var(--text-muted);">(optional — leave blank if you're not sure yet)</span></label>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
              <input type="number" id="cd-modal-dim-length" step="0.1" min="0" class="form-input" placeholder="Width (cm)"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Width (cm)</div>
            </div>
            <div>
              <input type="number" id="cd-modal-dim-width" step="0.1" min="0" class="form-input" placeholder="Depth (cm)"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Depth (cm)</div>
            </div>
            <div>
              <input type="number" id="cd-modal-dim-height" step="0.1" min="0" class="form-input" placeholder="Height (cm)"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Height (cm)</div>
            </div>
          </div>
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

  @include('design._validation_error_modal')

  {{-- ══════════════════════════════════════════
       HOW IT WORKS — friendly tutorial modal
  ═══════════════════════════════════════════ --}}
  <div class="cd-modal-overlay" id="cd-howitworks-overlay" onclick="closeCdHowItWorksOnBackdrop(event)">
    <div class="cd-modal cd-howitworks-modal">
      <div class="cd-modal-header">
        <span class="cd-modal-title">How It Works</span>
        <button type="button" class="cd-modal-close" onclick="closeCdHowItWorks()">✕</button>
      </div>

      <div class="cd-modal-body">
        <p class="cd-howitworks-intro">
          Tell us the piece you're picturing, and our AI will sketch a few concepts for you to
          choose from — no design experience needed. The more specific you are, the closer the
          first draft will be to what's in your head.
        </p>

        <div class="cd-howitworks-tips">
          <div class="cd-howitworks-tip">
            <div class="cd-howitworks-tip-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            </div>
            <div>
              <strong>Name the piece</strong>
              <span>A dining table, a bookshelf, a bed frame — start with what it actually is.</span>
            </div>
          </div>
          <div class="cd-howitworks-tip">
            <div class="cd-howitworks-tip-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
            </div>
            <div>
              <strong>Mention a material</strong>
              <span>Narra, oak, mahogany, rattan — wood type changes the whole look and feel.</span>
            </div>
          </div>
          <div class="cd-howitworks-tip">
            <div class="cd-howitworks-tip-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1.1-.3-.3-.4-.6-.4-1 0-.8.7-1.5 1.5-1.5H16c3.3 0 6-2.7 6-6 0-4.4-4-8-10-8z"/></svg>
            </div>
            <div>
              <strong>Describe the style</strong>
              <span>Rustic, modern minimalist, farmhouse, mid-century — pick a mood.</span>
            </div>
          </div>
          <div class="cd-howitworks-tip">
            <div class="cd-howitworks-tip-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 6H3M21 12H3M21 18H3"/></svg>
            </div>
            <div>
              <strong>Add size if it matters</strong>
              <span>"Seats six," "fits a small apartment" — scale helps us get proportions right.</span>
            </div>
          </div>
        </div>

        <div class="cd-howitworks-examples">
          <div class="cd-howitworks-examples-label">A few good examples</div>

          <div class="cd-howitworks-example cd-howitworks-example--good">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <span>"A rustic oak dining table with turned legs and a distressed finish, seats six."</span>
          </div>
          <div class="cd-howitworks-example cd-howitworks-example--good">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <span>"A modern minimalist bookshelf in walnut, floor-to-ceiling, open-back shelving."</span>
          </div>
          <div class="cd-howitworks-example cd-howitworks-example--good">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
            <span>"A cozy mid-century armchair in a warm mahogany frame with tan leather upholstery."</span>
          </div>

          <div class="cd-howitworks-example cd-howitworks-example--vague">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <span>"A table" — technically valid, but too vague for the AI to work with well.</span>
          </div>
        </div>
      </div>

      <div class="cd-modal-footer">
        <button type="button" class="cd-modal-save-btn" onclick="closeCdHowItWorks()">Got it, let's design</button>
      </div>
    </div>
  </div>

  @include('design._preview_modal')
  @include('design._favorite_js')

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

/* ── HOW IT WORKS ── */
function openCdHowItWorks() {
  document.getElementById('cd-howitworks-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeCdHowItWorks() {
  document.getElementById('cd-howitworks-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeCdHowItWorksOnBackdrop(e) {
  if (e.target.id === 'cd-howitworks-overlay') closeCdHowItWorks();
}

function saveCdOptionalPanel() {
  const type = document.getElementById('cd-modal-furniture-type-select').value;
  const material = document.querySelector('input[name="cd_modal_material"]:checked')?.value || 'No Preference';
  const dimLength = document.getElementById('cd-modal-dim-length').value;
  const dimWidth = document.getElementById('cd-modal-dim-width').value;
  const dimHeight = document.getElementById('cd-modal-dim-height').value;
  const additional = document.getElementById('cd-modal-additional').value;

  document.getElementById('cd-hidden-furniture-type').value = type;
  document.getElementById('cd-hidden-material').value = material;
  document.getElementById('cd-hidden-dim-length').value = dimLength;
  document.getElementById('cd-hidden-dim-width').value = dimWidth;
  document.getElementById('cd-hidden-dim-height').value = dimHeight;
  document.getElementById('cd-hidden-additional').value = additional;

  const parts = [];
  if (type) parts.push(type);
  if (material && material !== 'No Preference') parts.push(material);
  document.getElementById('cd-optional-summary').textContent = parts.length ? parts.join(' · ') : 'Furniture Type & Details';

  closeCdOptionalPanel();
}

document.addEventListener('DOMContentLoaded', function () {
  updateCdPromptCount();
});
</script>
@endpush