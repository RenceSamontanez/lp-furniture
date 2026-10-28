@extends('layouts.app')

@section('title', 'This invoice was cancelled — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $i      = $invoice;
  $day    = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $reason = trim((string) $i->void_reason);
  $paidBefore = (bool) $i->paid_at;
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card sx-main">
      <span class="sx-badge" role="status">{{ $i->statusLabel() }}</span>
      <h1 class="sx-title">This Invoice Was Cancelled</h1>

      @if($refund)
        <p class="sx-lead">You asked us to cancel this service, and your refund was sent. The details are below.</p>
        @php $sentAttempt = $refund->attempts->firstWhere('outcome', 'sent'); @endphp
        <x-notice variant="success" title="Your refund was sent">
          {{ '₱' . number_format((float) ($sentAttempt?->amount ?? $refund->approved_amount), 2) }} by {{ $sentAttempt ? (\App\Models\ServiceRefundRequest::METHODS[$sentAttempt->method] ?? $sentAttempt->method) : $refund->methodLabel() }}@if($sentAttempt?->sent_on) on {{ $sentAttempt->sent_on->format('F j, Y') }}@endif. Transaction number: {{ $sentAttempt?->transaction_number }}.
          <x-slot:actions><a href="{{ route('services.refund.show', $refund->reference) }}" class="lp-btn lp-btn--secondary">See request {{ $refund->reference }}</a></x-slot:actions>
        </x-notice>
      @elseif($paidBefore)
        <p class="sx-lead">We cancelled this invoice after a payment was recorded for it. Please contact us so we can go through it with you.</p>
      @else
        <p class="sx-lead">We cancelled this invoice. You do not need to pay anything for it, and no payment is expected.</p>
      @endif

      <dl class="sx-facts">
        <div><dt>Invoice number</dt><dd>{{ $i->invoice_number }}</dd></div>
        <div><dt>Your service request</dt><dd>{{ $ticket?->reference_number }}@if($ticket) · {{ $ticket->serviceLabel() }}@endif</dd></div>
        <div><dt>Cancelled on</dt><dd>{{ $day($i->voided_at) }}</dd></div>
        <div><dt>Amount that was on this invoice</dt><dd class="sx-muted">₱{{ number_format((float) $i->total, 2) }}</dd></div>
      </dl>

      <h2 class="sx-h2">Why It Was Cancelled</h2>
      @if($reason !== '')
        <p class="sx-reason">{{ $reason }}</p>
      @else
        <p class="sx-reason">Our team cancelled this invoice. If you are not sure why, please contact us and we will explain.</p>
      @endif

      <h2 class="sx-h2">What Happens Next</h2>
      <p class="sx-next">If a corrected invoice is needed, we will send you a new one and let you know.</p>

      <x-back-link :href="route('services.request')" label="my service request" />
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
@endsection
