<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RSE\DynaFlow\Models\Dynaflow;

class DynaflowExceptionController extends Controller
{
    public function store(Request $request, Dynaflow $dynaflow)
    {
        $validated = $request->validate([
            'exceptionable_type' => 'required|string',
            'exceptionable_id'   => 'required|integer',
            'starts_at'          => 'nullable|date',
            'ends_at'            => 'nullable|date|after:starts_at',
        ]);

        $dynaflow->exceptions()->create($validated);

        return back()->with('success', 'Exception added successfully');
    }

    public function destroy(Dynaflow $dynaflow, $exceptionId)
    {
        $dynaflow->exceptions()->where('id', $exceptionId)->delete();

        return back()->with('success', 'Exception removed successfully');
    }
}
