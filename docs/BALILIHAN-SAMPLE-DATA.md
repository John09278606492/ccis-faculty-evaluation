# Balilihan sample evaluation data

Choose **Balilihan Campus** in the campus selector. Departments are **CCJ**, **CTAS**, and **CCIS**, using the codes supplied by the user without assuming their expanded names. Existing campuses and records remain separate.

Each department has one sample faculty, one sample immediate head, ten sample students, two sample courses with SET assignments, and one SEF assignment. Eight students per department have completed both course evaluations. Students 009 and 010 have pending evaluations. There are nine assignments and 48 synthetic SET responses. Supervisor Annex B evaluations start pending; an actual staff submission supplies the SEF score and comments. The three previously generated SEF sample responses were backed up and reset to demonstrate this flow. Sample forms, course names, comments and development plans are labelled SAMPLE.

## Accounts

All newly created sample staff accounts use password `BalilihanDemo!2026`:

- `sample.dean@balilihan.test` — campus report reviewer.
- `sample.head.ccj@balilihan.test`, `sample.head.ctas@balilihan.test`, `sample.head.ccis@balilihan.test` — sample heads/reviewers.
- `sample.faculty.ccj@balilihan.test`, `sample.faculty.ctas@balilihan.test`, `sample.faculty.ccis@balilihan.test` — sample faculty.

The existing super-admin account can access this campus and manage assignments. Reviewer accounts cannot change evaluator rosters.

Historical sample leadership accounts were configured as Supervisor and Chairperson. Under the current policy those positions do not grant Annex B evaluator authority. Select supervisors per faculty in **Teaching Classes → Evaluators → Assign Evaluators by Section**. Only explicitly selected accounts use **My Supervisor Tasks**. This document describes older demonstration data; it is not an instruction to reseed the current campus database.

Student evaluation uses ID-only entry at `/evaluations/start`; no password or PIN is required. Pending student IDs include `BAL-CCJ-2026-009`, `BAL-CTAS-2026-009` and `BAL-CCIS-2026-009`. Each sees only their department's two enrolled course assignments.

Open **Reports → Evaluation Results**, filter by department, and choose **Report / Print** for Annex C/D. Prepared names and development-plan text are synthetic. Supervisor submission data demonstrates Annex B reporting; it does not add an enforced immediate-head approval workflow.

Run `php artisan db:seed --class=BalilihanEvaluationSeeder` to prepare this dataset. Repeated runs preserve existing accounts, passwords, forms and responses without duplicating sample rows.
