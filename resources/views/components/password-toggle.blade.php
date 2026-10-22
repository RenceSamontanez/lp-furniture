@props(['for'])
{{-- Show/Hide button for the password field with id="{{ $for }}". Behaviour: public/assets/js/password-toggle.js, look: public/assets/css/password-toggle.css. --}}
<button type="button" class="password-toggle" data-password-toggle="{{ $for }}" aria-controls="{{ $for }}" aria-pressed="false" aria-label="Show password">Show</button>
