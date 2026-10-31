<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\MaterialUsage;
use App\Support\MaterialStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public const UNITS = ['sqft', 'liters', 'pieces', 'kg'];

    public function index(Request $request)
    {
        $materials = Inventory::query()->orderBy('material_name')->get();

        $stats = [
            'total' => $materials->count(),
            'in'    => $materials->filter(fn ($m) => (float) $m->material_quantity > 0 && MaterialStock::statusFor($m->material_quantity, $m->low_stock_threshold) === MaterialStock::IN)->count(),
            'low'   => $materials->filter(fn ($m) => (float) $m->material_quantity > 0 && MaterialStock::statusFor($m->material_quantity, $m->low_stock_threshold) === MaterialStock::LOW)->count(),
            'short' => $materials->filter(fn ($m) => (float) $m->material_quantity <= 0)->count(),
        ];

        $used30 = MaterialStock::usedSince(30);

        // The old Edit URL lands here as ?edit=ID: that material's modal opens.
        $editId = (int) $request->get('edit');
        $openModal = old('_modal') ?: ($editId > 0 && $materials->contains('id', $editId) ? 'material-edit-' . $editId : ($request->boolean('add') ? 'material-add' : null));

        return view('admin.inventory.index', compact('materials', 'stats', 'used30', 'openModal'));
    }

    /** The Add page is now a modal on the list; the old URL still works and opens it. */
    public function create()
    {
        return redirect()->route('admin.inventory.index', ['add' => 1]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateMaterial($request);

        MaterialStock::create($validated, Auth::guard('admin')->user());

        return redirect()->route('admin.inventory.index')->with('success', 'Material added.');
    }

    /** The Edit page is now a modal on the list; the old URL still works and opens it. */
    public function edit($id)
    {
        Inventory::findOrFail($id);

        return redirect()->route('admin.inventory.index', ['edit' => (int) $id]);
    }

    /** Name, unit, Low Stock At; a different count is saved as a correction (a movement with the difference), never silently. */
    public function update(Request $request, $id)
    {
        $material = Inventory::findOrFail($id);
        $validated = $this->validateMaterial($request, true);

        // A material that is short shows its negative count in the box; keeping it is fine, typing a new negative count is not.
        if ((float) $validated['material_quantity'] < 0 && abs((float) $validated['material_quantity'] - (float) $material->material_quantity) >= 0.005) {
            throw \Illuminate\Validation\ValidationException::withMessages(['material_quantity' => 'A count cannot be below zero.']);
        }

        DB::transaction(function () use ($material, $validated) {
            $row = Inventory::lockForUpdate()->findOrFail($material->id);
            $row->update([
                'material_name' => $validated['material_name'], 'unit' => $validated['unit'],
                'low_stock_threshold' => $validated['low_stock_threshold'],
                'inventory_status' => MaterialStock::statusFor($row->material_quantity, $validated['low_stock_threshold']),
            ]);
            if (abs((float) $validated['material_quantity'] - (float) $row->material_quantity) >= 0.005) {
                MaterialStock::correct($row->id, (float) $validated['material_quantity'], Auth::guard('admin')->user());
            }
        });

        return redirect()->route('admin.inventory.index')->with('success', 'Material updated.');
    }

    /** Adds a delivered amount to the count and records a 'restocked' movement. */
    public function restock(Request $request, $id)
    {
        $material = Inventory::findOrFail($id);
        $data = $request->validate([
            'add_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'note'         => ['nullable', 'string', 'max:200'],
        ], ['add_quantity.gt' => 'Enter how much you are adding.', 'add_quantity.required' => 'Enter how much you are adding.']);

        $note = isset($data['note']) ? trim(\App\Support\TextSanitizer::line($data['note'])) : null;
        MaterialStock::change($material->id, round((float) $data['add_quantity'], 2), 'restocked', null, Auth::guard('admin')->user(), $note !== '' ? $note : null);

        return redirect()->route('admin.inventory.index')
            ->with('success', 'Restocked ' . MaterialStock::number($data['add_quantity']) . ' ' . $material->unit . ' of ' . $material->material_name . '.');
    }

    /** Past movements keep the material's name; its usage rows go with it (the foreign key would otherwise refuse the delete). */
    public function destroy($id)
    {
        $material = Inventory::findOrFail($id);
        DB::transaction(function () use ($material) {
            MaterialUsage::where('inventory_id', $material->id)->delete();
            $material->delete();
        });

        return back()->with('success', 'Material removed.');
    }

    private function validateMaterial(Request $request, bool $allowNegativeCount = false): array
    {
        return $request->validate([
            'material_name'       => 'required|string|max:255',
            'material_quantity'   => 'required|numeric|min:' . ($allowNegativeCount ? '-99999999' : '0') . '|max:99999999',
            'unit'                => 'required|string|max:50',
            'low_stock_threshold' => 'required|numeric|min:0|max:99999999',
        ]);
    }
}
