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
  $peso     = fn ($n) => '₱' . number_format((float) $n, 2);
  $instant  = !$paid && !$started;
  $method   = old('refund_method');
  $days     = \App\Models\ServiceRefundRequest::REFUND_DAYS;
@endphp
<div class="si-wrap">
  <div class="rq-flow">

    <div class="rq-flow__summary">@include('services.refunds._summary')</div>

    <div class="si-card rq-main rq-flow__form">
      <x-back-link :href="route('services.request')" label="my service requests" />
      <h1 class="rq-title">{{ $instant ? 'Cancel this service request' : 'Request cancellation or refund' }}</h1>
      <p class="rq-lead">
        @if($instant)
          Nothing has been paid and the work has not started, so we can cancel it for you right away.
        @else
          Tell us why, and our team will review your request and reply to you. Payments on this service are paused while we review.
        @endif
      </p>

      @if($errors->any())
        <div class="co-errors" role="alert">
          @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
      @endif

      <form method="POST" action="{{ route('services.refund.review', $ticket->id) }}" id="rq-form" novalidate
            data-lp-gate="rq-confirm-modal" data-lp-final-action="{{ route('services.refund.store', $ticket->id) }}">
        @csrf

        <fieldset class="rq-fieldset">
          <legend class="rq-legend"><span class="rq-step-no">1</span> Why do you want to cancel?</legend>
          <div class="rq-field">
            <label class="form-label" for="rq-reason">Reason</label>
            <select name="reason_code" id="rq-reason" class="form-input" required aria-describedby="rq-reason-err">
              <option value="">Choose a reason</option>
              @foreach(\App\Models\ServiceRefundRequest::REASONS as $k => $label)
                <option value="{{ $k }}" @selected(old('reason_code') === $k)>{{ $label }}</option>
              @endforeach
            </select>
            <p class="rq-error" id="rq-reason-err" @if(!$errors->has('reason_code')) hidden @endif>{{ $errors->first('reason_code') ?: 'Choose a reason.' }}</p>
          </div>
          <div class="rq-field">
            <label class="form-label" for="rq-details">Details (optional)</label>
            <textarea name="reason_text" id="rq-details" class="form-input" rows="3" maxlength="1000">{{ old('reason_text') }}</textarea>
          </div>
        </fieldset>

        <fieldset class="rq-fieldset">
          <legend class="rq-legend"><span class="rq-step-no">2</span> How can we reach you?</legend>
          <div class="rq-two">
            <div class="rq-field">
              <label class="form-label" for="rq-phone">Mobile number</label>
              <input type="tel" name="contact_phone" id="rq-phone" class="form-input" required autocomplete="tel" inputmode="tel" value="{{ old('contact_phone', $defaults['phone']) }}" aria-describedby="rq-phone-err"/>
              <p class="rq-error" id="rq-phone-err" @if(!$errors->has('contact_phone')) hidden @endif>{{ $errors->first('contact_phone') ?: 'Enter your mobile number.' }}</p>
            </div>
            <div class="rq-field">
              <label class="form-label" for="rq-email">Email</label>
              <input type="email" name="contact_email" id="rq-email" class="form-input" required autocomplete="email" value="{{ old('contact_email', $defaults['email']) }}" aria-describedby="rq-email-err"/>
              <p class="rq-error" id="rq-email-err" @if(!$errors->has('contact_email')) hidden @endif>{{ $errors->first('contact_email') ?: 'Enter a valid email address.' }}</p>
            </div>
          </div>
        </fieldset>

        @if($paid)
        <fieldset class="rq-fieldset">
          <legend class="rq-legend"><span class="rq-step-no">3</span> Where should we send your refund?</legend>
          <p class="rq-hint" style="margin:0 0 10px;">Refunds are returned by GCash or cash, even if you paid by card.</p>
          <div class="rq-radios" role="radiogroup" aria-label="Refund method">
            @foreach(\App\Models\ServiceRefundRequest::METHODS as $k => $label)
              <label class="rq-radio"><input type="radio" name="refund_method" value="{{ $k }}" required @checked($method === $k)/> <span>{{ $label }}{{ $k === 'cash' ? ' (you pick it up from us)' : '' }}</span></label>
            @endforeach
          </div>
          <p class="rq-error" id="rq-method-err" @if(!$errors->has('refund_method')) hidden @endif>{{ $errors->first('refund_method') ?: 'Choose how you want your refund: GCash or cash.' }}</p>

          <div class="rq-gcash" id="rq-gcash" @if($method !== 'gcash') hidden @endif>
            <div class="rq-field" style="margin-top:14px;">
              <label class="form-label" for="rq-gnum">GCash number</label>
              <input type="tel" name="gcash_number" id="rq-gnum" class="form-input" inputmode="tel" autocomplete="off" placeholder="09171234567" value="{{ old('gcash_number') }}" aria-describedby="rq-gnum-hint rq-gnum-err"/>
              <p class="rq-hint" id="rq-gnum-hint">The mobile number of your GCash account, for example 09171234567.</p>
              <p class="rq-error" id="rq-gnum-err" @if(!$errors->has('gcash_number')) hidden @endif>{{ $errors->first('gcash_number') ?: 'Enter a GCash number like 09171234567.' }}</p>
            </div>
            <div class="rq-field">
              <label class="form-label" for="rq-gname">Name or initials on your GCash account</label>
              <input type="text" name="gcash_account_name" id="rq-gname" class="form-input" autocomplete="off" value="{{ old('gcash_account_name') }}" aria-describedby="rq-gname-hint rq-gname-err"/>
              <p class="rq-hint" id="rq-gname-hint">As it appears when someone sends you money, for example Juan D.</p>
              <p class="rq-error" id="rq-gname-err" @if(!$errors->has('gcash_account_name')) hidden @endif>{{ $errors->first('gcash_account_name') ?: 'Enter the name on that GCash account.' }}</p>
            </div>
            <x-notice variant="info" title="Please check your GCash number and name">A wrong number delays your refund.</x-notice>
          </div>
        </fieldset>
        @endif

        <div class="rq-field">
          <label class="rq-check" for="rq-terms">
            <input type="checkbox" name="terms" id="rq-terms" value="1" required @checked(old('terms'))/>
            <span>I have read the <a href="{{ route('services.terms') }}#cancellation-and-refunds" class="lp-link" target="_blank" rel="noopener">Other Services cancellation and refund terms</a>.</span>
          </label>
          <p class="rq-error" id="rq-terms-err" @if(!$errors->has('terms')) hidden @endif>{{ $errors->first('terms') ?: 'Please confirm that you have read the terms.' }}</p>
        </div>

        <div class="rq-actions">
          <button type="submit" class="lp-btn lp-btn--primary">{{ $instant ? 'Review and cancel' : 'Review my request' }}</button>
          <x-back-link :href="route('services.request')" label="my service requests" />
        </div>
      </form>
    </div>

    <div class="rq-flow__contact">@include('services._contact-card')</div>

  </div>
</div>

{{-- Confirmation modal: opens after the form passes validation. The server validates everything again. --}}
<x-modal id="rq-confirm-modal" title="{{ $instant ? 'Cancel this service request?' : 'Check your request' }}" summary-form="rq-form">
  <dl class="lp-modal__facts">
    <div class="lp-modal__row"><dt>Service request</dt><dd>{{ $ticket->reference_number }}</dd></div>
    @if($ctx['invoice'])<div class="lp-modal__row"><dt>Invoice</dt><dd>{{ $ctx['invoice']->invoice_number }}</dd></div>@endif
    @if($paid)
      <div class="lp-modal__row"><dt>Refund amount</dt><dd>The full amount you paid, {{ $peso($ctx['paid_cents'] / 100) }}</dd></div>
      <div class="lp-modal__row"><dt>Refund method</dt><dd data-summary="refund_method"></dd></div>
      <div class="lp-modal__row" data-summary-if="refund_method=gcash"><dt>GCash number</dt><dd data-summary="gcash_number"></dd></div>
      <div class="lp-modal__row" data-summary-if="refund_method=gcash"><dt>GCash name</dt><dd data-summary="gcash_account_name"></dd></div>
    @endif
    <div class="lp-modal__row"><dt>Mobile</dt><dd data-summary="contact_phone"></dd></div>
    <div class="lp-modal__row"><dt>Email</dt><dd data-summary="contact_email"></dd></div>
    <div class="lp-modal__row"><dt>Reason</dt><dd data-summary="reason_code"></dd></div>
    <div class="lp-modal__row"><dt>Details</dt><dd data-summary="reason_text"></dd></div>
  </dl>
  @if($instant)
    <x-notice variant="blocked" title="This cannot be undone">Your service request and invoice are cancelled right away.</x-notice>
  @elseif($paid)
    <x-notice variant="problem" title="Please check your GCash number and name.">Once a refund is sent, it cannot be recalled or sent again.</x-notice>
    <label class="lp-modal__check" for="rq-checked"><input type="checkbox" id="rq-checked" data-lp-reset/> <span>I checked these details</span></label>
  @else
    <x-notice variant="info" title="Our team will review this">Payments on this service are paused until we reply.</x-notice>
    <label class="lp-modal__check" for="rq-checked"><input type="checkbox" id="rq-checked" data-lp-reset/> <span>I checked these details</span></label>
  @endif
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back and edit</button>
    <button type="button" class="lp-btn lp-btn--primary" data-lp-gate-submit="rq-form" @unless($instant) data-lp-requires="rq-checked" @endunless data-lp-confirm>{{ $instant ? 'Cancel this service request' : 'Confirm and send request' }}</button>
  </x-slot:footer>
</x-modal>

<script>
  (function () {
    var form = document.getElementById('rq-form');
    var box = document.getElementById('rq-gcash');
    var sum = document.getElementById('rq-sum');
    if (sum && window.matchMedia('(max-width: 860px)').matches) { sum.open = false; }

    function show(id, on, text) { var n = document.getElementById(id); if (n) { if (text) { n.textContent = text; } n.hidden = !on; } }
    function val(name) { var e = form.elements[name]; return e ? (e.value || '').trim() : ''; }
    function checked(name) { var e = form.querySelector('input[name="' + name + '"]:checked'); return e ? e.value : ''; }

    if (box) {
      document.querySelectorAll('input[name="refund_method"]').forEach(function (r) {
        r.addEventListener('change', function () { box.hidden = checked('refund_method') !== 'gcash'; show('rq-method-err', false); });
      });
    }

    // Friendly inline errors before the confirmation opens (the server checks everything again).
    form.addEventListener('submit', function (e) {
      var bad = [];
      var phoneOk = /^(09|\+639|639)\d{9}$/.test(val('contact_phone').replace(/[\s-]/g, ''));
      show('rq-reason-err', !val('reason_code')); if (!val('reason_code')) { bad.push('rq-reason'); }
      show('rq-phone-err', !phoneOk); if (!phoneOk) { bad.push('rq-phone'); }
      var mailOk = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(val('contact_email'));
      show('rq-email-err', !mailOk); if (!mailOk) { bad.push('rq-email'); }
      if (box) {
        var m = checked('refund_method');
        show('rq-method-err', !m); if (!m) { bad.push('rq-gcash'); }
        if (m === 'gcash') {
          var gOk = /^(09|\+639|639)\d{9}$/.test(val('gcash_number').replace(/[\s-]/g, ''));
          show('rq-gnum-err', !gOk); if (!gOk) { bad.push('rq-gnum'); }
          var nOk = val('gcash_account_name').length >= 2;
          show('rq-gname-err', !nOk); if (!nOk) { bad.push('rq-gname'); }
        }
      }
      var tOk = form.elements.terms.checked;
      show('rq-terms-err', !tOk); if (!tOk) { bad.push('rq-terms'); }
      if (bad.length) { e.preventDefault(); e.stopImmediatePropagation(); var f = document.getElementById(bad[0]); if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } if (f && f.focus) { f.focus(); } }
    }, true);
  })();
</script>
@endsection
