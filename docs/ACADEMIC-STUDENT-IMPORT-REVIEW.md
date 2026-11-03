# Academic student import review

Open Students > Upload Students. The flow is Upload and select period > Review import > Import Reviewed Students.

The academic period is explicit and defaults to the campus period selected by the operator. The file has student_id, first_name, last_name, department_code; email, middle_name and sections are optional. Separate multiple sections with a pipe. Download Example CSV supplies the current format.

Review performs no student/account/membership writes. It shows total rows, distinct new students, distinct existing students, repeated ID rows and rows with errors. The first 50 error rows are displayed with source spreadsheet row numbers. Invalid plans cannot commit; correct the file and review again. Repeated IDs with consistent identity details merge irregular section memberships, while conflicting names/email/department or duplicated emails on different IDs are reported.

Parsed rows remain server-side in a private 30-minute cache plan bound to the original user, campus and academic period. Temporary files are removed immediately after parsing. Going back can review the cached rows again for another valid period without depending on an already deleted file. The final confirmation rechecks all three dedicated academic permissions, current department access, record state and period membership. Changed existing records require another review. Another user or campus cannot reuse the token. Successful commits consume the token and repeated clicks cannot import it again.

Writes remain atomic through AcademicStudents::import. An outer transaction rechecks and locks existing ID matches before final import. Passwords and existing roles are preserved; only memberships in the selected period are updated.

Regression tests: AcademicStudentImportPreviewTest plus the updated upload workflow in AcademicStudentsTest.
