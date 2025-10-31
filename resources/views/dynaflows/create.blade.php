<x-app-layout>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">{{ __('Create Workflow') }}</h2>

                    <form action="{{ route('dynaflows.store') }}" method="POST">
                        @csrf

                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name (English)') }}</label>
                                <input type="text" name="name[en]" value="{{ old('name.en') }}" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('name.en')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name (Arabic)') }}</label>
                                <input type="text" name="name[ar]" value="{{ old('name.ar') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" dir="rtl">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Topic (Model)') }}</label>
                                <select name="topic" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('Select Model') }}</option>
                                    @foreach($models as $model)
                                        <option value="{{ $model }}" {{ old('topic') === $model ? 'selected' : '' }}>{{ class_basename($model) }}</option>
                                    @endforeach
                                </select>
                                @error('topic')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Action') }}</label>
                                <select name="action" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('Select Action') }}</option>
                                    @foreach($actions as $action)
                                        <option value="{{ $action }}" {{ old('action') === $action ? 'selected' : '' }}>{{ __(ucfirst($action)) }}</option>
                                    @endforeach
                                </select>
                                @error('action')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Description (English)') }}</label>
                                <textarea name="description[en]" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description.en') }}</textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Description (Arabic)') }}</label>
                                <textarea name="description[ar]" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" dir="rtl">{{ old('description.ar') }}</textarea>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="active" value="1" {{ old('active', true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <label class="ml-2 text-sm text-gray-700">{{ __('Active') }}</label>
                            </div>

                            <div class="flex gap-3">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    {{ __('Create Workflow') }}
                                </button>
                                <a href="{{ route('dynaflows.index') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                                    {{ __('Cancel') }}
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
