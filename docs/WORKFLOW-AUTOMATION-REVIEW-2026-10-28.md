# Evaluation workflow automation review

The main setup problem was repeated selection: administrators saw a long list of
class chips and had to find the same faculty member separately for each subject
and section. The changes below reduce that work while keeping campus and
department permissions, individual overrides and historical results.

## Changes delivered

| Process | New approach | Where to use it |
| --- | --- | --- |
| Selecting classes for an evaluation | Include all prepared classes in the selected department and academic period automatically. The draft refreshes its class list when saved or reviewed. | Evaluations → Create or Manage → Faculty and Classes |
| Selecting particular faculty | Select a faculty member once to include all their prepared subjects and sections. | Include → All prepared classes for selected faculty |
| Including later offerings after opening | Detect new prepared classes in the same department/period, preview faculty, students and supervisors, then add all or selected classes in the background. Existing assignments, responses, schedule and instruments are retained. | Manage an open evaluation → Add Newly Prepared Classes |
| Editing an existing custom class selection | A compact count replaces hundreds of chips. The optional customizer is a searchable checkbox list with a bounded height. Existing selections are retained. | Include → Choose specific classes → Specific classes |
| Re-entering the evaluation name and instruments | Suggest the department/period name and reuse the department’s previously configured Annex A/B choices. A unique available instrument can also be selected automatically. Ambiguous new versions remain a choice. | Choose Department and Academic Period |
| Repeating department setup | Prepare several department evaluation drafts in one background operation. Existing draft or published evaluations are kept. Missing instruments are reported instead of guessed. | Evaluations → Prepare Department Evaluations |
| Finding the same supervisor exception across many classes | Group missing supervisors by faculty. Choose one or more supervisors once for all affected subjects/sections in that evaluation. Existing class choices remain untouched. | Manage or Review & Open → Resolve Missing Supervisors |
| Reusing supervisor choices | Suggest a configured department default or a consistent supervisor set already chosen for that faculty. The evaluator of a department head is not inferred from a job title; choose once for all affected classes. | Resolve Missing Supervisors |
| Closing a scheduled evaluation | The scheduler closes published evaluations whose configured closing time has passed. Blank closing dates continue to mean manual closing. A maintenance heartbeat appears in System Health. | Optional Closes field; campus server scheduler |
| Releasing faculty results | For evaluations managed by a cycle, closing that evaluation controls result release. An old semester end date cannot prematurely release results from a manual-closing evaluation that is still open. | Existing protected faculty results pages |

Automatic class selection excludes inactive classes, missing faculty profiles,
empty rosters, unresolved sections on subjects with multiple instructors, existing
unrelated evaluation/assessment records and duplicate student rosters for the same
faculty and subject. Excluded classes appear with reasons. Manual selection is
still available; opening remains subject to readiness validation.

Publication protects the existing class list. Later prepared offerings are added
only through the explicit Add Newly Prepared Classes preview and confirmation.
That action reuses valid individual supervisors or the saved department default;
self-evaluation and unresolved classes are excluded with reasons. Roster, section,
instrument or supervisor changes after preview require a fresh review. Closing the
evaluation or losing access prevents queued additions. Annex B tasks are created
or reused for the added faculty. No automation opens an evaluation, changes
submitted ratings, merges identities, or grants access to another campus or
department. Existing custom class selections remain custom.

## Automation already present and reviewed

| Area | Existing automation | Remaining operator decision |
| --- | --- | --- |
| Student master list | Reads relevant columns and subject blocks from the original campus report; validates IDs; proposes missing departments and courses. | Confirm the import and resolve conflicting data. |
| Instructor offerings | Creates/reuses faculty profiles, subjects, sections and teaching classes from the offering export. Repeated source rows are linked rather than duplicated. | Resolve ambiguous faculty identities or conflicting offerings. |
| Student evaluators | Exact subject, program and academic-period matching; confirmed section evidence narrows instructor choices. Explicit class rosters are protected. | Supply section evidence when multiple instructors remain possible. |
| Sections | Reusable section uploads, optional name/sex fields, validation against the master list, multiple memberships for irregular students. | Correct inconsistent source section headings and unknown IDs. |
| Department supervisors | Background bulk fill of missing supervisors; current individual choices and multiple supervisors are retained. | Select the department default; confirm alternatives for self-evaluation cases. |
| Supervisor tasks | Annex B tasks are prepared from actual faculty-specific delegations; submitted Annex B results feed SEF reporting. | Choose the authorized evaluators. A role name alone does not assign an evaluation. |
| Student access | Student ID identifies available assigned evaluations; evaluation PIN approval is not required. | Campus staff maintain a trustworthy student master list. |
| Progress | Completion and remaining work are calculated by student and section within the viewer’s assigned scope. Nonresponses do not become zero ratings. | Decide who to follow up with. |
| Reports | SET/SEF distinction, section grouping, anonymous tally, raw/average totals, configured signatories and selected/bulk individual copies. | Select reporting scope and whether authorized identity disclosure is needed. |
| Deleted students | Active lists/sign-in can be removed while submitted evaluations and historical reports remain. | Decide whether a student should be removed. |
| Background work | Imports, bulk assignments and exports run outside the upload request; queued/completed/failed results are notified privately. | Correct invalid rows and confirm imports. |
| Notification reliability | Bell actions are persisted and retried so short SQLite writer contention does not lose them. | None for normal operation. |
| Drafts | Private campus/account-scoped working drafts can be restored after navigation. Expired drafts are pruned. | Resume or discard; drafts do not publish themselves. |
| Backups | Scheduled daily backups, retention and integrity checks; recoverable snapshots are kept. | Configure recovery credentials and verify a restore before deployment. |
| Runtime and health | Campus address discovery, worker management, live-data updates, background cleanup and maintenance status. | Ensure the campus host and scheduler are running. |
| Staff import | Column mapping, role/dept selection, employee-ID/email validation and background processing. | Provide actual contact/identity data; imported faculty profiles do not invent credentials. |
| Assessment module | Existing assessment readiness, expiry maintenance, class-record sync and background tasks were checked as adjacent workflows. | Its separate teaching/assessment policy remains unchanged; evaluation changes do not alter exam behavior. |

## Further opportunities, in priority order

These are findings for follow-up, not claims that the features already exist.

| Priority | Opportunity | Proposed behavior and boundary |
| --- | --- | --- |
| High | Refresh draft evaluator rosters after later enrolment imports | Offer a reviewed change summary for untouched automatic draft rosters. Keep custom exclusions and published/submitted rosters fixed. Current enrolment matching intentionally stops when a class enters evaluation setup. |
| High | One import issue inbox | Group invalid IDs, missing enrolments, wrong section headings and unmatched offerings across upload jobs. Download one correction list and retry only corrected records. Keep source row references. |
| High | Faculty profile completion in bulk | Accept a trusted staff directory matched by registrar/employee ID to complete missing email and home-department data. Ambiguous names need confirmation; never infer contact details or merge accounts silently. |
| High | Duplicate faculty review | Show likely old placeholder/new faculty profile pairs together with their source IDs and teaching classes. A privileged reviewer chooses the canonical identity. Existing responses need a preservation plan before any merge. |
| High | Section roster reconciliation | Compare proposed additions/removals across all lists for the same faculty/subject before applying them. Flag contradictory teacher/section claims once, rather than one file at a time. |
| Medium | Exact student cohort assignment across subjects | Reuse a confirmed section roster for its matching subjects while retaining subject-enrolment checks and irregular-student exceptions. Do not assume program/year proves instructor assignment. |
| Medium | Department follow-up digest | Generate a scoped list of sections/students with incomplete evaluations and optional in-system reminders at a configured time. No email/SMS sending without an explicitly configured delivery policy. |
| Medium | Faculty/department report packs | Queue a selected-faculty PDF/report pack with saved report settings and completion notification. Large PDF rendering needs a deployment-supported renderer; browser printing alone is not a background PDF service. |
| Medium | Staff login setup checklist | Detect assigned supervisors without usable staff sign-in details and group them for account completion. Generate temporary credentials only on an explicit administrator action; show them privately. |
| Medium | Academic-period rollover checklist | Reuse configuration and form choices, then request current student/offering imports. Do not promote students or copy last term’s section memberships automatically. |
| Medium | Evaluation-only navigation profile | An explicit deployment setting can hide unused assessment menus while retaining their data and permissions. The earlier request hid assessment entry on login, so this review does not silently disable the whole module. |
| Medium | Draft setup readiness dashboard | Show one department checklist for enrolments, roster conflicts, missing sections, supervisors, sign-in readiness and instruments, with direct in-page corrections. |
| Low | Saved report presets | Store an authorized department/period/report-layout preset, while requiring a fresh permission check on every use and keeping identity visibility off by default. |
| Low | Recovery drill automation | Verify a backup in an isolated copy on demand, and report integrity and restore-readiness. Never overwrite the live database automatically. |

## Decisions that remain deliberate

- Who evaluates a faculty member, especially a dean/head, must be confirmed by an authorized administrator. Suggestions use existing choices/defaults, remain editable and are not applied until confirmation.
- Conflicting student IDs, faculty identities or instructor/section claims must be corrected or reviewed.
- Starting/publishing an evaluation, changing role scope and revealing evaluator identity remain explicit actions.
- Manual closing remains available indefinitely when no closing date is set.

## Verification

Tests cover prepared/faculty/manual class selection, repeated saves, duplicate
rosters, instrument defaults, scoped batch preparation, grouped supervisor jobs,
stale/concurrent supervisor choices, self-evaluation, scheduled/manual closing,
existing supervisor behavior, reporting and navigation. Desktop and phone checks
cover the compact class summary, grouped supervisor form and bulk department modal.
The schema change is additive; a SQLite backup was saved before it was applied.

The principal implementation points are `EvaluationSetupAutomation`,
`EvaluationDraftPreparation`, `SupervisorAssignmentGaps`, `EvaluationCycles`,
`BackgroundTasks`, the Evaluation resource/pages, and `MaintainEvaluations`.

The broad regression run passed 73 tests (474 assertions). After refining scoped
supervisor handling, the focused selection/lifecycle/supervisor run passed 45
tests (276 assertions). Desktop and 390-pixel phone checks completed without page
errors. The live database check verified that existing results, student rosters
and supervisor assignments were unchanged by this automation update.
The final manual-closing result-privacy and reporting checks passed another
28 tests (125 assertions); guide/navigation checks passed 11 tests (107 assertions).
