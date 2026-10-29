@extends('layouts.admin')

@section('title', 'Notifications — Admin')
@section('page-title', 'Notifications')

@push('styles')
<style>
  .an-actions { width: 190px; vertical-align: top; }
  .an-actions form { margin: 0; }
  .an-actions .lp-btn { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 44px; text-align: center; text-decoration: none; box-sizing: border-box; }
  .an-actions .lp-btn + .lp-btn, .an-actions form + .lp-btn { margin-top: 8px; }
  @media (max-width: 600px) {
    .admin-table td.an-body { display: block; }
    .admin-table td.an-body::before { display: block; margin-bottom: 4px; }
    .admin-table td.an-actions { display: block; width: 100%; }
    .admin-table td.an-actions::before { display: none; }
  }
</style>
@endpush

@section('content')
@php $unread = $notifications->getCollection()->where('is_read', false)->count(); @endphp

<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">Your Notifications</div>
    <form method="POST" action="{{ route('admin.notifications.readAll') }}">
      @csrf
      <button type="submit" class="lp-btn lp-btn--secondary">Mark All as Read</button>
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
              <td data-label="Notification" class="an-body">
                <strong>{{ $n->title }}</strong>
                @unless($n->is_read)<span class="admin-nav-badge" style="margin-left:6px;">New</span>@endunless
                <div>{{ $n->message }}</div>
              </td>
              <td data-label="" class="an-actions">
                @unless($n->is_read)
                  <form method="POST" action="{{ route('admin.notifications.read', $n->id) }}">
                    @csrf
                    <button type="submit" class="lp-btn lp-btn--secondary" aria-label="Mark read: {{ $n->title }}">Mark Read</button>
                  </form>
                @endunless
                @if($n->url)
                  <a href="{{ $n->url }}" class="lp-btn lp-btn--primary" aria-label="Open notification: {{ $n->title }}">Open Notification</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $notifications->links() }}</div>
@endsection
