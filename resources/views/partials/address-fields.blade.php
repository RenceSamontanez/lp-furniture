{{--
  The structured delivery address fields (My Account and the order details step). Behaviour: public/assets/js/my-account.js.
  $p   = field name prefix ('' on My Account, 'addr_' on the order form)   $idp = id prefix
  $v   = current values: province_id, province, city_id, city, barangay_id, barangay, street, postal_code, landmark
  The three place fields send ids only; the server re-checks that the barangay is inside the city and the city inside the province.
--}}
@php
  $v = $v ?? [];
  $val = fn (string $k) => old($p . $k, $v[$k] ?? '');
  $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 005 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
  $chev = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  // old input after a failed save carries only the ids: write the names back from our own tables
  $ids = [];
  foreach (['province_id', 'city_id', 'barangay_id'] as $k) { $ids[$k] = old($p . $k, $v[$k] ?? null); }
  $nm = array_filter($ids) ? \App\Support\DeliveryAddress::names($ids) : ['province' => '', 'city' => '', 'barangay' => ''];
  $places = [
    ['key' => 'province', 'label' => 'Province',              'field' => 'province_id', 'col' => 'c4'],
    ['key' => 'city',     'label' => 'City or municipality',  'field' => 'city_id',     'col' => 'c4'],
    ['key' => 'barangay', 'label' => 'Barangay',              'field' => 'barangay_id', 'col' => 'c4'],
  ];
@endphp
<div class="g12" data-address
     data-url-provinces="{{ route('places.provinces') }}"
     data-url-cities="{{ route('places.cities', ['province' => '__ID__']) }}"
     data-url-barangays="{{ route('places.barangays', ['city' => '__ID__']) }}">
  @foreach($places as $pl)
    @php
      $id = $idp . $pl['key'];
      $err = $errors->first($p . $pl['field']);
    @endphp
    <div class="fld {{ $pl['col'] }}" data-combo="{{ $pl['key'] }}">
      <label for="{{ $id }}">{{ $pl['label'] }} <span class="rq" aria-hidden="true">*</span></label>
      <div class="cbw">
        <input class="inp{{ $err ? ' bad' : '' }}" id="{{ $id }}" type="text" role="combobox" aria-expanded="false" aria-controls="{{ $id }}-l" aria-autocomplete="list"
               autocomplete="off" placeholder="Search or choose" value="{{ $nm[$pl['key']] }}" aria-required="true">
        <input type="hidden" name="{{ $p . $pl['field'] }}" value="{{ old($p . $pl['field'], $v[$pl['field']] ?? '') }}">
        <button type="button" class="cbt" tabindex="-1" aria-label="Show {{ strtolower($pl['label']) }} options">{!! $chev !!}</button>
      </div>
      <p class="err{{ $err ? ' show' : '' }}">{{ $err }}</p>
    </div>
  @endforeach

  <div class="fld c6">
    <label for="{{ $idp }}street">House no., street <span class="rq" aria-hidden="true">*</span></label>
    <input class="inp{{ $errors->has($p . 'street') ? ' bad' : '' }}" id="{{ $idp }}street" name="{{ $p }}street" maxlength="120" autocomplete="address-line1"
           placeholder="Blk 5 Lot 12, Sampaguita St." value="{{ $val('street') }}" aria-required="true">
    <p class="err{{ $errors->has($p . 'street') ? ' show' : '' }}">{{ $errors->first($p . 'street') }}</p>
  </div>
  <div class="fld c2">
    <label for="{{ $idp }}zip">Postal code <span class="rq" aria-hidden="true">*</span></label>
    <input class="inp mx-num{{ $errors->has($p . 'postal_code') ? ' bad' : '' }}" id="{{ $idp }}zip" name="{{ $p }}postal_code" inputmode="numeric" maxlength="4"
           placeholder="4217" autocomplete="postal-code" value="{{ $val('postal_code') }}" aria-required="true">
    <p class="err{{ $errors->has($p . 'postal_code') ? ' show' : '' }}">{{ $errors->first($p . 'postal_code') }}</p>
  </div>
  <div class="fld c4">
    <label for="{{ $idp }}note">Landmark <span class="opt">optional</span></label>
    <input class="inp{{ $errors->has($p . 'landmark') ? ' bad' : '' }}" id="{{ $idp }}note" name="{{ $p }}landmark" maxlength="120"
           placeholder="Near the chapel, blue gate" value="{{ $val('landmark') }}">
    <p class="err{{ $errors->has($p . 'landmark') ? ' show' : '' }}">{{ $errors->first($p . 'landmark') }}</p>
  </div>

  <div class="c12 prev" data-preview aria-live="polite">{!! $pin !!}<div><b>Delivery address</b><span class="pvt">Your full address appears here as you fill it in.</span></div></div>
</div>
