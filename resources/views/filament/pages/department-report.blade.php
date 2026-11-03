<x-filament-panels::page>
    {{-- Semester Filter --}}
    <div class="mb-6">
        {{ $this->semesterFilterForm }}
    </div>

    {{-- Department Table (Filament) --}}
    {{ $this->table }}

    @php
        $distributionChart = $this->distributionChartData;
        $trendData = $this->semesterTrendData;
    @endphp

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        {{-- Score Distribution --}}
        <x-filament::section>
            <x-slot name="heading">SET Score Distribution</x-slot>
            <x-slot name="description">Breakdown by rating category</x-slot>

            @if(array_sum($distributionChart['data']) === 0)
                <x-filament-tables::empty-state icon="heroicon-o-chart-pie" heading="No data"
                    description="No evaluation data available." />
            @else
                <div wire:key="distribution-{{ md5(json_encode($distributionChart)) }}" x-load-js="[@js(\Filament\Support\Facades\FilamentAsset::getScriptSrc('chart-js'))]" x-data="{
                                chart: null,
                                destroy() { this.chart?.destroy(); },
                                async init() {
                                    while (! window.Chart) await new Promise((resolve) => setTimeout(resolve, 50));
                                    const ctx = this.$refs.distChart.getContext('2d');
                                    this.chart = new Chart(ctx, {
                                        type: 'doughnut',
                                        data: {
                                            labels: @js($distributionChart['labels']),
                                            datasets: [{
                                                data: @js($distributionChart['data']),
                                                backgroundColor: @js($distributionChart['colors']),
                                                borderWidth: 2,
                                                borderColor: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: true,
                                            plugins: {
                                                legend: {
                                                    position: 'bottom',
                                                    labels: {
                                                        padding: 16,
                                                        usePointStyle: true,
                                                        pointStyle: 'circle',
                                                        color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#374151',
                                                    }
                                                }
                                            },
                                            cutout: '60%',
                                        }
                                    });
                                }
                            }" wire:ignore>
                    <canvas x-ref="distChart" class="max-h-64 mx-auto"></canvas>
                </div>
            @endif
        </x-filament::section>

        {{-- Semester Trend --}}
        <x-filament::section>
            <x-slot name="heading">SET Semester Trend</x-slot>
            <x-slot name="description">Student evaluation mean / 5 across semesters</x-slot>

            @if(empty($trendData['labels']))
                <x-filament-tables::empty-state icon="heroicon-o-arrow-trending-up" heading="No trend data"
                    description="Not enough semester data to show trends." />
            @else
                <div wire:key="trend-{{ md5(json_encode($trendData)) }}" x-load-js="[@js(\Filament\Support\Facades\FilamentAsset::getScriptSrc('chart-js'))]" x-data="{
                                chart: null,
                                destroy() { this.chart?.destroy(); },
                                async init() {
                                    while (! window.Chart) await new Promise((resolve) => setTimeout(resolve, 50));
                                    const ctx = this.$refs.trendChart.getContext('2d');
                                    this.chart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: @js($trendData['labels']),
                                            datasets: [{
                                                label: 'Avg Score',
                                                data: @js($trendData['data']),
                                                borderColor: '#340167',
                                                backgroundColor: 'rgba(52, 1, 103, 0.1)',
                                                fill: true,
                                                tension: 0.4,
                                                pointBackgroundColor: '#340167',
                                                pointBorderColor: '#fff',
                                                pointRadius: 5,
                                                pointHoverRadius: 7,
                                                borderWidth: 2.5,
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: true,
                                            scales: {
                                                y: {
                                                    beginAtZero: true,
                                                    max: 5,
                                                    ticks: {
                                                        stepSize: 1,
                                                        color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                                                    },
                                                    grid: {
                                                        color: document.documentElement.classList.contains('dark') ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)',
                                                    },
                                                },
                                                x: {
                                                    grid: { display: false },
                                                    ticks: {
                                                        maxRotation: 45,
                                                        color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                                                    },
                                                },
                                            },
                                            plugins: { legend: { display: false } }
                                        }
                                    });
                                }
                            }" wire:ignore>
                    <canvas x-ref="trendChart" class="max-h-64"></canvas>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
