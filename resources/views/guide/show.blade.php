@extends('layouts.app')

@section('title', 'How to request a custom design — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/guide.css') }}"/>
@endpush

@section('content')
<div class="guide-page">
  <article class="guide-card">
    <header class="guide-head">
      <h1 class="guide-title">How to Request a Custom Design</h1>
      <p class="guide-lead">Tell us the piece you picture, see a few concepts, and we will call you with a price. Nothing is charged until you accept a quote.</p>
    </header>

    <section aria-labelledby="guide-steps-h">
      <h2 class="guide-h2" id="guide-steps-h">The Steps</h2>
      <ol class="guide-steps">
        <li><h3>Choose the Furniture Type</h3><p>Pick what you want built, such as a table or a wardrobe. If it is not on the list, choose Other and say what it is. The picture will be that type of furniture.</p></li>
        <li><h3>Describe It</h3><p>Write the style, finish and details in your own words, for example "a rustic dining table with rounded corners".</p></li>
        <li><h3>Add the Size and the Wood</h3><p>The size is optional, and the next section shows how to measure. Choose Gmelina, Mahogany or Acacia, or choose Special request if you want another wood or want to bring your own.</p></li>
        <li><h3>Generate</h3><p>Press Generate. It usually takes a minute or two to make a few concepts.</p></li>
        <li><h3>Review</h3><p>Look at the concepts and check that the piece is the type you asked for. If it is not, press "No, try again". Pick the one you like best.</p></li>
        <li><h3>Send It to Our Team</h3><p>Give us your phone number and the best time to call. This is a request, not an order, and nothing is due yet.</p></li>
        <li><h3>Wait for Our Call</h3><p>Our team calls you to talk about the details and the price. If we miss you, we leave a message here in your account.</p></li>
        <li><h3>Accept the Quote</h3><p>We send you a quote in your account. Read the agreement and the Terms and Conditions, then accept it before it expires.</p></li>
        <li><h3>Pay</h3><p>Pay the deposit by card or GCash. Your order is confirmed once we receive it, and we keep you updated until it is ready.</p></li>
      </ol>
    </section>

    <section aria-labelledby="guide-measure-h">
      <h2 class="guide-h2" id="guide-measure-h">How to Measure</h2>
      @include('guide._measure')
    </section>

    <p class="guide-cta">
      <a href="{{ route('design.index') }}" class="guide-btn">Start a Custom Design</a>
      <a href="{{ route('terms') }}" class="guide-link" target="_blank" rel="noopener" data-no-loading>Terms and Conditions</a>
    </p>
  </article>
</div>
@endsection
