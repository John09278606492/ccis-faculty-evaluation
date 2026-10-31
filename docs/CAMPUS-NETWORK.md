# Campus network access

## On the server computer

1. Keep `database/campus.sqlite` on the server's local disk. Application data uses SQLite; a main MySQL service is no longer required. This remains the only computer that needs the project, PHP and Node.js. SQL coding assessments use their separate student MySQL service.
2. Double-click **Start-Campus.cmd**. It starts the web-only gateway on TCP 8020, prints the server's current LAN addresses and opens the browser at localhost automatically. The server computer keeps using localhost when Wi-Fi changes. Start-Local.cmd remains available for local development on 8010.
3. Once, right-click **Enable-Campus-Access.cmd → Run as administrator** to allow TCP 8020 from the local subnet. Only the Node gateway program and this web port are allowed. Firewall settings are not disabled.
4. Give students one of the printed LAN links. They need a browser and network connectivity to the server. They do not install the project, Laragon or a database.
5. Use **Stop-Campus.cmd** for clean shutdown. Closing the launcher window leaves services running. A pre-existing student MySQL server is reused after credential verification and is not stopped by the campus launcher.

All users share the same application and records. WebSocket live updates and student phpMyAdmin use the same host and port as the web page. Generated links use the request's host. Student MySQL, PHP workers and the underlying Reverb service remain loopback-only. The launcher's firewall script does not open the existing main MySQL service. The campus process disables application debug output without changing development .env settings.

The campus gateway is a Windows LAN launcher with up to 16 PHP workers. The [SQLite load-test report](SQLITE-DEPLOYMENT-AND-LOAD-TEST.md) records local tests with 1,000 students, including queueing at peak load. Size/test the school server against its actual simultaneous usage and network.

## Stable campus address

Student devices can keep DHCP/dynamic addresses. The **server** should have a DHCP reservation (or an IT-assigned static address outside conflicting leases). Ask campus IT to create an internal DNS record such as `evaluation.school.edu.ph` pointing at that server. All campus DNS clients can then keep using the same name. No hosts-file edits are needed on every student laptop.

The launcher discovers current adapter addresses each time it runs, and the running gateway updates its status links within five seconds of an address change. It never hardcodes a particular laptop IP. If the server's DHCP lease changes, users on other devices must open the new numeric link; reloading the old address cannot reach the server. Detection does not update campus DNS or DHCP reservations.

## Across buildings / VLANs

Sharing the same school internet connection does not guarantee connectivity. IT must route the relevant campus VLANs to the server and allow the application port. Guest/client isolation may need an exception for this server. The default firewall rule uses LocalSubnet and does not automatically permit other routed VLANs.

In an elevated PowerShell, IT can configure the approved networks explicitly (replace the example networks):

```powershell
.\scripts\Enable-Campus-Access.ps1 -Port 8020 -AllowedNetworks '10.20.0.0/16','10.30.0.0/16'
```

Do not forward this port on the internet router for a campus-only deployment.

## Permanent school server

Use the reserved IP/internal DNS name with HTTPS and a managed Apache/Nginx + PHP-FPM (or Windows Apache/PHP) deployment. Configure the document root to `public`, persistent queue/scheduler/Reverb services, reverse proxy WebSockets, backups and startup after reboot. Database credentials stay on the server. A school-trusted certificate must be trusted by client devices. Actual DNS, certificates, VLAN/firewall policies and production capacity require the school's network/server configuration.

The old Laragon main MySQL database is retained as the pre-migration source. The application now uses SQLite; no main database network listener is needed. The launcher's optional student SQL service stays on loopback.

The launcher rebuilds its own configuration cache at startup so the current address and SQLite settings apply to every worker. If startup detects an unrelated default configuration cache, run `php artisan config:clear` from the project before starting.

## Diagnostics

- Server can open localhost but students cannot: check the firewall rule, Wi-Fi client isolation and VLAN routing.
- Use the **server's** displayed address, not localhost/127.0.0.1 on a student device.
- Status: `storage/app/campus-server.json`. Logs: `storage/logs/campus.log`, `campus-error.log`, `host-child-*.log` and Laravel logs.
- Advanced alternative port: `powershell -ExecutionPolicy Bypass -File .\Start-Campus.ps1 -Port 8040`; use the same port in the firewall script.
- Remove the rule later from an elevated PowerShell: `Remove-NetFirewallRule -Name CCIS-Classroom-Campus-8020`.

References: [Laravel Reverb deployment](https://laravel.com/docs/12.x/reverb), [Microsoft scoped firewall rules](https://learn.microsoft.com/en-us/powershell/module/netsecurity/new-netfirewallrule).

## Local verification — 22 October 2026

- Gateway listening on `0.0.0.0:8020`; internal PHP/Reverb/phpMyAdmin workers on loopback.
- LAN-address login returned HTTP 200 with no old localhost-only links or debug toolbar.
- WebSocket connection established through the same LAN port; student phpMyAdmin returned its expected sign-on redirect through that port.
- 20 simultaneous login requests returned HTTP 200 in a same-computer smoke check. This is not a campus capacity benchmark.
- Stop/restart succeeded, releasing the gateway/workers and leaving the pre-existing development web server and student MySQL process intact.
- Related tests: 22 passed; after adding credential-verification tests the student database suite passed 10 tests / 42 assertions.
- The current shell has no administrator token, so the firewall rule has **not** been installed. Run the administrator step above before testing from another device. No remote-device or inter-VLAN access has been verified.

## Connection recovery

Repeated `ws://` failures together with `livewire/update` connection timeouts can
mean the browser is still using the server's previous IP. Check the current link
in the launcher or `storage/app/campus-server.json`. Use localhost only on the
server computer. HTTP campus connections use the plain WebSocket transport;
HTTPS deployments use the same transport with TLS enabled.

Background refreshes pause while navigating. During a live-connection outage, a
bounded health check runs before fallback refreshes so unreachable addresses do
not accumulate component refresh requests. Saved work remains in the database.

## Local verification — 27 October 2026

The pasted console logs referred to `172.16.98.136`, while the active Wi-Fi address
was `172.16.96.100`. HTTP and WebSocket handshakes succeeded at the current address.
After a managed restart, the status file reported the current sharing link and
healthy services. The unrelated assessment server on port 8100 was left running.

With the existing data (8 offerings, 20 sections, 2,337 user records and 484 roster
memberships), a browser check measured main-page navigation at 376–1,021 ms after
initial load. Opening the largest class editor (235 students) took 1,514 ms; leaving
it took 486 ms. There were no failed requests or browser/socket errors in that run.
These are same-computer checks through the LAN address, not a campus load test.

A simulated unavailable health endpoint produced zero fallback component refreshes
and a connection-loss message; restoring connectivity returned the live indicator
to connected. Related validation: 43 PHP tests / 246 assertions and 15 JavaScript
tests passed. Receipts are in `storage/app/maintenance/navigation-network-after.json`
and `connection-recovery-receipt.json`; test logs are in `storage/logs/`.
