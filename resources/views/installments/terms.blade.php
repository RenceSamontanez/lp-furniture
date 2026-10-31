@extends('layouts.app')

@section('title', 'Installment Terms — ' . $t['shop_name'])

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/installments.css') }}?v={{ @filemtime(public_path('assets/css/installments.css')) }}"/>
@endpush

@section('content')
@php
  $pct = config('installments.down_payment_percent');
  $grace = config('installments.grace_days');
  $rem = config('installments.reminder_days_before');
  $levels = config('installments.levels');
  $peso = fn ($n) => '₱' . number_format($n);
@endphp
<article class="inst-terms">
  <h1>Installment Terms</h1>
  <p class="meta">Version {{ $version }}. These terms apply when you choose to pay an order in installments at {{ $t['shop_name'] }}.</p>

  <h2>1. Who can use installments</h2>
  <p>Installments are available to customers whose ID has been verified, and only up to the limit of their Installment Standing (section 8). You can always pay an order in full instead.</p>

  <h2>2. The down payment and the schedule</h2>
  <p>{{ $pct }}% of the order total is charged right away as a down payment. The rest is split into equal installments over 1, 2 or 3 months, every 15 or 30 days, as you choose at checkout. The last installment carries any rounding difference so the total is exact. There is no interest and there are no late fees.</p>
  <p>Due dates are counted from the day your down payment is confirmed, not from the day you placed the order.</p>

  <h2>3. Automatic card charges</h2>
  <p>If you pay by card, you allow {{ $t['shop_name'] }} to charge your saved card for each installment on its due date, through our payment provider PayMongo. We never see or store your card number. If you pay with GCash, we send you a notification with a Pay Now button on each due date and you approve each payment in GCash. You can pay any installment early with Pay Now.</p>

  <h2>4. Reminders and receipts</h2>
  <p>We notify you {{ $rem }} days before every due date and send a receipt after every payment.</p>

  <h2>5. When a payment does not go through</h2>
  <p>If a card charge is declined, we try again the next day. If it fails again, we send you a Pay Now link so you can pay with a card or GCash. An installment that is still unpaid {{ $grace }} days after its due date is marked Overdue.</p>

  <h2>6. Paying early and paying the balance</h2>
  <p>You can pay any unpaid installment, or the whole remaining balance, at any time, by card or GCash, at no extra cost.</p>

  <h2>7. Changing your card and stopping automatic charges</h2>
  <p>You can change the card used for your plan from your order page. To ask us to stop automatic charges, contact us at least {{ $t['stop_auto_debit_days'] }} days before the next due date. Stopping automatic charges does not cancel what you owe: you can pay each installment with Pay Now.</p>

  <h2>8. Installment Standing</h2>
  <p>Every customer has an Installment Standing from 0 to 100. New customers start at {{ config('installments.start_score') }}. Paying on time or early raises it, finishing a plan without an Overdue installment raises it, and paying late or becoming Overdue lowers it. It decides which installment plans you can use on future orders:</p>
  <ul>
    @foreach($levels as $l)
      <li><b>{{ $l['name'] }}</b> ({{ $l['min'] }} and above):
        @if($l['months'] === 0) no installments, pay in full only.
        @else up to {{ $l['months'] }} month{{ $l['months'] > 1 ? 's' : '' }}, {{ $l['cap'] ? 'on orders up to ' . $peso($l['cap']) : 'on any order amount' }}.
        @endif
      </li>
    @endforeach
  </ul>
  <p>If you think your standing is wrong, contact us and we will review it.</p>

  <h2>9. Cancellations and refunds</h2>
  <p>{{ $t['refund_policy'] }}</p>

  <h2>10. Contact</h2>
  <p>{{ $t['shop_name'] }}, {{ $t['contact_email'] }}, {{ $t['contact_phone'] }}. {{ $t['contact_hours'] }}.</p>

  <h2>11. Governing law</h2>
  <p>These terms are governed by {{ $t['governing_law'] }}.</p>
</article>
@endsection
