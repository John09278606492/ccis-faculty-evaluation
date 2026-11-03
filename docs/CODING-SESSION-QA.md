# Coding session QA — October 21, 2026

## Follow-up implementation and retest

Implemented the responsive builder with a container query, readable Live preview/Split view controls, initialized preview answer bindings, isolated sandbox storage fallbacks, conditional starter-file instructions, shared timing labels, and in-page submission/restart confirmations.

Browser retest confirmed:

- The builder displays readable question rows at the same available width instead of crushing titles between three columns.
- Instructor and student pages show Untimed for the test activity.
- The actual preview can set/read/clear sandbox storage and prints 1 then 0 without an error.
- All three instructor preview editors initialize without the earlier Livewire entangle errors.
- Live updates connected on port 8105.
- A student edit immediately followed by Submit opens an in-page summary showing 1 answered and 2 unanswered. Keep editing returns to the attempt. Submit answers completes attempt 2 and displays the saved code in a read-only review screen. This resolves the earlier browser submission verification gap.

Screenshots are stored locally under storage/app/qa/. The original observations below are retained as the before-change record, not as outstanding defects.

Instance: C:\Users\front\Documents\ChatGPT\EVALUATION AND QUIZ MANAGEMENT SYSTEM
URL: http://127.0.0.1:8010
Assessment: QA Coding Session — Web, PHP and SQL (ID 1)
Student: QA-2026-001; attempt 1. Test records remain available locally.

## Verified through the browser

- Instructor login, assessment list, question builder and instructor preview.
- HTML/CSS/JavaScript multi-file preview: counter changed from 0 to 1 and console showed `count 1`.
- PHP execution returned `PHP result: 42`.
- SQL CREATE/INSERT/SELECT returned a table containing the inserted row.
- Student login and explicit Start attempt.
- Edited HTML updated the live preview.
- Created `notes.txt` and edited its contents.
- Save indicator changed from Unsaved to Saved.
- Reload restored the edited HTML and the new file's contents.
- Maximize and restore workspace controls worked.
- Student PHP and SQL execution returned the expected results.
- Progress reached 3 of 3 answered.

## Submission verification and limitation

Clicking Submit stalled browser automation at the native confirmation step. Subsequent browser operations also timed out. This is not enough evidence to label submission itself broken.

Submitted the same saved attempt through the application's Livewire component, with no validation errors. A separate database read confirmed status `submitted`, a submission timestamp and all three answers. The saved answers contain the edited HTML, notes file, PHP and SQL code. The final confirmation and review screen were not verified in the browser.

## Findings and suggestions, in priority order

1. **Responsive builder layout:** at the current browser width (about 1086 px), the sidebar plus three builder columns squeezed question titles into almost one character per line. Collapse the sidebar automatically or turn question properties into a drawer at this width. This was visible in the instructor builder.
2. **Preview binding errors:** the browser logged `Livewire Entangle Error` for `answers.1`, `answers.2` and `answers.3` in instructor preview. Initialize answer state before editor binding and keep preview persistence separate. Runners still worked during this test.
3. **Preview console error:** after adding/editing a text file, output displayed `Cannot convert undefined or null to object`. It did not prevent saving. Reproduce and trace the preview/storage initialization; do not assume this is an error in the student's code.
4. **Toolbar readability:** LIVE and SPLIT appeared as pale, effectively blank controls in the maximized dark workspace screenshot. Improve contrast and show full labels such as Live preview and Split view.
5. **Conflicting instructions:** builder help says students cannot add/remove starter files even when “Students can create files and folders” is checked. Make this help conditional on the setting.
6. **Timing labels:** this untimed activity displayed Untimed before starting but 120 minutes in its header and 120 min in the list. Render time using the effective activity timing rules.
7. **Submission UX:** consider an in-page confirmation showing answered count and save completion. Independently retest the existing native confirmation in a normal browser before treating the automation timeout as an application defect.

## Local setup correction

The Reverb port originally selected for this session (8095) was already serving phpMyAdmin from the old D-drive instance. Changed only this C-drive instance's .env and Start-Local.ps1 to Reverb port 8105 and started that service. The new Reverb process reports that it is listening on 127.0.0.1:8105. Browser reconnection after this correction was not verified because browser automation was stalled.

## Not covered

Multi-student load, real LAN devices, network loss recovery, automatic grading, instructor grading UI and student phpMyAdmin operations were not exercised in this pass. No feature fixes or GitHub push were performed.
