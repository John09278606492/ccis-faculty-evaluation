# Import instructor offerings

Use **Teaching Classes → Import Offering File** with the original campus Excel
(`.xlsx` or `.xls`) or CSV file. Keep the export intact; no manual column filtering,
reordering or conversion is required. Headers are detected in the first 50 rows.
Only recognized offering fields are read; unrelated columns/worksheets, blank
rows and repeated headings are ignored. The selected school year and semester
filter historical rows automatically. Required-field problems remain visible in
the review, with worksheet and source-row references. Unreadable period rows are
excluded and listed in the downloadable review.
Choose the academic period and, optionally, teaching departments. Select **Check
File**, continue working, then open the notification or **Review Uploads**. Review
the proposed changes and use **Import Ready Classes**. Classes needing attention
are excluded and included in the downloadable review.

The optional **Download example CSV** in the upload window provides the
required headers and two fictional sample rows for the selected academic period
and source campus ID. Open it in Excel, replace the samples with actual data,
keep the headers, and save as CSV UTF-8. Use the same source faculty ID when one
instructor teaches multiple subjects or sections. The original campus export is
still accepted with its additional metadata columns.

Upload students and import instructor offerings in either order. Clear subject
matches are assigned automatically by default. For subjects with multiple
instructors, use **Section students** on the Teaching Classes list to choose the
instructor, create/select the section and assign enrolled students in one modal.
Sectioning is required for these subjects; the system does not guess the teacher.
Select supervisors,
then review and open the evaluation. See [enrolment matching](ENROLMENT-EVALUATOR-MATCHING.md).

## Source mapping

| Export field | Used for |
| --- | --- |
| `sy`, `sem` | Match the selected school year and actual semester. Other periods are excluded. |
| `course_code`, `course_desc`, `units` | Reuse matching subjects or create missing subjects when permitted. Conflicting catalogue entries are retained for attention. |
| `id`, `first_name`, `last_name` | Link source faculty identities to campus faculty profiles. Source IDs are stored separately from local user IDs and employee IDs. |
| `section` | Match the exact saved department/semester section, allowing case and whitespace normalization. New sections start empty. |
| `code`, `name` | Identify the degree program and suggest its teaching department. Explicit program matches can override the suggestion within authorized departments. |
| `campus_id` | Validate the source export campus, not a local database primary key. Balilihan's supplied export uses 6. |
| `year`, `sem-2`, lecture/lab units, program ID, grading system, source timestamps and editor | Retained as source metadata. Curriculum semester is not used as the actual offering semester; source timestamps do not replace local audit timestamps. |

Computer Science and Information Technology programs map to CCIS, Criminology to
CCJ, and Industrial/Electrical/Electronics Technology to CTAS. Unknown programs need
an explicit match. Imports cannot expand the uploader's campus or department access.
Missing departments require campus-wide access and department-creation permission.

## Faculty and rosters

Existing source links are authoritative. Otherwise, only a unique exact
normalized name match is used, including an existing middle name when the export includes it in the given name. **Optional Matches** lets an authorized
uploader select an existing same-campus instructor when names differ. Ambiguous,
deleted or student accounts cannot be silently reused.

Missing faculty can be created only with `create_user` permission. These are
teaching faculty profiles with no email, unknown home department and an unknown
random password. Complete the email and home department under **Staff Accounts**,
then use **Issue Temporary Password** when staff sign-in is needed. The **Sign-in
Details → Needs Email** filter finds unfinished profiles. No contact details or
home departments are invented. Teaching in another department does not change an
existing instructor's home department.

Exact enrolled subject code/title and academic period identify potential evaluators.
A clear instructor match can be assigned before sections are available. Multiple
instructors require confirmed section evidence or explicit same-page sectioning.
No student membership is inferred from degree program or year alone.
Confirmed section CSV uploads update imported class rosters automatically when
**Follow confirmed section uploads** is enabled. This applies only before a form
or evaluation is assigned and before responses or assessment records exist.
Customizing evaluators or manually attaching/detaching a student stops automatic
changes for that class. Changing only the supervisors keeps automatic section updates enabled. Individual exceptions remain available. Supervisors are selected
explicitly, and importing never opens an evaluation or assigns Annex A/B forms.

Existing matching classes, rosters, supervisor choices and submitted responses are
retained. Source links make repeat uploads idempotent. Duplicate source
rows for the same class are combined. Conflicting subject variants or different
instructors for the same subject/section require attention.

## Background validation

Reviews and imports use the bulk background worker and private bell notifications.
The original upload remains unchanged. A review stores a frozen plan on the server;
confirmation checks fresh permissions, scope, subjects, identities, sections and
rosters before writing in a transaction. A stale review must be prepared again.
The database has a backup made before the email-nullability/source-link migration.

## Verification with the supplied export

First semester 2026–2027 contains 352 source rows. Twelve repeated class rows combine
into existing review groups: 324 classes are ready and 16 need attention. The live
database now contains the confirmed 324 classes and 56 linked faculty profiles.

In an isolated database copy, confirmation created 324 classes, 56 faculty profiles
and 5,085 section-roster memberships. Repeating the same file created no additional
classes, faculty or roster memberships. Classes with empty/new sections remain
awaiting their student lists in that earlier test. The two imports and their reviews took 30–43 seconds
combined on this computer, which is why they run in the background.

The subsequent automatic enrolment backfill created 6,176 evaluator assignments
across 79 live classes. A fresh read-only plan proposed zero additional assignments.
Another 3,315 student/subject matches require section/instructor configuration.
FPST OJT 1 matched 103 students; CLJ 6 requires sectioning for its 267 enrolled
students because multiple instructors teach it. The existing two submitted
evaluations were verified unchanged against pre-backfill hashes. A SQLite backup
was taken before applying the backfill.
