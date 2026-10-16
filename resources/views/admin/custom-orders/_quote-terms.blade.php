{{-- Read-only terms of one quote. Admin-only: includes internal call notes. --}}
@php $summary = \App\Support\QuoteTerms::summary($quote); @endphp
<dl class="co-dl">
  <div><dt>Size (length x width x height)</dt><dd>{{ \App\Support\Dimensions::triplet($quote->width_cm, $quote->depth_cm, $quote->height_cm, $quote->request->dimension_unit ?? null) }}</dd></div>
  <div><dt>Material / finish</dt><dd>{{ $quote->material }}@if($quote->finish) / {{ $quote->finish }}@endif</dd></div>
  <div><dt>Production</dt><dd>{{ $quote->production_days }} working days</dd></div>
  <div><dt>Delivery</dt><dd>{{ $quote->delivery_option === 'delivery' ? 'Delivery' : 'Pickup' }}</dd></div>
  @if($quote->delivery_option === 'delivery')
    <div class="co-span-2"><dt>Delivery address</dt><dd>{{ $quote->delivery_address }}</dd></div>
  @endif
</dl>

<div class="co-block-label">Description</div>
<div class="co-quote">{{ $quote->final_description }}</div>
@if($quote->modifications)
  <div class="co-block-label">Modifications</div>
  <div class="co-quote">{{ $quote->modifications }}</div>
@endif

<div class="co-block-label">Money</div>
<dl class="co-money">
  <div><dt>Price</dt><dd>₱{{ number_format($quote->price, 2) }}</dd></div>
  <div><dt>Delivery fee</dt><dd>₱{{ number_format($quote->delivery_fee, 2) }}</dd></div>
  <div class="co-money-total"><dt>Total</dt><dd>₱{{ number_format($summary['total'], 2) }}</dd></div>
  <div><dt>Due up front</dt><dd>₱{{ number_format($summary['upfront'], 2) }}{{ $quote->payment_terms === 'full' ? ' (in full)' : ' (deposit)' }}</dd></div>
  @if($summary['balance'] > 0)<div><dt>Balance</dt><dd>₱{{ number_format($summary['balance'], 2) }}</dd></div>@endif
</dl>
@if($summary['schedule'])
  <ul class="co-log" style="margin-top:8px;">
    @foreach($summary['schedule'] as $row)
      <li>#{{ $row['no'] }} · ₱{{ number_format($row['amount'], 2) }} · day {{ $row['day_offset'] }} after the deposit</li>
    @endforeach
  </ul>
@endif

@if($quote->call_notes)
  <div class="co-block-label">Internal call notes (not shown to the customer)</div>
  <div class="co-quote">{{ $quote->call_notes }}</div>
@endif
