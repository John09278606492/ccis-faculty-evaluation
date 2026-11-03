# CCIS Classroom — self-contained Windows application

This is the standalone replacement for the original desktop host's external Laragon/project configuration. It bundles Electron, PHP, the classroom application and its dependencies, MySQL, phpMyAdmin and the Microsoft runtime prerequisite. Users do not need to clone the repository, install Composer/Node.js, install Laragon or create a database.

## Install and use

1. Run `dist/CCIS-Classroom-2.0.0-Windows-x64-Setup.exe`. The installer supports an installation directory under Program Files and installs the Microsoft C++ prerequisite when absent.
2. Open **CCIS Classroom**. Enter the first administrator's name, email and password (12 characters minimum), then choose **Install my classroom**.
3. Choose **Start classroom**, then **Open system**. The default local address is `http://127.0.0.1:18080`.
4. Students use the displayed network link from browsers on the same classroom network. The desktop window stays open while they work. Windows Firewall may require private-network permission.
5. **Manage database** opens a local-only phpMyAdmin session for the system database. Students receive their own database access through their assessment, never through the system database manager.
6. **Stop safely** flushes and shuts down the managed databases and gateway. Closing the desktop window also performs this shutdown and waits for ongoing management operations.

## Move to another laptop

1. Choose **Create backup** and save the ZIP outside the application data folder, ideally on another drive. Creating a portable backup stops the classroom so both MySQL data directories are consistent.
2. Install the same application on the new Windows x64 laptop.
3. Choose **Restore backup** before creating a new classroom, select that ZIP, then **Start classroom**. Records, administrator/student accounts, uploads, student workspaces and encryption keys are retained.

Restoring an existing installation preserves its previous data in a sibling `before-restore` directory. Backups contain private records and credentials; treat the ZIP as private. This restore supports the standalone desktop backup format with the bundled MySQL 8.0 series. The older `classroom:backup` SQL/files ZIP is a different format and is deliberately rejected rather than partially restored. Existing Laragon databases are not silently imported or modified by this installer.

## Data and service management

- Installed binaries/resources remain under the chosen installation folder.
- Writable classroom files, MySQL data, .env and logs live under Electron's per-user application data directory, in `classroom-data`. **Open data & logs folder** shows the exact location.
- The installed application does not depend on C:\laragon, a D: drive, PHP on PATH, a system MySQL service or the source checkout.
- Runtime PHP, MySQL and phpMyAdmin paths come from the installed resource directory. Restores regenerate paths and ports for the destination laptop.
- The system MySQL server listens only on loopback, on classroom port + 1. The student server listens only on loopback, on port + 2. The system database manager listens only on loopback, on port + 3.
- The classroom gateway is the only LAN-facing service. Reverb, PHP workers, the scheduler and queue worker run behind it. Only managed child processes are stopped; occupied ports produce an error and are not reclaimed from other applications.
- Startup runs migrations/seeding and preserves existing administrator accounts. Source updates are refreshed from the installed release without copying a release .env over your instance.
- The Node control window uses a sandboxed renderer, context isolation and a narrow IPC interface. It never embeds remote pages with Node privileges. Database credentials are not exposed to the renderer.

## Build from source (developers only)

Requires Windows x64, Node.js 24+, installed main-project Composer dependencies and a completed main-project asset/code-runner build. Runtime source defaults point at the development computer's existing Laragon files. Override `CCIS_PHP_SOURCE`, `CCIS_MYSQL_SOURCE` and `CCIS_PHPMYADMIN_SOURCE` to use other complete runtime distributions. These paths are build inputs only and are not required on the installed laptop.

```powershell
cd desktop-host/standalone
npm ci
node node_modules/electron/install.js
npm run prepare:bundle
npm test
node tests/smoke.cjs
npm run build
```

`prepare:bundle` refuses to overwrite an existing bundle; rename the previous bundle before preparing another release. It allowlists application source/dependencies and excludes .env, uploaded records, database files, public/storage, build.old and compiled bootstrap caches. Microsoft VC++ redistributable is downloaded from Microsoft's official endpoint and its Authenticode signature is checked.

Third-party license notices are preserved in the bundled PHP, MySQL, phpMyAdmin and dependency directories. The installer is currently unsigned; code signing requires the publisher's signing identity.

## Verification

`tests/runtime.test.cjs` checks port validation, dotenv escaping, path containment and operation serialization. `tests/backup.test.cjs` exercises the real PHP archive helper, checks preservation of empty folders and rejects ZIP traversal. `tests/smoke.cjs` performs a clean install with bundled binaries, starts HTTP/database services, creates student database and uploaded-file evidence, makes a cold backup, restores into another folder, verifies retained data and stops cleanly. A fresh physical laptop and the interactive Windows installer/UAC flow still require separate acceptance testing.
