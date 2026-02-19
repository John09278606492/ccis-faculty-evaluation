<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @php
                $user = auth()->user();
                $enrolledCount = $user->courseOfferings()->count();
                $withFormCount = $user->courseOfferings()->whereNotNull('form_id')->count();
                $completedCount = \App\Models\FormSubmission::where('user_id', $user->id)
                    ->whereIn('course_offering_id', $user->courseOfferings()->pluck('course_offerings.id'))
                    ->distinct('course_offering_id')
                    ->count('course_offering_id');
                $pendingCount = $withFormCount - $completedCount;
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-lg bg-blue-100 dark:bg-blue-900">
                        <x-heroicon-o-academic-cap class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Enrolled Courses</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $enrolledCount }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-lg bg-amber-100 dark:bg-amber-900">
                        <x-heroicon-o-clock class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Pending Evaluations</p>
                        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $pendingCount }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-lg bg-green-100 dark:bg-green-900">
                        <x-heroicon-o-check-circle class="w-6 h-6 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Completed</p>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $completedCount }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>