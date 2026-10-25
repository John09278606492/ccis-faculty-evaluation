# Laragon setup for the Codex project

Laragon is already installed at `C:\laragon`. The project source remains in this Codex workspace.

## Addresses

- Application: http://ccis-codex.localhost
- Administrator database manager: http://localhost/phpmyadmin/
- Student database interface: http://ccis-codex.localhost/student-phpmyadmin/ (open from the assessment's Open phpMyAdmin button to sign in).

The Apache virtual host is `C:\laragon\etc\apache2\sites-enabled\ccis-codex.conf`. It points only at this project's public directory. Access is local to this computer. The existing `ccis-faculty-evaluation.test` site is a different project copy and was not changed.

## Databases

- System records: `ccis_codex_session` on the Laragon MySQL instance at 127.0.0.1:3306.
- App login: `ccis_codex_app`, restricted to that database. Its generated password is stored in the ignored .env file. The application no longer uses the root database account.
- Student workspaces: separate MySQL at 127.0.0.1:3317. Student credentials stay scoped to their own workspace databases. Student phpMyAdmin is served by Apache through a dedicated alias and signs in through the application.
- To manage system data, use the existing Laragon administrator login in http://localhost/phpmyadmin/ and choose `ccis_codex_session`. Do not replace or drop the database.

## Starting

1. Open Laragon and start Apache and MySQL.
2. After adding or changing the virtual host, use Laragon's Reload control.
3. Run Start-Local.ps1 from this project for Reverb and the student MySQL companion services. The fallback development server on port 8010 remains available, but use the Laragon application address above for the integrated student database interface.
4. For queued work, run `php artisan queue:work` in a terminal in this project.

The `.localhost` name resolves to loopback in modern browsers without editing the Windows hosts file.

## Backup and activation status

Before configuration, the previous .env and Laragon settings were backed up under `storage/app/laragon-config-backup/`. These contain private configuration and must not be published.

Apache configuration syntax passed, the dedicated application database account connected successfully and migration status remained intact. The existing Laragon phpMyAdmin returned HTTP 200. Activating the new virtual host requires Reload in Laragon: automatic execution of the Apache process restart was blocked by approval review. Browser verification of the new address is pending that reload.
