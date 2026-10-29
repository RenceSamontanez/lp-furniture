@extends('layouts.admin')

@section('title', 'Ideas to Review — Admin')
@section('page-title', 'Ideas to Review')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-ideas.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-ideas-review.css') }}"/>
@endpush

@php
  $statusLabels = ['new' => 'Needs Review', 'answered' => 'Answered', 'added_as_option' => 'Added as Option', 'dismissed' => 'Dismissed'];
  $tabs = $statusLabels + ['all' => 'All'];
  $kindLabels = ['furniture_type' => 'Furniture Type', 'wood' => 'Wood', 'idea' => 'Idea Phrase'];
  $routes = [
    'answer'  => route('admin.ideas.answer', ['id' => '__ID__']),
    'option'  => route('admin.ideas.option', ['id' => '__ID__']),
    'dismiss' => route('admin.ideas.dismiss', ['id' => '__ID__']),
    'undo'    => route('admin.ideas.undo', ['id' => '__ID__']),
    'delete'  => route('admin.ideas.delete', ['id' => '__ID__']),
  ];
  // what customers can already pick, for the live preview in Add as Option
  $existingIdeas = \App\Models\DesignIdea::where('is_active', true)->orderBy('sort_order')->get(['scope', 'idea_key', 'text'])
      ->groupBy(fn ($d) => $d->scope . ':' . $d->idea_key)->map(fn ($g) => $g->pluck('text')->values())->all();
  $pageData = [
    'rows' => $rows, 'routes' => $routes, 'typeChoices' => $typeChoices, 'styles' => $styles,
    'extraTypes' => array_values($extraTypeNames), 'existingIdeas' => $existingIdeas,
    'initial' => ['tab' => $tab, 'kind' => $kind, 'sort' => $sort, 'q' => $search],
    'reopen' => $reopen,
    'nameMax' => ['furniture_type' => \App\Models\ExtraFurnitureType::NAME_MAX, 'idea' => \App\Support\IdeaText::IDEA_MAX],
    'statusLabels' => $statusLabels, 'answerMax' => \App\Models\UnansweredIdea::ANSWER_MAX,
  ];
@endphp

@section('content')
<div class="co-page ir-page ir2">

  <p class="ir2-lead">When a customer types something on the Customize page that it doesn't recognize, like a wood name or a style, it lands here. Tell the system what it means, and it will know next time.</p>
  <p class="ir2-help-row"><button type="button" class="lp-btn lp-btn--secondary ir2-small" data-lp-modal-open="ir-help">Need Help? See An Example</button></p>

  @if($undo && $undo->status === 'dismissed')
    <x-notice variant="info" title="Dismissed “{{ $undo->text }}”">
      It no longer counts as new.
      <x-slot:actions>
        <form method="POST" action="{{ route('admin.ideas.undo', $undo->id) }}">@csrf<button type="submit" class="lp-btn lp-btn--secondary">Undo</button></form>
      </x-slot:actions>
    </x-notice>
  @endif

  @if($errors->any() && !$reopen)
    <x-notice variant="problem" title="That did not save">
      @foreach($errors->all() as $message){{ $message }} @endforeach
    </x-notice>
  @endif

  <div class="ir2-how" aria-label="How this page works">
    <div><span class="ir2-n">1</span><h3>A Customer Types Something New</h3><p>The Customize page didn't have an answer for it, so it was saved here.</p></div>
    <div><span class="ir2-n">2</span><h3>You Review It</h3><p>Check what it is and how many customers typed it. The more times, the more it matters.</p></div>
    <div><span class="ir2-n">3</span><h3>You Choose What To Do</h3><p><strong>View</strong> opens it so you can <strong>Send a Reply</strong>, which shows next time. <strong>Add as Option</strong> makes it a choice customers can pick. <strong>Dismiss</strong> ignores it.</p></div>
  </div>

  <div class="ir2-tabs" role="tablist" aria-label="Ideas by status">
    @foreach($tabs as $key => $label)
      <button type="button" class="ir2-tab {{ $tab === $key ? 'is-on' : '' }}" role="tab" data-tab="{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}<span class="ir2-count">{{ $key === 'all' ? $total : ($counts[$key] ?? 0) }}</span></button>
    @endforeach
  </div>

  <div class="ir2-tools">
    <input type="search" id="ir-q" class="form-input" value="{{ $search }}" placeholder="Search the idea or the answer" aria-label="Search the idea or the answer" maxlength="100" autocomplete="off"/>
    <select id="ir-kind" class="form-input" aria-label="Type">
      <option value="">All Types</option>
      @foreach($kindLabels as $key => $label)<option value="{{ $key }}" @selected($kind === $key)>{{ $label }}</option>@endforeach
    </select>
    <select id="ir-sort" class="form-input" aria-label="Sort">
      <option value="most" @selected($sort === 'most')>Most Typed First</option>
      <option value="recent" @selected($sort === 'newest')>Most Recent First</option>
    </select>
  </div>

  <div class="admin-panel ir2-panel">
    <table class="ir2-table" id="ir-table">
      <thead><tr><th>Idea</th><th>Type</th><th>Times Typed</th><th>Last Typed</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody id="ir-rows">
      @foreach($rows as $row)
        <tr class="ir2-row" data-id="{{ $row['id'] }}" data-status="{{ $row['status'] }}" data-kind="{{ $row['kind'] }}" data-times="{{ $row['times'] }}" data-last="{{ $row['lastTs'] }}"
            data-search="{{ mb_strtolower($row['text'] . ' ' . ($row['answer'] ?? '')) }}">
          <td class="ir2-idea">
            <span class="ir2-topic" title="{{ $row['text'] }}">{{ $row['text'] }}</span>
            @if($row['askedFor'] !== '')<div class="ir2-sub">Asked for: {{ $row['askedFor'] }}</div>@endif
          </td>
          <td data-label="Type"><span class="ir2-chip">{{ $row['kindLabel'] }}</span></td>
          <td data-label="Times Typed">{{ $row['times'] }} {{ $row['times'] === 1 ? 'time' : 'times' }}</td>
          <td data-label="Last Typed">{{ $row['last'] }}</td>
          <td data-label="Status"><span class="ir2-chip ir2-st-{{ $row['status'] }}">{{ $statusLabels[$row['status']] ?? $row['status'] }}</span></td>
          <td class="ir2-actions-cell">
            <div class="ir2-actions">
              <button type="button" class="lp-btn lp-btn--primary ir2-small" data-view="{{ $row['id'] }}">View</button>
              <button type="button" class="lp-btn lp-btn--secondary ir2-small ir2-more" data-more="{{ $row['id'] }}" aria-haspopup="menu" aria-expanded="false" aria-label="More actions for {{ $row['text'] }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>More
              </button>
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <div class="ir2-empty" id="ir-empty" @if($rows->isNotEmpty()) hidden @endif>
      <strong id="ir-empty-title">{{ $total === 0 ? 'Nothing here yet' : 'No ideas match' }}</strong>
      <span id="ir-empty-text">{{ $total === 0 ? 'No customer has typed anything we had no answer for yet. New ones show up here by themselves.' : 'Try a different tab, type or search.' }}</span>
    </div>
  </div>

  @if($total > $rows->count())
    <p class="ir2-cap">Showing the {{ $rows->count() }} most typed of {{ $total }} ideas. Search covers only what is shown.</p>
  @endif
</div>

{{-- The More menu: one floating menu for every row --}}
<div class="ir2-menu" id="ir-menu" role="menu" hidden></div>
<form method="POST" id="ir-act-form" hidden>@csrf</form>
<script type="application/json" id="ir-data">@json($pageData)</script>

{{-- Modals: the shared modal, mounted on the body when opened. One of each; the script fills them for the chosen idea. --}}
<x-modal id="ir-view" title="Customer Idea" size="wide" data-guard>
  <div id="ir-view-top"></div>
  <hr class="ir2-rule">
  <form method="POST" id="ir-reply-form" class="ir2-reply" data-modal-form novalidate>
    @csrf
    <input type="hidden" name="_modal" value="reply"><input type="hidden" name="_idea" id="ir-reply-id" value="">
    <h3 class="ir2-h">Your Reply</h3>
    <div class="ir2-label" id="ir-can-label">1. Can We Make This?</div>
    <div class="ir2-opts" role="radiogroup" aria-labelledby="ir-can-label" id="ir-can">
      <button type="button" class="ir2-opt" role="radio" aria-checked="false" data-o="yes"><b>Yes, We Can Make It</b><small>We offer this as it is.</small></button>
      <button type="button" class="ir2-opt" role="radio" aria-checked="false" data-o="more"><b>Yes, With A Catch</b><small>It costs more or takes longer.</small></button>
      <button type="button" class="ir2-opt" role="radio" aria-checked="false" data-o="no"><b>No, We Don't Offer It</b><small>Point them to other choices.</small></button>
    </div>
    <div class="am-field ir2-field" id="ir-reply-field">
      <label class="ir2-label" for="ir-answer">2. Your Reply <span class="ir2-soft">(a starter is filled in. Edit it to fit.)</span></label>
      <textarea id="ir-answer" name="answer" class="form-input" rows="5" maxlength="{{ \App\Models\UnansweredIdea::ANSWER_MAX }}" data-required placeholder="Pick an option above to fill in a starter reply, or write your own."></textarea>
      <p class="am-err" data-client="Write a reply before saving." id="ir-answer-err" role="alert"></p>
      <div class="ir2-soft ir2-addline">Want more detail? Click to add a sentence:</div>
      <div class="ir2-chips" id="ir-chips"></div>
      <ul class="ir2-tips">
        <li>Write it as if you are talking to the customer.</li>
        <li>Fill in the <strong>[brackets]</strong> with your own words.</li>
        <li>Don't promise a price here. Say we will confirm it.</li>
      </ul>
    </div>
    <div class="ir2-label">What The Customer Will See</div>
    <div class="ir2-bubble" id="ir-preview" aria-live="polite"></div>
  </form>
</x-modal>

<x-modal id="ir-option" title="Add as an Option" size="mid" data-guard>
  <form method="POST" id="ir-option-form" data-modal-form novalidate>
    @csrf
    <input type="hidden" name="_modal" value="option"><input type="hidden" name="_idea" id="ir-option-id" value="">
    <div class="ir2-ctx" id="ir-option-ctx"></div>
    <div class="am-field ir2-field" id="ir-option-list-wrap">
      <span class="ir2-label" id="ir-option-list-label">Which Choice List?</span>
      <div id="ir-option-list-fixed" class="ir2-fixed"></div>
      <div id="ir-option-list-pick" class="ir2-pick" hidden>
        <select name="scope" id="ir-opt-scope" class="form-input" aria-labelledby="ir-option-list-label">
          <option value="type">A Furniture Type</option>
          <option value="style">A Style</option>
        </select>
        <select name="key" id="ir-opt-key" class="form-input" aria-label="Which one"></select>
      </div>
      <p class="am-err" id="ir-opt-key-err" role="alert"></p>
    </div>
    <div class="am-field ir2-field" id="ir-opt-name-field">
      <label class="ir2-label" for="ir-opt-name">Name Customers Will See</label>
      <input type="text" name="wording" id="ir-opt-name" class="form-input" data-required autocomplete="off">
      <p class="am-err" data-client="Enter a name for this option." id="ir-opt-name-err" role="alert"></p>
    </div>
    <div class="ir2-label">How It Will Look On The Customize Page</div>
    <div class="ir2-pills" id="ir-opt-pills"></div>
    <p class="am-help">Customers can click it like any other choice, so they no longer have to type it.</p>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="ir-option-form" class="lp-btn lp-btn--primary ir2-primary" data-lp-confirm>Add as Option</button>
  </x-slot:footer>
</x-modal>

<x-modal id="ir-delete" title="Delete This Idea?" size="mid">
  <p class="ir2-p">This removes <strong id="ir-del-name"></strong> from the list for good. If a customer types it again, it will come back as new.</p>
  <form method="POST" id="ir-delete-form">@csrf</form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Keep Idea</button>
    <button type="submit" form="ir-delete-form" class="lp-btn lp-btn--danger ir2-primary" data-lp-confirm>Delete Idea</button>
  </x-slot:footer>
</x-modal>

<x-modal id="ir-help" title="How To Handle An Idea" size="wide">
  <div class="ir2-sec"><h3 class="ir2-h">An Example</h3><p class="ir2-p">A customer is designing a <strong>King Bed</strong>. Under Wood they choose <strong>Other Wood</strong> and type <strong>Puno ng Saging</strong>. We have no such choice, so it shows up here as an idea.</p></div>
  <div class="ir2-sec"><h3 class="ir2-h">Step By Step</h3><ol class="ir2-ol"><li>Click View to read the full idea, see where it came from and which requests asked for it.</li><li>Ask yourself: can we really get and build with this material? Does it change the price or the time?</li><li>Choose what to do, using the guide below.</li></ol></div>
  <div class="ir2-sec"><h3 class="ir2-h">Which Button Should I Use?</h3>
    <div class="ir2-guide">
      <div><b>Send Reply</b><span>We can make it with conditions, or only sometimes. Explain it to the customer, for example "Yes, we can make a King Bed in this wood, but it takes longer."</span></div>
      <div><b>Add as Option</b><span>We can make it often. It becomes a button on the Customize page, so customers don't have to type it. (A wood cannot be added: the wood list is fixed.)</span></div>
      <div><b>Dismiss</b><span>It's a typo, a test word, or something we will never make.</span></div>
    </div>
  </div>
  <div class="ir2-sec"><h3 class="ir2-h">Not Sure?</h3><p class="ir2-p">Check with the workshop first, then come back. An idea can stay in Needs Review as long as you need.</p></div>
  <x-slot:footer><button type="button" class="lp-btn lp-btn--primary ir2-primary" data-lp-modal-close>Got It</button></x-slot:footer>
</x-modal>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/admin-modal-forms.js') }}"></script>
<script src="{{ asset('assets/js/admin-ideas-review.js') }}"></script>
@endpush
