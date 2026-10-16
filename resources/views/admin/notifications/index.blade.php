@extends('layouts.admin')

@section('title', 'Notifications — Admin')
@section('page-title', 'Notifications')

@section('content')
@php $unread = $notifications->getCollection()->where('is_read', false)->count(); @endphp

<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">Your notifications</div>
    <form method="POST" action="{{ route('admin.notifications.readAll') }}">
      @csrf
      <button type="submit" class="admin-btn admin-btn-outline">Mark all as read</button>
    </form>
  </div>
  <div class="admin-panel-body" style="padding:0;">
    @if($notifications->isEmpty())
      <p style="padding:24px;color:var(--text-muted);margin:0;">No notifications yet.</p>
    @else
      <table class="admin-table">
        <thead><tr><th>When</th><th>Notification</th><th></th></tr></thead>
        <tbody>
          @foreach($notifications as $n)
            <tr style="{{ $n->is_read ? '' : 'background:#FBF6EC;' }}">
              <td data-label="When" style="white-space:nowrap;">
                {{ $n->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') }}
              </td>
              <td data-label="Notification">
                <strong>{{ $n->title }}</strong>
                @unless($n->is_read)<span class="admin-nav-badge" style="margin-left:6px;">New</span>@endunless
                <div>{{ $n->message }}</div>
                @if($n->url)
                  <a href="{{ $n->url }}">Open custom order</a>
                @endif
              </td>
              <td data-label="">
                @unless($n->is_read)
                  <form method="POST" action="{{ route('admin.notifications.read', $n->id) }}">
                    @csrf
                    <button type="submit" class="admin-btn admin-btn-outline">Mark read</button>
                  </form>
                @endunless
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $notifications->links() }}</div>
@include('partials.co-loading')
@endsection
