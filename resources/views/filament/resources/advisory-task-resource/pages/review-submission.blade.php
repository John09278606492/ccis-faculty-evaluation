<x-filament-panels::page>
    @php
        $submission = $this->entry;
        $task = $this->record;
        $files = $submission->files ?? [];
    @endphp

    {{-- Styles live here so this page needs no asset rebuild. --}}
    <style>
        .adv-review { display: grid; gap: 24px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .adv-review { grid-template-columns: minmax(0, 1fr) 380px; align-items: start; } .adv-review-side { position: sticky; top: 80px; } }
        .adv-file { border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #fff; }
        .adv-file-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 14px; font-weight: 600; }
        .adv-file img { display: block; width: 100%; height: auto; background: #f8fafc; }
        .adv-file iframe { display: block; width: 100%; height: 80vh; border: 0; }
        .adv-file-other { padding: 18px 14px; font-size: 14px; color: #4b5563; }
        .adv-card { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 16px; }
        .adv-card h3 { margin: 0 0 8px; font-size: 15px; font-weight: 700; }
        .adv-meta { display: grid; gap: 4px; font-size: 14px; }
        .adv-req { margin: 0; padding-left: 18px; font-size: 14px; }
        .dark .adv-file, .dark .adv-card { background: #111827; border-color: #374151; }
        .dark .adv-file-head { border-color: #1f2937; }
        .dark .adv-file-other { color: #9ca3af; }
    </style>

    <div class="adv-review">
        <div class="space-y-4">
            @forelse ($files as $index => $path)
                @php
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $url = route('advising.file', ['submission' => $submission->id, 'index' => $index]);
                    $inline = $url.'?inline=1';
                @endphp
                <section class="adv-file" wire:key="adv-file-{{ $index }}">
                    <div class="adv-file-head">
                        <span>File {{ $index + 1 }} of {{ count($files) }} · {{ strtoupper($ext) }}</span>
                        <x-filament::link :href="$url" icon="heroicon-o-arrow-down-tray">Download</x-filament::link>
                    </div>
                    @if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true))
                        <a href="{{ $inline }}" target="_blank" rel="noopener" title="Open full size">
                            <img src="{{ $inline }}" alt="File {{ $index + 1 }} from {{ $submission->student_name }}" loading="lazy">
                        </a>
                    @elseif ($ext === 'pdf')
                        <iframe src="{{ $inline }}" title="File {{ $index + 1 }} from {{ $submission->student_name }}" loading="lazy"></iframe>
                    @else
                        <div class="adv-file-other">This file type can't be shown here. Use Download to open it.</div>
                    @endif
                </section>
            @empty
                <div class="adv-card">This student has not submitted any files yet.</div>
            @endforelse
        </div>

        <div class="adv-review-side space-y-4">
            <div class="adv-card">
                <h3>Submission</h3>
                <div class="adv-meta">
                    <div>Status:
                        <x-filament::badge :color="\App\Models\AdvisorySubmission::COLORS[$submission->status] ?? 'gray'" style="display:inline-flex">
                            {{ \App\Models\AdvisorySubmission::STATUSES[$submission->status] ?? $submission->status }}
                        </x-filament::badge>
                    </div>
                    @if ($submission->submitted_at)
                        <div>Submitted {{ $submission->submitted_at->timezone('Asia/Manila')->format('M j, Y g:ia') }}
                            @if ($task->due_at && $submission->submitted_at->gt($task->due_at)) <strong class="text-danger-600">(late)</strong>@endif
                        </div>
                    @endif
                    @if ($submission->reviewed_at)
                        <div>Last reviewed {{ $submission->reviewed_at->timezone('Asia/Manila')->format('M j, Y g:ia') }}</div>
                    @endif
                </div>
                @if ($submission->note)
                    <p class="mt-3 text-sm" style="white-space:pre-wrap"><strong>Student note:</strong> {{ $submission->note }}</p>
                @endif
            </div>

            <div class="adv-card">
                <h3>Requirements</h3>
                <ul class="adv-req">@foreach ($task->requirementList() as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
            </div>

            @if ($this->canReview())
                <form wire:submit="save" class="adv-card space-y-4">
                    <h3>Update status</h3>
                    {{ $this->form }}
                    <x-filament::button type="submit" class="w-full">Save status</x-filament::button>
                </form>
            @endif
        </div>
    </div>
</x-filament-panels::page>
