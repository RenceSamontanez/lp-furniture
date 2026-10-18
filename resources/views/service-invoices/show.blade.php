@extends('layouts.app')

@section('title', 'Invoice ' . $invoice->invoice_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-invoice-pay.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $i     = $invoice;
  $peso  = fn ($n) => '₱' . number_format((float) $n, 2);
  $day   = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $state = $i->paymentStateLabel();
  $plan  = \App\Support\InvoicePaymentRules::PLANS[$i->payment_plan] ?? \App\Support\InvoicePaymentRules::PLANS['full'];
  $paidRows = $i->payments->where('status', 'paid');
  $online   = $due['installment'] !== null ? \App\Support\ServiceInvoicePayments::onlineProblem($due['cents']) : null;
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card sx-main">
      <span class="sx-badge sx-badge--{{ strtolower(str_replace(' ', '-', $state)) }}" role="status">{{ $state }}</span>
      <h1 class="sx-title">Invoice {{ $i->invoice_number }}</h1>
      <p class="sx-lead">{{ $i->service_description }}</p>

      <dl class="sx-facts">
        <div><dt>Your service request</dt><dd>{{ $ticket?->reference_number }}@if($ticket) · {{ $ticket->serviceLabel() }}@endif</dd></div>
        <div><dt>Date sent</dt><dd>{{ $day($i->issued_at) }}</dd></div>
        <div><dt>Total</dt><dd><strong>{{ $peso($i->total) }}</strong></dd></div>
        <div><dt>Payment plan</dt><dd>{{ $plan }}</dd></div>
        <div><dt>Paid so far</dt><dd>{{ $peso($i->amount_paid) }}</dd></div>
        @if($i->status !== 'paid')
          <div><dt>Balance</dt><dd>{{ $peso($i->balanceCents() / 100) }}</dd></div>
        @endif
      </dl>

      <h2 class="sx-h2">What this invoice covers</h2>
      <table class="ip-lines">
        <tbody>
          @foreach($i->lines as $line)
            <tr><td>{{ $line->description }}</td><td>{{ $peso($line->amount) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot><tr><td>Total</td><td>{{ $peso($i->total) }}</td></tr></tfoot>
      </table>

      <h2 class="sx-h2" id="pay-heading">Payment</h2>
      <section class="ip-pay" aria-labelledby="pay-heading">
        @if($i->status === 'paid')
          <p class="ip-pay-state"><strong>Paid in full.</strong> Amount received: {{ $peso($i->amount_received ?? $i->total) }}@if($i->paid_at) on {{ $day($i->paid_at) }}@endif. Thank you.</p>
        @else
          @if($i->isHalfHalf() && $i->paidCents() > 0)
            <p class="ip-pay-state"><strong>Half paid.</strong> The balance is due after the service.</p>
          @endif

          @if($due['available'])
            <p class="ip-due"><span>{{ $due['label'] }}</span> <strong>{{ $peso($due['cents'] / 100) }}</strong></p>
            @if($online)
              <p class="ip-hint" role="note">{{ $online }}</p>
            @endif
            <div class="ip-actions">
              <form method="POST" action="{{ route('services.invoice.pay', [$i->id, 'gcash']) }}" class="ip-form">
                @csrf
                <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" data-pay @disabled($online)>Pay with GCash</button>
              </form>
              <form method="POST" action="{{ route('services.invoice.pay', [$i->id, 'stripe']) }}" class="ip-form">
                @csrf
                <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" data-pay @disabled($online)>Pay with card</button>
              </form>
            </div>
            <p class="ip-hint">You can also pay in cash in person. Our team will record it on your invoice.</p>
          @else
            <p class="ip-due"><span>{{ $due['label'] }}</span> <strong>{{ $peso($due['cents'] / 100) }}</strong></p>
            <div class="ip-actions ip-actions--one">
              <button type="button" class="lp-btn lp-btn--primary lp-btn--block" disabled aria-describedby="ip-wait">{{ $due['label'] }}</button>
            </div>
            <p class="ip-hint" id="ip-wait">{{ $due['reason'] }}</p>
          @endif
        @endif

        @if($paidRows->isNotEmpty())
          <h3 class="ip-sub">Payments received</h3>
          <ul class="ip-payments">
            @foreach($paidRows as $p)
              <li><span>{{ $p->paid_at?->timezone('Asia/Manila')->format('M j, Y') }} · {{ $p->methodLabel() }}@if($i->isHalfHalf()) · {{ $p->installment === 1 ? 'first half' : 'remaining balance' }}@endif</span> <strong>{{ $peso($p->amount) }}</strong></li>
            @endforeach
          </ul>
        @endif
      </section>

      <div class="ip-links">
        <a href="{{ route('services.invoice.print', $i->id) }}" class="lp-btn lp-btn--secondary" target="_blank" rel="noopener">View or print invoice</a>
        <a href="{{ route('services.request') }}" class="lp-btn lp-btn--secondary">Back to my service request</a>
      </div>
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
<script>
  // One tap, one payment: after the first tap the buttons wait, so a double click cannot start two payments.
  document.querySelectorAll('form.ip-form').forEach(function (form) {
    form.addEventListener('submit', function () {
      document.querySelectorAll('[data-pay]').forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
    });
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { document.querySelectorAll('[data-pay]').forEach(function (b) { b.disabled = false; }); } });
</script>
@endsection
