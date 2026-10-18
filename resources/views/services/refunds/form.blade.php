@extends('layouts.app')

@section('title', 'Request cancellation or refund — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $paid     = $ctx['paid_cents'] > 0;
  $work     = $ctx['work'];
  $started  = $work !== 'not_started';
  $invoice  = $ctx['invoice'];
  $peso     = fn ($n) => '₱' . number_format((float) $n, 2);
  $instant  = !$paid && !$started;
  $method   = old('refund_method');
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card rq-main">
      <h1 class="rq-title">{{ $instant ? 'Cancel this service request' : 'Request cancellation or refund' }}</h1>
      <p class="rq-lead">
        @if($instant)
          Nothing has been paid and the work has not started, so we can cancel it for you right away.
        @else
          Tell us why, and our team will review your request and reply to you. Payments on this service are paused while we review.
        @endif
      </p>

      <dl class="sx-facts">
        <div><dt>Your service request</dt><dd>{{ $ticket->reference_number }} · {{ $ticket->serviceLabel() }}</dd></div>
        @if($invoice)<div><dt>Invoice</dt><dd>{{ $invoice->invoice_number }} · {{ $peso($invoice->total) }}</dd></div>@endif
        <div><dt>Paid so far</dt><dd><strong>{{ $peso($ctx['paid_cents'] / 100) }}</strong></dd></div>
        <div><dt>Work</dt><dd>{{ \App\Support\ServiceWorkState::label($work) }}</dd></div>
      </dl>

      @if($started)
        <x-notice variant="problem" title="Work has already started">
          Work has already started on this service. Under our terms, refunds are usually not possible once work starts. You can still send your request and our team will review it and reply to you.
        </x-notice>
      @elseif($paid)
        <x-notice variant="info" title="You can get back the full amount you paid">
          Work has not started, so if we approve your request you get back {{ $peso($ctx['paid_cents'] / 100) }}. A refund takes up to {{ \App\Models\ServiceRefundRequest::REFUND_DAYS }} days after approval.
        </x-notice>
      @endif

      @if($errors->any())
        <div class="co-errors" role="alert">
          @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
      @endif

      <form method="POST" action="{{ route('services.refund.review', $ticket->id) }}" id="rq-form" novalidate>
        @csrf

        <fieldset class="rq-fieldset">
          <legend class="rq-legend">Why do you want to cancel?</legend>
          <div class="rq-field">
            <label class="form-label" for="rq-reason">Reason</label>
            <select name="reason_code" id="rq-reason" class="form-input" required>
              <option value="">Choose a reason</option>
              @foreach(\App\Models\ServiceRefundRequest::REASONS as $k => $label)
                <option value="{{ $k }}" @selected(old('reason_code') === $k)>{{ $label }}</option>
              @endforeach
            </select>
            @error('reason_code')<p class="rq-error">{{ $message }}</p>@enderror
          </div>
          <div class="rq-field">
            <label class="form-label" for="rq-details">Details (optional)</label>
            <textarea name="reason_text" id="rq-details" class="form-input" rows="3" maxlength="1000">{{ old('reason_text') }}</textarea>
          </div>
        </fieldset>

        <fieldset class="rq-fieldset">
          <legend class="rq-legend">How can we reach you?</legend>
          <div class="rq-field">
            <label class="form-label" for="rq-phone">Mobile number</label>
            <input type="tel" name="contact_phone" id="rq-phone" class="form-input" required autocomplete="tel" inputmode="tel" value="{{ old('contact_phone', $defaults['phone']) }}"/>
            @error('contact_phone')<p class="rq-error">{{ $message }}</p>@enderror
          </div>
          <div class="rq-field">
            <label class="form-label" for="rq-email">Email</label>
            <input type="email" name="contact_email" id="rq-email" class="form-input" required autocomplete="email" value="{{ old('contact_email', $defaults['email']) }}"/>
            @error('contact_email')<p class="rq-error">{{ $message }}</p>@enderror
          </div>
        </fieldset>

        @if($paid)
        <fieldset class="rq-fieldset">
          <legend class="rq-legend">Where should we send your refund?</legend>
          <p class="rq-hint" style="margin:0 0 10px;">Refunds are returned by GCash or cash, even if you paid by card.</p>
          <div class="rq-radios" role="radiogroup" aria-label="Refund method">
            @foreach(\App\Models\ServiceRefundRequest::METHODS as $k => $label)
              <label class="rq-radio"><input type="radio" name="refund_method" value="{{ $k }}" required @checked($method === $k)/> <span>{{ $label }}{{ $k === 'cash' ? ' (you pick it up from us)' : '' }}</span></label>
            @endforeach
          </div>
          @error('refund_method')<p class="rq-error">{{ $message }}</p>@enderror

          <div class="rq-gcash" id="rq-gcash" @if($method !== 'gcash') hidden @endif>
            <div class="rq-field" style="margin-top:14px;">
              <label class="form-label" for="rq-gnum">GCash number</label>
              <input type="tel" name="gcash_number" id="rq-gnum" class="form-input" inputmode="tel" autocomplete="off" placeholder="09171234567" value="{{ old('gcash_number') }}"/>
              @error('gcash_number')<p class="rq-error">{{ $message }}</p>@enderror
            </div>
            <div class="rq-field">
              <label class="form-label" for="rq-gname">Name on the GCash account</label>
              <input type="text" name="gcash_account_name" id="rq-gname" class="form-input" autocomplete="off" value="{{ old('gcash_account_name') }}"/>
              @error('gcash_account_name')<p class="rq-error">{{ $message }}</p>@enderror
            </div>
            <p class="rq-hint">Please check the number carefully. A wrong number delays your refund.</p>
          </div>
        </fieldset>
        @endif

        <div class="rq-field">
          <label class="rq-check" for="rq-terms">
            <input type="checkbox" name="terms" id="rq-terms" value="1" required @checked(old('terms'))/>
            <span>I have read the <a href="{{ route('services.terms') }}#cancellation-and-refunds" target="_blank" rel="noopener">Other Services cancellation and refund terms</a>.</span>
          </label>
          @error('terms')<p class="rq-error">{{ $message }}</p>@enderror
        </div>

        <div class="rq-actions">
          <button type="submit" class="lp-btn lp-btn--primary">Review my request</button>
          <a href="{{ route('services.request') }}" class="lp-btn lp-btn--secondary">Back to my service requests</a>
        </div>
      </form>
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
<script>
  (function () {
    var box = document.getElementById('rq-gcash');
    if (!box) { return; }
    document.querySelectorAll('input[name="refund_method"]').forEach(function (r) {
      r.addEventListener('change', function () { box.hidden = (document.querySelector('input[name="refund_method"]:checked') || {}).value !== 'gcash'; });
    });
  })();
</script>
@endsection
