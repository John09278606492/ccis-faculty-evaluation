@php
    $kind = strtolower($quiz->kindLabel());
    $usesPhpMyAdmin = $quiz->questions->contains(fn ($question) => $question->hasDatabase());
@endphp
<div class="flex items-start gap-3 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
    <x-heroicon-o-eye class="mt-0.5 h-5 w-5 shrink-0" />
    <div>
        <p class="font-semibold">This {{ $kind }} is supervised</p>
        <p class="mt-1">While your attempt is in progress, your instructor sees:</p>
        <ul class="mt-1 list-disc pl-5">
            <li>when you leave this page (switch to another tab, window or app), and for how long;</li>
            <li>text you paste into an answer from outside this {{ $kind }}, including what was pasted.</li>
        </ul>
        <p class="mt-1">Nothing else on your computer is seen: not which sites or apps you open, and not your screen.
            Copying and pasting within this {{ $kind }} is not recorded.</p>
        @if ($usesPhpMyAdmin)
            <p class="mt-1">phpMyAdmin opens in another tab, so time spent there shows as time away from this page.</p>
        @endif
    </div>
</div>
