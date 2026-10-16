@extends('layouts.app')

@section('title', 'Labor-only request — LP Furniture')

@section('content')
<div class="terms-page">
  <article class="terms-card" style="text-align:center;">
    <h1 class="terms-title">Coming soon</h1>
    <p>The labor-only request form is being prepared. For now, please contact us and the owner will discuss your furniture with you by phone.</p>
    @php($phone = \App\Support\Luma\Contact::phone())
    @php($email = \App\Support\Luma\Contact::email())
    @if($phone || $email)
      <p>
        @if($phone) Phone: <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a><br>@endif
        @if($email) Email: <a href="mailto:{{ $email }}">{{ $email }}</a>@endif
      </p>
    @endif
    <p><a href="{{ route('home') }}" class="link-text">Back to the shop</a></p>
  </article>
</div>
@endsection
