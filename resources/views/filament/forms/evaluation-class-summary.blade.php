<div class="rounded-lg border p-4 space-y-2" role="status">
    <p><strong>{{ $classes->count() }} classes</strong> · {{ $classes->pluck('user_id')->unique()->count() }} faculty · {{ $classes->flatMap(fn ($c) => $c->students->pluck('id'))->unique()->count() }} students</p>
    <p class="text-sm text-gray-500">{{ $automatic ? 'The draft class list refreshes when saved or reviewed. After opening, use Add Newly Prepared Classes to include later offerings.' : 'Your saved class selection is preserved. Choose an automatic option above to include prepared classes without selecting each instructor.' }}</p>
    @if($automatic && count($excluded))
        <details><summary class="cursor-pointer">{{ count($excluded) }} classes need preparation</summary>
            <ul class="list-disc ps-5" style="max-height:200px;overflow:auto">@foreach($excluded as $item)<li>{{ $item['label'] }}: {{ $item['reason'] }}</li>@endforeach</ul>
        </details>
    @endif
</div>
