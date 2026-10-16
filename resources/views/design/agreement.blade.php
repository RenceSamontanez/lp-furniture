@extends('layouts.app')

@section('title', 'Agreement ' . $agreement->agreement_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="cd-wrap">
  <div class="cd-top-bar">
    <a href="{{ route('design.show', $designRequest->id) }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to your design
    </a>
  </div>

  @if(session('success'))<div class="auth-success" style="max-width:680px;margin:0 auto 20px;">{{ session('success') }}</div>@endif
  @if(session('info'))<div class="co-flash-info">{{ session('info') }}</div>@endif

  <div class="co-card co-review">
    <div class="co-head">
      <h1 class="co-title">Agreement {{ $agreement->agreement_number }}</h1>
      <p class="co-lead">
        Agreed on {{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }} (Philippine time).
        This record cannot be changed.
      </p>
    </div>

    @if($imageUrl)
      <div class="co-design-frame" style="margin-bottom:20px;">
        <img src="{{ $imageUrl }}" alt="Reference design for this agreement"/>
      </div>
    @endif

    @include('agreements._terms', ['terms' => $terms, 'paymentDue' => $terms['payment_due_at'] ?? null])

    @php $agreementOrder = $agreement->order; @endphp
    @if($agreementOrder)
      <p class="co-hint">An order has been created for this agreement.</p>
    @elseif($designRequest->pipeline_status === 'accepted')
      <p class="co-hint">Next step: payment.</p>
    @endif

    <div class="co-agree-form">
      @if($agreementOrder)
        <a href="{{ route('orders.show', $agreementOrder->id) }}" class="ai-action-btn ai-action-primary" style="text-decoration:none;">View order</a>
      @elseif($designRequest->pipeline_status === 'accepted')
        <a href="{{ route('custom.checkout', $agreement->id) }}" class="ai-action-btn ai-action-primary" style="text-decoration:none;">Proceed to payment</a>
      @endif
      <a href="{{ route('design.agreement.print', $agreement->id) }}" class="ai-action-btn ai-action-secondary" target="_blank" rel="noopener" style="text-decoration:none;">Print view</a>
    </div>
  </div>
</div>
@include('partials.co-loading')
@endsection
