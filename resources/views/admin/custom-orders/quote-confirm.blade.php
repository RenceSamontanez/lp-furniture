@extends('layouts.admin')

@section('title', 'Send Quote — Admin')
@section('page-title', 'Send Quote')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php $r = $designRequest; @endphp

<a href="{{ route('admin.custom-orders.show', $r->id) }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to request</a>

<div class="admin-panel" style="max-width:760px;">
  <div class="admin-panel-header">
    <div class="admin-panel-title">
      Send ₱{{ number_format($summary['total'], 2) }} quote to {{ $r->user->display_name }} ({{ $r->contact_phone }})?
    </div>
  </div>
  <div class="admin-panel-body">
    <p class="co-hint">
      Version {{ $quote->version }}. The customer is notified and has {{ \App\Models\CustomQuote::VALID_DAYS }} days to respond.
      @if($replaces)
        Version {{ $replaces->version }} (₱{{ number_format($replaces->totalAmount(), 2) }}) can no longer be accepted once this is sent.
      @endif
    </p>

    @include('admin.custom-orders._quote-terms', ['quote' => $quote])

    <form method="POST" action="{{ route('admin.custom-orders.quotes.send', [$r->id, $quote->id]) }}" class="co-form-actions" style="margin-top:20px;">
      @csrf
      <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
      <a href="{{ route('admin.custom-orders.quotes.edit', [$r->id, $quote->id]) }}" class="admin-btn admin-btn-outline">Edit</a>
      <button type="submit" class="admin-btn admin-btn-gold">Send quote</button>
    </form>
  </div>
</div>
</div>
@endsection
