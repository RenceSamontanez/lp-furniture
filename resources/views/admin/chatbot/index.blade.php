@extends('layouts.admin')

@section('title', 'Chatbot Training — Admin')
@section('page-title', 'Chatbot Training')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
@endpush

@section('content')

<p class="page-lead">Teach Luma what to answer. Each intent matches customer messages by keywords and replies with the response you write.</p>

<div class="admin-filter-tabs" style="margin-bottom:16px;">
  <a href="{{ route('admin.chatbot.index') }}" class="admin-filter-tab {{ $tab === 'intents' ? 'active' : '' }}">Intents</a>
  <a href="{{ route('admin.chatbot.index', ['tab' => 'unanswered']) }}" class="admin-filter-tab {{ $tab === 'unanswered' ? 'active' : '' }}">Unanswered Questions ({{ $unanswered->count() }})</a>
</div>

@if($tab === 'unanswered')

<div class="admin-panel" style="margin-bottom:16px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Questions Luma Could Not Answer</div></div>
  <div class="admin-panel-body">
    <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;">Add an intent with these words as keywords to teach her. A question leaves this list when an intent with a matching keyword is added. Dismiss one to hide it.</p>
    @if($unanswered->isNotEmpty())
    <ul style="margin:0;padding:0;list-style:none;font-size:13px;color:#3D3129;line-height:1.5;">
      @foreach($unanswered as $row)
        <li style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 0;border-top:1px solid rgba(0,0,0,.06);">
          <span style="min-width:0;overflow-wrap:anywhere;">
            {{ $row->question }}
            <span style="color:var(--text-muted);display:block;font-size:12px;">Asked {{ $row->times_asked }} {{ $row->times_asked === 1 ? 'time' : 'times' }} &middot; last asked {{ $row->last_asked_at?->timezone(config('luma.timezone', 'Asia/Manila'))->format('M j, Y g:i A') }}</span>
          </span>
          <form method="POST" action="{{ route('admin.chatbot.unanswered.dismiss', $row->id) }}" style="flex:none;">
            @csrf
            <button type="submit" class="admin-btn admin-btn-outline" aria-label="Dismiss this question: {{ $row->question }}">Dismiss</button>
          </form>
        </li>
      @endforeach
    </ul>
    @else
    <p style="font-size:13px;color:var(--text-muted);margin:0;">No unanswered questions right now.</p>
    @endif
  </div>
</div>

@else

<div class="cb-tools">
  <form method="GET" action="{{ route('admin.chatbot.index') }}">
    <input type="text" name="search" class="form-input" placeholder="Search Intents" aria-label="Search intents" value="{{ $search }}"/>
    <select name="category" class="form-input" aria-label="Category" onchange="this.form.submit()">
      <option value="">All Categories</option>
      @foreach($categories as $c)<option value="{{ $c }}" @selected($category === $c)>{{ $c }}</option>@endforeach
    </select>
    <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  </form>
  <button type="button" class="admin-btn admin-btn-gold" data-lp-modal-open="intent-add">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Intent
  </button>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($intents->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <p>No chatbot intents found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Category</th><th>Keywords</th><th>Response Preview</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($intents as $intent)
            <tr>
              <td data-label="Category">{{ $intent->category ?? '—' }}</td>
              <td data-label="Keywords" style="max-width:240px;"><span class="cb-kw">{{ Str::limit($intent->keywords, 80) }}</span></td>
              <td data-label="Response"><span class="cb-rs">{{ Str::limit($intent->response, 140) }}</span></td>
              <td data-label="Status">
                <form method="POST" action="{{ route('admin.chatbot.toggle', $intent->id) }}">
                  @csrf
                  <button type="submit" class="admin-pill {{ $intent->is_active ? 'approved' : 'rejected' }}" style="border:none;cursor:pointer;" title="{{ $intent->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                    {{ $intent->is_active ? 'Active' : 'Inactive' }}
                  </button>
                </form>
              </td>
              <td data-label="Actions">
                <div class="cb-actions">
                  <button type="button" class="admin-btn admin-btn-outline" data-lp-modal-open="intent-edit-{{ $intent->id }}">Edit</button>
                  <button type="button" class="admin-btn admin-btn-danger" data-lp-modal-open="intent-delete" data-set-action="intent-delete-form"
                          data-url="{{ route('admin.chatbot.destroy', $intent->id) }}" data-label="{{ Str::limit($intent->keywords, 60) }}">Delete</button>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $intents->appends(request()->query())->links() }}</div>

@include('admin.chatbot._modal', ['intent' => new \App\Models\ChatbotIntent(), 'modalId' => 'intent-add'])
@foreach($modalIntents as $intent)
  @include('admin.chatbot._modal', ['intent' => $intent, 'modalId' => 'intent-edit-' . $intent->id])
@endforeach

<x-modal id="intent-delete" title="Delete This Intent?">
  <p>Luma will stop answering these keywords: <strong data-fill="intent-delete-form"></strong>. You can add the intent again later.</p>
  <form method="POST" action="#" id="intent-delete-form">@csrf @method('DELETE')</form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="intent-delete-form" class="lp-btn lp-btn--danger" data-lp-confirm>Delete Intent</button>
  </x-slot:footer>
</x-modal>

@endif

@endsection
