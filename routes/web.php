<?php

use Illuminate\Support\Facades\Route;
use RSE\DynaFlow\Controllers\DynaflowController;
use RSE\DynaFlow\Controllers\DynaflowExceptionController;
use RSE\DynaFlow\Controllers\DynaflowInstanceController;
use RSE\DynaFlow\Controllers\DynaflowStepAssigneeController;
use RSE\DynaFlow\Controllers\DynaflowStepController;
use RSE\DynaFlow\Controllers\DynaflowStepExecutionController;

Route::prefix(config('dynaflow.route_prefix', 'dynaflows'))
    ->middleware(config('dynaflow.middleware', ['web', 'auth']))
    ->group(function () {
        Route::resource('dynaflows', DynaflowController::class);

        Route::post('dynaflows/{dynaflow}/steps', [DynaflowStepController::class, 'store'])
            ->name('dynaflows.steps.store');

        Route::put('dynaflows/{dynaflow}/steps/{step}', [DynaflowStepController::class, 'update'])
            ->name('dynaflows.steps.update');

        Route::delete('dynaflows/{dynaflow}/steps/{step}', [DynaflowStepController::class, 'destroy'])
            ->name('dynaflows.steps.destroy');

        Route::post('dynaflows/{dynaflow}/steps/{step}/assignees', [DynaflowStepAssigneeController::class, 'store'])
            ->name('dynaflows.steps.assignees.store');

        Route::delete('dynaflows/{dynaflow}/steps/{step}/assignees/{assigneeId}', [DynaflowStepAssigneeController::class, 'destroy'])
            ->name('dynaflows.steps.assignees.destroy');

        Route::post('dynaflows/{dynaflow}/exceptions', [DynaflowExceptionController::class, 'store'])
            ->name('dynaflows.exceptions.store');

        Route::delete('dynaflows/{dynaflow}/exceptions/{exceptionId}', [DynaflowExceptionController::class, 'destroy'])
            ->name('dynaflows.exceptions.destroy');

        Route::get('instances', [DynaflowInstanceController::class, 'index'])
            ->name('dynaflows.instances.index');

        Route::get('instances/{instance}', [DynaflowInstanceController::class, 'show'])
            ->name('dynaflows.instances.show');

        Route::post('instances/{instance}/execute', [DynaflowStepExecutionController::class, 'execute'])
            ->name('dynaflows.instances.execute');
    });
