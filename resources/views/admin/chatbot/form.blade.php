@extends('layouts.admin')

@section('title', ($intent->exists ? 'Edit' : 'Add') . ' Chatbot Intent — Admin')
@section('page-title', ($intent->exists ? 'Edit' : 'Add') . ' Intent')

@section('content')

<a href="{{ route('admin.chatbot.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Chatbot Training</a>

@if($errors->any())
  <div class="admin-toast admin-toast-error" style="margin:0 0 20px;">
    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

<div class="admin-panel" style="max-width:640px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Intent Details</div></div>
  <div class="admin-panel-body">
    <form method="POST" action="{{ $intent->exists ? route('admin.chatbot.update', $intent->id) : route('admin.chatbot.store') }}">
      @csrf
      @if($intent->exists) @method('PUT') @endif

      <div style="margin-bottom:16px;">
        <label class="form-label">Keywords (comma-separated)</label>
        <textarea name="keywords" class="form-input" rows="2" required
                  placeholder="e.g. how to order, place an order, ordering process, paano mag order">{{ old('keywords', $intent->keywords) }}</textarea>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          The chatbot matches user messages against these keywords. Separate each phrase with a comma.
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <label class="form-label">Response</label>
        <textarea name="response" class="form-input" rows="4" required
                  placeholder="The message the chatbot will reply with when matched">{{ old('response', $intent->response) }}</textarea>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          Use <code>@{{phone}}</code>, <code>@{{email}}</code> or <code>@{{facebook}}</code> to insert the current values from
          <a href="{{ route('admin.settings.contact.edit') }}" style="color:var(--gold);">Contact Settings</a> — don't type the number or address directly.
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <label class="form-label">Suggestion Chips (comma-separated) <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
        <input type="text" name="suggestions" class="form-input"
               placeholder="e.g. Browse catalog, Payment options, Track my order"
               value="{{ old('suggestions', $intent->suggestions) }}"/>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;align-items:end;">
        <div>
          <label class="form-label">Category <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
          <input type="text" name="category" class="form-input" maxlength="100"
                 placeholder="e.g. Ordering, Payment, Delivery"
                 value="{{ old('category', $intent->category) }}"/>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-bottom:11px;">
          <input type="checkbox" name="is_active" value="1" style="width:16px;height:16px;accent-color:var(--gold);"
                 {{ old('is_active', $intent->is_active ?? true) ? 'checked' : '' }}/>
          <span style="font-size:13.5px;color:#3D3129;">Active (chatbot will use this)</span>
        </label>
      </div>

      <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:14px;">
        {{ $intent->exists ? 'Update Intent' : 'Add Intent' }}
      </button>
    </form>
  </div>
</div>

@endsection