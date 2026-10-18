# Quiz / Exam / Activity System - Guide

Adds a quiz/exam/activity module on top of the existing campus → course offering →
student structure, so an instructor can build a timed quiz or exam (or an untimed,
deadline-based "activity" with file submission) for one of their course offerings,
and students take it from the same Filament panel they already use.

## Files Added

- Migrations: `quizzes`, `quiz_questions`, `quiz_attempts`, `quiz_answers`
- Models: `app/Models/Quiz.php`, `QuizQuestion.php`, `QuizAttempt.php`, `QuizAnswer.php`
- Instructor side: `app/Filament/Resources/QuizResource.php` (+ Pages + `QuestionsRelationManager` +
  `AttemptsRelationManager` for grading), and `app/Filament/Pages/QuizBuilder.php` — the visual
  drag-and-drop question builder, built the same way as `app/Filament/Pages/FormBuilder.php`
- Student side: `app/Filament/Pages/MyQuizzes.php` (list, under the "Student" nav group) and
  `app/Filament/Pages/TakeQuiz.php` (the actual timed quiz-taking page)
- `app/Policies/QuizPolicy.php`
- `app/Filament/Pages/Login.php` now accepts a student ID number as well as an email address

## Building a Quiz (instructor)

1. In the admin panel, go to **Evaluations → Quizzes & Activities → New**.
2. Pick the **Course Offering** (only offerings you teach in the current campus are listed),
   give it a title, and choose the type:
   - **Quiz / Exam** — pick a **Timing** mode (see below).
   - **Activity** — untimed; only the **Available Until** deadline matters.
3. For Quiz/Exam, **Timing** has two modes:
   - **Duration** — a student clicks Start whenever they like (within Available From/Until, if
     set) and gets the given number of minutes from that moment. Everyone's clock runs independently.
   - **Scheduled** — set exact **Opens At** / **Closes At** clock times. Every student sees a
     waiting screen with a live countdown until it opens, then answers questions, and at the exact
     closing time **everyone** is cut off and auto-submitted together, no matter when each person
     started (or if they never started at all).
4. Save, then click **Build** to open the visual question builder — the same drag-and-drop
   experience as the evaluation Form Builder: drag a question type from the left palette onto the
   canvas (or click it), reorder questions by dragging, and edit the selected question's text,
   points, and **answer key** in the right-hand panel:
   - **Multiple Choice** — tick the checkbox next to the correct choice(s).
   - **True / False** — pick which one is correct.
   - **Short Answer** — list every accepted answer (case-insensitive match at grading time).
   - **Essay / File Upload** — no answer key; these are always graded manually afterward.
5. If you set an **Opens At** / **Available From** time, you can also pick **Notify Students Before
   It Starts** (2 minutes up to 1 day ahead) — every enrolled student gets a database notification
   (bell icon, top right) with a direct link to the exam once that lead time arrives. There's no
   cron/queue needed for this — it's checked automatically on ordinary page loads, the same way the
   "no active School Year" banner works.
6. Toggle **Published** when you want students to see it.

While waiting for a scheduled exam to open, students aren't stuck watching a countdown — the
waiting screen has a **Back to My Quizzes** link, and they can safely leave and come back anytime
before it starts (or wait for the notification).

The plain **Questions** tab on the Edit page still exists too (same underlying data, simpler
table-and-modal editing) if you'd rather not use the visual builder for a given quiz.

## Sharing a Quiz / Previewing It

Every quiz row (and the Edit page) has two extra buttons:

- **Preview** — opens the exact page students see, live timer and all, but nothing you type or
  click is ever saved (an in-memory attempt is used instead of a real one).
- **Share Link** — shows the direct student URL in a copyable field (and tries to copy it to your
  clipboard automatically). Post this link anywhere — a projector, LAN chat, a printed QR code. A
  student who isn't logged in yet is sent to `/login` first and automatically redirected back to
  the quiz afterward, so the same link works for the whole class regardless of who's logged in.

Students to test with come from the existing student system — bulk import via
**Users → Import Students** (`StudentImporter`), or add/bulk-add them to a course offering from the
course offering's **Evaluators** tab (`StudentsRelationManager`). No changes were needed there;
being attached to the course offering is what makes a quiz visible to a student.

## Taking a Quiz (student)

Students log in to the same panel at **My Quizzes** (Student nav group), where they see every
published quiz/exam/activity for their enrolled courses with a status (Not Started / In Progress /
Partial score / Final score). The quiz itself opens on its own distraction-free page — no admin
sidebar or navigation, just the quiz. Before anything is shown, the student's identity is checked
twice: they must be logged in (by student ID or email), and they must actually be registered for
the course offering the quiz belongs to — anyone else sees a friendly "you can't access this quiz"
message explaining why (not registered / attempts used up / quiz not open yet) instead of a raw
error page. Answers autosave as they're entered, so a dropped WiFi connection doesn't lose progress
— reopening "Continue" resumes the same attempt. When the timer hits zero the attempt is
auto-submitted, and this is also enforced server-side (checked on page load / autosave) in case the
student's browser tab was closed, since there's no background queue worker running in this setup.

## Exam Supervision

On assessments of type **Exam**, the page records each time a student switches to another tab or minimizes the browser while an attempt is in progress, and for how long (measured by the server). Students see a line saying so. The instructor gets a notification the 1st, 3rd, 5th and 10th time a student leaves, and the **Attempts & Submissions** tab shows a **Left page** column with the count, total time away, and the times on hover. Quizzes and activities are not tracked. Nothing is blocked and no screenshots are taken: treat it as a prompt to walk over, not proof of cheating, since a notification or a dropped connection can also hide the page.

## Grading

Multiple choice / true-false / short-answer (the answer key set in the builder) are graded
**instantly and automatically** on submit — no instructor action needed. If a quiz also has an
essay or file-upload question, the score shown right after submission is explicitly labeled a
**partial score** (only the auto-graded points), not the final one — students see "essay pending"
next to it, and it only becomes the **final score** once you grade the rest.

Essay and file-upload answers show up under a quiz's **Attempts** tab with a **Grade** action. That
same screen also recaps the auto-graded answers (correct/incorrect, what the student answered, and
points) above the essay/file grading fields, so you see the full score breakdown in one place, not
just the essays in isolation. Enter points and optional feedback per answer; the attempt's total
score recomputes automatically and its status flips from partial to final.

## Signing in with a Student ID

Students sign in with their ID number and a 6-digit PIN. Imported students have no usable PIN until
their instructor issues one: open the course offering, go to **Evaluators**, and use **Sign-in PINs**
to download a CSV of new PINs to print or share (PINs are stored hashed and cannot be shown again).
**New PIN** on a student's row, or in **Users**, replaces one forgotten PIN.

Staff sign in with their email and password. Staff imported with the shared starting password
(`password123`) must choose their own the first time they sign in.

## Running It Offline on a Classroom WiFi/LAN

The Filament admin theme has no external CDN dependencies (fonts/scripts are all bundled locally by
Vite), so this works without internet once assets are built:

```bash
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

Then have students connect to the same WiFi/hotspot as the host machine and browse to
`http://<host-machine-LAN-IP>:8000` (find the IP with `ipconfig` on Windows). The only feature that
needs real internet is the optional "Sign in with Google" button — irrelevant here since students
sign in with their student ID and password instead.
