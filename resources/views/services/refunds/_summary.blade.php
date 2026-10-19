{{-- Sticky summary card for the cancellation flow: invoice, paid amount, work state, the rule, the refund timeline.
     Needs $ticket and $ctx. Collapsed on phones (summary first), open on desktop. --}}
@php
  $paid    = $ctx['paid_cents'] > 0;
  $work    = $ctx['work'];
  $started = $work !== 'not_started';
  $invoice = $ctx['invoice'];
  $peso    = fn ($n) => '₱' . number_format((float) $n, 2);
  $days    = \App\Models\ServiceRefundRequest::REFUND_DAYS;
@endphp
<aside class="rq-sum" aria-label="Summary">
  <details class="rq-sum__details" id="rq-sum" open>
    <summary class="rq-sum__head"><span>Your service request</span><span class="rq-sum__ref">{{ $ticket->reference_number }}</span></summary>
    <dl class="rq-sum__facts">
      <div><dt>Service</dt><dd>{{ $ticket->serviceLabel() }}</dd></div>
      @if($invoice)<div><dt>Invoice</dt><dd>{{ $invoice->invoice_number }} · {{ $peso($invoice->total) }}</dd></div>@endif
      <div><dt>You paid</dt><dd><strong>{{ $peso($ctx['paid_cents'] / 100) }}</strong></dd></div>
      <div><dt>Work</dt><dd>{{ \App\Support\ServiceWorkState::label($work) }}</dd></div>
    </dl>

    @if($started)
      <x-notice variant="blocked" title="Work has already started">Refunds are usually not possible once work starts. Our team will still review your request.</x-notice>
    @elseif($paid)
      <x-notice variant="info" title="Refunds are for the full amount">Work has not started, so you can get back the full {{ $peso($ctx['paid_cents'] / 100) }} by GCash or cash.</x-notice>
    @else
      <x-notice variant="info" title="Cancelled right away">Nothing was paid and work has not started, so there is nothing to refund.</x-notice>
    @endif

    @if($paid)
      <ol class="rq-timeline" aria-label="What happens next">
        <li><strong>Sent</strong><span>You send this request today.</span></li>
        <li><strong>Under review</strong><span>We reply, usually within a day.</span></li>
        <li><strong>Refund</strong><span>Within {{ $days }} days after approval.</span></li>
      </ol>
    @endif
  </details>
</aside>
