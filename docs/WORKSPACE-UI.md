# Workspace navigation and student access

The current faculty evaluation workflow is documented in [EVALUATION-WORKFLOW.md](EVALUATION-WORKFLOW.md).

- **Academic Management:** departments, school years, courses, Student Master List and Sections.
- **Faculty Evaluation:** Teaching Classes, Evaluation Setup, Evaluation Forms, My Supervisor Tasks and student completion monitoring.
- **Evaluation Reports:** faculty results/printing, individual Annex copies, department summaries and personal released results.
- **Assessments:** quizzes, exams, activities and Class Records. This group starts collapsed.
- **Administration / Settings:** staff, roles, assigned access, background operations and system configuration.

The Dashboard and setup pages show the same four evaluation steps, filtered by permissions.
Campus and department restrictions, draft recovery and historical evaluation results are retained.

Faculty evaluations use student ID entry through `/evaluations/start`. They have no PIN
or sign-in approval requirement. Supervisors answer Annex B through My Supervisor Tasks.

PIN and browser sign-in approval controls apply exclusively to quizzes, exams and
activities. Approving a waiting browser does not itself start an assessment attempt;
assignment, publication, schedule and attempt limits still apply.

The UI uses the installed Filament components and existing permissions. No additional
plugin is required for these navigation groups, workflow links or review modals.
