@extends('layouts.admin')

@section('title', 'Order Details — Admin')
@section('page-title', 'Order ' . $order->order_number)

@section('content')

<a href="{{ route('admin.orders.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Orders</a>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

  <div>
    {{-- Items --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Order Items</div>
        <span class="admin-pill {{ $order->order_status }}">{{ str_replace('_',' ',$order->order_status) }}</span>
      </div>
      <div class="admin-panel-body" style="padding:0;">
        <table class="admin-table">
          <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
          <tbody>
            @foreach($order->items as $item)
              <tr>
                <td data-label="Product">{{ $item->product->product_name ?? 'N/A' }}</td>
                <td data-label="Qty">{{ $item->quantity }}</td>
                <td data-label="Price">₱{{ number_format($item->unit_price, 2) }}</td>
                <td data-label="Subtotal">₱{{ number_format($item->subtotal, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    {{-- Needs review (duplicate or late payment) --}}
    @if($order->needs_review)
      <div class="admin-panel" style="border-color:#E8C27A;">
        <div class="admin-panel-header">
          <div class="admin-panel-title">Needs review</div>
          <form method="POST" action="{{ route('admin.orders.review', $order->id) }}">
            @csrf
            <button type="submit" class="admin-btn admin-btn-gold">Mark reviewed</button>
          </form>
        </div>
        <div class="admin-panel-body" style="font-size:13.5px;">
          {{ $order->review_reason }}
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">
            Refunds are handled outside the system. Write what was done in the order note.
          </div>
        </div>
      </div>
    @elseif($order->reviewed_at)
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
        Review cleared by {{ $reviewedBy?->first_name }} {{ $reviewedBy?->last_name }}
        on {{ $order->reviewed_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
      </div>
    @endif

    {{-- Payment ledger --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Payments received</div>
        @if($unrecorded)
          <span class="admin-pill pending" title="Cash order with no payment recorded yet">Unrecorded</span>
        @endif
      </div>
      <div class="admin-panel-body" style="padding:0;">
        @if($errors->has('payment'))
          <div class="admin-toast admin-toast-error" style="position:static;margin:14px;">{{ $errors->first('payment') }}</div>
        @endif

        @if($ledger->isEmpty())
          <p style="padding:16px 20px;margin:0;font-size:13px;color:var(--text-muted);">
            No payments are recorded for this order yet.
          </p>
        @else
          <table class="admin-table">
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th><th>Note</th></tr></thead>
            <tbody>
              @foreach($ledger as $row)
                <tr>
                  <td data-label="Date">{{ $row->paid_on->format('M d, Y') }}</td>
                  <td data-label="Amount">₱{{ number_format($row->amount, 2) }}</td>
                  <td data-label="Method">{{ $methodLabels[$row->method] ?? $row->method }}</td>
                  <td data-label="Reference" style="overflow-wrap:anywhere;">{{ $row->gateway_reference ?: '—' }}</td>
                  <td data-label="Recorded by">{{ $row->recorder ? $row->recorder->first_name . ' ' . $row->recorder->last_name : 'System' }}</td>
                  <td data-label="Note">
                    {{ $row->note ?: '—' }}
                    @if($row->installment)<div style="font-size:11.5px;color:var(--text-muted);">Installment #{{ $row->installment->installment_no }}</div>@endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        <div style="display:flex;gap:24px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid var(--border);font-size:13px;">
          <span><span style="color:var(--text-muted);">Total</span> ₱{{ number_format($order->total_amount, 2) }}</span>
          <span><span style="color:var(--text-muted);">Paid</span> <strong>₱{{ number_format($paid, 2) }}</strong></span>
          <span><span style="color:var(--text-muted);">Remaining</span> <strong>₱{{ number_format($remaining, 2) }}</strong></span>
        </div>
      </div>
    </div>

    {{-- Record payment --}}
    @if($remaining > 0 && $order->order_status !== 'cancelled' && $order->payment)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Record payment</div></div>
        <div class="admin-panel-body">
          <form method="POST" action="{{ route('admin.orders.payments.store', $order->id) }}" id="record-payment-form">
            @csrf
            <input type="hidden" name="form_token" value="{{ $formToken }}"/>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;">
              <div>
                <label for="rp-amount" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Amount (₱), up to {{ number_format($remaining, 2) }}</label>
                <input id="rp-amount" name="amount" type="text" inputmode="decimal" class="form-input" required value="{{ old('amount') }}"/>
              </div>
              <div>
                <label for="rp-method" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Method</label>
                <select id="rp-method" name="method" class="form-input" required>
                  @foreach(\App\Support\RecordPayment::ADMIN_METHODS as $m)
                    <option value="{{ $m }}" @selected(old('method', 'cash') === $m)>{{ $methodLabels[$m] }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="rp-date" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Paid on</label>
                <input id="rp-date" name="paid_on" type="date" class="form-input" required
                       max="{{ now('Asia/Manila')->toDateString() }}" value="{{ old('paid_on', now('Asia/Manila')->toDateString()) }}"/>
              </div>
              @if($unpaidRows->isNotEmpty())
                <div>
                  <label for="rp-inst" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Applies to installment (optional)</label>
                  <select id="rp-inst" name="installment_id" class="form-input">
                    <option value="">Not linked</option>
                    @foreach($unpaidRows as $row)
                      <option value="{{ $row->id }}" @selected((string) old('installment_id') === (string) $row->id)>
                        #{{ $row->installment_no }} · ₱{{ number_format($row->amount_due, 2) }} · due {{ $row->due_date->format('M d, Y') }}
                      </option>
                    @endforeach
                  </select>
                </div>
              @endif
            </div>
            <div style="margin-top:14px;">
              <label for="rp-note" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Note (optional)</label>
              <textarea id="rp-note" name="note" rows="2" maxlength="1000" class="form-input">{{ old('note') }}</textarea>
            </div>
            <button type="submit" class="admin-btn admin-btn-gold" style="margin-top:14px;" id="rp-submit">Record payment</button>
          </form>
          <p style="font-size:12px;color:var(--text-muted);margin:14px 0 0;">
            Refunds are not handled in the system. Handle them outside it and write what was done in the order note.
          </p>
        </div>
      </div>
      <script>
        (function () {
          var f = document.getElementById('record-payment-form');
          if (!f) return;
          f.addEventListener('submit', function () {
            var b = document.getElementById('rp-submit');
            if (b) { b.disabled = true; }
          });
        })();
      </script>
    @elseif($remaining <= 0)
      <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 20px;">
        Paid in full. Refunds are not handled in the system; write any refund in the order note.
      </p>
    @endif

    {{-- Customer instructions --}}
    @if($order->customization_note)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Customer Instructions</div></div>
        <div class="admin-panel-body" style="font-size:13.5px;color:var(--text-muted);">
          {{ $order->customization_note }}
        </div>
      </div>
    @endif

    {{-- Admin notes --}}
    @if($order->notes)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Admin Notes</div></div>
        <div class="admin-panel-body" style="font-size:13.5px;color:var(--text-muted);">
          {{ $order->notes }}
        </div>
      </div>
    @endif
  </div>

  <div>
    {{-- Customer info --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Customer</div></div>
      <div class="admin-panel-body">
        <div style="font-size:14px;font-weight:600;color:var(--brown-deep);margin-bottom:4px;">
          {{ $order->user->first_name }} {{ $order->user->last_name }}
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:2px;">{{ $order->user->email }}</div>
        <div style="font-size:13px;color:var(--text-muted);">{{ $order->user->phone_number }}</div>
      </div>
    </div>

    {{-- Order summary --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Summary</div></div>
      <div class="admin-panel-body">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Delivery Option</span>
          <span style="text-transform:capitalize;">{{ $order->delivery_option }}</span>
        </div>

        @if($order->delivery_option === 'delivery' && $order->delivery_address)
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;padding:8px;background:var(--cream);border-radius:8px;">
            {{ $order->delivery_address }}
          </div>
        @endif

        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Delivery Fee</span>
          <span>₱{{ number_format($order->delivery_fee, 2) }}</span>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Production Status</span>
          <span class="admin-pill {{ $order->production_status }}" style="font-size:10px;">{{ str_replace('_',' ',$order->production_status) }}</span>
        </div>

        <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:var(--brown-deep);padding-top:10px;border-top:2px solid var(--brown-deep);margin-top:10px;">
          <span>Total</span>
          <span>₱{{ number_format($order->total_amount, 2) }}</span>
        </div>
      </div>
    </div>

    {{-- Payment details --}}
    @if($order->payment)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Payment</div></div>
        <div class="admin-panel-body">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
            <span style="color:var(--text-muted);">Method</span>
            <span style="text-transform:capitalize;">{{ $order->payment->payment_method }}</span>
          </div>

          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
            <span style="color:var(--text-muted);">Type</span>
            <span>{{ $order->payment->payment_type === 'installment' ? 'Installment' : 'Full Payment' }}</span>
          </div>

          @if($order->payment->payment_type === 'installment')
            @php
              $installmentMonths = $order->payment->installment_duration_months;
              $installmentFreqLabel = $order->payment->installment_frequency_days == 15 ? '15 Days' : '1 Month';
            @endphp
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:8px;padding:8px;background:var(--cream);border-radius:8px;">
              <span>Plan</span>
              <span>{{ $installmentMonths }} {{ Str::plural('Month', $installmentMonths) }} · Every {{ $installmentFreqLabel }}</span>
            </div>
          @endif

          <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:8px;">
            <span style="color:var(--text-muted);">Status</span>
            <span class="admin-pill {{ $order->payment->payment_status }}" style="font-size:10px;">{{ $order->payment->payment_status }}</span>
          </div>

          <div style="display:flex;justify-content:space-between;font-size:13px;padding-top:10px;border-top:1px solid var(--border);">
            <span style="color:var(--text-muted);">Paid On</span>
            <span>{{ $order->payment->paid_at ? $order->payment->paid_at->format('M d, Y · h:i A') : '—' }}</span>
          </div>
        </div>
      </div>
    @endif

    {{-- Update status --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Update Status</div></div>
      <div class="admin-panel-body">
        @if($order->statusUpdatedBy)
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border);">
            Last updated by <strong style="color:var(--brown-deep);">{{ $order->statusUpdatedBy->first_name }} {{ $order->statusUpdatedBy->last_name }}</strong>
            on {{ $order->status_updated_at?->format('M d, Y · h:i A') }}
          </div>
        @endif
        <form method="POST" action="{{ route('admin.orders.updateStatus', $order->id) }}">
          @csrf
          <select name="order_status" class="form-input" style="margin-bottom:12px;">
            @foreach(['pending','confirmed','in_production','ready','out_for_delivery','completed','cancelled'] as $s)
              <option value="{{ $s }}" {{ $order->order_status === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
            @endforeach
          </select>
          <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:11px;">
            Update Status
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

@endsection