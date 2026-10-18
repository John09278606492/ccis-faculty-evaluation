# HANDOFF: CCIS Faculty Evaluation

For the next AI agent working on this repository. Read this file first, then
[`docs/HANDOVER.md`](docs/HANDOVER.md), which is the detailed source of truth for the architecture,
data formats and gotchas of the assessment features. This file says where things stand, how the owner
wants you to work, and what to do next.

Last updated: 2026-10-18.

---

## 1. The project in short

- **What it is:** a Laravel 12 + Filament 3.3 + Livewire 3 (PHP 8.3) web app for a college (CCIS):
  faculty evaluations, student assessments (quizzes, exams, activities), class records (Excel sync) and
  reports. It is multi-campus: Filament tenancy by `Campus`, and URLs start with the campus **id**
  (`/1/assessments/...`).
- **Who uses it:** an instructor runs everything from **one Windows laptop** (Laragon, MySQL) shared to
  students over the classroom Wi-Fi/LAN, often with **no internet**. Nothing may depend on a CDN at exam
  time. A desktop host app (`desktop-host/`) starts the servers.
- **Assessment features built so far:**
  - code questions in a VS Code-style workspace;
  - an in-browser **Run** button: HTML/CSS/JS, PHP via WebAssembly, SQL via SQLite;
  - a private MySQL database per student, with one-click phpMyAdmin sign-on;
  - **exam supervision** (newest, see section 4).
- **Owner:** GitHub user `John09278606492` (the instructor). Explain things in plain, simple English.

## 2. Where things stand (git)

| | |
|---|---|
| Repository | `John09278606492/ccis-faculty-evaluation` |
| `main` | `0271239` "Update handover for committed state". **Does not have the work below yet.** |
| Work branch | `claude/gallant-rubin-edsfim`, pushed and up to date with GitHub |
| Commits on the branch, not on `main` | `65bf7e6` Fix `.env.example` parsing and add the Unit test folder; `a1e2eed` Add exam supervision |
| Pull request | None yet. The owner was asked whether to open one or merge directly; no answer yet. |
| Tests | `vendor/bin/pest` → **63 passed** on the branch |

**CI (GitHub Actions, `.github/workflows/tests.yml`)** runs only on pushes to `main` and on pull requests.
The last run on `main` failed within one second, before any step ran. That points to the GitHub Actions
account or runner settings, not the code. Even once it runs, two steps would fail on code that existed
before this work:
- `vendor/bin/pint --test` reports style issues in many existing files, including `desktop-host/`;
- `npm audit --audit-level=high` reports vulnerabilities from the existing Tailwind/PostCSS tooling.

Don't reformat unrelated files without asking.

## 3. How the owner wants you to work (rules)

1. **Wait for the owner to choose before starting a new feature.** Report options with a recommendation,
   then wait.
2. **Commit to a branch, not `main`,** unless the owner says otherwise. Never open a pull request unless
   asked.
3. **Never run `php artisan shield:generate --all`.** It overwrites `app/Policies` and removes the
   course-ownership checks (for example `QuizPolicy` requires `courseOffering->user_id === $user->id`).
   Note that `README.md` still tells you to run it during setup: **don't**.
4. **Keep the file-name and runner rules in sync** between `app/Support/CodeProject.php` and
   `resources/js/components/code-editor.js`. The runner rule is `runnerFor()` in `code-runner.js`.
5. **After changing anything under `resources/js/components` or `resources/js/runners`:**
   - run `npm run build:code-editor`, then `php artisan filament:assets`;
   - commit the updated bundles in `resources/js/dist/components/` and `public/js/app/components/`.

   `public/code-runner/` is git-ignored and rebuilt by `npm run build`.
6. **Wording:** code, tables and classes say "quiz". User-facing text says "Assessment" or the item's
   own type (`Quiz::kindLabel()`: Quiz, Exam, Activity). Keep it that way.
7. **Add or update Pest tests for every behaviour change,** and run the full suite before each commit.
8. **The student database server** (`php artisan classroom:student-db`) needs MySQL and phpMyAdmin
   binaries (Laragon on the laptop). Only use it where they are installed; otherwise rely on the tests,
   which use a fake (`fakeStudentDatabases()` in `tests/Feature/QuizWorkflowTest.php`).
9. **Exam supervision scope, agreed with the owner:**
   - only during an attempt, only on the exam page, logs and notifications only;
   - **no screenshots, no lockdown, no monitoring of anything outside the page;**
   - students are always told what is recorded.

   Browsers cannot reveal which other site or app a student opened, so don't claim or attempt that.
10. **Commit messages and PR text** must not mention AI model names.

## 4. What was done in the last session

1. **Setup fixes** (`65bf7e6`):
   - `.env.example` had `STUDENT_DB_LARAGON_PATHS="C:\laragon;D:\laragon"`. Inside double quotes, `\l` is an
     invalid escape for phpdotenv, so a fresh copy of the file broke every artisan command. It's now
     single-quoted, and `tests/Unit/EnvExampleTest.php` guards it.
   - `phpunit.xml` declares a `tests/Unit` suite, but the folder wasn't in git, which made plain
     `vendor/bin/pest` stop on a fresh clone. The new test lives there now.
2. **Exam supervision with paste detection** (`a1e2eed`). Full description in `docs/HANDOVER.md` section
   4.6.
   - **Setting:** *Supervise attempts* toggle per assessment (`quizzes.supervise_attempts`).
   - **Students:** see a notice before Start and a "Supervised" badge during the attempt.
   - **Leaving the page:** another tab, window or app, with the duration. Not recorded:
     - absences under 2 s;
     - focus inside the Run preview iframe;
     - the file chooser;
     - confirm dialogs.
   - **Outside pastes:** a paste or drop into an answer, of 3 or more lines or 100 or more characters,
     that was not copied inside the page, isn't already in the student's files or starter files, and isn't
     shown on the page. The log keeps the text, question and file name.
   - **Instructor:**
     - a bell notification, at most one per attempt every 2 minutes;
     - a *Supervision* column and a *Supervision log* action in Attempts & Submissions;
     - the log inside the grading modal.
   - **Files:**
     - `app/Services/ExamSupervision.php`, `app/Models/QuizAttemptEvent.php`, migration
       `2026_10_17_000001_create_quiz_attempt_events.php`;
     - `TakeQuiz::superviseEvent()`;
     - `resources/js/components/exam-supervision.js`, plus `containsText()` added to `code-editor.js`;
     - `AttemptsRelationManager` (column, action, listener for `classroom-supervision`);
     - the views `filament/partials/supervision-{notice,log}.blade.php`;
     - `resources/js/live-updates.js`, which turns broadcasts of kind `supervision` into a separate Livewire
       event so students' pages aren't reloaded.
   - **Tests:** six tests at the end of `tests/Feature/QuizWorkflowTest.php` (helper `supervisedAttempt()`).
   - **Not yet tried on the real laptop** with student devices.

## 5. Setting up a fresh environment

```bash
composer install            # slow behind some proxies: GitHub zip downloads may fall back to git clones
npm install                 # npm 10 may rewrite package-lock.json ("peer" flags); revert that noise
npm run build               # editor bundles + public/code-runner (PHP/SQLite runtimes) + Vite assets
php artisan filament:assets
cp .env.example .env && php artisan key:generate
# For local work without MySQL: set DB_CONNECTION=sqlite, comment out the other DB_* lines, then
touch database/database.sqlite
php artisan migrate
vendor/bin/pest             # expect 63 passed
```

On the owner's Windows laptop, read `docs/HANDOVER.md` section 6 first. CLI OPcache serves stale code there
(use `php -d opcache.enable_cli=0 artisan ...`), and the Laragon installation is partly broken.

**Local demo data** (SQLite) for trying things in a browser. Run this with
`php artisan tinker --execute="require 'path/to/seed.php';"` after `php artisan migrate:fresh`:

```php
<?php
require base_path('tests/Support/EvaluationFixture.php');
use Spatie\Permission\Models\Permission;
['student' => $student, 'offering' => $offering] = evaluationFixture();
$student->update(['student_id' => '2026-0001']);
$student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
$teacher = $offering->instructor;
$teacher->update(['email' => 'teacher@example.test', 'password' => bcrypt('password')]);
$teacher->assignRole('super_admin');
$teacher->campuses()->syncWithoutDetaching([$offering->campus->id]);
foreach (['update_quiz', 'view_any_quiz', 'view_quiz', 'create_quiz'] as $p) {
    $teacher->givePermissionTo(Permission::findOrCreate($p, 'web'));
}
$quiz = App\Models\Quiz::create(['course_offering_id' => $offering->id, 'auto_sync_class_record' => false,
    'title' => 'Midterm Practical', 'type' => 'exam', 'duration_minutes' => 60, 'max_attempts' => 3,
    'is_published' => false, 'assign_to_all' => true, 'supervise_attempts' => true]);
$quiz->questions()->create(['type' => 'code', 'question_text' => 'Build the page', 'points' => 10, 'order' => 1,
    'options' => ['language' => 'web', 'allow_run' => true, 'files' => [
        ['name' => 'index.html', 'content' => "<h1>Hello</h1>\n<script src=\"app.js\"></script>"],
        ['name' => 'app.js', 'content' => "console.log('ready')"]]],
    'correct_answer' => ['files' => [['name' => 'index.html', 'content' => '<h1>Hi</h1>']]]]);
$quiz->update(['is_published' => true]);
```

Then run `BROADCAST_CONNECTION=log QUEUE_CONNECTION=sync PHP_CLI_SERVER_WORKERS=4 php artisan serve`.
- **Students** sign in with their **student ID only** (no password): `2026-0001`.
- **Instructors** click "Instructor or admin? Sign in with a password": `teacher@example.test` / `password`.
- **Exam page:** `/1/take-assessment/1`.
- **Results:** `/1/assessments/1/edit?activeRelationManager=2` (Attempts & Submissions).

**Browser-testing gotcha:** Playwright makes every page it controls report focus and visibility, so it
cannot test "left the page" by itself. What worked:
1. Run Chromium under `xvfb-run` with `--remote-debugging-port=9222`.
2. Use Playwright through `connectOverCDP` to sign in and set things up, then disconnect it.
3. Switch tabs with Chrome's DevTools HTTP endpoints (`PUT /json/new`, `/json/activate/{id}`).

Paste detection can be tested with Playwright normally; grant `clipboard-read` and `clipboard-write`.

## 6. Map of the codebase (assessment parts)

| Area | Where |
|---|---|
| Assessment settings form, list | `app/Filament/Resources/QuizResource.php` |
| Questions, students, attempts and grading tabs | `app/Filament/Resources/QuizResource/RelationManagers/` |
| Question builder | `app/Filament/Pages/QuizBuilder.php` |
| Student exam page | `app/Filament/Pages/TakeQuiz.php` + `resources/views/filament/pages/take-quiz.blade.php` |
| Student list of assessments | `app/Filament/Pages/MyQuizzes.php` |
| Code project rules | `app/Support/CodeProject.php`, `QuizQuestion::normalizeCodeAnswer()` |
| Editor, runners | `resources/js/components/{code-editor,code-runner,vscode-theme}.js`, `resources/js/runners/php-worker.js`, built by `scripts/build-code-runner.mjs` |
| Student MySQL databases | `app/Services/StudentDatabase/`, `app/Http/Controllers/StudentDatabaseController.php`, `app/Console/Commands/StudentDatabaseCommand.php` |
| Exam supervision | `app/Services/ExamSupervision.php`, `resources/js/components/exam-supervision.js` |
| Live updates (Reverb) | `app/Services/ClassroomUpdates.php`, `app/Events/ClassroomChanged.php`, `resources/js/live-updates.js`, `app/Filament/Concerns/ReceivesClassroomUpdates.php` |
| Class record (Excel) sync | `app/Services/ClassRecordSyncService.php`, `app/Filament/Pages/ClassRecords.php` |
| Policies (ownership checks) | `app/Policies/` (never regenerate) |
| Tests | `tests/Feature/QuizWorkflowTest.php` (assessments), `tests/Feature/Evaluation*Test.php`, `tests/Support/EvaluationFixture.php` |

## 7. Open items and suggested next steps

1. **Get the branch onto `main`:** open a pull request or merge, whichever the owner chooses.
2. **On the laptop:**
   - run `php -d opcache.enable_cli=0 artisan migrate` and `npm run build`;
   - try exam supervision with real student devices over the LAN.
3. **Fix Laragon** and run the migrations on the real database (`docs/HANDOVER.md` section 6.2).
4. **Check the desktop host end to end** (`docs/HANDOVER.md` section 9): `/phpmyadmin` through the gateway,
   and a clean MySQL stop.
5. **Get CI working:**
   - check the GitHub Actions account and runner settings;
   - then decide with the owner whether to run Pint across the repository and how to handle `npm audit`.
6. **Possible later features** (not requested):
   - a "Require Safe Exam Browser" setting per assessment;
   - a resizable Run output panel;
   - a PHP-to-MySQL relay so in-browser PHP can reach the student database.
7. **Small known gap:** the editor doesn't enforce the 50,000-character project limit in the browser;
   only the server does.

## 8. A good first message for the new agent

> Read HANDOFF.md and docs/HANDOVER.md completely. Set up the environment as described, run
> `vendor/bin/pest` (expect 63 passing) and tell me whether it worked and anything that no longer matches.
> Work on a branch, follow the rules in HANDOFF.md section 3, and wait for me to choose before starting a
> new feature.
