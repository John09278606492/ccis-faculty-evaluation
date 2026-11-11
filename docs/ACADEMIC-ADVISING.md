# Academic advising

Staff use **Academic Advising → My Advisory Sections → Add advisory section** to
claim an existing section in the selected campus and term. The signed-in staff
member becomes its adviser. Each section has one adviser; a section with tasks
cannot be released, so its submission history stays intact.

Use a section's **Edit** button to select its school year, semester and section.
Available sections are filtered to that term, and the current selection stays
available while editing. Changing the section refreshes existing tasks' untouched
student rosters. Once students have submitted work, keep that roster source so
their submissions remain with their original class. Add another advisory section
only when using a different student list. To change the enrollment semester while
keeping the same students, edit the task's **Advising for** field instead.
Missing semesters and sections can be created under Academic Management → School
Years and Sections.

Use **Advising Tasks → Create** to choose a claimed section, enter requirements,
instructions, a deadline in Philippine time and the notice students should see.
Set **Advising for** to the semester the students are preparing to enroll in.
For example, keep **Student list semester = First Semester** on the advisory
section and choose **Advising for = Second Semester** on its task. This uses the
first-semester student list without changing their academic records, grades or
membership. Existing tasks can have their advising semester corrected through
**Open & review**. The advising semester is displayed to students in their task
list, requirements and dashboard. Only semesters in the current campus are allowed.
The default notice says failure to submit by the deadline means the student will
not be enrolled. This is a displayed notice: the module does not automatically
change anyone's enrollment or grades.

Students find **My Learning → My Advising**, and open requirements also appear in
their dashboard's **To do now** card. They may upload up to ten JPG, PNG, WebP or
PDF files of up to 10 MB each, with a note. Sending again replaces the previous
files until the adviser approves. Approved submissions are locked. Closing a task
stops submissions; archiving also hides it from students. Late submissions are
rejected unless the adviser explicitly enables them.

Open a task to see **Student submissions**, filter by status and download files.
Approve complete submissions or return them with required feedback. Students
receive a bell notification after review. **Remind waiting students** notifies
students still owing files; creating an open task also notifies its roster.

Section membership is the source of truth. Roster synchronization adds new members
and removes departed students' untouched rows, retaining submitted history. A
departed student cannot open that section's tasks or files. Staff lists are scoped
to their own advisory sections in the current campus; super admins may inspect all
sections in that campus.

Files stay on the private local disk (`storage/app/private/advisory-submissions`).
Authenticated downloads check campus access, section membership and ownership or
adviser permission. File paths and content types are validated on the server.
Submission and review operations serialize against the task to protect approval
and deadline checks from concurrent or stale requests.

Deployment runs only `2026_11_11_000001_create_advisory_tables.php`, which creates
three tables and adds the module permissions to existing instructor, student and
super-admin roles. The student permission allowlist includes `page_MyAdvising`.
No seeders, student records, assessment answers or existing enrollment data need
to be replaced. Back up the live database and changed code before deployment.
