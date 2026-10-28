{{--
  The three notification controls in ONE row: Mark all as read, Only show unread (shared switch), Dates (reveals From / To).
  $mode = 'page'     real GET form (#nf-filter) with query-string filters; works without JavaScript for the basics.
  $mode = 'dropdown' the navbar panel; notifications.js drives it (no form).
  $filters (page only): unread / from / to as read from the query string.
--}}
@php
  $isPage   = $mode === 'page';
  $f        = $filters ?? ['unread' => false, 'from' => null, 'to' => null];
  $datesOpen = $isPage && ($f['from'] || $f['to']);
  $pfx      = $isPage ? 'nfp' : 'nfd';
@endphp
<div class="nf-ctl" role="group" aria-label="Notification filters">
  @if($isPage)
    <button type="submit" form="nf-markall" class="lp-btn lp-btn--secondary nf-btn">Mark All as Read</button>
  @else
    <button type="button" class="lp-btn lp-btn--secondary nf-btn" data-nf-act="markall">Mark All as Read</button>
  @endif

  @include('partials.switch', ['id' => $pfx . '-unread', 'name' => 'unread', 'checked' => $f['unread'], 'label' => 'Only show unread'])

  <button type="button" class="lp-btn lp-btn--secondary nf-btn nf-dates-btn" data-nf-act="dates"
          aria-expanded="{{ $datesOpen ? 'true' : 'false' }}" aria-controls="{{ $pfx }}-dates"
          @if($datesOpen) data-active="1" @endif>Dates</button>
</div>

<div class="nf-dates" id="{{ $pfx }}-dates" @unless($datesOpen) hidden @endunless>
  <label>From <input type="date" name="from" value="{{ $f['from'] }}" data-nf-from></label>
  <label>To <input type="date" name="to" value="{{ $f['to'] }}" data-nf-to></label>
  @if($isPage)
    <a class="lp-link lp-link--tap" href="{{ route('notifications.index') }}">Clear</a>
  @else
    <button type="button" class="lp-link lp-link--tap" data-nf-act="clear">Clear</button>
  @endif
</div>
