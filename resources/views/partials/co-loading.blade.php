{{--
  Loading feedback for the whole site: included once, at the end of the body,
  by both layouts (layouts/app and layouts/admin). Pages must not include it
  again. Forms opt out with data-no-loading; links opt in with
  data-loading="Opening payment..." (add data-loading-overlay="payment" for
  the full-page payment overlay).
--}}
<link rel="stylesheet" href="{{ asset('assets/css/co-loading.css') }}"/>
<div class="lp-progress" id="lp-progress" hidden aria-hidden="true"></div>
<div class="lp-overlay" id="lp-pay-overlay" hidden role="alertdialog" aria-modal="true" aria-labelledby="lp-pay-overlay-title" aria-describedby="lp-pay-overlay-text">
  <div class="lp-overlay-card">
    <div class="lp-overlay-spinner" aria-hidden="true"></div>
    <p class="lp-overlay-title" id="lp-pay-overlay-title">Please wait</p>
    <p class="lp-overlay-text" id="lp-pay-overlay-text">Processing your payment. Please don't close or refresh this page.</p>
  </div>
</div>
<script src="{{ asset('assets/js/co-loading.js') }}" defer></script>
