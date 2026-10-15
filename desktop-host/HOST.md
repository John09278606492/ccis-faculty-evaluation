# CCIS Classroom Server

Open `nativephp/electron/dist/win-unpacked/ccis-classroom-server.exe` on the main computer, or install `nativephp/electron/dist/CCIS Classroom Server-1.0.0-setup.exe`. Keep the entire win-unpacked folder together when using the runnable app. This installer is unsigned.

The server starts automatically. Wait for **Live · ready**, then **Copy link**. Other devices open that link in their browser and use their existing accounts. **Open system** opens the system on the host computer.

Keep the desktop app open, the computer awake, and MySQL running. A wired host can serve phones on the same router's Wi-Fi. Allow the app on your Windows private network if prompted. Guest Wi-Fi may isolate devices. Restart the server after changing networks and copy the new link.

## Existing records

This controller uses the existing classroom project at `C:\dev\laravel projects\ccis-faculty-evaluation`, PHP at `C:\php\php.exe`, and that project's MySQL database and workbook storage. It does not transfer those records or install MySQL on another computer. Stop the server before changing paths, the sharing port or automatic startup under **Server settings**.

## Operation

The public gateway shares HTTP and WebSockets on port 8088. Four PHP workers and Reverb run on loopback behind it. NativePHP's desktop controls stay private to the host. Closing the desktop app stops its managed process tree. Startup checks the database and ports without killing unrelated applications. NativePHP's environment is stripped before launching the classroom workers so its SQLite settings and encryption key cannot override the classroom configuration.

The classroom installation requires its Composer dependencies, Vite build, `.env` and Reverb credentials. Leave its configuration uncached because the desktop host supplies runtime ports. The local process-status indicator checks every five seconds; classroom data updates through WebSocket events.

## Development

Install Composer dependencies, copy `.env.example` only if `.env` is absent, and generate the application key. Requires PHP 8.3+, Node.js 22+ and Windows 10+ for this Windows build.

```powershell
php -d opcache.enable_cli=0 artisan native:install --no-interaction
php -d opcache.enable_cli=0 artisan native:run
```

Close the app before building because Windows locks running Electron files.

```powershell
php -d opcache.enable_cli=0 artisan native:build win x64 --no-interaction
php -d opcache.enable_cli=0 vendor/bin/phpunit
```

## Verification

Packaged startup, stop, restart and exit cleanup were checked. Two authenticated browser sessions received push events through the same LAN port. Main-project tests: 44 tests, 252 assertions. Desktop tests: 2 tests, 5 assertions. A separate physical phone was not available for testing; router/firewall configuration may affect its access.

[NativePHP documentation](https://nativephp.com/docs/desktop/2/getting-started/installation)
