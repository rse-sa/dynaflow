<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\DynaflowEngine;

class DynaflowStepExecutionController extends Controller
{
    public function execute(Request $request, DynaflowInstance $instance)
    {
        $validated = $request->validate([
            'target_step_id' => 'required|exists:dynaflow_steps,id',
            'decision'       => 'required|string|in:approve,reject,request_edit,cancel',
            'note'           => 'nullable|string',
        ]);

        $targetStep = DynaflowStep::findOrFail($validated['target_step_id']);
        $engine     = app(DynaflowEngine::class);

        try {
            $engine->executeStep(
                instance: $instance,
                targetStep: $targetStep,
                decision: $validated['decision'],
                user: auth()->user(),
                note: $validated['note']
            );

            return redirect()
                ->route('dynaflows.instances.show', $instance)
                ->with('success', __('Step executed successfully'));
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }
}
