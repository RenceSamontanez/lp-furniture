@extends('layouts.admin')

@section('title', 'Reports and Analytics — Admin')
@section('page-title', 'Reports and Analytics')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-reports.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-materials.css') }}?v={{ @filemtime(public_path('assets/css/admin-materials.css')) }}"/>
@endpush

@php
  $tabs = ['sales' => 'Sales', 'prod' => 'Products', 'svc' => 'Services & AI', 'luma' => 'Luma', 'cust' => 'Customers', 'materials' => 'Materials'];
  $money = fn ($v) => \App\Support\AdminReports::peso($v === null ? null : (float) $v);
  $up = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1l4 7H1z"/></svg>';
  $dn = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M5 9L1 2h8z"/></svg>';
@endphp

{{-- one change chip: a small triangle and the change --}}
@php
  $chip = fn (array $c) => '<span class="rp-trend rp-' . $c['tone'] . '">' . ($c['dir'] === 'up' ? $up : ($c['dir'] === 'dn' ? $dn : '')) . e($c['chip']) . '</span>';
@endphp

@section('content')
<div class="rp">

  <div class="rp-top">
    <div class="rp-seg" role="group" aria-label="Compare period">
      @foreach($r['periods'] as $key => $label)
        <a href="{{ route('admin.reports', ['period' => $key, 'tab' => $tab]) }}" data-period="{{ $key }}" class="{{ $r['period'] === $key ? 'is-on' : '' }}" @if($r['period'] === $key) aria-current="true" @endif>{{ $label }}</a>
      @endforeach
    </div>
  </div>

  <div class="rp-kpis">
    @foreach($r['kpis'] as $k)
      <div class="rp-kpi rp-top-{{ $k['tone'] }}">
        <div class="rp-kpi__l">{{ $k['label'] }}</div>
        <div class="rp-kpi__v">{{ $k['value'] }}</div>
        {!! $chip($k) !!}
        <div class="rp-kpi__d">Was {{ $k['was'] }}</div>
      </div>
    @endforeach
  </div>

  <div class="rp-tabs" role="tablist" aria-label="Report sections">
    @foreach($tabs as $key => $label)
      <button type="button" class="rp-tab {{ $tab === $key ? 'is-on' : '' }}" role="tab" id="rp-t-{{ $key }}" data-tab="{{ $key }}" aria-controls="rp-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
  </div>

  {{-- SALES --}}
  @php $s = $r['sales']; @endphp
  <section class="rp-pane {{ $tab === 'sales' ? 'is-on' : '' }}" id="rp-sales" role="tabpanel" aria-labelledby="rp-t-sales">
    <div class="rp-grid">
      <div>
        <div class="rp-card"><h2>Monthly Revenue</h2><div class="rp-body">
          <div class="rp-chart">
            @foreach($s['months'] as $m)
              <div class="rp-col {{ $m['value'] <= 0 ? 'is-zero' : '' }}" title="{{ $m['label'] }}: {{ $money($m['value']) }}">
                <span class="rp-val">{{ $m['value'] > 0 ? '₱' . ($m['value'] >= 1000 ? number_format(round($m['value'] / 1000)) . 'k' : number_format($m['value'])) : '₱0' }}</span>
                <div class="rp-bar" style="height: {{ max(3, $m['height']) }}%"></div>
                <span class="rp-mo">{{ $m['label'] }}</span>
                <span class="rp-mv rp-{{ $m['chip']['tone'] }}">{{ $m['chip']['text'] }}</span>
              </div>
            @endforeach
          </div>
        </div></div>
        <div class="rp-card"><h2>Orders by Status <span class="rp-soft">{{ number_format($s['ordersTotal']) }} {{ $s['ordersTotal'] === 1 ? 'order' : 'orders' }}</span></h2><div class="rp-body">
          @if($s['ordersTotal'] === 0)
            <p class="rp-empty">No orders yet.</p>
          @else
            <div class="rp-seg-bar" role="img" aria-label="Orders by status">
              @foreach($s['statuses'] as $st)@if($st['count'] > 0)<i class="rp-s-{{ $st['key'] }}" style="width: {{ $st['count'] / $s['ordersTotal'] * 100 }}%" title="{{ $st['label'] }}: {{ $st['count'] }}"></i>@endif @endforeach
            </div>
            <div class="rp-legend">
              @foreach($s['statuses'] as $st)<div><b>{{ number_format($st['count']) }}</b><span class="rp-sq rp-s-{{ $st['key'] }}"></span>{{ $st['label'] }}</div>@endforeach
            </div>
          @endif
        </div></div>
      </div>
      <div>
        <div class="rp-card"><h2>Catalog vs Customized</h2><div class="rp-body">
          @foreach($s['split'] as $row)
            <div class="rp-hb">
              <div class="rp-t"><b>{{ $row['label'] }}</b><span>{{ number_format($row['orders']) }} {{ $row['orders'] === 1 ? 'order' : 'orders' }}</span></div>
              <div class="rp-t rp-t2"><span>{{ $money($row['total']) }}</span><span>Collected {{ $money($row['paid']) }}</span></div>
              <div class="rp-trk rp-green"><i style="width: {{ $row['total'] > 0 ? min(100, $row['paid'] / $row['total'] * 100) : 0 }}%"></i></div>
            </div>
          @endforeach
        </div></div>
        <div class="rp-card"><h2>Money Collected</h2><div class="rp-body">
          <div class="rp-big">{{ $money($s['collected']['paid']) }} <span class="rp-soft">of {{ $money($s['collected']['total']) }}</span></div>
          <div class="rp-trk rp-green rp-thick"><i style="width: {{ min(100, $s['collected']['pct']) }}%"></i></div>
          <div class="rp-split"><span>Collected {{ $s['collected']['pct'] }}%</span><span>To collect <b>{{ $money($s['collected']['left']) }}</b></span></div>
        </div></div>
      </div>
    </div>
  </section>

  {{-- PRODUCTS --}}
  @php $p = $r['products']; @endphp
  <section class="rp-pane {{ $tab === 'prod' ? 'is-on' : '' }}" id="rp-prod" role="tabpanel" aria-labelledby="rp-t-prod">
    <div class="rp-grid">
      <div>
        <div class="rp-card"><h2>Best-Selling Products</h2><div class="rp-body">
          @forelse($p['best'] as $i => $b)
            <div class="rp-li"><span><span class="rp-rank">{{ $i + 1 }}</span>{{ $b->product_name }}</span><b>{{ number_format($b->sold) }} sold</b></div>
          @empty <p class="rp-empty">No sales yet.</p> @endforelse
        </div></div>
        <div class="rp-card"><h2>Orders by Category</h2><div class="rp-body">
          @forelse($p['category'] as $c)
            <div class="rp-hb"><div class="rp-t"><b>{{ $c['name'] }}</b><span>{{ number_format($c['n']) }} ({{ $c['pct'] }}%)</span></div><div class="rp-trk"><i style="width: {{ $c['width'] }}%"></i></div></div>
          @empty <p class="rp-empty">No sales yet.</p> @endforelse
        </div></div>
      </div>
      <div>
        <div class="rp-card"><h2>Most Used Wood <span class="rp-soft">Orders</span></h2><div class="rp-body">
          @forelse($p['wood'] as $c)
            <div class="rp-hb"><div class="rp-t"><b>{{ $c['name'] }}</b><span>{{ number_format($c['n']) }} ({{ $c['pct'] }}%)</span></div><div class="rp-trk"><i style="width: {{ $c['width'] }}%"></i></div></div>
          @empty <p class="rp-empty">No wood recorded on orders yet.</p> @endforelse
        </div></div>
        <div class="rp-card"><h2>Low Stock <a href="{{ route('admin.products.index') }}" class="lp-btn lp-btn--secondary rp-small">Manage</a></h2><div class="rp-body">
          @forelse($p['low'] as $low)
            <div class="rp-li"><span>{{ $low->product_name }}</span><span class="rp-low">{{ (int) $low->stock_quantity }} left</span></div>
          @empty <p class="rp-empty">Nothing is running low.</p> @endforelse
        </div></div>
      </div>
    </div>
  </section>

  {{-- SERVICES & AI --}}
  @php $v = $r['services']; $f = $v['funnel']; @endphp
  <section class="rp-pane {{ $tab === 'svc' ? 'is-on' : '' }}" id="rp-svc" role="tabpanel" aria-labelledby="rp-t-svc">
    <div class="rp-four">
      @foreach($v['cards'] as $k)
        <div><b>{{ $k['value'] }}</b><span>{{ $k['label'] }}</span>{!! $chip($k) !!}</div>
      @endforeach
    </div>
    <div class="rp-grid">
      <div>
        <div class="rp-card"><h2>Customize Studio: From Design To Sale</h2><div class="rp-body">
          @foreach([['Designs Made', $f['made'], null], ['Became Requests', $f['requests'], $f['requestsPct'] !== null ? $f['requestsPct'] . '% of designs' : null], ['Became Paid Orders', $f['paid'], $f['paidPct'] !== null ? $f['paidPct'] . '% of requests' : null]] as $i => [$lab, $n, $note])
            <div class="rp-hb"><div class="rp-t"><b>{{ $lab }}</b><span><b class="rp-ink">{{ number_format($n) }}</b>{{ $note ? ' (' . $note . ')' : '' }}</span></div>
              <div class="rp-trk {{ $i === 2 ? 'rp-green' : '' }}"><i style="width: {{ $f['made'] > 0 ? max($n > 0 ? 2 : 0, $n / $f['made'] * 100) : 0 }}%"></i></div></div>
          @endforeach
          <div class="rp-li"><span>Average paid order</span><b>{{ $money($f['average']) }}</b></div>
          <div class="rp-li"><span>Designs that ended in a sale</span><b>{{ $f['salePct'] !== null ? $f['salePct'] . '%' : '—' }}</b></div>
        </div></div>
        <div class="rp-card"><h2>Designs Made per Month</h2><div class="rp-body">
          <div class="rp-chart">
            @foreach($v['months'] as $m)
              <div class="rp-col {{ $m['value'] <= 0 ? 'is-zero' : '' }}" title="{{ $m['label'] }}: {{ number_format($m['value']) }}">
                <span class="rp-val">{{ number_format($m['value']) }}</span><div class="rp-bar" style="height: {{ max(3, $m['height']) }}%"></div>
                <span class="rp-mo">{{ $m['label'] }}</span><span class="rp-mv rp-{{ $m['chip']['tone'] }}">{{ $m['chip']['text'] }}</span>
              </div>
            @endforeach
          </div>
        </div></div>
      </div>
      <div>
        <div class="rp-card"><h2>Service Demand <a href="{{ route('admin.service-inquiries.index') }}" class="lp-btn lp-btn--secondary rp-small">View Requests</a></h2><div class="rp-body rp-flush">
          <table class="rp-mini rp-stack">
            <thead><tr><th>Service</th><th class="r">Requests</th><th class="r rp-hide-mid">Done</th><th class="r">Revenue</th><th>Demand</th></tr></thead>
            <tbody>
            @foreach($v['demand'] as $d)
              <tr>
                <td><b>@titlecase($d['label'])</b><div class="rp-trk rp-slim"><i style="width: {{ $d['width'] }}%"></i></div></td>
                <td class="r" data-l="Requests">{{ number_format($d['requests']) }}</td>
                <td class="r rp-hide-mid rp-show-phone" data-l="Done">{{ number_format($d['done']) }}</td>
                <td class="r" data-l="Revenue">{{ $money($d['revenue']) }}</td>
                <td data-l="Demand"><span class="rp-mv rp-{{ $d['change'] > 0 ? 'good' : ($d['change'] < 0 ? 'bad' : 'flat') }}">{{ $d['change'] > 0 ? '+' . $d['change'] : ($d['change'] < 0 ? '−' . abs($d['change']) : 'Same') }}</span></td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div></div>
      </div>
    </div>
  </section>

  {{-- LUMA --}}
  @php $l = $r['luma']; @endphp
  <section class="rp-pane {{ $tab === 'luma' ? 'is-on' : '' }}" id="rp-luma" role="tabpanel" aria-labelledby="rp-t-luma">
    @if(!$l['tracked'])
      <p class="rp-notyet">Not tracked yet</p>
    @else
      <div class="rp-four">
        @foreach($l['cards'] as $k)<div><b>{{ $k['value'] }}</b><span>{{ $k['label'] }}</span>{!! $chip($k) !!}</div>@endforeach
      </div>
      <div class="rp-grid">
        <div>
          <div class="rp-card"><h2>Luma Answer Rate by Month</h2><div class="rp-body">
            <div class="rp-chart">
              @foreach($l['months'] as $m)
                <div class="rp-col {{ $m['value'] <= 0 ? 'is-zero' : '' }}" title="{{ $m['label'] }}: {{ round($m['value']) }}%">
                  <span class="rp-val">{{ round($m['value']) }}%</span><div class="rp-bar" style="height: {{ max(3, $m['value']) }}%"></div>
                  <span class="rp-mo">{{ $m['label'] }}</span><span class="rp-mv rp-{{ $m['chip']['tone'] }}">{{ $m['chip']['text'] }}</span>
                </div>
              @endforeach
            </div>
          </div></div>
          <div class="rp-card"><h2>Most Asked Questions</h2><div class="rp-body">
            @forelse($l['top'] as $q)
              <div class="rp-hb"><div class="rp-t"><b class="rp-q">{{ $q['q'] }}</b><span>{{ number_format($q['n']) }} {{ $q['n'] === 1 ? 'time' : 'times' }} ({{ $q['pct'] }}%)</span></div><div class="rp-trk"><i style="width: {{ $q['width'] }}%"></i></div></div>
            @empty <p class="rp-empty">Nobody has asked Luma anything in this period.</p> @endforelse
          </div></div>
        </div>
        <div>
          <div class="rp-card"><h2>Questions Luma Couldn't Answer <a href="{{ route('admin.chatbot.index', ['tab' => 'unanswered']) }}" class="lp-btn lp-btn--secondary rp-small">Train Luma</a></h2><div class="rp-body">
            @forelse($l['couldNot'] as $q)
              <div class="rp-li"><span class="rp-q">{{ $q->question }}</span><b>{{ number_format($q->times_asked) }} {{ $q->times_asked == 1 ? 'time' : 'times' }}</b></div>
            @empty <p class="rp-empty">Luma has answered everything so far.</p> @endforelse
          </div></div>
        </div>
      </div>
    @endif
  </section>

  {{-- CUSTOMERS --}}
  @php $c = $r['customers']; @endphp
  <section class="rp-pane {{ $tab === 'cust' ? 'is-on' : '' }}" id="rp-cust" role="tabpanel" aria-labelledby="rp-t-cust">
    <div class="rp-four">
      @foreach($c['cards'] as $k)<div><b>{{ $k['value'] }}</b><span>{{ $k['label'] }}</span>@if($k['chip']){!! $chip($k) !!}@endif</div>@endforeach
    </div>
    <div class="rp-grid">
      <div>
        <div class="rp-card"><h2>Unpaid Orders To Follow Up</h2><div class="rp-body rp-flush">
          @if(!$c['follow'])<p class="rp-empty rp-pad">No unpaid orders are waiting.</p>@else
          <table class="rp-mini rp-stack">
            <thead><tr><th>Customer</th><th>Order</th><th class="r">Amount</th><th>Waiting</th></tr></thead>
            <tbody>
            @foreach($c['follow'] as $row)
              <tr><td><b>{{ $row['customer'] }}</b></td><td data-l="Order"><a href="{{ route('admin.orders.show', $row['id']) }}">{{ $row['order'] }}</a></td><td class="r" data-l="Amount">{{ $money($row['amount']) }}</td><td data-l="Waiting">{{ $row['days'] }} {{ $row['days'] === 1 ? 'day' : 'days' }}</td></tr>
            @endforeach
            </tbody>
          </table>@endif
        </div></div>
        <div class="rp-card"><h2>Top Customers</h2><div class="rp-body rp-flush">
          @if(!$c['top'])<p class="rp-empty rp-pad">No orders yet.</p>@else
          <table class="rp-mini rp-stack">
            <thead><tr><th>Customer</th><th class="r">Orders</th><th class="r">Spent</th><th>Last Order</th></tr></thead>
            <tbody>
            @foreach($c['top'] as $row)
              <tr><td><b>{{ $row['name'] }}</b></td><td class="r" data-l="Orders">{{ $row['orders'] }}</td><td class="r" data-l="Spent">{{ $money($row['spent']) }}</td><td data-l="Last Order">{{ $row['last'] }}</td></tr>
            @endforeach
            </tbody>
          </table>@endif
        </div></div>
      </div>
      <div>
        <div class="rp-card"><h2>Why Orders Get Cancelled</h2><div class="rp-body">
          @forelse($c['why'] as $w)
            <div class="rp-hb"><div class="rp-t"><b>{{ $w['name'] }}</b><span>{{ $w['n'] }} {{ $w['n'] === 1 ? 'order' : 'orders' }}</span></div><div class="rp-trk rp-red"><i style="width: {{ $w['width'] }}%"></i></div></div>
          @empty <p class="rp-empty">No orders were cancelled in this period.</p> @endforelse
        </div></div>
      </div>
    </div>
  </section>

  {{-- MATERIALS: from the inventory history; no prices anywhere --}}
  <section class="rp-pane {{ $tab === 'materials' ? 'is-on' : '' }}" id="rp-materials" role="tabpanel" aria-labelledby="rp-t-materials">
    <div class="rp-top">
      <div class="rp-seg" role="group" aria-label="Materials period">
        @foreach($mt['periods'] as $key => $label)
          <a href="{{ route('admin.reports', ['period' => $r['period'], 'tab' => 'materials', 'mperiod' => $key]) }}" data-mperiod="{{ $key }}" class="{{ $mt['days'] === $key ? 'is-on' : '' }}" @if($mt['days'] === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
      </div>
    </div>
    <div class="mt-rp">
      @foreach($mt['kpis'] as $k)
        <div class="kpi {{ $k['tone'] }}"><div class="l">{{ $k['label'] }}</div><div class="v">{{ $k['value'] }}</div><div class="d">{{ $k['note'] }}</div></div>
      @endforeach
    </div>
    <div class="rp-grid">
      <div>
        <div class="rp-card"><h2>Most Needed Right Now <span class="mt-smp">Closest to running out first</span></h2><div class="rp-body">
          @forelse($mt['needs'] as $n)
            <div class="mt-need">
              <div class="t"><span>{{ $n['name'] }}</span><span class="mt-chip {{ $n['tone'] }}">{{ $n['chip'] }}</span></div>
              <div class="mt-trk {{ in_array($n['tone'], ['short', 'out'], true) ? 'red' : ($n['tone'] === 'low' ? '' : 'green') }}"><i style="width: {{ $n['pct'] }}%"></i></div>
              <div class="m">{{ $n['left'] }} &middot; {{ $n['pace'] }}{{ in_array($n['pace'], ['Out', 'No recent use'], true) ? '' : ' at the current pace' }}</div>
              @if($n['buy'] > 0)<div class="buy">Buy about <b>{{ number_format($n['buy']) }} {{ $n['unit'] }}</b> to cover the next 30 days.</div>@endif
            </div>
          @empty <p class="rp-empty">No materials yet.</p> @endforelse
        </div></div>
        <div class="rp-card"><h2>Wood Used And Restocked By Week</h2><div class="rp-body">
          <div class="mt-keyl"><span><i style="background:#c8963e"></i>Used</span><span><i style="background:#1d4e89"></i>Restocked</span></div>
          @php $anyWeek = collect($mt['weeks'])->contains(fn ($w) => $w['used'] > 0 || $w['restocked'] > 0); @endphp
          @if(! $anyWeek)
            <p class="rp-empty">No materials were used in this period.</p>
          @else
            <div class="mt-wk">
              @foreach($mt['weeks'] as $w)
                <div class="wc">
                  <div class="pair">
                    <div class="pc"><span class="val">{{ $w['used'] ?: '' }}</span><div class="bar" style="height: {{ $w['used'] / $mt['weekMax'] * 120 }}px"></div></div>
                    <div class="pc"><span class="val">{{ $w['restocked'] ?: '' }}</span><div class="bar rs" style="height: {{ $w['restocked'] / $mt['weekMax'] * 120 }}px; min-height: {{ $w['restocked'] ? 4 : 0 }}px"></div></div>
                  </div>
                  <span class="wl">{{ $w['label'] }}</span>
                </div>
              @endforeach
            </div>
          @endif
          <p class="rp-empty" style="margin-top:8px;">All wood types together, in sqft.</p>
        </div></div>
      </div>
      <div>
        <div class="rp-card"><h2>Recent Movements</h2><div class="rp-body">
          @forelse($mt['recent'] as $v)
            <div class="rp-li"><span><b>{{ $v['name'] }}</b><br><span class="mt-smp">{{ $v['reason'] }} &middot; {{ $v['date'] }}</span></span><span class="{{ $v['positive'] ? 'mt-mv-in' : 'mt-mv-out' }}">{{ $v['change'] }}</span></div>
          @empty <p class="rp-empty">Nothing has moved yet.</p> @endforelse
        </div></div>
        <div class="rp-card"><h2>Things To Know</h2><div class="rp-body">
          <div class="rp-li"><span>Orders with wood you don't stock{{ $mt['unmappedNames'] ? ' (' . implode(', ', $mt['unmappedNames']) . ')' : '' }}</span><b>{{ $mt['unmappedOrders'] }}</b></div>
          <div class="rp-li"><span>Orders where the customer supplied the wood</span><b>{{ $mt['customerWoodOrders'] }}</b></div>
          <p class="rp-empty" style="margin-top:8px;">Wood you don't stock is not deducted. Add it as a material so it can be tracked. When the customer supplies the wood, only finish, nails, sandpaper and glue are deducted.</p>
        </div></div>
      </div>
    </div>
    <div class="rp-card" style="margin-top:14px;"><h2>Used And Restocked By Material</h2><div class="rp-body rp-flush">
      @if(! $mt['anyActivity'])
        <p class="rp-empty rp-pad">No materials were used in this period.</p>
      @else
        <table class="rp-mini rp-stack">
          <thead><tr><th>Material</th><th class="r">Used</th><th class="r">Restocked</th><th class="r">Net Change</th><th class="r">Remaining</th></tr></thead>
          <tbody>
            @foreach($mt['table'] as $row)
              <tr>
                <td><b>{{ $row['name'] }}</b></td>
                <td class="r" data-l="Used">{{ \App\Support\MaterialStock::number($row['used']) }} {{ $row['unit'] }}</td>
                <td class="r" data-l="Restocked">{{ \App\Support\MaterialStock::number($row['restocked']) }} {{ $row['unit'] }}</td>
                <td class="r" data-l="Net Change">{{ ($row['net'] > 0 ? '+' : '') . \App\Support\MaterialStock::number($row['net']) }}</td>
                <td class="r" data-l="Remaining">@if($row['short'])<span class="mt-short">{{ $row['remaining'] }}</span>@else{{ $row['remaining'] }}@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div></div>
  </section>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/admin-reports.js') }}"></script>
@endpush
