@extends('layouts.app')

@section('title', 'Request ' . $req->reference . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $r     = $req;
  $t     = $r->inquiry;
  $peso  = fn ($n) => '₱' . number_format((float) $n, 2);
  $day   = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');
  $when  = fn ($d) => $d?->timezone('Asia/Manila')->format('M j, Y g:i A');
  $sentAttempt = $r->attempts->firstWhere('outcome', 'sent');

  // Sent -> Under review -> Approved -> Refunded. Declined stops at step 3; an instant cancellation is Sent -> Cancelled.
  [$steps, $at, $stepVariant] = match ($r->status) {
      'new', 'needs_info' => [[['Sent', 'Sent'], ['Under review', 'Review'], ['Approved', 'Approved'], ['Refunded', 'Refunded']], 2, 'normal'],
      'approved'          => [[['Sent', 'Sent'], ['Under review', 'Review'], ['Approved', 'Approved'], ['Refunded', 'Refunded']], 3, 'normal'],
      'refund_problem'    => [[['Sent', 'Sent'], ['Under review', 'Review'], ['Refund problem', 'Problem'], ['Refunded', 'Refunded']], 3, 'problem'],
      'refunded'          => [[['Sent', 'Sent'], ['Under review', 'Review'], ['Approved', 'Approved'], ['Refunded', 'Refunded']], 4, 'complete'],
      'declined'          => [[['Sent', 'Sent'], ['Under review', 'Review'], ['Declined', 'Declined']], 3, 'stopped'],
      default             => [[['Sent', 'Sent'], ['Cancelled', 'Cancelled']], 2, 'complete'],
  };
  $statusTitle = match ($r->status) {
      'new'            => 'We are reviewing your request',
      'needs_info'     => 'We need a little more information',
      'approved'       => 'Approved',
      'refund_problem' => 'Your refund needs your attention',
      'refunded'       => 'Refunded',
      'declined'       => 'We could not approve your request',
      'closed'         => 'Cancelled',
      default          => $r->statusLabel(),
  };
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="si-card rq-main">
      <p class="rq-ref-label" style="margin-top:0;">Cancellation request</p>
      <h1 class="rq-title" style="margin-top:2px;">{{ $r->reference }}</h1>
      <p class="rq-lead">For service request {{ $t->reference_number }} · {{ $t->serviceLabel() }}</p>

      <x-stepper :steps="$steps" :current="$at" :variant="$stepVariant" label="Progress of your request"/>

      @switch($r->status)
        @case('new')
          <x-notice variant="waiting" title="{{ $statusTitle }}">Payments on this service are paused until we reply. We will notify you.</x-notice>
          @break
        @case('needs_info')
          <x-notice variant="waiting" title="{{ $statusTitle }}">Please answer our question below. Payments stay paused until we reply.</x-notice>
          @break
        @case('approved')
          <x-notice variant="success" title="{{ $statusTitle }}">
            Your refund of {{ $peso($r->approved_amount) }} by {{ $r->methodLabel() }} is on its way. It takes up to {{ \App\Models\ServiceRefundRequest::REFUND_DAYS }} days after approval, so by {{ $day($r->refund_due_at) }}.
          </x-notice>
          @break
        @case('refund_problem')
          <x-notice variant="problem" title="{{ $statusTitle }}">We could not send your refund. Please send the correct details below and we will try again.</x-notice>
          @break
        @case('refunded')
          <x-notice variant="success" title="Your refund was sent">
            {{ $peso($sentAttempt?->amount ?? $r->approved_amount) }} by {{ $sentAttempt ? (\App\Models\ServiceRefundRequest::METHODS[$sentAttempt->method] ?? $sentAttempt->method) : $r->methodLabel() }}
            @if($sentAttempt) on {{ $sentAttempt->sent_on?->format('F j, Y') }}. Transaction number: {{ $sentAttempt->transaction_number }}@endif
          </x-notice>
          @break
        @case('declined')
          <x-notice variant="blocked" title="{{ $statusTitle }}">{{ $r->decision_note }}</x-notice>
          <p class="rq-hint">Your service request stays open and your invoice can be paid as before.</p>
          @break
        @case('closed')
          <x-notice variant="success" title="Cancelled">No payment was made, so there is nothing to refund.</x-notice>
          @break
      @endswitch

      <h2 class="sx-h2" style="margin-top:22px;">Messages</h2>
      <ul class="rq-thread">
        @foreach($r->messages as $m)
          <li class="rq-msg rq-msg--{{ $m->sender }}">
            <span class="rq-msg-meta">{{ $m->sender === 'customer' ? 'You' : ($m->sender === 'admin' ? 'Our team' : 'LP Furniture') }} · {{ $when($m->created_at) }}</span>
            <span class="rq-msg-body">{{ $m->body }}</span>
          </li>
        @endforeach
      </ul>

      @if($r->status === 'needs_info')
        <form method="POST" action="{{ route('services.refund.reply', $r->reference) }}" id="rq-reply">
          @csrf
          <div class="rq-field">
            <label class="form-label" for="rq-body">Your reply</label>
            <textarea name="body" id="rq-body" class="form-input" rows="4" maxlength="1000" required>{{ old('body') }}</textarea>
            @error('body')<p class="rq-error">{{ $message }}</p>@enderror
          </div>
          <div class="rq-actions"><button type="submit" class="lp-btn lp-btn--primary">Send Reply</button></div>
        </form>
      @elseif($r->status === 'refund_problem')
        <form method="POST" action="{{ route('services.refund.reply', $r->reference) }}" id="rq-reply">
          @csrf
          @if($r->refund_method === 'gcash')
            <div class="rq-field">
              <label class="form-label" for="rq-gnum">Correct GCash Number</label>
              <input type="tel" name="gcash_number" id="rq-gnum" class="form-input" inputmode="tel" autocomplete="off" placeholder="09171234567" value="{{ old('gcash_number') }}" required/>
              @error('gcash_number')<p class="rq-error">{{ $message }}</p>@enderror
            </div>
            <div class="rq-field">
              <label class="form-label" for="rq-gname">Name on the GCash Account</label>
              <input type="text" name="gcash_account_name" id="rq-gname" class="form-input" autocomplete="off" value="{{ old('gcash_account_name') }}" required/>
            </div>
          @endif
          <div class="rq-field">
            <label class="form-label" for="rq-body">Message (Optional)</label>
            <textarea name="body" id="rq-body" class="form-input" rows="2" maxlength="1000">{{ old('body') }}</textarea>
          </div>
          <div class="rq-actions"><button type="submit" class="lp-btn lp-btn--primary">Send Corrected Details</button></div>
        </form>
      @endif

      <div class="rq-actions">
        @if($r->invoice && $r->status !== 'refunded')
          <a href="{{ route('services.invoice', $r->invoice->id) }}" class="lp-btn lp-btn--secondary">See my invoice</a>
        @endif
        <x-back-link :href="route('services.request')" label="my service requests" />
      </div>
    </div>

    <div class="si-side">
      @include('services._contact-card')
    </div>

  </div>
</div>
@endsection
