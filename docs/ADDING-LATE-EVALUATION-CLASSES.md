# Adding prepared offerings after an evaluation opens

Open **Evaluations**, manage the open evaluation, and select **Add Newly Prepared
Classes**. The system detects new offerings in that evaluation’s department and
academic period. You do not have to find each faculty member or subject manually.

The preview selects every eligible new class by default. It shows faculty,
subject, confirmed reporting sections, student count and supervisor choices.
Search or deselect classes if needed, then select **Add Selected Classes**. The
operation runs in the background and reports completion or validation failure
through the notification bell.

Classes need active faculty, assigned student evaluators, and confirmed student
sections for subjects handled by multiple instructors. They also need valid
supervisor evaluators. Existing individual/multiple supervisor choices take
precedence. A missing selection can use the saved department supervisor, clearly
identified in the preview; self-evaluation is excluded. Classes needing attention
are listed by faculty with reasons. Opening the preview again detects offerings
that became ready after correcting their sections or rosters.

The action applies the evaluation’s existing published Annex A, creates or reuses
its Annex B tasks, and retains existing class assignments, student/supervisor
choices, submissions, scores, form versions, publication time and schedule.
Repeated confirmations do not duplicate classes or supervisor tasks. It does not
refresh the rosters of classes already included in the evaluation.

The preview expires after 30 minutes. Changed rosters, sections, subject/faculty
details, supervisors or instruments require a new preview. Permission and
department/campus access are checked again by the worker. Closed, archived or
expired evaluations cannot receive additions. A scheduled evaluation can receive
classes before its opening time; students still wait for that opening time.

Verification covers preservation of existing record values, selected/bulk jobs,
repeat submissions, stale previews, closed evaluations, permission loss,
department isolation, duplicate rosters and visiting faculty with an explicitly
assigned supervisor from another department. Desktop and 390-pixel phone previews
were checked against the campus server without submitting live additions.

The final combined lifecycle, automation, background processing, supervisor,
reporting and guide regression run passed **92 tests (529 assertions)**. The live
CCIS preview detected 11 ready classes; no live class additions were submitted.
