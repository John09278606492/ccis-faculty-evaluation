# SQLite deployment and evaluation reliability — 23 October 2026

## Current installation

The application was using MySQL. Its main database has now been copied, verified and switched to **`database/campus.sqlite`** in this project folder. SQLite stores campus administration, academic rosters, evaluation cycles, SET/SEF responses, assessment records and reporting data. No project files were moved to the old D-drive checkout.

The separate **student MySQL workspaces** remain available for SQL coding assessments and phpMyAdmin. Those exercises intentionally require MySQL; they are independent of the application database. Ordinary evaluations do not need the old main MySQL service.

Start the existing installation by double-clicking **`Start-Campus.cmd`**. It opens the browser and prints the current LAN address. Stop it with **`Stop-Campus.cmd`**. Local development is also available with `Start-Local.cmd` on port 8010. No desktop installer is needed.

Current checks confirmed:

- 58 tables / 1,163 source rows copied with matching per-table raw-value digests and row counts.
- Existing 56 accounts and 69 evaluation submissions retained; original password hashes and application key preserved.
- SQLite 3.53.4, foreign keys enabled, WAL journal, `synchronous=FULL`, 10-second busy timeout.
- `integrity_check=ok`; zero foreign-key errors; no pending migrations.
- File-based sessions and cache, separate from SQLite evaluation writes.
- Campus launcher restarted successfully with 16 application PHP workers, plus separate phpMyAdmin/background services.
- Existing staff browser session, dashboard and supervisor task list remained usable after migration.

Local verification artifacts (ignored by Git):

- `database/campus.sqlite.verification.json`: original row counts and digests.
- `storage/app/qa/sqlite-cutover.json`: active driver, settings and integrity receipt.
- `storage/app/backups/ccis-backup-2026-10-23-233622.zip`: verified pre-cutover MySQL backup.
- `storage/app/backups/ccis-backup-2026-10-23-234230.zip`: verified post-cutover SQLite backup, uploads and one student SQL workspace.
- `storage/app/backups/pre-sqlite-2026-10-23.env`: private rollback configuration; contains secrets and must stay out of Git.

The original MySQL source remains intact. It becomes stale as soon as new records are written to SQLite; switching back later requires reconciliation rather than simply replacing `.env`.

## What the stress test covers

Test accounts and responses were created in **isolated SQLite databases**, not in the live presentation database:

- Balilihan campus: **334 CCJ**, **333 CTAS**, **333 CCIS** students.
- 1 super-admin, 1 campus admin, 3 deans, 3 chairpersons, 6 instructors.
- Two student evaluation assignments per student: **2,000 Annex A responses**.
- Dean and chairperson ratings for each faculty member: **12 Annex B responses**.
- Irregular students belonging to multiple sections, with deduplicated evaluation rosters.
- Separate boundary campus and department checks.

Fixture setup uses the actual role/scope assignment, academic import, section assignment, class creation, evaluation-cycle readiness and publishing services. The HTTP harness signs in staff through the real Filament/Livewire login and uses separate student cookie jars, CSRF tokens, ID entry, assignment pages, form reads and submissions. Each student performs seven HTTP requests, with no think time between steps. This is substantially more concentrated than students reading a form before answering.

The full workflow verifies:

1. Super-admin assigns campus administration; campus admin assigns department leadership and scopes.
2. Department staff import students, maintain multiple sections, assign evaluators and publish cycles.
3. All students submit both assigned evaluations exactly once.
4. The fixture explicitly selects each dean/chairperson as evaluators for its faculty classes; those selected accounts complete Annex B tasks. Leadership alone does not grant evaluator authority.
5. Student evaluation sessions cannot unlock staff pages or another department's assignments.
6. Twenty simultaneous replayed submissions cannot create duplicates.
7. Campus administrators and department heads can see their authorized results and student completion progress.
8. Faculty scores remain hidden while evaluation is open; released personal reports are accessible after closure.
9. Cycle closure/archive succeeds and subsequent submissions are rejected.
10. Submitted scores, anonymity, encrypted identities, response counts, foreign keys and SQLite integrity remain correct.

Expected scores are checked independently: all student answers of 4 give **SET 80%**, all supervisor answers of 5 give **SEF 100%**. These remain separate ratings; no unapproved SET/SEF weighting is invented.

## Measurements

Machine: Windows, AMD Ryzen 7 5800H, 16 logical CPUs, 31.4 GB RAM; PHP 8.3.35, SQLite 3.53.4, Node 24. Sixteen PHP HTTP workers, configuration/route caching and OPcache. Clients and server run on the same machine over loopback.

| Test | Students completing both forms | Concurrent student flows | Student request p95 | Student phase |
|---|---:|---:|---:|---:|
| Sustained capacity (`capacity-64-isolated`) | 1,000 / 1,000 | 64 | 2.18 s | 121.14 s |
| Simultaneous barrier (`verified-1000`) | 1,000 / 1,000 | 1,000 | 17.34 s | 100.50 s |

The sustained run executed **7,000 student requests**, plus role/report/boundary checks: **61/61 workflow checks and 23/23 data audit checks passed**, with 2,000 student responses and 12 supervisor responses. Its request p50 was 1.07 s, p99 2.67 s, maximum 3.75 s. These are individual HTTP request durations, not total user journey durations.

The simultaneous test opens 1,000 independent student sessions over five seconds, waits at an entry barrier, then releases all students together. It does **not** process students in sequential batches. Its final run completed **1,000/1,000 student flows**, **7,000 student HTTP requests**, **2,000 SET responses**, **12 SEF responses**, **61/61 workflow checks** and **23/23 data checks**, with no failed student flow or failed workflow assertion. Request p50 was **13.74 s**, p99 **18.29 s**, maximum **19.22 s**. The 2,000 submission requests all returned the expected successful redirect. Full role/boundary/report operations brought the total to **7,151 HTTP requests**; expected 403/404 denials are deliberate access tests. The student phase took **100.50 seconds**; subsequent report checks and audits are outside that timing.

An earlier 1,000-flow run found the faculty report permission bug and ran alongside the full automated suite. It is exploratory evidence, not the final capacity measurement. The final run above was performed after that suite finished and after the bug was fixed. Raw final evidence is in `storage/app/load-tests/verified-1000/http-results.json` and `data-audit.json`; the sustained result is in `storage/app/load-tests/capacity-64-isolated/`.

Exploratory tests found genuine failures before optimization: the old shared-IP limiter returned 429s, database-backed cache/session writes caused SQLite lock errors, and an instantaneous zero-ramp connection storm on this Windows gateway refused some sockets. The five-second connection ramp plus keepalive tests do not establish that a zero-ramp socket storm is solved.

One exploratory sustained run was invalidated when live maintenance mode also affected its test server. The harness now isolates maintenance state as well as database, sessions, cache, routes and configuration. That interrupted run is not used as capacity evidence.

**Interpretation:** the 1,000-flow test measures data integrity under an extreme queue; the smaller concurrent run measures more usable response times. A successful completion count alone is not proof of acceptable responsiveness. Repeat these tests on the school's actual server before choosing a concurrency target or opening the entire campus at once.

These tests do not simulate 1,000 physical browsers loading JavaScript/WASM assets, rendering forms, maintaining WebSockets, printing, or crossing campus Wi-Fi/VLANs. The role setup operations are exercised through application services, while authentication, evaluation submissions and report access are exercised over HTTP. This is not a claim that every administrative UI click was performed by 1,000 browsers.

## Changes made from the findings

- Evaluator-specific request limits, with an additional generous campus-network limit. Different students on a shared NAT address no longer consume one ten-request budget.
- Staff login rate limits include the account identifier, preserving failed-attempt limits without blocking unrelated staff on the same campus IP.
- SQLite's writer reservation is acquired before submission transaction reads on PHP 8.3; bounded transaction retries and fingerprint uniqueness prevent lock-upgrade failures and duplicate records.
- Sessions/cache moved to files to avoid unnecessary application-database write contention.
- Added indexes for student IDs, evaluator enrollment lookup, academic-period/department assignments, faculty instruments and evaluation-cycle type.
- Entry-page eager loading, runtime configuration caching, up to 16 PHP workers, longer gateway keepalive and a larger listener backlog.
- Fixed cycle closure's unloaded campus relation under strict loading.
- Fixed released personal faculty reports requiring the general staff results permission. Personal-results permission permits only the instructor's own released report; editing still requires staff reporting and update permissions.
- Test route/config/event caches and maintenance state are isolated from the running campus instance.
- Optional load-only request timing records no SQL text or student identities.

Automated verification: **413 tests / 1,898 assertions passed**, plus two runtime recovery tests. The new cases cover SQLite copying without overwrites/key changes, shared-campus rate limits and released faculty report authorization.

After the final report-signatory adjustment, the affected report/migration tests were rerun: **6 passed / 44 assertions**, including that personal-results access cannot edit reports or silently make the evaluated instructor their own preparer.

## Re-test, 25 October 2026

The harness now starts its PHP workers with the same settings as the campus launcher: OPcache (loaded with `zend_extension=opcache` when php.ini leaves it off, plus a file cache for Windows), a two-second file revalidation interval, and isolated event and Filament component caches. Earlier runs did not load OPcache when php.ini had it switched off, as Laragon's PHP 8.3 does, so the Windows figures above are probably slower than the real campus server. Re-run them on the server computer to confirm.

Linux container, 4 logical CPUs, 4 PHP workers, load generator on the same CPUs, 300 students, 7 requests each:

| Test | Students completing both forms | Requests/s | Student request p50 | p95 |
|---|---:|---:|---:|---:|
| 32 students at a time | 300 / 300 | 67 | 0.40 s | 1.15 s |
| All 300 within 3 seconds | 300 / 300 | 67 | 4.3 s | 5.6 s |

Inside Laravel, a request takes 15–25 ms (entry 14 ms, form 16 ms, submission 26 ms); framework start-up adds about 20 ms. The rest of the time is spent waiting for a free PHP worker, so a burst queues rather than failing. No errors, duplicates or lock failures occurred. Capacity grows with the number of PHP workers, and the launcher uses one per logical CPU, up to 16. In practice students take minutes to read and answer each form, so their requests spread out far more than in this test.

## Reproduce safely

Use Node 20+ and a new directory under `storage/app/load-tests`. The harness binds only loopback and refuses to overwrite an existing test database. It never replaces the live `.env` or database. The fixture command itself rejects the normal application environment and non-isolated databases.

First generate a reusable fixture and run the workflow:

```powershell
node scripts/evaluation-load-test.cjs --work=storage/app/load-tests/my-first-run --students=1000 --workers=16 --concurrency=64 --sessions=file --cache=file --configCache=1 --profile=1 --fullCycle=1
```

Then clone its pristine fixture into a fresh simultaneous run:

```powershell
node scripts/evaluation-load-test.cjs --work=storage/app/load-tests/my-burst-run --fixture=storage/app/load-tests/my-first-run/fixture-pristine.sqlite --workers=16 --concurrency=1000 --rampMs=5000 --sessions=file --cache=file --configCache=1 --profile=1 --fullCycle=1
```

Each directory contains `http-results.json`, `data-audit.json`, private test fixtures, worker logs and optional `php-metrics.jsonl`. Exit code is nonzero if any student flow or workflow check fails. Keep these directories private and out of source control. The test fixture uses a deliberately cheap password hash to reduce setup cost; live application password hashing is unchanged, so staff-login latency here is not a production password-hash benchmark.

## Deploy to the school server

Keep SQLite and its `-wal` / `-shm` companions on a **local server disk**, writable by the PHP account. Clients access HTTP; they never open the database file directly. Do not place an active WAL database on a shared SMB/NAS folder. SQLite WAL allows readers alongside a writer, but still permits only one writer at a time ([SQLite WAL documentation](https://www.sqlite.org/wal.html)).

For permanent hosting, configure Apache/PHP or Nginx/PHP-FPM with document root `public`, HTTPS, a reserved server IP/internal DNS name, persistent queue/scheduler/Reverb processes and appropriate worker/resource limits. Use `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL`, and the original application/fingerprint keys when moving existing data. Configure trusted proxies for the actual reverse proxy rather than trusting every client.

The Windows launcher is already usable for local/campus testing; it is not a substitute for the school's service management, TLS, power/reboot policy, backups or network routing. No external school server or physical remote device was available for this test. See [campus network configuration](CAMPUS-NETWORK.md).

Git carries code, **not** the ignored database, `.env`, uploads or SQL workspace data. Moving laptops therefore requires a verified backup/restore as well as pulling the code.

## Backups and migration

Use the application backup command, which takes a consistent SQLite `VACUUM INTO` snapshot rather than copying only the open database file:

```powershell
php artisan classroom:backup
php artisan classroom:restore "ABSOLUTE_PATH_TO_BACKUP.zip"
```

The second command verifies the archive without overwriting live data. `--stage=ABSOLUTE_NEW_DIRECTORY` stages a verified copy. Preserve the original `APP_KEY` securely; current backups need that key separately because a recovery passphrase has not been configured. Set `BACKUP_RECOVERY_PASSPHRASE` privately to include an encrypted key envelope in future backups; `--recover-key` can recover it during staging. Never regenerate encryption/fingerprint keys for existing data. Store backups outside the server's primary disk and restrict file access.

For another MySQL installation, take it offline, back it up, apply pending migrations, then use:

```powershell
php artisan classroom:sqlite-copy "ABSOLUTE_PATH_TO_NEW_SQLITE_FILE"
```

This verifies the copy but does not activate it. Only after successful verification should its `.env` point to the new SQLite file with the settings from `.env.example`. Preserve the original key, clear the default configuration cache and restart the server. The command refuses an existing destination and leaves the source database intact.
