@extends('layouts.focus')

@section('title', 'Verify Your Identity — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/identity-customer.css') }}?v={{ @filemtime(public_path('assets/css/identity-customer.css')) }}"/>
@endpush

@section('content')
@php
  // is_validated on the user record is the single source of truth (every other page checks it too).
  $isVerified = Auth::user()->is_validated;
  $isPending  = !$isVerified && $validation && $validation->isPending();
  $wasRejected = !$isVerified && $validation && $validation->isRejected();
  $showForm   = !$isVerified && !$isPending;
  $forService = $forService ?? false; // true when reached from the Other Services request page
  $tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>';
  $cross = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  $slots = [
    ['name' => 'id_photo',     'title' => '1. Photo of your ID',         'label' => 'Upload photo of your ID', 'type' => 'id'],
    ['name' => 'selfie_photo', 'title' => '2. Selfie holding your ID',   'label' => 'Upload selfie holding your ID', 'type' => 'selfie'],
  ];
@endphp

<div class="vfy">
  <div class="vfy-wrap">
    <h1 class="vfy-title">Verify your identity</h1>
    <p class="vfy-sub">{{ $forService
        ? 'One-time check before you send a service request. It takes about a minute: add two photos and we review them.'
        : 'One-time check before your first order. It takes about a minute: add two photos and we review them.' }}</p>

    @if($isVerified)
      <x-notice variant="success" title="Identity verified">
        {{ $forService ? 'Your account is fully verified. You can now send your service request.' : 'Your account is fully verified. You can browse and place orders.' }}
        <x-slot:actions>
          @if($forService)
            <a href="{{ route('services.request') }}" class="lp-btn lp-btn--primary">Continue to your service request</a>
          @else
            <a href="{{ route('catalog') }}" class="lp-btn lp-btn--primary">Start shopping</a>
          @endif
        </x-slot:actions>
      </x-notice>

    @elseif($isPending)
      <x-notice variant="waiting" title="We are reviewing your photos">
        Submitted {{ $validation->submitted_at ? $validation->submitted_at->format('M j, Y') : '' }}. Review usually completes within 24 hours, and we will notify you here.
        @if($forService) When your ID is approved, open Other Services again to send your service request.@endif
        <x-slot:actions><a href="{{ route('home') }}" class="lp-btn lp-btn--secondary">Back to home</a></x-slot:actions>
      </x-notice>
      <div class="vfy-glass vfy-mine">
        <h2>Your submission</h2>
        <div class="vfy-mine__grid">
          <figure><img src="{{ route('validation.photo', ['id' => $validation->id, 'type' => 'id']) }}" alt="Your ID photo" loading="lazy"><figcaption>Photo of your ID</figcaption></figure>
          <figure><img src="{{ route('validation.photo', ['id' => $validation->id, 'type' => 'selfie']) }}" alt="Your selfie holding your ID" loading="lazy"><figcaption>Selfie holding your ID</figcaption></figure>
        </div>
        <p class="vfy-note">Only LP Furniture admins can see your photos. We use them to verify you and for nothing else.</p>
      </div>

    @else
      @if($wasRejected)
        <x-notice variant="problem" title="Your last submission was rejected">
          Reason: {{ rtrim($validation->rejection_reason ?: 'Please send clearer photos', '.') }}.
          @if($validation->follow_up_requested_at) We asked you to send a new submission.@endif
          Please send new photos using the tips on this page.
        </x-notice>
      @elseif($forService)
        <x-notice variant="info" title="Important">
          We need to check your ID before you can send a service request. This is a one-time check, and it is not an order.
          <x-slot:actions><a href="#vfy-form" class="lp-btn lp-btn--primary">Continue ID check</a></x-slot:actions>
        </x-notice>
      @endif

      @if($errors->any())
        <x-notice variant="problem" title="Please fix this and try again">
          <ul class="vfy-errors">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-notice>
      @endif

      <div class="vfy-grid">
        <form class="vfy-glass vfy-up" method="POST" action="{{ route('validation.submit') }}" enctype="multipart/form-data" id="vfy-form" novalidate>
          @csrf
          @if($attempt > 1)<p class="vfy-attempt">This will be attempt {{ $attempt }}.</p>@endif
          <div class="vfy-slots">
            @foreach($slots as $i => $s)
              <div class="vfy-slot">
                <h2>{{ $s['title'] }}</h2>
                <label class="vfy-drop" id="vfy-drop-{{ $i }}">
                  <input type="file" name="{{ $s['name'] }}" accept="image/jpeg,image/png" data-slot="{{ $i }}" aria-label="{{ $s['label'] }}" aria-describedby="vfy-msg-{{ $i }}">
                  <img alt="" hidden>
                  <span class="vfy-drop__hint"><b>Tap to add</b><small>JPEG or PNG, up to 5 MB</small></span>
                </label>
                <span class="vfy-msg" id="vfy-msg-{{ $i }}" role="alert" hidden></span>
              </div>
            @endforeach
          </div>
          <label class="vfy-confirm">
            <input type="checkbox" name="confirm" value="1" id="vfy-confirm" {{ old('confirm') ? 'checked' : '' }}>
            <span>I confirm these photos are genuine, belong to me, and may be used by LP Furniture to verify my identity.</span>
          </label>
          <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" id="vfy-submit" disabled>Submit for review</button>
          <p class="vfy-hint" id="vfy-hint">Add both photos and tick the box to continue.</p>
        </form>

        <aside class="vfy-glass vfy-crit" aria-label="What we check">
          <h2>What we check</h2>
          <ul>
            @foreach($checks as $key => $label)
              <li><i>{!! $tick !!}</i><span><b>{{ $label }}</b>
                <small>{{ [
                  'name' => 'Use the same full name you registered with.',
                  'face' => 'Your whole face is visible, without a mask, filters or sunglasses.',
                  'id'   => 'Not expired. All four corners show, with no glare or blur.',
                ][$key] ?? '' }}</small></span></li>
            @endforeach
          </ul>
          <div>
            <b class="vfy-small-h">IDs we accept</b>
            <div class="vfy-tags">@foreach($acceptedIds as $id)<span>{{ $id }}</span>@endforeach</div>
          </div>
          <div class="vfy-two">
            <ul>
              <li><i>{!! $tick !!}</i><span>Bright, even light</span></li>
              <li><i>{!! $tick !!}</i><span>Photo of the original ID</span></li>
            </ul>
            <ul>
              <li><i class="is-x">{!! $cross !!}</i><span>Edited or cropped photos</span></li>
              <li><i class="is-x">{!! $cross !!}</i><span>Screenshots or photocopies</span></li>
            </ul>
          </div>
          <p class="vfy-note">Only LP Furniture admins can see your photos. We use them to verify you and for nothing else.</p>
        </aside>
      </div>
    @endif
  </div>
</div>
@endsection

@push('scripts')
  @if($showForm)<script src="{{ asset('assets/js/identity-upload.js') }}?v={{ @filemtime(public_path('assets/js/identity-upload.js')) }}"></script>@endif
@endpush
