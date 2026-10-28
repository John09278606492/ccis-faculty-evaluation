# Move the evaluation system without starting again

The application uses **SQLite**. Move the existing database, uploads and original
application encryption key together. GitHub carries application code; it does not
carry your student records or private configuration.

## Windows server: prepared private transfer ZIP

The transfer ZIP prepared for this move includes the application, installed PHP
dependencies, browser assets, a consistent `database/campus.sqlite` snapshot,
uploaded/private files, a destination-ready `.env` preserving the original
`APP_KEY`, and portable PHP/Node runtimes. It is a folder-based server package;
there is no desktop application or installer. Keep it private and out of GitHub.

1. In AnyDesk, use **File Transfer / File Manager** to copy the private ZIP to the
   campus server. Extract it to a **new** local folder, for example
   `C:\CampusEvaluation`. Do not extract over an existing installation or database.
2. Double-click **Verify-Transfer.cmd**. It checks the database, encryption key and
   transferred file checksums. It does not reset records or generate a new key.
3. Double-click **Start-Campus.cmd**. The supplied runtimes are used automatically.
   If Windows reports a missing Microsoft Visual C++ runtime, campus IT must install
   the matching Microsoft x64 prerequisite before PHP can run.
4. Right-click **Enable-Campus-Access.cmd → Run as administrator** once. Give
   students the server network link displayed by the launcher. Their phones and
   laptops only need browsers.
5. Sign in with the existing account. Check students, sections, teaching classes,
   evaluation status, results, uploads and a supervisor account before cutover.

The package is a snapshot at the timestamp recorded in `TRANSFER-MANIFEST.json`.
It does not automatically copy changes made on the old laptop afterward. Before
the final move, finish background tasks, arrange a quiet window, stop accepting
work on the old host and make a fresh transfer snapshot. Keep the old installation
intact as a fallback, but use only the campus server for new work after cutover.
Do not run two independent database copies as if they shared updates.

The evaluation package preserves the main database and its stored records. If
using live assessment SQL workspaces as well, export/restore those separately
using `classroom:backup`; their separate MySQL data is not the evaluation SQLite
database. This package targets the evaluation deployment.

## Network and server requirements

The server keeps the SQLite file on its **local disk**, outside OneDrive, network
shares and shared folders. Only the application accesses that file. Client devices
access the website; they never open the SQLite file directly.

Ask campus IT for a reserved server IP and preferably an internal DNS name.
Students can keep dynamic IP addresses. Campus Wi-Fi/VLAN routing and client
isolation must allow access to the server's application port (default 8020).
The default firewall rule permits the local subnet; routed campus VLANs need
explicit approved network ranges. See [campus networking](CAMPUS-NETWORK.md).

A Windows computer running the Wi-Fi controller can also host the application if
IT approves the available resources and network access. This package does not
change the Wi-Fi controller or router settings. An actual router appliance or a
Linux controller requires a different deployment procedure.

AnyDesk is for remote administration and file transfer. Closing AnyDesk does not
stop the application, but the server PC must remain powered on and awake. Use
**Stop-Campus.cmd** for a clean application shutdown. IT should configure startup
after reboot and HTTPS before ongoing campus production use. PHP workers,
background processing, the scheduler and live updates are managed by the launcher.

## Later application updates from GitHub

The deployment branch currently used by this workspace is
`claude/project-thread-zjbov2`. Do not assume the repository's `main` branch contains
these newer features. Use the exact branch/commit recorded in the transfer
manifest. A source-only checkout can use:

```powershell
git clone --branch claude/project-thread-zjbov2 https://github.com/John09278606492/ccis-faculty-evaluation.git C:\CampusEvaluationSource
```

For an established server, back up first and stop the application. Update the
code and locked dependencies while retaining `.env`, `database/campus.sqlite`,
`storage/app/private`, and `storage/app/public`. Run pending migrations, which
must preserve existing data, then restart and verify. Do not use `migrate:fresh`,
database reset commands, demo seeders or `key:generate` on the transferred data.
Do not unzip a first-install transfer snapshot over a newer live server database.

Make daily backups to another disk/device; a backup on the same failing server
disk is insufficient. The existing backup/recovery commands verify database and
file integrity. Original-key recovery needs either the retained `.env` or a
configured protected recovery envelope. See `config/backup.php` and
`php artisan help classroom:restore`.

References: [AnyDesk file transfer](https://support.anydesk.com/docs/file-manager-and-file-transfer),
[SQLite online snapshots](https://www.sqlite.org/backup.html),
[Laravel encryption keys](https://laravel.com/docs/12.x/encryption).
