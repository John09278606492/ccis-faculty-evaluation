@if($context = app(\App\Services\GuidedHelp::class)->forCurrentPage())
    <x-guided-help :context="$context" />
@endif
