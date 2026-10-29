@extends('layouts.admin')

@section('title', 'Ideas to review — Admin')
@section('page-title', 'Ideas to Review')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-ideas.css') }}"/>
@endpush

@php
  $statusLabels = \App\Models\UnansweredIdea::STATUS_LABELS;
  $kindLabels = \App\Models\UnansweredIdea::KIND_LABELS;
  $base = array_filter(['kind' => $kind, 'q' => $search, 'sort' => $sort === 'most' ? null : $sort]);
@endphp

@section('content')
<div class="co-page ir-page">

  <p class="ir-lead">Things customers typed on the Customize page that we had no answer for. Write an answer once and the page shows it the next time someone types the same thing.</p>

  @if($undo && $undo->status === 'dismissed')
    <x-notice variant="info" title="Dismissed “{{ $undo->text }}”">
      It no longer counts as new.
      <x-slot:actions>
        <form method="POST" action="{{ route('admin.ideas.undo', $undo->id) }}">@csrf<button type="submit" class="lp-btn lp-btn--secondary">Undo</button></form>
      </x-slot:actions>
    </x-notice>
  @endif

  @if($errors->any())
    <x-notice variant="problem" title="That did not save">
      @foreach($errors->all() as $message){{ $message }} @endforeach
    </x-notice>
  @endif

  <div class="co-counts">
    <a href="{{ route('admin.ideas.index', ['status' => 'all'] + $base) }}" class="co-count {{ $status === null ? 'active' : '' }}">
      <div class="co-count-num">{{ $total }}</div><div class="co-count-label">All</div>
    </a>
    @foreach($statusLabels as $key => $label)
      <a href="{{ route('admin.ideas.index', ['status' => $key] + $base) }}" class="co-count {{ $status === $key ? 'active' : '' }}">
        <div class="co-count-num">{{ $counts[$key] ?? 0 }}</div><div class="co-count-label">@titlecase($label)</div>
      </a>
    @endforeach
  </div>

  <form method="GET" action="{{ route('admin.ideas.index') }}" class="ir-filters">
    <input type="hidden" name="status" value="{{ $status ?? 'all' }}"/>
    <label class="ir-field"><span>Search</span>
      <input type="search" name="q" value="{{ $search }}" class="form-input" placeholder="Search the text or the answer" maxlength="100"/></label>
    <label class="ir-field"><span>Kind</span>
      <select name="kind" class="form-input">
        <option value="">All kinds</option>
        @foreach($kindLabels as $key => $label)<option value="{{ $key }}" @selected($kind === $key)>{{ $label }}</option>@endforeach
      </select></label>
    <label class="ir-field"><span>Sort</span>
      <select name="sort" class="form-input">
        <option value="most" @selected($sort === 'most')>Most asked first</option>
        <option value="newest" @selected($sort === 'newest')>Newest first</option>
      </select></label>
    <button type="submit" class="lp-btn lp-btn--primary">Apply</button>
    @if($search !== '' || $kind || $sort !== 'most')
      <a href="{{ route('admin.ideas.index', ['status' => $status ?? 'all']) }}" class="lp-btn lp-btn--secondary">Clear</a>
    @endif
  </form>

  <div class="admin-panel">
    <div class="admin-panel-body" style="padding:0;">
      @if($rows->isEmpty())
        <div class="admin-empty ir-empty">
          <p><strong>Nothing here.</strong></p>
          <p>
            @if($total === 0)
              No customer has typed anything we had no answer for yet. New ones show up here by themselves.
            @else
              No ideas match these filters.
            @endif
          </p>
        </div>
      @else
        <table class="admin-table ir-table">
          <thead><tr><th>Idea</th><th>Kind</th><th>Times Seen</th><th>First Seen</th><th>Last Seen</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          @foreach($rows as $row)
            @php $canOption = $row->kind !== 'wood' && $row->status !== 'dismissed'; @endphp
            <tr>
              <td data-label="Idea">
                <div class="ir-text">{{ $row->text }}</div>
                @if($row->answer)<div class="ir-answer"><span>Answer:</span> {{ $row->answer }}</div>@endif
              </td>
              <td data-label="Kind"><span class="ir-kind ir-kind--{{ $row->kind }}">{{ $kindLabels[$row->kind] ?? $row->kind }}</span></td>
              <td data-label="Times Seen"><strong>{{ $row->times_asked }}&times;</strong></td>
              <td data-label="First Seen">{{ $row->first_seen_at?->format('M j, Y') }}</td>
              <td data-label="Last Seen">{{ $row->last_seen_at?->format('M j, Y') }}</td>
              <td data-label="Status"><span class="ir-status ir-status--{{ $row->status }}">@titlecase($statusLabels[$row->status] ?? $row->status)</span></td>
              <td data-label="Actions">
                <div class="ir-actions">
                  @if($row->status !== 'dismissed')
                    <button type="button" class="lp-btn lp-btn--primary lp-btn--chip" data-lp-modal-open="ir-answer-{{ $row->id }}">{{ $row->answer ? 'Edit Answer' : 'Write Answer' }}</button>
                  @endif
                  @if($canOption)
                    @if($row->status !== 'added_as_option')
                      <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="ir-option-{{ $row->id }}">Add as Option</button>
                    @endif
                  @endif
                  @if($row->status === 'dismissed')
                    <form method="POST" action="{{ route('admin.ideas.undo', $row->id) }}">@csrf<button type="submit" class="lp-btn lp-btn--secondary lp-btn--chip">Undo Dismiss</button></form>
                  @else
                    <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="ir-dismiss-{{ $row->id }}">Dismiss</button>
                  @endif
                  <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-lp-modal-open="ir-delete-{{ $row->id }}">Delete</button>
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>

  <div style="margin-top:16px;">{{ $rows->links() }}</div>

  {{-- one modal set per row on this page --}}
  @foreach($rows as $row)
    <x-modal id="ir-answer-{{ $row->id }}" title="Answer for “{{ $row->text }}”">
      <form method="POST" action="{{ route('admin.ideas.answer', $row->id) }}" id="ir-answer-form-{{ $row->id }}" class="ir-form">
        @csrf
        <label for="ir-answer-text-{{ $row->id }}">What should the customer be told?</label>
        <textarea id="ir-answer-text-{{ $row->id }}" name="answer" class="form-input" rows="4" maxlength="{{ \App\Models\UnansweredIdea::ANSWER_MAX }}" required>{{ $row->answer }}</textarea>
        <p class="ir-hint">Plain text, up to {{ \App\Models\UnansweredIdea::ANSWER_MAX }} characters. No links or email addresses. It shows on the Customize page the next time a customer types this exact thing.</p>
      </form>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
        <button type="submit" form="ir-answer-form-{{ $row->id }}" class="lp-btn lp-btn--primary" data-lp-confirm>Save Answer</button>
      </x-slot:footer>
    </x-modal>

    @if($row->kind !== 'wood' && $row->status !== 'dismissed' && $row->status !== 'added_as_option')
      <x-modal id="ir-option-{{ $row->id }}" title="Add “{{ $row->text }}” as an Option">
        <form method="POST" action="{{ route('admin.ideas.option', $row->id) }}" id="ir-option-form-{{ $row->id }}" class="ir-form" data-ir-option>
          @csrf
          @if($row->kind === 'furniture_type')
            <label for="ir-w-{{ $row->id }}">Name to show as a quick pick under &ldquo;Something else&rdquo;</label>
            <input type="text" id="ir-w-{{ $row->id }}" name="wording" class="form-input" value="{{ $row->text }}" maxlength="{{ \App\Models\ExtraFurnitureType::NAME_MAX }}" required/>
            <p class="ir-hint">It appears as a chip in the &ldquo;Something else&rdquo; box. The customer's request is still saved as &ldquo;Something else&rdquo; with this text.</p>
          @else
            <label for="ir-s-{{ $row->id }}">Belongs To</label>
            <select id="ir-s-{{ $row->id }}" name="scope" class="form-input" data-ir-scope required>
              <option value="type">A furniture type</option>
              <option value="style">A style</option>
            </select>
            <label for="ir-k-{{ $row->id }}">Which One</label>
            <select id="ir-k-{{ $row->id }}" name="key" class="form-input" data-ir-key required
                    data-types='@json(array_keys($typeChoices))' data-styles='@json($styles)'>
              @foreach(array_keys($typeChoices) as $t)<option value="{{ $t }}">{{ $typeChoices[$t] }}</option>@endforeach
            </select>
            <label for="ir-w-{{ $row->id }}">Wording of the Quick Idea</label>
            <input type="text" id="ir-w-{{ $row->id }}" name="wording" class="form-input" value="{{ $row->text }}" maxlength="{{ \App\Support\IdeaText::IDEA_MAX }}" required/>
            <p class="ir-hint">Shown as a tap-to-add chip, so keep it short, like &ldquo;glass top&rdquo;.</p>
          @endif
        </form>
        <x-slot:footer>
          <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
          <button type="submit" form="ir-option-form-{{ $row->id }}" class="lp-btn lp-btn--primary" data-lp-confirm>Add Option</button>
        </x-slot:footer>
      </x-modal>
    @endif

    @if($row->status !== 'dismissed')
      <x-modal id="ir-dismiss-{{ $row->id }}" title="Dismiss “{{ $row->text }}”?">
        <p>It stops counting as new and customers see nothing for it. You can undo this.</p>
        <form method="POST" action="{{ route('admin.ideas.dismiss', $row->id) }}" id="ir-dismiss-form-{{ $row->id }}">@csrf</form>
        <x-slot:footer>
          <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go Back</button>
          <button type="submit" form="ir-dismiss-form-{{ $row->id }}" class="lp-btn lp-btn--primary" data-lp-confirm>Dismiss</button>
        </x-slot:footer>
      </x-modal>
    @endif

    <x-modal id="ir-delete-{{ $row->id }}" title="Delete “{{ $row->text }}”?">
      <p>This removes it and its count for good. If a customer types it again it starts over as new. This cannot be undone.</p>
      <form method="POST" action="{{ route('admin.ideas.delete', $row->id) }}" id="ir-delete-form-{{ $row->id }}">@csrf</form>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go Back</button>
        <button type="submit" form="ir-delete-form-{{ $row->id }}" class="lp-btn lp-btn--danger" data-lp-confirm>Delete for Good</button>
      </x-slot:footer>
    </x-modal>
  @endforeach
</div>
@endsection

@push('scripts')
<script>
// the "Which one" list follows "Belongs to"
document.querySelectorAll('[data-ir-option]').forEach(function (form) {
  var scope = form.querySelector('[data-ir-scope]'), key = form.querySelector('[data-ir-key]');
  if (!scope || !key) { return; }
  var types = JSON.parse(key.getAttribute('data-types')), styles = JSON.parse(key.getAttribute('data-styles'));
  var labels = {};
  [].forEach.call(key.options, function (o) { labels[o.value] = o.textContent; });
  scope.addEventListener('change', function () {
    var list = scope.value === 'style' ? styles : types;
    key.textContent = '';
    list.forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = labels[v] || v; key.appendChild(o); });
  });
});
</script>
@endpush
