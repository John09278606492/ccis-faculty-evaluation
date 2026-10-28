# Hostinger deployment

Target: `https://faculty-evaluation.bisubalilihan.com`, Business Web Hosting.
The user confirmed the current computer is the authoritative source of records.

## Verified hosting capabilities

The protected server preflight returned PHP 8.3.36, pdo_sqlite, sqlite3, intl,
mbstring, ZIP, GD, DOM, curl, XML and fileinfo. SQLite in-memory queries succeeded;
symlink and flock are available. On-disk database locking and actual workload
capacity still require validation after staging. No database conversion is needed
solely because hPanel offers MariaDB databases.

## Files and records

Keep Laravel code, `.env`, SQLite and private uploads in a sibling `evaluation-app`
directory outside `public_html`. Only Laravel's public assets and an index pointing
to that sibling belong in `public_html`. Use the original application and evaluator
fingerprint keys. Verify snapshot record counts and encrypted payload compatibility.
Never upload the private migration archive into a public web directory. Never run
`key:generate`, `migrate:fresh` or demonstration seeders on the transferred data.

The Windows runtime ZIP and Windows automatic update controller do not run here.
Changes after the snapshot are not synchronized between separate installations.
Retain the old application and select one installation for new work at cutover.

## Hosting settings

Use production environment, HTTPS, debug off, secure session cookies, SQLite with
an absolute private database path, file cache/sessions and database queues.
Set `CLASSROOM_LIVE_TRANSPORT=polling` and `BROADCAST_CONNECTION=null` for this shared
hosting deployment. Open panel pages check for updates every 20 seconds without
opening unavailable WebSocket connections or forcing a page reload. This is not
instant server push. Default campus/Reverb behavior remains unchanged elsewhere.

Add three once-per-minute PHP cron commands in hPanel after staging, using the
account's PHP CLI executable (verify PHP >=8.2 and the required extensions):

```
evaluation-app/scripts/hostinger-maintain.php queue
evaluation-app/scripts/hostinger-maintain.php bulk
evaluation-app/scripts/hostinger-maintain.php schedule
```

Paths in the hPanel command must include `domains/<domain>/` relative to the
displayed account home prefix. Workers exit when idle and use separate locks so
a long import does not create overlapping bulk workers. Bulk jobs keep their
existing 900-second job timeout; the hosting account's resource/process limits
still apply. A queue job may wait until the next minute's cron invocation.

## Before publishing

Back up the existing target contents, test the staged Laravel boot, login, assets,
evaluation access, reports, uploads, queues, notifications and permissions, then
obtain approval for replacing the default site. Public student access currently
identifies students by ID only; decide on stronger verification before using it
for unrestricted public submissions. Configure email delivery separately if used.
Do not claim the site is deployed until the actual HTTPS domain passes checks.
