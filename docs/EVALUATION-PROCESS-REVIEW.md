# Evaluation process review — 25 October 2026

## Scope and evidence

Reviewed the faculty evaluation path: account scope, master-list/section validation,
teaching classes, evaluator assignment, saved drafts, scheduling/publication,
student Annex A, supervisor Annex B, results, exports, individual copies, release,
closure and retained history. The full automated suite also covers shared sign-in,
background jobs, notifications and assessments. Test records are isolated; no
submitted answers were invented in the live campus database.

The new integration scenario follows a campus administrator, department dean,
chairperson, an additional supervisor from another department, the faculty member
and two students. The dean and visiting supervisor are explicitly selected. The chairperson is not
selected and cannot answer Annex B. One student deliberately does not submit.
Student answers are all 4; the primary supervisor answers 5; the additional
supervisor answers 3. Expected SET = 80%; expected SEF = (100% + 60%) / 2 = 80%.
The pending responses do not become zeros. SET and SEF remain separate ratings.

## Problems corrected

| Finding | Corrected behavior |
| --- | --- |
| Department leadership could automatically confer Annex B evaluator authority. | Only per-faculty supervisor selections authorize tasks and responses. Deans, chairpersons and administrators must be explicitly selected. Publication checks each teaching class. Leadership settings continue to control organizational access and report signatories. |
| Selecting an Annex A form version could hide completed Annex B results in Annex C/D. | The faculty report includes the paired instrument from the same faculty, semester, teaching department and evaluation setup. Another setup is not silently mixed in. Scores/Tally retain their exact type/version filters. |
| Deactivating a teaching class could remove its completed evaluations from reports. | Inactive assignments with submitted history remain reportable. Inactive empty classes stay out. Submission availability is still checked independently. |
| Changing a draft class's faculty, subject or academic period could carry evaluator choices onto another assignment. | Before evaluations are prepared, an identity change clears the old student/supervisor roster and instrument assignment. Once a cycle, supervisor task, submitted response or assessment is present, create a new class instead. Descriptive label edits remain possible. |
| Annex C used the optional class reference instead of the saved section selection. | It displays the confirmed assigned sections. Combined sections remain one combined roster row; they are not guessed from students' degree programs. |
| Additional supervisor delegation could also satisfy a general report-scope check. | The delegate may answer their assigned Annex B task. Reading departmental reports still requires normal reporting permission and department access. |
| Faculty report HTML lacked the cache restriction already used by tabular reports and copies. | Annex C/D now return private, no-store responses. |

## Rules verified

- **Setup and scope:** permissions do not grant another campus/department by
  themselves. A supervisor chosen for one faculty is not promoted to a department
  head. Foreign-campus staff, students and self-evaluation are rejected.
- **Roster:** section students must exist in the master list. Multiple sections and
  individual exceptions are explicit. Student/supervisor changes save together;
  unfinished choices remain in a private draft. A stale draft cannot overwrite a
  later supervisor assignment.
- **Publication:** draft review and opening are separate actions. Missing forms,
  criteria, evaluators, valid supervisors or schedule prevent publication.
  Scheduled evaluations prepare tasks without accepting responses early.
- **Evaluator authority:** unselected deans, chairpersons and administrators have
  no supervisor tasks and cannot submit Annex B. Removing even a configured dean
  from the selection revokes evaluator access while retaining previous responses.
- **Responses:** each explicitly selected evaluator submits once per assignment. A primary and an
  additional supervisor produce independent Annex B responses. Incomplete
  responses are excluded from numerical scoring; nonrespondents are not zeros.
- **Reports:** primary and additional supervisor scores and comments reach Annex
  C/D. Anonymous supervisor rows reach Scores/Tally. Their completed-response
  counts reach the CSV export and selected/bulk Annex B copies.
- **Identity:** ordinary reports and printable copies hide respondent identity by
  default. Revealing an individual copy requires the dedicated permission.
  Prepared/reviewed names identify report signatories, not a student response.
- **History and release:** student soft deletion retains submitted answers,
  comments, scores and printable history. Faculty results follow closure and
  minimum-response rules. Closing/archiving stops new responses without deleting
  historical results.
- **Bulk work:** import/export tasks retain permission/scope checks, use private
  downloads and report completion through the existing background-task path.

## Reproduction and receipts

Final validation after the explicit-supervisor revision: **587 tests passed,
3,247 assertions**. The focused supervisor/report/draft checks passed 46 tests
with 406 assertions. The assignment browser check verified desktop and phone
layouts, saving selected supervisors and no JavaScript or server errors. The five report browser
checks passed with SET 80%, SEF 80%, two anonymous supervisor tally rows, two
separate Annex B copies, hidden print controls and no JavaScript errors.

- `tests/Feature/EvaluationProcessReviewTest.php`: complete supervisor-to-report
  path, exact arithmetic, comments, CSV, copies, form filtering, deletion/history,
  release and delegated-supervisor reporting restrictions.
- `tests/Feature/TeachingClassesTest.php`: identity-change reset and protection
  after supervisor task preparation.
- `tests/Feature/FacultySupervisorEvaluatorsTest.php`: assignment scope, duplicate
  submissions, removal, schedule, saved drafts and stale-draft rejection.
- Full-suite log: `storage/logs/explicit-supervisors-release.txt`.
- Report browser receipt:
  `storage/app/maintenance/evaluation-review-browser-receipt.json`.

The browser check uses reports generated from the isolated integration scenario.
It checks the displayed values and print styles; it is not a physical-printer
test. This review does not claim a new 1,000-user simultaneous campus-network
benchmark or verification of a future school-server deployment.
