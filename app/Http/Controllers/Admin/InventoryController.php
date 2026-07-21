<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'all');

        $materials = Inventory::query()
            ->when($status !== 'all', fn($q) => $q->where('inventory_status', $status))
            ->orderBy('material_name')
            ->paginate(15);

        $stats = [
            'total'        => Inventory::count(),
            'low_stock'    => Inventory::where('inventory_status', 'low_stock')->count(),
            'out_of_stock' => Inventory::where('inventory_status', 'out_of_stock')->count(),
        ];

        return view('admin.inventory.index', compact('materials', 'status', 'stats'));
    }

    public function create()
    {
        return view('admin.inventory.form', ['material' => new Inventory()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateMaterial($request);
        $validated['inventory_status'] = $this->computeStatus($validated['material_quantity'], $validated['low_stock_threshold']);

        Inventory::create($validated);

        return redirect()->route('admin.inventory.index')->with('success', 'Material added.');
    }

    public function edit($id)
    {
        $material = Inventory::findOrFail($id);
        return view('admin.inventory.form', compact('material'));
    }

    public function update(Request $request, $id)
    {
        $material = Inventory::findOrFail($id);
        $validated = $this->validateMaterial($request);
        $validated['inventory_status'] = $this->computeStatus($validated['material_quantity'], $validated['low_stock_threshold']);

        $material->update($validated);

        return redirect()->route('admin.inventory.index')->with('success', 'Material updated.');
    }

    public function destroy($id)
    {
        Inventory::findOrFail($id)->delete();
        return back()->with('success', 'Material removed.');
    }

    /* Quick quantity adjustment (+/- buttons) */
    public function adjustQuantity(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|numeric|min:0']);

        $material = Inventory::findOrFail($id);
        $material->update([
            'material_quantity' => $request->quantity,
            'inventory_status'  => $this->computeStatus($request->quantity, $material->low_stock_threshold),
        ]);

        return back()->with('success', 'Quantity updated.');
    }

    private function computeStatus($quantity, $threshold): string
    {
        if ($quantity <= 0) return 'out_of_stock';
        if ($quantity <= $threshold) return 'low_stock';
        return 'in_stock';
    }

    private function validateMaterial(Request $request): array
    {
        return $request->validate([
            'material_name'       => 'required|string|max:255',
            'material_quantity'   => 'required|numeric|min:0',
            'unit'                => 'required|string|max:50',
            'low_stock_threshold' => 'required|numeric|min:0',
        ]);
    }
}