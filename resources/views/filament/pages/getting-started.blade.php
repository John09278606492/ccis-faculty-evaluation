<x-filament-panels::page>
    @php($guide = $this->guide())
    <div x-data="{
        key: @js($guide['storage_key']),
        ids: @js(array_column($guide['steps'], 'id')),
        checked: [],
        openStep: null,
        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.key) || '[]');
                this.checked = Array.isArray(saved) ? saved.filter(id => this.ids.includes(id)) : [];
            } catch (_) { this.checked = []; }
            this.openStep = this.ids.find(id => !this.checked.includes(id)) || this.ids[0];
        },
        save() { try { localStorage.setItem(this.key, JSON.stringify(this.checked)); } catch (_) {} },
        toggle(id) {
            this.checked = this.checked.includes(id) ? this.checked.filter(item => item !== id) : [...this.checked, id];
            this.save();
        },
        next() { this.openStep = this.ids.find(id => !this.checked.includes(id)) || this.ids[0]; }
    }" class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Your {{ $guide['audience'] }} guide</x-slot>
            <x-slot name="description">Learn one step at a time. Open a task when you are ready, then use Guide me on that page for an on-screen walkthrough.</x-slot>
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">This checklist records what you have learned. It does not import, save, open, close or submit an evaluation. Your progress is kept for this account and campus in this browser.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white" x-text="checked.length + ' of ' + ids.length + ' steps reviewed'"></span>
                    <x-filament::button size="sm" x-on:click="next()">Continue learning</x-filament::button>
                    <x-filament::button size="sm" color="gray" outlined x-on:click="checked = []; save(); openStep = ids[0]">Start again</x-filament::button>
                </div>
                <p x-cloak x-show="checked.length === ids.length" class="text-sm font-semibold text-primary-600 dark:text-primary-400">Guide complete. You can return here or replay Guide me whenever you need help.</p>
            </div>
        </x-filament::section>

        @foreach($guide['steps'] as $index => $step)
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <details :open="openStep === @js($step['id'])" @if($loop->first) open @endif>
                    <summary class="cursor-pointer px-6 py-5 text-base font-semibold text-gray-950 dark:text-white"
                        x-on:click.prevent="openStep = openStep === @js($step['id']) ? null : @js($step['id'])">
                        <span>{{ $index + 1 }}. {{ $step['title'] }}</span>
                        <span x-cloak x-show="checked.includes(@js($step['id']))" class="ml-3 text-sm font-normal text-primary-600 dark:text-primary-400">Reviewed</span>
                    </summary>
                    <div class="space-y-5 px-6 pb-6">
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $step['why'] }}</p>
                        <ol class="list-decimal space-y-3 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach($step['instructions'] as $instruction)
                                <li class="pl-1">{{ $instruction }}</li>
                            @endforeach
                        </ol>
                        <p class="text-sm text-gray-700 dark:text-gray-300"><strong>Ready when:</strong> {{ $step['done'] }}</p>
                        <div class="flex flex-wrap items-center gap-4">
                            @if($step['url'])
                                <x-filament::button tag="a" :href="$step['url']" size="sm" icon="heroicon-o-arrow-top-right-on-square">Open task</x-filament::button>
                            @endif
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" class="rounded border-gray-300 text-primary-600"
                                    :checked="checked.includes(@js($step['id']))" x-on:change="toggle(@js($step['id']))">
                                I understand this step
                            </label>
                        </div>
                    </div>
                </details>
            </div>
        @endforeach

        <x-filament::section heading="Need help?">
            <p class="text-sm text-gray-600 dark:text-gray-400">Missing teacher or student: check the academic period, enrolment and class roster. Missing menu or restricted page: ask your administrator to check your campus, department and permissions. Failed upload: open Uploads &amp; Downloads and review the task details. Share the error reference with your campus administrator when one is shown.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
