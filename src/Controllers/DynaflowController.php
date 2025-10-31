<?php

namespace RSE\DynaFlow\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use RSE\DynaFlow\Models\Dynaflow;

class DynaflowController extends Controller
{
    public function index(): Factory|\Illuminate\Contracts\View\View|View
    {
        $dynaflows = Dynaflow::with(['steps', 'overriddenBy'])
            ->latest()
            ->paginate(15);

        return view('dynaflow::dynaflows.index', compact('dynaflows'));
    }

    public function create(): Factory|\Illuminate\Contracts\View\View|View
    {
        $models  = config('dynaflow.available_models', []);
        $actions = ['create', 'update', 'delete'];

        return view('dynaflow::dynaflows.create', compact('models', 'actions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|array',
            'name.*'      => 'required|string',
            'topic'       => 'required|string',
            'action'      => 'required|string|in:create,update,delete',
            'description' => 'nullable|array',
            'active'      => 'boolean',
        ]);

        $dynaflow = Dynaflow::create($validated);

        return redirect()
            ->route('dynaflows.show', $dynaflow)
            ->with('success', 'Workflow created successfully');
    }

    public function show(Dynaflow $dynaflow): Factory|\Illuminate\Contracts\View\View|View
    {
        $dynaflow->load(['steps.allowedTransitions', 'steps.assignees.assignable', 'exceptions.exceptionable']);

        return view('dynaflow::dynaflows.show', compact('dynaflow'));
    }

    public function edit(Dynaflow $dynaflow): Factory|\Illuminate\Contracts\View\View|View
    {
        $models  = config('dynaflow.available_models', []);
        $actions = ['create', 'update', 'delete'];

        return view('dynaflow::dynaflows.edit', compact('dynaflow', 'models', 'actions'));
    }

    public function update(Request $request, Dynaflow $dynaflow): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|array',
            'name.*'      => 'required|string',
            'topic'       => 'required|string',
            'action'      => 'required|string|in:create,update,delete',
            'description' => 'nullable|array',
            'active'      => 'boolean',
        ]);

        $dynaflow->update($validated);

        return redirect()
            ->route('dynaflows.show', $dynaflow)
            ->with('success', 'Workflow updated successfully');
    }

    public function destroy(Dynaflow $dynaflow): RedirectResponse
    {
        $dynaflow->delete();

        return redirect()
            ->route('dynaflows.index')
            ->with('success', 'Workflow deleted successfully');
    }
}
