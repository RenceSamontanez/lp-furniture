@extends('layouts.app')

@section('title', 'Check your request — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $paid    = $ctx['paid_cents'] > 0;
  $started = $ctx['work'] !== 'not_started';
  $instant = !$paid && !$started;
  $peso    = fn ($n) => '₱' . number_format((float) $n, 2);
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card rq-main">
      <h1 class="rq-title">Check your request</h1>
      <p class="rq-lead">
        @if($instant) When you confirm, your service request is cancelled right away.
        @else When you confirm, your request is sent to our team and payments on this service are paused until we reply.
        @endif
      </p>

      <dl class="rq-summary">
        <div><dt>Service request</dt><dd>{{ $ticket->reference_number }} · {{ $ticket->serviceLabel() }}</dd></div>
        <div><dt>Reason</dt><dd>{{ \App\Models\ServiceRefundRequest::REASONS[$clean['reason_code']] }}</dd></div>
        @if($clean['reason_text'])<div><dt>Details</dt><dd>{{ $clean['reason_text'] }}</dd></div>@endif
        <div><dt>Mobile</dt><dd>{{ $clean['contact_phone'] }}</dd></div>
        <div><dt>Email</dt><dd>{{ $clean['contact_email'] }}</dd></div>
        @if($paid)
          <div><dt>You paid</dt><dd>{{ $peso($ctx['paid_cents'] / 100) }}</dd></div>
          <div><dt>Refund by</dt><dd>{{ \App\Models\ServiceRefundRequest::METHODS[$clean['refund_method']] }}@if($clean['gcash_number']): {{ $clean['gcash_number'] }} ({{ $clean['gcash_account_name'] }})@endif</dd></div>
        @endif
      </dl>

      @if($started)
        <x-notice variant="problem" title="Work has already started">
          Our team will review your request and reply to you. Under our terms, refunds are usually not possible once work starts.
        </x-notice>
      @elseif($paid)
        <x-notice variant="info" title="A refund takes up to {{ \App\Models\ServiceRefundRequest::REFUND_DAYS }} days after approval">
          If approved, you get back the full {{ $peso($ctx['paid_cents'] / 100) }}.
        </x-notice>
      @endif

      <form method="POST" action="{{ route('services.refund.store', $ticket->id) }}" id="rq-confirm">
        @csrf
        <input type="hidden" name="reason_code" value="{{ $clean['reason_code'] }}"/>
        <input type="hidden" name="reason_text" value="{{ $clean['reason_text'] }}"/>
        <input type="hidden" name="contact_phone" value="{{ $clean['contact_phone'] }}"/>
        <input type="hidden" name="contact_email" value="{{ $clean['contact_email'] }}"/>
        @if($paid)
          <input type="hidden" name="refund_method" value="{{ $clean['refund_method'] }}"/>
          <input type="hidden" name="gcash_number" value="{{ $clean['gcash_number'] }}"/>
          <input type="hidden" name="gcash_account_name" value="{{ $clean['gcash_account_name'] }}"/>
        @endif
        <input type="hidden" name="terms" value="1"/>

        <div class="rq-actions">
          <button type="submit" class="lp-btn lp-btn--primary" id="rq-send">{{ $instant ? 'Cancel this service request' : 'Send my request' }}</button>
          <button type="button" class="lp-btn lp-btn--secondary" onclick="history.back()">Change my answers</button>
        </div>
      </form>
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
<script>
  // One tap, one request: after the first tap the button waits, so a double tap cannot send two.
  document.getElementById('rq-confirm').addEventListener('submit', function () {
    var b = document.getElementById('rq-send');
    setTimeout(function () { b.disabled = true; b.textContent = 'Sending...'; }, 0);
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { var b = document.getElementById('rq-send'); b.disabled = false; } });
</script>
@endsection
