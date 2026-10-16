@extends('layouts.admin')

@section('title', 'Custom Order — Admin')
@section('page-title', 'Custom Order')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php
  $r = $designRequest;
  $labels = \App\Support\CustomOrderPipeline::LABELS;
  $selected = $r->selectedSuggestion;
  $eventLabels = [
      'submitted'          => 'Customer sent the request to the team',
      'call_logged'        => 'Call logged',
      'marked_unreachable' => 'Marked unreachable, customer notified',
      'declined_by_admin'  => 'Declined, customer notified',
      'quote_draft_created' => 'Quote draft created',
      'quote_draft_updated' => 'Quote draft updated',
      'quote_draft_deleted' => 'Quote draft deleted',
      'quote_sent'         => 'Quote sent to the customer',
      'quote_superseded'   => 'Earlier quote replaced',
      'quote_expired'      => 'Quote expired',
      'quote_accepted'     => 'Customer accepted the agreement',
      'quote_declined_by_customer' => 'Customer declined the quote',
      'agreement_integrity_failed' => 'Agreement failed its integrity check',
  ];
@endphp

<a href="{{ route('admin.custom-orders.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Custom Orders</a>

@if($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('pipeline') ? $errors->first('pipeline') : $errors->first() }}</div>
@endif

<div class="co-detail">
  <div>
    {{-- Customer + request --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">{{ $r->order_name }}</div>
        <span class="co-chip co-chip--{{ $r->pipeline_status }}">{{ $labels[$r->pipeline_status] ?? $r->pipeline_status }}</span>
      </div>
      <div class="admin-panel-body">
        <dl class="co-dl">
          <div><dt>Customer</dt><dd>{{ $r->user->display_name }}</dd></div>
          <div><dt>Phone</dt><dd><a href="tel:{{ $r->contact_phone }}">{{ $r->contact_phone }}</a></dd></div>
          <div><dt>Email</dt><dd><a href="mailto:{{ $r->contact_email }}">{{ $r->contact_email }}</a></dd></div>
          <div><dt>Preferred call window</dt><dd>{{ $r->callWindowLabel() }}</dd></div>
          <div><dt>Build mode</dt><dd>{{ \App\Models\CustomDesignRequest::REQUEST_MODES[$r->request_mode] ?? '—' }}</dd></div>
          <div><dt>Submitted</dt><dd>{{ $r->submitted_at?->format('M d, Y h:i A') }}</dd></div>
        </dl>

        <dl class="co-dl" style="margin-top:16px;">
          <div>
            <dt>Furniture type</dt>
            <dd>
              {{ $r->furniture_type_label }}
              @if(blank($r->furniture_type))
                <form method="POST" action="{{ route('admin.custom-orders.furniture-type', $r->id) }}" class="co-type-form">
                  @csrf
                  <label class="co-block-label" for="co-type-set">Set the type (older request)</label>
                  <select name="furniture_type" id="co-type-set" class="form-input" required>
                    <option value="">Choose</option>
                    @foreach(\App\Support\FurnitureTypes::LIST as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                  </select>
                  <input type="text" name="furniture_type_other" class="form-input" maxlength="{{ \App\Support\FurnitureTypes::OTHER_MAX }}" placeholder="If Other, describe it"/>
                  <button type="submit" class="admin-btn admin-btn-outline">Save type</button>
                </form>
              @endif
            </dd>
          </div>
          <div><dt>Preferred material</dt><dd>{{ $r->preferred_material ?: '—' }}</dd></div>
          <div><dt>Dimensions</dt><dd>{{ $r->dimensions ?: '—' }}</dd></div>
        </dl>
      </div>
    </div>

    {{-- Quote versions --}}
    @if($quotes->isNotEmpty())
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Quote versions</div></div>
        <div class="admin-panel-body" style="padding:0;">
          <table class="admin-table">
            <thead><tr><th>Version</th><th>Status</th><th>Price</th><th>Total</th><th>Sent</th><th>Expires</th></tr></thead>
            <tbody>
              @foreach($quotes as $q)
                <tr>
                  <td data-label="Version">v{{ $q->version }}</td>
                  <td data-label="Status"><span class="co-chip co-quote-chip--{{ $q->status }}">{{ ucfirst($q->status) }}</span></td>
                  <td data-label="Price">
                    ₱{{ number_format($q->price, 2) }}
                    @if($q->previous_price !== null && (float) $q->previous_price !== (float) $q->price)
                      <div class="co-log-meta">was ₱{{ number_format($q->previous_price, 2) }}</div>
                    @endif
                  </td>
                  <td data-label="Total">₱{{ number_format($q->totalAmount(), 2) }}</td>
                  <td data-label="Sent">
                    @if($q->sent_at)
                      {{ $q->sent_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}
                      <div class="co-log-meta">by {{ $q->sender?->first_name ?? 'Admin' }}</div>
                    @else — @endif
                  </td>
                  <td data-label="Expires">{{ $q->expires_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      @php $current = $quotes->firstWhere('status', 'sent') ?? $quotes->first(); @endphp
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Version {{ $current->version }} terms</div></div>
        <div class="admin-panel-body">
          @include('admin.custom-orders._quote-terms', ['quote' => $current])
        </div>
      </div>
    @endif

    {{-- Order (Stage 9) --}}
    @if(isset($order) && $order)
      <div class="admin-panel">
        <div class="admin-panel-header">
          <div class="admin-panel-title">Order {{ $order->order_number }}</div>
          <span style="display:flex;gap:6px;">
            <span class="admin-pill {{ $order->order_status }}">{{ str_replace('_', ' ', $order->order_status) }}</span>
            @if($order->isOverdueUnpaid())<span class="admin-pill rejected">Overdue, unpaid</span>@endif
          </span>
        </div>
        <div class="admin-panel-body">
          <dl class="co-dl">
            <div><dt>Paid</dt><dd>₱{{ number_format($order->amount_paid, 2) }} of ₱{{ number_format($order->total_amount, 2) }}</dd></div>
            <div><dt>Expected ready</dt><dd>{{ $order->expected_ready_date ? 'about ' . \Illuminate\Support\Carbon::parse($order->expected_ready_date)->format('M d, Y') : '—' }}</dd></div>
          </dl>
          <a href="{{ route('admin.orders.show', $order->id) }}" class="admin-btn admin-btn-gold" style="margin-top:12px;">Open order, ledger and status</a>
        </div>
      </div>
    @endif

    {{-- Agreement (Stage 6): read only --}}
    @if($agreement)
      <div class="admin-panel">
        <div class="admin-panel-header">
          <div class="admin-panel-title">Agreement {{ $agreement->agreement_number }}</div>
          @if($hashOk)
            <span class="co-chip co-chip--accepted">Hash verified</span>
          @else
            <span class="co-chip co-chip--cancelled">Hash mismatch</span>
          @endif
        </div>
        <div class="admin-panel-body">
          <dl class="co-dl">
            <div><dt>Accepted</dt><dd>{{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</dd></div>
            <div><dt>Payment due by</dt><dd>{{ $r->payment_due_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? '—' }}</dd></div>
            <div><dt>Total / upfront</dt><dd>₱{{ number_format($agreement->total_amount, 2) }} / ₱{{ number_format($agreement->upfront_amount, 2) }}</dd></div>
            <div><dt>IP address</dt><dd>{{ $agreement->ip_address }}</dd></div>
            <div><dt>User agent</dt><dd style="overflow-wrap:anywhere;">{{ $agreement->user_agent ?: '—' }}</dd></div>
            <div><dt>Policy version</dt><dd>{{ $agreement->policy_version }}</dd></div>
            <div><dt>SHA-256</dt><dd style="overflow-wrap:anywhere;font-size:11.5px;">{{ $agreement->snapshot_sha256 }}</dd></div>
          </dl>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            @if($hashOk)
              <a href="{{ route('admin.agreements.show', $agreement->id) }}" class="admin-btn admin-btn-outline">View agreement</a>
              <a href="{{ route('admin.agreements.print', $agreement->id) }}" class="admin-btn admin-btn-gold" target="_blank" rel="noopener">Print view</a>
            @else
              <p class="co-hint">This agreement is blocked until it is investigated.</p>
            @endif
          </div>
        </div>
      </div>
    @endif

    {{-- Timeline: everything, with who and when (Asia/Manila) --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Timeline</div></div>
      <div class="admin-panel-body">
        @include('agreements._timeline', ['entries' => $timeline])
      </div>
    </div>
  </div>

  {{-- Side column: actions --}}
  <div>
    {{-- Design image and the customer's words, beside the actions --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Selected design</div></div>
      <div class="admin-panel-body">
        @php $designImage = $r->previewImageUrl(); @endphp
        <div class="co-design-summary co-design-summary--stack">
        @if($designImage)
          <div class="co-design-frame co-design-frame--side">
            <img src="{{ $designImage }}" alt="Selected design"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
          </div>
        @else
          <div class="co-noimage">No image generated</div>
        @endif

        <div class="co-block-label">Customer's prompt</div>
        <div class="co-quote">{{ $r->description }}</div>

        @if($r->modification_notes)
          <div class="co-block-label">Customer's customization idea</div>
          <div class="co-quote">{{ $r->modification_notes }}</div>
        @endif
        </div>

        @if($r->reference_image)
          <div class="co-block-label">Customer's reference image</div>
          <div class="co-design-frame co-design-frame--side">
            <a href="{{ asset('storage/' . $r->reference_image) }}" target="_blank" rel="noopener">
              <img src="{{ asset('storage/' . $r->reference_image) }}" alt="Customer-uploaded reference"/>
            </a>
          </div>
        @endif
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">This request</div></div>
      <div class="admin-panel-body">
        <div class="co-status-row">
          <span class="co-chip co-chip--{{ $r->pipeline_status }}">{{ $labels[$r->pipeline_status] ?? $r->pipeline_status }}</span>
        </div>

        <div class="co-lastcall">
          <div class="co-lastcall-label">Last call update</div>
          @if($lastCall)
            <div class="co-lastcall-value">
              {{ $lastCall['label'] }}@if($lastCall['attempts']), {{ \App\Support\CallUpdateMessage::times((int) $lastCall['attempts']) }} called @endif
            </div>
            <div class="co-log-meta">
              {{ \Illuminate\Support\Carbon::instance($lastCall['at'])->timezone('Asia/Manila')->format('M d, Y h:i A') }}
              · {{ $lastCall['count'] }} {{ $lastCall['count'] === 1 ? 'update' : 'updates' }} so far
            </div>
          @else
            <div class="co-lastcall-value co-muted">No call update yet</div>
          @endif
        </div>

        @if($isOpen)
          <button type="button" class="admin-btn admin-btn-gold co-wide" id="co-open-call">Send call update</button>

          <details class="co-more">
            <summary>More actions</summary>
            <form method="POST" action="{{ route('admin.custom-orders.decline', $r->id) }}" class="co-action-form"
                  onsubmit="return confirm('Decline this request? The customer will be notified and this cannot be undone.')">
              @csrf
              <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
              <div class="co-action-title">Decline request</div>
              <textarea name="reason" class="form-input" rows="3" maxlength="1000" required
                        placeholder="Reason (shown to the customer)">{{ old('reason') }}</textarea>
              <button type="submit" class="admin-btn admin-btn-danger co-wide" data-loading="Declining...">Decline request</button>
            </form>
          </details>
        @else
          <p class="co-note-closed">
            This request is {{ strtolower($labels[$r->pipeline_status] ?? $r->pipeline_status) }}; call updates are closed.
            @if($r->decline_reason)<br/>Reason: {{ $r->decline_reason }}@endif
          </p>
        @endif
        @php $draft = $quotes->firstWhere('status', 'draft'); @endphp
        <div style="margin-top:18px;padding-top:18px;border-top:1px solid #EFE7DA;">
          <div class="co-action-title">Quote</div>
          @if($draft)
            <p class="co-hint">Version {{ $draft->version }} is a draft.</p>
            <a href="{{ route('admin.custom-orders.quotes.confirm', [$r->id, $draft->id]) }}" class="admin-btn admin-btn-gold" style="width:100%;margin-bottom:8px;">Review and send</a>
            <a href="{{ route('admin.custom-orders.quotes.edit', [$r->id, $draft->id]) }}" class="admin-btn admin-btn-outline" style="width:100%;margin-bottom:8px;">Edit draft</a>
            <form method="POST" action="{{ route('admin.custom-orders.quotes.delete', [$r->id, $draft->id]) }}"
                  onsubmit="return confirm('Delete this draft?')">
              @csrf
              <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
              <button type="submit" class="admin-btn admin-btn-danger" style="width:100%;">Delete draft</button>
            </form>
          @elseif(in_array($r->pipeline_status, ['accepted', 'ordered'], true))
            <p class="co-hint">
              {{ $r->pipeline_status === 'ordered' ? 'Quote accepted, order created.' : 'Quote accepted, waiting for the customer to pay.' }}
            </p>
          @elseif($quoteBlocker)
            <p class="co-hint">{{ $quoteBlocker }}</p>
            <button type="button" class="admin-btn admin-btn-outline co-btn-disabled" disabled>Create quote</button>
          @else
            @php $hasQuotes = $quotes->isNotEmpty(); @endphp
            <a href="{{ route('admin.custom-orders.quotes.create', $r->id) }}" class="admin-btn admin-btn-gold" style="width:100%;">
              {{ $hasQuotes ? 'Create new version' : 'Create quote' }}
            </a>
          @endif
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Call log</div></div>
      <div class="admin-panel-body">
        @if($r->callLogs->isEmpty())
          <p class="co-note-closed">No calls logged yet.</p>
        @else
          <ul class="co-log">
            @foreach($r->callLogs->sortByDesc('attempt_no') as $log)
              <li>
                <strong>#{{ $log->attempt_no }}</strong> · {{ $log->answered ? 'Answered' : 'No answer' }}
                @if($log->note)<div>{{ $log->note }}</div>@endif
                <div class="co-log-meta">
                  {{ $log->called_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}
                  · {{ $log->admin?->first_name ?? 'Admin' }}
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </div>
    </div>
  </div>
</div>

{{-- Send call update (Stage 13) --}}
@if($isOpen)
<div class="co-modal" id="co-call-modal" hidden role="dialog" aria-modal="true" aria-labelledby="co-call-title">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="co-call-title">Send call update</h2>
      <button type="button" class="co-modal-x" id="co-call-close" aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('admin.custom-orders.call-update', $r->id) }}" id="co-call-form">
      @csrf
      <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>

      <label class="co-modal-label" for="co-outcome">Outcome</label>
      <select name="outcome" id="co-outcome" class="form-input" required>
        <option value="unreachable">Unreachable</option>
        <option value="ringing">Ringing but not answering</option>
        <option value="answered">Answered</option>
      </select>

      <div id="co-attempts-wrap">
        <label class="co-modal-label" for="co-attempts">Call attempts</label>
        <input type="number" name="attempts" id="co-attempts" class="form-input" min="1" max="20" value="1"/>
      </div>

      <label class="co-modal-label" for="co-note">Extra note (optional, added as a separate line)</label>
      <textarea name="note" id="co-note" class="form-input" rows="2" maxlength="500"></textarea>

      <div class="co-modal-label">The customer will receive</div>
      <div class="co-preview" aria-live="polite">
        <div class="co-preview-title" id="co-preview-title"></div>
        <div id="co-preview" style="white-space:pre-line;"></div>
      </div>

      <div class="co-modal-actions">
        <button type="button" class="admin-btn admin-btn-outline" id="co-call-cancel">Cancel</button>
        <button type="submit" class="admin-btn admin-btn-gold" id="co-call-send" data-loading="Sending...">Send notification</button>
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
