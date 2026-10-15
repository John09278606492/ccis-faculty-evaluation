# Mobile table cards

Standard Filament tables display labeled record cards below 640px. Desktop keeps its original table layout. The global column configuration adds escaped label metadata; responsive CSS stacks existing rows, so Livewire updates, permissions, filtering and actions use the same components without duplicating records.

Sortable headers remain available as compact controls. Selection checkboxes and row actions remain accessible, and actions have at least 44px height. Long field values wrap. Column visibility preferences still apply. Tables already using Filament's Split/Stack layouts retain their existing behavior.

Workbook previews stay as spreadsheet grids because their cell coordinates and merged ranges matter.

The suggested Mobile Preset plugin requires Filament 4.11.5+ or 5.6.5+; this project is on Filament 3. This implementation supplies the requested card behavior without a major framework upgrade or installing an incompatible dependency.

Verified at 320, 390, 639, 640 and 1280px: cards below 640px, tables above, no page-level horizontal overflow on class records. Visually inspected light/dark layouts, quiz action dropdowns, workbook preview/Close button, and preservation after search updates. Vite build and 44 existing tests (252 assertions) pass.

Reference: https://filamentphp.com/plugins/hammadzafar05-mobile-preset
