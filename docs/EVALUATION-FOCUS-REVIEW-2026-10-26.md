# Evaluation-focused review — 26 October 2026

## Login change

The student tab now offers faculty evaluation only. Its assessment ID/PIN form,
assessment instructions and approval polling are not rendered. Faculty & staff
retains password sign-in and configured staff authentication options. The student
evaluation entry page links back to Faculty & staff sign-in without mentioning
assessments. Assessment backend records and routes have not been removed.

The BISU seal, entrance photo, purple theme and light presentation are retained.
Browser checks at 320, 390, 768, 1024 and 1440 pixels verified both access tabs,
the correct evaluation destination, hidden assessment controls, loaded images and
no horizontal overflow. Devices preferring dark mode still receive the light login.

## Reproduced issues and corrections

| Issue | Reproduction | Correction |
| --- | --- | --- |
| An old ID-only student session overrode a signed-in supervisor. | Leave an evaluation student ID in the session, authenticate as an explicitly assigned supervisor and open Annex B. The request returned 403 because it resolved to the student. | An authenticated account takes precedence over the ID-only evaluation session. Successful staff password sign-in clears that old session. The regression verifies successful Annex B submission and the supervisor's encrypted identity, with no student response created. |
| Staff sign-in trusted only a profile flag when identifying student accounts. | A test account retained the student role while `is_student` was false. It could pass staff password authentication. | Staff sign-in uses `isStudent()`, which checks both the profile flag and student role. The inconsistent account is denied. |
| Printable-copy authorization had the same flag-only check. | An authorized test staff account acquired the student role while retaining report permissions and its old false flag. It could still create print selections. | Copy authorization uses the same student-role check. New selections and previously created links are denied after the role change. |

The inconsistent accounts above are deliberately constructed test fixtures, not
claims that such accounts exist in the live campus database.

## Evaluation paths checked

- ID-only student access, session expiry, enrollment rechecks and duplicate
  submission prevention; this entry does not authenticate the student into staff
  or assessment pages.
- Campus and department visibility, preview/report permissions, explicit
  per-faculty supervisor selection and rejection of unrelated supervisors.
- Draft preparation, publication, opening/closing schedules and result release.
- Complete-response scoring, separate SET and SEF percentages, anonymous tally
  rows, response totals and exclusion of incomplete answers/nonrespondents from
  numerical averages.
- Annex C/D pairing and filters, Scores/Tally, selected/bulk Annex A/B copies,
  identity-reveal authorization and hidden print controls.
- Retention of completed evaluation results after student deletion and teaching
  class deactivation; no live database reset, reseeding or fabricated submission.

## Validation receipts

- Full-suite run: **597 passed, 3,293 assertions**, approximately 331 seconds.
  Log: `storage/logs/evaluation-focus-full-review.txt`.
- After the final role checks, targeted sign-in, entry, report-copy and complete
  evaluation-flow tests: **30 passed, 249 assertions**. This includes the two newly
  added inconsistent-role cases discovered after the full run started.
  Log: `storage/logs/evaluation-focus-security-final.txt`.
- The supervisor-session regression failed with 403 before the fix and passed
  afterward. Logs: `evaluation-focus-session-before.txt` and
  `evaluation-focus-session-after.txt` under `storage/logs/`.
- Both inconsistent-role cases failed before correction:
  `storage/logs/evaluation-focus-role-before.txt`.
- Five report browser checks used isolated synthetic responses: SET **80%**, SEF
  **80%**, two anonymous Supervisor rows and two separate supervisor copies.
  Receipt: `storage/app/maintenance/evaluation-review-browser-receipt.json`.
- Login browser receipt: `storage/app/maintenance/bisu-login-browser-receipt.json`.

## Read-only live setup check

The current published CCIS sample evaluation has one teaching class, five student
assignments and one explicitly selected supervisor. Its readiness check returned
no setup findings. There are two existing submitted responses, and SQLite's
foreign-key check returned no violations. Those records were not changed.

This review is not a new concurrent 1,000-user load benchmark, a physical-printer
test or certification of a future school-server deployment.
