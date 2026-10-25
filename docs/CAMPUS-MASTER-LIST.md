# Uploading the campus student master list

Open **Academic Management → Students → Upload Students**. Upload the original `student-list_report.xlsx` without deleting its title rows, renaming columns or rearranging the repeated subject columns. CSV and older Excel files remain supported.

## Upload and review

1. Select the campus first. The report's campus must match it.
2. Select existing colleges/departments to import, or leave the selection empty to include all. Choices follow the staff member's assigned scope. Unselected existing departments are counted as skipped. New college codes in the file are also reviewed for automatic creation by authorized campus-wide importers.
3. Continue to the review. A campus report automatically selects its semester/school year when exactly one matching academic period already exists in that campus. If it does not exist, create/correct it first; the system does not invent semester dates. A basic CSV uses the selected period.
4. Check the new/existing student counts, department/program totals, subject enrolments and validation errors. Errors refer to the **original Excel row**, including its title/header rows. Download the error CSV for the complete list.
5. Confirm and import. Review alone makes no student changes. An invalid/stale review cannot partially import the file. Review tokens belong to the same staff member, campus and period, expire after 30 minutes and can be committed only once.

The application preserves existing student account passwords, assessment PINs, roles and evaluation submissions. It refuses to overwrite staff/privileged accounts or inaccessible student IDs. New evaluation accounts have **no usable password until a PIN is issued**; ID-only evaluation access remains independent from assessment sign-in. This avoids generating thousands of unnecessary password hashes during a master-list import.

## What the columns mean

| Campus report column | Application use |
|---|---|
| ID No. | Existing/new student identifier; explicit numeric zero-padding is preserved |
| Lastname / Firstname / Middlename | Student account names |
| College | Assigned student department in the selected campus |
| Course | **Degree program**, such as BSCS or BSIT; not a teaching-class subject |
| Year Level | Read-only reported year in this academic period; available when filtering subject-based suggestions |
| Sex | Reported academic context shown with the imported record |
| Total Units | Period academic record and validation against listed subject units |
| Repeated Subject Code / Description / Lec / Lab / Units | Actual subject enrolments for this student and period |
| Campus / Semester / School Year title rows | Campus validation and period detection |

The report has **no class section or instructor columns**. The application does not infer a section from the degree program/year, assign an arbitrary instructor or create teaching classes. A subject code can represent different descriptions across programs. Those descriptions are retained separately.

## Automatic course catalogue

### Automatic colleges / departments

Missing colleges/departments are now included in the same review and atomic import as students and courses. The review shows **New departments** and a table of proposed codes and names. Creation requires the student-import permissions, `create_department`, and campus-wide access in the selected campus. Department-only importers cannot create departments outside their assignment or grant themselves broader access.

Existing departments are matched within the selected campus, ignoring case and spaces in their codes. Existing names, active status, supervisors and chairpersons are preserved. Inactive departments and equivalent existing duplicates require correction before import. The same code in another campus is a separate department and is never adopted into this campus.

The campus report's **College** column supplies the code. An optional `department_name`, **Department Name**, or **College Name** column supplies a full name. If no full name is provided, the code is used as the initial name; edit it later under **Departments**. Codes support 1–20 letters/numbers with `. _ & / -` separators; names support up to 255 characters. Conflicting names for one code in the same upload are validation errors. No department heads or teaching assignments are inferred.

Department creation is preview-only until the valid import is confirmed. Commit rechecks access and catalogue state and serializes creation per campus. Changes after preview require a fresh review. A later student error rolls back new departments, students and courses together. Re-importing the same file reuses the existing departments.

### Courses

The import review now lists **New courses to add**, **Existing course matches**, and **Courses needing review**. After confirming a valid student import, missing, unambiguous subjects are added to **Academic Management → Courses** under the student's college/department. This also requires `create_course` in addition to the student-import permissions. Importers without course-creation permission can still save students and original subject enrolments; the review explains which courses need an authorized manager.

Existing courses are matched within the department, ignoring code letter case and spaces. They are not duplicated, renamed, given different units or automatically reactivated. A conflicting title/unit value, multiple existing equivalent codes, or an unsupported course-code/title/unit length is shown for review; the importer skips that course while preserving the student's original subject data. It never chooses an arbitrary description. The course catalogue supports codes up to 20 characters, titles up to 255 and units from 0 to 99.9 with one decimal place.

For already-uploaded data, use **Students → Sync Courses from Enrolments**. Select the academic period and departments, review the proposed courses/conflicts, then confirm. Repeating the sync does not duplicate existing matches. Reviews expire after 30 minutes, are bound to the same user/campus, and recheck source data, permissions and relevant catalogue records at commit. Courses added to the selected import are part of the same database transaction as the student import.

The example CSV now demonstrates two repeated subject blocks. Add further complete **Subject Code / Description / Lec / Lab / Units** blocks as needed, or upload the original Excel report directly. The degree-program column remains separate from these course subjects. Existing evaluation rosters and historical results are never replaced by this catalogue sync.

Use **Students → Enrolled Subjects** to inspect a student's imported program, units and subjects for the selected academic period. The student list includes a degree-program filter. Students can still be placed into multiple explicit sections for irregular schedules; uploading this report preserves their manually maintained section memberships.

## Use subject enrolments to suggest evaluators

1. Set up the real subject, faculty, teaching department and academic period under **Teaching Classes**.
2. Open the class's **Evaluators → Add from Master List Subjects** action.
3. Choose the correct master-list subject description. Only matching subject codes in the same period and authorized student scope are offered. An exact code/title match can be preselected; an ambiguous title is not guessed.
4. Filter by degree program and, optionally, the source report's year. Exclude students who do not attend this particular class/section.
5. Review the matching, newly added and final roster counts, confirm the class/section membership, then add the evaluators.

This adds or retains students; it does not remove existing evaluators or historical responses. “Do not add these students” excludes them from the new suggestions, not from an already existing roster. Use the existing explicit roster controls for removals. Manual section selection, custom students and ID-list assignment remain available.

A later master-list upload updates the student's imported subject snapshot for that period, but does not silently change already configured evaluation rosters. Review and reapply suggestions when needed. Other periods remain unchanged.

## Validation

- Required student ID, first/last names and valid, authorized college codes; missing departments require the creation access described above.
- Duplicate/conflicting IDs, duplicate emails and staff-account collisions.
- Numeric IDs must be whole numbers and at most 15 digits; use text for longer IDs because Excel can round them. A six-digit number remains a six-digit ID, not a fabricated institutional number.
- Spreadsheet formulas/errors are rejected in imported fields rather than evaluated.
- Subject code/description completeness, numeric/nonnegative lecture/lab/units, duplicate student subjects and total-unit reconciliation.
- Required scope, record state and academic-period checks are repeated at commit; records changed after preview require another review.
- Limits: 5 MB upload, 5,000 student rows, 256 columns, 10 worksheets, 50 subjects per student, and a bounded expanded Excel archive.

Lecture/laboratory values are preserved as reported; the importer does not invent a conversion between contact hours and academic units.

## Verification with the supplied campus file — 24 October 2026

The original file was tested twice in an **isolated SQLite QA database**, without importing real students into the live presentation database or modifying the source workbook.

| College | Students |
|---|---:|
| CCIS | 821 |
| CCJ | 694 |
| CTAS | 798 |
| **Total** | **2,313** |

The import found **7 degree-program labels**, **14,764 subject enrolments** and **109 subject codes**. Eight codes have more than one description; those variants remain separate. Ten students have no listed subjects; their account/academic records remain valid without invented enrolments. There were **zero validation errors**, including no total-unit mismatches.

The final first-upload review took **3.81 seconds**, followed by **15.89 seconds** to commit. Re-upload review took **8.17 seconds** and commit **27.82 seconds** on the local test machine. These are local measurements, not guaranteed school-server response times. Optimize the server's PHP memory/execution limits for the real list size and upload before opening a busy evaluation session.

All nine real-file checks passed: expected student/profile/enrolment counts, password-login disabled until PIN issuance, manual section preservation, other-period preservation, no invented sections, unchanged original workbook hash, and SQLite integrity/foreign keys. The repeat upload did not duplicate accounts or subject enrolments.

The reader's repeated full-sheet dimension scans were removed, reducing parsing from approximately 34.5 seconds to 2.7 seconds. Existing-record validation now preloads authorized IDs, roles, memberships and academic records, reducing the re-upload analysis from 23,139 queries to 54. Subject validation visits each block once.

Full automated suite: **429 passed / 1,967 assertions**. New coverage includes the real report layout, zero-padded IDs, source formulas/errors, unit reconciliation, period/campus mismatches, department selection, reused subject codes, scope boundaries, forged foreign-period filters, stale previews, the upload wizard and the subject-based roster action. Production assets built successfully. The database migration is applied to the running app, and the post-change backup passed archive integrity verification.

Private local QA evidence is kept under `storage/app/qa/` and logs under `storage/logs/`; student records and source workbooks are not committed to Git.

## Course automation follow-up — 24 October 2026

The already-uploaded Balilihan first-semester 2026–2027 enrolments were reviewed and synchronized after taking a backup. **125 missing courses were created**, **1 existing course matched**, and a repeat preview proposed **0 new courses**. Evaluation-submission and evaluator-roster counts stayed unchanged; SQLite integrity and foreign-key checks passed.

Four department/code combinations require a human decision because the source lists different titles or units: **CCIS / GE CW**, **CCIS / PROF ELEC 2**, **CTAS / NSTP 1**, and **CTAS / FPST 5**. No course was guessed or overwritten for these conflicts. The full follow-up suite passed **460 tests / 2,270 assertions**, including variable-width CSV subjects, repeat imports, normalized codes, conflicting units/titles, inactive courses, permission/scope boundaries, stale reviews, the sync wizard, preserved results/rosters, and matching evaluator suggestions.
