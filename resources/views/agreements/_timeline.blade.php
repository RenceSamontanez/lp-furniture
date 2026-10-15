{{-- A custom order timeline. $entries comes from CustomOrderTimeline. --}}
@if(empty($entries))
  <p class="co-hint">Nothing has happened yet.</p>
@else
  <ul class="co-tl">
    @foreach($entries as $e)
      <li class="co-tl-{{ $e['kind'] }}">
        <div class="co-tl-title">{{ $e['title'] }}</div>
        @if($e['detail'])<div class="co-tl-detail">{{ $e['detail'] }}</div>@endif
        <div class="co-tl-when">
          {{ $e['at']->timezone('Asia/Manila')->format('M d, Y h:i A') }}@if(!empty($e['actor'])) · {{ $e['actor'] }}@endif
        </div>
      </li>
    @endforeach
  </ul>
@endif
