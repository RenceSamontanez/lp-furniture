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

@if($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('ticket') ? $errors->first('ticket') : $errors->first() }}</div>
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
            <summary>Close this ticket</summary>
            <form method="POST" action="{{ route('admin.service-inquiries.close', $t->id) }}" class="co-action-form"
                  onsubmit="return confirm('Close this ticket? It can no longer be updated.')">
              @csrf
              <label class="co-modal-label" for="si-reason" style="margin-top:0;">Reason</label>
              <textarea name="reason" id="si-reason" class="form-input" rows="3" maxlength="1000" required
                        placeholder="e.g. Agreed on a price by phone, customer will visit the shop"></textarea>
              <button type="submit" class="admin-btn admin-btn-outline co-wide" style="margin-top:8px;" data-loading="Closing...">Mark closed</button>
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
@endsection

@push('scripts')
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
