@extends('layouts.admin')

@section('title', 'Chatbot Training — Admin')
@section('page-title', 'Chatbot Training')

@section('content')

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
  <form method="GET" action="{{ route('admin.chatbot.index') }}" style="display:flex;gap:8px;flex:1;min-width:240px;">
    <input type="text" name="search" class="form-input" placeholder="Search keywords or response..." value="{{ $search }}" style="max-width:320px;"/>
    <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  </form>

  <a href="{{ route('admin.chatbot.create') }}" class="admin-btn admin-btn-gold">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Intent
  </a>
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
        <thead><tr><th>Keywords</th><th>Response Preview</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($intents as $intent)
            <tr>
              <td data-label="Keywords" style="max-width:220px;">
                <span style="display:block;white-space:normal;font-size:12.5px;color:#3D3129;">{{ Str::limit($intent->keywords, 80) }}</span>
              </td>
              <td data-label="Response" style="max-width:280px;">
                <span style="display:block;white-space:normal;font-size:12.5px;color:#6B5D52;line-height:1.4;">{{ Str::limit($intent->response, 90) }}</span>
              </td>
              <td data-label="Category">{{ $intent->category ?? '—' }}</td>
              <td data-label="Status">
                <form method="POST" action="{{ route('admin.chatbot.toggle', $intent->id) }}">
                  @csrf
                  <button type="submit" class="admin-pill {{ $intent->is_active ? 'approved' : 'rejected' }}" style="border:none;cursor:pointer;">
                    {{ $intent->is_active ? 'Active' : 'Inactive' }}
                  </button>
                </form>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="{{ route('admin.chatbot.edit', $intent->id) }}" class="admin-btn admin-btn-outline">Edit</a>
                  <form method="POST" action="{{ route('admin.chatbot.destroy', $intent->id) }}" onsubmit="return confirm('Delete this intent?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="admin-btn admin-btn-danger">Delete</button>
                  </form>
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

@endsection