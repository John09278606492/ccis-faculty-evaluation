# Windows release verification — 22 October 2026

- Desktop unit/archive tests: 6 passed.
- Related Laravel workflow/student database tests: 75 passed, 448 assertions.
- Clean installation integration: bundled PHP/MySQL, initial administrator, HTTP gateway, system database sign-on, student database creation and safe shutdown passed.
- Portable backup integration: restored into a second independent data folder; administrator, student SQL evidence and uploaded file retained.
- Packaged application integration: ASAR source matches release source; gateway runs using packaged Electron as Node; bundled PHP/MySQL serve the login page and database manager; restored student database configuration updates to the destination port; managed services stop successfully.

Repeat the packaged check after `npm run build`:

```powershell
node tests/packaged.cjs ".test-data/<successful-smoke-run>/Second laptop"
```

The installer is unsigned. Interactive installation/UAC and acceptance testing on a separate physical laptop have not been performed. Existing Laragon data is not automatically migrated; use the standalone backup/restore flow for subsequent laptop transfers.
