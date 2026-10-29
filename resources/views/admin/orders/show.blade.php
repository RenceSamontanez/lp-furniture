@extends('layouts.admin')

@section('title', 'Order Details — Admin')
@section('page-title', 'Order ' . $order->order_number)

@section('content')

<a href="{{ route('admin.orders.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">Back to Orders</a>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

  <div>
    {{-- Items --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Order Items</div>
        <span class="admin-pill {{ $order->order_status }}">@titlecase(str_replace('_',' ',$order->order_status))</span>
      </div>
      <div class="admin-panel-body" style="padding:0;">
        <table class="admin-table">
          <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
          <tbody>
            @foreach($order->items as $item)
              <tr>
                <td data-label="Product">{{ $order->design_agreement_id ? ($item->product_name ?: 'Custom order') : ($item->product->product_name ?? 'N/A') }}
                  @php $itemType = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['design']['furniture_type'] ?? null) : null; @endphp
                  @if($itemType)<div style="font-size:11.5px;color:var(--text-muted);">Furniture type: {{ $itemType }}</div>@endif
                  @php $itemWood = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['design']['wood'] ?? null) : null; @endphp
                  @if($itemWood)<div style="font-size:11.5px;color:var(--text-muted);">Wood: {{ $itemWood['label'] }}@if(($itemWood['supply'] ?? null) === 'customer') <strong>(customer-supplied material)</strong>@endif</div>@endif
                  @php $itemQ = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['quote'] ?? null) : null; @endphp
                  @if($itemQ && isset($itemQ['width_cm']))<div style="font-size:11.5px;color:var(--text-muted);">Size (length x width x height): {{ \App\Support\Dimensions::triplet($itemQ['width_cm'], $itemQ['depth_cm'], $itemQ['height_cm'], $itemQ['dimension_unit'] ?? null) }}</div>@endif
                </td>
                <td data-label="Qty">{{ $item->quantity }}</td>
                <td data-label="Price">₱{{ number_format($item->unit_price, 2) }}</td>
                <td data-label="Subtotal">₱{{ number_format($item->subtotal, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    {{-- Customized order: agreement, custom order, deadline --}}
    @if($order->design_agreement_id && $order->agreement)
      <div class="admin-panel">
        <div class="admin-panel-header">
          <div class="admin-panel-title">Customized Order</div>
          <span style="display:flex;gap:6px;flex-wrap:wrap;">
            <span class="admin-pill confirmed">Customized Order</span>
            @if($order->isOverdueUnpaid())<span class="admin-pill rejected">Overdue, Unpaid</span>@endif

          </span>
        </div>
        <div class="admin-panel-body" style="font-size:13.5px;">
          <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;">
            <span style="color:var(--text-muted);">Agreement</span>
            <a href="{{ route('admin.agreements.show', $order->agreement->id) }}">{{ $order->agreement->agreement_number }}</a>
          </div>
          @if($order->design_request_id)
            <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;">
              <span style="color:var(--text-muted);">Custom order</span>
              <a href="{{ route('admin.custom-orders.show', $order->design_request_id) }}">Open the custom order</a>
            </div>
          @endif
          <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;">
            <span style="color:var(--text-muted);">Payment due by</span>
            <span>{{ $order->designRequest?->payment_due_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? '—' }}</span>
          </div>
          <div style="display:flex;justify-content:space-between;gap:12px;">
            <span style="color:var(--text-muted);">Expected ready date</span>
            <span>{{ $order->expected_ready_date ? 'about ' . \Illuminate\Support\Carbon::parse($order->expected_ready_date)->format('M d, Y') : 'Set when the first payment is received' }}</span>
          </div>
          @if($order->isOverdueUnpaid())
            <p style="font-size:12px;color:var(--text-muted);margin:12px 0 0;">
              The payment window has passed and nothing has been received. The order is not cancelled automatically; cancel it below or record a payment.
            </p>
          @endif
        </div>
      </div>
    @endif

    {{-- Needs review (duplicate or late payment) --}}
    @if($order->needs_review)
      <div class="admin-panel" style="border-color:#E8C27A;">
        <div class="admin-panel-header">
          <div class="admin-panel-title">Needs Review</div>
          <form method="POST" action="{{ route('admin.orders.review', $order->id) }}">
            @csrf
            <button type="submit" class="admin-btn admin-btn-gold">Mark Reviewed</button>
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
        <div class="admin-panel-title">Payments Received</div>
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
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th><th>Note</th></tr></thead>
            <tbody>
              @foreach($ledger as $row)
                <tr>
                  <td data-label="Date">{{ $row->paid_on->format('M d, Y') }}</td>
                  <td data-label="Amount">₱{{ number_format($row->amount, 2) }}</td>
                  <td data-label="Method">{{ $methodLabels[$row->method] ?? $row->method }}</td>
                  <td data-label="Reference" style="overflow-wrap:anywhere;">{{ $row->gateway_reference ?: '—' }}</td>
                  <td data-label="Recorded By">{{ $row->recorder ? $row->recorder->first_name . ' ' . $row->recorder->last_name : 'System' }}</td>
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
        <div class="admin-panel-header"><div class="admin-panel-title">Record Payment</div></div>
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
                <label for="rp-date" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Paid On</label>
                <input id="rp-date" name="paid_on" type="date" class="form-input" required
                       max="{{ now('Asia/Manila')->toDateString() }}" value="{{ old('paid_on', now('Asia/Manila')->toDateString()) }}"/>
              </div>
              @if($unpaidRows->isNotEmpty())
                <div>
                  <label for="rp-inst" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Applies to Installment (Optional)</label>
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
              <label for="rp-note" style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px;">Note (Optional)</label>
              <textarea id="rp-note" name="note" rows="2" maxlength="1000" class="form-input">{{ old('note') }}</textarea>
            </div>
            <button type="submit" class="admin-btn admin-btn-gold" style="margin-top:14px;" id="rp-submit">Record Payment</button>
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
          @php $prodStatus = $order->design_agreement_id ? $order->order_status : $order->production_status; @endphp
          <span class="admin-pill {{ $prodStatus }}" style="font-size:10px;">@titlecase(str_replace('_',' ',$prodStatus))</span>
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
            @if($order->design_agreement_id)
              @php
                $usedMethods = $ledger->pluck('method')->unique()->map(fn ($m) => $methodLabels[$m] ?? $m)->values();
              @endphp
              <span>{{ $usedMethods->isNotEmpty() ? $usedMethods->implode(', ') : ($methodLabels[$order->payment->payment_method === 'gcash' ? 'gcash_paymongo' : $order->payment->payment_method] ?? ucfirst($order->payment->payment_method)) . ' (chosen, not paid yet)' }}</span>
            @else
              <span style="text-transform:capitalize;">{{ $order->payment->payment_method }}</span>
            @endif
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
            @if($order->design_agreement_id)
              @php
                $overall = $paid + 0.001 >= (float) $order->total_amount ? ['paid', 'Paid in full']
                    : ($paid > 0 ? ['partial', 'Partially paid'] : ['pending', 'Unpaid']);
              @endphp
              <span class="admin-pill {{ $overall[0] }}" style="font-size:10px;">@titlecase($overall[1])</span>
            @else
              <span class="admin-pill {{ $order->payment->payment_status }}" style="font-size:10px;">@titlecase($order->payment->payment_status)</span>
            @endif
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
        @if($order->design_agreement_id)
          @php
            $forward = \App\Support\CustomOrderStatus::forwardTargets($order);
            $cancellable = \App\Support\CustomOrderStatus::canCancel($order);
          @endphp
          @if($errors->has('status'))
            <div class="admin-toast admin-toast-error" style="position:static;margin-bottom:12px;">{{ $errors->first('status') }}</div>
          @endif
          <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;">
            Customized orders only move forward: confirmed, in production, ready or out for delivery, completed.
          </p>
          @if($forward || $cancellable)
            <form method="POST" action="{{ route('admin.orders.updateStatus', $order->id) }}">
              @csrf
              <select name="order_status" class="form-input" style="margin-bottom:12px;" id="co-status-select">
                <option value="{{ $order->order_status }}" selected>Currently {{ str_replace('_',' ',$order->order_status) }}</option>
                @foreach($forward as $s)
                  <option value="{{ $s }}">{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                @endforeach
                @if($cancellable)
                  <option value="cancelled">Cancel this order</option>
                @endif
              </select>
              @if($paid + 0.001 < (float) $order->total_amount)
                <textarea name="override_reason" rows="2" maxlength="1000" class="form-input" style="margin-bottom:12px;"
                          placeholder="Only to complete with a balance outstanding: why (kept in the order note)">{{ old('override_reason') }}</textarea>
              @endif
              @if($cancellable)
                <textarea name="cancel_reason" rows="2" maxlength="1000" class="form-input" style="margin-bottom:12px;"
                          placeholder="Reason for cancelling (required if money was received; refunds are handled outside the system)">{{ old('cancel_reason') }}</textarea>
              @endif
              <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:11px;">
                Update Status
              </button>
            </form>
          @else
            <p style="font-size:13px;margin:0;">This order is {{ str_replace('_',' ',$order->order_status) }}; no further changes.</p>
          @endif
        @else
          <p style="font-size:13px;margin:0;">Catalog orders are updated from their Update progress card.</p>
        @endif
      </div>
    </div>
  </div>

</div>

@endsection