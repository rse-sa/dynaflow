<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RSE\DynaFlow\Models\DynaflowInstance;
use RSE\DynaFlow\Services\DynaflowValidator;

class DynaflowInstanceController extends Controller
{
    public function index(Request $request)
    {
        $query = DynaflowInstance::with(['dynaflow', 'triggeredBy', 'currentStep'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('dynaflow_id')) {
            $query->where('dynaflow_id', $request->dynaflow_id);
        }

        $instances = $query->paginate(15);

        return view('dynaflow::instances.index', compact('instances'));
    }

    public function show(DynaflowInstance $instance)
    {
        $instance->load([
            'dynaflow.steps',
            'currentStep.allowedTransitions',
            'executions.step',
            'executions.executedBy',
            'dynaflowData',
        ]);

        $canExecute           = false;
        $availableTransitions = collect();

        if ($instance->isPending() && auth()->check()) {
            $validator  = app(DynaflowValidator::class);
            $canExecute = $validator->canUserExecuteStep($instance->currentStep, auth()->user());

            if ($canExecute) {
                $availableTransitions = $instance->currentStep->allowedTransitions;
            }
        }

        return view('dynaflow::instances.show', compact('instance', 'canExecute', 'availableTransitions'));
    }
}
