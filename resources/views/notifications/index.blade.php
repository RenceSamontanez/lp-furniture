@extends('layouts.app')

@section('title', 'Notifications — LP Furniture')

@section('content')
@php
  $isFiltered = $filters['unread'] || $filters['from'] || $filters['to'];
@endphp
<div class="nf-wrap">
  <div class="nf-container">

    <div class="nf-head">
      <p class="gallery-eyebrow">Your Updates</p>
      <h1 class="nf-title">Notifications</h1>
      <p class="nf-count nf-count--page">{{ $unreadCount > 0 ? $unreadCount . ' unread' : 'All caught up' }}</p>
    </div>

    @if($hasAny)
      <form method="GET" action="{{ route('notifications.index') }}" id="nf-filter" class="nf-filter" data-nf-page>
        @include('partials.notif-controls', ['mode' => 'page', 'filters' => $filters])
        <noscript><button type="submit" class="lp-btn lp-btn--primary nf-apply">Apply filters</button></noscript>
      </form>
      <form method="POST" action="{{ route('notifications.readAll') }}" id="nf-markall">
        @csrf
        @method('PATCH')
      </form>
    @endif

    @if(!$hasAny)
      <div class="nf-empty">
        <h2>No notifications yet</h2>
        <p>Order updates, payment confirmations, and alerts will appear here.</p>
        <a href="{{ route('catalog') }}" class="lp-btn lp-btn--primary">Browse Catalog</a>
      </div>
    @elseif($notifications->isEmpty())
      <div class="nf-empty">
        <h2>No notifications match.</h2>
        <a class="lp-link lp-link--tap" href="{{ route('notifications.index') }}">Clear filters</a>
      </div>
    @else
      <div class="nf-list">
        @foreach($notifications as $notif)
          @include('partials.notif-item', ['notif' => $notif])
        @endforeach
      </div>

      <div class="nf-pager">
        {{ $notifications->links() }}
      </div>
    @endif

  </div>
</div>
@endsection
