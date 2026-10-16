{{-- Switch between Stripe and GCash for a customized order that is still pending and unpaid. --}}
@php
  $canSwitch = $order->design_agreement_id
      && $order->order_status === 'pending'
      && $order->payment
      && $order->payment->payment_status === 'pending'
      && (float) $order->amount_paid <= 0;
  $current = $order->payment->payment_method ?? null;
  $target = $current === 'stripe' ? 'gcash' : 'stripe';
@endphp
@if($canSwitch)
  <form method="POST" action="{{ route('orders.paymentMethod.update', $order->id) }}" class="auth-info-box" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    @csrf
    <input type="hidden" name="payment_method" value="{{ $target }}"/>
    <span>Prefer {{ $target === 'gcash' ? 'GCash' : 'a card' }}? You can change how you pay until a payment is received.</span>
    <button type="submit" class="btn btn-gold" style="padding:9px 16px;">Switch to {{ $target === 'gcash' ? 'GCash' : 'card (Stripe)' }}</button>
  </form>
@endif
