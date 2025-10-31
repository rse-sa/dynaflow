<?php

namespace RSE\DynaFlow\Services;

use RSE\DynaFlow\Models\DynaflowInstance;

class DynaflowStepVisualizer
{
    public function generate(DynaflowInstance $instance): array
    {
        $dynaflow   = $instance->dynaflow;
        $steps      = $dynaflow->steps;
        $executions = $instance->executions->keyBy('dynaflow_step_id');

        $visualData = [];

        foreach ($steps as $step) {
            $execution = $executions->get($step->id);

            $stepData = [
                'id'           => $step->id,
                'name'         => $step->name,
                'description'  => $step->description,
                'order'        => $step->order,
                'is_final'     => $step->is_final,
                'is_current'   => $instance->current_step_id === $step->id,
                'is_completed' => $execution !== null,
                'transitions'  => $step->allowedTransitions->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'order' => $t->order,
                ])->toArray(),
                'assignees' => $step->assignees->map(fn ($a) => [
                    'type' => class_basename($a->assignable_type),
                    'id'   => $a->assignable_id,
                    'name' => $a->assignable->name ?? 'Unknown',
                ])->toArray(),
                'execution' => $execution ? [
                    'executed_by' => [
                        'id'   => $execution->executedBy->id,
                        'name' => $execution->executedBy->name,
                    ],
                    'decision'       => $execution->decision,
                    'note'           => $execution->note,
                    'duration_hours' => $execution->duration_hours,
                    'duration_days'  => round($execution->duration_hours / 24, 1),
                    'executed_at'    => $execution->executed_at->toIso8601String(),
                ] : null,
            ];

            $visualData[] = $stepData;
        }

        return [
            'instance' => [
                'id'           => $instance->id,
                'status'       => $instance->status,
                'triggered_by' => [
                    'id'   => $instance->triggeredBy->id,
                    'name' => $instance->triggeredBy->name,
                ],
                'created_at' => $instance->created_at->toIso8601String(),
            ],
            'dynaflow' => [
                'id'     => $dynaflow->id,
                'name'   => $dynaflow->name,
                'action' => $dynaflow->action,
            ],
            'steps' => $visualData,
        ];
    }
}
