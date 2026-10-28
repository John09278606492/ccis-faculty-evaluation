# Automatic campus updates

The new Windows campus package checks the configured GitHub branch every five
minutes while the server runs. It needs outbound internet access to GitHub and
GitHub release assets; it does not open an inbound webhook or require Git on the
server. The campus website continues using its installed version while offline,
while CI is testing a push, or if a download/validation fails.

## What users experience

Compatible updates are prepared beside the running code. A permanent gateway
keeps the public address and listener open, warms a separate set of private PHP
workers, checks `/up` and `/login`, and changes the active pointer only when ready.
HTTP requests already running complete on their original workers and are never
replayed. Open pages tag their form, upload and Livewire requests with the version
that rendered them. Old workers remain available while those pages are active;
new page navigation uses the new version without a required refresh or login.

One stable Reverb process keeps live-data connections available across the web
version switch. Only one version owns queue workers and scheduling. Existing
background jobs and scheduled tasks finish before the new version takes over;
web requests keep working during that wait. Private drafts, sessions, uploads,
database path, encryption key and anonymous-evaluation key remain shared.

If the new version stops passing health checks shortly after activation, the
previous healthy code is selected again. Rollback never replaces the database
with an older snapshot, so newly submitted evaluations are retained.

## What is deliberately held

This is not a promise that arbitrary application changes, Windows reboots or
database rewrites can be performed with zero downtime. Automatic deployment
requires protocol 1, a backward-compatible release declaration, no unapplied
migrations and the same migration source signature as the running version.
Schema changes, incompatible bootstrap/runtime changes and unsupported packages
stay pending while the current website remains available. Database migrations
are never silently forced, demo seeders never run, and original keys never change.
Campus IT/developers must plan and test the appropriate maintenance procedure for
such a release. A new transfer ZIP must never overwrite newer server records.

The initial installation of this update controller is also a one-time deployment.
The older transfer ZIP did not contain it. Use the updated package for the server;
the new package includes `campus-updates.json` with automatic checks enabled.
The ordinary source checkout leaves checks disabled unless explicitly configured.

## GitHub release pipeline

`.github/workflows/campus-release.yml` runs on pushes to
`claude/project-thread-zjbov2`. It runs the application and JavaScript tests, builds
browser assets, installs production dependencies from the lockfile, and publishes
a code-only package tagged `campus-<full commit SHA>`. A draft release is published
only after both the ZIP and its metadata are uploaded. Already published packages
for a commit are immutable. Failed CI does not produce a deployable package.

The archive contains code, dependencies and browser assets. It excludes `.env`,
databases, uploaded files, sessions, runtime binaries and local deployment state.
The server verifies the archive checksum, every file checksum, commit identity,
deployment protocol, schema signature, shared database and key fingerprints.
Traversal, symbolic links, Windows alternate streams, duplicate names and oversized
archives are rejected before extraction. Staging does not overwrite the live tree.

`campus-release-policy.json` is an engineering contract, not a switch to bypass
validation. Future contributors must keep old page submissions and queued payloads
compatible, preserve shared-data behavior, and set `backwardCompatible` to false
for a release that cannot safely coexist. Changing migrations automatically holds
the release regardless of that declaration. Do not claim compatibility for an
arbitrary breaking change merely to make the updater install it.

## Deployment state and administration

- `campus-updates.json`: repository, branch, five-minute interval and enabled flag.
- `storage/app/campus-updates/update.json`: current/pending state and explanation.
- `storage/app/campus-updates/current.json`: atomic active-code pointer, restored
  when the controller starts again.
- `.campus-releases/<commit>/`: isolated downloaded code. These folders contain
  links to shared storage and must not be copied over the database directory.
- `storage/app/campus-updates/backups/`: consistent pre-switch SQLite snapshots.
- `storage/logs/campus-*.log` and `release-<commit>.log`: private service diagnostics.
- **Administration → System Health & Recovery**: administrator-only update status.

The public campus port and Windows Firewall program path remain stable. Application
workers and release preparation stay on loopback. The token, if one is needed for
a private repository, belongs in `storage/app/private/campus-github-token.txt` with
repository Contents read permission; never commit it or put it in a URL. The
current repository is readable publicly and does not require a download token.
Authenticated download headers are never forwarded to another asset host.

Update checks respect GitHub conditional responses, rate limits and backoff.
Failed candidates are retained for diagnosis. Old code is retained on disk; unused
web workers are stopped after leases drain, with a 24-hour upper bound on retired
versions; older pages then use the declared backward-compatible active handlers.
This bound does not terminate an in-flight request. The controller limits simultaneously
running versions and waits if old pages or insufficient disk space prevent staging.
Plan disk retention with IT; keep independent daily data backups on another drive.

References: [GitHub commit checks](https://docs.github.com/en/rest/commits/commits),
[GitHub conditional requests](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api),
[Laravel deployment and long-running workers](https://laravel.com/docs/12.x/deployment).
