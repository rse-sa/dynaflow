<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900">{{ __('Workflow Instance') }} #{{ $instance->id }}</h2>
                            <p class="text-gray-600 mt-1">{{ $instance->dynaflow->name }}</p>
                            <div class="flex gap-3 mt-3">
                                @if($instance->isPending())
                                    <span class="px-3 py-1 text-sm bg-yellow-100 text-yellow-800 rounded">{{ __('Pending') }}</span>
                                @elseif($instance->isCompleted())
                                    <span class="px-3 py-1 text-sm bg-green-100 text-green-800 rounded">{{ __('Completed') }}</span>
                                @else
                                    <span class="px-3 py-1 text-sm bg-red-100 text-red-800 rounded">{{ __('Cancelled') }}</span>
                                @endif
                                <span class="px-3 py-1 text-sm bg-blue-100 text-blue-800 rounded">
                                    {{ class_basename($instance->model_type) }} #{{ $instance->model_id }}
                                </span>
                            </div>
                        </div>
                        <a href="{{ route('dynaflows.instances.index') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            {{ __('Back') }}
                        </a>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="font-medium text-gray-700">{{ __('Triggered By:') }}</span>
                            <span class="text-gray-600">{{ $instance->triggeredBy->name }}</span>
                        </div>
                        <div>
                            <span class="font-medium text-gray-700">{{ __('Created:') }}</span>
                            <span class="text-gray-600">{{ $instance->created_at->format('Y-m-d H:i') }}</span>
                        </div>
                        @if($instance->completed_at)
                            <div>
                                <span class="font-medium text-gray-700">{{ __('Completed:') }}</span>
                                <span class="text-gray-600">{{ $instance->completed_at->format('Y-m-d H:i') }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @if(session('success'))
                <div class="bg-green-100 text-green-800 p-4 rounded-md">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-100 text-red-800 p-4 rounded-md">
                    {{ session('error') }}
                </div>
            @endif

            <x-dynaflow::dynaflow-steps :instance="$instance" />

            @if($canExecute)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Execute Step') }}</h3>
                        <form action="{{ route('dynaflows.instances.execute', $instance) }}" method="POST">
                            @csrf
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Next Step') }}</label>
                                    <select name="target_step_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @foreach($availableTransitions as $transition)
                                            <option value="{{ $transition->id }}">{{ $transition->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Decision') }}</label>
                                    <select name="decision" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="approve">{{ __('Approve') }}</option>
                                        <option value="reject">{{ __('Reject') }}</option>
                                        <option value="request_edit">{{ __('Request Edit') }}</option>
                                        <option value="cancel">{{ __('Cancel') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Note') }}</label>
                                    <textarea name="note" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                </div>

                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    {{ __('Execute Step') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
