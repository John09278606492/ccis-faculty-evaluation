# Revised local workflow — 23 October 2026

This guide supersedes earlier navigation and evaluation sign-in instructions in WORKSPACE-UI.md.

## Daily evaluation work

1. Start **Start-Campus.cmd**. It starts the web services and opens the browser. Share the address printed by the launcher; the laptop's LAN address can change between networks. Use a campus DNS name and reserved server address for a permanent deployment.
2. Select the campus and academic period in the context strip. Permissions determine actions; campus membership and assigned departments determine records. Administration > Access & Assignments manages explicit scope and records an audit trail. Removing a department never creates campus-wide access.
3. Under Academic Management, manage Students and Teaching Classes. Import students with Upload → Review → Commit. Section membership belongs to an academic period; irregular students can belong to several sections. The year stays in the section label.
4. Configure the department's supervisor and chairperson. Under Faculty Evaluation, create a draft Evaluation Cycle, select Annex A/B versions and teaching classes, then manage evaluators. Add Sections / Custom Students supports additions, replacement and exclusions with a review step; each student appears once per assignment.
5. Review and publish the cycle. Readiness checks require rating criteria, explicitly selected nonself supervisor evaluators and rosters. Publishing explicitly prepares Annex B tasks. Supervisor tasks do not get created merely by opening a page.
6. Students enter their ID at `/evaluations/start`, without assessment PIN or approval. Heads use My Faculty Evaluations. Monitor Student Progress distinguishes open follow-up work from work that is not open or has closed.
7. Faculty Results is the primary reporting workspace: filtered scores, anonymous tally, CSV and Annex C/D preview. Individual Evaluation Copies supports Annex A/B selected/bulk printing. Identity remains hidden unless explicitly requested by an authorized user. SET and SEF remain separate.
8. Close and archive cycles. Published instruments are locked; duplicate as a new version to change criteria. New submissions retain an instrument snapshot. Existing historical responses remain intact.

Teaching Classes reuses the existing course-offering records for compatibility with assessments. A class record currently belongs to one evaluation cycle; create a new period/class record for another cycle. Legacy assignments remain accessible through their existing routes.

## Assessments and health

Assessment sign-in approval is request-specific. Expired attempts are finalized by the server maintenance job even when a browser closes. SQL evidence has pending/ready/failed status, retries and verification; pruning refuses unverified evidence. The shared host restarts failed workers with bounded retries and reports degraded services rather than silently routing to dead workers.

Super administrators can inspect Administration > System Health & Recovery. The scheduler must remain running for expiry sweeps, snapshot recovery and scheduled backups.

## Backup and recovery

`php artisan classroom:backup` creates a versioned ZIP containing the main database, uploaded files, retained student SQL workspaces and a manifest with SHA-256 hashes. A workspace export failure fails the backup rather than silently producing an incomplete recovery bundle.

Set `BACKUP_RECOVERY_PASSPHRASE` privately in the ignored `.env` (at least 12 characters) to include an encrypted recovery envelope for the original APP_KEY. Keep that passphrase separately. Without an envelope, supply the original APP_KEY separately during recovery; generating a new key cannot decrypt existing evaluator identities or database credentials.

Verify a new backup:

```powershell
php artisan classroom:restore "C:\path\ccis-backup.zip"
```

Stage verified recovery files in a new empty location:

```powershell
php artisan classroom:restore "C:\path\ccis-backup.zip" --stage="C:\Recovery\FacultyEvaluation" --recover-key
```

The command asks privately for the passphrase, checks paths and hashes, and writes RESTORE-INSTRUCTIONS.md. It does not overwrite the running database. Rehearse the staged import against an isolated database before a real server migration. Keep recovery files private; they contain institutional data and credentials.

Automated isolated tests exercise encrypted record recovery and SQL archive preservation. A live-machine restore and a physical printer check remain operational acceptance checks for the campus deployment.

## Verified implementation

On 23 October 2026: the full PHP suite passed **408 tests / 1,855 assertions**; both runtime restart-policy tests passed; the production asset build and Blade compilation passed. All migrations are applied. Browser checks verified scoped student progress (the previous 0/0 case now shows 2/2), supervisor tasks, visible period filters, and Annex C/D inside a modal. A real HTTP Livewire regression test covers department options after hydration; the middleware retains its scoped actor through rendering. The campus supervisor was restarted with the updated runtime and reported healthy services.

The current laptop LAN address during verification was `http://192.168.1.8:8020`; `http://127.0.0.1:8020` remains local to the host. Use the launcher's displayed address after moving networks.
