@extends('layouts.app')

@section('title', 'Request sent — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php $instant = $req->status === 'closed'; @endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card rq-main">
      <h1 class="rq-title">{{ $instant ? 'Your service request was cancelled' : 'Your request was sent' }}</h1>
      <p class="rq-ref-label">Your reference number</p>
      <p class="rq-ref">{{ $req->reference }}</p>

      @if($instant)
        <x-notice variant="success" title="Cancelled">
          No payment was made, so there is nothing to refund.
        </x-notice>
      @else
        <x-notice variant="info" title="Your request was sent">
          A refund, if approved, takes up to {{ \App\Models\ServiceRefundRequest::REFUND_DAYS }} days after approval. Payments on this service are paused until we reply.
        </x-notice>
      @endif

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
@endsection
