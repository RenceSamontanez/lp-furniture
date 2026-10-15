@extends('layouts.admin')

@section('title', 'Custom Order — Admin')
@section('page-title', 'Custom Order')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
@endpush

@section('content')
@php
  $r = $designRequest;
  $labels = \App\Support\CustomOrderPipeline::LABELS;
  $selected = $r->selectedSuggestion;
  $eventLabels = [
      'submitted'          => 'Customer sent the request to the team',
      'call_logged'        => 'Call logged',
      'marked_unreachable' => 'Marked unreachable, customer notified',
      'declined_by_admin'  => 'Declined, customer notified',
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
        <div class="admin-panel-title">{{ $r->title }}</div>
        <span class="co-chip co-chip--{{ $r->pipeline_status }}">{{ $labels[$r->pipeline_status] ?? $r->pipeline_status }}</span>
      </div>
      <div class="admin-panel-body">
        <dl class="co-dl">
          <div><dt>Customer</dt><dd>{{ $r->user->first_name }} {{ $r->user->last_name }}</dd></div>
          <div><dt>Phone</dt><dd><a href="tel:{{ $r->contact_phone }}">{{ $r->contact_phone }}</a></dd></div>
          <div><dt>Email</dt><dd><a href="mailto:{{ $r->contact_email }}">{{ $r->contact_email }}</a></dd></div>
          <div><dt>Preferred call window</dt><dd>{{ $r->callWindowLabel() }}</dd></div>
          <div><dt>Build mode</dt><dd>{{ \App\Models\CustomDesignRequest::REQUEST_MODES[$r->request_mode] ?? '—' }}</dd></div>
          <div><dt>Submitted</dt><dd>{{ $r->submitted_at?->format('M d, Y h:i A') }}</dd></div>
        </dl>

        @if($r->request_mode === 'modified')
          <div class="co-block-label">Changes the customer wants</div>
          <div class="co-quote">{{ $r->modification_notes }}</div>
        @endif

        <div class="co-block-label">Customer's original prompt</div>
        <div class="co-quote">{{ $r->description }}</div>

        <dl class="co-dl" style="margin-top:16px;">
          <div><dt>Furniture type</dt><dd>{{ $r->furniture_type ?: '—' }}</dd></div>
          <div><dt>Preferred material</dt><dd>{{ $r->preferred_material ?: '—' }}</dd></div>
          <div><dt>Dimensions</dt><dd>{{ $r->dimensions ?: '—' }}</dd></div>
        </dl>
      </div>
    </div>

    {{-- Design image --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Selected design</div></div>
      <div class="admin-panel-body">
        @if($selected && $selected->isLoadable())
          <div class="co-design-frame">
            <img src="{{ $selected->displayUrl() }}" alt="Selected design"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
          </div>
        @else
          <div class="co-noimage">No image generated</div>
        @endif

        @if($r->reference_image)
          <div class="co-block-label">Customer's reference image</div>
          <div class="co-design-frame">
            <a href="{{ asset('storage/' . $r->reference_image) }}" target="_blank" rel="noopener">
              <img src="{{ asset('storage/' . $r->reference_image) }}" alt="Customer-uploaded reference"/>
            </a>
          </div>
        @endif
      </div>
    </div>

    {{-- Timeline --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Timeline</div></div>
      <div class="admin-panel-body">
        <ul class="co-timeline">
          @foreach($events as $e)
            @php $d = $e->data ?? []; @endphp
            <li>
              <div>
                {{ $eventLabels[$e->type] ?? ucfirst(str_replace('_', ' ', $e->type)) }}
                @if($e->type === 'call_logged')
                  (attempt {{ $d['attempt_no'] ?? '?' }}, {{ !empty($d['answered']) ? 'answered' : 'no answer' }})
                @endif
              </div>
              @if(!empty($d['note']))<div class="co-log-meta">"{{ $d['note'] }}"</div>@endif
              @if(!empty($d['reason']))<div class="co-log-meta">Reason: {{ $d['reason'] }}</div>@endif
              <div class="co-timeline-when">
                {{ $e->occurred_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}
                · {{ $e->actor_type === 'admin' ? ($actors[$e->actor_id]->first_name ?? 'Admin') : ucfirst($e->actor_type) }}
              </div>
            </li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>

  {{-- Side column: actions --}}
  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Work this request</div></div>
      <div class="admin-panel-body">
        @if($isOpen)
          <form method="POST" action="{{ route('admin.custom-orders.calls', $r->id) }}" class="co-action-form">
            @csrf
            <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
            <div class="co-action-title">Log a call</div>
            <div class="co-radio-row">
              <label><input type="radio" name="answered" value="1" required/> Answered</label>
              <label><input type="radio" name="answered" value="0" required/> No answer</label>
            </div>
            <textarea name="note" class="form-input" rows="3" maxlength="1000" placeholder="Note (optional)"></textarea>
            <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;">Save call</button>
          </form>

          <form method="POST" action="{{ route('admin.custom-orders.unreachable', $r->id) }}" class="co-action-form">
            @csrf
            <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
            <div class="co-action-title">Mark unreachable</div>
            @if($r->pipeline_status === 'unreachable')
              <p class="co-hint">Already unreachable. Log an answered call to resume.</p>
            @elseif($unanswered < 1)
              <p class="co-hint">Available after at least one unanswered call is logged.</p>
            @else
              <p class="co-hint">The customer is notified to check their number.</p>
            @endif
            <button type="submit" class="admin-btn admin-btn-outline" style="width:100%;"
                    @disabled($r->pipeline_status === 'unreachable' || $unanswered < 1)>Mark unreachable</button>
          </form>

          <form method="POST" action="{{ route('admin.custom-orders.decline', $r->id) }}" class="co-action-form"
                onsubmit="return confirm('Decline this request? The customer will be notified and this cannot be undone.')">
            @csrf
            <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>
            <div class="co-action-title">Decline</div>
            <textarea name="reason" class="form-input" rows="3" maxlength="1000" required
                      placeholder="Reason (shown to the customer)">{{ old('reason') }}</textarea>
            <button type="submit" class="admin-btn admin-btn-danger" style="width:100%;">Decline request</button>
          </form>
        @else
          <p class="co-note-closed">
            This request is {{ strtolower($labels[$r->pipeline_status] ?? $r->pipeline_status) }}; no further actions are available here.
            @if($r->decline_reason)<br/>Reason: {{ $r->decline_reason }}@endif
          </p>
        @endif

        <div style="margin-top:18px;padding-top:18px;border-top:1px solid #EFE7DA;">
          <button type="button" class="admin-btn admin-btn-outline co-btn-disabled" disabled>Create quote (next step)</button>
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
@endsection
