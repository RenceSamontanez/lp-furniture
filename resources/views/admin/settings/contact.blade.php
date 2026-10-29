@extends('layouts.admin')

@section('title', 'Contact Settings — Admin')
@section('page-title', 'Contact Settings')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
@endpush

@php
  $openModal = old('_section') === 'business' ? 'contact-biz' : (old('_section') === 'shop' ? 'contact-shop' : null);
  $mineBiz   = $openModal === 'contact-biz';
  $mineShop  = $openModal === 'contact-shop';
  $v = fn (bool $mine, string $k) => $mine ? old($k, $settings->{$k}) : $settings->{$k};
  $e = fn (bool $mine, string $k) => $mine ? $errors->first($k) : '';
  $filled = fn ($x) => trim((string) $x) !== '';
  $pin = $filled($settings->map_lat) && $filled($settings->map_lng) ? $settings->map_lat . ', ' . $settings->map_lng : null;
@endphp

@section('content')

<p class="page-lead">These details appear in the website footer, the checkout Need Help panel, the design request page and in chatbot replies.</p>

<div class="ct-grid">
  <div class="admin-panel" style="margin:0;">
    <div class="admin-panel-header"><div class="admin-panel-title">Business Contact Info</div>
      <button type="button" class="admin-btn admin-btn-outline" data-lp-modal-open="contact-biz">Edit</button></div>
    <div class="admin-panel-body">
      <dl class="ct-kv">
        <dt>Phone</dt><dd>@if($filled($settings->phone)){{ $settings->phone }}@else<span class="is-empty">Not set</span>@endif</dd>
        <dt>Email</dt><dd>@if($filled($settings->email)){{ $settings->email }}@else<span class="is-empty">Not set</span>@endif</dd>
        <dt>Facebook Page</dt><dd>@if($filled($settings->facebook_url)){{ $settings->facebook_url }}@else<span class="is-empty">Not set</span>@endif</dd>
        <dt>TikTok Page</dt><dd>@if($filled($settings->tiktok_url)){{ $settings->tiktok_url }}@else<span class="is-empty">Not set. The TikTok button stays hidden in the website footer.</span>@endif</dd>
      </dl>
    </div>
  </div>

  <div class="admin-panel" style="margin:0;">
    <div class="admin-panel-header"><div class="admin-panel-title">Shop Location</div>
      <button type="button" class="admin-btn admin-btn-outline" data-lp-modal-open="contact-shop">Edit</button></div>
    <div class="admin-panel-body">
      <dl class="ct-kv">
        <dt>Shop Address</dt><dd>@if($filled($settings->shop_address)){{ $settings->shop_address }}@else<span class="is-empty">Not set. The address built into the website is used.</span>@endif</dd>
        <dt>Nearby Places</dt><dd>@if($filled($settings->shop_landmarks)){{ $settings->shop_landmarks }}@else<span class="is-empty">Not set</span>@endif</dd>
        <dt>Map Pin</dt><dd>@if($pin){{ $pin }}@else<span class="is-empty">Not set. The map searches by the address instead.</span>@endif</dd>
      </dl>
    </div>
  </div>

  <div class="admin-panel ct-wide" style="margin:0;">
    <div class="admin-panel-header"><div class="admin-panel-title">Where This Shows</div></div>
    <div class="admin-panel-body">
      <ul class="ct-where">
        <li>The website footer, with the map pin, the nearby places and the Facebook and TikTok buttons</li>
        <li>The checkout Need Help panel</li>
        <li>The design request page</li>
        <li>Chatbot replies: Luma's contact answers, and any response that uses <code>@{{phone}}</code>, <code>@{{email}}</code> or <code>@{{facebook}}</code></li>
        <li>Pickup details: the order details block and the order tracking page</li>
        <li>The Other Services page and the printed service invoice</li>
        <li>Notification and email wording, and the terms and policy pages</li>
      </ul>
      <p class="ct-saved">
        @if($settings->updatedBy)
          Last saved by {{ trim($settings->updatedBy->first_name . ' ' . $settings->updatedBy->last_name) ?: $settings->updatedBy->email }}
          on {{ $settings->updated_at?->format('M j, Y g:i A') }}.
        @else
          Not yet saved by an admin — these are the starting placeholders.
        @endif
      </p>
    </div>
  </div>
</div>

<x-modal id="contact-biz" title="Edit Business Contact" size="mid" :data-guard="true" :data-open-on-load="$mineBiz" :data-force-dirty="$mineBiz">
  <form method="POST" id="contact-biz-form" data-modal-form action="{{ route('admin.settings.contact.update') }}" novalidate>
    @csrf
    @method('PUT')
    <input type="hidden" name="_section" value="business">
    <div class="am-grid">
      <div class="am-field {{ $e($mineBiz, 'phone') ? 'is-invalid' : '' }}">
        <label class="form-label" for="phone">Phone</label>
        <input type="text" id="phone" name="phone" class="form-input" maxlength="30" data-required
               placeholder="e.g. 0917-123-4567 or (043) 123-4567" value="{{ $v($mineBiz, 'phone') }}"/>
        <div class="am-help">Philippine mobile or landline number.</div>
        <p class="am-err" data-client="Enter a Philippine mobile or landline number.">{{ $e($mineBiz, 'phone') }}</p>
      </div>
      <div class="am-field {{ $e($mineBiz, 'email') ? 'is-invalid' : '' }}">
        <label class="form-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="form-input" maxlength="191" data-required
               placeholder="e.g. hello@yourshop.com" value="{{ $v($mineBiz, 'email') }}"/>
        <p class="am-err" data-client="Enter an email address.">{{ $e($mineBiz, 'email') }}</p>
      </div>
      <div class="am-field am-full {{ $e($mineBiz, 'facebook_url') ? 'is-invalid' : '' }}">
        <label class="form-label" for="facebook_url">Facebook Page URL</label>
        <input type="url" id="facebook_url" name="facebook_url" class="form-input" maxlength="255" data-required
               placeholder="https://facebook.com/YourPage" value="{{ $v($mineBiz, 'facebook_url') }}"/>
        <div class="am-help">Must start with https:// and be on facebook.com or m.me. Customers' "Message us on Facebook" button opens <strong>{{ $settings->messenger_url }}</strong>.</div>
        <p class="am-err" data-client="Enter the Facebook page address.">{{ $e($mineBiz, 'facebook_url') }}</p>
      </div>
      <div class="am-field am-full {{ $e($mineBiz, 'tiktok_url') ? 'is-invalid' : '' }}">
        <label class="form-label" for="tiktok_url">TikTok Page URL <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <input type="url" id="tiktok_url" name="tiktok_url" class="form-input" maxlength="255"
               placeholder="https://www.tiktok.com/@yourshop" value="{{ $v($mineBiz, 'tiktok_url') }}"/>
        <div class="am-help">Must start with https:// and be on tiktok.com. Leave it empty to hide the TikTok button in the website footer.</div>
        <p class="am-err">{{ $e($mineBiz, 'tiktok_url') }}</p>
      </div>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="contact-biz-form" class="lp-btn lp-btn--primary" data-lp-confirm>Save Changes</button>
  </x-slot:footer>
</x-modal>

<x-modal id="contact-shop" title="Edit Shop Location" size="mid" :data-guard="true" :data-open-on-load="$mineShop" :data-force-dirty="$mineShop">
  <form method="POST" id="contact-shop-form" data-modal-form action="{{ route('admin.settings.contact.update') }}" novalidate>
    @csrf
    @method('PUT')
    <input type="hidden" name="_section" value="shop">
    <div class="am-grid">
      <div class="am-field am-full {{ $e($mineShop, 'shop_address') ? 'is-invalid' : '' }}">
        <label class="form-label" for="shop_address">Shop Address</label>
        <input type="text" id="shop_address" name="shop_address" class="form-input" maxlength="255"
               placeholder="e.g. Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas" value="{{ $v($mineShop, 'shop_address') }}"/>
        <div class="am-help">Leave empty to use the address built into the website.</div>
        <p class="am-err">{{ $e($mineShop, 'shop_address') }}</p>
      </div>
      <div class="am-field am-full {{ $e($mineShop, 'shop_landmarks') ? 'is-invalid' : '' }}">
        <label class="form-label" for="shop_landmarks">Nearby Places</label>
        <input type="text" id="shop_landmarks" name="shop_landmarks" class="form-input" maxlength="500"
               placeholder="e.g. San Juan Doctors Hospital, Calit-calit Barangay Hall" value="{{ $v($mineShop, 'shop_landmarks') }}"/>
        <p class="am-err">{{ $e($mineShop, 'shop_landmarks') }}</p>
      </div>
      <div class="am-field {{ $e($mineShop, 'map_lat') ? 'is-invalid' : '' }}">
        <label class="form-label" for="map_lat">Map Latitude <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <input type="text" inputmode="decimal" id="map_lat" name="map_lat" class="form-input" maxlength="20" placeholder="e.g. 13.8123456" value="{{ $v($mineShop, 'map_lat') }}"/>
        <p class="am-err">{{ $e($mineShop, 'map_lat') }}</p>
      </div>
      <div class="am-field {{ $e($mineShop, 'map_lng') ? 'is-invalid' : '' }}">
        <label class="form-label" for="map_lng">Map Longitude <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <input type="text" inputmode="decimal" id="map_lng" name="map_lng" class="form-input" maxlength="20" placeholder="e.g. 121.3912345" value="{{ $v($mineShop, 'map_lng') }}"/>
        <p class="am-err">{{ $e($mineShop, 'map_lng') }}</p>
      </div>
      <p class="am-help am-full" style="margin:0;">
        To get these numbers: open Google Maps, right-click the exact spot of the shop, and click the two numbers at the top of the menu
        (the first is the latitude, the second the longitude). With both filled in, the footer map and the "Open in Google Maps" link point to that exact spot.
        Leave both empty to search by the address instead.
      </p>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="contact-shop-form" class="lp-btn lp-btn--primary" data-lp-confirm>Save Changes</button>
  </x-slot:footer>
</x-modal>

@endsection
