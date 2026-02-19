<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats Overview --}}
        @php
            $user = auth()->user();
            $submissions = \App\Models\FormSubmission::whereHas('courseOffering', fn($q) => $q->where('user_id', $user->id))->get();
            $totalSubmissions = $submissions->count();
            $courseCount = \App\Models\CourseOffering::where('user_id', $user->id)->whereHas('formSubmissions')->count();
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border dark:border-gray-700 p-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-academic-cap class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $courseCount }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Courses Evaluated</p>
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
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Evaluations Received</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
            <div class="flex items-start gap-2">
                <x-heroicon-o-information-circle class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" />
                <p class="text-xs text-blue-700 dark:text-blue-300">
                    All evaluations are <strong>anonymous</strong>. You can view the scores and ratings for each
                    submission,
                    but the identity of evaluators is not disclosed.
                </p>
            </div>
        </div>

        {{-- Table --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>