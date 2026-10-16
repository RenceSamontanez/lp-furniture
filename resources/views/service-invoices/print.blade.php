@php
  $i = $invoice;
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $day = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $phone = \App\Support\Luma\Contact::phone();
  $email = \App\Support\Luma\Contact::email();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="robots" content="noindex"/>
<title>Invoice {{ $i->invoice_number ?: 'draft ' . $i->id }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/service-invoice-print.css') }}"/>
</head>
<body class="iv-body">
  <div class="iv-toolbar">
    <a href="{{ $backUrl }}">Back</a>
    <button type="button" onclick="window.print()">Print</button>
  </div>

  <main class="iv-sheet">
    @if($i->status === 'void')<div class="iv-mark">Void</div>@elseif($i->status === 'draft')<div class="iv-mark">Draft</div>@endif

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
        <div class="iv-number">{{ $i->invoice_number ?: 'Not yet issued' }}</div>
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
        <div><dt>Date issued</dt><dd>{{ $day($i->issued_at) ?? 'Not yet issued' }}</dd></div>
        <div><dt>Service request</dt><dd>{{ $i->inquiry?->reference_number }}</dd></div>
        <div>
          <dt>Status</dt>
          <dd>
            @if($i->status === 'paid') Paid
            @elseif($i->status === 'void') Void
            @elseif($i->status === 'issued') Unpaid
            @else Draft @endif
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
      @if($i->status === 'paid')
        <p><strong>Paid</strong> on {{ $day($i->paid_at) }} by {{ $i->paidMethodLabel() }}.</p>
        <p>Amount received: {{ $peso($i->amount_received) }}@if($i->paid_reference) · Reference: {{ $i->paid_reference }}@endif</p>
      @elseif($i->status === 'void')
        <p>This invoice was voided on {{ $day($i->voided_at) }} and is no longer valid.</p>
      @else
        <p>Not yet paid. Please contact us to arrange payment.</p>
      @endif
    </section>

    <footer class="iv-foot">{{ config('invoice.footer') }}</footer>
  </main>
</body>
</html>
