# Coding exam load test — 25 October 2026

A 4th-year class (BSIT 4A, *IT 411 Web Systems and Technologies 2*) takes a 90-minute coding exam at the same time: a PHP project question (3 starter files), a web page question (HTML/CSS/JS) and one short answer. Every student is a separate browser session with its own address, cookies and live-updates WebSocket.

## What each student does

1. Signs in with their student ID and instructor-issued PIN.
2. Opens the exam page and downloads its scripts, styles and the PHP runtime used by **Run** (7.15 MB compressed per student, once; the browser keeps it).
3. Joins live updates (campus, panel and personal channels) like the real page.
4. Presses **Start** when the instructor opens the exam (all within 10 seconds).
5. Types for four minutes: the editor saves the whole project after each pause, about every 5 seconds per student, growing the files and sometimes adding a new file. Saves average 6.7 KB.
6. One in four pastes text from outside; one in five leaves the tab and comes back (exam supervision log and instructor bell).
7. A third hand in early with **Submit**; the rest are submitted by the timer in the same second.

The instructor keeps the Attempts tab open the whole time, which reloads on every live update as in the browser. **Run** itself executes in the student's browser, so after the runtime download it adds no server work.

After the run the harness checks the database: every student's last saved files must match exactly what was stored, every attempt must be submitted once, and SQLite `integrity_check` must pass.

## Results

Linux container, 4 CPUs, so 4 PHP workers (the campus launcher uses one per CPU, up to 16), with the simulated students on the same CPUs. The campus launcher's own gateway, Reverb and queue workers were used (`desktop-host/resources/server/host.cjs`).

| | 60 students, before fixes | 60 students, after | 120 students, after |
|---|---:|---:|---:|
| Attempts started and submitted | **26 / 60** | 59 / 60 ¹ | 119 / 120 ¹ |
| Saved answers matching the student's last save | 52 / 52 | 118 / 118 | 238 / 238 |
| Code saves: p50 / p95 | 40 / 54 ms | 38 / 56 ms | 38 / 56 ms |
| Start: p50 / p95 | 429 / 688 ms | 69 / 104 ms | 65 / 98 ms |
| Extra page refreshes caused by classmates | 1,296 requests | 178 | 120 |
| Timer submit, two thirds of the class at once: p50 / p95 | — | 254 / 653 ms | 361 / 1,186 ms |
| All requests: p50 / p95 | 53 / 645 ms | 41 / 128 ms | 40 / 111 ms |

¹ The one missing student each time got a 503 when a PHP worker crashed inside OPcache and restarted a second later. That is a known fault of this container's PHP 8.3.6 build, not of the application; in a browser the student simply presses Start again (the same attempt resumes). The campus PC runs PHP 8.3.35.

## Problems found and fixed

**Starts, saves and submits could fail with "database is locked".** Already with three students. SQLite ignores `lockForUpdate()`, and PHP 8.3 cannot begin an immediate transaction, so a transaction that read the attempt and then wrote failed at once when a classmate saved in between, instead of waiting. The editor would show "Not saved" and retry. Start, every autosave, submit and manual grading now take SQLite's writer reservation first (`App\Support\SqliteWriter`), as evaluation submissions already did, so requests queue for a few milliseconds instead.

**Every Start and Submit made every classmate's page call the server.** Attempt changes were announced on the campus channel that students share, and each student's page answered with a server request, so a class starting together sent students × students requests (60 students: 1,296 extra requests in a minute). That made Start take up to 0.8 s and, worse, when such a refresh was waiting in the same request as **Start**, the page did not re-render: 34 of 60 students saw Start do nothing. Students now ignore classmates' attempt updates; a student's own attempt changes reach only them on their private channel, and not the page that made the change.

## Remaining limits

- **Timer submit.** When most of the class is submitted by the timer in the same second, the last ones wait about a second on 4 workers while the requests queue. Nothing is lost: answers saved before the deadline are kept and the submission time is the deadline. The school PC's 16 workers should make this about four times shorter.
- **Network.** 120 students downloading the PHP runtime is about 860 MB. On classroom Wi-Fi this, not the server, is the bottleneck. Have students open the exam page a few minutes before it starts: the waiting room downloads the runtime in the background, and the browser keeps it for later exams.
- Live updates messages: about 78,000 small WebSocket messages for 120 students over the exam (progress and tab-leave notices reach every open page, which ignores them). Reverb handled them without errors.
- SQL questions with MySQL workspaces were not part of this test.

## A 3-hour exam with 50 students

A 180-minute exam, tested at its heaviest point, the end: each student's two projects start at about 38 KB and grow to the 50,000-character limit while they save every ~5 seconds for 15 minutes.

| | Result |
|---|---:|
| Attempts started and submitted | 50 / 50 |
| Saved answers matching the student's last save | 100 / 100 |
| Code saves | 8,937, p50 44 ms / p95 60 ms, no errors |
| Size of one save near the end | about 140 KB |
| Timer submit (two thirds at once): p50 / p95 | 195 / 665 ms |
| Database after the exam | 5.8 MB, `integrity_check` ok |

Saves replace the stored answer rather than adding to it, so the database does not grow with exam length. Typing keeps each student signed in (sessions last 120 minutes after the last request); the server closes attempts when the timer ends even if a browser is closed, keeping everything already saved. On Wi-Fi, 50 students saving large projects send about 1.4 MB per second in total near the end of the exam.

## Reproduce

Run it against a server whose `.env` points at an isolated database under `storage/app/load-tests` with `APP_ENV=loadtesting`, never the live `database/campus.sqlite`. The fixture command refuses any other database.

```bash
touch storage/app/load-tests/coding/assessment.sqlite
php artisan migrate --force
php artisan classroom:assessment-load-fixture --students=120 --manifest=storage/app/load-tests/coding/fixture.json
node desktop-host/resources/server/host.cjs "$PWD" "$(which php)" 8300 storage/app/load-tests/host-status.json
node scripts/assessment-load-test.cjs --url=http://127.0.0.1:8300 --manifest=storage/app/load-tests/coding/fixture.json \
  --minutes=4 --saveEvery=5000 --rampMs=30000 --db=storage/app/load-tests/coding/assessment.sqlite --out=storage/app/load-tests/coding/result.json
```

Options: `--students` (fewer than the fixture), `--minutes` of typing, `--saveEvery` (average milliseconds between saves per student), `--rampMs` (sign-in spread), `--startSpreadMs` (Start spread), `--startKb` (start each project at this size, as late in a long exam), `--ws=0` (no live updates). The exit code is nonzero when any student fails or the data audit finds a difference.
