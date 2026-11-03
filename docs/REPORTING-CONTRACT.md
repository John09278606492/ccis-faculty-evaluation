# Reporting contract

Faculty Results is the canonical reporting workspace. Legacy Evaluation Reports bookmarks redirect there.

## Scope

Visible semester, teaching department, evaluation cycle, instrument version and evaluation-type filters scope the same assignments used for counts, ratings, tally drill-downs, Annex C/D previews and filtered CSV exports. The semester defaults to the shared selected academic period. Teaching department uses explicit assignment teaching_department_id, then course department, then faculty home department for general assignments. Visiting faculty are counted in the department where they teach.

## Score units and completeness

Every numeric rating criterion must contain a numeric value within the instrument scale bounds. Missing or invalid numeric criteria make that response incomplete; incomplete responses contribute no rating and never count as zero.

The central EvaluationScoring service returns raw total and maximum, percentage /100, and normalized mean /5. Sum-configured forms still report a normalized /5 mean; the raw sum remains separately labeled. Configured section weights are applied as weighted section percentages. Fields and rating scales come from saved instrument snapshots when available.

Assignment percentages are aggregated by complete respondent count, retaining full precision until display. SET, SEF and other instruments are reported separately. No unapproved SET/SEF combined rating is generated. Current assignment versions are used; old versions are not silently pooled into current summaries. Archived immutable snapshots remain available through response copies.

## Completion and privacy

Student completion counts unique enrolled students matched by the private fingerprint or legacy account reference for the current instrument. Duplicate legacy responses do not inflate completion. Supervisor tasks are excluded from the student completion denominator.

The faculty member receiving results must wait until the evaluation closes and the required number of complete responses exists (configured minimum for students; one for an Annex B supervisor task). Other reviewers require reporting permission plus assigned campus/department access. Response drill-downs omit identity and submission time; authorized identity retrieval uses the separate controlled copy workflow.

## Tests

ReportingConsistencyTest verifies units, completeness, weighted respondent aggregation, SET/SEF separation, visiting and no-course supervisor departments, filtered CSV output, and unique dashboard completion. EvaluationResultsReleaseTest verifies release thresholds and scope. ReportServiceTest verifies shared summary and breakdown behavior.
