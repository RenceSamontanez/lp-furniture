{{-- The shop's phone and email from Contact Settings; "through our website" when nothing real is set. --}}
@php
  $phone = \App\Support\Luma\Contact::phone();
  $email = \App\Support\Luma\Contact::email();
@endphp
<aside class="si-card" aria-label="Contact details">
  <h2 class="si-card-title">Prefer to talk first?</h2>
  @if($phone || $email)
    @if($phone)
      <p class="si-card-line"><span>Phone</span> <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></p>
    @endif
    @if($email)
      <p class="si-card-line"><span>Email</span> <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    @endif
  @else
    <p class="si-card-line">You can reach us through our website.</p>
  @endif
  <p class="si-card-note">We call Monday to Friday, 8:00 AM to 5:00 PM.</p>
</aside>
