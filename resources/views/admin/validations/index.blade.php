@extends('layouts.admin')

@section('title', 'Identity Validations — Admin')
@section('page-title', 'Identity Validations')

@section('content')

<div class="admin-filter-tabs">
  <a href="{{ route('admin.validations.index', ['status'=>'pending']) }}" class="admin-filter-tab {{ $status==='pending'?'active':'' }}">Pending</a>
  <a href="{{ route('admin.validations.index', ['status'=>'approved']) }}" class="admin-filter-tab {{ $status==='approved'?'active':'' }}">Approved</a>
  <a href="{{ route('admin.validations.index', ['status'=>'rejected']) }}" class="admin-filter-tab {{ $status==='rejected'?'active':'' }}">Rejected</a>
  <a href="{{ route('admin.validations.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($validations->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 6L9 17l-5-5"/></svg>
        <p>No {{ $status !== 'all' ? $status : '' }} validations found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          @foreach($validations as $v)
            <tr>
              <td data-label="Customer">{{ $v->user->first_name }} {{ $v->user->last_name }}</td>
              <td data-label="Email">{{ $v->user->email }}</td>
              <td data-label="Phone">{{ $v->user->phone_number }}</td>
              <td data-label="Status"><span class="admin-pill {{ $v->status }}">{{ $v->status }}</span></td>
              <td data-label="Submitted">{{ $v->submitted_at ? $v->submitted_at->format('M d, Y') : '—' }}</td>
              <td data-label=""><a href="{{ route('admin.validations.show', $v->id) }}" class="admin-btn admin-btn-gold">Review</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $validations->links() }}</div>

@endsection