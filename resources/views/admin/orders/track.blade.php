@extends('layouts.admin')

@section('title', 'Order ' . $order->order_number . ' — Admin')
@section('page-title', 'Order ' . $order->order_number)

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/order-tracking.css') }}"/>
@endpush

@php
  use App\Support\OrderStages;

  $pickup    = OrderStages::isPickup($order);
  $stages    = OrderStages::for($order);
  $status    = $order->order_status;
  $cancelled = $status === 'cancelled';
  $idx       = array_search($status, $stages, true);
  $next      = $cancelled ? null : OrderStages::next($order);
  $locked    = !$cancelled && $status === 'pending' && !$pay['met'];
  $needsBalance = !$cancelled && $next === 'completed' && $pay['state'] !== 'paid';
  $payBadge  = ['paid' => 'b-ok', 'partial' => 'b-warn', 'awaiting' => 'b-warn'][$pay['state']];
  $cancelEvent = $cancelled ? $order->events->firstWhere('type', 'cancel') : null;
  $cust      = $order->user;
@endphp

@section('content')
<div class="ot" data-ot-admin>
<div class="ot-wrap">

  <div class="top"><a class="back" href="{{ route('admin.orders.index') }}">&larr; Back to orders</a></div>

  <section class="glass card" aria-labelledby="ot-h1">
    <div class="head">
      <div>
        <h1 id="ot-h1">Order {{ $order->order_number }}</h1>
        <div class="meta">{{ $cust->first_name }} {{ $cust->last_name }} &middot; Placed {{ $order->created_at->timezone('Asia/Manila')->format('M j, Y') }} &middot; {{ $pickup ? 'Pickup' : 'Delivery' }}</div>
      </div>
      <div class="badges">
        @unless($cancelled && $pay['paid'] <= 0)<span class="badge {{ $payBadge }}">{{ $pay['label'] }}</span>@endunless
        @if($cancelled)
          <span class="badge b-bad">Cancelled</span>
        @else
          <span class="badge b-stage">{{ OrderStages::labelFor($order) }}</span>
        @endif
      </div>
    </div>
    <div style="margin-top:22px;">
      @if($cancelled)
        <div class="cancelled-box">This order was cancelled.</div>
      @else
        <ol class="steps" aria-label="Order progress">
          @foreach($stages as $i => $key)
            <li class="{{ $i < $idx ? 'done' : ($i === $idx ? 'current' : '') }}" @if($i === $idx) aria-current="step" @endif>
              <span class="dot">{{ $i < $idx ? '✓' : $i + 1 }}</span>
              <span>{{ OrderStages::label($key, $pickup) }}</span>
            </li>
          @endforeach
        </ol>
      @endif
    </div>
  </section>

  <div class="cols">
    {{-- ───────── left: history, items, payments ───────── --}}
    <div>
      <section class="glass card" aria-labelledby="ot-hist-t">
        <h2 id="ot-hist-t">Order history</h2>
        <p class="sub">Everything that happened to this order. Corrections stay here and are never shown to the customer.</p>
        <ul class="hist">
          @foreach($order->events as $e)
            <li>
              <span class="pin {{ $e->type === 'correction' ? 'fix' : (in_array($e->type, ['placed', 'payment'], true) || !$e->admin_user_id ? 'sys' : '') }}"></span>
              <div>
                <h3>{{ $e->title ?: ucfirst($e->type) }}
                  @if($e->customer_notified)<span class="tag t-sent">Customer notified</span>@else<span class="tag t-int">Internal only</span>@endif
                </h3>
                <div class="when">{{ $e->created_at->timezone('Asia/Manila')->format('M j, g:i A') }}@if($e->admin) &middot; {{ $e->admin->first_name }} {{ $e->admin->last_name }}@endif</div>
                @if($e->note)<p class="note">{{ $e->note }}</p>@endif
                @if($e->photo_path)<a href="{{ $e->photoUrl() }}" target="_blank" rel="noopener"><img src="{{ $e->photoUrl() }}" alt="Progress photo sent to the customer"></a>@endif
              </div>
            </li>
          @endforeach
        </ul>
      </section>

      <section class="glass card" aria-labelledby="ot-items-t">
        <h2 id="ot-items-t">Order items</h2>
        <div class="scroll">
          <table>
            <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
            <tbody>
              @foreach($order->items as $item)
                <tr>
                  <td>{{ $item->product->product_name ?? $item->product_name ?? 'N/A' }}</td>
                  <td>{{ $item->quantity }}</td>
                  <td>₱{{ number_format($item->unit_price, 2) }}</td>
                  <td>₱{{ number_format($item->subtotal, 2) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      @if($order->needs_review)
        <section class="glass card" aria-labelledby="ot-rev-t">
          <h2 id="ot-rev-t">Needs review</h2>
          <p class="sub">{{ $order->review_reason }}</p>
          <form method="POST" action="{{ route('admin.orders.review', $order->id) }}">
            @csrf
            <button type="submit" class="lp-btn lp-btn--primary">Mark reviewed</button>
          </form>
          <p class="hint">Refunds are handled outside the system. Write what was done in the order note.</p>
        </section>
      @elseif($order->reviewed_at)
        <p class="hint" style="margin:0 0 14px;">Review cleared by {{ $reviewedBy?->first_name }} {{ $reviewedBy?->last_name }} on {{ $order->reviewed_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.</p>
      @endif

      <section class="glass card" aria-labelledby="ot-pay-t">
        <h2 id="ot-pay-t">Payments received
          @if($order->payment && $order->payment->payment_method === 'onsite' && $ledger->isEmpty())
            <span class="badge b-warn" style="font-family:'DM Sans',sans-serif;vertical-align:middle;" title="Cash order with no payment recorded yet">Unrecorded</span>
          @endif
        </h2>
        @if($errors->has('payment'))
          <div class="cancelled-box" role="alert">{{ $errors->first('payment') }}</div>
        @endif
        @if($ledger->isEmpty())
          <p class="sub">No payments are recorded for this order yet.</p>
        @else
          <p class="sub">Payments so far are listed below.</p>
          <div class="scroll">
            <table>
              <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>By</th><th>Note</th></tr></thead>
              <tbody>
                @foreach($ledger as $row)
                  <tr>
                    <td>{{ $row->paid_on->format('M d, Y') }}</td>
                    <td>₱{{ number_format($row->amount, 2) }}</td>
                    <td>{{ $methodLabels[$row->method] ?? $row->method }}</td>
                    <td>{{ $row->gateway_reference ?: '—' }}</td>
                    <td>{{ $row->recorder ? $row->recorder->first_name . ' ' . $row->recorder->last_name : 'System' }}</td>
                    <td>{{ $row->note ?: '—' }}@if($row->installment)<br><small>Installment #{{ $row->installment->installment_no }}</small>@endif</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
        <dl style="margin-top:10px;">
          <div class="kv"><dt>Total</dt><dd>₱{{ number_format($pay['total'], 2) }}</dd></div>
          <div class="kv"><dt>Paid</dt><dd>₱{{ number_format($pay['paid'], 2) }}</dd></div>
          <div class="kv"><dt>Remaining</dt><dd>₱{{ number_format($pay['remaining'], 2) }}</dd></div>
        </dl>

        @if($pay['remaining'] > 0 && !$cancelled && $order->payment)
          <hr>
          <h3 style="font-size:17px;margin-bottom:10px;">Record a payment</h3>
          <form method="POST" action="{{ route('admin.orders.payments.store', $order->id) }}" id="record-payment-form">
            @csrf
            <input type="hidden" name="form_token" value="{{ $formToken }}"/>
            <div class="row" style="align-items:flex-end;">
              <div style="flex:1;min-width:130px;">
                <label for="rp-amount">Amount (₱), up to {{ number_format($pay['remaining'], 2) }}</label>
                <input id="rp-amount" name="amount" type="text" inputmode="decimal" required value="{{ old('amount') }}"/>
              </div>
              <div style="flex:1;min-width:130px;">
                <label for="rp-method">Method</label>
                <select id="rp-method" name="method" required>
                  @foreach(\App\Support\RecordPayment::ADMIN_METHODS as $m)
                    <option value="{{ $m }}" @selected(old('method', 'cash') === $m)>{{ $methodLabels[$m] }}</option>
                  @endforeach
                </select>
              </div>
              <div style="flex:1;min-width:130px;">
                <label for="rp-date">Paid on</label>
                <input id="rp-date" name="paid_on" type="date" required max="{{ now('Asia/Manila')->toDateString() }}" value="{{ old('paid_on', now('Asia/Manila')->toDateString()) }}"/>
              </div>
            </div>
            @if($unpaidRows->isNotEmpty())
              <div class="field" style="margin-top:12px;">
                <label for="rp-inst">Applies to installment (optional)</label>
                <select id="rp-inst" name="installment_id">
                  <option value="">Not linked</option>
                  @foreach($unpaidRows as $row)
                    <option value="{{ $row->id }}" @selected((string) old('installment_id') === (string) $row->id)>#{{ $row->installment_no }} · ₱{{ number_format($row->amount_due, 2) }} · due {{ $row->due_date->format('M d, Y') }}</option>
                  @endforeach
                </select>
              </div>
            @endif
            <div class="field" style="margin-top:12px;">
              <label for="rp-note">Note (optional)</label>
              <textarea id="rp-note" name="note" rows="2" maxlength="1000">{{ old('note') }}</textarea>
            </div>
            <button type="submit" class="lp-btn lp-btn--primary" id="rp-submit">Record payment</button>
          </form>
          <p class="hint">Refunds are not handled in the system. Handle them outside it and write what was done in the order note.</p>
        @elseif($pay['remaining'] <= 0)
          <p class="hint">Paid in full. Refunds are not handled in the system; write any refund in the order note.</p>
        @endif
      </section>

      @if($order->customization_note)
        <section class="glass card"><h2>Customer instructions</h2><p class="sub" style="margin:0;white-space:pre-line;">{{ $order->customization_note }}</p></section>
      @endif
      @if($order->notes)
        <section class="glass card"><h2>Admin notes</h2><p class="sub" style="margin:0;white-space:pre-line;">{{ $order->notes }}</p></section>
      @endif
    </div>

    {{-- ───────── right: update card, summary ───────── --}}
    <div class="side">
      <section class="glass card" aria-labelledby="ot-up-t">
        <h2 id="ot-up-t">Update progress</h2>
        <p class="sub">This is how you keep the customer informed. Each update reaches their Notifications and email.</p>

        @if($cancelled)
          <div class="cancelled-box">This order was cancelled.@if($cancelEvent?->note) Reason: {{ $cancelEvent->note }}@endif</div>
        @else
          <div class="next"><small>Now</small><strong>{{ OrderStages::labelFor($order) }}</strong></div>

          @if($locked)
            <div class="lock"><b>Waiting for payment.</b> The next step unlocks when the customer pays, or when you record a payment below.</div>
          @elseif(!$next)
            <div class="done-box">This order is completed. The customer has been notified.</div>
          @else
            <div class="next"><small>Next step</small><strong>{{ OrderStages::labelFor($order, $next) }}</strong></div>
            @if($needsBalance)
              <div class="lock"><b>Balance not paid.</b> ₱{{ number_format($pay['remaining'], 2) }} is still owed. Record it before completing this order.</div>
            @endif
          @endif

          @unless($locked)
            <form method="POST" action="{{ $next ? route('admin.orders.stage.advance', $order->id) : route('admin.orders.stage.update', $order->id) }}" enctype="multipart/form-data" id="ot-form">
              @csrf
              <input type="hidden" name="current_status" value="{{ $status }}">
              <input type="hidden" name="confirmed" id="ot-confirmed" value="0">

              <div class="field">
                <label for="ot-msg">Message to the customer <span style="font-weight:400">(optional)</span><span class="count" id="ot-cnt">0/300</span></label>
                <textarea id="ot-msg" name="message" maxlength="300" placeholder="Example: The frame is assembled. Sanding and staining are next.">{{ old('message') }}</textarea>
                <p class="hint">Shown on their tracking page and in the email.</p>
              </div>

              <div class="field">
                <label for="ot-photo">Progress photo <span style="font-weight:400">(optional)</span></label>
                <div class="photo">
                  <span id="ot-thumb"></span>
                  <button type="button" class="lp-btn lp-btn--secondary" id="ot-add-photo">Add a photo</button>
                  <input type="file" id="ot-photo" name="photo" accept="image/jpeg,image/png" hidden>
                </div>
                <p class="hint">JPG or PNG, up to 5 MB.</p>
                <div class="err" id="ot-photo-err" role="alert">{{ $errors->first('photo') }}</div>
              </div>

              @if($next)
                <div class="preview"><small>The customer will read</small>{{ OrderStages::customerCopy($order, $next) }}<span id="ot-pv-msg"></span></div>
                <button type="button" class="lp-btn lp-btn--primary lp-btn--block" data-ot-open data-ot-kind="advance"
                        data-ot-url="{{ route('admin.orders.stage.advance', $order->id) }}"
                        data-ot-title="{{ OrderStages::buttonLabel($order, $next) }}?"
                        data-ot-question="{{ OrderStages::question($order, $next) }}"
                        data-ot-check="Yes, this is correct. Notify the customer."
                        data-ot-yes="Yes, {{ lcfirst(OrderStages::buttonLabel($order, $next)) }}"
                        data-ot-copy="{{ OrderStages::customerCopy($order, $next) }}"
                        @disabled($needsBalance)>
                  {{ OrderStages::buttonLabel($order, $next) }}
                </button>
              @endif

              <div class="actions-row">
                <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" id="ot-send-only" data-ot-url="{{ route('admin.orders.stage.update', $order->id) }}" disabled>Send update only (stage stays)</button>
                @php $canFix = !in_array($status, ['pending', 'confirmed'], true); @endphp
                <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-ot-open data-ot-kind="correct"
                        data-ot-url="{{ route('admin.orders.stage.correct', $order->id) }}"
                        data-ot-title="Correct last step?"
                        data-ot-question="This moves the order back from {{ OrderStages::labelFor($order) }} to {{ OrderStages::previous($order) ? OrderStages::labelFor($order, OrderStages::previous($order)) : '' }}."
                        data-ot-reason="1" data-ot-danger="1"
                        data-ot-check="I understand the customer is not notified and this is saved in the history."
                        data-ot-yes="Correct last step"
                        @if(!$canFix) disabled title="The first stages can't be undone" @endif>Correct last step</button>
              </div>
            </form>
          @endunless

          @if(in_array($status, ['pending', 'confirmed'], true))
            <div class="row" style="justify-content:flex-end;margin-top:8px;">
              <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-ot-open data-ot-kind="cancel"
                      data-ot-url="{{ route('admin.orders.stage.cancel', $order->id) }}"
                      data-ot-title="Cancel this order?"
                      data-ot-question="The order will be closed and the customer is told it was cancelled. They will see the reason you write."
                      data-ot-reason="1" data-ot-danger="1"
                      data-ot-check="Yes, cancel this order. Refunds are handled outside the system."
                      data-ot-yes="Cancel order">Cancel order</button>
            </div>
          @endif
        @endif

        <form method="POST" id="ot-action" hidden>
          @csrf
          <input type="hidden" name="current_status" value="{{ $status }}">
          <input type="hidden" name="confirmed" value="0">
          <input type="hidden" name="reason" value="">
        </form>
      </section>

      <section class="glass card" aria-labelledby="ot-sum-t">
        <h2 id="ot-sum-t">Summary</h2>
        <dl>
          <div class="kv"><dt>Delivery option</dt><dd>{{ $pickup ? 'Pickup' : 'Delivery' }}</dd></div>
          @if(!$pickup && $order->delivery_address)<div class="addr">{{ $order->delivery_address }}</div>@endif
          <div class="kv"><dt>Delivery fee</dt><dd>₱{{ number_format($order->delivery_fee, 2) }}</dd></div>
          @if($order->payment)
            <div class="kv"><dt>Payment method</dt><dd>{{ ucfirst($order->payment->payment_method) }} &middot; {{ $order->payment->payment_type === 'installment' ? 'Installment' : 'Full payment' }}</dd></div>
            @if($order->payment->payment_type === 'installment')
              <div class="kv"><dt>Needed to confirm</dt><dd>₱{{ number_format($pay['required'], 2) }}</dd></div>
            @endif
          @endif
          <div class="kv total"><dt>Total</dt><dd>₱{{ number_format($order->total_amount, 2) }}</dd></div>
        </dl>
      </section>

      <section class="glass card" aria-labelledby="ot-cust-t">
        <h2 id="ot-cust-t">Customer</h2>
        <p style="margin:0;font-weight:700;">{{ $cust->first_name }} {{ $cust->last_name }}</p>
        <p class="sub" style="margin:2px 0 0;">{{ $cust->email }}</p>
        <p class="sub" style="margin:0;">{{ $cust->phone_number }}</p>
      </section>
    </div>
  </div>

  <dialog id="ot-dlg" aria-labelledby="ot-dlg-title">
    <div class="dlg">
      <h3 id="ot-dlg-title"></h3>
      <p class="q" id="ot-dlg-q"></p>
      <div id="ot-dlg-reason-wrap" hidden>
        <label for="ot-dlg-reason">Reason (required, saved in the order history)</label>
        <textarea id="ot-dlg-reason" maxlength="300"></textarea>
        <div class="err" id="ot-dlg-reason-err" hidden>Write between 8 and 300 characters so the team knows why.</div>
      </div>
      <div class="preview" id="ot-dlg-prev" hidden></div>
      <label class="chk" for="ot-dlg-chk"><input type="checkbox" id="ot-dlg-chk"><span id="ot-dlg-chk-text"></span></label>
      <div class="row">
        <button type="button" class="lp-btn lp-btn--secondary" id="ot-dlg-no">Go back</button>
        <button type="button" class="lp-btn lp-btn--primary" id="ot-dlg-yes" disabled>Confirm</button>
      </div>
    </div>
  </dialog>

</div>
</div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/order-tracking.js') }}"></script>
@endpush
