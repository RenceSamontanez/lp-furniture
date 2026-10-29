@extends('layouts.admin')

@section('title', 'Agreement ' . $agreement->agreement_number . ' — Admin')
@section('page-title', 'Agreement')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="co-page">
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
  <a href="{{ route('admin.custom-orders.show', $agreement->request_id) }}" class="admin-btn admin-btn-outline">Back to Custom Order</a>
  <a href="{{ route('admin.agreements.print', $agreement->id) }}" class="admin-btn admin-btn-gold" target="_blank" rel="noopener">Print View</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">{{ $agreement->agreement_number }}</div>
  </div>
  <div class="admin-panel-body">
    <dl class="co-dl" style="margin-bottom:20px;">
      <div><dt>Customer</dt><dd>{{ \App\Support\NameFormat::proper($terms['people']['customer']['name'] ?? '') }}</dd></div>
      <div><dt>Agreed on</dt><dd>{{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }} (Philippine time)</dd></div>
      <div><dt>Prepared by</dt><dd>{{ \App\Support\NameFormat::proper($terms['people']['finalized_by']['name'] ?? '') }}</dd></div>
      <div><dt>IP / user agent</dt><dd style="overflow-wrap:anywhere;">{{ $agreement->ip_address }} · {{ $agreement->user_agent ?: '—' }}</dd></div>
    </dl>

    @if($imageUrl)
      <div class="co-design-frame" style="margin-bottom:20px;">
        <img src="{{ $imageUrl }}" alt="Reference design for this agreement"/>
      </div>
    @endif

    @include('agreements._terms', ['terms' => $terms, 'paymentDue' => $terms['payment_due_at'] ?? null, 'showVersions' => true])
  </div>
</div>
</div>
@endsection
