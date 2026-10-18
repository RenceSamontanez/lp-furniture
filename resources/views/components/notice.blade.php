@props(['variant' => 'info', 'title' => '', 'role' => null])
@php
  $variant = in_array($variant, ['info', 'waiting', 'blocked', 'success', 'problem'], true) ? $variant : 'info';
  $live = $role ?? ($variant === 'problem' ? 'alert' : 'status');
@endphp
<div {{ $attributes->class(['lp-notice', 'lp-notice--' . $variant]) }} role="{{ $live }}">
  <svg class="lp-notice__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($variant)
      @case('waiting')
        <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
        @break
      @case('blocked')
        <rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
        @break
      @case('success')
        <circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>
        @break
      @case('problem')
        <path d="M12 4 3 19h18L12 4Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>
        @break
      @default
        <circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>
    @endswitch
  </svg>
  <div class="lp-notice__body">
    @if($title !== '')<p class="lp-notice__title">{{ $title }}</p>@endif
    @if(trim((string) $slot) !== '')<div class="lp-notice__text">{{ $slot }}</div>@endif
    @isset($actions)<div class="lp-notice__actions">{{ $actions }}</div>@endisset
  </div>
</div>
