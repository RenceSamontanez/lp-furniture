@extends('layouts.admin')

@section('title', ($employee->exists ? 'Edit' : 'Add') . ' Employee — Admin')
@section('page-title', ($employee->exists ? 'Edit' : 'Add') . ' Employee')

@section('content')

<a href="{{ route('admin.employees.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">
  Back to Employees
</a>

@if($errors->any())
  <div class="admin-toast admin-toast-error" style="margin:0 0 20px;">
    @foreach($errors->all() as $error)
      <div>{{ $error }}</div>
    @endforeach
  </div>
@endif

<div class="admin-panel" style="max-width:560px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Employee Details</div></div>
  <div class="admin-panel-body">

    <form method="POST"
          action="{{ $employee->exists ? route('admin.employees.update', $employee->id) : route('admin.employees.store') }}">
      @csrf
      @if($employee->exists) @method('PUT') @endif

      <div style="margin-bottom:16px;">
        <label class="form-label">Full Name</label>
        <input type="text" name="full_name" class="form-input" required maxlength="150"
               value="{{ old('full_name', $employee->full_name) }}"/>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
        <div>
          <label class="form-label">Role</label>
          <select name="role" class="form-input" required>
            <option value="construction" {{ old('role', $employee->role) === 'construction' ? 'selected' : '' }}>Construction</option>
            <option value="barnis" {{ old('role', $employee->role) === 'barnis' ? 'selected' : '' }}>Barnis (Varnishing)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Employment Type</label>
          <select name="type" class="form-input" required>
            <option value="daily" {{ old('type', $employee->type) === 'daily' ? 'selected' : '' }}>Daily</option>
            <option value="oncall" {{ old('type', $employee->type) === 'oncall' ? 'selected' : '' }}>On-call</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
        <div>
          <label class="form-label">Phone Number <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
          <input type="text" name="phone" class="form-input" maxlength="20"
                 placeholder="09171234567"
                 value="{{ old('phone', $employee->phone) }}"/>
        </div>
        <div>
          <label class="form-label">Current Status</label>
          <select name="status" class="form-input" required>
            <option value="available" {{ old('status', $employee->status ?? 'available') === 'available' ? 'selected' : '' }}>Available</option>
            <option value="assigned" {{ old('status', $employee->status) === 'assigned' ? 'selected' : '' }}>Assigned</option>
            <option value="oncall" {{ old('status', $employee->status) === 'oncall' ? 'selected' : '' }}>On-call</option>
            <option value="off" {{ old('status', $employee->status) === 'off' ? 'selected' : '' }}>Off</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label">Notes <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <textarea name="notes" class="form-input" rows="3" maxlength="1000"
                  placeholder="Specialization, availability notes, etc.">{{ old('notes', $employee->notes) }}</textarea>
      </div>

      <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:14px;">
        {{ $employee->exists ? 'Update Employee' : 'Add Employee' }}
      </button>
    </form>

  </div>
</div>

@endsection