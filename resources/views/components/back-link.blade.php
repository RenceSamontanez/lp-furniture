{{--
  The one "back" control for every customer page: a ghost (secondary) button, words only, no arrow.
  Page level:   <x-back-link :href="route('orders.index')" label="My Orders" />   -> "Back" (screen readers hear "Back to My Orders")
  Named (checkout steps, where the next-to-it button names the place too):
                <x-back-link named label="Review" type="button" onclick="..." />  -> "Back to Review" (a <button>)
  `label` is the place it goes to; pass `text` to replace the whole word group.
  Look and size live in public/assets/css/customer-type.css (.lp-btn--back); it is loaded by the customer layouts only.
--}}
@props(['href' => null, 'label' => null, 'text' => null, 'type' => null, 'named' => false])
@php
    $place = $label !== null ? \App\Support\TitleCase::apply($label) : null;
    $words = $text ?? ($named && $place ? 'Back to ' . $place : 'Back');
    $aria  = $place && $words === 'Back' ? 'Back to ' . $place : null;
    $class = 'lp-btn lp-btn--secondary lp-btn--back';
@endphp
@if($type === 'button' || $href === null)
    <button type="button" {{ $attributes->merge(['class' => $class]) }} @if($aria) aria-label="{{ $aria }}" @endif><span>{{ $words }}</span></button>
@else
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }} @if($aria) aria-label="{{ $aria }}" @endif><span>{{ $words }}</span></a>
@endif
