<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

class DynaflowStepController extends Controller
{
    public function store(Request $request, Dynaflow $dynaflow)
    {
        $validated = $request->validate([
            'name'          => 'required|array',
            'name.*'        => 'required|string',
            'description'   => 'nullable|array',
            'order'         => 'required|integer',
            'is_final'      => 'boolean',
            'transitions'   => 'nullable|array',
            'transitions.*' => 'exists:dynaflow_steps,id',
        ]);

        $step = $dynaflow->steps()->create([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? [],
            'order'       => $validated['order'],
            'is_final'    => $validated['is_final'] ?? false,
        ]);

        if (! empty($validated['transitions'])) {
            $step->allowedTransitions()->sync($validated['transitions']);
        }

        return redirect()
            ->route('dynaflows.show', $dynaflow)
            ->with('success', 'Step created successfully');
    }

    public function update(Request $request, Dynaflow $dynaflow, DynaflowStep $step)
    {
        $validated = $request->validate([
            'name'          => 'required|array',
            'name.*'        => 'required|string',
            'description'   => 'nullable|array',
            'order'         => 'required|integer',
            'is_final'      => 'boolean',
            'transitions'   => 'nullable|array',
            'transitions.*' => 'exists:dynaflow_steps,id',
        ]);

        $step->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? [],
            'order'       => $validated['order'],
            'is_final'    => $validated['is_final'] ?? false,
        ]);

        if (isset($validated['transitions'])) {
            $step->allowedTransitions()->sync($validated['transitions']);
        }

        return redirect()
            ->route('dynaflows.show', $dynaflow)
            ->with('success', 'Step updated successfully');
    }

    public function destroy(Dynaflow $dynaflow, DynaflowStep $step)
    {
        $step->delete();

        return redirect()
            ->route('dynaflows.show', $dynaflow)
            ->with('success', 'Step deleted successfully');
    }
}
