{{-- Every agreed term, rendered from a terms array (AgreementTerms::fromQuote
     or a stored terms_snapshot). Used by the review page, the agreement page,
     the print view and the admin view. Optional: $paymentDue (Y-m-d H:i:s, Manila). --}}
@php
  $fmt = fn ($s, $f = 'M d, Y h:i A') => \Illuminate\Support\Carbon::parse($s, 'Asia/Manila')->format($f);
  $dim = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $q = $terms['quote']; $m = $terms['money']; $d = $terms['delivery']; $p = $terms['production'];
@endphp
<dl class="co-quote-list">
  <div><dt>Design</dt><dd>{{ $terms['design']['title'] }}</dd></div>
  @if(!empty($terms['design']['wood']['label']))
    <div><dt>Wood</dt><dd>{{ $terms['design']['wood']['label'] }}</dd></div>
  @endif
  @if(!empty($terms['design']['furniture_type']))
    <div><dt>Furniture type</dt><dd>{{ $terms['design']['furniture_type'] }}</dd></div>
  @endif
  <div><dt>Description</dt><dd style="white-space:pre-line;">{{ $q['description'] }}</dd></div>
  <div><dt>Size (length x width x height)</dt><dd>{{ \App\Support\Dimensions::triplet($q['width_cm'], $q['depth_cm'], $q['height_cm'], $q['dimension_unit'] ?? null) }}</dd></div>
  <div><dt>Material and finish</dt><dd>{{ $q['material'] }}@if($q['finish']), {{ $q['finish'] }}@endif</dd></div>
  @if($q['modifications'])
    <div><dt>Modifications</dt><dd style="white-space:pre-line;">{{ $q['modifications'] }}</dd></div>
  @endif
  <div><dt>{{ $d['option'] === 'delivery' ? 'Delivery to' : 'Pickup' }}</dt><dd>{{ $d['option'] === 'delivery' ? $d['address'] : 'Pick up at our shop' }}</dd></div>
  <div><dt>Production time</dt><dd>{{ $p['days'] }} working days (Monday to Saturday), ready about {{ $fmt($p['ready_about'], 'M d, Y') }}, counted from {{ $fmt($p['counted_from'], 'M d, Y') }}</dd></div>
  <div><dt>Price</dt><dd>{{ $peso($m['price']) }}</dd></div>
  @if($d['option'] === 'delivery')
    <div><dt>Delivery fee</dt><dd>{{ $peso($m['delivery_fee']) }}</dd></div>
  @endif
  <div class="co-quote-total"><dt>Total</dt><dd>{{ $peso($m['total']) }}</dd></div>
  <div>
    <dt>{{ $m['payment_terms'] === 'deposit' ? 'Deposit due first' : 'Payment' }}</dt>
    <dd>{{ $peso($m['upfront']) }}{{ $m['payment_terms'] === 'full' ? ', paid in full' : '' }}</dd>
  </div>
  @if(!empty($terms['schedule']))
    <div>
      <dt>Balance</dt>
      <dd>
        {{ $peso($m['balance']) }} in {{ count($terms['schedule']) }} payments:
        <ul class="co-schedule">
          @foreach($terms['schedule'] as $row)
            <li>Payment {{ $row['no'] }}: {{ $peso($row['amount']) }}, {{ $row['day_offset'] }} days after your first payment</li>
          @endforeach
        </ul>
      </dd>
    </div>
  @endif
  @if($showVersions ?? false)
    <div><dt>Quote version</dt><dd>Version {{ $q['version'] }}, sent {{ $fmt($q['sent_at']) }}, valid until {{ $fmt($q['expires_at']) }}</dd></div>
  @else
    <div><dt>Quote sent</dt><dd>{{ $fmt($q['sent_at']) }}, valid until {{ $fmt($q['expires_at']) }}</dd></div>
  @endif
  @isset($paymentDue)
    <div><dt>Payment must be started by</dt><dd>{{ $fmt($paymentDue) }}</dd></div>
  @endisset
</dl>

<div class="co-policy">
  <div class="co-policy-title" id="policy-title">{{ $terms['policy']['title'] }}@if(($showVersions ?? false) || ($showPolicyVersion ?? false)) (version {{ $terms['policy']['version'] }})@endif</div>
  <div class="co-policy-body" tabindex="0" role="region" aria-labelledby="policy-title">@include('agreements._policy', ['text' => $terms['policy']['text']])</div>
</div>
