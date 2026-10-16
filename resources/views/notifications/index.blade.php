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
      <div class="notif-header-actions">
        <a href="{{ route('orders.index') }}" class="notif-history-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
          Order History
        </a>
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
                @if(!$notif->is_read)
                  <form method="POST" action="{{ route('notifications.read', $notif->id) }}" style="display:inline;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="notif-read-btn">Mark read</button>
                  </form>
                @endif
              </div>

              {{-- Clear, unambiguous action row — replaces the old
                   single "View →" button that confusingly always
                   landed on the full Order History list instead of
                   the specific order the notification was about. --}}
                   @if($notif->url)
                <div class="notif-action-bar">
                  <div class="notif-action-context">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
                    <span>Related Order</span>
                  </div>
                  <a href="{{ $notif->url }}" class="notif-action-cta">
                    View Details
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="notif-action-arrow"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                  </a>
                </div>
              @endif
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

.notif-header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.notif-history-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  border-radius: 10px;
  border: 1.5px solid var(--gold);
  background: var(--gold);
  color: #fff;
  font-family: var(--font-body);
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: all .2s;
  white-space: nowrap;
}

.notif-history-btn:hover {
  background: var(--gold-light);
  color: #fff;
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

/* ── NEW: 3-option action row (View This Order / Order History / Home) ── */
.notif-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.notif-action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 13px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: #fff;
  color: var(--text-muted);
  font-size: 11.5px;
  font-weight: 600;
  text-decoration: none;
  transition: all .2s;
}

.notif-action-btn:hover {
  border-color: var(--gold);
  color: var(--gold);
}

.notif-action-primary {
  background: var(--gold);
  border-color: var(--gold);
  color: #fff;
}

.notif-action-primary:hover {
  background: var(--gold-light);
  color: #fff;
}

/* ── ENHANCED ACTION BAR — replaces the plain button ── */
.notif-action-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 14px;
  padding: 12px 16px;
  background: linear-gradient(135deg, #FFF8EA 0%, #FFFBF4 100%);
  border: 1px solid rgba(200,150,62,.25);
  border-radius: 12px;
}

.notif-action-context {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--gold);
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .4px;
}

.notif-action-context svg {
  flex-shrink: 0;
}

.notif-action-cta {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 16px;
  border-radius: 9px;
  background: var(--gold);
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  text-decoration: none;
  box-shadow: 0 4px 12px rgba(200,150,62,.3);
  transition: all .2s;
  flex-shrink: 0;
}

.notif-action-cta:hover {
  background: var(--gold-light);
  color: #fff;
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(200,150,62,.4);
}

.notif-action-arrow {
  transition: transform .2s;
}

.notif-action-cta:hover .notif-action-arrow {
  transform: translateX(3px);
}

/* ── MOBILE ── */
@media (max-width: 640px) {
  .notif-wrap {
    padding: 24px 16px;
  }

  .notif-header-actions {
    width: 100%;
    flex-direction: column;
  }

  .notif-history-btn,
  .notif-markall-btn {
    width: 100%;
    justify-content: center;
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

  .notif-actions {
    flex-direction: column;
  }

  .notif-action-bar {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }

  .notif-action-cta {
    justify-content: center;
  }
}

</style>
@endsection