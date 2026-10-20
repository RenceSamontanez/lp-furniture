{{--
  Session alerts as glass toasts, shared by the customer, focus and admin layouts.
  success / error / status / warning / info flash messages are rendered here
  and nowhere else (pages must not print them inline as well). Validation
  errors stay beside their form fields; only the admin layout passes
  $toastValidation to also toast the first one, as it always did.
  The container is always present (it is also where scripts add toasts through window.LpToast);
  $toastNav = true when the smart nav exists, which moves the stack below it.
  Errors are role="alert" and last 8s; the rest are role="status" and last 5s.
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
        'success' => '<path d="M5 12.5l4.5 4.5L19 7"/>',
        'error'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
        'warning' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.01"/>',
        'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    ];
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/alerts.css') }}"/>
<div class="lp-toasts{{ ($toastNav ?? false) ? ' lp-toasts--nav' : '' }}" id="lp-toasts" aria-live="polite" aria-relevant="additions">
  @foreach($lpToasts as $toast)
    @php $isError = $toast['type'] === 'error'; @endphp
    <div class="lp-toast lp-toast--{{ $toast['type'] }}" role="{{ $isError ? 'alert' : 'status' }}" data-lp-toast data-lp-toast-timeout="{{ $isError ? 8000 : 5000 }}" style="--d: {{ $isError ? 8 : 5 }}s">
      <span class="lp-toast-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">{!! $lpIcons[$toast['type']] !!}</svg></span>
      <div class="lp-toast-msg">{{ $toast['message'] }}</div>
      <button type="button" class="lp-toast-close" data-lp-toast-close aria-label="Dismiss notification">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
      <span class="lp-toast-bar" aria-hidden="true"></span>
    </div>
  @endforeach
</div>
<script src="{{ asset('assets/js/alerts.js') }}" defer></script>
