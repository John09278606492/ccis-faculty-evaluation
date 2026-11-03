$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$phpExe = (Get-Command php).Source
$studentAlreadyRunning = [bool] (Get-NetTCPConnection -State Listen -LocalPort 3317 -ErrorAction SilentlyContinue)
$services = @(
    @{ Name='web'; Port=8010; Args='-d opcache.enable_cli=0 artisan serve --host=127.0.0.1 --port=8010' },
    @{ Name='reverb'; Port=8105; Args='-d opcache.enable_cli=0 artisan reverb:start --host=127.0.0.1 --port=8105' },
    @{ Name='student-db'; Port=3317; Args='-d opcache.enable_cli=0 artisan classroom:student-db serve --phpmyadmin-port=8096' }
)
foreach ($service in $services) {
    if (-not (Get-NetTCPConnection -State Listen -LocalPort $service.Port -ErrorAction SilentlyContinue)) {
        Start-Process -FilePath $phpExe -ArgumentList $service.Args -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput "storage/logs/local-$($service.Name).log" -RedirectStandardError "storage/logs/local-$($service.Name)-error.log"
    }
}
if ($studentAlreadyRunning -and -not (Get-NetTCPConnection -State Listen -LocalPort 8096 -ErrorAction SilentlyContinue)) {
    # MySQL can survive a closed parent process while its phpMyAdmin child stops.
    # Repair the companion independently instead of treating an open MySQL port
    # as proof that the complete student workspace is available.
    $studentRoot = Join-Path $PSScriptRoot 'storage/app/student-db'
    $phpMyAdminArgs = '-d opcache.enable_cli=0 -d session.save_path="{0}/sessions" -S 127.0.0.1:8096 -t "{0}/web"' -f $studentRoot
    Start-Process -FilePath $phpExe -ArgumentList $phpMyAdminArgs -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput storage/logs/local-phpmyadmin.log -RedirectStandardError storage/logs/local-phpmyadmin-error.log
}
$appUrl = 'http://127.0.0.1:8010/login'
Write-Host 'Waiting for the local app...'
$appReady = $false
$startupDeadline = (Get-Date).AddSeconds(60)
while ((Get-Date) -lt $startupDeadline) {
    try {
        $response = Invoke-WebRequest -Uri $appUrl -UseBasicParsing -TimeoutSec 5
        if ($response.StatusCode -eq 200) {
            $appReady = $true
            break
        }
    } catch {
        Start-Sleep -Milliseconds 500
    }
}
if (-not $appReady) {
    throw 'The app did not become ready. Check storage/logs/local-web-error.log and storage/logs/laravel.log.'
}
Start-Process -FilePath $appUrl
Write-Host "Opened $appUrl in your default browser."
Write-Host 'For queued background jobs, run in another terminal: php artisan queue:work'
