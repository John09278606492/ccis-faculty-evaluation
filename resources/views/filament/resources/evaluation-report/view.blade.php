<x-filament-panels::page>
    @php
        $summary = $this->summary;
        $sectionBreakdown = $this->sectionBreakdown;
        $fieldBreakdown = $this->fieldBreakdown;
        $courseCards = $this->courseCards;
        $comments = $this->comments;
        $record = $this->record;
    @endphp

    {{-- Profile Header --}}
    <x-filament::section>
        <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
            <div class="flex-shrink-0">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white font-bold text-2xl shadow-lg">
                    {{ strtoupper(substr($record->full_name, 0, 1)) }}
                </div>
            </div>
            <div class="flex-grow">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $record->full_name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $record->evaluateeDepartment?->name ?? 'No Department' }}
                    @if($record->email)
                        <span class="mx-1">&middot;</span> {{ $record->email }}
                    @endif
                </p>
            </div>
            <div class="flex gap-4 flex-wrap">
                <div class="text-center px-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ $summary['total_submissions'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Evaluations</div>
                </div>
                <div class="text-center px-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <div class="text-xl font-bold @if(($summary['avg_score'] ?? 0) >= 4.5) text-emerald-600 dark:text-emerald-400 @elseif(($summary['avg_score'] ?? 0) >= 3.5) text-blue-600 dark:text-blue-400 @elseif(($summary['avg_score'] ?? 0) >= 2.5) text-amber-600 dark:text-amber-400 @else text-gray-600 dark:text-gray-400 @endif">
                        {{ $summary['avg_score'] ?? 'N/A' }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Avg Score</div>
                </div>
                <div class="text-center px-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <div class="text-xl font-bold @if($summary['rating_label'] === 'Outstanding') text-emerald-600 @elseif($summary['rating_label'] === 'Very Satisfactory') text-blue-600 @else text-amber-600 @endif">
                        {{ $summary['rating_label'] }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rating</div>
                </div>
                <div class="text-center px-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <div class="text-xl font-bold text-cyan-600 dark:text-cyan-400">{{ $summary['completion_rate'] }}%</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Completion</div>
                </div>
            </div>
        </div>
    </x-filament::section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        {{-- Section-Level Scores --}}
        <x-filament::section>
            <x-slot name="heading">Section Scores</x-slot>
            <x-slot name="description">Average scores by form section</x-slot>

            @if($sectionBreakdown->isEmpty())
                <x-filament-tables::empty-state
                    icon="heroicon-o-chart-bar"
                    heading="No section data"
                    description="No evaluation data available for this evaluatee."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start divide-y divide-gray-200 dark:divide-white/5">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="fi-ta-header-cell px-3 py-2 text-start text-sm font-semibold text-gray-950 dark:text-white">Section</th>
                                <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Avg</th>
                                <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Min</th>
                                <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Max</th>
                                <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Fields</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach($sectionBreakdown as $section)
                                <tr class="fi-ta-row transition hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="fi-ta-cell px-3 py-2.5 text-sm font-medium text-gray-950 dark:text-white">{{ $section['section'] }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-center">
                                        <x-filament::badge :color="match(true) { $section['avg'] >= 4.5 => 'success', $section['avg'] >= 3.5 => 'info', $section['avg'] >= 2.5 => 'warning', default => 'danger' }">
                                            {{ $section['avg'] }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-500 dark:text-gray-400">{{ $section['min'] }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-500 dark:text-gray-400">{{ $section['max'] }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-400">{{ $section['field_count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Score Radar Chart --}}
        <x-filament::section>
            <x-slot name="heading">Score Radar</x-slot>
            <x-slot name="description">Visual distribution across sections</x-slot>

            @if($sectionBreakdown->isEmpty())
                <x-filament-tables::empty-state
                    icon="heroicon-o-chart-pie"
                    heading="No data"
                    description="No data available to chart."
                />
            @else
                <div x-load-js="[@js(\Filament\Support\Facades\FilamentAsset::getScriptSrc('chart-js'))]" x-data="{
                    chart: null,
                    async init() {
                        while (! window.Chart) await new Promise((resolve) => setTimeout(resolve, 50));
                        const ctx = this.$refs.radarChart.getContext('2d');
                        this.chart = new Chart(ctx, {
                            type: 'radar',
                            data: {
                                labels: @js($sectionBreakdown->pluck('section')->toArray()),
                                datasets: [{
                                    label: 'Avg Score',
                                    data: @js($sectionBreakdown->pluck('avg')->toArray()),
                                    backgroundColor: 'rgba(99, 102, 241, 0.15)',
                                    borderColor: 'rgba(99, 102, 241, 0.8)',
                                    pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                                    pointBorderColor: '#fff',
                                    pointRadius: 4,
                                    borderWidth: 2,
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                scales: {
                                    r: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } }
                                },
                                plugins: { legend: { display: false } }
                            }
                        });
                    }
                }" wire:ignore>
                    <canvas x-ref="radarChart" class="max-h-64"></canvas>
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- Per-Field Breakdown --}}
    <x-filament::section class="mt-6">
        <x-slot name="heading">Per-Field Breakdown</x-slot>
        <x-slot name="description">Detailed scores for each evaluation field</x-slot>

        @if($fieldBreakdown->isEmpty())
            <x-filament-tables::empty-state
                icon="heroicon-o-table-cells"
                heading="No field data"
                description="No detailed field data available."
            />
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start divide-y divide-gray-200 dark:divide-white/5">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="fi-ta-header-cell px-3 py-2 text-start text-sm font-semibold text-gray-950 dark:text-white">Section</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-start text-sm font-semibold text-gray-950 dark:text-white">Field</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Avg</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Min</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Max</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:text-white">Responses</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">Distribution</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach($fieldBreakdown as $row)
                            <tr class="fi-ta-row transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['section'] }}</td>
                                <td class="fi-ta-cell px-3 py-2.5 text-sm font-medium text-gray-950 dark:text-white">{{ $row['field_label'] }}</td>
                                <td class="fi-ta-cell px-3 py-2.5 text-center">
                                    <x-filament::badge :color="match(true) { $row['avg'] >= 4.5 => 'success', $row['avg'] >= 3.5 => 'info', $row['avg'] >= 2.5 => 'warning', default => 'danger' }">
                                        {{ $row['avg'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-500">{{ $row['min'] }}</td>
                                <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-500">{{ $row['max'] }}</td>
                                <td class="fi-ta-cell px-3 py-2.5 text-center text-sm text-gray-400">{{ $row['count'] }}</td>
                                <td class="fi-ta-cell px-3 py-2.5">
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                        <div class="h-2 rounded-full transition-all @if($row['avg'] >= 4.5) bg-emerald-500 @elseif($row['avg'] >= 3.5) bg-blue-500 @elseif($row['avg'] >= 2.5) bg-amber-500 @else bg-red-500 @endif" style="width: {{ ($row['avg'] / 5) * 100 }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Course-Level Results --}}
    <x-filament::section class="mt-6">
        <x-slot name="heading">Course-Level Results</x-slot>
        <x-slot name="description">Evaluation results per course assignment</x-slot>

        @if($courseCards->isEmpty())
            <x-filament-tables::empty-state
                icon="heroicon-o-academic-cap"
                heading="No course data"
                description="No course data available for this evaluatee."
            />
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($courseCards as $card)
                    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 hover:shadow-md transition bg-white dark:bg-gray-900">
                        <div class="flex items-start justify-between mb-2">
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white truncate">{{ $card['course'] }}</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Section: {{ $card['section'] }} &middot; {{ $card['semester'] }}</p>
                            </div>
                            @if($card['avg_score'])
                                <x-filament::badge :color="match(true) { $card['avg_score'] >= 4.5 => 'success', $card['avg_score'] >= 3.5 => 'info', $card['avg_score'] >= 2.5 => 'warning', default => 'danger' }">
                                    {{ $card['avg_score'] }}
                                </x-filament::badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 mt-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>
                                <x-heroicon-o-document-text class="w-3.5 h-3.5 inline -mt-0.5" />
                                {{ $card['submissions'] }}/{{ $card['students'] }} submitted
                            </span>
                            <span>
                                <x-heroicon-o-star class="w-3.5 h-3.5 inline -mt-0.5" />
                                {{ $card['rating_label'] }}
                            </span>
                        </div>
                        <div class="mt-2">
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full @if($card['completion'] >= 80) bg-emerald-500 @elseif($card['completion'] >= 50) bg-amber-500 @else bg-red-500 @endif" style="width: {{ min($card['completion'], 100) }}%"></div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-0.5">{{ $card['completion'] }}% completion</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Comments Panel --}}
    <x-filament::section class="mt-6">
        <x-slot name="heading">Feedback & Comments</x-slot>
        <x-slot name="description">Anonymized comments from evaluators</x-slot>

        @if(empty($comments['overall_comments']) && empty($comments['field_comments']))
            <x-filament-tables::empty-state
                icon="heroicon-o-chat-bubble-left-right"
                heading="No comments"
                description="No comments submitted yet."
            />
        @else
            @if(!empty($comments['overall_comments']))
                <div class="mb-6">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                        <x-heroicon-o-chat-bubble-left class="w-4 h-4 inline -mt-0.5" /> Overall Comments
                    </h4>
                    <div class="space-y-2">
                        @foreach($comments['overall_comments'] as $comment)
                            <div class="pl-3 border-l-2 border-primary-300 dark:border-primary-700 py-1">
                                <p class="text-sm text-gray-700 dark:text-gray-300 italic">&ldquo;{{ $comment }}&rdquo;</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(!empty($comments['field_comments']))
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                        <x-heroicon-o-chat-bubble-bottom-center-text class="w-4 h-4 inline -mt-0.5" /> Field-Specific Comments
                    </h4>
                    @foreach($comments['field_comments'] as $fieldName => $fieldComments)
                        <div class="mb-4">
                            <h5 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400 mb-1.5">{{ $fieldName }}</h5>
                            <div class="space-y-1.5">
                                @foreach($fieldComments as $comment)
                                    <div class="pl-3 border-l-2 border-gray-200 dark:border-gray-700 py-0.5">
                                        <p class="text-sm text-gray-600 dark:text-gray-400 italic">&ldquo;{{ $comment }}&rdquo;</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
