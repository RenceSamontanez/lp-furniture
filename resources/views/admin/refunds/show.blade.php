@extends('layouts.admin')

@section('title', $req->reference . ' — Admin')
@section('page-title', 'Cancellation request')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-refunds.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php
  $r     = $req;
  $t     = $r->inquiry;
  $peso  = fn ($n) => '₱' . number_format((float) $n, 2);
  $when  = fn ($d) => $d?->timezone('Asia/Manila')->format('M d, Y h:i A');
  $day   = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $open  = $r->isOpen();
  $chip  = in_array($r->status, ['declined', 'closed'], true) ? 'rf-' . $r->status : $r->status;
  $starters = \App\Support\ServiceRefundRequestService::DECLINE_STARTERS;
@endphp

<a href="{{ route('admin.refunds.index') }}" class="lp-btn lp-btn--secondary" style="margin-bottom:20px;">← Back to Cancellations &amp; Refunds</a>

@if($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('request') ? $errors->first('request') : $errors->first() }}</div>
@endif

@if($r->status === 'new')
  <x-notice variant="info" title="New request: waiting for your decision">Payments on this service are paused until you decide.</x-notice>
@elseif($r->status === 'needs_info')
  <x-notice variant="waiting" title="Waiting for the customer">You asked for more information. Payments stay paused until you decide.</x-notice>
@elseif($r->status === 'approved')
  <x-notice variant="{{ $r->isOverdue() ? 'problem' : 'waiting' }}" title="{{ $r->isOverdue() ? 'Refund overdue' : 'Approved: send the refund' }}">
    {{ $peso($r->approved_amount) }} by {{ $r->methodLabel() }}, due by {{ $day($r->refund_due_at) }}. The refund is paid outside the system, then recorded here.
  </x-notice>
@elseif($r->status === 'refund_problem')
  <x-notice variant="problem" title="The refund did not go through">The customer was asked to send the correct details. When they do, the request returns to Approved.</x-notice>
@elseif($r->status === 'refunded')
  <x-notice variant="success" title="Refunded">The service request and invoice are cancelled.</x-notice>
@elseif($r->status === 'declined')
  <x-notice variant="blocked" title="Declined">The hold is lifted. The invoice can be paid again if it could be before.</x-notice>
@endif

@if($r->approved_as_exception)
  <x-notice variant="info" title="Approved as an exception">{{ $r->exception_reason }}</x-notice>
@endif

<div class="co-detail">
  <div>
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">{{ $r->reference }}</div>
        <span class="co-chip co-chip--{{ $chip }}">{{ $r->statusLabel() }}</span>
      </div>
      <div class="admin-panel-body">
        <dl class="co-dl">
          <div><dt>Customer</dt><dd>{{ $t->name }}@if($t->user) ({{ $t->user->email }})@endif</dd></div>
          <div><dt>Contact phone</dt><dd><a href="tel:{{ $r->contact_phone }}">{{ $r->contact_phone }}</a></dd></div>
          <div><dt>Contact email</dt><dd><a href="mailto:{{ $r->contact_email }}">{{ $r->contact_email }}</a></dd></div>
          <div><dt>Service request</dt><dd><a href="{{ route('admin.service-inquiries.show', $t->id) }}">{{ $t->reference_number }}</a> · {{ $t->serviceLabel() }}</dd></div>
          <div><dt>Invoice</dt><dd>@if($r->invoice)<a href="{{ route('admin.service-invoices.show', $r->invoice->id) }}">{{ $r->invoice->invoice_number }}</a> · {{ $peso($r->invoice->total) }}@else None @endif</dd></div>
          <div><dt>Paid</dt><dd>{{ $peso($paidCents / 100) }} now ({{ $peso($r->amount_paid_snapshot) }} when requested)</dd></div>
          <div><dt>Work state (live)</dt><dd><strong>{{ \App\Support\ServiceWorkState::label($work) }}</strong> (was {{ strtolower(\App\Support\ServiceWorkState::label($r->work_state_snapshot)) }} when requested)</dd></div>
          <div><dt>Source</dt><dd>{{ \App\Models\ServiceRefundRequest::SOURCES[$r->source] ?? $r->source }} · {{ $when($r->created_at) }}</dd></div>
          <div><dt>Reason</dt><dd>{{ $r->reasonLabel() }}</dd></div>
          <div>
            <dt>Refund method</dt>
            <dd>
              {{ $r->methodLabel() ?? 'No refund needed (nothing paid)' }}
            </dd>
          </div>
          @if($r->refund_method === 'gcash')
            <div>
              <dt>GCash Number</dt>
              <dd class="rf-copy"><strong id="rf-gcash" class="rf-mask">{{ $r->gcash_number }}</strong>
                <button type="button" class="lp-btn lp-btn--chip lp-btn--secondary" id="rf-copy" data-copy-from="rf-gcash">Copy</button></dd>
            </div>
            <div><dt>GCash Initials</dt><dd>{{ $r->gcash_account_name ?: '—' }}</dd></div>
          @endif
          @if($r->approved_amount !== null)<div><dt>Approved amount</dt><dd>{{ $peso($r->approved_amount) }} · by {{ $day($r->refund_due_at) }}@if($r->decider) · approved by {{ $r->decider->first_name }}@endif</dd></div>@endif
        </dl>

        @if($r->reason_text)
          <div class="co-block-label">Details from the customer</div>
          <div class="co-quote si-concern">{{ $r->reason_text }}</div>
        @endif
        @if($r->decision_note)
          <div class="co-block-label">Explanation sent to the customer</div>
          <div class="co-quote si-concern">{{ $r->decision_note }}</div>
        @endif
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Messages</div></div>
      <div class="admin-panel-body">
        <ul class="rf-thread">
          @foreach($r->messages as $m)
            <li class="rf-msg rf-msg--{{ $m->sender }}">
              <span class="rf-msg-meta">{{ $m->sender === 'customer' ? $t->name : ($m->sender === 'admin' ? ($m->admin?->first_name ?: 'Team') : 'System') }} · {{ $when($m->created_at) }}</span>
              <span class="rf-msg-body">{{ $m->body }}</span>
            </li>
          @endforeach
        </ul>
      </div>
    </div>

    @if($r->attempts->isNotEmpty())
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Refund attempts</div></div>
      <div class="admin-panel-body">
        <table class="rf-attempts">
          <thead><tr><th>When</th><th>Amount</th><th>How</th><th>Transaction no.</th><th>Result</th></tr></thead>
          <tbody>
            @foreach($r->attempts as $a)
              <tr>
                <td>{{ $when($a->created_at) }}@if($a->admin)<br/>{{ $a->admin->first_name }}@endif</td>
                <td>{{ $peso($a->amount) }}</td>
                <td>{{ \App\Models\ServiceRefundRequest::METHODS[$a->method] ?? $a->method }}</td>
                <td>{{ $a->transaction_number ?: '—' }}</td>
                <td>{{ $a->outcome === 'sent' ? 'Sent' . ($a->sent_on ? ' on ' . $a->sent_on->format('M d, Y') : '') : 'Did not go through' }}@if($a->note)<br/><span class="si-hint">{{ $a->note }}</span>@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif
  </div>

  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Actions</div></div>
      <div class="admin-panel-body rf-actions">
        @if(in_array($r->status, ['new', 'needs_info'], true))
          @if($work === 'not_started')
            <button type="button" class="lp-btn lp-btn--primary lp-btn--block" data-lp-modal-open="rf-approve-modal">Approve{{ $paidCents > 0 ? ' and refund ' . $peso($paidCents / 100) : '' }}</button>
            <form method="POST" action="{{ route('admin.refunds.approve', $r->id) }}" id="rf-approve-form">@csrf</form>
          @else
            <x-notice variant="blocked" title="Work has already started">
              A normal approval is not offered. You can decline with an explanation, or approve as an exception.
            </x-notice>
            <details class="rf-action">
              <summary>Approve as an exception</summary>
              <form method="POST" action="{{ route('admin.refunds.approve-exception', $r->id) }}" id="rf-exc-form" data-lp-gate="rf-exc-modal">
                @csrf
                <label class="co-modal-label" for="rf-exc" style="margin-top:0;">Why is this an exception? (required)</label>
                <textarea name="reason" id="rf-exc" class="form-input" rows="3" minlength="10" maxlength="1000" required>{{ old('reason') }}</textarea>
                <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" style="margin-top:10px;">Approve as an exception</button>
              </form>
            </details>
          @endif

          <details class="rf-action">
            <summary>Decline with an explanation</summary>
            <form method="POST" action="{{ route('admin.refunds.decline', $r->id) }}" id="rf-decline-form" data-lp-gate="rf-decline-modal">
              @csrf
              <label class="co-modal-label" for="rf-starter" style="margin-top:0;">Start from</label>
              <select id="rf-starter" class="form-input">
                <option value="work_started">Work has already started</option>
                <option value="work_done">Work is already done</option>
                <option value="other">Other</option>
              </select>
              <label class="co-modal-label" for="rf-expl">Explanation for the customer (at least 20 characters)</label>
              <textarea name="explanation" id="rf-expl" class="form-input" rows="4" minlength="20" maxlength="1000" required>{{ old('explanation') }}</textarea>
              <button type="submit" class="lp-btn lp-btn--danger lp-btn--block" style="margin-top:10px;">Decline</button>
            </form>
          </details>

          <details class="rf-action">
            <summary>Ask the customer for more information</summary>
            <form method="POST" action="{{ route('admin.refunds.ask-info', $r->id) }}">
              @csrf
              <label class="co-modal-label" for="rf-ask" style="margin-top:0;">Your question</label>
              <textarea name="message" id="rf-ask" class="form-input" rows="3" minlength="5" maxlength="1000" required>{{ old('message') }}</textarea>
              <button type="submit" class="lp-btn lp-btn--secondary lp-btn--block" style="margin-top:10px;" data-loading="Sending...">Send question</button>
            </form>
          </details>

        @elseif($r->status === 'approved')
          <details class="rf-action" open>
            <summary>Mark refund as sent</summary>
            <form method="POST" action="{{ route('admin.refunds.mark-refunded', $r->id) }}" id="mr-form" data-lp-gate="rf-sent-modal" novalidate>
              @csrf
              <label class="co-modal-label" for="mr-amount" style="margin-top:0;">Amount sent (₱)</label>
              <input type="text" inputmode="decimal" name="amount" id="mr-amount" class="form-input" required value="{{ old('amount', number_format((float) $r->approved_amount, 2, '.', '')) }}"/>
              <label class="co-modal-label" for="mr-method">How it was sent</label>
              <select name="method" id="mr-method" class="form-input" required>
                @foreach(\App\Models\ServiceRefundRequest::METHODS as $k => $label)<option value="{{ $k }}" @selected(old('method', $r->refund_method) === $k)>{{ $label }}</option>@endforeach
              </select>
              <label class="co-modal-label" for="mr-tx">Transaction number</label>
              <input type="text" name="transaction_number" id="mr-tx" class="form-input" maxlength="60" required autocomplete="off" aria-describedby="mr-tx-hint mr-tx-error" value="{{ old('transaction_number') }}"/>
              <p class="si-hint rf-tx-hint" id="mr-tx-hint">GCash reference numbers are usually 10 to 13 digits, for example 6028 871 884223. Spaces and dashes are ignored.</p>
              <p class="rf-tx-error" id="mr-tx-error" role="alert" hidden></p>
              <div id="mr-override-wrap" hidden>
                <label class="lp-modal__check" for="mr-override" style="margin-top:6px;">
                  <input type="checkbox" name="reference_override" id="mr-override" value="1" @checked(old('reference_override'))/>
                  <span>This reference does not look like a normal GCash number</span>
                </label>
                <label class="co-modal-label" for="mr-override-reason">Why is it still correct? (at least 10 characters)</label>
                <textarea name="reference_override_reason" id="mr-override-reason" class="form-input" rows="2" maxlength="300">{{ old('reference_override_reason') }}</textarea>
              </div>
              <label class="co-modal-label" for="mr-date">Date sent</label>
              <input type="date" name="sent_on" id="mr-date" class="form-input" required max="{{ now('Asia/Manila')->toDateString() }}" value="{{ old('sent_on', now('Asia/Manila')->toDateString()) }}"/>
              <label class="co-modal-label" for="mr-note">Note (required only if the amount differs from the approved amount)</label>
              <textarea name="note" id="mr-note" class="form-input" rows="2" maxlength="1000">{{ old('note') }}</textarea>
              <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" style="margin-top:10px;">Mark refund as sent</button>
            </form>
          </details>

          <details class="rf-action">
            <summary>Refund did not go through</summary>
            <form method="POST" action="{{ route('admin.refunds.refund-failed', $r->id) }}" id="rf-failed-form" data-lp-gate="rf-failed-modal">
              @csrf
              <label class="co-modal-label" for="rf-problem" style="margin-top:0;">What went wrong</label>
              <select name="problem" id="rf-problem" class="form-input" required>
                @foreach(\App\Support\ServiceRefundRequestService::PROBLEMS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
              </select>
              <label class="co-modal-label" for="rf-pnote">Note</label>
              <textarea name="note" id="rf-pnote" class="form-input" rows="2" maxlength="1000"></textarea>
              <button type="submit" class="lp-btn lp-btn--danger lp-btn--block" style="margin-top:10px;">Refund did not go through</button>
            </form>
          </details>

        @elseif($r->status === 'refund_problem')
          <x-notice variant="waiting" title="Waiting for corrected details">The customer was told. You will be notified when they reply.</x-notice>
        @else
          <p class="co-note-closed">This request is {{ strtolower($r->statusLabel()) }}. No further action is needed.</p>
        @endif
      </div>
    </div>
  </div>
</div>

@if(in_array($r->status, ['new', 'needs_info'], true))
  @if($work === 'not_started')
  <x-modal id="rf-approve-modal" title="Approve this request?">
    <p>{{ $paidCents > 0 ? 'The customer will get back the full ' . $peso($paidCents / 100) . ' by ' . $r->methodLabel() . ', within ' . \App\Models\ServiceRefundRequest::REFUND_DAYS . ' days.' : 'Nothing was paid, so the service request will be cancelled.' }}</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
      <button type="submit" form="rf-approve-form" class="lp-btn lp-btn--primary" data-lp-confirm>Approve</button>
    </x-slot:footer>
  </x-modal>
  @else
  <x-modal id="rf-exc-modal" title="Approve as an exception?">
    <p>Work has already started. This is flagged and logged with your reason.</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
      <button type="button" class="lp-btn lp-btn--primary" data-lp-gate-submit="rf-exc-form" data-lp-confirm>Approve as an exception</button>
    </x-slot:footer>
  </x-modal>
  @endif
  <x-modal id="rf-decline-modal" title="Decline this request?">
    <p>The customer will read your explanation and payments open again.</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
      <button type="button" class="lp-btn lp-btn--danger" data-lp-gate-submit="rf-decline-form" data-lp-confirm>Decline</button>
    </x-slot:footer>
  </x-modal>
@elseif($r->status === 'approved')
  <x-modal id="rf-sent-modal" title="Mark this refund as sent?" summary-form="mr-form">
    <dl class="lp-modal__facts">
      <dt>Amount</dt><dd>₱<span data-summary="amount"></span></dd>
      <dt>Method</dt><dd data-summary="method"></dd>
      @if($r->refund_method === 'gcash')
        <dt>GCash number</dt><dd>{{ $r->gcash_number }}</dd>
        <dt>GCash name</dt><dd>{{ $r->gcash_account_name }}</dd>
      @endif
      <dt>Transaction no.</dt><dd data-summary="transaction_number"></dd>
      <dt>Date sent</dt><dd data-summary="sent_on"></dd>
      <dt data-summary-if="reference_override=1">Override</dt><dd data-summary-if="reference_override=1" data-summary="reference_override_reason"></dd>
    </dl>
    <x-notice variant="info" title="Check the details first.">The service request and invoice will be cancelled and the customer told. A sent refund cannot be recalled or sent again.</x-notice>
    <label class="lp-modal__check" for="rf-sent-tick"><input type="checkbox" id="rf-sent-tick" data-lp-reset/> <span>I sent this refund and the details match</span></label>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
      <button type="button" class="lp-btn lp-btn--primary" data-lp-gate-submit="mr-form" data-lp-requires="rf-sent-tick" data-lp-confirm>Mark refund as sent</button>
    </x-slot:footer>
  </x-modal>
  <x-modal id="rf-failed-modal" title="Record that the refund did not go through?">
    <p>The customer will be asked to send the right details.</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
      <button type="button" class="lp-btn lp-btn--danger" data-lp-gate-submit="rf-failed-form" data-lp-confirm>Record it</button>
    </x-slot:footer>
  </x-modal>
@endif
</div>
@endsection

@push('scripts')
<script>
(function () {
  var starters = @json($starters);
  var pick = document.getElementById('rf-starter');
  var box = document.getElementById('rf-expl');
  if (pick && box) {
    pick.addEventListener('change', function () { if (box.value.trim() === '' || Object.values(starters).indexOf(box.value) !== -1) { box.value = starters[pick.value] || ''; } });
    box.value = box.value || starters[pick.value] || '';
  }
  // Transaction number: live hint, inline error, and the override box (a warning, not a block).
  var tx = document.getElementById('mr-tx'), method = document.getElementById('mr-method');
  if (tx && method) {
    var err = document.getElementById('mr-tx-error'), hint = document.getElementById('mr-tx-hint');
    var wrap = document.getElementById('mr-override-wrap'), box = document.getElementById('mr-override');
    var check = function () {
      var v = tx.value.trim(), ok = true, msg = '';
      if (method.value === 'cash') {
        hint.textContent = 'A cash receipt or voucher number: 4 to 20 letters, digits or dashes.';
        ok = v === '' || /^[A-Za-z0-9-]{4,20}$/.test(v);
        msg = 'A cash receipt number is 4 to 20 letters, digits or dashes.';
        wrap.hidden = true;
      } else {
        hint.textContent = 'GCash reference numbers are usually 10 to 13 digits, for example 6028 871 884223. Spaces and dashes are ignored.';
        ok = v === '' || /^\d{10,13}$/.test(v.replace(/[\s-]/g, ''));
        msg = 'This does not look like a normal GCash reference (10 to 13 digits). Check it, or tick the box below and write a reason.';
        wrap.hidden = ok;
        if (ok) { box.checked = false; }
      }
      err.hidden = ok; err.textContent = ok ? '' : msg;
      tx.setAttribute('aria-invalid', ok ? 'false' : 'true');
    };
    tx.addEventListener('input', check); method.addEventListener('change', check); check();
    document.getElementById('mr-form').addEventListener('submit', function (e) {
      var v = tx.value.trim(), gcash = method.value !== 'cash';
      var good = gcash ? /^\d{10,13}$/.test(v.replace(/[\s-]/g, '')) : /^[A-Za-z0-9-]{4,20}$/.test(v);
      var reasonOk = document.getElementById('mr-override-reason').value.trim().length >= 10;
      if (v === '' || (!good && (!gcash || !box.checked || !reasonOk))) {
        e.preventDefault(); e.stopImmediatePropagation();
        check();
        if (v === '') { err.hidden = false; err.textContent = 'Enter the transaction number.'; }
        else if (gcash && !good && box.checked && !reasonOk) { err.hidden = false; err.textContent = 'Write a short reason (at least 10 characters).'; }
        tx.focus();
      }
    }, true);
  }
  document.querySelectorAll('[data-copy-from]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.getAttribute('data-copy-from'));
      if (el && navigator.clipboard) { navigator.clipboard.writeText(el.textContent.trim()); b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); }
    });
  });
})();
</script>
@endpush
