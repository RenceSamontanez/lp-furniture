@extends('layouts.admin')

@section('title', 'Activity Log — Admin')
@section('page-title', 'Activity Log')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-activity-log.css') }}?v={{ @filemtime(public_path('assets/css/admin-activity-log.css')) }}"/>
@endpush

@php
  use App\Support\ActivityLogViewer as V;
  $url = fn (array $o = []) => route('admin.activity-log', V::queryFor($f, $o + ['page' => null]));
  $today = now()->startOfDay();
  $quick = [
    'Today'        => [$today->format('Y-m-d'), $today->format('Y-m-d')],
    'Yesterday'    => [$today->copy()->subDay()->format('Y-m-d'), $today->copy()->subDay()->format('Y-m-d')],
    'Last 7 Days'  => [$today->copy()->subDays(6)->format('Y-m-d'), $today->format('Y-m-d')],
    'Last 30 Days' => [$today->copy()->subDays(29)->format('Y-m-d'), $today->format('Y-m-d')],
  ];
  $quickOn = 'All Time';
  foreach ($quick as $label => [$a, $b]) {
      if ($f['from'] === $a && $f['to'] === $b) { $quickOn = $label; }
  }
  $fmt = fn (?string $d) => $d ? \Carbon\Carbon::createFromFormat('!Y-m-d', $d)->format('M j, Y') : null;

  // removable filter chips: label + the link that drops just that filter
  $chips = [];
  if ($f['q'] !== '') { $chips[] = ['Search: ' . $f['q'], ['q' => '']]; }
  if ($f['person'] > 0) { $chips[] = [$people[$f['person']] ?? 'Person', ['person' => 0]]; }
  if ($f['what'] !== '') { $chips[] = [V::WHAT[$f['what']], ['what' => '']]; }
  if ($f['who'] !== '') { $chips[] = [V::WHO[$f['who']], ['who' => '']]; }
  if ($f['from'] || $f['to']) {
      $chips[] = [$quickOn !== 'All Time' ? $quickOn : ($fmt($f['from']) ?? 'Start') . ' to ' . ($fmt($f['to']) ?? 'Today'), ['from' => null, 'to' => null]];
  }
  if ($f['tod'] !== '') { $chips[] = [V::TOD[$f['tod']], ['tod' => '']]; }
  if ($f['tab'] !== 'all') { $chips[] = [V::TABS[$f['tab']], ['tab' => 'all']]; }
  $heads = ['when' => 'When', 'who' => 'Who', 'type' => 'Type'];
  $sortCol = V::sortColumn($f['sort']);
@endphp

@section('content')
<div class="al" id="al-page">
  <p class="al-sub">A record of who signed in and what the team changed. It shows people and actions only. It never stores IP addresses, passwords or anything customers typed. Records are kept for 180 days.</p>

  <div class="al-sum">
    @foreach($summary as $s)
      <div class="al-sum__{{ $s['tone'] ?: 'plain' }}"><b>{{ $s['value'] }}</b><span>{{ $s['label'] }}</span></div>
    @endforeach
  </div>

  <div class="al-tabs" role="tablist" aria-label="Kind of activity">
    @foreach(V::TABS as $key => $label)
      <a class="al-tab {{ $f['tab'] === $key ? 'is-on' : '' }}" role="tab" aria-selected="{{ $f['tab'] === $key ? 'true' : 'false' }}" href="{{ $url(['tab' => $key]) }}">{{ $label }}<span class="al-tab__c">{{ $counts[$key] }}</span></a>
    @endforeach
  </div>

  <div class="admin-panel al-filters">
    <form method="GET" action="{{ route('admin.activity-log') }}" id="al-form" role="search">
      @if($f['tab'] !== 'all')<input type="hidden" name="tab" value="{{ $f['tab'] }}"/>@endif
      <div class="al-row">
        <div class="al-col al-col--grow"><label for="al-q">Search</label><input type="search" id="al-q" name="q" class="form-input" value="{{ $f['q'] }}" maxlength="100" placeholder="A person, action or order number" autocomplete="off"/></div>
        <div class="al-col"><label for="al-person">Person</label>
          <select id="al-person" name="person" class="form-input"><option value="">Everyone</option>@foreach($people as $id => $name)<option value="{{ $id }}" @selected($f['person'] === (int) $id)>{{ $name }}</option>@endforeach</select></div>
        <div class="al-col"><label for="al-what">What Happened</label>
          <select id="al-what" name="what" class="form-input">@foreach(V::WHAT as $k => $l)<option value="{{ $k }}" @selected($f['what'] === (string) $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="al-col"><label for="al-who">Who Is It</label>
          <select id="al-who" name="who" class="form-input">@foreach(V::WHO as $k => $l)<option value="{{ $k }}" @selected($f['who'] === (string) $k)>{{ $l }}</option>@endforeach</select></div>
      </div>
      <div class="al-row">
        <div class="al-col"><span class="al-lab" id="al-qd-l">Quick Dates</span>
          <div class="al-qd" role="group" aria-labelledby="al-qd-l">
            @foreach($quick as $label => [$a, $b])
              <a class="al-fc" href="{{ $url(['from' => $a, 'to' => $b]) }}" aria-pressed="{{ $quickOn === $label ? 'true' : 'false' }}">{{ $label }}</a>
            @endforeach
            <a class="al-fc" href="{{ $url(['from' => null, 'to' => null]) }}" aria-pressed="{{ $quickOn === 'All Time' && !$f['from'] && !$f['to'] ? 'true' : 'false' }}">All Time</a>
          </div></div>
        <div class="al-col"><label for="al-from">From</label><input type="date" id="al-from" name="from" class="form-input" value="{{ $f['from'] }}" min="{{ $minDate }}" max="{{ $maxDate }}"/></div>
        <div class="al-col"><label for="al-to">To</label><input type="date" id="al-to" name="to" class="form-input" value="{{ $f['to'] }}" min="{{ $minDate }}" max="{{ $maxDate }}"/></div>
        <div class="al-col"><label for="al-tod">Time Of Day</label>
          <select id="al-tod" name="tod" class="form-input">@foreach(V::TOD as $k => $l)<option value="{{ $k }}" @selected($f['tod'] === (string) $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="al-col"><label for="al-sort">Sort By</label>
          <select id="al-sort" name="sort" class="form-input">@foreach(V::SORTS as $k => $l)<option value="{{ $k }}" @selected($f['sort'] === $k)>{{ $l }}</option>@endforeach</select></div>
      </div>
      <noscript><button type="submit" class="lp-btn lp-btn--primary">Apply</button></noscript>
    </form>
    <div class="al-row al-row--end">
      <div class="al-chips" id="al-chips">
        @foreach($chips as [$text, $drop])
          <span class="al-chip">{{ $text }}<a href="{{ $url($drop) }}" aria-label="Remove {{ $text }}">&times;</a></span>
        @endforeach
        @if(count($chips) > 0)<a class="al-clear" href="{{ route('admin.activity-log') }}">Clear All</a>@endif
      </div>
      <span class="al-grow"></span>
      <button type="button" class="lp-btn lp-btn--secondary al-small" data-lp-modal-open="al-alert" aria-haspopup="dialog">Preview Customer Alert</button>
      <a class="lp-btn lp-btn--secondary al-small" href="{{ route('admin.activity-log.export', V::queryFor($f, ['page' => null])) }}">Download CSV</a>
    </div>
  </div>

  <div class="admin-panel al-panel">
    @if($rows->isNotEmpty())
      <table class="al-table" id="al-table">
        <thead>
          <tr>
            @foreach(['when', 'who'] as $col)
              @php $on = $sortCol === $col; $desc = in_array($f['sort'], ['new', 'za'], true); @endphp
              <th @if($on) aria-sort="{{ $desc ? 'descending' : 'ascending' }}" @endif><a class="al-sortb {{ $on ? 'is-on' : '' }} {{ $on && $desc ? 'is-desc' : '' }}" href="{{ $url(['sort' => V::headerSort($f['sort'], $col)]) }}">{{ $heads[$col] }}<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1l4 7H1z"/></svg></a></th>
            @endforeach
            <th>What Happened</th>
            <th aria-sort="{{ $sortCol === 'type' ? 'ascending' : 'none' }}"><a class="al-sortb {{ $sortCol === 'type' ? 'is-on' : '' }}" href="{{ $url(['sort' => 'type']) }}">Type<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1l4 7H1z"/></svg></a></th>
            <th><span class="al-sr">Actions</span></th>
          </tr>
        </thead>
        <tbody id="al-rows">
          @foreach($rows as $r)
            @php
              $t = $r->created_at;
              $late = V::isLate($t);
              $roleName = $r->role === 'admin' ? 'Admin' : ($r->role === 'customer' ? 'Customer' : 'System');
              $open = $links[$r->id] ?? '';
            @endphp
            <tr class="al-row-item">
              <td class="al-when"><b>{{ V::dayLabel($t) }}</b><span>{{ $t->format('g:i A') }}@if($late)<em class="al-late">Late Night</em>@endif</span></td>
              <td class="al-who" data-label="Who"><b>{{ $r->actor_name }}</b><span class="al-role al-role--{{ $r->role }}">{{ $roleName }}</span></td>
              <td data-label="What Happened">{{ $r->description }}</td>
              <td data-label="Type"><span class="al-type {{ V::typeClass($r->category) }}">{{ V::categoryLabel($r->category) }}</span></td>
              <td class="al-act"><button type="button" class="lp-btn lp-btn--secondary al-small" data-lp-modal-open="al-detail" aria-haspopup="dialog"
                  data-when="{{ V::dayLabel($t) }} ({{ $t->format('Y-m-d') }}) at {{ $t->format('g:i A') }}{{ $late ? ', late night' : '' }}"
                  data-who="{{ $r->actor_name }} ({{ $roleName }})"
                  data-what="{{ $r->description }}"
                  data-type="{{ V::categoryLabel($r->category) }}"
                  data-item="{{ $r->subject_label ?: '—' }}"
                  data-url="{{ $open }}">Details<span class="al-sr"> for {{ $r->description }}</span></button></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="al-empty"><strong>No activity matches</strong>Try a different tab, date or search.</div>
    @endif
    <div class="al-pager">
      <span>@if($rows->total() > 0)Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }}@else 0 records @endif</span>
      <span class="al-pager__btns">
        @if($rows->previousPageUrl())<a class="lp-btn lp-btn--secondary al-small" href="{{ $rows->previousPageUrl() }}" rel="prev">Previous</a>@else<span class="lp-btn lp-btn--secondary al-small is-off" aria-disabled="true">Previous</span>@endif
        @if($rows->nextPageUrl())<a class="lp-btn lp-btn--secondary al-small" href="{{ $rows->nextPageUrl() }}" rel="next">Next</a>@else<span class="lp-btn lp-btn--secondary al-small is-off" aria-disabled="true">Next</span>@endif
      </span>
    </div>
  </div>

  <p class="al-note"><b>Customer sign-in alerts:</b> every time a customer signs in, they get one notification, at most one every 30 minutes. It never shows an IP or device.</p>
</div>

<x-modal id="al-detail" title="Activity Details" size="mid">
  <dl class="al-dl" id="al-dl">
    <dt>When</dt><dd data-f="when"></dd>
    <dt>Who</dt><dd data-f="who"></dd>
    <dt>What Happened</dt><dd data-f="what"></dd>
    <dt>Type</dt><dd data-f="type"></dd>
    <dt>Item</dt><dd data-f="item"></dd>
  </dl>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button>
    <a href="#" class="lp-btn lp-btn--primary" id="al-open" hidden>Open Item</a>
  </x-slot:footer>
</x-modal>

<x-modal id="al-alert" title="Customer Sign-In Alert" size="mid">
  <p class="al-hint">This is what a customer sees in their notifications after signing in.</p>
  <div class="al-alertbox"><span class="al-alertbox__ic" aria-hidden="true">!</span><div><b>{{ \App\Support\ActivityLog::ALERT_TITLE }}</b><p>{{ $alertSample }}</p></div></div>
  <p class="al-hint">One alert at most every 30 minutes. No email is sent.</p>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-close>Close</button>
  </x-slot:footer>
</x-modal>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/admin-activity-log.js') }}?v={{ @filemtime(public_path('assets/js/admin-activity-log.js')) }}"></script>
@endpush
