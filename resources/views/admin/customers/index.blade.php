@extends('layouts.admin')

@section('title', 'Customers — Admin')
@section('page-title', 'Customers')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-customers.css') }}"/>
@endpush

@php
  use App\Support\AdminCustomers as AC;
  $url   = fn (array $o = []) => route('admin.customers.index', AC::queryFor($f, $o));
  $show  = fn ($id) => route('admin.customers.show', array_merge(['id' => $id], AC::queryFor($f), $customers->currentPage() > 1 ? ['page' => $customers->currentPage()] : []));
  $heads = ['name' => 'Name', 'orders' => 'Orders', 'spent' => 'Total Spent', 'joined' => 'Joined'];
  $filtered = $f['search'] !== '' || count($chips) > 0;
  $perPage = $customers->perPage();
@endphp

@section('content')
<div class="cu" id="cu-page" data-url="{{ route('admin.customers.index') }}" data-range-error="{{ $f['rangeError'] ? '1' : '' }}">

  <div class="cu-stats" id="cu-stats">
    <a href="{{ $url(['status' => 'all']) }}" class="cu-stat {{ $f['status'] === 'all' ? 'is-on' : '' }}" @if($f['status'] === 'all') aria-current="true" @endif><b>{{ $stats['total'] }}</b><span>Total Customers</span></a>
    <a href="{{ $url(['status' => 'verified']) }}" class="cu-stat cu-stat--v {{ $f['status'] === 'verified' ? 'is-on' : '' }}" @if($f['status'] === 'verified') aria-current="true" @endif><b>{{ $stats['verified'] }}</b><span>Verified</span></a>
    <a href="{{ $url(['status' => 'unverified']) }}" class="cu-stat cu-stat--u {{ $f['status'] === 'unverified' ? 'is-on' : '' }}" @if($f['status'] === 'unverified') aria-current="true" @endif><b>{{ $stats['unverified'] }}</b><span>Unverified</span></a>
  </div>

  <form method="GET" action="{{ route('admin.customers.index') }}" class="cu-tools" id="cu-tools" role="search">
    <input type="search" name="search" id="cu-q" class="form-input" value="{{ $f['search'] }}" placeholder="Search name, email or phone" aria-label="Search customers" maxlength="100" autocomplete="off"/>
    <button type="button" class="lp-btn lp-btn--secondary cu-filter-btn" data-lp-modal-open="cu-filters" aria-haspopup="dialog">Filters<span class="cu-badge" id="cu-badge" @if(!count($chips)) hidden @endif>{{ count($chips) }}</span></button>
    <span class="cu-grow"></span>
    <label for="cu-sort" class="cu-sortlabel">Sort</label>
    <select name="sort" id="cu-sort" class="form-input" aria-label="Sort customers">
      @foreach(AC::SORTS as $key => $label)<option value="{{ $key }}" @selected($f['sort'] === $key)>{{ $label }}</option>@endforeach
    </select>
    @foreach(['status' => 'all', 'orders' => 'any', 'joined' => 'any'] as $k => $default)
      <input type="hidden" name="{{ $k }}" value="{{ $f[$k] }}"/>
    @endforeach
    <input type="hidden" name="min" value="{{ $f['min'] !== null ? $f['min'] : '' }}"/>
    <input type="hidden" name="max" value="{{ $f['max'] !== null ? $f['max'] : '' }}"/>
    <noscript><button type="submit" class="lp-btn lp-btn--primary">Search</button></noscript>
  </form>

  <div id="cu-dyn">
    <div class="cu-active" id="cu-active">
      @foreach($chips as $chip)
        <span class="cu-chip">{{ $chip['label'] }}<a href="{{ $url($chip['drop']) }}" aria-label="Remove {{ $chip['label'] }}">&times;</a></span>
      @endforeach
      @if(count($chips) > 1)
        <a class="cu-clear" href="{{ $url(['search' => '', 'status' => 'all', 'orders' => 'any', 'joined' => 'any', 'min' => null, 'max' => null]) }}">Clear All</a>
      @endif
    </div>

    <p class="cu-count" id="cu-count" aria-live="polite">
      @if($customers->total() <= $perPage)
        Showing {{ $customers->total() }} of {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'customer' : 'customers' }}
      @else
        Showing {{ $customers->firstItem() }} to {{ $customers->lastItem() }} of {{ $customers->total() }} {{ $filtered ? 'matching customers (' . $stats['total'] . ' in total)' : 'customers' }}
      @endif
    </p>

    <div class="admin-panel cu-panel">
      @if($customers->isNotEmpty())
        <table class="cu-table">
          <thead>
            <tr>
              @foreach(['name', 'email', 'phone', 'orders', 'spent', 'status', 'joined'] as $col)
                @if(isset($heads[$col]))
                  @php $on = str_starts_with($f['sort'], $col . '-'); $asc = str_ends_with($f['sort'], '-asc'); @endphp
                  <th @if($on) aria-sort="{{ $asc ? 'ascending' : 'descending' }}" @endif>
                    <a class="cu-sortb {{ $on ? 'is-on' : '' }} {{ $on && !$asc ? 'is-desc' : '' }}" href="{{ $url(['sort' => AC::headerSort($f['sort'], $col)]) }}">{{ $heads[$col] }}<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1l4 7H1z"/></svg></a>
                  </th>
                @else
                  <th>{{ ucfirst($col) }}</th>
                @endif
              @endforeach
              <th><span class="cu-sr">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            @foreach($customers as $c)
              <tr class="cu-row">
                <td class="cu-name"><strong>{{ $c->first_name }} {{ $c->last_name }}</strong></td>
                <td data-label="Email" class="cu-email">{{ $c->email }}</td>
                <td data-label="Phone">{{ $c->phone_number ?: '—' }}</td>
                <td data-label="Orders">{{ $c->orders_count }}</td>
                <td data-label="Total Spent">₱{{ number_format((float) $c->total_spent, 2) }}</td>
                <td data-label="Status"><span class="cu-vchip {{ $c->is_validated ? 'is-v' : 'is-u' }}">{{ $c->is_validated ? 'Verified' : 'Unverified' }}</span></td>
                <td data-label="Joined">{{ $c->created_at->format('M j, Y') }}</td>
                <td class="cu-act"><a href="{{ $show($c->id) }}" class="lp-btn lp-btn--primary cu-small">View<span class="cu-sr"> {{ $c->first_name }} {{ $c->last_name }}</span></a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @elseif($stats['total'] === 0)
        <div class="cu-empty"><strong>No customers yet</strong>Customers show up here after they create an account.</div>
      @else
        <div class="cu-empty"><strong>No customers match</strong>Change the search or clear the filters.
          <div class="cu-empty-act"><a class="lp-btn lp-btn--secondary cu-small" href="{{ $url(['search' => '', 'status' => 'all', 'orders' => 'any', 'joined' => 'any', 'min' => null, 'max' => null]) }}">Clear Filters</a></div>
        </div>
      @endif
    </div>

    <div class="cu-pager">{{ $customers->links() }}</div>
  </div>
</div>

{{-- Filters: the shared modal, mounted on the body when opened. Real radios and inputs tied to #cu-fform; the server validates everything again. --}}
<form method="GET" action="{{ route('admin.customers.index') }}" id="cu-fform" hidden>
  <input type="hidden" name="search" value="{{ $f['search'] }}"/>
  <input type="hidden" name="sort" value="{{ $f['sort'] }}"/>
</form>
<x-modal id="cu-filters" title="Filter Customers" size="mid">
  @foreach([
    ['status', 'Status', AC::STATUS_CHOICES],
    ['orders', 'Orders', AC::ORDER_CHOICES],
    ['joined', 'Joined', AC::JOINED_CHOICES],
  ] as [$name, $label, $choices])
    <fieldset class="cu-fset">
      <legend>{{ $label }}</legend>
      <div class="cu-fchips">
        @foreach($choices as $value => $text)
          <label class="cu-fc"><input type="radio" name="{{ $name }}" value="{{ $value }}" form="cu-fform" data-default="{{ array_key_first($choices) }}" @checked((string) $f[$name] === (string) $value)/><span>{{ $text }}</span></label>
        @endforeach
      </div>
    </fieldset>
  @endforeach
  <fieldset class="cu-fset">
    <legend>Total Spent</legend>
    <div class="cu-fgrid">
      <div><label class="cu-sr" for="cu-min">Minimum Spent</label><input type="text" id="cu-min" name="min" form="cu-fform" class="form-input {{ $f['rangeError'] ? 'is-bad' : '' }}" inputmode="numeric" autocomplete="off" placeholder="Minimum, for example 10000" value="{{ $f['rangeError'] ? $f['rawMin'] : ($f['min'] !== null ? rtrim(rtrim(number_format($f['min'], 2, '.', ''), '0'), '.') : '') }}" aria-describedby="cu-ferr"/></div>
      <div><label class="cu-sr" for="cu-max">Maximum Spent</label><input type="text" id="cu-max" name="max" form="cu-fform" class="form-input {{ $f['rangeError'] ? 'is-bad' : '' }}" inputmode="numeric" autocomplete="off" placeholder="Maximum, for example 100000" value="{{ $f['rangeError'] ? $f['rawMax'] : ($f['max'] !== null ? rtrim(rtrim(number_format($f['max'], 2, '.', ''), '0'), '.') : '') }}" aria-describedby="cu-ferr"/></div>
    </div>
    <p class="cu-ferr" id="cu-ferr" role="alert" @if(!$f['rangeError']) hidden @endif>The minimum can't be higher than the maximum.</p>
    <p class="cu-fhelp">Total Spent counts money received on orders that are not cancelled.</p>
  </fieldset>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" id="cu-freset">Clear All</button>
    <button type="submit" form="cu-fform" class="lp-btn lp-btn--primary" id="cu-fapply">Show Results</button>
  </x-slot:footer>
</x-modal>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/admin-customers.js') }}"></script>
@endpush
