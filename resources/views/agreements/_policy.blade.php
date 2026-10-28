{{-- A policy text as headed sections. $text is the stored text (a snapshot or the live policy). --}}
@foreach(\App\Support\PolicyText::sections($text) as $section)
  <section class="pt-section">
    @if($section['heading'])<h3 class="pt-heading">{{ !empty($titleHeadings) ? title_case($section['heading']) : $section['heading'] }}</h3>@endif
    @foreach($section['blocks'] as $block)
      @if($block['type'] === 'list')
        <ul class="pt-list">@foreach($block['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
      @else
        <p class="pt-p">{{ $block['text'] }}</p>
      @endif
    @endforeach
  </section>
@endforeach
