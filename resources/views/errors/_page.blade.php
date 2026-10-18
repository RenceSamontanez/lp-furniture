{{-- Shared body for the branded 403 / 404 / 419 pages. Uses the normal site layout. --}}
@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/errors.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
<div class="si-wrap">
  <div class="er-grid">
    <div class="si-card er-main">
      <div class="er-code" aria-hidden="true">{{ $code }}</div>
      <h1 class="er-title">{{ $heading }}</h1>
      <p class="er-text">{{ $text }}</p>
      <div class="er-actions">
        <a href="{{ url('/') }}" class="lp-btn lp-btn--primary">Go to home</a>
        <a href="{{ route('catalog') }}" class="lp-btn lp-btn--secondary">Browse catalog</a>
      </div>
    </div>
    <div class="er-side">
      @include('services._contact-card')
    </div>
  </div>
</div>
@endsection
