@php
  // Focus pages (sign in, register, password reset, email notice, ID validation): no nav, no footer, no chat.
  // Just a small logo link home, the page's own controls and the shared toast container.
  $lpPath = fn (string $p) => parse_url(asset($p), PHP_URL_PATH);
  $lpVer  = fn (string $p) => $lpPath($p) . '?v=' . (@filemtime(public_path($p)) ?: 1);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'LP Furniture')</title>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/fonts.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/customer-type.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/focus.css') }}"/>
@stack('styles')
</head>
<body class="focus-body lp-type" data-nav="light">

<header class="focus-top">
  <a href="{{ route('home') }}" class="focus-logo">LP <em>Furniture</em></a>
</header>

@include('partials.alerts')

<main class="focus-main">
@yield('content')
</main>

<script>
  const APP = {
    csrfToken: "{{ csrf_token() }}",
    isLoggedIn: {{ Auth::check() ? 'true' : 'false' }},
    homeUrl: "{{ route('home', [], false) }}",
  };
</script>
<script src="{{ $lpVer('assets/main.js') }}"></script>
<script src="{{ asset('assets/js/lp-modal.js') }}"></script>
@stack('scripts')

@include('partials.co-loading')

</body>
</html>
