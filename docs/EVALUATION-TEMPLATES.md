# Faculty evaluation templates

The supplied Annex A, Annex B and Annex D documents are now represented in the application. Annex A contains 15 SET criteria in three sections. Annex B contains 15 SEF criteria with the suggested means of verification. The original 1–5 manifested-behaviour scale is retained. Annex D provides the faculty information, separate SET/SEF summaries, development plan and signature spaces.

## Forms

Open **Evaluations → Evaluation Forms**. Prepared Annex A/B drafts are available for each existing campus. **Use Annex Template** creates another editable draft. **Duplicate** copies the form, fields, scale, report category and department restrictions into an independent inactive draft. It copies no responses and clears schedule and submission limits.

Use **Edit** for settings and **Build** for criteria. Set **Summary report category** to SET, SEF or Other so the acknowledgement report classifies it correctly. Activate the reviewed form, then select it in an evaluation assignment. Use Evaluation Setup to pair Annex A and Annex B, choose the teaching classes and publish. Select supervisor evaluators on each teaching class before publication. Supervisors use their staff login and the evaluation assignment link. Student evaluation continues to use ID-only entry without PIN approval.

**Preview** shows the electronic evaluator layout. **Print Instrument** opens a blank paper instrument with a Print / Save PDF button. Copies retain the template layout while using their own edited criteria and maximum possible score.

## Organizing questions in the builder

The left-hand Form Outline lists each section and its question count. Use Add Section, click its name to edit it, and save. Use Add Question inside a section to place a question there; palette clicks also use the selected section. To move a question, open it, choose Section / Move Question, and Save Changes. Section arrows move the whole group with its questions. Move questions out before deleting a section; a non-empty section cannot be deleted accidentally. Imported template sections are converted to visible headers without changing question identifiers. Changing wording keeps existing answer identifiers; internal identifiers remain under Advanced.

## Viewing reports

### Department supervisors and visiting faculty

In **Academic Management → Departments → Edit**, Department Leadership sets the
Dean / Department Head and Chairperson for organizational access and report
signatories. It does not authorize them to evaluate every faculty member.

Use **Teaching Classes → Evaluators → Assign Evaluators by Section → Supervisor
evaluators** to select who will complete Annex B for that faculty. Deans and
chairpersons must also be selected explicitly. Same-campus staff may be chosen
for visiting faculty without granting department-wide student or report access.
Student accounts, foreign-campus staff and self-evaluation are rejected.

**Evaluation Setup** prepares one Annex B task per faculty, teaching department,
academic period and evaluation setup on publication. A supervisor can also be
selected on an already published teaching class. Page visits never create tasks.
Only selected supervisors see **My Supervisor Tasks** and can answer Annex B.
Removing a selected supervisor immediately stops their evaluator access, while
retaining submitted responses. Department leadership changes do not change these
explicit choices. Multiple selected supervisors submit independent responses;
completed responses supply the SEF rating and anonymous Supervisor tally rows.

Each **Evaluation Assignment** now has an **Evaluation Type**. Leave it automatic to inherit the selected form's category, or explicitly choose Student (SET), Supervisor (SEF), Faculty/Peer, Self or Other. The assignment choice takes precedence in the evaluator page, assignment list, results details and printable summary. This describes who evaluates the faculty member; it is separate from the evaluatee's job title and does not change access permissions. Unclassified older assignments show Other / Unspecified until configured. The printable summary groups responses and percentages by this assignment type.

Older forms restricted exclusively to the student role automatically resolve to Student Evaluation when no category has been configured. Mixed or unspecified roles remain unclassified. The Results list leaves the combined score blank when multiple evaluation types are present; use the separate type summary instead.

Open **Reports → Evaluation Results → Report / Print** beside a faculty member. The report opens in a modal within the current page. Choose Annex C (the default individual faculty report) or Annex D (development acknowledgement), then select the reporting period. Use Print / Save PDF inside the preview; only the report frame is printed, without the surrounding navigation or modal. Form previews, print-instrument previews and the builder preview also open in system modals.

Annex C follows the supplied Individual Faculty Evaluation Report: faculty information, course/year/section rows, complete student-response counts, average SET percentages, weighted SET scores, SET/SEF rating summary, anonymous qualitative comments, and prepared/reviewed signature spaces. Weighted SET score is response count × average SET percentage; the summary divides the total weighted score by total complete responses. The completed report photographs clarify that OVERALL RATING is a row label with separate SET and SEF results, not a third combined score. The sample 22,476.65 / 237 rounds to 94.84. Computation retains full precision until display. Signature blocks appear vertically, as in the sample; sample identities and handwritten signatures are not imported. Annex C metadata and Annex D development-plan fields are saved together without replacing one another.

Results use current assigned forms in the selected campus, faculty and semester. Only complete rating responses contribute. Rating = total score / maximum possible score × 100; the unchanged 15-item instruments have a maximum of 75. SET and SEF are shown separately and each category is weighted by its complete response count. Missing results remain unavailable; there is no invented combined weighting. Other forms are listed in the detailed summary without being relabelled as official SET or SEF.

Reviewers need the Evaluation Results page permission and campus access. Saving rank, development plan and signature-name/date fields additionally requires Update Course Offering permission. Save before printing; signatures are completed on paper. Individual evaluator identities are excluded from the report.

The supplied documents' repeated Annex A copy was imported once. These are web layouts based on the supplied content, rather than Word pagination replicas. Existing assignments and submitted evaluations are preserved.

For a new installation, run migrations and `php artisan evaluation:prepare-templates`. This command creates missing campus drafts without overwriting existing forms.
