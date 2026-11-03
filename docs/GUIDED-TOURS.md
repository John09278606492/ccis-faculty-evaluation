# Guided help for faculty evaluation

The panel uses `jibaymcs/filament-tour` 3.1.2, compatible with Filament 3.
Plugin documentation: https://filamentphp.com/plugins/jibay-mcs-tour

Click **Guide me** above a supported page. Upload Students, Upload Section
Students and Assign Evaluators by Section also contain their own **Guide me**
button. Use Next / Back, Finish guide, the close button or Escape. Guides can
be replayed. They are offered on demand so they do not interrupt uploads,
drafts or recurring work.

## Covered workflows

- Dashboard: campus, academic period and next steps.
- Student Master List: supported files, example CSV, period, departments,
  validation review, confirmation and final background import.
- Sections: create the section, download its CSV, upload its roster and confirm
  matched master-list identities. Sex is optional; IDs retain leading zeros.
- Teaching Classes: faculty and subject, saved sections, individual exceptions,
  explicit Annex B supervisor selection and reviewing changes before saving.
- Evaluation Setup: Annex A/B, schedule, readiness checklist and publication.
- Background Processing: status, Details / Review, correcting validation errors,
  confirmation, completion and download expiry.
- Evaluation Forms, Student Progress, Faculty Results, individual copies,
  supervisor tasks and released personal results.
- Authenticated student My Evaluations: pending tasks and submission guidance.

Guides contain explanations only. They never select a file, click a save button,
confirm an import, publish an evaluation or request access to another campus's
records. All import validation, authorization and background jobs remain in
their existing services. Unknown guide IDs and guides outside the current
user's permissions are rejected server-side.

## Implementation

`GuidedHelp` is the central permission and instruction registry. It uses stable
`data-guide` targets for important fields and actions. When a target is absent
(for example an action on an empty table), the plugin displays the explanation
next to the current guide button or heading, using visible controls only. No
step intentionally falls back to a centered modal.

`CampusTourPlugin` extends the requested plugin with a persistent
`GuidedTours` widget. The widget serializes only the requested authorized tour.
This avoids the upstream loader scanning and constructing every resource page,
and its assumption that the selected campus has a slug. A manual-only tour
route prevents auto-start and chaining into unrelated tours.

The placement-aware browser adapter uses Driver.js (the plugin's underlying
tour engine) with the plugin's existing server-side tour definitions and events.
The upstream browser renderer is not loaded alongside it, avoiding competing
popovers. Important fields scroll into view inside the active dialog. Large
table/form regions use a representative header/control, and long guidance
scrolls within a phone-sized popover so its navigation remains accessible.
Positioning reserves space outside the highlight, flips sides when needed and
recalculates on viewport resize and scrolling. Rebuild the locally served bundle
with `npm run build:guides`; the normal npm build includes this step.
The dialog host stays fixed during draft saves, and Livewire morphs reattach the
current step to its updated control. The popover keeps its title and navigation
visible while long descriptions scroll. Background clicks cannot pass through
to submit, cancel or advance the underlying form; use Close, Escape or Finish
guide to return to the form.

The runtime survives Filament SPA navigation. Escape closes only the tour,
preserving the underlying upload dialog. Navigation dismisses any active tour.
Popover styling fits phone screens and all guidance is hidden when printing.
The adapter's JS and CSS are served locally for campus networks.
An Alpine initialization interceptor uses Filament's native eager strategy for
tables. This prevents below-the-fold phone tables from losing their modal
containers when an early upload action or background refresh runs.

Installations use the normal `composer install` and Filament asset publication
(`php artisan filament:assets`), including the existing post-autoload scripts.
Do not turn on the plugin's development CSS-selector tool for users.

Verification includes permission and cross-campus denials, replay without
database writes, upload/section regression tests, and real browser checks for
Next, Back, Escape, modal state, SPA navigation and phone layout. Browser
receipts and screenshots are kept under ignored `storage/app/maintenance/`.
The placement regression checks cover 80 steps across five screen sizes,
including section/supervisor assignment dialogs, and assert that the highlight
is not covered and that navigation stays inside the popover. The geometry unit
tests cover seven edge cases; eight permission/asset tests also pass.
