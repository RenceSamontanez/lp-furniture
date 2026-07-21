<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    /* ════════════════════════════════
       LIST EMPLOYEES
    ════════════════════════════════ */
    public function index(Request $request)
    {
        $role   = $request->get('role', 'all');
        $status = $request->get('status', 'all');

        $employees = Employee::query()
            ->when($role !== 'all', fn($q) => $q->where('role', $role))
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderBy('full_name')
            ->paginate(15);

        $stats = [
            'total'      => Employee::count(),
            'available'  => Employee::where('status', 'available')->count(),
            'assigned'   => Employee::where('status', 'assigned')->count(),
            'construction' => Employee::where('role', 'construction')->count(),
            'barnis'     => Employee::where('role', 'barnis')->count(),
        ];

        return view('admin.employees.index', compact('employees', 'role', 'status', 'stats'));
    }

    /* ════════════════════════════════
       SHOW CREATE FORM
    ════════════════════════════════ */
    public function create()
    {
        return view('admin.employees.form', ['employee' => new Employee()]);
    }

    /* ════════════════════════════════
       STORE NEW EMPLOYEE
    ════════════════════════════════ */
    public function store(Request $request)
    {
        $validated = $this->validateEmployee($request);

        Employee::create($validated);

        return redirect()->route('admin.employees.index')
            ->with('success', 'Employee added successfully.');
    }

    /* ════════════════════════════════
       SHOW EDIT FORM
    ════════════════════════════════ */
    public function edit($id)
    {
        $employee = Employee::findOrFail($id);
        return view('admin.employees.form', compact('employee'));
    }

    /* ════════════════════════════════
       UPDATE EMPLOYEE
    ════════════════════════════════ */
    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $validated = $this->validateEmployee($request);

        $employee->update($validated);

        return redirect()->route('admin.employees.index')
            ->with('success', 'Employee updated successfully.');
    }

    /* ════════════════════════════════
       QUICK STATUS UPDATE
    ════════════════════════════════ */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:available,assigned,oncall,off',
        ]);

        $employee = Employee::findOrFail($id);
        $employee->update(['status' => $request->status]);

        return back()->with('success', 'Status updated.');
    }

    /* ════════════════════════════════
       DELETE EMPLOYEE
    ════════════════════════════════ */
    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);
        $employee->delete();

        return back()->with('success', 'Employee removed.');
    }

    /* ════════════════════════════════
       VALIDATION RULES
    ════════════════════════════════ */
    private function validateEmployee(Request $request): array
    {
        return $request->validate([
            'full_name' => 'required|string|max:150',
            'role'      => 'required|in:construction,barnis',
            'type'      => 'required|in:oncall,daily',
            'phone'     => 'nullable|string|max:20',
            'status'    => 'required|in:available,assigned,oncall,off',
            'notes'     => 'nullable|string|max:1000',
        ]);
    }
}