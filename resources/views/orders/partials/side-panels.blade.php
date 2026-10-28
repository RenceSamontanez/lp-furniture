    @if($order->payment)
        <div class="order-panel">
          <div class="order-panel-header"><div class="order-panel-title">Payment</div></div>
          <div class="order-panel-body">
            <div class="order-info-row">
              <span class="order-info-label">Method</span>
              <span class="order-info-value" style="text-transform:capitalize;">{{ $order->payment->payment_method }}</span>
            </div>
            <div class="order-info-row">
              <span class="order-info-label">Type</span>
              <span class="order-info-value" style="text-transform:capitalize;">{{ $order->payment->payment_type }}</span>
            </div>
            <div class="order-info-row">
              <span class="order-info-label">Status</span>
              <span class="order-status-pill {{ $order->payment->payment_status }}" style="font-size:10px;">
                {{ $order->payment->payment_status }}
              </span>
            </div>

            @if(in_array($order->payment->payment_method, ['stripe', 'gcash']) && $order->payment->payment_status !== 'paid' && $order->order_status !== 'cancelled')
              <div style="margin-top:14px;padding:14px;background:#FFF3CD;border:1px solid #FFE29A;border-radius:10px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <strong style="font-size:13px;color:#856404;">Payment Not Completed</strong>
                </div>
                <p style="font-size:12.5px;color:#856404;margin-bottom:12px;line-height:1.5;">
                  @if($order->design_agreement_id)
                    @php $reservedUntil = $order->designRequest?->payment_due_at; @endphp
                    Your order is saved, but payment wasn't finished.
                    @if($reservedUntil && $reservedUntil->isFuture())
                      Your agreement is reserved until {{ $reservedUntil->timezone('Asia/Manila')->format('M d, Y') }}. Complete payment before then to confirm your order.
                    @else
                      The payment window for this agreement has ended. Please contact us.
                    @endif
                  @else
                  Your order is saved, but payment wasn't finished. Your items are being held for a limited time — complete payment now to confirm your order.
                  @endif
                </p>
                <a href="{{ route($order->payment->payment_method === 'stripe' ? 'payment.stripe' : 'payment.gcash.paymongo', $order->id) }}" class="btn btn-gold" style="display:block;text-align:center;padding:11px;" data-loading="Opening payment..." data-loading-overlay="payment">
                  Finish Payment
                </a>
              </div>
            @endif

            {{-- Change Payment Method — only while the order hasn't
                 progressed past pending and payment hasn't succeeded.
                 Checking order_status (not just payment_status) matters
                 specifically for onsite/cash orders, whose payment_status
                 never programmatically becomes 'paid' through any
                 existing flow — so payment_status alone can't be
                 trusted to tell us the order is still "open". --}}
            @if($order->order_status === 'pending' && $order->payment->payment_status !== 'paid' && (float) $order->amount_paid <= 0)
              <button type="button" class="order-change-method-toggle" style="margin-top:14px;" onclick="document.getElementById('change-method-panel').classList.toggle('open')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                Change Payment Method
              </button>

              <div class="order-change-method-panel" id="change-method-panel">
                <form method="POST" action="{{ route('orders.paymentMethod.update', $order->id) }}">
                  @csrf

                  <label class="payment-method-card {{ $order->payment->payment_method === 'stripe' ? 'selected' : '' }}" data-method-card="stripe">
                    <input type="radio" name="payment_method" value="stripe" {{ $order->payment->payment_method === 'stripe' ? 'checked' : '' }}/>
                    <div class="payment-method-icon stripe">
                      <svg width="18" height="13" viewBox="0 0 60 25" fill="white"><path d="M28.3 8.7c0-2.7 2.3-3.7 4.8-3.7 2.7 0 5.3 1 7.3 2.7l3.3-5.3C41.3.8 37.7 0 33.3 0c-6.7 0-12 3.3-12 9.7 0 11 14.7 8.3 14.7 13 0 2.3-2 3.7-5 3.7-3 0-6.3-1.3-8.7-3.3l-3.7 5.3c3 2.3 7.3 3.7 12 3.7 7 0 12.3-3.3 12.3-10 0-11.3-14.6-8.7-14.6-13.4z"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Credit / Debit Card (Stripe)</div>
                      <div class="payment-method-desc">Visa, Mastercard, and more.</div>
                    </div>
                  </label>

                  <label class="payment-method-card {{ $order->payment->payment_method === 'gcash' ? 'selected' : '' }}" data-method-card="gcash">
                    <input type="radio" name="payment_method" value="gcash" {{ $order->payment->payment_method === 'gcash' ? 'checked' : '' }}/>
                    <div class="payment-method-icon gcash">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">GCash</div>
                      <div class="payment-method-desc">Pay via GCash, powered by PayMongo.</div>
                    </div>
                  </label>

                  @unless($order->design_agreement_id)
                  <label class="payment-method-card {{ $order->payment->payment_method === 'onsite' ? 'selected' : '' }}" data-method-card="onsite">
                    <input type="radio" name="payment_method" value="onsite" {{ $order->payment->payment_method === 'onsite' ? 'checked' : '' }}/>
                    <div class="payment-method-icon onsite">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 12h.01"/><path d="M17 12h.01"/><path d="M7 12h.01"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Cash on Pickup / Delivery</div>
                      <div class="payment-method-desc">Pay in cash when you receive your order.</div>
                    </div>
                  </label>
                  @endunless

                  <button type="submit" class="btn btn-gold" style="width:100%;padding:11px;margin-top:4px;">
                    Confirm Switch
                  </button>
                </form>
              </div>

              <script>
                document.querySelectorAll('#change-method-panel input[name="payment_method"]').forEach(function (input) {
                  input.addEventListener('change', function () {
                    document.querySelectorAll('#change-method-panel .payment-method-card').forEach(function (card) {
                      card.classList.remove('selected');
                    });
                    this.closest('.payment-method-card').classList.add('selected');
                  });
                });
              </script>
            @endif
          </div>
        </div>
      @endif

      <div class="order-panel">
        <div class="order-panel-header"><div class="order-panel-title">Delivery</div></div>
        <div class="order-panel-body">
          <div class="order-info-row">
            <span class="order-info-label">Method</span>
            <span class="order-info-value" style="text-transform:capitalize;">{{ $order->delivery_option }}</span>
          </div>
          @if($order->delivery_option === 'delivery' && $order->delivery_address)
            <div class="order-info-row">
              <span class="order-info-label">Address</span>
              <span class="order-info-value">{{ $order->delivery_address }}</span>
            </div>
          @endif
          <div class="order-info-row">
            <span class="order-info-label">Delivery Fee</span>
            <span class="order-info-value">₱{{ number_format($order->delivery_fee, 2) }}</span>
          </div>
        </div>
      </div>

      <div class="order-panel">
        <div class="order-panel-header"><div class="order-panel-title">Order Summary</div></div>
        <div class="order-panel-body">
          @php $subtotal = $order->items->sum('subtotal'); @endphp
          <div class="order-info-row">
            <span class="order-info-label">Subtotal</span>
            <span class="order-info-value">₱{{ number_format($subtotal, 2) }}</span>
          </div>
          <div class="order-info-row">
            <span class="order-info-label">Delivery Fee</span>
            <span class="order-info-value">₱{{ number_format($order->delivery_fee, 2) }}</span>
          </div>
          <div class="order-total-row">
            <span>Total</span>
            <span>₱{{ number_format($order->total_amount, 2) }}</span>
          </div>
        </div>
      </div>
