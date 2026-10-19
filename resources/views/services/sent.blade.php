@extends('layouts.app')

@section('title', 'Request Received — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
@endpush

@section('content')
<div class="si-wrap">
  <div class="si-layout si-layout--single">
    <div class="co-card si-sent">
      <div class="si-sent-icon" aria-hidden="true">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
      </div>
      <h1 class="co-title">We received your request</h1>
      <p class="si-ref-label">Your reference number</p>
      <p class="si-ref">{{ $reference }}</p>
      <p class="co-lead">
        We will call you on a weekday between 8:00 AM and 5:00 PM. No payment is due at this stage.
        Please keep your phone nearby and have the reference number ready.
      </p>
      <p class="co-lead">You can follow the status of your request on the request page.</p>

      @include('services._contact-card')

      <div class="co-actions si-sent-actions">
        <a href="{{ route('services.request') }}" class="lp-btn lp-btn--secondary">View my requests</a>
        <a href="{{ route('home') }}" class="lp-btn lp-btn--primary">Back to the shop</a>
      </div>
    </div>
  </div>
</div>
@endsection
