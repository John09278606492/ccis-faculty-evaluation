@props(['submission', 'fields'])

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
            <span class="font-medium text-gray-500 dark:text-gray-400">Evaluator:</span>
            <span class="ml-2">{{ $submission->form?->isSupervisorInstrument() ? 'Supervisor' : 'Student' }}</span>
        </div>
        <div>
            <span class="font-medium text-gray-500 dark:text-gray-400">Date:</span>
            <span class="ml-2">{{ $submission->submitted_at?->format('M j, Y g:i A') }}</span>
        </div>
        @if($submission->department)
            <div>
                <span class="font-medium text-gray-500 dark:text-gray-400">Department:</span>
                <span class="ml-2">{{ $submission->department->name }}</span>
            </div>
        @endif
    </div>

    <hr class="border-gray-200 dark:border-gray-700">

    <dl class="space-y-4">
        @foreach($fields as $field)
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $field->label }}
                    @if($field->is_required)
                        <span class="text-danger-500">*</span>
                    @endif
                </dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                    @php
                        $value = $submission->getFieldValue($field->name);
                    @endphp

                    @if(is_array($value))
                        <ul class="list-disc list-inside">
                            @foreach($value as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @elseif($field->type === 'checkbox')
                        @if($value)
                            <x-heroicon-o-check-circle class="h-5 w-5 text-success-500" />
                        @else
                            <x-heroicon-o-x-circle class="h-5 w-5 text-danger-500" />
                        @endif
                    @elseif($field->type === 'file' && $value)
                        <a href="{{ Storage::url($value) }}" target="_blank" class="text-primary-600 hover:underline">
                            View File
                        </a>
                    @else
                        {{ $value ?? '-' }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</div>