@extends('layouts.admin')

@section('title', 'Contact Settings — Admin')
@section('page-title', 'Contact Settings')

@section('content')

<div class="admin-panel" style="max-width:640px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Business Contact Info</div></div>
  <div class="admin-panel-body">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">
      Shown on the checkout "Need Help" panel and the customer design request page, and used in chatbot
      replies wherever an intent's response contains <code>@{{phone}}</code>, <code>@{{email}}</code> or <code>@{{facebook}}</code>.
    </p>

    <form method="POST" action="{{ route('admin.settings.contact.update') }}">
      @csrf
      @method('PUT')

      <div style="margin-bottom:16px;">
        <label class="form-label" for="phone">Phone</label>
        <input type="text" id="phone" name="phone" class="form-input" maxlength="30" required
               placeholder="e.g. 0917-123-4567 or (043) 123-4567"
               value="{{ old('phone', $settings->phone) }}"/>
        @error('phone')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Philippine mobile or landline number.</div>
      </div>

      <div style="margin-bottom:16px;">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-input" maxlength="191" required
               placeholder="e.g. hello@yourshop.com"
               value="{{ old('email', $settings->email) }}"/>
        @error('email')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label" for="facebook_url">Facebook Page URL</label>
        <input type="url" id="facebook_url" name="facebook_url" class="form-input" maxlength="255" required
               placeholder="https://facebook.com/YourPage"
               value="{{ old('facebook_url', $settings->facebook_url) }}"/>
        @error('facebook_url')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          Must start with https:// and be on facebook.com or m.me. Customers' "Message us on Facebook" button opens
          <strong>{{ $settings->messenger_url }}</strong>.
        </div>
      </div>

      <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:14px;">
        Save Contact Settings
      </button>
    </form>

    <div style="font-size:12px;color:var(--text-muted);margin-top:14px;">
      @if($settings->updatedBy)
        Last saved by {{ trim($settings->updatedBy->first_name . ' ' . $settings->updatedBy->last_name) ?: $settings->updatedBy->email }}
        on {{ $settings->updated_at?->format('M j, Y g:i A') }}.
      @else
        Not yet saved by an admin — these are the starting placeholders.
      @endif
    </div>
  </div>
</div>

@endsection
