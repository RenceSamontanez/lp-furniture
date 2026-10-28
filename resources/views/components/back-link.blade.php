{{--
  The one "back" control for every customer page: a ghost (secondary) button, "← Back to <place>".
  Link:    <x-back-link :href="route('orders.index')" label="My Orders" />          -> "Back to My Orders"
  Button:  <x-back-link label="delivery details" type="button" onclick="..." />      -> a <button> for checkout steps
  `label` is the place it goes to; pass `text` to replace the whole sentence ("Back").
  Look and size live in public/assets/css/customer-type.css (.lp-btn--back); it is loaded by the customer layouts only.
--}}
@props(['href' => null, 'label' => null, 'text' => null, 'type' => null])
@php
    $words = $text ?? ('Back to ' . $label);
    $class = 'lp-btn lp-btn--secondary lp-btn--back';
@endphp
@if($type === 'button' || $href === null)
    <button type="button" {{ $attributes->merge(['class' => $class]) }}><span class="lp-btn-arrow-left" aria-hidden="true">&larr;</span><span>{{ $words }}</span></button>
@else
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}><span class="lp-btn-arrow-left" aria-hidden="true">&larr;</span><span>{{ $words }}</span></a>
@endif
