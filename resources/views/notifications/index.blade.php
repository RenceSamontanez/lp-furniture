@extends('layouts.app')

@section('title', 'Notifications — LP Furniture')

@section('content')
<div class="notif-wrap">

  <div class="notif-container">

    <div class="notif-header">
      <div>
        <p class="gallery-eyebrow" style="margin-bottom:6px;">Your Updates</p>
        <h1 class="notif-title">Notifications</h1>
      </div>
      @if($notifications->count() > 0)
        <form method="POST" action="{{ route('notifications.readAll') }}">
          @csrf
          @method('PATCH')
          <button type="submit" class="notif-markall-btn">
            Mark all as read
          </button>
        </form>
      @endif
    </div>

    @if($notifications->isEmpty())
      <div class="notif-empty">
        <div class="notif-empty-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
        </div>
        <h3>No notifications yet</h3>
        <p>Order updates, payment confirmations, and alerts will appear here.</p>
        <a href="{{ route('catalog') }}" class="btn btn-gold" style="margin-top:20px;">
          Browse Catalog →
        </a>
      </div>

    @else
      <div class="notif-list">
        @foreach($notifications as $notif)
          <div class="notif-item {{ $notif->is_read ? '' : 'notif-item--unread' }}">

            <div class="notif-icon {{ $notif->is_read ? '' : 'notif-icon--unread' }}">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 01-3.46 0"/>
              </svg>
            </div>

            <div class="notif-content">
              <div class="notif-item-title">
                {{ $notif->title }}
                @if(!$notif->is_read)
                  <span class="notif-unread-dot"></span>
                @endif
              </div>
              <div class="notif-message">{{ $notif->message }}</div>
              <div class="notif-meta">
                <span class="notif-time">{{ $notif->created_at->diffForHumans() }}</span>
                @if($notif->url)
                  <a href="{{ $notif->url }}" class="notif-view-btn">View →</a>
                @endif
                @if(!$notif->is_read)
                  <form method="POST" action="{{ route('notifications.read', $notif->id) }}" style="display:inline;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="notif-read-btn">Mark read</button>
                  </form>
                @endif
              </div>
            </div>

          </div>
        @endforeach
      </div>

      <div style="margin-top:20px;">
        {{ $notifications->links() }}
      </div>
    @endif

  </div>
</div>

<style>
/* ── NOTIFICATION PAGE STYLES ── */
.notif-wrap {
  background: var(--cream);
  min-height: calc(100vh - 64px);
  padding: 40px 60px;
}

.notif-container {
  max-width: 680px;
  margin: 0 auto;
}

.notif-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 28px;
  gap: 16px;
  flex-wrap: wrap;
}

.notif-title {
  font-family: var(--font-display);
  font-size: 30px;
  color: var(--brown-deep);
}

.notif-markall-btn {
  padding: 10px 20px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  background: var(--warm-white);
  font-family: var(--font-body);
  font-size: 13px;
  font-weight: 500;
  color: var(--brown-mid);
  cursor: pointer;
  transition: all .2s;
  white-space: nowrap;
}

.notif-markall-btn:hover {
  border-color: var(--gold);
  color: var(--gold);
}

/* Empty state */
.notif-empty {
  background: var(--warm-white);
  border-radius: 16px;
  border: 1px solid var(--border);
  padding: 60px 40px;
  text-align: center;
}

.notif-empty-icon {
  width: 64px; height: 64px;
  border-radius: 50%;
  background: var(--cream);
  border: 2px dashed var(--border);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
  color: var(--gold);
}

.notif-empty h3 {
  font-family: var(--font-display);
  font-size: 20px;
  color: var(--brown-deep);
  margin-bottom: 8px;
}

.notif-empty p {
  font-size: 14px;
  color: var(--text-muted);
  line-height: 1.6;
}

/* Notification list */
.notif-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.notif-item {
  background: var(--warm-white);
  border-radius: 14px;
  border: 1px solid var(--border);
  padding: 18px 20px;
  display: flex;
  align-items: flex-start;
  gap: 14px;
  transition: box-shadow .2s;
}

.notif-item:hover {
  box-shadow: var(--shadow);
}

.notif-item--unread {
  background: #FFFBF4;
  border-color: rgba(200,150,62,.3);
}

.notif-icon {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: var(--cream);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: var(--text-muted);
}

.notif-icon--unread {
  background: rgba(200,150,62,.15);
  color: var(--gold);
}

.notif-content {
  flex: 1;
  min-width: 0;
}

.notif-item-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--brown-deep);
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.notif-unread-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--gold);
  flex-shrink: 0;
}

.notif-message {
  font-size: 13px;
  color: var(--text-muted);
  line-height: 1.55;
  margin-bottom: 10px;
}

.notif-meta {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.notif-time {
  font-size: 12px;
  color: var(--text-muted);
}

/* View button — visible and styled */
.notif-view-btn {
  font-size: 12px;
  font-weight: 600;
  color: var(--warm-white);
  background: var(--gold);
  padding: 5px 14px;
  border-radius: 20px;
  text-decoration: none;
  transition: background .2s;
}

.notif-view-btn:hover {
  background: var(--gold-light);
  color: var(--brown-deep);
}

/* Mark read button */
.notif-read-btn {
  font-size: 12px;
  color: var(--text-muted);
  background: none;
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 4px 12px;
  cursor: pointer;
  transition: all .2s;
}

.notif-read-btn:hover {
  border-color: var(--brown-mid);
  color: var(--brown-mid);
}

/* ── MOBILE ── */
@media (max-width: 640px) {
  .notif-wrap {
    padding: 24px 16px;
  }

  .notif-title {
    font-size: 24px;
  }

  .notif-item {
    padding: 14px 16px;
    gap: 12px;
  }

  .notif-icon {
    width: 32px;
    height: 32px;
  }

  .notif-meta {
    gap: 10px;
  }

  .notif-empty {
    padding: 40px 20px;
  }
}
</style>
@endsection