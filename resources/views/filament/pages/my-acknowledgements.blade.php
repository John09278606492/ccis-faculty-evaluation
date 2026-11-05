<x-filament-panels::page>
    @php
        $subjects = $this->getSubjectSummary();
        $toSign = $subjects->sum('to_sign');
    @endphp

    {{-- Styles live here so this page needs no asset rebuild. --}}
    <style>
        .ack-hero { display: flex; align-items: center; gap: 16px; padding: 16px 18px; border-radius: 12px; border: 1px solid #fcd34d; background: #fffbeb; color: #78350f; }
        .ack-hero.done { border-color: #86efac; background: #f0fdf4; color: #14532d; }
        .ack-hero-count { flex: 0 0 auto; min-width: 56px; height: 56px; padding: 0 10px; display: grid; place-items: center; border-radius: 12px; background: #f59e0b; color: #fff; font-size: 26px; font-weight: 700; }
        .ack-hero.done .ack-hero-count { background: #16a34a; }
        .ack-hero h2 { margin: 0; font-size: 18px; font-weight: 700; line-height: 1.3; }
        .ack-hero p { margin: 2px 0 0; font-size: 14px; }
        .ack-subjects { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        .ack-subject { display: block; width: 100%; padding: 14px; text-align: left; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; color: #111827; cursor: pointer; }
        .ack-subject:hover, .ack-subject:focus-visible { border-color: #6366f1; }
        .ack-subject.selected { border-color: #4f46e5; box-shadow: 0 0 0 2px #4f46e5; }
        .ack-subject:disabled { cursor: default; opacity: .75; border-color: #e5e7eb; }
        .ack-subject-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .ack-subject-code { font-size: 12px; font-weight: 600; color: #6b7280; }
        .ack-subject-name { margin-top: 1px; font-size: 15px; font-weight: 600; line-height: 1.3; }
        .ack-pill { flex: 0 0 auto; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; background: #fef3c7; color: #92400e; }
        .ack-pill.done { background: #dcfce7; color: #166534; }
        .ack-pill.none { background: #f3f4f6; color: #4b5563; }
        .ack-bar { height: 6px; margin-top: 12px; border-radius: 999px; overflow: hidden; background: #e5e7eb; }
        .ack-bar span { display: block; height: 100%; border-radius: 999px; background: #16a34a; }
        .ack-subject-note { margin-top: 6px; font-size: 12px; color: #6b7280; }
        .ack-filter-note { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 14px; }
        .ack-filter-note button { min-height: 44px; padding: 0 4px; font-weight: 600; color: #4f46e5; text-decoration: underline; }
        .dark .ack-hero { border-color: #92400e; background: rgba(245, 158, 11, .12); color: #fde68a; }
        .dark .ack-hero.done { border-color: #166534; background: rgba(22, 163, 74, .12); color: #bbf7d0; }
        .dark .ack-subject { border-color: #374151; background: #111827; color: #f9fafb; }
        .dark .ack-subject-code, .dark .ack-subject-note { color: #9ca3af; }
        .dark .ack-bar { background: #374151; }
        .dark .ack-pill.none { background: #374151; color: #d1d5db; }
        @media (max-width: 639px) {
            .ack-subjects { grid-template-columns: 1fr; }
            .ack-table .fi-ta-actions, .ack-table .fi-ta-actions > * { width: 100%; }
            .ack-table .fi-ta-actions .fi-btn { width: 100%; min-height: 48px; font-size: 16px; }
            .ack-table .fi-ta-actions .fi-link { min-height: 44px; }
        }
    </style>

    <section @class(['ack-hero', 'done' => $toSign === 0]) aria-live="polite">
        <div class="ack-hero-count">{{ $toSign }}</div>
        <div>
            @if ($toSign > 0)
                <h2>You have {{ $toSign }} {{ str('form')->plural($toSign) }} to sign</h2>
                <p>Across {{ $subjects->where('to_sign', '>', 0)->count() }} {{ str('subject')->plural($subjects->where('to_sign', '>', 0)->count()) }}.</p>
            @elseif ($subjects->sum('total') > 0)
                <h2>You're all caught up</h2>
                <p>You have no forms waiting for your signature.</p>
            @else
                <h2>Nothing to sign yet</h2>
                <p>Forms from your instructors will appear here.</p>
            @endif
        </div>
    </section>

    @if ($subjects->isNotEmpty())
        <div class="ack-subjects">
            @foreach ($subjects as $item)
                <button
                    type="button"
                    wire:click="toggleSubject({{ $item->id }})"
                    wire:key="ack-subject-{{ $item->id }}"
                    @disabled($item->total === 0)
                    aria-pressed="{{ $this->subject === $item->id ? 'true' : 'false' }}"
                    @class(['ack-subject', 'selected' => $this->subject === $item->id])
                >
                    <span class="ack-subject-top">
                        <span>
                            <span class="ack-subject-code" style="display:block">{{ $item->code }}{{ $item->section ? ' · '.$item->section : '' }}</span>
                            <span class="ack-subject-name" style="display:block">{{ $item->name }}</span>
                        </span>
                        @if ($item->to_sign > 0)
                            <span class="ack-pill">{{ $item->to_sign }} to sign</span>
                        @elseif ($item->total > 0 && $item->signed === $item->total)
                            <span class="ack-pill done">All signed</span>
                        @else
                            <span class="ack-pill none">{{ $item->total > 0 ? 'None open' : 'No forms' }}</span>
                        @endif
                    </span>
                    @if ($item->total > 0)
                        <span class="ack-bar" style="display:block"><span style="width: {{ round($item->signed / $item->total * 100) }}%"></span></span>
                        <span class="ack-subject-note" style="display:block">{{ $item->signed }} of {{ $item->total }} {{ str('form')->plural($item->total) }} signed</span>
                    @else
                        <span class="ack-subject-note" style="display:block">Your instructor has not issued a form yet.</span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    @if ($this->subject)
        <div class="ack-filter-note">
            <span>Showing one subject only.</span>
            <button type="button" wire:click="toggleSubject({{ $this->subject }})">Show all subjects</button>
        </div>
    @endif

    <div class="ack-table">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
