@extends('layouts.admin')

@section('title', 'Service Ticket — Admin')
@section('page-title', 'Service Ticket')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-invoices.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php
  $t = $ticket;
  $noteLines = $t->admin_notes ? array_reverse(explode("\n", $t->admin_notes)) : [];
@endphp

<a href="{{ route('admin.service-inquiries.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Other Services</a>

@php $ctx = $ctx ?? \App\Support\ServiceRefundRequestService::context($t); $work = $ctx['work']; @endphp

@if(session('offer_cancellation_request'))
  <x-notice variant="blocked" title="This service has a payment on it">
    This service has a payment on it, so it must go through a cancellation request so the refund is recorded.
    <x-slot:actions><a href="#cancellation-request" class="lp-btn lp-btn--primary" data-open-cancellation>Open a cancellation request</a></x-slot:actions>
  </x-notice>
@elseif($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('ticket') ? $errors->first('ticket') : $errors->first() }}</div>
@endif

@if($t->isCancelled())
  <x-notice variant="blocked" title="Cancelled">{{ $t->closed_reason ?: 'This ticket was cancelled.' }}</x-notice>
@elseif($ctx['open'])
  <x-notice variant="blocked" title="On hold: cancellation requested">
    Request {{ $ctx['open']->reference }} is {{ strtolower($ctx['open']->statusLabel()) }}. Payments are paused until it is decided.
    <x-slot:actions><a href="{{ route('admin.refunds.show', $ctx['open']->id) }}" class="lp-btn lp-btn--primary">Open the request</a></x-slot:actions>
  </x-notice>
@elseif($work !== 'not_started' && $t->isOpen())
  <x-notice variant="info" title="{{ $work === 'done' ? 'Service done' : 'Work started' }}">
    The customer can no longer get an automatic refund. A refund now needs an exception.
  </x-notice>
@endif

<div class="co-detail">
  <div>
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">{{ $t->reference_number }}</div>
        <span class="co-chip co-chip--{{ $t->status }}">{{ $t->statusLabel() }}</span>
      </div>
      <div class="admin-panel-body">
        <dl class="co-dl">
          <div><dt>Name</dt><dd>{{ $t->name }}</dd></div>
          <div><dt>Phone</dt><dd><a href="tel:{{ $t->phone }}">{{ $t->phone }}</a></dd></div>
          <div><dt>Email</dt><dd>@if($t->email)<a href="mailto:{{ $t->email }}">{{ $t->email }}</a>@else — @endif</dd></div>
          <div><dt>Account</dt><dd>{{ $t->user ? $t->user->display_name . ' (' . $t->user->email . ')' : 'No account (walk-in or an older ticket)' }}</dd></div>
          <div><dt>Service</dt><dd>{{ $t->serviceLabel() }}</dd></div>
          <div><dt>Furniture type</dt><dd>{{ $t->furniture_type ?: '—' }}</dd></div>
          <div><dt>Preferred call time</dt><dd>{{ $t->callTimeSummary() ?? 'Any time, Monday to Friday, 8 AM to 5 PM' }}</dd></div>
          <div><dt>Received</dt><dd>{{ $t->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}@if($t->createdBy) · entered by {{ $t->createdBy->first_name }}@endif</dd></div>
        </dl>

        <div class="co-block-label">Concern</div>
        <div class="co-quote si-concern">{{ $t->concern }}</div>

        @if($t->status === 'closed' && $t->closed_reason)
          <div class="co-block-label">Closed because</div>
          <div class="co-quote si-concern">{{ $t->closed_reason }}</div>
        @endif
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Notes and call history</div></div>
      <div class="admin-panel-body">
        @if(empty($noteLines))
          <p class="co-note-closed">Nothing recorded yet.</p>
        @else
          <ul class="si-notes">
            @foreach($noteLines as $line)
              @php [$when, $who, $text] = array_pad(explode(' · ', $line, 3), 3, ''); @endphp
              <li><span class="si-notes-when">{{ $when }} · {{ $who }}</span>{{ $text }}</li>
            @endforeach
          </ul>
        @endif

        <form method="POST" action="{{ route('admin.service-inquiries.notes', $t->id) }}" class="co-action-form" style="margin-top:16px;">
          @csrf
          <label class="co-modal-label" for="si-note" style="margin-top:0;">Add a note</label>
          <textarea name="note" id="si-note" class="form-input" rows="2" maxlength="1000" required></textarea>
          <button type="submit" class="admin-btn admin-btn-outline" style="margin-top:8px;" data-loading="Saving...">Save note</button>
        </form>
      </div>
    </div>
  </div>

  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Invoice</div></div>
      <div class="admin-panel-body">
        @if($t->invoices->isNotEmpty())
          <ul class="inv-ticket-list">
            @foreach($t->invoices as $inv)
              <li>
                <a href="{{ route('admin.service-invoices.show', $inv->id) }}">{{ $inv->invoice_number ?: 'Draft #' . $inv->id }}</a>
                <span>₱{{ number_format((float) $inv->total, 2) }}</span>
                <span class="co-chip inv-status--{{ $inv->status }}">{{ $inv->statusLabel() }}</span>
              </li>
            @endforeach
          </ul>
        @endif
        @if($t->invoices->where('status', '!=', 'void')->isEmpty())
          <a href="{{ route('admin.service-invoices.create', $t->id) }}" class="admin-btn admin-btn-gold co-wide">Create invoice</a>
          <p class="si-hint" style="margin-top:8px;">Use the price agreed in the call. Nothing is sent to the customer until you send it.</p>
        @else
          <p class="si-hint">A ticket has one live invoice at a time. Cancel it to send a new one.</p>
        @endif
      </div>
    </div>

    @if($isOpen)
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Work</div></div>
      <div class="admin-panel-body">
        @php
          $can     = \App\Support\ServiceWorkState::canStart($t);
          $peso    = fn ($v) => \App\Support\ServiceInvoiceDesk::peso($v);
          $wInv    = $ctx['invoice'] ?? \App\Support\ServiceRefundRequestService::liveInvoice($t);
          $pendingInv = $t->invoices->whereIn('status', ['draft', 'issued'])->first();
          $startedBy  = \App\Support\ServiceWorkState::startedBy($t);
          $doneInv    = $wInv && in_array($wInv->status, ['issued', 'paid'], true) && !$wInv->service_done_at ? $wInv : null;
        @endphp
        @if($work === 'done')
          <x-notice variant="success" title="Service done">Marked done on {{ $wInv?->service_done_at?->timezone('Asia/Manila')->format('M d, Y h:i A') }}.</x-notice>
        @elseif($t->service_started_at)
          <p class="si-work-line"><strong>Work started {{ $t->service_started_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}@if($startedBy), by {{ $startedBy }}@endif</strong></p>
          @if(\App\Support\ServiceWorkState::startedWithoutPayment($t))
            <x-notice variant="blocked" title="Started before payments were required">Work was marked as started before this rule existed, so nothing needs to be done.</x-notice>
          @endif
          @if($doneInv && !$ctx['open'])
            <button type="button" class="lp-btn lp-btn--primary lp-btn--block" data-lp-modal-open="si-done-modal">Mark service done</button>
          @endif
          @if(!$ctx['open'] && $work === 'started')
            <button type="button" class="lp-btn lp-btn--secondary lp-btn--block" style="margin-top:10px;" data-lp-modal-open="si-undo-modal">Undo work started</button>
          @endif
        @elseif($can === 'allowed')
          <button type="button" class="lp-btn lp-btn--primary lp-btn--block" data-lp-modal-open="si-start-modal">Start work</button>
        @elseif($can === 'no_invoice')
          <x-notice variant="info" title="Create an invoice first">Work starts after the customer pays.</x-notice>
        @elseif($can === 'invoice_draft' || $can === 'awaiting_payment')
          <x-notice variant="waiting" title="Waiting for payment of {{ $pendingInv?->invoice_number ?: 'the invoice' }}, {{ $pendingInv ? $peso(($pendingInv->installmentCents()[1] ?? $pendingInv->totalCents()) / 100) : '' }}">
            @if($can === 'invoice_draft') The invoice has not been sent yet. @else Work starts as soon as {{ $pendingInv && $pendingInv->isHalfHalf() ? 'the first half is' : 'it is' }} paid. @endif
          </x-notice>
        @elseif($can === 'on_hold')
          <x-notice variant="blocked" title="On hold">A cancellation request is open. Work cannot start until it is decided.</x-notice>
        @else
          <x-notice variant="blocked" title="Work cannot start">The invoice or the service request was cancelled.</x-notice>
        @endif
      </div>
    </div>

    @if($can === 'allowed' && $wInv)
    <x-modal id="si-start-modal" title="Start work on {{ $t->reference_number }}?">
      <dl class="lp-modal__facts">
        <dt>Invoice</dt><dd>{{ $wInv->invoice_number }}</dd>
        <dt>Payment</dt>
        <dd>@if($wInv->status === 'paid') Paid in full: {{ $peso($wInv->paidCents() / 100) }}
            @else Half paid: {{ $peso($wInv->paidCents() / 100) }} of {{ $peso($wInv->totalCents() / 100) }}. The balance of {{ $peso($wInv->balanceCents() / 100) }} is due after the service.@endif</dd>
      </dl>
      <x-notice variant="info" title="After this, the customer can no longer get an automatic refund." />
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
        <button type="submit" form="si-start-form" class="lp-btn lp-btn--primary" data-lp-confirm>Start work</button>
      </x-slot:footer>
    </x-modal>
    <form method="POST" action="{{ route('admin.service-inquiries.work-started', $t->id) }}" id="si-start-form">@csrf</form>
    @endif

    @if($doneInv && !$ctx['open'])
    <x-modal id="si-done-modal" title="Mark the service as done?">
      <p>{{ $doneInv->isHalfHalf() && $doneInv->balanceCents() > 0 ? 'The customer will be told the remaining balance of ' . $peso($doneInv->balanceCents() / 100) . ' is now due.' : 'This is recorded on the ticket.' }}</p>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
        <button type="submit" form="si-done-form" class="lp-btn lp-btn--primary" data-lp-confirm>Mark service done</button>
      </x-slot:footer>
    </x-modal>
    <form method="POST" action="{{ route('admin.service-invoices.service-done', $doneInv->id) }}" id="si-done-form">@csrf</form>
    @endif

    @if($t->service_started_at && !$ctx['open'] && $work === 'started')
    <x-modal id="si-undo-modal" title="Undo &quot;Work started&quot;?">
      <p>The customer can get an automatic refund again.</p>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
        <button type="submit" form="si-undo-form" class="lp-btn lp-btn--primary" data-lp-confirm>Undo work started</button>
      </x-slot:footer>
    </x-modal>
    <form method="POST" action="{{ route('admin.service-inquiries.work-started-undo', $t->id) }}" id="si-undo-form">@csrf</form>
    @endif

    @if(!$ctx['open'])
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Cancellation</div></div>
      <div class="admin-panel-body">
        <details class="co-more" id="cancellation-request" @if(session('offer_cancellation_request') || $errors->has('reason_code')) open @endif>
          <summary>Open a cancellation request for the customer</summary>
          <p class="si-hint">Use this when the customer asks by phone or in person. It puts the invoice on hold until you decide.@if($ctx['paid_cents'] > 0) {{ \App\Support\ServiceInvoiceDesk::peso($ctx['paid_cents'] / 100) }} has been paid.@endif</p>
          <form method="POST" action="{{ route('admin.service-inquiries.refund-request', $t->id) }}" class="co-action-form" id="cr-form" data-lp-gate="cr-modal">
            @csrf
            <label class="co-modal-label" for="cr-reason" style="margin-top:0;">Reason</label>
            <select name="reason_code" id="cr-reason" class="form-input" required>
              @foreach(\App\Models\ServiceRefundRequest::REASONS as $k => $label)
                <option value="{{ $k }}" @selected(old('reason_code', 'change_of_mind') === $k)>{{ $label }}</option>
              @endforeach
            </select>
            <label class="co-modal-label" for="cr-text">Details (optional)</label>
            <textarea name="reason_text" id="cr-text" class="form-input" rows="2" maxlength="1000">{{ old('reason_text') }}</textarea>
            <label class="co-modal-label" for="cr-phone">Customer mobile</label>
            <input type="text" name="contact_phone" id="cr-phone" class="form-input" required value="{{ old('contact_phone', $t->phone) }}"/>
            <label class="co-modal-label" for="cr-email">Customer email</label>
            <input type="email" name="contact_email" id="cr-email" class="form-input" required value="{{ old('contact_email', $t->email ?: optional($t->user)->email) }}"/>
            @if($ctx['paid_cents'] > 0)
              <label class="co-modal-label" for="cr-method">Refund method</label>
              <select name="refund_method" id="cr-method" class="form-input" required>
                @foreach(\App\Models\ServiceRefundRequest::METHODS as $k => $label)
                  <option value="{{ $k }}" @selected(old('refund_method') === $k)>{{ $label }}</option>
                @endforeach
              </select>
              <label class="co-modal-label" for="cr-gcash">GCash number (if GCash)</label>
              <input type="text" name="gcash_number" id="cr-gcash" class="form-input" inputmode="tel" autocomplete="off" value="{{ old('gcash_number') }}"/>
              <label class="co-modal-label" for="cr-gname">Name on the GCash account (if GCash)</label>
              <input type="text" name="gcash_account_name" id="cr-gname" class="form-input" autocomplete="off" value="{{ old('gcash_account_name') }}"/>
            @endif
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" style="margin-top:12px;">Open a cancellation request</button>
          </form>
        </details>
      </div>
    </div>
    @endif
    @endif

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Calls</div></div>
      <div class="admin-panel-body">
        <div class="co-lastcall">
          <div class="co-lastcall-label">Call attempts</div>
          <div class="co-lastcall-value">{{ $t->attempts }}</div>
          <div class="co-lastcall-label" style="margin-top:8px;">Last contacted</div>
          <div class="co-lastcall-value @if(!$t->last_contacted_at) co-muted @endif">
            {{ $t->last_contacted_at ? $t->last_contacted_at->timezone('Asia/Manila')->format('M d, Y h:i A') : 'Not yet' }}
          </div>
        </div>

        @if($isOpen)
          <button type="button" class="admin-btn admin-btn-gold co-wide" id="co-open-call">Send call update</button>

          <details class="co-more">
            <summary>Close or cancel this ticket</summary>
            <form method="POST" action="{{ route('admin.service-inquiries.close', $t->id) }}" class="co-action-form" id="si-close-form" data-lp-gate="si-close-modal">
              @csrf
              <label class="co-modal-label" for="si-reason" style="margin-top:0;">Reason</label>
              <textarea name="reason" id="si-reason" class="form-input" rows="3" maxlength="1000" required
                        placeholder="e.g. Agreed on a price by phone, customer will visit the shop"></textarea>
              <button type="submit" class="admin-btn admin-btn-outline co-wide" style="margin-top:8px;">Mark closed</button>
            </form>
          </details>
        @else
          <p class="co-note-closed">This ticket is closed.</p>
        @endif
      </div>
    </div>
  </div>
</div>

@if($isOpen)
<div class="co-modal" id="co-call-modal" hidden role="dialog" aria-modal="true" aria-labelledby="co-call-title">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="co-call-title">Send call update</h2>
      <button type="button" class="co-modal-x" id="co-call-close" aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('admin.service-inquiries.call-update', $t->id) }}" id="co-call-form">
      @csrf
      <label class="co-modal-label" for="co-outcome">Outcome</label>
      <select name="outcome" id="co-outcome" class="form-input" required>
        <option value="unreachable">Unreachable</option>
        <option value="ringing">Ringing but not answering</option>
        <option value="answered">Answered</option>
      </select>

      <div id="co-attempts-wrap">
        <label class="co-modal-label" for="co-attempts">Call attempts</label>
        <input type="number" name="attempts" id="co-attempts" class="form-input" min="1" max="20" value="{{ max(1, (int) $t->attempts) }}"/>
      </div>

      <label class="co-modal-label" for="co-note">Extra note (optional, added as a separate line)</label>
      <textarea name="note" id="co-note" class="form-input" rows="2" maxlength="500"></textarea>

      @if($t->user_id)
        <div class="co-modal-label">The customer will receive</div>
        <div class="co-preview" aria-live="polite">
          <div class="co-preview-title" id="co-preview-title"></div>
          <div id="co-preview" style="white-space:pre-line;"></div>
        </div>
      @else
        <p class="si-hint">This ticket has no customer account, so no in-app notification is sent. The call is recorded in the notes.</p>
        <div hidden><div id="co-preview-title"></div><div id="co-preview"></div></div>
      @endif

      <div class="co-modal-actions">
        <button type="button" class="admin-btn admin-btn-outline" id="co-call-cancel">Cancel</button>
        <button type="submit" class="admin-btn admin-btn-gold" id="co-call-send" data-loading="Sending...">{{ $t->user_id ? 'Send notification' : 'Save call update' }}</button>
      </div>
    </form>
  </div>
</div>
@endif
</div>

@if($isOpen)
<x-modal id="si-close-modal" title="Close this ticket?">
  <p>It can no longer be updated. A sent invoice with no payment on it is cancelled with it and the customer is told.</p>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
    <button type="button" class="lp-btn lp-btn--primary" data-lp-gate-submit="si-close-form" data-lp-confirm>Close ticket</button>
  </x-slot:footer>
</x-modal>
@if(!$ctx['open'])
<x-modal id="cr-modal" title="Open this cancellation request?">
  <p>Payments on this service will be paused until you decide.</p>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
    <button type="button" class="lp-btn lp-btn--primary" data-lp-gate-submit="cr-form" data-lp-confirm>Open request</button>
  </x-slot:footer>
</x-modal>
@endif
@endif
@endsection

@push('scripts')
<script>document.querySelectorAll('[data-open-cancellation]').forEach(function (a) { a.addEventListener('click', function () { var d = document.getElementById('cancellation-request'); if (d) { d.open = true; } }); });</script>
@if($isOpen)
<script>
(function () {
  var data = @json($callPreview);
  var modal = document.getElementById('co-call-modal');
  var outcome = document.getElementById('co-outcome');
  var attempts = document.getElementById('co-attempts');
  var note = document.getElementById('co-note');
  var wrap = document.getElementById('co-attempts-wrap');
  var preview = document.getElementById('co-preview');
  var previewTitle = document.getElementById('co-preview-title');
  var opener = document.getElementById('co-open-call');

  function times(n) { return n + ' ' + (n === 1 ? 'time' : 'times'); }
  function render() {
    var n = Math.max(1, Math.min(20, parseInt(attempts.value, 10) || 1));
    wrap.hidden = outcome.value === 'answered';
    var values = Object.assign({ times: times(n) }, data.values);
    var text = data.templates[outcome.value].replace(/\{([a-z_]+)\}/g, function (m, key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : '';
    });
    if (note.value.trim() !== '') { text += '\n' + note.value.trim(); }
    previewTitle.textContent = data.titles[outcome.value];
    preview.textContent = text;
  }
  function open() { modal.hidden = false; render(); outcome.focus(); }
  function close() { modal.hidden = true; opener.focus(); }

  opener.addEventListener('click', open);
  document.getElementById('co-call-close').addEventListener('click', close);
  document.getElementById('co-call-cancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
  [outcome, attempts, note].forEach(function (el) { el.addEventListener('input', render); el.addEventListener('change', render); });
})();
</script>
@endif
@endpush
