@extends('layouts.admin')

@section('title', 'Review Identity — Admin')
@section('page-title', 'Review identity validation')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/identity-review.css') }}?v={{ @filemtime(public_path('assets/css/identity-review.css')) }}"/>
@endpush

@section('content')
@php
  $u = $validation->user;
  $name = trim($u->first_name . ' ' . $u->last_name);
  $initials = strtoupper(mb_substr($u->first_name, 0, 1) . mb_substr($u->last_name, 0, 1));
  $pending = $validation->isPending();
  $checks = config('identity.checks');
  $isLatest = $latest && $latest->id === $validation->id;
  $fmt = fn ($d) => $d ? $d->timezone(config('app.timezone'))->format('M j, Y, g:i A') : '—';
  $reviewerName = $validation->reviewer ? trim($validation->reviewer->first_name . ' ' . $validation->reviewer->last_name) : 'an admin';
  $photos = [
    ['type' => 'id',     'title' => 'Government ID',  'path' => $validation->id_photo_path],
    ['type' => 'selfie', 'title' => 'Selfie with ID', 'path' => $validation->selfie_photo_path],
  ];
@endphp
<div class="idr idr--review" data-idr-review>

  <a href="{{ route('admin.validations.index', ['status' => $validation->status]) }}" class="lp-link lp-link--tap idr-back">&larr; Back to list</a>

  {{-- Customer strip --}}
  <div class="idr-glass idr-info">
    <div class="idr-who2">
      <div class="idr-big" aria-hidden="true">{{ $initials }}</div>
      <div>
        <h2>{{ $name }}</h2>
        <div class="idr-tags">
          <span class="idr-pill is-{{ $validation->status }}">{{ ucfirst($validation->status) }}</span>
          <span class="idr-chip2">Attempt {{ $validation->attempt_number }}</span>
          @if($earlier->isNotEmpty())
            <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="idr-past">Past attempts</button>
          @endif
        </div>
      </div>
    </div>
    <dl class="idr-facts">
      <div><dt>Email</dt><dd>{{ $u->email }}</dd></div>
      <div><dt>Phone</dt><dd>{{ $u->phone_number ?: '—' }}</dd></div>
      <div><dt>Submitted</dt><dd>{{ $fmt($validation->submitted_at) }}</dd></div>
      <div><dt>Account created</dt><dd>{{ $u->created_at ? $u->created_at->format('M j, Y') : '—' }}</dd></div>
    </dl>
  </div>

  {{-- Photos --}}
  <div class="idr-docs">
    @foreach($photos as $i => $p)
      <article class="idr-glass idr-doc">
        <header>
          <h3>{{ $p['title'] }}</h3>
          <div class="idr-tools">
            <button class="lp-btn lp-btn--secondary lp-btn--chip" type="button" data-rotate="{{ $i }}">Rotate</button>
            <button class="lp-btn lp-btn--secondary lp-btn--chip" type="button" data-enlarge="{{ $i }}">Enlarge</button>
          </div>
        </header>
        <div class="idr-stage" data-stage="{{ $i }}" data-title="{{ $p['title'] }}" tabindex="0" role="button" aria-label="Enlarge {{ $p['title'] }} photo">
          @if($p['path'])
            <img src="{{ route('admin.validations.document', ['id' => $validation->id, 'type' => $p['type']]) }}" alt="{{ $p['title'] }} submitted by {{ $name }}" draggable="false">
          @endif
          <span class="idr-missing" @if($p['path']) hidden @endif>Photo not available</span>
        </div>
      </article>
    @endforeach
  </div>

  {{-- Action bar / decision record --}}
  <div class="idr-glass idr-bar {{ $pending ? '' : 'is-decided' }}">
    @if($pending)
      <div class="idr-bar__checks">
        <fieldset class="idr-checks" id="idr-checks">
          <legend>Checks to approve</legend>
          <span class="idr-cnt" id="idr-cnt" aria-live="polite">0 of 3</span>
          @foreach($checks as $key => $label)
            <label class="idr-chk">
              <input type="checkbox" name="confirm_{{ $key }}" value="1" form="idr-approve-form" data-check>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </fieldset>
        <span class="idr-msg" id="idr-check-msg" role="alert" hidden>Tick all three checks before approving.</span>
      </div>
      <div class="idr-acts">
        <button type="button" class="lp-btn lp-btn--danger" id="idr-reject-btn">Reject</button>
        <button type="button" class="lp-btn lp-btn--primary is-locked" id="idr-approve-btn" aria-disabled="true" aria-describedby="idr-cnt">Approve verification</button>
      </div>
      <div class="idr-reason">
        <label for="idr-why">Reason, needed only to reject</label>
        @foreach(config('identity.reason_chips') as $chip)
          <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-reason-chip>{{ $chip }}</button>
        @endforeach
        <input id="idr-why" class="idr-input {{ $errors->has('rejection_reason') ? 'is-err' : '' }}" type="text" name="rejection_reason" form="idr-reject-form"
               maxlength="{{ config('identity.reason_max') }}" value="{{ old('rejection_reason') }}" autocomplete="off"
               placeholder="Tell the customer what to fix, for example: please resubmit with better lighting" aria-describedby="idr-why-msg">
        <span class="idr-msg" id="idr-why-msg" role="alert" @unless($errors->has('rejection_reason')) hidden @endunless>{{ $errors->first('rejection_reason') ?: 'Add a reason so the customer knows what to fix.' }}</span>
      </div>

      <form id="idr-approve-form" method="POST" action="{{ route('admin.validations.approve', $validation->id) }}">@csrf</form>
      <form id="idr-reject-form" method="POST" action="{{ route('admin.validations.reject', $validation->id) }}">@csrf</form>
    @else
      <div class="idr-record">
        <span class="idr-pill is-{{ $validation->status }}">{{ ucfirst($validation->status) }}</span>
        <div class="idr-record__t">
          <p>{{ ucfirst($validation->status) }} by {{ $reviewerName }} on {{ $fmt($validation->reviewed_at) }}. This decision is final.</p>

          @if($validation->isApproved())
            @if($validation->checks_confirmed)
              <small>All three checks were confirmed before approving.</small>
              <div class="idr-oks">
                @foreach($checks as $key => $label)
                  @if(!empty($validation->checks_confirmed[$key]))<span>&#10003; {{ $label }}</span>@endif
                @endforeach
              </div>
            @else
              <small>This was decided before checks were recorded, so none are shown.</small>
            @endif
          @else
            <div class="idr-why">Reason sent to the customer: {{ $validation->rejection_reason }}</div>
            @if($validation->follow_up_requested_at)
              <small>New submission requested on {{ $fmt($validation->follow_up_requested_at) }}{{ $validation->followUpBy ? ' by ' . trim($validation->followUpBy->first_name . ' ' . $validation->followUpBy->last_name) : '' }}. Waiting for the customer to send new photos.</small>
            @elseif($isLatest && !$u->is_validated)
              <small>The customer can send new photos at any time. You can also ask them for a new submission.</small>
            @else
              <small>The customer has since sent a newer submission.</small>
            @endif
          @endif
        </div>
        <div class="idr-record__a">
          @if($validation->isRejected() && $isLatest && !$validation->follow_up_requested_at)
            <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="idr-follow-modal">Ask for a new submission</button>
          @endif
          @if(!$isLatest && $latest)
            <a class="lp-btn lp-btn--secondary" href="{{ route('admin.validations.show', $latest->id) }}">Open attempt {{ $latest->attempt_number }}</a>
          @endif
          <a class="lp-btn lp-btn--secondary" href="{{ route('admin.validations.index', ['status' => $validation->status]) }}">Back to list</a>
        </div>
      </div>
    @endif
  </div>
</div>

{{-- Confirmation modals --}}
@if($pending)
  <x-modal id="idr-approve-modal" title="Approve this verification?">
    <p>{{ $name }} will be marked as verified and notified right away. All three checks are confirmed.</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
      <button type="submit" form="idr-approve-form" class="lp-btn lp-btn--primary" data-lp-confirm>Approve</button>
    </x-slot:footer>
  </x-modal>
  <x-modal id="idr-reject-modal" title="Reject this verification?">
    <p>{{ $name }} will see this reason and can send new photos.</p>
    <x-notice variant="problem" title="Reason the customer will see"><span id="idr-reject-quote"></span></x-notice>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
      <button type="submit" form="idr-reject-form" class="lp-btn lp-btn--danger" data-lp-confirm>Reject</button>
    </x-slot:footer>
  </x-modal>
@elseif($validation->isRejected() && $isLatest && !$validation->follow_up_requested_at)
  <x-modal id="idr-follow-modal" title="Ask for a new submission?">
    <p>{{ $name }} is notified with your reason and can send new photos. Their new submission appears under Pending as attempt {{ $validation->attempt_number + 1 }}.</p>
    <x-notice variant="info" title="Reason on file"><span>{{ $validation->rejection_reason }}</span></x-notice>
    <form id="idr-follow-form" method="POST" action="{{ route('admin.validations.follow-up', $validation->id) }}">@csrf</form>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
      <button type="submit" form="idr-follow-form" class="lp-btn lp-btn--primary" data-lp-confirm>Send request</button>
    </x-slot:footer>
  </x-modal>
@endif

@if($earlier->isNotEmpty())
  <x-modal id="idr-past" title="Past attempts">
    <p>{{ $name }} has had {{ $earlier->count() }} earlier {{ \Illuminate\Support\Str::plural('submission', $earlier->count()) }} before this one.</p>
    <ul class="idr-hist">
      @foreach($earlier as $e)
        <li>
          <b>Attempt {{ $e->attempt_number }}: {{ $e->status }}</b>
          <small>{{ $fmt($e->reviewed_at ?? $e->submitted_at) }}</small>
          @if($e->rejection_reason)<span>Reason: {{ $e->rejection_reason }}</span>@endif
          <a class="lp-link" href="{{ route('admin.validations.show', $e->id) }}">Open this attempt</a>
        </li>
      @endforeach
    </ul>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button>
    </x-slot:footer>
  </x-modal>
@endif

{{-- Glass photo viewer --}}
<dialog id="idr-viewer" class="idr-viewer" aria-labelledby="idr-viewer-title">
  <div class="idr-glass idr-lb">
    <div class="idr-lb__bt">
      <h2 id="idr-viewer-title"></h2>
      <button type="button" class="lp-btn lp-btn--secondary" id="idr-viewer-rotate">Rotate</button>
      <button type="button" class="lp-btn lp-btn--primary" id="idr-viewer-close">Close</button>
    </div>
    <div class="idr-stage idr-stage--lb" id="idr-viewer-stage"><img alt="" draggable="false"></div>
  </div>
</dialog>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/identity-review.js') }}?v={{ @filemtime(public_path('assets/js/identity-review.js')) }}"></script>
@endpush
