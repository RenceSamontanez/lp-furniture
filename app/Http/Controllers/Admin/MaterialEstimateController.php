<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaterialEstimate;
use App\Models\ProductMaterialEstimate;
use App\Support\MaterialLibrary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The furniture-type library. Editing a row changes the starter for NEW products and for custom orders; a product that already
 * has its own saved estimate keeps it. Everything is a modal on the list; the old create/edit URLs redirect and open it.
 */
class MaterialEstimateController extends Controller
{
    public function index(Request $request)
    {
        $types = MaterialEstimate::orderBy('sort_order')->orderBy('name')->get();
        $usage = ProductMaterialEstimate::query()->selectRaw('type_name, COUNT(*) as n')->groupBy('type_name')->pluck('n', 'type_name');

        $editId = (int) $request->get('edit');
        $openModal = old('_modal') ?: ($editId > 0 && $types->contains('id', $editId) ? 'estimate-edit-' . $editId : ($request->boolean('add') ? 'estimate-add' : null));

        return view('admin.material-estimates.index', compact('types', 'usage', 'openModal'));
    }

    public function create()
    {
        return redirect()->route('admin.material-estimates.index', ['add' => 1]);
    }

    public function edit($id)
    {
        MaterialEstimate::findOrFail($id);

        return redirect()->route('admin.material-estimates.index', ['edit' => (int) $id]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $next = (int) MaterialEstimate::max('sort_order') + 1;
        MaterialEstimate::create($data + ['sort_order' => $next, 'is_edited' => false]);

        return redirect()->route('admin.material-estimates.index')->with('success', 'Furniture type added.');
    }

    public function update(Request $request, $id)
    {
        $type = MaterialEstimate::findOrFail($id);
        $data = $this->validated($request, $type);

        $changed = false;
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            if (abs((float) $data[$k] - (float) $type->{$k}) > 0.004) {
                $changed = true;
            }
        }
        $type->fill($data);
        if ($type->isSeeded()) {
            $starter = $type->starterValues();
            $type->is_edited = collect(array_keys(MaterialLibrary::FIELDS))->contains(fn ($k) => abs((float) $data[$k] - $starter[$k]) > 0.004);
        } elseif ($changed) {
            $type->is_edited = false;
        }
        $type->save();

        return redirect()->route('admin.material-estimates.index')->with('success', 'Estimate saved.');
    }

    /** Back to the seeded numbers; only for a seeded type. */
    public function reset($id)
    {
        $type = MaterialEstimate::findOrFail($id);
        if (! $type->isSeeded()) {
            return redirect()->route('admin.material-estimates.index')->withErrors(['reset' => 'This type was added by hand, so it has no starter values.']);
        }
        foreach ($type->starterValues() as $k => $v) {
            $type->{$k} = $v;
        }
        $type->is_edited = false;
        $type->save();

        return redirect()->route('admin.material-estimates.index')->with('success', 'Reset to starter values.');
    }

    private function validated(Request $request, ?MaterialEstimate $type = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120', Rule::unique('material_estimates', 'name')->ignore($type?->id), new \App\Rules\NoLinkOrEmail()],
        ];
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            $rules[$k] = ['required', 'numeric', 'min:0', 'max:99999'];
        }
        $data = $request->validate($rules, ['name.unique' => 'There is already a furniture type with this name.']);
        $data['name'] = \App\Support\TextSanitizer::line($data['name']);
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            $data[$k] = round((float) $data[$k], 2);
        }
        // A seeded name is what the matcher and custom orders look up, so it cannot be renamed.
        if ($type?->isSeeded()) {
            $data['name'] = $type->name;
        }

        return $data;
    }
}
