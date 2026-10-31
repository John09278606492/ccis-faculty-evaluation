# Assigned data access

Permissions decide which actions a user may perform. Campus membership and department assignments decide which records staff can read.

- Non-super-admin users require membership in the selected campus.
- A staff member with an instructor department or an assigned department supervisor/chairperson position is limited to those departments in that campus. User-management permissions do not remove this restriction.
- A campus administrator with both `view_any_user` and `update_user`, and no department assignment, can manage the selected campus. Other unassigned staff receive no department data.
- Existing super-admin authority remains unchanged. Ordinary students retain the existing enrollment and own-account access checks.
- Academic students have an explicit home department and multiple section memberships. Department visibility includes that home department and relevant enrolled course offerings. Visiting faculty are included for offerings in the authorized teaching department, without exposing their offerings elsewhere.

`AssignedDataAccess` supplies the shared query rules. Persistent authenticated panel middleware applies them to tables, record lookups, model-based filters and aggregates, including Livewire requests. Report and signed individual-copy endpoints apply the same checks explicitly and recheck access when reopened. Maintenance commands are unaffected.

Regression coverage: `AssignedDataAccessTest`, `StudentEvaluationProgressTest`, `IndividualEvaluationCopiesTest`, `IndividualFacultyReportTest`.
