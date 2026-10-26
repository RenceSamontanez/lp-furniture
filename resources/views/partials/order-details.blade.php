{{--
  Order details step (catalog checkout and custom agreement checkout): who receives it, their phone and where to deliver.
  Posts recipient_name, phone_country, contact_phone, address_mode, addr_*, keep_address with the surrounding form.
  The server re-checks everything (App\Support\OrderContact); this block only makes it easy and lists what is missing.
  Optional: $continue = CSS selector of the button(s) that must stay disabled until the details are complete.
--}}
@php
  $odUser   = Auth::user();
  $odE164   = \App\Support\PhoneNumber::forUser($odUser);
  [$odCountry, $odNational] = \App\Support\PhoneNumber::split($odE164);
  $odSavedIds = \App\Support\DeliveryAddress::fromUser($odUser);
  $odSaved  = $odSavedIds ? $odSavedIds + ['names' => \App\Support\DeliveryAddress::names($odSavedIds), 'line' => \App\Support\DeliveryAddress::line($odSavedIds)] : null;
  $odLegacy = $odSaved ? null : (trim((string) $odUser->address) !== '' ? trim($odUser->address) : null);
  // the form was sent back with errors in the address block: open the form, not the saved card
  $odMode   = old('address_mode', $odSaved ? 'saved' : 'new');
  $odKeep   = old('keep_address', $odSaved ? 'no' : 'yes');
  $odPin    = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11.5A7 7 0 005 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
  $odErr    = $errors->first('recipient_name');
  $odData   = ['tab' => null, 'countries' => \App\Support\PhoneNumber::countries(), 'phoneRules' => \App\Support\PhoneNumber::rulesForScript()];
@endphp
@once
  @push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/my-account.css') }}?v={{ @filemtime(public_path('assets/css/my-account.css')) }}"/>
  @endpush
  @push('scripts')
    <script type="application/json" id="ma-data">@json($odData)</script>
    <script src="{{ asset('assets/js/my-account.js') }}?v={{ @filemtime(public_path('assets/js/my-account.js')) }}"></script>
  @endpush
@endonce

<section class="mx od" data-order-details data-continue="{{ $continue ?? '' }}" data-has-saved="{{ $odSaved ? '1' : '0' }}" aria-label="Order details" style="margin:4px 0 18px">
  <div class="g12">
    <div class="fld c6"><label for="oName">Recipient name <span class="rq" aria-hidden="true">*</span></label>
      <input class="inp{{ $odErr ? ' bad' : '' }}" id="oName" name="recipient_name" value="{{ old('recipient_name', $odUser->display_name) }}" maxlength="100" autocomplete="name" aria-required="true">
      <p class="err{{ $odErr ? ' show' : '' }}">{{ $odErr }}</p></div>
    <div class="fld c6">
      @include('partials.phone-field', ['idp' => 'o', 'numName' => 'contact_phone', 'ccName' => 'phone_country', 'country' => $odCountry, 'national' => $odNational, 'hintText' => 'We call this number to confirm your order.'])
    </div>
  </div>

  <input type="hidden" name="address_mode" value="{{ $odMode }}" data-od-mode>

  @if($odSaved)
    <div class="saved" data-od-saved style="margin-top:14px" @if($odMode !== 'saved') hidden @endif>
      <span class="ico ok">{!! $odPin !!}</span>
      <div class="t"><span class="tag">Saved address</span><b>{{ $odSaved['street'] }}</b>
        <small>Brgy. {{ preg_replace('/^Brgy\.?\s+/i', '', $odSaved['names']['barangay']) }}, {{ $odSaved['names']['city'] }}, {{ $odSaved['names']['province'] }} <span class="mx-num">{{ $odSaved['postal_code'] }}</span>@if($odSaved['landmark']) · {{ $odSaved['landmark'] }}@endif</small></div>
      <button type="button" class="lp-btn lp-btn--secondary" data-od-diff>Use a different address</button>
    </div>
  @endif

  <div data-od-form style="margin-top:14px" @if($odSaved && $odMode === 'saved') hidden @endif>
    <div class="ash"><h2>Delivery address</h2><span><span class="rq">*</span> required</span></div>
    @if($odLegacy)
      <p class="prevnote">{!! $odPin !!}<span><b>Previous address (read only):</b> {{ $odLegacy }}<br>Please choose the province, city and barangay below before you order.</span></p>
    @endif
    @include('partials.address-fields', ['p' => 'addr_', 'idp' => 'o', 'v' => []])
    <fieldset class="ask" data-od-ask>
      <legend data-od-ask-legend>{{ $odSaved ? 'Replace your saved delivery address with this one?' : 'Use this as my permanent delivery address?' }}</legend>
      <div class="opts" data-od-opts>
        @if($odSaved)
          <label class="opt2"><input type="radio" name="keep_address" value="no" @checked($odKeep !== 'yes')><span>No, only for this order<small>Your saved address stays as it is.</small></span></label>
          <label class="opt2"><input type="radio" name="keep_address" value="yes" @checked($odKeep === 'yes')><span>Yes, replace my saved address<small>Next orders will use this one.</small></span></label>
        @else
          <label class="opt2"><input type="radio" name="keep_address" value="yes" @checked($odKeep !== 'no')><span>Yes, save it<small>Next orders will use it automatically.</small></span></label>
          <label class="opt2"><input type="radio" name="keep_address" value="no" @checked($odKeep === 'no')><span>No, only for this order<small>I will enter it again next time.</small></span></label>
        @endif
      </div>
    </fieldset>
  </div>

  <p class="od-foot" style="border-top:0;padding-top:6px"><span class="msg" data-od-msg aria-live="polite"></span></p>
</section>
