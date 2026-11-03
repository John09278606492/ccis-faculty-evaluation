# Evaluation demonstration — 22 October 2026

Choose **DEMO Presentation Campus** (campus 2). All names, courses and responses below are synthetic. Existing campus records were preserved. This is a demonstration instrument, not an officially validated evaluation form.

## Evaluation access

Students open `/evaluations/start`, enter their student ID and choose their assigned evaluation. No PIN or staff approval is required for evaluations. This evaluation-only session does not grant access to quizzes, exams, activities or staff pages.

## Staff sign-in

Use the running campus server's printed URL, currently `http://192.168.0.101:8020/login`. Choose **Instructor or admin? Sign in with a password** for staff accounts.

| Purpose | Account | Password / PIN |
|---|---|---|
| Manage assignments and approve students | Existing `admin@ccis.test` | Existing `CodexTest!2026` |
| Read-only dean demonstration | `demo.dean@ccis.test` | `DemoEval!2026` |
| Read-only chairperson demonstration | `demo.chair@ccis.test` | `DemoEval!2026` |
| Faculty demonstration | `demo.faculty1@ccis.test` (also faculty2/faculty3) | `DemoEval!2026` |
| Student with pending evaluations | ID `DEMO-2026-011` | Student ID only at `/evaluations/start` |
| Another student with pending evaluations | ID `DEMO-2026-012` | Student ID only at `/evaluations/start` |

The dean/chair demo accounts share a read-only reviewer role within the demo campus. These are demonstration accounts, not a final policy for real dean/chairperson responsibilities. PINs and sign-in approval are assessment-only controls. Faculty results remain held while an evaluation is open, as required by the existing release/privacy workflow.

## Five-minute walkthrough

1. **Evaluation Assignments:** show the three DEMO faculty assignments and the 12 enrolled evaluators per assignment.
2. **Evaluation Forms:** show the DEMO form's 12 rating criteria grouped into Preparation, Instruction, Assessment and Learning Environment.
3. **Evaluators:** show the assigned student list. Open `/evaluations/start` in another browser/device and enter `DEMO-2026-012`; choose an assigned faculty evaluation directly.
4. **Submit:** use `DEMO-2026-011` for a fresh submission; it has not submitted any demo evaluation. Show required answers, submission confirmation and duplicate-submission protection.
5. **Reports:** log in as the demo dean/reviewer. Show evaluation results, section/field breakdowns and department summaries. Seeded averages are 4.50, 4.00 and 3.00; response counts are 8, 6 and 4. Completion is 66.7%, 50% and 33.3%, respectively. New demo submissions naturally change those figures.

There are 2 departments, 3 faculty, 12 students, 3 evaluation assignments and 18 initial anonymous synthetic responses. No assessment/quiz data is required for this walkthrough.

## Review findings and changes

- Completion previously divided all responses by enrollment, allowing results above 100%. It now counts each enrolled respondent once using the private fingerprint (with legacy account-ID support), without attaching identities to anonymous responses. Department completion uses the same completed-student counts.
- Evaluatee summaries previously included submissions from an older form after the assignment's form was changed. Counts and scores now use the current assigned form.
- The evaluation results page previously loaded an instructor's scores/course details across all campuses. Counts, scores and the detail modal now use only the currently selected campus and current assigned forms. A cross-campus regression test verifies the other campus is excluded.
- The main database service was stopped when this review began; it was restarted using its existing C:\laragon data, and the campus web server was restarted.
- Evaluation/access/report tests: 75 passed, 194 assertions, including the new reporting and campus-scoping regressions. Demo records were checked against the real MySQL database, and seeding was rerun without duplicate records. The demo dean login and evaluation results page were verified in the browser.

Regenerate/refresh this demo only: `php artisan db:seed --class=DemoEvaluationSeeder --force`. It refreshes its explicitly tagged synthetic responses and preserves account passwords already changed after creation. Do not use demo credentials or this seeder for real faculty evaluation records.
# Evaluator assignment permissions

In **Roles & Permissions**, open the role and find the **Evaluation Assignments / Course Offering** permissions. Enable **Manage Evaluators** (`manage_evaluators_course::offering`), together with the usual View and Update permissions, for staff who should assign evaluators. Save the role and assign it to the responsible staff account.

This permission controls individual additions/removals, bulk additions/removals and CSV/Excel ID-list imports. Being the evaluated instructor or managing user accounts does not grant this permission. Staff can manage only assignments in an accessible campus, and only students belonging to that campus can be added. Students cannot manage evaluator rosters.

Existing super-admins receive the permission. Other roles must be explicitly enabled; the demo dean and chairperson remain read-only. Evaluation entry itself continues to use student IDs without PIN or approval.
