@extends('layouts.admin')

@section('title', 'Employees — Admin')
@section('page-title', 'Employees')

@section('content')

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['total'] }}</div><div class="admin-stat-label">Total Employees</div></div>
  </div>
  <div class="admin-stat-card accent-green">
    <div class="admin-stat-icon green">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['available'] }}</div><div class="admin-stat-label">Available</div></div>
  </div>
  <div class="admin-stat-card accent-blue">
    <div class="admin-stat-icon blue">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['assigned'] }}</div><div class="admin-stat-label">Currently Assigned</div></div>
  </div>
  <div class="admin-stat-card accent-purple">
    <div class="admin-stat-icon purple">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['construction'] }} / {{ $stats['barnis'] }}</div><div class="admin-stat-label">Construction / Barnis</div></div>
  </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
  <div class="admin-filter-tabs" style="margin-bottom:0;">
    <a href="{{ route('admin.employees.index', ['role'=>'all','status'=>$status]) }}" class="admin-filter-tab {{ $role==='all'?'active':'' }}">All Roles</a>
    <a href="{{ route('admin.employees.index', ['role'=>'construction','status'=>$status]) }}" class="admin-filter-tab {{ $role==='construction'?'active':'' }}">Construction</a>
    <a href="{{ route('admin.employees.index', ['role'=>'barnis','status'=>$status]) }}" class="admin-filter-tab {{ $role==='barnis'?'active':'' }}">Barnis</a>
  </div>

  <a href="{{ route('admin.employees.create') }}" class="admin-btn admin-btn-gold">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Employee
  </a>
</div>

@if(session('success'))
  <div class="admin-toast admin-toast-success" style="margin:0 0 20px;">{{ session('success') }}</div>
@endif

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($employees->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <p>No employees found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Role</th><th>Type</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($employees as $employee)
            <tr>
              <td data-label="Name"><div style="font-weight:600;color:#2A1810;">{{ $employee->full_name }}</div></td>
              <td data-label="Role" style="text-transform:capitalize;">{{ $employee->role }}</td>
              <td data-label="Type" style="text-transform:capitalize;">{{ $employee->type }}</td>
              <td data-label="Phone">{{ $employee->phone ?? '—' }}</td>
              <td data-label="Status">
                <form method="POST" action="{{ route('admin.employees.status', $employee->id) }}" class="admin-quick-status-form">
                  @csrf
                  <select name="status" class="admin-quick-status-select admin-pill {{ $employee->status }}" onchange="this.form.submit()">
                    <option value="available" {{ $employee->status === 'available' ? 'selected' : '' }}>Available</option>
                    <option value="assigned" {{ $employee->status === 'assigned' ? 'selected' : '' }}>Assigned</option>
                    <option value="oncall" {{ $employee->status === 'oncall' ? 'selected' : '' }}>On-call</option>
                    <option value="off" {{ $employee->status === 'off' ? 'selected' : '' }}>Off</option>
                  </select>
                </form>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="{{ route('admin.employees.edit', $employee->id) }}" class="admin-btn admin-btn-outline">Edit</a>
                  <form method="POST" action="{{ route('admin.employees.destroy', $employee->id) }}"
                        onsubmit="return confirm('Remove this employee record?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="admin-btn admin-btn-danger">Remove</button>
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

<div style="margin-top:16px;">{{ $employees->appends(request()->query())->links() }}</div>

@endsection