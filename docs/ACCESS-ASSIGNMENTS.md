# Access and assignments

Roles decide actions. Campus membership and department assignments decide records. User-management permissions never imply campus-wide authority.

`User Management > Access & Assignments` requires `manage_access_assignments`. Campus-wide data access is an explicit `user_campus_access` record; department access includes instructor home department, configured supervisor/chairperson departments, and explicit `user_department_access` records. Campus data scope does not grant write permissions. Removing a profile department cannot grant campus access. Self-elevation and cross-campus assignments are blocked; changes are recorded in `access_audit_events`.

Role delegation requires `assign_user_roles`. Non-super-admin users cannot change their own roles, grant permissions they do not possess, modify super administrators, or remove global/other-campus roles. Higher-authority staff accounts cannot be edited or have passwords reset by lower-authority account managers. Student accounts accept student-safe roles only; incompatible legacy student/staff accounts are blocked from the panel until corrected.

Academic permissions are independent: `view_academic_students`, `manage_academic_students`, `import_academic_students`. The student import entry is Academic Management > Students. Old queued importers recheck the initiating actor, campus, department, profile, and existing account before each row. Another campus's account is never adopted by an ID/email match. Existing roles are preserved unless explicitly and validly delegated. Imported staff receive random unknown passwords; use Users > Issue Temporary Password to provide a unique credential privately. It is displayed once, must change at first sign-in, and is never written to the audit log.

Direct form preview/print applies the same department checks as panel data. Students cannot use staff preview endpoints. Legacy submission tables/modal views hide names and IPs; protected identity retrieval remains in the authorized copy workflow. Submitted responses have a policy denying mutation/deletion and no delete buttons in form editing. Source-form and assignment delete protection must also prevent cascades.

Migration `2026_10_22_000011_add_explicit_access_assignments` is idempotent after partial DDL creation. It preserves known demo dean campus read scope and named campus admin roles; it does not infer campus authority from generic user permissions.
