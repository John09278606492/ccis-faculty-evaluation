<div class="space-y-3">
    <a href="{{ asset('templates/teaching-class-section-roster-example.csv') }}" download="teaching-class-section-roster-example.csv"
        class="inline-flex items-center gap-2 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
        <x-heroicon-o-arrow-down-tray class="h-5 w-5" />
        Download Section Roster CSV
    </a>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Replace the example rows with the actual section list. Keep IDs as text to preserve leading zeros. Use the names recorded in the campus masterlist.
    </p>
    <pre class="overflow-x-auto rounded-lg bg-gray-100 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">Section,ID No.,Lastname,Firstname,Middlename,Sex
BSIT 3A,SAMPLE-REPLACE-001,DELA CRUZ,JUAN,SANTOS,M
BSIT 3A,SAMPLE-REPLACE-002,REYES,MARIA,,F</pre>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        A section title on the first line, such as <strong>BSIT - 3A</strong>, followed by <strong>ID No., Lastname, Firstname, Middlename, Sex</strong> is also accepted. For combined sections, use the Section column on every row.
    </p>
    <p class="text-sm text-gray-600 dark:text-gray-300">Validation runs in the background. Open <strong>Background Processing → Details / Review</strong> or the notification bell. Correct all errors before selecting <strong>Confirm Assignment</strong>. Confirming adds the matched students and their sections for this academic period, preserving existing evaluators, other sections and submitted evaluations.</p>
</div>
