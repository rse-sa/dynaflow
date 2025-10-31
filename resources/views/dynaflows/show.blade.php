<x-app-layout>
    <div class="py-12" x-data="dynaflowShow()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900">{{ $dynaflow->name }}</h2>
                            <p class="text-gray-600 mt-1">{{ $dynaflow->description }}</p>
                            <div class="flex gap-3 mt-3">
                                <span class="px-3 py-1 text-sm bg-blue-100 text-blue-800 rounded">{{ class_basename($dynaflow->topic) }}</span>
                                <span class="px-3 py-1 text-sm bg-purple-100 text-purple-800 rounded">{{ __(ucfirst($dynaflow->action)) }}</span>
                                @if($dynaflow->active)
                                    <span class="px-3 py-1 text-sm bg-green-100 text-green-800 rounded">{{ __('Active') }}</span>
                                @else
                                    <span class="px-3 py-1 text-sm bg-gray-100 text-gray-800 rounded">{{ __('Inactive') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('dynaflows.edit', $dynaflow) }}" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                {{ __('Edit') }}
                            </a>
                            <a href="{{ route('dynaflows.index') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                {{ __('Back') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            @if(session('success'))
                <div class="bg-green-100 text-green-800 p-4 rounded-md">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-gray-900">{{ __('Workflow Steps') }}</h3>
                        <button @click="showStepModal = true" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            {{ __('Add Step') }}
                        </button>
                    </div>

                    <div class="space-y-4">
                        @forelse($dynaflow->steps as $step)
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 flex items-center justify-center bg-blue-100 text-blue-800 font-semibold rounded-full">
                                                {{ $step->order }}
                                            </span>
                                            <h4 class="text-lg font-semibold text-gray-900">{{ $step->name }}</h4>
                                            @if($step->is_final)
                                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">{{ __('Final') }}</span>
                                            @endif
                                        </div>
                                        <p class="text-gray-600 mt-2 ml-11">{{ $step->description }}</p>

                                        <div class="mt-4 ml-11 space-y-3">
                                            <div>
                                                <span class="text-sm font-medium text-gray-700">{{ __('Can transition to:') }}</span>
                                                <div class="flex flex-wrap gap-2 mt-1">
                                                    @forelse($step->allowedTransitions as $transition)
                                                        <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded">
                                                            {{ $transition->name }}
                                                        </span>
                                                    @empty
                                                        <span class="text-sm text-gray-500">{{ __('No transitions defined') }}</span>
                                                    @endforelse
                                                </div>
                                            </div>

                                            <div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-sm font-medium text-gray-700">{{ __('Assignees:') }}</span>
                                                    <button @click="showAssigneeModal = true; selectedStep = {{ $step->id }}" class="text-sm text-blue-600 hover:text-blue-800">
                                                        {{ __('Add Assignee') }}
                                                    </button>
                                                </div>
                                                <div class="flex flex-wrap gap-2 mt-1">
                                                    @forelse($step->assignees as $assignee)
                                                        <div class="flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded">
                                                            <span>{{ $assignee->assignable->name ?? class_basename($assignee->assignable_type) }}</span>
                                                            <form action="{{ route('dynaflows.steps.assignees.destroy', [$dynaflow, $step, $assignee->id]) }}" method="POST" class="inline">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="text-red-600 hover:text-red-800 ml-1">×</button>
                                                            </form>
                                                        </div>
                                                    @empty
                                                        <span class="text-sm text-gray-500">{{ __('No assignees') }}</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex gap-2">
                                        <button @click="editStep({{ $step->toJson() }})" class="text-blue-600 hover:text-blue-800">
                                            {{ __('Edit') }}
                                        </button>
                                        <form action="{{ route('dynaflows.steps.destroy', [$dynaflow, $step]) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800" onclick="return confirm('{{ __('Are you sure?') }}')">
                                                {{ __('Delete') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-center text-gray-500 py-8">{{ __('No steps defined yet') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-gray-900">{{ __('Exceptions') }}</h3>
                        <button @click="showExceptionModal = true" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            {{ __('Add Exception') }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Type') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Starts At') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Ends At') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                            </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($dynaflow->exceptions as $exception)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ class_basename($exception->exceptionable_type) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $exception->exceptionable->name ?? __('N/A') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $exception->starts_at?->format('Y-m-d') ?? __('Immediately') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $exception->ends_at?->format('Y-m-d') ?? __('Infinite') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <form action="{{ route('dynaflows.exceptions.destroy', [$dynaflow, $exception->id]) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800" onclick="return confirm('{{ __('Are you sure?') }}')">
                                                {{ __('Delete') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">{{ __('No exceptions defined') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="showStepModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4" x-text="editingStep ? '{{ __('Edit Step') }}' : '{{ __('Add Step') }}'"></h3>

                    <form :action="editingStep ? `/dynaflows/{{ $dynaflow->id }}/steps/${editingStep.id}` : '{{ route('dynaflows.steps.store', $dynaflow) }}'" method="POST">
                        @csrf
                        <input type="hidden" name="_method" x-bind:value="editingStep ? 'PUT' : 'POST'">

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name (English)') }}</label>
                                <input type="text" name="name[en]" x-model="stepForm.name_en" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name (Arabic)') }}</label>
                                <input type="text" name="name[ar]" x-model="stepForm.name_ar" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" dir="rtl">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Description (English)') }}</label>
                                <textarea name="description[en]" x-model="stepForm.description_en" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Description (Arabic)') }}</label>
                                <textarea name="description[ar]" x-model="stepForm.description_ar" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" dir="rtl"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Order') }}</label>
                                <input type="number" name="order" x-model="stepForm.order" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Allowed Transitions') }}</label>
                                <select name="transitions[]" multiple class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" size="5">
                                    @foreach($dynaflow->steps as $s)
                                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-gray-500 mt-1">{{ __('Hold Ctrl/Cmd to select multiple') }}</p>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="is_final" value="1" x-model="stepForm.is_final" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <label class="ml-2 text-sm text-gray-700">{{ __('Final Step') }}</label>
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    {{ __('Save') }}
                                </button>
                                <button type="button" @click="closeStepModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="showAssigneeModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">{{ __('Add Assignee') }}</h3>

                    <form :action="`{{ route('dynaflows.show', $dynaflow) }}/steps/${selectedStep}/assignees`" method="POST">
                        @csrf

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Type') }}</label>
                                <select name="assignable_type" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('Select Type') }}</option>
                                    <option value="App\Models\User">{{ __('User') }}</option>
                                    <option value="App\Models\Role">{{ __('Role') }}</option>
                                    <option value="App\Models\Group">{{ __('Group') }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('ID') }}</label>
                                <input type="number" name="assignable_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    {{ __('Add') }}
                                </button>
                                <button type="button" @click="showAssigneeModal = false" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="showExceptionModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">{{ __('Add Exception') }}</h3>

                    <form action="{{ route('dynaflows.exceptions.store', $dynaflow) }}" method="POST">
                        @csrf

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Type') }}</label>
                                <select name="exceptionable_type" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('Select Type') }}</option>
                                    <option value="App\Models\User">{{ __('User') }}</option>
                                    <option value="App\Models\Role">{{ __('Role') }}</option>
                                    <option value="App\Models\Group">{{ __('Group') }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('ID') }}</label>
                                <input type="number" name="exceptionable_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Starts At') }}</label>
                                <input type="date" name="starts_at" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Ends At') }}</label>
                                <input type="date" name="ends_at" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    {{ __('Add') }}
                                </button>
                                <button type="button" @click="showExceptionModal = false" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function dynaflowShow() {
            return {
                showStepModal: false,
                showAssigneeModal: false,
                showExceptionModal: false,
                selectedStep: null,
                editingStep: null,
                stepForm: {
                    name_en: '',
                    name_ar: '',
                    description_en: '',
                    description_ar: '',
                    order: 1,
                    is_final: false
                },

                editStep(step) {
                    this.editingStep = step;
                    this.stepForm = {
                        name_en: step.name?.en || '',
                        name_ar: step.name?.ar || '',
                        description_en: step.description?.en || '',
                        description_ar: step.description?.ar || '',
                        order: step.order,
                        is_final: step.is_final
                    };
                    this.showStepModal = true;
                },

                closeStepModal() {
                    this.showStepModal = false;
                    this.editingStep = null;
                    this.stepForm = {
                        name_en: '',
                        name_ar: '',
                        description_en: '',
                        description_ar: '',
                        order: 1,
                        is_final: false
                    };
                }
            };
        }
    </script>
</x-app-layout>
