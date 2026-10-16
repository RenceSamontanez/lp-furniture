@extends('layouts.admin')

@section('title', 'New Service Ticket — Admin')
@section('page-title', 'New Service Ticket')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php
  $today  = \App\Support\ServiceInquiryDesk::today();
  $maxDay = $today->copy()->addDays(\App\Support\ServiceInquiryDesk::DATE_HORIZON_DAYS);
@endphp

<a href="{{ route('admin.service-inquiries.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Other Services</a>

@if($errors->any())
  <div class="co-error" role="alert">
    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

<div class="admin-panel si-form">
  <div class="admin-panel-header"><div class="admin-panel-title">Enter a ticket for a walk-in or phone customer</div></div>
  <div class="admin-panel-body">
    <form method="POST" action="{{ route('admin.service-inquiries.store') }}">
      @csrf

      <div class="co-block-label" style="margin-top:0;">Service</div>
      <div class="si-radio-list">
        @foreach(\App\Models\ServiceInquiry::SERVICE_TYPES as $value => $label)
          <label><input type="radio" name="service_type" value="{{ $value }}" required @checked(old('service_type') === $value)/> {{ $label }}</label>
        @endforeach
      </div>

      <div class="co-field">
        <label class="co-block-label" for="furniture_type" style="margin-top:0;">Furniture type (optional)</label>
        <select id="furniture_type" name="furniture_type" class="form-input">
          <option value="">Not sure or not listed</option>
          @foreach(\App\Support\FurnitureTypes::LIST as $f)
            <option value="{{ $f }}" @selected(old('furniture_type') === $f)>{{ $f }}</option>
          @endforeach
        </select>
      </div>

      <div class="co-field">
        <label class="co-block-label" for="concern" style="margin-top:0;">Concern</label>
        <textarea id="concern" name="concern" class="form-textarea" rows="5" minlength="10" maxlength="2000" required>{{ old('concern') }}</textarea>
      </div>

      <div class="si-form-grid">
        <div class="co-field">
          <label class="co-block-label" for="name" style="margin-top:0;">Name</label>
          <input type="text" id="name" name="name" class="form-input" required maxlength="120" value="{{ old('name') }}"/>
        </div>
        <div class="co-field">
          <label class="co-block-label" for="phone" style="margin-top:0;">Mobile number</label>
          <input type="tel" id="phone" name="phone" class="form-input" required maxlength="16" value="{{ old('phone') }}" placeholder="09171234567"/>
        </div>
      </div>

      <div class="co-field">
        <label class="co-block-label" for="email" style="margin-top:0;">Email (optional)</label>
        <input type="email" id="email" name="email" class="form-input" maxlength="150" value="{{ old('email') }}"/>
      </div>

      <div class="si-form-grid">
        <div class="co-field">
          <label class="co-block-label" for="preferred_call_window" style="margin-top:0;">Preferred call time</label>
          <select id="preferred_call_window" name="preferred_call_window" class="form-input">
            <option value="">Any time</option>
            @foreach(\App\Models\CustomDesignRequest::CALL_WINDOWS as $value => $label)
              <option value="{{ $value }}" @selected(old('preferred_call_window') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="co-field">
          <label class="co-block-label" for="preferred_call_date" style="margin-top:0;">Preferred date (optional)</label>
          <input type="date" id="preferred_call_date" name="preferred_call_date" class="form-input"
                 min="{{ $today->toDateString() }}" max="{{ $maxDay->toDateString() }}" value="{{ old('preferred_call_date') }}"/>
          <p class="si-hint">Weekdays only. Calls are made Monday to Friday, 8 AM to 5 PM.</p>
        </div>
      </div>

      <p class="si-hint">A ticket entered here has no customer account, so the customer is not notified in the app.</p>

      <div class="co-modal-actions">
        <a href="{{ route('admin.service-inquiries.index') }}" class="admin-btn admin-btn-outline">Cancel</a>
        <button type="submit" class="admin-btn admin-btn-gold" data-loading="Saving...">Create ticket</button>
      </div>
    </form>
  </div>
</div>
</div>
@endsection
