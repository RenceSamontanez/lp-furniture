@extends('layouts.app')

@section('title', 'Send to Our Team — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
@php
  $editing = $editing ?? false;
  $hasSaved = $hasSaved ?? false;
  $values = $values ?? [];
  $mode = old('request_mode', $values['request_mode'] ?? 'exact');
  $window = old('preferred_call_window', $values['preferred_call_window'] ?? null);
  $notesValue = old('modification_notes', $values['modification_notes'] ?? '');
  $savedPhone = $savedPhone ?? null;
  $savedEmail = $savedEmail ?? null;
  $submitted = session()->hasOldInput();
  // Another number or email is being used (or was just submitted and needs fixing): fields open.
  $useDifferent = $hasSaved && (
      $submitted ? (bool) old('use_different') || $errors->hasAny(['contact_phone', 'contact_email'])
                 : ($editing && ($defaultPhone !== $savedPhone || strcasecmp((string) $defaultEmail, (string) $savedEmail) !== 0))
  );
  $lockContact = $hasSaved && !$useDifferent;
  $saveByDefault = $submitted ? (bool) old('save_contact') : true;
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
      <h1 class="co-title">{{ $editing ? 'Edit your request' : 'Send to our team' }}</h1>
      <p class="co-lead">
        @if($editing)
          You can change your request until we send you a quote. Our team is told when you save.
        @else
          This is a request, not an order. No payment is due yet. Our team will call you to discuss the price and details.
        @endif
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

    <form method="POST" action="{{ $editing ? route('design.update', $designRequest->id) : route('design.sendToTeam', $designRequest->id) }}" class="co-form" id="co-send-form">
      @csrf
      @if($suggestion && !$editing)
        <input type="hidden" name="suggestion_id" value="{{ $suggestion->id }}"/>
      @endif

      <fieldset class="co-section">
        <legend class="co-section-title">How can we reach you?</legend>

        @if($hasSaved)
          {{-- Saved contact: shown filled in and read-only until the customer asks to use another. --}}
          <p class="co-hint co-hint--lead" id="co-saved-hint" @if($useDifferent) hidden @endif>We'll use your saved contact for this request.</p>
        @endif

        <div class="co-grid" id="co-contact-fields">
          <div class="co-field">
            <label class="form-label" for="contact_phone">Mobile number</label>
            <input type="tel" id="contact_phone" name="contact_phone" class="form-input {{ $lockContact ? 'is-locked' : '' }}" required maxlength="16"
                   value="{{ old('contact_phone', $defaultPhone) }}" placeholder="09171234567"
                   data-saved="{{ $savedPhone }}" @readonly($lockContact)/>
            <p class="co-hint">Philippine mobile number, for example 09171234567.</p>
          </div>
          <div class="co-field">
            <label class="form-label" for="contact_email">Email</label>
            <input type="email" id="contact_email" name="contact_email" class="form-input {{ $lockContact ? 'is-locked' : '' }}" required maxlength="150"
                   value="{{ old('contact_email', $defaultEmail) }}"
                   data-saved="{{ $savedEmail }}" @readonly($lockContact)/>
          </div>
        </div>

        @if($hasSaved)
          @include('partials.switch', [
            'id' => 'use_different', 'name' => 'use_different', 'checked' => $useDifferent,
            'label' => 'Use a different number or email for this request',
          ])
          <div id="co-update-saved" @if(!$useDifferent) hidden @endif>
            @include('partials.switch', [
              'id' => 'update_saved', 'name' => 'update_saved', 'checked' => (bool) old('update_saved'),
              'label' => 'Also update my saved contact',
              'hint' => 'Your next custom orders will use the new number and email.',
            ])
          </div>
        @else
          @include('partials.switch', [
            'id' => 'save_contact', 'name' => 'save_contact', 'checked' => $saveByDefault,
            'label' => 'Save this number and email for my next custom orders',
          ])
        @endif
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

        <div class="co-field co-notes" id="co-notes">
          <label class="form-label" for="modification_notes">What would you like to change or add to this design?</label>
          <textarea id="modification_notes" name="modification_notes" class="form-textarea" rows="4" maxlength="2000"
                    placeholder="e.g. Make it 20 cm longer, use a darker stain, add a drawer">{{ $notesValue }}</textarea>
          <p class="co-hint" id="co-notes-hint">Required if you want changes. Optional if you want it built exactly as shown.</p>
        </div>
      </fieldset>

      <div class="co-actions">
        <a href="{{ route('design.show', $designRequest->id) }}" class="co-btn co-btn--ghost">Cancel</a>
        <button type="submit" class="co-btn co-btn--primary" id="co-submit">{{ $editing ? 'Save changes' : 'Send to our team' }}</button>
      </div>
    </form>
  </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
  const textarea = document.getElementById('modification_notes');
  document.querySelectorAll('input[name="request_mode"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      textarea.required = this.value === 'modified' && this.checked;
    });
  });
  const checked = document.querySelector('input[name="request_mode"]:checked');
  textarea.required = !!checked && checked.value === 'modified';

  // Saved contact: the fields stay read-only until "different" is switched on.
  const different = document.getElementById('use_different');
  if (different) {
    const phone = document.getElementById('contact_phone');
    const email = document.getElementById('contact_email');
    const update = document.getElementById('update_saved');
    const updateRow = document.getElementById('co-update-saved');
    const apply = function () {
      const on = different.checked;
      [phone, email].forEach(function (input) {
        input.readOnly = !on;
        input.classList.toggle('is-locked', !on);
        if (!on) { input.value = input.getAttribute('data-saved') || ''; }
      });
      updateRow.hidden = !on;
      const hint = document.getElementById("co-saved-hint");
      if (hint) { hint.hidden = on; }
      if (!on) { update.checked = false; }
    };
    different.addEventListener('change', apply);
  }

  // One click only; the server also refuses a second submission.
  document.getElementById('co-send-form').addEventListener('submit', function () {
    const btn = document.getElementById('co-submit');
    btn.disabled = true;
    btn.textContent = 'Sending...';
  });
})();
</script>
@endpush
