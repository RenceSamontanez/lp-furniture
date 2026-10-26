@extends('layouts.admin')

@section('title', 'Identity Validations — Admin')
@section('page-title', 'Identity Validation')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/identity-review.css') }}?v={{ @filemtime(public_path('assets/css/identity-review.css')) }}"/>
@endpush

@section('content')
@php
  $tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
  $empty = $search !== ''
      ? 'No ' . $status . ' customers match “' . $search . '”.'
      : ($status === 'pending' ? 'New identity submissions will show up here.' : 'Nothing ' . $status . ' yet.');
@endphp
<div class="idr">

  <div class="idr-qhead">
    <nav class="idr-tabs" aria-label="Status">
      @foreach($tabs as $key => $label)
        <a href="{{ route('admin.validations.index', array_filter(['status' => $key, 'q' => $search])) }}"
           class="idr-tab" @if($status === $key) aria-current="page" @endif>
          {{ $label }} <span>{{ $counts[$key] }}</span>
        </a>
      @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.validations.index') }}" class="idr-search" role="search">
      <input type="hidden" name="status" value="{{ $status }}">
      <label class="idr-sr" for="idr-q">Search name or email</label>
      <input id="idr-q" class="idr-input" type="search" name="q" value="{{ $search }}" maxlength="80" placeholder="Search name or email">
      <button type="submit" class="lp-btn lp-btn--secondary">Search</button>
      @if($search !== '')<a class="lp-link lp-link--tap" href="{{ route('admin.validations.index', ['status' => $status]) }}">Clear</a>@endif
    </form>
  </div>

  <div class="idr-glass idr-list">
    <div class="idr-row idr-row--head" aria-hidden="true">
      <span></span><span>Customer</span><span>Submitted</span><span>Attempt</span><span>Status</span><span></span>
    </div>
    @forelse($validations as $v)
      @php
        $u = $v->user;
        $name = trim($u->first_name . ' ' . $u->last_name);
        $initials = strtoupper(mb_substr($u->first_name, 0, 1) . mb_substr($u->last_name, 0, 1));
      @endphp
      <div class="idr-row">
        <div class="idr-av" aria-hidden="true">{{ $initials }}</div>
        <div class="idr-who"><b>{{ $name }}</b><small>{{ $u->email }}</small></div>
        <span class="idr-date">{{ $v->submitted_at ? $v->submitted_at->format('M j, Y') : '—' }}</span>
        <span class="idr-att {{ $v->attempt_number > 1 ? 'is-re' : '' }}">Attempt {{ $v->attempt_number }}</span>
        <span class="idr-pill is-{{ $v->status }}">{{ ucfirst($v->status) }}</span>
        <a class="lp-btn {{ $v->status === 'pending' ? 'lp-btn--primary' : 'lp-btn--secondary' }} idr-open"
           href="{{ route('admin.validations.show', $v->id) }}"
           aria-label="{{ $v->status === 'pending' ? 'Review' : 'View' }} {{ $name }}, attempt {{ $v->attempt_number }}">{{ $v->status === 'pending' ? 'Review' : 'View' }}</a>
      </div>
    @empty
      <p class="idr-empty"><b>Nothing here</b>{{ $empty }}</p>
    @endforelse
  </div>

  <div class="idr-pager">{{ $validations->links() }}</div>
</div>
@endsection
