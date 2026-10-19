@extends('layouts.app')

@section('title', 'Other Services terms — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="terms-page">
  <article class="terms-card">
    <header class="terms-head">
      <h1 class="terms-title">Other Services terms</h1>
      <p class="terms-meta">These terms cover Other Services only: labor when you bring your own wood, repairs, and other work we price by phone. Furniture orders and custom furniture orders have their own <a href="{{ route('terms') }}" class="lp-link">Terms and Conditions</a>.</p>
    </header>

    <div class="terms-body">
      <section class="pt-section">
        <h2 class="pt-heading">How Other Services work</h2>
        <p class="pt-p">You send a service request. Our team calls you, agrees the work and the price with you, and then sends you an invoice. You can pay the invoice online (GCash or card) or in cash. For larger jobs we may offer to split the payment in two halves: the first half to start, and the second half after the service is done.</p>
      </section>

      <section class="pt-section" id="cancellation-and-refunds">
        <h2 class="pt-heading">Cancellation and refunds</h2>
        <ul class="pt-list">
          <li>A refund is possible only if the service has not started. Once our laborer starts the work, the work is non-refundable.</li>
          <li>We mark a service as started when our team records that work has begun. You can see this on your service request.</li>
          <li>To ask for a cancellation or refund, use Luma or the request form on your invoice or service request page. Always keep your request reference number (it looks like REF-2026-0001). It is how you and our team find your request.</li>
          <li>While a request is open, payments on that service are paused until we reply.</li>
          <li>If we approve a refund before the work starts, we return the full amount you paid.</li>
          <li>Refunds are returned by GCash or cash, whichever you choose, even if you paid by card. A refund takes up to 7 days after we approve it.</li>
          <li>If the GCash number you give us is wrong, we will tell you and ask you to send the right one. The 7 days start again from when we can send it.</li>
          <li>If you ask for a refund after the work has started or is done, we review your request individually. We may decline it and will tell you why in writing, or we may approve it as an exception.</li>
          <li>If nothing has been paid and the work has not started, you can cancel at any time and nothing is owed.</li>
        </ul>
      </section>

      <section class="pt-section">
        <h2 class="pt-heading">Questions</h2>
        <p class="pt-p">If anything here is unclear, please ask our team before you pay. We are happy to explain.</p>
      </section>
    </div>
  </article>
</div>
@endsection
