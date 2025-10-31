<div x-data="dynaflowSteps(@js($data))" class="dynaflow-diagram">
    <div class="bg-white rounded-lg shadow-sm p-6">
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900" x-text="dynaflow.name"></h3>
            <div class="flex items-center gap-4 mt-2 text-sm text-gray-600">
                <span x-text="`{{ __('Status') }}: ${instance.status}`"></span>
                <span x-text="`{{ __('Triggered by') }}: ${instance.triggered_by.name}`"></span>
                <span x-text="`{{ __('Created') }}: ${formatDate(instance.created_at)}`"></span>
            </div>
        </div>

        <div class="relative">
            <template x-for="(step, index) in steps" :key="step.id">
                <div class="mb-8 last:mb-0">
                    <div class="flex items-start gap-4">
                        <div class="flex flex-col items-center">
                            <div
                                class="w-10 h-10 rounded-full flex items-center justify-center font-semibold"
                                :class="{
                                    'bg-green-500 text-white': step.is_completed,
                                    'bg-blue-500 text-white': step.is_current,
                                    'bg-gray-200 text-gray-600': !step.is_completed && !step.is_current
                                }"
                                x-text="step.order"
                            ></div>
                            <div
                                x-show="index < steps.length - 1"
                                class="w-0.5 h-16 mt-2"
                                :class="step.is_completed ? 'bg-green-500' : 'bg-gray-300'"
                            ></div>
                        </div>

                        <div class="flex-1">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-semibold text-gray-900" x-text="step.name"></h4>
                                    <span
                                        x-show="step.is_current"
                                        class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded"
                                    >{{ __('Current') }}</span>
                                    <span
                                        x-show="step.is_completed"
                                        class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded"
                                    >{{ __('Completed') }}</span>
                                </div>

                                <p class="text-sm text-gray-600 mb-3" x-text="step.description"></p>

                                <div class="space-y-2 text-sm">
                                    <div x-show="step.assignees.length > 0">
                                        <span class="font-medium text-gray-700">{{ __('Assignees') }}:</span>
                                        <template x-for="assignee in step.assignees" :key="assignee.id">
                                            <span class="inline-block px-2 py-1 bg-gray-200 text-gray-700 rounded text-xs ml-1" x-text="assignee.name"></span>
                                        </template>
                                    </div>

                                    <div x-show="step.transitions.length > 0">
                                        <span class="font-medium text-gray-700">{{ __('Can transition to') }}:</span>
                                        <template x-for="transition in step.transitions" :key="transition.id">
                                            <span class="inline-block px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs ml-1" x-text="transition.name"></span>
                                        </template>
                                    </div>

                                    <div x-show="step.execution">
                                        <div class="mt-3 p-3 bg-white rounded border border-gray-200">
                                            <div class="grid grid-cols-2 gap-2 text-xs">
                                                <div>
                                                    <span class="font-medium text-gray-700">{{ __('Executed by') }}:</span>
                                                    <span x-text="step.execution?.executed_by.name"></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-700">{{ __('Decision') }}:</span>
                                                    <span
                                                        class="px-2 py-0.5 rounded"
                                                        :class="{
                                                            'bg-green-100 text-green-800': step.execution?.decision === 'approve',
                                                            'bg-red-100 text-red-800': step.execution?.decision === 'reject',
                                                            'bg-yellow-100 text-yellow-800': step.execution?.decision === 'request_edit'
                                                        }"
                                                        x-text="step.execution?.decision"
                                                    ></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-700">{{ __('Duration') }}:</span>
                                                    <span x-text="`${step.execution?.duration_hours}h (${step.execution?.duration_days}d)`"></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-700">{{ __('Executed at') }}:</span>
                                                    <span x-text="formatDate(step.execution?.executed_at)"></span>
                                                </div>
                                            </div>
                                            <div x-show="step.execution?.note" class="mt-2">
                                                <span class="font-medium text-gray-700">{{ __('Note') }}:</span>
                                                <p class="text-gray-600 mt-1" x-text="step.execution?.note"></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('dynaflowSteps', (initialData) => ({
            instance: initialData.instance,
            dynaflow: initialData.dynaflow,
            steps: initialData.steps,

            formatDate(dateString) {
                if (!dateString) return '';
                const date = new Date(dateString);
                return date.toLocaleString();
            }
        }));
    });
</script>
