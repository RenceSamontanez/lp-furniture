{{--
  Session alerts as toasts, shared by the customer and admin layouts.
  success / error / status / warning / info flash messages are rendered here
  and nowhere else (pages must not print them inline as well). Validation
  errors stay beside their form fields; only the admin layout passes
  $toastValidation to also toast the first one, as it always did.
--}}
@php
    $lpToasts = [];

    foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'status' => 'info', 'info' => 'info'] as $key => $type) {
        $message = session($key);
        if (is_string($message) && trim($message) !== '') {
            $lpToasts[] = ['type' => $type, 'message' => $message];
        }
    }

    if (($toastValidation ?? false) && isset($errors) && $errors->any()) {
        $lpToasts[] = ['type' => 'error', 'message' => (string) $errors->first()];
    }

    $lpIcons = [
        'success' => '<path d="M20 6L9 17l-5-5"/>',
        'error'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
        'warning' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.01"/>',
        'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    ];
@endphp
@if(count($lpToasts) > 0)
<link rel="stylesheet" href="{{ asset('assets/css/alerts.css') }}"/>
<div class="lp-toasts" id="lp-toasts">
  @foreach($lpToasts as $toast)
    <div class="lp-toast lp-toast--{{ $toast['type'] }}" role="alert" data-lp-toast data-lp-toast-timeout="6000">
      <svg class="lp-toast-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $lpIcons[$toast['type']] !!}</svg>
      <div class="lp-toast-msg">{{ $toast['message'] }}</div>
      <button type="button" class="lp-toast-close" data-lp-toast-close aria-label="Close alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
  @endforeach
</div>
<script src="{{ asset('assets/js/alerts.js') }}" defer></script>
@endif
