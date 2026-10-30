@extends('layouts.admin')

@section('title', 'Customer Details — Admin')
@section('page-title', $customer->first_name . ' ' . $customer->last_name)

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-customers.css') }}"/>
@endpush

@php
  use App\Support\AdminCustomers as AC;
  $s = $p['stats'];
  $money = fn ($n) => '₱' . number_format((float) $n, 2);
  $tabs = [
    'orders'   => ['Orders', $s['orders']],
    'designs'  => ['Designs', $s['designs']],
    'activity' => ['Activity', null],
    'names'    => ['Name Changes', $p['nameChanges']->count()],
  ];
@endphp

@section('content')
<div class="cp">
  <p class="cp-back"><a href="{{ route('admin.customers.index', $back) }}" class="lp-btn lp-btn--secondary cu-small">Back to Customers</a></p>

  <div class="cp-card cp-head">
    <div class="cp-av" aria-hidden="true">{{ AC::initials($customer) }}</div>
    <div class="cp-who">
      <h2 class="cp-name">{{ $customer->first_name }} {{ $customer->last_name }}</h2>
      <div class="cp-meta">
        <span class="cu-vchip {{ $customer->is_validated ? 'is-v' : 'is-u' }}">{{ $customer->is_validated ? 'Verified' : 'Unverified' }}</span>
        <span>Last activity {{ AC::when($p['lastActivity']) }}</span>
      </div>
    </div>
    <span class="cp-grow"></span>
    @if($p['pendingValidationId'])
      <a href="{{ route('admin.validations.show', $p['pendingValidationId']) }}" class="lp-btn lp-btn--primary">Review Identity Validation</a>
    @endif
  </div>

  <div class="cp-tiles">
    <div class="cp-tile"><b>{{ $s['orders'] }}</b><span>Total Orders</span></div>
    <div class="cp-tile"><b>{{ $money($s['spent']) }}</b><span>Total Spent</span></div>
    <div class="cp-tile"><b>{{ $s['designs'] }}</b><span>Designs Made</span></div>
    <div class="cp-tile"><b>{{ $money($s['average']) }}</b><span>Average Order</span></div>
  </div>

  <div class="cp-grid">
    <div class="cp-card">
      <div class="cp-tabs" role="tablist" aria-label="Customer details">
        @foreach($tabs as $key => [$label, $count])
          <button type="button" class="cp-tab" role="tab" id="cp-tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="cp-pane-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" tabindex="{{ $tab === $key ? 0 : -1 }}">{{ $label }}@if($count !== null)<i>{{ $count }}</i>@endif</button>
        @endforeach
      </div>

      {{-- Orders --}}
      <div class="cp-pane" id="cp-pane-orders" data-pane="orders" role="tabpanel" aria-labelledby="cp-tab-orders" @if($tab !== 'orders') hidden @endif>
        @if(empty($p['orders']))
          <div class="cp-empty"><b>No orders yet</b>Orders will show here once this customer buys.</div>
        @else
          <table class="cp-table cp-stk">
            <thead><tr><th>Order</th><th>Item</th><th class="r">Total</th><th>Status</th><th>Payment</th></tr></thead>
            <tbody>
              @foreach($p['orders'] as $o)
                <tr>
                  <td><a class="cp-num" href="{{ route('admin.orders.show', $o['id']) }}">{{ $o['number'] }}</a><span class="cp-soft">{{ $o['date']->format('M j, Y') }}</span></td>
                  <td data-label="Item">{{ $o['item'] }}</td>
                  <td data-label="Total" class="r">{{ $money($o['total']) }}</td>
                  <td data-label="Status"><span class="cp-pst is-{{ $o['status'] }}">@titlecase($o['statusLabel'])</span></td>
                  <td data-label="Payment"><span class="cp-pst is-{{ $o['pay'] }}">{{ ucfirst($o['pay']) }}</span>@if($o['paid'] > 0 && $o['pay'] !== 'paid')<span class="cp-soft">{{ $money($o['paid']) }} received</span>@endif</td>
                </tr>
              @endforeach
            </tbody>
          </table>
          <div class="cp-foot">Showing the latest {{ count($p['orders']) }} of {{ $s['orders'] }} {{ $s['orders'] === 1 ? 'order' : 'orders' }}.</div>
        @endif
      </div>

      {{-- Designs --}}
      <div class="cp-pane" id="cp-pane-designs" data-pane="designs" role="tabpanel" aria-labelledby="cp-tab-designs" @if($tab !== 'designs') hidden @endif>
        @if(empty($p['designs']))
          <div class="cp-empty"><b>No designs yet</b>Designs from the Customize page will show here.</div>
        @else
          <div class="cp-dgrid">
            @foreach($p['designs'] as $d)
              @php $tag = $d['link'] ? 'a' : 'div'; @endphp
              <{{ $tag }} class="cp-dz" @if($d['link']) href="{{ $d['link'] }}" @endif>
                <div class="im">@if($d['image'])<img src="{{ $d['image'] }}" alt="" loading="lazy"/>@else{{ mb_strtoupper(mb_substr($d['title'], 0, 1)) }}@endif</div>
                <div class="tx">
                  <b>{{ $d['title'] }}</b>
                  <span>{{ $d['date']->format('M j, Y') }}@if($d['pictures'] > 0) · {{ $d['pictures'] }} {{ $d['pictures'] === 1 ? 'picture' : 'pictures' }}@endif</span>
                  <span class="cp-pst is-{{ $d['tone'] }}">@titlecase($d['status'])</span>
                </div>
              </{{ $tag }}>
            @endforeach
          </div>
          @if($p['designsMore'] > 0)<div class="cp-foot">Showing the latest {{ count($p['designs']) }} of {{ $s['designs'] }} designs.</div>@endif
        @endif
      </div>

      {{-- Activity: built only from records that already exist --}}
      <div class="cp-pane" id="cp-pane-activity" data-pane="activity" role="tabpanel" aria-labelledby="cp-tab-activity" @if($tab !== 'activity') hidden @endif>
        <ul class="cp-tl">
          @foreach($p['activity'] as $a)
            <li>@if($a['url'])<a href="{{ $a['url'] }}">{{ $a['text'] }}</a>@else{{ $a['text'] }}@endif<span>{{ AC::when($a['at']) }}</span></li>
          @endforeach
        </ul>
        <div class="cp-foot">Built from this customer's orders, payments, designs, Luma chats, identity checks and name changes. Sign-ins are not recorded.</div>
      </div>

      {{-- Name changes --}}
      <div class="cp-pane" id="cp-pane-names" data-pane="names" role="tabpanel" aria-labelledby="cp-tab-names" @if($tab !== 'names') hidden @endif>
        @if($p['nameChanges']->isEmpty())
          <div class="cp-empty"><b>No name changes</b>This customer has not changed their name.</div>
        @else
          <p class="cp-note">Changing a name does not reset identity verification. Check that the new name still matches the ID photo.</p>
          <table class="cp-table cp-namechg cp-stk">
            <thead><tr><th>When</th><th>Old Name</th><th>New Name</th><th>Verified Then</th></tr></thead>
            <tbody>
              @foreach($p['nameChanges'] as $c)
                <tr>
                  <td>{{ $c->created_at->format('M j, Y g:i A') }}</td>
                  <td data-label="Old Name">{{ $c->oldName() }}</td>
                  <td data-label="New Name">{{ $c->newName() }}</td>
                  <td data-label="Verified Then">{{ $c->was_verified ? 'Yes' : 'No' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

    <div class="cp-side">
      <div class="cp-card">
        <h2>Contact</h2>
        <dl class="cp-kvs">
          <div class="cp-kv"><dt>Phone</dt><dd @if(!$customer->phone_number) class="is-none" @endif>{{ $customer->phone_number ?: 'No phone on file' }}</dd></div>
          <div class="cp-kv"><dt>Email</dt><dd>{{ $customer->email }}</dd></div>
          <div class="cp-kv"><dt>Location</dt><dd @if(!$p['location']) class="is-none" @endif>{{ $p['location'] ?: 'Not provided yet' }}</dd></div>
          <div class="cp-kv"><dt>Saved Address</dt><dd @if(!$p['address']) class="is-none" @endif>{{ $p['address'] ?: 'No address saved' }}</dd></div>
        </dl>
      </div>
      <div class="cp-card">
        <h2>Account</h2>
        <dl class="cp-kvs">
          <div class="cp-kv"><dt>Email Verified</dt><dd><span class="cu-vchip {{ $customer->email_verified_at ? 'is-v' : 'is-u' }}">{{ $customer->email_verified_at ? 'Yes' : 'No' }}</span></dd></div>
          <div class="cp-kv"><dt>Identity</dt><dd>{{ $p['identity']['label'] }}@if($p['identity']['date'])<span class="cp-soft">{{ $p['identity']['date']->format('M j, Y') }}</span>@endif</dd></div>
          <div class="cp-kv"><dt>Joined</dt><dd>{{ $customer->created_at->format('M j, Y') }}</dd></div>
          <div class="cp-kv"><dt>Last Activity</dt><dd>{{ AC::when($p['lastActivity']) }}</dd></div>
        </dl>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/admin-customers.js') }}"></script>
@endpush
