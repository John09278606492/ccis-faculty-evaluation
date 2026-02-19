<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats Overview --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @php
                $campusId = filament()->getTenant()?->id;
                $totalInstructors = \App\Models\User::where('is_instructor', true)
                    ->when($campusId, fn($q) => $q->whereHas('instructorDepartment', fn($d) => $d->where('campus_id', $campusId)))
                    ->count();
                $evaluatedInstructors = \App\Models\User::where('is_instructor', true)
                    ->when($campusId, fn($q) => $q->whereHas('instructorDepartment', fn($d) => $d->where('campus_id', $campusId)))
                    ->whereHas('courseOfferingsAsInstructor.formSubmissions')
                    ->count();
                $totalSubmissions = \App\Models\FormSubmission::whereHas('courseOffering', function ($q) use ($campusId) {
                    $q->whereHas('semester.schoolYear', fn($sy) => $sy->when($campusId, fn($s) => $s->where('campus_id', $campusId)));
                })->count();
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border dark:border-gray-700 p-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-users class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalInstructors }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Instructors</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border dark:border-gray-700 p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-check-badge class="w-5 h-5 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $evaluatedInstructors }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Evaluated Instructors</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border dark:border-gray-700 p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-clipboard-document-check class="w-5 h-5 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalSubmissions }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Evaluations</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>