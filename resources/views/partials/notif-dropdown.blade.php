{{-- Navbar notification panel (desktop, >= 1001px). Opened by the bell (#sn-bell) through public/assets/js/notifications.js; below that width the bell is a plain link to the full page. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs>
    <symbol id="nf-bag" viewBox="0 0 24 24"><path d="M6 8h12l1 12H5z"/><path d="M9 8a3 3 0 0 1 6 0"/></symbol>
    <symbol id="nf-pencil" viewBox="0 0 24 24"><path d="M4 20l4.2-1 10-10a2.1 2.1 0 0 0-3-3l-10 10z"/></symbol>
    <symbol id="nf-shield" viewBox="0 0 24 24"><path d="M12 3l7 3v5.5c0 4.2-2.8 7.3-7 9.5-4.2-2.2-7-5.3-7-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></symbol>
  </defs>
</svg>

<section class="nf-dd" id="notif-dd" role="dialog" aria-label="Notifications" hidden
         data-feed="{{ route('notifications.feed', [], false) }}"
         data-read-url="{{ route('notifications.read', ['id' => 0], false) }}"
         data-read-all-url="{{ route('notifications.readAll', [], false) }}"
         data-unread="{{ $unreadCount }}">
  <div class="nf-dd-h">
    <h2 class="nf-dd-title">Notifications</h2>
    <span class="nf-count" data-nf-count></span>
  </div>
  @include('partials.notif-controls', ['mode' => 'dropdown'])
  <div class="nf-dd-list" data-nf-list aria-live="polite"></div>
  <div class="nf-dd-f"><a class="lp-link lp-link--tap" href="{{ route('notifications.index') }}">See All Notifications</a></div>
</section>
