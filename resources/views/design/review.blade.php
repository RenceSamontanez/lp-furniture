@extends('layouts.app')

@section('title', 'Review and Accept — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap cd-wrap--wide">
  <div class="cd-top-bar">
    <a href="{{ route('design.show', $designRequest->id) }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to your design
    </a>
  </div>

  @php $media = $designRequest->previewImageUrl(); @endphp
  <div class="co-layout {{ $media ? 'co-layout--media' : '' }}">
  <div class="co-main">
  <div class="co-card co-review">
    <div class="co-head">
      <h1 class="co-title">Review and accept your agreement</h1>
      <p class="co-lead">
        Please read every term below. Nothing is agreed until you tick the box and press
        "Accept agreement". This quote is valid until
        {{ $quote->expires_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
      </p>
    </div>

    @if($errors->any())
      <div class="co-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @include('agreements._terms', ['terms' => $terms])

    <p class="co-hint">
      If you accept now, you can pay straight away. Please start your payment by
      <strong>{{ $dueIfAccepted->timezone('Asia/Manila')->format('M d, Y h:i A') }}</strong>.
      Your order is confirmed once we receive your deposit.
    </p>

    <form method="POST" action="{{ route('design.quote.accept', [$designRequest->id, $quote->id]) }}" class="co-agree-form">
      @csrf
      <label class="co-agree">
        <input type="checkbox" name="agree" value="1" required/>
        <span>I have read and agree to the Terms and Conditions, including the cancellation and refund policy.</span>
      </label>
      <button type="submit" class="ai-action-btn ai-action-primary">Accept agreement</button>
    </form>

    <form method="POST" action="{{ route('design.quote.decline', [$designRequest->id, $quote->id]) }}" class="co-decline-form"
          onsubmit="return confirm('Decline this quote? You can still contact us afterwards.')">
      @csrf
      <div class="co-action-title">Not for you?</div>
      <textarea name="reason" class="form-input" rows="2" maxlength="1000" placeholder="Reason (optional)"></textarea>
      <button type="submit" class="ai-action-btn ai-action-secondary">Decline this quote</button>
    </form>
  </div>
  </div>
  @if($media)
    <aside class="co-media">
      <figure class="co-media-card">
        <img src="{{ $media }}" alt="The design you are reviewing" onerror="this.closest('.co-media').style.display='none';"/>
        <figcaption>Your selected design</figcaption>
      </figure>
    </aside>
  @endif
  </div>
</div>
@endsection
