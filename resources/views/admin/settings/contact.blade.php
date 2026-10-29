@extends('layouts.admin')

@section('title', 'Contact Settings — Admin')
@section('page-title', 'Contact Settings')

@section('content')

<div class="admin-panel" style="max-width:640px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Business Contact Info</div></div>
  <div class="admin-panel-body">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">
      Shown on the checkout "Need Help" panel, the customer design request page and the website footer, and used in chatbot
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

      <div style="margin-bottom:16px;">
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

      <div style="margin-bottom:20px;">
        <label class="form-label" for="tiktok_url">TikTok Page URL (Optional)</label>
        <input type="url" id="tiktok_url" name="tiktok_url" class="form-input" maxlength="255"
               placeholder="https://www.tiktok.com/@yourshop"
               value="{{ old('tiktok_url', $settings->tiktok_url) }}"/>
        @error('tiktok_url')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          Must start with https:// and be on tiktok.com. Leave it empty to hide the TikTok button in the website footer.
        </div>
      </div>

      <div style="margin:26px 0 8px;font-weight:700;font-size:14px;">Shop location (website footer)</div>
      <div style="margin-bottom:16px;">
        <label class="form-label" for="shop_address">Shop Address</label>
        <input type="text" id="shop_address" name="shop_address" class="form-input" maxlength="255"
               placeholder="e.g. Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas"
               value="{{ old('shop_address', $settings->shop_address) }}"/>
        @error('shop_address')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Leave empty to use the address built into the website.</div>
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label" for="shop_landmarks">Nearby Places</label>
        <input type="text" id="shop_landmarks" name="shop_landmarks" class="form-input" maxlength="500"
               placeholder="e.g. San Juan Doctors Hospital, Calit-calit Barangay Hall"
               value="{{ old('shop_landmarks', $settings->shop_landmarks) }}"/>
        @error('shop_landmarks')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
        <div style="flex:1 1 200px;">
          <label class="form-label" for="map_lat">Map Latitude (Optional)</label>
          <input type="text" inputmode="decimal" id="map_lat" name="map_lat" class="form-input" maxlength="20" placeholder="e.g. 13.8123456"
                 value="{{ old('map_lat', $settings->map_lat) }}"/>
          @error('map_lat')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
        <div style="flex:1 1 200px;">
          <label class="form-label" for="map_lng">Map Longitude (Optional)</label>
          <input type="text" inputmode="decimal" id="map_lng" name="map_lng" class="form-input" maxlength="20" placeholder="e.g. 121.3912345"
                 value="{{ old('map_lng', $settings->map_lng) }}"/>
          @error('map_lng')<div style="font-size:12px;color:#B3261E;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
      </div>
      <div style="font-size:11px;color:var(--text-muted);margin:0 0 20px;">
        To get these numbers: open Google Maps, right-click the exact spot of the shop, and click the two numbers at the top of the menu
        (the first is the latitude, the second the longitude). With both filled in, the footer map and the "Open in Google Maps" link point to that exact spot.
        Leave both empty to search by the address instead.
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
