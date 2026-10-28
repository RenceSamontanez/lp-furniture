@php
  $i = $invoice;
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $day = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $phone = \App\Support\Luma\Contact::settingsPhone();
  $email = \App\Support\Luma\Contact::settingsEmail();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="robots" content="noindex"/>
<title>Invoice {{ $i->invoice_number ?: 'draft ' . $i->id }}</title>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/css/service-invoice-print.css') }}"/>
</head>
<body class="iv-body">
  <div class="iv-toolbar">
    <a href="{{ $backUrl }}" class="lp-btn lp-btn--secondary">Back</a>
    <button type="button" class="lp-btn lp-btn--primary" onclick="window.print()">Print</button>
  </div>

  <main class="iv-sheet">
    @if($i->status === 'void')<div class="iv-mark">Cancelled</div>@elseif($i->status === 'draft')<div class="iv-mark">Draft</div>@endif

    <header class="iv-head">
      <div>
        <div class="iv-brand">LP Furniture</div>
        <div class="iv-contact">
          @if($phone || $email)
            @if($phone){{ $phone }}@endif @if($phone && $email)<span aria-hidden="true">/</span>@endif @if($email){{ $email }}@endif
          @else
            Contact us through our website.
          @endif
        </div>
      </div>
      <div class="iv-title">
        <h1>Invoice</h1>
        <div class="iv-number">{{ $i->invoice_number ?: 'Not yet sent' }}</div>
      </div>
    </header>

    <section class="iv-meta">
      <div>
        <h2>Billed to</h2>
        <p><strong>{{ $i->customer_name }}</strong></p>
        <p>{{ $i->customer_phone }}</p>
        @if($i->customer_email)<p>{{ $i->customer_email }}</p>@endif
        @if($i->customer_address)<p class="iv-pre">{{ $i->customer_address }}</p>@endif
      </div>
      <dl>
        <div><dt>Date sent</dt><dd>{{ $day($i->issued_at) ?? 'Not yet sent' }}</dd></div>
        <div><dt>Service request</dt><dd>{{ $i->inquiry?->reference_number }}</dd></div>
        <div>
          <dt>Status</dt>
          <dd>
            @if($i->status === 'void') Cancelled
            @elseif($i->status === 'draft') Draft
            @else {{ $i->paymentStateLabel() }} @endif
          </dd>
        </div>
      </dl>
    </section>

    <section class="iv-service">
      <h2>Service availed</h2>
      <p>{{ $i->service_description }}</p>
    </section>

    <table class="iv-lines">
      <thead><tr><th>Description</th><th>Amount</th></tr></thead>
      <tbody>
        @foreach($i->lines as $line)
          <tr><td>{{ $line->description }}</td><td>{{ $peso($line->amount) }}</td></tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr><td>Total</td><td>{{ $peso($i->total) }}</td></tr>
      </tfoot>
    </table>

    <p class="iv-delivery">Delivery: {{ $i->deliveryLabel() }}</p>

    @if($i->notes)
      <section class="iv-note"><h2>Note</h2><p class="iv-pre">{{ $i->notes }}</p></section>
    @endif

    <section class="iv-pay">
      <h2>Payment</h2>
      @php $paidRows = $i->payments()->where('status', 'paid')->get(); @endphp
      @if($i->status === 'void')
        <p>This invoice was cancelled on {{ $day($i->voided_at) }} and is no longer valid.</p>
      @elseif($i->status === 'paid')
        <p><strong>Paid</strong>@if($i->paid_at) on {{ $day($i->paid_at) }}@endif @if($i->paidMethodLabel()) by {{ $i->paidMethodLabel() }}@endif.</p>
        <p>Amount received: {{ $peso($i->amount_received ?? $i->total) }}@if($i->paid_reference && $paidRows->isEmpty()) · Reference: {{ $i->paid_reference }}@endif</p>
      @elseif($i->status === 'issued')
        <p><strong>{{ $i->paymentStateLabel() }}</strong>@if($i->paidCents() > 0). Paid so far: {{ $peso($i->amount_paid) }}. Balance: {{ $peso($i->balanceCents() / 100) }}@else. Pay online (GCash or card) from your invoice page in your account, or pay in cash in person.@endif</p>
      @else
        <p>Not yet sent.</p>
      @endif
      @if($paidRows->isNotEmpty())
        @foreach($paidRows as $p)
          <p class="iv-payline">{{ $p->paid_at?->timezone('Asia/Manila')->format('M j, Y') }} · {{ $p->methodLabel() }}@if($i->isHalfHalf()) · {{ $p->installment === 1 ? 'first half' : 'remaining balance' }}@endif: {{ $peso($p->amount) }}</p>
        @endforeach
      @endif
    </section>

    <footer class="iv-foot">{{ config('invoice.footer') }}</footer>
  </main>
</body>
</html>
