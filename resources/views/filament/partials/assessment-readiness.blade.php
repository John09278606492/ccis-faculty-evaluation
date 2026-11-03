<div class="grid gap-3 sm:grid-cols-2" role="list" aria-label="Assessment readiness">
    @foreach($checks as $check)
        <div role="listitem" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <p class="font-medium">{{ $check['label'] }} · {{ $check['ready'] ? 'Ready' : 'Needs attention' }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $check['detail'] }}</p>
        </div>
    @endforeach
</div>
