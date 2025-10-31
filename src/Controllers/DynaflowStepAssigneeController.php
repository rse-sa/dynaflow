<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RSE\DynaFlow\Models\Dynaflow;
use RSE\DynaFlow\Models\DynaflowStep;

class DynaflowStepAssigneeController extends Controller
{
    public function store(Request $request, Dynaflow $dynaflow, DynaflowStep $step)
    {
        $validated = $request->validate([
            'assignable_type' => 'required|string',
            'assignable_id'   => 'required|integer',
        ]);

        $step->assignees()->firstOrCreate($validated);

        return back()->with('success', 'Assignee added successfully');
    }

    public function destroy(Dynaflow $dynaflow, DynaflowStep $step, $assigneeId)
    {
        $step->assignees()->where('id', $assigneeId)->delete();

        return back()->with('success', 'Assignee removed successfully');
    }
}
