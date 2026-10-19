@extends('layouts.app')

@section('title', 'Request sent — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $instant = $req->status === 'closed';
  $days    = \App\Models\ServiceRefundRequest::REFUND_DAYS;
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card rq-main rq-sent">
      <div class="rq-sent__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
      </div>
      <h1 class="rq-title" style="margin-top:12px;">{{ $instant ? 'Your service request was cancelled' : 'Your request was sent' }}</h1>

      <p class="rq-ref-label">Your reference number</p>
      <div class="rq-ref-row">
        <p class="rq-ref" id="rq-ref">{{ $req->reference }}</p>
        <button type="button" class="lp-btn lp-btn--secondary" data-copy-text="{{ $req->reference }}" data-copy-done="Copied" aria-label="Copy reference number {{ $req->reference }}">Copy</button>
      </div>

      @if($instant)
        <x-notice variant="success" title="Cancelled">No payment was made, so there is nothing to refund.</x-notice>
      @else
        <x-notice variant="info" title="Payments on this service are paused until we reply">A refund, if approved, takes up to {{ $days }} days after approval.</x-notice>

        <ol class="rq-timeline rq-timeline--big" aria-label="What happens next">
          <li class="is-done"><strong>Sent</strong><span>We received your request.</span></li>
          <li class="is-now"><strong>Under review</strong><span>Our team checks it and replies to you.</span></li>
          <li><strong>Refund within {{ $days }} days after approval</strong><span>By GCash or cash, the full amount you paid.</span></li>
        </ol>
      @endif

      <p class="rq-track">
        Track it any time in <a href="{{ route('services.request') }}" class="lp-link">My service requests</a>, or ask Luma with this reference number.
      </p>

      <div class="rq-actions">
        <a href="{{ route('services.refund.show', $req->reference) }}" class="lp-btn lp-btn--primary">See my request</a>
        <a href="{{ route('services.request') }}" class="lp-btn lp-btn--secondary">Back to my service requests</a>
      </div>
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
<script>
  document.querySelectorAll('[data-copy-text]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy-text'), done = b.getAttribute('data-copy-done') || 'Copied', was = b.textContent;
      function ok() { b.textContent = done; setTimeout(function () { b.textContent = was; }, 1600); }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(ok, ok); } else { ok(); }
    });
  });
</script>
@endsection
