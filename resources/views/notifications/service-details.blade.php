@extends('layouts.app')

@section('title', $d['headline'] . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/notification-details.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
<div class="nd-wrap">
  <article class="nd-card" id="notif-detail" aria-labelledby="nd-title">
    <a href="{{ route('notifications.index') }}" class="lp-link lp-link--tap nd-back">← Back to notifications</a>

    <p class="nd-eyebrow">Notification · {{ $d['when'] }}</p>
    <h1 class="nd-title" id="nd-title">{{ $d['headline'] }}</h1>

    @if($d['important'])
      <x-notice :variant="$d['important'][0]" :title="$d['important'][1]">{{ $d['important'][2] ?? '' }}</x-notice>
    @endif

    <section class="nd-section" aria-labelledby="nd-what">
      <h2 class="nd-h2" id="nd-what">What happened</h2>
      <p class="nd-text">{{ $d['happened'] }}</p>
      @if($d['current'])
        <p class="nd-current">{{ $d['current'] }}</p>
      @endif
    </section>

    @if($d['reason'])
      <section class="nd-section" aria-labelledby="nd-reason">
        <h2 class="nd-h2" id="nd-reason">{{ $d['reason'][0] }}</h2>
        <p class="nd-text nd-quote">{{ $d['reason'][1] }}</p>
      </section>
    @endif

    @if(count($d['facts']) || count($d['refs']))
      <section class="nd-section" aria-labelledby="nd-affected">
        <h2 class="nd-h2" id="nd-affected">What this is about</h2>
        <dl class="nd-facts">
          @foreach($d['facts'] as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
          @endforeach
          @foreach($d['refs'] as $label => $value)
            <div>
              <dt>{{ $label }}</dt>
              <dd class="nd-ref"><span class="nd-chip">{{ $value }}</span>
                <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip nd-copy" data-copy-text="{{ $value }}" aria-label="Copy {{ strtolower($label) }} number {{ $value }}">Copy</button></dd>
            </div>
          @endforeach
        </dl>
      </section>
    @endif

    <section class="nd-section" aria-labelledby="nd-next">
      <h2 class="nd-h2" id="nd-next">What happens next</h2>
      <p class="nd-text">{{ $d['next'] ?? 'You can follow this under My service requests.' }}</p>
    </section>

    <section class="nd-section" aria-labelledby="nd-track">
      <h2 class="nd-h2" id="nd-track">How to track this</h2>
      <p class="nd-text">
        See it any time under <a href="{{ $d['list_url'] }}" class="lp-link">My service requests</a>@if($d['item_url']), or <a href="{{ $d['item_url'] }}" class="lp-link">open it directly</a>@endif.
        @if(count($d['refs'])) You can also ask Luma about it with the reference number.@endif
      </p>
    </section>

    <div class="nd-actions">
      <a href="{{ route('notifications.index') }}" class="lp-btn lp-btn--secondary">Back to notifications</a>
      @if(config('luma.notification_ask_button') && $d['ask_ref'])
        <button type="button" class="lp-btn lp-btn--primary" id="nd-ask-luma" data-ref="{{ $d['ask_ref'] }}">Ask Luma about this</button>
      @endif
    </div>
  </article>
</div>
<script>
  document.querySelectorAll('.nd-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy-text'), was = b.textContent;
      function ok() { b.textContent = 'Copied'; setTimeout(function () { b.textContent = was; }, 1600); }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(ok, ok); } else { ok(); }
    });
  });
  var ask = document.getElementById('nd-ask-luma');
  if (ask) {
    ask.addEventListener('click', function () {
      if (typeof openChatbot === 'function') { openChatbot(); }
      var input = document.getElementById('chat-input');
      if (input) { input.value = ask.getAttribute('data-ref') + ' '; input.focus(); }
    });
  }
</script>
@endsection
