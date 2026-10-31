<div class="space-y-3 text-sm">
    @if($error)
        <p class="text-danger-600">{{ $error }}</p>
    @elseif(! $result)
        <p class="text-gray-500">Upload one list per section (CSV or Excel): the section name in the first row, for example BSIT 3A, then an ID No. column. The comparison appears here before anything is changed.</p>
    @else
        @foreach($result['files'] as $file)
            <p>
                <span class="font-medium">{{ $file['name'] }}</span>: {{ $file['count'] }} IDs
                @if($file['section'])
                    <span class="text-gray-500">· {{ $file['title'] }} → section {{ $file['section'] }}</span>
                @else
                    <span class="text-warning-600">· {{ $file['problem'] }}</span>
                @endif
            </p>
        @endforeach
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach(['Evaluators now' => $result['current'], 'Students in the lists' => $result['listed'], 'Verified (in both)' => $result['verified'], 'Differences' => count($result['missing']) + count($result['extra']) + count($result['unknown'])] as $label => $value)
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($value) }}</p></div>
            @endforeach
        </div>
        @if($result['identical'])
            <p class="font-semibold text-success-700">Verified: the evaluators are exactly the students in the {{ count($result['files']) === 1 ? 'list' : 'lists' }}.@if($result['pending']) {{ count($result['pending']) }} still need their report section.@endif</p>
        @endif
        @if($result['missing'])
            <details @if(count($result['missing']) <= 20) open @endif><summary class="cursor-pointer text-success-700">In the list but not evaluators of this class: {{ count($result['missing']) }}</summary>
                <ul class="mt-1 space-y-0.5 text-gray-600">@foreach($result['missing'] as $s)<li>{{ $s['id'] }} {{ $s['name'] }}@if($s['sections'])<span class="text-gray-400"> · now in {{ implode(', ', $s['sections']) }}</span>@endif</li>@endforeach</ul></details>
        @endif
        @if($result['extra'])
            <details @if(count($result['extra']) <= 20) open @endif><summary class="cursor-pointer text-warning-600">Evaluators not in any list: {{ count($result['extra']) }}</summary>
                <ul class="mt-1 space-y-0.5 text-gray-600">@foreach($result['extra'] as $s)<li>{{ $s['id'] }} {{ $s['name'] }}@if($s['sections'])<span class="text-gray-400"> · {{ implode(', ', $s['sections']) }}</span>@endif @if($s['submitted'])<span class="text-primary-600"> · already submitted, will be kept</span>@endif</li>@endforeach</ul></details>
        @endif
        @if($result['unknown'])
            <details><summary class="cursor-pointer text-danger-600">IDs not found in the student masterlist: {{ count($result['unknown']) }} (ignored; check for typos)</summary>
                <p class="mt-1 text-gray-600">{{ implode(', ', $result['unknown']) }}</p></details>
        @endif
        @if($result['pending'])
            <details><summary class="cursor-pointer text-gray-600">Verified evaluators whose report section is still pending: {{ count($result['pending']) }}</summary>
                <ul class="mt-1 space-y-0.5 text-gray-600">@foreach($result['pending'] as $s)<li>{{ $s['id'] }} {{ $s['name'] }} → {{ $s['section'] }}</li>@endforeach</ul></details>
        @endif
    @endif
</div>
