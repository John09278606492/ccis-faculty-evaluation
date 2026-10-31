<x-filament-panels::page data-live-panel class="fi-dashboard-page">
    @php
        $workspace = $this->getWorkspaceData();
    @endphp
    @include('filament.partials.workspace-guide')
    <div class="grid gap-4 md:grid-cols-3">
        @foreach($workspace['cards'] as $card)
            <x-filament::section><div class="text-sm text-gray-500">{{ $card['label'] }}</div><div class="my-2 text-3xl font-semibold">{{ $card['value'] }}</div><div class="text-sm text-gray-500">{{ $card['hint'] }}</div></x-filament::section>
        @endforeach
    </div>
    @if(! empty($workspace['progress']))
        <x-filament::section heading="Evaluation progress" description="Student ratings received so far, one expected per student per class.">
            <div class="space-y-4">
                @foreach($workspace['progress'] as $row)
                    @php
                        $percent = $row['expected'] ? round($row['received'] / $row['expected'] * 100) : 0;
                    @endphp
                    <a href="{{ $row['url'] }}" class="block rounded-lg p-2 hover:bg-gray-50 dark:hover:bg-white/5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ number_format($row['received']) }} of {{ number_format($row['expected']) }} · <strong>{{ $percent }}%</strong></span>
                        </div>
                        <div style="margin-top:.5rem;height:10px;border-radius:9999px;overflow:hidden;background:rgba(var(--gray-400),.25)"><div style="height:100%;border-radius:9999px;background:rgb(var(--primary-600));width: {{ $percent }}%"></div></div>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    @foreach($workspace['warnings'] as $warning)<x-filament::section><p class="text-warning-700">{{ $warning }}</p></x-filament::section>@endforeach
    @if (method_exists($this, 'filtersForm'))
        {{ $this->filtersForm }}
    @endif

    <details class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300">More Statistics</summary>
    <div class="mt-4" x-data="{
            draggedEl: null,
            placeholder: null,
            grid: null,

            init() {
                this.placeholder = document.createElement('div');
                this.placeholder.className = 'col-span-full h-1.5 bg-primary-500 rounded-full my-1';
                this.grid = this.$refs.widgetGrid;
            },

            startDrag(e) {
                const handle = e.target.closest('.drag-handle');
                if (!handle) { e.preventDefault(); return; }
                const item = handle.closest('[data-widget-class]');
                if (!item) { e.preventDefault(); return; }
                this.draggedEl = item;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
                requestAnimationFrame(() => {
                    item.style.opacity = '0.4';
                });
            },

            endDrag() {
                if (this.draggedEl) {
                    this.draggedEl.style.opacity = '';
                }
                if (this.placeholder.parentNode) {
                    this.placeholder.remove();
                }
                this.draggedEl = null;
            },

            overDrag(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (!this.draggedEl || !this.grid) return;

                const after = this.getAfter(e.clientY);
                if (after == null) {
                    this.grid.appendChild(this.placeholder);
                } else {
                    this.grid.insertBefore(this.placeholder, after);
                }
            },

            async dropDrag(e) {
                e.preventDefault();
                if (!this.draggedEl || !this.grid) return;

                if (this.placeholder.parentNode) {
                    this.grid.insertBefore(this.draggedEl, this.placeholder);
                    this.placeholder.remove();
                }
                this.draggedEl.style.opacity = '';

                const order = [...this.grid.querySelectorAll('[data-widget-class]')]
                    .map(el => el.dataset.widgetClass);

                // Call the Livewire method directly and reload to reflect new order
                await $wire.call('reorderWidgets', order);
                location.reload();
            },

            getAfter(y) {
                const els = [...this.grid.querySelectorAll('[data-widget-class]')]
                    .filter(el => el !== this.draggedEl);
                return els.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset, element: child };
                    }
                    return closest;
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            }
        }">
        @if(count($this->getVisibleWidgets()) > 1)
        {{-- Reset Order Button --}}
        <div class="flex items-center justify-end gap-2 mb-3">
            <button type="button"
                x-on:click="if(confirm('Reset widget order to defaults?')) { $wire.call('resetWidgetOrder').then(() => location.reload()); }"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium bg-gray-100 text-gray-600 transition hover:bg-danger-500 hover:text-white dark:bg-gray-800 dark:text-gray-400">
                <x-heroicon-m-arrow-path class="w-4 h-4" />
                <span>Reset Order</span>
            </button>
        </div>

        @endif
        {{-- Widget Grid --}}
        <div x-ref="widgetGrid" x-on:dragstart="startDrag($event)" x-on:dragend="endDrag()"
            x-on:dragover.prevent="overDrag($event)" x-on:drop.prevent="dropDrag($event)"
            class="grid grid-cols-1 gap-6 md:grid-cols-2 fi-wi">
            @foreach ($this->getVisibleWidgets() as $widgetKey => $widget)
                @php
                    $widgetClass = ($widget instanceof \Filament\Widgets\WidgetConfiguration) ? $widget->widget : $widget;
                @endphp

                @php
                    // Read columnSpan from widget class to apply to wrapper
                    $colSpan = 'full';
                    try {
                        $ref = new \ReflectionProperty($widgetClass, 'columnSpan');
                        $val = $ref->getDefaultValue();
                        if ($val !== null) {
                            $colSpan = $val;
                        }
                    } catch (\Throwable $e) {
                    }
                    $spanClass = match (true) {
                        $colSpan === 'full' => 'col-span-full',
                        $colSpan === 2 || $colSpan === '2' => 'md:col-span-2',
                        $colSpan === 1 || $colSpan === '1' => '',
                        default => 'col-span-full',
                    };
                @endphp

                <div data-widget-class="{{ $widgetClass }}" draggable="true" class="relative group {{ $spanClass }}">
                    {{-- Drag Handle --}}
                    <div
                        class="drag-handle absolute top-0 left-0 right-0 z-20 h-8 cursor-grab active:cursor-grabbing flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                        <div
                            class="flex items-center gap-1 px-3 py-0.5 rounded-b-lg bg-[#340167]/80 dark:bg-[#DCDDDE]/80 backdrop-blur-sm shadow">
                            <x-heroicon-m-bars-3 class="w-3.5 h-3.5 text-white dark:text-gray-800" />
                            <span class="text-[11px] font-medium text-white dark:text-gray-800">Drag to reorder</span>
                        </div>
                    </div>

                    @livewire(
                        $widgetClass,
                        [...(($widget instanceof \Filament\Widgets\WidgetConfiguration) ? [...$widget->widget::getDefaultProperties(), ...$widget->getProperties()] : $widget::getDefaultProperties()), ...$this->getWidgetData()],
                        key("{$widgetClass}-{$widgetKey}"),
                    )
                    </div>
            @endforeach
        </div>
    </div>
    </details>
</x-filament-panels::page>

