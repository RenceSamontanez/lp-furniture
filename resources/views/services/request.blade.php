@extends('layouts.app')

@section('title', 'Other Services Request — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-refunds.css') }}"/>
@endpush

@section('content')
@php
  $type   = old('service_type', in_array($serviceType, array_keys(\App\Models\ServiceInquiry::SERVICE_TYPES), true) ? $serviceType : null);
  $window = old('preferred_call_window');
  $today  = \App\Support\ServiceInquiryDesk::today();
  $maxDay = $today->copy()->addDays(\App\Support\ServiceInquiryDesk::DATE_HORIZON_DAYS);
@endphp
<div class="si-wrap">
  <div class="si-layout">

    <div class="co-card si-form-card">
      <div class="co-head">
        <h1 class="co-title">Other Services Request</h1>
        <p class="co-lead">
          For work we price by phone, such as labor only when you bring your own wood, or a repair.
          This is a request, not an order. No payment is due. Our team will call you to discuss it.
        </p>
      </div>

      @if($errors->any())
        <div class="co-errors" role="alert">
          @foreach($errors->all() as $error)
            <div>{{ $error }}</div>
          @endforeach
        </div>
      @endif

      <form method="POST" action="{{ route('services.request.submit') }}" class="co-form" id="si-form" enctype="multipart/form-data">
        @csrf
        {{-- Honeypot: left empty by people, filled by bots. --}}
        <div class="si-hp" aria-hidden="true">
          <label for="si-website">Website</label>
          <input type="text" id="si-website" name="website" value="" tabindex="-1" autocomplete="off"/>
        </div>

        <fieldset class="co-section">
          <legend class="co-section-title">What do you need?</legend>
          <div class="co-options co-options--1">
            @foreach(\App\Models\ServiceInquiry::SERVICE_TYPES as $value => $label)
              <label class="co-option">
                <input type="radio" name="service_type" value="{{ $value }}" required @checked($type === $value)/>
                <span>{{ $label }}</span>
              </label>
            @endforeach
          </div>
          <div class="co-field" style="margin-top:14px;">
            <label class="form-label" for="furniture_type">Furniture Type (Optional)</label>
            <select id="furniture_type" name="furniture_type" class="form-input">
              <option value="">Not sure or not listed</option>
              @foreach(\App\Support\FurnitureTypes::LIST as $t)
                <option value="{{ $t }}" @selected(old('furniture_type') === $t)>{{ $t }}</option>
              @endforeach
            </select>
          </div>
          <div class="co-field" style="margin-top:14px;">
            <label class="form-label" for="concern">Tell Us About It</label>
            <textarea id="concern" name="concern" class="form-textarea" rows="5" minlength="10" maxlength="2000" required aria-describedby="concern-help"
                      placeholder="e.g. The right front leg of my dining table is cracked near the joint. It is narra, about 75 cm high. It started 2 weeks ago.">{{ old('concern') }}</textarea>
            <p class="co-hint" id="concern-help">Please tell us <strong>what is wrong</strong>, <strong>where</strong> on the furniture, its <strong>size</strong>, what it is <strong>made of</strong> and <strong>since when</strong>. Write at least 40 characters, or add a photo below. A clear description helps us give you the right price.</p>
          </div>
          <div class="co-field" style="margin-top:14px;">
            <label class="form-label" for="photos">Photos (optional if you wrote a clear description)</label>
            <input type="file" id="photos" name="photos[]" class="form-input" accept="image/jpeg,image/png" multiple aria-describedby="photos-help"/>
            <p class="co-hint" id="photos-help">Up to 3 photos, JPG or PNG, 5 MB each. A photo of the problem is enough to start.</p>
          </div>
          <div class="co-field" style="margin-top:14px;">
            <span class="form-label" id="work-location-label">Where should the work be done?</span>
            <div class="co-options co-options--3" role="radiogroup" aria-labelledby="work-location-label">
              @foreach(\App\Models\ServiceInquiry::WORK_LOCATIONS as $value => $label)
                <label class="co-option">
                  <input type="radio" name="work_location" value="{{ $value }}" @checked(old('work_location', 'unsure') === $value)/>
                  <span>{{ $label }}</span>
                </label>
              @endforeach
            </div>
            <p class="co-hint">Our team confirms the place with you. Any visit or pickup fee is confirmed by our team and shown on your invoice.</p>
          </div>
        </fieldset>

        <fieldset class="co-section">
          <legend class="co-section-title">How can we reach you?</legend>
          <div class="co-grid">
            <div class="co-field">
              <label class="form-label" for="name">Your Name</label>
              <input type="text" id="name" name="name" class="form-input" required maxlength="120" value="{{ old('name', $defaults['name']) }}" autocomplete="name"/>
            </div>
            <div class="co-field">
              <label class="form-label" for="phone">Mobile Number</label>
              <input type="tel" id="phone" name="phone" class="form-input" required maxlength="16" value="{{ old('phone', $defaults['phone']) }}" placeholder="09171234567" autocomplete="tel"/>
              <p class="co-hint">Philippine mobile number, for example 09171234567.</p>
            </div>
          </div>
          <div class="co-field" style="margin-top:14px;">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-input" required maxlength="150" value="{{ old('email', $defaults['email']) }}" autocomplete="email"/>
          </div>
        </fieldset>

        <fieldset class="co-section">
          <legend class="co-section-title">When should we call?</legend>
          <p class="co-hint co-hint--lead">We call Monday to Friday, 8:00 AM to 5:00 PM (Philippine time).</p>
          <div class="co-options co-options--3">
            @foreach(\App\Models\CustomDesignRequest::CALL_WINDOWS as $value => $label)
              @php [$name, $hours] = array_pad(explode(', ', $label, 2), 2, ''); @endphp
              <label class="co-option">
                <input type="radio" name="preferred_call_window" value="{{ $value }}" @checked($window === $value)/>
                <span class="co-option-text">
                  <span class="co-option-name">{{ $name }}</span>
                  <span class="co-option-sub">{{ $hours }}</span>
                </span>
              </label>
            @endforeach
          </div>
          <div class="co-field" style="margin-top:14px;">
            <label class="form-label" for="preferred_call_date">Preferred Date (Optional)</label>
            <input type="date" id="preferred_call_date" name="preferred_call_date" class="form-input"
                   min="{{ $today->toDateString() }}" max="{{ $maxDay->toDateString() }}" value="{{ old('preferred_call_date') }}"/>
            <p class="co-hint">Weekdays only.</p>
          </div>
        </fieldset>

        <div class="co-field" style="margin-bottom:14px;">
          <label class="co-option" for="si-terms">
            <input type="checkbox" name="terms" id="si-terms" value="1" required @checked(old('terms'))/>
            <span>I have read and agree to the <a href="{{ route('services.terms') }}" class="lp-link" target="_blank" rel="noopener" data-no-loading>Other Services Terms</a>.</span>
          </label>
        </div>

        <div class="co-actions">
          <a href="{{ route('home') }}" class="lp-btn lp-btn--secondary">Cancel</a>
          <button type="submit" class="lp-btn lp-btn--primary" id="si-submit" data-loading="Sending...">Send Request</button>
        </div>
      </form>
    </div>

    <div class="si-side">
      @include('services._contact-card')

      @if($mine->isNotEmpty())
          <section class="si-card" aria-label="My requests">
            <h2 class="si-card-title">My requests</h2>
            <ul class="si-list rq-mini-list">
              @foreach($mine as $t)
                @php
                  $live    = $t->invoices->first(fn ($inv) => $inv->status !== 'void');
                  $earlier = $t->invoices->filter(fn ($inv) => $inv->status === 'void');
                  $openReq = $t->refundRequests->first(fn ($x) => $x->isOpen());
                  $lastReq = $t->refundRequests->first();
                @endphp
                <li class="rq-mini">
                  <div class="rq-mini__top">
                    <strong>{{ $t->reference_number }}</strong>
                    <span class="si-chip si-chip--{{ $t->status }}">{{ $t->statusLabel() }}</span>
                  </div>
                  <div class="si-list-sub">{{ $t->serviceLabel() }} · {{ $t->created_at->timezone('Asia/Manila')->format('M d, Y') }}</div>

                  @if($t->isCancelled())
                    <p class="rq-mini__info">This service request was cancelled.</p>
                  @elseif($openReq)
                    <p class="rq-mini__info"><strong>Cancellation requested.</strong> Payments are paused until we reply.</p>
                  @elseif($live)
                    <p class="rq-mini__info">Invoice {{ $live->invoice_number }} · ₱{{ number_format((float) $live->total, 2) }} · {{ $live->paymentStateLabel() }}</p>
                  @endif

                  <div class="rq-mini__actions">
                    @if($openReq)
                      <a href="{{ route('services.refund.show', $openReq->reference) }}" class="lp-link lp-link--tap">See request {{ $openReq->reference }}</a>
                    @endif
                    @if($live && !$t->isCancelled())
                      <a href="{{ route('services.invoice', $live->id) }}" class="lp-link lp-link--tap">View invoice {{ $live->invoice_number }}</a>
                    @endif
                    @if($t->isOpen() && !$openReq)
                      <a href="{{ route('services.refund.create', $t->id) }}" class="lp-link lp-link--tap">Request cancellation or refund</a>
                    @endif
                    @if($t->isCancelled())
                      @php $why = $lastReq && !$earlier->count() ? route('services.refund.show', $lastReq->reference) : ($earlier->first() ? route('services.invoice', $earlier->first()->id) : null); @endphp
                      @if($why)<a href="{{ $why }}" class="lp-link lp-link--tap">See What Happened</a>@endif
                    @endif
                  </div>

                  @if($earlier->isNotEmpty())
                    <p class="si-earlier">@if($live)Earlier invoices:@else Cancelled invoice:@endif
                      @foreach($earlier as $inv)
                        <a href="{{ route('services.invoice', $inv->id) }}" class="lp-link">{{ $inv->invoice_number }} (cancelled)</a>@unless($loop->last), @endunless
                      @endforeach
                      @unless($live) · <a href="{{ route('services.invoice', $earlier->first()->id) }}" class="lp-link">Cancelled, view why</a>@endunless
                    </p>
                  @endif
                </li>
              @endforeach
            </ul>
          </section>
      @endif
    </div>

  </div>
</div>
@endsection
