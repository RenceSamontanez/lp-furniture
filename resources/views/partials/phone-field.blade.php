{{--
  Phone number with a searchable country picker (default Philippines +63). Behaviour: public/assets/js/my-account.js.
  $idp = id prefix, $numName = number field name, $ccName = country field name, $country / $national = current values, $hintText optional
--}}
@php
  $countries = \App\Support\PhoneNumber::countries();
  $cc = old($ccName, $country ?? 'PH');
  $ccRow = collect($countries)->firstWhere('code', $cc) ?? $countries[0];
  $err = $errors->first($numName);
@endphp
<label for="{{ $idp }}ph">Phone Number <span class="rq" aria-hidden="true">*</span></label>
<div class="phone" data-phone>
  <div data-combo="country"><div class="cbw">
    <input class="inp" id="{{ $idp }}cc" type="text" role="combobox" aria-expanded="false" aria-controls="{{ $idp }}cc-l" aria-autocomplete="list" aria-label="Country code"
           autocomplete="off" value="{{ $ccRow['code'] }} {{ $ccRow['dial'] }}">
    <input type="hidden" name="{{ $ccName }}" value="{{ $ccRow['code'] }}">
    <button type="button" class="cbt" tabindex="-1" aria-label="Show countries"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
  </div></div>
  <input class="inp mx-num{{ $err ? ' bad' : '' }}" id="{{ $idp }}ph" name="{{ $numName }}" type="tel" inputmode="tel" maxlength="24"
         value="{{ old($numName, $national ?? '') }}" autocomplete="tel-national" aria-required="true" aria-describedby="{{ $idp }}phh">
</div>
<p class="hint" id="{{ $idp }}phh">{{ $hintText ?? 'We call this number about your orders.' }}</p>
<p class="err{{ $err ? ' show' : '' }}" data-phone-err>{{ $err }}</p>
