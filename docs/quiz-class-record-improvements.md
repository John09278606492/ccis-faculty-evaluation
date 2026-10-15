# Quiz and class record improvements

Implemented locally on 7–8 October 2026.

## Instructor workflow

1. Create a quiz draft. Saving opens the question builder.
2. Add questions and answer keys. Multiple choice accepts exactly one correct choice, matching the student interface. Invalid question edits stay visible with an explanation.
3. Pending edits save when switching questions; leaving the page warns about unsaved inputs. Questions can be reordered with arrow buttons as well as drag and drop. The editor stacks on small screens.
4. Preview, then publish from Quiz settings. Empty quizzes, incomplete answer keys, impossible passing scores and invalid opening/closing dates are rejected at publication. Adding an incomplete question to a published quiz returns it to draft.
5. Use Settings & results to grade submissions. The list shows drafts/published state and a count awaiting grading. Questions lock after the first attempt to protect existing work; duplicate the quiz for revisions.
6. Export results from Attempts & Submissions using the already-installed, free `pxlrbt/filament-excel` plugin. No additional dependencies were installed.

## Student workflow

- Opening a new quiz displays instructions, question count, points, timing and attempt allowance before the student presses Start.
- Returning resumes an active attempt or reviews the completed one. A retry requires an explicit action.
- A sticky progress bar and question links help students navigate long quizzes. Submission confirmation includes the number of unanswered questions.
- Upload validation errors appear beside the question; saved uploads remain visible after returning.
- Activity deadlines take priority over any old duration setting. Each new attempt gets a fresh timer in the browser.

## Class records

1. Upload XLSX or XLS (maximum 20 MB). Uploads use unique storage names while preserving the original download filename.
2. Choose Map sheet. Select the course/section and an actual workbook header from the searchable list; cell references identify each choice.
3. Check the roster summary before saving. It shows matched IDs, unrecognized IDs, missing enrolled students and sample IDs. Duplicate IDs and zero-match mappings are rejected.
4. Open a quiz and choose Sync to Class Record. The preview identifies the destination, score count, unmatched IDs and whether a column will be inserted or updated.
5. Sync, then download the workbook from Class Records. The menu also offers the workbook from before the most recent sync.

Sync considers each student's latest attempt and writes only fully graded scores. It does not fall back to an earlier graded attempt when the latest is unfinished. Existing cells without a current final score are retained. Blank gaps no longer stop scanning student rows. Existing quiz mappings are reused, and a workbook-level lock prevents simultaneous sheet syncs from overwriting each other. Failed validation leaves the workbook and column mappings unchanged; a pre-sync backup supports recovery from write failures.

The integration is for the official **Quizzes** section: a merged header containing “Quizzes”, a “Total Score” header, a “HIGHEST POSSIBLE SCORE” row within the first 100 rows, numbered student rows in column A and names in B. Header row numbers are detected. Exams and activities can be exported separately; they are not written into the Quizzes section. This is not a universal spreadsheet mapping engine.

## Verification

- 27 automated tests, 133 assertions: evaluation regressions, explicit quiz start/retry behavior, enrollment rechecks, preview isolation, question validation and edit preservation, grading, guided mapping, duplicate IDs, final-score selection, and XLS/XLSX formula/format preservation.
- Browser walkthrough with isolated SQLite demo data: instructor mapping, student start/answer/submit/review, instructor sync preview, successful 10/10 workbook sync, backup creation and downloaded Excel results.
- Desktop and 390-pixel phone layouts inspected. The downloaded workbook was reopened to confirm score 10, maximum 10 and `=SUM(D21:E21)`.
- Production assets built, Blade templates compiled, and full-repository Pint formatting checks passed. Three pre-existing formatting issues in evaluation pages were fixed without behavior changes.

Run tests with `php -d opcache.enable_cli=0 vendor/bin/pest`. This machine has PHP opcode caching enabled without timestamp checks, so disabling the CLI cache is necessary for reliable development checks. Restart the local PHP/web-server process if it continues to show older screens.

No database schema migration is required. Existing application data was not used for the browser walkthrough. Changes are local and have not been deployed. Verification focused on the quiz/class-record workflows above, not every unrelated administration screen.

## Workbook preview and linked-formula sync repair (2026-10-08)

The reported sync failure was reproduced against a copy of the actual workbook: PhpSpreadsheet tried to recalculate linked Excel formulas and raised `Unable to access External Workbook`. The roster had 27 students: one latest attempt graded, 20 in progress and six with no attempt. Partial grading was not the blocker.

Sync now saves without server-side formula recalculation and retains XLSX external-link package parts and relationships. Excel remains responsible for recalculating linked formulas with access to their source workbooks. The existing pre-sync backup and transaction protection remain in place.

Class Records now includes **Preview workbook** with worksheet selection, a scrollable styled cell grid, merged cells, row/column references, a cell inspector and formula-expression toggle. Sync includes saved/proposed views, green proposed cells, per-student destinations and reasons, status totals and unmatched-ID notices. Preview does not modify files or mapping records. Formula cells show a formula indicator rather than presenting cached totals as current; cached values can be inspected and are explicitly labelled potentially outdated. Logos, charts and print layout are not rendered. Previews show 100 rows at a time and up to 80 columns.

Verification: 29 automated tests / 167 assertions, including one-of-27 partial sync, preview immutability, Livewire preview dialogs and repeated sync with an unavailable external workbook. Actual-file copy sync succeeded; its four external-link package files were byte-identical, both external references and 81 linked formulas remained, and all package XML parsed. The original uploaded workbook and actual database mapping were unchanged during diagnosis.

## Assessment opening dates and activity sync

Quiz and activity sync now writes the scheduled opening date (`available_from`) into the date row immediately above the title, using an Excel date formatted `mmm d, yyyy`. Without a scheduled opening, it uses the earliest recorded attempt start. It does not substitute the creation date or today's sync date. If neither is available, existing dates remain untouched. Templates without a separate date row include the date in the title instead.

The sync preview labels the date source and highlights the date cell alongside scores. Activities now sync into the Activities group with their own total-score formulas. Column insertions update all assessment mappings on the worksheet, including existing activity mappings. Re-sync updates the existing date/score column.

Validation: 32 tests, 186 assertions, covering scheduled-date precedence, earliest-start fallback, missing dates, Excel date formatting, re-sync updates, and activity mappings after a quiz column insertion.

## Automatic class record syncing

Auto-sync to class record is enabled by default for existing and new quizzes (also supported for activities). The toggle is on the quiz edit/create form; save changes to apply it. Final-grade creation, completion of manual grading and score corrections trigger sync after the database transaction commits. Turning the setting on catches up existing grades; completing a worksheet mapping also retries matching assessments. Rolled-back grading never writes a workbook. No queue worker is required.

Automatic destination selection is restricted to the course instructor's mapped workbooks. It prefers a single existing assessment-to-column link; otherwise it requires exactly one mapped worksheet. Ambiguous grading periods are not guessed: manually sync to the desired sheet first. Failed syncs preserve the grade and display the error on the quiz form; fixing the issue and syncing manually retries and clears the error. Turning off auto-sync leaves manual syncing available.

Applied migration: 2026_10_08_000001_add_auto_sync_to_quizzes. Validation covers the default, toggle persistence, after-commit behavior, rollback, score correction, failure isolation, mapping catch-up, ambiguous periods and workbook ownership.

## Administrator-uploaded workbook auto-sync repair

The actual auto-sync failure was an ownership filter: the course instructor was user 1, while the mapped workbook was uploaded by super-admin user 11. Automatic destination discovery now accepts instructor-owned and super-admin-owned uploads within the same course. Unrelated instructors' workbooks remain excluded, and ambiguous destinations still require an explicit manual link.

Retried auto-sync for quiz 9 and sheet 6 on the actual workbook: all three currently graded scores and the October 8 opening date were verified after reopening the saved file; the error cleared and the pre-sync backup exists. Validation: 38 tests, 223 assertions, including administrator uploads, after-commit grade completion, score corrections, and unrelated-owner exclusion.

## Cross-device live UI updates (WebSocket push)

The earlier two-second quiz polling has been replaced by Laravel Reverb and Echo. Quiz saves, final grades, worksheet syncs, roster changes, and individual quiz assignments publish small invalidation events after the database transaction commits. Rolled-back changes publish nothing. Events contain course/quiz IDs and a change type, never grades, answers, or student names.

Private channels require membership in the campus and the correct application environment. Each page re-queries through its existing authorization. Quiz lists, My Quizzes, attempts and class records update on events; read-only workbook previews can update while open. Editable actions preserve their form input. Active quiz answers and pending uploads stay intact. Quiz edit forms warn about conflicting remote edits and reject stale saves. Reconnection rechecks the visible state so missed events are recovered. The live badge reports connection failures instead of silently presenting the screen as current.

This covers the quiz/class-record workflows, not every unrelated administrative resource. Existing Filament notification polling is separate. Scheduled opening countdowns refresh at their deadline; saving a change pushes immediately, subject to network and server processing time.

Verification: two browser pages received an authenticated event through the NativePHP LAN gateway on port 8088. Earlier, a demo quiz title saved in one browser updated the second browser's list without navigation; the title was restored. Regression coverage includes after-commit delivery, rollback, campus/environment isolation, publication, final grades, roster/assignment changes, input preservation and stale-edit protection.

## NativePHP Windows host

`desktop-host/` is a separate NativePHP 2 application that controls the existing classroom project. It preserves the project's MySQL database, workbook storage and accounts. It is not a portable copy of the classroom database. The installed classroom project and PHP executable must remain available on the host computer, and MySQL must be running.

The host window starts automatically by default, displays local network links, offers Copy link and Open system, and has Start/Stop and configurable paths/port. It launches a single public HTTP/WebSocket gateway (default 8088), four loopback PHP workers (8096–8099), and loopback Reverb (8095). NativePHP's control panel stays on loopback. NativePHP process cleanup terminates the gateway and its children when the app closes. Startup checks database availability and conflicting ports; it never kills unrelated services or migrates the classroom data.

Desktop process-status checks run locally every five seconds; they do not poll classroom data. NativePHP's environment is stripped before launching classroom workers so its SQLite settings and encryption key cannot override the classroom configuration. Debug output is disabled on the shared application.

See `desktop-host/HOST.md` for launch and build instructions. Windows installer is unsigned. Physical-device reachability still depends on the local network/firewall; the LAN URL and WebSocket gateway were verified from browsers on the host computer. Final regression suite: 44 tests, 252 assertions; desktop suite: 2 tests, 5 assertions.
