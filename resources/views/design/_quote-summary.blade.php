{{-- Customer-facing, read-only quote summary. Explicit fields only:
     internal call notes and admin data are never rendered here. --}}
@php
  $sum = \App\Support\QuoteTerms::summary($quote);
  $dim = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
  $isSent = $quote->status === 'sent';
@endphp
<div class="co-quote-card">
  <div class="co-status-title">
    @if($isSent)
      Your quote is ready (version {{ $quote->version }})
    @else
      This quote expired on {{ $quote->expires_at->timezone('Asia/Manila')->format('M d, Y') }}
    @endif
  </div>

  <dl class="co-quote-list">
    <div><dt>Description</dt><dd style="white-space:pre-line;">{{ $quote->final_description }}</dd></div>
    <div><dt>Size</dt><dd>{{ $dim($quote->width_cm) }} x {{ $dim($quote->depth_cm) }} x {{ $dim($quote->height_cm) }} cm</dd></div>
    <div><dt>Material</dt><dd>{{ $quote->material }}@if($quote->finish), {{ $quote->finish }}@endif</dd></div>
    @if($quote->modifications)<div><dt>Modifications</dt><dd style="white-space:pre-line;">{{ $quote->modifications }}</dd></div>@endif
    <div><dt>Production time</dt><dd>{{ $quote->production_days }} working days</dd></div>
    <div><dt>{{ $quote->delivery_option === 'delivery' ? 'Delivery to' : 'Pickup' }}</dt><dd>{{ $quote->delivery_option === 'delivery' ? $quote->delivery_address : 'Pick up at our shop' }}</dd></div>
    <div><dt>Price</dt><dd>₱{{ number_format($quote->price, 2) }}</dd></div>
    @if($quote->delivery_option === 'delivery')
      <div><dt>Delivery fee</dt><dd>₱{{ number_format($quote->delivery_fee, 2) }}</dd></div>
    @endif
    <div class="co-quote-total"><dt>Total</dt><dd>₱{{ number_format($sum['total'], 2) }}</dd></div>
    <div><dt>{{ $quote->payment_terms === 'deposit' ? 'Deposit due first' : 'Payment' }}</dt><dd>₱{{ number_format($sum['upfront'], 2) }}{{ $quote->payment_terms === 'full' ? ', paid in full' : '' }}</dd></div>
    @if($sum['schedule'])
      <div><dt>Balance</dt><dd>₱{{ number_format($sum['balance'], 2) }} in {{ count($sum['schedule']) }} payments, one every {{ $quote->installment_frequency_days }} days</dd></div>
    @endif
  </dl>

  @if($isSent)
    <div class="co-status-text">
      Valid until {{ $quote->expires_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
      Review and accept is coming next.
    </div>
  @else
    <div class="co-status-text">Contact us if you'd still like to proceed.</div>
  @endif
</div>
