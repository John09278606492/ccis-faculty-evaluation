# Match enrolled students, then assign report sections

Import the student masterlist and instructor offerings in either order. With
**Automatically assign enrolled students** enabled (the default), the background
import links exact subject enrolments to a clear instructor match. Existing manual
choices and classes already prepared for evaluation are preserved.

Open **Teaching Classes → Section students** for any class needing sectioning.
Subjects handled by multiple instructors appear first. Choose the instructor and
section, then select enrolled students or upload that section's CSV/Excel ID list.
Create a missing section with **+** in the same modal. Unknown IDs, mismatched
names and students not enrolled in the subject are rejected without partial changes.
Validation and assignment run in the background; the result appears in the same
modal and in the notification bell. Use **Assign another section** to continue
without leaving the page. The full validation report is downloadable when needed.

For a manual override, open **Edit → Evaluators → Match Enrolled Students**.
The match uses the selected campus and academic period, exact normalized subject
code/title, and the instructor/class you opened. It respects the operator's
department access. Degree programs can narrow the candidates.

Clear class matches are preselected. When several teachers or section classes
fit, staff explicitly select the students who attend the chosen class. Existing
assignments to another class of the same faculty/subject/cycle are excluded to
avoid duplicate evaluations. The server rechecks enrolments, scope, class choices
and roster state before committing a reviewed selection. No ratings are created
by this process. Supervisors can be selected separately without student sections.

For subjects with one instructor, students can evaluate after being assigned even
when their section is unknown, provided the evaluation is open. Their responses
carry **Section pending**. When that instructor has several imported section rows,
unknown sections share one faculty/subject roster to avoid duplicate evaluations.
For subjects with multiple instructors, sectioning is mandatory before opening
the evaluation or allowing the student to evaluate. Program/year alone cannot
choose the teacher. Unrelated section membership does not satisfy this check.
In the class evaluator table, select students and choose the bulk action
**Assign Section**. This adds a membership in an existing authorized section for
the same academic period, without removing memberships in other sections.
Select students from the section's department together.

Section assignment also fills pending section labels on their submitted responses
using the existing private submission fingerprint. Names/IDs are not added to
reports. Scores, comments, submission dates and already known historical section
labels are preserved. Classification records the responsible staff and timestamp
inside private response metadata. Later general roster changes do not silently
rewrite a recorded section.

Annex C separates complete student responses by their recorded section; Scores
and Tally reports show section summaries and the anonymous tally has a Section
column. Individual Annex A copies use the recorded section too. Historical
responses without section metadata retain their existing class label. They are
not guessed or rewritten from current membership data. Pending responses remain
included in overall rating calculations and are grouped under Section pending.

Automatic matching follows confirmed section evidence for untouched imported
classes. Only tracked, unanswered automatic assignments can move to the same
instructor's actual section. Submitted answers are never moved between teachers.
Once a class roster is customized manually, later imports do not silently replace it.
