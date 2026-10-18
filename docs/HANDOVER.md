# Handover: code questions, in-browser runners, student databases, Assessments wording

Written 2026-10-16 for whoever continues this work in another worktree or environment.
Project: **CCIS Faculty Evaluation** (Laravel 12, Filament 3.3, Livewire 3, PHP 8.3) at
`D:\lenovo's drive c old files\dev\laravel projects\ccis-faculty-evaluation`, GitHub `John09278606492/ccis-faculty-evaluation`, branch `main`.

The instructor runs the whole system from **one Windows laptop** shared to students over the classroom
Wi-Fi/LAN (often with no internet). Every feature below is designed for that: nothing depends on a CDN
or the internet at exam time.

---

## 0. Do this first

1. Everything is committed on `main` (latest feature commit `560a2d4`). Only `.playwright-cli/`
   (browser debug logs) is left out on purpose. Nothing has been pushed to GitHub.
2. After cloning or creating the worktree, install and build:
   ```bash
   composer install
   npm install
   npm run build                      # also builds the code editor + PHP/SQLite runtimes
   php -d opcache.enable_cli=0 artisan filament:assets
   php -d opcache.enable_cli=0 artisan migrate
   ```
3. Read section 6 (gotchas) before running any artisan command on this machine.

---

## 1. What exists (committed)

| Commit | Content |
|---|---|
| `734bfe6` | Code-editor question type (first version), live classroom updates over Laravel Reverb, desktop host app, class-record auto-sync, quiz assignments |
| `7c6137f` | Multi-file code questions and an in-browser **Run** button (HTML/CSS/JS preview, PHP via WebAssembly, SQL via SQLite WebAssembly) |

## 2. What exists (commit `560a2d4`)

All of this is built and tested (56 Pest tests passing):

- **Student database workspaces**: a separate MySQL server for students, per-student accounts, and
  one-click phpMyAdmin sign-on from a code question (section 4.4).
- **"Assessments" wording**: the UI no longer says "quiz" generically (section 4.5).
- **VS Code-style workspace**: Explorer with folders, student-created files, tabs, quick open, status bar
  and VS Code colours (section 4.2).
- New per-question settings: *Students can create files and folders* and *Code suggestions (autocomplete)*.
- Fixes: a crash from live updates reaching the lazy Attempts table, and MySQL tools failing under the
  web server (section 5).
- `docs/HANDOVER.md` (this file).

Files (relative to the project root):

```
Modified
  .env.example                                          STUDENT_DB_* settings
  app/Filament/Concerns/ReceivesClassroomUpdates.php    lazy-table crash fix
  app/Filament/Pages/{ClassRecords,MyQuizzes,QuizBuilder,TakeQuiz}.php
  app/Filament/Resources/QuizResource.php (+ Pages/EditQuiz.php, RelationManagers/*)
  app/Models/{Quiz,QuizAnswer,QuizQuestion}.php
  app/Providers/AppServiceProvider.php                  service bindings, FilamentAsset registration
  app/Providers/Filament/AdminPanelProvider.php         nav group "Assessments"
  app/Support/CodeProject.php                           folders, student files
  config/classroom.php                                  student_db section
  desktop-host/HOST.md, desktop-host/resources/server/host.cjs
  package.json, package-lock.json
  public/js/app/components/code-editor.js               published bundle (copy of the dist file)
  resources/css/filament/admin/theme.css                VS Code workspace styles
  resources/js/components/{code-editor,code-runner}.js, resources/js/runners/php-worker.js
  resources/js/dist/components/code-editor.js           built bundle
  resources/views/components/code-editor.blade.php
  resources/views/filament/forms/components/code-editor.blade.php
  resources/views/filament/pages/{quiz-builder,take-quiz}.blade.php
  routes/web.php                                        student DB route, old-URL redirects
  tests/Feature/QuizWorkflowTest.php
New
  app/Console/Commands/StudentDatabaseCommand.php
  app/Http/Controllers/StudentDatabaseController.php
  app/Models/StudentDbAccount.php
  app/Services/StudentDatabase/{StudentDbServer,StudentDatabases,MySqlStudentDatabases}.php
  database/migrations/2026_10_16_000001_create_student_database_workspaces.php
  resources/js/components/vscode-theme.js
  resources/student-db/{config.inc.php.stub,signon.php.stub,signed-out.html}
  docs/HANDOVER.md
```

---

## 3. Data model

Internally everything is still named "quiz" (tables, models, classes). Only the user-facing words changed.

### `quiz_questions` with `type = 'code'`

`options` (JSON):

| Key | Meaning |
|---|---|
| `language` | Preset that picks the starter files: `web`, `php`, `html`, `css`, `javascript`, `sql`, `python`, `java`, `c`, `cpp`, `csharp`, `plaintext` (`QuizQuestion::CODE_LANGUAGES`) |
| `files` | Starter files: `[{"name": "css/style.css", "content": "..."}]`. Names are paths |
| `folders` | Starter folders that contain no files yet |
| `allow_run` | Students see the Run button (default true; only matters for web/PHP/SQL) |
| `allow_student_files` | Students may add, rename and delete files and folders (default true; new questions store true) |
| `autocomplete` | Code suggestions in the student editor (default false) |
| `database` | `{"enabled": bool, "starter_sql": "..."}`: each student gets their own MySQL database |
| `starter_code` | **Legacy** single-file starter (questions from before file tabs); still read |

`correct_answer` (JSON), instructor-only reference solution: `{"files": [...]}`. The legacy form is
`{"reference": "..."}`. It is never shown to students.

### `quiz_answers`

- `answer_text` for code questions holds a project JSON string: `{"files": [...], "folders": [...]}`.
  Legacy plain-string answers are still read as one file.
- `database_name`: the student's workspace database (set only after setup succeeded).
- `database_export`: SQL dump taken when the attempt is submitted (graded from this).

### `student_db_accounts`

One row per student: `user_id`, `mysql_user` (`stu{userId}`), `password` (encrypted with `APP_KEY`).

### Rules (`app/Support/CodeProject.php`, mirrored in `resources/js/components/code-editor.js`)

- Path segments use `[A-Za-z0-9_-]` with dot-separated parts. At most 4 levels deep, 120 chars per path,
  30 files, 50,000 characters in total. Extensions are listed in `CodeProject::EXTENSIONS`.
- `CodeProject::runner(files)` decides what can run: any `.php` → `php`, any html/css/js → `web`,
  any `.sql` → `sql`, otherwise nothing (Python, Java, C, C++, C# are write-only).
- `QuizQuestion::normalizeCodeAnswer()` validates what students save. Without `allow_student_files`, only
  the starter file names are kept (extra files are dropped). With it, any valid project is accepted.
- **Keep the PHP and JS rules in sync** when changing either.

---

## 4. Features and where they live

### 4.1 Code question type

- Instructors add Code questions in the **Assessment Builder** (`app/Filament/Pages/QuizBuilder.php` +
  `resources/views/filament/pages/quiz-builder.blade.php`) or in the **Questions** tab of an assessment
  (`QuestionsRelationManager.php`). Both offer language preset, starter files, reference solution,
  Run / student-files / autocomplete toggles, and the database option.
- Changing the language preset swaps the starter files **only if they are still empty**.
- Code answers are **graded manually** (`QuizQuestion::MANUALLY_GRADED_TYPES`). Grading happens in the
  **Attempts & Submissions** tab (`AttemptsRelationManager.php`), which shows the student's files read-only
  with Run, the reference solution, and the database export with an "Open in phpMyAdmin" link.
- **Automatic checking** (`options.checks` = `{"enabled", "cases": [{"label", "input"}], "ignore_case"}`):
  `resources/js/components/code-checker.js` runs the reference solution and each answer on the test cases
  in the grader's browser and compares outputs; `AttemptsRelationManager::recordAutoCheck()` stores the
  result in `quiz_answers.auto_check` and fills in the suggested points unless they were set by hand.
  Only PHP, web and SQL questions without a student database (`QuizQuestion::canAutoCheck()`).

### 4.2 VS Code-style workspace (the editor)

- Blade: `resources/views/components/code-editor.blade.php` (`<x-code-editor>`). Props: `state-path` or
  `value`, `starter-files`, `read-only`, `can-manage-files`, `can-run`, `autocomplete`,
  `commit-debounce`, `min-height`, `max-height`.
- JS: `resources/js/components/code-editor.js`, an async Alpine component loaded with Filament's `x-load`.
  It provides:
  - an Explorer tree with folders (New File / New Folder / Collapse, rename with F2 or double-click, delete)
  - closable tabs, breadcrumbs, and quick open with Ctrl+P
  - Ctrl+B toggles the Explorer, Ctrl+S saves now, Ctrl+Enter runs
  - a status bar (file count, saved state, Ln/Col, language) and a maximize button
- CodeMirror 6. Colours are VS Code Dark/Light Modern (`resources/js/components/vscode-theme.js`) and
  follow Filament's dark-mode class.
- Students' edits autosave: the entangled Livewire state is committed after 750 ms of no typing,
  on Ctrl+S, and before Run.
- Styles live in `resources/css/filament/admin/theme.css` (`.cq-*` classes, built by Vite).
- Exam pages with code questions use a wider layout (`TakeQuiz::getLayoutData`).
- Filament form field wrapper: `app/Filament/Forms/Components/CodeEditor.php`.

**Build pipeline** (`scripts/build-code-runner.mjs`, run by `npm run build:code-editor` and by `npm run build`):

1. It bundles the editor with esbuild into `resources/js/dist/components/code-editor.js` (committed).
2. `php artisan filament:assets` copies that to `public/js/app/components/code-editor.js` (committed).
   It is registered in `AppServiceProvider` as Alpine component `code-editor`.
3. It copies the runtimes to `public/code-runner/` (**git-ignored**, rebuilt by `npm run build`):
   `php-worker.js` + `8_3_33/php_8_3.wasm` (~18 MB) and `sql/` (sql.js).

### 4.3 In-browser Run (no code runs on the server)

`resources/js/components/code-runner.js`:

- **web**: HTML/CSS/JS rendered in a sandboxed `iframe` (`srcdoc`, no same-origin).
  - Project CSS is inlined on one line. Project JS is loaded as `data:` URLs, so error line numbers
    match the student's file.
  - A small bridge script reports `console.*` output and errors, and intercepts link clicks and form
    submits.
  - Paths are resolved relative to the current page (`resolveProjectHref`), so `pages/about.html` can
    use `../css/style.css`.
- **php**: PHP 8.3 as WebAssembly (`@php-wasm/web-8-3`, asyncify build) in a Web Worker
  (`resources/js/runners/php-worker.js`). It supports `require`, `$_GET`, `$_POST` (form posts
  from the preview) and redirects.
- **sql**: sql.js (SQLite) worker, fresh in-memory DB per run, results shown as tables. Syntax is SQLite,
  close to but not identical to MySQL.
- Every run has a 10 s timeout (60 s for the first PHP load); the worker is restarted after a timeout,
  so infinite loops only stop that run.
- This was verified working over plain `http://<LAN-IP>`, which is not a secure context.

### 4.4 Student database workspaces (MySQL + phpMyAdmin)

Purpose: a code question with **"Give each student a MySQL database (phpMyAdmin)"** gives every student
their own database, opened from the exam with **Open phpMyAdmin**. No passwords are shown to students.

- **Separate server.** A second MySQL instance listens on **127.0.0.1:3307**, using Laragon's MySQL
  binaries. Its data lives in `storage/app/student-db/` (git-ignored). It is **never** the app's own
  database (`faculty_rating_system` on 3306).
- **Service:** `app/Services/StudentDatabase/StudentDbServer.php` handles install, serve, stop,
  binary detection, sign-on tokens and running `mysql`/`mysqldump` with credentials in a temp options
  file. `MySqlStudentDatabases.php` provisions accounts and databases and exports or drops them; it is
  bound to the `StudentDatabases` interface in `AppServiceProvider` (tests use a fake).
- **Accounts:** `'stu{id}'@'%'` with `ALL` on `` `stu{id}\_%`.* `` only. Limits:
  `MAX_USER_CONNECTIONS 5`, `MAX_QUERIES_PER_HOUR 20000`. The server runs with
  `local_infile=0` and a dedicated `secure_file_priv`. Verified: a student cannot read another
  student's database or `mysql.*`, cannot create `stu{other}_*` databases, and cannot write files.
- **Databases:** one per answer, `stu{userId}_a{attemptId}_q{questionId}`. It is created on first
  "Open phpMyAdmin" and loaded with the question's starter SQL, run **as the student**, through the
  `mysql` client so `DELIMITER` works. A database left over from failed setup is rebuilt.
  On submit, `TakeQuiz::exportStudentDatabases()` saves a `mysqldump` into
  `quiz_answers.database_export`. A stopped server never blocks submitting.
- **phpMyAdmin:**
  - `classroom:student-db install` copies Laragon's phpMyAdmin to
    `storage/app/student-db/web/phpmyadmin/`, without `setup/`, examples or docs.
  - It writes `config.inc.php` from `resources/student-db/config.inc.php.stub`: `auth_type = signon`,
    the server fixed to 127.0.0.1:3307, and system databases hidden.
  - It also writes `signon.php`, which exchanges a one-time token for a phpMyAdmin session.
    Tokens are files in `storage/app/student-db/tokens/`, valid 60 s and deleted on use.
- **Route:** `GET /student-databases/{quizAnswer}/open` → `StudentDatabaseController@open`.
  - Students may open it only for their own attempt while it is in progress.
  - Instructors (owner of the course offering, or super_admin) may open it any time after the
    database exists.
  - Returns 503 with a friendly message if the server is down.
- **Command:** `php artisan classroom:student-db {install|serve|stop|status|prune|host-config}`
  - `install` is idempotent and keeps data. It creates the root password, encrypted with `APP_KEY`, in
    `storage/app/student-db/secrets.json`. **If APP_KEY changes, the root password can't be read.**
  - `serve --phpmyadmin-port=8095` is development mode (MySQL + phpMyAdmin on 127.0.0.1:8095).
  - `prune [--quiz=ID]` drops workspace databases of graded attempts (or of one assessment); the
    exports stay.
  - `host-config` prints the JSON the desktop host reads.
- **Desktop host:** `desktop-host/resources/server/host.cjs`. If the student DB is installed, it starts
  `classroom:student-db serve` and two PHP workers for phpMyAdmin (sharing port + 12/13). The gateway
  routes `/phpmyadmin/*` to them, so students use the same address and port as the app. On stop, it
  shuts MySQL down cleanly first.
  **Not verified end to end**: the app's own MySQL was down during this session, so the full host
  could not start. The routing logic was tested with an equivalent proxy.
- **Config:** `config/classroom.php` → `student_db` (port, path, `mysql_bin`, `phpmyadmin_source`,
  `laragon_paths`, `phpmyadmin_url`, limits). Environment variables are in `.env.example`
  (`STUDENT_DB_*`). For development without the desktop host, set
  `STUDENT_DB_PHPMYADMIN_URL=http://localhost:8095/phpmyadmin`.

### 4.5 "Assessments" wording

- Menus: **Assessments**, **My Assessments**. Pages use the item's own type via `Quiz::kindLabel()`
  (Quiz / Exam / Activity): "Take Exam", "Exam Builder — Midterm", "You can't access this exam".
- URLs moved: `/{tenant}/assessments`, `/{tenant}/my-assessments`, `/{tenant}/take-assessment/{id}`,
  `/{tenant}/assessments/{id}/builder`. Old `/quizzes`, `/my-quizzes` and `/take-quiz` links 301-redirect
  (`routes/web.php`), keeping query strings.
- Code comments, class names and tables still say "quiz"; that is intentional.

---

## 5. Bugs found and fixed this session (keep these fixes)

1. **Crash on the assessment edit page:** "Typed property RelationManager::$table must not be accessed
   before initialization". The live-updates script dispatches `classroom-push` to all Livewire
   components (on load and on reconnect), and it could reach the **lazy** Attempts relation manager
   before its table existed. Fixed in `ReceivesClassroomUpdates::receiveClassroomUpdate()` (skip render
   while the table is uninitialized). Regression test: "ignores live updates that reach a lazy table
   before it has loaded".
2. **Open phpMyAdmin failed under the web server:** MySQL error 2004 / WSA 10106. Under `php -S` and
   the desktop host's workers, child processes lacked `SYSTEMROOT`, so Windows sockets failed.
   `StudentDbServer::process()` now passes the full `getenv()` environment.
3. **Half-created workspace:** if starter SQL failed, the database stayed without its tables. It is
   now rebuilt when `database_name` was never saved.
4. A policy overwrite during this session (from `shield:generate --all` on the demo DB) was **reverted**.
   See gotcha 6.3.

---

## 6. Gotchas on this machine (important)

0. **The panel runs in SPA mode** (`->spa()` in `AdminPanelProvider`): panel links swap the page
   with `wire:navigate` and the document stays loaded. Page scripts must not rely on
   `DOMContentLoaded`/`alpine:init` having not fired yet, and document/window listeners must be
   removed on `livewire:navigating` (see the exam script in `take-quiz.blade.php`). Non-panel pages
   and exams are listed in `spaUrlExceptions` so they always load fresh.

1. **CLI OPcache serves stale code.** `C:\php\php.ini` has `opcache.enable_cli=1` and
   `opcache.validate_timestamps=0`, so `php artisan …` can run old code after edits. Always use
   `php -d opcache.enable_cli=0 artisan …`, or fix php.ini (`validate_timestamps=1`).
   `php artisan test` starts a child process that still uses the stale cache, so run tests as
   `php -d opcache.enable_cli=0 vendor/bin/pest`.
2. **`C:\laragon` is broken.** It has the data (`C:\laragon\data\mysql-8.4`, which holds the live
   `faculty_rating_system` DB) but its MySQL, Apache and PHP binaries are missing, so the app's MySQL
   could not start in this session.
   - **Back up `C:\laragon\data` first**, then reinstall Laragon from laragon.org.
   - `D:\download\laragon-wamp.exe` is **unsigned**; don't use it.
   - `D:\laragon` is complete (MySQL 8.0.30). The student DB server currently uses
     `D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin`. After reinstalling, auto-detection may pick
     MySQL 8.4 (newest), which upgrades the student data folder in place; pin `STUDENT_DB_MYSQL_BIN`
     to stay on 8.0.
3. **Never run `php artisan shield:generate --all` blindly.** It rewrites `app/Policies/*.php` and
   removes the custom course-ownership checks (e.g. `QuizPolicy` requires
   `courseOffering->user_id === $user->id`). This happened once in this session and was reverted with
   `git checkout HEAD -- app/Policies`.
4. `npm audit` warnings come from existing Tailwind/PostCSS tooling, not the new packages.
5. Code questions need `npm run build` (for `public/code-runner/`) on every new clone or worktree;
   without it, Run fails to load PHP/SQL.
6. Each student's first PHP Run downloads ~18 MB from the laptop (then cached).
7. `@php-wasm/web` is GPL-2.0-or-later, which is fine for internal school use.
8. Live updates log "Live update could not be delivered" whenever Reverb is not running; that is
   harmless.

---

## 7. Tests

- `php -d opcache.enable_cli=0 vendor/bin/pest` → **56 passed** at handover.
- The code/database tests are at the end of `tests/Feature/QuizWorkflowTest.php`. They rely on its
  helper functions (`quizWorkflowFixture`, `gradedAttempt`, `fakeStudentDatabases`,
  `databaseQuestionAttempt`) and on `tests/Support/EvaluationFixture.php`.
- No automated JS tests. Browser behaviour was verified manually (section 8).

---

## 8. Demo environment (optional, for trying things)

`storage/app/demo/` (git-ignored) holds a separate SQLite demo database, so the real database is never
touched:

- `seed-demo.php` rebuilds `demo.sqlite` with a campus, demo instructor and student, and a published
  "Web & Database Hands-on" quiz. Generated logins go to `storage/app/demo/ACCOUNTS.txt`; the student
  signs in with ID `2026-0001`.
- `workspace-demo.php` turns assessment #2 into "Midterm Practical Exam", a web project with folders,
  student files and autocomplete on.
- The demo database also has Shield permissions generated (demo only).

Run it (Git Bash, from the project root):

```bash
D="$(pwd -W)/storage/app/demo"
DB_CONNECTION=sqlite DB_DATABASE="$D/demo.sqlite" php -d opcache.enable_cli=0 artisan tinker --execute="require '$D/seed-demo.php';"
```

```bash
php -d opcache.enable_cli=0 artisan classroom:student-db serve --phpmyadmin-port=8095
```

```bash
cd public && D="$(cd .. && pwd -W)/storage/app/demo" && DB_CONNECTION=sqlite DB_DATABASE="$D/demo.sqlite" BROADCAST_CONNECTION=log QUEUE_CONNECTION=sync STUDENT_DB_PHPMYADMIN_URL=http://localhost:8095/phpmyadmin php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Use `http://127.0.0.1:8000` for the instructor and `http://localhost:8000` for the student; the two
hosts keep separate sessions. The demo server is single-threaded, so it is slow but works.

**Verified manually in the browser**, using the demo:
- HTML/JS run with console output
- PHP form POST including checkbox arrays
- SQL tables
- infinite-loop stop
- folder links across pages
- Explorer create, rename and delete, and saving with folders
- phpMyAdmin sign-on, isolation, export and grading view
- retake with a fresh database, and the closed attempt blocked
- the lazy-table crash fix
- all 47 panel pages loading

---

## 9. Open items / next steps

1. **Push** `main` to GitHub when ready (nothing has been pushed).
2. **Fix Laragon** (gotcha 6.2), then run `php -d opcache.enable_cli=0 artisan migrate` on the real
   database (the new migration has not been run there).
3. **Verify the desktop host end to end** with the real database:
   - Start CCIS Classroom Server and open `http://<LAN-IP>:8088` from a second device.
   - Check that phpMyAdmin works at `/phpmyadmin`.
   - Check that Stop shuts the student MySQL down cleanly.
4. After grading, use `classroom:student-db prune` to remove graded workspaces.
5. **Requested by the instructor but not built:** exam supervision, i.e. a log of when students leave
   the exam tab, with notifications. The previous assistant could not build this part in its session
   (its responses on this topic were stopped by an automated safety check), so nothing for it exists in
   the code. The instructor's stated scope: only during an exam, only on the exam page at the classroom
   server's address, logs and notifications only, **no screenshots and no lockdown**. Related
   facts already established:
   - Browsers cannot reveal which other site or tab a student switched to; only that they left the
     page and for how long.
   - Screen capture needs explicit student consent and HTTPS (not available on the current plain-HTTP
     LAN setup). The instructor has since dropped screenshots.
   - An alternative discussed but not built: a per-assessment **"Require Safe Exam Browser"** setting
     (SEB from safeexambrowser.org locks the student's computer to the exam address; the server
     checks SEB's request headers). The instructor found per-laptop installation too slow; the
     proposed mitigation was hosting the SEB installer on the laptop server plus a one-click
     "Open in Safe Exam Browser" link.
6. Possible improvements, not requested:
   - a resizable output panel
   - Python/Java/C++ execution (would need a server-side sandbox such as Piston in Docker/WSL2;
     the laptop has no Docker and only ~21 GB free)
   - a PHP-to-MySQL relay so in-browser PHP can use `mysqli` against the student server
     (php-wasm supports WebSocket-to-TCP; needs a prototype)
