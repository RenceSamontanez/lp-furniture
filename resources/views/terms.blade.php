@extends('layouts.app')

@section('title', 'Terms and Conditions — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="terms-page">
  <article class="terms-card">
    <header class="terms-head">
      <h1 class="terms-title">{{ $policy->title ?? 'Terms and Conditions' }}</h1>
      @if($policy)
        <p class="terms-meta">Version {{ $policy->version }}. Last updated {{ $policy->created_at->timezone('Asia/Manila')->format('F j, Y') }}.</p>
      @endif
    </header>

    @if($policy)
      <div class="terms-body">@include('agreements._policy', ['text' => $policy->body, 'titleHeadings' => true])</div>
    @else
      <p class="pt-p">Our Terms and Conditions are not available right now. Please contact us through our website.</p>
    @endif
  </article>
</div>
@endsection
