# CCIS Faculty Evaluation System

A campus-scoped Laravel and Filament application for faculty evaluations, student quizzes, class records, role-based administration, and evaluation reporting.

Repository: [John09278606492/ccis-faculty-evaluation](https://github.com/John09278606492/ccis-faculty-evaluation)

## Main capabilities

- Multi-campus administration with tenant-aware access
- Configurable faculty evaluation forms and scoring
- Anonymous evaluation submissions with duplicate prevention
- Student enrollment and course-offering management
- Quizzes, timed attempts, manual grading, and private file submissions
- Class record imports and evaluation reports
- Role and permission management through Filament Shield

## Requirements

- PHP 8.2 or newer with the Laravel-required extensions
- Composer 2
- Node.js 20 or newer and npm
- SQLite with PHP `pdo_sqlite` / `sqlite3` for the application database
- MySQL is separate and optional for SQL coding assessment workspaces; evaluations use SQLite

## Local setup with Laragon

```powershell
git clone https://github.com/John09278606492/ccis-faculty-evaluation.git
cd ccis-faculty-evaluation
Copy-Item .env.example .env
composer install
npm install
php artisan key:generate
```

For a **new, empty installation**, keep the SQLite settings from `.env.example`, create its database file, then run:

```powershell
if (-not (Test-Path database/campus.sqlite)) { New-Item database/campus.sqlite -ItemType File }
php artisan classroom:install
npm run build
```

For the existing project, simply double-click **Start-Campus.cmd** (shared campus access) or **Start-Local.cmd** (local development). The current database is `database/campus.sqlite`. Existing installations must keep their original `.env` / encryption keys; do not run key generation when moving their records. See [SQLite migration, capacity measurements and server deployment](docs/SQLITE-DEPLOYMENT-AND-LOAD-TEST.md).

`classroom:install` runs the migrations, creates the default roles (super_admin, instructor, student) and a campus, and asks for the first super admin's email and password. It is safe to run again: it never removes permissions added on the Roles page. Do not run `shield:generate --all` without `--option=permissions`, because it overwrites the committed policies.

Set `BACKUP_PATH` in `.env` to a second drive or USB stick for the daily backups (see `desktop-host/HOST.md`).

Point a Laragon virtual host at the project's `public` directory, or run `composer run dev` for Laravel, the queue, logs, and Vite together.

## Automatic live updates

`Start-Campus.cmd` starts the WebSocket service along with the web server. Saved changes update open tables, dashboard cards/widgets, department charts, academic-period selectors, read-only record details, navigation badges, tenant/account labels and report previews automatically. A student's completed-evaluation counters also update across their open tabs through their own authorized private channel. Each viewer's campus, department and permissions still determine which records are returned; broadcast messages contain only a change notification.

Updates wait while an editable modal or focused input is in use, then apply automatically. Report previews preserve unsaved report details and pause updates during printing. Reconnecting or returning to a hidden tab catches up with saved changes. If WebSockets are unavailable, panel pages check automatically every 20 seconds. The ID-only student evaluation entry checks its assignment list every 10 seconds without replacing the student ID input or active evaluation answers.

System Health checks service, backup and scheduler state every 30 seconds because those changes can occur outside a database save. School-year warnings are scoped to the selected campus and update with its academic settings. Active editors and examination/evaluation answers retain their draft state; saved-data invalidation never replaces those answers with another user's data.

After deploying new browser assets, already-open tabs need one reload to load that version; subsequent data changes do not require manual refreshes.

## Background processing

**Faculty Evaluation → Monitor Student Progress → By Section** shows section membership, students who finished all assigned evaluations, students with open follow-ups, and the overall evaluation completion rate. **View Students** opens that section's roster with individual progress; Department, Semester and Section filters remain visible. Students without assignments are shown separately and do not lower the completion rate. Future, closed or exhausted evaluations are not classified as open follow-ups. Irregular students appear in every section they belong to, so section totals must not be summed as unique campus totals. All counts respect the viewer's assigned campus/departments and disclose completion only, never response ratings or comments.

**Academic Management → Sections** owns student lists by department and academic period. Create/open a section, then use its **Students** tab to **Download Student List CSV** or **Upload Section Students**. The example includes that section's name followed by `ID No.,Lastname,Firstname,Middlename`; files without a section heading use the section currently open. Sex is optional: omit its column or leave it blank; older files containing Sex remain supported and supplied values are validated against the masterlist. A supplied heading/Section column must match it. IDs remain text, and validation checks the full name, period masterlist and authorized campus/department. Any invalid row blocks the entire upload. Open the background review, correct errors if necessary, then **Confirm Section Students**. Other sections and identity details are preserved. Individual students can also be added or removed here; removing a membership does not delete the student or existing evaluations.

**Teaching Classes → Evaluators → Assign Evaluators by Section** is the single assignment flow in Teaching Classes. Choose the sections taught by this faculty member, optionally enable **Add or exclude individual students** for irregular students or exclusions, then review and **Save Evaluators**. Each section shows its student count and has a direct **Upload / Manage Section Students** link. Empty sections are blocked until their list is uploaded and confirmed. Section pages provide **Review / Confirm Uploads** to reach the background review. Creating a section alone does not populate its students.

The faculty, subject and academic period appear in the assignment dialog. Saving uses only the selected sections and explicit exceptions, with additions/removals shown before saving; submitted responses remain preserved. Previous bulk/manual selections are not automatically loaded as individual exceptions. Confirmed section-based assignments retain their explicitly saved exceptions. The Teaching Classes list shows the actual assigned sections and active evaluator count without unrelated evaluation/report columns. Existing active rosters remain unchanged until staff review and save. Later section edits do not silently change an already assigned teaching-class roster. Inactive sections keep their students/history but are unavailable for new selections. Existing memberships are backfilled into Sections during migration; legacy student-profile and masterlist section entries also register in the central section catalogue.

The notification bell stays usable during SQLite imports. Toast-close events never write to the notifications table. If a real notification is marked read or dismissed while the database is busy, the change is saved privately, shown immediately and retried automatically by the bell and worker. A brief “Saving…” indicator disappears after it is synchronized; reloading the page does not lose the pending action.

Bulk student uploads, course synchronization, evaluator ID files and class-record workbooks run in the background. Upload the file, continue working, then open the notification bell when the review is ready. Review validation errors and proposed departments/courses in **Administration → Background Processing**, then select **Confirm Import**. The confirmed import is queued separately and rechecks permissions and changes since review before saving. Failed operations produce a persistent notification and a task reference.

Staff, faculty, department and assessment CSV exports, plus selected missing-PIN generation, also run in the background. Notifications link to private downloads; the owning user’s current campus, department and permissions are checked again when downloading. Downloads expire after seven days; PIN export files are encrypted at rest. Existing PINs are preserved.

Both launcher scripts manage the dedicated bulk worker automatically. A separately hosted server must supervise `php artisan queue:work bulk --queue=bulk --sleep=1 --tries=1 --timeout=900` alongside its ordinary queue worker and scheduler. The bulk queue uses a 1,200-second reservation, longer than its 900-second job timeout. `php artisan background:maintain` cleans expired private files and flags abandoned operations; the scheduler runs it every ten minutes. File transfer still completes before a job is queued; parsing, validation, database imports and export generation continue without holding the browser request open.

## Development checks

```powershell
php artisan test
node --test tests/js/live-panel-refresh.test.mjs
vendor\bin\pint --test
npm run build
composer audit --locked
npm audit --omit=dev --audit-level=high
```

GitHub Actions runs the same tests, formatting check, production asset build, and dependency audits on pushes to `main` and on pull requests.

## Security and privacy

- Evaluation access is checked against the form's campus, allowed roles, and student enrollment.
- New evaluation records do not store evaluator user IDs, IP addresses, or browser user-agent strings. A keyed one-way fingerprint enforces one submission per evaluator and course offering.
- Set a stable `EVALUATION_FINGERPRINT_KEY` in production. If omitted, the application uses `APP_KEY`; changing either key resets duplicate-recognition for future requests.
- Quiz attachments are restricted by type and size, stored on the private disk, and downloaded only through an authorized route. Existing public-disk attachments remain downloadable through the protected route for compatibility.
- Use HTTPS in production and set `APP_ENV=production`, `APP_DEBUG=false`, and the correct `APP_URL`.
- Never commit `.env`, credentials, database exports, or files from `storage/app/private`.

## Deployment

After deploying a reviewed revision:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesSeeder --force
php artisan optimize
php artisan filament:cache-components
php artisan icons:cache
```

Back up the database before migrations, keep `EVALUATION_FINGERPRINT_KEY` stable, and ensure the web server can write to `storage` and `bootstrap/cache`.

## License

This project is licensed under the MIT License.
