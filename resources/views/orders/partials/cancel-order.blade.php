{{--
  "Cancel Order" for a customer's own UNPAID catalog order: a button plus the shared confirmation modal (<x-modal>) asking for a reason.
  Shown only while OrderTracking::customerCancelBlock() says the order can be cancelled; the server checks again on submit.
  Place it OUTSIDE any link (the My Orders card is an <a>). Expects $order (with payment). Optional $class for the button.
--}}
@if(\App\Support\OrderTracking::customerCancelBlock($order) === null)
  @once
    @push('styles')
      <link rel="stylesheet" href="{{ asset('assets/css/order-cancel.css') }}?v={{ @filemtime(public_path('assets/css/order-cancel.css')) }}"/>
    @endpush
    @push('scripts')
      <script src="{{ asset('assets/js/order-cancel.js') }}?v={{ @filemtime(public_path('assets/js/order-cancel.js')) }}"></script>
    @endpush
  @endonce

  @php $cid = 'cancel-order-' . $order->id; @endphp
  <button type="button" class="lp-btn lp-btn--secondary {{ $class ?? '' }}" data-lp-modal-open="{{ $cid }}">Cancel Order</button>

  <x-modal :id="$cid" title="Cancel This Order?">
    <form method="POST" action="{{ route('orders.cancel', $order->id) }}" id="{{ $cid }}-form" class="oc-form" data-oc-form>
      @csrf
      <p class="oc-lead">Order {{ $order->order_number }} has not been paid, so nothing will be charged. Please tell us why you are cancelling.</p>
      <fieldset class="oc-reasons">
        <legend>Reason for Cancelling</legend>
        @foreach(\App\Support\OrderTracking::CUSTOMER_CANCEL_REASONS as $i => $reason)
          <label class="oc-opt">
            <input type="radio" name="reason" value="{{ $reason }}" @if($i === 0) required @endif>
            <span>{{ $reason }}</span>
          </label>
        @endforeach
      </fieldset>
      <div class="oc-other" data-oc-other hidden>
        <label for="{{ $cid }}-other">Tell Us More <span class="oc-opt-tag">Optional</span></label>
        <textarea id="{{ $cid }}-other" name="reason_other" rows="2" maxlength="{{ \App\Support\OrderTracking::CUSTOMER_CANCEL_OTHER_MAX }}" placeholder="Anything else you want us to know."></textarea>
        <small class="oc-count"><span data-oc-count>0</span> / {{ \App\Support\OrderTracking::CUSTOMER_CANCEL_OTHER_MAX }}</small>
      </div>
    </form>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Keep Order</button>
      <button type="submit" form="{{ $cid }}-form" class="lp-btn lp-btn--danger">Cancel Order</button>
    </x-slot:footer>
  </x-modal>
@endif
