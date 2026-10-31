{{-- The checkout "Action needed" banner. One for server errors at the top of the page ($messages filled) and one inside the
     Payment Method step for client-side checks (empty, hidden until needed). Focusable so it can be focused when it appears. --}}
@props(['id', 'messages' => []])
<div class="ckn-alert" id="{{ $id }}" role="alert" tabindex="-1" @if(empty($messages)) hidden @endif>
  <svg class="ckn-alert-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
  </svg>
  <div class="ckn-alert-text">
    <strong class="ckn-alert-title">Action needed</strong>
    <div class="ckn-alert-list" data-ckn-alert-list>@foreach($messages as $m)<p>{{ $m }}</p>@endforeach</div>
  </div>
</div>
