@extends('layouts.app')

@section('title', 'Send to Our Team — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
@php
  $mode = old('request_mode', 'exact');
  $window = old('preferred_call_window');
@endphp
<div class="cd-wrap">

  <div class="cd-top-bar">
    <a href="{{ route('design.show', $designRequest->id) }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to your design
    </a>
  </div>

  <div class="co-card">
    <div class="co-head">
      <h1 class="co-title">Send to our team</h1>
      <p class="co-lead">
        This is a request, not an order. No payment is due yet. Our team will call you to discuss the price and details.
      </p>
    </div>

    <div class="co-design">
      @if($suggestion)
        <img src="{{ $suggestion->displayUrl() }}" alt="Selected concept" class="co-design-img"
             onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
      @else
        <div class="co-design-img co-design-img--none">No image</div>
      @endif
      <div class="co-design-meta">
        <div class="co-design-name">{{ $designRequest->title }}</div>
        <div class="co-design-desc">{{ \Illuminate\Support\Str::limit($designRequest->description, 160) }}</div>
        @unless($suggestion)
          <div class="co-design-note">Sending your description only. Our team will discuss the design with you directly.</div>
        @endunless
      </div>
    </div>

    @if($errors->any())
      <div class="co-errors" role="alert">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('design.sendToTeam', $designRequest->id) }}" class="co-form" id="co-send-form">
      @csrf
      @if($suggestion)
        <input type="hidden" name="suggestion_id" value="{{ $suggestion->id }}"/>
      @endif

      <fieldset class="co-section">
        <legend class="co-section-title">How can we reach you?</legend>
        <div class="co-grid">
          <div class="co-field">
            <label class="form-label" for="contact_phone">Mobile number</label>
            <input type="tel" id="contact_phone" name="contact_phone" class="form-input" required maxlength="16"
                   value="{{ old('contact_phone', $defaultPhone) }}" placeholder="09171234567"/>
            <p class="co-hint">Philippine mobile number. This doesn't change your profile.</p>
          </div>
          <div class="co-field">
            <label class="form-label" for="contact_email">Email</label>
            <input type="email" id="contact_email" name="contact_email" class="form-input" required maxlength="150"
                   value="{{ old('contact_email', $defaultEmail) }}"/>
          </div>
        </div>
      </fieldset>

      <fieldset class="co-section">
        <legend class="co-section-title">When should we call?</legend>
        <div class="co-options co-options--3">
          @foreach(\App\Models\CustomDesignRequest::CALL_WINDOWS as $value => $label)
            @php [$name, $hours] = array_pad(explode(', ', $label, 2), 2, ''); @endphp
            <label class="co-option">
              <input type="radio" name="preferred_call_window" value="{{ $value }}" required @checked($window === $value)/>
              <span class="co-option-text">
                <span class="co-option-name">{{ $name }}</span>
                <span class="co-option-sub">{{ $hours }}</span>
              </span>
            </label>
          @endforeach
        </div>
      </fieldset>

      <fieldset class="co-section">
        <legend class="co-section-title">How would you like it built?</legend>
        <div class="co-options co-options--2">
          @foreach(\App\Models\CustomDesignRequest::REQUEST_MODES as $value => $label)
            <label class="co-option">
              <input type="radio" name="request_mode" value="{{ $value }}" required @checked($mode === $value)/>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </div>

        <div class="co-field co-notes" id="co-notes" @if($mode !== 'modified') hidden @endif>
          <label class="form-label" for="modification_notes">What would you like changed?</label>
          <textarea id="modification_notes" name="modification_notes" class="form-textarea" rows="4" maxlength="2000"
                    placeholder="e.g. Make it 20 cm longer, use a darker stain, add a drawer">{{ old('modification_notes') }}</textarea>
        </div>
      </fieldset>

      <div class="co-actions">
        <a href="{{ route('design.show', $designRequest->id) }}" class="co-btn co-btn--ghost">Cancel</a>
        <button type="submit" class="co-btn co-btn--primary" id="co-submit">Send to our team</button>
      </div>
    </form>
  </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
  const notes = document.getElementById('co-notes');
  const textarea = document.getElementById('modification_notes');
  document.querySelectorAll('input[name="request_mode"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      const modified = this.value === 'modified' && this.checked;
      notes.hidden = !modified;
      textarea.required = modified;
    });
  });
  textarea.required = !notes.hidden;

  // One click only; the server also refuses a second submission.
  document.getElementById('co-send-form').addEventListener('submit', function () {
    const btn = document.getElementById('co-submit');
    btn.disabled = true;
    btn.textContent = 'Sending...';
  });
})();
</script>
@endpush
