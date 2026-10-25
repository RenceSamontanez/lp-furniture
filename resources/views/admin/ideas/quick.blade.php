@extends('layouts.admin')

@section('title', 'Quick ideas — Admin')
@section('page-title', 'Quick ideas')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-ideas.css') }}"/>
@endpush

@php
  $sections = ['type' => 'By furniture type', 'style' => 'By style', 'general' => 'Anything else'];
@endphp

@section('content')
<div class="co-page ir-page">

  <p class="ir-lead qi-lead">The tap-to-add chips under the description on the Customize page. A customer sees the ideas for the furniture type they picked, then the ones for the style, up to 7. With neither picked they see the &ldquo;Anything else&rdquo; list. Changes show on the next page load.</p>

  @if($errors->any())
    <x-notice variant="problem" title="That did not save">
      @foreach($errors->all() as $message){{ $message }} @endforeach
    </x-notice>
  @endif

  @foreach($sections as $scope => $heading)
    <h2 class="qi-section">{{ $heading }}</h2>
    <div class="qi-grid">
      @foreach($groups[$scope] as $group)
        <section class="qi-card" aria-label="{{ $group['label'] }}">
          <h3>{{ $group['label'] }}</h3>
          @forelse($group['rows'] as $i => $idea)
            <div class="qi-row {{ $idea->is_active ? '' : 'is-off' }}">
              <form method="POST" action="{{ route('admin.quick-ideas.update', $idea->id) }}" class="qi-edit">
                @csrf
                <label class="cz-sr" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)" for="qi-{{ $idea->id }}">Idea wording</label>
                <input type="text" id="qi-{{ $idea->id }}" name="text" value="{{ $idea->text }}" maxlength="{{ $max }}" class="qi-input" required/>
                <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip">Save</button>
              </form>
              <div class="qi-tools">
                <form method="POST" action="{{ route('admin.quick-ideas.move', $idea->id) }}">@csrf<input type="hidden" name="dir" value="up"/>
                  <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-label="Move {{ $idea->text }} up" @disabled($i === 0)>&uarr;</button></form>
                <form method="POST" action="{{ route('admin.quick-ideas.move', $idea->id) }}">@csrf<input type="hidden" name="dir" value="down"/>
                  <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-label="Move {{ $idea->text }} down" @disabled($i === $group['rows']->count() - 1)>&darr;</button></form>
                <form method="POST" action="{{ route('admin.quick-ideas.toggle', $idea->id) }}">@csrf
                  <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-pressed="{{ $idea->is_active ? 'true' : 'false' }}">{{ $idea->is_active ? 'On' : 'Off' }}</button></form>
                <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-lp-modal-open="qi-del-{{ $idea->id }}" aria-label="Delete {{ $idea->text }}">Delete</button>
              </div>
            </div>
          @empty
            <p class="qi-empty">No ideas yet. Customers see nothing here until you add one.</p>
          @endforelse

          <form method="POST" action="{{ route('admin.quick-ideas.store') }}" class="qi-add">
            @csrf
            <input type="hidden" name="scope" value="{{ $scope }}"/>
            <input type="hidden" name="idea_key" value="{{ $group['key'] }}"/>
            <label class="cz-sr" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)" for="qi-new-{{ $scope }}-{{ \Illuminate\Support\Str::slug($group['key'] ?: 'general') }}">New idea for {{ $group['label'] }}</label>
            <input type="text" id="qi-new-{{ $scope }}-{{ \Illuminate\Support\Str::slug($group['key'] ?: 'general') }}" name="text" class="qi-input" maxlength="{{ $max }}" placeholder="Add an idea, e.g. glass top" required/>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--chip">Add</button>
          </form>
        </section>
      @endforeach
    </div>
  @endforeach

  <h2 class="qi-section">&ldquo;Something else&rdquo; quick picks</h2>
  <p class="ir-lead qi-lead">Chips inside the customer's &ldquo;Something else&rdquo; box. Tapping one fills the text box, so the request is still saved as &ldquo;Something else&rdquo;. The page shows the first {{ $chipsShown }} that are on; keep the list short. Furniture types customers ask for often can be added from Ideas to review.</p>
  <div class="qi-grid">
    <section class="qi-card" aria-label="Something else quick picks">
      @forelse($extraTypes as $i => $type)
        <div class="qi-row {{ $type->is_active ? '' : 'is-off' }}">
          <form method="POST" action="{{ route('admin.extra-types.update', $type->id) }}" class="qi-edit">
            @csrf
            <label style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)" for="et-{{ $type->id }}">Furniture name</label>
            <input type="text" id="et-{{ $type->id }}" name="name" value="{{ $type->name }}" maxlength="{{ $typeMax }}" class="qi-input" required/>
            <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip">Save</button>
          </form>
          <div class="qi-tools">
            <form method="POST" action="{{ route('admin.extra-types.move', $type->id) }}">@csrf<input type="hidden" name="dir" value="up"/>
              <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-label="Move {{ $type->name }} up" @disabled($i === 0)>&uarr;</button></form>
            <form method="POST" action="{{ route('admin.extra-types.move', $type->id) }}">@csrf<input type="hidden" name="dir" value="down"/>
              <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-label="Move {{ $type->name }} down" @disabled($i === $extraTypes->count() - 1)>&darr;</button></form>
            <form method="POST" action="{{ route('admin.extra-types.toggle', $type->id) }}">@csrf
              <button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip" aria-pressed="{{ $type->is_active ? 'true' : 'false' }}">{{ $type->is_active ? 'On' : 'Off' }}</button></form>
            <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-lp-modal-open="et-del-{{ $type->id }}" aria-label="Delete {{ $type->name }}">Delete</button>
          </div>
        </div>
      @empty
        <p class="qi-empty">No quick picks. The &ldquo;Something else&rdquo; box shows no chips.</p>
      @endforelse
      <form method="POST" action="{{ route('admin.extra-types.store') }}" class="qi-add">
        @csrf
        <label style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)" for="et-new">New furniture name</label>
        <input type="text" id="et-new" name="name" class="qi-input" maxlength="{{ $typeMax }}" placeholder="Add a furniture name, e.g. Pergola" required/>
        <button type="submit" class="lp-btn lp-btn--primary lp-btn--chip">Add</button>
      </form>
    </section>
  </div>

  @foreach($groups as $list)
    @foreach($list as $group)
      @foreach($group['rows'] as $idea)
        <x-modal id="qi-del-{{ $idea->id }}" title="Delete this idea?">
          <p>&ldquo;{{ $idea->text }}&rdquo; is removed for good. To keep it but hide it from customers, turn it Off instead.</p>
          <form method="POST" action="{{ route('admin.quick-ideas.delete', $idea->id) }}" id="qi-del-form-{{ $idea->id }}">@csrf</form>
          <x-slot:footer>
            <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
            <button type="submit" form="qi-del-form-{{ $idea->id }}" class="lp-btn lp-btn--danger" data-lp-confirm>Delete</button>
          </x-slot:footer>
        </x-modal>
      @endforeach
    @endforeach
  @endforeach
  @foreach($extraTypes as $type)
    <x-modal id="et-del-{{ $type->id }}" title="Delete this quick pick?">
      <p>&ldquo;{{ $type->name }}&rdquo; is removed from the &ldquo;Something else&rdquo; box. Requests already made with it are not changed.</p>
      <form method="POST" action="{{ route('admin.extra-types.delete', $type->id) }}" id="et-del-form-{{ $type->id }}">@csrf</form>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
        <button type="submit" form="et-del-form-{{ $type->id }}" class="lp-btn lp-btn--danger" data-lp-confirm>Delete</button>
      </x-slot:footer>
    </x-modal>
  @endforeach
</div>
@endsection
