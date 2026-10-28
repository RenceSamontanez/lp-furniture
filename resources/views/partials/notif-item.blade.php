{{-- One notification card on the full page. Buttons are words only. "View details" only where $notif->hasDetails() (NotificationKind). --}}
@php
  $kind = $notif->kind();
  $icon = ['order' => 'nf-bag', 'service' => 'nf-pencil', 'account' => 'nf-shield'][$kind];
  $details = $notif->detailsUrl();
@endphp
<article class="nf-item {{ $notif->is_read ? '' : 'nf-item--unread' }}" data-id="{{ $notif->id }}" data-kind="{{ $kind }}">
  <div class="nf-ic" aria-hidden="true"><svg><use href="#{{ $icon }}"/></svg></div>
  <div class="nf-body">
    <h3 class="nf-item-title">
      @unless($notif->is_read)<span class="nf-ud" role="img" aria-label="Unread"></span>@endunless
      {{ $notif->title }}
    </h3>
    <p class="nf-text">{{ $notif->message }}</p>
    <div class="nf-meta">
      <time class="nf-when" datetime="{{ $notif->created_at->toIso8601String() }}" title="{{ $notif->created_at->format('M j, Y g:i A') }}">{{ $notif->timeLabel() }}</time>
      @if($details)
        <a href="{{ $details }}" class="lp-btn lp-btn--primary lp-btn--chip nf-view">View details</a>
      @endif
      @unless($notif->is_read)
        <form method="POST" action="{{ route('notifications.read', $notif->id) }}">
          @csrf
          @method('PATCH')
          <button type="submit" class="lp-link lp-link--tap">Mark read</button>
        </form>
      @endunless
    </div>
  </div>
</article>
